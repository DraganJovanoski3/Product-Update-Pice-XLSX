<?php
/**
 * One-time script to generate the static sample template.
 * Run: php scripts/generate-template.php
 */

define( 'ABSPATH', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dir = dirname( __DIR__ ) . '/templates';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}

$file = $dir . '/price-import-template.xlsx';

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

( new Xlsx( $spreadsheet ) )->save( $file );

echo "Created: {$file}\n";
