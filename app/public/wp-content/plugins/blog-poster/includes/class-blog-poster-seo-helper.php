<?php
/**
 * SEO helper for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blog_Poster_SEO_Helper {

	/**
	 * Generate normalized slug for SEO.
	 *
	 * @param string $title    Post title.
	 * @param string $raw_slug Candidate slug from input/frontmatter.
	 * @param array  $keywords Keyword list.
	 * @return string
	 */
	public static function normalize_slug( $title, $raw_slug = '', $keywords = array() ) {
		$base = (string) $raw_slug;
		if ( '' === trim( $base ) ) {
			$base = (string) $title;
		}

		$slug = sanitize_title( $base );

		// Fallback for Japanese-only strings or empty sanitized value.
		if ( '' === $slug && is_array( $keywords ) ) {
			foreach ( $keywords as $keyword ) {
				$candidate = sanitize_title( (string) $keyword );
				if ( '' !== $candidate ) {
					$slug = $candidate;
					break;
				}
			}
		}

		if ( '' === $slug ) {
			$slug = 'post-' . gmdate( 'Ymd-his' );
		}

		return $slug;
	}

	/**
	 * Build SEO description from HTML content.
	 *
	 * @param string $html_content HTML content.
	 * @param array  $keywords     Keyword list.
	 * @param int    $min_length   Minimum length.
	 * @param int    $max_length   Maximum length.
	 * @return string
	 */
	public static function build_meta_description_from_html( $html_content, $keywords = array(), $min_length = 120, $max_length = 160 ) {
		$text = wp_strip_all_tags( (string) $html_content );
		return self::optimize_meta_description( $text, $keywords, $min_length, $max_length );
	}

	/**
	 * Optimize SEO meta description.
	 *
	 * @param string $text       Raw text.
	 * @param array  $keywords   Keyword list.
	 * @param int    $min_length Minimum length.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	public static function optimize_meta_description( $text, $keywords = array(), $min_length = 120, $max_length = 160 ) {
		$text = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) );
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		$primary = self::get_focus_keyword( $keywords );
		if ( '' !== $primary && false === mb_stripos( $text, $primary, 0, 'UTF-8' ) ) {
			$text = $primary . '。' . $text;
		}

		if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
			$text = mb_substr( $text, 0, $max_length, 'UTF-8' );
			$text = preg_replace( '/[、。,\s]+$/u', '', $text );
		}

		if ( mb_strlen( $text, 'UTF-8' ) < $min_length ) {
			$addon = '初心者にも分かりやすく、実務で使えるポイントを簡潔に解説します。';
			if ( '' !== $primary && false === mb_stripos( $addon, $primary, 0, 'UTF-8' ) ) {
				$addon = $primary . 'の要点も押さえます。' . $addon;
			}
			while ( mb_strlen( $text, 'UTF-8' ) < $min_length ) {
				$text .= $addon;
				if ( mb_strlen( $text, 'UTF-8' ) >= $max_length ) {
					break;
				}
			}
			if ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
				$text = mb_substr( $text, 0, $max_length, 'UTF-8' );
				$text = preg_replace( '/[、。,\s]+$/u', '', $text );
			}
		}

		return $text;
	}

	/**
	 * Extract keywords from title and content.
	 *
	 * @param string $title   Title text.
	 * @param string $content Content text or HTML.
	 * @param int    $limit   Maximum keyword count.
	 * @return array
	 */
	public static function extract_keywords( $title, $content, $limit = 8 ) {
		$limit = max( 1, intval( $limit ) );
		$title_tokens = self::tokenize_keywords( (string) $title );
		$body_tokens  = self::tokenize_keywords( wp_strip_all_tags( (string) $content ) );

		$scores = array();

		foreach ( $body_tokens as $token ) {
			if ( ! isset( $scores[ $token ] ) ) {
				$scores[ $token ] = 0;
			}
			$scores[ $token ] += 1;
		}
		foreach ( $title_tokens as $token ) {
			if ( ! isset( $scores[ $token ] ) ) {
				$scores[ $token ] = 0;
			}
			$scores[ $token ] += 3;
		}

		arsort( $scores );
		$keywords = array_keys( $scores );
		return array_slice( $keywords, 0, $limit );
	}

	/**
	 * Build keyword suggestions with score and placement.
	 *
	 * @param string $title         Post title.
	 * @param string $content       Post content (HTML).
	 * @param array  $seed_keywords Existing keywords.
	 * @param int    $limit         Maximum suggestion count.
	 * @return array
	 */
	public static function build_keyword_suggestions( $title, $content, $seed_keywords = array(), $limit = 5 ) {
		$limit = max( 1, intval( $limit ) );
		$content_text = wp_strip_all_tags( (string) $content );
		$extracted = self::extract_keywords( $title, $content_text, max( $limit, 8 ) );

		$pool = array();
		if ( is_array( $seed_keywords ) ) {
			foreach ( $seed_keywords as $keyword ) {
				$keyword = trim( (string) $keyword );
				if ( '' !== $keyword ) {
					$pool[] = $keyword;
				}
			}
		}
		$pool = array_merge( $pool, $extracted );
		$pool = array_values( array_unique( $pool ) );

		$suggestions = array();
		foreach ( $pool as $keyword ) {
			$in_title = ( false !== mb_stripos( $title, $keyword, 0, 'UTF-8' ) );
			$occurs   = substr_count( mb_strtolower( $content_text, 'UTF-8' ), mb_strtolower( $keyword, 'UTF-8' ) );
			$score    = min( 100, ( $in_title ? 45 : 15 ) + ( $occurs * 12 ) + min( 18, mb_strlen( $keyword, 'UTF-8' ) ) );
			$placement = $in_title ? 'title,h2,body' : 'h2,body';

			$suggestions[] = array(
				'keyword' => $keyword,
				'score'   => $score,
				'recommended_placement' => $placement,
			);
		}

		usort(
			$suggestions,
			static function ( $a, $b ) {
				return intval( $b['score'] ) <=> intval( $a['score'] );
			}
		);

		return array_slice( $suggestions, 0, $limit );
	}

	/**
	 * Pick focus keyword from candidate list.
	 *
	 * @param array $keywords Keyword list.
	 * @return string
	 */
	public static function get_focus_keyword( $keywords ) {
		if ( ! is_array( $keywords ) ) {
			return '';
		}
		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' !== $keyword ) {
				return $keyword;
			}
		}
		return '';
	}

	/**
	 * Apply Blog Poster SEO metadata and Yoast sync.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    SEO payload.
	 * @return void
	 */
	public static function apply_post_seo_meta( $post_id, $args = array() ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		$title       = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : get_the_title( $post_id );
		$content     = isset( $args['content'] ) ? (string) $args['content'] : '';
		$slug        = self::normalize_slug( $title, $args['slug'] ?? '', $args['keywords'] ?? array() );
		$keywords    = isset( $args['keywords'] ) && is_array( $args['keywords'] ) ? $args['keywords'] : array();
		$meta_desc   = isset( $args['meta_description'] ) ? (string) $args['meta_description'] : '';
		$canonical   = isset( $args['canonical'] ) ? esc_url_raw( $args['canonical'] ) : '';

		if ( empty( $keywords ) ) {
			$keywords = self::extract_keywords( $title, $content, 8 );
		}

		$focus = self::get_focus_keyword( $keywords );
		$meta_desc = self::optimize_meta_description( $meta_desc, $keywords, 120, 160 );
		if ( '' === $meta_desc ) {
			$meta_desc = self::build_meta_description_from_html( $content, $keywords );
		}

		$suggestions = self::build_keyword_suggestions( $title, $content, $keywords, 5 );
		if ( '' === $canonical ) {
			$canonical = get_permalink( $post_id );
		}

		// Ensure post_name is updated with normalized slug.
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => $slug,
			)
		);

		update_post_meta( $post_id, '_blog_poster_meta_description', $meta_desc );
		update_post_meta( $post_id, '_blog_poster_keywords', implode( ',', $keywords ) );
		update_post_meta( $post_id, '_blog_poster_focus_keyword', $focus );
		update_post_meta( $post_id, '_blog_poster_keyword_suggestions', $suggestions );
		update_post_meta( $post_id, '_blog_poster_canonical', $canonical );

		$og = self::build_og_meta( $post_id, $title, $meta_desc, $content );
		update_post_meta( $post_id, '_blog_poster_og_title', $og['title'] );
		update_post_meta( $post_id, '_blog_poster_og_description', $og['description'] );
		update_post_meta( $post_id, '_blog_poster_og_type', $og['type'] );
		update_post_meta( $post_id, '_blog_poster_og_url', $og['url'] );
		update_post_meta( $post_id, '_blog_poster_og_image', $og['image'] );

		if ( self::is_yoast_enabled() ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_canonical', $canonical );
		}
	}

	/**
	 * Run one SEO optimization pass and save analysis.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function optimize_post_for_score( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! class_exists( 'Blog_Poster_SEO_Analyzer' ) ) {
			return array();
		}

		$analyzer = new Blog_Poster_SEO_Analyzer();
		$before   = $analyzer->analyze_comprehensive( $post_id );
		$before_score = intval( $before['overall']['composite_score'] ?? 0 );

		if ( $before_score < 75 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$keywords = explode( ',', (string) get_post_meta( $post_id, '_blog_poster_keywords', true ) );
				self::apply_post_seo_meta(
					$post_id,
					array(
						'title'            => $post->post_title,
						'slug'             => $post->post_name,
						'content'          => $post->post_content,
						'meta_description' => get_post_meta( $post_id, '_blog_poster_meta_description', true ),
						'keywords'         => $keywords,
						'canonical'        => get_post_meta( $post_id, '_blog_poster_canonical', true ),
					)
				);
			}
		}

		$after = $analyzer->analyze_comprehensive( $post_id );
		$analyzer->save_analysis( $post_id, $after );

		return array(
			'before_score' => $before_score,
			'after_score'  => intval( $after['overall']['composite_score'] ?? 0 ),
		);
	}

	/**
	 * Build Open Graph metadata values.
	 *
	 * @param int    $post_id          Post ID.
	 * @param string $title            Post title.
	 * @param string $meta_description Meta description.
	 * @param string $content          Post content.
	 * @return array
	 */
	public static function build_og_meta( $post_id, $title, $meta_description, $content ) {
		$image = '';
		if ( has_post_thumbnail( $post_id ) ) {
			$image = get_the_post_thumbnail_url( $post_id, 'full' );
		}

		return array(
			'title'       => sanitize_text_field( $title ),
			'description' => self::optimize_meta_description( $meta_description, self::extract_keywords( $title, $content, 3 ), 120, 160 ),
			'type'        => 'article',
			'url'         => esc_url_raw( get_permalink( $post_id ) ),
			'image'       => esc_url_raw( $image ),
		);
	}

	/**
	 * Check Yoast plugin is active.
	 *
	 * @return bool
	 */
	public static function is_yoast_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'wordpress-seo/wp-seo.php' );
	}

	/**
	 * Check Yoast integration is enabled in plugin settings.
	 *
	 * @return bool
	 */
	public static function is_yoast_enabled() {
		$settings = class_exists( 'Blog_Poster_Settings' ) ? Blog_Poster_Settings::get_settings() : array();
		return ! empty( $settings['enable_yoast_integration'] ) && self::is_yoast_active();
	}

	/**
	 * Tokenize text for keyword extraction.
	 *
	 * @param string $text Input text.
	 * @return array
	 */
	private static function tokenize_keywords( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$tokens = array();
		if ( preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}ー]{2,20}/u', $text, $jp_matches ) ) {
			$tokens = array_merge( $tokens, $jp_matches[0] );
		}
		if ( preg_match_all( '/[A-Za-z][A-Za-z0-9\+\-\.]{1,30}/u', $text, $en_matches ) ) {
			$tokens = array_merge( $tokens, $en_matches[0] );
		}

		$stop = array(
			'これ', 'それ', 'ため', 'よう', 'こと', 'もの', 'です', 'ます', 'する', 'した', 'して',
			'with', 'from', 'that', 'this', 'have', 'will', 'your', 'about', 'into', 'also',
		);

		$clean = array();
		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}
			if ( in_array( mb_strtolower( $token, 'UTF-8' ), $stop, true ) ) {
				continue;
			}
			$clean[] = $token;
		}

		return $clean;
	}
}
