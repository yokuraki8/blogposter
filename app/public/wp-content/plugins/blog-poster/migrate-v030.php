<?php
/**
 * Database Migration Script for v0.3.0-alpha
 *
 * Purpose: Migrate wp_blog_poster_jobs table from v0.2.x to v0.3.0-alpha
 * Run this once after upgrading to v0.3.0-alpha
 *
 * Changes:
 * - Drop old columns: section_index, sections_total, subsection_index, subsections_total, previous_summary
 * - Add new columns: outline_md, content_md, final_markdown, final_html
 */

// WordPress環境を読み込み
require_once(__DIR__ . '/../../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Permission denied');
}

global $wpdb;
$table_name = $wpdb->prefix . 'blog_poster_jobs';

echo "Starting migration for table: $table_name\n\n";

// 既存テーブルをバックアップ
$backup_table = $table_name . '_backup_' . date('Ymd_His');
$wpdb->query("CREATE TABLE $backup_table LIKE $table_name");
$wpdb->query("INSERT INTO $backup_table SELECT * FROM $table_name");
echo "✓ Backup created: $backup_table\n";

// 新しいカラムを追加（存在しない場合）
$columns_to_add = array(
    'outline_md' => "ALTER TABLE $table_name ADD COLUMN outline_md longtext AFTER total_steps",
    'content_md' => "ALTER TABLE $table_name ADD COLUMN content_md longtext AFTER outline_md",
    'final_markdown' => "ALTER TABLE $table_name ADD COLUMN final_markdown longtext AFTER content_md",
    'final_html' => "ALTER TABLE $table_name ADD COLUMN final_html longtext AFTER final_markdown",
);

foreach ($columns_to_add as $column => $sql) {
    $check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
    if (empty($check)) {
        $wpdb->query($sql);
        echo "✓ Added column: $column\n";
    } else {
        echo "- Column already exists: $column\n";
    }
}

// 古いカラムを削除（存在する場合）
$columns_to_drop = array(
    'section_index',
    'sections_total',
    'subsection_index',
    'subsections_total',
    'previous_summary'
);

foreach ($columns_to_drop as $column) {
    $check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
    if (!empty($check)) {
        $wpdb->query("ALTER TABLE $table_name DROP COLUMN $column");
        echo "✓ Dropped column: $column\n";
    } else {
        echo "- Column already dropped: $column\n";
    }
}

echo "\n✓ Migration completed successfully!\n";
echo "Backup table: $backup_table\n";
