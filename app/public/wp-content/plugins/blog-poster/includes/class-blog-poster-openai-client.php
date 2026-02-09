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
     * モデル構成
     *
     * @param string $model モデル名
     * @return array
     */
    private function get_model_config( $model ) {
        $is_responses_api = ( 0 === strpos( $model, 'gpt-5' ) );
        return array(
            'endpoint' => $is_responses_api ? 'responses' : 'chat/completions',
            'uses_responses_api' => $is_responses_api,
            'supports_temperature' => ! $is_responses_api,
            'token_field' => $is_responses_api ? 'max_output_tokens' : 'max_tokens',
        );
    }

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

        $model_config = $this->get_model_config( $this->model );
        $supports_temperature = $model_config['supports_temperature'];

        // リトライロジック
        $max_retries = 2;
        $last_error = null;

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            if ( $attempt === 0 ) {
                $len = is_string( $this->api_key ) ? strlen( $this->api_key ) : 0;
                $enc_hint = is_string( $this->api_key ) && 0 === strpos( $this->api_key, 'enc::' ) ? 'yes' : 'no';
                error_log( 'Blog Poster: OpenAI key length=' . $len . ' encrypted_prefix=' . $enc_hint );
            }
            if ( $model_config['uses_responses_api'] ) {
                $url = self::API_BASE_URL . $model_config['endpoint'];
                $body = array(
                    'model' => $this->model,
                    'input' => $adjusted_prompt,
                    $model_config['token_field'] => $max_tokens,
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
                $url = self::API_BASE_URL . $model_config['endpoint'];
                $body = array(
                    'model' => $this->model,
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => $adjusted_prompt
                        )
                    ),
                    'temperature' => $this->temperature,
                    $model_config['token_field'] => $max_tokens,
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
                return $this->error_response( $response->get_error_message(), $response->get_error_code() );
            }

            // レスポンスからテキストとトークン数を抽出
            $text = '';
            $tokens = 0;

            if ( isset( $response['status'] ) && 'incomplete' === $response['status'] ) {
                $reason = '';
                if ( isset( $response['incomplete_details']['reason'] ) ) {
                    $reason = $response['incomplete_details']['reason'];
                }
                error_log( 'Blog Poster: OpenAI incomplete response (attempt ' . ( $attempt + 1 ) . '): ' . wp_json_encode( $response, JSON_UNESCAPED_UNICODE ) );

                // max_output_tokensが原因でリトライ可能な場合
                if ( 'max_output_tokens' === $reason && $attempt < $max_retries ) {
                    $old_max_tokens = $max_tokens;
                    $max_tokens = (int) ( $max_tokens * 1.5 );
                    error_log( 'Blog Poster: Retrying with increased max_tokens: ' . $old_max_tokens . ' -> ' . $max_tokens );
                    continue;
                }

                // リトライ上限到達またはリトライ不可な理由
                $last_error = sprintf(
                    __( 'OpenAIのレスポンスが不完全です（reason: %s）。', 'blog-poster' ),
                    $reason !== '' ? $reason : 'unknown'
                );
                if ( $attempt < $max_retries ) {
                    continue;
                } else {
                    return $this->error_response( $last_error );
                }
            }

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

            // 成功時はループを抜ける
            return $this->success_response( $text, $tokens );
        }

        // リトライがすべて失敗した場合のエラー返却（通常は上のループ内でreturnされるため到達しない）
        if ( ! empty( $last_error ) ) {
            return $this->error_response( $last_error );
        }

        return $this->error_response( __( 'OpenAIのレスポンスから有効なテキストを抽出できませんでした。', 'blog-poster' ) );
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
            return $this->error_response( $response->get_error_message(), $response->get_error_code() );
        }

        // レスポンスから画像URLを抽出
        $image_url = '';

        if ( isset( $response['data'][0]['url'] ) ) {
            $image_url = $response['data'][0]['url'];
        }

        return $this->success_response( $image_url );
    }
}
