<?php
/**
 * Plugin Name: WC Review Importer
 * Plugin URI:  https://cupcoding.com/wc-review-importer
 * Description: Bulk import and manage WooCommerce product reviews via CSV/Excel. Supports Arabic/UTF-8, verified owner toggle, image import, and export.
 * Version:     1.1.6
 * Author:      Obada Al-Tarazi
 * Text Domain: wc-review-importer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WCRI_VERSION',     '1.1.6' );
define( 'WCRI_PLUGIN_FILE', __FILE__ );
define( 'WCRI_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WCRI_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Main plugin bootstrap – loaded after all plugins are initialised so that
 * WooCommerce is guaranteed to be present.
 */
add_action( 'plugins_loaded', static function () {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'WC Review Importer requires WooCommerce to be active.', 'wc-review-importer' )
				. '</p></div>';
		} );
		return;
	}

	// Declare HPOS compatibility.
	add_action( 'before_woocommerce_init', static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCRI_PLUGIN_FILE, true );
		}
	} );

	require_once WCRI_PLUGIN_DIR . 'includes/class-wcri-helpers.php';
	require_once WCRI_PLUGIN_DIR . 'includes/class-wcri-importer.php';
	require_once WCRI_PLUGIN_DIR . 'includes/class-wcri-exporter.php';
	require_once WCRI_PLUGIN_DIR . 'admin/class-wcri-admin.php';

	WCRI_Helpers::init();
	WCRI_Admin::init();
} );

register_activation_hook( WCRI_PLUGIN_FILE, static function () {
	// Create upload directory for review images.
	$upload   = wp_upload_dir();
	$dir      = $upload['basedir'] . '/wcri-images';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Options -Indexes\n" );
	}
} );
