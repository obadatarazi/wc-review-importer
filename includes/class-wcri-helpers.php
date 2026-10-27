<?php
defined( 'ABSPATH' ) || exit;

/**
 * Static utility helpers.
 */
class WCRI_Helpers {

	const AVATAR_META_KEY = '_wcri_avatar_id';

	/**
	 * Register frontend hooks (review avatars).
	 */
	public static function init(): void {
		add_filter( 'pre_get_avatar_data', [ self::class, 'filter_avatar_data' ], 10, 2 );
		add_filter( 'get_avatar', [ self::class, 'filter_avatar_html' ], 10, 6 );
	}

	/**
	 * Resolve a product ID from either a numeric ID or a SKU string.
	 *
	 * @param string|int $identifier
	 * @return int|null  Product post ID, or null on failure.
	 */
	public static function resolve_product_id( $identifier ): ?int {
		$identifier = trim( $identifier );

		if ( is_numeric( $identifier ) ) {
			$id        = (int) $identifier;
			$post_type = get_post_type( $id );

			if ( 'product' === $post_type ) {
				return $id;
			}

			if ( 'product_variation' === $post_type ) {
				$parent_id = (int) wp_get_post_parent_id( $id );
				return $parent_id > 0 ? $parent_id : null;
			}
		}

		// Try as SKU.
		$id = wc_get_product_id_by_sku( $identifier );
		return $id ? $id : null;
	}

	/**
	 * Parse a date string into a MySQL datetime string.
	 * Falls back to "now" (optionally randomised within the past N days).
	 *
	 * @param string $raw         Raw date string from CSV.
	 * @param bool   $randomise   Return a random date within the past 365 days when $raw is empty.
	 * @return string             MySQL datetime (UTC).
	 */
	public static function parse_date( string $raw, bool $randomise = false ): string {
		$raw = trim( $raw );

		if ( $raw !== '' ) {
			$ts = strtotime( $raw );
			if ( $ts !== false ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		if ( $randomise ) {
			$offset = wp_rand( 0, 365 * DAY_IN_SECONDS );
			return gmdate( 'Y-m-d H:i:s', time() - $offset );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Clamp an integer to the 1-5 star range.
	 */
	public static function clamp_rating( $raw ): int {
		$int = (int) $raw;
		return max( 1, min( 5, $int ) );
	}

	/**
	 * Compute an MD5 fingerprint for a review row to detect duplicates.
	 */
	public static function fingerprint( int $product_id, string $email, string $content, int $rating = 0 ): string {
		$content = trim( $content );
		$key     = $product_id . '|' . strtolower( trim( $email ) ) . '|' . $content;
		if ( $content === '' ) {
			$key .= '|rating:' . $rating;
		}
		return md5( $key );
	}

	/**
	 * Check whether an identical review already exists (by fingerprint stored
	 * as comment meta).
	 */
	public static function duplicate_exists( string $fp ): bool {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_wcri_fp' AND meta_value = %s LIMIT 1",
				$fp
			)
		);
		return ! empty( $exists );
	}

	/**
	 * Download a remote image and attach it to the WordPress media library.
	 * Returns the attachment ID or 0 on failure.
	 */
	public static function sideload_image( string $url, int $post_id = 0 ): int {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp  = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file = [
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		];

		$id = media_handle_sideload( $file, $post_id );
		@unlink( $tmp );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Upload a reviewer avatar from an admin form file input.
	 *
	 * @param array $file       $_FILES entry.
	 * @param int   $post_id    Parent post ID for the attachment.
	 * @return int Attachment ID, or 0 on failure.
	 */
	public static function upload_avatar( array $file, int $post_id = 0 ): int {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return 0;
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return 0;
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$mime  = $check['type'] ?? '';
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
			return 0;
		}

		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_handle_upload( 'wcri_avatar', $post_id );
		return is_wp_error( $attachment_id ) ? 0 : (int) $attachment_id;
	}

	/**
	 * Resolve avatar attachment ID from import row data.
	 *
	 * @param array $row        Import row.
	 * @param int   $product_id Product post ID.
	 * @return int
	 */
	public static function resolve_avatar_id( array $row, int $product_id ): int {
		$avatar_id = (int) ( $row['avatar_id'] ?? 0 );
		if ( $avatar_id > 0 ) {
			return $avatar_id;
		}

		$avatar_url = trim( (string) ( $row['avatar_url'] ?? '' ) );
		if ( $avatar_url !== '' && filter_var( $avatar_url, FILTER_VALIDATE_URL ) ) {
			return self::sideload_image( $avatar_url, $product_id );
		}

		return 0;
	}

	/**
	 * Attach an avatar image to a review comment.
	 */
	public static function attach_avatar_to_comment( int $comment_id, int $attachment_id ): void {
		if ( $comment_id > 0 && $attachment_id > 0 ) {
			update_comment_meta( $comment_id, self::AVATAR_META_KEY, $attachment_id );
		}
	}

	/**
	 * Get avatar URL for a review comment.
	 */
	public static function get_avatar_url( int $comment_id, int $size = 96 ): string {
		$attachment_id = (int) get_comment_meta( $comment_id, self::AVATAR_META_KEY, true );
		if ( ! $attachment_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $attachment_id, [ $size, $size ] );
		return $url ? $url : '';
	}

	/**
	 * @param mixed $id_or_email Comment object, ID, or email.
	 */
	private static function comment_id_from_avatar_id( $id_or_email ): int {
		if ( is_object( $id_or_email ) && isset( $id_or_email->comment_ID ) ) {
			return (int) $id_or_email->comment_ID;
		}

		if ( is_numeric( $id_or_email ) ) {
			$comment = get_comment( (int) $id_or_email );
			return $comment ? (int) $comment->comment_ID : 0;
		}

		return 0;
	}

	/**
	 * Use custom avatar in WordPress avatar API (WP 4.2+).
	 *
	 * @param array $args        Avatar data.
	 * @param mixed $id_or_email Comment or user identifier.
	 * @return array
	 */
	public static function filter_avatar_data( array $args, $id_or_email ): array {
		$comment_id = self::comment_id_from_avatar_id( $id_or_email );
		if ( ! $comment_id ) {
			return $args;
		}

		$size = (int) ( $args['size'] ?? 96 );
		$url  = self::get_avatar_url( $comment_id, $size );
		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Fallback for themes that build avatar HTML directly.
	 *
	 * @param string $avatar         Default avatar HTML.
	 * @param mixed  $id_or_email    Comment or user identifier.
	 * @param int    $size           Avatar size.
	 * @param string $default        Default URL.
	 * @param string $alt            Alt text.
	 * @param array  $args           Extra args.
	 * @return string
	 */
	public static function filter_avatar_html( $avatar, $id_or_email, $size, $default, $alt, $args ): string {
		$comment_id = self::comment_id_from_avatar_id( $id_or_email );
		if ( ! $comment_id ) {
			return $avatar;
		}

		$url = self::get_avatar_url( $comment_id, (int) $size );
		if ( ! $url ) {
			return $avatar;
		}

		$classes = isset( $args['class'] ) ? $args['class'] : '';
		if ( is_array( $classes ) ) {
			$classes = implode( ' ', $classes );
		}

		return sprintf(
			'<img alt="%s" src="%s" class="avatar avatar-%d photo %s" height="%d" width="%d" loading="lazy" decoding="async" />',
			esc_attr( $alt ),
			esc_url( $url ),
			(int) $size,
			esc_attr( $classes ),
			(int) $size,
			(int) $size
		);
	}

	/**
	 * Return all expected CSV column headers (lowercase, trimmed).
	 */
	public static function csv_headers(): array {
		return [
			'product_id',
			'sku',
			'reviewer_name',
			'reviewer_email',
			'rating',
			'review_content',
			'review_date',
			'verified',
			'image_url',
			'avatar_url',
		];
	}

	/**
	 * Generate a sample CSV string for download.
	 */
	public static function sample_csv(): string {
		$rows = [
			self::csv_headers(),
			[ '42',  '',          'Ahmed Al-Rashid', 'ahmed@example.com',  '5', 'منتج رائع جداً وجودة عالية!', '2024-03-15', '1', '', '' ],
			[ '',    'MY-SKU-01', 'Sarah Jones',     'sarah@example.com',  '4', 'Great product, fast shipping.', '2024-02-20', '0', 'https://example.com/photo.jpg', '' ],
			[ '100', '',          'Carlos Méndez',   'carlos@example.com', '3', 'Average quality, okay price.',  '',           '1', '', '' ],
		];

		$out = '';
		foreach ( $rows as $row ) {
			$out .= implode( ',', array_map( static fn( $v ) => '"' . str_replace( '"', '""', $v ) . '"', $row ) ) . "\r\n";
		}
		return $out;
	}
}
