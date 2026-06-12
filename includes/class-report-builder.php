<?php
/**
 * Not-updated report builder and export.
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PUPX_Report_Builder {

	/**
	 * Get label for a skip reason code.
	 *
	 * @param string $reason      Reason code.
	 * @param string $import_type price or content.
	 * @return string
	 */
	public static function reason_label( $reason, $import_type = 'price' ) {
		$labels = 'content' === $import_type
			? PUPX_Content_Updater::reason_labels()
			: PUPX_Price_Updater::reason_labels();

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/**
	 * Build flat rows for export.
	 *
	 * @param array  $not_updated Not-updated rows.
	 * @param string $import_type price or content.
	 * @return array
	 */
	private static function build_export_rows( array $not_updated, $import_type = 'price' ) {
		if ( 'content' === $import_type ) {
			$rows = array( array( 'Row', 'SKU', 'Fields', 'Reason' ) );
			foreach ( $not_updated as $row ) {
				$rows[] = array(
					$row['row_num'],
					$row['sku'],
					isset( $row['fields'] ) ? $row['fields'] : '',
					self::reason_label( $row['reason'], $import_type ),
				);
			}
			return $rows;
		}

		$rows = array( array( 'Row', 'SKU', 'Price', 'Reason' ) );
		foreach ( $not_updated as $row ) {
			$rows[] = array(
				$row['row_num'],
				$row['sku'],
				isset( $row['price'] ) ? $row['price'] : '',
				self::reason_label( $row['reason'], $import_type ),
			);
		}

		return $rows;
	}

	/**
	 * Write not-updated rows to an XLSX file on disk.
	 *
	 * @param string $path        Absolute file path.
	 * @param array  $not_updated Not-updated rows.
	 * @param string $import_type price or content.
	 * @return bool
	 */
	public static function write_xlsx_file( $path, array $not_updated, $import_type = 'price' ) {
		try {
			$spreadsheet = new Spreadsheet();
			$sheet       = $spreadsheet->getActiveSheet();
			$sheet->setTitle( 'Not Updated' );
			$sheet->fromArray( self::build_export_rows( $not_updated, $import_type ) );

			$sheet->getColumnDimension( 'A' )->setWidth( 8 );
			$sheet->getColumnDimension( 'B' )->setWidth( 20 );
			$sheet->getColumnDimension( 'C' )->setWidth( 'content' === $import_type ? 30 : 12 );
			$sheet->getColumnDimension( 'D' )->setWidth( 30 );

			( new Xlsx( $spreadsheet ) )->save( $path );
			return is_readable( $path );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Write not-updated rows to a CSV file on disk.
	 *
	 * @param string $path        Absolute file path.
	 * @param array  $not_updated Not-updated rows.
	 * @param string $import_type price or content.
	 * @return bool
	 */
	public static function write_csv_file( $path, array $not_updated, $import_type = 'price' ) {
		$output = fopen( $path, 'w' );
		if ( false === $output ) {
			return false;
		}

		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		foreach ( self::build_export_rows( $not_updated, $import_type ) as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
		return is_readable( $path );
	}

	/**
	 * Generate report files for a completed import session.
	 *
	 * @param string $session_id  Session ID.
	 * @param array  $not_updated Not-updated rows.
	 * @param string $import_type price or content.
	 * @return bool
	 */
	public static function generate_reports( $session_id, array $not_updated, $import_type = 'price' ) {
		PUPX_Import_Session::raise_limits();

		$dir = PUPX_Import_Session::get_dir( $session_id );
		if ( ! $dir ) {
			return false;
		}

		$xlsx_ok = self::write_xlsx_file( trailingslashit( $dir ) . 'not-updated.xlsx', $not_updated, $import_type );
		$csv_ok  = self::write_csv_file( trailingslashit( $dir ) . 'not-updated.csv', $not_updated, $import_type );

		return $xlsx_ok && $csv_ok;
	}

	/**
	 * Get path to a generated report file.
	 *
	 * @param string $session_id Session ID.
	 * @param string $format     xlsx or csv.
	 * @return string|null
	 */
	public static function get_report_path( $session_id, $format ) {
		$dir = PUPX_Import_Session::get_dir( $session_id );
		if ( ! $dir ) {
			return null;
		}

		$filename = 'csv' === $format ? 'not-updated.csv' : 'not-updated.xlsx';
		$path     = trailingslashit( $dir ) . $filename;

		return is_readable( $path ) ? $path : null;
	}

	/**
	 * Get download filename for report.
	 *
	 * @param string $import_type price or content.
	 * @param string $format      xlsx or csv.
	 * @return string
	 */
	public static function report_filename( $import_type, $format ) {
		$base = 'content' === $import_type ? 'not-updated-content' : 'not-updated-prices';
		return $base . ( 'csv' === $format ? '.csv' : '.xlsx' );
	}
}
