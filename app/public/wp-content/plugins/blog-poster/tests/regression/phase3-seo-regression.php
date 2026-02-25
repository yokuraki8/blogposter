<?php
/**
 * Phase 3 SEO regression checks (WP-CLI eval-file).
 *
 * Usage:
 * wp eval-file tests/regression/phase3-seo-regression.php
 */

if ( ! defined( 'WPINC' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$results = array(
	'slug_collision'    => false,
	'meta_length_range' => false,
	'og_duplicate_safe' => false,
	'notes'             => array(),
);

$created_posts         = array();
$settings              = get_option( 'blog_poster_settings', array() );
$original_settings     = $settings;
$yoast_installed       = file_exists( WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php' );
$yoast_originally_on   = $yoast_installed && is_plugin_active( 'wordpress-seo/wp-seo.php' );

try {
	$settings['enable_yoast_integration'] = true;
	update_option( 'blog_poster_settings', $settings );

	if ( $yoast_installed && ! $yoast_originally_on ) {
		activate_plugin( 'wordpress-seo/wp-seo.php', '', false, true );
	}

	// slug collision regression.
	$first_id = wp_insert_post(
		array(
			'post_title'   => 'SEO Slug Collision One',
			'post_name'    => 'phase3-seo-collision',
			'post_content' => '<p>content one</p>',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$second_id = wp_insert_post(
		array(
			'post_title'   => 'SEO Slug Collision Two',
			'post_name'    => 'phase3-seo-collision',
			'post_content' => '<p>content two</p>',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $first_id ) && ! is_wp_error( $second_id ) ) {
		$created_posts[] = $first_id;
		$created_posts[] = $second_id;
		$slug_1 = get_post_field( 'post_name', $first_id );
		$slug_2 = get_post_field( 'post_name', $second_id );
		$results['slug_collision'] = ( $slug_1 !== '' && $slug_2 !== '' && $slug_1 !== $slug_2 );
	} else {
		$results['notes'][] = 'failed to create collision test posts';
	}

	// meta length regression.
	$meta_text = Blog_Poster_SEO_Helper::optimize_meta_description( '短い説明です。', array( 'SEO' ), 120, 160 );
	$meta_len = mb_strlen( $meta_text, 'UTF-8' );
	$results['meta_length_range'] = ( $meta_len >= 120 && $meta_len <= 160 );
	if ( ! $results['meta_length_range'] ) {
		$results['notes'][] = 'meta length out of range: ' . $meta_len;
	}

	// OG duplicate regression safety.
	$test_id = wp_insert_post(
		array(
			'post_title'   => 'OG Duplicate Safety Check',
			'post_content' => '<p>og content</p>',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $test_id ) ) {
		$created_posts[] = $test_id;
		Blog_Poster_SEO_Helper::apply_post_seo_meta(
			$test_id,
			array(
				'title'            => get_the_title( $test_id ),
				'slug'             => 'phase3-og-safety',
				'content'          => get_post_field( 'post_content', $test_id ),
				'meta_description' => 'OG重複回避の回帰テストです。',
				'keywords'         => array( 'OG', 'SEO' ),
				'canonical'        => get_permalink( $test_id ),
			)
		);

		ob_start();
		Blog_Poster::get_instance()->render_og_tags();
		$output_with_yoast = ob_get_clean();

		$results['og_duplicate_safe'] = ( $yoast_installed ? trim( $output_with_yoast ) === '' : true );
		if ( ! $results['og_duplicate_safe'] ) {
			$results['notes'][] = 'og tags were output while yoast is active';
		}
	} else {
		$results['notes'][] = 'failed to create og test post';
	}
} finally {
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}

	update_option( 'blog_poster_settings', $original_settings );

	if ( $yoast_installed ) {
		if ( $yoast_originally_on ) {
			activate_plugin( 'wordpress-seo/wp-seo.php', '', false, true );
		} else {
			deactivate_plugins( 'wordpress-seo/wp-seo.php', true, false );
		}
	}
}

echo wp_json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
