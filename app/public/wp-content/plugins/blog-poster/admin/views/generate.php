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
        const $previewSection = $('#preview-section');
        const $previewContent = $('#preview-content');

        // フォームデータ取得
        const topic = $('#topic').val().trim();
        const additionalInstructions = $('#additional_instructions').val().trim();

        if (!topic) {
            alert('<?php esc_js( _e( 'トピックを入力してください。', 'blog-poster' ) ); ?>');
            return;
        }

        // ボタンを無効化
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> <?php esc_js( _e( '生成中...', 'blog-poster' ) ); ?>');
        $previewSection.hide();

        // AJAX リクエスト
        $.ajax({
            url: blogPosterAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'blog_poster_generate_article',
                nonce: blogPosterAdmin.nonce,
                topic: topic,
                additional_instructions: additionalInstructions
            },
            timeout: 120000, // 2分タイムアウト
            success: function(response) {
                if (response.success) {
                    // 成功時の処理
                    const data = response.data;

                    // プレビュー表示
                    let preview = '<div class="article-preview">';
                    preview += '<h2 class="preview-title">' + data.article.title + '</h2>';
                    preview += '<div class="preview-meta">';
                    preview += '<p><strong><?php esc_js( _e( 'Slug:', 'blog-poster' ) ); ?></strong> ' + data.article.slug + '</p>';
                    preview += '<p><strong><?php esc_js( _e( 'メタディスクリプション:', 'blog-poster' ) ); ?></strong> ' + data.article.meta_description + '</p>';
                    preview += '<p><strong><?php esc_js( _e( '使用トークン数:', 'blog-poster' ) ); ?></strong> ' + data.tokens.toLocaleString() + '</p>';
                    preview += '<p><strong><?php esc_js( _e( '残り記事数:', 'blog-poster' ) ); ?></strong> ' + data.remaining + '</p>';
                    preview += '</div>';
                    preview += '<div class="preview-content">' + data.article.content + '</div>';
                    preview += '<div class="preview-actions">';
                    preview += '<a href="' + data.post_url + '" class="button button-primary" target="_blank"><?php esc_js( _e( '投稿を編集', 'blog-poster' ) ); ?></a>';
                    preview += '</div>';
                    preview += '</div>';

                    $previewContent.html(preview);
                    $previewSection.fadeIn();

                    // 成功メッセージ
                    alert(data.message + '\n\n<?php esc_js( _e( '投稿を編集画面で確認できます。', 'blog-poster' ) ); ?>');

                    // フォームをリセット
                    $('#topic').val('');
                    $('#additional_instructions').val('');

                } else {
                    // エラー時の処理
                    alert('<?php esc_js( _e( 'エラー:', 'blog-poster' ) ); ?> ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);

                let errorMessage = '<?php esc_js( _e( '記事生成中にエラーが発生しました。', 'blog-poster' ) ); ?>';

                if (status === 'timeout') {
                    errorMessage = '<?php esc_js( _e( 'タイムアウトしました。もう一度お試しください。', 'blog-poster' ) ); ?>';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }

                alert(errorMessage);
            },
            complete: function() {
                // ボタンを有効化
                $button.prop('disabled', false).html(originalText);
            }
        });
    });
});
</script>

<style>
.dashicons.spin {
    animation: rotation 2s infinite linear;
}

@keyframes rotation {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(359deg);
    }
}

.article-preview {
    padding: 20px;
}

.preview-title {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #2271b1;
}

.preview-meta {
    background: #f6f7f7;
    padding: 15px;
    margin: 15px 0;
    border-left: 4px solid #2271b1;
}

.preview-meta p {
    margin: 5px 0;
}

.preview-content {
    line-height: 1.8;
    margin: 20px 0;
}

.preview-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #dcdcde;
}
</style>
