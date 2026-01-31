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
        $this->api_key = $api_key;
        $this->model = $model;
        $this->temperature = isset( $options['temperature'] ) ? $options['temperature'] : 0.7;
        $this->max_tokens = isset( $options['max_tokens'] ) ? $options['max_tokens'] : 2000;
    }

    /**
     * テキスト生成（抽象メソッド）
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    abstract public function generate_text( $prompt, $response_format = null );

    /**
     * 画像生成（抽象メソッド）
     *
     * @param string $prompt プロンプト
     * @return array レスポンス
     */
    abstract public function generate_image( $prompt );

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
            'timeout' => 180,
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'Blog Poster API Error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
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

            return new WP_Error(
                'api_error',
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
        $settings = get_option( 'blog_poster_settings', array() );

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
    protected function error_response( $message ) {
        return array(
            'success' => false,
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
}
