<?php
/**
 * WPME_Ajax_Handler — AJAX endpoints for the export workflow.
 *
 * Actions:
 *   wpme_start_export    — Create ZIP session (returns token).
 *   wpme_add_batch       — Add a batch of files to the ZIP.
 *   wpme_download        — Stream the completed ZIP and clean up.
 *   wpme_get_filtered_ids — Return all IDs matching current filters.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPME_Ajax_Handler {

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_wpme_start_export', array( $this, 'start_export' ) );
        add_action( 'wp_ajax_wpme_add_batch', array( $this, 'add_batch' ) );
        add_action( 'wp_ajax_wpme_download', array( $this, 'download' ) );
        add_action( 'wp_ajax_wpme_get_filtered_ids', array( $this, 'get_filtered_ids' ) );
    }

    /**
     * Verify nonce and capability; die on failure.
     */
    private function verify_request() {
        check_ajax_referer( 'wpme_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array(
                'message' => __( '権限がありません。', 'wp-media-export' ),
            ), 403 );
        }
    }

    /**
     * Generate a UUID v4.
     *
     * @return string
     */
    private function generate_token() {
        $bytes = random_bytes( 16 );
        // Set version (4) and variant bits.
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
    }

    /**
     * Transient key for a given token.
     *
     * @param string $token
     * @return string
     */
    private function transient_key( $token ) {
        return 'wpme_export_' . $token;
    }

    /* ──────────────────────────────────────────────
     * AJAX: wpme_start_export
     * ────────────────────────────────────────────── */

    public function start_export() {
        $this->verify_request();

        $total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;

        if ( $total < 1 ) {
            wp_send_json_error( array(
                'message' => __( 'エクスポート対象が選択されていません。', 'wp-media-export' ),
            ) );
        }

        $token    = $this->generate_token();
        $zip_path = wp_tempnam( 'wpme_' );

        // Rename to .zip extension for clarity.
        $zip_path_ext = $zip_path . '.zip';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @rename( $zip_path, $zip_path_ext );
        $zip_path = $zip_path_ext;

        set_transient( $this->transient_key( $token ), array(
            'zip_path' => $zip_path,
            'total'    => $total,
            'added'    => 0,
            'user_id'  => get_current_user_id(),
        ), HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'token' => $token,
        ) );
    }

    /* ──────────────────────────────────────────────
     * AJAX: wpme_add_batch
     * ────────────────────────────────────────────── */

    public function add_batch() {
        $this->verify_request();

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $ids   = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();

        if ( ! $token || empty( $ids ) ) {
            wp_send_json_error( array(
                'message' => __( '不正なリクエストです。', 'wp-media-export' ),
            ) );
        }

        $session = get_transient( $this->transient_key( $token ) );

        if ( ! $session ) {
            wp_send_json_error( array(
                'message' => __( 'セッションが期限切れです。もう一度やり直してください。', 'wp-media-export' ),
            ) );
        }

        // Verify ownership.
        if ( (int) $session['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array(
                'message' => __( '権限がありません。', 'wp-media-export' ),
            ), 403 );
        }

        $builder = new WPME_Zip_Builder( $session['zip_path'] );
        $added   = $builder->add_batch( $ids );

        $session['added'] += $added;
        set_transient( $this->transient_key( $token ), $session, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'added'    => $session['added'],
            'total'    => $session['total'],
            'errors'   => $builder->get_errors(),
        ) );
    }

    /* ──────────────────────────────────────────────
     * AJAX: wpme_download
     * ────────────────────────────────────────────── */

    public function download() {
        // Use GET for iframe-based download; nonce in query string.
        check_ajax_referer( 'wpme_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( esc_html__( '権限がありません。', 'wp-media-export' ), 403 );
        }

        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( ! $token ) {
            wp_die( esc_html__( '不正なリクエストです。', 'wp-media-export' ) );
        }

        $session = get_transient( $this->transient_key( $token ) );

        if ( ! $session ) {
            wp_die( esc_html__( 'セッションが期限切れです。', 'wp-media-export' ) );
        }

        if ( (int) $session['user_id'] !== get_current_user_id() ) {
            wp_die( esc_html__( '権限がありません。', 'wp-media-export' ), 403 );
        }

        $zip_path = $session['zip_path'];

        if ( ! file_exists( $zip_path ) ) {
            wp_die( esc_html__( 'ZIPファイルが見つかりません。', 'wp-media-export' ) );
        }

        // Clean up transient (single-use token).
        delete_transient( $this->transient_key( $token ) );

        $filename = 'media-export-' . gmdate( 'Y-m-d-His' ) . '.zip';
        $filesize = filesize( $zip_path );

        // Stream the ZIP.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @ob_end_clean();
        while ( ob_get_level() > 0 ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . $filesize );
        header( 'Content-Transfer-Encoding: binary' );

        // Read in 8 KB chunks.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fh = fopen( $zip_path, 'rb' );
        if ( $fh ) {
            while ( ! feof( $fh ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                echo fread( $fh, 8192 );
                flush();
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $fh );
        }

        // Delete temp file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink( $zip_path );

        exit;
    }

    /* ──────────────────────────────────────────────
     * AJAX: wpme_get_filtered_ids
     * ────────────────────────────────────────────── */

    public function get_filtered_ids() {
        $this->verify_request();

        $args = WPME_Media_List_Table::build_query_args();
        $args['posts_per_page'] = -1;
        $args['fields']         = 'ids';
        $args['no_found_rows']  = true;

        // Sanitize filter params from POST.
        if ( isset( $_POST['mime_filter'] ) ) {
            $mime = sanitize_text_field( wp_unslash( $_POST['mime_filter'] ) );
            if ( $mime ) {
                $args['post_mime_type'] = $mime;
            } else {
                unset( $args['post_mime_type'] );
            }
        }
        if ( isset( $_POST['m'] ) ) {
            $m = absint( $_POST['m'] );
            if ( $m ) {
                $args['m'] = $m;
            } else {
                unset( $args['m'] );
            }
        }
        if ( isset( $_POST['s'] ) ) {
            $s = sanitize_text_field( wp_unslash( $_POST['s'] ) );
            if ( $s ) {
                $args['s'] = $s;
            } else {
                unset( $args['s'] );
            }
        }

        $query = new WP_Query( $args );

        wp_send_json_success( array(
            'ids'   => $query->posts,
            'total' => count( $query->posts ),
        ) );
    }
}
