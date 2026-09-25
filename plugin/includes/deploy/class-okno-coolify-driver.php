<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 2 : webhook Coolify (fronts hébergés sur VPS).
 * POST avec token Bearer. Pas de statut en v1.
 */
class Okno_Coolify_Driver implements Okno_Deploy_Driver_Interface {

	const SECRET_NAME = 'coolify_token';

	public function get_id() {
		return 'coolify';
	}

	public function supports_status() {
		return false;
	}

	public function check_config() {
		$settings = Okno_Plugin::settings();
		if ( '' === $settings['coolify_url'] ) {
			return new WP_Error( 'okno_deploy_config', __( 'No Coolify webhook URL set.', 'okno' ) );
		}
		if ( 'unreadable' === Okno_Secrets::status( self::SECRET_NAME ) ) {
			return new WP_Error(
				'okno_secret_unreadable',
				__( 'The Coolify token can’t be read (the WordPress salts have probably changed). Enter it again in Okno settings.', 'okno' )
			);
		}
		if ( null === Okno_Secrets::get( self::SECRET_NAME ) ) {
			return new WP_Error( 'okno_deploy_config', __( 'No Coolify token set.', 'okno' ) );
		}
		return true;
	}

	public function trigger( array $record ) {
		$settings = Okno_Plugin::settings();
		$token    = Okno_Secrets::get( self::SECRET_NAME );

		$response = wp_remote_post(
			$settings['coolify_url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'okno_deploy_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'okno_deploy_failed',
				sprintf( /* translators: %d: HTTP status code. */ __( 'Coolify responded with %d.', 'okno' ), $code )
			);
		}

		$record['status'] = 'triggered';
		$record['eta']    = max( 1, (int) Okno_Plugin::settings()['build_hook_eta'] );
		return $record;
	}

	public function get_status( array $record ) {
		return $record;
	}
}
