<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 3 : GitHub Actions via workflow_dispatch (fine-grained PAT).
 * Supporte le polling du statut du run pour affichage temps réel.
 */
class Okno_Github_Actions_Driver implements Okno_Deploy_Driver_Interface {

	const SECRET_NAME = 'gh_pat';
	const API_BASE    = 'https://api.github.com';

	public function get_id() {
		return 'github';
	}

	public function supports_status() {
		return true;
	}

	public function check_config() {
		$settings = Okno_Plugin::settings();
		if ( '' === $settings['gh_repo'] || '' === $settings['gh_workflow'] || '' === $settings['gh_branch'] ) {
			return new WP_Error( 'okno_deploy_config', __( 'Configuration GitHub incomplète (repo, workflow, branche).', 'okno' ) );
		}
		if ( 'unreadable' === Okno_Secrets::status( self::SECRET_NAME ) ) {
			return new WP_Error(
				'okno_secret_unreadable',
				__( 'Le PAT GitHub est illisible (les salts WordPress ont probablement changé). Re-saisissez-le dans les réglages Okno.', 'okno' )
			);
		}
		if ( null === Okno_Secrets::get( self::SECRET_NAME ) ) {
			return new WP_Error( 'okno_deploy_config', __( 'Aucun PAT GitHub configuré.', 'okno' ) );
		}
		return true;
	}

	public function trigger( array $record ) {
		$settings = Okno_Plugin::settings();

		// B2 : borne haute des runs existants AVANT le dispatch. Le run déclenché
		// est forcément d'id supérieur — l'ancienne heuristique « premier run créé
		// après started - 120 s » suivait le mauvais run quand deux publications
		// se succédaient.
		$record['meta']['since_run_id'] = $this->latest_run_id( $settings );

		$response = $this->request(
			'POST',
			sprintf(
				'/repos/%s/actions/workflows/%s/dispatches',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] )
			),
			array( 'ref' => $settings['gh_branch'] )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 204 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$raw  = isset( $body['message'] ) ? $body['message'] : sprintf( __( 'GitHub a répondu %d.', 'okno' ), $code );

			// Le « Not Found » brut de GitHub n'aide personne : on dit quoi vérifier.
			switch ( $code ) {
				case 404:
					$message = sprintf(
						__( 'GitHub n’a pas trouvé le workflow « %1$s » sur %2$s (ou le PAT n’a pas accès à ce dépôt). Ouvrez les réglages Okno et enregistrez-les pour lancer le contrôle de configuration.', 'okno' ),
						$settings['gh_workflow'],
						$settings['gh_repo']
					);
					break;
				case 401:
					$message = __( 'PAT GitHub refusé : token invalide, révoqué ou expiré. Re-saisissez-le dans les réglages Okno.', 'okno' );
					break;
				case 403:
					$message = sprintf( __( 'GitHub a refusé la requête (403) : le PAT n’a probablement pas la permission « Actions: write ». Détail : %s', 'okno' ), $raw );
					break;
				case 422:
					$message = sprintf( __( 'GitHub a rejeté le déclenchement (422) : la branche « %1$s » n’existe pas, ou le workflow n’accepte pas workflow_dispatch. Détail : %2$s', 'okno' ), $settings['gh_branch'], $raw );
					break;
				default:
					$message = $raw;
			}

			return new WP_Error( 'okno_deploy_failed', $message );
		}

		$record['status'] = 'pending';
		return $record;
	}

	public function get_status( array $record ) {
		$settings = Okno_Plugin::settings();

		if ( empty( $record['meta']['run_id'] ) ) {
			$run = $this->find_run( $settings, $record );
			if ( ! $run ) {
				return $record; // Run pas encore visible côté GitHub, ou API injoignable.
			}
			$record['meta']['run_id']  = $run['id'];
			$record['meta']['run_url'] = $run['html_url'];
		}

		$response = $this->request(
			'GET',
			sprintf( '/repos/%s/actions/runs/%d', $settings['gh_repo'], $record['meta']['run_id'] )
		);
		if ( is_wp_error( $response ) ) {
			return $record;
		}

		$run = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $run['status'] ) ) {
			return $record;
		}

		if ( 'completed' === $run['status'] ) {
			$record['status']  = ( 'success' === $run['conclusion'] ) ? 'success' : 'error';
			$record['message'] = ( 'success' === $run['conclusion'] )
				? __( 'Déploiement terminé.', 'okno' )
				: sprintf( __( 'Le workflow a échoué (%s).', 'okno' ), (string) $run['conclusion'] );
		} else {
			$record['status'] = 'building'; // queued | in_progress.
		}

		return $record;
	}

	/**
	 * Id du run le plus récent du workflow, tous événements confondus.
	 *
	 * @param array $settings Réglages.
	 * @return int 0 si inconnu (API injoignable, aucun run).
	 */
	private function latest_run_id( $settings ) {
		$response = $this->request(
			'GET',
			sprintf(
				'/repos/%s/actions/workflows/%s/runs?per_page=1',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] )
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$runs = isset( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();
		return $runs ? (int) $runs[0]['id'] : 0;
	}

	/**
	 * Retrouve le run déclenché par ce record : le plus ancien run
	 * workflow_dispatch d'id supérieur à la borne prise au déclenchement.
	 *
	 * @param array $settings Réglages.
	 * @param array $record   Record du deploy.
	 * @return array|null
	 */
	private function find_run( $settings, array $record ) {
		$response = $this->request(
			'GET',
			sprintf(
				'/repos/%s/actions/workflows/%s/runs?branch=%s&event=workflow_dispatch&per_page=20',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] ),
				rawurlencode( $settings['gh_branch'] )
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$runs  = isset( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();
		$since = isset( $record['meta']['since_run_id'] ) ? (int) $record['meta']['since_run_id'] : 0;

		$match = null;
		foreach ( $runs as $run ) {
			if ( $since > 0 ) {
				if ( (int) $run['id'] <= $since ) {
					continue;
				}
			} elseif ( strtotime( $run['created_at'] ) < $record['started'] - 120 ) {
				// Record d'avant B2 (ou borne inconnue) : on retombe sur la date.
				continue;
			}
			// La liste est triée du plus récent au plus ancien : le dernier
			// candidat retenu est le premier run postérieur au déclenchement.
			$match = $run;
		}

		return $match;
	}

	private function request( $method, $path, $body = null ) {
		$pat = Okno_Secrets::get( self::SECRET_NAME );
		if ( null === $pat ) {
			return new WP_Error( 'okno_secret_unreadable', __( 'PAT GitHub absent ou illisible.', 'okno' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $pat,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'Okno/' . OKNO_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		return wp_remote_request( self::API_BASE . $path, $args );
	}
}
