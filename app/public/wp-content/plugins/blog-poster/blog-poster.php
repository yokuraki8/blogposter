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
 * Version:           0.2.6-alpha
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
define( 'BLOG_POSTER_VERSION', '0.2.6-alpha' );
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
        require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-job-manager.php';
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

        // フロントエンドのスタイル
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles' ) );
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
    }

    /**
     * プラグイン無効化時の処理
     */
    public function deactivate() {
        // スケジュールされたCronジョブをクリア
        wp_clear_scheduled_hook( 'blog_poster_scheduled_generation' );
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
            status varchar(20) DEFAULT 'pending',
            current_step int(11) DEFAULT 0,
            total_steps int(11) DEFAULT 3,
            section_index int(11) DEFAULT 0,
            sections_total int(11) DEFAULT 0,
            previous_summary longtext,
            outline longtext,
            sections_content longtext,
            final_content longtext,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta( $sql_jobs );
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
                'gemini' => 'gemini-1.5-pro',
                'claude' => 'claude-3-5-sonnet-20241022',
                'openai' => 'gpt-5.2'
            ),
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'formality' => 50,      // フォーマル度 (0-100)
            'expertise' => 50,      // 専門性 (0-100)
            'friendliness' => 50,   // 親しみやすさ (0-100)
            'enable_rag' => false,  // RAG機能（有料プラン）
            'enable_image_generation' => false, // 画像生成（有料プラン）
            'subscription_plan' => 'free', // free, paid_with_api, paid_without_api
            'articles_generated' => 0,
            'articles_limit_free' => 5
        );

        if ( ! get_option( 'blog_poster_settings' ) ) {
            add_option( 'blog_poster_settings', $default_settings );
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
}

/**
 * プラグインを起動
 */
function blog_poster_init() {
    return Blog_Poster::get_instance();
}

// プラグインを起動
blog_poster_init();

// ★ TEMPORARY: Force DB Schema Update
add_action( 'admin_init', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'blog_poster_jobs';

    $col = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'section_index'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN section_index int(9) DEFAULT 0" );
        error_log( 'Blog Poster: Added section_index column' );
    }

    $col = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'sections_total'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN sections_total int(9) DEFAULT 0" );
        error_log( 'Blog Poster: Added sections_total column' );
    }

    $col = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'previous_summary'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN previous_summary longtext DEFAULT NULL" );
        error_log( 'Blog Poster: Added previous_summary column' );
    }

    $col = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'current_step'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN current_step varchar(50) DEFAULT 'init'" );
        error_log( 'Blog Poster: Added current_step column' );
    }
} );
