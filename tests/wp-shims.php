<?php
/**
 * Minimal WordPress + EDD shims so the store extension can be unit tested
 * without a WordPress install. Only what the exercised code paths touch —
 * the same approach as the client library's test suite.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;

		public function __construct( int $id ) {
			$this->ID = $id;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;

		public function __construct( string $code = 'error' ) {
			$this->code = $code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// ---- Post meta / posts -----------------------------------------------------

function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['__wp_post_meta'][ $post_id ][ $key ] ?? '';

	return $value;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['__wp_post_meta'][ $post_id ][ $key ] = $value;

	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['__wp_post_meta'][ $post_id ][ $key ] );

	return true;
}

function get_attached_file( $attachment_id ) {
	return $GLOBALS['__wp_attached_files'][ $attachment_id ] ?? false;
}

// ---- Options ----------------------------------------------------------------

function get_option( $key, $default = false ) {
	return $GLOBALS['__wp_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['__wp_options'][ $key ] = $value;

	return true;
}

function get_post_type( $post_id ) {
	return $GLOBALS['__wp_post_types'][ $post_id ] ?? false;
}

function get_page_by_path( $path, $output = OBJECT, $post_type = 'post' ) {
	return $GLOBALS['__wp_pages_by_path'][ $path ] ?? null;
}

function get_post_field( $field, $post_id ) {
	return $GLOBALS['__wp_post_fields'][ $post_id ][ $field ] ?? '';
}

function get_the_title( $post_id ) {
	return $GLOBALS['__wp_post_fields'][ $post_id ]['post_title'] ?? '';
}

// ---- EDD --------------------------------------------------------------------

function edd_get_download_files( $download_id ) {
	return $GLOBALS['__edd_download_files'][ $download_id ] ?? array();
}

// ---- Hooks ------------------------------------------------------------------

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__wp_hooks'][] = array( 'tag' => $tag, 'callback' => $callback, 'priority' => $priority );

	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

function apply_filters( $tag, $value, ...$args ) {
	$override = $GLOBALS['__wp_filter_overrides'][ $tag ] ?? null;

	return null !== $override ? $override : $value;
}

// ---- HTTP -------------------------------------------------------------------

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__wp_http_requests'][] = $url;

	return $GLOBALS['__wp_http_responses'][ $url ] ?? new WP_Error( 'http_failure' );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
}

// ---- Uploads / users / caps ---------------------------------------------------

function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['__wp_uploads_basedir'],
		'baseurl' => 'https://store.example/wp-content/uploads',
	);
}

function current_user_can( $cap, ...$args ) {
	return in_array( $cap, $GLOBALS['__wp_user_caps'], true );
}

function get_current_user_id() {
	return $GLOBALS['__wp_current_user_id'] ?? 1;
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['__wp_user_meta'][ $user_id ][ $key ] = $value;

	return true;
}

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['__wp_user_meta'][ $user_id ][ $key ] ?? '';
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['__wp_user_meta'][ $user_id ][ $key ] );

	return true;
}

// ---- Cron ---------------------------------------------------------------------

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['__wp_scheduled'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return $event['time'];
		}
	}

	return false;
}

function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['__wp_scheduled'][] = array(
		'time' => $time,
		'hook' => $hook,
		'args' => $args,
	);

	return true;
}

function wp_is_post_revision( $post_id ) {
	return in_array( $post_id, $GLOBALS['__wp_revisions'], true );
}

function wp_is_post_autosave( $post_id ) {
	return in_array( $post_id, $GLOBALS['__wp_autosaves'], true );
}

// ---- Escaping / misc ------------------------------------------------------------

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/** Fixed sentinel value: tests set this literal string to simulate a passing nonce. */
function wp_verify_nonce( $nonce, $action = -1 ) {
	return 'test-nonce' === $nonce ? 1 : false;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce" />';

	if ( $echo ) {
		echo $field;
	}

	return $field;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_title( $title ) {
	return strtolower( preg_replace( '/[^A-Za-z0-9-]+/', '-', (string) $title ) );
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_nonce_url( $url, $action = -1 ) {
	return $url . '&_wpnonce=test';
}

function admin_url( $path = '' ) {
	return 'https://store.example/wp-admin/' . $path;
}

function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ) {
	$GLOBALS['__wp_meta_boxes'][] = $id;
}

/**
 * Reset all shim state between tests.
 */
function srfe_shims_reset(): void {
	$GLOBALS['__wp_post_meta']        = array();
	$GLOBALS['__wp_post_types']       = array();
	$GLOBALS['__wp_pages_by_path']    = array();
	$GLOBALS['__wp_post_fields']      = array();
	$GLOBALS['__edd_download_files']  = array();
	$GLOBALS['__wp_hooks']            = array();
	$GLOBALS['__wp_filter_overrides'] = array();
	$GLOBALS['__wp_http_requests']    = array();
	$GLOBALS['__wp_http_responses']   = array();
	$GLOBALS['__wp_user_caps']        = array( 'edit_products' );
	$GLOBALS['__wp_current_user_id']  = 1;
	$GLOBALS['__wp_user_meta']        = array();
	$GLOBALS['__wp_scheduled']        = array();
	$GLOBALS['__wp_revisions']        = array();
	$GLOBALS['__wp_autosaves']        = array();
	$GLOBALS['__wp_meta_boxes']       = array();
	$GLOBALS['__wp_attached_files']   = array();
	$GLOBALS['__wp_options']          = array();
	$_POST                            = array();
}
