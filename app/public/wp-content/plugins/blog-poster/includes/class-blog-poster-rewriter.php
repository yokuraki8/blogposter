<?php
/**
 * Rewrite helper for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Rewriter {

    public function generate_suggestion( $content, $task_type, $context = array() ) {
        $prompt = $this->build_prompt( $task_type, $context );
        if ( '' === $prompt ) {
            return array(
                'content' => null,
                'reason' => '対応できないタスク種別です。',
                'confidence_score' => 0,
            );
        }

        $max_tokens = $this->get_max_tokens_for_task( $task_type );
        $response = $this->request_ai_text( $prompt, $max_tokens );
        if ( is_wp_error( $response ) ) {
            return array(
                'content' => null,
                'reason' => $response->get_error_message(),
                'confidence_score' => 0,
            );
        }

        $text = $this->sanitize_generated_text( $response );

        return array(
            'content' => $text,
            'reason' => 'AI提案（自動生成）',
            'confidence_score' => 72,
        );
    }

    public function generate_lead_paragraph( $post_id ) {
        $context = $this->build_context( $post_id );
        $suggestion = $this->generate_suggestion( $context['content'], 'missing_lead', $context );
        return $suggestion['content'];
    }

    public function improve_meta_description( $post_id ) {
        $context = $this->build_context( $post_id );
        $suggestion = $this->generate_suggestion( $context['content'], 'meta_description', $context );
        return $suggestion['content'];
    }

    public function generate_conclusion( $post_id ) {
        $context = $this->build_context( $post_id );
        $suggestion = $this->generate_suggestion( $context['content'], 'missing_conclusion', $context );
        return $suggestion['content'];
    }

    public function generate_cta( $post_id ) {
        $context = $this->build_context( $post_id );
        $suggestion = $this->generate_suggestion( $context['content'], 'missing_cta', $context );
        return $suggestion['content'];
    }

    public function rewrite_content( $post_id, $task ) {
        $task_type = $this->resolve_task_type( $task );
        if ( 'unsupported' === $task_type ) {
            return new WP_Error( 'unsupported_task', '対応できないタスク種別です。' );
        }

        $context = $this->build_context( $post_id );
        $suggestion = $this->generate_suggestion( $context['content'], $task_type, $context );

        if ( empty( $suggestion['content'] ) ) {
            return new WP_Error( 'rewrite_failed', $suggestion['reason'] );
        }

        return array(
            'task_type' => $task_type,
            'content' => $suggestion['content'],
            'suggestion' => $suggestion,
        );
    }

    public function apply_rewrite( $post_id, $task_id, $content ) {
        $manager = new Blog_Poster_Task_Manager();
        $task = $manager->get_task( $post_id, $task_id );
        if ( empty( $task ) ) {
            return new WP_Error( 'task_not_found', 'タスクが見つかりません。' );
        }

        $task_type = $this->resolve_task_type( $task );
        if ( 'unsupported' === $task_type ) {
            return new WP_Error( 'unsupported_task', '対応できないタスク種別です。' );
        }

        $content = is_string( $content ) ? trim( $content ) : '';
        if ( '' === $content ) {
            $result = $this->rewrite_content( $post_id, $task );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $content = $result['content'];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'post_not_found', '投稿が見つかりません。' );
        }

        $new_content = $post->post_content;
        $updated_meta = false;
        $updated_content = '';

        switch ( $task_type ) {
            case 'missing_lead':
                $lead_html = '<p>' . esc_html( $content ) . '</p>';
                $new_content = $lead_html . "\n\n" . ltrim( $new_content );
                $updated_content = $new_content;
                break;
            case 'missing_conclusion':
                $result = $this->replace_conclusion_section( $new_content, $content );
                if ( $result['replaced'] ) {
                    $new_content = $result['content'];
                } else {
                    $conclusion_html = "\n\n<h2>まとめ</h2>\n<p>" . esc_html( $content ) . '</p>';
                    $new_content = rtrim( $new_content ) . $conclusion_html;
                }
                $updated_content = $new_content;
                break;
            case 'missing_cta':
                $cta_html = "\n\n<p><strong>次のステップ:</strong> " . esc_html( $content ) . '</p>';
                $new_content = rtrim( $new_content ) . $cta_html;
                $updated_content = $new_content;
                break;
            case 'meta_description':
                $meta = $this->trim_text( $content, 160 );
                if ( $meta !== '' ) {
                    update_post_meta( $post_id, '_blog_poster_meta_description', $meta );
                    if ( $this->is_yoast_enabled() ) {
                        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );
                    }
                    $updated_meta = true;
                }
                break;
        }

        if ( ! $updated_meta ) {
            $update_id = wp_update_post(
                array(
                    'ID' => $post_id,
                    'post_content' => $new_content,
                ),
                true
            );
            if ( is_wp_error( $update_id ) ) {
                return $update_id;
            }
        }

        $manager->update_task_result(
            $post_id,
            $task_id,
            array(
                'rewritten_content' => $content,
                'applied_at' => time(),
                'is_approved' => true,
            ),
            'completed'
        );

        return array(
            'task_id' => $task_id,
            'task_type' => $task_type,
            'content' => $content,
            'updated_content' => $updated_content,
            'updated_meta' => $updated_meta,
        );
    }

    private function resolve_task_type( $task ) {
        if ( ! empty( $task['rec_type'] ) ) {
            return $task['rec_type'];
        }
        $title = isset( $task['title'] ) ? $task['title'] : '';
        $section = isset( $task['section'] ) ? $task['section'] : '';

        if ( false !== mb_strpos( $title, 'リード' ) ) {
            return 'missing_lead';
        }
        if ( false !== mb_strpos( $title, '結論' ) || 'conclusion' === $section ) {
            return 'missing_conclusion';
        }
        if ( false !== mb_strpos( $title, 'CTA' ) ) {
            return 'missing_cta';
        }
        if ( false !== mb_strpos( $title, 'ディスクリプション' ) ) {
            return 'meta_description';
        }

        return 'unsupported';
    }

    private function build_context( $post_id ) {
        $post = get_post( $post_id );
        $title = $post ? $post->post_title : '';
        $content = $post ? $post->post_content : '';
        $excerpt = $post ? $post->post_excerpt : '';
        $plain = wp_strip_all_tags( $content );
        $plain = preg_replace( '/\s+/u', ' ', trim( $plain ) );
        $summary = mb_substr( $plain, 0, 1200 );
        $headings = $this->extract_h2_titles( $content );

        return array(
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'summary' => $summary,
            'headings' => $headings,
        );
    }

    private function build_prompt( $task_type, $context ) {
        $title = $context['title'] ?? '';
        $excerpt = $context['excerpt'] ?? '';
        $summary = $context['summary'] ?? '';
        $headings = isset( $context['headings'] ) && is_array( $context['headings'] ) ? implode( ' / ', $context['headings'] ) : '';

        $base = "あなたは日本語のSEO編集者です。HTMLやMarkdownを使わず、平文のみで出力してください。引用符や箇条書き、コードブロックは禁止です。";

        switch ( $task_type ) {
            case 'missing_lead':
                return $base . "\n"
                    . "目的: 記事の冒頭に置くリード文（150-250文字）を作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "見出し: {$headings}\n"
                    . "要約: " . mb_substr( $summary, 0, 300 ) . "\n"
                    . "出力: リード文のみ。";
            case 'missing_conclusion':
                return $base . "\n"
                    . "目的: 記事末尾のまとめ文（200-300文字）を作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "見出し: {$headings}\n"
                    . "要約: " . mb_substr( $summary, 0, 300 ) . "\n"
                    . "出力: まとめ文のみ。";
            case 'missing_cta':
                return $base . "\n"
                    . "目的: 記事末尾に追加するCTA文（80-140文字）を作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "見出し: {$headings}\n"
                    . "要約: " . mb_substr( $summary, 0, 300 ) . "\n"
                    . "出力: CTA文のみ。";
            case 'meta_description':
                return $base . "\n"
                    . "目的: メタディスクリプション（120-160文字）を作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "抜粋: {$excerpt}\n"
                    . "要約: " . mb_substr( $summary, 0, 300 ) . "\n"
                    . "出力: メタディスクリプションのみ。";
        }

        return '';
    }

    private function get_max_tokens_for_task( $task_type ) {
        switch ( $task_type ) {
            case 'missing_cta':
                return 450;
            case 'meta_description':
                return 480;
            case 'missing_lead':
                return 1000;
            case 'missing_conclusion':
            default:
                return 1200;
        }
    }

    private function extract_h2_titles( $content ) {
        $titles = array();
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\\/h2>/is', $content, $matches ) ) {
            foreach ( $matches[1] as $item ) {
                $titles[] = trim( wp_strip_all_tags( $item ) );
            }
        }
        return array_slice( $titles, 0, 8 );
    }

    private function sanitize_generated_text( $text ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }
        $text = preg_replace( '/```[\\s\\S]*?```/u', '', $text );
        $text = preg_replace( '/^[\"“”「『]+/u', '', $text );
        $text = preg_replace( '/[\"“”」』]+$/u', '', $text );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\\s+/u', ' ', $text );
        return trim( $text );
    }

    private function trim_text( $text, $max_length ) {
        $text = preg_replace( '/\\s+/u', ' ', trim( (string) $text ) );
        if ( mb_strlen( $text ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length );
        }
        return $text;
    }

    private function request_ai_text( $prompt, $max_tokens ) {
        $client = $this->get_ai_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }
        $response = $client->generate_text( $prompt, array( 'max_tokens' => $max_tokens ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( ! is_array( $response ) || empty( $response['success'] ) ) {
            $message = is_array( $response ) && isset( $response['error'] ) ? $response['error'] : 'AI応答に失敗しました。';
            return new WP_Error( 'ai_error', $message );
        }
        return isset( $response['data'] ) ? $response['data'] : '';
    }

    private function replace_conclusion_section( $html, $text ) {
        $replacement = "\n<p>" . esc_html( $text ) . '</p>';
        $pattern = '/(<h[23][^>]*>\\s*(まとめ|結論|総括)\\s*<\\/h[23]>)(.*?)(?=<h[23][^>]*>|\\z)/isu';
        $replaced = false;
        $updated = preg_replace_callback(
            $pattern,
            function ( $matches ) use ( $replacement, &$replaced ) {
                $replaced = true;
                return $matches[1] . $replacement;
            },
            $html,
            1
        );

        return array(
            'content' => $updated,
            'replaced' => $replaced,
        );
    }

    private function get_ai_client() {
        $settings = $this->get_effective_settings();
        $provider = isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'claude';

        $api_key = '';
        switch ( $provider ) {
            case 'gemini':
                $api_key = Blog_Poster_Settings::get_api_key( 'gemini', $settings );
                $model = isset( $settings['default_model']['gemini'] ) ? $settings['default_model']['gemini'] : 'gemini-2.5-pro';
                $client = new Blog_Poster_Gemini_Client( $api_key, $model, $settings );
                break;
            case 'claude':
                $api_key = Blog_Poster_Settings::get_api_key( 'claude', $settings );
                $model = isset( $settings['default_model']['claude'] ) ? $settings['default_model']['claude'] : 'claude-sonnet-4-5-20250929';
                $client = new Blog_Poster_Claude_Client( $api_key, $model, $settings );
                break;
            case 'openai':
            default:
                $api_key = Blog_Poster_Settings::get_api_key( 'openai', $settings );
                $model = isset( $settings['default_model']['openai'] ) ? $settings['default_model']['openai'] : 'gpt-5.2';
                $client = new Blog_Poster_OpenAI_Client( $api_key, $model, $settings );
                break;
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'APIキーが設定されていません。', 'blog-poster' ) );
        }

        return $client;
    }

    private function get_effective_settings() {
        return get_option( 'blog_poster_settings', array() );
    }

    private function is_yoast_enabled() {
        $settings = get_option( 'blog_poster_settings', array() );
        $enabled = ! empty( $settings['enable_yoast_integration'] );
        if ( ! $enabled ) {
            return false;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return function_exists( 'is_plugin_active' ) && is_plugin_active( 'wordpress-seo/wp-seo.php' );
    }
}
