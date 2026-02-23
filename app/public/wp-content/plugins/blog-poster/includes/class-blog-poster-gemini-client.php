<?php
/**
 * Google Gemini APIクライアント
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_Gemini_Client クラス
 */
class Blog_Poster_Gemini_Client extends Blog_Poster_AI_Client {

    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * モデル名を正規化
     *
     * @param string $model モデル名
     * @return string 正規化後のモデル名
     */
    public static function normalize_model( $model ) {
        $model = trim( (string) $model );
        if ( '' === $model ) {
            return 'gemini-2.5-pro';
        }

        $unsupported_models = array(
            'gemini-3-pro',
        );

        if ( in_array( $model, $unsupported_models, true ) ) {
            error_log( 'Blog Poster: Unsupported Gemini model selected, falling back to gemini-2.5-pro' );
            return 'gemini-2.5-pro';
        }

        return $model;
    }

    /**
     * テキスト生成
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_text( $prompt, $options = null ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'Gemini APIキーが設定されていません。', 'blog-poster' ) );
        }

        $model = $this->model;
        if ( is_array( $options ) && ! empty( $options['model'] ) ) {
            $model = $options['model'];
        }
        // モデル名を正規化（ローカル変数のみ変更、インスタンス変数は変更しない）
        $normalized_model = self::normalize_model( $model );
        if ( $normalized_model !== $model ) {
            $model = $normalized_model;
            // 注意: $this->modelは変更しない（副作用を避ける）
        }

        $max_tokens = $this->max_tokens;
        if ( is_array( $options ) && isset( $options['max_tokens'] ) ) {
            $max_tokens = (int) $options['max_tokens'];
        }

        $url = self::API_BASE_URL . $model . ':generateContent?key=' . $this->api_key;

        $adjusted_prompt = $this->apply_tone_settings( $prompt );

        // Gemini 2.5系モデルの判定（thinking mode対応が必要）
        $is_thinking_model = ( strpos( $model, 'gemini-2.5' ) !== false );

        $generation_config = array(
            'temperature' => $this->temperature,
            'maxOutputTokens' => $max_tokens,
        );

        // Gemini 2.5系: thinkingBudgetを最小値(128)に制限
        // （思考トークンがmaxOutputTokensを消費して回答が空になる問題の防止）
        // 注: 2.5 Proは思考を完全無効化(0)できない。最小値は128。
        if ( $is_thinking_model ) {
            $generation_config['thinkingConfig'] = array(
                'thinkingBudget' => 128,
            );
        }

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $adjusted_prompt
                        )
                    )
                )
            ),
            'generationConfig' => $generation_config,
        );

        $headers = array(
            'Content-Type' => 'application/json',
        );

        $response = $this->make_request( $url, $body, $headers );

        // v1で404の場合はv1betaへフォールバック
        if ( is_wp_error( $response ) && strpos( $response->get_error_message(), 'ステータスコード 404' ) !== false ) {
            $fallback_url = str_replace( '/v1/', '/v1beta/', $url );
            $response = $this->make_request( $fallback_url, $body, $headers );
        }

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            $error_data    = $response->get_error_data();
            if ( ! empty( $error_data ) ) {
                error_log( 'Blog Poster Gemini error data: ' . wp_json_encode( $error_data, JSON_UNESCAPED_UNICODE ) );
            }
            return $this->error_response( $error_message, $response->get_error_code() );
        }

        // レスポンスからテキストとトークン数を抽出
        $text = '';
        $tokens = 0;

        // SAFETY / RECITATION等のブロック応答を明示的にエラー化
        if ( isset( $response['candidates'][0]['finishReason'] ) ) {
            $finish_reason = $response['candidates'][0]['finishReason'];
            if ( in_array( $finish_reason, array( 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT' ), true ) ) {
                error_log( 'Blog Poster Gemini: Response blocked. finishReason=' . $finish_reason );
                return $this->error_response(
                    sprintf( __( 'Geminiがコンテンツをブロックしました（理由: %s）', 'blog-poster' ), $finish_reason )
                );
            }
        }

        // Thinking Mode対応: thought以外のテキストパートを全て連結
        if ( isset( $response['candidates'][0]['content']['parts'] ) && is_array( $response['candidates'][0]['content']['parts'] ) ) {
            $parts = $response['candidates'][0]['content']['parts'];
            $text_parts = array();
            foreach ( $parts as $part ) {
                if ( ! empty( $part['thought'] ) ) {
                    continue;
                }
                if ( isset( $part['text'] ) && '' !== $part['text'] ) {
                    $text_parts[] = $part['text'];
                }
            }
            $text = implode( '', $text_parts );
        }

        if ( isset( $response['usageMetadata']['totalTokenCount'] ) ) {
            $tokens = $response['usageMetadata']['totalTokenCount'];
        }

        if ( empty( trim( $text ) ) ) {
            error_log( 'Blog Poster Gemini: Empty text after parsing. Raw response: ' . wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );
            return $this->error_response( __( 'Geminiからの応答テキストが空です。', 'blog-poster' ) );
        }

        return $this->success_response( $text, $tokens );
    }

    /**
     * 画像生成（Imagen 3経由）
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_image( $prompt ) {
        // TODO: Imagen 3 API実装（Phase 4で実装予定）
        return $this->error_response( __( '画像生成機能は開発中です。', 'blog-poster' ) );
    }
}
