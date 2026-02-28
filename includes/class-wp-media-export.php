<?php
/**
 * WP_Media_Export — Core plugin class (singleton).
 *
 * Registers admin menu, enqueues assets, renders the admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Media_Export {

    /** @var self|null */
    private static $instance = null;

    /** @var string Admin page hook suffix. */
    private $hook_suffix = '';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register submenu under Media.
     */
    public function add_menu() {
        $this->hook_suffix = add_submenu_page(
            'upload.php',
            __( 'メディアエクスポート', 'wp-media-export' ),
            __( 'メディアエクスポート', 'wp-media-export' ),
            'upload_files',
            'wp-media-export',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enqueue CSS/JS only on our admin page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'wpme-admin-style',
            WPME_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            WPME_VERSION
        );

        wp_enqueue_script(
            'wpme-admin-script',
            WPME_PLUGIN_URL . 'assets/js/admin-script.js',
            array( 'jquery' ),
            WPME_VERSION,
            true
        );

        wp_localize_script( 'wpme-admin-script', 'wpme', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wpme_nonce' ),
            'batch_size' => 50,
            'i18n'       => array(
                'no_selection'     => __( 'エクスポートするメディアを選択してください。', 'wp-media-export' ),
                'preparing'        => __( '準備中…', 'wp-media-export' ),
                'processing'       => __( '処理中… %1$d / %2$d', 'wp-media-export' ),
                'downloading'      => __( 'ダウンロード中…', 'wp-media-export' ),
                'complete'         => __( '完了しました。', 'wp-media-export' ),
                'error'            => __( 'エラーが発生しました。', 'wp-media-export' ),
                'select_all_page'  => __( 'このページの %d 件が選択されています。', 'wp-media-export' ),
                'select_all_match' => __( 'フィルタに一致する全 %d 件を選択', 'wp-media-export' ),
                'all_selected'     => __( 'フィルタに一致する全 %d 件が選択されています。', 'wp-media-export' ),
                'clear_selection'  => __( '選択を解除', 'wp-media-export' ),
                'fetching_all'     => __( '全件のIDを取得中…', 'wp-media-export' ),
                'confirm_all'      => __( '全 %d 件のメディアをダウンロードします。よろしいですか？', 'wp-media-export' ),
            ),
        ) );
    }

    /**
     * Render the admin page.
     */
    public function render_admin_page() {
        $list_table = new WPME_Media_List_Table();
        $list_table->prepare_items();
        ?>
        <div class="wrap wpme-wrap">
            <h1><?php esc_html_e( 'メディアエクスポート', 'wp-media-export' ); ?></h1>
            <p><?php esc_html_e( 'エクスポートするメディアファイルを選択し、「ダウンロード」ボタンをクリックしてください。', 'wp-media-export' ); ?></p>

            <form id="wpme-media-form" method="get">
                <input type="hidden" name="page" value="wp-media-export" />
                <?php
                $list_table->search_box( __( '検索', 'wp-media-export' ), 'wpme-search' );
                ?>

                <div class="wpme-select-all-banner" id="wpme-select-all-banner"></div>

                <?php $list_table->display(); ?>
            </form>

            <div class="wpme-actions">
                <button type="button" id="wpme-download-btn" class="button button-primary">
                    <?php esc_html_e( '選択をダウンロード', 'wp-media-export' ); ?>
                </button>
                <button type="button" id="wpme-download-all-btn" class="button button-secondary">
                    <?php esc_html_e( '全件ダウンロード', 'wp-media-export' ); ?>
                </button>
                <span id="wpme-selected-count"></span>
            </div>

            <div class="wpme-progress-wrap" id="wpme-progress-wrap">
                <div class="wpme-progress-bar-outer">
                    <div class="wpme-progress-bar-inner" id="wpme-progress-bar"></div>
                    <div class="wpme-progress-text" id="wpme-progress-text">0%</div>
                </div>
                <div class="wpme-progress-status" id="wpme-progress-status"></div>
            </div>

            <div class="wpme-errors" id="wpme-errors">
                <strong><?php esc_html_e( '一部のファイルでエラーが発生しました:', 'wp-media-export' ); ?></strong>
                <ul id="wpme-error-list"></ul>
            </div>

            <iframe class="wpme-download-frame" id="wpme-download-frame"></iframe>
        </div>
        <?php
    }
}
