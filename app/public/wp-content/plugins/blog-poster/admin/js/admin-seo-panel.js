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
      const status = t.status === 'completed' ? 'completed' : 'pending';
      const priority = t.priority || 3;
      const checked = t.status === 'completed' ? 'checked' : '';
      const label = t.status === 'completed' ? '完了' : '未完';
      $list.append(
        `<li data-task-id="${t.id}" data-priority="${priority}" data-status="${status}">
          <label>
            <input type="checkbox" class="task-checkbox" ${checked} />
            <span class="task-title">[P${priority}] ${t.title}</span>
          </label>
          <button type="button" class="button-link blog-poster-preview-rewrite">提案を見る</button>
          <span class="task-status">${label}</span>
        </li>`
      );
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

  $(document).on('change', '.blog-poster-task-filter', function() {
    const value = $(this).val();
    const $panel = $(this).closest('.blog-poster-seo-panel');
    $panel.find('.tasks-list li').each(function() {
      const p = $(this).data('priority').toString();
      if (value === 'all' || value === p) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });

  $(document).on('click', '.blog-poster-batch-apply', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    const taskIds = [];
    $panel.find('.task-checkbox:checked').each(function() {
      taskIds.push($(this).closest('li').data('task-id'));
    });
    if (taskIds.length === 0) return;
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_batch_apply',
      nonce: blogPosterSeo.nonce,
      post_id: postId,
      task_ids: taskIds
    }).done(function(res) {
      if (res.success) {
        taskIds.forEach(id => {
          const $li = $panel.find(`li[data-task-id="${id}"]`);
          $li.attr('data-status', 'completed');
          $li.find('.task-status').text('完了');
        });
      }
    });
  });

  $(document).on('click', '.blog-poster-preview-rewrite', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    const taskId = $(this).closest('li').data('task-id');
    const $modal = $panel.find('.blog-poster-rewrite-modal');
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_preview_rewrite',
      nonce: blogPosterSeo.nonce,
      post_id: postId,
      task_id: taskId
    }).done(function(res) {
      if (res.success) {
        $modal.find('.preview-text').text(res.data && res.data.message ? res.data.message : 'プレビュー準備中');
        $modal.show();
      }
    });
  });

  $(document).on('click', '.blog-poster-modal-close', function() {
    $(this).closest('.blog-poster-rewrite-modal').hide();
  });
})(jQuery);
