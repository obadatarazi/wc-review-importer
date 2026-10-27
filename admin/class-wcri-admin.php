<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the WordPress admin dashboard page.
 */
class WCRI_Admin {

	public static function init(): void {
		add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_wcri_import',        [ self::class, 'handle_import' ] );
		add_action( 'admin_post_wcri_add_single',    [ self::class, 'handle_single' ] );
		add_action( 'admin_post_wcri_export',        [ self::class, 'handle_export' ] );
		add_action( 'admin_post_wcri_delete_review', [ self::class, 'handle_delete' ] );
		add_action( 'wp_ajax_wcri_add_single',       [ self::class, 'ajax_add_single' ] );
		add_action( 'wp_ajax_wcri_import',           [ self::class, 'ajax_import' ] );
		add_action( 'wp_ajax_wcri_delete_review',    [ self::class, 'ajax_delete_review' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Review Importer', 'wc-review-importer' ),
			__( 'Review Importer', 'wc-review-importer' ),
			'manage_woocommerce',
			'wcri-importer',
			[ self::class, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'wcri-importer' ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'add' );

		wp_enqueue_style(
			'wcri-admin',
			WCRI_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WCRI_VERSION
		);

		$script_deps = [ 'jquery' ];
		if ( $tab === 'add' ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			$script_deps[] = 'wc-enhanced-select';
		}

		wp_enqueue_script(
			'wcri-admin',
			WCRI_PLUGIN_URL . 'assets/js/admin.js',
			$script_deps,
			WCRI_VERSION,
			true
		);
		wp_localize_script( 'wcri-admin', 'wcri', [
			'nonce'    => wp_create_nonce( 'wcri_nonce' ),
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'strings'  => [
				'confirm_delete'  => __( 'Delete this review? This cannot be undone.', 'wc-review-importer' ),
				'drop_label'      => __( 'Drop your CSV or XLSX file here', 'wc-review-importer' ),
				'or_click'        => __( 'or click to browse', 'wc-review-importer' ),
				'select_product'  => __( 'Please select at least one product.', 'wc-review-importer' ),
				'name_required'   => __( 'Please enter a reviewer name.', 'wc-review-importer' ),
				'file_required'   => __( 'Please choose a CSV or XLSX file.', 'wc-review-importer' ),
				'saving'          => __( 'Saving…', 'wc-review-importer' ),
				'importing'       => __( 'Importing…', 'wc-review-importer' ),
				'deleting'        => __( 'Deleting…', 'wc-review-importer' ),
				'save_review'     => __( 'Save Review', 'wc-review-importer' ),
				'import_reviews'  => __( 'Import Reviews', 'wc-review-importer' ),
				'error_generic'   => __( 'Something went wrong. Please try again.', 'wc-review-importer' ),
				'review_saved'    => __( 'Review saved successfully.', 'wc-review-importer' ),
				'deleted'         => __( 'Review deleted.', 'wc-review-importer' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	public static function handle_import(): void {
		check_admin_referer( 'wcri_import' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorised' );
		}

		$out = self::process_import_submission( $_POST, $_FILES );
		if ( isset( $out['error'] ) ) {
			self::redirect_with( 'error', $out['error'], 'import' );
		}

		set_transient( 'wcri_last_result_' . get_current_user_id(), $out['result'], 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=wcri-importer&tab=import&wcri_done=1' ) );
		exit;
	}

	public static function handle_single(): void {
		check_admin_referer( 'wcri_single' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorised' );
		}

		$out = self::process_single_submission( $_POST, $_FILES );
		if ( isset( $out['error'] ) ) {
			set_transient( 'wcri_error_' . get_current_user_id(), $out['error'], 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=wcri-importer&tab=add&wcri_error=1' ) );
			exit;
		}

		set_transient( 'wcri_last_result_' . get_current_user_id(), $out['result'], 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=wcri-importer&tab=add&wcri_done=1' ) );
		exit;
	}

	/**
	 * AJAX: save single review(s).
	 */
	public static function ajax_add_single(): void {
		check_ajax_referer( 'wcri_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-review-importer' ) ], 403 );
		}

		$out = self::process_single_submission( $_POST, $_FILES );
		if ( isset( $out['error'] ) ) {
			wp_send_json_error( [ 'message' => $out['error'] ] );
		}

		$result = $out['result'];
		/* translators: %d: number of reviews imported */
		$message = sprintf( _n( '%d review saved.', '%d reviews saved.', (int) $result['imported'], 'wc-review-importer' ), (int) $result['imported'] );

		wp_send_json_success( [
			'message' => $message,
			'html'    => self::get_result_notice_html( $result ),
			'result'  => $result,
		] );
	}

	/**
	 * AJAX: bulk import file.
	 */
	public static function ajax_import(): void {
		check_ajax_referer( 'wcri_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-review-importer' ) ], 403 );
		}

		$out = self::process_import_submission( $_POST, $_FILES );
		if ( isset( $out['error'] ) ) {
			wp_send_json_error( [ 'message' => $out['error'] ] );
		}

		wp_send_json_success( [
			'html'   => self::get_result_notice_html( $out['result'] ),
			'result' => $out['result'],
		] );
	}

	/**
	 * AJAX: delete a review.
	 */
	public static function ajax_delete_review(): void {
		check_ajax_referer( 'wcri_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wc-review-importer' ) ], 403 );
		}

		$id = (int) ( $_POST['review_id'] ?? 0 );
		if ( ! $id || ! wp_delete_comment( $id, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not delete review.', 'wc-review-importer' ) ] );
		}

		wp_send_json_success( [
			'message' => __( 'Review deleted.', 'wc-review-importer' ),
		] );
	}

	/**
	 * @param array $post  POST data.
	 * @param array $files FILES data.
	 * @return array{result:array}|array{error:string}
	 */
	private static function process_single_submission( array $post, array $files ): array {
		$product_ids = isset( $post['product_id'] )
			? array_map( 'absint', (array) wp_unslash( $post['product_id'] ) )
			: [];
		$product_ids = array_values( array_filter( $product_ids ) );

		if ( empty( $product_ids ) ) {
			return [ 'error' => __( 'Please select at least one product.', 'wc-review-importer' ) ];
		}

		$name = sanitize_text_field( $post['reviewer_name'] ?? '' );
		if ( $name === '' ) {
			return [ 'error' => __( 'Please enter a reviewer name.', 'wc-review-importer' ) ];
		}

		$avatar_id = 0;
		if ( ! empty( $files['wcri_avatar']['tmp_name'] ) ) {
			$avatar_id = WCRI_Helpers::upload_avatar( $files['wcri_avatar'], $product_ids[0] );
		}

		$data = [
			'reviewer_name'  => $name,
			'reviewer_email' => sanitize_email( $post['reviewer_email'] ?? '' ),
			'rating'         => sanitize_text_field( $post['rating'] ?? '5' ),
			'review_content' => wp_kses_post( $post['review_content'] ?? '' ),
			'review_date'    => sanitize_text_field( $post['review_date'] ?? '' ),
			'verified'       => ! empty( $post['verified'] ) ? '1' : '0',
			'avatar_id'      => $avatar_id,
		];

		$config = [
			'randomise_dates'  => ! empty( $post['wcri_randomise_dates'] ),
			'verified_default' => false,
			'auto_publish'     => true,
		];

		$importer = new WCRI_Importer( $config );
		$result   = null;
		foreach ( $product_ids as $product_id ) {
			$result = $importer->import_single( array_merge( $data, [ 'product_id' => (string) $product_id ] ) );
		}

		return [ 'result' => $result ?? [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'log' => [] ] ];
	}

	/**
	 * @param array $post  POST data.
	 * @param array $files FILES data.
	 * @return array{result:array}|array{error:string}
	 */
	private static function process_import_submission( array $post, array $files ): array {
		if ( empty( $files['wcri_file']['tmp_name'] ) ) {
			return [ 'error' => __( 'No file uploaded.', 'wc-review-importer' ) ];
		}

		$file = $files['wcri_file'];
		$ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, [ 'csv', 'xlsx', 'xls' ], true ) ) {
			return [ 'error' => __( 'Only CSV and XLSX files are supported.', 'wc-review-importer' ) ];
		}

		$tmp = wp_tempnam( 'wcri_import' );
		if ( ! move_uploaded_file( $file['tmp_name'], $tmp ) ) {
			return [ 'error' => __( 'Failed to move uploaded file.', 'wc-review-importer' ) ];
		}

		$config = [
			'randomise_dates'  => ! empty( $post['wcri_randomise_dates'] ),
			'verified_default' => ! empty( $post['wcri_verified_default'] ),
			'auto_publish'     => true,
		];

		$importer = new WCRI_Importer( $config );
		$result   = $importer->import_file( $tmp );
		@unlink( $tmp );

		return [ 'result' => $result ];
	}

	public static function handle_export(): void {
		check_admin_referer( 'wcri_export' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorised' );
		}
		WCRI_Exporter::stream_csv();
	}

	public static function handle_delete(): void {
		check_admin_referer( 'wcri_delete_review' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorised' );
		}
		$id = (int) ( $_POST['review_id'] ?? 0 );
		if ( $id ) {
			wp_delete_comment( $id, true );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wcri-importer&tab=reviews&deleted=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'add' );
		$tabs = [
			'add'     => __( 'Add Review', 'wc-review-importer' ),
			'import'  => __( 'Bulk Import', 'wc-review-importer' ),
			'reviews' => __( 'All Reviews', 'wc-review-importer' ),
			'export'  => __( 'Export', 'wc-review-importer' ),
		];

		$result = null;
		if ( ! empty( $_GET['wcri_done'] ) ) {
			$result = get_transient( 'wcri_last_result_' . get_current_user_id() );
			delete_transient( 'wcri_last_result_' . get_current_user_id() );
		}

		$error_msg = null;
		if ( ! empty( $_GET['wcri_error'] ) ) {
			$error_msg = get_transient( 'wcri_error_' . get_current_user_id() );
			delete_transient( 'wcri_error_' . get_current_user_id() );
		}

		?>
		<div class="wcri-wrap">
			<header class="wcri-header">
				<div class="wcri-header-inner">
					<div class="wcri-logo">
						<span class="wcri-logo-icon">★</span>
						<div>
							<h1><?php esc_html_e( 'Review Importer', 'wc-review-importer' ); ?></h1>
							<p><?php esc_html_e( 'Bulk import & manage WooCommerce product reviews', 'wc-review-importer' ); ?></p>
						</div>
					</div>
					<div class="wcri-header-meta">
						<span class="wcri-badge">v<?php echo esc_html( WCRI_VERSION ); ?></span>
					</div>
				</div>
			</header>

			<nav class="wcri-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcri-importer&tab=' . $key ) ); ?>"
					   class="wcri-tab <?php echo $tab === $key ? 'wcri-tab--active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wcri-content">
				<div id="wcri-notices" class="wcri-notices" aria-live="polite">
				<?php
				if ( $error_msg ) {
					echo '<div class="wcri-notice wcri-notice--warning"><p>' . esc_html( $error_msg ) . '</p></div>';
				}
				if ( $result ) {
					self::render_result_notice( $result );
				}
				if ( ! empty( $_GET['deleted'] ) ) {
					echo '<div class="wcri-notice wcri-notice--success"><p>✅ ' . esc_html__( 'Review deleted.', 'wc-review-importer' ) . '</p></div>';
				}
				?>
				</div>
				<?php
				switch ( $tab ) {
					case 'add':     self::render_add_tab();     break;
					case 'import':  self::render_import_tab();  break;
					case 'reviews': self::render_reviews_tab(); break;
					case 'export':  self::render_export_tab();  break;
					default:        self::render_add_tab();     break;
				}
				?>
			</div>

			<footer class="wcri-footer">
				<p><?php esc_html_e( 'WC Review Importer — Compatible with WooCommerce 7+', 'wc-review-importer' ); ?></p>
			</footer>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab renders
	// -------------------------------------------------------------------------

	private static function render_import_tab(): void {
		?>
		<div class="wcri-grid">
			<div class="wcri-card wcri-card--main">
				<h2 class="wcri-card-title">
					<span class="wcri-icon">📥</span>
					<?php esc_html_e( 'Upload CSV / Excel File', 'wc-review-importer' ); ?>
				</h2>

				<form method="post" enctype="multipart/form-data" id="wcri-import-form" class="wcri-ajax-form" data-wcri-action="import">

					<div class="wcri-dropzone" id="wcri-dropzone">
						<input type="file" name="wcri_file" id="wcri-file-input" accept=".csv,.xlsx,.xls" required>
						<div class="wcri-dropzone-inner">
							<div class="wcri-dropzone-icon">📂</div>
							<p class="wcri-dropzone-label"><?php esc_html_e( 'Drop your CSV or XLSX file here', 'wc-review-importer' ); ?></p>
							<p class="wcri-dropzone-sub"><?php esc_html_e( 'or click to browse', 'wc-review-importer' ); ?></p>
							<p class="wcri-dropzone-file" id="wcri-file-name"></p>
						</div>
					</div>

					<div class="wcri-options">
						<label class="wcri-toggle">
							<input type="checkbox" name="wcri_randomise_dates" value="1">
							<span class="wcri-toggle-slider"></span>
							<span class="wcri-toggle-label"><?php esc_html_e( 'Randomise dates for rows with empty review_date', 'wc-review-importer' ); ?></span>
						</label>
						<label class="wcri-toggle">
							<input type="checkbox" name="wcri_verified_default" value="1">
							<span class="wcri-toggle-slider"></span>
							<span class="wcri-toggle-label"><?php esc_html_e( 'Mark all imported reviews as "Verified Owner" by default', 'wc-review-importer' ); ?></span>
						</label>
					</div>

					<button type="submit" class="wcri-btn wcri-btn--primary">
						<span class="wcri-btn-icon">🚀</span>
						<?php esc_html_e( 'Import Reviews', 'wc-review-importer' ); ?>
					</button>
				</form>
			</div>

			<aside class="wcri-sidebar">
				<div class="wcri-card wcri-card--info">
					<h3><?php esc_html_e( 'CSV Column Format', 'wc-review-importer' ); ?></h3>
					<ul class="wcri-col-list">
						<?php
						$cols = [
							'product_id'     => __( 'Numeric product ID', 'wc-review-importer' ),
							'sku'            => __( 'Product SKU (alternative to product_id)', 'wc-review-importer' ),
							'reviewer_name'  => __( 'Full name of the reviewer', 'wc-review-importer' ),
							'reviewer_email' => __( 'Reviewer email address', 'wc-review-importer' ),
							'rating'         => __( 'Star rating (1–5)', 'wc-review-importer' ),
							'review_content' => __( 'Review text (optional) — UTF-8 / Arabic supported', 'wc-review-importer' ),
							'review_date'    => __( 'Date string or leave empty to randomise', 'wc-review-importer' ),
							'verified'       => __( '1 = verified owner, 0 = not verified', 'wc-review-importer' ),
							'image_url'      => __( 'Optional: public URL of review image', 'wc-review-importer' ),
							'avatar_url'     => __( 'Optional: public URL of reviewer avatar', 'wc-review-importer' ),
						];
						foreach ( $cols as $col => $desc ) :
							?>
							<li>
								<code><?php echo esc_html( $col ); ?></code>
								<span><?php echo esc_html( $desc ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="wcri-card wcri-card--download">
					<h3><?php esc_html_e( 'Sample File', 'wc-review-importer' ); ?></h3>
					<p><?php esc_html_e( 'Download a ready-to-use sample CSV to get started quickly.', 'wc-review-importer' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcri-importer&wcri_sample=1' ) ); ?>"
					   class="wcri-btn wcri-btn--secondary wcri-btn--sm" id="wcri-sample-btn">
						⬇ <?php esc_html_e( 'Download Sample CSV', 'wc-review-importer' ); ?>
					</a>
					<?php
					// Serve sample download.
					if ( ! empty( $_GET['wcri_sample'] ) && current_user_can( 'manage_woocommerce' ) ) {
						add_action( 'admin_footer', static function () {
							echo '<script>window.location.href="' . esc_url( add_query_arg( 'wcri_download_sample', '1', admin_url( 'admin.php?page=wcri-importer' ) ) ) . '";</script>';
						} );
					}
					?>
				</div>
			</aside>
		</div>
		<?php
		// Inline sample download trigger.
		if ( ! empty( $_GET['wcri_sample'] ) && current_user_can( 'manage_woocommerce' ) ) {
			// Already handled in JS redirect above – nothing more needed here.
		}
	}

	private static function render_add_tab(): void {
		?>
		<div class="wcri-grid wcri-grid--single">
			<div class="wcri-card">
				<h2 class="wcri-card-title">
					<span class="wcri-icon">✍️</span>
					<?php esc_html_e( 'Add a Single Review', 'wc-review-importer' ); ?>
				</h2>

				<form method="post" class="wcri-form wcri-ajax-form" id="wcri-add-form" enctype="multipart/form-data" data-wcri-action="add_single">

					<div class="wcri-form-row">
						<div class="wcri-field wcri-field--product">
							<label><?php esc_html_e( 'Product ID', 'wc-review-importer' ); ?> <span class="wcri-req">*</span></label>
							<select
								class="wc-product-search"
								name="product_id[]"
								multiple="multiple"
								data-placeholder="<?php esc_attr_e( 'Search for a product…', 'wc-review-importer' ); ?>"
								data-action="woocommerce_json_search_products"
								data-allow_clear="true"
							></select>
						</div>
					</div>

					<div class="wcri-form-row wcri-form-row--2">
						<div class="wcri-field">
							<label><?php esc_html_e( 'Reviewer Name', 'wc-review-importer' ); ?> <span class="wcri-req">*</span></label>
							<input type="text" name="reviewer_name" required placeholder="Ahmed Al-Rashid">
						</div>
						<div class="wcri-field">
							<label><?php esc_html_e( 'Reviewer Email', 'wc-review-importer' ); ?></label>
							<input type="email" name="reviewer_email" placeholder="ahmed@example.com">
						</div>
					</div>

					<div class="wcri-form-row wcri-form-row--3">
						<div class="wcri-field">
							<label><?php esc_html_e( 'Rating', 'wc-review-importer' ); ?> <span class="wcri-req">*</span></label>
							<div class="wcri-star-picker" id="wcri-star-picker">
								<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
									<button type="button" class="wcri-star" data-value="<?php echo $i; ?>">★</button>
								<?php endfor; ?>
								<input type="hidden" name="rating" id="wcri-rating-input" value="5">
							</div>
						</div>
						<div class="wcri-field">
							<label><?php esc_html_e( 'Review Date', 'wc-review-importer' ); ?></label>
							<input type="date" name="review_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
						</div>
						<div class="wcri-field wcri-field--toggle">
							<label><?php esc_html_e( 'Verified Owner', 'wc-review-importer' ); ?></label>
							<label class="wcri-toggle wcri-toggle--inline">
								<input type="checkbox" name="verified" value="1">
								<span class="wcri-toggle-slider"></span>
							</label>
						</div>
					</div>

					<div class="wcri-form-row wcri-form-row--2">
						<div class="wcri-field">
							<label><?php esc_html_e( 'Review Content', 'wc-review-importer' ); ?></label>
							<textarea name="review_content" rows="5" placeholder="<?php esc_attr_e( 'Optional — leave blank for rating-only reviews.', 'wc-review-importer' ); ?>"></textarea>
						</div>
						<div class="wcri-field">
							<label><?php esc_html_e( 'Reviewer Avatar', 'wc-review-importer' ); ?></label>
							<input type="file" name="wcri_avatar" id="wcri-avatar-input" accept="image/jpeg,image/png,image/gif,image/webp">
							<div class="wcri-avatar-preview" id="wcri-avatar-preview" hidden></div>
							<p class="wcri-field-hint"><?php esc_html_e( 'Optional — JPG, PNG, GIF, or WebP.', 'wc-review-importer' ); ?></p>
						</div>
					</div>

					<label class="wcri-toggle">
						<input type="checkbox" name="wcri_randomise_dates" value="1">
						<span class="wcri-toggle-slider"></span>
						<span class="wcri-toggle-label"><?php esc_html_e( 'Use a random date instead of the date above', 'wc-review-importer' ); ?></span>
					</label>

					<button type="submit" class="wcri-btn wcri-btn--primary">
						<span class="wcri-btn-icon">💾</span>
						<?php esc_html_e( 'Save Review', 'wc-review-importer' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	private static function render_reviews_tab(): void {
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 20;

		$total_comments = get_comments( [
			'type'   => 'review',
			'status' => 'any',
			'count'  => true,
		] );

		$comments = get_comments( [
			'type'   => 'review',
			'status' => 'any',
			'number' => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		] );

		$total_pages = (int) ceil( $total_comments / $per_page );
		?>
		<div class="wcri-card">
			<div class="wcri-table-header">
				<h2 class="wcri-card-title">
					<span class="wcri-icon">📋</span>
					<?php
					/* translators: %d: total review count */
					printf( esc_html__( 'All Reviews (%d)', 'wc-review-importer' ), (int) $total_comments );
					?>
				</h2>
			</div>

			<?php if ( empty( $comments ) ) : ?>
				<div class="wcri-empty">
					<span class="wcri-empty-icon">💬</span>
					<p><?php esc_html_e( 'No reviews found. Add one from the Add Review tab or use Bulk Import.', 'wc-review-importer' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wcri-table-wrap">
					<table class="wcri-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Reviewer', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Rating', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Review', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Date', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wc-review-importer' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wc-review-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $comments as $comment ) : ?>
								<?php
								$product_id = (int) $comment->comment_post_ID;
								$product    = wc_get_product( $product_id );
								$rating     = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
								$verified   = (bool) get_comment_meta( $comment->comment_ID, 'verified', true );
								$imported   = (bool) get_comment_meta( $comment->comment_ID, '_wcri_imported', true );
								?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" target="_blank" class="wcri-product-link">
											<?php echo $product ? esc_html( $product->get_name() ) : '#' . $product_id; ?>
										</a>
										<small>#<?php echo esc_html( $product_id ); ?></small>
									</td>
									<td>
										<strong><?php echo esc_html( $comment->comment_author ); ?></strong>
										<?php if ( $verified ) : ?>
											<span class="wcri-badge wcri-badge--verified"><?php esc_html_e( 'Verified', 'wc-review-importer' ); ?></span>
										<?php endif; ?>
										<?php if ( $imported ) : ?>
											<span class="wcri-badge wcri-badge--imported"><?php esc_html_e( 'Imported', 'wc-review-importer' ); ?></span>
										<?php endif; ?>
										<br><small><?php echo esc_html( $comment->comment_author_email ); ?></small>
									</td>
									<td>
										<div class="wcri-stars wcri-stars--sm">
											<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
												<span class="<?php echo $i <= $rating ? 'wcri-star--filled' : 'wcri-star--empty'; ?>">★</span>
											<?php endfor; ?>
										</div>
									</td>
									<td>
										<p class="wcri-review-excerpt"><?php echo esc_html( wp_trim_words( $comment->comment_content, 12 ) ); ?></p>
									</td>
									<td>
										<small><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $comment->comment_date ) ) ); ?></small>
									</td>
									<td>
										<?php if ( $comment->comment_approved == '1' ) : ?>
											<span class="wcri-status wcri-status--approved"><?php esc_html_e( 'Published', 'wc-review-importer' ); ?></span>
										<?php else : ?>
											<span class="wcri-status wcri-status--pending"><?php esc_html_e( 'Pending', 'wc-review-importer' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<button
											type="button"
											class="wcri-btn wcri-btn--danger wcri-btn--xs wcri-delete-btn"
											data-review-id="<?php echo esc_attr( (string) $comment->comment_ID ); ?>"
											aria-label="<?php esc_attr_e( 'Delete review', 'wc-review-importer' ); ?>"
										>🗑</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="wcri-pagination">
						<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcri-importer&tab=reviews&paged=' . $p ) ); ?>"
							   class="wcri-page-btn <?php echo $p === $paged ? 'wcri-page-btn--active' : ''; ?>">
								<?php echo esc_html( $p ); ?>
							</a>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_export_tab(): void {
		$total = get_comments( [
			'type'   => 'review',
			'status' => 'any',
			'count'  => true,
		] );
		?>
		<div class="wcri-grid wcri-grid--single">
			<div class="wcri-card">
				<h2 class="wcri-card-title">
					<span class="wcri-icon">📤</span>
					<?php esc_html_e( 'Export Reviews to CSV', 'wc-review-importer' ); ?>
				</h2>
				<p class="wcri-export-desc">
					<?php
					printf(
						/* translators: %d: number of reviews */
						esc_html__( 'Export all %d reviews to a UTF-8 CSV file (Excel-compatible). The file will include all review fields and can be re-imported.', 'wc-review-importer' ),
						(int) $total
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wcri_export' ); ?>
					<input type="hidden" name="action" value="wcri_export">
					<button type="submit" class="wcri-btn wcri-btn--primary">
						<span class="wcri-btn-icon">⬇</span>
						<?php esc_html_e( 'Download CSV Export', 'wc-review-importer' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Result notice
	// -------------------------------------------------------------------------

	public static function get_result_notice_html( array $result ): string {
		ob_start();
		self::render_result_notice( $result );
		return (string) ob_get_clean();
	}

	private static function render_result_notice( array $result ): void {
		$type = $result['errors'] > 0 ? 'warning' : 'success';
		if ( $result['imported'] === 0 && $result['errors'] === 0 ) {
			$type = 'info';
		}
		?>
		<div class="wcri-notice wcri-notice--<?php echo esc_attr( $type ); ?>">
			<div class="wcri-notice-summary">
				<span class="wcri-notice-stat wcri-notice-stat--success">✅ <?php echo (int) $result['imported']; ?> <?php esc_html_e( 'imported', 'wc-review-importer' ); ?></span>
				<span class="wcri-notice-stat wcri-notice-stat--skip">⏭ <?php echo (int) $result['skipped']; ?> <?php esc_html_e( 'skipped', 'wc-review-importer' ); ?></span>
				<span class="wcri-notice-stat wcri-notice-stat--error">❌ <?php echo (int) $result['errors']; ?> <?php esc_html_e( 'errors', 'wc-review-importer' ); ?></span>
			</div>
			<?php if ( ! empty( $result['log'] ) ) : ?>
				<details class="wcri-log">
					<summary><?php esc_html_e( 'View import log', 'wc-review-importer' ); ?></summary>
					<ul>
						<?php foreach ( $result['log'] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Redirect helper
	// -------------------------------------------------------------------------

	private static function redirect_with( string $type, string $message, string $tab = 'import' ): void {
		set_transient( 'wcri_error_' . get_current_user_id(), $message, 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=wcri-importer&tab=' . $tab . '&wcri_error=1' ) );
		exit;
	}
}

// Serve sample CSV download.
add_action( 'admin_init', static function () {
	if (
		isset( $_GET['page'], $_GET['wcri_sample'] )
		&& $_GET['page'] === 'wcri-importer'
		&& current_user_can( 'manage_woocommerce' )
	) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wcri-sample-import.csv"' );
		header( 'Pragma: no-cache' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM
		echo WCRI_Helpers::sample_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
} );
