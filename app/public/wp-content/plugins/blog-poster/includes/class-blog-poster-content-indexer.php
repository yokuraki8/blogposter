<?php
/**
 * Content Indexer for RAG feature
 *
 * Indexes WordPress posts/pages for retrieval-augmented generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Content_Indexer {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'blog_poster_content_index';
    }

    /**
     * Register WordPress hooks
     */
    public function register_hooks() {
        add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
        add_action( 'delete_post', array( $this, 'remove_from_index' ) );
        add_action( 'wp_ajax_blog_poster_rag_reindex', array( $this, 'ajax_reindex_all' ) );
        add_action( 'wp_ajax_blog_poster_rag_status', array( $this, 'ajax_get_status' ) );
    }

    /**
     * Hook: called on save_post
     */
    public function on_save_post( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        $this->index_post( $post_id );
    }

    /**
     * Index a single post by ID
     *
     * @param int $post_id
     * @return bool
     */
    public function index_post( $post_id ) {
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_status, array( 'publish' ), true ) ) {
            return false;
        }
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return false;
        }

        $content_text = wp_strip_all_tags( $post->post_content );
        $content_text = preg_replace( '/\s+/', ' ', $content_text );
        $content_text = trim( $content_text );

        $keywords = $this->extract_keywords( $post->post_title . ' ' . $content_text );
        $url      = get_permalink( $post_id );

        $data = array(
            'post_id'      => $post_id,
            'post_type'    => $post->post_type,
            'title'        => $post->post_title,
            'url'          => $url,
            'content_text' => substr( $content_text, 0, 65535 ),
            'keywords'     => implode( ',', $keywords ),
            'indexed_at'   => current_time( 'mysql' ),
        );

        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$this->table_name} WHERE post_id = %d", $post_id )
        );

        if ( $existing ) {
            $wpdb->update(
                $this->table_name,
                $data,
                array( 'post_id' => $post_id ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                $data,
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
        }

        return true;
    }

    /**
     * Index all published posts and pages
     *
     * @return int Number of indexed items
     */
    public function index_all() {
        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        $count = 0;
        foreach ( $posts as $post_id ) {
            if ( $this->index_post( $post_id ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Remove a post from the index
     *
     * @param int $post_id
     */
    public function remove_from_index( $post_id ) {
        global $wpdb;
        $wpdb->delete(
            $this->table_name,
            array( 'post_id' => $post_id ),
            array( '%d' )
        );
    }

    /**
     * Get index status
     *
     * @return array { count: int, last_indexed: string|null }
     */
    public function get_status() {
        global $wpdb;
        $count        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
        $last_indexed = $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$this->table_name}" );
        return array(
            'count'        => $count,
            'last_indexed' => $last_indexed,
        );
    }

    /**
     * Extract keywords from text (frequency-based, Japanese-friendly)
     *
     * @param string $text
     * @param int    $limit
     * @return array
     */
    public function extract_keywords( $text, $limit = 20 ) {
        // Normalize
        $text = mb_strtolower( $text, 'UTF-8' );
        $text = preg_replace( '/[\x{3000}\x{0020}\x{00A0}]+/u', ' ', $text );

        // Split on whitespace and common Japanese punctuation
        $tokens = preg_split( '/[\s\p{P}、。・「」『』【】（）〔〕]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

        // Count frequency, filter short tokens
        $freq = array();
        foreach ( $tokens as $token ) {
            $len = mb_strlen( $token, 'UTF-8' );
            if ( $len < 2 ) {
                continue;
            }
            $freq[ $token ] = isset( $freq[ $token ] ) ? $freq[ $token ] + 1 : 1;
        }

        arsort( $freq );
        return array_slice( array_keys( $freq ), 0, $limit );
    }

    /**
     * AJAX: reindex all content
     */
    public function ajax_reindex_all() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }
        $count = $this->index_all();
        wp_send_json_success( array(
            'message' => sprintf( '%d件のコンテンツをインデックスしました。', $count ),
            'count'   => $count,
        ) );
    }

    /**
     * AJAX: get index status
     */
    public function ajax_get_status() {
        check_ajax_referer( 'blog_poster_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }
        wp_send_json_success( $this->get_status() );
    }
}
