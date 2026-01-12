<?php
/**
 * 記事生成管理クラス
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_Generator クラス
 */
class Blog_Poster_Generator {
    /**
     * JSON出力を有効にするか
     *
     * @return bool
     */
    private function use_json_output() {
        $settings = get_option( 'blog_poster_settings', array() );
        return ! isset( $settings['use_json_output'] ) || (bool) $settings['use_json_output'];
    }

    /**
     * JSON出力の有効状態を取得
     *
     * @return bool
     */
    public function is_json_output_enabled() {
        return $this->use_json_output();
    }

    /**
     * JSONレスポンスをパース
     *
     * @param string $response レスポンス
     * @return array|WP_Error
     */
    private function parse_json_blocks_response( $response ) {
        // JSONブロックを抽出（より厳密に）
        // 1. ```jsonブロックの開始から末尾の```までを抽出
        $json_str = preg_replace( '/^.*?```json\\s*\\n/s', '', $response );
        $json_str = preg_replace( '/\\n```.*$/s', '', $json_str );

        // 2. 前後の不要な空白を削除
        $json_str = trim( $json_str );
        $json_str = $this->sanitize_json_string( $json_str );
        $json_str = $this->sanitize_json_string( $json_str );

        // 3. デバッグログ（最初の200文字）
        error_log( 'Blog Poster: Parsing JSON response (first 200 chars): ' . substr( $json_str, 0, 200 ) );
        $this->log_json_debug_samples( 'response', $json_str );

        $data = $this->json_decode_safe( $json_str );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $fragment = $this->extract_json_fragment( $json_str );
            if ( '' !== $fragment && $fragment !== $json_str ) {
                $data = $this->json_decode_safe( $fragment );
            }
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $repaired = $this->repair_json_with_openai( $json_str );
            if ( '' !== $repaired ) {
                $data = $this->json_decode_safe( $repaired );
            }
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $error_msg = json_last_error_msg();
            error_log( 'Blog Poster: JSON parse error: ' . $error_msg );
            error_log( 'Blog Poster: Response length: ' . strlen( $json_str ) );
            error_log( 'Blog Poster: Response sample (first 500 chars): ' . substr( $json_str, 0, 500 ) );
            error_log( 'Blog Poster: Response sample (last 500 chars): ' . substr( $json_str, -500 ) );
            return new WP_Error( 'json_parse_error', 'JSONパースエラー: ' . $error_msg );
        }

        return $data;
    }

    /**
     * OpenAI Structured Outputs用のJSONスキーマ（アウトライン）
     *
     * @return array
     */
    private function get_openai_outline_schema() {
        return array(
            'type' => 'json_schema',
            'json_schema' => array(
                'name' => 'blog_poster_outline',
                'description' => 'Blog Poster outline JSON schema',
                'strict' => true,
                'schema' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'title', 'slug', 'meta_description', 'excerpt', 'target_reader', 'reader_goal', 'keywords', 'sections' ),
                    'properties' => array(
                        'title' => array( 'type' => 'string' ),
                        'slug' => array( 'type' => 'string' ),
                        'meta_description' => array( 'type' => 'string' ),
                        'excerpt' => array( 'type' => 'string' ),
                        'target_reader' => array( 'type' => 'string' ),
                        'reader_goal' => array( 'type' => 'string' ),
                        'keywords' => array(
                            'type' => 'array',
                            'items' => array( 'type' => 'string' ),
                        ),
                        'sections' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => array( 'h2', 'purpose', 'reader_state_before', 'reader_state_after', 'key_content', 'subsections' ),
                                'properties' => array(
                                    'h2' => array( 'type' => 'string' ),
                                    'purpose' => array( 'type' => 'string' ),
                                    'reader_state_before' => array( 'type' => 'string' ),
                                    'reader_state_after' => array( 'type' => 'string' ),
                                    'key_content' => array( 'type' => 'string' ),
                                    'subsections' => array(
                                        'type' => 'array',
                                        'items' => array(
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'required' => array( 'h3', 'content_type', 'key_points' ),
                                            'properties' => array(
                                                'h3' => array( 'type' => 'string' ),
                                                'content_type' => array( 'type' => 'string' ),
                                                'key_points' => array( 'type' => 'string' ),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * OpenAI Structured Outputs用のJSONスキーマ（ブロック）
     *
     * @return array
     */
    private function get_openai_blocks_schema() {
        return array(
            'type' => 'json_schema',
            'json_schema' => array(
                'name' => 'blog_poster_blocks',
                'description' => 'Blog Poster content blocks JSON schema',
                'strict' => true,
                'schema' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array( 'blocks' ),
                    'properties' => array(
                        'blocks' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => array( 'type' ),
                                'properties' => array(
                                    'type' => array( 'type' => 'string' ),
                                    'content' => array( 'type' => 'string' ),
                                    'language' => array( 'type' => 'string' ),
                                    'items' => array(
                                        'type' => 'array',
                                        'items' => array( 'type' => 'string' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * OpenAI Structured Outputsを使うか
     *
     * @param Blog_Poster_AI_Client $client AIクライアント
     * @return bool
     */
    private function should_use_openai_schema( $client ) {
        return ( $client instanceof Blog_Poster_OpenAI_Client )
            && 0 === strpos( $client->get_model(), 'gpt-5' );
    }

    /**
     * JSON文字列の制御文字を除去
     *
     * @param string $json_str JSON文字列
     * @return string
     */
    private function sanitize_json_string( $json_str ) {
        // BOM除去
        $json_str = preg_replace( '/^\xEF\xBB\xBF/', '', $json_str );
        // JSON文字列内の未エスケープ改行/タブをエスケープ、その他制御文字は無害化
        $result = '';
        $in_string = false;
        $escape = false;
        $length = strlen( $json_str );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $json_str[ $i ];
            $ord  = ord( $char );

            if ( $in_string ) {
                if ( $escape ) {
                    $escape = false;
                    $result .= $char;
                    continue;
                }

                if ( '\\' === $char ) {
                    $escape = true;
                    $result .= $char;
                    continue;
                }

                if ( '"' === $char ) {
                    $in_string = false;
                    $result .= $char;
                    continue;
                }

                if ( "\n" === $char ) {
                    $result .= '\\n';
                    continue;
                }
                if ( "\r" === $char ) {
                    $result .= '\\r';
                    continue;
                }
                if ( "\t" === $char ) {
                    $result .= '\\t';
                    continue;
                }

                if ( $ord < 0x20 ) {
                    $result .= sprintf( '\\u%04x', $ord );
                    continue;
                }

                $result .= $char;
                continue;
            }

            if ( '"' === $char ) {
                $in_string = true;
                $result .= $char;
                continue;
            }

            if ( $ord < 0x20 ) {
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * JSON修復（OpenAI fallback）
     *
     * @param string $json_str JSON文字列
     * @return string
     */
    private function repair_json_with_openai( $json_str ) {
        $settings = get_option( 'blog_poster_settings', array() );
        $api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
        if ( empty( $api_key ) ) {
            return '';
        }

        $client = new Blog_Poster_OpenAI_Client( $api_key, 'gpt-4.1', $settings );
        $prompt = "You are a JSON repair tool. Return a JSON object with a single key 'fixed' that contains the corrected JSON (as an object or array, not a string). Do not add explanations or markdown.\n\nINVALID JSON:\n" . $json_str;
        $response = $client->generate_text( $prompt, array( 'type' => 'json_object' ) );
        if ( ! $response['success'] ) {
            return '';
        }

        $fixed_payload = $this->sanitize_json_string( trim( $response['data'] ) );
        $decoded = $this->json_decode_safe( $fixed_payload );
        if ( is_array( $decoded ) && array_key_exists( 'fixed', $decoded ) ) {
            return wp_json_encode( $decoded['fixed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        $fragment = $this->extract_json_fragment( $fixed_payload );
        return '' !== $fragment ? $fragment : $fixed_payload;
    }

    /**
     * UTF-8不正を許容したJSONデコード
     *
     * @param string $json_str JSON文字列
     * @return array|null
     */
    private function json_decode_safe( $json_str ) {
        $flags = 0;
        if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        return json_decode( $json_str, true, 512, $flags );
    }

    /**
     * JSON文字列の先頭/末尾を16進ダンプで記録
     *
     * @param string $label ラベル
     * @param string $json_str JSON文字列
     * @return void
     */
    private function log_json_debug_samples( $label, $json_str ) {
        $head = substr( $json_str, 0, 200 );
        $tail = substr( $json_str, -200 );
        error_log( 'Blog Poster: JSON ' . $label . ' head hex: ' . bin2hex( $head ) );
        error_log( 'Blog Poster: JSON ' . $label . ' tail hex: ' . bin2hex( $tail ) );
    }

    /**
     * JSON断片を抽出
     *
     * @param string $text 入力テキスト
     * @return string
     */
    private function extract_json_fragment( $text ) {
        $start = null;
        $length = strlen( $text );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $text[ $i ];
            if ( '{' === $char || '[' === $char ) {
                $start = $i;
                break;
            }
        }

        if ( null === $start ) {
            return '';
        }

        $stack = array();
        $in_string = false;
        $escape = false;

        for ( $i = $start; $i < $length; $i++ ) {
            $char = $text[ $i ];

            if ( $in_string ) {
                if ( $escape ) {
                    $escape = false;
                    continue;
                }
                if ( '\\\\' === $char ) {
                    $escape = true;
                    continue;
                }
                if ( '"' === $char ) {
                    $in_string = false;
                }
                continue;
            }

            if ( '"' === $char ) {
                $in_string = true;
                continue;
            }

            if ( '{' === $char || '[' === $char ) {
                $stack[] = $char;
                continue;
            }

            if ( '}' === $char || ']' === $char ) {
                array_pop( $stack );
                if ( empty( $stack ) ) {
                    return substr( $text, $start, $i - $start + 1 );
                }
            }
        }

        return '';
    }

    /**
     * JSONブロックを検証
     *
     * @param array $article_json JSONデータ
     * @return array
     */
    public function validate_article_json( $article_json ) {
        $issues = array();

        if ( ! is_array( $article_json ) || ! isset( $article_json['blocks'] ) || ! is_array( $article_json['blocks'] ) ) {
            return array(
                'valid'  => false,
                'issues' => array( 'blocks が不正です。' ),
            );
        }

        $allowed_types = array( 'h2', 'h3', 'text', 'code', 'list' );

        foreach ( $article_json['blocks'] as $index => $block ) {
            if ( ! isset( $block['type'] ) || ! in_array( $block['type'], $allowed_types, true ) ) {
                error_log( 'Blog Poster: Invalid block at index ' . $index . ': ' . json_encode( $block, JSON_UNESCAPED_UNICODE ) );
                $issues[] = '不正なブロック種別: ' . ( $block['type'] ?? 'unknown' ) . ' (index: ' . $index . ')';
                continue;
            }

            if ( in_array( $block['type'], array( 'h2', 'h3', 'text' ), true ) ) {
                if ( empty( $block['content'] ) ) {
                    $issues[] = 'content が空です (index: ' . $index . ')';
                }
            } elseif ( 'code' === $block['type'] ) {
                if ( empty( $block['content'] ) ) {
                    $issues[] = 'code.content が空です (index: ' . $index . ')';
                }
                if ( empty( $block['language'] ) ) {
                    $issues[] = 'code.language が空です (index: ' . $index . ')';
                }
                if ( isset( $block['content'] ) ) {
                    $code_text = $block['content'];
                    $has_md    = preg_match( '/^\s*#{1,6}\s/m', $code_text )
                        || preg_match( '/^\s*[-*]\s+/m', $code_text )
                        || preg_match( '/^\s*\d+\.\s+/m', $code_text );
                    $has_ja    = preg_match( '/[ぁ-んァ-ン一-龯]/u', $code_text );
                    $sentence_count = preg_match_all( '/[。！？]/u', $code_text );
                    $code_token_count  = preg_match_all( '/[;{}<>$=]/', $code_text );
                    $code_token_count += preg_match_all( '/\b(public|private|protected|return|const|let|var|import|export|function|class)\b/i', $code_text );
                    $looks_like_prose = $has_ja && $sentence_count >= 2 && $code_token_count < 2;

                    if ( $has_md || $looks_like_prose ) {
                        $issues[] = 'code.content に説明文が混入しています (index: ' . $index . ')';
                    }
                    if ( false !== strpos( $code_text, '```' ) ) {
                        $issues[] = 'code.content にバッククォートが含まれています (index: ' . $index . ')';
                    }
                }
            } elseif ( 'list' === $block['type'] ) {
                if ( empty( $block['items'] ) || ! is_array( $block['items'] ) ) {
                    $issues[] = 'list.items が不正です (index: ' . $index . ')';
                }
            }

            if ( isset( $block['content'] ) && is_string( $block['content'] ) && false !== strpos( $block['content'], '```' ) ) {
                $issues[] = 'content にバッククォートが含まれています (index: ' . $index . ')';
            }
        }

        return array(
            'valid'  => empty( $issues ),
            'issues' => $issues,
        );
    }

    /**
     * JSONブロックをHTMLに変換
     *
     * @param array $article_json JSONデータ
     * @return string
     */
    public function render_article_json_to_html( $article_json ) {
        if ( ! is_array( $article_json ) || ! isset( $article_json['blocks'] ) ) {
            return '';
        }

        $html_parts = array();

        foreach ( $article_json['blocks'] as $block ) {
            $type = isset( $block['type'] ) ? $block['type'] : '';
            switch ( $type ) {
                case 'h2':
                    $html_parts[] = '<h2>' . wp_kses_post( $block['content'] ) . '</h2>';
                    break;
                case 'h3':
                    $html_parts[] = '<h3>' . wp_kses_post( $block['content'] ) . '</h3>';
                    break;
                case 'text':
                    $html_parts[] = '<p>' . wp_kses_post( $block['content'] ) . '</p>';
                    break;
                case 'code':
                    $language = isset( $block['language'] ) ? sanitize_text_field( $block['language'] ) : 'text';
                    $code     = isset( $block['content'] ) ? $block['content'] : '';
                    $html_parts[] = '<pre><code class="language-' . esc_attr( $language ) . '">' . esc_html( $code ) . '</code></pre>';
                    break;
                case 'list':
                    if ( isset( $block['items'] ) && is_array( $block['items'] ) ) {
                        $items = array();
                        foreach ( $block['items'] as $item ) {
                            $items[] = '<li>' . esc_html( $item ) . '</li>';
                        }
                        $html_parts[] = '<ul>' . implode( '', $items ) . '</ul>';
                    }
                    break;
            }
        }

        return implode( "\n", $html_parts );
    }

    /**
     * AIクライアントを取得
     *
     * @return Blog_Poster_AI_Client|WP_Error
     */
    private function get_ai_client() {
        $settings = get_option( 'blog_poster_settings', array() );
        $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'openai';

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
                $model = isset( $settings['default_model']['claude'] ) ? $settings['default_model']['claude'] : 'claude-sonnet-4-5-20250514';
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
     * ブログ記事を生成（3フェーズ方式）
     *
     * @param string $topic トピック/キーワード
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    public function generate_article( $topic, $additional_instructions = '' ) {
        // Phase 1: アウトライン生成（最大3回試行）
        $outline = null;
        $max_outline_attempts = 3;

        for ( $i = 0; $i < $max_outline_attempts; $i++ ) {
            $outline = $this->generate_outline( $topic, $additional_instructions );

            if ( ! is_wp_error( $outline ) ) {
                break;
            }

            error_log( "Blog Poster: Outline generation attempt " . ( $i + 1 ) . " failed: " . $outline->get_error_message() );
        }

        if ( is_wp_error( $outline ) ) {
            return new WP_Error( 'outline_generation_failed', 'アウトライン生成に3回失敗しました。' );
        }

        // JSON方式
        if ( $this->use_json_output() ) {
            $intro_blocks = $this->generate_intro_blocks( $outline );
            if ( is_wp_error( $intro_blocks ) ) {
                return $intro_blocks;
            }

            $blocks           = $intro_blocks;
            $previous_summary = '';

            foreach ( $outline['sections'] as $section ) {
                $section_result = $this->generate_section_blocks( $section, $topic, $previous_summary, $additional_instructions );

                if ( is_wp_error( $section_result ) ) {
                    error_log( "Blog Poster: Section generation failed for " . $section['h2'] . ": " . $section_result->get_error_message() );
                    continue;
                }

                $blocks           = array_merge( $blocks, $section_result['blocks'] );
                $previous_summary = isset( $section_result['summary'] ) ? $section_result['summary'] : '';
            }

            $summary_blocks = $this->generate_summary_blocks( $outline, '' );
            if ( is_wp_error( $summary_blocks ) ) {
                return $summary_blocks;
            }

            $blocks = array_merge( $blocks, $summary_blocks );

            $article_data = array(
                'title'            => $outline['title'],
                'slug'             => $outline['slug'],
                'meta_description' => $outline['meta_description'],
                'excerpt'          => $outline['excerpt'],
                'content'          => wp_json_encode(
                    array(
                        'meta'   => array(
                            'title'            => $outline['title'],
                            'slug'             => $outline['slug'],
                            'meta_description' => $outline['meta_description'],
                            'excerpt'          => $outline['excerpt'],
                            'keywords'         => isset( $outline['keywords'] ) ? $outline['keywords'] : array(),
                        ),
                        'blocks' => $blocks,
                    ),
                    JSON_UNESCAPED_UNICODE
                ),
                'keywords'         => isset( $outline['keywords'] ) ? $outline['keywords'] : array(),
            );
        } else {
            // Phase 2: セクションごとに生成
            $sections_content = array();
            $previous_summary = '';

            foreach ( $outline['sections'] as $section ) {
                $section_result = $this->generate_section_content( $section, $topic, $previous_summary, $additional_instructions );

                if ( is_wp_error( $section_result ) ) {
                    error_log( "Blog Poster: Section generation failed for " . $section['h2'] . ": " . $section_result->get_error_message() );
                    continue;
                }

                $sections_content[] = $section_result['content'];
                $previous_summary = $section_result['summary'];
            }

            // Phase 3: 記事組み立て
            $article_data = $this->assemble_article( $outline, $sections_content, $topic );

            if ( is_wp_error( $article_data ) ) {
                return $article_data;
            }

            // コードブロック修正
            $article_data['content'] = $this->fix_code_blocks( $article_data['content'] );

            // Claude関連の虚偽記述をチェック
            $article_data['content'] = $this->fact_check_claude_references( $article_data['content'] );
        }

        return array(
            'success' => true,
            'article' => $article_data,
            'tokens' => isset( $article_data['tokens'] ) ? $article_data['tokens'] : 0,
            'model' => isset( $article_data['model'] ) ? $article_data['model'] : '',
        );
    }

    /**
     * Phase 1: アウトラインをJSON形式で生成
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error アウトラインデータまたはエラー
     */
    public function generate_outline( $topic, $additional_instructions = '' ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $prompt = <<<PROMPT
あなたは{$topic}に関する実務経験を持つテクニカルライターです。

以下のトピックについて、読者が実行できる高品質な記事のアウトラインをJSON形式で生成してください。

トピック: {$topic}

【アウトラインの要件】
1. 5-7個のH2セクションを作成
2. 各H2の下に2-4個のH3サブセクションを配置
3. 各セクションの目的と読者の状態変化を明記
4. 読者が「実行できる」ことを重視

【JSON出力形式】
{
  "title": "（50文字以内、具体的で魅力的なタイトル）",
  "slug": "（英数字とハイフンのみ）",
  "meta_description": "（120文字以内）",
  "excerpt": "（100-200文字）",
  "target_reader": "（ターゲット読者の具体像）",
  "reader_goal": "（この記事で達成できること）",
  "keywords": ["キーワード1", "キーワード2", "..."],
  "sections": [
    {
      "h2": "セクション見出し",
      "purpose": "このセクションの目的",
      "reader_state_before": "読者の現在の状態",
      "reader_state_after": "読者がこのセクションを読んだ後の状態",
      "key_content": "含めるべき重要な内容（具体例、コード、手順など）",
      "subsections": [
        {
          "h3": "サブセクション見出し",
          "content_type": "code/explanation/step-by-step",
          "key_points": "このサブセクションで伝えるべきポイント"
        }
      ]
    }
  ]
}

{$additional_instructions}

【Output Rules】
1. Output strictly valid JSON.
2. Escape all control characters inside strings (use \\n for newlines, \\t for tabs).
3. Do NOT include raw newlines or tabs inside string values.
4. The root element must be a single JSON object (not an array).

上記のJSON形式で、実行可能な記事のアウトラインを生成してください。
PROMPT;

        $response_format = $this->should_use_openai_schema( $client ) ? $this->get_openai_outline_schema() : null;
        $response = $client->generate_text( $prompt, $response_format );

        if ( ! $response['success'] ) {
            return new WP_Error( 'outline_generation_failed', $response['error'] );
        }

        // JSONをパース
        $outline_data = $this->parse_json_outline( $response['data'] );

        if ( is_wp_error( $outline_data ) ) {
            return $outline_data;
        }

        return $outline_data;
    }

    /**
     * JSON形式のアウトラインをパース
     *
     * @param string $response AI応答
     * @return array|WP_Error パース結果またはエラー
     */
    private function parse_json_outline( $response ) {
        // JSONブロックを抽出（より厳密に）
        // 1. ```jsonブロックの開始から末尾の```までを抽出
        $json_str = preg_replace( '/^.*?```json\\s*\\n/s', '', $response );
        $json_str = preg_replace( '/\\n```.*$/s', '', $json_str );

        // 2. 前後の不要な空白を削除
        $json_str = trim( $json_str );

        // 3. デバッグログ（最初の200文字）
        error_log( 'Blog Poster: Parsing JSON outline (first 200 chars): ' . substr( $json_str, 0, 200 ) );
        $this->log_json_debug_samples( 'outline', $json_str );

        $data = $this->json_decode_safe( $json_str );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $fragment = $this->extract_json_fragment( $json_str );
            if ( '' !== $fragment && $fragment !== $json_str ) {
                $data = $this->json_decode_safe( $fragment );
            }
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $repaired = $this->repair_json_with_openai( $json_str );
            if ( '' !== $repaired ) {
                $data = $this->json_decode_safe( $repaired );
            }
        }

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $error_msg = json_last_error_msg();
            error_log( 'Blog Poster: JSON parse error (outline): ' . $error_msg );
            error_log( 'Blog Poster: Outline response length: ' . strlen( $json_str ) );
            error_log( 'Blog Poster: Outline response sample (first 500 chars): ' . substr( $json_str, 0, 500 ) );
            error_log( 'Blog Poster: Outline response sample (last 500 chars): ' . substr( $json_str, -500 ) );
            return new WP_Error( 'json_parse_error', 'JSONパースエラー: ' . $error_msg );
        }

        // 配列で返ってきた場合は先頭要素を採用
        if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
            $data = $data[0];
        }

        // 必須フィールドの検証
        $required_fields = array( 'title', 'slug', 'meta_description', 'excerpt', 'sections' );
        foreach ( $required_fields as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                error_log( "Blog Poster: Missing required field in outline: {$field}" );
                return new WP_Error( 'missing_field', "必須フィールドが不足しています: {$field}" );
            }
        }

        return $data;
    }

    /**
     * Phase 2: 個別セクションのコンテンツを生成
     *
     * @param array  $section セクション情報
     * @param string $topic トピック
     * @param string $previous_summary 前セクションの要約
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    public function generate_section_content( $section, $topic, $previous_summary = '', $additional_instructions = '' ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $context = '';
        if ( ! empty( $previous_summary ) ) {
            $context = "【これまでの流れ】\n{$previous_summary}\n\n";
        }

        $subsections_list = '';
        if ( isset( $section['subsections'] ) ) {
            foreach ( $section['subsections'] as $sub ) {
                $subsections_list .= "### {$sub['h3']}\n";
                $subsections_list .= "タイプ: {$sub['content_type']}\n";
                $subsections_list .= "ポイント: {$sub['key_points']}\n\n";
            }
        }

        $prompt = <<<PROMPT
あなたは{$topic}に関する実務経験を持つテクニカルライターです。

{$context}以下のセクションの内容を、Markdown形式で具体的に記述してください。

【セクション情報】
見出し: {$section['h2']}
目的: {$section['purpose']}
読者の状態変化: {$section['reader_state_before']} → {$section['reader_state_after']}
含めるべき内容: {$section['key_content']}

【サブセクション構成】
{$subsections_list}

【記述要件】
1. 完全に動作するコード例を含める（省略なし、コピペで動く状態）
2. コードブロックは必ず```php、```javascript等の言語指定付きで記述
3. 各ステップの実行結果を説明
4. 抽象的な説明（「〜が重要です」）は禁止
5. 読者が実際に手を動かせる情報のみ

【コードブロックの記述ルール - 最重要】
このルールは絶対に守ってください：

【絶対禁止事項 - 違反厳禁】
以下の行為は絶対に禁止します。1つでも違反があればNG判定されます：

❌ コードブロック内にMarkdown見出し（#, ##, ###等）を絶対に含めない
❌ コードブロック内に箇条書き（-, *, 1. 等）を絶対に含めない
❌ コードブロック内に日本語の説明文を絶対に含めない（コメント以外）
❌ コードと説明文を同じブロック内に混在させない
❌ 開始タグ（```言語名）と終了タグ（```）の数を必ず一致させる

【正しいコードブロック構造】
1. コードブロック開始：```言語名
   - 例：```php、```javascript、```bash、```html
   - 3つのバッククォートの後に言語名を入力
   - バッククォートは3つ以上使用しない

2. コードブロック終了：```
   - 3つのバッククォートのみで閉じる
   - 同じ行に何も入力しない
   - 開始と終了は必ず1対1で対応させる

3. コードブロックの内容
   - コードのみを記述（純粋なプログラムコード）
   - コメント（//, /*, #等）は可
   - 説明文、見出し、箇条書きは絶対に含めない
   - コードとテキストは必ず別ブロックに分ける

4. 説明文の配置
   - コードブロックの外（前後）に必ず配置
   - コードブロックを```で閉じてから説明を書く
   - 説明後に新しいコードブロックを開く

5. 複数のコードブロック
   - 前のコードブロックを必ず```で閉じてから、新しいコードブロックを開く
   - ブロック間には必ず空行を入れる

6. 禁止事項の詳細
   - HTMLエンティティ化されたタグ（<!--?php、&lt;?php等）は使用しない
   - <?phpは必ず```php内に記述
   - バッククォートを4つ以上使用しない
   - 引用符（"や'）をバッククォートの直後に付けない

【正しいコードブロック記述例】
例1: PHPコード
```php
<?php
function example_function() {
    echo "Hello World";
}
```

例2: コードとテキストの混在
```javascript
// ブラウザのコンソールで実行
console.log("Hello");
```

ここで説明を書きます。

```javascript
// 次のコード例
console.log("World");
```

【修正方法 - 万が一ルール違反があった場合】
1. バッククォートが1-2個しかない場合：3つに増やす
2. バッククォートが4個以上の場合：3個に減らす
3. 閉じタグがない場合：末尾に```を追加
4. HTMLエンティティ化されている場合：元のタグに戻す

{$additional_instructions}

このセクションの内容をMarkdown形式で記述してください。
上記のコードブロック記述ルールを絶対に守ってください。
PROMPT;

        $response_format = $this->should_use_openai_schema( $client ) ? $this->get_openai_blocks_schema() : null;
        $response = $client->generate_text( $prompt, $response_format );

        if ( ! $response['success'] ) {
            return new WP_Error( 'section_generation_failed', $response['error'] );
        }

        $content = $response['data'];

        // このセクションの要約を生成（次のセクションへの文脈として使用）
        $summary = $this->generate_section_summary( $section['h2'], $content );

        return array(
            'content' => $content,
            'summary' => $summary,
        );
    }

    /**
     * Phase 2: セクションのJSONブロックを生成
     *
     * @param array  $section セクション情報
     * @param string $topic トピック
     * @param string $previous_summary 前セクションの要約
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error
     */
    public function generate_section_blocks( $section, $topic, $previous_summary = '', $additional_instructions = '' ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $context = '';
        if ( ! empty( $previous_summary ) ) {
            $context = "【これまでの流れ】\n{$previous_summary}\n\n";
        }

        $subsections_list = '';
        if ( isset( $section['subsections'] ) ) {
            foreach ( $section['subsections'] as $sub ) {
                $subsections_list .= "- {$sub['h3']} ({$sub['content_type']}): {$sub['key_points']}\n";
            }
        }

        $prompt = <<<PROMPT
あなたは{$topic}に関する実務経験を持つテクニカルライターです。

{$context}以下のセクション内容をJSONブロック配列で出力してください。

【セクション情報】
見出し: {$section['h2']}
目的: {$section['purpose']}
読者の状態変化: {$section['reader_state_before']} → {$section['reader_state_after']}
含めるべき内容: {$section['key_content']}

【サブセクション構成】
{$subsections_list}

【出力ルール - 絶対遵守】
- Markdown記号（#, -, ```, ** など）を使わない
- JSON以外のテキストは出力しない
- codeブロックのcontentはコードのみ（説明文を含めない）
- textはプレーンテキストのみ
- 先頭ブロックは必ずh2（見出し: {$section['h2']}）
- サブセクションごとにh3を入れて構成する
- 文字列内の改行・タブは必ず \\n / \\t にエスケープする
- 文字列内に生の改行やタブを入れない
- ルートは必ず { "blocks": [...] } のオブジェクト

【出力フォーマット - 厳密に従うこと】
{ "blocks": [ ... ] }

ブロック種別とフィールド名（必ず"content"フィールドを使用）:
- h2: { "type": "h2", "content": "見出しテキスト" }
- h3: { "type": "h3", "content": "見出しテキスト" }
- text: { "type": "text", "content": "段落テキスト" }
- code: { "type": "code", "language": "javascript", "content": "コード内容" }
- list: { "type": "list", "items": ["項目1", "項目2"] }

【重要】"text"フィールドは使用禁止。必ず"content"フィールドを使用すること

{$additional_instructions}
PROMPT;

        $response_format = $this->should_use_openai_schema( $client ) ? $this->get_openai_blocks_schema() : null;
        $response = $client->generate_text( $prompt, $response_format );

        if ( ! $response['success'] ) {
            return new WP_Error( 'api_error', $response['error'] ?? 'APIエラー' );
        }

        $data = $this->parse_json_blocks_response( $response['data'] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        if ( ! isset( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
            return new WP_Error( 'invalid_blocks', 'blocks が取得できませんでした。' );
        }

        return array(
            'blocks'  => $data['blocks'],
            'summary' => isset( $data['summary'] ) ? $data['summary'] : '',
        );
    }

    /**
     * セクションの要約を生成
     *
     * @param string $heading 見出し
     * @param string $content コンテンツ
     * @return string 要約
     */
    private function generate_section_summary( $heading, $content ) {
        // 簡易的な要約（最初の200文字程度）
        $text_only = strip_tags( $content );
        $text_only = preg_replace( '/```.*?```/s', '', $text_only );
        $summary = mb_substr( $text_only, 0, 200 ) . '...';

        return "{$heading}: {$summary}";
    }

    /**
     * Phase 3: 記事を組み立て
     *
     * @param array $outline アウトライン
     * @param array $sections_content セクションコンテンツ配列
     * @param string $topic トピック
     * @return array|WP_Error 記事データまたはエラー
     */
    private function assemble_article( $outline, $sections_content, $topic ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        // 導入文を生成
        $intro_prompt = <<<PROMPT
以下の記事の導入文を200-300文字で作成してください。

タイトル: {$outline['title']}
読者のゴール: {$outline['reader_goal']}

【導入文の要件】
- この記事で得られる具体的な成果を明記
- 読者の課題を明確にする
- 抽象的な説明を避ける
PROMPT;

        $response_format = $this->should_use_openai_schema( $client ) ? $this->get_openai_blocks_schema() : null;
        $intro_response = $client->generate_text( $intro_prompt, $response_format );
        $intro = $intro_response['success'] ? $intro_response['data'] : '';

        // まとめを生成
        $summary_prompt = <<<PROMPT
以下の記事のまとめを200-300文字で作成してください。

タイトル: {$outline['title']}

【まとめの要件】
- 要点を3-5個、箇条書きで記載
- 次のアクション提案を含める
- 読者が実行すべき具体的なステップを提示
PROMPT;

        $response_format = $this->should_use_openai_schema( $client ) ? $this->get_openai_blocks_schema() : null;
        $summary_response = $client->generate_text( $summary_prompt, $response_format );
        $summary = $summary_response['success'] ? $summary_response['data'] : '';

        // 本文を組み立て
        $body = $intro . "\n\n";
        $body .= implode( "\n\n", $sections_content );
        $body .= "\n\n## まとめ\n\n" . $summary;

        return array(
            'title' => $outline['title'],
            'slug' => $outline['slug'],
            'meta_description' => $outline['meta_description'],
            'excerpt' => $outline['excerpt'],
            'content' => $body,
            'keywords' => isset( $outline['keywords'] ) ? $outline['keywords'] : array(),
        );
    }

    /**
     * コードブロックを修正（v0.2.6 - 完全版）
     *
     * @param string $content コンテンツ
     * @return string 修正されたコンテンツ
     */
    public function fix_code_blocks( $content ) {
        // 修正前の検証
        $open_count_before = preg_match_all( '/```\w*/', $content, $open_matches_before );
        $close_count_before = preg_match_all( '/^```\s*$/m', $content, $close_matches_before );

        // パターン1: 記事全体を囲む```markdownブロックを削除
        $content = preg_replace( '/^```(?:markdown|md)\s*\n/', '', $content );
        $content = preg_replace( '/\n```\s*$/', '', $content );

        // パターン2: 5つ以上のバッククォートを3つに修正
        $content = preg_replace( '/`{4,}(php|javascript|js|html|css|bash|sh|python|py|java|c|cpp|go|rust|sql|json|xml|yaml|yml|markdown|md)?/', '```$1', $content );

        // パターン3: バッククォート1-2個で始まる壊れたコードブロックを修正
        $content = preg_replace( '/^`{1,2}(php|javascript|js|html|css|bash|sh|python|py|java|c|cpp|go|rust|sql|json|xml|yaml|yml)\s*$/m', '```$1', $content );

        // パターン4: 引用符付きのコードブロック開始を修正（```php" → ```php）
        $content = preg_replace( '/```(php|javascript|js|html|css|bash|sh|python|py|java|c|cpp|go|rust|sql|json|xml|yaml|yml)["\']/', '```$1', $content );

        // パターン5: HTMLエンティティ化されたタグを修正
        $html_entity_replacements = array(
            '<!--?php'  => '<?php',
            '?-->'      => '?>',
            '&lt;?php'  => '<?php',
            '?&gt;'     => '?>',
            '&lt;?'     => '<?',
            '&gt;'      => '>',
            '&lt;'      => '<',
            '&amp;'     => '&',
            '&quot;'    => '"',
        );
        $content = strtr( $content, $html_entity_replacements );

        // パターン6: 空のコードブロックを削除
        $content = preg_replace( '/```\w*\s*\n\s*```/m', '', $content );

        // パターン7: 行単位での構造チェックと修正（最重要）
        $lines = explode( "\n", $content );
        $fixed_lines = array();
        $in_code_block = false;

        for ( $i = 0; $i < count( $lines ); $i++ ) {
            $line = $lines[ $i ];
            $trimmed = trim( $line );

            // コードブロック開始を検出（```で始まり、言語指定がある場合とない場合）
            if ( preg_match( '/^```(\w*)/', $trimmed ) ) {
                // すでにコードブロック内なら前のブロックを閉じる
                if ( $in_code_block ) {
                    $fixed_lines[] = '```';
                }
                $in_code_block = true;
                $fixed_lines[] = $line;
            }
            // コードブロック終了を検出（```のみの行）
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

        $content = implode( "\n", $fixed_lines );

        // パターン8: 誤って囲まれた文章ブロックをアンラップ
        $lines         = explode( "\n", $content );
        $sanitized     = array();
        $block_lines   = array();
        $fence_open    = '';
        $in_code_block = false;

        foreach ( $lines as $line ) {
            if ( preg_match( '/^```/', trim( $line ) ) ) {
                if ( ! $in_code_block ) {
                    $in_code_block = true;
                    $fence_open    = $line;
                    $block_lines   = array();
                } else {
                    $block_text = implode( "\n", $block_lines );
                    $is_empty   = '' === trim( $block_text );
                    $has_code   = preg_match( '/[;{}<>$=]/', $block_text )
                        || preg_match( '/<\?php|\bfunction\b|\bclass\b|=>/i', $block_text );
                    $has_md     = preg_match( '/^\s{0,3}#{1,6}\s/m', $block_text )
                        || preg_match( '/^\s*[-*]\s+/m', $block_text )
                        || preg_match( '/^\s*\d+\.\s+/m', $block_text );
                    $has_ja     = preg_match( '/[ぁ-んァ-ン一-龯]/u', $block_text );

                    if ( $is_empty || ( $has_md || ( $has_ja && ! $has_code ) ) ) {
                        $sanitized = array_merge( $sanitized, $block_lines );
                    } else {
                        $sanitized[] = $fence_open;
                        $sanitized   = array_merge( $sanitized, $block_lines );
                        $sanitized[] = '```';
                    }

                    $in_code_block = false;
                    $fence_open    = '';
                    $block_lines   = array();
                }
                continue;
            }

            if ( $in_code_block ) {
                $block_lines[] = $line;
            } else {
                $sanitized[] = $line;
            }
        }

        if ( $in_code_block ) {
            $block_text = implode( "\n", $block_lines );
            $is_empty   = '' === trim( $block_text );
            $has_code   = preg_match( '/[;{}<>$=]/', $block_text )
                || preg_match( '/<\?php|\bfunction\b|\bclass\b|=>/i', $block_text );
            $has_md     = preg_match( '/^\s{0,3}#{1,6}\s/m', $block_text )
                || preg_match( '/^\s*[-*]\s+/m', $block_text )
                || preg_match( '/^\s*\d+\.\s+/m', $block_text );
            $has_ja     = preg_match( '/[ぁ-んァ-ン一-龯]/u', $block_text );

            if ( $is_empty || ( $has_md || ( $has_ja && ! $has_code ) ) ) {
                $sanitized = array_merge( $sanitized, $block_lines );
            } else {
                $sanitized[] = $fence_open;
                $sanitized   = array_merge( $sanitized, $block_lines );
                $sanitized[] = '```';
            }
        }

        $content = implode( "\n", $sanitized );

        // パターン9: 過度な空行の削減（3行以上の連続空行を2行に）
        $content = preg_replace( '/\n\s*\n\s*\n+/', "\n\n", $content );

        // 修正後の検証
        $open_count_after = preg_match_all( '/^```\w*/m', $content, $open_matches_after, PREG_OFFSET_CAPTURE );
        $close_count_after = preg_match_all( '/^```\s*$/m', $content, $close_matches_after );

        // ログ出力（本番では必ず削除または集約）
        if ( $open_count_after !== $close_count_after ) {
            error_log( "Blog Poster v0.2.6: Code block mismatch after fix. Open: {$open_count_after}, Close: {$close_count_after}" );
        }

        return $content;
    }

    /**
     * Claude関連の虚偽記述をチェック・修正
     *
     * @param string $content コンテンツ
     * @return string 修正されたコンテンツ
     */
    public function fact_check_claude_references( $content ) {
        // パターン1: Claudeの「インストール」「プラグイン」に関する虚偽記述を検出
        $false_patterns = array(
            '/Claude.*?プラグイン.*?インストール/u',
            '/Claude.*?をインストール/u',
            '/Claude.*?ダウンロード.*?インストール/u',
            '/Claude.*?プラグインディレクトリ/u',
            '/wp-content\/plugins\/claude/i',
        );

        $has_false_info = false;
        foreach ( $false_patterns as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                $has_false_info = true;
                error_log( "Blog Poster: Detected false Claude reference matching pattern: {$pattern}" );
                break;
            }
        }

        if ( $has_false_info ) {
            // 虚偽記述が見つかった場合、該当部分を修正
            $content = preg_replace(
                '/Claude.*?プラグイン.*?インストール[^。]*。/u',
                'ClaudeのAPIキーを取得して設定します。',
                $content
            );

            $content = preg_replace(
                '/Claude.*?をインストール[^。]*。/u',
                'Claude APIを使用するには、Anthropic公式サイトでAPIキーを取得します。',
                $content
            );

            // ログに記録
            error_log( "Blog Poster: Fixed false Claude references in generated article" );
        }

        return $content;
    }

    /**
     * 記事の品質を検証
     *
     * @param string $content コンテンツ
     * @return array 検証結果
     */
    public function validate_article( $content ) {
        $issues = array();

        // コードブロックの検証
        if ( preg_match( '/`php/', $content ) || preg_match( '/`javascript/', $content ) ) {
            $issues[] = 'コードブロックが正しく記述されていません（```phpではなく`phpになっています）';
        }

        // コードブロックが途中で切れていないか確認
        $open_count = preg_match_all( '/```\w+/', $content, $open_matches );
        $close_count = preg_match_all( '/^```\s*$/m', $content, $close_matches );

        if ( $open_count !== $close_count ) {
            $issues[] = 'コードブロックが閉じられていません（開始: ' . $open_count . '個, 終了: ' . $close_count . '個）';
        }

        // 「〜が重要です」「〜できます」で終わる段落の検出
        if ( preg_match( '/[重要|大切]です。?\n\n/', $content ) ) {
            $issues[] = '抽象的な説明（「〜が重要です」）で終わる段落があります';
        }

        // コード例の完全性チェック（基本的な検証）
        if ( preg_match( '/```php\s*\n.*?\$\w+.*?```/s', $content ) ) {
            // PHPコードがある場合、変数が定義されているか簡易チェック
            preg_match_all( '/```php\s*\n(.*?)```/s', $content, $matches );
            foreach ( $matches[1] as $code_block ) {
                // 変数の使用を検出
                preg_match_all( '/\$(\w+)/', $code_block, $vars );
                if ( ! empty( $vars[1] ) ) {
                    // 最初に使われる変数が代入または関数パラメータで定義されているか
                    $first_var = $vars[1][0];
                    if ( ! preg_match( "/\\\${$first_var}\s*=/", $code_block ) &&
                         ! preg_match( "/function.*?\\\${$first_var}/", $code_block ) ) {
                        // 一部のケースでは問題ないので、厳しすぎないように
                    }
                }
            }
        }

        if ( empty( $issues ) ) {
            return array( 'valid' => true );
        }

        return array(
            'valid' => false,
            'issues' => implode( "\n", $issues ),
        );
    }

    /**
     * コードブロックの健全性を検証
     *
     * @param string $content コンテンツ
     * @return array 検証結果
     */
    public function validate_code_blocks( $content ) {
        $issues       = array();
        $lines        = explode( "\n", $content );
        $in_block     = false;
        $block_lines  = array();
        $block_start  = 0;
        $fence_line   = '';

        foreach ( $lines as $index => $line ) {
            $trimmed = trim( $line );
            if ( preg_match( '/^```/', $trimmed ) ) {
                if ( ! $in_block ) {
                    $in_block    = true;
                    $block_lines = array();
                    $block_start = $index + 1;
                    $fence_line  = $trimmed;
                } else {
                    $block_text = implode( "\n", $block_lines );
                    $is_empty   = '' === trim( $block_text );
                    $code_token_count  = preg_match_all( '/[;{}<>$=]/', $block_text );
                    $code_token_count += preg_match_all( '/<\?php|\bfunction\b|\bclass\b|=>/i', $block_text );
                    $code_token_count += preg_match_all( '/\b(public|private|protected|return|const|let|var|import|export)\b/i', $block_text );
                    $has_md     = preg_match( '/^\s*#{1,6}\s/m', $block_text )
                        || preg_match( '/^\s*[-*]\s+/m', $block_text )
                        || preg_match( '/^\s*\d+\.\s+/m', $block_text );
                    $has_ja     = preg_match( '/[ぁ-んァ-ン一-龯]/u', $block_text );
                    $sentence_count = preg_match_all( '/[。！？]/u', $block_text );
                    $looks_like_prose = $has_ja && $sentence_count >= 2 && $code_token_count < 2;

                    if ( $is_empty ) {
                        $issues[] = '空のコードブロックが検出されました（開始行: ' . $block_start . '）';
                    } elseif ( $has_md && $has_ja ) {
                        $issues[] = 'コードブロック内に見出し/箇条書きが混入しています（開始行: ' . $block_start . '）';
                    } elseif ( $looks_like_prose ) {
                        $issues[] = 'コードブロック内に文章・見出し・箇条書きが混入しています（開始行: ' . $block_start . '）';
                    }

                    $in_block    = false;
                    $block_lines = array();
                    $block_start = 0;
                    $fence_line  = '';
                }
                continue;
            }

            if ( $in_block ) {
                $block_lines[] = $line;
            }
        }

        if ( $in_block ) {
            $issues[] = 'コードブロックが閉じられていません（開始行: ' . $block_start . '）';
        }

        return array(
            'valid'  => empty( $issues ),
            'issues' => $issues,
        );
    }

    /**
     * 導入部を生成
     *
     * @param array $outline アウトライン
     * @return string 導入部
     */
    public function generate_intro( $outline ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return '';
        }

        $prompt = <<<PROMPT
以下の記事の導入部を200〜300文字で作成してください。

タイトル: {$outline['title']}
想定読者: {$outline['target_reader']}
読者の目標: {$outline['reader_goal']}

【導入部の要件】
- 読者の課題や疑問に共感する文から始める
- この記事で何が得られるかを明示する
- 読み進める動機を与える
- 箇条書きは使わない

Markdown形式で出力してください。見出しは不要です。
PROMPT;

        $response = $client->generate_text( $prompt );

        return $response['success'] ? $response['data'] : '';
    }

    /**
     * まとめを生成
     *
     * @param array  $outline アウトライン
     * @param string $all_content 全コンテンツ
     * @return string まとめ
     */
    public function generate_summary( $outline, $all_content ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return '';
        }

        $headings = $this->extract_headings( $all_content );

        $prompt = <<<PROMPT
以下の記事のまとめセクションを作成してください。

【記事タイトル】
{$outline['title']}

【記事本文の要約】
記事では以下のトピックを扱いました：
{$headings}

【まとめの要件】
- H2「まとめ」から始める
- 記事の要点を3〜5点で箇条書き
- 読者が次に取るべき具体的なアクションを1つ提案
- 200〜300文字程度

Markdown形式で出力してください。
PROMPT;

        $response = $client->generate_text( $prompt );

        return $response['success'] ? $response['data'] : '';
    }

    /**
     * JSONブロックで導入部を生成
     *
     * @param array $outline アウトライン
     * @return array|WP_Error
     */
    public function generate_intro_blocks( $outline ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $prompt = <<<PROMPT
以下の記事の導入部をJSONブロック配列で作成してください。

タイトル: {$outline['title']}
想定読者: {$outline['target_reader']}
読者の目標: {$outline['reader_goal']}

【要件】
- 読者の課題への共感から始める
- この記事で得られることを明示
- 箇条書きは使わない
- Markdown記号は使わない
- JSON以外のテキストを出力しない
- 文字列内の改行・タブは必ず \\n / \\t にエスケープする
- 文字列内に生の改行やタブを入れない
- ルートは必ず { "blocks": [...] } のオブジェクト

【出力フォーマット - 厳密に従うこと】
{ "blocks": [ { "type": "text", "content": "..." } ] }

【重要】"text"フィールドは使用禁止。必ず"content"フィールドを使用すること
PROMPT;

        $response = $client->generate_text( $prompt );
        if ( ! $response['success'] ) {
            return new WP_Error( 'api_error', $response['error'] ?? 'APIエラー' );
        }

        $data = $this->parse_json_blocks_response( $response['data'] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return isset( $data['blocks'] ) ? $data['blocks'] : array();
    }

    /**
     * JSONブロックでまとめを生成
     *
     * @param array  $outline アウトライン
     * @param string $all_content 本文
     * @return array|WP_Error
     */
    public function generate_summary_blocks( $outline, $all_content ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $prompt = <<<PROMPT
以下の記事のまとめをJSONブロック配列で作成してください。

タイトル: {$outline['title']}

【要件】
- 要点を3-5個のlistで記述
- 次のアクション提案をtextで1段落追加
- Markdown記号は使わない
- JSON以外のテキストを出力しない
- 文字列内の改行・タブは必ず \\n / \\t にエスケープする
- 文字列内に生の改行やタブを入れない
- ルートは必ず { "blocks": [...] } のオブジェクト

【出力フォーマット - 厳密に従うこと】
{ "blocks": [
  { "type": "h2", "content": "まとめ" },
  { "type": "list", "items": ["...","..."] },
  { "type": "text", "content": "..." }
] }

【重要】"text"フィールドは使用禁止。必ず"content"フィールドを使用すること
PROMPT;

        $response = $client->generate_text( $prompt );
        if ( ! $response['success'] ) {
            return new WP_Error( 'api_error', $response['error'] ?? 'APIエラー' );
        }

        $data = $this->parse_json_blocks_response( $response['data'] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return isset( $data['blocks'] ) ? $data['blocks'] : array();
    }

    /**
     * コンテンツから見出しを抽出
     *
     * @param string $content コンテンツ
     * @return string 見出しリスト
     */
    private function extract_headings( $content ) {
        preg_match_all( '/^##\s+(.+)$/m', $content, $matches );
        $headings = isset( $matches[1] ) ? $matches[1] : array();
        return ! empty( $headings ) ? implode( "\n", $headings ) : '（見出しなし）';
    }

    /**
     * Featured Image を生成
     *
     * @param string $topic トピック
     * @return string|WP_Error 画像URLまたはエラー
     */
    public function generate_featured_image( $topic ) {
        // TODO: Phase 4で実装
        return new WP_Error( 'not_implemented', __( '画像生成機能は開発中です。', 'blog-poster' ) );
    }
}
