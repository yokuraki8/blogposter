<?php
/**
 * Primary source validator for external links.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Primary_Research_Validator {

    /**
     * @var array
     */
    private $settings = array();

    /**
     * @var array<string,array>
     */
    private $validation_cache = array();

    /**
     * @param array|null $settings Optional settings.
     */
    public function __construct( $settings = null ) {
        if ( is_array( $settings ) ) {
            $this->settings = $settings;
            return;
        }

        $this->settings = class_exists( 'Blog_Poster_Settings' )
            ? Blog_Poster_Settings::get_settings()
            : get_option( 'blog_poster_settings', array() );
    }

    /**
     * Whether primary research validation is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return ! empty( $this->settings['primary_research_enabled'] );
    }

    /**
     * Validate one external URL.
     *
     * @param string $url External URL.
     * @return array
     */
    public function validate_external_url( $url ) {
        $url = esc_url_raw( (string) $url );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return array(
                'valid' => false,
                'exists' => false,
                'credibility_passed' => false,
                'credibility_score' => 0,
                'status_code' => 0,
                'mode' => $this->get_mode(),
                'reasons' => array( 'URL形式が不正です。' ),
            );
        }

        if ( isset( $this->validation_cache[ $url ] ) ) {
            return $this->validation_cache[ $url ];
        }

        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( $this->is_blocked_domain( $host ) ) {
            $result = array(
                'valid' => false,
                'exists' => false,
                'credibility_passed' => false,
                'credibility_score' => 0,
                'status_code' => 0,
                'mode' => $this->get_mode(),
                'reasons' => array( 'ブロック対象ドメインのため除外しました。' ),
            );
            $this->validation_cache[ $url ] = $result;
            return $result;
        }

        $exists_required = $this->is_existence_check_enabled();
        $cred_required   = $this->is_credibility_check_enabled();
        $mode            = $this->get_mode();

        $exists_result = array(
            'exists' => true,
            'status_code' => 0,
            'content_type' => '',
            'last_modified' => '',
            'error' => '',
            'reasons' => array(),
        );

        if ( $exists_required ) {
            $exists_result = $this->check_url_existence( $url );
        }

        $score_result = $this->score_credibility( $url, $exists_result );
        $threshold    = $this->get_credibility_threshold();
        $cred_passed  = ! $cred_required || $score_result['score'] >= $threshold;

        $reasons = array_merge( $exists_result['reasons'], $score_result['reasons'] );
        if ( $cred_required && ! $cred_passed ) {
            $reasons[] = sprintf(
                '信頼性スコア不足: %d/%d',
                $score_result['score'],
                $threshold
            );
        }

        $valid = true;
        if ( ! $exists_result['exists'] ) {
            // URLの存在チェックは常に必須扱い。
            $valid = false;
        } elseif ( 'strict' === $mode && ! $cred_passed ) {
            $valid = false;
        }

        $result = array(
            'valid' => $valid,
            'exists' => (bool) $exists_result['exists'],
            'credibility_passed' => (bool) $cred_passed,
            'credibility_score' => (int) $score_result['score'],
            'status_code' => (int) $exists_result['status_code'],
            'mode' => $mode,
            'reasons' => array_values( array_unique( array_filter( $reasons ) ) ),
        );

        $this->validation_cache[ $url ] = $result;
        return $result;
    }

    /**
     * Validate markdown links and optionally strip invalid ones.
     *
     * @param string $markdown Markdown text.
     * @return array{markdown:string,reports:array}
     */
    public function filter_markdown_external_links( $markdown ) {
        $markdown = (string) $markdown;
        if ( '' === $markdown ) {
            return array(
                'markdown' => '',
                'reports' => array(),
            );
        }

        $reports = array();
        $pattern = '/\[(.*?)\]\((https?:\/\/[^\s\)]+)\)/u';

        $filtered = preg_replace_callback(
            $pattern,
            function( $matches ) use ( &$reports ) {
                $anchor = (string) $matches[1];
                $url    = esc_url_raw( (string) $matches[2] );

                if ( '' === $url ) {
                    return $matches[0];
                }

                $host      = (string) wp_parse_url( $url, PHP_URL_HOST );
                $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                if ( '' !== $site_host && $host === $site_host ) {
                    return $matches[0];
                }

                $report = $this->validate_external_url( $url );
                $reports[ $url ] = $report;

                if ( ! $report['valid'] ) {
                    return $anchor;
                }

                return $matches[0];
            },
            $markdown
        );

        $text_for_scan = is_string( $filtered ) ? $filtered : $markdown;
        foreach ( $this->extract_external_urls( $text_for_scan ) as $url ) {
            if ( isset( $reports[ $url ] ) ) {
                continue;
            }
            $reports[ $url ] = $this->validate_external_url( $url );
        }

        return array(
            'markdown' => is_string( $filtered ) ? $filtered : $markdown,
            'reports' => $reports,
        );
    }

    /**
     * Extract external URLs from markdown/html text.
     *
     * @param string $text Input text.
     * @return array
     */
    private function extract_external_urls( $text ) {
        $urls = array();
        $text = (string) $text;
        if ( '' === $text ) {
            return $urls;
        }

        if ( preg_match_all( '/\bhttps?:\/\/[^\s<>"\)\]]+/u', $text, $matches ) ) {
            foreach ( $matches[0] as $candidate ) {
                $candidate = esc_url_raw( (string) $candidate );
                if ( '' === $candidate ) {
                    continue;
                }
                $host = (string) wp_parse_url( $candidate, PHP_URL_HOST );
                $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                if ( '' !== $site_host && $host === $site_host ) {
                    continue;
                }
                $urls[] = $candidate;
            }
        }

        if ( preg_match_all( '/<a[^>]+href=["\'](https?:\/\/[^"\']+)["\']/iu', $text, $anchor_matches ) ) {
            foreach ( $anchor_matches[1] as $candidate ) {
                $candidate = esc_url_raw( (string) $candidate );
                if ( '' === $candidate ) {
                    continue;
                }
                $host = (string) wp_parse_url( $candidate, PHP_URL_HOST );
                $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                if ( '' !== $site_host && $host === $site_host ) {
                    continue;
                }
                $urls[] = $candidate;
            }
        }

        return array_values( array_unique( $urls ) );
    }

    /**
     * @return bool
     */
    private function is_existence_check_enabled() {
        return ! isset( $this->settings['external_link_existence_check_enabled'] )
            || ! empty( $this->settings['external_link_existence_check_enabled'] );
    }

    /**
     * @return bool
     */
    private function is_credibility_check_enabled() {
        return ! isset( $this->settings['external_link_credibility_check_enabled'] )
            || ! empty( $this->settings['external_link_credibility_check_enabled'] );
    }

    /**
     * @return string
     */
    private function get_mode() {
        $mode = isset( $this->settings['primary_research_mode'] )
            ? sanitize_key( $this->settings['primary_research_mode'] )
            : 'strict';
        return in_array( $mode, array( 'strict', 'warn' ), true ) ? $mode : 'strict';
    }

    /**
     * @return int
     */
    private function get_credibility_threshold() {
        $value = isset( $this->settings['primary_research_credibility_threshold'] )
            ? (int) $this->settings['primary_research_credibility_threshold']
            : 70;
        return max( 0, min( 100, $value ) );
    }

    /**
     * @return int
     */
    private function get_timeout_seconds() {
        $value = isset( $this->settings['primary_research_timeout_sec'] )
            ? (int) $this->settings['primary_research_timeout_sec']
            : 8;
        return max( 3, min( 30, $value ) );
    }

    /**
     * @return int
     */
    private function get_retry_count() {
        $value = isset( $this->settings['primary_research_retry_count'] )
            ? (int) $this->settings['primary_research_retry_count']
            : 2;
        return max( 0, min( 3, $value ) );
    }

    /**
     * @param string $url URL.
     * @return array
     */
    private function check_url_existence( $url ) {
        $timeout = $this->get_timeout_seconds();
        $retries = $this->get_retry_count();
        $max_attempts = $retries + 1;

        $reasons = array();
        $status_code = 0;
        $content_type = '';
        $last_modified = '';
        $exists = false;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $args = array(
                'timeout'     => $timeout,
                'redirection' => 5,
                'user-agent'  => 'BlogPoster/1.0 (+WordPress)',
            );

            $response = wp_remote_head( $url, $args );
            if ( is_wp_error( $response ) ) {
                $response = wp_remote_get( $url, $args );
            } else {
                $status_code = (int) wp_remote_retrieve_response_code( $response );
                if ( in_array( $status_code, array( 405, 501 ), true ) ) {
                    $response = wp_remote_get( $url, $args );
                }
            }

            if ( is_wp_error( $response ) ) {
                $reasons[] = 'HTTPリクエスト失敗: ' . $response->get_error_message();
                if ( $attempt < $max_attempts ) {
                    continue;
                }
                break;
            }

            $status_code   = (int) wp_remote_retrieve_response_code( $response );
            $content_type  = (string) wp_remote_retrieve_header( $response, 'content-type' );
            $last_modified = (string) wp_remote_retrieve_header( $response, 'last-modified' );

            if ( $status_code >= 200 && $status_code < 300 ) {
                $exists = true;
                break;
            }

            if ( in_array( $status_code, array( 404, 410 ), true ) ) {
                $reasons[] = 'リンク先が存在しません（HTTP ' . $status_code . '）。';
                break;
            }

            if ( in_array( $status_code, array( 408, 429, 500, 502, 503, 504 ), true ) && $attempt < $max_attempts ) {
                $reasons[] = 'HTTP ' . $status_code . ' で再試行します。';
                continue;
            }

            $reasons[] = 'HTTPステータスが不正です: ' . $status_code;
            break;
        }

        if ( $exists ) {
            $reasons[] = 'リンク先の実在を確認しました。';
        }

        return array(
            'exists' => $exists,
            'status_code' => $status_code,
            'content_type' => $content_type,
            'last_modified' => $last_modified,
            'reasons' => $reasons,
        );
    }

    /**
     * @param string $url URL.
     * @param array  $exists_result Existence check result.
     * @return array{score:int,reasons:array}
     */
    private function score_credibility( $url, $exists_result ) {
        $score = 0;
        $reasons = array();
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );

        if ( 0 === stripos( $url, 'https://' ) ) {
            $score += 10;
            $reasons[] = 'HTTPSを使用しています。';
        }

        if ( $this->is_allowed_domain( $host ) ) {
            $score += 40;
            $reasons[] = '許可ドメインに一致します。';
        }

        $trusted_suffixes = array( '.go.jp', '.gov', '.ac.jp', '.edu', '.or.jp', '.org', '.int' );
        foreach ( $trusted_suffixes as $suffix ) {
            if ( '' !== $host && str_ends_with( strtolower( $host ), strtolower( $suffix ) ) ) {
                $score += 25;
                $reasons[] = '公的・学術系ドメインです。';
                break;
            }
        }

        if ( preg_match( '#/(press|news|research|report|whitepaper|statistics|docs|document)#i', $path ) ) {
            $score += 10;
            $reasons[] = '一次情報を示すURLパターンです。';
        }

        $content_type = isset( $exists_result['content_type'] ) ? strtolower( (string) $exists_result['content_type'] ) : '';
        if ( '' !== $content_type && ( false !== strpos( $content_type, 'text/html' ) || false !== strpos( $content_type, 'application/pdf' ) ) ) {
            $score += 10;
            $reasons[] = '参照しやすいコンテンツ形式です。';
        }

        if ( ! empty( $exists_result['last_modified'] ) ) {
            $score += 5;
            $reasons[] = '更新情報がヘッダに存在します。';
        }

        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( strlen( $query ) > 120 ) {
            $score -= 5;
            $reasons[] = '長いクエリ文字列を含むため減点します。';
        }

        $low_trust_hosts = array( 'medium.com', 'note.com', 'qiita.com' );
        foreach ( $low_trust_hosts as $low_trust_host ) {
            if ( '' !== $host && ( $host === $low_trust_host || str_ends_with( $host, '.' . $low_trust_host ) ) ) {
                $score -= 8;
                $reasons[] = 'UGC中心ドメインのため補助情報として扱います。';
                break;
            }
        }

        $score = max( 0, min( 100, $score ) );
        return array(
            'score' => $score,
            'reasons' => $reasons,
        );
    }

    /**
     * @param string $host Hostname.
     * @return bool
     */
    private function is_allowed_domain( $host ) {
        if ( '' === $host ) {
            return false;
        }
        $allowed = $this->parse_domain_list( $this->settings['primary_research_allowed_domains'] ?? '' );
        foreach ( $allowed as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $host Hostname.
     * @return bool
     */
    private function is_blocked_domain( $host ) {
        if ( '' === $host ) {
            return false;
        }
        $blocked = $this->parse_domain_list( $this->settings['primary_research_blocked_domains'] ?? '' );
        foreach ( $blocked as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $raw Raw text list.
     * @return array
     */
    private function parse_domain_list( $raw ) {
        $raw = strtolower( (string) $raw );
        $parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return array();
        }

        $domains = array();
        foreach ( $parts as $part ) {
            $part = preg_replace( '#^https?://#', '', trim( $part ) );
            $part = trim( (string) $part, " \t\n\r\0\x0B/" );
            if ( '' === $part ) {
                continue;
            }
            $domains[] = $part;
        }

        return array_values( array_unique( $domains ) );
    }
}
