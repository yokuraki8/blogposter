<?php
/**
 * 生成履歴画面テンプレート
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'blog_poster_history';

// 履歴データを取得
$history = $wpdb->get_results(
    "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 50"
);
?>

<div class="wrap blog-poster-history">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <div class="blog-poster-history-container">
        <?php if ( empty( $history ) ): ?>
            <div class="blog-poster-empty-state">
                <span class="dashicons dashicons-list-view"></span>
                <h2><?php _e( 'まだ記事が生成されていません', 'blog-poster' ); ?></h2>
                <p><?php _e( '記事生成を開始すると、ここに履歴が表示されます。', 'blog-poster' ); ?></p>
                <a href="<?php echo admin_url( 'admin.php?page=blog-poster-generate' ); ?>" class="button button-primary">
                    <?php _e( '記事を生成', 'blog-poster' ); ?>
                </a>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e( 'ID', 'blog-poster' ); ?></th>
                        <th><?php _e( '記事タイトル', 'blog-poster' ); ?></th>
                        <th><?php _e( 'AIプロバイダー', 'blog-poster' ); ?></th>
                        <th><?php _e( 'モデル', 'blog-poster' ); ?></th>
                        <th><?php _e( 'トークン数', 'blog-poster' ); ?></th>
                        <th><?php _e( 'ステータス', 'blog-poster' ); ?></th>
                        <th><?php _e( '生成日時', 'blog-poster' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history as $item ): ?>
                        <tr>
                            <td><?php echo esc_html( $item->id ); ?></td>
                            <td>
                                <?php if ( $item->post_id ): ?>
                                    <a href="<?php echo get_edit_post_link( $item->post_id ); ?>">
                                        <?php echo esc_html( get_the_title( $item->post_id ) ); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( ucfirst( $item->ai_provider ) ); ?></td>
                            <td><?php echo esc_html( $item->ai_model ); ?></td>
                            <td><?php echo esc_html( number_format( $item->tokens_used ) ); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr( $item->status ); ?>">
                                    <?php echo esc_html( ucfirst( $item->status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $item->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.blog-poster-history-container {
    margin: 20px 0;
}

.blog-poster-empty-state {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 60px 24px;
    text-align: center;
}

.blog-poster-empty-state .dashicons {
    font-size: 80px;
    width: 80px;
    height: 80px;
    color: #c3c4c7;
}

.blog-poster-empty-state h2 {
    margin: 20px 0 10px;
    color: #646970;
}

.blog-poster-empty-state p {
    margin-bottom: 20px;
    color: #646970;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.status-draft {
    background: #f0f0f1;
    color: #50575e;
}

.status-published {
    background: #00a32a;
    color: #fff;
}

.status-failed {
    background: #d63638;
    color: #fff;
}
</style>
