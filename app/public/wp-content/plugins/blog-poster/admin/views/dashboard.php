<?php
/**
 * ダッシュボード画面テンプレート
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( 'blog_poster_settings', array() );
$articles_generated = isset( $settings['articles_generated'] ) ? $settings['articles_generated'] : 0;
$articles_limit = isset( $settings['articles_limit_free'] ) ? $settings['articles_limit_free'] : 5;
$subscription_plan = isset( $settings['subscription_plan'] ) ? $settings['subscription_plan'] : 'free';
?>

<div class="wrap blog-poster-dashboard">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <div class="blog-poster-welcome-panel">
        <h2><?php _e( 'Blog Posterへようこそ', 'blog-poster' ); ?></h2>
        <p><?php _e( 'AI駆動型ブログ記事自動生成プラグイン - 高品質な日本語記事を簡単に作成', 'blog-poster' ); ?></p>
    </div>

    <div class="blog-poster-stats-grid">
        <!-- 使用状況 -->
        <div class="blog-poster-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="stat-content">
                <h3><?php _e( '記事生成数', 'blog-poster' ); ?></h3>
                <p class="stat-number"><?php echo esc_html( $articles_generated ); ?> / <?php echo esc_html( $articles_limit ); ?></p>
                <?php if ( $subscription_plan === 'free' ): ?>
                    <p class="stat-description">
                        <?php printf( __( '無料プランで残り %d 記事生成可能', 'blog-poster' ), max( 0, $articles_limit - $articles_generated ) ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 現在のプラン -->
        <div class="blog-poster-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-star-filled"></span>
            </div>
            <div class="stat-content">
                <h3><?php _e( '現在のプラン', 'blog-poster' ); ?></h3>
                <p class="stat-number">
                    <?php
                    switch ( $subscription_plan ) {
                        case 'free':
                            _e( '無料プラン', 'blog-poster' );
                            break;
                        case 'paid_with_api':
                            _e( '有料プラン（自前API）', 'blog-poster' );
                            break;
                        case 'paid_without_api':
                            _e( '有料プラン', 'blog-poster' );
                            break;
                        default:
                            _e( '無料プラン', 'blog-poster' );
                    }
                    ?>
                </p>
                <?php if ( $subscription_plan === 'free' ): ?>
                    <p class="stat-description">
                        <a href="#"><?php _e( '有料プランにアップグレード', 'blog-poster' ); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- AIプロバイダー -->
        <div class="blog-poster-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-admin-generic"></span>
            </div>
            <div class="stat-content">
                <h3><?php _e( 'AIプロバイダー', 'blog-poster' ); ?></h3>
                <p class="stat-number">
                    <?php
                    $ai_provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'openai';
                    switch ( $ai_provider ) {
                        case 'gemini':
                            echo 'Google Gemini';
                            break;
                        case 'claude':
                            echo 'Anthropic Claude';
                            break;
                        case 'openai':
                            echo 'OpenAI';
                            break;
                        default:
                            echo 'OpenAI';
                    }
                    ?>
                </p>
                <p class="stat-description">
                    <a href="<?php echo admin_url( 'admin.php?page=blog-poster-settings' ); ?>">
                        <?php _e( '設定を変更', 'blog-poster' ); ?>
                    </a>
                </p>
            </div>
        </div>

        <!-- バージョン情報 -->
        <div class="blog-poster-stat-card">
            <div class="stat-icon">
                <span class="dashicons dashicons-info"></span>
            </div>
            <div class="stat-content">
                <h3><?php _e( 'バージョン', 'blog-poster' ); ?></h3>
                <p class="stat-number"><?php echo BLOG_POSTER_VERSION; ?></p>
                <p class="stat-description">
                    <?php _e( '開発中バージョン', 'blog-poster' ); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- クイックアクション -->
    <div class="blog-poster-quick-actions">
        <h2><?php _e( 'クイックアクション', 'blog-poster' ); ?></h2>
        <div class="action-buttons">
            <a href="<?php echo admin_url( 'admin.php?page=blog-poster-generate' ); ?>" class="button button-primary button-hero">
                <span class="dashicons dashicons-edit-large"></span>
                <?php _e( '新しい記事を生成', 'blog-poster' ); ?>
            </a>
            <a href="<?php echo admin_url( 'admin.php?page=blog-poster-settings' ); ?>" class="button button-hero">
                <span class="dashicons dashicons-admin-settings"></span>
                <?php _e( '設定', 'blog-poster' ); ?>
            </a>
            <a href="<?php echo admin_url( 'admin.php?page=blog-poster-history' ); ?>" class="button button-hero">
                <span class="dashicons dashicons-list-view"></span>
                <?php _e( '生成履歴', 'blog-poster' ); ?>
            </a>
        </div>
    </div>

    <!-- はじめに -->
    <div class="blog-poster-getting-started">
        <h2><?php _e( 'はじめに', 'blog-poster' ); ?></h2>
        <ol>
            <li>
                <strong><?php _e( 'APIキーを設定', 'blog-poster' ); ?></strong><br>
                <?php printf(
                    __( '<a href="%s">設定画面</a>でGoogle Gemini、Anthropic Claude、またはOpenAIのAPIキーを設定してください。', 'blog-poster' ),
                    admin_url( 'admin.php?page=blog-poster-settings' )
                ); ?>
            </li>
            <li>
                <strong><?php _e( 'トーン・スタイルを調整', 'blog-poster' ); ?></strong><br>
                <?php _e( 'フォーマル度、専門性、親しみやすさのスライダーを調整して、記事のトーンをカスタマイズできます。', 'blog-poster' ); ?>
            </li>
            <li>
                <strong><?php _e( '記事を生成', 'blog-poster' ); ?></strong><br>
                <?php printf(
                    __( '<a href="%s">記事生成</a>ページでキーワードやトピックを入力して、記事を自動生成します。', 'blog-poster' ),
                    admin_url( 'admin.php?page=blog-poster-generate' )
                ); ?>
            </li>
        </ol>
    </div>
</div>

<style>
.blog-poster-dashboard {
    max-width: 1200px;
}

.blog-poster-welcome-panel {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 24px;
    margin: 20px 0;
}

.blog-poster-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.blog-poster-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-icon {
    font-size: 40px;
    color: #2271b1;
}

.stat-icon .dashicons {
    width: 40px;
    height: 40px;
    font-size: 40px;
}

.stat-content h3 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #646970;
}

.stat-number {
    font-size: 28px;
    font-weight: 600;
    margin: 0 0 5px;
    color: #1d2327;
}

.stat-description {
    margin: 0;
    font-size: 13px;
    color: #646970;
}

.blog-poster-quick-actions,
.blog-poster-getting-started {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 24px;
    margin: 20px 0;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-buttons .button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.blog-poster-getting-started ol {
    margin-left: 20px;
}

.blog-poster-getting-started li {
    margin-bottom: 15px;
}
</style>
