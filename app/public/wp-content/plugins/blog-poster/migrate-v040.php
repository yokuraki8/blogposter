<?php
/**
 * Migration v0.4.0: Create content index table for RAG feature
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function blog_poster_migrate_v040() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'blog_poster_content_index';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        post_type VARCHAR(50) NOT NULL DEFAULT 'post',
        title TEXT NOT NULL,
        url TEXT NOT NULL,
        content_text LONGTEXT NOT NULL,
        keywords TEXT NOT NULL DEFAULT '',
        indexed_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY post_id (post_id),
        KEY post_type (post_type)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
