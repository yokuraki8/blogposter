<?php
/**
 * OpenAI APIクライアント
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_OpenAI_Client クラス
 */
class Blog_Poster_OpenAI_Client extends Blog_Poster_AI_Client {

    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.openai.com/v1/';

    /**
     * テキスト生成
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_text( $prompt ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'OpenAI APIキーが設定されていません。', 'blog-poster' ) );
        }

        $url = self::API_BASE_URL . 'chat/completions';

        $adjusted_prompt = $this->apply_tone_settings( $prompt );

        $body = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $adjusted_prompt
                )
            ),
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
        );

        if ( 0 === strpos( $this->model, 'gpt-5' ) ) {
            $body['max_completion_tokens'] = $this->max_tokens;
            unset( $body['max_tokens'] );
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        );

        $response = $this->make_request( $url, $body, $headers );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        // レスポンスからテキストとトークン数を抽出
        $text = '';
        $tokens = 0;

        if ( isset( $response['choices'][0]['message']['content'] ) ) {
            $text = $response['choices'][0]['message']['content'];
        }

        if ( isset( $response['usage']['total_tokens'] ) ) {
            $tokens = $response['usage']['total_tokens'];
        }

        return $this->success_response( $text, $tokens );
    }

    /**
     * 画像生成（DALL-E 3）
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    public function generate_image( $prompt ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'OpenAI APIキーが設定されていません。', 'blog-poster' ) );
        }

        $url = self::API_BASE_URL . 'images/generations';

        $body = array(
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
            'quality' => 'standard',
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        );

        $response = $this->make_request( $url, $body, $headers );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        // レスポンスから画像URLを抽出
        $image_url = '';

        if ( isset( $response['data'][0]['url'] ) ) {
            $image_url = $response['data'][0]['url'];
        }

        return $this->success_response( $image_url );
    }
}
