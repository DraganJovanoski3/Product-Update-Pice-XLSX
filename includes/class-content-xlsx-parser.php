<?php
/**
 * XLSX parser for SKU-based content and SEO imports.
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\IOFactory;

class PUPX_Content_Xlsx_Parser {

	const FIELD_COLUMNS = array(
		'sku',
		'slug',
		'post_title',
		'full_description',
		'short_description',
		'focus_keyphrase',
		'seo_title',
		'meta_description',
		'meta_keywords',
		'product_tags',
		'cross_reference',
		'product_categories',
	);

	/**
	 * Normalize a header cell to a field key.
	 *
	 * @param string $header Header cell value.
	 * @return string
	 */
	public static function normalize_header( $header ) {
		$key = strtolower( trim( (string) $header ) );
		$key = str_replace( array( ' ', '-' ), '_', $key );
		$key = preg_replace( '/_+/', '_', $key );
		return trim( $key, '_' );
	}

	/**
	 * Parse a content import XLSX file.
	 *
	 * @param string $file_path Absolute path to XLSX file.
	 * @return array{rows: array, preview: array, total: int, columns: array}
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

		$header_row = array_shift( $raw_rows );
		$column_map = self::build_column_map( $header_row );

		if ( ! isset( $column_map['sku'] ) ) {
			throw new Exception( __( 'The XLSX file must include a "sku" column in row 1.', 'product-update-price-xlsx' ) );
		}

		$rows = array();
		foreach ( $raw_rows as $index => $row ) {
			$parsed = self::parse_data_row( $row, $column_map, $index + 2 );
			if ( null !== $parsed ) {
				$rows[] = $parsed;
			}
		}

		if ( empty( $rows ) ) {
			throw new Exception( __( 'No data rows found in the XLSX file.', 'product-update-price-xlsx' ) );
		}

		return array(
			'rows'    => $rows,
			'preview' => array_slice( $rows, 0, 5 ),
			'total'   => count( $rows ),
			'columns' => array_values( array_unique( array_values( $column_map ) ) ),
		);
	}

	/**
	 * Build column index map from header row.
	 *
	 * @param array $header_row Header cells.
	 * @return array<string, int>
	 */
	private static function build_column_map( array $header_row ) {
		$map = array();

		foreach ( $header_row as $index => $cell ) {
			$key = self::normalize_header( $cell );
			if ( '' === $key || ! in_array( $key, self::FIELD_COLUMNS, true ) ) {
				continue;
			}
			$map[ $key ] = (int) $index;
		}

		return $map;
	}

	/**
	 * Parse one data row into a field map.
	 *
	 * @param array $row        Raw row cells.
	 * @param array $column_map Header map.
	 * @param int   $row_num    Spreadsheet row number.
	 * @return array|null
	 */
	private static function parse_data_row( array $row, array $column_map, $row_num ) {
		$data = array(
			'row_num' => $row_num,
			'sku'     => '',
			'fields'  => array(),
		);

		$has_value = false;

		foreach ( $column_map as $field => $index ) {
			$value = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';

			if ( 'sku' === $field ) {
				$data['sku'] = $value;
				if ( '' !== $value ) {
					$has_value = true;
				}
				continue;
			}

			if ( '' !== $value ) {
				$data['fields'][ $field ] = $value;
				$has_value                = true;
			}
		}

		if ( ! $has_value ) {
			return null;
		}

		return $data;
	}

	/**
	 * Check if row has at least one updatable field besides SKU.
	 *
	 * @param array $row Parsed row.
	 * @return bool
	 */
	public static function has_updatable_fields( array $row ) {
		return ! empty( $row['fields'] );
	}
}
