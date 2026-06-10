<?php
/**
 * One-time SKU to product ID map with duplicate detection.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Sku_Resolver {

	/**
	 * Build SKU map from a single database query.
	 *
	 * @return array{map: array<string, int>, duplicates: array<string, true>}
	 */
	public static function build_map() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value != ''",
			ARRAY_A
		);

		$map        = array();
		$duplicates = array();

		foreach ( $results as $row ) {
			$sku        = trim( (string) $row['meta_value'] );
			$product_id = (int) $row['post_id'];

			if ( '' === $sku ) {
				continue;
			}

			if ( isset( $map[ $sku ] ) ) {
				$duplicates[ $sku ] = true;
				continue;
			}

			$map[ $sku ] = $product_id;
		}

		// Remove ambiguous SKUs from the usable map.
		foreach ( array_keys( $duplicates ) as $sku ) {
			unset( $map[ $sku ] );
		}

		return array(
			'map'        => $map,
			'duplicates' => $duplicates,
		);
	}

	/**
	 * Resolve a SKU to a product ID.
	 *
	 * @param string $sku            SKU from import row.
	 * @param array  $map            Preloaded SKU map.
	 * @param array  $duplicate_skus Duplicate SKUs in store.
	 * @return array{product_id: int|null, reason: string|null}
	 */
	public static function resolve( $sku, array $map, array $duplicate_skus ) {
		if ( isset( $duplicate_skus[ $sku ] ) ) {
			return array(
				'product_id' => null,
				'reason'     => 'duplicate_sku_in_store',
			);
		}

		if ( ! isset( $map[ $sku ] ) ) {
			return array(
				'product_id' => null,
				'reason'     => 'sku_not_found',
			);
		}

		return array(
			'product_id' => (int) $map[ $sku ],
			'reason'     => null,
		);
	}
}
