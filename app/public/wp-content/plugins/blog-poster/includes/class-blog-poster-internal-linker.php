<?php
/**
 * Internal Linker for RAG feature
 *
 * Inserts internal links to related articles into generated Markdown content.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Internal_Linker {

    /**
     * Process Markdown content and insert internal links
     *
     * @param string $markdown_content  Generated Markdown
     * @param array  $related_articles  From Content_Retriever::search_related()
     * @param int    $max_links         Maximum links to insert (default 3)
     * @return string  Modified Markdown
     */
    public function process( $markdown_content, $related_articles, $max_links = 3 ) {
        if ( empty( $related_articles ) || empty( $markdown_content ) ) {
            return $markdown_content;
        }

        $inserted     = 0;
        $used_urls    = array();
        $content      = $markdown_content;

        // Split content into lines for processing
        $lines        = explode( "\n", $content );
        $in_code      = false;
        $result_lines = array();

        foreach ( $lines as $line ) {
            // Track code fence state
            if ( preg_match( '/^```/', $line ) ) {
                $in_code = ! $in_code;
                $result_lines[] = $line;
                continue;
            }

            // Skip headings and code blocks
            if ( $in_code || preg_match( '/^#/', $line ) ) {
                $result_lines[] = $line;
                continue;
            }

            // Try to insert links in this line
            if ( $inserted < $max_links ) {
                $line = $this->try_insert_link( $line, $related_articles, $used_urls, $inserted, $max_links );
            }

            $result_lines[] = $line;
        }

        return implode( "\n", $result_lines );
    }

    /**
     * Try to insert a link in a line of Markdown text
     *
     * @param string $line
     * @param array  $related_articles
     * @param array  &$used_urls
     * @param int    &$inserted
     * @param int    $max_links
     * @return string
     */
    private function try_insert_link( $line, $related_articles, &$used_urls, &$inserted, $max_links ) {
        foreach ( $related_articles as $article ) {
            if ( $inserted >= $max_links ) {
                break;
            }

            $url   = $article['url'];
            $title = $article['title'];

            // Skip if URL already used
            if ( in_array( $url, $used_urls, true ) ) {
                continue;
            }

            // Skip if line already contains this URL
            if ( strpos( $line, $url ) !== false ) {
                continue;
            }

            // Check if line is already a link (contains markdown link syntax for this article)
            if ( strpos( $line, '[' ) !== false && strpos( $line, $url ) !== false ) {
                continue;
            }

            // Get anchor text candidates from article title keywords
            $anchor_candidates = $this->get_anchor_candidates( $title );

            foreach ( $anchor_candidates as $anchor ) {
                $anchor_len = mb_strlen( $anchor, 'UTF-8' );
                if ( $anchor_len < 4 ) {
                    continue;
                }

                // Case-insensitive search in line
                $pos = mb_strpos( mb_strtolower( $line, 'UTF-8' ), mb_strtolower( $anchor, 'UTF-8' ), 0, 'UTF-8' );

                if ( $pos === false ) {
                    continue;
                }

                // Extract actual text at position (preserve original case)
                $original_anchor = mb_substr( $line, $pos, $anchor_len, 'UTF-8' );

                // Make sure it's not already inside a link
                $before = mb_substr( $line, 0, $pos, 'UTF-8' );
                if ( substr_count( $before, '[' ) !== substr_count( $before, ']' ) ) {
                    // We're inside a link already
                    continue;
                }

                // Replace first occurrence with link
                $linked_line = mb_substr( $line, 0, $pos, 'UTF-8' )
                    . '[' . $original_anchor . '](' . esc_url( $url ) . ')'
                    . mb_substr( $line, $pos + $anchor_len, null, 'UTF-8' );

                $line        = $linked_line;
                $used_urls[] = $url;
                $inserted++;
                break; // Move to next article after inserting one link
            }

            if ( in_array( $url, $used_urls, true ) ) {
                break; // This article's link was inserted
            }
        }

        return $line;
    }

    /**
     * Get anchor text candidates from article title
     * Returns the full title and meaningful substrings
     *
     * @param string $title
     * @return array
     */
    private function get_anchor_candidates( $title ) {
        $candidates = array( $title );

        // Add keywords of 4+ chars extracted from title
        $words = preg_split( '/[\s\p{P}、。・「」]+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
        foreach ( $words as $word ) {
            if ( mb_strlen( $word, 'UTF-8' ) >= 4 ) {
                $candidates[] = $word;
            }
        }

        return $candidates;
    }
}
