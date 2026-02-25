/**
 * Blog Poster管理画面JavaScript
 *
 * @package BlogPoster
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // スライダーの値更新
        $('.blog-poster-slider').on('input', function() {
            const id = $(this).attr('id');
            const value = $(this).val();
            $('#' + id + '-value').text(value);
        });

        // APIプロバイダー切り替え
        $('#ai_provider').on('change', function() {
            const provider = $(this).val();
            $('.api-section').hide();
            $('#' + provider + '-section').show();
        });

        // 初期表示時のAPIセクション表示
        const initialProvider = $('#ai_provider').val();
        if (initialProvider) {
            $('#' + initialProvider + '-section').show();
        }

        // Claude APIキー検証ボタン
        $('#claude-key-check').on('click', function() {
            const $btn = $(this);
            const $status = $('#claude-key-check-status');
            $btn.prop('disabled', true).text('確認中...');
            $status.removeClass('success error').text('');

            $.ajax({
                url: blogPosterAdmin.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'blog_poster_check_api_key',
                    nonce: blogPosterAdmin.nonce,
                    provider: 'claude'
                }
            }).done(function(res) {
                if (res.success) {
                    $status.addClass('success').text(res.data.message);
                } else {
                    const msg = res.data && res.data.message ? res.data.message : '確認に失敗しました';
                    $status.addClass('error').text(msg);
                }
            }).fail(function(xhr, status) {
                $status.addClass('error').text('通信エラー: ' + status);
            }).always(function() {
                $btn.prop('disabled', false).text('APIキーを確認');
            });
        });

        // Gemini APIキー検証ボタン
        $('#gemini-key-check').on('click', function() {
            const $btn = $(this);
            const $status = $('#gemini-key-check-status');
            $btn.prop('disabled', true).text('確認中...');
            $status.removeClass('success error').text('');

            $.ajax({
                url: blogPosterAdmin.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'blog_poster_check_api_key',
                    nonce: blogPosterAdmin.nonce,
                    provider: 'gemini'
                }
            }).done(function(res) {
                if (res.success) {
                    $status.addClass('success').text(res.data.message);
                } else {
                    const msg = res.data && res.data.message ? res.data.message : '確認に失敗しました';
                    $status.addClass('error').text(msg);
                }
            }).fail(function(xhr, status) {
                $status.addClass('error').text('通信エラー: ' + status);
            }).always(function() {
                $btn.prop('disabled', false).text('APIキーを確認');
            });
        });

        // OpenAI APIキー検証ボタン
        $('#openai-key-check').on('click', function() {
            const $btn = $(this);
            const $status = $('#openai-key-check-status');
            $btn.prop('disabled', true).text('確認中...');
            $status.removeClass('success error').text('');

            $.ajax({
                url: blogPosterAdmin.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'blog_poster_check_api_key',
                    nonce: blogPosterAdmin.nonce,
                    provider: 'openai'
                }
            }).done(function(res) {
                if (res.success) {
                    $status.addClass('success').text(res.data.message);
                } else {
                    const msg = res.data && res.data.message ? res.data.message : '確認に失敗しました';
                    $status.addClass('error').text(msg);
                }
            }).fail(function(xhr, status) {
                $status.addClass('error').text('通信エラー: ' + status);
            }).always(function() {
                $btn.prop('disabled', false).text('APIキーを確認');
            });
        });
    });

})(jQuery);
