<?php
/**
 * Admin page, AJAX handlers, and import UI.
 */

defined( 'ABSPATH' ) || exit;

class PUPX_Admin_Page {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'wp_ajax_pupx_upload_file', array( __CLASS__, 'ajax_upload_file' ) );
		add_action( 'wp_ajax_pupx_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_pupx_download_report', array( __CLASS__, 'ajax_download_report' ) );
		add_action( 'admin_post_pupx_download_template', array( __CLASS__, 'download_template' ) );
	}

	/**
	 * Register WooCommerce submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Update Prices (XLSX)', 'product-update-price-xlsx' ),
			__( 'Update Prices (XLSX)', 'product-update-price-xlsx' ),
			'manage_woocommerce',
			'pupx-price-import',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_pupx-price-import' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'pupx-admin',
			PUPX_PLUGIN_URL . 'assets/admin.css',
			array(),
			PUPX_VERSION
		);

		wp_enqueue_script(
			'pupx-admin',
			PUPX_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			PUPX_VERSION,
			true
		);

		wp_localize_script(
			'pupx-admin',
			'pupxAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'pupx_import' ),
				'batchSize' => PUPX_BATCH_SIZE,
				'i18n'      => array(
					'uploading'    => __( 'Uploading and parsing file…', 'product-update-price-xlsx' ),
					'processing'   => __( 'Updating prices…', 'product-update-price-xlsx' ),
					'complete'     => __( 'Import complete.', 'product-update-price-xlsx' ),
					'error'        => __( 'An error occurred. Please try again.', 'product-update-price-xlsx' ),
					'selectFile'   => __( 'Please select an XLSX file first.', 'product-update-price-xlsx' ),
					'totalRows'    => __( 'Total rows', 'product-update-price-xlsx' ),
					'processed'    => __( 'Processed', 'product-update-price-xlsx' ),
					'updated'      => __( 'Updated', 'product-update-price-xlsx' ),
					'skipped'      => __( 'Skipped', 'product-update-price-xlsx' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'product-update-price-xlsx' ) );
		}

		$template_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=pupx_download_template' ),
			'pupx_download_template'
		);
		?>
		<div class="wrap pupx-wrap">
			<h1><?php esc_html_e( 'Update Prices (XLSX)', 'product-update-price-xlsx' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Import regular prices by SKU from an XLSX file. Only matching products are updated — nothing else is changed.', 'product-update-price-xlsx' ); ?>
			</p>

			<div class="pupx-card">
				<h2><?php esc_html_e( 'Step 1 — Upload XLSX', 'product-update-price-xlsx' ); ?></h2>
				<p>
					<a href="<?php echo esc_url( $template_url ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Download sample template', 'product-update-price-xlsx' ); ?>
					</a>
				</p>
				<p class="pupx-format-note">
					<?php esc_html_e( 'Required columns: Column A = SKU, Column B = Regular Price. Row 1 may be a header row.', 'product-update-price-xlsx' ); ?>
				</p>
				<form id="pupx-upload-form" enctype="multipart/form-data">
					<input type="file" id="pupx-file" name="pupx_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" />
					<button type="submit" class="button button-primary" id="pupx-upload-btn">
						<?php esc_html_e( 'Upload & Preview', 'product-update-price-xlsx' ); ?>
					</button>
				</form>
			</div>

			<div class="pupx-card pupx-hidden" id="pupx-preview-section">
				<h2><?php esc_html_e( 'Step 2 — Preview', 'product-update-price-xlsx' ); ?></h2>
				<p id="pupx-total-rows"></p>
				<table class="widefat striped pupx-preview-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Row', 'product-update-price-xlsx' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'product-update-price-xlsx' ); ?></th>
							<th><?php esc_html_e( 'Regular Price', 'product-update-price-xlsx' ); ?></th>
						</tr>
					</thead>
					<tbody id="pupx-preview-body"></tbody>
				</table>
				<p class="description" id="pupx-preview-note"></p>
				<button type="button" class="button button-primary button-hero" id="pupx-start-import">
					<?php esc_html_e( 'Run Import', 'product-update-price-xlsx' ); ?>
				</button>
			</div>

			<div class="pupx-card pupx-hidden" id="pupx-progress-section">
				<h2><?php esc_html_e( 'Step 3 — Import Progress', 'product-update-price-xlsx' ); ?></h2>
				<div class="pupx-progress-bar-wrap">
					<div class="pupx-progress-bar" id="pupx-progress-bar"></div>
				</div>
				<p id="pupx-progress-text"></p>
			</div>

			<div class="pupx-card pupx-hidden" id="pupx-results-section">
				<h2><?php esc_html_e( 'Step 4 — Results', 'product-update-price-xlsx' ); ?></h2>
				<div class="pupx-summary" id="pupx-summary"></div>
				<div class="pupx-export-buttons">
					<button type="button" class="button" id="pupx-download-xlsx">
						<?php esc_html_e( 'Download Not Updated (XLSX)', 'product-update-price-xlsx' ); ?>
					</button>
					<button type="button" class="button" id="pupx-download-csv">
						<?php esc_html_e( 'Download Not Updated (CSV)', 'product-update-price-xlsx' ); ?>
					</button>
				</div>
				<h3><?php esc_html_e( 'Products Not Updated', 'product-update-price-xlsx' ); ?></h3>
				<div class="pupx-table-scroll">
					<table class="widefat striped" id="pupx-not-updated-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Row', 'product-update-price-xlsx' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'product-update-price-xlsx' ); ?></th>
								<th><?php esc_html_e( 'Price', 'product-update-price-xlsx' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'product-update-price-xlsx' ); ?></th>
							</tr>
						</thead>
						<tbody id="pupx-not-updated-body"></tbody>
					</table>
				</div>
			</div>

			<div class="pupx-notice pupx-hidden" id="pupx-notice"></div>
		</div>
		<?php
	}

	/**
	 * Verify AJAX request permissions and nonce.
	 *
	 * @return bool
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'product-update-price-xlsx' ) ), 403 );
		}

		check_ajax_referer( 'pupx_import', 'nonce' );
		return true;
	}

	/**
	 * AJAX: upload and parse XLSX file.
	 */
	public static function ajax_upload_file() {
		self::verify_request();
		PUPX_Import_Session::raise_limits();

		if ( empty( $_FILES['pupx_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'product-update-price-xlsx' ) ) );
		}

		$file = $_FILES['pupx_file'];

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: PHP upload error code */
						__( 'File upload failed (error code %d). Check upload_max_filesize on the server.', 'product-update-price-xlsx' ),
						(int) $file['error']
					),
				)
			);
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $ext ) {
			wp_send_json_error( array( 'message' => __( 'Only .xlsx files are allowed.', 'product-update-price-xlsx' ) ) );
		}

		$tmp_path = $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'product-update-price-xlsx' ) ) );
		}

		try {
			$parsed = PUPX_Xlsx_Parser::parse_file( $tmp_path );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		try {
			$session_id = PUPX_Import_Session::create( $parsed['rows'] );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'total'      => $parsed['total'],
				'preview'    => $parsed['preview'],
			)
		);
	}

	/**
	 * AJAX: process one batch of rows.
	 */
	public static function ajax_process_batch() {
		self::verify_request();
		PUPX_Import_Session::raise_limits();

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		$session = PUPX_Import_Session::get( $session_id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => __( 'Import session expired. Please upload the file again.', 'product-update-price-xlsx' ) ) );
		}

		if ( ! empty( $session['complete'] ) ) {
			wp_send_json_success( self::build_batch_response( $session, true ) );
		}

		wp_suspend_cache_addition( true );

		try {
			$result = PUPX_Price_Updater::process_batch( $session, PUPX_BATCH_SIZE );

			if ( ! PUPX_Import_Session::save( $session_id, $session ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not save import progress. Check server disk space and permissions.', 'product-update-price-xlsx' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		} finally {
			wp_suspend_cache_addition( false );
		}

		if ( $result['complete'] && function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables();
		}

		wp_send_json_success( self::build_batch_response( $session, $result['complete'] ) );
	}

	/**
	 * Build AJAX batch response payload.
	 *
	 * @param array $session  Session data.
	 * @param bool  $complete Whether import is complete.
	 * @return array
	 */
	private static function build_batch_response( array $session, $complete ) {
		$total     = (int) $session['total'];
		$processed = (int) $session['offset'];

		$response = array(
			'processed' => $processed,
			'total'     => $total,
			'updated'   => (int) $session['updated'],
			'skipped'   => (int) $session['skipped'],
			'complete'  => (bool) $complete,
			'percent'   => $total > 0 ? round( ( $processed / $total ) * 100 ) : 100,
		);

		if ( $complete ) {
			$not_updated = isset( $session['not_updated'] ) ? $session['not_updated'] : array();
			$labels      = PUPX_Price_Updater::reason_labels();
			$preview     = array_slice( $not_updated, 0, 200 );

			$response['not_updated_count'] = count( $not_updated );
			$response['not_updated']       = array_map(
				function ( $row ) use ( $labels ) {
					return array(
						'row_num'      => $row['row_num'],
						'sku'          => $row['sku'],
						'price'        => $row['price'],
						'reason'       => $row['reason'],
						'reason_label' => isset( $labels[ $row['reason'] ] ) ? $labels[ $row['reason'] ] : $row['reason'],
					);
				},
				$preview
			);

			if ( count( $not_updated ) > count( $preview ) ) {
				$response['not_updated_truncated'] = true;
			}
		}

		return $response;
	}

	/**
	 * AJAX: download not-updated report.
	 */
	public static function ajax_download_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'product-update-price-xlsx' ) );
		}

		check_ajax_referer( 'pupx_import', 'nonce' );

		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		$format     = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'xlsx';

		$session = PUPX_Import_Session::get( $session_id );
		if ( ! $session || empty( $session['complete'] ) ) {
			wp_die( esc_html__( 'Report not available.', 'product-update-price-xlsx' ) );
		}

		$not_updated = isset( $session['not_updated'] ) ? $session['not_updated'] : array();

		if ( 'csv' === $format ) {
			PUPX_Report_Builder::stream_csv( $not_updated );
		}

		PUPX_Report_Builder::stream_xlsx( $not_updated );
	}

	/**
	 * Download sample template via admin-post.
	 */
	public static function download_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'product-update-price-xlsx' ) );
		}

		check_admin_referer( 'pupx_download_template' );

		$file = PUPX_PLUGIN_DIR . 'templates/price-import-template.xlsx';

		if ( file_exists( $file ) ) {
			PUPX_File_Download::send_file(
				$file,
				'price-import-template.xlsx',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
			);
		}

		try {
			PUPX_Xlsx_Parser::stream_sample_template();
		} catch ( Exception $e ) {
			wp_die( esc_html__( 'Could not generate template file.', 'product-update-price-xlsx' ) );
		}
	}
}
