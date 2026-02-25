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

    /**
     * External URL validation cache.
     *
     * @var array<string,bool>
     */
    private $external_url_validation_cache = array();

    /**
     * @var Blog_Poster_Primary_Research_Validator|null
     */
    private $primary_research_validator = null;

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

        $text = $this->sanitize_generated_text( $response, $task_type );

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

        if ( in_array( $task_type, array( 'internal_links', 'external_links' ), true ) ) {
            $entries = $this->parse_link_suggestions( $suggestion['content'], $task_type );
            if ( empty( $entries ) ) {
                $message = 'リンク提案の形式が不正です。';
                if ( 'external_links' === $task_type ) {
                    $message = '有効な外部リンクが見つかりませんでした（404ページは除外されます）。';
                }
                return new WP_Error( 'invalid_links', $message );
            }
            $suggestion['content'] = $this->build_link_suggestions_text( $entries );
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
            case 'internal_links':
            case 'external_links':
                $entries = $this->parse_link_suggestions( $content, $task_type );
                if ( empty( $entries ) ) {
                    $message = 'リンク提案の形式が不正です。';
                    if ( 'external_links' === $task_type ) {
                        $message = '有効な外部リンクが見つかりませんでした（404ページは除外されます）。';
                    }
                    return new WP_Error( 'invalid_links', $message );
                }
                $updated = $this->append_link_list_to_content( $new_content, $task_type, $entries );
                if ( $updated === $new_content ) {
                    return new WP_Error( 'duplicate_links', '追加可能な新しいリンクが見つかりませんでした。' );
                }
                $new_content = $updated;
                $updated_content = $new_content;
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
        if ( false !== mb_strpos( $title, '内部リンク' ) ) {
            return 'internal_links';
        }
        if ( false !== mb_strpos( $title, '外部リンク' ) ) {
            return 'external_links';
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
            'post_id' => (int) $post_id,
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'summary' => $summary,
            'headings' => $headings,
            'internal_candidates' => $this->get_internal_link_candidates( $post_id, 8 ),
        );
    }

    private function build_prompt( $task_type, $context ) {
        $title = $context['title'] ?? '';
        $excerpt = $context['excerpt'] ?? '';
        $summary = $context['summary'] ?? '';
        $headings = isset( $context['headings'] ) && is_array( $context['headings'] ) ? implode( ' / ', $context['headings'] ) : '';
        $internal_candidates = isset( $context['internal_candidates'] ) && is_array( $context['internal_candidates'] )
            ? $context['internal_candidates']
            : array();

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
            case 'internal_links':
                $candidates = '';
                if ( ! empty( $internal_candidates ) ) {
                    $rows = array();
                    foreach ( $internal_candidates as $candidate ) {
                        $rows[] = ( $candidate['title'] ?? '' ) . ' | ' . ( $candidate['url'] ?? '' );
                    }
                    $candidates = implode( "\n", $rows );
                }
                return $base . "\n"
                    . "目的: 記事に追加する内部リンク候補を2〜3件作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "見出し: {$headings}\n"
                    . "要約: " . mb_substr( $summary, 0, 350 ) . "\n"
                    . ( $candidates !== '' ? "利用可能な内部リンク候補（タイトル | URL）:\n{$candidates}\n" : '' )
                    . "制約: URLは必ず同一サイト内のみ。\n"
                    . "出力形式: 1行につき「アンカーテキスト | URL | 追加文（30-80文字）」で2〜3行。";
            case 'external_links':
                $primary_research_note = '';
                if ( $this->is_primary_research_enabled() ) {
                    $primary_research_note = "制約: 一次情報（公式発表・公的機関・原典資料）を優先し、リンク切れURLは提案しない。";
                }
                return $base . "\n"
                    . "目的: 記事に追加する外部リンク候補を2件作成してください。\n"
                    . "タイトル: {$title}\n"
                    . "見出し: {$headings}\n"
                    . "要約: " . mb_substr( $summary, 0, 350 ) . "\n"
                    . "制約: 公式サイト・公的機関・主要メディアなど信頼できるURLのみ。URLは https:// から始める。\n"
                    . ( '' !== $primary_research_note ? $primary_research_note . "\n" : '' )
                    . "出力形式: 1行につき「アンカーテキスト | URL | 追加文（30-80文字）」で2行。";
        }

        return '';
    }

    private function get_max_tokens_for_task( $task_type ) {
        switch ( $task_type ) {
            case 'missing_cta':
                return 450;
            case 'meta_description':
                return 480;
            case 'external_links':
                return 900;
            case 'internal_links':
                return 1000;
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

    private function sanitize_generated_text( $text, $task_type = '' ) {
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return '';
        }
        $text = preg_replace( '/```[\\s\\S]*?```/u', '', $text );
        $text = preg_replace( '/^[\"“”「『]+/u', '', $text );
        $text = preg_replace( '/[\"“”」』]+$/u', '', $text );
        $text = wp_strip_all_tags( $text );
        if ( in_array( $task_type, array( 'internal_links', 'external_links' ), true ) ) {
            $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
            $text = preg_replace( '/[ \t]+/u', ' ', $text );
            $text = preg_replace( "/\n{3,}/u", "\n\n", $text );
        } else {
            $text = preg_replace( '/\\s+/u', ' ', $text );
        }
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

    private function get_internal_link_candidates( $post_id, $limit = 8 ) {
        $query = new WP_Query(
            array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => max( 1, (int) $limit ),
                'post__not_in' => array( (int) $post_id ),
                'orderby' => 'date',
                'order' => 'DESC',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
            )
        );

        $items = array();
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $url = get_permalink( $post->ID );
                if ( ! $url ) {
                    continue;
                }
                $items[] = array(
                    'title' => get_the_title( $post->ID ),
                    'url' => $url,
                );
            }
        }
        wp_reset_postdata();

        return $items;
    }

    private function parse_link_suggestions( $content, $task_type ) {
        $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $lines = preg_split( "/\n+/u", (string) $content );
        $entries = array();
        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/^[\-\*\d\.\)\s・]+/u', '', $line ) );
            if ( '' === $line ) {
                continue;
            }

            $parts = array_map( 'trim', explode( '|', $line ) );
            if ( count( $parts ) < 2 ) {
                continue;
            }

            $anchor = sanitize_text_field( $parts[0] );
            $url = trim( $parts[1] );
            $note = isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '';
            if ( '' === $anchor || '' === $url ) {
                continue;
            }

            if ( 0 === strpos( $url, '/' ) ) {
                $url = home_url( $url );
            }
            $url = esc_url_raw( $url );
            if ( '' === $url || ! wp_http_validate_url( $url ) ) {
                continue;
            }

            $url_host = (string) wp_parse_url( $url, PHP_URL_HOST );
            if ( 'internal_links' === $task_type && ( '' === $url_host || $url_host !== $site_host ) ) {
                continue;
            }
            if ( 'external_links' === $task_type && ( '' === $url_host || $url_host === $site_host ) ) {
                continue;
            }
            if ( 'external_links' === $task_type ) {
                $validation = $this->get_primary_research_validator()->validate_external_url( $url );
                if ( ! $validation['valid'] ) {
                    continue;
                }
            }

            $entries[] = array(
                'anchor' => $anchor,
                'url' => $url,
                'note' => $note,
            );
        }

        return $entries;
    }

    private function build_link_suggestions_text( $entries ) {
        $lines = array();
        foreach ( $entries as $entry ) {
            $anchor = sanitize_text_field( $entry['anchor'] ?? '' );
            $url = esc_url_raw( $entry['url'] ?? '' );
            $note = sanitize_text_field( $entry['note'] ?? '' );
            if ( '' === $anchor || '' === $url ) {
                continue;
            }
            $line = $anchor . ' | ' . $url;
            if ( '' !== $note ) {
                $line .= ' | ' . $note;
            }
            $lines[] = $line;
        }

        return implode( "\n", $lines );
    }

    private function is_external_reference_url_valid( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( '' === $url ) {
            return false;
        }

        if ( isset( $this->external_url_validation_cache[ $url ] ) ) {
            return (bool) $this->external_url_validation_cache[ $url ];
        }

        $validation = $this->get_primary_research_validator()->validate_external_url( $url );
        $valid = ! empty( $validation['valid'] );
        $this->external_url_validation_cache[ $url ] = $valid;

        return $valid;
    }

    /**
     * @return Blog_Poster_Primary_Research_Validator
     */
    private function get_primary_research_validator() {
        if ( null !== $this->primary_research_validator ) {
            return $this->primary_research_validator;
        }

        if ( ! class_exists( 'Blog_Poster_Primary_Research_Validator' ) ) {
            // Fallback: treat as permissive validator when class is unavailable (e.g. isolated tests).
            $this->primary_research_validator = new class() {
                public function validate_external_url( $url ) {
                    return array( 'valid' => true );
                }
            };
            return $this->primary_research_validator;
        }

        $this->primary_research_validator = new Blog_Poster_Primary_Research_Validator(
            $this->get_effective_settings()
        );
        return $this->primary_research_validator;
    }

    /**
     * @return bool
     */
    private function is_primary_research_enabled() {
        return ! empty( $this->get_effective_settings()['primary_research_enabled'] );
    }

    private function append_link_list_to_content( $content, $task_type, $entries ) {
        $existing_urls = array();
        if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
            $existing_urls = array_map( 'esc_url_raw', $matches[1] );
        }

        $list_items = array();
        foreach ( $entries as $entry ) {
            $url = esc_url_raw( $entry['url'] );
            if ( in_array( $url, $existing_urls, true ) ) {
                continue;
            }
            $existing_urls[] = $url;

            $anchor = esc_html( $entry['anchor'] );
            $note = trim( (string) ( $entry['note'] ?? '' ) );
            $note_html = '' !== $note ? ' - ' . esc_html( $note ) : '';
            $list_items[] = '<li><a href="' . esc_url( $url ) . '">' . $anchor . '</a>' . $note_html . '</li>';
        }

        if ( empty( $list_items ) ) {
            return $content;
        }

        $heading = 'internal_links' === $task_type ? '関連記事' : '参考リンク';
        $section = "\n\n<h2>" . esc_html( $heading ) . "</h2>\n<ul>\n" . implode( "\n", $list_items ) . "\n</ul>";

        return rtrim( $content ) . $section;
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
        return Blog_Poster_Settings::get_settings();
    }

    private function is_yoast_enabled() {
        $settings = Blog_Poster_Settings::get_settings();
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
