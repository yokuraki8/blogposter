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
        <div class="score-bar" aria-hidden="true">
            <div class="score-bar-fill" style="width:<?php echo ! empty( $analysis['overall']['composite_score'] ) ? esc_attr( $analysis['overall']['composite_score'] ) : 0; ?>%"></div>
        </div>
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

    <div class="blog-poster-seo-recommendations">
        <div class="recommendations-header">
            <strong><?php esc_html_e( '改善提案', 'blog-poster' ); ?></strong>
        </div>
        <ul class="recommendations-list"></ul>
    </div>

    <div class="blog-poster-seo-tasks">
        <div class="tasks-header">
            <strong><?php esc_html_e( '改善タスク', 'blog-poster' ); ?></strong>
            <div class="tasks-actions">
                <select class="blog-poster-task-filter">
                    <option value="all"><?php esc_html_e( 'すべて', 'blog-poster' ); ?></option>
                    <option value="1"><?php esc_html_e( 'Critical', 'blog-poster' ); ?></option>
                    <option value="2"><?php esc_html_e( 'High', 'blog-poster' ); ?></option>
                    <option value="3"><?php esc_html_e( 'Medium', 'blog-poster' ); ?></option>
                    <option value="4"><?php esc_html_e( 'Low', 'blog-poster' ); ?></option>
                </select>
                <button type="button" class="button blog-poster-generate-tasks"><?php esc_html_e( 'タスク生成', 'blog-poster' ); ?></button>
            </div>
        </div>
        <ul class="tasks-list"></ul>
    </div>

    <div class="blog-poster-rewrite-modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <strong><?php esc_html_e( 'リライトプレビュー', 'blog-poster' ); ?></strong>
                <button type="button" class="button-link blog-poster-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="preview-text"></div>
                <div class="diff-text" style="display:none;"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="button button-primary blog-poster-apply-rewrite"><?php esc_html_e( '適用', 'blog-poster' ); ?></button>
                <button type="button" class="button blog-poster-modal-close"><?php esc_html_e( 'キャンセル', 'blog-poster' ); ?></button>
            </div>
        </div>
    </div>
</div>
