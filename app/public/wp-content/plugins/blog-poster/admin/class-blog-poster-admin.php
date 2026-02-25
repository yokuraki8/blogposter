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

        // v0.2.5-alpha: 非同期ジョブ方式のAJAXハンドラー
        add_action( 'wp_ajax_blog_poster_create_job', array( $this, 'ajax_create_job' ) );
        add_action( 'wp_ajax_blog_poster_process_step', array( $this, 'ajax_process_step' ) );
        add_action( 'wp_ajax_blog_poster_get_job_status', array( $this, 'ajax_get_job_status' ) );
        add_action( 'wp_ajax_blog_poster_create_post', array( $this, 'ajax_create_post' ) );

        // APIキー確認ハンドラー
        add_action( 'wp_ajax_blog_poster_check_api_key', array( $this, 'ajax_check_api_key' ) );

        // SEO分析・リライト
        add_action( 'add_meta_boxes', array( $this, 'add_seo_metabox' ) );
        add_action( 'wp_ajax_blog_poster_analyze_seo', array( $this, 'ajax_analyze_seo' ) );
        add_action( 'wp_ajax_blog_poster_get_analysis', array( $this, 'ajax_get_analysis' ) );
        add_action( 'wp_ajax_blog_poster_get_tasks', array( $this, 'ajax_get_tasks' ) );
        add_action( 'wp_ajax_blog_poster_generate_tasks', array( $this, 'ajax_generate_tasks' ) );
        add_action( 'wp_ajax_blog_poster_apply_task', array( $this, 'ajax_apply_task' ) );
        add_action( 'wp_ajax_blog_poster_preview_rewrite', array( $this, 'ajax_preview_rewrite' ) );
        add_action( 'wp_ajax_blog_poster_apply_rewrite', array( $this, 'ajax_apply_rewrite' ) );

        // Yoast SEOメタ同期（投稿保存時）
        add_action( 'save_post', array( $this, 'sync_yoast_meta_on_save' ), 20, 2 );
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
        if ( strpos( $hook, 'blog-poster' ) === false && $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }

        wp_enqueue_style(
            'blog-poster-admin',
            BLOG_POSTER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BLOG_POSTER_VERSION
        );

        if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
            wp_enqueue_style(
                'blog-poster-seo-panel',
                BLOG_POSTER_PLUGIN_URL . 'assets/css/seo-panel.css',
                array(),
                BLOG_POSTER_VERSION
            );
        }
    }

    /**
     * JavaScriptを読み込み
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'blog-poster' ) === false && $hook !== 'post.php' && $hook !== 'post-new.php' ) {
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

        if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
            wp_enqueue_script(
                'blog-poster-seo-panel',
                BLOG_POSTER_PLUGIN_URL . 'admin/js/admin-seo-panel.js',
                array( 'jquery' ),
                BLOG_POSTER_VERSION,
                true
            );

            wp_localize_script(
                'blog-poster-seo-panel',
                'blogPosterSeo',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'blog_poster_nonce' ),
                )
            );
        }

        // Settings page JS
        if ( strpos( $hook, 'blog-poster-settings' ) !== false ) {
            wp_enqueue_script(
                'blog-poster-settings',
                BLOG_POSTER_PLUGIN_URL . 'admin/js/admin-settings.js',
                array( 'jquery' ),
                BLOG_POSTER_VERSION,
                true
            );
        }
    }

    public function add_seo_metabox() {
        add_meta_box(
            'blog-poster-seo-panel',
            __( 'Blog Poster SEO分析', 'blog-poster' ),
            array( $this, 'render_seo_metabox' ),
            'post',
            'normal',
            'high'
        );
    }

    public function render_seo_metabox( $post ) {
        require BLOG_POSTER_PLUGIN_DIR . 'admin/views/metabox-seo-panel.php';
    }

    public function ajax_analyze_seo() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Invalid post id' ) );
        }
        $analyzer = new Blog_Poster_SEO_Analyzer();
        $analysis = $analyzer->analyze_comprehensive( $post_id );
        $analyzer->save_analysis( $post_id, $analysis );
        wp_send_json_success( $analysis );
    }

    public function ajax_get_analysis() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $analyzer = new Blog_Poster_SEO_Analyzer();
        $analysis = $analyzer->get_analysis( $post_id );
        wp_send_json_success( $analysis );
    }

    public function ajax_get_tasks() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $manager = new Blog_Poster_Task_Manager();
        $tasks = $manager->get_tasks( $post_id );
        wp_send_json_success( $tasks );
    }

    public function ajax_generate_tasks() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $analyzer = new Blog_Poster_SEO_Analyzer();
        $analysis = $analyzer->get_analysis( $post_id );
        if ( empty( $analysis ) ) {
            $analysis = $analyzer->analyze_comprehensive( $post_id );
            $analyzer->save_analysis( $post_id, $analysis );
        }
        $manager = new Blog_Poster_Task_Manager();
        $tasks = $manager->create_tasks( $post_id, $analysis );
        wp_send_json_success( $tasks );
    }

    public function ajax_apply_task() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $task_id = sanitize_text_field( $_POST['task_id'] ?? '' );
        $manager = new Blog_Poster_Task_Manager();
        $ok = $manager->apply_task( $post_id, $task_id );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => 'Task not found' ) );
        }
        wp_send_json_success( array( 'task_id' => $task_id, 'status' => 'completed' ) );
    }

    public function ajax_preview_rewrite() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $task_id = sanitize_text_field( $_POST['task_id'] ?? '' );

        $manager = new Blog_Poster_Task_Manager();
        $task = $manager->get_task( $post_id, $task_id );
        if ( empty( $task ) ) {
            wp_send_json_error( array( 'message' => 'Task not found' ) );
        }

        $task_type = $this->resolve_rewrite_task_type( $task );
        $original_text = $this->get_original_text_for_task( $post_id, $task_type );

        $rewriter = new Blog_Poster_Rewriter();
        $result = $rewriter->rewrite_content( $post_id, $task );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $manager->update_task_suggestion(
            $post_id,
            $task_id,
            array(
                'content' => $result['content'],
                'reason' => $result['suggestion']['reason'] ?? 'AI提案',
                'confidence_score' => $result['suggestion']['confidence_score'] ?? 70,
            )
        );

        wp_send_json_success(
            array(
                'task_id' => $task_id,
                'content' => $result['content'],
                'task_type' => $result['task_type'],
                'original' => $original_text,
            )
        );
    }

    public function ajax_apply_rewrite() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $task_id = sanitize_text_field( $_POST['task_id'] ?? '' );
        $content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

        $rewriter = new Blog_Poster_Rewriter();
        $result = $rewriter->apply_rewrite( $post_id, $task_id, $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'task_id' => $task_id,
                'status' => 'completed',
                'task_type' => $result['task_type'] ?? '',
                'updated_content' => $result['updated_content'] ?? '',
                'updated_meta' => ! empty( $result['updated_meta'] ),
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
        $existing = Blog_Poster_Settings::get_settings();

        // AI Provider
        if ( isset( $input['ai_provider'] ) ) {
            $sanitized['ai_provider'] = sanitize_text_field( $input['ai_provider'] );
        }

        // API Keys
        $gemini_key = isset( $input['gemini_api_key'] ) ? sanitize_text_field( $input['gemini_api_key'] ) : '';
        $claude_key = isset( $input['claude_api_key'] ) ? sanitize_text_field( $input['claude_api_key'] ) : '';
        $openai_key = isset( $input['openai_api_key'] ) ? sanitize_text_field( $input['openai_api_key'] ) : '';
        $existing_gemini = isset( $existing['gemini_api_key'] ) ? $existing['gemini_api_key'] : '';
        $existing_claude = isset( $existing['claude_api_key'] ) ? $existing['claude_api_key'] : '';
        $existing_openai = isset( $existing['openai_api_key'] ) ? $existing['openai_api_key'] : '';

        if ( $gemini_key !== '' ) {
            $sanitized['gemini_api_key'] = Blog_Poster_Settings::encrypt( $gemini_key );
        } elseif ( $existing_gemini !== '' ) {
            $sanitized['gemini_api_key'] = Blog_Poster_Settings::is_encrypted( $existing_gemini )
                ? $existing_gemini
                : Blog_Poster_Settings::encrypt( $existing_gemini );
        } else {
            $sanitized['gemini_api_key'] = '';
        }

        if ( $claude_key !== '' ) {
            $sanitized['claude_api_key'] = Blog_Poster_Settings::encrypt( $claude_key );
        } elseif ( $existing_claude !== '' ) {
            $sanitized['claude_api_key'] = Blog_Poster_Settings::is_encrypted( $existing_claude )
                ? $existing_claude
                : Blog_Poster_Settings::encrypt( $existing_claude );
        } else {
            $sanitized['claude_api_key'] = '';
        }

        if ( $openai_key !== '' ) {
            $sanitized['openai_api_key'] = Blog_Poster_Settings::encrypt( $openai_key );
        } elseif ( $existing_openai !== '' ) {
            $sanitized['openai_api_key'] = Blog_Poster_Settings::is_encrypted( $existing_openai )
                ? $existing_openai
                : Blog_Poster_Settings::encrypt( $existing_openai );
        } else {
            $sanitized['openai_api_key'] = '';
        }

        // Parameters
        $sanitized['temperature'] = isset( $input['temperature'] ) ? floatval( $input['temperature'] ) : 0.7;
        $sanitized['max_tokens'] = isset( $input['max_tokens'] ) ? intval( $input['max_tokens'] ) : 8000;

        // Slider settings
        $sanitized['formality'] = isset( $input['formality'] ) ? intval( $input['formality'] ) : 50;
        $sanitized['expertise'] = isset( $input['expertise'] ) ? intval( $input['expertise'] ) : 50;
        $sanitized['friendliness'] = isset( $input['friendliness'] ) ? intval( $input['friendliness'] ) : 50;

        // Yoast SEO integration
        $sanitized['enable_yoast_integration'] = isset( $input['enable_yoast_integration'] ) ? (bool) $input['enable_yoast_integration'] : false;
        $sanitized['enable_image_generation']  = isset( $input['enable_image_generation'] ) ? (bool) $input['enable_image_generation'] : false;
        $sanitized['image_provider']           = isset( $input['image_provider'] ) ? sanitize_key( $input['image_provider'] ) : 'openai';
        if ( ! in_array( $sanitized['image_provider'], array( 'openai', 'gemini' ), true ) ) {
            $sanitized['image_provider'] = 'openai';
        }
        $sanitized['image_aspect_ratio'] = isset( $input['image_aspect_ratio'] ) ? sanitize_text_field( $input['image_aspect_ratio'] ) : '1:1';
        if ( ! in_array( $sanitized['image_aspect_ratio'], array( '1:1', '3:2', '4:3', '16:9' ), true ) ) {
            $sanitized['image_aspect_ratio'] = '1:1';
        }
        $sanitized['image_style'] = isset( $input['image_style'] ) ? sanitize_key( $input['image_style'] ) : 'photo';
        if ( ! in_array( $sanitized['image_style'], array( 'photo', 'illustration' ), true ) ) {
            $sanitized['image_style'] = 'photo';
        }
        $sanitized['image_size']    = isset( $input['image_size'] ) ? sanitize_text_field( $input['image_size'] ) : '1024x1024';
        if ( ! in_array( $sanitized['image_size'], array( '1024x1024', '1536x1024', '1024x1536', '1792x1024', '1024x1792' ), true ) ) {
            $sanitized['image_size'] = '1024x1024';
        }
        $sanitized['image_quality'] = isset( $input['image_quality'] ) ? sanitize_text_field( $input['image_quality'] ) : 'standard';
        if ( ! in_array( $sanitized['image_quality'], array( 'standard', 'hd' ), true ) ) {
            $sanitized['image_quality'] = 'standard';
        }

        // Default models
        if ( isset( $input['default_model'] ) && is_array( $input['default_model'] ) ) {
            $sanitized['default_model'] = array();
            foreach ( $input['default_model'] as $provider => $model ) {
                $sanitized['default_model'][ sanitize_key( $provider ) ] = sanitize_text_field( $model );
            }
        }

        // Category settings
        $sanitized['category_ids'] = array();
        if ( isset( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
            $sanitized['category_ids'] = array_values(
                array_filter(
                    array_map( 'intval', $input['category_ids'] ),
                    function ( $value ) {
                        return $value > 0;
                    }
                )
            );
        }
        $sanitized['default_category_id'] = isset( $input['default_category_id'] ) ? intval( $input['default_category_id'] ) : 0;


        // RAG settings
        $sanitized['rag_enabled']       = ! empty( $input['rag_enabled'] ) ? '1' : '0';
        $sanitized['max_internal_links'] = isset( $input['max_internal_links'] ) ? min( 5, max( 1, (int) $input['max_internal_links'] ) ) : 3;

        // Primary research settings (external link validation)
        $sanitized['primary_research_enabled'] = ! empty( $input['primary_research_enabled'] ) ? '1' : '0';
        $sanitized['external_link_existence_check_enabled'] = ! empty( $input['external_link_existence_check_enabled'] ) ? '1' : '0';
        $sanitized['external_link_credibility_check_enabled'] = ! empty( $input['external_link_credibility_check_enabled'] ) ? '1' : '0';

        $mode = isset( $input['primary_research_mode'] ) ? sanitize_key( $input['primary_research_mode'] ) : 'strict';
        $sanitized['primary_research_mode'] = in_array( $mode, array( 'strict', 'warn' ), true ) ? $mode : 'strict';

        $threshold = isset( $input['primary_research_credibility_threshold'] ) ? (int) $input['primary_research_credibility_threshold'] : 70;
        $sanitized['primary_research_credibility_threshold'] = max( 0, min( 100, $threshold ) );

        $timeout = isset( $input['primary_research_timeout_sec'] ) ? (int) $input['primary_research_timeout_sec'] : 8;
        $sanitized['primary_research_timeout_sec'] = max( 3, min( 30, $timeout ) );

        $retry_count = isset( $input['primary_research_retry_count'] ) ? (int) $input['primary_research_retry_count'] : 2;
        $sanitized['primary_research_retry_count'] = max( 0, min( 3, $retry_count ) );

        $sanitized['primary_research_allowed_domains'] = isset( $input['primary_research_allowed_domains'] )
            ? sanitize_textarea_field( $input['primary_research_allowed_domains'] )
            : '';
        $sanitized['primary_research_blocked_domains'] = isset( $input['primary_research_blocked_domains'] )
            ? sanitize_textarea_field( $input['primary_research_blocked_domains'] )
            : '';

        // Auto quality gate settings
        $sanitized['auto_quality_gate_enabled'] = ! empty( $input['auto_quality_gate_enabled'] ) ? '1' : '0';
        $quality_mode = isset( $input['auto_quality_gate_mode'] ) ? sanitize_key( $input['auto_quality_gate_mode'] ) : 'strict';
        $sanitized['auto_quality_gate_mode'] = in_array( $quality_mode, array( 'strict', 'warn' ), true ) ? $quality_mode : 'strict';
        $max_fixes = isset( $input['auto_quality_gate_max_fixes'] ) ? (int) $input['auto_quality_gate_max_fixes'] : 1;
        $sanitized['auto_quality_gate_max_fixes'] = max( 0, min( 2, $max_fixes ) );

        // 既存の設定を維持
        $current_settings = Blog_Poster_Settings::get_settings();
        $sanitized = array_merge( $current_settings, $sanitized );
        if ( isset( $sanitized['subscription_plan'] ) && 'free' === $sanitized['subscription_plan'] ) {
            $sanitized['enable_image_generation'] = false;
        }

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
        $article_length = isset( $_POST['article_length'] ) ? sanitize_text_field( wp_unslash( $_POST['article_length'] ) ) : 'standard';

        if ( empty( $topic ) ) {
            wp_send_json_error( array( 'message' => 'トピックを入力してください' ) );
        }

        // 現在の設定からai_provider、ai_modelを取得
        $settings = Blog_Poster_Settings::get_settings();
        $ai_provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'gemini';
        $ai_model = isset( $settings['default_model'][ $ai_provider ] ) ? $settings['default_model'][ $ai_provider ] : '';
        $temperature = isset( $settings['temperature'] ) ? floatval( $settings['temperature'] ) : 0.7;

        $options = array(
            'ai_provider' => $ai_provider,
            'ai_model' => $ai_model,
            'temperature' => $temperature,
            'article_length' => $article_length,
        );

        $job_manager = new Blog_Poster_Job_Manager();
        $job_id      = $job_manager->create_job( $topic, $additional_instructions, $options );

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
        $job = $job_manager->get_job( $job_id );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => 'ジョブが見つかりません' ) );
        }

        if ( in_array( $job['status'], array( 'failed', 'cancelled', 'completed' ), true ) ) {
            $message = ! empty( $job['error_message'] ) ? $job['error_message'] : 'ジョブは既に終了しています。';
            wp_send_json_error( array( 'message' => $message ) );
        }

        // ステータスに合わせて実行ステップを補正（競合・再送対策）
        if ( 'pending' === $job['status'] ) {
            $step = 'outline';
        } elseif ( 'outline' === $job['status'] ) {
            $step = 'content';
        } elseif ( 'content' === $job['status'] ) {
            // content完了済みならreviewを許可
            $total_sections = isset( $job['total_sections'] ) ? intval( $job['total_sections'] ) : 0;
            $current_section = isset( $job['current_section_index'] ) ? intval( $job['current_section_index'] ) : 0;
            if ( 'review' !== $step || ( $total_sections > 0 && $current_section < $total_sections ) ) {
                $step = 'content';
            }
        } elseif ( 'review' === $job['status'] ) {
            $step = 'review';
        }

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

        $job_lock_name     = '';
        $job_lock_acquired = false;

        ob_start();
        $send_json = function ( $success, $payload ) use ( &$job_lock_name, &$job_lock_acquired ) {
            if ( $job_lock_acquired && '' !== $job_lock_name ) {
                $this->release_db_lock( $job_lock_name );
                $job_lock_acquired = false;
            }
            if ( ob_get_length() ) {
                $buffer = ob_get_contents();
                ob_end_clean();
                if ( $buffer ) {
                    error_log( 'Blog Poster: ajax_create_post cleared unexpected output buffer.' );
                }
            }
            if ( $success ) {
                wp_send_json_success( $payload );
            }
            wp_send_json_error( $payload );
        };

        error_log( 'Blog Poster: ajax_create_post called' );

        $title            = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $slug             = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $excerpt          = isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '';
        // マークダウンの内容はサニタイズせずそのまま取得（後でHTMLに変換してからサニタイズする）
        $content          = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
        $meta_description = isset( $_POST['meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_description'] ) ) : '';
        $job_id           = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

        error_log( 'Blog Poster: Title: ' . $title );
        error_log( 'Blog Poster: Content length: ' . strlen( $content ) );

        // マークダウンをHTMLに変換（ジョブがあればジョブ側の生成結果を優先）
        $html_content = '';
        $job = null;
        $external_link_audit = array();
        $quality_report = array();
        $markdown_for_validation = '';
        $generator = new Blog_Poster_Generator();
        if ( $job_id > 0 ) {
            $job_lock_name = 'blog_poster_create_post_' . $job_id;
            $job_lock_acquired = $this->acquire_db_lock( $job_lock_name, 10 );
            if ( ! $job_lock_acquired ) {
                $send_json( false, array( 'message' => '投稿作成ロックの取得に失敗しました。再試行してください。' ) );
            }

            global $wpdb;
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT post_id, final_html, final_markdown, final_title, outline_md, ai_provider, ai_model, temperature FROM {$wpdb->prefix}blog_poster_jobs WHERE id = %d",
                $job_id
            ), ARRAY_A );

            if ( $job && ! empty( $job['post_id'] ) ) {
                $existing_post_id = intval( $job['post_id'] );
                $send_json(
                    true,
                    array(
                        'post_id'  => $existing_post_id,
                        'edit_url' => admin_url( 'post.php?post=' . $existing_post_id . '&action=edit' ),
                        'message'  => '投稿は既に作成済みです',
                    )
                );
            }
        }

        if ( empty( $title ) && $job && ! empty( $job['final_title'] ) ) {
            $title = $job['final_title'];
        }

        if ( $job && ! empty( $job['final_markdown'] ) ) {
            $markdown_for_validation = $job['final_markdown'];
        } else {
            $markdown_for_validation = $content;
        }

        if ( class_exists( 'Blog_Poster_Primary_Research_Validator' ) ) {
            $validator = new Blog_Poster_Primary_Research_Validator( Blog_Poster_Settings::get_settings() );
            if ( $validator->is_enabled() && ! empty( $markdown_for_validation ) ) {
                $validation_result = $validator->filter_markdown_external_links( $markdown_for_validation );
                $markdown_for_validation = isset( $validation_result['markdown'] ) ? $validation_result['markdown'] : $markdown_for_validation;
                $external_link_audit = isset( $validation_result['reports'] ) && is_array( $validation_result['reports'] )
                    ? $validation_result['reports']
                    : array();
            }
        }

        if ( class_exists( 'Blog_Poster_Content_Quality_Gate' ) && ! empty( $markdown_for_validation ) ) {
            $quality_gate = new Blog_Poster_Content_Quality_Gate( Blog_Poster_Settings::get_settings() );
            if ( $quality_gate->is_enabled() ) {
                $quality_report = $quality_gate->validate_markdown(
                    $markdown_for_validation,
                    array(
                        'title' => $title,
                    )
                );
                $quality_report['enabled'] = true;
                $quality_report['auto_fix_attempts'] = 0;
                $quality_report['auto_fix_applied'] = false;

                $max_fixes = $quality_gate->get_max_auto_fixes();
                if ( ! empty( $job['article_length'] ) ) {
                    $generator->set_article_length( $job['article_length'] );
                }
                for ( $attempt = 1; empty( $quality_report['passes'] ) && $attempt <= $max_fixes; $attempt++ ) {
                    $fixed = $generator->auto_fix_quality_markdown(
                        $markdown_for_validation,
                        $quality_report,
                        array(
                            'topic' => $title,
                            'title' => $title,
                        )
                    );
                    if ( is_wp_error( $fixed ) ) {
                        error_log( 'Blog Poster: ajax_create_post quality auto-fix failed: ' . $fixed->get_error_message() );
                        break;
                    }
                    $fixed_markdown = isset( $fixed['markdown'] ) ? (string) $fixed['markdown'] : '';
                    if ( '' === trim( $fixed_markdown ) || trim( $fixed_markdown ) === trim( $markdown_for_validation ) ) {
                        break;
                    }
                    $markdown_for_validation = $fixed_markdown;
                    $quality_report['auto_fix_attempts'] = $attempt;
                    $quality_report['auto_fix_applied'] = true;

                    if ( isset( $validator ) && $validator instanceof Blog_Poster_Primary_Research_Validator && $validator->is_enabled() ) {
                        $validation_result = $validator->filter_markdown_external_links( $markdown_for_validation );
                        $markdown_for_validation = isset( $validation_result['markdown'] ) ? $validation_result['markdown'] : $markdown_for_validation;
                        $external_link_audit = isset( $validation_result['reports'] ) && is_array( $validation_result['reports'] )
                            ? $validation_result['reports']
                            : array();
                    }

                    $quality_report = array_merge(
                        $quality_report,
                        $quality_gate->validate_markdown(
                            $markdown_for_validation,
                            array(
                                'title' => $title,
                            )
                        )
                    );
                    $quality_report['enabled'] = true;
                    $quality_report['auto_fix_attempts'] = $attempt;
                    $quality_report['auto_fix_applied'] = true;
                }

                if ( empty( $quality_report['passes'] ) && 'strict' === ( $quality_report['mode'] ?? 'strict' ) ) {
                    $first_issue_message = ! empty( $quality_report['issues'][0]['message'] )
                        ? (string) $quality_report['issues'][0]['message']
                        : '品質基準を満たしていません。';
                    $send_json(
                        false,
                        array(
                            'message' => '自動品質ゲート（strict）で停止しました: ' . $first_issue_message,
                            'quality_report' => $quality_report,
                        )
                    );
                }
            }
        }

        if ( ! empty( $markdown_for_validation ) ) {
            $html_content = self::markdown_to_html( $markdown_for_validation );
        } elseif ( $job && ! empty( $job['final_html'] ) ) {
            $html_content = $job['final_html'];
        } else {
            $html_content = self::markdown_to_html( $content );
        }

        if ( empty( $title ) || empty( $html_content ) ) {
            error_log( 'Blog Poster: Empty title or content after job fallback' );
            $send_json( false, array( 'message' => 'ジョブが未完了の可能性があります。完了後に再試行してください。' ) );
        }

        $keywords = $this->extract_keywords_from_job_record( $job );
        if ( empty( $keywords ) ) {
            $keywords = Blog_Poster_SEO_Helper::extract_keywords( $title, $html_content, 8 );
        }
        $slug = Blog_Poster_SEO_Helper::normalize_slug( $title, $slug, $keywords );
        $meta_description = Blog_Poster_SEO_Helper::optimize_meta_description( $meta_description, $keywords, 120, 160 );
        if ( '' === $meta_description ) {
            $meta_description = Blog_Poster_SEO_Helper::build_meta_description_from_html( $html_content, $keywords, 120, 160 );
        }

        try {
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
                $send_json( false, array( 'message' => $post_id->get_error_message() ) );
            }

            // ジョブからモデル情報を取得して post_meta に保存
            if ( $job_id > 0 ) {
                if ( ! $job ) {
                    global $wpdb;
                    $job = $wpdb->get_row( $wpdb->prepare(
                        "SELECT post_id, ai_provider, ai_model, temperature FROM {$wpdb->prefix}blog_poster_jobs WHERE id = %d",
                        $job_id
                    ), ARRAY_A );
                }

                if ( $job ) {
                    update_post_meta( $post_id, '_blog_poster_provider', $job['ai_provider'] );
                    update_post_meta( $post_id, '_blog_poster_model', $job['ai_model'] );
                    update_post_meta( $post_id, '_blog_poster_temperature', $job['temperature'] );
                    error_log( 'Blog Poster: Job metadata saved. Provider: ' . $job['ai_provider'] . ', Model: ' . $job['ai_model'] . ', Temperature: ' . $job['temperature'] );
                }
            }

            if ( ! empty( $external_link_audit ) ) {
                update_post_meta( $post_id, '_blog_poster_external_link_audit', wp_json_encode( $external_link_audit, JSON_UNESCAPED_UNICODE ) );
                $checked_count = count( $external_link_audit );
                $invalid_count = 0;
                foreach ( $external_link_audit as $audit_entry ) {
                    if ( empty( $audit_entry['valid'] ) ) {
                        $invalid_count++;
                    }
                }
                update_post_meta( $post_id, '_blog_poster_external_link_audit_summary', sprintf( 'checked=%d invalid=%d', $checked_count, $invalid_count ) );
            }

            if ( ! empty( $quality_report ) ) {
                update_post_meta( $post_id, '_blog_poster_quality_report', wp_json_encode( $quality_report, JSON_UNESCAPED_UNICODE ) );
                $quality_summary = sprintf(
                    'mode=%s score=%d pass=%s issues=%d fixes=%d',
                    isset( $quality_report['mode'] ) ? sanitize_text_field( (string) $quality_report['mode'] ) : 'strict',
                    isset( $quality_report['quality_score'] ) ? (int) $quality_report['quality_score'] : 0,
                    ! empty( $quality_report['passes'] ) ? 'yes' : 'no',
                    isset( $quality_report['issues'] ) && is_array( $quality_report['issues'] ) ? count( $quality_report['issues'] ) : 0,
                    isset( $quality_report['auto_fix_attempts'] ) ? (int) $quality_report['auto_fix_attempts'] : 0
                );
                update_post_meta( $post_id, '_blog_poster_quality_report_summary', $quality_summary );
            }

            // ジョブに post_id を紐付け（post_id=0のときのみ）
            if ( $job_id > 0 ) {
                global $wpdb;
                $rows = $wpdb->update(
                    "{$wpdb->prefix}blog_poster_jobs",
                    array( 'post_id' => $post_id ),
                    array(
                        'id'      => $job_id,
                        'post_id' => 0,
                    ),
                    array( '%d' ),
                    array( '%d', '%d' )
                );

                if ( false === $rows ) {
                    $send_json( false, array( 'message' => 'ジョブへの投稿紐付けに失敗しました。' ) );
                }

                if ( 0 === $rows ) {
                    $existing_post_id = intval(
                        $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT post_id FROM {$wpdb->prefix}blog_poster_jobs WHERE id = %d",
                                $job_id
                            )
                        )
                    );

                    if ( $existing_post_id > 0 && $existing_post_id !== intval( $post_id ) ) {
                        wp_delete_post( $post_id, true );
                        $post_id = $existing_post_id;
                    }
                }
            }

        // カテゴリ設定
        $settings = Blog_Poster_Settings::get_settings();
        $category_ids = isset( $settings['category_ids'] ) && is_array( $settings['category_ids'] )
            ? array_values( array_filter( array_map( 'intval', $settings['category_ids'] ) ) )
            : array();
        $default_category_id = isset( $settings['default_category_id'] ) ? intval( $settings['default_category_id'] ) : 0;

        if ( $default_category_id > 0 && ! in_array( $default_category_id, $category_ids, true ) ) {
            $category_ids[] = $default_category_id;
        }

        if ( ! empty( $category_ids ) ) {
            wp_set_post_categories( $post_id, $category_ids, false );
        }

        $category_names = array();
        if ( ! empty( $category_ids ) ) {
            foreach ( $category_ids as $cat_id ) {
                $term = get_term( $cat_id, 'category' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $category_names[] = $term->name;
                }
            }
        }

        Blog_Poster_SEO_Helper::apply_post_seo_meta(
            $post_id,
            array(
                'title'            => $title,
                'slug'             => $slug,
                'content'          => $html_content,
                'meta_description' => $meta_description,
                'keywords'         => $keywords,
                'canonical'        => get_permalink( $post_id ),
            )
        );

        $image_result = Blog_Poster_Image_Helper::maybe_generate_and_attach_featured_image(
            $post_id,
            $title,
            array(
                'title'      => $title,
                'keywords'   => $keywords,
                'categories' => $category_names,
            ),
            $settings
        );
        if ( ! empty( $image_result['error'] ) ) {
            error_log( 'Blog Poster: featured image generation failed. post_id=' . $post_id . ' error=' . $image_result['error'] );
        }

        $yoast_enabled = Blog_Poster_SEO_Helper::is_yoast_enabled();
        if ( $yoast_enabled && $job_id > 0 ) {
            $tags = $this->extract_tags_from_job( $job_id );
            if ( ! empty( $tags ) ) {
                wp_set_post_terms( $post_id, $tags, 'post_tag', false );
            }
        }

        // 投稿作成後にSEO最適化を1回実行して分析結果を保存
        Blog_Poster_SEO_Helper::optimize_post_for_score( $post_id );

            error_log( 'Blog Poster: Post created successfully with ID: ' . $post_id );

            $send_json(
                true,
                array(
                    'post_id'  => $post_id,
                    'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
                    'message'  => '投稿を作成しました',
                    'external_link_audit' => $external_link_audit,
                    'quality_report' => $quality_report,
                )
            );
        } finally {
            if ( $job_lock_acquired && '' !== $job_lock_name ) {
                $this->release_db_lock( $job_lock_name );
            }
        }
    }

    /**
     * Yoast SEOが有効か判定
     *
     * @return bool
     */
    private function is_yoast_active() {
        if ( class_exists( 'Blog_Poster_SEO_Helper' ) ) {
            return Blog_Poster_SEO_Helper::is_yoast_active();
        }
        return false;
    }

    private function acquire_db_lock( $lock_name, $timeout = 10 ) {
        global $wpdb;
        $result = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $lock_name,
                intval( $timeout )
            )
        );
        return '1' === (string) $result;
    }

    private function release_db_lock( $lock_name ) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'SELECT RELEASE_LOCK(%s)',
                $lock_name
            )
        );
    }

    public function sync_yoast_meta_on_save( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post || 'post' !== $post->post_type ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $yoast_enabled = Blog_Poster_SEO_Helper::is_yoast_enabled();
        if ( ! $yoast_enabled ) {
            return;
        }

        $meta = get_post_meta( $post_id, '_blog_poster_meta_description', true );
        if ( empty( $meta ) ) {
            $meta = Blog_Poster_SEO_Helper::build_meta_description_from_html( $post->post_content, array(), 120, 160 );
            update_post_meta( $post_id, '_blog_poster_meta_description', $meta );
        }

        $meta = sanitize_text_field( $meta );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );
        $focus_keyword = sanitize_text_field( get_post_meta( $post_id, '_blog_poster_focus_keyword', true ) );
        if ( '' !== $focus_keyword ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_keyword );
        }
        update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( get_the_title( $post_id ) ) );
        update_post_meta( $post_id, '_yoast_wpseo_canonical', esc_url_raw( get_permalink( $post_id ) ) );
    }

    private function resolve_rewrite_task_type( $task ) {
        if ( ! empty( $task['rec_type'] ) ) {
            return $task['rec_type'];
        }
        $title = isset( $task['title'] ) ? $task['title'] : '';
        $section = isset( $task['section'] ) ? $task['section'] : '';

        if ( false !== mb_strpos( $title, 'リード' ) ) {
            return 'missing_lead';
        }
        if ( false !== mb_strpos( $title, '結論' ) || 'conclusion' === $section ) {
            return 'missing_conclusion';
        }
        if ( false !== mb_strpos( $title, 'CTA' ) ) {
            return 'missing_cta';
        }
        if ( false !== mb_strpos( $title, 'ディスクリプション' ) ) {
            return 'meta_description';
        }
        if ( false !== mb_strpos( $title, '内部リンク' ) ) {
            return 'internal_links';
        }
        if ( false !== mb_strpos( $title, '外部リンク' ) ) {
            return 'external_links';
        }

        return 'unsupported';
    }

    private function get_original_text_for_task( $post_id, $task_type ) {
        if ( 'meta_description' === $task_type ) {
            $meta = get_post_meta( $post_id, '_blog_poster_meta_description', true );
            if ( empty( $meta ) ) {
                $meta = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            }
            return (string) $meta;
        }

        return '';
    }

    /**
     * ジョブからキーワードを抽出してタグに変換
     *
     * @param int $job_id ジョブID
     * @return array タグ名配列（最大3件）
     */
    private function extract_tags_from_job( $job_id ) {
        $job_manager = new Blog_Poster_Job_Manager();
        $job = $job_manager->get_job( $job_id );

        if ( ! $job || empty( $job['outline_md'] ) ) {
            return array();
        }

        $generator = new Blog_Poster_Generator();
        $parsed = $generator->parse_markdown_frontmatter( $job['outline_md'] );
        $keywords = isset( $parsed['meta']['keywords'] ) ? $parsed['meta']['keywords'] : array();

        if ( ! is_array( $keywords ) ) {
            return array();
        }

        $tags = array();
        foreach ( $keywords as $keyword ) {
            $keyword = sanitize_text_field( $keyword );
            if ( '' !== $keyword ) {
                $tags[] = $keyword;
            }
        }

        return array_slice( array_unique( $tags ), 0, 3 );
    }

    /**
     * 投稿本文からメタディスクリプションを生成
     *
     * @param string $html_content HTML本文
     * @param int $max_length 文字数上限
     * @return string
     */
    private function build_meta_description( $html_content, $max_length = 160 ) {
        return Blog_Poster_SEO_Helper::build_meta_description_from_html( $html_content, array(), 120, max( 120, intval( $max_length ) ) );
    }

    /**
     * Extract keyword list from job outline frontmatter.
     *
     * @param array|null $job Job record.
     * @return array
     */
    private function extract_keywords_from_job_record( $job ) {
        if ( empty( $job ) || empty( $job['outline_md'] ) ) {
            return array();
        }

        $generator = new Blog_Poster_Generator();
        $parsed = $generator->parse_markdown_frontmatter( $job['outline_md'] );
        $keywords = isset( $parsed['meta']['keywords'] ) ? $parsed['meta']['keywords'] : array();
        if ( ! is_array( $keywords ) ) {
            return array();
        }

        $result = array();
        foreach ( $keywords as $keyword ) {
            $keyword = sanitize_text_field( (string) $keyword );
            if ( '' !== $keyword ) {
                $result[] = $keyword;
            }
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * マークダウンをHTMLに変換（v0.2.6改善版）
     *
     * @param string $markdown マークダウンテキスト
     * @return string HTML
     */
    public static function markdown_to_html( $markdown ) {
        // CODEタグがある場合はセグメント分割して処理
        if ( preg_match( '/<CODE(?:\\s+lang="[^"]*")?>/i', $markdown ) ) {
            return self::markdown_to_html_with_code_tags( $markdown );
        }

        return self::markdown_to_html_plain( $markdown );
    }

    /**
     * CODEタグを含むMarkdownをHTMLに変換
     *
     * @param string $markdown マークダウンテキスト
     * @return string HTML
     */
    private static function markdown_to_html_with_code_tags( $markdown ) {
        $parts = preg_split(
            '/<CODE(?:\\s+lang="([^"]*)")?>\\s*([\\s\\S]*?)\\s*<\\/CODE>/i',
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $html_parts = array();
        for ( $i = 0; $i < count( $parts ); $i++ ) {
            if ( $i % 3 === 0 ) {
                $text = $parts[ $i ];
                if ( trim( $text ) !== '' ) {
                    $html_parts[] = self::markdown_to_html_plain( $text );
                }
                continue;
            }

            $lang = $parts[ $i ];
            $code = $parts[ $i + 1 ];
            $i++; // consume code content

            $lang = is_string( $lang ) ? trim( $lang ) : '';
            $code = is_string( $code ) ? $code : '';
            $escaped = htmlspecialchars( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $class = $lang !== '' ? ' class="language-' . esc_attr( $lang ) . '"' : '';
            $html_parts[] = "<pre><code{$class}>{$escaped}</code></pre>";
        }

        return implode( "\n", $html_parts );
    }

    /**
     * マークダウンをHTMLに変換（CODEタグなしの標準ルート）
     *
     * @param string $markdown マークダウンテキスト
     * @return string HTML
     */
    private static function markdown_to_html_plain( $markdown ) {
        // Parsedownライブラリを読み込み
        if ( ! class_exists( 'Parsedown' ) ) {
            require_once BLOG_POSTER_PLUGIN_DIR . 'includes/Parsedown.php';
        }

        // ステップ1: マークダウンの前処理
        // 1-1. HTMLエンティティをデコード
        $markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // UTF-8検証と修復
        if ( ! mb_check_encoding( $markdown, 'UTF-8' ) ) {
            $markdown = mb_convert_encoding( $markdown, 'UTF-8', 'auto' );
        }
        $markdown = mb_convert_encoding( $markdown, 'UTF-8', 'UTF-8' );

        // 1-1.5. バッククォートのネスト正規化（5重 → 3重）
        $markdown = preg_replace( '/`{5,}/u', '```', $markdown );
        $markdown = preg_replace( '/`{4}/u', '```', $markdown );

        // 1-2. 記事全体を囲む```markdownブロックを削除
        $markdown = preg_replace( '/^```(?:markdown|md)\s*\n/u', '', $markdown );
        $markdown = preg_replace( '/\n```\s*$/u', '', $markdown );

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

        // 1-3.5. HTMLのコードブロックをMarkdownに正規化
        if ( preg_match( '/<pre><code/i', $markdown ) ) {
            $markdown = preg_replace_callback(
                '/<pre><code(?:\\s+class="language-([^"]+)")?>\\s*([\\s\\S]*?)\\s*<\\/code><\\/pre>/i',
                function ( $matches ) {
                    $lang = isset( $matches[1] ) ? trim( $matches[1] ) : '';
                    $code = isset( $matches[2] ) ? $matches[2] : '';
                    $code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $fence = '```' . $lang;
                    return "\n{$fence}\n{$code}\n```\n";
                },
                $markdown
            );
        }

        // 1-3.6. フェンス前の改行を強制（言語指定は維持）
        $markdown = preg_replace( '/([^\n])```/u', "$1\n```", $markdown );

        // 1-4. AIが出力した不正なコードブロック形式を修正
        // パターン: 言語指定が単独行で、その後にコードが続く場合
        // 例: "python\n# code\nimport csv" → "```python\n# code\nimport csv\n```"
        $markdown = self::fix_malformed_code_blocks( $markdown );

        // ステップ2: コードブロックの構造検証と修正
        $lines = explode( "\n", $markdown );
        $fixed_lines = array();
        $in_code_block = false;

        for ( $i = 0; $i < count( $lines ); $i++ ) {
            $line = $lines[ $i ];
            $trimmed = trim( $line );

            // コードブロック終了を優先して検出
            if ( $trimmed === '```' ) {
                if ( $in_code_block ) {
                    $in_code_block = false;
                }
                $fixed_lines[] = $line;
                continue;
            }

            // コードブロック開始を検出
            if ( preg_match( '/^```(\w*)/u', $trimmed ) ) {
                // すでにコードブロック内なら前のブロックを閉じる
                if ( $in_code_block ) {
                    $fixed_lines[] = '```';
                }
                $in_code_block = true;
                $fixed_lines[] = $line;
                continue;
            }

            // その他の行
            $fixed_lines[] = $line;
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

    /**
     * AIが出力した不正なコードブロック形式を修正
     *
     * 問題パターン:
     * - 言語指定が単独行で、その後にコードが続く場合
     * - 例: "python\n# code\nimport csv" → "```python\n# code\nimport csv\n```"
     *
     * @param string $markdown マークダウン文字列
     * @return string 修正後のマークダウン
     */
    private static function fix_malformed_code_blocks( $markdown ) {
        $lines = explode( "\n", $markdown );
        $fixed_lines = array();
        $i = 0;
        $in_fenced_block = false;
        $supported_languages = array(
            'python', 'javascript', 'js', 'typescript', 'ts', 'bash', 'shell', 'sh',
            'php', 'sql', 'html', 'css', 'scss', 'sass', 'less', 'json', 'xml',
            'yaml', 'yml', 'markdown', 'md', 'ruby', 'rb', 'java', 'c', 'cpp',
            'csharp', 'cs', 'go', 'rust', 'rust', 'kotlin', 'swift', 'objc',
            'perl', 'lua', 'r', 'scala', 'haskell', 'clojure', 'groovy', 'gradle',
            'cmake', 'makefile', 'dockerfile', 'diff', 'patch', 'text', 'plain',
            'plaintext', 'ini', 'toml', 'docker', 'latex', 'tex', 'matlab',
            'scheme', 'lisp', 'elisp', 'vim', 'nasm', 'asm', 'assembly',
        );

        while ( $i < count( $lines ) ) {
            $line = $lines[ $i ];
            $trimmed = trim( $line );

            // 既存のフェンス内はそのまま通す
            if ( preg_match( '/^```/u', $trimmed ) ) {
                $in_fenced_block = ! $in_fenced_block;
                $fixed_lines[] = $line;
                $i++;
                continue;
            }
            if ( $in_fenced_block ) {
                $fixed_lines[] = $line;
                $i++;
                continue;
            }

            // 言語指定が単独行か判定（行の先頭が直接言語名である場合）
            if ( $i < count( $lines ) - 1 &&
                 in_array( strtolower( $trimmed ), $supported_languages, true ) &&
                 ! preg_match( '/^```/u', $trimmed ) &&
                 strlen( $trimmed ) > 0 &&
                 ! preg_match( '/\s/u', $trimmed ) ) { // スペースが含まれていない（純粋な言語名）

                $next_line = trim( $lines[ $i + 1 ] );

                // 次の行が空白でない場合、コードブロックと判定
                if ( strlen( $next_line ) > 0 ) {
                    $language = $trimmed;
                    $code_lines = array( "```$language" );
                    $i++;

                    // 連続する空行をスキップ（最初）
                    while ( $i < count( $lines ) && trim( $lines[ $i ] ) === '' ) {
                        $i++;
                    }

                    // コードをスキャンして、ブロック終了まで追加
                    $found_code_end = false;
                    $blank_line_count = 0;

                    while ( $i < count( $lines ) && ! $found_code_end ) {
                        $current = $lines[ $i ];
                        $current_trimmed = trim( $current );

                        // 2行以上の連続空行があればコードブロック終了
                        if ( strlen( $current_trimmed ) === 0 ) {
                            $blank_line_count++;
                            if ( $blank_line_count >= 2 ) {
                                $found_code_end = true;
                                $code_lines[] = '```';
                                $i--; // 一つ戻す
                            } else {
                                $code_lines[] = $current;
                            }
                        } else {
                            $blank_line_count = 0;
                            $code_lines[] = $current;
                        }

                        $i++;
                    }

                    // 最後に```を追加（ループが終了した場合）
                    if ( ! $found_code_end ) {
                        $code_lines[] = '```';
                    }

                    $fixed_lines = array_merge( $fixed_lines, $code_lines );
                } else {
                    $fixed_lines[] = $line;
                }
            } else {
                $fixed_lines[] = $line;
            }

            $i++;
        }

        return implode( "\n", $fixed_lines );
    }

    /**
     * AJAX: APIキーの検証
     *
     * @since 0.3.0
     */
    public function ajax_check_api_key() {
        // Nonceチェック
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );

        // 権限チェック
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( '権限がありません。', 'blog-poster' )
            ) );
        }

        // パラメータ取得
        $provider = isset( $_POST['provider'] ) ? sanitize_text_field( $_POST['provider'] ) : '';

        if ( empty( $provider ) ) {
            wp_send_json_error( array(
                'message' => __( 'プロバイダーが指定されていません。', 'blog-poster' )
            ) );
        }

        // 現在の設定からAPIキーを取得
        $settings = Blog_Poster_Settings::get_settings();
        $api_key_field = $provider . '_api_key';

        if ( empty( $settings[ $api_key_field ] ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( '%s のAPIキーが設定されていません。', 'blog-poster' ),
                    $this->get_provider_name( $provider )
                )
            ) );
        }

        $api_key = Blog_Poster_Settings::get_api_key( $provider, $settings );
        $model = isset( $settings['default_model'][ $provider ] ) ? $settings['default_model'][ $provider ] : $this->get_default_model( $provider );

        // APIキーを検証
        $is_valid = $this->verify_api_key( $provider, $api_key, $model );

        if ( $is_valid ) {
            wp_send_json_success( array(
                'message' => sprintf(
                    __( '%s のAPIキーは有効です。', 'blog-poster' ),
                    $this->get_provider_name( $provider )
                )
            ) );
        } else {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( '%s のAPIキーが無効です。設定を確認してください。', 'blog-poster' ),
                    $this->get_provider_name( $provider )
                )
            ) );
        }
    }

    /**
     * APIキーをプロバイダーのAPIに対して検証
     *
     * @param string $provider プロバイダー（claude, openai, gemini）
     * @param string $api_key APIキー
     * @param string $model モデル名
     * @return bool 有効な場合true、無効な場合false
     *
     * @since 0.3.0
     */
    private function verify_api_key( $provider, $api_key, $model ) {
        switch ( $provider ) {
            case 'claude':
                return $this->verify_claude_api_key( $api_key, $model );
            case 'openai':
                return $this->verify_openai_api_key( $api_key, $model );
            case 'gemini':
                return $this->verify_gemini_api_key( $api_key, $model );
            default:
                return false;
        }
    }

    /**
     * Claude APIキーを検証
     *
     * @param string $api_key Claude APIキー
     * @param string $model モデル名
     * @return bool 有効な場合true、無効な場合false
     *
     * @since 0.3.0
     */
    private function verify_claude_api_key( $api_key, $model ) {
        $url = 'https://api.anthropic.com/v1/messages';

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'x-api-key'       => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'    => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model'      => $model,
                'max_tokens' => 10,
                'messages'   => array(
                    array(
                        'role'    => 'user',
                        'content' => 'test',
                    ),
                ),
            ) ),
            'timeout' => 10,
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'Claude API verification error: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        // 401: 認証失敗, 400: 不正なリクエスト（モデルが存在しない等）
        if ( 401 === $status_code || 400 === $status_code ) {
            return false;
        }

        // 200, 429（レート制限）などはAPIキーが有効
        return $status_code >= 200 && $status_code < 500;
    }

    /**
     * OpenAI APIキーを検証
     *
     * @param string $api_key OpenAI APIキー
     * @param string $model モデル名
     * @return bool 有効な場合true、無効な場合false
     *
     * @since 0.3.0
     */
    private function verify_openai_api_key( $api_key, $model ) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'model'       => $model,
                'messages'    => array(
                    array(
                        'role'    => 'user',
                        'content' => 'test',
                    ),
                ),
                'max_tokens'  => 10,
            ) ),
            'timeout' => 10,
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'OpenAI API verification error: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        // 401: 認証失敗
        if ( 401 === $status_code ) {
            return false;
        }

        // 200, 429（レート制限）などはAPIキーが有効
        return $status_code >= 200 && $status_code < 500;
    }

    /**
     * Google Gemini APIキーを検証
     *
     * @param string $api_key Gemini APIキー
     * @param string $model モデル名
     * @return bool 有効な場合true、無効な場合false
     *
     * @since 0.3.0
     */
    private function verify_gemini_api_key( $api_key, $model ) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array(
                                'text' => 'test',
                            ),
                        ),
                    ),
                ),
                'generationConfig' => array(
                    'maxOutputTokens' => 10,
                ),
            ) ),
            'timeout' => 10,
        );

        // APIキーをクエリパラメータに追加
        $url = add_query_arg( 'key', $api_key, $url );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'Gemini API verification error: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        // 401, 403: 認証失敗
        if ( 401 === $status_code || 403 === $status_code ) {
            return false;
        }

        // 200, 429（レート制限）などはAPIキーが有効
        return $status_code >= 200 && $status_code < 500;
    }

    /**
     * プロバイダーのデフォルトモデルを取得
     *
     * @param string $provider プロバイダー（claude, openai, gemini）
     * @return string デフォルトモデル名
     *
     * @since 0.3.0
     */
    private function get_default_model( $provider ) {
        switch ( $provider ) {
            case 'claude':
                return 'claude-sonnet-4-5-20250929';
            case 'openai':
                return 'gpt-5-mini';
            case 'gemini':
                return 'gemini-2.5-pro';
            default:
                return '';
        }
    }
}
