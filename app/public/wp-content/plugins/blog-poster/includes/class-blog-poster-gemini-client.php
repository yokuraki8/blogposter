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
    const DEFAULT_IMAGE_MODEL = 'gemini-2.5-flash-image';

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
     * @param array  $options オプション
     * @return array レスポンス
     */
    public function generate_image( $prompt, $options = array() ) {
        if ( empty( $this->api_key ) ) {
            return $this->error_response( __( 'Gemini APIキーが設定されていません。', 'blog-poster' ) );
        }

        $requested_model = isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : self::DEFAULT_IMAGE_MODEL;
        $model_candidates = $this->resolve_image_model_candidates( $requested_model );

        $size = isset( $options['size'] ) ? sanitize_text_field( $options['size'] ) : '1024x1024';
        $aspect_ratio = $this->to_aspect_ratio( $size );

        $headers = array(
            'Content-Type' => 'application/json',
        );

        $last_error = null;

        foreach ( $model_candidates as $model ) {
            // 1) generateContent image response path.
            $url = self::API_BASE_URL . $model . ':generateContent?key=' . $this->api_key;
            $body = array(
                'contents' => array(
                    array(
                        'parts' => array(
                            array(
                                'text' => $prompt,
                            ),
                        ),
                    ),
                ),
                'generationConfig' => array(
                    'responseModalities' => array( 'IMAGE' ),
                ),
            );
            if ( '' !== $aspect_ratio ) {
                $body['generationConfig']['imageConfig'] = array(
                    'aspectRatio' => $aspect_ratio,
                );
            }

            $response = $this->make_request( $url, $body, $headers );
            if ( ! is_wp_error( $response ) ) {
                $data = $this->extract_inline_image_data( $response );
                if ( '' !== $data ) {
                    return $this->success_response( $data );
                }
            } else {
                $last_error = $response;
                if ( $this->is_model_unsupported_error( $response->get_error_message() ) ) {
                    error_log( 'Blog Poster Gemini: image model unsupported for generateContent, trying predict fallback for model: ' . $model );
                }
            }

            // 2) Fallback: predict style endpoint.
            $predict_url = self::API_BASE_URL . $model . ':predict?key=' . $this->api_key;
            $predict_body = array(
                'instances' => array(
                    array(
                        'prompt' => $prompt,
                    ),
                ),
                'parameters' => array(
                    'sampleCount' => 1,
                ),
            );
            if ( '' !== $aspect_ratio ) {
                $predict_body['parameters']['aspectRatio'] = $aspect_ratio;
            }

            $predict_response = $this->make_request( $predict_url, $predict_body, $headers );
            if ( is_wp_error( $predict_response ) ) {
                $last_error = $predict_response;
                if ( $this->is_model_unsupported_error( $predict_response->get_error_message() ) ) {
                    error_log( 'Blog Poster Gemini: image model unsupported for predict, trying next model: ' . $model );
                    continue;
                }
                return $this->error_response( $predict_response->get_error_message(), $predict_response->get_error_code() );
            }

            $data = $this->extract_prediction_image_data( $predict_response );
            if ( '' !== $data ) {
                return $this->success_response( $data );
            }
        }

        if ( is_wp_error( $last_error ) ) {
            return $this->error_response( $last_error->get_error_message(), $last_error->get_error_code() );
        }

        return $this->error_response( __( 'Gemini画像生成のレスポンスから画像データを取得できませんでした。', 'blog-poster' ) );
    }

    /**
     * Resolve image model candidates with fallbacks.
     *
     * @param string $requested_model Requested model.
     * @return array
     */
    private function resolve_image_model_candidates( $requested_model ) {
        $requested_model = sanitize_text_field( (string) $requested_model );
        $requested_model = preg_replace( '#^models/#', '', $requested_model );
        if ( '' === $requested_model ) {
            $requested_model = self::DEFAULT_IMAGE_MODEL;
        }

        $candidates = array(
            $requested_model,
            self::DEFAULT_IMAGE_MODEL,
            'gemini-2.0-flash-exp-image-generation',
            'imagen-4.0-fast-generate-001',
        );

        $unique = array();
        foreach ( $candidates as $candidate ) {
            $candidate = sanitize_text_field( (string) $candidate );
            $candidate = preg_replace( '#^models/#', '', $candidate );
            if ( '' === $candidate || in_array( $candidate, $unique, true ) ) {
                continue;
            }
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * Check whether an error indicates the model is unsupported/not found.
     *
     * @param string $message Error message.
     * @return bool
     */
    private function is_model_unsupported_error( $message ) {
        $message = strtolower( (string) $message );
        return (
            false !== strpos( $message, 'not found' ) ||
            false !== strpos( $message, 'not supported' ) ||
            false !== strpos( $message, 'unsupported for predict' ) ||
            false !== strpos( $message, 'unsupported for generatecontent' )
        );
    }

    /**
     * Map pixel size to Gemini aspect ratio.
     *
     * @param string $size Size string.
     * @return string
     */
    private function to_aspect_ratio( $size ) {
        $map = array(
            '1024x1024' => '1:1',
            '1536x1024' => '3:2',
            '1024x1536' => '2:3',
            '1792x1024' => '16:9',
            '1024x1792' => '9:16',
        );
        return isset( $map[ $size ] ) ? $map[ $size ] : '1:1';
    }

    /**
     * Extract inline image data from generateContent response.
     *
     * @param array $response API response.
     * @return string
     */
    private function extract_inline_image_data( $response ) {
        if ( ! isset( $response['candidates'][0]['content']['parts'] ) || ! is_array( $response['candidates'][0]['content']['parts'] ) ) {
            return '';
        }
        foreach ( $response['candidates'][0]['content']['parts'] as $part ) {
            if ( isset( $part['inlineData']['data'] ) && is_string( $part['inlineData']['data'] ) && '' !== $part['inlineData']['data'] ) {
                $mime = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
                return 'data:' . $mime . ';base64,' . $part['inlineData']['data'];
            }
        }
        return '';
    }

    /**
     * Extract image data from predict response.
     *
     * @param array $response API response.
     * @return string
     */
    private function extract_prediction_image_data( $response ) {
        if ( ! isset( $response['predictions'][0] ) || ! is_array( $response['predictions'][0] ) ) {
            return '';
        }
        $pred = $response['predictions'][0];
        if ( isset( $pred['bytesBase64Encoded'] ) && is_string( $pred['bytesBase64Encoded'] ) && '' !== $pred['bytesBase64Encoded'] ) {
            return 'data:image/png;base64,' . $pred['bytesBase64Encoded'];
        }
        if ( isset( $pred['mimeType'], $pred['image']['base64'] ) && is_string( $pred['image']['base64'] ) && '' !== $pred['image']['base64'] ) {
            return 'data:' . $pred['mimeType'] . ';base64,' . $pred['image']['base64'];
        }
        return '';
    }
}
