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
                $model = isset( $settings['default_model']['gemini'] ) ? $settings['default_model']['gemini'] : 'gemini-1.5-pro';
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
                $model = isset( $settings['default_model']['openai'] ) ? $settings['default_model']['openai'] : 'gpt-4o';
                $client = new Blog_Poster_OpenAI_Client( $api_key, $model, $settings );
                break;
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'APIキーが設定されていません。', 'blog-poster' ) );
        }

        return $client;
    }

    /**
     * ブログ記事を生成
     *
     * @param string $topic トピック/キーワード
     * @param string $additional_instructions 追加指示
     * @return array|WP_Error 生成結果またはエラー
     */
    public function generate_article( $topic, $additional_instructions = '' ) {
        // TODO: Phase 1で完全実装
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
     * 記事生成プロンプトを構築
     *
     * @param string $topic トピック
     * @param string $additional_instructions 追加指示
     * @return string プロンプト
     */
    private function build_article_prompt( $topic, $additional_instructions = '' ) {
        $prompt = "あなたは専門知識を持つプロのライターです。以下のトピックについて、読者に実際の価値を提供する高品質なブログ記事を日本語で作成してください。\n\n";
        $prompt .= "【トピック】\n{$topic}\n\n";

        if ( ! empty( $additional_instructions ) ) {
            $prompt .= "【追加指示】\n{$additional_instructions}\n\n";
        }

        $prompt .= "【必須要件 - 厳守してください】\n\n";

        $prompt .= "1. **具体性の確保（最重要）**\n";
        $prompt .= "   - 各セクションに最低1つの具体例、手順、またはコードサンプルを含める\n";
        $prompt .= "   - 「〜することが重要です」「〜できます」のような抽象的な説明だけで終わらない\n";
        $prompt .= "   - 読者が「この記事を読んで実際に何かを実行できる」内容にする\n";
        $prompt .= "   - 具体的な数値、ツール名、コマンド、手順を含める\n\n";

        $prompt .= "2. **実行可能な情報**\n";
        $prompt .= "   - コード例を含む場合は、コピー＆ペーストで動作するコードを記載\n";
        $prompt .= "   - 手順を含む場合は、ステップバイステップで記載（「ステップ1」「ステップ2」）\n";
        $prompt .= "   - ツールやサービスを紹介する場合は、具体的な使用方法を記載\n";
        $prompt .= "   - 「〜を設定します」ではなく「〜の設定画面で○○に△△を入力します」のように具体的に\n\n";

        $prompt .= "3. **技術的正確性**\n";
        $prompt .= "   - 技術用語は正確に使用する（例：「インストール」は実際にインストールするものにのみ使用）\n";
        $prompt .= "   - Webサービスやクラウドサービスを「インストール」とは言わない\n";
        $prompt .= "   - APIやSaaSは「利用開始」「アクセス」「連携」などの正確な表現を使用\n\n";

        $prompt .= "4. **E-E-A-T（経験・専門性・権威性・信頼性）の実証**\n";
        $prompt .= "   - 実際の使用経験を感じさせる記述（「実際に試したところ」「検証した結果」）\n";
        $prompt .= "   - 具体的なメリット・デメリット、注意点を記載\n";
        $prompt .= "   - よくある失敗例とその解決策を含める\n\n";

        $prompt .= "5. **見出し構造の深化**\n";
        $prompt .= "   - H2（3-5個）の下に必ずH3（2-4個）を配置し、情報を深掘りする\n";
        $prompt .= "   - H3の下に具体例や詳細説明を記載\n";
        $prompt .= "   - フラットな構造を避け、階層的に情報を整理\n\n";

        $prompt .= "6. **SEO最適化（自然な形で）**\n";
        $prompt .= "   - キーワードを詰め込まず、文脈に沿って自然に使用\n";
        $prompt .= "   - 読者の検索意図（知りたいこと）に応える内容\n";
        $prompt .= "   - タイトルは魅力的で具体的（「〜の方法」「〜を徹底解説」「初心者向け〜」）\n\n";

        $prompt .= "7. **禁止事項**\n";
        $prompt .= "   - ❌ 抽象的な説明だけの段落\n";
        $prompt .= "   - ❌ 具体例のない「〜が重要です」「〜できます」\n";
        $prompt .= "   - ❌ 技術的に不正確な表現\n";
        $prompt .= "   - ❌ 情報の羅列だけで終わるセクション\n";
        $prompt .= "   - ❌ 読者が何をすべきか分からない内容\n\n";

        $prompt .= "【記事の構成要素】\n\n";

        $prompt .= "**導入部（200-300文字）**\n";
        $prompt .= "- この記事で読者が得られる具体的な成果を明示\n";
        $prompt .= "- トピックの重要性を具体的な事例や数値で説明\n\n";

        $prompt .= "**本文セクション（H2を3-5個）**\n";
        $prompt .= "各H2セクションには：\n";
        $prompt .= "- H3を2-4個含め、詳細に深掘り\n";
        $prompt .= "- 最低1つの具体例（コード、手順、スクリーンショット説明、具体的数値）\n";
        $prompt .= "- 実際に実行できる情報\n";
        $prompt .= "- 注意点や失敗例（あれば）\n\n";

        $prompt .= "**まとめ（200-300文字）**\n";
        $prompt .= "- 記事の要点を3-5点で箇条書き\n";
        $prompt .= "- 次のアクションを明示（「まず○○から始めましょう」）\n\n";

        $prompt .= "【出力形式】\n";
        $prompt .= "以下の形式で厳密に出力してください。\n\n";
        $prompt .= "=== タイトル ===\n";
        $prompt .= "具体的で魅力的なタイトル（40-60文字、数字や「初心者向け」「徹底解説」などを含む）\n\n";
        $prompt .= "=== Slug ===\n";
        $prompt .= "英数字とハイフンのみ（例：wordpress-plugin-development-guide）\n\n";
        $prompt .= "=== メタディスクリプション ===\n";
        $prompt .= "読者の検索意図に応え、記事の価値を明確に示す（120-160文字）\n\n";
        $prompt .= "=== 抜粋 ===\n";
        $prompt .= "記事の核心的な価値を簡潔に（100-200文字）\n\n";
        $prompt .= "=== 本文 ===\n";
        $prompt .= "【導入】\n";
        $prompt .= "（導入文：200-300文字）\n\n";
        $prompt .= "## H2見出し1（具体的なタイトル）\n";
        $prompt .= "（導入文）\n\n";
        $prompt .= "### H3見出し1-1\n";
        $prompt .= "（具体例を含む詳細説明。コード例や手順を必ず含める）\n\n";
        $prompt .= "### H3見出し1-2\n";
        $prompt .= "（具体例を含む詳細説明）\n\n";
        $prompt .= "（H2を3-5個、各H2の下にH3を2-4個という構成で記載）\n\n";
        $prompt .= "## まとめ\n";
        $prompt .= "この記事のポイント：\n";
        $prompt .= "- ポイント1\n";
        $prompt .= "- ポイント2\n";
        $prompt .= "- ポイント3\n\n";
        $prompt .= "（次のアクション提案）\n\n";
        $prompt .= "=== 関連キーワード ===\n";
        $prompt .= "検索ボリュームがあり関連性の高いキーワード5-10個（カンマ区切り）\n\n";

        $prompt .= "【品質チェック】\n";
        $prompt .= "生成前に以下を自己チェックしてください：\n";
        $prompt .= "✓ 各セクションに具体例が含まれているか？\n";
        $prompt .= "✓ 読者が実際に何かを実行できる情報があるか？\n";
        $prompt .= "✓ 技術用語は正確に使用されているか？\n";
        $prompt .= "✓ 抽象的な説明だけの段落はないか？\n";
        $prompt .= "✓ 見出し構造が深化しているか（H2>H3の階層）？\n\n";

        $prompt .= "それでは、上記の要件を厳守して、読者に実際の価値を提供する高品質な記事を生成してください。\n";

        return $prompt;
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
