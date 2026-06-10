<?php
/**
 * Safe per-row regular price update logic.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Price_Updater {

	/**
	 * Human-readable skip reason labels.
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels() {
		return array(
			'empty_sku'              => __( 'Empty SKU', 'product-update-price-xlsx' ),
			'invalid_price'          => __( 'Invalid price', 'product-update-price-xlsx' ),
			'sku_not_found'          => __( 'SKU not found in store', 'product-update-price-xlsx' ),
			'duplicate_sku_in_store' => __( 'Duplicate SKU in store', 'product-update-price-xlsx' ),
			'duplicate_sku_in_file'  => __( 'Duplicate SKU in import file', 'product-update-price-xlsx' ),
			'sku_mismatch'           => __( 'SKU mismatch after load', 'product-update-price-xlsx' ),
			'product_not_loadable'   => __( 'Product could not be loaded', 'product-update-price-xlsx' ),
		);
	}

	/**
	 * Process a batch of import rows.
	 *
	 * @param array $session Session data (modified in place).
	 * @param int   $batch_size Number of rows per batch.
	 * @return array Batch result stats.
	 */
	public static function process_batch( array &$session, $batch_size = PUPX_BATCH_SIZE ) {
		PUPX_Import_Session::ensure_sku_map( $session );

		$rows     = $session['rows'];
		$offset   = (int) $session['offset'];
		$total    = (int) $session['total'];
		$end      = min( $offset + $batch_size, $total );
		$updated  = 0;
		$skipped  = 0;

		for ( $i = $offset; $i < $end; $i++ ) {
			$row    = $rows[ $i ];
			$result = self::process_row( $row, $session );

			if ( 'updated' === $result['status'] ) {
				++$updated;
			} else {
				++$skipped;
				$session['not_updated'][] = array(
					'row_num' => $row['row_num'],
					'sku'     => $row['sku'],
					'price'   => $row['price'],
					'reason'  => $result['reason'],
				);
			}
		}

		$session['offset']  = $end;
		$session['updated'] += $updated;
		$session['skipped'] += $skipped;
		$session['complete'] = $session['offset'] >= $total;

		return array(
			'processed' => $end,
			'total'     => $total,
			'updated'   => $session['updated'],
			'skipped'   => $session['skipped'],
			'complete'  => $session['complete'],
		);
	}

	/**
	 * Process a single import row.
	 *
	 * @param array $row     Import row.
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

		$price = PUPX_Xlsx_Parser::normalize_price( $row['price'] );
		if ( null === $price ) {
			return array(
				'status' => 'skipped',
				'reason' => 'invalid_price',
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

		$product->set_regular_price( $price );
		$product->save();

		return array(
			'status' => 'updated',
		);
	}
}
