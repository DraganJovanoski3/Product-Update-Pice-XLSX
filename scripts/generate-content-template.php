<?php
/**
 * Generate static content import template XLSX.
 * Run: php scripts/generate-content-template.php
 */

define( 'ABSPATH', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dir = dirname( __DIR__ ) . '/templates';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}

$file = $dir . '/content-import-template.xlsx';

$headers = array(
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

$sample = array(
	'ABC-123',
	'abc-123-product',
	'Sample Product Title',
	'Full product description goes here.',
	'Short description here.',
	'sample keyphrase',
	'Sample SEO Title | Store',
	'Sample meta description for search engines.',
	'keyword1, keyword2',
	'Tag One, Tag Two',
	'REF-001, REF-002',
	'Category One, Category Two',
);

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle( 'Content Import' );
$sheet->fromArray( array( $headers, $sample ) );

foreach ( range( 'A', 'L' ) as $col ) {
	$sheet->getColumnDimension( $col )->setWidth( 22 );
}

( new Xlsx( $spreadsheet ) )->save( $file );

echo "Created: {$file}\n";
