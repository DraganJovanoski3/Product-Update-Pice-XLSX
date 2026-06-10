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
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = PUPX_Price_Updater::reason_labels();
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/**
	 * Stream not-updated rows as CSV.
	 *
	 * @param array $not_updated Not-updated rows.
	 */
	public static function stream_csv( array $not_updated ) {
		PUPX_File_Download::stream_headers( 'not-updated-prices.csv', 'text/csv; charset=utf-8' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		fputcsv( $output, array( 'Row', 'SKU', 'Price', 'Reason' ) );

		foreach ( $not_updated as $row ) {
			fputcsv(
				$output,
				array(
					$row['row_num'],
					$row['sku'],
					$row['price'],
					self::reason_label( $row['reason'] ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Stream not-updated rows as XLSX.
	 *
	 * @param array $not_updated Not-updated rows.
	 */
	public static function stream_xlsx( array $not_updated ) {
		$spreadsheet = new Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Not Updated' );

		$sheet->fromArray( array( array( 'Row', 'SKU', 'Price', 'Reason' ) ) );

		$row_index = 2;
		foreach ( $not_updated as $row ) {
			$sheet->fromArray(
				array(
					array(
						$row['row_num'],
						$row['sku'],
						$row['price'],
						self::reason_label( $row['reason'] ),
					),
				),
				null,
				'A' . $row_index
			);
			++$row_index;
		}

		$sheet->getColumnDimension( 'A' )->setWidth( 8 );
		$sheet->getColumnDimension( 'B' )->setWidth( 20 );
		$sheet->getColumnDimension( 'C' )->setWidth( 12 );
		$sheet->getColumnDimension( 'D' )->setWidth( 30 );

		PUPX_File_Download::stream_headers(
			'not-updated-prices.xlsx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);

		$writer = new Xlsx( $spreadsheet );
		$writer->save( 'php://output' );
		exit;
	}
}
