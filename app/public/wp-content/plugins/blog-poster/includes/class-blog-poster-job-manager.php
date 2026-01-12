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
			outline_md longtext,
			content_md longtext,
			final_markdown longtext,
			final_html longtext,
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
	 * ジョブをキャンセル
	 *
	 * @param int $job_id ジョブID
	 * @return bool
	 */
	public function cancel_job( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return false;
		}

		if ( 'completed' === $job['status'] || 'failed' === $job['status'] ) {
			return false;
		}

		return (bool) $this->update_job(
			$job_id,
			array(
				'status'        => 'cancelled',
				'error_message' => 'Cancelled by user',
			)
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

			$outline_md  = null;
			$last_error  = '';
			$max_attempt = 2;

			for ( $attempt = 0; $attempt < $max_attempt; $attempt++ ) {
				$outline_md = $this->generator->generate_outline_markdown( $job['topic'], $additional_instructions );

				if ( ! is_wp_error( $outline_md ) ) {
					break;
				}

				$last_error = $outline_md->get_error_message();
				error_log( 'Blog Poster: Outline generation retry ' . ( $attempt + 1 ) . ' failed: ' . $last_error );
			}

			if ( is_wp_error( $outline_md ) || null === $outline_md ) {
				throw new Exception( $last_error ?: 'アウトライン生成に失敗しました。' );
			}

			$this->update_job(
				$job_id,
				array(
					'outline_md'   => $outline_md,
					'current_step' => 1,
				)
			);

			return array(
				'success' => true,
				'message' => 'アウトライン生成完了',
				'outline' => $outline_md,
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
	 * Step 2: 本文生成 (Markdown形式)
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_content( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( ! $job || ! in_array( $job['status'], array( 'outline', 'content' ), true ) ) {
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
			$outline_md = $job['outline_md'] ?? '';
			$topic   = $job['topic'];
			$additional_instructions = $job['additional_instructions'] ?? '';

			if ( empty( $outline_md ) ) {
				throw new Exception( 'アウトラインが見つかりません。' );
			}

			// Markdownアウトラインから本文を生成
			$content_md = '';

			// セクション単位で本文を生成
			// TODO: outline_mdをパースしてセクション情報を抽出
			$sections_md = $this->extract_sections_from_outline_markdown( $outline_md );

			$previous_summary = '';

			foreach ( $sections_md as $section ) {
				$section_result = $this->generator->generate_section_markdown(
					$section,
					$topic,
					$previous_summary,
					$additional_instructions
				);

				if ( is_wp_error( $section_result ) ) {
					error_log( 'Blog Poster: Section generation failed for ' . $section['title'] . ': ' . $section_result->get_error_message() );
					continue;
				}

				$content_md .= $section_result . "\n\n";
				$previous_summary = $section_result;
			}

			$this->update_job(
				$job_id,
				array(
					'content_md'   => $content_md,
					'current_step' => 2,
				)
			);

			return array(
				'success'         => true,
				'message'         => '本文生成完了',
				'content_preview' => mb_substr( $content_md, 0, 500 ) . '...',
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
	 * Step 3: レビューと最終化 (Markdown-First)
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
			$content_md = $job['content_md'] ?? '';
			$topic      = $job['topic'];
			$outline_md = $job['outline_md'] ?? '';

			if ( empty( $content_md ) ) {
				throw new Exception( '本文が見つかりません。' );
			}

			// Markdown後処理
			$final_markdown = $this->generator->postprocess_markdown( $content_md );

			if ( is_wp_error( $final_markdown ) ) {
				throw new Exception( $final_markdown->get_error_message() );
			}

			// MarkdownからHTMLへ変換
			$final_html = $this->generator->markdown_to_html( $final_markdown );

			if ( is_wp_error( $final_html ) ) {
				throw new Exception( $final_html->get_error_message() );
			}

			$this->update_job(
				$job_id,
				array(
					'status'          => 'completed',
					'final_markdown'  => $final_markdown,
					'final_html'      => $final_html,
					'current_step'    => 3,
				)
			);

			// アウトラインからメタデータを抽出
			$outline_metadata = $this->extract_metadata_from_outline_markdown( $outline_md );

			return array(
				'success'          => true,
				'message'          => '記事生成完了',
				'title'            => $outline_metadata['title'] ?? 'Untitled',
				'slug'             => $outline_metadata['slug'] ?? '',
				'meta_description' => $outline_metadata['meta_description'] ?? '',
				'excerpt'          => $outline_metadata['excerpt'] ?? '',
				'markdown'         => $final_markdown,
				'html'             => $final_html,
				'keywords'         => $outline_metadata['keywords'] ?? array(),
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
	 * Markdownアウトラインからセクション情報を抽出
	 *
	 * @param string $outline_md Markdownアウトライン
	 * @return array セクション情報配列
	 */
	private function extract_sections_from_outline_markdown( $outline_md ) {
		// TODO: Markdown形式のアウトラインをパースしてセクション配列に変換
		// 実装例: h2ヘッダーごとにセクションを分割
		$sections = array();

		// プレースホルダー実装
		preg_match_all( '/^## (.+)$/m', $outline_md, $matches );

		foreach ( $matches[1] as $title ) {
			$sections[] = array(
				'title' => $title,
				'content' => '',
			);
		}

		return ! empty( $sections ) ? $sections : array();
	}

	/**
	 * Markdownアウトラインからメタデータを抽出
	 *
	 * @param string $outline_md Markdownアウトライン
	 * @return array メタデータ配列
	 */
	private function extract_metadata_from_outline_markdown( $outline_md ) {
		$metadata = array(
			'title'            => 'Untitled',
			'slug'             => '',
			'meta_description' => '',
			'excerpt'          => '',
			'keywords'         => array(),
		);

		// TODO: Markdown形式のアウトラインからメタデータをパース
		// 実装例: YAML Front Matterまたはコメント形式から抽出

		// プレースホルダー実装
		if ( preg_match( '/^# (.+)$/m', $outline_md, $matches ) ) {
			$metadata['title'] = trim( $matches[1] );
		}

		return $metadata;
	}
}
