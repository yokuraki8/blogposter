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
    private function generate_outline( $topic, $additional_instructions = '' ) {
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

上記のJSON形式で、実行可能な記事のアウトラインを生成してください。
PROMPT;

        $response = $client->generate_text( $prompt );

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
        // コードブロックを除去（```json ... ``` の形式に対応）
        $json_str = preg_replace( '/```json\s*\n/', '', $response );
        $json_str = preg_replace( '/```\s*$/', '', $json_str );
        $json_str = trim( $json_str );

        $data = json_decode( $json_str, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_parse_error', 'JSONパースエラー: ' . json_last_error_msg() );
        }

        // 必須フィールドの検証
        $required_fields = array( 'title', 'slug', 'meta_description', 'excerpt', 'sections' );
        foreach ( $required_fields as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
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
    private function generate_section_content( $section, $topic, $previous_summary = '', $additional_instructions = '' ) {
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

{$additional_instructions}

このセクションの内容をMarkdown形式で記述してください。
PROMPT;

        $response = $client->generate_text( $prompt );

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

        $intro_response = $client->generate_text( $intro_prompt );
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

        $summary_response = $client->generate_text( $summary_prompt );
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
     * コードブロックを修正（強化版）
     *
     * @param string $content コンテンツ
     * @return string 修正されたコンテンツ
     */
    private function fix_code_blocks( $content ) {
        // パターン1: バッククォート1つで始まる壊れたコードブロック（`php → ```php）
        $content = preg_replace( '/`(php|javascript|js|html|css|bash|sh|python|py|java|c|cpp|go|rust|sql|json|xml|yaml|yml)\s*\n/', '```$1' . "\n", $content );

        // パターン2: ダブルクォートで終わるコードブロック（```php" → ```php）
        $content = preg_replace( '/```(php|javascript|js|html|css|bash|sh|python|py|java|c|cpp|go|rust|sql|json|xml|yaml|yml)"\s*\n/', '```$1' . "\n", $content );

        // パターン3: 空のコードブロックを削除（```php\n``` または ```\n```）
        $content = preg_replace( '/```\w*\s*\n\s*```/m', '', $content );

        // パターン4: 閉じられていないコードブロックを検出して閉じる
        $lines = explode( "\n", $content );
        $in_code_block = false;
        $fixed_lines = array();

        foreach ( $lines as $line ) {
            if ( preg_match( '/^```\w+/', $line ) ) {
                $in_code_block = true;
            } elseif ( preg_match( '/^```\s*$/', $line ) ) {
                $in_code_block = false;
            }
            $fixed_lines[] = $line;
        }

        // 最後にコードブロックが閉じられていない場合は閉じる
        if ( $in_code_block ) {
            $fixed_lines[] = '```';
        }

        $content = implode( "\n", $fixed_lines );

        // パターン5: コードブロック内の不要な空行を削減（連続する3行以上の空行を2行に）
        $content = preg_replace( '/(```\w+.*?\n)((?:\s*\n){3,})/s', '$1' . "\n\n", $content );

        return $content;
    }

    /**
     * Claude関連の虚偽記述をチェック・修正
     *
     * @param string $content コンテンツ
     * @return string 修正されたコンテンツ
     */
    private function fact_check_claude_references( $content ) {
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
    private function validate_article( $content ) {
        $issues = array();

        // コードブロックの検証
        if ( preg_match( '/`php/', $content ) || preg_match( '/`javascript/', $content ) ) {
            $issues[] = 'コードブロックが正しく記述されていません（```phpではなく`phpになっています）';
        }

        // コードブロックが途中で切れていないか確認
        $open_count = preg_match_all( '/```\w+/', $content );
        $close_count = preg_match_all( '/```\s*$/m', $content );

        if ( $open_count > $close_count ) {
            $issues[] = 'コードブロックが閉じられていません';
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
