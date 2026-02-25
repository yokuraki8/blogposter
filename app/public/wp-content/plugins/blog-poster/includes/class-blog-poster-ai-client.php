<?php
/**
 * AI APIクライアント抽象基底クラス
 *
 * @package BlogPoster
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Blog_Poster_AI_Client 抽象クラス
 */
abstract class Blog_Poster_AI_Client {
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * APIキー
     *
     * @var string
     */
    protected $api_key;

    /**
     * 使用するモデル
     *
     * @var string
     */
    protected $model;

    /**
     * Temperature設定
     *
     * @var float
     */
    protected $temperature;

    /**
     * 最大トークン数
     *
     * @var int
     */
    protected $max_tokens;

    /**
     * コンストラクタ
     *
     * @param string $api_key APIキー
     * @param string $model モデル名
     * @param array  $options オプション設定
     */
    public function __construct( $api_key, $model, $options = array() ) {
        if ( is_string( $api_key ) && 0 === strpos( $api_key, 'enc::' ) ) {
            if ( ! class_exists( 'Blog_Poster_Settings' ) && defined( 'BLOG_POSTER_PLUGIN_DIR' ) ) {
                require_once BLOG_POSTER_PLUGIN_DIR . 'includes/class-blog-poster-settings.php';
            }
            if ( class_exists( 'Blog_Poster_Settings' ) ) {
                $api_key = Blog_Poster_Settings::decrypt( $api_key );
            }
        }
        if ( is_string( $api_key ) && ( 0 === strpos( $api_key, 'enc::' ) || strlen( $api_key ) > 500 ) ) {
            error_log( 'Blog Poster: API key appears invalid after decrypt attempt.' );
            $api_key = '';
        }
        $this->api_key = $api_key;
        $this->model = $model;
        $this->temperature = isset( $options['temperature'] ) ? $options['temperature'] : 0.7;
        $this->max_tokens = isset( $options['max_tokens'] ) ? $options['max_tokens'] : 8000;
    }

    /**
     * テキスト生成（抽象メソッド）
     *
     * @param string $prompt プロンプト
     * @param array|null $options オプション（max_tokens, model等）
     * @return array レスポンス
     */
    abstract public function generate_text( $prompt, $options = null );

    /**
     * 画像生成（抽象メソッド）
     *
     * @param string $prompt プロンプト
     * @param array  $options オプション（size/quality/response_format等）
     * @return array レスポンス
     */
    abstract public function generate_image( $prompt, $options = array() );

    /**
     * APIリクエストを送信
     *
     * @param string $url エンドポイントURL
     * @param array  $body リクエストボディ
     * @param array  $headers ヘッダー
     * @return array|WP_Error レスポンスまたはエラー
     */
    protected function make_request( $url, $body, $headers = array() ) {
        // デバッグログ: リクエスト内容
        error_log( 'Blog Poster API Request - Model: ' . ( isset( $body['model'] ) ? $body['model'] : 'N/A' ) );
        error_log( 'Blog Poster API Request - URL: ' . $this->redact_api_key_from_url( $url ) );

        $args = array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
            'timeout' => 90,
            'cookies' => array(),
        );

        $response = null;
        $status_code = 0;
        $response_body = '';

        for ( $attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++ ) {
            if ( $attempt > 1 ) {
                $delay_ms = (int) ( 250 * pow( 2, $attempt - 2 ) );
                usleep( $delay_ms * 1000 );
            }

            $response = wp_remote_post( $url, $args );
            if ( is_wp_error( $response ) ) {
                if ( $attempt < self::MAX_RETRY_ATTEMPTS ) {
                    continue;
                }
                error_log( 'Blog Poster API Error: ' . $response->get_error_message() );
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            if ( $this->is_retryable_http_status( $status_code ) && $attempt < self::MAX_RETRY_ATTEMPTS ) {
                continue;
            }
            break;
        }

        // UTF-8検証と修復
        if ( ! mb_check_encoding( $response_body, 'UTF-8' ) ) {
            error_log( 'Blog Poster: Invalid UTF-8 in API response, attempting to fix.' );
            $response_body = mb_convert_encoding( $response_body, 'UTF-8', 'auto' );
        }
        // 不正なUTF-8シーケンスを除去
        $response_body = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $response_body );

        $data = json_decode( $response_body, true );

        if ( $status_code !== 200 ) {
            // デバッグログ: エラー詳細
            error_log( 'Blog Poster API Error - Status: ' . $status_code );
            error_log( 'Blog Poster API Error - Response: ' . $response_body );

            // エラーメッセージを詳細化
            $error_message = sprintf(
                __( 'APIエラー: ステータスコード %d', 'blog-poster' ),
                $status_code
            );

            // 詳細なエラー情報を追加
            $error_detail = '';
            if ( isset( $data['error']['message'] ) ) {
                $error_detail = $data['error']['message'];
                $error_message .= ' - ' . $error_detail;
            }

            // 具体的なエラー原因を判定
            $specific_error = '';
            if ( $status_code === 429 ) {
                $specific_error = 'APIレート制限に達しました。しばらく待ってからもう一度試してください。';
            } elseif ( $status_code === 403 ) {
                if ( stripos( $error_detail, 'credit' ) !== false || stripos( $response_body, 'credit' ) !== false ) {
                    $specific_error = 'APIクレジットが不足しています。設定ページでAPIキーと設定を確認してください。';
                } else {
                    $specific_error = 'APIアクセス権限がありません。APIキーが正しいか確認してください。';
                }
            } elseif ( $status_code === 401 ) {
                $specific_error = 'APIキーが無効です。設定ページで正しいAPIキーを入力してください。';
            } elseif ( $status_code === 400 ) {
                if ( stripos( $error_detail, 'model' ) !== false || stripos( $response_body, 'model' ) !== false ) {
                    $specific_error = 'サポートされていないモデルが指定されています。モデル設定を確認してください。';
                } else {
                    $specific_error = 'リクエスト形式に誤りがあります。プラグイン設定を確認してください。';
                }
            } elseif ( $status_code === 500 || $status_code === 502 || $status_code === 503 ) {
                $specific_error = 'AIサービスが一時的に利用できません。しばらく待ってからもう一度試してください。';
            }

            // 具体的なエラーが判定できた場合は追加
            if ( ! empty( $specific_error ) ) {
                $error_message = $specific_error . "\n（詳細: " . $error_message . "）";
            }

            $error_code = $this->map_error_code( $status_code, $error_detail, $response_body );
            return new WP_Error(
                $error_code,
                $error_message,
                $data
            );
        }

        return $data;
    }

    /**
     * URLに含まれるAPIキーをマスク
     *
     * @param string $url 対象URL
     * @return string マスク後URL
     */
    private function redact_api_key_from_url( $url ) {
        return preg_replace( '/([?&](?:key|api_key|apikey)=)[^&]+/i', '$1REDACTED', $url );
    }

    /**
     * プロンプトにトーン設定を追加
     *
     * @param string $base_prompt ベースプロンプト
     * @return string 調整されたプロンプト
     */
    protected function apply_tone_settings( $base_prompt ) {
        $settings = class_exists( 'Blog_Poster_Settings' )
            ? Blog_Poster_Settings::get_settings()
            : get_option( 'blog_poster_settings', array() );

        $formality = isset( $settings['formality'] ) ? intval( $settings['formality'] ) : 50;
        $expertise = isset( $settings['expertise'] ) ? intval( $settings['expertise'] ) : 50;
        $friendliness = isset( $settings['friendliness'] ) ? intval( $settings['friendliness'] ) : 50;

        $tone_instructions = "\n\n【文体とトーンの指示】\n";

        // フォーマル度
        if ( $formality < 30 ) {
            $tone_instructions .= "- カジュアルで親しみやすい文体を使用してください。\n";
        } elseif ( $formality > 70 ) {
            $tone_instructions .= "- フォーマルで丁寧な文体を使用してください。敬語を適切に使ってください。\n";
        } else {
            $tone_instructions .= "- ビジネスカジュアルな文体を使用してください。\n";
        }

        // 専門性
        if ( $expertise < 30 ) {
            $tone_instructions .= "- 専門用語は避け、一般読者にも分かりやすい表現を使ってください。\n";
        } elseif ( $expertise > 70 ) {
            $tone_instructions .= "- 専門用語を適切に使用し、業界の専門家向けの詳細な内容にしてください。\n";
        } else {
            $tone_instructions .= "- 専門用語は必要最小限にし、初めて聞く人にも分かるよう説明を加えてください。\n";
        }

        // 親しみやすさ
        if ( $friendliness < 30 ) {
            $tone_instructions .= "- 客観的で中立的なトーンを保ってください。\n";
        } elseif ( $friendliness > 70 ) {
            $tone_instructions .= "- 読者に語りかけるような親しみやすいトーンを使ってください。\n";
        } else {
            $tone_instructions .= "- 適度に親しみやすく、でも礼儀正しいトーンを保ってください。\n";
        }

        return $base_prompt . $tone_instructions;
    }

    /**
     * エラーレスポンスを生成
     *
     * @param string $message エラーメッセージ
     * @return array エラーレスポンス
     */
    protected function error_response( $message, $code = 'api_error' ) {
        return array(
            'success' => false,
            'error_code' => $code,
            'error'   => $message,
        );
    }

    /**
     * 成功レスポンスを生成
     *
     * @param mixed $data データ
     * @param int   $tokens 使用トークン数
     * @return array 成功レスポンス
     */
    protected function success_response( $data, $tokens = 0 ) {
        return array(
            'success' => true,
            'data'    => $data,
            'tokens'  => $tokens,
            'model'   => $this->model,
        );
    }

    /**
     * 使用中のモデル名を取得
     *
     * @return string
     */
    public function get_model() {
        return $this->model;
    }

    public function get_text_content( $response ) {
        if ( ! is_array( $response ) ) {
            return '';
        }
        if ( isset( $response['success'] ) && false === $response['success'] ) {
            return '';
        }
        return ( isset( $response['data'] ) && is_string( $response['data'] ) ) ? $response['data'] : '';
    }

    private function is_retryable_http_status( $status_code ) {
        $status_code = (int) $status_code;
        return in_array( $status_code, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true );
    }

    private function map_error_code( $status_code, $error_detail, $response_body ) {
        $status_code = (int) $status_code;
        $error_detail = is_string( $error_detail ) ? strtolower( $error_detail ) : '';
        $response_body = is_string( $response_body ) ? strtolower( $response_body ) : '';

        if ( 401 === $status_code ) {
            return 'api_auth_error';
        }
        if ( 403 === $status_code ) {
            if ( false !== strpos( $error_detail, 'credit' ) || false !== strpos( $response_body, 'credit' ) || false !== strpos( $error_detail, 'quota' ) || false !== strpos( $response_body, 'quota' ) ) {
                return 'api_insufficient_quota';
            }
            return 'api_forbidden';
        }
        if ( 429 === $status_code ) {
            return 'api_rate_limit';
        }
        if ( $status_code >= 500 ) {
            return 'api_server_error';
        }
        return 'api_error';
    }
}
