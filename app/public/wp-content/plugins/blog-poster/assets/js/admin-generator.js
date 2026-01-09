/**
 * Blog Poster - 記事生成管理画面JavaScript (v0.2.5-alpha)
 * 非同期ジョブ方式による3ステップ記事生成
 *
 * @package BlogPoster
 * @since 0.2.5-alpha
 */

jQuery(document).ready(function($) {

    const steps = ['outline', 'content', 'review'];
    const stepLabels = {
        'outline': '構成案を作成中...',
        'content': '本文を生成中...',
        'review': '最終チェック中...'
    };

    let currentJobId = null;

    // 記事生成フォーム送信
    $('#blog-poster-generate-form').on('submit', function(e) {
        e.preventDefault();

        const topic = $('#topic').val().trim();
        const additionalInstructions = $('#additional-instructions').val().trim();

        if (!topic) {
            alert('トピックを入力してください');
            return;
        }

        // UI更新
        $('#generate-button').prop('disabled', true).text('生成中...');
        $('#progress-container').show();
        $('#result-container').hide();
        $('#error-message').hide();
        updateProgress(0, '準備中...');

        // ジョブ作成
        $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_create_job',
                nonce: blogPosterAjax.nonce,
                topic: topic,
                additional_instructions: additionalInstructions
            },
            success: function(response) {
                if (response.success) {
                    currentJobId = response.data.job_id;
                    console.log('Job created:', currentJobId);
                    processNextStep(0);
                } else {
                    showError(response.data.message);
                }
            },
            error: function(xhr, status, error) {
                showError('通信エラーが発生しました: ' + error);
            }
        });
    });

    /**
     * 次のステップを処理
     */
    function processNextStep(stepIndex) {
        if (stepIndex >= steps.length) {
            // 全ステップ完了
            updateProgress(100, '完了！');
            $('#generate-button').prop('disabled', false).text('記事を生成');
            return;
        }

        const step = steps[stepIndex];
        const progress = Math.floor(((stepIndex) / steps.length) * 100);
        updateProgress(progress, stepLabels[step]);

        $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_process_step',
                nonce: blogPosterAjax.nonce,
                job_id: currentJobId,
                step: step
            },
            timeout: 120000, // 2分のタイムアウト
            success: function(response) {
                if (response.success) {
                    const nextProgress = Math.floor(((stepIndex + 1) / steps.length) * 100);
                    updateProgress(nextProgress, stepLabels[step] + ' 完了');

                    // 最終ステップなら結果を表示
                    if (step === 'review') {
                        displayResult(response.data);
                    }

                    // 次のステップへ（少し待ってから）
                    setTimeout(function() {
                        processNextStep(stepIndex + 1);
                    }, 500);
                } else {
                    showError(response.data.message || 'ステップ処理に失敗しました');
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showError('処理がタイムアウトしました。もう一度お試しください。');
                } else {
                    showError('通信エラー: ' + error);
                }
            }
        });
    }

    /**
     * プログレスバー更新
     */
    function updateProgress(percent, message) {
        $('#progress-bar').css('width', percent + '%').attr('aria-valuenow', percent);
        $('#progress-bar').text(Math.floor(percent) + '%');
        $('#progress-message').text(message);
    }

    /**
     * エラー表示
     */
    function showError(message) {
        $('#progress-container').hide();
        $('#error-message').html('<strong>エラー:</strong> ' + message).show();
        $('#generate-button').prop('disabled', false).text('記事を生成');
    }

    /**
     * 結果表示
     */
    function displayResult(data) {
        $('#result-container').show();
        $('#result-title').text(data.title);
        $('#result-slug').text(data.slug);
        $('#result-excerpt').text(data.excerpt);

        // Markdownをプレビュー表示（簡易版）
        const contentHtml = markdownToHtml(data.content);
        $('#result-content').html(contentHtml);

        // 投稿作成ボタンを有効化
        $('#create-post-button')
            .prop('disabled', false)
            .data('job-id', currentJobId)
            .data('title', data.title)
            .data('slug', data.slug)
            .data('excerpt', data.excerpt)
            .data('content', data.content)
            .data('meta-description', data.meta_description);

        // 検証結果表示
        if (data.validation && !data.validation.valid) {
            $('#validation-issues').html('<div class="notice notice-warning"><p><strong>検証警告:</strong><br>' + data.validation.issues.replace(/\n/g, '<br>') + '</p></div>').show();
        } else {
            $('#validation-issues').hide();
        }
    }

    /**
     * 簡易Markdown→HTML変換
     */
    function markdownToHtml(markdown) {
        let html = markdown;

        // コードブロック
        html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, function(match, lang, code) {
            return '<pre><code class="language-' + (lang || 'text') + '">' + escapeHtml(code.trim()) + '</code></pre>';
        });

        // 見出し
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // 太字・イタリック
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

        // リンク
        html = html.replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank">$1</a>');

        // 箇条書き
        html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');

        // 段落
        html = html.replace(/\n\n/g, '</p><p>');
        html = '<p>' + html + '</p>';

        return html;
    }

    /**
     * HTMLエスケープ
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /**
     * 投稿作成ボタン
     */
    $('#create-post-button').on('click', function() {
        if (!confirm('この内容で投稿を作成しますか？')) {
            return;
        }

        const button = $(this);
        const originalText = button.text();
        button.prop('disabled', true).text('作成中...');

        $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_create_post',
                nonce: blogPosterAjax.nonce,
                job_id: button.data('job-id'),
                title: button.data('title'),
                slug: button.data('slug'),
                excerpt: button.data('excerpt'),
                content: button.data('content'),
                meta_description: button.data('meta-description')
            },
            success: function(response) {
                if (response.success) {
                    alert('投稿を作成しました！編集画面に移動します。');
                    window.location.href = response.data.edit_url;
                } else {
                    alert('投稿作成に失敗しました: ' + response.data.message);
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('通信エラーが発生しました');
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});
