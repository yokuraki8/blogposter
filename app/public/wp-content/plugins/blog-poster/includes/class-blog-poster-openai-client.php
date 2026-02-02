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
    public function generate_text( $prompt, $options = null ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'OpenAI APIキーが設定されていません。', 'blog-poster' ) );
        }

        // オプションからmax_tokensを取得（指定があれば上書き）
        $max_tokens = $this->max_tokens;
        $response_format = null;
        if ( is_array( $options ) ) {
            if ( isset( $options['max_tokens'] ) ) {
                $max_tokens = (int) $options['max_tokens'];
            }
            if ( isset( $options['type'] ) || isset( $options['json_schema'] ) ) {
                $response_format = $options;
            }
        }

        $adjusted_prompt = $this->apply_tone_settings( $prompt );

        $is_gpt5 = ( 0 === strpos( $this->model, 'gpt-5' ) );
        // gpt-5.2-proはtemperatureパラメータをサポートしない
        $supports_temperature = ( 'gpt-5.2-pro' !== $this->model );

        if ( $is_gpt5 ) {
            $url = self::API_BASE_URL . 'responses';
            $body = array(
                'model' => $this->model,
                'input' => $adjusted_prompt,
                'max_output_tokens' => $max_tokens,
            );
            if ( $supports_temperature ) {
                $body['temperature'] = $this->temperature;
            }
            $format = array( 'type' => 'text' );
            if ( ! empty( $response_format ) ) {
                if ( isset( $response_format['type'], $response_format['json_schema'] ) && 'json_schema' === $response_format['type'] ) {
                    $format = array_merge(
                        array( 'type' => 'json_schema' ),
                        $response_format['json_schema']
                    );
                } elseif ( isset( $response_format['type'] ) ) {
                    $format = $response_format;
                }
            }
            $body['text'] = array(
                'format' => $format,
            );
        } else {
            $url = self::API_BASE_URL . 'chat/completions';
            $body = array(
                'model' => $this->model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $adjusted_prompt
                    )
                ),
                'temperature' => $this->temperature,
                'max_tokens' => $max_tokens,
            );
            if ( ! empty( $response_format ) ) {
                $body['response_format'] = $response_format;
            }
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
            $content = $response['choices'][0]['message']['content'];
            if ( is_array( $content ) ) {
                $parts = array();
                foreach ( $content as $item ) {
                    if ( isset( $item['text'] ) ) {
                        $parts[] = $item['text'];
                    }
                }
                $text = implode( '', $parts );
            } else {
                $text = $content;
            }
        } elseif ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
            // gpt-5系のレスポンス形式: output配列内のmessageタイプを探す
            $parts = array();
            foreach ( $response['output'] as $output_item ) {
                if ( isset( $output_item['type'] ) && 'message' === $output_item['type'] && isset( $output_item['content'] ) ) {
                    foreach ( $output_item['content'] as $content_item ) {
                        if ( isset( $content_item['type'] ) && 'output_text' === $content_item['type'] && isset( $content_item['text'] ) ) {
                            $parts[] = $content_item['text'];
                        } elseif ( isset( $content_item['text'] ) ) {
                            $parts[] = $content_item['text'];
                        }
                    }
                }
            }
            $text = implode( '', $parts );
        } elseif ( isset( $response['output_text'] ) ) {
            $text = $response['output_text'];
        }

        if ( '' === $text ) {
            error_log( 'Blog Poster: OpenAI empty content response: ' . wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );
            return $this->error_response( __( 'OpenAIのレスポンスが空です。', 'blog-poster' ) );
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
