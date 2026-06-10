<?php
/**
 * Plugin Name: Product Update Price XLSX
 * Description: Import regular prices from XLSX by SKU. Safe batch updates with not-updated report.
 * Version: 1.0.5
 * Author: Product Update Price XLSX
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * Text Domain: product-update-price-xlsx
 */

defined( 'ABSPATH' ) || exit;

define( 'PUPX_VERSION', '1.0.5' );
define( 'PUPX_PLUGIN_FILE', __FILE__ );
define( 'PUPX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PUPX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PUPX_BATCH_SIZE', 20 );

/**
 * Bootstrap the plugin after dependencies are available.
 */
function pupx_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'pupx_woocommerce_missing_notice' );
		return;
	}

	$autoload = PUPX_PLUGIN_DIR . 'vendor/autoload.php';
	if ( ! file_exists( $autoload ) ) {
		add_action( 'admin_notices', 'pupx_composer_missing_notice' );
		return;
	}

	require_once $autoload;

	require_once PUPX_PLUGIN_DIR . 'includes/class-file-download.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-import-session.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-xlsx-parser.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-sku-resolver.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-price-updater.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-report-builder.php';
	require_once PUPX_PLUGIN_DIR . 'includes/class-admin-page.php';

	PUPX_Admin_Page::init();
}
add_action( 'plugins_loaded', 'pupx_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function pupx_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Product Update Price XLSX requires WooCommerce to be installed and active.', 'product-update-price-xlsx' );
	echo '</p></div>';
}

/**
 * Admin notice when Composer dependencies are missing.
 */
function pupx_composer_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Product Update Price XLSX: Run "composer install" in the plugin directory to install PhpSpreadsheet.', 'product-update-price-xlsx' );
	echo '</p></div>';
}
