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
	 * テーブルの存在を確認し、なければ作成、あれば更新
	 */
	private function ensure_table_exists() {
		global $wpdb;

		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" );

		if ( $table_exists !== $this->table_name ) {
			error_log( 'Blog Poster: Jobs table does not exist. Creating...' );
			$this->create_table();
		} else {
			// 既存テーブルがある場合はスキーマ更新を確認
			$this->upgrade_table();
		}
	}

	/**
	 * ジョブテーブルを作成/更新
	 */
	private function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$this->table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			topic varchar(500) NOT NULL,
			additional_instructions text,
			ai_provider varchar(100),
			ai_model varchar(100),
			temperature float DEFAULT 0.7,
			article_length varchar(20) DEFAULT 'standard',
			status varchar(20) DEFAULT 'pending',
			current_step int(11) DEFAULT 0,
			total_steps int(11) DEFAULT 3,
			current_section_index int(11) DEFAULT 0,
			total_sections int(11) DEFAULT 0,
			previous_context text,
			outline_md longtext,
			content_md longtext,
			final_markdown longtext,
			final_html longtext,
			post_id bigint(20) UNSIGNED DEFAULT 0,
			final_title text,
			error_message text,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );

		error_log( 'Blog Poster: Jobs table schema updated.' );
	}

	/**
	 * テーブルスキーマを更新
	 */
	private function upgrade_table() {
		// create_tableと同じ処理だが、カラム追加のために呼び出す
		$this->create_table();
	}

	/**
	 * 新しいジョブを作成
	 *
	 * @param string $topic トピック
	 * @param string $additional_instructions 追加指示
	 * @param array  $options オプション配列 (ai_provider, ai_model, temperature, article_length)
	 * @return int ジョブID
	 */
	public function create_job( $topic, $additional_instructions = '', $options = array() ) {
		global $wpdb;

		// $options から ai_provider, ai_model, temperature, article_length を取得
		$ai_provider = isset( $options['ai_provider'] ) ? $options['ai_provider'] : '';
		$ai_model = isset( $options['ai_model'] ) ? $options['ai_model'] : '';
		$temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : 0.7;
		$article_length = isset( $options['article_length'] ) ? $options['article_length'] : 'standard';

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'topic'                    => $topic,
				'additional_instructions'  => $additional_instructions,
				'ai_provider'              => $ai_provider,
				'ai_model'                 => $ai_model,
				'temperature'              => $temperature,
				'article_length'           => $article_length,
				'status'                   => 'pending',
				'current_section_index'    => 0,
				'total_sections'           => 0,
				'previous_context'         => '',
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
	 * 次に実行可能なジョブを取得
	 *
	 * @return array|null ジョブデータまたはnull
	 */
	public function get_next_runnable_job() {
		global $wpdb;

		// pending, outline, content, review のいずれかのステータスのジョブを取得
		$job = $wpdb->get_row(
			"SELECT * FROM {$this->table_name}
			 WHERE status IN ('pending', 'outline', 'content', 'review')
			 ORDER BY created_at ASC
			 LIMIT 1",
			ARRAY_A
		);

		return $job;
	}

	/**
	 * Step 1: アウトライン生成
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_outline( $job_id ) {
		$job = $this->get_job( $job_id );
		$original_settings = $this->apply_job_settings( $job );

		if ( ! $job || 'pending' !== $job['status'] ) {
			$this->restore_job_settings( $original_settings );
			return array(
				'success' => false,
				'message' => 'Invalid job state',
			);
		}

		try {
			error_log( 'Blog Poster: Starting Markdown outline flow (claude default).' );
			$additional_instructions = $job['additional_instructions'] ?? '';

			// article_lengthをジョブから取得してジェネレーターに設定
			$article_length = $job['article_length'] ?? 'standard';
			$this->generator->set_article_length( $article_length );

			$outline_result = null;
			$last_error     = '';
			$max_attempt    = 5;
			$used_fallback  = false;

			for ( $attempt = 0; $attempt < $max_attempt; $attempt++ ) {
				if ( $attempt >= 2 ) {
					$outline_result = $this->generator->generate_outline_markdown( $job['topic'], $additional_instructions, 'gemini-2.5-flash' );
					$used_fallback = true;
				} else {
					$outline_result = $this->generator->generate_outline_markdown( $job['topic'], $additional_instructions );
				}

				if ( ! is_wp_error( $outline_result ) ) {
					break;
				}

				$last_error = $outline_result->get_error_message();
				if ( 'api_insufficient_quota' === $outline_result->get_error_code() ) {
					break;
				}
				error_log( 'Blog Poster: Outline generation retry ' . ( $attempt + 1 ) . ' failed: ' . $last_error );
			}

			if ( is_wp_error( $outline_result ) || null === $outline_result ) {
				throw new Exception( $last_error ?: 'アウトライン生成に失敗しました。' );
			}

			if ( empty( $outline_result['success'] ) ) {
				throw new Exception( 'アウトライン生成結果が不正です。' );
			}

			$outline_md = isset( $outline_result['outline_md'] ) ? $outline_result['outline_md'] : '';

			if ( empty( $outline_md ) ) {
				throw new Exception( 'アウトラインが空です。' );
			}

			// セクション数をカウントして初期化
			$sections = isset( $outline_result['sections'] ) ? $outline_result['sections'] : array();
			$total_sections = count( $sections );

			$this->update_job(
				$job_id,
				array(
					'outline_md'      => $outline_md,
					'current_step'    => 1,
					'status'          => 'outline',
					'total_sections'  => $total_sections,
					'current_section_index' => 0,
					'previous_context' => '',
				)
			);

			return array(
				'success' => true,
				'message' => 'アウトライン生成完了',
				'outline' => $outline_md,
				'total_sections' => $total_sections,
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
		} finally {
			$this->restore_job_settings( $original_settings );
		}
	}

	/**
	 * Step 2: 本文生成 (ステップ実行による分割処理)
	 *
	 * @param int $job_id ジョブID
	 * @return array 実行結果
	 */
	public function process_step_content( $job_id ) {
		$job = $this->get_job( $job_id );
		$original_settings = $this->apply_job_settings( $job );

		if ( ! $job || ! in_array( $job['status'], array( 'outline', 'content' ), true ) ) {
			$this->restore_job_settings( $original_settings );
			return array(
				'success' => false,
				'message' => 'Invalid job state',
			);
		}

		try {
			// article_lengthをジョブから取得してジェネレーターに設定
			$article_length = $job['article_length'] ?? 'standard';
			$this->generator->set_article_length( $article_length );

			$outline_md = $job['outline_md'] ?? '';
			$additional_instructions = $job['additional_instructions'] ?? '';
			$current_section_index = intval( $job['current_section_index'] ?? 0 );
			$previous_context = $job['previous_context'] ?? '';
			$current_content_md = $job['content_md'] ?? '';

			if ( empty( $outline_md ) ) {
				// 直前の更新反映待ち（非同期実行の競合回避）
				for ( $retry = 0; $retry < 2 && empty( $outline_md ); $retry++ ) {
					sleep( 1 );
					$job_retry = $this->get_job( $job_id );
					$outline_md = $job_retry['outline_md'] ?? '';
				}
			}

			if ( empty( $outline_md ) ) {
				return array(
					'success' => false,
					'message' => 'アウトライン生成中です。しばらく待ってから再試行してください。',
				);
			}

			// 初回または継続のステータス更新（アウトライン確認後）
			$this->update_job(
				$job_id,
				array(
					'status'       => 'content',
					'current_step' => 2,
				)
			);

			// Markdownアウトラインからセクション情報を取得
			$parsed_outline = $this->generator->parse_markdown_frontmatter( $outline_md );
			$sections       = isset( $parsed_outline['sections'] ) ? $parsed_outline['sections'] : array();

			if ( empty( $sections ) ) {
				throw new Exception( 'アウトラインからセクションを抽出できませんでした。' );
			}

			// 全セクション数を更新（念のため）
			$total_sections = count( $sections );
			if ( $job['total_sections'] != $total_sections ) {
				$this->update_job( $job_id, array( 'total_sections' => $total_sections ) );
			}

			// すべて完了しているかチェック
			if ( $current_section_index >= $total_sections ) {
				return array(
					'success'         => true,
					'done'            => true,
					'message'         => 'すべてのセクションの生成が完了しました',
					'content_preview' => mb_substr( $current_content_md, 0, 500 ) . '...',
					'total_sections'  => $total_sections,
					'current_section' => $current_section_index,
				);
			}

			// 今回生成するセクション
			$section = $sections[ $current_section_index ];
			error_log( "Blog Poster: Processing section {$current_section_index} / {$total_sections}: " . $section['title'] );

			// セクション生成実行（リトライ処理付き）
			$max_retries = 3;
			$section_result = null;
			$last_error_msg = '';

			for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
				$section_result = $this->generator->generate_section_markdown(
					$sections,
					$current_section_index,
					$previous_context,
					$additional_instructions
				);

				if ( ! is_wp_error( $section_result ) ) {
					break; // 成功したらループを抜ける
				}

				$last_error_msg = $section_result->get_error_message();
				error_log( "Blog Poster: Section generation retry {$attempt} failed: {$last_error_msg}" );

				if ( 'api_insufficient_quota' === $section_result->get_error_code() ) {
					break;
				}

				if ( $attempt < $max_retries ) {
					sleep( 2 ); // 少し待ってからリトライ
				}
			}

			if ( is_wp_error( $section_result ) ) {
				throw new Exception( 'Section generation failed after ' . $max_retries . ' attempts: ' . $last_error_msg );
			}

			// 結果を結合
			$new_content_md = $current_content_md . $section_result['section_md'] . "\n\n";
			$new_context    = $section_result['context'];
			$next_index     = $current_section_index + 1;

			// DB更新
			$this->update_job(
				$job_id,
				array(
					'content_md'            => $new_content_md,
					'previous_context'      => $new_context,
					'current_section_index' => $next_index,
				)
			);

			// 完了判定
			$is_done = ( $next_index >= $total_sections );

			return array(
				'success'          => true,
				'done'             => $is_done,
				'message'          => $is_done ? '本文生成完了' : 'セクション生成完了',
				'total_sections'   => $total_sections,
				'current_section'  => $next_index,
				'content_preview'  => mb_substr( $new_content_md, 0, 100 ) . '...',
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
		} finally {
			$this->restore_job_settings( $original_settings );
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
		$original_settings = $this->apply_job_settings( $job );

		if ( ! $job || ! in_array( $job['status'], array( 'content', 'review' ), true ) ) {
			$this->restore_job_settings( $original_settings );
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
			// article_lengthをジョブから取得してジェネレーターに設定
			$article_length = $job['article_length'] ?? 'standard';
			$this->generator->set_article_length( $article_length );

			$content_md = $job['content_md'] ?? '';
			$topic      = $job['topic'];
			$outline_md = $job['outline_md'] ?? '';
			$additional_instructions = $job['additional_instructions'] ?? '';

			if ( empty( $content_md ) ) {
				throw new Exception( '本文が見つかりません。' );
			}

			// Markdown後処理
			$final_markdown = $this->generator->postprocess_markdown( $content_md );
			$final_markdown = $this->generator->normalize_code_blocks_after_generation( $final_markdown );

			// 末尾切れの保険処理（最終セクションを再生成）
			if ( $this->generator->is_truncated_markdown( $final_markdown ) ) {
				error_log( 'Blog Poster: Detected truncated markdown. Regenerating last section.' );
				$repaired_content = $this->regenerate_last_section_markdown( $content_md, $outline_md, $additional_instructions );
				if ( is_wp_error( $repaired_content ) ) {
					throw new Exception( $repaired_content->get_error_message() );
				}
				$content_md = $repaired_content;
				$final_markdown = $this->generator->postprocess_markdown( $content_md );
				$final_markdown = $this->generator->normalize_code_blocks_after_generation( $final_markdown );

				if ( $this->generator->is_truncated_markdown( $final_markdown ) ) {
					error_log( 'Blog Poster: Truncation still suspected after regeneration. Proceeding with warning.' );
				}
			}

			if ( is_wp_error( $final_markdown ) ) {
				throw new Exception( $final_markdown->get_error_message() );
			}

			// MarkdownからHTMLへ変換
			$final_html = Blog_Poster_Admin::markdown_to_html( $final_markdown );

			if ( is_wp_error( $final_html ) ) {
				throw new Exception( $final_html->get_error_message() );
			}

			// アウトラインからメタデータを抽出
			$outline_meta = array();
			if ( ! empty( $outline_md ) ) {
				$parsed_outline = $this->generator->parse_markdown_frontmatter( $outline_md );
				$outline_meta   = isset( $parsed_outline['meta'] ) ? $parsed_outline['meta'] : array();
			}

			// Markdownからタイトルを抽出
			$title = $outline_meta['title'] ?? '';
			if ( empty( $title ) && ! empty( $final_markdown ) ) {
				if ( preg_match( '/^#\s+(.+)$/m', $final_markdown, $matches ) ) {
					$title = trim( $matches[1] );
				}
			}
			if ( empty( $title ) ) {
				// ユーザー入力のトピックをフォールバックとして使用
				$title = ! empty( $topic ) ? $topic : 'Untitled';
				error_log( 'Blog Poster: Using topic as title fallback: ' . $title );
			}

			$this->update_job(
				$job_id,
				array(
					'final_markdown'  => $final_markdown,
					'final_html'      => $final_html,
					'current_step'    => 3,
					'final_title'     => $title,
				)
			);

			return array(
				'success'          => true,
				'message'          => '記事生成完了',
				'title'            => $title,
				'slug'             => $outline_meta['slug'] ?? '',
				'meta_description' => $outline_meta['meta_description'] ?? '',
				'excerpt'          => $outline_meta['excerpt'] ?? '',
				'markdown'         => $final_markdown,
				'html'             => $final_html,
				'keywords'         => $outline_meta['keywords'] ?? array(),
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
		} finally {
			$this->restore_job_settings( $original_settings );
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

	/**
	 * 内容MarkdownをH2見出しで分割
	 *
	 * @param string $content_md Markdown本文
	 * @return array セクション配列
	 */
	private function split_content_markdown_sections( $content_md ) {
		$parts = preg_split( '/^(## .+)$/m', $content_md, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$sections = array();
		$current = '';

		foreach ( $parts as $part ) {
			if ( preg_match( '/^## /', $part ) ) {
				if ( '' !== trim( $current ) ) {
					$sections[] = trim( $current );
				}
				$current = $part;
				continue;
			}

			$current .= "\n" . $part;
		}

		if ( '' !== trim( $current ) ) {
			$sections[] = trim( $current );
		}

		if ( empty( $sections ) ) {
			return array( trim( $content_md ) );
		}

		return $sections;
	}

	/**
	 * 末尾切れ検出時に最終セクションを再生成
	 *
	 * @param string $content_md Markdown本文
	 * @param string $outline_md Markdownアウトライン
	 * @param string $additional_instructions 追加指示
	 * @return string|WP_Error 修正後Markdown本文
	 */
	private function regenerate_last_section_markdown( $content_md, $outline_md, $additional_instructions ) {
		$parsed_outline = $this->generator->parse_markdown_frontmatter( $outline_md );
		$sections = isset( $parsed_outline['sections'] ) ? $parsed_outline['sections'] : array();

		if ( empty( $sections ) ) {
			return new WP_Error( 'outline_missing', 'アウトラインからセクションを抽出できませんでした。' );
		}

		$content_sections = $this->split_content_markdown_sections( $content_md );
		if ( empty( $content_sections ) ) {
			return new WP_Error( 'content_missing', '本文を分割できませんでした。' );
		}

		$last_index = count( $sections ) - 1;
		$previous_context = '';

		if ( count( $content_sections ) >= 2 ) {
			$previous_context = $this->generator->extract_section_context( $content_sections[ count( $content_sections ) - 2 ] );
		}

		$section_result = $this->generator->generate_section_markdown(
			$sections,
			$last_index,
			$previous_context,
			$additional_instructions
		);

		if ( is_wp_error( $section_result ) ) {
			return $section_result;
		}

		$content_sections[ count( $content_sections ) - 1 ] = $section_result['section_md'];

		return rtrim( implode( "\n\n", $content_sections ) ) . "\n";
	}

	/**
	 * ジョブの設定を適用
	 *
	 * @param array $job ジョブデータ
	 * @return array|null 以前の設定
	 */
	private function apply_job_settings( $job ) {
		if ( empty( $job ) || ( empty( $job['ai_provider'] ) && empty( $job['ai_model'] ) ) ) {
			return null;
		}

		$settings = get_option( 'blog_poster_settings', array() );
		$original = $settings;

		if ( ! empty( $job['ai_provider'] ) ) {
			$settings['ai_provider'] = $job['ai_provider'];
		}

		if ( ! empty( $job['ai_model'] ) ) {
			$provider = ! empty( $job['ai_provider'] ) ? $job['ai_provider'] : ( $settings['ai_provider'] ?? 'gemini' );
			$settings[ $provider . '_model' ] = $job['ai_model'];
		}

		if ( isset( $job['temperature'] ) && '' !== $job['temperature'] ) {
			$settings['temperature'] = floatval( $job['temperature'] );
		}

		update_option( 'blog_poster_settings', $settings );

		return $original;
	}

	/**
	 * 設定を元に戻す
	 *
	 * @param array|null $original_settings 以前の設定
	 * @return void
	 */
	private function restore_job_settings( $original_settings ) {
		if ( null === $original_settings ) {
			return;
		}

		update_option( 'blog_poster_settings', $original_settings );
	}
}
