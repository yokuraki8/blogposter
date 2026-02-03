<?php
/**
 * 記事生成管理クラス - Markdown-Firstアーキテクチャ
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_Generator クラス
 *
 * Markdown-First方式で記事を生成
 * - JSON Schema不要
 * - 複雑なsanitize処理不要
 * - シンプルなフロー: Outline生成 → Section生成 → 結合 → HTML変換
 */
class Blog_Poster_Generator {

    /**
     * 現在の記事長設定
     *
     * @var string
     */
    private $current_article_length = 'standard';

    /**
     * 設定オーバーライド（ジョブ実行時の一時設定、DBに保存しない）
     *
     * @var array|null
     */
    private $settings_override = null;

    /**
     * 設定オーバーライドを設定
     *
     * @param array $override オーバーライドする設定
     * @return void
     */
    public function set_settings_override( $override ) {
        $this->settings_override = $override;
    }

    /**
     * 設定オーバーライドをクリア
     *
     * @return void
     */
    public function clear_settings_override() {
        $this->settings_override = null;
    }

    /**
     * 設定を取得（オーバーライドがあればマージ）
     *
     * @return array
     */
    private function get_effective_settings() {
        $settings = get_option( 'blog_poster_settings', array() );
        if ( ! empty( $this->settings_override ) ) {
            $settings = array_merge( $settings, $this->settings_override );
        }
        return $settings;
    }

    /**
     * APIレスポンスのエラーを抽出
     *
     * @param mixed $response レスポンス
     * @return string
     */
    private function extract_api_error_message( $response ) {
        if ( is_array( $response ) && isset( $response['success'] ) && false === $response['success'] ) {
            return isset( $response['error'] ) ? $response['error'] : 'APIエラーが発生しました。';
        }

        return '';
    }

    /**
     * APIレスポンスからWP_Errorを生成
     *
     * @param mixed $response レスポンス
     * @param string $default_code デフォルトエラーコード
     * @return WP_Error|null
     */
    private function response_to_wp_error( $response, $default_code ) {
        $message = $this->extract_api_error_message( $response );
        if ( '' === $message ) {
            return null;
        }

        $code = $default_code;
        $lower_message = strtolower( $message );
        if ( false !== strpos( $lower_message, 'insufficient_quota' ) || false !== strpos( $lower_message, 'quota' ) ) {
            $code = 'api_insufficient_quota';
        } elseif ( preg_match( '/\b429\b/u', $message ) ) {
            $code = 'api_rate_limit';
        }

        return new WP_Error( $code, $message );
    }

    /**
     * 記事長に応じた設定を取得
     *
     * @param string $article_length 記事長（short/standard/long）
     * @return array 設定配列
     */
    private function get_length_config( $article_length ) {
        $configs = array(
            'short' => array(
                'total_chars' => 2000,
                'h2_count' => '3-4',
                'h2_min' => 3,
                'h2_max' => 4,
                'section_chars' => '200-300',
                'outline_max_tokens' => 1200,
                'max_tokens' => 1500,
            ),
            'standard' => array(
                'total_chars' => 5000,
                'h2_count' => '4-5',
                'h2_min' => 4,
                'h2_max' => 5,
                'section_chars' => '300-500',
                'outline_max_tokens' => 1500,
                'max_tokens' => 3000,
            ),
            'long' => array(
                'total_chars' => 10000,
                'h2_count' => '6-8',
                'h2_min' => 6,
                'h2_max' => 8,
                'section_chars' => '500-800',
                'outline_max_tokens' => 2000,
                'max_tokens' => 4500,
            ),
        );
        return isset( $configs[ $article_length ] ) ? $configs[ $article_length ] : $configs['standard'];
    }

    /**
     * 記事長を設定
     *
     * @param string $article_length 記事長（short/standard/long）
     */
    public function set_article_length( $article_length ) {
        $this->current_article_length = $article_length;
    }

    /**
     * AIクライアントを取得
     *
     * @return object|WP_Error AIクライアントまたはエラー
     */
    private function get_ai_client() {
        // オーバーライドを含む有効な設定を取得（DBに保存せずメモリ上でマージ）
        $settings = $this->get_effective_settings();
        $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude';

        $api_key = '';
        $model = '';

        switch ( $provider ) {
            case 'gemini':
                $api_key = Blog_Poster_Settings::get_api_key( 'gemini', $settings );
                $model = isset( $settings['default_model']['gemini'] ) ? $settings['default_model']['gemini'] : 'gemini-2.5-pro';
                $client = new Blog_Poster_Gemini_Client( $api_key, $model, $settings );
                break;

            case 'claude':
                $api_key = Blog_Poster_Settings::get_api_key( 'claude', $settings );
                $model = isset( $settings['default_model']['claude'] ) ? $settings['default_model']['claude'] : 'claude-sonnet-4-5-20250929';
                $client = new Blog_Poster_Claude_Client( $api_key, $model, $settings );
                break;

            case 'openai':
            default:
                $api_key = Blog_Poster_Settings::get_api_key( 'openai', $settings );
                $model = isset( $settings['default_model']['openai'] ) ? $settings['default_model']['openai'] : 'gpt-5.2';
                $client = new Blog_Poster_OpenAI_Client( $api_key, $model, $settings );
                break;
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'APIキーが設定されていません。', 'blog-poster' ) );
        }

        return $client;
    }

    /**
     * 記事生成のメインエントリーポイント
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    public function generate_article( $topic, $additional_instructions = '' ) {
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 600 );
        }

        error_log( "Blog Poster: Starting Markdown-First article generation for topic: {$topic}" );

        // 1. Outline生成
        $outline_result = $this->generate_outline_markdown( $topic, $additional_instructions );
        if ( is_wp_error( $outline_result ) ) {
            return $outline_result;
        }

        if ( ! $outline_result['success'] ) {
            return new WP_Error( 'outline_failed', __( 'アウトライン生成に失敗しました。', 'blog-poster' ) );
        }

        $outline_sections = $outline_result['sections'];
        $meta = $outline_result['meta'];

        error_log( 'Blog Poster: Outline generated with ' . count( $outline_sections ) . ' sections' );

        // 2. Section単位で生成
        $sections_md = array();
        $previous_context = '';

        foreach ( $outline_sections as $index => $section ) {
            error_log( "Blog Poster: Generating section {$index}: {$section['title']}" );

            $result = $this->generate_section_markdown( $outline_sections, $index, $previous_context, $additional_instructions );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( ! $result['success'] ) {
                return new WP_Error( 'section_failed', sprintf( __( 'セクション%d生成に失敗しました。', 'blog-poster' ), $index + 1 ) );
            }

            $sections_md[] = $result['section_md'];
            $previous_context = $result['context'];
        }

        // 3. 結合
        $frontmatter = $this->build_frontmatter( $meta );
        $final_md = $frontmatter . "\n\n" . implode( "\n\n", $sections_md );

        // 4. 後処理
        $final_md = $this->postprocess_markdown( $final_md );
        $final_md = $this->normalize_code_blocks_after_generation( $final_md );

        // 5. HTML変換
        $html = Blog_Poster_Admin::markdown_to_html( $final_md );

        error_log( 'Blog Poster: Article generation completed successfully' );

        return array(
            'success' => true,
            'title' => $meta['title'],
            'slug' => $meta['slug'],
            'excerpt' => $meta['excerpt'],
            'keywords' => isset( $meta['keywords'] ) ? $meta['keywords'] : array(),
            'markdown' => $final_md,
            'html' => $html,
        );
    }

    /**
     * Markdown形式のアウトライン生成
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error ['success' => bool, 'outline_md' => string, 'meta' => array, 'sections' => array]
     */
    public function generate_outline_markdown( $topic, $additional_instructions = '', $forced_model = '' ) {
        // プロバイダーを判定（オーバーライドを含む有効な設定を取得）
        $settings = $this->get_effective_settings();
        $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude';
        if ( ! empty( $forced_model ) && 0 === strpos( $forced_model, 'gemini-' ) ) {
            $provider = 'gemini';
        }
        $configured_model = isset( $settings['ai_model'] ) ? $settings['ai_model'] : '';
        $outline_model = ! empty( $forced_model ) ? $forced_model : $configured_model;
        error_log( sprintf( 'Blog Poster: Outline provider=%s model=%s', $provider, $outline_model !== '' ? $outline_model : 'default' ) );

        // Geminiの場合は2段階生成
        if ( 'gemini' === $provider ) {
            error_log( 'Blog Poster: Using 2-step outline generation for Gemini' );

            // Step1: セクションタイトルのみ生成
            $step1_result = $this->generate_outline_step1_gemini( $topic, $additional_instructions, $forced_model );
            if ( is_wp_error( $step1_result ) ) {
                return $step1_result;
            }

            $section_titles = $step1_result['titles'];

            // Step2: 詳細構造を生成
            $step2_result = $this->generate_outline_step2_gemini( $topic, $section_titles, $additional_instructions, $forced_model );
            if ( is_wp_error( $step2_result ) ) {
                return $step2_result;
            }

            return $step2_result;
        }

        // Claude/OpenAIは既存の1段階生成を使用
        error_log( 'Blog Poster: Using standard outline generation for ' . $provider );

        $config = $this->get_length_config( $this->current_article_length );
        $h2_min = $config['h2_min'];
        $h2_max = $config['h2_max'];
        $h2_count = $config['h2_count'];
        $outline_max_tokens = $config['outline_max_tokens'];

        $max_outline_retries = 2;
        for ( $outline_attempt = 0; $outline_attempt <= $max_outline_retries; $outline_attempt++ ) {
            $client = $this->get_ai_client();
            if ( is_wp_error( $client ) ) {
                return $client;
            }

            $current_additional_instructions = $additional_instructions;
            if ( $outline_attempt > 0 ) {
                $current_additional_instructions .= "\n\n【重要】H2見出しは必ず{$h2_min}〜{$h2_max}個にしてください。前回の生成ではこの制約が守られていません。";
            }

            $prompt = $this->build_outline_prompt( $topic, $current_additional_instructions );
            $model_override = '';
            if ( isset( $settings['ai_provider'] ) && 'gemini' === $settings['ai_provider'] && ! empty( $forced_model ) ) {
                $model_override = $forced_model;
            }

            try {
            $response = $client->generate_text( $prompt, array( 'max_tokens' => $outline_max_tokens, 'model' => $model_override ) );

                if ( is_wp_error( $response ) ) {
                    return $response;
                }
                $api_error = $this->response_to_wp_error( $response, 'outline_api_error' );
                if ( $api_error ) {
                    return $api_error;
                }

                if ( isset( $response['success'] ) && false === $response['success'] ) {
                    $error_msg = isset( $response['error'] ) ? $response['error'] : 'APIエラーが発生しました。';
                    error_log( 'Blog Poster: API error response: ' . $error_msg );
                    return new WP_Error( 'api_error', $error_msg );
                }

                $outline_md = '';
                if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
                    $outline_md = $response['data'];
                } elseif ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
                    $outline_md = $response['content'];
                }

                if ( empty( $outline_md ) ) {
                    error_log( 'Blog Poster: Outline response empty. Raw response: ' . print_r( $response, true ) );
                    return new WP_Error( 'outline_empty', 'アウトラインが空です。' );
                }

                error_log( 'Blog Poster: Generated outline (first 500 chars): ' . substr( $outline_md, 0, 500 ) );

                $parsed = $this->parse_markdown_frontmatter( $outline_md );
                $sections = isset( $parsed['sections'] ) ? $parsed['sections'] : array();
                $section_count = count( $sections );

                if ( empty( $sections ) ) {
                    error_log( 'Blog Poster: No sections parsed from outline. Outline MD head: ' . substr( $outline_md, 0, 200 ) );
                    return new WP_Error( 'outline_no_sections', 'アウトラインからセクションを抽出できませんでした。' );
                }

                if ( $section_count >= $h2_min && $section_count <= $h2_max ) {
                    error_log( 'Blog Poster: Outline section count validated: ' . $section_count . ' (within ' . $h2_min . '-' . $h2_max . ')' );
                    return array(
                        'success' => true,
                        'outline_md' => $outline_md,
                        'meta' => $parsed['meta'],
                        'sections' => $sections,
                    );
                }

                error_log( 'Blog Poster: Outline section count out of range: ' . $section_count . ' (expected: ' . $h2_min . '-' . $h2_max . '), attempt: ' . $outline_attempt . '/' . $max_outline_retries );
                error_log( 'Blog Poster: Full outline MD: ' . $outline_md );

                if ( $outline_attempt < $max_outline_retries ) {
                    continue;
                }

                return new WP_Error( 'outline_section_count', 'アウトラインのH2セクション数が不足しています。実際: ' . $section_count . '個（必要: ' . $h2_count . '個）' );

            } catch ( Exception $e ) {
                error_log( 'Blog Poster: Outline generation error: ' . $e->getMessage() );
                return new WP_Error( 'outline_error', $e->getMessage() );
            }
        }

        return new WP_Error( 'outline_generation_failed', 'アウトライン生成に失敗しました。' );
    }

    /**
     * Gemini専用: Step1 - セクションタイトルのみ生成
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error ['success' => true, 'titles' => array]
     */
    private function generate_outline_step1_gemini( $topic, $additional_instructions = '', $forced_model = '' ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $additional_text = ! empty( $additional_instructions ) ? "\n追加指示: {$additional_instructions}" : '';

        $config = $this->get_length_config( $this->current_article_length );
        $h2_count = $config['h2_count'];
        $h2_min = $config['h2_min'];
        $h2_max = $config['h2_max'];
        $total_chars = $config['total_chars'];
        $outline_max_tokens = $config['outline_max_tokens'];

        // セクション例を動的に生成
        $section_examples = '';
        for ( $i = 1; $i <= $h2_max; $i++ ) {
            $optional = $i > $h2_min ? '（任意）' : '';
            $section_examples .= "{$i}. セクション{$i}のタイトル{$optional}\n";
        }

        $prompt = "以下のトピックについて、ブログ記事のセクション見出し（H2）を{$h2_count}個考えてください。

トピック: {$topic}{$additional_text}

【記事の目標文字数】約{$total_chars}文字

【事実性の厳守】
1. 構成は事実に基づく内容のみを想定すること
2. 憶測・推測・断定できない表現に基づく構成は禁止

【重要な制約】
1. セクション見出しは必ず{$h2_min}個以上{$h2_max}個以下
2. 各見出しは読者の課題解決を意識
3. 論理的な流れを持つ構成

出力形式（番号付きリストのみ）:
{$section_examples}
番号付きリストのみを出力してください。説明文は不要です。";

        try {
            $options = array( 'max_tokens' => $outline_max_tokens );
            if ( ! empty( $forced_model ) ) {
                $options['model'] = $forced_model;
            }
            $response = $client->generate_text( $prompt, $options );

            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $api_error = $this->response_to_wp_error( $response, 'step1_api_error' );
            if ( $api_error ) {
                return $api_error;
            }

            $content = '';
            if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
                $content = $response['data'];
            } elseif ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
                $content = $response['content'];
            }

            if ( empty( $content ) ) {
                error_log( 'Blog Poster: Step1 response empty' );
                return new WP_Error( 'step1_empty', 'セクションタイトルの生成に失敗しました。' );
            }

            // 番号付きリストからタイトルを抽出
            $titles = array();
            $lines = explode( "\n", $content );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                // "1. タイトル" または "1) タイトル" 形式を抽出
                if ( preg_match( '/^\d+[\.\)]\s+(.+)$/u', $line, $matches ) ) {
                    $titles[] = trim( $matches[1] );
                }
            }

            $count = count( $titles );
            if ( $count < $h2_min || $count > $h2_max ) {
                error_log( 'Blog Poster: Step1 section count out of range: ' . $count );
                return new WP_Error( 'step1_count', 'セクション数が不足しています。実際: ' . $count . '個（必要: ' . $h2_count . '個）' );
            }

            error_log( 'Blog Poster: Step1 generated ' . $count . ' section titles' );

            return array(
                'success' => true,
                'titles' => $titles,
            );

        } catch ( Exception $e ) {
            error_log( 'Blog Poster: Step1 error: ' . $e->getMessage() );
            return new WP_Error( 'step1_error', $e->getMessage() );
        }
    }

    /**
     * Gemini専用: Step2 - セクションタイトルから詳細構造を生成
     *
     * @param string $topic トピック
     * @param array $section_titles セクションタイトル配列
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error ['success' => true, 'outline_md' => string, ...]
     */
    private function generate_outline_step2_gemini( $topic, $section_titles, $additional_instructions = '', $forced_model = '' ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $additional_text = ! empty( $additional_instructions ) ? "\n追加指示: {$additional_instructions}" : '';

        $config = $this->get_length_config( $this->current_article_length );
        $total_chars = $config['total_chars'];

        // セクションタイトルをリスト化
        $titles_list = '';
        foreach ( $section_titles as $idx => $title ) {
            $num = $idx + 1;
            $titles_list .= "{$num}. {$title}\n";
        }

        $title_example_1 = isset( $section_titles[0] ) ? $section_titles[0] : 'セクション1のタイトル';
        $title_example_2 = isset( $section_titles[1] ) ? $section_titles[1] : 'セクション2のタイトル';

        $prompt = "以下のトピックとセクション構成に基づいて、詳細なアウトラインを作成してください。

トピック: {$topic}{$additional_text}

【記事の目標文字数】約{$total_chars}文字

セクション構成:
{$titles_list}

【事実性の厳守】
1. 事実に基づく内容のみでアウトラインを構成すること
2. 憶測・推測・断定できない表現に基づく構成は禁止

以下の形式で出力してください:

---
title: \"記事タイトル（SEO最適化、30-60文字）\"
slug: \"url-friendly-slug\"
excerpt: \"記事の抜粋（120-160文字）\"
keywords: [\"キーワード1\", \"キーワード2\", \"キーワード3\"]
---

## {$title_example_1}

### サブセクション1-1

### サブセクション1-2

## {$title_example_2}

### サブセクション2-1

（以下同様に全セクションを展開。箇条書きは不要）

要件:
- 上記のセクション構成のタイトルをそのままH2に使用する
- 各H2の下にH3を2-4個配置

出力はMarkdown形式のみ。説明文は不要です。";

        // デバッグ: プロンプト先頭500文字をログ出力
        error_log( 'Blog Poster: Step2 prompt (first 500 chars): ' . substr( $prompt, 0, 500 ) );
        error_log( 'Blog Poster: Step2 section titles: ' . print_r( $section_titles, true ) );

        try {
            $options = array( 'max_tokens' => $outline_max_tokens );
            if ( ! empty( $forced_model ) ) {
                $options['model'] = $forced_model;
            }
            $response = $client->generate_text( $prompt, $options );

            if ( is_wp_error( $response ) ) {
                error_log( 'Blog Poster: Step2 WP_Error: ' . $response->get_error_message() );
                return $response;
            }
            $api_error = $this->response_to_wp_error( $response, 'step2_api_error' );
            if ( $api_error ) {
                error_log( 'Blog Poster: Step2 API error: ' . $api_error->get_error_message() );
                return $api_error;
            }

            // デバッグ: レスポンス全体をログ出力
            error_log( 'Blog Poster: Step2 raw response: ' . print_r( $response, true ) );

            $outline_md = '';
            if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
                $outline_md = $response['data'];
                error_log( 'Blog Poster: Step2 got data field, length: ' . strlen( $outline_md ) );
            } elseif ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
                $outline_md = $response['content'];
                error_log( 'Blog Poster: Step2 got content field, length: ' . strlen( $outline_md ) );
            }

            if ( empty( $outline_md ) ) {
                error_log( 'Blog Poster: Step2 response empty. Response keys: ' . implode( ', ', array_keys( $response ) ) );
                return new WP_Error( 'step2_empty', 'アウトラインの生成に失敗しました。' );
            }

            // YAML frontmatterとセクション構造を解析
            $parsed = $this->parse_markdown_frontmatter( $outline_md );
            $sections = isset( $parsed['sections'] ) ? $parsed['sections'] : array();

            if ( empty( $sections ) ) {
                error_log( 'Blog Poster: Step2 no sections parsed' );
                return new WP_Error( 'step2_no_sections', 'セクションを抽出できませんでした。' );
            }

            error_log( 'Blog Poster: Step2 completed with ' . count( $sections ) . ' sections' );

            return array(
                'success' => true,
                'outline_md' => $outline_md,
                'meta' => $parsed['meta'],
                'sections' => $sections,
            );

        } catch ( Exception $e ) {
            error_log( 'Blog Poster: Step2 error: ' . $e->getMessage() );
            return new WP_Error( 'step2_error', $e->getMessage() );
        }
    }

    /**
     * Section単位でMarkdown本文生成
     *
     * @param array $outline_sections アウトラインのセクション配列
     * @param int $section_index 生成するセクションのインデックス
     * @param string $previous_context 前セクションの要約
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error ['success' => bool, 'section_md' => string, 'context' => string]
     */
    public function generate_section_markdown( $outline_sections, $section_index, $previous_context = '', $additional_instructions = '' ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $section = $outline_sections[ $section_index ];
        $prompt = $this->build_section_prompt( $section, $previous_context, $additional_instructions );

        $config = $this->get_length_config( $this->current_article_length );
        $max_tokens = $config['max_tokens'];

        try {
            $response = $client->generate_text( $prompt, array( 'max_tokens' => $max_tokens ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $api_error = $this->response_to_wp_error( $response, 'section_api_error' );
            if ( $api_error ) {
                return $api_error;
            }

            // Claude系は data キーに本文が入るケースがあるため両方を確認
            $section_md = '';
            if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
                $section_md = $response['data'];
            } elseif ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
                $section_md = $response['content'];
            }

            if ( empty( $section_md ) ) {
                error_log( 'Blog Poster: Section response empty. Raw response: ' . print_r( $response, true ) );
                return new WP_Error( 'section_empty', '本文が空です。' );
            }

            // セクション見出しを強制
            $section_md = $this->normalize_section_heading( $section_md, $section['title'] );

            // セクション内でコードブロックが閉じていることを保証
            $section_md = $this->validate_section_code_blocks( $section_md );
            $section_md = $this->postprocess_markdown( $section_md );

            // 次のセクションのためのコンテキスト要約を生成
            $context = $this->extract_section_context( $section_md );

            return array(
                'success' => true,
                'section_md' => $section_md,
                'context' => $context,
            );

        } catch ( Exception $e ) {
            error_log( 'Blog Poster: Section generation error: ' . $e->getMessage() );
            return new WP_Error( 'section_error', $e->getMessage() );
        }
    }

    /**
     * Outline生成プロンプトを構築
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return string プロンプト
     */
    private function build_outline_prompt( $topic, $additional_instructions = '' ) {
        $additional_text = ! empty( $additional_instructions ) ? "\n追加指示: {$additional_instructions}" : '';
        $config = $this->get_length_config( $this->current_article_length );
        $h2_count = $config['h2_count'];
        $h2_min = $config['h2_min'];
        $h2_max = $config['h2_max'];
        $total_chars = $config['total_chars'];

        // セクション例を動的に生成
        $section_examples = '';
        for ( $i = 1; $i <= $h2_min; $i++ ) {
            $section_examples .= "\n## セクション{$i}のタイトル\n\n### サブセクション{$i}-1\n";
            if ( $i <= 2 ) {
                $section_examples .= "\n### サブセクション{$i}-2\n";
            }
        }
        if ( $h2_max > $h2_min ) {
            $section_examples .= "\n（必要に応じて" . ( $h2_min + 1 ) . "-{$h2_max}個目のセクションも追加）\n";
        }

        return <<<PROMPT

あなたは日本語ブログ記事のプロフェッショナルライターです。

トピック: {$topic}{$additional_text}

【記事の目標文字数】約{$total_chars}文字

【事実性の厳守】
- 事実に基づく内容のみで構成案を作成すること
- 憶測・推測・断定できない表現に基づく構成は禁止

【重要】以下の形式で記事のアウトラインを作成してください:

---
title: "記事タイトル（SEO最適化、30-60文字）"
slug: "url-friendly-slug"
excerpt: "記事の抜粋（120-160文字）"
meta_description: "120〜160文字の記事概要。検索結果に表示されるSEO用テキスト。キーワードを自然に含む"
keywords: ["キーワード1", "キーワード2", "キーワード3"]
---
{$section_examples}
【重要な制約】
1. **H2見出し（##）は「必ず{$h2_min}個以上{$h2_max}個以下」作成すること** ← これが最重要です
2. {$h2_min}個未満は絶対に禁止
3. {$h2_max}個を超えることも禁止
4. 各H2の下にH3見出し（###）を2-4個配置
5. 読者の課題解決を意識した構成
6. H3見出しは具体例・手順・チェックリスト等を示唆する短いタイトルにする

数値確認:
- H2（##）の数: {$h2_count}個の範囲（絶対ルール）
- H3（###）の数: 各H2につき2-4個

【最終確認】
出力はMarkdown形式のみ。説明文は不要です。
H2見出し（##）を「必ず{$h2_count}個」作成してください。
PROMPT;
    }

    /**
     * Section生成プロンプトを構築
     *
     * @param array $section セクション情報
     * @param string $previous_context 前セクションのコンテキスト
     * @param string $additional_instructions 追加指示
     * @return string プロンプト
     */
    private function build_section_prompt( $section, $previous_context = '', $additional_instructions = '' ) {
        $section_title = $section['title'];
        $subsections_text = '';

        if ( ! empty( $section['subsections'] ) ) {
            $subsections_list = array();
            foreach ( $section['subsections'] as $sub ) {
                $subsections_list[] = "### {$sub['title']}";
                if ( ! empty( $sub['points'] ) ) {
                    foreach ( $sub['points'] as $point ) {
                        $subsections_list[] = "- {$point}";
                    }
                }
            }
            $subsections_text = implode( "\n", $subsections_list );
        }

        $context_text = ! empty( $previous_context ) ? "\n前のセクションの内容: {$previous_context}" : '';
        $additional_text = ! empty( $additional_instructions ) ? "\n追加指示: {$additional_instructions}" : '';

        $config = $this->get_length_config( $this->current_article_length );
        $section_chars = $config['section_chars'];

        return "以下のセクションの本文を、Markdown形式で詳細に執筆してください。

セクション: ## {$section_title}
{$subsections_text}{$context_text}{$additional_text}

【事実性の厳守】
- 事実に基づく内容のみを記述すること
- 憶測・推測・断定できない表現は禁止

要件:
- 各サブセクションは{$section_chars}文字で詳細に
- 具体例や手順を含める（コード例は必要に応じて）
- コードは必ず <CODE lang=\"言語\">... </CODE> で囲む（```は禁止）
- <CODE>内に本文や見出しを混入させない
- 読者が実行できる内容を提供
- 技術的に正確な情報のみ

出力はMarkdown形式のみ。余計な説明不要。セクションタイトル(##)から開始してください。";
    }

    /**
     * セクションのH2見出しをアウトラインに合わせて正規化
     *
     * @param string $section_md セクション本文
     * @param string $section_title H2タイトル
     * @return string
     */
    private function normalize_section_heading( $section_md, $section_title ) {
        $section_md = ltrim( $section_md );
        $expected_heading = "## {$section_title}";

        if ( preg_match( '/^##\s+.+$/mu', $section_md, $matches, PREG_OFFSET_CAPTURE ) ) {
            $first_heading = trim( $matches[0][0] );
            if ( $first_heading === $expected_heading ) {
                return $section_md;
            }
            // 最初のH2を置換
            $section_md = preg_replace( '/^##\s+.+$/mu', $expected_heading, $section_md, 1 );
            return $section_md;
        }

        return $expected_heading . "\n\n" . $section_md;
    }

    /**
     * MarkdownからYAML frontmatterとセクション構造を解析
     *
     * @param string $markdown Markdownテキスト
     * @return array ['meta' => array, 'body' => string, 'sections' => array]
     */
    public function parse_markdown_frontmatter( $markdown ) {
        if ( empty( $markdown ) || ! is_string( $markdown ) ) {
            return array(
                'meta' => array(),
                'body' => '',
                'sections' => array(),
            );
        }

        $meta = array();
        $body = $markdown;
        $sections = array();

        // YAML frontmatterを抽出
        if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n/su', $markdown, $matches ) ) {
            $frontmatter = $matches[1];
            $body = trim( substr( $markdown, strlen( $matches[0] ) ) );

            // 簡易YAML解析（key: value形式）
            $lines = explode( "\n", $frontmatter );
            foreach ( $lines as $line ) {
                $line = trim( $line );

                // title, slug, excerpt, meta_description - シングルクォート、ダブルクォート両対応
                if ( preg_match( '/^(title|slug|excerpt|meta_description):\s*["\']([^"\']*)["\']/', $line, $m ) ) {
                    $meta[ $m[1] ] = $m[2];
                } elseif ( preg_match( '/^(title|slug|excerpt|meta_description):\s*(.+)$/u', $line, $m ) ) {
                    $value = trim( $m[2] );
                    // 前後のクォートを削除（シングル/ダブル両対応）
                    if ( preg_match( '/^["\'](.+)["\']$/', $value, $quote_match ) ) {
                        $value = $quote_match[1];
                    }
                    $meta[ $m[1] ] = $value;
                }

                // keywords配列
                if ( preg_match( '/^keywords:\s*\[(.*)\]/', $line, $m ) ) {
                    $keywords_str = $m[1];
                    $keywords = array();
                    if ( preg_match_all( '/["\']([^"\']*)["\']/', $keywords_str, $kw_matches ) ) {
                        $keywords = $kw_matches[1];
                    }
                    $meta['keywords'] = $keywords;
                }
            }
        }

        // セクション構造を解析（H1, H2, H3）
        $lines = explode( "\n", $body );
        $current_section = null;
        $current_subsection = null;
        $h1_title = null;

        foreach ( $lines as $line ) {
            $line = trim( $line );

            // H1（タイトル候補）
            if ( preg_match( '/^#\s+(.+)$/u', $line, $m ) ) {
                if ( $h1_title === null ) {
                    $h1_title = trim( $m[1] );
                }
            }

            // H2セクション
            elseif ( preg_match( '/^##\s+(.+)$/u', $line, $m ) ) {
                if ( $current_section !== null ) {
                    $sections[] = $current_section;
                }
                $current_section = array(
                    'title' => trim( $m[1] ),
                    'subsections' => array(),
                );
                $current_subsection = null;
            }

            // H3サブセクション
            elseif ( preg_match( '/^###\s+(.+)$/u', $line, $m ) ) {
                if ( $current_section !== null ) {
                    $current_subsection = array(
                        'title' => trim( $m[1] ),
                        'points' => array(),
                    );
                    $current_section['subsections'][] = $current_subsection;
                }
            }

            // リストポイント
            elseif ( preg_match( '/^-\s+(.+)$/u', $line, $m ) ) {
                if ( $current_subsection !== null ) {
                    $last_idx = count( $current_section['subsections'] ) - 1;
                    $current_section['subsections'][ $last_idx ]['points'][] = trim( $m[1] );
                }
            }
        }

        // 最後のセクションを追加
        if ( $current_section !== null ) {
            $sections[] = $current_section;
        }

        // タイトル抽出のフォールバック処理
        if ( empty( $meta['title'] ) ) {
            if ( ! empty( $h1_title ) ) {
                // H1からのタイトル抽出を優先
                $meta['title'] = $h1_title;
            } elseif ( ! empty( $sections ) && ! empty( $sections[0]['title'] ) ) {
                // 最初のH2セクションをタイトルとして使用（ただしトピックベースの自動生成を推奨）
                $first_section_title = $sections[0]['title'];
                // 一般的な構成セクション名を除外（目次、はじめに等）
                if ( ! preg_match( '/^(目次|はじめに|概要|はじめにあたって|まとめ|結論|参考文献|付録)$/u', $first_section_title ) ) {
                    $meta['title'] = $first_section_title;
                    error_log( 'Blog Poster: Using first section as title: ' . $first_section_title );
                }
            }
        }

        // 最終的にタイトルが空の場合は「Untitled」ではなくトピック情報を保持
        if ( empty( $meta['title'] ) ) {
            $meta['title'] = 'Untitled Article';
        }

        return array(
            'meta' => $meta,
            'body' => $body,
            'sections' => $sections,
        );
    }

    /**
     * YAML frontmatterを構築
     * シングル/ダブルクォート両対応、日本語文字セーフ
     *
     * @param array $meta メタデータ
     * @return string frontmatter文字列
     */
    private function build_frontmatter( $meta ) {
        $lines = array( '---' );

        if ( isset( $meta['title'] ) ) {
            // シングルクォートを使用（日本語文字との相性が良い）
            $escaped_title = str_replace( "'", "\\'", $meta['title'] );
            $lines[] = "title: '" . $escaped_title . "'";
        }

        if ( isset( $meta['slug'] ) ) {
            $escaped_slug = str_replace( "'", "\\'", $meta['slug'] );
            $lines[] = "slug: '" . $escaped_slug . "'";
        }

        if ( isset( $meta['excerpt'] ) ) {
            $escaped_excerpt = str_replace( "'", "\\'", $meta['excerpt'] );
            $lines[] = "excerpt: '" . $escaped_excerpt . "'";
        }

        if ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ) {
            $keywords_str = array();
            foreach ( $meta['keywords'] as $kw ) {
                $escaped_kw = str_replace( "'", "\\'", $kw );
                $keywords_str[] = "'" . $escaped_kw . "'";
            }
            $lines[] = 'keywords: [' . implode( ', ', $keywords_str ) . ']';
        }

        $lines[] = '---';

        return implode( "\n", $lines );
    }

    /**
     * セクションからコンテキスト要約を抽出
     *
     * @param string $section_md セクションのMarkdown
     * @return string コンテキスト要約（300文字程度）
     */
    public function extract_section_context( $section_md ) {
        // 最初の300文字を抽出（見出しやコードブロックを除外）
        $lines = explode( "\n", $section_md );
        $text_lines = array();
        $in_code_block = false;

        foreach ( $lines as $line ) {
            // コードブロック制御
            if ( preg_match( '/^```/u', $line ) ) {
                $in_code_block = ! $in_code_block;
                continue;
            }

            if ( $in_code_block ) {
                continue;
            }

            // 見出し行をスキップ
            if ( preg_match( '/^#{2,}/u', $line ) ) {
                continue;
            }

            $line = trim( $line );
            if ( ! empty( $line ) ) {
                $text_lines[] = $line;
            }
        }

        $context = implode( ' ', $text_lines );

        // 300文字に制限
        if ( mb_strlen( $context ) > 300 ) {
            $context = mb_substr( $context, 0, 300 ) . '...';
        }

        return $context;
    }

    /**
     * セクションのコードブロック整合性を検証・修正
     *
     * @param string $markdown セクションのMarkdown
     * @return string 修正後のMarkdown
     */
    private function validate_section_code_blocks( $markdown ) {
        // コードブロックの開始・終了をカウント
        $open_count = preg_match_all( '/```/mu', $markdown, $matches );

        // 奇数個の場合、最後に閉じタグを追加
        if ( $open_count % 2 !== 0 ) {
            error_log( 'Blog Poster: Unclosed code block detected in section, auto-fixing' );
            $markdown .= "\n```\n";
        }

        // セクション末尾のコードブロックチェック
        // 末尾が```で終わる場合、次のセクションとの干渉を防ぐため改行を追加
        if ( preg_match( '/```\s*$/mu', $markdown ) ) {
            $markdown .= "\n";
        }

        return $markdown;
    }

    /**
     * Markdownの最小限の修正
     *
     * @param string $markdown Markdownテキスト
     * @return string 修正後のMarkdown
     */
    public function postprocess_markdown( $markdown ) {
        // 1. コードブロック開始/終了の一致確認（柔軟な正規表現）
        $open_count = preg_match_all( '/```[\w]*/mu', $markdown, $open_matches );
        $close_count = preg_match_all( '/```\s*$|```\s*\n/mu', $markdown, $close_matches );

        error_log( "Blog Poster: Code block check - Open: {$open_count}, Close: {$close_count}" );

        // 開始が終了より多い場合、末尾に```を追加
        if ( $open_count > $close_count ) {
            $diff = $open_count - $close_count;
            for ( $i = 0; $i < $diff; $i++ ) {
                $markdown .= "\n```\n";
            }
            error_log( "Blog Poster: Added {$diff} closing code block(s)" );
        }

        // 2. 連続する空行を2つまでに制限
        $markdown = preg_replace( "/\n{4,}/u", "\n\n\n", $markdown );

        // 3. 末尾の余分な空白削除
        $markdown = rtrim( $markdown ) . "\n";

        return $markdown;
    }

    /**
     * 生成後の全文パースでコードブロックの混入を修正
     *
     * @param string $markdown Markdownテキスト
     * @return string 修正後のMarkdown
     */
    public function normalize_code_blocks_after_generation( $markdown ) {
        $converted = 0;

        $markdown = preg_replace_callback(
            '/```([^\n]*)\R(.*?)\R```/su',
            function( $matches ) use ( &$converted ) {
                $language = trim( $matches[1] );
                $language_normalized = strtolower( $language );
                $content  = $matches[2];

                $convertible_language = '' === $language || in_array( $language_normalized, array( 'text', 'plain', 'plaintext' ), true );
                if ( ! $convertible_language ) {
                    return $matches[0];
                }

                if ( '' === trim( $content ) ) {
                    $converted++;
                    return '';
                }

                if ( $this->is_prose_like_code_block( $content ) ) {
                    $converted++;
                    $lines = preg_split( '/\R/u', $content );
                    $first_nonempty_index = null;
                    foreach ( $lines as $index => $line ) {
                        if ( '' !== trim( $line ) ) {
                            $first_nonempty_index = $index;
                            break;
                        }
                    }
                    if ( null !== $first_nonempty_index && 0 === strcasecmp( trim( $lines[ $first_nonempty_index ] ), 'text' ) ) {
                        unset( $lines[ $first_nonempty_index ] );
                        $lines = array_values( $lines );
                    }
                    $content = implode( "\n", $lines );
                    return rtrim( $content ) . "\n";
                }

                return $matches[0];
            },
            $markdown
        );

        if ( $converted > 0 ) {
            error_log( "Blog Poster: Converted {$converted} prose-like code block(s) to text" );
        }

        return $markdown;
    }

    /**
     * 生成結果が途中で切れている可能性を判定
     *
     * @param string $markdown Markdownテキスト
     * @return bool 途中切れの疑いがある場合true
     */
    public function is_truncated_markdown( $markdown ) {
        $markdown = trim( $markdown );
        if ( '' === $markdown ) {
            return false;
        }

        $lines = preg_split( '/\R/u', $markdown );
        $last_line = '';
        for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
            $candidate = trim( $lines[ $i ] );
            if ( '' !== $candidate ) {
                $last_line = $candidate;
                break;
            }
        }

        if ( '' === $last_line ) {
            return false;
        }

        if ( preg_match( '/^#{1,6}\s+/u', $last_line ) ) {
            return false;
        }

        if ( preg_match( '/^(\d+[\.)]\s+|[-*+]\s+)/u', $last_line ) ) {
            return false;
        }

        if ( preg_match( '/[`>]$/u', $last_line ) ) {
            return false;
        }

        if ( preg_match( '/[。．.!?！？…"]$/u', $last_line ) ) {
            return false;
        }

        if ( preg_match( '/[」』）】］)]$/u', $last_line ) ) {
            return false;
        }

        $tail_text = mb_substr( $last_line, max( 0, mb_strlen( $last_line ) - 40 ) );
        if ( preg_match( '/[。．.!?！？…]/u', $tail_text ) ) {
            return false;
        }

        if ( mb_strlen( $last_line ) < 20 ) {
            return false;
        }

        error_log( 'Blog Poster: Truncation suspected. Last line: ' . $last_line );
        return true;
    }

    /**
     * 本文として扱うべきコードブロックか判定
     *
     * @param string $content コードブロック内容
     * @return bool
     */
    private function is_prose_like_code_block( $content ) {
        $has_japanese = preg_match( '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $content );
        $has_sentence = preg_match( '/[。！？]/u', $content ) || preg_match( '/(です|ます|こと|ため|例えば|なお)/u', $content );
        $has_heading  = preg_match( '/^\s*#{1,6}\s+/mu', $content );
        $has_list     = preg_match( '/^\s*[-*+]\s+/mu', $content );

        $content_for_code = preg_replace( '/\{\{[^}]+\}\}/u', '', $content );
        $has_code_tokens = preg_match(
            '/[{};]|=>|\b(function|const|let|var|class|import|export|return|public|private|protected|if|else|for|while|switch|case|def|echo|print|console|new)\b/u',
            $content_for_code
        );
        $has_code_indent = preg_match( '/^\s{4,}\S/mu', $content );

        $has_prose_signal = $has_japanese || $has_sentence || $has_heading || $has_list;
        $has_code_signal  = $has_code_tokens || $has_code_indent;

        return $has_prose_signal && ! $has_code_signal;
    }


    /**
     * 記事の検証
     *
     * @param string $content 記事内容
     * @return array 検証結果
     */
    public function validate_article( $content ) {
        $issues = array();

        // 最小文字数チェック
        $char_count = mb_strlen( strip_tags( $content ) );
        if ( $char_count < 1000 ) {
            $issues[] = array(
                'type' => 'warning',
                'message' => sprintf( __( '記事が短すぎます（%d文字）。少なくとも1000文字以上を推奨します。', 'blog-poster' ), $char_count ),
            );
        }

        // コードブロックの検証
        $code_validation = $this->validate_code_blocks( $content );
        if ( ! $code_validation['valid'] ) {
            $issues[] = array(
                'type' => 'error',
                'message' => $code_validation['message'],
            );
        }

        return array(
            'valid' => empty( $issues ) || ! $this->has_errors( $issues ),
            'issues' => $issues,
            'char_count' => $char_count,
        );
    }

    /**
     * エラーが含まれているかチェック
     *
     * @param array $issues 問題配列
     * @return bool
     */
    private function has_errors( $issues ) {
        foreach ( $issues as $issue ) {
            if ( $issue['type'] === 'error' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * コードブロックの検証
     *
     * @param string $content 記事内容
     * @return array 検証結果
     */
    public function validate_code_blocks( $content ) {
        $open_count = preg_match_all( '/```\w*/mu', $content, $open_matches );
        $close_count = preg_match_all( '/```\s*$/mu', $content, $close_matches );

        if ( $open_count !== $close_count ) {
            return array(
                'valid' => false,
                'message' => sprintf(
                    __( 'コードブロックの開始と終了が一致しません（開始: %d, 終了: %d）。', 'blog-poster' ),
                    $open_count,
                    $close_count
                ),
            );
        }

        return array(
            'valid' => true,
            'message' => __( 'コードブロックは正常です。', 'blog-poster' ),
        );
    }

    /**
     * アイキャッチ画像を生成
     *
     * @param string $topic トピック
     * @return array|WP_Error 画像URLまたはエラー
     */
    public function generate_featured_image( $topic ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        // 画像生成に対応しているプロバイダーのみ
        if ( ! method_exists( $client, 'generate_image' ) ) {
            return new WP_Error(
                'not_supported',
                __( '現在のAIプロバイダーは画像生成に対応していません。', 'blog-poster' )
            );
        }

        $prompt = "A professional blog featured image for an article about: {$topic}. Modern, clean, and visually appealing.";

        try {
            $result = $client->generate_image( $prompt );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return array(
                'success' => true,
                'url' => $result['url'],
            );

        } catch ( Exception $e ) {
            error_log( 'Blog Poster: Featured image generation error: ' . $e->getMessage() );
            return new WP_Error( 'image_error', $e->getMessage() );
        }
    }

    /**
     * 記事のJSON表現を検証（後方互換性）
     *
     * @param array $article_json 記事JSON
     * @return array 検証結果
     */
    public function validate_article_json( $article_json ) {
        return array(
            'valid' => false,
            'message' => __( 'JSON形式は非推奨です。Markdown形式を使用してください。', 'blog-poster' ),
        );
    }

    /**
     * 記事JSONをHTMLにレンダリング（後方互換性）
     *
     * @param array $article_json 記事JSON
     * @return string|WP_Error HTML
     */
    public function render_article_json_to_html( $article_json ) {
        return new WP_Error(
            'deprecated',
            __( 'この機能は廃止されました。Markdown形式を使用してください。', 'blog-poster' )
        );
    }
}
