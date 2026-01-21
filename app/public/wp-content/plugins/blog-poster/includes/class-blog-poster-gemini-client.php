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
    const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1/models/';

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
    public function generate_text( $prompt, $response_format = null ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'Gemini APIキーが設定されていません。', 'blog-poster' ) );
        }

        $model = $this->model;
        if ( is_array( $response_format ) && ! empty( $response_format['model'] ) ) {
            $model = $response_format['model'];
        }
        $normalized_model = self::normalize_model( $model );
        if ( $normalized_model !== $model ) {
            $model = $normalized_model;
            $this->model = $normalized_model;
        }

        $max_tokens = $this->max_tokens;
        if ( is_array( $response_format ) && isset( $response_format['max_tokens'] ) ) {
            $max_tokens = (int) $response_format['max_tokens'];
        }

        $url = self::API_BASE_URL . $model . ':generateContent?key=' . $this->api_key;

        $adjusted_prompt = $this->apply_tone_settings( $prompt );

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
            'generationConfig' => array(
                'temperature' => $this->temperature,
                'maxOutputTokens' => $max_tokens,
            )
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
            return $this->error_response( $error_message );
        }

        // レスポンスからテキストとトークン数を抽出
        $text = '';
        $tokens = 0;

        if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $text = $response['candidates'][0]['content']['parts'][0]['text'];
        }

        if ( isset( $response['usageMetadata']['totalTokenCount'] ) ) {
            $tokens = $response['usageMetadata']['totalTokenCount'];
        }

        return $this->success_response( $text, $tokens );
    }

    /**
     * 利用可能なモデル一覧を取得
     *
     * @return array
     */
    public function list_models() {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'Gemini APIキーが設定されていません。', 'blog-poster' ) );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $this->api_key;

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 ) {
            return $this->error_response(
                sprintf( __( 'APIエラー: ステータスコード %d', 'blog-poster' ), $status_code ),
                $data
            );
        }

        $models = array();
        if ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
            foreach ( $data['models'] as $model ) {
                if ( isset( $model['name'] ) ) {
                    $models[] = $model['name'];
                }
            }
        }

        return $this->success_response( $models );
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
