<?php
/**
 * Plugin Name: WP Media Export
 * Plugin URI:  https://github.com/example/wp-media-export
 * Description: WordPress のメディアファイルを一括でZIPダウンロードできるプラグイン。バッチ処理とプログレスバーで大量ファイルにも対応。
 * Version:     1.0.0
 * Author:      WP Media Export Team
 * Author URI:  https://github.com/example
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-export
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPME_VERSION', '1.0.0' );
define( 'WPME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPME_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation hook — check for ZipArchive support.
 */
function wpme_activate() {
    if ( ! class_exists( 'ZipArchive' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'WP Media Export requires the PHP ZipArchive extension. Please enable it and try again.', 'wp-media-export' ),
            'Plugin Activation Error',
            array( 'back_link' => true )
        );
    }
}
register_activation_hook( __FILE__, 'wpme_activate' );

require_once WPME_PLUGIN_DIR . 'includes/class-wp-media-export.php';
require_once WPME_PLUGIN_DIR . 'includes/class-media-list-table.php';
require_once WPME_PLUGIN_DIR . 'includes/class-zip-builder.php';
require_once WPME_PLUGIN_DIR . 'includes/class-ajax-handler.php';

WP_Media_Export::get_instance();
WPME_Ajax_Handler::get_instance();
