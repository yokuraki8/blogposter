<?php
/**
 * PHPUnit Bootstrap File for Blog Poster Plugin
 *
 * Sets up test environment with WordPress stubs and plugin includes.
 *
 * @package BlogPoster
 */

// Define constants
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'BLOG_POSTER_PLUGIN_DIR' ) ) {
    define( 'BLOG_POSTER_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Define WordPress stub functions
if ( ! function_exists( 'wp_json_encode' ) ) {
    /**
     * WordPress JSON encode function.
     *
     * @param mixed $data Data to encode.
     * @return string JSON encoded string.
     */
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    /**
     * Make a POST request.
     *
     * @param string $url URL to post to.
     * @param array  $args Optional request arguments.
     * @return array|WP_Error Response or error.
     */
    function wp_remote_post( $url, $args = array() ) {
        return array();
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    /**
     * Retrieve response code from a remote response.
     *
     * @param array|WP_Error $response HTTP response.
     * @return int|string The response code as int, empty string otherwise.
     */
    function wp_remote_retrieve_response_code( $response ) {
        if ( is_wp_error( $response ) ) {
            return '';
        }
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 200;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    /**
     * Retrieve body from a remote response.
     *
     * @param array|WP_Error $response HTTP response.
     * @return string Response body as string, empty string if not set.
     */
    function wp_remote_retrieve_body( $response ) {
        if ( is_wp_error( $response ) ) {
            return '';
        }
        return isset( $response['body'] ) ? $response['body'] : '';
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Check whether variable is a WordPress Error.
     *
     * @param mixed $thing Check if unknown variable is a WP_Error.
     * @return bool True, if WP_Error. False, if not WP_Error.
     */
    function is_wp_error( $thing ) {
        return ( $thing instanceof WP_Error );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    /**
     * Retrieve option value by option name.
     *
     * @param string $option Name of option to retrieve.
     * @param mixed  $default Value to return if option doesn't exist.
     * @return mixed Value set for the option.
     */
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    /**
     * Sanitize a string key.
     *
     * @param string $key String key to sanitize.
     * @return string Sanitized key string.
     */
    function sanitize_key( $key ) {
        $key = strtolower( $key );
        $key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
        return $key;
    }
}

if ( ! function_exists( '__' ) ) {
    /**
     * Retrieve translated string.
     *
     * @param string $text Text to translate.
     * @param string $domain Text domain.
     * @return string Translated text, or original text if translation not available.
     */
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'set_time_limit' ) ) {
    /**
     * Set the script execution time limit.
     *
     * @param int $seconds Time limit in seconds. Use 0 for unlimited.
     * @return void
     */
    function set_time_limit( $seconds ) {
        // Test stub - do nothing.
    }
}

if ( ! function_exists( 'error_log' ) ) {
    /**
     * Send error message to server log.
     *
     * @param string $message Error message.
     * @param int    $message_type Message type.
     * @param string $destination Destination.
     * @param string $extra_headers Extra headers.
     * @return bool True if message was sent, false otherwise.
     */
    function error_log( $message, $message_type = 0, $destination = null, $extra_headers = null ) {
        // Test stub - suppress error logging during tests.
        return true;
    }
}

// Define WP_Error class if not already defined
if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * WordPress Error class for handling errors.
     */
    class WP_Error {
        /**
         * @var string Error code.
         */
        public $code;

        /**
         * @var string Error message.
         */
        public $message;

        /**
         * @var mixed Error data.
         */
        public $data;

        /**
         * Constructor.
         *
         * @param string $code Error code.
         * @param string $message Error message.
         * @param mixed  $data Error data.
         */
        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        /**
         * Get error code.
         *
         * @return string Error code.
         */
        public function get_error_code() {
            return $this->code;
        }

        /**
         * Get error message.
         *
         * @return string Error message.
         */
        public function get_error_message() {
            return $this->message;
        }

        /**
         * Get error data.
         *
         * @return mixed Error data.
         */
        public function get_error_data() {
            return $this->data;
        }
    }
}

// Require plugin includes
$plugin_includes = array(
    'includes/class-blog-poster-ai-client.php',
    'includes/class-blog-poster-gemini-client.php',
    'includes/class-blog-poster-claude-client.php',
    'includes/class-blog-poster-openai-client.php',
    'includes/class-blog-poster-generator.php',
    'includes/class-blog-poster-image-helper.php',
    'includes/class-blog-poster-settings.php',
);

foreach ( $plugin_includes as $include ) {
    $include_path = BLOG_POSTER_PLUGIN_DIR . $include;
    if ( file_exists( $include_path ) ) {
        require_once $include_path;
    }
}

// Define mock Blog_Poster_Settings class if not already defined
if ( ! class_exists( 'Blog_Poster_Settings' ) ) {
    /**
     * Mock Blog_Poster_Settings class for testing.
     */
    class Blog_Poster_Settings {
        /**
         * Get plugin settings.
         *
         * @return array Settings array with defaults.
         */
        public static function get_settings() {
            return array(
                'ai_provider'   => 'gemini',
                'default_model' => array(
                    'gemini'  => 'gemini-2.0-flash',
                    'claude'  => 'claude-3-5-sonnet-20241022',
                    'openai'  => 'gpt-4o-mini',
                ),
                'temperature'   => 0.7,
                'max_tokens'    => 8000,
                'formality'     => 50,
                'expertise'     => 50,
                'friendliness'  => 50,
            );
        }

        /**
         * Get API key for provider.
         *
         * @param string $provider API provider name.
         * @param array  $settings Optional settings array.
         * @return string API key.
         */
        public static function get_api_key( $provider, $settings = null ) {
            return 'test-api-key-12345';
        }

        /**
         * Decrypt encrypted value.
         *
         * @param string $val Value to decrypt.
         * @return string Decrypted value.
         */
        public static function decrypt( $val ) {
            return $val;
        }
    }
}

// Define mock Blog_Poster_Admin class if not already defined
if ( ! class_exists( 'Blog_Poster_Admin' ) ) {
    /**
     * Mock Blog_Poster_Admin class for testing.
     */
    class Blog_Poster_Admin {
        /**
         * Convert markdown to HTML.
         *
         * @param string $md Markdown string.
         * @return string HTML string (passthrough for testing).
         */
        public static function markdown_to_html( $md ) {
            return $md;
        }
    }
}
