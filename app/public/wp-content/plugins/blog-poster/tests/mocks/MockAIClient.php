<?php
/**
 * Mock AI Client for Testing
 *
 * A concrete implementation of Blog_Poster_AI_Client for unit testing purposes.
 *
 * @package BlogPoster
 */

/**
 * MockAIClient class
 *
 * Provides a testable implementation of the abstract Blog_Poster_AI_Client class
 * with call logging and response sequencing capabilities.
 */
class MockAIClient extends Blog_Poster_AI_Client {
    /**
     * Array of responses to return in sequence.
     *
     * @var array
     */
    public $responses = array();

    /**
     * Log of all calls made to this client.
     *
     * @var array
     */
    public $call_log = array();

    /**
     * Counter for number of calls made.
     *
     * @var int
     */
    public $call_count = 0;

    /**
     * Constructor.
     *
     * @param array $responses Optional array of responses to return in sequence.
     */
    public function __construct( $responses = array() ) {
        $this->api_key = 'mock-key';
        $this->model = 'mock-model';
        $this->temperature = 0.7;
        $this->max_tokens = 8000;
        $this->responses = is_array( $responses ) ? $responses : array();
        $this->call_log = array();
        $this->call_count = 0;
    }

    /**
     * Generate text from a prompt.
     *
     * @param string $prompt The input prompt.
     * @param array  $options Optional additional options.
     * @return array Response array with generated text.
     */
    public function generate_text( $prompt, $options = null ) {
        // Log the call
        $this->call_log[] = array(
            'prompt'  => $prompt,
            'options' => $options,
        );

        // Get the next response from the array, cycling if necessary
        $response_count = count( $this->responses );
        if ( $response_count === 0 ) {
            $response = array( 'text' => '' );
        } else {
            $response = $this->responses[ $this->call_count % $response_count ];
        }

        // Increment call count
        $this->call_count++;

        return $response;
    }

    /**
     * Generate an image from a prompt.
     *
     * @param string $prompt The input prompt.
     * @return array Response array with image URL or error.
     */
    public function generate_image( $prompt ) {
        return array(
            'success' => false,
            'error'   => 'Not implemented in mock',
        );
    }

    /**
     * Extract text content from API response.
     *
     * @param array $response The API response array.
     * @return string The extracted text content.
     */
    public function get_text_content( $response ) {
        if ( ! is_array( $response ) ) {
            return '';
        }
        return isset( $response['text'] ) ? $response['text'] : '';
    }
}
