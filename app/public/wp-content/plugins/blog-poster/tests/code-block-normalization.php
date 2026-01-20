<?php
/**
 * Simple CLI test for code block normalization.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-blog-poster-generator.php';

function normalize_whitespace( $text ) {
    $text = preg_replace( "/\n{3,}/", "\n\n", $text );
    return trim( $text );
}

function assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$generator = new Blog_Poster_Generator();

$input = "```\nこれは本文です。\n### 見出し\n```\n\n```javascript\nconst x = 1;\n```\n";

$expected = "これは本文です。\n### 見出し\n\n```javascript\nconst x = 1;\n```";

$actual = $generator->normalize_code_blocks_after_generation( $input );

assert_true(
    normalize_whitespace( $actual ) === normalize_whitespace( $expected ),
    'Prose-like code block should be converted to text while keeping code blocks.'
);

assert_true(
    strpos( $actual, '```javascript' ) !== false,
    'Language-specific code block should remain.'
);

assert_true(
    strpos( $actual, '```' ) === strpos( $actual, '```javascript' ),
    'No extra code fences should remain around prose.'
);

fwrite( STDOUT, "OK: code block normalization\n" );
