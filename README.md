# Product Update Price XLSX

WooCommerce plugin to import **regular prices** and **SEO/content fields** from XLSX files by SKU.

## Features

### Price import
- XLSX-only import (2 columns: SKU, Regular Price)
- Updates **regular price only**

### SEO & content import
- Multi-column XLSX with header row
- Updates: title, slug, descriptions, Yoast SEO fields, tags, categories, cross_reference meta
- Empty cells are skipped (existing data not cleared)

### Both imports

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+
- Composer (for PhpSpreadsheet)

## Installation

1. Copy this folder to `wp-content/plugins/product-update-price-xlsx/`
2. Run `composer install` inside the plugin folder
3. Activate **Product Update Price XLSX** in WordPress admin
4. Go to **WooCommerce → Update Prices (XLSX)** or **WooCommerce → Update SEO & Content (XLSX)**

## XLSX Format

| SKU | Regular Price |
|-----|---------------|
| ABC-123 | 19.99 |
| VAR-001 | 45.00 |

Row 1 may be a header row. Empty rows are ignored.

## Skip Reasons

- **Empty SKU** — no SKU in the row
- **Invalid price** — missing or non-numeric price
- **SKU not found** — no matching product in the store
- **Duplicate SKU in store** — multiple products share the same SKU
- **Duplicate SKU in import file** — SKU appears more than once (first row wins)
- **SKU mismatch** — safety check failed after loading product
- **Product could not be loaded** — product ID invalid
