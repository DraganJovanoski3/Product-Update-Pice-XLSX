<?php
/**
 * Safe file download helpers for WordPress admin.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_File_Download {

	/**
	 * Clear output buffers before sending a file.
	 */
	public static function prepare() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}

		@ini_set( 'zlib.output_compression', 'Off' );

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
	}

	/**
	 * Send a file from disk as a download.
	 *
	 * @param string $path         Absolute file path.
	 * @param string $filename     Download filename.
	 * @param string $content_type MIME type.
	 */
	public static function send_file( $path, $filename, $content_type ) {
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'product-update-price-xlsx' ) );
		}

		self::prepare();

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Pragma: public' );

		readfile( $path );
		exit;
	}

	/**
	 * Send download headers for streamed output.
	 *
	 * @param string $filename     Download filename.
	 * @param string $content_type MIME type.
	 */
	public static function stream_headers( $filename, $content_type ) {
		self::prepare();

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Pragma: public' );
	}
}
