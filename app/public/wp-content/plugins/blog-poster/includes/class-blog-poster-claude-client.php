<?php
/**
 * Anthropic Claude APIクライアント
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_Claude_Client クラス
 */
class Blog_Poster_Claude_Client extends Blog_Poster_AI_Client {

    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.anthropic.com/v1/';

    /**
     * API Version
     */
    const API_VERSION = '2023-06-01';

    /**
     * テキスト生成
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_text( $prompt ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'Claude APIキーが設定されていません。', 'blog-poster' ) );
        }

        $url = self::API_BASE_URL . 'messages';

        $adjusted_prompt = $this->apply_tone_settings( $prompt );

        $body = array(
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $adjusted_prompt
                )
            )
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'x-api-key' => $this->api_key,
            'anthropic-version' => self::API_VERSION,
        );

        $response = $this->make_request( $url, $body, $headers );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        // レスポンスからテキストとトークン数を抽出
        $text = '';
        $tokens = 0;

        if ( isset( $response['content'][0]['text'] ) ) {
            $text = $response['content'][0]['text'];
        }

        if ( isset( $response['usage']['input_tokens'] ) && isset( $response['usage']['output_tokens'] ) ) {
            $tokens = $response['usage']['input_tokens'] + $response['usage']['output_tokens'];
        }

        return $this->success_response( $text, $tokens );
    }

    /**
     * 画像生成
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_image( $prompt ) {
        // TODO: Claude Image Generation実装（将来対応検討）
        return $this->error_response( __( 'Claude画像生成機能は現在サポートされていません。', 'blog-poster' ) );
    }
}
