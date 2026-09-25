<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pages d'administration d'Okno : Accueil, Démarrer, Réglages, Publications.
 *
 * Chaque onglet est une vraie page WordPress (slug propre) : le menu latéral
 * reste synchronisé et chaque onglet a une URL partageable.
 */
class Okno_Dashboard {

	const OPTION_CONNECTION = 'okno_connection';

	/**
	 * Onglets : slug de page => libellé, capability.
	 *
	 * @return array<string,array{label:string,cap:string}>
	 */
	public static function tabs() {
		return array(
			'okno'          => array(
				'label' => __( 'Accueil', 'okno' ),
				'cap'   => 'edit_posts',
			),
			'okno-start'    => array(
				'label' => __( 'Démarrer', 'okno' ),
				'cap'   => 'manage_options',
			),
			'okno-settings' => array(
				'label' => __( 'Réglages', 'okno' ),
				'cap'   => 'manage_options',
			),
			'okno-deploys'  => array(
				'label' => __( 'Publications', 'okno' ),
				'cap'   => 'edit_posts',
			),
		);
	}

	/**
	 * Point d'entrée : rend l'onglet correspondant à la page courante.
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture du slug de page.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'okno';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $page ] ) || ! current_user_can( $tabs[ $page ]['cap'] ) ) {
			$page = 'okno';
		}
		?>
		<div class="okno-admin">
			<?php self::render_bar( $page ); ?>
			<?php
			switch ( $page ) {
				case 'okno-start':
					self::render_start();
					break;
				case 'okno-settings':
					self::render_settings();
					break;
				case 'okno-deploys':
					self::render_deploys();
					break;
				default:
					self::render_home();
			}
			?>
		</div>
		<?php
	}

	private static function render_bar( $current ) {
		?>
		<header class="okno-bar">
			<div class="okno-bar__brand">
				<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="24" height="24">
				<h1 class="okno-bar__title">Okno</h1>
				<span class="okno-bar__version">v<?php echo esc_html( OKNO_VERSION ); ?></span>
			</div>
			<nav class="okno-tabs" aria-label="<?php esc_attr_e( 'Sections d’Okno', 'okno' ); ?>">
				<?php foreach ( self::tabs() as $slug => $tab ) : ?>
					<?php
					if ( ! current_user_can( $tab['cap'] ) ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</header>
		<?php
	}

	/* =====================================================================
	 * Accueil
	 * =================================================================== */

	private static function render_home() {
		$settings   = Okno_Plugin::settings();
		$configured = '' !== $settings['front_url'];
		$connection = self::connection();
		$history    = Okno_Deploy_Manager::history();
		$last       = reset( $history );
		$week_count = Okno_Activity::count_since( time() - WEEK_IN_SECONDS );
		$activities = Okno_Activity::recent( 40 );
		?>
		<div class="okno-page">

			<?php if ( ! $configured && current_user_can( 'manage_options' ) ) : ?>
				<div class="okno-card okno-card--accent okno-open">
					<div class="okno-open__text">
						<span class="okno-eyebrow"><?php esc_html_e( 'Première étape', 'okno' ); ?></span>
						<h2><?php esc_html_e( 'Connectez votre site à Okno', 'okno' ); ?></h2>
						<p><?php esc_html_e( 'Indiquez l’adresse du site, autorisez l’éditeur à l’afficher, installez le bridge : quatre étapes guidées, avec une vérification à chacune.', 'okno' ); ?></p>
					</div>
					<a class="okno-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-start' ) ); ?>">
						<?php esc_html_e( 'Démarrer', 'okno' ); ?>
						<?php Okno_Icons::render( 'arrow', 16 ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="okno-card okno-card--accent okno-open">
					<div class="okno-open__text">
						<h2><?php esc_html_e( 'Modifiez votre site en le regardant', 'okno' ); ?></h2>
						<p><?php esc_html_e( 'Cliquez sur un texte ou une image de votre site pour le modifier. Vous voyez le résultat immédiatement, avant de l’enregistrer.', 'okno' ); ?></p>
					</div>
					<div class="okno-actions" style="margin-top:0">
						<a class="okno-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-editor' ) ); ?>">
							<?php esc_html_e( 'Ouvrir l’éditeur', 'okno' ); ?>
						</a>
						<?php if ( $configured ) : ?>
							<a class="okno-btn okno-btn--quiet" href="<?php echo esc_url( $settings['front_url'] ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Voir le site', 'okno' ); ?>
								<?php Okno_Icons::render( 'external', 14 ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="okno-stats">
				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Connexion au site', 'okno' ); ?></span>
					<?php if ( ! $connection ) : ?>
						<span class="okno-stat__value"><span class="okno-dot"></span><?php esc_html_e( 'Pas encore testée', 'okno' ); ?></span>
					<?php elseif ( $connection['ok'] ) : ?>
						<span class="okno-stat__value"><span class="okno-dot okno-dot--ok"></span><?php esc_html_e( 'Opérationnelle', 'okno' ); ?></span>
						<span class="okno-stat__hint">
							<?php
							/* translators: %s: durée relative. */
							echo esc_html( sprintf( __( 'Vérifiée il y a %s', 'okno' ), human_time_diff( $connection['at'] ) ) );
							?>
						</span>
					<?php else : ?>
						<span class="okno-stat__value"><span class="okno-dot okno-dot--error"></span><?php esc_html_e( 'À vérifier', 'okno' ); ?></span>
						<span class="okno-stat__hint"><?php esc_html_e( 'Le dernier test a échoué.', 'okno' ); ?></span>
					<?php endif; ?>
				</div>

				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Mise en ligne', 'okno' ); ?></span>
					<span class="okno-stat__value">
						<span class="okno-dot <?php echo '' === $settings['deploy_driver'] ? '' : 'okno-dot--ok'; ?>"></span>
						<?php echo esc_html( self::driver_label( $settings['deploy_driver'] ) ); ?>
					</span>
					<?php if ( $last ) : ?>
						<span class="okno-stat__hint">
							<?php
							/* translators: 1: statut, 2: durée relative. */
							echo esc_html( sprintf( __( 'Dernière publication : %1$s, il y a %2$s', 'okno' ), self::status_label( $last['status'] ), human_time_diff( $last['started'] ) ) );
							?>
						</span>
					<?php endif; ?>
				</div>

				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Modifications cette semaine', 'okno' ); ?></span>
					<span class="okno-stat__value"><?php echo esc_html( number_format_i18n( $week_count ) ); ?></span>
				</div>
			</div>

			<h2 class="okno-heading" style="margin-top:40px"><?php esc_html_e( 'Dernières modifications', 'okno' ); ?></h2>

			<div class="okno-card okno-card--flush">
				<?php if ( ! $activities ) : ?>
					<div class="okno-empty">
						<?php Okno_Icons::render( 'history', 32 ); ?>
						<h3><?php esc_html_e( 'Aucune modification pour l’instant', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'Chaque enregistrement fait depuis l’éditeur apparaîtra ici : qui a modifié quel champ, quand, avec la valeur avant et après.', 'okno' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="okno-log">
						<?php foreach ( $activities as $activity ) : ?>
							<?php
							if ( ! current_user_can( 'edit_post', (int) $activity->post_id ) ) {
								continue;
							}
							self::render_activity( $activity );
							?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_activity( $activity ) {
		$post_id   = (int) $activity->post_id;
		$title     = $post_id ? Okno_Plugin::title( $post_id ) : '';
		$when      = strtotime( $activity->created_at . ' UTC' );
		$open_url  = add_query_arg(
			array(
				'page'  => 'okno-editor',
				'post'  => $post_id,
				'field' => $activity->field_path,
			),
			admin_url( 'admin.php' )
		);
		$initials  = self::initials( $activity->user_name );
		?>
		<li class="okno-log__item">
			<div class="okno-log__head">
				<div>
					<strong class="okno-log__field" title="<?php echo esc_attr( $activity->field_path ); ?>"><?php echo esc_html( $activity->field_label ); ?></strong>
					<span class="okno-log__meta">
						<span class="okno-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: auteur, 2: contenu, 3: durée relative. */
								__( '%1$s · %2$s · il y a %3$s', 'okno' ),
								$activity->user_name ? $activity->user_name : __( 'Utilisateur inconnu', 'okno' ),
								$title ? $title : __( 'contenu supprimé', 'okno' ),
								$when ? human_time_diff( $when ) : '?'
							)
						);
						?>
					</span>
				</div>
				<?php if ( $post_id && get_post( $post_id ) ) : ?>
					<a class="okno-btn okno-btn--quiet okno-btn--small" href="<?php echo esc_url( $open_url ); ?>">
						<?php esc_html_e( 'Ouvrir', 'okno' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<div class="okno-diff">
				<div class="okno-diff__value">
					<span class="okno-diff__label"><?php esc_html_e( 'Avant', 'okno' ); ?></span>
					<?php self::render_value( $activity->old_value, $activity->field_type ); ?>
				</div>
				<span class="okno-diff__arrow"><?php Okno_Icons::render( 'arrow', 16 ); ?></span>
				<div class="okno-diff__value okno-diff__value--after">
					<span class="okno-diff__label"><?php esc_html_e( 'Après', 'okno' ); ?></span>
					<?php self::render_value( $activity->new_value, $activity->field_type ); ?>
				</div>
			</div>
		</li>
		<?php
	}

	/**
	 * Affichage lisible d'une valeur journalisée, selon son type.
	 */
	private static function render_value( $value, $type ) {
		if ( '' === $value || '[]' === $value || '{}' === $value ) {
			echo '<span class="okno-diff__empty">' . esc_html__( 'vide', 'okno' ) . '</span>';
			return;
		}

		switch ( $type ) {
			case 'image':
			case 'file':
				$url = wp_get_attachment_image_url( (int) $value, 'thumbnail' );
				if ( $url && 'image' === $type ) {
					echo '<img src="' . esc_url( $url ) . '" alt="">';
				} else {
					echo esc_html( Okno_Plugin::title( (int) $value ) );
				}
				return;

			case 'wysiwyg':
				echo wp_kses_post( $value );
				return;

			case 'true_false':
				echo esc_html( '1' === $value ? __( 'Oui', 'okno' ) : __( 'Non', 'okno' ) );
				return;

			case 'link':
				$link = json_decode( $value, true );
				if ( is_array( $link ) ) {
					echo esc_html( trim( ( isset( $link['title'] ) ? $link['title'] . ' — ' : '' ) . ( isset( $link['url'] ) ? $link['url'] : '' ), ' —' ) );
					return;
				}
				break;

			case 'repeater':
			case 'flexible_content':
			case 'gallery':
			case 'relationship':
				$items = json_decode( $value, true );
				if ( is_array( $items ) ) {
					/* translators: %d: nombre d'éléments. */
					echo esc_html( sprintf( _n( '%d élément', '%d éléments', count( $items ), 'okno' ), count( $items ) ) );
					return;
				}
				break;
		}

		echo esc_html( wp_html_excerpt( wp_strip_all_tags( $value ), 400, '…' ) );
	}

	private static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( array_filter( (array) $parts ), 0, 2 ) as $part ) {
			$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
		}
		return '' !== $out ? $out : '?';
	}

	/* =====================================================================
	 * Démarrer
	 * =================================================================== */

	private static function render_start() {
		$settings    = Okno_Plugin::settings();
		$wp_origin   = Okno_Admin::wp_origin();
		$has_url     = '' !== $settings['front_url'];
		$connection  = self::connection();
		$bridge_file = OKNO_URL . 'assets/bridge/okno-bridge.js';

		$loader = "<script>\n  if (window.self !== window.top) {\n    var s = document.createElement('script');\n    s.src = '/okno-bridge.js';\n    s.setAttribute('data-wp-origin', '" . $wp_origin . "');\n    document.head.appendChild(s);\n  }\n</script>";
		$react  = "npm install @pixelersagency/okno-bridge\n\n// Dans le layout racine (Next.js : app/layout.tsx, Remix : root.tsx, Vite : App.tsx)\nimport { OknoBridge } from '@pixelersagency/okno-bridge/react';\n\n<OknoBridge wpOrigin=\"" . $wp_origin . '" />';
		$csp    = "Content-Security-Policy: frame-ancestors 'self' " . $wp_origin;

		$instructions_file = OKNO_DIR . 'docs/agent-instructions.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local du plugin.
		$instructions = file_exists( $instructions_file ) ? file_get_contents( $instructions_file ) : '';
		$instructions = str_replace( 'https://ADRESSE-DU-WORDPRESS', $wp_origin, $instructions );

		$intro        = __( 'Nous allons rendre ce site éditable avec Okno, l’éditeur visuel WordPress. Okno a des instructions précises à suivre pendant tout le projet : je te les donne ci-dessous. Lis-les entièrement avant d’écrire du code, et respecte-les pour chaque composant.', 'okno' );
		$prompt_exist = $intro . "\n\n" . __( 'Le site existe déjà. Installe le bridge, puis annote chaque contenu affiché avec le champ WordPress correspondant, en suivant les instructions. Termine par la liste de vérifications.', 'okno' ) . "\n\n" . __( 'Dépôt du site :', 'okno' ) . "\n\n---\n\n" . $instructions;
		$prompt_new   = $intro . "\n\n" . __( 'Nous partons de zéro. Construis le site et son modèle de contenu ACF en suivant les instructions dès le premier composant. Termine par la liste de vérifications.', 'okno' ) . "\n\n" . __( 'Idée ou brief du site :', 'okno' ) . "\n\n---\n\n" . $instructions;
		?>
		<div class="okno-page okno-page--narrow">
			<span class="okno-eyebrow"><?php esc_html_e( 'Connecter un site', 'okno' ); ?></span>
			<h2 style="margin:0 0 8px;font-size:22px;color:var(--okno-ink)"><?php esc_html_e( 'Rendre votre site éditable', 'okno' ); ?></h2>
			<p class="okno-lead"><?php esc_html_e( 'Okno fonctionne avec n’importe quel site qui lit son contenu dans WordPress : React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit ou HTML. Quatre étapes, chacune vérifiable.', 'okno' ); ?></p>

			<ol class="okno-steps">
				<li class="okno-step <?php echo $has_url ? 'okno-step--done' : 'okno-step--current'; ?>">
					<h3>
						<?php esc_html_e( 'Adresse du site', 'okno' ); ?>
						<?php if ( $has_url ) : ?>
							<span class="okno-pill okno-pill--ok"><?php echo esc_html( $settings['front_url'] ); ?></span>
						<?php endif; ?>
					</h3>
					<p><?php esc_html_e( 'L’adresse publique du site, et si vous en avez une, l’adresse de prévisualisation à afficher dans l’éditeur.', 'okno' ); ?></p>
					<a class="okno-btn <?php echo $has_url ? 'okno-btn--quiet okno-btn--small' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-settings' ) ); ?>">
						<?php echo $has_url ? esc_html__( 'Modifier', 'okno' ) : esc_html__( 'Renseigner l’adresse', 'okno' ); ?>
					</a>
				</li>

				<li class="okno-step">
					<h3><?php esc_html_e( 'Autoriser l’éditeur à afficher le site', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'Le site doit accepter d’être affiché dans wp-admin. Ajoutez cet en-tête HTTP à ses réponses (configuration de l’hébergeur, middleware ou fichier d’en-têtes du framework).', 'okno' ); ?></p>
					<div class="okno-code">
						<pre><?php echo esc_html( $csp ); ?></pre>
						<button type="button" data-okno-copy="<?php echo esc_attr( $csp ); ?>"><?php esc_html_e( 'Copier', 'okno' ); ?></button>
					</div>
					<div class="okno-actions">
						<button type="button" class="okno-btn okno-btn--quiet okno-btn--small" data-okno-check-headers <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Vérifier les en-têtes', 'okno' ); ?>
						</button>
					</div>
					<div class="okno-check" data-okno-headers-result hidden></div>
				</li>

				<li class="okno-step">
					<h3><?php esc_html_e( 'Installer le bridge', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'Un petit script qui ne se charge que dans l’éditeur : vos visiteurs ne le téléchargent jamais.', 'okno' ); ?></p>
					<div class="okno-segmented" role="tablist" data-okno-segmented>
						<button type="button" role="tab" aria-selected="true" data-panel="html"><?php esc_html_e( 'HTML · Astro · Nuxt · SvelteKit', 'okno' ); ?></button>
						<button type="button" role="tab" aria-selected="false" data-panel="react"><?php esc_html_e( 'React · Next.js · Remix · Vite', 'okno' ); ?></button>
					</div>
					<div data-panel-id="html">
						<p class="okno-help"><?php esc_html_e( 'Placez okno-bridge.js dans le dossier public du site, puis ce code dans le <head> du layout.', 'okno' ); ?></p>
						<div class="okno-code">
							<pre><?php echo esc_html( $loader ); ?></pre>
							<button type="button" data-okno-copy="<?php echo esc_attr( $loader ); ?>"><?php esc_html_e( 'Copier', 'okno' ); ?></button>
						</div>
						<div class="okno-actions">
							<a class="okno-btn okno-btn--quiet okno-btn--small" href="<?php echo esc_url( $bridge_file ); ?>" download="okno-bridge.js"><?php esc_html_e( 'Télécharger okno-bridge.js', 'okno' ); ?></a>
						</div>
					</div>
					<div data-panel-id="react" hidden>
						<p class="okno-help"><?php esc_html_e( 'Le composant charge le bridge uniquement dans l’éditeur, par import dynamique.', 'okno' ); ?></p>
						<div class="okno-code">
							<pre><?php echo esc_html( $react ); ?></pre>
							<button type="button" data-okno-copy="<?php echo esc_attr( $react ); ?>"><?php esc_html_e( 'Copier', 'okno' ); ?></button>
						</div>
					</div>
				</li>

				<li class="okno-step <?php echo ( $connection && $connection['ok'] ) ? 'okno-step--done' : ''; ?>">
					<h3><?php esc_html_e( 'Tester la connexion', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'Okno ouvre votre site en arrière-plan et attend que le bridge se présente. S’il répond, l’éditeur est prêt.', 'okno' ); ?></p>
					<div class="okno-actions">
						<button type="button" class="okno-btn" data-okno-test-bridge <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Tester la connexion', 'okno' ); ?>
						</button>
					</div>
					<div class="okno-check <?php echo $connection ? ( $connection['ok'] ? 'okno-check--ok' : 'okno-check--error' ) : ''; ?>" data-okno-bridge-result <?php echo $connection ? '' : 'hidden'; ?>>
						<?php if ( $connection ) : ?>
							<span class="okno-dot <?php echo $connection['ok'] ? 'okno-dot--ok' : 'okno-dot--error'; ?>"></span>
							<span>
								<?php
								echo esc_html(
									$connection['ok']
										/* translators: 1: version du bridge, 2: nombre de champs, 3: durée relative. */
										? sprintf( __( 'Bridge v%1$s détecté, %2$d champ(s) annoté(s) sur la page d’accueil. Vérifié il y a %3$s.', 'okno' ), $connection['version'], $connection['fields'], human_time_diff( $connection['at'] ) )
										/* translators: %s: durée relative. */
										: sprintf( __( 'Le bridge n’a pas répondu lors du dernier test, il y a %s.', 'okno' ), human_time_diff( $connection['at'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			</ol>

			<h2 class="okno-heading" style="margin-top:48px"><?php esc_html_e( 'Annoter les contenus', 'okno' ); ?></h2>
			<div class="okno-card">
				<p><?php esc_html_e( 'Chaque élément qui affiche un champ WordPress porte le nom de ce champ. Le bridge s’en sert pour savoir quoi mettre à jour, et l’éditeur pour savoir quel champ ouvrir quand on clique.', 'okno' ); ?></p>
				<div class="okno-code">
					<pre><?php echo esc_html( "<section data-wp-post=\"12\" data-okno-section=\"Hero\">\n  <h1 data-wp-field=\"hero_titre\">…</h1>\n  <img data-wp-field=\"hero_image\" src=\"…\" alt=\"…\">\n  <article data-wp-field=\"cartes.0.titre\">…</article>\n</section>" ); ?></pre>
				</div>
				<p class="okno-help" style="margin-top:12px"><?php esc_html_e( 'Toutes les annotations, les règles et la liste de vérifications sont dans le guide ci-dessous.', 'okno' ); ?></p>
			</div>

			<h2 class="okno-heading" style="margin-top:48px"><?php esc_html_e( 'Avec un agent IA', 'okno' ); ?></h2>
			<div class="okno-card">
				<span class="okno-eyebrow"><?php esc_html_e( 'Claude, Codex, Cursor…', 'okno' ); ?></span>
				<h2><?php esc_html_e( 'Laissez l’agent faire l’intégration', 'okno' ); ?></h2>
				<p><?php esc_html_e( 'Okno fournit à votre agent un contrat précis : où charger le bridge, comment nommer et annoter les champs, quelles erreurs éviter, quoi vérifier avant de livrer. Copiez le prompt adapté, ajoutez votre dépôt ou votre brief.', 'okno' ); ?></p>

				<div class="okno-prompts">
					<div class="okno-prompt">
						<h3><?php esc_html_e( 'Intégrer un site existant', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'Le site lit déjà son contenu dans WordPress. L’agent installe le bridge et annote chaque contenu.', 'okno' ); ?></p>
						<button type="button" class="okno-btn okno-btn--small" data-okno-copy-from="okno-prompt-existing"><?php esc_html_e( 'Copier le prompt', 'okno' ); ?></button>
						<textarea id="okno-prompt-existing" class="okno-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_exist ); ?></textarea>
					</div>
					<div class="okno-prompt">
						<h3><?php esc_html_e( 'Construire un nouveau site', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'L’agent conçoit le site et son modèle de contenu ACF, éditable avec Okno dès le premier composant.', 'okno' ); ?></p>
						<button type="button" class="okno-btn okno-btn--small" data-okno-copy-from="okno-prompt-new"><?php esc_html_e( 'Copier le prompt', 'okno' ); ?></button>
						<textarea id="okno-prompt-new" class="okno-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_new ); ?></textarea>
					</div>
				</div>

				<details style="margin-top:20px">
					<summary style="cursor:pointer;color:var(--okno-ink);font-weight:600"><?php esc_html_e( 'Lire les instructions complètes', 'okno' ); ?></summary>
					<textarea class="okno-instructions" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Instructions pour l’agent', 'okno' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
				</details>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Réglages
	 * =================================================================== */

	private static function render_settings() {
		$settings     = Okno_Plugin::settings();
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $public_types['attachment'] );
		$preflight    = Okno_Github_Preflight::consume();
		$drivers      = self::drivers();
		?>
		<div class="okno-page">

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau d'affichage. ?>
			<?php if ( isset( $_GET['okno_saved'] ) ) : ?>
				<div class="okno-notice okno-notice--ok" role="status">
					<span class="okno-dot okno-dot--ok"></span>
					<strong><?php esc_html_e( 'Réglages enregistrés.', 'okno' ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( $preflight ) : ?>
				<?php
				$levels = wp_list_pluck( $preflight, 'level' );
				$level  = in_array( 'error', $levels, true ) ? 'error' : ( in_array( 'warning', $levels, true ) ? 'warn' : 'ok' );
				?>
				<div class="okno-notice okno-notice--<?php echo esc_attr( $level ); ?>" role="status">
					<span class="okno-dot okno-dot--<?php echo esc_attr( $level ); ?>"></span>
					<div>
						<strong><?php esc_html_e( 'Contrôle de la configuration GitHub', 'okno' ); ?></strong>
						<ul>
							<?php foreach ( $preflight as $item ) : ?>
								<?php $item_level = 'warning' === $item['level'] ? 'warn' : $item['level']; ?>
								<li><span class="okno-pill okno-pill--<?php echo esc_attr( $item_level ); ?>"><?php echo esc_html( 'ok' === $item['level'] ? __( 'OK', 'okno' ) : ( 'error' === $item['level'] ? __( 'Erreur', 'okno' ) : __( 'Attention', 'okno' ) ) ); ?></span> <?php echo esc_html( $item['message'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-okno-settings>
				<input type="hidden" name="action" value="okno_save_settings">
				<?php wp_nonce_field( 'okno_save_settings' ); ?>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Votre site', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'Le site que vos clients modifient, tel que les visiteurs le voient.', 'okno' ); ?></p>

					<div class="okno-row">
						<label for="okno_front_url"><?php esc_html_e( 'Adresse du site', 'okno' ); ?></label>
						<div>
							<input type="url" id="okno_front_url" name="okno_settings[front_url]" value="<?php echo esc_attr( $settings['front_url'] ); ?>" placeholder="https://www.exemple.fr">
						</div>
					</div>
					<div class="okno-row">
						<label for="okno_preview_url"><?php esc_html_e( 'Adresse de prévisualisation', 'okno' ); ?></label>
						<div>
							<input type="url" id="okno_preview_url" name="okno_settings[preview_url]" value="<?php echo esc_attr( $settings['preview_url'] ); ?>" placeholder="https://preview.exemple.fr">
							<span class="okno-help"><?php esc_html_e( 'Facultatif. Affichée dans l’éditeur à la place du site public : utile quand le site public est généré au build et qu’une version à jour est servie ailleurs.', 'okno' ); ?></span>
						</div>
					</div>
				</section>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Contenus éditables', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'Les types de contenus proposés dans l’éditeur, et l’adresse de chacun sur le site.', 'okno' ); ?></p>

					<div class="okno-row">
						<span class="okno-row__label"><?php esc_html_e( 'Types de contenus', 'okno' ); ?></span>
						<div class="okno-checks">
							<?php foreach ( $public_types as $type ) : ?>
								<label>
									<input type="checkbox" name="okno_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="okno-row">
						<span class="okno-row__label"><?php esc_html_e( 'Adresses sur le site', 'okno' ); ?></span>
						<div>
							<div class="okno-patterns">
								<?php foreach ( $public_types as $type ) : ?>
									<div>
										<code><?php echo esc_html( $type->name ); ?></code>
										<input type="text" name="okno_settings[url_patterns][<?php echo esc_attr( $type->name ); ?>]" value="<?php echo esc_attr( isset( $settings['url_patterns'][ $type->name ] ) ? $settings['url_patterns'][ $type->name ] : '' ); ?>" placeholder="/{slug}" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: type de contenu. */ __( 'Modèle d’adresse pour %s', 'okno' ), $type->labels->name ) ); ?>">
									</div>
								<?php endforeach; ?>
							</div>
							<span class="okno-help"><?php esc_html_e( 'Variables disponibles : {slug}, {id}. Vide = /{slug}. La page d’accueil pointe toujours vers /.', 'okno' ); ?></span>
						</div>
					</div>

					<div class="okno-row">
						<label for="okno_path_meta_key"><?php esc_html_e( 'Champ contenant l’adresse', 'okno' ); ?></label>
						<div>
							<input type="text" id="okno_path_meta_key" name="okno_settings[path_meta_key]" value="<?php echo esc_attr( $settings['path_meta_key'] ); ?>" placeholder="page_path">
							<span class="okno-help"><?php esc_html_e( 'Facultatif. Nom d’un champ ACF ou d’une meta qui contient l’adresse réelle de chaque contenu (par exemple /services/audit). Prioritaire sur les modèles ci-dessus.', 'okno' ); ?></span>
						</div>
					</div>
				</section>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Mise en ligne', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'Ce qui se passe quand un client clique sur « Publier ».', 'okno' ); ?></p>

					<div class="okno-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Mode de mise en ligne', 'okno' ); ?>">
						<?php foreach ( $drivers as $value => $driver ) : ?>
							<label class="okno-choice">
								<input type="radio" name="okno_settings[deploy_driver]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['deploy_driver'], $value ); ?> data-okno-driver>
								<strong><?php echo esc_html( $driver['label'] ); ?></strong>
								<span><?php echo esc_html( $driver['help'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook">
						<div class="okno-row">
							<label for="okno_build_hook_url"><?php esc_html_e( 'URL du build hook', 'okno' ); ?></label>
							<div>
								<input type="url" id="okno_build_hook_url" name="okno_settings[build_hook_url]" value="<?php echo esc_attr( $settings['build_hook_url'] ); ?>" placeholder="https://api.netlify.com/build_hooks/…">
							</div>
						</div>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="coolify">
						<div class="okno-row">
							<label for="okno_coolify_url"><?php esc_html_e( 'URL du webhook Coolify', 'okno' ); ?></label>
							<div><input type="url" id="okno_coolify_url" name="okno_settings[coolify_url]" value="<?php echo esc_attr( $settings['coolify_url'] ); ?>"></div>
						</div>
						<?php self::secret_row( 'coolify_token', __( 'Token Coolify', 'okno' ) ); ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook coolify github_commit">
						<div class="okno-row">
							<label for="okno_build_hook_eta"><?php esc_html_e( 'Durée du build', 'okno' ); ?></label>
							<div>
								<input type="number" id="okno_build_hook_eta" name="okno_settings[build_hook_eta]" value="<?php echo esc_attr( $settings['build_hook_eta'] ); ?>" min="1" max="60"> <span class="okno-muted"><?php esc_html_e( 'minutes', 'okno' ); ?></span>
								<span class="okno-help"><?php esc_html_e( 'Affichée au client après « Publier », faute de pouvoir suivre le build en direct.', 'okno' ); ?></span>
							</div>
						</div>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="github github_commit">
						<div class="okno-row">
							<label for="okno_gh_repo"><?php esc_html_e( 'Dépôt GitHub', 'okno' ); ?></label>
							<div><input type="text" id="okno_gh_repo" name="okno_settings[gh_repo]" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" placeholder="organisation/site"></div>
						</div>
						<div class="okno-driver-fields" data-okno-driver-fields="github">
							<div class="okno-row">
								<label for="okno_gh_workflow"><?php esc_html_e( 'Fichier du workflow', 'okno' ); ?></label>
								<div><input type="text" id="okno_gh_workflow" name="okno_settings[gh_workflow]" value="<?php echo esc_attr( $settings['gh_workflow'] ); ?>" placeholder="deploy.yml"></div>
							</div>
						</div>
						<div class="okno-row">
							<label for="okno_gh_branch"><?php esc_html_e( 'Branche', 'okno' ); ?></label>
							<div><input type="text" id="okno_gh_branch" name="okno_settings[gh_branch]" value="<?php echo esc_attr( $settings['gh_branch'] ); ?>" placeholder="main"></div>
						</div>
						<?php self::secret_row( 'gh_pat', __( 'Token GitHub', 'okno' ), __( 'Token fine-grained limité à ce seul dépôt : « Actions : lecture et écriture » pour GitHub Actions, « Contents : lecture et écriture » pour le commit de publication. N’utilisez pas de token classique, il donne accès à tous vos dépôts.', 'okno' ) ); ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook coolify github github_commit">
						<div class="okno-row">
							<label for="okno_min_interval"><?php esc_html_e( 'Délai entre deux publications', 'okno' ); ?></label>
							<div>
								<input type="number" id="okno_min_interval" name="okno_settings[min_deploy_interval]" value="<?php echo esc_attr( $settings['min_deploy_interval'] ); ?>" min="0"> <span class="okno-muted"><?php esc_html_e( 'secondes', 'okno' ); ?></span>
							</div>
						</div>
					</div>
				</section>

				<div class="okno-savebar">
					<span class="okno-muted"><?php esc_html_e( 'Les secrets sont chiffrés en base avec une clé dérivée des salts WordPress.', 'okno' ); ?></span>
					<div class="okno-actions" style="margin:0">
						<?php if ( in_array( $settings['deploy_driver'], array( 'github', 'github_commit' ), true ) ) : ?>
							<button type="submit" class="okno-btn okno-btn--quiet" name="okno_test" value="1"><?php esc_html_e( 'Enregistrer et tester GitHub', 'okno' ); ?></button>
						<?php endif; ?>
						<button type="submit" class="okno-btn"><?php esc_html_e( 'Enregistrer', 'okno' ); ?></button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	private static function secret_row( $name, $label, $help = '' ) {
		$status = Okno_Secrets::status( $name );
		?>
		<div class="okno-row">
			<label for="okno_secret_<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div>
				<input type="password" id="okno_secret_<?php echo esc_attr( $name ); ?>" name="okno_secret[<?php echo esc_attr( $name ); ?>]" value="" autocomplete="new-password"
					placeholder="<?php echo 'ok' === $status ? esc_attr__( 'Enregistré — laisser vide pour le conserver', 'okno' ) : esc_attr__( 'Non renseigné', 'okno' ); ?>">
				<?php if ( 'unreadable' === $status ) : ?>
					<span class="okno-help" style="color:var(--okno-error)"><?php esc_html_e( 'Ce secret n’est plus lisible : les salts WordPress ont changé (hébergeur ou plugin de sécurité). Saisissez-le à nouveau.', 'okno' ); ?></span>
				<?php elseif ( 'ok' === $status ) : ?>
					<label class="okno-inline-check"><input type="checkbox" name="okno_clear_<?php echo esc_attr( $name ); ?>" value="1"> <?php esc_html_e( 'Supprimer ce secret', 'okno' ); ?></label>
				<?php endif; ?>
				<?php if ( $help ) : ?>
					<span class="okno-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Publications
	 * =================================================================== */

	private static function render_deploys() {
		$history = Okno_Deploy_Manager::history();
		$usage   = get_option( Okno_Plugin::OPTION_USAGE, array() );
		$usage   = is_array( $usage ) ? $usage : array();
		krsort( $usage );
		$usage = array_slice( $usage, 0, 8, true );
		?>
		<div class="okno-page">
			<h2 class="okno-heading"><?php esc_html_e( 'Historique des publications', 'okno' ); ?></h2>
			<div class="okno-card okno-card--flush">
				<?php if ( ! $history ) : ?>
					<div class="okno-empty">
						<?php Okno_Icons::render( 'rocket', 32 ); ?>
						<h3><?php esc_html_e( 'Aucune publication', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'Chaque clic sur « Publier » dans l’éditeur apparaîtra ici avec son résultat.', 'okno' ); ?></p>
					</div>
				<?php else : ?>
					<table class="okno-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Par', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Méthode', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Résultat', 'okno' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $record ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $record['started'] ) ); ?></td>
									<td><?php echo esc_html( $record['user_name'] ); ?></td>
									<td><?php echo esc_html( self::driver_label( $record['driver'] ) ); ?></td>
									<td>
										<span class="okno-pill okno-pill--<?php echo esc_attr( self::status_level( $record['status'] ) ); ?>"><?php echo esc_html( self::status_label( $record['status'] ) ); ?></span>
										<?php if ( $record['message'] ) : ?>
											<div class="okno-table__message"><?php echo esc_html( $record['message'] ); ?></div>
										<?php endif; ?>
										<?php if ( ! empty( $record['meta']['run_url'] ) ) : ?>
											<div class="okno-table__message"><a href="<?php echo esc_url( $record['meta']['run_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Voir le build', 'okno' ); ?></a></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $usage ) : ?>
				<h2 class="okno-heading" style="margin-top:40px"><?php esc_html_e( 'Activité par semaine', 'okno' ); ?></h2>
				<div class="okno-card okno-card--flush">
					<table class="okno-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Semaine', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Enregistrements', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Publications', 'okno' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $usage as $week => $counts ) : ?>
								<tr>
									<td><?php echo esc_html( $week ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $counts['save'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $counts['publish'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Libellés partagés
	 * =================================================================== */

	/**
	 * Modes de mise en ligne proposés.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function drivers() {
		return array(
			'live'          => array(
				'label' => __( 'Contenu en direct', 'okno' ),
				'help'  => __( 'Le site lit WordPress à chaque visite. Enregistrer suffit, rien à publier.', 'okno' ),
			),
			'build_hook'    => array(
				'label' => __( 'Build hook', 'okno' ),
				'help'  => __( 'Vercel, Netlify, Cloudflare Pages… Okno appelle l’URL de rebuild.', 'okno' ),
			),
			'github'        => array(
				'label' => __( 'GitHub Actions', 'okno' ),
				'help'  => __( 'Déclenche un workflow et suit le build en direct.', 'okno' ),
			),
			'github_commit' => array(
				'label' => __( 'Commit GitHub', 'okno' ),
				'help'  => __( 'Pour les hébergeurs qui déploient à chaque push.', 'okno' ),
			),
			'coolify'       => array(
				'label' => __( 'Coolify', 'okno' ),
				'help'  => __( 'Webhook de déploiement Coolify.', 'okno' ),
			),
		);
	}

	public static function driver_label( $driver ) {
		$drivers = self::drivers();
		if ( isset( $drivers[ $driver ] ) ) {
			return $drivers[ $driver ]['label'];
		}
		return '' === $driver ? __( 'Non configurée', 'okno' ) : $driver;
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'En attente', 'okno' ),
			'building'  => __( 'Build en cours', 'okno' ),
			'triggered' => __( 'Déclenchée', 'okno' ),
			'success'   => __( 'En ligne', 'okno' ),
			'error'     => __( 'Échec', 'okno' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function status_level( $status ) {
		if ( 'success' === $status || 'triggered' === $status ) {
			return 'ok';
		}
		if ( 'error' === $status ) {
			return 'error';
		}
		return 'accent';
	}

	/**
	 * Résultat du dernier test de connexion.
	 *
	 * @return array{ok:bool,version:string,fields:int,sections:int,at:int}|null
	 */
	public static function connection() {
		$value = get_option( self::OPTION_CONNECTION );
		return is_array( $value ) && isset( $value['at'] ) ? $value : null;
	}
}
