<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles parsing CSV/XLSX data and inserting WooCommerce reviews.
 */
class WCRI_Importer {

	/** @var array<string,mixed> Import configuration. */
	private array $config;

	/** @var array<int,string> Collected log messages. */
	private array $log = [];

	/** @var int */
	private int $imported = 0;

	/** @var int */
	private int $skipped = 0;

	/** @var int */
	private int $errors = 0;

	public function __construct( array $config = [] ) {
		$this->config = wp_parse_args( $config, [
			'randomise_dates'  => false,
			'verified_default' => false,
			'auto_publish'     => true,
		] );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Import from a CSV file path.
	 *
	 * @param string $file_path Absolute path to the uploaded file.
	 * @return array{imported:int,skipped:int,errors:int,log:array}
	 */
	public function import_file( string $file_path ): array {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, [ 'xlsx', 'xls' ], true ) ) {
			$rows = $this->parse_xlsx( $file_path );
		} else {
			$rows = $this->parse_csv( $file_path );
		}

		if ( is_wp_error( $rows ) ) {
			$this->log[] = '❌ ' . $rows->get_error_message();
			$this->errors++;
		} else {
			$this->process_rows( $rows );
		}

		return $this->result();
	}

	/**
	 * Import a single review from an associative array (manual add form).
	 */
	public function import_single( array $data ): array {
		$this->process_rows( [ $data ] );
		return $this->result();
	}

	// -------------------------------------------------------------------------
	// Parsers
	// -------------------------------------------------------------------------

	private function parse_csv( string $path ): array|\WP_Error {
		// Force UTF-8: detect and convert BOM / encoding.
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			return new \WP_Error( 'read_fail', __( 'Cannot read uploaded file.', 'wc-review-importer' ) );
		}

		// Strip UTF-8 BOM if present.
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}

		// Detect line endings.
		$raw   = str_replace( "\r\n", "\n", $raw );
		$raw   = str_replace( "\r",   "\n", $raw );
		$lines = explode( "\n", trim( $raw ) );

		if ( count( $lines ) < 2 ) {
			return new \WP_Error( 'empty', __( 'CSV file appears to be empty or has only headers.', 'wc-review-importer' ) );
		}

		// Parse headers.
		$headers = $this->parse_csv_line( array_shift( $lines ) );
		$headers = array_map( static fn( $h ) => strtolower( trim( $h ) ), $headers );

		$rows = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$values = $this->parse_csv_line( $line );
			if ( count( $values ) !== count( $headers ) ) {
				// Pad or trim to match header count.
				$values = array_pad( array_slice( $values, 0, count( $headers ) ), count( $headers ), '' );
			}
			$rows[] = array_combine( $headers, $values );
		}

		return $rows;
	}

	/**
	 * Minimal RFC-4180 CSV line parser (handles quoted fields with embedded commas/newlines).
	 */
	private function parse_csv_line( string $line ): array {
		$fields  = [];
		$current = '';
		$in_quote = false;
		$len     = strlen( $line );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $line[ $i ];

			if ( $in_quote ) {
				if ( $ch === '"' ) {
					if ( $i + 1 < $len && $line[ $i + 1 ] === '"' ) {
						$current .= '"';
						$i++;
					} else {
						$in_quote = false;
					}
				} else {
					$current .= $ch;
				}
			} else {
				if ( $ch === '"' ) {
					$in_quote = true;
				} elseif ( $ch === ',' ) {
					$fields[] = $current;
					$current  = '';
				} else {
					$current .= $ch;
				}
			}
		}

		$fields[] = $current;
		return $fields;
	}

	private function parse_xlsx( string $path ): array|\WP_Error {
		// Use PhpSpreadsheet if available (bundled via Composer / installed separately).
		// Fallback: treat as CSV (some "xlsx" exports are actually CSV).
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			try {
				$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $path );
				$sheet       = $spreadsheet->getActiveSheet();
				$data        = $sheet->toArray( null, true, true, false );

				if ( empty( $data ) ) {
					return new \WP_Error( 'empty_xlsx', __( 'Excel file is empty.', 'wc-review-importer' ) );
				}

				$headers = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), array_shift( $data ) );
				$rows    = [];
				foreach ( $data as $row ) {
					$row    = array_pad( array_slice( $row, 0, count( $headers ) ), count( $headers ), '' );
					$rows[] = array_combine( $headers, $row );
				}
				return $rows;
			} catch ( \Exception $e ) {
				return new \WP_Error( 'xlsx_fail', $e->getMessage() );
			}
		}

		// Graceful fallback – try reading as CSV.
		$this->log[] = '⚠️ ' . __( 'PhpSpreadsheet not found – attempting to parse XLSX as CSV.', 'wc-review-importer' );
		return $this->parse_csv( $path );
	}

	// -------------------------------------------------------------------------
	// Row processing
	// -------------------------------------------------------------------------

	private function process_rows( array $rows ): void {
		foreach ( $rows as $index => $row ) {
			$row_num = $index + 2; // +2 because row 1 = headers, humans count from 1.
			$this->process_row( $row, $row_num );
		}
	}

	private function process_row( array $row, int $row_num ): void {
		// Normalise keys.
		$row = array_map( static fn( $v ) => trim( (string) $v ), $row );

		$identifier = $row['product_id'] ?? $row['sku'] ?? '';
		if ( $identifier === '' ) {
			$identifier = $row['product_id'] ?? '';
		}
		if ( $identifier === '' ) {
			$identifier = $row['sku'] ?? '';
		}

		if ( $identifier === '' ) {
			$this->log[]   = sprintf( __( 'Row %d – skipped: no product_id or sku.', 'wc-review-importer' ), $row_num );
			$this->skipped++;
			return;
		}

		$product_id = WCRI_Helpers::resolve_product_id( $identifier );
		if ( ! $product_id ) {
			/* translators: 1: row number 2: identifier */
			$this->log[]   = sprintf( __( 'Row %d – skipped: product not found for "%s".', 'wc-review-importer' ), $row_num, esc_html( $identifier ) );
			$this->skipped++;
			return;
		}

		$content = trim( (string) ( $row['review_content'] ?? '' ) );

		$email = sanitize_email( $row['reviewer_email'] ?? '' );
		$name  = sanitize_text_field( $row['reviewer_name'] ?? __( 'Anonymous', 'wc-review-importer' ) );

		$rating = WCRI_Helpers::clamp_rating( $row['rating'] ?? 5 );

		// Duplicate check.
		$fp = WCRI_Helpers::fingerprint( $product_id, $email, $content, $rating );
		if ( WCRI_Helpers::duplicate_exists( $fp ) ) {
			/* translators: 1: row number */
			$this->log[]   = sprintf( __( 'Row %d – skipped: duplicate review detected.', 'wc-review-importer' ), $row_num );
			$this->skipped++;
			return;
		}

		$date     = WCRI_Helpers::parse_date( $row['review_date'] ?? '', $this->config['randomise_dates'] );

		$verified_raw = $row['verified'] ?? ( $this->config['verified_default'] ? '1' : '0' );
		$verified     = in_array( strtolower( $verified_raw ), [ '1', 'yes', 'true', 'y' ], true ) ? 1 : 0;

		$comment_data = [
			'comment_post_ID'      => $product_id,
			'comment_author'       => $name,
			'comment_author_email' => $email,
			'comment_content'      => wp_kses_post( $content ),
			'comment_date'         => get_date_from_gmt( $date ),
			'comment_date_gmt'     => $date,
			'comment_approved'     => $this->config['auto_publish'] ? 1 : 0,
			'comment_type'         => 'review',
		];

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			/* translators: 1: row number */
			$this->log[]  = sprintf( __( 'Row %d – error: could not insert review.', 'wc-review-importer' ), $row_num );
			$this->errors++;
			return;
		}

		// Store meta.
		add_comment_meta( $comment_id, 'rating',         $rating, true );
		add_comment_meta( $comment_id, 'verified',       $verified, true );
		add_comment_meta( $comment_id, '_wcri_fp',       $fp, true );
		add_comment_meta( $comment_id, '_wcri_imported', 1, true );

		$avatar_id = WCRI_Helpers::resolve_avatar_id( $row, $product_id );
		if ( $avatar_id ) {
			WCRI_Helpers::attach_avatar_to_comment( $comment_id, $avatar_id );
		}

		// Sideload image if provided.
		$image_url = $row['image_url'] ?? '';
		if ( $image_url !== '' && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$att_id = WCRI_Helpers::sideload_image( $image_url, $product_id );
			if ( $att_id ) {
				add_comment_meta( $comment_id, '_wcri_image_id', $att_id, true );
			}
		}

		// Recalculate product rating / review counts (WC 3.6+).
		if ( class_exists( 'WC_Comments' ) && is_callable( [ 'WC_Comments', 'clear_transients' ] ) ) {
			WC_Comments::clear_transients( $product_id );
		}

		$this->imported++;
		/* translators: 1: row number 2: product id 3: reviewer name */
		$this->log[] = sprintf( __( 'Row %d – ✅ imported for product #%d by %s.', 'wc-review-importer' ), $row_num, $product_id, esc_html( $name ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function result(): array {
		return [
			'imported' => $this->imported,
			'skipped'  => $this->skipped,
			'errors'   => $this->errors,
			'log'      => $this->log,
		];
	}
}
