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
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return md5( uniqid( wp_rand(), true ) );
    }

    /**
     * Transient key for a given token.
     *
     * @param string $token
     * @return string
     */
    private function transient_key( $token ) {
        return 'wpme_export_' . substr( preg_replace( '/[^a-f0-9\-]/', '', $token ), 0, 36 );
    }

    /**
     * Create a temp file path in the uploads directory.
     *
     * Uses the uploads dir (always writable) instead of system temp
     * to avoid cross-device rename issues and permission problems.
     *
     * @return string|false
     */
    private function create_temp_path() {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = $upload_dir['basedir'] . '/wpme-tmp';

        if ( ! file_exists( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
            // Prevent directory listing.
            @file_put_contents( $tmp_dir . '/index.php', '<?php // Silence.' );
        }

        $filename = 'wpme_' . wp_generate_password( 12, false ) . '.zip';
        $filepath = $tmp_dir . '/' . $filename;

        // Create the empty file.
        $result = @file_put_contents( $filepath, '' );
        if ( false === $result ) {
            return false;
        }

        return $filepath;
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
        $zip_path = $this->create_temp_path();

        if ( ! $zip_path ) {
            wp_send_json_error( array(
                'message' => __( '一時ファイルを作成できませんでした。アップロードディレクトリの書き込み権限を確認してください。', 'wp-media-export' ),
            ) );
        }

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

        // IDs may arrive as a JSON string or array.
        $ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '[]';
        if ( is_array( $ids_raw ) ) {
            $ids = array_map( 'absint', $ids_raw );
        } else {
            $decoded = json_decode( sanitize_text_field( $ids_raw ), true );
            $ids     = is_array( $decoded ) ? array_map( 'absint', $decoded ) : array();
        }

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

        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        // Sanitize filter params from POST.
        if ( isset( $_POST['mime_filter'] ) ) {
            $mime = sanitize_text_field( wp_unslash( $_POST['mime_filter'] ) );
            if ( $mime ) {
                $args['post_mime_type'] = $mime;
            }
        }
        if ( isset( $_POST['m'] ) ) {
            $m = absint( $_POST['m'] );
            if ( $m ) {
                $args['m'] = $m;
            }
        }
        if ( isset( $_POST['s'] ) ) {
            $s = sanitize_text_field( wp_unslash( $_POST['s'] ) );
            if ( $s ) {
                $args['s'] = $s;
            }
        }

        $query = new WP_Query( $args );

        wp_send_json_success( array(
            'ids'   => $query->posts,
            'total' => count( $query->posts ),
        ) );
    }
}
