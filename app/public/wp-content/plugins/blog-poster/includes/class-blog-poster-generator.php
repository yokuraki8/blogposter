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
     * ブログ記事を生成（品質検証付き）
     *
     * @param string $topic トピック/キーワード
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    public function generate_article( $topic, $additional_instructions = '' ) {
        $max_attempts = 3;
        $attempt = 0;

        // トピックの明確化
        $clarification = $this->clarify_topic( $topic );
        if ( ! is_wp_error( $clarification ) ) {
            $additional_instructions .= "\n\n【トピックの明確化】\n" . $clarification;
        }

        $last_content = '';

        while ( $attempt < $max_attempts ) {
            $attempt++;

            // 記事生成
            $result = $this->generate_article_internal( $topic, $additional_instructions );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $last_content = $result;

            // コードブロックを修正
            $result['article']['content'] = $this->fix_code_blocks( $result['article']['content'] );

            // 品質検証
            $validation = $this->validate_article( $result['article']['content'] );

            if ( $validation['valid'] ) {
                return $result;
            }

            // 問題点を追加指示に含めて再生成
            $additional_instructions .= "\n\n【前回の生成で見つかった問題（必ず修正してください）】\n" . $validation['issues'];
        }

        // 最大試行回数を超えた場合はログを残して最後の結果を返す
        error_log( "Blog Poster: Article generation failed validation after {$max_attempts} attempts for topic: {$topic}" );
        return $last_content;
    }

    /**
     * 記事生成の内部実装
     *
     * @param string $topic トピック/キーワード
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    private function generate_article_internal( $topic, $additional_instructions = '' ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        // プロンプトを構築
        $prompt = $this->build_article_prompt( $topic, $additional_instructions );

        // AIで記事を生成
        $response = $client->generate_text( $prompt );

        if ( ! $response['success'] ) {
            return new WP_Error( 'generation_failed', $response['error'] );
        }

        // 記事データを解析
        $article_data = $this->parse_article_response( $response['data'] );

        return array(
            'success' => true,
            'article' => $article_data,
            'tokens' => $response['tokens'],
            'model' => $response['model'],
        );
    }

    /**
     * 記事生成プロンプトを構築（簡素化版）
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return string プロンプト
     */
    private function build_article_prompt( $topic, $additional_instructions = '' ) {
        $prompt = "あなたは{$topic}に関する実務経験を持つテクニカルライターです。\n\n";
        $prompt .= "以下の記事を作成してください。\n\n";

        $prompt .= "【記事の目的】\n";
        $prompt .= "読者がこの記事だけを読んで、実際に{$topic}を実行できるようにすること。\n\n";

        $prompt .= "【必須コンテンツ】\n";
        $prompt .= "1. 完全に動作するコード例（省略なし、コピペで動く状態。必ず```php または ```javascript のようにコードブロックを明示）\n";
        $prompt .= "2. 各ステップの実行結果の説明\n";
        $prompt .= "3. よくあるエラーとその解決方法\n\n";

        $prompt .= "【文体】\n";
        $prompt .= "- 具体的な例や手順を文章で説明する\n";
        $prompt .= "- 「〜することが重要です」「〜できます」で終わる段落は禁止\n";
        $prompt .= "- 各段落は具体的な情報または手順で終わること\n";
        $prompt .= "- 技術用語は正確に使用（Webサービスを「インストール」と言わない）\n\n";

        $prompt .= "【記事構成】\n";
        $prompt .= "- 導入部: この記事で得られる具体的な成果（200-300文字）\n";
        $prompt .= "- 本文: H2見出しを3-5個、各H2の下にH3見出しを2-4個配置\n";
        $prompt .= "- まとめ: 要点3-5個と次のアクション提案\n\n";

        if ( ! empty( $additional_instructions ) ) {
            $prompt .= "【追加指示】\n{$additional_instructions}\n\n";
        }

        $prompt .= "【出力形式】\n";
        $prompt .= "=== タイトル ===\n";
        $prompt .= "（50文字以内、具体的で魅力的なタイトル）\n\n";
        $prompt .= "=== Slug ===\n";
        $prompt .= "（英数字とハイフンのみ）\n\n";
        $prompt .= "=== メタディスクリプション ===\n";
        $prompt .= "（120文字以内）\n\n";
        $prompt .= "=== 抜粋 ===\n";
        $prompt .= "（100-200文字）\n\n";
        $prompt .= "=== 本文 ===\n";
        $prompt .= "（ここにMarkdown形式で本文を記載。コードブロックは必ず```phpまたは```javascriptなどの言語指定付きで記載）\n\n";
        $prompt .= "=== 関連キーワード ===\n";
        $prompt .= "（5-10個、カンマ区切り）\n\n";

        $prompt .= "上記の形式で、読者が実際に実行できる高品質な記事を生成してください。\n";

        return $prompt;
    }

    /**
     * トピックの明確化
     *
     * @param string $topic トピック
     * @return string|WP_Error 明確化された内容またはエラー
     */
    private function clarify_topic( $topic ) {
        $client = $this->get_ai_client();

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $clarification_prompt = <<<PROMPT
以下のトピックについて、記事で扱うべき内容を明確にしてください。

トピック: {$topic}

以下の形式で簡潔に回答してください：
1. 読者が知りたいこと（1文）
2. 記事で提供すべき具体的な成果物（例：動作するコード、設定ファイル、手順書など）
3. 記事に含めるべきでないこと（混同しやすい別トピックなど）
PROMPT;

        $response = $client->generate_text( $clarification_prompt );

        if ( ! $response['success'] ) {
            return new WP_Error( 'clarification_failed', $response['error'] );
        }

        return $response['data'];
    }

    /**
     * コードブロックを修正
     *
     * @param string $content コンテンツ
     * @return string 修正されたコンテンツ
     */
    private function fix_code_blocks( $content ) {
        // 壊れたコードブロックを修正
        $content = preg_replace( '/`php\s*\n/', "```php\n", $content );
        $content = preg_replace( '/`javascript\s*\n/', "```javascript\n", $content );
        $content = preg_replace( '/`html\s*\n/', "```html\n", $content );
        $content = preg_replace( '/`css\s*\n/', "```css\n", $content );
        $content = preg_replace( '/`bash\s*\n/', "```bash\n", $content );

        // 閉じられていないコードブロックを検出して閉じる
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

        return implode( "\n", $fixed_lines );
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
     * AI応答から記事データを解析
     *
     * @param string $response AI応答テキスト
     * @return array 解析された記事データ
     */
    private function parse_article_response( $response ) {
        $article = array(
            'title' => '',
            'slug' => '',
            'meta_description' => '',
            'excerpt' => '',
            'content' => '',
            'keywords' => array(),
        );

        // セクションごとに分割
        if ( preg_match( '/===\s*タイトル\s*===\s*\n(.+?)\n/s', $response, $matches ) ) {
            $article['title'] = trim( $matches[1] );
        }

        if ( preg_match( '/===\s*Slug\s*===\s*\n(.+?)\n/s', $response, $matches ) ) {
            $article['slug'] = trim( $matches[1] );
        }

        if ( preg_match( '/===\s*メタディスクリプション\s*===\s*\n(.+?)\n/s', $response, $matches ) ) {
            $article['meta_description'] = trim( $matches[1] );
        }

        if ( preg_match( '/===\s*抜粋\s*===\s*\n(.+?)\n/s', $response, $matches ) ) {
            $article['excerpt'] = trim( $matches[1] );
        }

        if ( preg_match( '/===\s*本文\s*===\s*\n(.+?)\n===\s*関連キーワード/s', $response, $matches ) ) {
            $article['content'] = trim( $matches[1] );
        }

        if ( preg_match( '/===\s*関連キーワード\s*===\s*\n(.+?)($|\n===)/s', $response, $matches ) ) {
            $keywords_string = trim( $matches[1] );
            $article['keywords'] = array_map( 'trim', explode( ',', $keywords_string ) );
        }

        return $article;
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
