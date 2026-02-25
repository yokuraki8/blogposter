/**
 * SEO panel JS for Blog Poster.
 */
(function($) {
  'use strict';

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function diffWords(a, b) {
    const aWords = a.split(/\s+/).filter(Boolean);
    const bWords = b.split(/\s+/).filter(Boolean);
    const n = aWords.length;
    const m = bWords.length;
    const dp = Array.from({ length: n + 1 }, () => Array(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--) {
      for (let j = m - 1; j >= 0; j--) {
        dp[i][j] = aWords[i] === bWords[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
      }
    }
    let i = 0;
    let j = 0;
    const out = [];
    while (i < n && j < m) {
      if (aWords[i] === bWords[j]) {
        out.push(escapeHtml(aWords[i]));
        i++;
        j++;
      } else if (dp[i + 1][j] >= dp[i][j + 1]) {
        out.push('<del>' + escapeHtml(aWords[i]) + '</del>');
        i++;
      } else {
        out.push('<ins>' + escapeHtml(bWords[j]) + '</ins>');
        j++;
      }
    }
    while (i < n) {
      out.push('<del>' + escapeHtml(aWords[i]) + '</del>');
      i++;
    }
    while (j < m) {
      out.push('<ins>' + escapeHtml(bWords[j]) + '</ins>');
      j++;
    }
    return out.join(' ');
  }

  function renderScore($panel, analysis) {
    const score = analysis && analysis.overall ? analysis.overall.composite_score : 0;
    const grade = analysis && analysis.overall ? analysis.overall.grade : '-';
    $panel.find('.blog-poster-seo-summary .score').html(
      '<strong>総合スコア:</strong> ' + (score || 0) + '/100 (' + grade + ')'
    );
    $panel.find('.score-bar-fill').css('width', (score || 0) + '%');
  }

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

  function renderRecommendations($panel, analysis) {
    const $list = $panel.find('.recommendations-list').empty();
    const recs = analysis && analysis.recommendations ? analysis.recommendations : [];
    if (!recs || recs.length === 0) {
      $list.append('<li>改善提案はありません</li>');
      return;
    }
    recs.forEach(r => {
      const p = r.priority || 3;
      const title = r.title || '改善提案';
      const desc = r.description || '';
      $list.append(
        `<li><span class="rec-priority">P${p}</span><strong>${escapeHtml(title)}</strong> - ${escapeHtml(desc)}</li>`
      );
    });
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
        `<li data-task-id="${t.id}" data-priority="${priority}" data-status="${status}" data-rec-type="${t.rec_type || ''}">
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
    $.ajax({
      url: blogPosterSeo.ajaxUrl,
      method: 'POST',
      timeout: 120000,
      data: {
        action: 'blog_poster_analyze_seo',
        nonce: blogPosterSeo.nonce,
        post_id: postId
      }
    }).done(function(res) {
      if (res.success) {
        $status.text('分析完了');
        renderScore($panel, res.data);
        renderSections($panel, res.data);
        renderRecommendations($panel, res.data);
      } else {
        $status.text(res.data && res.data.message ? res.data.message : '分析失敗');
      }
    }).fail(function() {
      $status.text('分析がタイムアウトしました');
    });
  });

  $(document).on('click', '.blog-poster-generate-tasks', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    $.ajax({
      url: blogPosterSeo.ajaxUrl,
      method: 'POST',
      timeout: 120000,
      data: {
        action: 'blog_poster_generate_tasks',
        nonce: blogPosterSeo.nonce,
        post_id: postId
      }
    }).done(function(res) {
      if (res.success) {
        renderTasks($panel, res.data);
      } else if (res.data && res.data.message) {
        $panel.find('.blog-poster-seo-status').text(res.data.message);
      }
    }).fail(function() {
      $panel.find('.blog-poster-seo-status').text('タスク生成がタイムアウトしました');
    });
  });

  $(document).on('change', '.blog-poster-task-filter', function() {
    const value = $(this).val();
    const $panel = $(this).closest('.blog-poster-seo-panel');
    $panel.find('.tasks-list li').each(function() {
      const priority = $(this).data('priority');
      if (priority === undefined) {
        $(this).show();
        return;
      }
      const p = String(priority);
      if (value === 'all' || value === p) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  });

  $(document).on('click', '.blog-poster-preview-rewrite', function() {
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    const taskId = $(this).closest('li').data('task-id');
    const $modal = $panel.find('.blog-poster-rewrite-modal');
    $modal.find('.preview-text').text('生成中...');
    $modal.find('.diff-text').hide();
    $modal.find('.blog-poster-apply-rewrite').prop('disabled', true);
    $modal.data('task-id', taskId);
    $modal.show();
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_preview_rewrite',
      nonce: blogPosterSeo.nonce,
      post_id: postId,
      task_id: taskId
    }).done(function(res) {
      if (res.success) {
        $modal.find('.blog-poster-apply-rewrite').prop('disabled', false);
        const content = res.data && res.data.content ? res.data.content : 'プレビュー準備中';
        const original = res.data && res.data.original ? res.data.original : '';
        $modal.find('.preview-text').text(content);
        if (original !== '') {
          $modal.find('.diff-text').html(diffWords(original, content)).show();
        } else {
          $modal.find('.diff-text').html('<ins>' + escapeHtml(content) + '</ins>').show();
        }
      } else if (res.data && res.data.message) {
        $modal.find('.preview-text').text(res.data.message);
        $modal.find('.diff-text').hide();
        $modal.find('.blog-poster-apply-rewrite').prop('disabled', true);
      }
    }).fail(function() {
      $modal.find('.preview-text').text('生成に失敗しました。時間をおいて再試行してください。');
      $modal.find('.diff-text').hide();
      $modal.find('.blog-poster-apply-rewrite').prop('disabled', true);
    });
  });

  $(document).on('click', '.blog-poster-apply-rewrite', function() {
    const $modal = $(this).closest('.blog-poster-rewrite-modal');
    const $panel = $(this).closest('.blog-poster-seo-panel');
    const postId = $panel.data('post-id');
    const taskId = $modal.data('task-id');
    const content = $modal.find('.preview-text').text();
    if (!taskId) return;
    $.post(blogPosterSeo.ajaxUrl, {
      action: 'blog_poster_apply_rewrite',
      nonce: blogPosterSeo.nonce,
      post_id: postId,
      task_id: taskId,
      content: content
    }).done(function(res) {
      if (res.success) {
        const $li = $panel.find(`li[data-task-id="${taskId}"]`);
        $li.attr('data-status', 'completed');
        $li.find('.task-status').text('完了');
        $li.find('.task-checkbox').prop('checked', true);
        if (res.data && res.data.updated_content) {
          if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
            tinymce.get('content').setContent(res.data.updated_content);
          } else {
            $('#content').val(res.data.updated_content);
          }
        }
        $modal.hide();
      } else if (res.data && res.data.message) {
        $modal.find('.preview-text').text(res.data.message);
      }
    }).fail(function() {
      $modal.find('.preview-text').text('適用に失敗しました。時間をおいて再試行してください。');
    });
  });

  $(document).on('click', '.blog-poster-modal-close', function() {
    $(this).closest('.blog-poster-rewrite-modal').hide();
  });

  $(function() {
    $('.blog-poster-seo-panel').each(function() {
      const $panel = $(this);
      const postId = $panel.data('post-id');
      if (!postId) return;
      $.post(blogPosterSeo.ajaxUrl, {
        action: 'blog_poster_get_analysis',
        nonce: blogPosterSeo.nonce,
        post_id: postId
      }).done(function(res) {
        if (res.success && res.data) {
          renderScore($panel, res.data);
          renderSections($panel, res.data);
          renderRecommendations($panel, res.data);
        }
      });
      $.post(blogPosterSeo.ajaxUrl, {
        action: 'blog_poster_get_tasks',
        nonce: blogPosterSeo.nonce,
        post_id: postId
      }).done(function(res) {
        if (res.success && res.data) {
          renderTasks($panel, res.data);
        }
      });
    });
  });
})(jQuery);
