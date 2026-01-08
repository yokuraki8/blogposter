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
}
