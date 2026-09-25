<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administration : menu, assets, éditeur visuel, enregistrement des réglages.
 * Le rendu des onglets (Accueil, Démarrer, Réglages, Publications) est dans
 * Okno_Dashboard.
 */
class Okno_Admin {

	const EDITOR_SLUG = 'okno-editor';

	/** @var Okno_Plugin */
	private $plugin;

	/** @var string[] Hooks des pages du tableau de bord. */
	private $dashboard_hooks = array();

	/** @var string Hook de la page éditeur. */
	private $editor_hook = '';

	public function __construct( Okno_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_okno_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_filter( 'plugin_action_links_' . plugin_basename( OKNO_FILE ), array( $this, 'action_links' ) );
	}

	public function register_menu() {
		$tabs = Okno_Dashboard::tabs();

		$this->dashboard_hooks[] = add_menu_page(
			'Okno',
			'Okno',
			'edit_posts',
			'okno',
			array( 'Okno_Dashboard', 'render' ),
			Okno_Icons::menu_icon(),
			26
		);

		add_submenu_page( 'okno', $tabs['okno']['label'], $tabs['okno']['label'], 'edit_posts', 'okno', array( 'Okno_Dashboard', 'render' ) );

		$this->editor_hook = add_submenu_page( 'okno', __( 'Éditeur visuel', 'okno' ), __( 'Éditeur visuel', 'okno' ), 'edit_posts', self::EDITOR_SLUG, array( $this, 'render_editor_page' ) );

		foreach ( array( 'okno-start', 'okno-settings', 'okno-deploys' ) as $slug ) {
			$this->dashboard_hooks[] = add_submenu_page( 'okno', $tabs[ $slug ]['label'], $tabs[ $slug ]['label'], $tabs[ $slug ]['cap'], $slug, array( 'Okno_Dashboard', 'render' ) );
		}

		// Anciens liens (admin.php?page=okno&post=12) : ils ouvraient l'éditeur.
		add_action( 'load-toplevel_page_okno', array( $this, 'redirect_legacy_editor_links' ) );
	}

	public function redirect_legacy_editor_links() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirection de navigation, aucune écriture.
		if ( isset( $_GET['post'] ) ) {
			wp_safe_redirect( add_query_arg( 'post', absint( $_GET['post'] ), admin_url( 'admin.php?page=' . self::EDITOR_SLUG ) ) );
			exit;
		}
	}

	/**
	 * Raccourci « Modifier avec Okno » dans la barre d'admin, sur le site.
	 */
	public function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture de l'ID du contenu affiché.
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$settings = Okno_Plugin::settings();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $settings['post_types'], true ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'okno-edit',
				'title' => __( 'Modifier avec Okno', 'okno' ),
				'href'  => add_query_arg( 'post', $post_id, admin_url( 'admin.php?page=' . self::EDITOR_SLUG ) ),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=okno-start' ) ), esc_html__( 'Démarrer', 'okno' ) )
		);
		return $links;
	}

	/**
	 * Version d'un asset : date de modification du fichier. Une mise à jour du
	 * plugin change l'URL, donc le navigateur ne sert jamais un CSS périmé.
	 */
	public static function asset_version( $relative ) {
		$path = OKNO_DIR . $relative;
		return file_exists( $path ) ? OKNO_VERSION . '.' . filemtime( $path ) : OKNO_VERSION;
	}

	public function enqueue( $hook ) {
		if ( in_array( $hook, $this->dashboard_hooks, true ) ) {
			$this->enqueue_dashboard();
			return;
		}
		if ( $hook === $this->editor_hook ) {
			$this->enqueue_editor();
		}
	}

	private function enqueue_dashboard() {
		$settings = Okno_Plugin::settings();
		$mapper   = new Okno_Url_Mapper();

		wp_enqueue_style( 'okno-admin', OKNO_URL . 'assets/admin/admin.css', array(), self::asset_version( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'okno-admin', OKNO_URL . 'assets/admin/admin.js', array( 'wp-api-fetch' ), self::asset_version( 'assets/admin/admin.js' ), true );

		$preview = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];

		wp_add_inline_script(
			'okno-admin',
			'window.OknoAdmin = ' . wp_json_encode(
				array(
					'previewUrl'  => $preview ? trailingslashit( $preview ) : '',
					'frontOrigin' => $mapper->preview_origin(),
					'strings'     => array(
						'copied'          => __( 'Copié', 'okno' ),
						'copy'            => __( 'Copier', 'okno' ),
						'checking'        => __( 'Vérification…', 'okno' ),
						'testing'         => __( 'Connexion au site…', 'okno' ),
						/* translators: 1: version, 2: nombre de champs, 3: nombre de sections. */
						'bridgeOk'        => __( 'Bridge v%1$s détecté : %2$d champ(s) annoté(s) et %3$d section(s) sur la page d’accueil. L’éditeur est prêt.', 'okno' ),
						'bridgeNoFields'  => __( 'Le bridge répond, mais aucun champ n’est annoté sur la page d’accueil. Ajoutez data-wp-post et data-wp-field aux éléments de contenu.', 'okno' ),
						'bridgeTimeout'   => __( 'Le site n’a pas répondu en 10 secondes. Soit l’en-tête frame-ancestors bloque l’affichage (étape 2), soit le bridge n’est pas chargé (étape 3).', 'okno' ),
						'headersFailed'   => __( 'Impossible de joindre le site depuis le serveur WordPress.', 'okno' ),
					),
				)
			) . ';',
			'before'
		);
	}

	private function enqueue_editor() {
		wp_enqueue_media();
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		wp_enqueue_style( 'okno-editor', OKNO_URL . 'assets/admin/editor.css', array(), self::asset_version( 'assets/admin/editor.css' ) );
		wp_enqueue_script( 'okno-editor', OKNO_URL . 'assets/admin/editor.js', array( 'wp-api-fetch' ), self::asset_version( 'assets/admin/editor.js' ), true );

		$mapper = new Okno_Url_Mapper();
		$driver = Okno_Deploy_Manager::driver();
		$active = Okno_Deploy_Manager::active_deploy();

		$settings   = Okno_Plugin::settings();
		$post_types = array();
		foreach ( $settings['post_types'] as $type_name ) {
			$object = get_post_type_object( $type_name );
			if ( ! $object ) {
				continue;
			}
			$post_types[] = array(
				'name'      => $type_name,
				'label'     => $object->labels->singular_name,
				'canCreate' => current_user_can( $object->cap->create_posts ),
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Paramètres de navigation.
		$config = array(
			'postTypes'            => $post_types,
			'frontOrigin'          => $mapper->preview_origin(),
			'wpOrigin'             => self::wp_origin(),
			'initialPost'          => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0,
			'initialField'         => isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : '',
			'homeUrl'              => admin_url( 'admin.php?page=okno' ),
			'startUrl'             => admin_url( 'admin.php?page=okno-start' ),
			'settingsUrl'          => admin_url( 'admin.php?page=okno-settings' ),
			'liveMode'             => 'live' === $settings['deploy_driver'],
			'driverConfigured'     => (bool) $driver,
			'driverSupportsStatus' => $driver ? $driver->supports_status() : false,
			'activeDeploy'         => $active ? $active['id'] : '',
			'handshakeTimeout'     => 8000,
			'canDeploy'            => current_user_can( apply_filters( 'okno_deploy_capability', 'publish_pages' ) ),
		);
		// phpcs:enable

		wp_add_inline_script( 'okno-editor', 'window.OknoConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Origine de wp-admin, pour frame-ancestors et le bridge.
	 */
	public static function wp_origin() {
		$parts  = wp_parse_url( admin_url() );
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	/* ---------------------------------------------------------------------
	 * Page éditeur
	 * ------------------------------------------------------------------- */

	public function render_editor_page() {
		$settings   = Okno_Plugin::settings();
		$configured = '' !== $settings['front_url'];

		if ( ! $configured ) {
			?>
			<div class="okno-editor-setup">
				<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="40" height="40">
				<h1><?php esc_html_e( 'Okno n’est pas encore connecté à votre site', 'okno' ); ?></h1>
				<p><?php esc_html_e( 'Indiquez l’adresse du site et installez le bridge : l’éditeur s’ouvrira ensuite directement sur vos pages.', 'okno' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="okno-setup-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-start' ) ); ?>"><?php esc_html_e( 'Connecter le site', 'okno' ); ?></a>
				<?php else : ?>
					<p><?php esc_html_e( 'Demandez à l’administrateur du site de terminer la configuration.', 'okno' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}
		?>
		<div id="okno-editor" class="okno-editor">
			<header class="okno-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Actions de l’éditeur', 'okno' ); ?>">
				<div class="okno-toolbar-start">
					<a class="okno-exit" href="<?php echo esc_url( admin_url( 'admin.php?page=okno' ) ); ?>" title="<?php esc_attr_e( 'Retour à l’accueil d’Okno', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Retour à l’accueil d’Okno', 'okno' ); ?>">
						<?php Okno_Icons::render( 'back', 16 ); ?>
					</a>
					<img class="okno-mark" src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="Okno" width="22" height="22">
					<div class="okno-doc">
						<span id="okno-current-title" class="okno-current-title"><?php esc_html_e( 'Aucune page ouverte', 'okno' ); ?></span>
						<span id="okno-save-state" class="okno-save-state" aria-live="polite"></span>
					</div>
				</div>

				<div class="okno-toolbar-center">
					<span class="okno-viewport-toggle" role="group" aria-label="<?php esc_attr_e( 'Largeur de l’aperçu', 'okno' ); ?>">
						<button type="button" class="okno-vw active" data-width="" title="<?php esc_attr_e( 'Bureau', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Aperçu bureau', 'okno' ); ?>"><?php Okno_Icons::render( 'desktop', 16 ); ?></button>
						<button type="button" class="okno-vw" data-width="768" title="<?php esc_attr_e( 'Tablette', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Aperçu tablette', 'okno' ); ?>"><?php Okno_Icons::render( 'tablet', 16 ); ?></button>
						<button type="button" class="okno-vw" data-width="390" title="<?php esc_attr_e( 'Mobile', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Aperçu mobile', 'okno' ); ?>"><?php Okno_Icons::render( 'mobile', 16 ); ?></button>
					</span>
				</div>

				<div class="okno-toolbar-end">
					<span class="okno-history" role="group" aria-label="<?php esc_attr_e( 'Annuler et rétablir', 'okno' ); ?>">
						<button type="button" id="okno-undo" class="okno-icon-btn" title="<?php esc_attr_e( 'Annuler (Ctrl+Z)', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Annuler la dernière modification', 'okno' ); ?>" disabled><?php Okno_Icons::render( 'undo', 16 ); ?></button>
						<button type="button" id="okno-redo" class="okno-icon-btn" title="<?php esc_attr_e( 'Rétablir (Ctrl+Maj+Z)', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Rétablir la modification annulée', 'okno' ); ?>" disabled><?php Okno_Icons::render( 'redo', 16 ); ?></button>
					</span>
					<button type="button" id="okno-theme" class="okno-icon-btn" title="<?php esc_attr_e( 'Thème clair ou sombre', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Passer au thème sombre', 'okno' ); ?>" aria-pressed="false"><?php Okno_Icons::render( 'moon', 16 ); ?></button>
					<span id="okno-deploy-banner" class="okno-deploy-banner" hidden></span>
					<button type="button" id="okno-save" class="okno-btn okno-btn--quiet" disabled><?php esc_html_e( 'Enregistrer', 'okno' ); ?></button>
					<button type="button" id="okno-publish" class="okno-btn" disabled><?php esc_html_e( 'Publier', 'okno' ); ?></button>
				</div>
			</header>

			<div class="okno-main">
				<aside class="okno-sidebar" aria-label="<?php esc_attr_e( 'Navigation du site', 'okno' ); ?>">
					<div class="okno-sidebar-tabs">
						<button type="button" id="okno-tab-pages" class="okno-sidebar-tab active">
							<span><?php esc_html_e( 'Pages', 'okno' ); ?></span>
						</button>
						<button type="button" id="okno-tab-structure" class="okno-sidebar-tab" disabled>
							<span><?php esc_html_e( 'Structure', 'okno' ); ?></span>
						</button>
						<button type="button" id="okno-tab-history" class="okno-sidebar-tab" disabled>
							<span><?php esc_html_e( 'Historique', 'okno' ); ?></span>
						</button>
					</div>
					<div id="okno-pages-list" class="okno-sidebar-body"></div>
					<div id="okno-structure-tree" class="okno-sidebar-body" hidden></div>
					<div id="okno-history-list" class="okno-sidebar-body" hidden></div>
				</aside>

				<div class="okno-stage">
					<div class="okno-frame-wrap" id="okno-frame-wrap">
						<div class="okno-frame-placeholder" id="okno-frame-placeholder">
							<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="36" height="36">
							<p><?php esc_html_e( 'Choisissez une page à gauche pour l’afficher ici.', 'okno' ); ?></p>
						</div>
						<iframe id="okno-frame" title="<?php esc_attr_e( 'Aperçu du site', 'okno' ); ?>" hidden></iframe>

						<div id="okno-frame-help" class="okno-frame-help" hidden>
							<h2><?php esc_html_e( 'Le site ne répond pas', 'okno' ); ?></h2>
							<p><?php esc_html_e( 'L’aperçu s’est chargé, mais le bridge Okno ne s’est pas présenté. Deux causes possibles :', 'okno' ); ?></p>
							<ol>
								<li><strong><?php esc_html_e( 'Le site refuse d’être affiché dans wp-admin.', 'okno' ); ?></strong> <?php esc_html_e( 'Son en-tête frame-ancestors doit autoriser cette adresse.', 'okno' ); ?></li>
								<li><strong><?php esc_html_e( 'Le bridge n’est pas chargé sur cette page.', 'okno' ); ?></strong> <?php esc_html_e( 'Vérifiez l’installation.', 'okno' ); ?></li>
							</ol>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<a class="okno-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-start' ) ); ?>"><?php esc_html_e( 'Tester la connexion', 'okno' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<aside class="okno-panel" id="okno-panel" aria-label="<?php esc_attr_e( 'Champs de la sélection', 'okno' ); ?>"></aside>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Enregistrement des réglages
	 * ------------------------------------------------------------------- */

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'okno' ) );
		}
		check_admin_referer( 'okno_save_settings' );

		$input = isset( $_POST['okno_settings'] ) && is_array( $_POST['okno_settings'] ) ? wp_unslash( $_POST['okno_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$settings = Okno_Plugin::settings();

		$settings['front_url']   = esc_url_raw( isset( $input['front_url'] ) ? untrailingslashit( trim( $input['front_url'] ) ) : '' );
		$settings['preview_url'] = esc_url_raw( isset( $input['preview_url'] ) ? untrailingslashit( trim( $input['preview_url'] ) ) : '' );

		$public_types           = get_post_types( array( 'public' => true ), 'names' );
		$requested_types        = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$settings['post_types'] = array_values( array_intersect( $requested_types, $public_types ) );
		if ( empty( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'page' );
		}

		$patterns = array();
		if ( isset( $input['url_patterns'] ) && is_array( $input['url_patterns'] ) ) {
			foreach ( $input['url_patterns'] as $type => $pattern ) {
				$pattern = sanitize_text_field( $pattern );
				if ( '' !== $pattern && in_array( $type, $public_types, true ) ) {
					$patterns[ $type ] = $pattern;
				}
			}
		}
		$settings['url_patterns'] = $patterns;

		$settings['path_meta_key'] = sanitize_key( isset( $input['path_meta_key'] ) ? $input['path_meta_key'] : '' );

		$driver                    = isset( $input['deploy_driver'] ) ? sanitize_key( $input['deploy_driver'] ) : '';
		$settings['deploy_driver'] = array_key_exists( $driver, Okno_Dashboard::drivers() ) ? $driver : '';

		$settings['build_hook_url']      = esc_url_raw( isset( $input['build_hook_url'] ) ? trim( $input['build_hook_url'] ) : '' );
		$settings['build_hook_eta']      = max( 1, absint( isset( $input['build_hook_eta'] ) ? $input['build_hook_eta'] : 3 ) );
		$settings['coolify_url']         = esc_url_raw( isset( $input['coolify_url'] ) ? trim( $input['coolify_url'] ) : '' );
		$settings['gh_repo']             = sanitize_text_field( isset( $input['gh_repo'] ) ? $input['gh_repo'] : '' );
		$settings['gh_workflow']         = sanitize_text_field( isset( $input['gh_workflow'] ) ? $input['gh_workflow'] : '' );
		$settings['gh_branch']           = sanitize_text_field( isset( $input['gh_branch'] ) ? $input['gh_branch'] : '' );
		$settings['min_deploy_interval'] = absint( isset( $input['min_deploy_interval'] ) ? $input['min_deploy_interval'] : 60 );

		update_option( Okno_Plugin::OPTION_SETTINGS, $settings );

		// Secrets : champ vide = inchangé, valeur = remplacement.
		$secrets = isset( $_POST['okno_secret'] ) && is_array( $_POST['okno_secret'] ) ? wp_unslash( $_POST['okno_secret'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( 'coolify_token', 'gh_pat' ) as $name ) {
			if ( isset( $secrets[ $name ] ) && '' !== trim( $secrets[ $name ] ) ) {
				Okno_Secrets::set( $name, trim( $secrets[ $name ] ) );
			}
			if ( ! empty( $_POST[ 'okno_clear_' . $name ] ) ) {
				Okno_Secrets::set( $name, '' );
			}
		}

		// Contrôle préalable GitHub (dépôt, branche, workflow, token) : sans lui,
		// un « Not Found » ne se découvre qu'au premier déploiement raté.
		if ( 'github' === $settings['deploy_driver'] ) {
			Okno_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'], $settings['gh_workflow'] );
		} elseif ( 'github_commit' === $settings['deploy_driver'] ) {
			Okno_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'] );
		}

		wp_safe_redirect( add_query_arg( 'okno_saved', '1', admin_url( 'admin.php?page=okno-settings' ) ) );
		exit;
	}
}
