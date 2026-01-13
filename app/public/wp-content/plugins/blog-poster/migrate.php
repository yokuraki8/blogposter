<?php
/**
 * Database Migration Script for Blog Poster v0.3.0-alpha
 *
 * Access via: http://blog-poster-plugin.local/wp-content/plugins/blog-poster/migrate.php
 *
 * This script migrates wp_blog_poster_jobs table from v0.2.x to v0.3.0-alpha
 */

// WordPress環境を読み込み
// プラグインディレクトリから3階層上がってwp-load.phpにアクセス
// blog-poster -> plugins -> wp-content -> public -> wp-load.php
$wp_load_path = dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
if (!file_exists($wp_load_path)) {
    die('Error: wp-load.php not found at: ' . $wp_load_path . '<br>__DIR__ = ' . __DIR__);
}
require_once($wp_load_path);

if (!current_user_can('manage_options')) {
    wp_die('Permission denied. Please login as administrator.');
}

global $wpdb;
$table_name = $wpdb->prefix . 'blog_poster_jobs';

// テーブルの存在確認
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

if (!$table_exists) {
    echo '<h1>Error: Table does not exist</h1>';
    echo '<p>Table ' . esc_html($table_name) . ' not found. Please activate the plugin first.</p>';
    exit;
}

// 現在のテーブル構造を取得
$columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
$column_names = array_column($columns, 'Field');

// 新しいカラムがないか、古いカラムが残っているかチェック
$needs_migration = !in_array('outline_md', $column_names) ||
                   in_array('section_index', $column_names) ||
                   in_array('outline', $column_names) ||
                   in_array('sections_content', $column_names) ||
                   in_array('final_content', $column_names);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Blog Poster Migration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 40px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 20px 0; border-radius: 4px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .button { background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .button:hover { background: #005a87; }
        pre { background: #f4f4f4; padding: 15px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Blog Poster v0.3.0-alpha Database Migration</h1>

    <?php if (isset($_GET['run']) && $_GET['run'] === '1' && $needs_migration): ?>
        <?php
        // マイグレーション実行
        $errors = array();
        $success_messages = array();

        // 1. バックアップテーブルを作成
        $backup_table = $table_name . '_backup_' . date('Ymd_His');
        if ($wpdb->query("CREATE TABLE $backup_table LIKE $table_name")) {
            $wpdb->query("INSERT INTO $backup_table SELECT * FROM $table_name");
            $success_messages[] = "Backup created: $backup_table";
        } else {
            $errors[] = "Failed to create backup table";
        }

        // 2. 新しいカラムを追加
        $columns_to_add = array(
            'outline_md' => "ALTER TABLE $table_name ADD COLUMN outline_md longtext AFTER total_steps",
            'content_md' => "ALTER TABLE $table_name ADD COLUMN content_md longtext AFTER outline_md",
            'final_markdown' => "ALTER TABLE $table_name ADD COLUMN final_markdown longtext AFTER content_md",
            'final_html' => "ALTER TABLE $table_name ADD COLUMN final_html longtext AFTER final_markdown",
        );

        foreach ($columns_to_add as $column => $sql) {
            $check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (empty($check)) {
                if ($wpdb->query($sql)) {
                    $success_messages[] = "Added column: $column";
                } else {
                    $errors[] = "Failed to add column: $column - " . $wpdb->last_error;
                }
            } else {
                $success_messages[] = "Column already exists: $column";
            }
        }

        // 3. 古いカラムを削除
        $columns_to_drop = array('section_index', 'sections_total', 'subsection_index', 'subsections_total', 'previous_summary', 'outline', 'sections_content', 'final_content');

        foreach ($columns_to_drop as $column) {
            $check = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            if (!empty($check)) {
                if ($wpdb->query("ALTER TABLE $table_name DROP COLUMN $column")) {
                    $success_messages[] = "Dropped column: $column";
                } else {
                    $errors[] = "Failed to drop column: $column - " . $wpdb->last_error;
                }
            } else {
                $success_messages[] = "Column already removed: $column";
            }
        }

        // 結果表示
        if (empty($errors)) {
            echo '<div class="success">';
            echo '<h2>✓ Migration Completed Successfully!</h2>';
            foreach ($success_messages as $msg) {
                echo '<p>• ' . esc_html($msg) . '</p>';
            }
            echo '</div>';
        } else {
            echo '<div class="error">';
            echo '<h2>Migration Failed</h2>';
            foreach ($errors as $error) {
                echo '<p>• ' . esc_html($error) . '</p>';
            }
            echo '</div>';
        }

        // 更新後のテーブル構造を再取得
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $column_names = array_column($columns, 'Field');
        $needs_migration = false;
        ?>

        <p><a href="?">← Back to Status</a></p>

    <?php endif; ?>

    <h2>Current Status</h2>

    <?php if ($needs_migration): ?>
        <div class="warning">
            <h3>⚠ Migration Required</h3>
            <p>Your database table needs to be updated to v0.3.0-alpha schema.</p>
            <p><strong>Changes:</strong></p>
            <ul>
                <li>Add columns: outline_md, content_md, final_markdown, final_html</li>
                <li>Remove columns: section_index, sections_total, subsection_index, subsections_total, previous_summary</li>
            </ul>
            <p><a href="?run=1" class="button" onclick="return confirm('Are you sure? This will backup and modify your database.');">Run Migration</a></p>
        </div>
    <?php else: ?>
        <div class="success">
            <h3>✓ Database Up to Date</h3>
            <p>Your table is already using the v0.3.0-alpha schema.</p>
        </div>
    <?php endif; ?>

    <h2>Current Table Structure</h2>
    <p>Table: <code><?php echo esc_html($table_name); ?></code></p>

    <table>
        <thead>
            <tr>
                <th>Column</th>
                <th>Type</th>
                <th>Null</th>
                <th>Key</th>
                <th>Default</th>
                <th>Extra</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($columns as $column): ?>
                <tr>
                    <td><strong><?php echo esc_html($column->Field); ?></strong></td>
                    <td><?php echo esc_html($column->Type); ?></td>
                    <td><?php echo esc_html($column->Null); ?></td>
                    <td><?php echo esc_html($column->Key); ?></td>
                    <td><?php echo esc_html($column->Default); ?></td>
                    <td><?php echo esc_html($column->Extra); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Expected v0.3.0-alpha Schema</h2>
    <pre>id, topic, additional_instructions, status, current_step, total_steps,
outline_md, content_md, final_markdown, final_html,
error_message, created_at, updated_at</pre>

</body>
</html>
