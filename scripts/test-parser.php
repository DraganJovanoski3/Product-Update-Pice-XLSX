<?php
/**
 * Standalone parser test (no WordPress required).
 * Run: php scripts/test-parser.php
 */

define( 'ABSPATH', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-xlsx-parser.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pupx-test';
if ( ! is_dir( $tmp_dir ) ) {
	mkdir( $tmp_dir );
}

// Price normalization tests.
assert_true( PUPX_Xlsx_Parser::normalize_price( '19.99' ) === '19.99', 'decimal dot price' );
assert_true( PUPX_Xlsx_Parser::normalize_price( '19,99' ) === '19.99', 'decimal comma price' );
assert_true( PUPX_Xlsx_Parser::normalize_price( '1.234,56' ) === '1234.56', 'european thousands price' );
assert_true( null === PUPX_Xlsx_Parser::normalize_price( '' ), 'empty price invalid' );
assert_true( null === PUPX_Xlsx_Parser::normalize_price( 'abc' ), 'text price invalid' );
assert_true( null === PUPX_Xlsx_Parser::normalize_price( '-5' ), 'negative price invalid' );

// Build test XLSX with mixed rows.
$test_file = $tmp_dir . DIRECTORY_SEPARATOR . 'test-import.xlsx';
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray(
	array(
		array( 'SKU', 'Regular Price' ),
		array( 'GOOD-001', '10.50' ),
		array( 'GOOD-002', '20,00' ),
		array( '', '15.00' ),
		array( 'UNKNOWN', '5.00' ),
		array( 'GOOD-001', '99.99' ),
		array( 'BAD-PRICE', 'not-a-price' ),
	)
);
$writer = new Xlsx( $spreadsheet );
$writer->save( $test_file );

$parsed = PUPX_Xlsx_Parser::parse_file( $test_file );
assert_true( 6 === $parsed['total'], 'parses 6 data rows' );
assert_true( 'GOOD-001' === $parsed['rows'][0]['sku'], 'first row sku' );
assert_true( 5 === count( $parsed['preview'] ), 'preview capped at 5' );

// Generate large XLSX for performance smoke test (~2000 rows).
$large_file      = $tmp_dir . DIRECTORY_SEPARATOR . 'test-2000.xlsx';
$large_workbook  = new Spreadsheet();
$large_sheet     = $large_workbook->getActiveSheet();
$large_sheet->fromArray( array( array( 'SKU', 'Regular Price' ) ) );
for ( $i = 1; $i <= 2000; $i++ ) {
	$large_sheet->fromArray(
		array( array( 'PERF-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ), (string) ( 10 + ( $i % 100 ) ) ) ),
		null,
		'A' . ( $i + 1 )
	);
}
$large_writer = new Xlsx( $large_workbook );
$large_writer->save( $large_file );

$start = microtime( true );
$large_parsed = PUPX_Xlsx_Parser::parse_file( $large_file );
$elapsed = microtime( true ) - $start;

assert_true( 2000 === $large_parsed['total'], 'parses 2000 rows' );
assert_true( $elapsed < 10, 'parses 2000 rows in under 10 seconds (took ' . round( $elapsed, 2 ) . 's)' );

echo "\nAll parser tests passed.\n";
echo "Test files written to: {$tmp_dir}\n";
