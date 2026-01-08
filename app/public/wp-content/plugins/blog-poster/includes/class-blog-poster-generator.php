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
        $prompt = "以下のトピックについて、SEOに最適化された高品質なブログ記事を日本語で作成してください。\n\n";
        $prompt .= "【トピック】\n{$topic}\n\n";

        if ( ! empty( $additional_instructions ) ) {
            $prompt .= "【追加指示】\n{$additional_instructions}\n\n";
        }

        $prompt .= "【出力形式】\n";
        $prompt .= "以下の形式で出力してください。各セクションは明確に分けてください。\n\n";
        $prompt .= "=== タイトル ===\n";
        $prompt .= "（ここに記事タイトルを記載）\n\n";
        $prompt .= "=== Slug ===\n";
        $prompt .= "（ここにURL用のslugを記載。英数字とハイフンのみ）\n\n";
        $prompt .= "=== メタディスクリプション ===\n";
        $prompt .= "（ここにメタディスクリプションを記載。120-160文字）\n\n";
        $prompt .= "=== 抜粋 ===\n";
        $prompt .= "（ここに記事の抜粋を記載。100-200文字）\n\n";
        $prompt .= "=== 本文 ===\n";
        $prompt .= "（ここに記事本文を記載。見出し（H2、H3）を適切に使用）\n\n";
        $prompt .= "=== 関連キーワード ===\n";
        $prompt .= "（カンマ区切りで5-10個のキーワード）\n\n";

        $prompt .= "【要件】\n";
        $prompt .= "- 記事の長さ: 2000-3000文字程度\n";
        $prompt .= "- 見出し構造: H2を3-5個、各H2にH3を2-3個\n";
        $prompt .= "- SEO最適化: キーワードを自然に含める\n";
        $prompt .= "- 読みやすさ: 段落を適切に分ける\n";
        $prompt .= "- 日本語品質: 自然で正確な日本語表現\n";

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
