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

        // Extract search keywords from topic
        $indexer  = new Blog_Poster_Content_Indexer();
        $keywords = $indexer->extract_keywords( $topic, 10 );

        if ( empty( $keywords ) ) {
            return array();
        }

        // Build LIKE conditions
        $title_conditions   = array();
        $content_conditions = array();
        $keyword_conditions = array();
        $params             = array();

        foreach ( $keywords as $kw ) {
            $like                 = '%' . $wpdb->esc_like( $kw ) . '%';
            $title_conditions[]   = 'title LIKE %s';
            $content_conditions[] = 'content_text LIKE %s';
            $keyword_conditions[] = 'keywords LIKE %s';
            $params[]             = $like;
            $params[]             = $like;
            $params[]             = $like;
        }

        $title_sql    = implode( ' OR ', $title_conditions );
        $content_sql  = implode( ' OR ', $content_conditions );
        $keyword_sql  = implode( ' OR ', $keyword_conditions );

        // Build scoring query: title match = 2, keyword match = 1.5, content match = 1
        $all_params = array_merge( $params, $params, $params );
        
        // Simpler approach: get candidates, then score in PHP
        $where_params = array();
        $where_parts  = array();
        foreach ( $keywords as $kw ) {
            $like           = '%' . $wpdb->esc_like( $kw ) . '%';
            $where_parts[]  = '(title LIKE %s OR content_text LIKE %s OR keywords LIKE %s)';
            $where_params[] = $like;
            $where_params[] = $like;
            $where_params[] = $like;
        }

        $where_sql = implode( ' OR ', $where_parts );

        // Limit to 50 candidates for scoring
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
            $score = $this->calculate_score( $row, $keywords );
            if ( $score > 0 ) {
                $scored[] = array(
                    'post_id' => (int) $row->post_id,
                    'title'   => $row->title,
                    'url'     => $row->url,
                    'snippet' => $this->make_snippet( $row->content_text, $keywords ),
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
