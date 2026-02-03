<?php
/**
 * SEO task manager for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blog_Poster_Task_Manager {

    public function create_tasks( $post_id, $analysis ) {
        $tasks = array();
        $created_at = time();

        if ( isset( $analysis['recommendations'] ) && is_array( $analysis['recommendations'] ) ) {
            foreach ( $analysis['recommendations'] as $rec ) {
                $tasks[] = array(
                    'id' => 'task_' . ( $rec['id'] ?? uniqid() ),
                    'type' => $this->map_rec_type( $rec ),
                    'priority' => (int) ( $rec['priority'] ?? 3 ),
                    'status' => 'pending',
                    'category' => $rec['category'] ?? 'general',
                    'section' => $this->map_section( $rec ),
                    'title' => $rec['title'] ?? '改善タスク',
                    'description' => $rec['description'] ?? '',
                    'suggestion' => array(
                        'content' => null,
                        'reason' => $rec['description'] ?? '',
                        'confidence_score' => 70,
                    ),
                    'result' => array(
                        'rewritten_content' => null,
                        'applied_at' => null,
                        'is_approved' => null,
                    ),
                );
            }
        }

        $data = array(
            'version' => '1.0',
            'created_at' => $created_at,
            'updated_at' => $created_at,
            'tasks' => $tasks,
            'summary' => array(
                'total' => count( $tasks ),
                'pending' => count( $tasks ),
                'completed' => 0,
            ),
        );

        update_post_meta( $post_id, '_blog_poster_seo_tasks', $data );
        return $data;
    }

    public function get_tasks( $post_id ) {
        return get_post_meta( $post_id, '_blog_poster_seo_tasks', true );
    }

    public function update_task( $post_id, $task_id, $status ) {
        $data = $this->get_tasks( $post_id );
        if ( empty( $data['tasks'] ) ) {
            return false;
        }
        $updated = false;
        foreach ( $data['tasks'] as &$task ) {
            if ( $task['id'] === $task_id ) {
                $task['status'] = $status;
                $task['result']['applied_at'] = time();
                $updated = true;
                break;
            }
        }
        if ( ! $updated ) {
            return false;
        }
        $data['updated_at'] = time();
        $data['summary'] = $this->build_summary( $data['tasks'] );
        update_post_meta( $post_id, '_blog_poster_seo_tasks', $data );
        return true;
    }

    public function apply_task( $post_id, $task_id ) {
        return $this->update_task( $post_id, $task_id, 'completed' );
    }

    public function batch_apply( $post_id, $task_ids ) {
        $applied = 0;
        foreach ( $task_ids as $task_id ) {
            if ( $this->apply_task( $post_id, $task_id ) ) {
                $applied++;
            }
        }
        return $applied;
    }

    private function build_summary( $tasks ) {
        $summary = array(
            'total' => count( $tasks ),
            'pending' => 0,
            'completed' => 0,
        );
        foreach ( $tasks as $task ) {
            if ( $task['status'] === 'completed' ) {
                $summary['completed']++;
            } elseif ( $task['status'] === 'pending' ) {
                $summary['pending']++;
            }
        }
        return $summary;
    }

    private function map_rec_type( $rec ) {
        $type = $rec['type'] ?? '';
        if ( strpos( $type, 'missing' ) !== false ) {
            return 'add';
        }
        return 'rewrite';
    }

    private function map_section( $rec ) {
        $type = $rec['type'] ?? '';
        if ( strpos( $type, 'lead' ) !== false ) {
            return 'introduction';
        }
        if ( strpos( $type, 'conclusion' ) !== false ) {
            return 'conclusion';
        }
        return 'body';
    }
}

