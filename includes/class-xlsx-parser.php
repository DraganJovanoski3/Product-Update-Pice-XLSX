<?php
/**
 * XLSX file parser for SKU + regular price imports.
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PUPX_Xlsx_Parser {

	const HEADER_PATTERNS = array( 'sku', 'product sku', 'product_sku', 'code', 'product code', 'product_code' );
	const PRICE_PATTERNS  = array( 'regular price', 'regular_price', 'price', 'regular', 'regularprice' );

	/**
	 * Parse an uploaded XLSX file into normalized rows.
	 *
	 * @param string $file_path Absolute path to XLSX file.
	 * @return array{rows: array, preview: array, total: int}
	 * @throws Exception When file cannot be read.
	 */
	public static function parse_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			throw new Exception( __( 'Uploaded file not found.', 'product-update-price-xlsx' ) );
		}

		$spreadsheet = IOFactory::load( $file_path );
		$sheet       = $spreadsheet->getActiveSheet();
		$raw_rows    = $sheet->toArray( null, true, true, false );

		if ( empty( $raw_rows ) ) {
			throw new Exception( __( 'The XLSX file is empty.', 'product-update-price-xlsx' ) );
		}

		$start_index = 0;
		if ( self::is_header_row( $raw_rows[0] ) ) {
			$start_index = 1;
		}

		$rows = array();
		for ( $i = $start_index, $len = count( $raw_rows ); $i < $len; $i++ ) {
			$row = $raw_rows[ $i ];

			$sku   = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			$price = isset( $row[1] ) ? trim( (string) $row[1] ) : '';

			if ( '' === $sku && '' === $price ) {
				continue;
			}

			$rows[] = array(
				'row_num' => $i + 1,
				'sku'     => $sku,
				'price'   => $price,
			);
		}

		if ( empty( $rows ) ) {
			throw new Exception( __( 'No data rows found in the XLSX file.', 'product-update-price-xlsx' ) );
		}

		return array(
			'rows'    => $rows,
			'preview' => array_slice( $rows, 0, 5 ),
			'total'   => count( $rows ),
		);
	}

	/**
	 * Detect if the first row is a header row.
	 *
	 * @param array $row First row cells.
	 * @return bool
	 */
	private static function is_header_row( array $row ) {
		$col_a = isset( $row[0] ) ? strtolower( trim( (string) $row[0] ) ) : '';
		$col_b = isset( $row[1] ) ? strtolower( trim( (string) $row[1] ) ) : '';

		$sku_match   = in_array( $col_a, self::HEADER_PATTERNS, true );
		$price_match = in_array( $col_b, self::PRICE_PATTERNS, true );

		return $sku_match || $price_match;
	}

	/**
	 * Normalize a price string to a decimal number.
	 *
	 * @param string $price Raw price value.
	 * @return string|null Formatted decimal or null if invalid.
	 */
	public static function normalize_price( $price ) {
		if ( '' === $price || null === $price ) {
			return null;
		}

		$price = trim( (string) $price );
		$price = str_replace( array( ' ', "\xc2\xa0" ), '', $price );
		$price = preg_replace( '/[^\d,.\-]/', '', $price );

		if ( '' === $price ) {
			return null;
		}

		if ( preg_match( '/^\d{1,3}(\.\d{3})*,\d+$/', $price ) ) {
			$price = str_replace( '.', '', $price );
			$price = str_replace( ',', '.', $price );
		} elseif ( false !== strpos( $price, ',' ) && false === strpos( $price, '.' ) ) {
			$price = str_replace( ',', '.', $price );
		}

		if ( ! is_numeric( $price ) ) {
			return null;
		}

		$value = (float) $price;
		if ( $value < 0 ) {
			return null;
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value );
		}

		return number_format( $value, 2, '.', '' );
	}

	/**
	 * Generate a sample import template XLSX and stream it to the browser.
	 */
	public static function stream_sample_template() {
		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Price Import' );
		$sheet->fromArray(
			array(
				array( 'SKU', 'Regular Price' ),
				array( 'ABC-123', '19.99' ),
				array( 'VAR-001', '45.00' ),
			)
		);

		$sheet->getColumnDimension( 'A' )->setWidth( 20 );
		$sheet->getColumnDimension( 'B' )->setWidth( 15 );

		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="price-import-template.xlsx"' );
		header( 'Cache-Control: max-age=0' );

		$writer = new Xlsx( $spreadsheet );
		$writer->save( 'php://output' );
		exit;
	}
}
