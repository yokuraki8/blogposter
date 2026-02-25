<?php
/**
 * Generated content quality gate.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blog_Poster_Content_Quality_Gate {

	/**
	 * @var array
	 */
	private $settings = array();

	/**
	 * @param array|null $settings Settings.
	 */
	public function __construct( $settings = null ) {
		if ( is_array( $settings ) ) {
			$this->settings = $settings;
			return;
		}

		$this->settings = class_exists( 'Blog_Poster_Settings' )
			? Blog_Poster_Settings::get_settings()
			: get_option( 'blog_poster_settings', array() );
	}

	/**
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->settings['auto_quality_gate_enabled'] );
	}

	/**
	 * @return string strict|warn
	 */
	public function get_mode() {
		$mode = isset( $this->settings['auto_quality_gate_mode'] )
			? sanitize_key( $this->settings['auto_quality_gate_mode'] )
			: 'strict';
		return in_array( $mode, array( 'strict', 'warn' ), true ) ? $mode : 'strict';
	}

	/**
	 * @return int
	 */
	public function get_max_auto_fixes() {
		$value = isset( $this->settings['auto_quality_gate_max_fixes'] )
			? (int) $this->settings['auto_quality_gate_max_fixes']
			: 1;
		return max( 0, min( 2, $value ) );
	}

	/**
	 * Validate generated markdown.
	 *
	 * @param string $markdown Markdown text.
	 * @param array  $context  Optional context (title/topic).
	 * @return array
	 */
	public function validate_markdown( $markdown, $context = array() ) {
		$issues = array();
		$markdown = (string) $markdown;

		$issues = array_merge( $issues, $this->check_heading_integrity( $markdown ) );
		$issues = array_merge( $issues, $this->check_heading_length_constraints( $markdown ) );
		$issues = array_merge( $issues, $this->check_unmarked_heading_candidates( $markdown ) );
		$issues = array_merge( $issues, $this->check_garbled_text( $markdown ) );
		$issues = array_merge( $issues, $this->check_typo_variants( $markdown ) );
		$issues = array_merge( $issues, $this->check_editorial_instruction_noise( $markdown ) );
		$issues = array_merge( $issues, $this->check_tone_noise( $markdown ) );
		$issues = array_merge( $issues, $this->check_reference_url_consistency( $markdown ) );
		$issues = array_merge( $issues, $this->check_reference_data_freshness( $markdown ) );
		$issues = array_merge( $issues, $this->check_external_link_audit( $context ) );
		$issues = array_merge( $issues, $this->check_year_consistency( $markdown, $context ) );

		$severity_score = 0;
		foreach ( $issues as $issue ) {
			$severity = isset( $issue['severity'] ) ? $issue['severity'] : 'medium';
			if ( 'high' === $severity ) {
				$severity_score += 30;
			} elseif ( 'medium' === $severity ) {
				$severity_score += 15;
			} else {
				$severity_score += 5;
			}
		}

		$quality_score = max( 0, 100 - $severity_score );
		$passes = empty( $issues );

		return array(
			'passes' => $passes,
			'mode' => $this->get_mode(),
			'quality_score' => $quality_score,
			'issues' => $issues,
		);
	}

	/**
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_heading_integrity( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		foreach ( $lines as $line_no => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$heading_text = '';
			if ( preg_match( '/^#{2,3}\s+(.+)$/u', $line, $m ) ) {
				$heading_text = trim( $m[1] );
			} elseif ( preg_match( '/^<h[23][^>]*>(.*?)<\/h[23]>$/iu', $line, $m ) ) {
				$heading_text = trim( wp_strip_all_tags( $m[1] ) );
			}

			if ( '' === $heading_text ) {
				continue;
			}

			if ( mb_strlen( $heading_text, 'UTF-8' ) < 6 ) {
				$issues[] = array(
					'type' => 'short_heading',
					'severity' => 'medium',
					'line' => $line_no + 1,
					'message' => '見出しが短すぎます: ' . $heading_text,
				);
			}

			// Common truncated heading stems found in broken generations.
			if ( preg_match( '/(取り組|進め|見直|高め|引き上|活用し)$/u', $heading_text ) ) {
				$issues[] = array(
					'type' => 'truncated_heading',
					'severity' => 'high',
					'line' => $line_no + 1,
					'message' => '見出しが途中で切れている可能性があります: ' . $heading_text,
				);
			}
		}

		return $issues;
	}

	/**
	 * 見出しらしい行にMarkdown見出し記法がないケースを検知
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_unmarked_heading_candidates( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		$total = count( $lines );
		for ( $i = 0; $i < $total; $i++ ) {
			$line = trim( (string) $lines[ $i ] );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6}\s+|<h[1-6]\b|[-*+]\s+|\d+[\.)]\s+|```|参考[:：]|https?:\/\/)/iu', $line ) ) {
				continue;
			}

			$len = mb_strlen( $line, 'UTF-8' );
			if ( $len < 8 || $len > 48 ) {
				continue;
			}

			$prev = $i > 0 ? trim( (string) $lines[ $i - 1 ] ) : '';
			$next = $i + 1 < $total ? trim( (string) $lines[ $i + 1 ] ) : '';
			if ( '' !== $prev || '' === $next ) {
				continue;
			}

			if ( preg_match( '/[。．]$/u', $line ) ) {
				continue;
			}

			$is_heading_like = (bool) preg_match( '/[？?!！:：]/u', $line )
				|| (bool) preg_match( '/(とは|ポイント|リスト|まとめ|方法|手順|コツ)$/u', $line );

			if ( ! $is_heading_like ) {
				continue;
			}

			$issues[] = array(
				'type' => 'unmarked_heading_candidate',
				'severity' => 'high',
				'line' => $i + 1,
				'message' => '見出し候補に見出し記法（## など）がありません: ' . $line,
			);
		}

		return $issues;
	}

	/**
	 * H見出しの長さ制約を検証
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_heading_length_constraints( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		foreach ( $lines as $line_no => $line ) {
			$line = trim( (string) $line );
			$level = 0;
			$text = '';
			if ( preg_match( '/^(#{2,3})\s+(.+)$/u', $line, $m ) ) {
				$level = strlen( $m[1] );
				$text = trim( (string) $m[2] );
			} elseif ( preg_match( '/^<h([23])[^>]*>(.*?)<\/h\1>$/iu', $line, $m ) ) {
				$level = (int) $m[1];
				$text = trim( wp_strip_all_tags( (string) $m[2] ) );
			} else {
				continue;
			}

			$len = mb_strlen( $text, 'UTF-8' );

			if ( 3 === $level && $len > 40 ) {
				$issues[] = array(
					'type' => 'heading_too_long_h3',
					'severity' => 'high',
					'line' => $line_no + 1,
					'message' => 'H3見出しが長すぎます（40文字超）: ' . $text,
				);
			}
		}

		return $issues;
	}

	/**
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_garbled_text( $markdown ) {
		$issues = array();

		// Example: ひっ逼迫ぱく / はん販促そく style mixed ruby-like corruption.
		if ( preg_match_all( '/[ぁ-ん]{1,3}[一-龯]{1,3}[ぁ-ん]{1,3}/u', $markdown, $matches ) ) {
			$suspects = array_slice( array_unique( $matches[0] ), 0, 5 );
			foreach ( $suspects as $suspect ) {
				if ( mb_strlen( $suspect, 'UTF-8' ) <= 3 ) {
					continue;
				}
				$issues[] = array(
					'type' => 'garbled_text',
					'severity' => 'high',
					'message' => '文字化け/誤記の疑い: ' . $suspect,
				);
			}
		}

		return $issues;
	}

	/**
	 * 誤記バリアント（例: 省エ-ネ / 省エEネ）を検知
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_typo_variants( $markdown ) {
		$issues = array();
		$patterns = array(
			'/省エ[-‐‑–—ー]ネ/u' => 'typo_shoene_hyphen',
			'/省エ[A-Za-z]ネ/u' => 'typo_shoene_ascii',
		);

		foreach ( $patterns as $pattern => $type ) {
			if ( preg_match_all( $pattern, $markdown, $matches ) ) {
				foreach ( array_slice( array_unique( $matches[0] ), 0, 5 ) as $hit ) {
					$issues[] = array(
						'type' => $type,
						'severity' => 'high',
						'message' => '誤記の疑いがあります: ' . $hit,
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_reference_url_consistency( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		foreach ( $lines as $idx => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$plain_line = trim( wp_strip_all_tags( $line ) );
			if ( ! preg_match( '/^(?:[-*+]\s*)?(参考|参照|出典)\s*[:：]?/u', $plain_line ) ) {
				continue;
			}

			$same_line_has_url = preg_match( '/https?:\/\/\S+/u', $line );
			$next_line_has_url = false;
			if ( isset( $lines[ $idx + 1 ] ) ) {
				$next_line = trim( (string) $lines[ $idx + 1 ] );
				$next_line_has_url = (bool) preg_match( '/https?:\/\/\S+/u', $next_line );
			}

			if ( ! $same_line_has_url && ! $next_line_has_url ) {
				$issues[] = array(
					'type' => 'reference_missing_url',
					'severity' => 'medium',
					'line' => $idx + 1,
					'message' => '参考情報にURLがありません: ' . $line,
				);
			}
		}

		return $issues;
	}

	/**
	 * 業務記事に不向きな文体ノイズを検知
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_tone_noise( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		foreach ( $lines as $line_no => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/（\s*？\s*）|\(\s*\?\s*\)/u', $line ) ) {
				$issues[] = array(
					'type' => 'tone_noise_uncertain_joke',
					'severity' => 'medium',
					'line' => $line_no + 1,
					'message' => '業務記事に不向きな文体ノイズを検知しました: ' . $line,
				);
			}
		}

		return $issues;
	}

	/**
	 * 記事本文に混入した操作指示文を検知
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_editorial_instruction_noise( $markdown ) {
		$issues = array();
		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}

		foreach ( $lines as $line_no => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^#{1,3}\s+/u', $line ) ) {
				continue;
			}

			if ( preg_match( '/(ブラウザ|アクセス|確認してみよう|以下のURL|クリック)/u', $line ) ) {
				$issues[] = array(
					'type' => 'editorial_instruction_noise',
					'severity' => 'high',
					'line' => $line_no + 1,
					'message' => '記事本文に編集指示が混入している可能性があります: ' . $line,
				);
			}
		}

		return $issues;
	}

	/**
	 * 本文主張年と参考URL年の乖離を検知
	 *
	 * @param string $markdown Markdown.
	 * @return array
	 */
	private function check_reference_data_freshness( $markdown ) {
		$issues = array();
		$body_years = array();
		$ref_years = array();

		if ( preg_match_all( '/(20\d{2})(?:年度|年)/u', $markdown, $body_matches ) ) {
			foreach ( $body_matches[1] as $year ) {
				$body_years[] = (int) $year;
			}
		}
		if ( empty( $body_years ) ) {
			return $issues;
		}

		$lines = preg_split( "/\R/u", $markdown );
		if ( ! is_array( $lines ) ) {
			return $issues;
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false === mb_strpos( $line, 'http', 0, 'UTF-8' ) && 0 !== mb_strpos( $line, '参考', 0, 'UTF-8' ) ) {
				continue;
			}
			if ( preg_match_all( '/(20\d{2})(?:年度|年)?/u', $line, $ref_matches ) ) {
				foreach ( $ref_matches[1] as $year ) {
					$ref_years[] = (int) $year;
				}
			}
		}

		if ( empty( $ref_years ) ) {
			return $issues;
		}

		$max_body_year = max( $body_years );
		$max_ref_year = max( $ref_years );
		if ( $max_body_year - $max_ref_year >= 1 ) {
			$issues[] = array(
				'type' => 'reference_data_stale',
				'severity' => 'high',
				'message' => sprintf(
					'本文年(%d)に対し参考情報年(%d)が古い可能性があります。',
					$max_body_year,
					$max_ref_year
				),
			);
		}

		return $issues;
	}

	/**
	 * 一次情報監査結果を品質ゲートに反映
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private function check_external_link_audit( $context ) {
		$issues = array();
		$score_threshold = isset( $this->settings['primary_research_credibility_threshold'] )
			? max( 0, min( 100, (int) $this->settings['primary_research_credibility_threshold'] ) )
			: 70;
		$audit = isset( $context['external_link_audit'] ) && is_array( $context['external_link_audit'] )
			? $context['external_link_audit']
			: array();
		if ( empty( $audit ) ) {
			return $issues;
		}

		foreach ( $audit as $url => $report ) {
			$valid = ! empty( $report['valid'] );
			$exists = ! isset( $report['exists'] ) || ! empty( $report['exists'] );
			$score = isset( $report['credibility_score'] ) ? (int) $report['credibility_score'] : 0;

			if ( ! $exists ) {
				$issues[] = array(
					'type' => 'external_link_not_found',
					'severity' => 'high',
					'message' => '外部リンクの実在確認に失敗しました: ' . $url,
				);
				continue;
			}

			if ( ! $valid ) {
				$issues[] = array(
					'type' => 'external_link_untrusted',
					'severity' => 'high',
					'message' => '一次情報基準を満たさない外部リンクがあります: ' . $url,
				);
				continue;
			}

			if ( $score > 0 && $score < $score_threshold ) {
				$issues[] = array(
					'type' => 'external_link_low_score',
					'severity' => 'medium',
					'message' => '外部リンクの信頼性スコアが低めです: ' . $url . ' (score=' . $score . ')',
				);
			}
		}

		return $issues;
	}

	/**
	 * @param string $markdown Markdown.
	 * @param array  $context  Context.
	 * @return array
	 */
	private function check_year_consistency( $markdown, $context ) {
		$issues = array();
		$title = isset( $context['title'] ) ? (string) $context['title'] : '';
		if ( '' === $title ) {
			if ( preg_match( '/^#{1,2}\s+(.+)$/mu', $markdown, $m ) ) {
				$title = trim( $m[1] );
			}
		}

		if ( ! preg_match( '/(20\d{2})年最新/u', $title, $m ) ) {
			return $issues;
		}
		$title_year = (int) $m[1];
		if ( $title_year < 2020 ) {
			return $issues;
		}

		$years_in_body = array();
		if ( preg_match_all( '/(20\d{2})年度/u', $markdown, $m2 ) ) {
			foreach ( $m2[1] as $year ) {
				$years_in_body[] = (int) $year;
			}
		}
		if ( empty( $years_in_body ) ) {
			return $issues;
		}

		$max_body_year = max( $years_in_body );
		if ( $max_body_year <= ( $title_year - 2 ) ) {
			$issues[] = array(
				'type' => 'year_mismatch',
				'severity' => 'medium',
				'message' => sprintf( 'タイトル年(%d)に対し本文主要データ年(%d)が古い可能性があります。', $title_year, $max_body_year ),
			);
		}

		return $issues;
	}
}
