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
    let currentRequest = null;
    let isCancelled = false;
    let totalSectionsForProgress = 0;
    let lastProgressPercent = 0;
    let lastContentSection = 0;

    function computeProgressForStep(step, currentSection, totalSections) {
        if (!totalSections || totalSections <= 0) {
            return null;
        }
        const baseUnits = totalSections + 2;
        if (step === 'outline') {
            return Math.floor((1 / baseUnits) * 100);
        }
        if (step === 'content') {
            return Math.floor(((1 + currentSection) / baseUnits) * 100);
        }
        if (step === 'review') {
            return Math.floor(((totalSections + 1) / baseUnits) * 100);
        }
        return null;
    }

    // 記事生成フォーム送信
    $('#blog-poster-generate-form').on('submit', function(e) {
        e.preventDefault();

        console.log('Form submitted');
        console.log('blogPosterAjax:', blogPosterAjax);

        const topic = $('#topic').val().trim();
        const additionalInstructions = $('#additional_instructions').val().trim();
        const articleLength = $('#article_length').val();

        console.log('Topic:', topic);
        console.log('Additional instructions:', additionalInstructions);
        console.log('Article length:', articleLength);

        if (!topic) {
            alert('トピックを入力してください');
            return;
        }

        // UI更新
        $('#generate-button').prop('disabled', true).text('生成中...');
        $('#progress-container').show();
        $('#result-container').hide();
        $('#error-message').hide();
        $('#cancel-button').show().prop('disabled', false);
        isCancelled = false;
        lastProgressPercent = 0;
        lastContentSection = 0;
        updateProgress(0, '準備中...');

        // ジョブ作成
        currentRequest = $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_create_job',
                nonce: blogPosterAjax.nonce,
                topic: topic,
                additional_instructions: additionalInstructions,
                article_length: articleLength
            },
            success: function(response) {
                console.log('Create job response:', response);
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

    // キャンセル処理
    $('#cancel-button').on('click', function() {
        if (!currentJobId) {
            return;
        }
        isCancelled = true;
        $(this).prop('disabled', true);
        updateProgress(0, 'キャンセル処理中...');

        if (currentRequest && currentRequest.readyState !== 4) {
            currentRequest.abort();
        }

        $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_cancel_job',
                nonce: blogPosterAjax.nonce,
                job_id: currentJobId
            },
            success: function(response) {
                if (response.success) {
                    $('#progress-container').hide();
                    $('#error-message').html('<strong>キャンセル:</strong> 記事生成を中止しました。').show();
                } else {
                    showError(response.data && response.data.message ? response.data.message : 'キャンセルに失敗しました');
                }
                $('#generate-button').prop('disabled', false).text('記事を生成');
            },
            error: function() {
                showError('キャンセル中に通信エラーが発生しました。');
            }
        });
    });

    /**
     * 次のステップを処理
     */
    function processNextStep(stepIndex) {
        if (isCancelled) {
            return;
        }
        if (stepIndex >= steps.length) {
            // 全ステップ完了
            updateProgress(100, '完了！');
            $('#generate-button').prop('disabled', false).text('記事を生成');
            $('#cancel-button').hide();
            return;
        }

        const step = steps[stepIndex];
        let progress = computeProgressForStep(step, 0, totalSectionsForProgress);
        if (progress === null) {
            progress = Math.floor(((stepIndex) / steps.length) * 100);
        }
        if (step === 'content' && totalSectionsForProgress > 0) {
            const computed = computeProgressForStep('content', lastContentSection, totalSectionsForProgress);
            if (computed !== null) {
                progress = computed;
            }
        }
        updateProgress(progress, stepLabels[step]);

        console.log('Processing step:', step, 'Job ID:', currentJobId);

        currentRequest = $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_process_step',
                nonce: blogPosterAjax.nonce,
                job_id: currentJobId,
                step: step
            },
            timeout: 300000, // 5分のタイムアウト
            success: function(response) {
                console.log('Step ' + step + ' response:', response);
                if (response.success) {
                    if (step === 'outline' && response.data.total_sections) {
                        totalSectionsForProgress = response.data.total_sections;
                    }
                    if (step === 'content') {
                        const totalSections = response.data.total_sections || 0;
                        const currentSection = response.data.current_section || 0;
                        const totalSubsections = response.data.total_subsections || 0;
                        const currentSubsection = response.data.current_subsection || 0;
                        let sectionMessage = stepLabels[step];
                        if (totalSections > 0) {
                            totalSectionsForProgress = totalSections;
                            lastContentSection = currentSection;
                            sectionMessage = '本文を生成中... (' + currentSection + '/' + totalSections + ')';
                            if (totalSubsections > 0) {
                                sectionMessage += ' / H3 ' + currentSubsection + '/' + totalSubsections;
                            }
                            const computed = computeProgressForStep('content', currentSection, totalSections);
                            if (computed !== null) {
                                progress = computed;
                            }
                        }
                        if (!response.data.done) {
                            updateProgress(progress, sectionMessage);
                            setTimeout(function() {
                                processNextStep(stepIndex);
                            }, 300);
                            return;
                        }
                    }
                    let nextProgress = Math.floor(((stepIndex + 1) / steps.length) * 100);
                    if (totalSectionsForProgress > 0) {
                        if (step === 'outline') {
                            nextProgress = computeProgressForStep('outline', 0, totalSectionsForProgress);
                        } else if (step === 'content') {
                            nextProgress = computeProgressForStep('review', totalSectionsForProgress, totalSectionsForProgress);
                        }
                    }
                    updateProgress(nextProgress, stepLabels[step] + ' 完了');

                    // 最終ステップなら結果を表示
                    if (step === 'review') {
                        console.log('Review completed, displaying result with data:', response.data);
                        if (!response.data || !response.data.title || !response.data.markdown) {
                            updateProgress(progress, '完了処理中...（投稿作成前の再確認）');
                            setTimeout(function() {
                                processNextStep(stepIndex);
                            }, 1500);
                            return;
                        }
                        displayResult(response.data);
                    }

                    // 次のステップへ（少し待ってから）
                    setTimeout(function() {
                        processNextStep(stepIndex + 1);
                    }, 500);
                } else {
                    if (response.data && response.data.retry) {
                        updateProgress(progress, '処理継続中（他プロセス実行中）...');
                        setTimeout(function() {
                            processNextStep(stepIndex);
                        }, 5000);
                        return;
                    }
                    console.log('Step failed:', response.data.message);
                    showError(response.data.message || 'ステップ処理に失敗しました');
                }
            },
            error: function(xhr, status, error) {
                console.log('Process step error:', xhr, status, error);
                console.log('Response text:', xhr.responseText);
                if (status === 'abort') {
                    return;
                }
                if (status === 'timeout') {
                    // タイムアウト時はジョブ状態を確認して継続可能なら再実行
                    $.ajax({
                        url: blogPosterAjax.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'blog_poster_get_job_status',
                            nonce: blogPosterAjax.nonce,
                            job_id: currentJobId
                        },
                        success: function(statusResponse) {
                            if (statusResponse.success && statusResponse.data && statusResponse.data.status) {
                                const jobStatus = statusResponse.data.status;
                                if (['pending', 'outline', 'content', 'review'].includes(jobStatus)) {
                                    updateProgress(progress, '処理継続中（タイムアウト後に再試行）...');
                                    setTimeout(function() {
                                        processNextStep(stepIndex);
                                    }, 1500);
                                    return;
                                }
                            }
                            showError('処理がタイムアウトしました。もう一度お試しください。');
                        },
                        error: function() {
                            showError('処理がタイムアウトしました。もう一度お試しください。');
                        }
                    });
                } else {
                    showError('通信エラー: ' + error + '<br>詳細をコンソールで確認してください。');
                }
            }
        });
    }

    /**
     * プログレスバー更新
     */
    function updateProgress(percent, message) {
        const clamped = Math.min(100, Math.max(0, percent));
        const safePercent = Math.max(lastProgressPercent, clamped);
        lastProgressPercent = safePercent;
        $('#progress-bar').css('width', safePercent + '%').attr('aria-valuenow', safePercent);
        $('#progress-bar').text(Math.floor(safePercent) + '%');
        $('#progress-message').text(message);
    }

    /**
     * エラー表示
     */
    function showError(message) {
        $('#progress-container').hide();
        $('#error-message').html('<strong>エラー:</strong> ' + message).show();
        $('#generate-button').prop('disabled', false).text('記事を生成');
        $('#cancel-button').hide();
    }

    /**
     * 結果表示と自動投稿作成
     */
    function displayResult(data) {
        console.log('Displaying result and creating post automatically...');

        // プログレスメッセージを更新
        updateProgress(100, '投稿を作成中...');
        $('#cancel-button').hide();

        // 自動的に投稿を作成
        currentRequest = $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'blog_poster_create_post',
                nonce: blogPosterAjax.nonce,
                job_id: currentJobId,
                title: data.title,
                slug: data.slug,
                excerpt: data.excerpt,
                content: data.markdown,
                meta_description: data.meta_description
            },
            success: function(response) {
                console.log('Post creation response:', response);
                if (response.success) {
                    updateProgress(100, '完了！編集画面に移動します...');
                    // 1秒後に編集画面に移動
                    setTimeout(function() {
                        window.location.href = response.data.edit_url;
                    }, 1000);
                } else {
                    showError('投稿作成に失敗しました: ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.log('Post creation error:', xhr, status, error);
                console.log('Response text:', xhr.responseText);
                showError('投稿作成中に通信エラーが発生しました: ' + error);
            }
        });
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
     * 投稿作成ボタン（イベント委譲を使用）
     */
    $(document).on('click', '#create-post-button', function() {
        console.log('Create post button clicked');
        if (!confirm('この内容で投稿を作成しますか？')) {
            return;
        }

        const button = $(this);
        const originalText = button.text();
        button.prop('disabled', true).text('作成中...');

        const postData = {
            action: 'blog_poster_create_post',
            nonce: blogPosterAjax.nonce,
            job_id: button.data('job-id'),
            title: button.data('title'),
            slug: button.data('slug'),
            excerpt: button.data('excerpt'),
            content: button.data('content'),
            meta_description: button.data('meta-description')
        };

        console.log('Creating post with data:', postData);

        $.ajax({
            url: blogPosterAjax.ajaxurl,
            type: 'POST',
            data: postData,
            success: function(response) {
                console.log('Create post response:', response);
                if (response.success) {
                    alert('投稿を作成しました！編集画面に移動します。');
                    window.location.href = response.data.edit_url;
                } else {
                    alert('投稿作成に失敗しました: ' + response.data.message);
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.log('Create post error:', xhr, status, error);
                console.log('Response text:', xhr.responseText);
                alert('通信エラーが発生しました: ' + error);
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});
