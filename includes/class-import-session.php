<?php
/**
 * Import session storage between AJAX batches.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Import_Session {

	const TRANSIENT_PREFIX = 'pupx_import_';
	const TTL                = HOUR_IN_SECONDS;

	/**
	 * Create a new import session from parsed rows.
	 *
	 * @param array $rows Parsed import rows.
	 * @return string Session ID.
	 */
	public static function create( array $rows ) {
		$session_id = wp_generate_uuid4();

		self::save(
			$session_id,
			array(
				'rows'              => $rows,
				'total'             => count( $rows ),
				'offset'            => 0,
				'updated'           => 0,
				'skipped'           => 0,
				'not_updated'       => array(),
				'processed_skus'    => array(),
				'sku_map_loaded'    => false,
				'sku_map'           => array(),
				'duplicate_skus'    => array(),
				'complete'          => false,
				'created_at'        => time(),
			)
		);

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

		$data = get_transient( self::TRANSIENT_PREFIX . $session_id );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Save session data.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $data       Session data.
	 */
	public static function save( $session_id, array $data ) {
		set_transient( self::TRANSIENT_PREFIX . $session_id, $data, self::TTL );
	}

	/**
	 * Delete session.
	 *
	 * @param string $session_id Session ID.
	 */
	public static function delete( $session_id ) {
		delete_transient( self::TRANSIENT_PREFIX . $session_id );
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
}
