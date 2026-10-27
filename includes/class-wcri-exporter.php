<?php
defined( 'ABSPATH' ) || exit;

/**
 * Exports WooCommerce reviews to CSV.
 */
class WCRI_Exporter {

	/**
	 * Stream a CSV of all WooCommerce reviews to the browser.
	 * Exits after output.
	 */
	public static function stream_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-review-importer' ) );
		}

		$filename = 'wc-reviews-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		// Flush any output buffering.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Headers.
		fputcsv( $output, [
			'product_id',
			'sku',
			'reviewer_name',
			'reviewer_email',
			'rating',
			'review_content',
			'review_date',
			'verified',
			'comment_id',
			'status',
		] );

		// Fetch reviews in batches.
		$page     = 1;
		$per_page = 200;

		do {
			$comments = get_comments( [
				'type'    => 'review',
				'status'  => 'any',
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
			] );

			foreach ( $comments as $comment ) {
				$product_id = (int) $comment->comment_post_ID;
				$product    = wc_get_product( $product_id );
				$sku        = $product ? $product->get_sku() : '';
				$rating     = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
				$verified   = (int) get_comment_meta( $comment->comment_ID, 'verified', true );

				fputcsv( $output, [
					$product_id,
					$sku,
					$comment->comment_author,
					$comment->comment_author_email,
					$rating ?: '',
					$comment->comment_content,
					$comment->comment_date_gmt,
					$verified,
					$comment->comment_ID,
					$comment->comment_approved,
				] );
			}

			$page++;
		} while ( count( $comments ) === $per_page );

		fclose( $output );
		exit;
	}
}
