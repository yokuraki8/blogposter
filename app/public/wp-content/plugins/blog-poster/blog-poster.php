<?php
/**
 * Blog Poster - AI駆動型ブログ記事自動生成WordPressプラグイン
 *
 * @package           BlogPoster
 * @author            Bridge System
 * @copyright         2026 Bridge System
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Blog Poster
 * Plugin URI:        https://bridgesystem.me/blog-poster
 * Description:       AI駆動型ブログ記事自動生成プラグイン。Google Gemini、Anthropic Claude、OpenAIの3つのAIモデルに対応し、高品質な日本語記事を自動生成します。
 * Version:           0.4.0-alpha
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Bridge System
 * Author URI:        https://bridgesystem.me
 * Text Domain:       blog-poster
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグインのバージョン定義
define( 'BLOG_POSTER_VERSION', '0.4.0-alpha' );
define( 'BLOG_POSTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOG_POSTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BLOG_POSTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * プラグインの主要クラス
 */
class Blog_Poster {

    /**
     * シングルトンインスタンス
     *
     * @var Blog_Poster
     */
    private static $instance = null;

    /**
     * シングルトンインスタンスを取得
     *
     * @return Blog_Poster
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies() {
        // 管理画面関連
        require_once BLOG_POSTER_PLUGIN_DIR . 'admin/class-blog-poster-admin.php';

        // AI APIクライアント関連
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-ai-client.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-gemini-client.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-claude-client.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-openai-client.php';

        // コア機能
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-generator.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-settings.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-job-manager.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-queue-runner.php';

        // SEO分析・リライト
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-seo-helper.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-image-helper.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-seo-analyzer.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-task-manager.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-rewriter.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-primary-research-validator.php';

        // RAG コンテンツインデクサー
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-content-indexer.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-content-retriever.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-internal-linker.php';
        require_once BLOG_POSTER_PLUGIN_DIR . 'migrate-v040.php';
    }

    /**
     * WordPressフックを初期化
     */
    private function init_hooks() {
        // プラグイン有効化・無効化フック
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // 管理画面の初期化
        if ( is_admin() ) {
            $admin = new Blog_Poster_Admin();
        }

        // 国際化対応
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'after_setup_theme', array( $this, 'ensure_featured_image_support' ), 20 );

        // フロントエンドのスタイル
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles' ) );
        add_action( 'wp_head', array( $this, 'render_og_tags' ), 5 );

        // ジョブキュー処理
        add_action( 'blog_poster_process_queue', array( $this, 'process_queue_cron' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // RAG コンテンツインデクサーの初期化
        $content_indexer = new Blog_Poster_Content_Indexer();
        $content_indexer->register_hooks();
    }

    /**
     * Ensure featured image support for posts even if the active theme does not declare it.
     *
     * @return void
     */
    public function ensure_featured_image_support() {
        if ( ! current_theme_supports( 'post-thumbnails' ) ) {
            add_theme_support( 'post-thumbnails' );
        }
        if ( ! post_type_supports( 'post', 'thumbnail' ) ) {
            add_post_type_support( 'post', 'thumbnail' );
        }
    }

    /**
     * フロントエンドのスタイルを読み込み
     */
    public function enqueue_public_styles() {
        wp_enqueue_style(
            'blog-poster-public',
            BLOG_POSTER_PLUGIN_URL . 'assets/css/public.css',
            array(),
            BLOG_POSTER_VERSION
        );
    }

    /**
     * Render OG tags when Yoast is not active.
     *
     * @return void
     */
    public function render_og_tags() {
        $is_cli = defined( 'WP_CLI' ) && WP_CLI;
        if ( is_admin() && ! $is_cli ) {
            return;
        }

        if ( class_exists( 'Blog_Poster_SEO_Helper' ) && Blog_Poster_SEO_Helper::is_yoast_active() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( $post_id <= 0 && $is_cli ) {
            $post_id = get_the_ID();
        }
        if ( $post_id <= 0 ) {
            return;
        }
        if ( 'post' !== get_post_type( $post_id ) ) {
            return;
        }

        $title = get_post_meta( $post_id, '_blog_poster_og_title', true );
        $description = get_post_meta( $post_id, '_blog_poster_og_description', true );
        $type = get_post_meta( $post_id, '_blog_poster_og_type', true );
        $url = get_post_meta( $post_id, '_blog_poster_og_url', true );
        $image = get_post_meta( $post_id, '_blog_poster_og_image', true );

        if ( '' === $title ) {
            $title = get_the_title( $post_id );
        }
        if ( '' === $description ) {
            $description = get_post_meta( $post_id, '_blog_poster_meta_description', true );
        }
        if ( '' === $description ) {
            $description = get_the_excerpt( $post_id );
        }
        if ( '' === $type ) {
            $type = 'article';
        }
        if ( '' === $url ) {
            $url = get_permalink( $post_id );
        }
        if ( '' === $image && has_post_thumbnail( $post_id ) ) {
            $image = get_the_post_thumbnail_url( $post_id, 'full' );
        }

        if ( '' === $title || '' === $description ) {
            return;
        }

        echo "\n" . '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
        echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        if ( '' !== $image ) {
            echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
        }
    }

    /**
     * プラグイン有効化時の処理
     */
    public function activate() {
        // 初回有効化フラグを設定
        if ( ! get_option( 'blog_poster_activated' ) ) {
            update_option( 'blog_poster_activated', true );
            update_option( 'blog_poster_first_activation', time() );
        }

        // データベーステーブルの作成（将来的に使用）
        $this->create_tables();

        // デフォルト設定の作成
        $this->create_default_settings();

        // 設定移行・クリーンアップ（有効化時のみ）
        $this->maybe_upgrade_settings();
        Blog_Poster_Settings::sanitize_oversized_api_keys();
    }

    /**
     * プラグイン無効化時の処理
     */
    public function deactivate() {
        // スケジュールされたCronジョブをクリア
        wp_clear_scheduled_hook( 'blog_poster_scheduled_generation' );
        wp_clear_scheduled_hook( 'blog_poster_process_queue' );
    }

    /**
     * データベーステーブルを作成
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // 生成履歴テーブル
        $table_name = $wpdb->prefix . 'blog_poster_history';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) DEFAULT NULL,
            ai_provider varchar(50) NOT NULL,
            ai_model varchar(100) NOT NULL,
            prompt text,
            tokens_used int(11) DEFAULT 0,
            cost decimal(10,4) DEFAULT 0,
            status varchar(20) DEFAULT 'draft',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY ai_provider (ai_provider),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta( $sql );

        // ジョブ管理テーブル
        $jobs_table = $wpdb->prefix . 'blog_poster_jobs';
        $sql_jobs = "CREATE TABLE IF NOT EXISTS $jobs_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            topic varchar(500) NOT NULL,
            additional_instructions text,
            ai_provider varchar(20) DEFAULT '',
            ai_model varchar(100) DEFAULT '',
            temperature float DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            current_step int(11) DEFAULT 0,
            total_steps int(11) DEFAULT 3,
            current_section_index int(11) DEFAULT 0,
            total_sections int(11) DEFAULT 0,
            previous_context text,
            api_key_encrypted text,
            outline_md longtext,
            content_md longtext,
            final_markdown longtext,
            final_html longtext,
            post_id bigint(20) UNSIGNED DEFAULT 0,
            final_title text,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY status_created (status, created_at),
            KEY status_step (status, current_step),
            KEY status_updated (status, updated_at)
        ) $charset_collate;";

        dbDelta( $sql_jobs );

        // RAG コンテンツインデックステーブルの作成
        blog_poster_migrate_v040();
    }

    /**
     * デフォルト設定を作成
     */
    private function create_default_settings() {
        $default_settings = array(
            'ai_provider' => 'openai', // デフォルトはOpenAI
            'gemini_api_key' => '',
            'claude_api_key' => '',
            'openai_api_key' => '',
            'default_model' => array(
                'gemini' => 'gemini-2.5-pro',
                'claude' => 'claude-sonnet-4-5-20250929',
                'openai' => 'gpt-5.2'
            ),
            'temperature' => 0.7,
            'max_tokens' => 8000,
            'formality' => 50,      // フォーマル度 (0-100)
            'expertise' => 50,      // 専門性 (0-100)
            'friendliness' => 50,   // 親しみやすさ (0-100)
            'enable_rag' => false,  // RAG機能（有料プラン）
            'enable_image_generation' => false, // 画像生成（有料プラン）
            'image_provider' => 'openai', // openai / gemini
            'image_openai_model' => 'dall-e-3',
            'image_gemini_model' => 'gemini-2.5-flash-image',
            'image_aspect_ratio' => '1:1', // 1:1 / 3:2 / 4:3 / 16:9
            'image_style' => 'photo', // photo / illustration
            'image_size' => '1024x1024', // 1024x1024 / 1536x1024 / 1024x1536 / 1792x1024 / 1024x1792
            'image_quality' => 'standard', // standard / hd
            'enable_yoast_integration' => false, // Yoast SEO連携
            'primary_research_enabled' => false,
            'external_link_existence_check_enabled' => true,
            'external_link_credibility_check_enabled' => true,
            'primary_research_mode' => 'strict',
            'primary_research_credibility_threshold' => 70,
            'primary_research_timeout_sec' => 8,
            'primary_research_retry_count' => 2,
            'primary_research_allowed_domains' => '',
            'primary_research_blocked_domains' => '',
            'cron_step_limit' => 3, // Cron1回あたりの処理ステップ数
            'subscription_plan' => 'free', // free, paid_with_api, paid_without_api
            'articles_generated' => 0,
            'articles_limit_free' => 5
        );

        if ( ! get_option( 'blog_poster_settings' ) ) {
            add_option( 'blog_poster_settings', $default_settings );
        }
    }

    /**
     * 旧デフォルト設定の軽微な移行
     */
    public function maybe_upgrade_settings() {
        $settings = Blog_Poster_Settings::get_settings();
        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return;
        }

        list( $settings, $keys_changed ) = Blog_Poster_Settings::migrate_plaintext_keys( $settings );
        if ( $keys_changed ) {
            update_option( 'blog_poster_settings', $settings );
            error_log( 'Blog Poster: Migrated plaintext API keys to encrypted storage.' );
        }

        $current_max = isset( $settings['max_tokens'] ) ? intval( $settings['max_tokens'] ) : 0;
        if ( 0 === $current_max || 2000 === $current_max ) {
            $settings['max_tokens'] = 8000;
            update_option( 'blog_poster_settings', $settings );
            error_log( 'Blog Poster: Upgraded max_tokens setting to 8000.' );
        }
    }

    /**
     * 翻訳ファイルを読み込み
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'blog-poster',
            false,
            dirname( BLOG_POSTER_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Cron: キュー処理
     */
    public function process_queue_cron() {
        $settings = Blog_Poster_Settings::get_settings();
        $step_limit = isset( $settings['cron_step_limit'] ) ? intval( $settings['cron_step_limit'] ) : 3;
        $step_limit = max( 1, min( 10, $step_limit ) );
        $runner = new Blog_Poster_Queue_Runner();
        $runner->process_queue( $step_limit );
    }

    /**
     * Cron schedule追加（1分間隔）
     *
     * @param array $schedules 既存スケジュール
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['blog_poster_minute'] ) ) {
            $schedules['blog_poster_minute'] = array(
                'interval' => 60,
                'display'  => 'Every Minute (Blog Poster)',
            );
        }

        return $schedules;
    }
}

/**
 * プラグインを起動
 */
function blog_poster_init() {
    return Blog_Poster::get_instance();
}

// プラグインを起動
blog_poster_init();
