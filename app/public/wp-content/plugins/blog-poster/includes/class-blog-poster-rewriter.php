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
        return array(
            'content' => null,
            'reason' => 'AIリライトは未実装です。プレビューのみ対応。',
            'confidence_score' => 0,
        );
    }

    public function generate_lead_paragraph( $post_id ) {
        return '';
    }

    public function improve_meta_description( $post_id ) {
        return '';
    }

    public function generate_conclusion( $post_id ) {
        return '';
    }

    public function rewrite_content( $post_id, $task ) {
        return new WP_Error( 'rewrite_not_implemented', 'リライト機能は準備中です。' );
    }

    public function apply_rewrite( $post_id, $task_id, $content ) {
        return new WP_Error( 'rewrite_not_implemented', 'リライト機能は準備中です。' );
    }
}

