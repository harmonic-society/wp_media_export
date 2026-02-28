<?php
/**
 * WPME_Zip_Builder — Builds a ZIP archive on disk.
 *
 * Handles batch-adding files with periodic ZipArchive close/reopen
 * to stay within PHP file handle limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPME_Zip_Builder {

    /** @var string Absolute path to the temporary ZIP file. */
    private $zip_path;

    /** @var string[] Accumulated error messages. */
    private $errors = array();

    /** @var int Number of files added since last close/reopen. */
    private $files_since_reopen = 0;

    /** @var int Reopen interval (to avoid file handle exhaustion). */
    const REOPEN_INTERVAL = 200;

    /**
     * @param string $zip_path Path to the ZIP file on disk.
     */
    public function __construct( $zip_path ) {
        $this->zip_path = $zip_path;
    }

    /**
     * Get the ZIP file path.
     *
     * @return string
     */
    public function get_path() {
        return $this->zip_path;
    }

    /**
     * Get accumulated errors.
     *
     * @return string[]
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Add a batch of attachment IDs to the ZIP.
     *
     * @param int[] $ids Attachment post IDs.
     * @return int Number of files successfully added in this batch.
     */
    public function add_batch( array $ids ) {
        $upload_dir  = wp_upload_dir();
        $upload_base = realpath( $upload_dir['basedir'] );

        if ( ! $upload_base ) {
            $this->errors[] = __( 'アップロードディレクトリが見つかりません。', 'wp-media-export' );
            return 0;
        }

        $zip = new ZipArchive();

        // Open or create the ZIP file.
        $flag   = file_exists( $this->zip_path ) ? 0 : ZipArchive::CREATE;
        $result = $zip->open( $this->zip_path, $flag );

        if ( true !== $result ) {
            $this->errors[] = sprintf(
                /* translators: %d: ZipArchive error code */
                __( 'ZIPファイルを開けませんでした (エラーコード: %d)', 'wp-media-export' ),
                $result
            );
            return 0;
        }

        $added = 0;

        foreach ( $ids as $id ) {
            $id   = absint( $id );
            $file = get_attached_file( $id );

            if ( ! $file || ! file_exists( $file ) ) {
                $this->errors[] = sprintf(
                    /* translators: %d: attachment ID */
                    __( 'ID %d: ファイルが見つかりません。', 'wp-media-export' ),
                    $id
                );
                continue;
            }

            // Security: ensure file is inside uploads directory.
            $real = realpath( $file );
            if ( ! $real || strpos( $real, $upload_base . DIRECTORY_SEPARATOR ) !== 0 ) {
                $this->errors[] = sprintf(
                    /* translators: %d: attachment ID */
                    __( 'ID %d: アップロードディレクトリ外のファイルです。', 'wp-media-export' ),
                    $id
                );
                continue;
            }

            // Build relative path: uploads/2024/06/photo.jpg → 2024/06/photo.jpg
            $relative = substr( $real, strlen( $upload_base ) + 1 );

            $zip->addFile( $real, $relative );
            $added++;
            $this->files_since_reopen++;

            // Reopen to release file handles.
            if ( $this->files_since_reopen >= self::REOPEN_INTERVAL ) {
                $zip->close();
                $zip = new ZipArchive();
                $zip->open( $this->zip_path );
                $this->files_since_reopen = 0;
            }
        }

        $zip->close();

        return $added;
    }
}
