<?php
/**
 * Batch generation utilities for model/topic matrix testing.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Batch_Generator {
    private $generator;
    private $original_settings;
    private $job_manager;

    public function __construct() {
        $this->generator = new Blog_Poster_Generator();
        $this->original_settings = get_option( 'blog_poster_settings', array() );
        $this->job_manager = new Blog_Poster_Job_Manager();
    }

    /**
     * Run batch generation for a model/topic matrix.
     *
     * @param array $models List of model IDs.
     * @param array $cases List of cases with keys: topic, instructions.
     * @param array $options Options: temperature, temperature_map, limit, dry_run.
     * @return array Summary results.
     */
    public function run( $models, $cases, $options = array() ) {
        $results = array(
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => array(),
        );

        $limit = isset( $options['limit'] ) ? intval( $options['limit'] ) : 0;
        $dry_run = ! empty( $options['dry_run'] );
        $temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : null;
        $temperature_map = isset( $options['temperature_map'] ) && is_array( $options['temperature_map'] )
            ? $options['temperature_map']
            : array();

        $processed = 0;

        foreach ( $models as $model ) {
            $provider = $this->resolve_provider( $model );

            if ( ! $this->provider_has_api_key( $provider, $this->original_settings ) ) {
                $results['failed']++;
                $results['errors'][] = "Missing API key for provider {$provider} (model {$model})";
                continue;
            }

            foreach ( $cases as $case_index => $case ) {
                if ( $limit > 0 && $processed >= $limit ) {
                    break 2;
                }

                $results['total']++;
                $processed++;

                if ( $dry_run ) {
                    continue;
                }

                $run_temperature = $temperature;
                if ( null === $run_temperature && isset( $temperature_map[ $model ] ) ) {
                    $run_temperature = floatval( $temperature_map[ $model ] );
                }

                $this->apply_model_settings( $provider, $model, $run_temperature );

                $result = $this->generator->generate_article( $case['topic'], $case['instructions'] );
                if ( is_wp_error( $result ) ) {
                    $results['failed']++;
                    $results['errors'][] = "Model {$model} case {$case_index}: " . $result->get_error_message();
                    continue;
                }

                if ( empty( $result['title'] ) || empty( $result['html'] ) ) {
                    $results['failed']++;
                    $results['errors'][] = "Model {$model} case {$case_index}: empty output";
                    continue;
                }

                $post_id = $this->create_post_from_result( $model, $provider, $case, $result, $run_temperature );
                if ( is_wp_error( $post_id ) ) {
                    $results['failed']++;
                    $results['errors'][] = "Model {$model} case {$case_index}: " . $post_id->get_error_message();
                    continue;
                }

                $results['success']++;
            }
        }

        $this->restore_settings();

        return $results;
    }

    /**
     * Parse a template markdown file into case data.
     *
     * @param string $path Template path.
     * @return array Cases.
     */
    public function load_cases_from_template( $path ) {
        if ( ! file_exists( $path ) ) {
            return array();
        }

        $content = file_get_contents( $path );
        if ( false === $content ) {
            return array();
        }

        $cases = array();
        $pattern = '/トピック\/キーワード:\s*(.+?)\s*\n\s*-\s*追加指示:\s*([\s\S]*?)(?=\n\s*##|\z)/u';

        if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $topic = trim( $match[1] );
                $instructions = trim( preg_replace( '/\s+/u', ' ', $match[2] ) );
                if ( '' === $topic ) {
                    continue;
                }
                $cases[] = array(
                    'topic' => $topic,
                    'instructions' => $instructions,
                );
            }
        }

        return $cases;
    }

    /**
     * Enqueue jobs for model/case matrix.
     *
     * @param array $models Models.
     * @param array $cases Cases.
     * @param array $options Options: temperature, temperature_map.
     * @return array Summary.
     */
    public function enqueue_jobs( $models, $cases, $options = array() ) {
        $results = array(
            'total' => 0,
            'enqueued' => 0,
            'errors' => array(),
        );

        $temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : null;
        $temperature_map = isset( $options['temperature_map'] ) && is_array( $options['temperature_map'] )
            ? $options['temperature_map']
            : array();

        foreach ( $models as $model ) {
            $provider = $this->resolve_provider( $model );
            if ( ! $this->provider_has_api_key( $provider, $this->original_settings ) ) {
                $results['errors'][] = "Missing API key for provider {$provider} (model {$model})";
                continue;
            }

            foreach ( $cases as $case ) {
                $results['total']++;
                $run_temperature = $temperature;
                if ( null === $run_temperature && isset( $temperature_map[ $model ] ) ) {
                    $run_temperature = floatval( $temperature_map[ $model ] );
                }

                $job_id = $this->job_manager->create_job(
                    $case['topic'],
                    $case['instructions'],
                    array(
                        'ai_provider' => $provider,
                        'ai_model' => $model,
                        'temperature' => $run_temperature,
                    )
                );

                if ( $job_id <= 0 ) {
                    $results['errors'][] = "Failed to enqueue job for model {$model}";
                    continue;
                }

                $results['enqueued']++;
            }
        }

        return $results;
    }

    private function resolve_provider( $model ) {
        if ( 0 === strpos( $model, 'claude-' ) ) {
            return 'claude';
        }
        if ( 0 === strpos( $model, 'gemini-' ) ) {
            return 'gemini';
        }
        return 'openai';
    }

    private function provider_has_api_key( $provider, $settings ) {
        $key_field = $provider . '_api_key';
        return ! empty( $settings[ $key_field ] );
    }

    private function apply_model_settings( $provider, $model, $temperature = null ) {
        $settings = $this->original_settings;
        $settings['ai_provider'] = $provider;

        if ( ! isset( $settings['default_model'] ) || ! is_array( $settings['default_model'] ) ) {
            $settings['default_model'] = array();
        }

        $settings['default_model'][ $provider ] = $model;

        if ( null !== $temperature ) {
            $settings['temperature'] = $temperature;
        }

        update_option( 'blog_poster_settings', $settings );
    }

    private function restore_settings() {
        update_option( 'blog_poster_settings', $this->original_settings );
    }

    private function create_post_from_result( $model, $provider, $case, $result, $temperature ) {
        $title = $model . $result['title'];
        $post_id = wp_insert_post( array(
            'post_title' => $title,
            'post_content' => $result['html'],
            'post_excerpt' => isset( $result['excerpt'] ) ? $result['excerpt'] : '',
            'post_status' => 'draft',
            'post_type' => 'post',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_blog_poster_model', $model );
        update_post_meta( $post_id, '_blog_poster_provider', $provider );
        if ( null !== $temperature ) {
            update_post_meta( $post_id, '_blog_poster_temperature', $temperature );
        }
        update_post_meta( $post_id, '_blog_poster_topic', $case['topic'] );
        update_post_meta( $post_id, '_blog_poster_additional_instructions', $case['instructions'] );
        if ( ! empty( $result['keywords'] ) && is_array( $result['keywords'] ) ) {
            update_post_meta( $post_id, '_blog_poster_keywords', implode( ', ', $result['keywords'] ) );
        }

        return $post_id;
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'blog-poster batch-enqueue', function( $args, $assoc_args ) {
        $template_path = isset( $assoc_args['template'] )
            ? $assoc_args['template']
            : '/Users/yoshiki/Documents/Obsidian26/Projects/ProjectDetail/P2203/P2203記事生成テストのテンプレート.md';

        $models = array(
            'claude-opus-4-5-20251101',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gpt-5.2',
            'gpt-5.2-pro',
            'gpt-5-mini',
        );

        if ( isset( $assoc_args['models'] ) ) {
            $custom_models = array_filter( array_map( 'trim', explode( ',', $assoc_args['models'] ) ) );
            if ( ! empty( $custom_models ) ) {
                $models = $custom_models;
            }
        }

        $temperature = null;
        if ( isset( $assoc_args['temperature'] ) ) {
            $temperature = floatval( $assoc_args['temperature'] );
        }

        $temperature_map = array();
        if ( isset( $assoc_args['temperature-map'] ) ) {
            $decoded = json_decode( $assoc_args['temperature-map'], true );
            if ( is_array( $decoded ) ) {
                $temperature_map = $decoded;
            } else {
                WP_CLI::warning( 'temperature-map is not valid JSON; ignoring.' );
            }
        }

        $batch = new Blog_Poster_Batch_Generator();
        $cases = $batch->load_cases_from_template( $template_path );
        if ( empty( $cases ) ) {
            WP_CLI::error( "No cases parsed from template: {$template_path}" );
        }

        $results = $batch->enqueue_jobs( $models, $cases, array(
            'temperature' => $temperature,
            'temperature_map' => $temperature_map,
        ) );

        if ( ! wp_next_scheduled( 'blog_poster_process_queue' ) ) {
            wp_schedule_event( time() + 60, 'blog_poster_minute', 'blog_poster_process_queue' );
        }

        WP_CLI::success( sprintf(
            'Enqueued. total=%d enqueued=%d',
            $results['total'],
            $results['enqueued']
        ) );

        if ( ! empty( $results['errors'] ) ) {
            foreach ( $results['errors'] as $message ) {
                WP_CLI::warning( $message );
            }
        }
    } );

    WP_CLI::add_command( 'blog-poster batch-generate', function( $args, $assoc_args ) {
        $template_path = isset( $assoc_args['template'] )
            ? $assoc_args['template']
            : '/Users/yoshiki/Documents/Obsidian26/Projects/ProjectDetail/P2203/P2203記事生成テストのテンプレート.md';

        $models = array(
            'claude-opus-4-5-20251101',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gpt-5.2',
            'gpt-5.2-pro',
            'gpt-5-mini',
        );

        if ( isset( $assoc_args['models'] ) ) {
            $custom_models = array_filter( array_map( 'trim', explode( ',', $assoc_args['models'] ) ) );
            if ( ! empty( $custom_models ) ) {
                $models = $custom_models;
            }
        }

        $temperature = null;
        if ( isset( $assoc_args['temperature'] ) ) {
            $temperature = floatval( $assoc_args['temperature'] );
        }

        $temperature_map = array();
        if ( isset( $assoc_args['temperature-map'] ) ) {
            $decoded = json_decode( $assoc_args['temperature-map'], true );
            if ( is_array( $decoded ) ) {
                $temperature_map = $decoded;
            } else {
                WP_CLI::warning( 'temperature-map is not valid JSON; ignoring.' );
            }
        }

        $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 0;
        $dry_run = ! empty( $assoc_args['dry-run'] );

        $batch = new Blog_Poster_Batch_Generator();
        $cases = $batch->load_cases_from_template( $template_path );
        if ( empty( $cases ) ) {
            WP_CLI::error( "No cases parsed from template: {$template_path}" );
        }

        $results = $batch->run( $models, $cases, array(
            'temperature' => $temperature,
            'temperature_map' => $temperature_map,
            'limit' => $limit,
            'dry_run' => $dry_run,
        ) );

        WP_CLI::success( sprintf(
            'Batch done. total=%d success=%d failed=%d',
            $results['total'],
            $results['success'],
            $results['failed']
        ) );

        if ( ! empty( $results['errors'] ) ) {
            foreach ( $results['errors'] as $message ) {
                WP_CLI::warning( $message );
            }
        }
    } );
}
