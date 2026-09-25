<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestration des deploys : locks, rate limit, historique.
 */
class Okno_Deploy_Manager {

	const OPTION_HISTORY  = 'okno_deploy_history';
	const TRANSIENT_SAVE  = 'okno_saving';
	const HISTORY_MAX     = 20;
	const CRON_HOOK       = 'okno_cleanup_deploys';

	/** Au-delà, un deploy sans statut connu est considéré comme perdu. */
	const STALE_AFTER = 1800;

	/** Statuts considérés comme "en cours". */
	const ACTIVE_STATUSES = array( 'pending', 'building' );

	/**
	 * Driver configuré.
	 *
	 * @return Okno_Deploy_Driver_Interface|null
	 */
	public static function driver() {
		$settings = Okno_Plugin::settings();
		switch ( $settings['deploy_driver'] ) {
			case 'build_hook':
				return new Okno_Build_Hook_Driver();
			case 'coolify':
				return new Okno_Coolify_Driver();
			case 'github':
				return new Okno_Github_Actions_Driver();
			case 'github_commit':
				return new Okno_Github_Commit_Driver();
		}
		/**
		 * Permet d'enregistrer un driver custom.
		 *
		 * @param Okno_Deploy_Driver_Interface|null $driver
		 * @param string                            $driver_id Valeur du réglage.
		 */
		return apply_filters( 'okno_deploy_driver', null, $settings['deploy_driver'] );
	}

	/**
	 * Déclenche un déploiement (avec tous les garde-fous).
	 *
	 * @return array|WP_Error Record du deploy.
	 */
	public static function trigger() {
		// Jamais de deploy pendant qu'un save écrit en DB.
		if ( get_transient( self::TRANSIENT_SAVE ) ) {
			return new WP_Error(
				'okno_save_in_progress',
				__( 'Un enregistrement est en cours, réessayez dans quelques secondes.', 'okno' ),
				array( 'status' => 409 )
			);
		}

		// Un deploy actif bloque le suivant.
		$active = self::active_deploy();
		if ( $active ) {
			return new WP_Error(
				'okno_deploy_in_progress',
				__( 'Un déploiement est déjà en cours.', 'okno' ),
				array( 'status' => 409 )
			);
		}

		// Rate limit.
		$settings = Okno_Plugin::settings();
		$history  = self::history();
		$last     = reset( $history );
		$interval = max( 0, (int) $settings['min_deploy_interval'] );
		if ( $last && $interval > 0 && ( time() - $last['started'] ) < $interval ) {
			return new WP_Error(
				'okno_rate_limited',
				sprintf( __( 'Merci de patienter %d s entre deux déploiements.', 'okno' ), $interval ),
				array( 'status' => 429 )
			);
		}

		$driver = self::driver();
		if ( ! $driver ) {
			return new WP_Error(
				'okno_deploy_config',
				__( 'Aucune méthode de déploiement configurée dans les réglages Okno.', 'okno' ),
				array( 'status' => 400 )
			);
		}

		$config = $driver->check_config();
		if ( is_wp_error( $config ) ) {
			$config->add_data( array( 'status' => 400 ) );
			return $config;
		}

		$user   = wp_get_current_user();
		$record = array(
			// G : identifiant non devinable (uniqid() est prévisible).
			'id'        => 'dep_' . wp_generate_password( 16, false ),
			'driver'    => $driver->get_id(),
			'user_id'   => $user->ID,
			'user_name' => $user->display_name,
			'started'   => time(),
			'status'    => 'pending',
			'message'   => '',
			'eta'       => 0,
			'meta'      => array(),
		);

		$result = $driver->trigger( $record );

		if ( is_wp_error( $result ) ) {
			$record['status']  = 'error';
			$record['message'] = $result->get_error_message();
			self::push_history( $record );
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		self::push_history( $result );
		Okno_Plugin::bump_usage( 'publish' );
		return $result;
	}

	/**
	 * Statut d'un deploy, rafraîchi via le driver si nécessaire.
	 *
	 * @param string $deploy_id Identifiant Okno.
	 * @return array|WP_Error
	 */
	public static function get_status( $deploy_id ) {
		$history = self::history();
		if ( ! isset( $history[ $deploy_id ] ) ) {
			return new WP_Error( 'okno_not_found', __( 'Déploiement inconnu.', 'okno' ), array( 'status' => 404 ) );
		}

		$record = $history[ $deploy_id ];

		if ( in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
			$before = $record;
			$driver = self::driver();
			if ( $driver && $driver->get_id() === $record['driver'] && $driver->supports_status() ) {
				$record = $driver->get_status( $record );
			}

			$record = self::expire_if_stale( $record );

			// B4 : le polling écrivait en base à chaque appel, pendant tout le build.
			if ( $record !== $before ) {
				$history[ $deploy_id ] = $record;
				update_option( self::OPTION_HISTORY, $history, false );
			}
		}

		return $record;
	}

	/**
	 * Un deploy actif trop vieux est perdu : sans cette sortie, `trigger()`
	 * refuse tout nouveau déploiement pour toujours.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private static function expire_if_stale( array $record ) {
		if ( ! in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
			return $record;
		}
		if ( ( time() - $record['started'] ) <= self::STALE_AFTER ) {
			return $record;
		}

		$record['status']  = 'error';
		$record['message'] = __( 'Statut introuvable après 30 minutes : déploiement considéré comme perdu.', 'okno' );
		return $record;
	}

	/**
	 * B3 : nettoyage planifié. Sans lui, un onglet fermé pendant un build laisse
	 * un record « pending » éternel, et le client ne peut plus publier.
	 *
	 * @return void
	 */
	public static function cleanup_stale() {
		$history = self::history();
		$changed = false;

		foreach ( $history as $id => $record ) {
			$fresh = self::expire_if_stale( $record );
			if ( $fresh !== $record ) {
				$history[ $id ] = $fresh;
				$changed        = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_HISTORY, $history, false );
		}
	}

	/**
	 * Planifie le nettoyage (activation du plugin).
	 *
	 * @return void
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Déplanifie le nettoyage (désactivation).
	 *
	 * @return void
	 */
	public static function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Deploy actif (pending/building), statut rafraîchi.
	 *
	 * @return array|null
	 */
	public static function active_deploy() {
		foreach ( self::history() as $record ) {
			if ( in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
				$fresh = self::get_status( $record['id'] );
				if ( ! is_wp_error( $fresh ) && in_array( $fresh['status'], self::ACTIVE_STATUSES, true ) ) {
					return $fresh;
				}
			}
		}
		return null;
	}

	/**
	 * Historique, plus récent en premier, indexé par id.
	 *
	 * @return array<string,array>
	 */
	public static function history() {
		$history = get_option( self::OPTION_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	private static function push_history( array $record ) {
		$history = self::history();
		$history = array( $record['id'] => $record ) + $history;
		if ( count( $history ) > self::HISTORY_MAX ) {
			$history = array_slice( $history, 0, self::HISTORY_MAX, true );
		}
		update_option( self::OPTION_HISTORY, $history, false );
	}
}
