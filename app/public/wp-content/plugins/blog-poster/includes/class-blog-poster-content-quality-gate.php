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
		$issues = array_merge( $issues, $this->check_garbled_text( $markdown ) );
		$issues = array_merge( $issues, $this->check_reference_url_consistency( $markdown ) );
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
			if ( 0 !== mb_strpos( $line, '参考', 0, 'UTF-8' ) ) {
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

