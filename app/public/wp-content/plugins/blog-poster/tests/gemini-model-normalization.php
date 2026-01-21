<?php
/**
 * Simple CLI test for Gemini model normalization.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-blog-poster-ai-client.php';
require_once __DIR__ . '/../includes/class-blog-poster-gemini-client.php';

function assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$normalized = Blog_Poster_Gemini_Client::normalize_model( 'gemini-3-pro' );
assert_true(
    'gemini-2.5-pro' === $normalized,
    'Unsupported gemini-3-pro should fall back to gemini-2.5-pro.'
);

$normalized = Blog_Poster_Gemini_Client::normalize_model( 'gemini-2.5-flash' );
assert_true(
    'gemini-2.5-flash' === $normalized,
    'Supported model should remain unchanged.'
);

fwrite( STDOUT, "OK: gemini model normalization\n" );
