<?php
/**
 * Job queue runner for batch processing.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Queue_Runner {
    private $job_manager;

    public function __construct() {
        $this->job_manager = new Blog_Poster_Job_Manager();
    }

    /**
     * Process queue steps.
     *
     * @param int $step_limit Max steps to process.
     * @return array Summary.
     */
    public function process_queue( $step_limit = 1 ) {
        $results = array(
            'processed' => 0,
            'completed' => 0,
            'errors' => array(),
        );

        $step_limit = max( 1, intval( $step_limit ) );

        if ( $this->is_locked() ) {
            return array(
                'processed' => 0,
                'completed' => 0,
                'errors' => array( 'Queue is locked.' ),
            );
        }

        $this->set_lock();

        try {
            for ( $i = 0; $i < $step_limit; $i++ ) {
                if ( ! method_exists( $this->job_manager, 'get_next_runnable_job' ) ) {
                    $message = 'Job manager missing get_next_runnable_job().';
                    error_log( 'Blog Poster: ' . $message );
                    $results['errors'][] = $message;
                    break;
                }

                $job = $this->job_manager->get_next_runnable_job();
                if ( ! $job ) {
                    break;
                }

                $step_result = $this->process_job_step( $job );
                $results['processed']++;

                if ( ! $step_result['success'] ) {
                    $results['errors'][] = $step_result['message'];
                    continue;
                }

                if ( ! empty( $step_result['completed'] ) ) {
                    $results['completed']++;
                }
            }
        } finally {
            $this->clear_lock();
        }

        return $results;
    }

    /**
     * Process a specific job until completion or step limit.
     *
     * @param int $job_id Job ID.
     * @param int $step_limit Max steps.
     * @return array Summary.
     */
    public function process_job_by_id( $job_id, $step_limit = 50 ) {
        $results = array(
            'processed' => 0,
            'completed' => 0,
            'errors' => array(),
        );

        $job_id = intval( $job_id );
        $step_limit = max( 1, intval( $step_limit ) );

        if ( $job_id <= 0 ) {
            $results['errors'][] = 'Invalid job id.';
            return $results;
        }

        if ( $this->is_locked() ) {
            $results['errors'][] = 'Queue is locked.';
            return $results;
        }

        $this->set_lock();

        try {
            for ( $i = 0; $i < $step_limit; $i++ ) {
                $job = $this->job_manager->get_job( $job_id );
                if ( ! $job ) {
                    $results['errors'][] = 'Job not found.';
                    break;
                }

                if ( in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
                    break;
                }

                $step_result = $this->process_job_step( $job );
                $results['processed']++;

                if ( empty( $step_result['success'] ) ) {
                    $results['errors'][] = $step_result['message'];
                    break;
                }

                if ( ! empty( $step_result['completed'] ) ) {
                    $results['completed']++;
                    break;
                }
            }
        } finally {
            $this->clear_lock();
        }

        return $results;
    }

    private function process_job_step( $job ) {
        $status = $job['status'];

        if ( 'pending' === $status ) {
            return $this->job_manager->process_step_outline( intval( $job['id'] ) );
        }

        if ( 'outline' === $status || 'content' === $status ) {
            $content_result = $this->job_manager->process_step_content( intval( $job['id'] ) );
            if ( empty( $content_result['success'] ) ) {
                return $content_result;
            }

            if ( empty( $content_result['done'] ) ) {
                return $content_result;
            }

            $review_result = $this->job_manager->process_step_review( intval( $job['id'] ) );
            if ( empty( $review_result['success'] ) ) {
                return $review_result;
            }

            $post_id = $this->create_post_from_review( $job, $review_result );
            if ( is_wp_error( $post_id ) ) {
                return array(
                    'success' => false,
                    'message' => $post_id->get_error_message(),
                );
            }

            return array(
                'success' => true,
                'completed' => true,
                'post_id' => $post_id,
            );
        }

        if ( 'review' === $status ) {
            $review_result = $this->job_manager->process_step_review( intval( $job['id'] ) );
            if ( empty( $review_result['success'] ) ) {
                return $review_result;
            }

            $post_id = $this->create_post_from_review( $job, $review_result );
            if ( is_wp_error( $post_id ) ) {
                return array(
                    'success' => false,
                    'message' => $post_id->get_error_message(),
                );
            }

            return array(
                'success' => true,
                'completed' => true,
                'post_id' => $post_id,
            );
        }

        return array(
            'success' => false,
            'message' => 'Invalid job state',
        );
    }

    private function create_post_from_review( $job, $review_result ) {
        if ( ! empty( $job['post_id'] ) ) {
            // 既に投稿作成済みの場合、statusをcompletedに更新して返す
            $this->job_manager->update_job(
                intval( $job['id'] ),
                array( 'status' => 'completed' )
            );
            return intval( $job['post_id'] );
        }

        $model = isset( $job['ai_model'] ) ? $job['ai_model'] : '';
        $title = isset( $review_result['title'] ) ? $review_result['title'] : 'Untitled';
        // テスト用にモデル名をプレフィックスとして追加（区切り文字付き）
        $prefixed_title = $model !== '' ? '[' . $model . '] ' . $title : $title;

        $post_id = wp_insert_post( array(
            'post_title'   => $prefixed_title,
            'post_name'    => ! empty( $review_result['slug'] ) ? sanitize_title( $review_result['slug'] ) : '',
            'post_content' => isset( $review_result['html'] ) ? $review_result['html'] : '',
            'post_excerpt' => isset( $review_result['excerpt'] ) ? $review_result['excerpt'] : '',
            'post_status'  => 'draft',
            'post_author'  => $this->resolve_post_author(),
            'post_type'    => 'post',
        ) );

        if ( is_wp_error( $post_id ) ) {
            $this->job_manager->update_job(
                intval( $job['id'] ),
                array(
                    'status' => 'failed',
                    'error_message' => $post_id->get_error_message(),
                )
            );
            return $post_id;
        }

        $this->job_manager->update_job(
            intval( $job['id'] ),
            array(
                'post_id' => $post_id,
                'final_title' => $prefixed_title,
                'status' => 'completed',
            )
        );

        // post_metaにモデル情報を保存
        if ( ! empty( $job['ai_provider'] ) ) {
            update_post_meta( $post_id, '_blog_poster_provider', $job['ai_provider'] );
        }
        if ( ! empty( $job['ai_model'] ) ) {
            update_post_meta( $post_id, '_blog_poster_model', $job['ai_model'] );
        }
        if ( isset( $job['temperature'] ) ) {
            update_post_meta( $post_id, '_blog_poster_temperature', $job['temperature'] );
        }

        return $post_id;
    }

    private function resolve_post_author() {
        $author_id = get_current_user_id();
        return $author_id > 0 ? $author_id : 1;
    }


    private function is_locked() {
        return (bool) get_transient( 'blog_poster_queue_lock' );
    }

    private function set_lock() {
        set_transient( 'blog_poster_queue_lock', time(), 300 );
    }

    private function clear_lock() {
        delete_transient( 'blog_poster_queue_lock' );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'blog-poster process-queue', function( $args, $assoc_args ) {
        $steps = isset( $assoc_args['steps'] ) ? intval( $assoc_args['steps'] ) : 1;
        $job_id = isset( $assoc_args['job-id'] ) ? intval( $assoc_args['job-id'] ) : 0;
        $runner = new Blog_Poster_Queue_Runner();
        $results = $job_id > 0
            ? $runner->process_job_by_id( $job_id, $steps )
            : $runner->process_queue( $steps );

        WP_CLI::success( sprintf(
            'Queue processed. steps=%d completed=%d',
            $results['processed'],
            $results['completed']
        ) );

        if ( ! empty( $results['errors'] ) ) {
            foreach ( $results['errors'] as $message ) {
                WP_CLI::warning( $message );
            }
        }
    } );
}
