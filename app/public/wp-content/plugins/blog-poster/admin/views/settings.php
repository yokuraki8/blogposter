<?php
/**
 * 設定画面テンプレート
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( 'blog_poster_settings', array() );
$ai_provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude';
$categories = get_categories( array( 'hide_empty' => false ) );
$selected_categories = isset( $settings['category_ids'] ) && is_array( $settings['category_ids'] )
    ? array_map( 'intval', $settings['category_ids'] )
    : array();
$default_category_id = isset( $settings['default_category_id'] ) ? intval( $settings['default_category_id'] ) : 0;
$default_models = isset( $settings['default_model'] ) && is_array( $settings['default_model'] ) ? $settings['default_model'] : array();
$yoast_enabled = ! empty( $settings['enable_yoast_integration'] );
$subscription_plan = isset( $settings['subscription_plan'] ) ? (string) $settings['subscription_plan'] : 'free';
$is_paid_plan = 'free' !== $subscription_plan;
$image_generation_enabled = ! empty( $settings['enable_image_generation'] );
$image_provider = isset( $settings['image_provider'] ) ? $settings['image_provider'] : 'openai';
$image_aspect_ratio = isset( $settings['image_aspect_ratio'] ) ? $settings['image_aspect_ratio'] : '1:1';
$image_style = isset( $settings['image_style'] ) ? $settings['image_style'] : 'photo';
$image_size = isset( $settings['image_size'] ) ? $settings['image_size'] : '1024x1024';
$image_quality = isset( $settings['image_quality'] ) ? $settings['image_quality'] : 'standard';
$primary_research_enabled = ! empty( $settings['primary_research_enabled'] );
$external_link_existence_check_enabled = ! isset( $settings['external_link_existence_check_enabled'] ) || ! empty( $settings['external_link_existence_check_enabled'] );
$external_link_credibility_check_enabled = ! isset( $settings['external_link_credibility_check_enabled'] ) || ! empty( $settings['external_link_credibility_check_enabled'] );
$primary_research_mode = isset( $settings['primary_research_mode'] ) ? $settings['primary_research_mode'] : 'strict';
$primary_research_threshold = isset( $settings['primary_research_credibility_threshold'] ) ? (int) $settings['primary_research_credibility_threshold'] : 70;
$primary_research_timeout = isset( $settings['primary_research_timeout_sec'] ) ? (int) $settings['primary_research_timeout_sec'] : 8;
$primary_research_retry = isset( $settings['primary_research_retry_count'] ) ? (int) $settings['primary_research_retry_count'] : 2;
$primary_research_allowed_domains = isset( $settings['primary_research_allowed_domains'] ) ? $settings['primary_research_allowed_domains'] : '';
$primary_research_blocked_domains = isset( $settings['primary_research_blocked_domains'] ) ? $settings['primary_research_blocked_domains'] : '';
$auto_quality_gate_enabled = ! isset( $settings['auto_quality_gate_enabled'] ) || ! empty( $settings['auto_quality_gate_enabled'] );
$auto_quality_gate_mode = isset( $settings['auto_quality_gate_mode'] ) ? $settings['auto_quality_gate_mode'] : 'strict';
$auto_quality_gate_max_fixes = isset( $settings['auto_quality_gate_max_fixes'] ) ? (int) $settings['auto_quality_gate_max_fixes'] : 1;
$yoast_active = false;
if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( function_exists( 'is_plugin_active' ) ) {
    $yoast_active = is_plugin_active( 'wordpress-seo/wp-seo.php' );
}
$openai_models = array(
    'gpt-5.2',
    'gpt-5.2-pro',
    'gpt-5-mini',
);
$gemini_models = array(
    'gemini-2.5-pro',
    'gemini-2.5-flash',
);
$claude_models = array(
    'claude-sonnet-4-5-20250929',  // Claude Sonnet 4.5（推奨）
    'claude-opus-4-5-20251101',    // Claude Opus 4.5
);

$mask_key = function ( $value ) {
    $value = is_string( $value ) ? $value : '';
    $len = strlen( $value );
    if ( $len === 0 ) {
        return '';
    }
    if ( $len <= 8 ) {
        return str_repeat( '*', $len );
    }
    return substr( $value, 0, 4 ) . str_repeat( '*', max( 4, $len - 8 ) ) . substr( $value, -4 );
};
$masked_openai = $mask_key( Blog_Poster_Settings::decrypt( isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '' ) );
$masked_gemini = $mask_key( Blog_Poster_Settings::decrypt( isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '' ) );
$masked_claude = $mask_key( Blog_Poster_Settings::decrypt( isset( $settings['claude_api_key'] ) ? $settings['claude_api_key'] : '' ) );
?>

<div class="wrap blog-poster-settings">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <div class="blog-poster-version">
        <p><?php printf( __( 'バージョン: %s', 'blog-poster' ), BLOG_POSTER_VERSION ); ?></p>
    </div>

    <?php settings_errors(); ?>

    <form method="post" action="options.php">
        <?php
        settings_fields( 'blog_poster_settings_group' );
        do_settings_sections( 'blog_poster_settings_group' );
        ?>

        <div class="blog-poster-settings-container">

            <!-- AIプロバイダー選択 -->
            <div class="settings-section">
                <h2><?php _e( 'AIプロバイダー設定', 'blog-poster' ); ?></h2>
                <p class="description">
                    <?php _e( '使用するAIプロバイダーを選択し、APIキーを設定してください。', 'blog-poster' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e( 'AIプロバイダー', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select name="blog_poster_settings[ai_provider]" id="ai_provider">
                                <option value="gemini" <?php selected( $ai_provider, 'gemini' ); ?>>
                                    Google Gemini
                                </option>
                                <option value="claude" <?php selected( $ai_provider, 'claude' ); ?>>
                                    Anthropic Claude
                                </option>
                                <option value="openai" <?php selected( $ai_provider, 'openai' ); ?>>
                                    OpenAI
                                </option>
                            </select>
                            <p class="description">
                                <?php _e( '記事生成に使用するAIプロバイダーを選択します。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Google Gemini設定 -->
            <div class="settings-section api-section" id="gemini-section" style="display: <?php echo $ai_provider === 'gemini' ? 'block' : 'none'; ?>;">
                <h2><?php _e( 'Google Gemini API設定', 'blog-poster' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gemini_api_key"><?php _e( 'API Key', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="blog_poster_settings[gemini_api_key]"
                                id="gemini_api_key"
                                value=""
                                placeholder="<?php echo esc_attr( $masked_gemini ); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php _e( 'Google AI StudioからAPIキーを取得してください。', 'blog-poster' ); ?>
                                <a href="https://aistudio.google.com/app/apikey" target="_blank">
                                    <?php _e( 'APIキーを取得', 'blog-poster' ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="gemini_model"><?php _e( 'モデル', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select name="blog_poster_settings[default_model][gemini]" id="gemini_model">
                                <?php foreach ( $gemini_models as $model ) : ?>
                                    <option value="<?php echo esc_attr( $model ); ?>" <?php selected( isset( $default_models['gemini'] ) ? $default_models['gemini'] : 'gemini-2.5-pro', $model ); ?>>
                                        <?php echo esc_html( $model ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( 'Geminiの使用モデルを選択します。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e( 'APIキー確認', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <button type="button" class="button" id="gemini-key-check">
                                <?php _e( 'APIキーを確認', 'blog-poster' ); ?>
                            </button>
                            <span id="gemini-key-check-status" style="margin-left:8px;"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Anthropic Claude設定 -->
            <div class="settings-section api-section" id="claude-section" style="display: <?php echo $ai_provider === 'claude' ? 'block' : 'none'; ?>;">
                <h2><?php _e( 'Anthropic Claude API設定', 'blog-poster' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="claude_api_key"><?php _e( 'API Key', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="blog_poster_settings[claude_api_key]"
                                id="claude_api_key"
                                value=""
                                placeholder="<?php echo esc_attr( $masked_claude ); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php _e( 'Anthropic ConsoleからAPIキーを取得してください。', 'blog-poster' ); ?>
                                <a href="https://console.anthropic.com/" target="_blank">
                                    <?php _e( 'APIキーを取得', 'blog-poster' ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="claude_model"><?php _e( 'モデル', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select name="blog_poster_settings[default_model][claude]" id="claude_model">
                                <?php foreach ( $claude_models as $model ) : ?>
                                    <option value="<?php echo esc_attr( $model ); ?>" <?php selected( isset( $default_models['claude'] ) ? $default_models['claude'] : 'claude-sonnet-4-5-20250929', $model ); ?>>
                                        <?php echo esc_html( $model ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( 'Claudeの使用モデルを選択します。', 'blog-poster' ); ?>
                            </p>
                            <p>
                                <button type="button" class="button" id="claude-key-check">
                                    <?php _e( 'APIキーを確認', 'blog-poster' ); ?>
                                </button>
                                <span id="claude-key-check-status" style="margin-left:8px;"></span>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- OpenAI設定 -->
            <div class="settings-section api-section" id="openai-section" style="display: <?php echo $ai_provider === 'openai' ? 'block' : 'none'; ?>;">
                <h2><?php _e( 'OpenAI API設定', 'blog-poster' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="openai_api_key"><?php _e( 'API Key', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="blog_poster_settings[openai_api_key]"
                                id="openai_api_key"
                                value=""
                                placeholder="<?php echo esc_attr( $masked_openai ); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php _e( 'OpenAI PlatformからAPIキーを取得してください。', 'blog-poster' ); ?>
                                <a href="https://platform.openai.com/api-keys" target="_blank">
                                    <?php _e( 'APIキーを取得', 'blog-poster' ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai_model"><?php _e( 'モデル', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select name="blog_poster_settings[default_model][openai]" id="openai_model">
                                <?php foreach ( $openai_models as $model ) : ?>
                                    <option value="<?php echo esc_attr( $model ); ?>" <?php selected( isset( $default_models['openai'] ) ? $default_models['openai'] : 'gpt-5.2', $model ); ?>>
                                        <?php echo esc_html( $model ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( 'OpenAIの使用モデルを選択します。', 'blog-poster' ); ?>
                            </p>
                            <p>
                                <button type="button" class="button" id="openai-key-check">
                                    <?php _e( 'APIキーを確認', 'blog-poster' ); ?>
                                </button>
                                <span id="openai-key-check-status" style="margin-left:8px;"></span>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 生成パラメータ設定 -->
            <div class="settings-section">
                <h2><?php _e( '生成パラメータ', 'blog-poster' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="use_json_output"><?php _e( 'JSON出力', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="blog_poster_settings[use_json_output]"
                                    id="use_json_output"
                                    value="1"
                                    <?php checked( isset( $settings['use_json_output'] ) ? $settings['use_json_output'] : true, true ); ?>
                                />
                                <?php _e( 'JSON構造化出力を使用する', 'blog-poster' ); ?>
                            </label>
                            <p class="description">
                                <?php _e( 'JSONが不正な場合は従来のMarkdown方式にフォールバックします。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="temperature"><?php _e( 'Temperature', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="blog_poster_settings[temperature]"
                                id="temperature"
                                value="<?php echo esc_attr( isset( $settings['temperature'] ) ? $settings['temperature'] : 0.7 ); ?>"
                                min="0"
                                max="2"
                                step="0.1"
                                class="small-text"
                            />
                            <p class="description">
                                <?php _e( '生成の創造性を調整します（0-2）。低い値はより一貫性のある出力、高い値はより創造的な出力になります。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_tokens"><?php _e( 'Max Tokens', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="blog_poster_settings[max_tokens]"
                                id="max_tokens"
                                value="<?php echo esc_attr( isset( $settings['max_tokens'] ) ? $settings['max_tokens'] : 4000 ); ?>"
                                min="100"
                                max="8000"
                                step="100"
                                class="small-text"
                            />
                            <p class="description">
                                <?php _e( '生成する最大トークン数を指定します。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- SEO連携設定 -->
            <div class="settings-section">
                <h2><?php _e( 'SEO連携設定', 'blog-poster' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enable_yoast_integration"><?php _e( 'Yoast SEO連携', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="blog_poster_settings[enable_yoast_integration]"
                                    id="enable_yoast_integration"
                                    value="1"
                                    <?php checked( $yoast_enabled, true ); ?>
                                    <?php disabled( $yoast_active, false ); ?>
                                />
                                <?php _e( 'Yoast SEOが有効な場合のみ、メタディスクリプションとタグを自動登録', 'blog-poster' ); ?>
                            </label>
                            <p class="description">
                                <?php echo $yoast_active ? __( 'Yoast SEOは有効です。', 'blog-poster' ) : __( 'Yoast SEOが見つかりません。インストール済みの場合は有効化してください。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- カテゴリ設定 -->
            <div class="settings-section">
                <h2><?php _e( 'カテゴリ設定', 'blog-poster' ); ?></h2>
                <p class="description">
                    <?php _e( '記事生成時に付与するカテゴリを選択します。複数選択可能です。', 'blog-poster' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="category_ids"><?php _e( 'カテゴリ選択', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select
                                name="blog_poster_settings[category_ids][]"
                                id="category_ids"
                                multiple
                                size="6"
                                class="regular-text"
                            >
                                <?php foreach ( $categories as $category ) : ?>
                                    <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( $category->term_id, $selected_categories, true ) ); ?>>
                                        <?php echo esc_html( $category->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( 'Ctrl/Commandを押しながら複数選択できます。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_category_id"><?php _e( 'デフォルトカテゴリ', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <select name="blog_poster_settings[default_category_id]" id="default_category_id">
                                <option value="0"><?php _e( '未設定', 'blog-poster' ); ?></option>
                                <?php foreach ( $categories as $category ) : ?>
                                    <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $default_category_id, $category->term_id ); ?>>
                                        <?php echo esc_html( $category->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e( '未選択の場合はカテゴリ未設定のまま投稿されます。', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- トーン・スタイル設定 -->
            <div class="settings-section">
                <h2><?php _e( 'トーン・スタイル設定', 'blog-poster' ); ?></h2>
                <p class="description">
                    <?php _e( 'スライダーを調整して、生成される記事のトーンとスタイルをカスタマイズできます。', 'blog-poster' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="formality"><?php _e( 'フォーマル度', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="range"
                                name="blog_poster_settings[formality]"
                                id="formality"
                                value="<?php echo esc_attr( isset( $settings['formality'] ) ? $settings['formality'] : 50 ); ?>"
                                min="0"
                                max="100"
                                class="blog-poster-slider"
                            />
                            <span class="slider-value" id="formality-value"><?php echo esc_html( isset( $settings['formality'] ) ? $settings['formality'] : 50 ); ?></span>
                            <p class="description">
                                <?php _e( '0: カジュアル ← → 100: フォーマル', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="expertise"><?php _e( '専門性', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="range"
                                name="blog_poster_settings[expertise]"
                                id="expertise"
                                value="<?php echo esc_attr( isset( $settings['expertise'] ) ? $settings['expertise'] : 50 ); ?>"
                                min="0"
                                max="100"
                                class="blog-poster-slider"
                            />
                            <span class="slider-value" id="expertise-value"><?php echo esc_html( isset( $settings['expertise'] ) ? $settings['expertise'] : 50 ); ?></span>
                            <p class="description">
                                <?php _e( '0: 一般向け ← → 100: 専門的', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="friendliness"><?php _e( '親しみやすさ', 'blog-poster' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="range"
                                name="blog_poster_settings[friendliness]"
                                id="friendliness"
                                value="<?php echo esc_attr( isset( $settings['friendliness'] ) ? $settings['friendliness'] : 50 ); ?>"
                                min="0"
                                max="100"
                                class="blog-poster-slider"
                            />
                            <span class="slider-value" id="friendliness-value"><?php echo esc_html( isset( $settings['friendliness'] ) ? $settings['friendliness'] : 50 ); ?></span>
                            <p class="description">
                                <?php _e( '0: 距離を保つ ← → 100: 親しみやすい', 'blog-poster' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

        </div>


        <!-- RAG機能設定 -->
        <div class="blog-poster-section">
            <h2><?php _e( 'RAG機能（関連コンテンツ参照）', 'blog-poster' ); ?></h2>
            <p class="description"><?php _e( '既存の投稿・固定ページを参照して、内部リンクを自動挿入します。', 'blog-poster' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( 'RAG機能を有効化', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[rag_enabled]" value="1"
                                <?php checked( '1', $settings['rag_enabled'] ?? '0' ); ?>>
                            <?php _e( '記事生成時に既存コンテンツを参照する', 'blog-poster' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '内部リンク最大挿入数', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[max_internal_links]">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                <option value="<?php echo $i; ?>"
                                    <?php selected( $i, (int) ( $settings['max_internal_links'] ?? 3 ) ); ?>>
                                    <?php echo $i; ?> 件
                                </option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'コンテンツインデックス', 'blog-poster' ); ?></th>
                    <td>
                        <div id="rag-index-status">
                            <span class="rag-index-count">--</span> 件のコンテンツがインデックス済み
                            （最終更新: <span class="rag-last-indexed">--</span>）
                        </div>
                        <button type="button" id="rag-reindex-btn" class="button button-secondary" style="margin-top: 8px;">
                            <?php _e( '今すぐインデックス更新', 'blog-poster' ); ?>
                        </button>
                        <span id="rag-reindex-status" style="margin-left: 10px; display: none;"></span>
                    </td>
                </tr>
            </table>
        </div>
        <div class="blog-poster-section">
            <h2><?php _e( '画像生成機能（Featured Image）', 'blog-poster' ); ?></h2>
            <p class="description"><?php _e( '記事生成後にアイキャッチ画像を自動生成します（有料プラン専用）。', 'blog-poster' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( '画像生成を有効化', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[enable_image_generation]" value="1"
                                <?php checked( $image_generation_enabled, true ); ?>
                                <?php disabled( $is_paid_plan, false ); ?>>
                            <?php _e( '記事投稿時にFeatured Imageを自動生成する', 'blog-poster' ); ?>
                        </label>
                        <p class="description">
                            <?php echo $is_paid_plan ? esc_html__( '有効化すると投稿時に画像生成APIを呼び出します。', 'blog-poster' ) : esc_html__( '無料プランでは利用できません。', 'blog-poster' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '画像生成プロバイダー', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[image_provider]">
                            <option value="openai" <?php selected( $image_provider, 'openai' ); ?>>OpenAI (DALL-E 3)</option>
                            <option value="gemini" <?php selected( $image_provider, 'gemini' ); ?>>Google Gemini (Imagen 3)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'アスペクト比', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[image_aspect_ratio]">
                            <option value="1:1" <?php selected( $image_aspect_ratio, '1:1' ); ?>>1:1</option>
                            <option value="3:2" <?php selected( $image_aspect_ratio, '3:2' ); ?>>3:2</option>
                            <option value="4:3" <?php selected( $image_aspect_ratio, '4:3' ); ?>>4:3</option>
                            <option value="16:9" <?php selected( $image_aspect_ratio, '16:9' ); ?>>16:9</option>
                        </select>
                        <p class="description"><?php _e( '最終的なアイキャッチ画像はこの比率にセンタークロップされます。', 'blog-poster' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '画像トーン＆マナー', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[image_style]">
                            <option value="photo" <?php selected( $image_style, 'photo' ); ?>><?php _e( '実写', 'blog-poster' ); ?></option>
                            <option value="illustration" <?php selected( $image_style, 'illustration' ); ?>><?php _e( 'イラスト', 'blog-poster' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '画像品質', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[image_quality]">
                            <option value="standard" <?php selected( $image_quality, 'standard' ); ?>>standard</option>
                            <option value="hd" <?php selected( $image_quality, 'hd' ); ?>>hd</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <div class="blog-poster-section">
            <h2><?php _e( '一次情報リサーチ（外部リンク検証）', 'blog-poster' ); ?></h2>
            <p class="description"><?php _e( '外部リンクの実在性と信頼性を検証し、SEOリライトと記事生成の両方に適用します。', 'blog-poster' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( '一次情報リサーチを有効化', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[primary_research_enabled]" value="1" <?php checked( $primary_research_enabled, true ); ?>>
                            <?php _e( '外部リンクの検証を有効化する', 'blog-poster' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '実在チェック', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[external_link_existence_check_enabled]" value="1" <?php checked( $external_link_existence_check_enabled, true ); ?>>
                            <?php _e( 'HEAD/GETでURLが実在するか確認する', 'blog-poster' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '信頼性チェック', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[external_link_credibility_check_enabled]" value="1" <?php checked( $external_link_credibility_check_enabled, true ); ?>>
                            <?php _e( 'ドメイン・更新情報・URL構造をもとに信頼性スコアを判定する', 'blog-poster' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '不合格時のモード', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[primary_research_mode]">
                            <option value="strict" <?php selected( $primary_research_mode, 'strict' ); ?>>strict（不合格リンクは除外）</option>
                            <option value="warn" <?php selected( $primary_research_mode, 'warn' ); ?>>warn（警告のみ）</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '信頼性スコア閾値', 'blog-poster' ); ?></th>
                    <td>
                        <input type="number" min="0" max="100" step="1" class="small-text"
                            name="blog_poster_settings[primary_research_credibility_threshold]"
                            value="<?php echo esc_attr( $primary_research_threshold ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '検証タイムアウト（秒）', 'blog-poster' ); ?></th>
                    <td>
                        <input type="number" min="3" max="30" step="1" class="small-text"
                            name="blog_poster_settings[primary_research_timeout_sec]"
                            value="<?php echo esc_attr( $primary_research_timeout ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '再試行回数', 'blog-poster' ); ?></th>
                    <td>
                        <input type="number" min="0" max="3" step="1" class="small-text"
                            name="blog_poster_settings[primary_research_retry_count]"
                            value="<?php echo esc_attr( $primary_research_retry ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '許可ドメイン（改行 or カンマ区切り）', 'blog-poster' ); ?></th>
                    <td>
                        <textarea name="blog_poster_settings[primary_research_allowed_domains]" rows="3" class="large-text"><?php echo esc_textarea( $primary_research_allowed_domains ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '除外ドメイン（改行 or カンマ区切り）', 'blog-poster' ); ?></th>
                    <td>
                        <textarea name="blog_poster_settings[primary_research_blocked_domains]" rows="3" class="large-text"><?php echo esc_textarea( $primary_research_blocked_domains ); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>
        <div class="blog-poster-section">
            <h2><?php _e( '自動品質ゲート', 'blog-poster' ); ?></h2>
            <p class="description"><?php _e( '生成後に見出し破損・誤記・出典形式を自動検査し、必要なら自動修正します。', 'blog-poster' ); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( '自動品質ゲートを有効化', 'blog-poster' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="blog_poster_settings[auto_quality_gate_enabled]" value="1" <?php checked( $auto_quality_gate_enabled, true ); ?>>
                            <?php _e( '記事生成後に品質検査と自動修正を実行する', 'blog-poster' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '判定モード', 'blog-poster' ); ?></th>
                    <td>
                        <select name="blog_poster_settings[auto_quality_gate_mode]">
                            <option value="strict" <?php selected( $auto_quality_gate_mode, 'strict' ); ?>>strict（基準未達は失敗）</option>
                            <option value="warn" <?php selected( $auto_quality_gate_mode, 'warn' ); ?>>warn（警告のみ）</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( '自動修正の最大回数', 'blog-poster' ); ?></th>
                    <td>
                        <input type="number" min="0" max="2" step="1" class="small-text"
                            name="blog_poster_settings[auto_quality_gate_max_fixes]"
                            value="<?php echo esc_attr( $auto_quality_gate_max_fixes ); ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php submit_button( __( '設定を保存', 'blog-poster' ) ); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // AIプロバイダー切り替え
    $('#ai_provider').on('change', function() {
        const provider = $(this).val();
        $('.api-section').hide();
        $('#' + provider + '-section').show();
    });

    // スライダーの値を表示
    $('.blog-poster-slider').on('input', function() {
        const id = $(this).attr('id');
        $('#' + id + '-value').text($(this).val());
    });
});
</script>
