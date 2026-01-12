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
     * AIクライアントを取得
     *
     * @return object|WP_Error AIクライアントまたはエラー
     */
    private function get_ai_client() {
        $settings = get_option( 'blog_poster_settings', array() );
        $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude';

        $api_key = '';
        $model = '';

        switch ( $provider ) {
            case 'gemini':
                $api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
                $model = isset( $settings['default_model']['gemini'] ) ? $settings['default_model']['gemini'] : 'gemini-3-flash';
                $client = new Blog_Poster_Gemini_Client( $api_key, $model, $settings );
                break;

            case 'claude':
                $api_key = isset( $settings['claude_api_key'] ) ? $settings['claude_api_key'] : '';
                $model = isset( $settings['default_model']['claude'] ) ? $settings['default_model']['claude'] : 'claude-3-5-sonnet-20241022';
                $client = new Blog_Poster_Claude_Client( $api_key, $model, $settings );
                break;

            case 'openai':
            default:
                $api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
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

        // 5. HTML変換
        $html = $this->markdown_to_html( $final_md );

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
    public function generate_outline_markdown( $topic, $additional_instructions = '' ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $prompt = $this->build_outline_prompt( $topic, $additional_instructions );

        try {
            $response = $client->generate_content( $prompt, array( 'max_tokens' => 4000 ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $outline_md = $response['content'];

            // YAML frontmatterとセクション構造を解析
            $parsed = $this->parse_markdown_frontmatter( $outline_md );

            return array(
                'success' => true,
                'outline_md' => $outline_md,
                'meta' => $parsed['meta'],
                'sections' => $parsed['sections'],
            );

        } catch ( Exception $e ) {
            error_log( 'Blog Poster: Outline generation error: ' . $e->getMessage() );
            return new WP_Error( 'outline_error', $e->getMessage() );
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

        try {
            $response = $client->generate_content( $prompt, array( 'max_tokens' => 8000 ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $section_md = $response['content'];

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

        return "あなたは日本語ブログ記事のプロフェッショナルライターです。

トピック: {$topic}{$additional_text}

以下の形式で記事のアウトラインを作成してください:

---
title: \"記事タイトル（SEO最適化、30-60文字）\"
slug: \"url-friendly-slug\"
excerpt: \"記事の抜粋（120-160文字）\"
keywords: [\"キーワード1\", \"キーワード2\", \"キーワード3\"]
---

## セクション1のタイトル

### サブセクション1-1
- キーポイント

### サブセクション1-2
- キーポイント

## セクション2のタイトル

### サブセクション2-1
- キーポイント

要件:
- H2セクションを5-7個作成
- 各H2の下にH3を2-4個配置
- 読者の課題解決を意識した構成
- 具体例やコード例を含むセクション構成

出力はMarkdown形式のみ。説明文は不要です。";
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

        return "以下のセクションの本文を、Markdown形式で詳細に執筆してください。

セクション: ## {$section_title}
{$subsections_text}{$context_text}{$additional_text}

要件:
- 各サブセクションは300-500文字で詳細に
- 具体例、手順、コード例を必ず含める
- コードブロックは\`\`\`言語名\\nコード\\n\`\`\`形式で
- 読者が実行できる内容を提供
- 技術的に正確な情報のみ

出力はMarkdown形式のみ。余計な説明不要。セクションタイトル(##)から開始してください。";
    }

    /**
     * MarkdownからYAML frontmatterとセクション構造を解析
     *
     * @param string $markdown Markdownテキスト
     * @return array ['meta' => array, 'body' => string, 'sections' => array]
     */
    private function parse_markdown_frontmatter( $markdown ) {
        $meta = array();
        $body = $markdown;
        $sections = array();

        // YAML frontmatterを抽出
        if ( preg_match( '/^---\s*\n(.*?)\n---\s*\n/s', $markdown, $matches ) ) {
            $frontmatter = $matches[1];
            $body = trim( substr( $markdown, strlen( $matches[0] ) ) );

            // 簡易YAML解析（key: value形式）
            $lines = explode( "\n", $frontmatter );
            foreach ( $lines as $line ) {
                $line = trim( $line );

                // title, slug, excerpt
                if ( preg_match( '/^(title|slug|excerpt):\s*"([^"]*)"/', $line, $m ) ) {
                    $meta[ $m[1] ] = $m[2];
                } elseif ( preg_match( '/^(title|slug|excerpt):\s*(.+)$/', $line, $m ) ) {
                    $meta[ $m[1] ] = trim( $m[2] );
                }

                // keywords配列
                if ( preg_match( '/^keywords:\s*\[(.*)\]/', $line, $m ) ) {
                    $keywords_str = $m[1];
                    $keywords = array();
                    if ( preg_match_all( '/"([^"]*)"/', $keywords_str, $kw_matches ) ) {
                        $keywords = $kw_matches[1];
                    }
                    $meta['keywords'] = $keywords;
                }
            }
        }

        // セクション構造を解析（H2とH3）
        $lines = explode( "\n", $body );
        $current_section = null;
        $current_subsection = null;

        foreach ( $lines as $line ) {
            $line = trim( $line );

            // H2セクション
            if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
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
            elseif ( preg_match( '/^###\s+(.+)$/', $line, $m ) ) {
                if ( $current_section !== null ) {
                    $current_subsection = array(
                        'title' => trim( $m[1] ),
                        'points' => array(),
                    );
                    $current_section['subsections'][] = $current_subsection;
                }
            }

            // リストポイント
            elseif ( preg_match( '/^-\s+(.+)$/', $line, $m ) ) {
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

        return array(
            'meta' => $meta,
            'body' => $body,
            'sections' => $sections,
        );
    }

    /**
     * YAML frontmatterを構築
     *
     * @param array $meta メタデータ
     * @return string frontmatter文字列
     */
    private function build_frontmatter( $meta ) {
        $lines = array( '---' );

        if ( isset( $meta['title'] ) ) {
            $lines[] = 'title: "' . str_replace( '"', '\\"', $meta['title'] ) . '"';
        }

        if ( isset( $meta['slug'] ) ) {
            $lines[] = 'slug: "' . str_replace( '"', '\\"', $meta['slug'] ) . '"';
        }

        if ( isset( $meta['excerpt'] ) ) {
            $lines[] = 'excerpt: "' . str_replace( '"', '\\"', $meta['excerpt'] ) . '"';
        }

        if ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ) {
            $keywords_str = array();
            foreach ( $meta['keywords'] as $kw ) {
                $keywords_str[] = '"' . str_replace( '"', '\\"', $kw ) . '"';
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
    private function extract_section_context( $section_md ) {
        // 最初の300文字を抽出（見出しやコードブロックを除外）
        $lines = explode( "\n", $section_md );
        $text_lines = array();
        $in_code_block = false;

        foreach ( $lines as $line ) {
            // コードブロック制御
            if ( preg_match( '/^```/', $line ) ) {
                $in_code_block = ! $in_code_block;
                continue;
            }

            if ( $in_code_block ) {
                continue;
            }

            // 見出し行をスキップ
            if ( preg_match( '/^#{2,}/', $line ) ) {
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
     * Markdownの最小限の修正
     *
     * @param string $markdown Markdownテキスト
     * @return string 修正後のMarkdown
     */
    private function postprocess_markdown( $markdown ) {
        // 1. コードブロック開始/終了の一致確認
        $open_count = preg_match_all( '/^```\w*/m', $markdown, $open_matches );
        $close_count = preg_match_all( '/^```\s*$/m', $markdown, $close_matches );

        error_log( "Blog Poster: Code block check - Open: {$open_count}, Close: {$close_count}" );

        // 開始が終了より多い場合、末尾に```を追加
        if ( $open_count > $close_count ) {
            $diff = $open_count - $close_count;
            for ( $i = 0; $i < $diff; $i++ ) {
                $markdown .= "\n```";
            }
            error_log( "Blog Poster: Added {$diff} closing code block(s)" );
        }

        // 2. 連続する空行を2つまでに制限
        $markdown = preg_replace( "/\n{4,}/", "\n\n\n", $markdown );

        // 3. 末尾の余分な空白削除
        $markdown = rtrim( $markdown ) . "\n";

        return $markdown;
    }

    /**
     * MarkdownをHTMLに変換
     *
     * @param string $markdown Markdownテキスト
     * @return string HTML
     */
    public function markdown_to_html( $markdown ) {
        if ( ! class_exists( 'Parsedown' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/parsedown/Parsedown.php';
        }

        $parsedown = new Parsedown();
        $parsedown->setSafeMode( false );
        $html = $parsedown->text( $markdown );

        // コードブロックのシンタックスハイライトクラス追加
        $html = $this->add_code_block_classes( $html );

        return $html;
    }

    /**
     * コードブロックにクラスを追加
     *
     * @param string $html HTML
     * @return string 修正後のHTML
     */
    private function add_code_block_classes( $html ) {
        // <pre><code class="language-php"> 形式に変換
        $html = preg_replace_callback(
            '/<pre><code class="([^"]+)">/',
            function( $matches ) {
                $lang = $matches[1];
                return '<pre><code class="language-' . esc_attr( $lang ) . ' hljs">';
            },
            $html
        );

        return $html;
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
        $open_count = preg_match_all( '/```\w*/m', $content, $open_matches );
        $close_count = preg_match_all( '/```\s*$/m', $content, $close_matches );

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
