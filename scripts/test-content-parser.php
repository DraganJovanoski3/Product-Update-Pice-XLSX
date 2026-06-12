<?php
/**
 * Standalone content parser tests.
 * Run: php scripts/test-content-parser.php
 */

define( 'ABSPATH', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-content-xlsx-parser.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pupx-content-test';
if ( ! is_dir( $tmp_dir ) ) {
	mkdir( $tmp_dir );
}

$headers = array( 'sku', 'post_title', 'seo_title', 'product_tags', 'cross_reference' );
$test_file = $tmp_dir . DIRECTORY_SEPARATOR . 'content-test.xlsx';

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray(
	array(
		$headers,
		array( 'GOOD-001', 'New Title', 'SEO Title Here', 'Tag A, Tag B', 'REF-1, REF-2' ),
		array( 'GOOD-002', '', 'Only SEO', '', '' ),
		array( '', '', '', '', '' ),
		array( 'DUPE', 'First', '', '', '' ),
		array( 'DUPE', 'Second', '', '', '' ),
	)
);
( new Xlsx( $spreadsheet ) )->save( $test_file );

$parsed = PUPX_Content_Xlsx_Parser::parse_file( $test_file );
assert_true( 4 === $parsed['total'], 'parses 4 data rows' );
assert_true( 'GOOD-001' === $parsed['rows'][0]['sku'], 'first sku parsed' );
assert_true( isset( $parsed['rows'][0]['fields']['post_title'] ), 'post_title in fields' );
assert_true( 'New Title' === $parsed['rows'][0]['fields']['post_title'], 'post_title value' );
assert_true( PUPX_Content_Xlsx_Parser::has_updatable_fields( $parsed['rows'][1] ), 'seo-only row has fields' );
assert_true( ! PUPX_Content_Xlsx_Parser::has_updatable_fields( array( 'sku' => 'X', 'fields' => array() ) ), 'sku-only has no updatable fields' );

$key = PUPX_Content_Xlsx_Parser::normalize_header( 'Product Tags' );
assert_true( 'product_tags' === $key, 'header normalization' );

echo "\nAll content parser tests passed.\n";
