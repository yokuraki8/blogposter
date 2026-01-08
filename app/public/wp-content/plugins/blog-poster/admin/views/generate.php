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

        <div class="generate-preview-section" id="preview-section" style="display: none;">
            <h2><?php _e( '生成結果プレビュー', 'blog-poster' ); ?></h2>
            <div id="preview-content"></div>
        </div>
    </div>
</div>

<style>
.blog-poster-generate-container {
    max-width: 900px;
}

.generate-form-section,
.generate-preview-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 24px;
    margin: 20px 0;
}

#generate-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

#preview-content {
    padding: 20px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#blog-poster-generate-form').on('submit', function(e) {
        e.preventDefault();

        const $button = $('#generate-button');
        const originalText = $button.html();

        $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> <?php esc_js( _e( '生成中...', 'blog-poster' ) ); ?>');

        // TODO: AJAX実装（Phase 1で実装予定）
        setTimeout(function() {
            $button.prop('disabled', false).html(originalText);
            alert('<?php esc_js( _e( '記事生成機能は開発中です。Phase 1で実装予定です。', 'blog-poster' ) ); ?>');
        }, 1000);
    });
});
</script>
