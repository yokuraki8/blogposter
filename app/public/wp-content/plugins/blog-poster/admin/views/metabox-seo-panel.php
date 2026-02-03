<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$analysis = get_post_meta( $post->ID, '_blog_poster_seo_analysis', true );
$tasks = get_post_meta( $post->ID, '_blog_poster_seo_tasks', true );
?>
<div class="blog-poster-seo-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
    <div class="blog-poster-seo-header">
        <button type="button" class="button button-primary blog-poster-analyze"><?php esc_html_e( '再分析', 'blog-poster' ); ?></button>
        <span class="blog-poster-seo-status"></span>
    </div>

    <div class="blog-poster-seo-summary">
        <?php if ( ! empty( $analysis ) ) : ?>
            <div class="score">
                <strong><?php esc_html_e( '総合スコア', 'blog-poster' ); ?>:</strong>
                <?php echo esc_html( $analysis['overall']['composite_score'] ); ?>/100
                (<?php echo esc_html( $analysis['overall']['grade'] ); ?>)
            </div>
        <?php else : ?>
            <div class="score"><?php esc_html_e( '未分析', 'blog-poster' ); ?></div>
        <?php endif; ?>
    </div>

    <div class="blog-poster-seo-sections">
        <div class="section">
            <h4><?php esc_html_e( '構造', 'blog-poster' ); ?></h4>
            <div class="content"></div>
        </div>
        <div class="section">
            <h4><?php esc_html_e( 'SEO', 'blog-poster' ); ?></h4>
            <div class="content"></div>
        </div>
        <div class="section">
            <h4><?php esc_html_e( 'エンゲージメント', 'blog-poster' ); ?></h4>
            <div class="content"></div>
        </div>
        <div class="section">
            <h4><?php esc_html_e( '信頼性', 'blog-poster' ); ?></h4>
            <div class="content"></div>
        </div>
    </div>

    <div class="blog-poster-seo-tasks">
        <div class="tasks-header">
            <strong><?php esc_html_e( '改善タスク', 'blog-poster' ); ?></strong>
            <button type="button" class="button blog-poster-generate-tasks"><?php esc_html_e( 'タスク生成', 'blog-poster' ); ?></button>
        </div>
        <ul class="tasks-list"></ul>
    </div>
</div>
