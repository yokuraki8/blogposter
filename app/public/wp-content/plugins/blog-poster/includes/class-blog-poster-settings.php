<?php
/**
 * Settings helper for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Settings {
    const ENC_PREFIX = 'enc::';
    const ENC_CIPHER = 'aes-256-cbc';

    public static function is_encrypted( $value ) {
        return is_string( $value ) && 0 === strpos( $value, self::ENC_PREFIX );
    }

    public static function encrypt( $plain ) {
        if ( ! is_string( $plain ) || $plain === '' ) {
            return '';
        }

        $key = self::get_key();
        $hmac_key = self::get_hmac_key();
        $iv_len = openssl_cipher_iv_length( self::ENC_CIPHER );
        $iv = random_bytes( $iv_len );
        $cipher = openssl_encrypt( $plain, self::ENC_CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $cipher ) {
            return '';
        }

        $iv_b64 = base64_encode( $iv );
        $cipher_b64 = base64_encode( $cipher );
        $mac = hash_hmac( 'sha256', $iv_b64 . ':' . $cipher_b64, $hmac_key );

        return self::ENC_PREFIX . $iv_b64 . ':' . $cipher_b64 . ':' . $mac;
    }

    public static function decrypt( $value ) {
        if ( ! self::is_encrypted( $value ) ) {
            return is_string( $value ) ? $value : '';
        }

        $payload = substr( $value, strlen( self::ENC_PREFIX ) );
        $parts = explode( ':', $payload );
        if ( count( $parts ) !== 3 ) {
            return '';
        }

        list( $iv_b64, $cipher_b64, $mac ) = $parts;
        $hmac_key = self::get_hmac_key();
        $expected = hash_hmac( 'sha256', $iv_b64 . ':' . $cipher_b64, $hmac_key );
        if ( ! hash_equals( $expected, $mac ) ) {
            return '';
        }

        $iv = base64_decode( $iv_b64, true );
        $cipher = base64_decode( $cipher_b64, true );
        if ( false === $iv || false === $cipher ) {
            return '';
        }

        $key = self::get_key();
        $plain = openssl_decrypt( $cipher, self::ENC_CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }

    public static function get_api_key( $provider, $settings = null ) {
        $settings = is_array( $settings ) ? $settings : get_option( 'blog_poster_settings', array() );
        $key_field = $provider . '_api_key';
        $raw = isset( $settings[ $key_field ] ) ? $settings[ $key_field ] : '';
        return self::decrypt( $raw );
    }

    private static function get_key() {
        $seed = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
        return hash( 'sha256', $seed, true );
    }

    private static function get_hmac_key() {
        $seed = ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
        return hash( 'sha256', $seed, true );
    }
}
