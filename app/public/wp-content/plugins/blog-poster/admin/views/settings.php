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
$ai_provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'openai';
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
                                value="<?php echo esc_attr( isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '' ); ?>"
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
                                value="<?php echo esc_attr( isset( $settings['claude_api_key'] ) ? $settings['claude_api_key'] : '' ); ?>"
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
                                value="<?php echo esc_attr( isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '' ); ?>"
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
                                value="<?php echo esc_attr( isset( $settings['max_tokens'] ) ? $settings['max_tokens'] : 2000 ); ?>"
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
