<?php
/**
 * 管理画面クラス
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_Admin クラス
 */
class Blog_Poster_Admin {

    /**
     * コンストラクタ
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // AJAXハンドラー
        add_action( 'wp_ajax_blog_poster_generate_article', array( $this, 'ajax_generate_article' ) );

        // v0.2.5-alpha: 非同期ジョブ方式のAJAXハンドラー
        add_action( 'wp_ajax_blog_poster_create_job', array( $this, 'ajax_create_job' ) );
        add_action( 'wp_ajax_blog_poster_process_step', array( $this, 'ajax_process_step' ) );
        add_action( 'wp_ajax_blog_poster_get_job_status', array( $this, 'ajax_get_job_status' ) );
        add_action( 'wp_ajax_blog_poster_create_post', array( $this, 'ajax_create_post' ) );
        add_action( 'wp_ajax_blog_poster_regenerate_from_json', array( $this, 'ajax_regenerate_from_json' ) );

        add_action( 'add_meta_boxes', array( $this, 'register_post_meta_box' ) );
    }

    /**
     * 管理画面メニューを追加
     */
    public function add_admin_menu() {
        // メインメニュー
        add_menu_page(
            __( 'Blog Poster', 'blog-poster' ),
            __( 'Blog Poster', 'blog-poster' ),
            'manage_options',
            'blog-poster',
            array( $this, 'display_dashboard_page' ),
            'dashicons-edit-large',
            30
        );

        // ダッシュボード
        add_submenu_page(
            'blog-poster',
            __( 'ダッシュボード', 'blog-poster' ),
            __( 'ダッシュボード', 'blog-poster' ),
            'manage_options',
            'blog-poster',
            array( $this, 'display_dashboard_page' )
        );

        // 記事生成
        add_submenu_page(
            'blog-poster',
            __( '記事生成', 'blog-poster' ),
            __( '記事生成', 'blog-poster' ),
            'manage_options',
            'blog-poster-generate',
            array( $this, 'display_generate_page' )
        );

        // 設定
        add_submenu_page(
            'blog-poster',
            __( '設定', 'blog-poster' ),
            __( '設定', 'blog-poster' ),
            'manage_options',
            'blog-poster-settings',
            array( $this, 'display_settings_page' )
        );

        // 履歴
        add_submenu_page(
            'blog-poster',
            __( '生成履歴', 'blog-poster' ),
            __( '生成履歴', 'blog-poster' ),
            'manage_options',
            'blog-poster-history',
            array( $this, 'display_history_page' )
        );
    }

    /**
     * スタイルシートを読み込み
     */
    public function enqueue_styles( $hook ) {
        if ( strpos( $hook, 'blog-poster' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'blog-poster-admin',
            BLOG_POSTER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BLOG_POSTER_VERSION
        );
    }

    /**
     * JavaScriptを読み込み
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'blog-poster' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'blog-poster-admin',
            BLOG_POSTER_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            BLOG_POSTER_VERSION,
            true
        );

        // v0.2.5-alpha: 非同期ジョブ方式のJavaScript
        if ( strpos( $hook, 'blog-poster-generate' ) !== false ) {
            wp_enqueue_script(
                'blog-poster-generator',
                BLOG_POSTER_PLUGIN_URL . 'assets/js/admin-generator.js',
                array( 'jquery' ),
                BLOG_POSTER_VERSION . '-' . time(), // キャッシュバスティング
                true
            );

            wp_localize_script(
                'blog-poster-generator',
                'blogPosterAjax',
                array(
                    'ajaxurl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'blog_poster_nonce' ),
                )
            );
        }

        // AJAX用のデータを渡す
        wp_localize_script(
            'blog-poster-admin',
            'blogPosterAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'blog_poster_nonce' )
            )
        );
    }

    /**
     * 設定を登録
     */
    public function register_settings() {
        register_setting(
            'blog_poster_settings_group',
            'blog_poster_settings',
            array( $this, 'sanitize_settings' )
        );
    }

    /**
     * 設定をサニタイズ
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // AI Provider
        if ( isset( $input['ai_provider'] ) ) {
            $sanitized['ai_provider'] = sanitize_text_field( $input['ai_provider'] );
        }

        // API Keys
        $sanitized['gemini_api_key'] = isset( $input['gemini_api_key'] ) ? sanitize_text_field( $input['gemini_api_key'] ) : '';
        $sanitized['claude_api_key'] = isset( $input['claude_api_key'] ) ? sanitize_text_field( $input['claude_api_key'] ) : '';
        $sanitized['openai_api_key'] = isset( $input['openai_api_key'] ) ? sanitize_text_field( $input['openai_api_key'] ) : '';

        // Parameters
        $sanitized['temperature'] = isset( $input['temperature'] ) ? floatval( $input['temperature'] ) : 0.7;
        $sanitized['max_tokens'] = isset( $input['max_tokens'] ) ? intval( $input['max_tokens'] ) : 2000;

        // Slider settings
        $sanitized['formality'] = isset( $input['formality'] ) ? intval( $input['formality'] ) : 50;
        $sanitized['expertise'] = isset( $input['expertise'] ) ? intval( $input['expertise'] ) : 50;
        $sanitized['friendliness'] = isset( $input['friendliness'] ) ? intval( $input['friendliness'] ) : 50;

        // JSON output toggle
        $sanitized['use_json_output'] = isset( $input['use_json_output'] ) ? (bool) $input['use_json_output'] : false;

        // 既存の設定を維持
        $current_settings = get_option( 'blog_poster_settings', array() );
        $sanitized = array_merge( $current_settings, $sanitized );

        return $sanitized;
    }

    /**
     * ダッシュボードページを表示
     */
    public function display_dashboard_page() {
        require_once BLOG_POSTER_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * 記事生成ページを表示
     */
    public function display_generate_page() {
        require_once BLOG_POSTER_PLUGIN_DIR . 'admin/views/generate.php';
    }

    /**
     * 設定ページを表示
     */
    public function display_settings_page() {
        require_once BLOG_POSTER_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * 履歴ページを表示
     */
    public function display_history_page() {
        require_once BLOG_POSTER_PLUGIN_DIR . 'admin/views/history.php';
    }

    /**
     * 記事編集画面のメタボックスを登録
     */
    public function register_post_meta_box() {
        add_meta_box(
            'blog-poster-json-regenerate',
            __( 'Blog Poster JSON再描画', 'blog-poster' ),
            array( $this, 'render_json_regenerate_metabox' ),
            'post',
            'side',
            'default'
        );
    }

    /**
     * JSON再描画メタボックス表示
     *
     * @param WP_Post $post 投稿
     */
    public function render_json_regenerate_metabox( $post ) {
        $json_content = get_post_meta( $post->ID, '_blog_poster_article_json', true );
        if ( empty( $json_content ) ) {
            echo '<p>' . esc_html__( 'JSONデータが見つかりません。', 'blog-poster' ) . '</p>';
            return;
        }

        wp_nonce_field( 'blog_poster_regenerate_json', 'blog_poster_regenerate_json_nonce' );
        ?>
        <p><?php esc_html_e( '保存済みJSONから本文HTMLを再描画します。', 'blog-poster' ); ?></p>
        <p>
            <button type="button" class="button" id="blog-poster-regenerate-json">
                <?php esc_html_e( 'JSONから再描画', 'blog-poster' ); ?>
            </button>
        </p>
        <script>
            jQuery(function($) {
                $('#blog-poster-regenerate-json').on('click', function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js( __( '再描画中...', 'blog-poster' ) ); ?>');
                    $.post(ajaxurl, {
                        action: 'blog_poster_regenerate_from_json',
                        nonce: $('#blog_poster_regenerate_json_nonce').val(),
                        post_id: <?php echo (int) $post->ID; ?>
                    }).done(function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data && response.data.message ? response.data.message : '再描画に失敗しました');
                        }
                    }).fail(function() {
                        alert('通信エラーが発生しました');
                    }).always(function() {
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'JSONから再描画', 'blog-poster' ) ); ?>');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX: 記事生成
     */
    public function ajax_generate_article() {
        // nonceチェック
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        // 権限チェック
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( '権限がありません。', 'blog-poster' )
            ) );
        }

        // パラメータ取得
        $topic = isset( $_POST['topic'] ) ? sanitize_text_field( $_POST['topic'] ) : '';
        $additional_instructions = isset( $_POST['additional_instructions'] ) ? sanitize_textarea_field( $_POST['additional_instructions'] ) : '';

        if ( empty( $topic ) ) {
            wp_send_json_error( array(
                'message' => __( 'トピックを入力してください。', 'blog-poster' )
            ) );
        }

        // 設定チェック
        $settings = get_option( 'blog_poster_settings', array() );
        $ai_provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'openai';

        // APIキーチェック
        $api_key_field = $ai_provider . '_api_key';
        if ( empty( $settings[ $api_key_field ] ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( '%s のAPIキーが設定されていません。設定画面で設定してください。', 'blog-poster' ),
                    $this->get_provider_name( $ai_provider )
                )
            ) );
        }

        // 無料プラン制限チェック
        $subscription_plan = isset( $settings['subscription_plan'] ) ? $settings['subscription_plan'] : 'free';
        $articles_generated = isset( $settings['articles_generated'] ) ? intval( $settings['articles_generated'] ) : 0;
        $articles_limit = isset( $settings['articles_limit_free'] ) ? intval( $settings['articles_limit_free'] ) : 5;

        if ( $subscription_plan === 'free' && $articles_generated >= $articles_limit ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( '無料プランの制限（%d記事）に達しました。有料プランにアップグレードしてください。', 'blog-poster' ),
                    $articles_limit
                )
            ) );
        }

        // 記事生成
        $generator = new Blog_Poster_Generator();
        $result = $generator->generate_article( $topic, $additional_instructions );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message()
            ) );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( array(
                'message' => __( '記事の生成に失敗しました。', 'blog-poster' )
            ) );
        }

        // WordPress投稿を作成
        $article = $result['article'];
        $post_id = wp_insert_post( array(
            'post_title'   => $article['title'],
            'post_content' => $article['content'],
            'post_excerpt' => $article['excerpt'],
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ) );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array(
                'message' => __( '投稿の作成に失敗しました。', 'blog-poster' )
            ) );
        }

        // Slug設定
        if ( ! empty( $article['slug'] ) ) {
            wp_update_post( array(
                'ID'        => $post_id,
                'post_name' => sanitize_title( $article['slug'] )
            ) );
        }

        // メタディスクリプション設定
        if ( ! empty( $article['meta_description'] ) ) {
            update_post_meta( $post_id, '_blog_poster_meta_description', $article['meta_description'] );
        }

        // 関連キーワード設定
        if ( ! empty( $article['keywords'] ) ) {
            update_post_meta( $post_id, '_blog_poster_keywords', implode( ', ', $article['keywords'] ) );
        }

        // 生成履歴をデータベースに記録
        global $wpdb;
        $table_name = $wpdb->prefix . 'blog_poster_history';
        $wpdb->insert(
            $table_name,
            array(
                'post_id'      => $post_id,
                'ai_provider'  => $ai_provider,
                'ai_model'     => $result['model'],
                'prompt'       => $topic,
                'tokens_used'  => $result['tokens'],
                'status'       => 'draft',
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        // 記事生成数をカウントアップ
        $settings['articles_generated'] = $articles_generated + 1;
        update_option( 'blog_poster_settings', $settings );

        // 成功レスポンス
        wp_send_json_success( array(
            'message'  => __( '記事が正常に生成されました！', 'blog-poster' ),
            'post_id'  => $post_id,
            'post_url' => get_edit_post_link( $post_id, 'raw' ),
            'article'  => $article,
            'tokens'   => $result['tokens'],
            'remaining' => max( 0, $articles_limit - ( $articles_generated + 1 ) ),
        ) );
    }

    /**
     * AIプロバイダー名を取得
     */
    private function get_provider_name( $provider ) {
        switch ( $provider ) {
            case 'gemini':
                return 'Google Gemini';
            case 'claude':
                return 'Anthropic Claude';
            case 'openai':
                return 'OpenAI';
            default:
                return 'AI';
        }
    }

    /**
     * Ajax: ジョブ作成
     *
     * @since 0.2.5-alpha
     */
    public function ajax_create_job() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        $topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
        $additional_instructions = isset( $_POST['additional_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['additional_instructions'] ) ) : '';

        if ( empty( $topic ) ) {
            wp_send_json_error( array( 'message' => 'トピックを入力してください' ) );
        }

        $job_manager = new Blog_Poster_Job_Manager();
        $job_id      = $job_manager->create_job( $topic, $additional_instructions );

        wp_send_json_success(
            array(
                'job_id'  => $job_id,
                'message' => 'ジョブを作成しました',
            )
        );
    }

    /**
     * Ajax: ステップ実行
     *
     * @since 0.2.5-alpha
     */
    public function ajax_process_step() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;
        $step   = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';

        if ( ! $job_id || ! $step ) {
            wp_send_json_error( array( 'message' => 'パラメータが不正です' ) );
        }

        $job_manager = new Blog_Poster_Job_Manager();

        switch ( $step ) {
            case 'outline':
                $result = $job_manager->process_step_outline( $job_id );
                break;
            case 'content':
                $result = $job_manager->process_step_content( $job_id );
                break;
            case 'review':
                $result = $job_manager->process_step_review( $job_id );
                break;
            default:
                wp_send_json_error( array( 'message' => '不明なステップです' ) );
        }

        if ( $result['success'] ) {
            // success フィールドを除いてデータを返す
            unset( $result['success'] );
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Ajax: ジョブステータス取得
     *
     * @since 0.2.5-alpha
     */
    public function ajax_get_job_status() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        $job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

        $job_manager = new Blog_Poster_Job_Manager();
        $job         = $job_manager->get_job( $job_id );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => 'ジョブが見つかりません' ) );
        }

        wp_send_json_success(
            array(
                'status'       => $job['status'],
                'current_step' => $job['current_step'],
                'total_steps'  => $job['total_steps'],
            )
        );
    }

    /**
     * Ajax: 投稿を作成
     *
     * @since 0.2.5-alpha
     */
    public function ajax_create_post() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        error_log( 'Blog Poster: ajax_create_post called' );

        $title            = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $slug             = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        $excerpt          = isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '';
        // マークダウンの内容はサニタイズせずそのまま取得（後でHTMLに変換してからサニタイズする）
        $content          = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
        $meta_description = isset( $_POST['meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_description'] ) ) : '';

        error_log( 'Blog Poster: Title: ' . $title );
        error_log( 'Blog Poster: Content length: ' . strlen( $content ) );

        if ( empty( $title ) || empty( $content ) ) {
            error_log( 'Blog Poster: Empty title or content' );
            wp_send_json_error( array( 'message' => 'タイトルまたは本文が空です' ) );
        }

        // JSONブロックならHTMLへレンダリング、なければMarkdown変換
        $decoded = json_decode( $content, true );
        if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['blocks'] ) ) {
            $generator    = new Blog_Poster_Generator();
            $html_content = $generator->render_article_json_to_html( $decoded );
            $html_content = $generator->fact_check_claude_references( $html_content );
        } else {
            $html_content = $this->markdown_to_html( $content );
        }

        // 投稿を作成
        $post_data = array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $html_content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
            'post_type'    => 'post',
        );

        error_log( 'Blog Poster: Creating post with data: ' . print_r( $post_data, true ) );

        $post_id = wp_insert_post( $post_data );

        error_log( 'Blog Poster: Post ID: ' . $post_id );

        if ( is_wp_error( $post_id ) ) {
            error_log( 'Blog Poster: wp_insert_post error: ' . $post_id->get_error_message() );
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }

        if ( isset( $decoded ) && is_array( $decoded ) && isset( $decoded['blocks'] ) ) {
            update_post_meta( $post_id, '_blog_poster_article_json', wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) );
        }

        // メタ情報を保存
        if ( ! empty( $meta_description ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
        }

        error_log( 'Blog Poster: Post created successfully with ID: ' . $post_id );

        wp_send_json_success(
            array(
                'post_id'  => $post_id,
                'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
                'message'  => '投稿を作成しました',
            )
        );
    }

    /**
     * Ajax: JSONから本文を再描画
     */
    public function ajax_regenerate_from_json() {
        check_ajax_referer( 'blog_poster_regenerate_json', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $json_content = get_post_meta( $post_id, '_blog_poster_article_json', true );
        if ( empty( $json_content ) ) {
            wp_send_json_error( array( 'message' => 'JSONデータが見つかりません。' ) );
        }

        $decoded = json_decode( $json_content, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $decoded['blocks'] ) ) {
            wp_send_json_error( array( 'message' => 'JSONが不正です。' ) );
        }

        $generator    = new Blog_Poster_Generator();
        $html_content = $generator->render_article_json_to_html( $decoded );

        $updated = wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => $html_content,
            ),
            true
        );

        if ( is_wp_error( $updated ) ) {
            wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => '再描画が完了しました。' ) );
    }

    /**
     * マークダウンをHTMLに変換（v0.2.6改善版）
     *
     * @param string $markdown マークダウンテキスト
     * @return string HTML
     */
    private function markdown_to_html( $markdown ) {
        // Parsedownライブラリを読み込み
        if ( ! class_exists( 'Parsedown' ) ) {
            require_once BLOG_POSTER_PLUGIN_DIR . 'includes/Parsedown.php';
        }

        // ステップ1: マークダウンの前処理
        // 1-1. HTMLエンティティをデコード
        $markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // 1-2. 記事全体を囲む```markdownブロックを削除
        $markdown = preg_replace( '/^```(?:markdown|md)\s*\n/', '', $markdown );
        $markdown = preg_replace( '/\n```\s*$/', '', $markdown );

        // 1-3. HTMLエンティティ化されたタグを修正
        $html_entity_replacements = array(
            '<!--?php'  => '<?php',
            '?-->'      => '?>',
            '&lt;?php'  => '<?php',
            '?&gt;'     => '?>',
            '&lt;'      => '<',
            '&gt;'      => '>',
            '&quot;'    => '"',
            '&amp;'     => '&',
        );
        $markdown = strtr( $markdown, $html_entity_replacements );

        // ステップ2: コードブロックの構造検証と修正
        $lines = explode( "\n", $markdown );
        $fixed_lines = array();
        $in_code_block = false;

        for ( $i = 0; $i < count( $lines ); $i++ ) {
            $line = $lines[ $i ];
            $trimmed = trim( $line );

            // コードブロック開始を検出
            if ( preg_match( '/^```(\w*)/', $trimmed ) ) {
                // すでにコードブロック内なら前のブロックを閉じる
                if ( $in_code_block ) {
                    $fixed_lines[] = '```';
                }
                $in_code_block = true;
                $fixed_lines[] = $line;
            }
            // コードブロック終了を検出
            elseif ( trim( $line ) === '```' ) {
                if ( $in_code_block ) {
                    $in_code_block = false;
                }
                $fixed_lines[] = $line;
            }
            // その他の行
            else {
                $fixed_lines[] = $line;
            }
        }

        // 最後に開いたままのコードブロックがあれば閉じる
        if ( $in_code_block ) {
            $fixed_lines[] = '```';
        }

        $markdown = implode( "\n", $fixed_lines );

        // ステップ3: Parsedownを使用してHTMLに変換
        $parsedown = new Parsedown();
        $parsedown->setSafeMode( true ); // XSS対策
        $html = $parsedown->text( $markdown );

        // ステップ4: HTMLのサニタイズ
        $allowed_html = wp_kses_allowed_html( 'post' );

        // コードブロック関連のタグと属性を追加
        $allowed_html['pre']  = array(
            'class' => true,
        );
        $allowed_html['code'] = array(
            'class' => true,
        );

        $html = wp_kses( $html, $allowed_html );

        return $html;
    }
}
