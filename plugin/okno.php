<?php
/**
 * Plugin Name:       Okno
 * Plugin URI:        https://github.com/pixelersagency/okno
 * Description:       Éditeur visuel pour fronts headless (Astro, Next.js, …) directement dans wp-admin. Les champs ACF existants sont la source de vérité.
 * Version:           1.0.0-beta.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pixelers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       okno
 */

defined( 'ABSPATH' ) || exit;

// Deux copies du plugin installées côte à côte (zip déballé dans un dossier
// versionné à côté du dossier okno/, par exemple) : on sort proprement au lieu
// de fataler sur « Cannot redeclare class Okno_Plugin » à l'activation.
// (Pas de class_exists() ici : PHP lie la classe de ce fichier dès la
// compilation, le test serait vrai dès le premier chargement.)
if ( defined( 'OKNO_VERSION' ) ) {
	return;
}

define( 'OKNO_VERSION', '1.0.0-beta.2' );
define( 'OKNO_FILE', __FILE__ );
define( 'OKNO_DIR', plugin_dir_path( __FILE__ ) );
define( 'OKNO_URL', plugin_dir_url( __FILE__ ) );

require_once OKNO_DIR . 'includes/adapters/interface-okno-adapter.php';
require_once OKNO_DIR . 'includes/adapters/class-okno-acf-adapter.php';
require_once OKNO_DIR . 'includes/deploy/interface-okno-deploy-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-build-hook-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-coolify-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-preflight.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-actions-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-commit-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-deploy-manager.php';
require_once OKNO_DIR . 'includes/class-okno-secrets.php';
require_once OKNO_DIR . 'includes/class-okno-url-mapper.php';
require_once OKNO_DIR . 'includes/class-okno-activity.php';
require_once OKNO_DIR . 'includes/class-okno-schema.php';
require_once OKNO_DIR . 'includes/class-okno-rest.php';
require_once OKNO_DIR . 'includes/admin/class-okno-icons.php';
require_once OKNO_DIR . 'includes/admin/class-okno-dashboard.php';
require_once OKNO_DIR . 'includes/admin/class-okno-admin.php';

/**
 * Bootstrap.
 */
final class Okno_Plugin {

	const OPTION_SETTINGS = 'okno_settings';
	const OPTION_SECRETS  = 'okno_secrets';
	const OPTION_USAGE    = 'okno_usage';

	/** @var Okno_Plugin|null */
	private static $instance = null;

	/** @var Okno_Adapter_Interface[] */
	private $adapters = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		$this->adapters['acf'] = new Okno_ACF_Adapter();

		/**
		 * Permet d'enregistrer des adapters supplémentaires (JetEngine, Metabox, …).
		 *
		 * @param Okno_Adapter_Interface[] $adapters Adapters indexés par id.
		 */
		$this->adapters = apply_filters( 'okno_adapters', $this->adapters );

		add_action( 'rest_api_init', array( new Okno_Rest( $this ), 'register_routes' ) );
		add_action( Okno_Deploy_Manager::CRON_HOOK, array( 'Okno_Deploy_Manager', 'cleanup_stale' ) );
		Okno_Activity::init();

		if ( is_admin() ) {
			new Okno_Admin( $this );
		}
	}

	/** @return Okno_Adapter_Interface[] Adapters disponibles (dépendance présente). */
	public function adapters() {
		return array_filter(
			$this->adapters,
			static function ( $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Réglages avec valeurs par défaut.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'front_url'           => '',
			'preview_url'         => '',
			'post_types'          => array( 'page' ),
			'url_patterns'        => array(), // post_type => pattern, ex. 'page' => '/{slug}'.
			'path_meta_key'       => '', // Meta (ex. champ ACF) contenant le chemin de la page sur le front.
			'deploy_driver'       => '',
			'build_hook_url'      => '',
			'build_hook_eta'      => 3, // minutes.
			'coolify_url'         => '',
			'gh_repo'             => '',
			'gh_workflow'         => 'deploy.yml',
			'gh_branch'           => 'main',
			'min_deploy_interval' => 60, // secondes.
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Titre lisible d'un contenu.
	 *
	 * get_the_title() passe par wptexturize() et rend des entités (&rsquo;,
	 * &amp;…). L'éditeur écrit ces titres en textContent : sans décodage,
	 * « l’eau » s'affiche « l&rsquo;eau ».
	 *
	 * @param int|WP_Post $post Post.
	 * @return string
	 */
	public static function title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Incrémente un compteur d'usage (objectiver le "0 ticket").
	 *
	 * @param string $event 'save' ou 'publish'.
	 */
	public static function bump_usage( $event ) {
		$usage = get_option( self::OPTION_USAGE, array() );
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}
		$week = gmdate( 'o-\WW' );
		if ( ! isset( $usage[ $week ] ) ) {
			$usage[ $week ] = array(
				'save'    => 0,
				'publish' => 0,
			);
		}
		if ( isset( $usage[ $week ][ $event ] ) ) {
			$usage[ $week ][ $event ]++;
		}
		// On garde 26 semaines glissantes.
		if ( count( $usage ) > 26 ) {
			ksort( $usage );
			$usage = array_slice( $usage, -26, null, true );
		}
		update_option( self::OPTION_USAGE, $usage, false );
	}
}

add_action( 'plugins_loaded', array( 'Okno_Plugin', 'instance' ) );

register_activation_hook( __FILE__, array( 'Okno_Deploy_Manager', 'schedule_cleanup' ) );
register_activation_hook( __FILE__, array( 'Okno_Activity', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Okno_Deploy_Manager', 'unschedule_cleanup' ) );
