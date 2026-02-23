<?php
/**
 * Content Retriever for RAG feature
 *
 * Searches indexed content for topics related to the generated article.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Content_Retriever {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'blog_poster_content_index';
    }

    /**
     * Search related content by topic/keyword
     *
     * @param string $topic   Topic or keywords to search for
     * @param int    $limit   Maximum number of results (default 5)
     * @return array  Array of articles: [{ post_id, title, url, snippet, score }]
     */
    public function search_related( $topic, $limit = 5 ) {
        global $wpdb;

        if ( empty( $topic ) ) {
            return array();
        }

        // Build search terms from topic using multiple strategies
        $search_terms = $this->extract_search_terms( $topic );

        if ( empty( $search_terms ) ) {
            return array();
        }

        // Build LIKE conditions for each search term
        $where_parts  = array();
        $where_params = array();

        foreach ( $search_terms as $term ) {
            $like           = '%' . $wpdb->esc_like( $term ) . '%';
            $where_parts[]  = '(title LIKE %s OR content_text LIKE %s OR keywords LIKE %s)';
            $where_params[] = $like;
            $where_params[] = $like;
            $where_params[] = $like;
        }

        $where_sql = implode( ' OR ', $where_parts );

        $sql = $wpdb->prepare(
            "SELECT post_id, post_type, title, url, content_text, keywords FROM {$this->table_name} WHERE ($where_sql) LIMIT 50",
            $where_params
        );

        $rows = $wpdb->get_results( $sql );

        if ( empty( $rows ) ) {
            return array();
        }

        // Score each row
        $scored = array();
        foreach ( $rows as $row ) {
            $score = $this->calculate_score( $row, $search_terms );
            if ( $score > 0 ) {
                $scored[] = array(
                    'post_id' => (int) $row->post_id,
                    'title'   => $row->title,
                    'url'     => $row->url,
                    'snippet' => $this->make_snippet( $row->content_text, $search_terms ),
                    'score'   => $score,
                );
            }
        }

        // Sort by score descending
        usort( $scored, function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        return array_slice( $scored, 0, $limit );
    }

    /**
     * Extract search terms from a Japanese topic using multiple strategies
     *
     * @param string $topic
     * @return array
     */
    private function extract_search_terms( $topic ) {
        $terms = array();

        // Strategy 1: full topic (trimmed)
        $topic_clean = trim( $topic );
        if ( mb_strlen( $topic_clean, 'UTF-8' ) >= 4 ) {
            $terms[] = $topic_clean;
        }

        // Strategy 2: split on Japanese particles and punctuation
        $particles = array( 'の', 'で', 'に', 'が', 'は', 'を', 'と', 'から', 'まで', 'へ', 'より', 'によって', 'において', 'における', 'について', 'ために', 'による', '・', '｜', '【', '】', '（', '）', '「', '」', '、', '。', '：', ':', '!', '！', '?', '？', '/', '／' );

        $pattern = '/(' . implode( '|', array_map( function( $p ) { return preg_quote( $p, '/' ); }, $particles ) ) . ')/u';
        $parts   = preg_split( $pattern, $topic, -1, PREG_SPLIT_NO_EMPTY );

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( mb_strlen( $part, 'UTF-8' ) >= 2 ) {
                $terms[] = $part;
            }
        }

        // Strategy 3: extract substrings of 4-8 chars from topic
        $topic_len = mb_strlen( $topic_clean, 'UTF-8' );
        for ( $len = 8; $len >= 3; $len-- ) {
            for ( $start = 0; $start + $len <= $topic_len; $start++ ) {
                $substr = mb_substr( $topic_clean, $start, $len, 'UTF-8' );
                // Only add non-particle-heavy substrings
                if ( preg_match( '/\p{Han}|\p{Katakana}|\p{Hiragana}[^\x{3041}-\x{309F}]/u', $substr ) ) {
                    $terms[] = $substr;
                }
                // Limit to avoid too many terms
                if ( count( $terms ) > 30 ) {
                    break 2;
                }
            }
        }

        return array_unique( $terms );
    }


    /**
     * Calculate relevance score for a row
     *
     * @param object $row
     * @param array  $keywords
     * @return float
     */
    private function calculate_score( $row, $keywords ) {
        $score    = 0;
        $title_lc = mb_strtolower( $row->title, 'UTF-8' );
        $kw_lc    = mb_strtolower( $row->keywords, 'UTF-8' );
        $body_lc  = mb_strtolower( mb_substr( $row->content_text, 0, 2000, 'UTF-8' ), 'UTF-8' );

        foreach ( $keywords as $kw ) {
            $kw_lower = mb_strtolower( $kw, 'UTF-8' );
            if ( mb_strpos( $title_lc, $kw_lower ) !== false ) {
                $score += 2.0;
            }
            if ( mb_strpos( $kw_lc, $kw_lower ) !== false ) {
                $score += 1.5;
            }
            if ( mb_strpos( $body_lc, $kw_lower ) !== false ) {
                $score += 1.0;
            }
        }
        return $score;
    }

    /**
     * Create a text snippet from content, highlighting matched keywords
     *
     * @param string $content_text
     * @param array  $keywords
     * @param int    $max_length
     * @return string
     */
    private function make_snippet( $content_text, $keywords, $max_length = 200 ) {
        $text = preg_replace( '/\s+/', ' ', $content_text );

        // Try to find first keyword occurrence and extract surrounding text
        foreach ( $keywords as $kw ) {
            $pos = mb_strpos( mb_strtolower( $text, 'UTF-8' ), mb_strtolower( $kw, 'UTF-8' ), 0, 'UTF-8' );
            if ( $pos !== false ) {
                $start   = max( 0, $pos - 60 );
                $snippet = mb_substr( $text, $start, $max_length, 'UTF-8' );
                if ( $start > 0 ) {
                    $snippet = '...' . $snippet;
                }
                if ( mb_strlen( $text, 'UTF-8' ) > $start + $max_length ) {
                    $snippet .= '...';
                }
                return $snippet;
            }
        }

        // Fallback: return first N chars
        $snippet = mb_substr( $text, 0, $max_length, 'UTF-8' );
        if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
            $snippet .= '...';
        }
        return $snippet;
    }

    /**
     * Format related articles as prompt context string
     *
     * @param array $articles  Result of search_related()
     * @return string
     */
    public function format_as_context( $articles ) {
        if ( empty( $articles ) ) {
            return '';
        }

        $lines = array( '## 参照可能な既存コンテンツ（内部リンク候補）' );
        foreach ( $articles as $i => $article ) {
            $num     = $i + 1;
            $title   = esc_html( $article['title'] );
            $url     = esc_url( $article['url'] );
            $snippet = esc_html( $article['snippet'] );
            $lines[] = "{$num}. [{$title}]({$url}) - {$snippet}";
        }

        return implode( "\n", $lines );
    }
}
