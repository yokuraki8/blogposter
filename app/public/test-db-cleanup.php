<?php
/**
 * DBクリーンアップテストスクリプト
 *
 * 使用方法:
 * wp eval-file test-db-cleanup.php
 * または
 * php test-db-cleanup.php (WPがロードされている環境で)
 */

// WordPress をロード
if ( ! defined( 'ABSPATH' ) ) {
    $wp_config = dirname( dirname( dirname( __FILE__ ) ) ) . '/wp-config.php';
    if ( file_exists( $wp_config ) ) {
        require $wp_config;
        require ABSPATH . 'wp-settings.php';
    } else {
        echo "Error: Could not locate wp-config.php\n";
        exit( 1 );
    }
}

require_once ABSPATH . 'wp-content/plugins/blog-poster/includes/class-blog-poster-settings.php';

echo "=== Blog Poster DB Cleanup Test ===\n\n";

// 現在の設定を取得
$settings = get_option( 'blog_poster_settings', array() );
$current_size = strlen( wp_json_encode( $settings ) );

echo "1. Current DB Option Size: " . number_format( $current_size ) . " bytes\n";
echo "2. Providers: " . implode( ', ', array( 'openai', 'claude', 'gemini' ) ) . "\n\n";

// 各プロバイダーのAPIキーサイズを表示
foreach ( array( 'openai', 'claude', 'gemini' ) as $provider ) {
    $field = $provider . '_api_key';
    $key = isset( $settings[ $field ] ) ? $settings[ $field ] : '';
    $key_size = strlen( $key );
    $status = ( $key_size > 0 ) ? ( $key_size > 1000 ? 'OVERSIZED ⚠️' : 'OK' ) : 'empty';
    printf( "   %s: %d bytes (%s)\n", $provider, $key_size, $status );
}

echo "\n3. Running sanitize_oversized_api_keys()...\n";
$result = Blog_Poster_Settings::sanitize_oversized_api_keys();

echo "\n4. Cleanup Results:\n";
echo "   - Cleaned: " . $result['cleaned'] . " key(s)\n";
echo "   - Before: " . number_format( $result['total_before'] ) . " bytes\n";
echo "   - After: " . number_format( $result['total_after'] ) . " bytes\n";
echo "   - Reduction: " . number_format( $result['total_before'] - $result['total_after'] ) . " bytes\n";

// クリーンアップ後の設定を再確認
$settings_after = get_option( 'blog_poster_settings', array() );
echo "\n5. Post-Cleanup API Key Sizes:\n";
foreach ( array( 'openai', 'claude', 'gemini' ) as $provider ) {
    $field = $provider . '_api_key';
    $key = isset( $settings_after[ $field ] ) ? $settings_after[ $field ] : '';
    $key_size = strlen( $key );
    printf( "   %s: %d bytes\n", $provider, $key_size );
}

echo "\n=== Test Complete ===\n";
