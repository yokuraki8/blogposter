(function($) {
    'use strict';

    // RAG index status
    function loadRagStatus() {
        $.post(ajaxurl, {
            action: 'blog_poster_rag_status',
            nonce: blogPosterAdmin.nonce
        }, function(response) {
            if (response.success) {
                $('.rag-index-count').text(response.data.count);
                $('.rag-last-indexed').text(response.data.last_indexed || '未実行');
            }
        });
    }

    // RAG reindex button
    $('#rag-reindex-btn').on('click', function() {
        var $btn    = $(this);
        var $status = $('#rag-reindex-status');

        $btn.prop('disabled', true).text('更新中...');
        $status.show().text('インデックスを更新しています...');

        $.post(ajaxurl, {
            action: 'blog_poster_rag_reindex',
            nonce: blogPosterAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('今すぐインデックス更新');
            if (response.success) {
                $status.text(response.data.message);
                loadRagStatus();
            } else {
                $status.text('エラーが発生しました: ' + (response.data.message || ''));
            }
            setTimeout(function() { $status.hide(); }, 5000);
        });
    });

    // Load status on page load if RAG section exists
    if ($('#rag-index-status').length) {
        loadRagStatus();
    }

})(jQuery);
