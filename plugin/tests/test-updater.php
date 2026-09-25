<?php
/**
 * Tests des mises à jour GitHub : php plugin/tests/test-updater.php
 */
define( 'ABSPATH', true );
define( 'OKNO_FILE', '/var/www/wp-content/plugins/okno/okno.php' );
define( 'OKNO_URL', 'https://wp.example.com/wp-content/plugins/okno/' );
define( 'OKNO_VERSION', '1.0.0-beta.3' );
define( 'HOUR_IN_SECONDS', 3600 );
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return preg_match( '#^https?://#', $s ) ? htmlspecialchars( $s, ENT_QUOTES ) : ''; }
function plugin_basename( $f ) { return 'okno/okno.php'; }
function home_url() { return 'https://wp.example.com'; }
function is_wp_error( $x ) { return false; }
$GLOBALS['transients'] = array();
$GLOBALS['http_calls'] = 0;
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_remote_get( $url, $args ) { $GLOBALS['http_calls']++; return $GLOBALS['http_response']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../includes/class-okno-updater.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

$release = array(
	'tag_name'     => 'v1.0.0-beta.4',
	'html_url'     => 'https://github.com/pixelersagency/okno/releases/tag/v1.0.0-beta.4',
	'published_at' => '2026-09-26T10:00:00Z',
	'body'         => "### Added\n\n- Updates from **GitHub** in `wp-admin`\n- See [docs](https://github.com/pixelersagency/okno)\n\n<script>alert(1)</script>",
	'assets'       => array(
		array( 'name' => 'okno-1.0.0-beta.4.zip', 'browser_download_url' => 'https://github.com/x/okno-1.0.0-beta.4.zip' ),
		array( 'name' => 'okno.zip', 'browser_download_url' => 'https://github.com/x/okno.zip' ),
	),
);

/* --- from_release --- */
$u = Okno_Updater::from_release( $release );
check( 'version sans le v', '1.0.0-beta.4' === $u['version'], $u );
check( 'paquet = okno.zip', 'https://github.com/x/okno.zip' === $u['package'], $u );
check( 'slug okno', 'okno' === $u['slug'] );
check( 'release sans okno.zip ignorée', false === Okno_Updater::from_release( array( 'tag_name' => 'v2.0.0', 'assets' => array() ) ) );
check( 'brouillon ignoré', false === Okno_Updater::from_release( array_merge( $release, array( 'draft' => true ) ) ) );
check( 'bêta plus récente détectée', version_compare( $u['version'], OKNO_VERSION, '>' ) );
check( 'stable plus récente que la bêta', version_compare( '1.0.0', '1.0.0-beta.9', '>' ) );

/* --- check() : seulement pour Okno --- */
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => json_encode( $release + array( 'name' => 'x', 'extra' => str_repeat( 'x', 10 ) ) ) );
check( 'autre plugin intouché', 'other' === Okno_Updater::check( 'other', array(), 'hello/hello.php' ) );
$r = Okno_Updater::check( false, array( 'Version' => OKNO_VERSION ), 'okno/okno.php' );
check( 'mise à jour proposée', is_array( $r ) && '1.0.0-beta.4' === $r['version'], $r );
Okno_Updater::check( false, array(), 'okno/okno.php' );
check( 'API appelée une seule fois (cache)', 1 === $GLOBALS['http_calls'], $GLOBALS['http_calls'] );
check( 'cache allégé', ! isset( $GLOBALS['transients']['okno_update_release']['extra'] ) );

/* --- erreur API : rien proposé, échec mis en cache --- */
Okno_Updater::flush();
$GLOBALS['http_response'] = array( 'code' => 403, 'body' => '{"message":"rate limit"}' );
check( 'erreur API : rien', false === Okno_Updater::check( false, array(), 'okno/okno.php' ) );
Okno_Updater::check( false, array(), 'okno/okno.php' );
check( 'échec mis en cache', 2 === $GLOBALS['http_calls'], $GLOBALS['http_calls'] );

/* --- détails et notes --- */
Okno_Updater::flush();
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => json_encode( $release ) );
$d = Okno_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'okno' ) );
check( 'détails : version', '1.0.0-beta.4' === $d->version, $d );
check( 'détails : autre slug intouché', 'keep' === Okno_Updater::details( 'keep', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
$html = $d->sections['changelog'];
check( 'notes : titre et liste', false !== strpos( $html, '<h4>Added</h4>' ) && false !== strpos( $html, '<li>Updates from <strong>GitHub</strong> in <code>wp-admin</code></li>' ), $html );
check( 'notes : lien', false !== strpos( $html, '<a href="https://github.com/pixelersagency/okno">docs</a>' ), $html );
check( 'notes : HTML échappé', false === strpos( $html, '<script>' ), $html );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
