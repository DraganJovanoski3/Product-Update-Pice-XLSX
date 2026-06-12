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
	public static function create( array $rows, $import_type = 'price' ) {
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
			'import_type'    => in_array( $import_type, array( 'price', 'content' ), true ) ? $import_type : 'price',
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

		self::write_json( trailingslashit( $dir ) . 'processed-skus.json', array() );
		file_put_contents( trailingslashit( $dir ) . 'not-updated.jsonl', '' );

		return $session_id;
	}

	/**
	 * Load session data for batch processing (avoids loading full not-updated list).
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	public static function get_for_batch( $session_id ) {
		$dir = self::get_dir( $session_id );
		if ( ! $dir ) {
			return null;
		}

		$meta = self::read_json( trailingslashit( $dir ) . 'meta.json' );
		$rows = self::read_json( trailingslashit( $dir ) . 'rows.json' );

		if ( null === $meta || null === $rows ) {
			return null;
		}

		$processed_skus = self::read_json( trailingslashit( $dir ) . 'processed-skus.json' );

		return array_merge(
			$meta,
			array(
				'rows'           => $rows,
				'processed_skus' => is_array( $processed_skus ) ? $processed_skus : array(),
				'sku_map'        => array(),
				'duplicate_skus' => array(),
				'not_updated'    => array(),
			)
		);
	}

	/**
	 * Get full session data including not-updated rows.
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	public static function get( $session_id ) {
		$session = self::get_for_batch( $session_id );
		if ( ! $session ) {
			return null;
		}

		$session['not_updated'] = self::read_not_updated( $session_id );
		$sku_data               = self::read_json( trailingslashit( self::get_dir( $session_id ) ) . 'sku-map.json' );

		if ( is_array( $sku_data ) ) {
			$session['sku_map']        = isset( $sku_data['map'] ) ? $sku_data['map'] : array();
			$session['duplicate_skus'] = isset( $sku_data['duplicates'] ) ? $sku_data['duplicates'] : array();
		}

		return $session;
	}

	/**
	 * Read all not-updated rows from session storage.
	 *
	 * @param string $session_id Session ID.
	 * @return array
	 */
	public static function read_not_updated( $session_id ) {
		$dir = self::get_dir( $session_id );
		if ( ! $dir ) {
			return array();
		}

		$jsonl_path = trailingslashit( $dir ) . 'not-updated.jsonl';
		if ( is_readable( $jsonl_path ) && filesize( $jsonl_path ) > 0 ) {
			$rows  = array();
			$lines = file( $jsonl_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					$row = json_decode( $line, true );
					if ( is_array( $row ) ) {
						$rows[] = $row;
					}
				}
			}

			return $rows;
		}

		$legacy = self::read_json( trailingslashit( $dir ) . 'not-updated.json' );
		return is_array( $legacy ) ? $legacy : array();
	}

	/**
	 * Append not-updated rows to session log file.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $entries    Rows to append.
	 * @return bool
	 */
	public static function append_not_updated( $session_id, array $entries ) {
		if ( empty( $entries ) ) {
			return true;
		}

		$dir = self::get_dir( $session_id );
		if ( ! $dir ) {
			return false;
		}

		$path = trailingslashit( $dir ) . 'not-updated.jsonl';
		$fp   = fopen( $path, 'a' );

		if ( false === $fp ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			fwrite( $fp, wp_json_encode( $entry ) . "\n" );
		}

		fclose( $fp );
		return true;
	}

	/**
	 * Save batch progress without rewriting large arrays.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $session    Session data.
	 * @param array  $new_skipped New not-updated entries from this batch.
	 * @return bool
	 */
	public static function save_batch( $session_id, array $session, array $new_skipped = array() ) {
		$dir = self::get_dir( $session_id );
		if ( ! $dir ) {
			return false;
		}

		$meta = array(
			'import_type'    => isset( $session['import_type'] ) ? $session['import_type'] : 'price',
			'total'          => (int) $session['total'],
			'offset'         => (int) $session['offset'],
			'updated'        => (int) $session['updated'],
			'skipped'        => (int) $session['skipped'],
			'complete'       => ! empty( $session['complete'] ),
			'sku_map_loaded' => ! empty( $session['sku_map_loaded'] ),
			'created_at'     => isset( $session['created_at'] ) ? (int) $session['created_at'] : time(),
		);

		$ok = self::write_json( trailingslashit( $dir ) . 'meta.json', $meta );
		$ok = self::write_json( trailingslashit( $dir ) . 'processed-skus.json', $session['processed_skus'] ) && $ok;
		$ok = self::append_not_updated( $session_id, $new_skipped ) && $ok;

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
	 * Ensure SKU map file exists for this session.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $session    Session data (passed by reference).
	 */
	public static function ensure_sku_map( $session_id, array &$session ) {
		$dir  = self::get_dir( $session_id );
		$path = trailingslashit( $dir ) . 'sku-map.json';

		if ( is_readable( $path ) ) {
			$data = self::read_json( $path );
			if ( is_array( $data ) ) {
				$session['sku_map']        = isset( $data['map'] ) ? $data['map'] : array();
				$session['duplicate_skus'] = isset( $data['duplicates'] ) ? $data['duplicates'] : array();
				$session['sku_map_loaded'] = true;
				return;
			}
		}

		$resolved = PUPX_Sku_Resolver::build_map();
		self::write_json(
			$path,
			array(
				'map'        => $resolved['map'],
				'duplicates' => $resolved['duplicates'],
			)
		);

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

		@ini_set( 'memory_limit', '768M' );
	}

	/**
	 * Reduce WooCommerce overhead during batch updates.
	 *
	 * @param bool $defer Whether to defer heavy WC work.
	 */
	public static function set_wc_deferred( $defer ) {
		if ( function_exists( 'wc_deferred_product_sync' ) ) {
			wc_deferred_product_sync( (bool) $defer );
		}

		wp_defer_term_counting( (bool) $defer );
		wp_defer_comment_counting( (bool) $defer );
	}
}
