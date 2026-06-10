<?php
/**
 * File-based import session storage (handles large imports reliably).
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Import_Session {

	const TTL = DAY_IN_SECONDS;

	/**
	 * Get storage directory for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return string|null
	 */
	public static function get_dir( $session_id ) {
		if ( empty( $session_id ) ) {
			return null;
		}

		$dir = self::session_dir( $session_id );
		return is_dir( $dir ) ? $dir : null;
	}

	/**
	 * Get storage directory for a session.
	 *
	 * @param string $session_id Session ID.
	 * @return string
	 */
	private static function session_dir( $session_id ) {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'pupx-imports';

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
			self::write_dir_protection( $base );
		}

		return trailingslashit( $base ) . sanitize_file_name( $session_id );
	}

	/**
	 * Write index.html and .htaccess to block direct web access.
	 *
	 * @param string $dir Directory path.
	 */
	private static function write_dir_protection( $dir ) {
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Read JSON file.
	 *
	 * @param string $path File path.
	 * @return array|null
	 */
	private static function read_json( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$data = json_decode( file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write JSON file.
	 *
	 * @param string $path File path.
	 * @param array  $data Data to store.
	 * @return bool
	 */
	private static function write_json( $path, array $data ) {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return false;
		}

		return false !== file_put_contents( $path, $json, LOCK_EX );
	}

	/**
	 * Remove expired import sessions.
	 */
	public static function cleanup_old_sessions() {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'pupx-imports';

		if ( ! is_dir( $base ) ) {
			return;
		}

		$cutoff = time() - self::TTL;
		$dirs   = glob( trailingslashit( $base ) . '*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			$meta = self::read_json( trailingslashit( $dir ) . 'meta.json' );
			$time = isset( $meta['created_at'] ) ? (int) $meta['created_at'] : filemtime( $dir );

			if ( $time < $cutoff ) {
				self::delete_dir( $dir );
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function delete_dir( $dir ) {
		$items = glob( trailingslashit( $dir ) . '*' );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_dir( $item ) ) {
					self::delete_dir( $item );
				} else {
					wp_delete_file( $item );
				}
			}
		}

		@rmdir( $dir );
	}

	/**
	 * Create a new import session from parsed rows.
	 *
	 * @param array $rows Parsed import rows.
	 * @return string Session ID.
	 * @throws Exception When session cannot be created.
	 */
	public static function create( array $rows ) {
		self::cleanup_old_sessions();

		$session_id = wp_generate_uuid4();
		$dir        = self::session_dir( $session_id );

		if ( ! wp_mkdir_p( $dir ) ) {
			throw new Exception( __( 'Could not create import session directory.', 'product-update-price-xlsx' ) );
		}

		self::write_dir_protection( dirname( $dir ) );
		self::write_dir_protection( $dir );

		if ( ! self::write_json( trailingslashit( $dir ) . 'rows.json', $rows ) ) {
			throw new Exception( __( 'Could not save import rows.', 'product-update-price-xlsx' ) );
		}

		$meta = array(
			'total'          => count( $rows ),
			'offset'         => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'complete'       => false,
			'sku_map_loaded' => false,
			'created_at'     => time(),
		);

		if ( ! self::write_json( trailingslashit( $dir ) . 'meta.json', $meta ) ) {
			throw new Exception( __( 'Could not save import session.', 'product-update-price-xlsx' ) );
		}

		self::write_json( trailingslashit( $dir ) . 'not-updated.json', array() );
		self::write_json( trailingslashit( $dir ) . 'processed-skus.json', array() );

		return $session_id;
	}

	/**
	 * Get session data.
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	public static function get( $session_id ) {
		if ( empty( $session_id ) ) {
			return null;
		}

		$dir = self::session_dir( $session_id );
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$meta = self::read_json( trailingslashit( $dir ) . 'meta.json' );
		$rows = self::read_json( trailingslashit( $dir ) . 'rows.json' );

		if ( null === $meta || null === $rows ) {
			return null;
		}

		$not_updated    = self::read_json( trailingslashit( $dir ) . 'not-updated.json' );
		$processed_skus = self::read_json( trailingslashit( $dir ) . 'processed-skus.json' );
		$sku_data       = self::read_json( trailingslashit( $dir ) . 'sku-map.json' );

		$session = array_merge(
			$meta,
			array(
				'rows'           => $rows,
				'not_updated'    => is_array( $not_updated ) ? $not_updated : array(),
				'processed_skus' => is_array( $processed_skus ) ? $processed_skus : array(),
				'sku_map'        => array(),
				'duplicate_skus' => array(),
			)
		);

		if ( is_array( $sku_data ) ) {
			$session['sku_map']        = isset( $sku_data['map'] ) ? $sku_data['map'] : array();
			$session['duplicate_skus'] = isset( $sku_data['duplicates'] ) ? $sku_data['duplicates'] : array();
		}

		return $session;
	}

	/**
	 * Save session data.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $session    Session data.
	 * @return bool
	 */
	public static function save( $session_id, array $session ) {
		$dir = self::session_dir( $session_id );
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$meta = array(
			'total'          => (int) $session['total'],
			'offset'         => (int) $session['offset'],
			'updated'        => (int) $session['updated'],
			'skipped'        => (int) $session['skipped'],
			'complete'       => ! empty( $session['complete'] ),
			'sku_map_loaded' => ! empty( $session['sku_map_loaded'] ),
			'created_at'     => isset( $session['created_at'] ) ? (int) $session['created_at'] : time(),
		);

		$ok  = self::write_json( trailingslashit( $dir ) . 'meta.json', $meta );
		$ok  = self::write_json( trailingslashit( $dir ) . 'not-updated.json', $session['not_updated'] ) && $ok;
		$ok  = self::write_json( trailingslashit( $dir ) . 'processed-skus.json', $session['processed_skus'] ) && $ok;

		if ( ! empty( $session['sku_map_loaded'] ) ) {
			$ok = self::write_json(
				trailingslashit( $dir ) . 'sku-map.json',
				array(
					'map'        => $session['sku_map'],
					'duplicates' => $session['duplicate_skus'],
				)
			) && $ok;
		}

		return $ok;
	}

	/**
	 * Delete session.
	 *
	 * @param string $session_id Session ID.
	 */
	public static function delete( $session_id ) {
		$dir = self::session_dir( $session_id );
		if ( is_dir( $dir ) ) {
			self::delete_dir( $dir );
		}
	}

	/**
	 * Ensure SKU map is loaded once per session.
	 *
	 * @param array $session Session data (passed by reference).
	 */
	public static function ensure_sku_map( array &$session ) {
		if ( ! empty( $session['sku_map_loaded'] ) ) {
			return;
		}

		$resolved = PUPX_Sku_Resolver::build_map();
		$session['sku_map']        = $resolved['map'];
		$session['duplicate_skus'] = $resolved['duplicates'];
		$session['sku_map_loaded'] = true;
	}

	/**
	 * Extend PHP limits for long-running import steps.
	 */
	public static function raise_limits() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		} elseif ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		@ini_set( 'memory_limit', '512M' );
	}
}
