<?php
/**
 * SKU-based product content and Yoast SEO updater.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Content_Updater {

	const YOAST_META = array(
		'focus_keyphrase'  => '_yoast_wpseo_focuskw',
		'seo_title'        => '_yoast_wpseo_title',
		'meta_description' => '_yoast_wpseo_metadesc',
		'meta_keywords'    => '_yoast_wpseo_metakeywords',
	);

	/**
	 * Human-readable skip reason labels.
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels() {
		return array(
			'empty_sku'              => __( 'Empty SKU', 'product-update-price-xlsx' ),
			'no_fields_to_update'    => __( 'No fields to update', 'product-update-price-xlsx' ),
			'sku_not_found'          => __( 'SKU not found in store', 'product-update-price-xlsx' ),
			'duplicate_sku_in_store' => __( 'Duplicate SKU in store', 'product-update-price-xlsx' ),
			'duplicate_sku_in_file'  => __( 'Duplicate SKU in import file', 'product-update-price-xlsx' ),
			'sku_mismatch'           => __( 'SKU mismatch after load', 'product-update-price-xlsx' ),
			'product_not_loadable'   => __( 'Product could not be loaded', 'product-update-price-xlsx' ),
			'slug_conflict'          => __( 'Slug already used by another product', 'product-update-price-xlsx' ),
		);
	}

	/**
	 * Whether Yoast SEO is available.
	 *
	 * @return bool
	 */
	public static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	/**
	 * Process a batch of content import rows.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $session    Session data (modified in place).
	 * @param int    $batch_size Batch size.
	 * @return array
	 */
	public static function process_batch( $session_id, array &$session, $batch_size = PUPX_BATCH_SIZE ) {
		PUPX_Import_Session::ensure_sku_map( $session_id, $session );

		$rows        = $session['rows'];
		$offset      = (int) $session['offset'];
		$total       = (int) $session['total'];
		$end         = min( $offset + $batch_size, $total );
		$updated     = 0;
		$skipped     = 0;
		$new_skipped = array();

		for ( $i = $offset; $i < $end; $i++ ) {
			$row    = $rows[ $i ];
			$result = self::process_row( $row, $session );

			if ( 'updated' === $result['status'] ) {
				++$updated;
			} else {
				++$skipped;
				$new_skipped[] = array(
					'row_num' => $row['row_num'],
					'sku'     => isset( $row['sku'] ) ? $row['sku'] : '',
					'fields'  => isset( $row['fields'] ) ? implode( ', ', array_keys( $row['fields'] ) ) : '',
					'reason'  => $result['reason'],
				);
			}
		}

		unset( $session['rows'] );

		$session['offset']      = $end;
		$session['updated']    += $updated;
		$session['skipped']    += $skipped;
		$session['complete']    = $session['offset'] >= $total;
		$session['new_skipped'] = $new_skipped;

		return array(
			'processed' => $end,
			'total'     => $total,
			'updated'   => $session['updated'],
			'skipped'   => $session['skipped'],
			'complete'  => $session['complete'],
		);
	}

	/**
	 * Process a single content import row.
	 *
	 * @param array $row     Parsed row.
	 * @param array $session Session data.
	 * @return array{status: string, reason?: string}
	 */
	public static function process_row( array $row, array &$session ) {
		$sku = trim( (string) $row['sku'] );

		if ( '' === $sku ) {
			return array(
				'status' => 'skipped',
				'reason' => 'empty_sku',
			);
		}

		if ( ! PUPX_Content_Xlsx_Parser::has_updatable_fields( $row ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_fields_to_update',
			);
		}

		if ( isset( $session['processed_skus'][ $sku ] ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'duplicate_sku_in_file',
			);
		}

		$session['processed_skus'][ $sku ] = true;

		$resolved = PUPX_Sku_Resolver::resolve( $sku, $session['sku_map'], $session['duplicate_skus'] );
		if ( null !== $resolved['reason'] ) {
			return array(
				'status' => 'skipped',
				'reason' => $resolved['reason'],
			);
		}

		$product = wc_get_product( $resolved['product_id'] );
		if ( ! $product instanceof WC_Product ) {
			return array(
				'status' => 'skipped',
				'reason' => 'product_not_loadable',
			);
		}

		if ( trim( (string) $product->get_sku() ) !== $sku ) {
			return array(
				'status' => 'skipped',
				'reason' => 'sku_mismatch',
			);
		}

		$fields = $row['fields'];

		if ( isset( $fields['slug'] ) ) {
			$slug = sanitize_title( $fields['slug'] );
			if ( self::slug_in_use( $slug, $product->get_id() ) ) {
				return array(
					'status' => 'skipped',
					'reason' => 'slug_conflict',
				);
			}
			$product->set_slug( $slug );
		}

		if ( isset( $fields['post_title'] ) ) {
			$product->set_name( $fields['post_title'] );
		}

		if ( isset( $fields['full_description'] ) ) {
			$product->set_description( $fields['full_description'] );
		}

		if ( isset( $fields['short_description'] ) ) {
			$product->set_short_description( $fields['short_description'] );
		}

		$product->save();

		$product_id = $product->get_id();

		self::apply_yoast_meta( $product_id, $fields );

		if ( isset( $fields['cross_reference'] ) ) {
			update_post_meta( $product_id, 'cross_reference', $fields['cross_reference'] );
		}

		if ( isset( $fields['product_tags'] ) ) {
			self::apply_product_tags( $product_id, $fields['product_tags'] );
		}

		if ( isset( $fields['product_categories'] ) ) {
			self::apply_product_categories( $product_id, $fields['product_categories'] );
		}

		return array(
			'status' => 'updated',
		);
	}

	/**
	 * Check if slug belongs to another product.
	 *
	 * @param string $slug       Proposed slug.
	 * @param int    $product_id Current product ID.
	 * @return bool
	 */
	private static function slug_in_use( $slug, $product_id ) {
		if ( '' === $slug ) {
			return false;
		}

		$existing = get_page_by_path( $slug, OBJECT, 'product' );
		return $existing instanceof WP_Post && (int) $existing->ID !== (int) $product_id;
	}

	/**
	 * Apply Yoast SEO meta fields.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $fields     Row fields.
	 */
	private static function apply_yoast_meta( $product_id, array $fields ) {
		if ( ! self::is_yoast_active() ) {
			return;
		}

		foreach ( self::YOAST_META as $field_key => $meta_key ) {
			if ( isset( $fields[ $field_key ] ) ) {
				update_post_meta( $product_id, $meta_key, $fields[ $field_key ] );
			}
		}
	}

	/**
	 * Apply product tags from comma-separated names.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $value      Comma-separated tag names.
	 */
	private static function apply_product_tags( $product_id, $value ) {
		$names = self::split_list( $value );
		if ( empty( $names ) ) {
			return;
		}

		wp_set_object_terms( $product_id, $names, 'product_tag', false );
	}

	/**
	 * Apply existing product categories from comma-separated names.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $value      Comma-separated category names.
	 */
	private static function apply_product_categories( $product_id, $value ) {
		$names = self::split_list( $value );
		if ( empty( $names ) ) {
			return;
		}

		$term_ids = array();
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
		}
	}

	/**
	 * Split comma-separated list and trim values.
	 *
	 * @param string $value Raw list.
	 * @return array
	 */
	private static function split_list( $value ) {
		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		return array_values( array_filter( $parts, static function ( $part ) {
			return '' !== $part;
		} ) );
	}
}
