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
    });

})(jQuery);
