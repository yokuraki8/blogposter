<?php
/**
 * Blog Poster Job Manager
 *
 * 非同期ジョブ管理クラス - 記事生成処理を3ステップに分割して実行
 *
 * @package BlogPoster
 * @since 0.2.5-alpha
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ジョブ管理クラス
 */
class Blog_Poster_Job_Manager {

	/**
	 * テーブル名
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Generatorインスタンス
	 *
	 * @var Blog_Poster_Generator
	 */
	private $generator;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'blog_poster_jobs';
		$this->generator  = new Blog_Poster_Generator();
		$this->ensure_table_exists();
	}

	/**
	 * テーブルの存在を確認し、なければ作成
	 */
	private function ensure_table_exists() {
		global $wpdb;

		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" );

		if ( $table_exists !== $this->table_name ) {
			error_log( 'Blog Poster: Jobs table does not exist. Creating...' );
			$this->create_table();
		}
	}

	/**
	 * ジョブテーブルを作成
	 */
	private function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$this->table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			topic varchar(500) NOT NULL,
			additional_instructions text,
			status varchar(20) DEFAULT 'pending',
			current_step int(11) DEFAULT 0,
			total_steps int(11) DEFAULT 3,
			outline longtext,
			sections_content longtext,
			final_content longtext,
			error_message text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );

		error_log( 'Blog Poster: Jobs table created.' );
	}

	/**
	 * 新しいジョブを作成
	 *
	 * @param string $topic トピック
	 * @param string $additional_instructions 追加指示
	 * @return int ジョブID
	 */
	public function create_job( $topic, $additional_instructions = '' ) {
		global $wpdb;

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'topic'                    => $topic,
				'additional_instructions'  => $additional_instructions,
				'status'                   => 'pending',
			)
		);

		if ( false === $result ) {
			error_log( 'Blog Poster: Failed to create job. Error: ' . $wpdb->last_error );
			error_log( 'Blog Poster: Table name: ' . $this->table_name );
			return 0;
		}

		error_log( 'Blog Poster: Job created successfully. Insert ID: ' . $wpdb->insert_id );
		return $wpdb->insert_id;
	}

	/**
	 * ジョブの状態を取得
	 *
	 * @param int $job_id ジョブID
	 * @return array|null ジョブデータ
	 */
	public function get_job( $job_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $job_id ),
			ARRAY_A
		);
	}

	/**
	 * ジョブを更新
	 *
	 * @param int   $job_id ジョブID
	 * @param array $data 更新データ
	 * @return bool 更新成功
	 */
	public function update_job( $job_id, $data ) {
		global $wpdb;
		return $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $job_id )
		);
	}

	/**
	 * Step 1: アウトライン生成
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_outline( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job || 'pending' !== $job['status'] ) {
			return array(
				'success' => false,
				'message' => 'Invalid job state',
			);
		}

		$this->update_job(
			$job_id,
			array(
				'status'       => 'outline',
				'current_step' => 1,
			)
		);

		try {
			$additional_instructions = $job['additional_instructions'] ?? '';

			$outline = $this->generator->generate_outline( $job['topic'], $additional_instructions );

			if ( is_wp_error( $outline ) ) {
				throw new Exception( $outline->get_error_message() );
			}

			$this->update_job(
				$job_id,
				array(
					'outline'      => wp_json_encode( $outline, JSON_UNESCAPED_UNICODE ),
					'current_step' => 1,
				)
			);

			return array(
				'success' => true,
				'message' => 'アウトライン生成完了',
				'outline' => $outline,
			);

		} catch ( Exception $e ) {
			$this->update_job(
				$job_id,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
				)
			);
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Step 2: 本文生成
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_content( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job || 'outline' !== $job['status'] ) {
			return array(
				'success' => false,
				'message' => 'Invalid job state',
			);
		}

		$this->update_job(
			$job_id,
			array(
				'status'       => 'content',
				'current_step' => 2,
			)
		);

		try {
			$outline = json_decode( $job['outline'], true );
			$topic   = $job['topic'];
			$additional_instructions = $job['additional_instructions'] ?? '';

			// 導入部生成
			$intro = $this->generator->generate_intro( $outline );

			// 各セクション生成
			$sections         = array();
			$previous_summary = '';

			foreach ( $outline['sections'] as $section ) {
				$section_result = $this->generator->generate_section_content(
					$section,
					$topic,
					$previous_summary,
					$additional_instructions
				);

				if ( is_wp_error( $section_result ) ) {
					error_log( 'Blog Poster: Section generation failed for ' . $section['h2'] . ': ' . $section_result->get_error_message() );
					continue;
				}

				$sections[]       = $section_result['content'];
				$previous_summary = $section_result['summary'];
			}

			// まとめ生成
			$all_content = implode( "\n\n", $sections );
			$summary     = $this->generator->generate_summary( $outline, $all_content );

			// 全体を組み立て
			$full_content = $intro . "\n\n" . $all_content . "\n\n" . $summary;

			$this->update_job(
				$job_id,
				array(
					'sections_content' => wp_json_encode( $sections, JSON_UNESCAPED_UNICODE ),
					'final_content'    => $full_content,
					'current_step'     => 2,
				)
			);

			return array(
				'success'         => true,
				'message'         => '本文生成完了',
				'content_preview' => mb_substr( $full_content, 0, 500 ) . '...',
			);

		} catch ( Exception $e ) {
			$this->update_job(
				$job_id,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
				)
			);
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Step 3: レビューと最終化
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_review( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job || 'content' !== $job['status'] ) {
			return array(
				'success' => false,
				'message' => 'Invalid job state',
			);
		}

		$this->update_job(
			$job_id,
			array(
				'status'       => 'review',
				'current_step' => 3,
			)
		);

		try {
			$outline = json_decode( $job['outline'], true );
			$content = $job['final_content'];

			// コードブロック修正
			$content = $this->generator->fix_code_blocks( $content );

			// ファクトチェック
			$content = $this->generator->fact_check_claude_references( $content );

			// 検証
			$validation = $this->generator->validate_article( $content );

			$this->update_job(
				$job_id,
				array(
					'status'        => 'completed',
					'final_content' => $content,
					'current_step'  => 3,
				)
			);

			return array(
				'success'          => true,
				'message'          => '記事生成完了',
				'title'            => $outline['title'],
				'slug'             => $outline['slug'],
				'meta_description' => $outline['meta_description'],
				'excerpt'          => $outline['excerpt'],
				'content'          => $content,
				'keywords'         => isset( $outline['keywords'] ) ? $outline['keywords'] : array(),
				'validation'       => $validation,
			);

		} catch ( Exception $e ) {
			$this->update_job(
				$job_id,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
				)
			);
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
