/**
 * SEO panel JS for Blog Poster.
 */
(function($) {
  'use strict';

  function renderSections($panel, analysis) {
    if (!analysis) return;
    const structure = analysis.structure || {};
    const seo = analysis.seo || {};
    const engagement = analysis.engagement || {};
    const trust = analysis.trust || {};

    $panel.find('.section').eq(0).find('.content').text(
      `H2:${structure.heading_hierarchy?.h2_count || 0} / リード:${structure.lead_paragraph?.present ? '有' : '無'} / 結論:${structure.conclusion?.present ? '有' : '無'}`
    );
    $panel.find('.section').eq(1).find('.content').text(
      `タイトル:${seo.title?.length || 0}字 / ディスクリプション:${seo.meta_description?.length || 0}字 / 内部リンク:${seo.internal_links?.count || 0}`
    );
    $panel.find('.section').eq(2).find('.content').text(
      `フック:${engagement.hook?.present ? '有' : '無'} / CTA:${engagement.cta?.count || 0}`
    );
    $panel.find('.section').eq(3).find('.content').text(
      `出典:${trust.citations?.count || 0} / 数値:${trust.numeric_data?.count || 0}`
    );
  }

  function renderTasks($panel, tasks) {
    const $list = $panel.find('.tasks-list').empty();
    if (!tasks || !tasks.tasks || tasks.tasks.length === 0) {
      $list.append('<li>タスクはありません</li>');
      return;
    }
    tasks.tasks.forEach(t => {
      const status = t.status === 'completed' ? '✅' : '⬜';
      $list.append(`<li data-task-id="${t.id}">${status} ${t.title}</li>`);
    });
  }

  $(document).on('click', '.blog-poster-analyze', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    const $status = $panel.find('.blog-poster-seo-status').text('分析中...');
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_analyze_seo',
      nonce: blogPosterSeo.nonce,
      post_id: postId
    }).done(function(res) {
      if (res.success) {
        $status.text('分析完了');
        renderSections($panel, res.data);
      } else {
        $status.text('分析失敗');
      }
    }).fail(function() {
      $status.text('分析失敗');
    });
  });

  $(document).on('click', '.blog-poster-generate-tasks', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_generate_tasks',
      nonce: blogPosterSeo.nonce,
      post_id: postId
    }).done(function(res) {
      if (res.success) {
        renderTasks($panel, res.data);
      }
    });
  });
})(jQuery);

