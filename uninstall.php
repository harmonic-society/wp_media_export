<?php
/**
 * WP Media Export — Uninstall
 *
 * Cleans up transients and temp files created by the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wpme_export_%'
        OR option_name LIKE '_transient_timeout_wpme_export_%'"
);

// Remove temp directory.
$upload_dir = wp_upload_dir();
$tmp_dir    = $upload_dir['basedir'] . '/wpme-tmp';

if ( is_dir( $tmp_dir ) ) {
    $files = glob( $tmp_dir . '/*' );
    if ( $files ) {
        foreach ( $files as $file ) {
            @unlink( $file );
        }
    }
    @rmdir( $tmp_dir );
}
