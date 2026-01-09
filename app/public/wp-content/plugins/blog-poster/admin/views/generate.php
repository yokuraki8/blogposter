<?php
/**
 * 記事生成画面テンプレート
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( 'blog_poster_settings', array() );
?>

<div class="wrap blog-poster-generate">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <div class="blog-poster-generate-container">
        <div class="generate-form-section">
            <h2><?php _e( '記事生成設定', 'blog-poster' ); ?></h2>
            <p class="description">
                <?php _e( 'キーワードやトピックを入力して、AIが自動的にブログ記事を生成します。', 'blog-poster' ); ?>
            </p>

            <form id="blog-poster-generate-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="topic"><?php _e( 'トピック/キーワード', 'blog-poster' ); ?> *</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="topic"
                                name="topic"
                                class="regular-text"
                                placeholder="<?php esc_attr_e( '例: WordPressプラグイン開発の基礎', 'blog-poster' ); ?>"
                                required
                            />
                            <p class="description">
                                <?php _e( '記事のメイントピックまたはキーワードを入力してください。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="additional_instructions"><?php _e( '追加指示（任意）', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="additional_instructions"
                                name="additional_instructions"
                                rows="5"
                                class="large-text"
                                placeholder="<?php esc_attr_e( '記事に含めたい内容や特別な要求があれば入力してください...', 'blog-poster' ); ?>"
                            ></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-hero" id="generate-button">
                        <span class="dashicons dashicons-edit-large"></span>
                        <?php _e( '記事を生成', 'blog-poster' ); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- プログレスバー表示 (v0.2.5-alpha) -->
        <div class="generate-progress-section" id="progress-container" style="display: none;">
            <h2><?php _e( '記事生成中', 'blog-poster' ); ?></h2>
            <div class="progress-wrapper">
                <div class="progress-bar-container">
                    <div class="progress-bar" id="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                        0%
                    </div>
                </div>
                <p class="progress-message" id="progress-message">準備中...</p>
            </div>
        </div>

        <!-- エラー表示 -->
        <div class="generate-error-section" id="error-message" style="display: none;"></div>

        <!-- 結果表示 -->
        <div class="generate-result-section" id="result-container" style="display: none;">
            <h2><?php _e( '生成結果', 'blog-poster' ); ?></h2>
            <div class="result-meta">
                <p><strong><?php _e( 'タイトル:', 'blog-poster' ); ?></strong> <span id="result-title"></span></p>
                <p><strong><?php _e( 'Slug:', 'blog-poster' ); ?></strong> <span id="result-slug"></span></p>
                <p><strong><?php _e( '抜粋:', 'blog-poster' ); ?></strong> <span id="result-excerpt"></span></p>
            </div>
            <div id="validation-issues" style="display: none;"></div>
            <div class="result-content" id="result-content"></div>
            <p class="submit">
                <button type="button" class="button button-primary button-hero" id="create-post-button" disabled>
                    <span class="dashicons dashicons-admin-post"></span>
                    <?php _e( '投稿を作成', 'blog-poster' ); ?>
                </button>
            </p>
        </div>

        <div class="generate-preview-section" id="preview-section" style="display: none;">
            <h2><?php _e( '生成結果プレビュー', 'blog-poster' ); ?></h2>
            <div id="preview-content"></div>
        </div>
    </div>
</div>

<style>
/* v0.2.5-alpha: 非同期ジョブ方式のスタイル */
.blog-poster-generate-container {
    max-width: 900px;
}

.generate-form-section,
.generate-preview-section,
.generate-progress-section,
.generate-error-section,
.generate-result-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 24px;
    margin: 20px 0;
}

.generate-error-section {
    background: #fef8f8;
    border-color: #cc1818;
    color: #cc1818;
    padding: 15px;
}

#generate-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

/* プログレスバー */
.progress-wrapper {
    margin: 20px 0;
}

.progress-bar-container {
    width: 100%;
    height: 40px;
    background-color: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0,0,0,.1);
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2271b1 0%, #135e96 100%);
    transition: width 0.5s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
    font-size: 14px;
}

.progress-message {
    text-align: center;
    margin-top: 15px;
    font-size: 15px;
    color: #50575e;
    font-weight: 500;
}

/* 結果表示 */
.result-meta {
    background: #f6f7f7;
    padding: 15px;
    margin: 15px 0;
    border-left: 4px solid #2271b1;
}

.result-meta p {
    margin: 5px 0;
}

.result-content {
    line-height: 1.8;
    margin: 20px 0;
    padding: 20px;
    background: #f9f9f9;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    max-height: 600px;
    overflow-y: auto;
}

.result-content h2 {
    margin-top: 1.5em;
    padding-top: 0.5em;
    border-top: 2px solid #e0e0e0;
}

.result-content h3 {
    margin-top: 1.2em;
}

.result-content pre {
    background: #282c34;
    color: #abb2bf;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
}

.result-content code {
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', Courier, monospace;
}

.result-content pre code {
    background: transparent;
    padding: 0;
}

#preview-content {
    padding: 20px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}
</style>
