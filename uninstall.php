<?php
/**
 * WP Media Export — Uninstall
 *
 * Cleans up transients created by the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wpme_export_%'
        OR option_name LIKE '_transient_timeout_wpme_export_%'"
);
