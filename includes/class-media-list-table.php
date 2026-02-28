<?php
/**
 * WPME_Media_List_Table — WP_List_Table extension for media listing.
 *
 * Displays attachments with checkbox, thumbnail, title, filename,
 * MIME type, date, and file size. Supports MIME type and date filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WPME_Media_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'media',
            'plural'   => 'medias',
            'ajax'     => false,
        ) );
    }

    /**
     * Define table columns.
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'        => '<input type="checkbox" />',
            'thumbnail' => '',
            'title'     => __( 'タイトル', 'wp-media-export' ),
            'filename'  => __( 'ファイル名', 'wp-media-export' ),
            'mime_type' => __( 'タイプ', 'wp-media-export' ),
            'date'      => __( '日付', 'wp-media-export' ),
            'file_size' => __( 'サイズ', 'wp-media-export' ),
        );
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'title' => array( 'title', false ),
            'date'  => array( 'date', true ),
        );
    }

    /**
     * Checkbox column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="media_ids[]" value="%d" />',
            $item->ID
        );
    }

    /**
     * Thumbnail column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_thumbnail( $item ) {
        return wp_get_attachment_image( $item->ID, array( 40, 40 ), true );
    }

    /**
     * Title column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_title( $item ) {
        return esc_html( $item->post_title ?: __( '(タイトルなし)', 'wp-media-export' ) );
    }

    /**
     * Filename column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_filename( $item ) {
        $file = get_attached_file( $item->ID );
        return esc_html( $file ? wp_basename( $file ) : '—' );
    }

    /**
     * MIME type column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_mime_type( $item ) {
        return esc_html( $item->post_mime_type );
    }

    /**
     * Date column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_date( $item ) {
        return esc_html( mysql2date( get_option( 'date_format' ), $item->post_date ) );
    }

    /**
     * File size column.
     *
     * @param WP_Post $item
     * @return string
     */
    public function column_file_size( $item ) {
        $file = get_attached_file( $item->ID );
        if ( $file && file_exists( $file ) ) {
            return esc_html( size_format( filesize( $file ) ) );
        }
        return '—';
    }

    /**
     * Extra filter controls above the table.
     *
     * @param string $which 'top' or 'bottom'.
     */
    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        $mime_filter = isset( $_GET['mime_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['mime_filter'] ) ) : '';
        $m           = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0;
        ?>
        <div class="alignleft actions">
            <select name="mime_filter" id="wpme-mime-filter">
                <option value=""><?php esc_html_e( 'すべてのタイプ', 'wp-media-export' ); ?></option>
                <option value="image" <?php selected( $mime_filter, 'image' ); ?>><?php esc_html_e( '画像', 'wp-media-export' ); ?></option>
                <option value="video" <?php selected( $mime_filter, 'video' ); ?>><?php esc_html_e( '動画', 'wp-media-export' ); ?></option>
                <option value="audio" <?php selected( $mime_filter, 'audio' ); ?>><?php esc_html_e( '音声', 'wp-media-export' ); ?></option>
                <option value="application" <?php selected( $mime_filter, 'application' ); ?>><?php esc_html_e( 'ドキュメント', 'wp-media-export' ); ?></option>
            </select>

            <?php $this->months_dropdown( 'attachment' ); ?>

            <?php submit_button( __( 'フィルタ', 'wp-media-export' ), '', 'filter_action', false ); ?>
        </div>
        <?php
    }

    /**
     * Year-month dropdown for date filtering.
     *
     * @param string $post_type
     */
    protected function months_dropdown( $post_type ) {
        global $wpdb;

        $months = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
                 FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = 'inherit'
                 ORDER BY post_date DESC",
                $post_type
            )
        );

        $m = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0;
        ?>
        <select name="m" id="wpme-date-filter">
            <option value="0"><?php esc_html_e( 'すべての日付', 'wp-media-export' ); ?></option>
            <?php foreach ( $months as $row ) :
                $value = $row->year * 100 + $row->month;
                /* translators: 1: year, 2: month name */
                $label = sprintf(
                    '%1$d年%2$s',
                    $row->year,
                    $GLOBALS['wp_locale']->get_month( str_pad( $row->month, 2, '0', STR_PAD_LEFT ) )
                );
                ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $m, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Build WP_Query args from current request parameters.
     *
     * @return array
     */
    public static function build_query_args() {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        // MIME type filter.
        $mime_filter = isset( $_GET['mime_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['mime_filter'] ) ) : '';
        if ( $mime_filter ) {
            $args['post_mime_type'] = $mime_filter;
        }

        // Date filter (YYYYMM).
        $m = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : 0;
        if ( $m ) {
            $args['m'] = $m;
        }

        // Search.
        $s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        if ( $s ) {
            $args['s'] = $s;
        }

        // Sort.
        $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
        if ( in_array( $orderby, array( 'title', 'date' ), true ) ) {
            $args['orderby'] = $orderby;
        }
        $order = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : '';
        if ( in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
            $args['order'] = $order;
        }

        return $args;
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items() {
        $per_page = 50;
        $paged    = $this->get_pagenum();

        $args = self::build_query_args();
        $args['posts_per_page'] = $per_page;
        $args['paged']          = $paged;

        $query = new WP_Query( $args );

        $this->items = $query->posts;

        $this->set_pagination_args( array(
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => $query->max_num_pages,
        ) );

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }

    /**
     * Message when no items are found.
     */
    public function no_items() {
        esc_html_e( 'メディアファイルが見つかりません。', 'wp-media-export' );
    }
}
