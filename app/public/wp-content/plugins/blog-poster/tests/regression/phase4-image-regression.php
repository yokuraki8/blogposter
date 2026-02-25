<?php
/**
 * Phase 4 image generation regression checks (WP-CLI eval-file).
 *
 * Usage:
 * wp eval-file wp-content/plugins/blog-poster/tests/regression/phase4-image-regression.php
 */

if ( ! defined( 'WPINC' ) ) {
	exit( 1 );
}

$png_1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Wf4wAAAAASUVORK5CYII=';

$report = array(
	'openai_featured_image_set' => false,
	'gemini_featured_image_set' => false,
	'image_off_no_effect'       => false,
	'image_failure_no_block'    => false,
	'size_quality_reflected'    => false,
	'notes'                     => array(),
);

$state = array(
	'mode' => '',
	'openai_last_body' => null,
	'gemini_last_body' => null,
	'png' => $png_1x1,
);

$created_posts      = array();
$settings           = get_option( 'blog_poster_settings', array() );
$original_settings  = $settings;
$original_plan      = isset( $settings['subscription_plan'] ) ? $settings['subscription_plan'] : 'free';

$mock_filter = function ( $preempt, $args, $url ) use ( &$state ) {
	// OpenAI image generation mock.
	if ( false !== strpos( $url, 'api.openai.com/v1/images/generations' ) ) {
		$state['openai_last_body'] = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		if ( 'openai_fail' === $state['mode'] ) {
			return new WP_Error( 'openai_mock_fail', 'mock openai failure' );
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array(
							'b64_json' => $state['png'],
						),
					),
				)
			),
		);
	}

	// Gemini image generation mock.
	if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
		$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		if ( false !== strpos( $url, ':generateContent' ) ) {
			$state['gemini_last_body'] = $body;
		}
		if ( 'gemini_fail' === $state['mode'] ) {
			return new WP_Error( 'gemini_mock_fail', 'mock gemini failure' );
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array(
									array(
										'inlineData' => array(
											'mimeType' => 'image/png',
											'data'     => $state['png'],
										),
									),
								),
							),
						),
					),
				)
			),
		);
	}

	return $preempt;
};

add_filter( 'pre_http_request', $mock_filter, 10, 3 );

try {
	$settings['subscription_plan'] = 'paid_with_api';
	$settings['enable_image_generation'] = true;
	$settings['image_size'] = '1792x1024';
	$settings['image_quality'] = 'hd';
	$settings['image_openai_model'] = 'dall-e-3';
	$settings['image_gemini_model'] = 'gemini-2.0-flash-preview-image-generation';

	// 1) OpenAI path.
	$settings['image_provider'] = 'openai';
	$settings['ai_provider'] = 'openai';
	update_option( 'blog_poster_settings', $settings );
	$state['mode'] = 'openai_ok';

	$post_openai = wp_insert_post(
		array(
			'post_title'   => 'Phase4 OpenAI Image Test',
			'post_content' => '<p>openai image test body</p>',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $post_openai ) ) {
		$created_posts[] = $post_openai;
		$result = Blog_Poster_Image_Helper::maybe_generate_and_attach_featured_image(
			$post_openai,
			'Phase4 OpenAI Image Test',
			array(
				'title' => 'Phase4 OpenAI Image Test',
				'keywords' => array( 'OpenAI', 'DALL-E', 'Featured Image' ),
			),
			$settings
		);
		$report['openai_featured_image_set'] = ( ! empty( $result['success'] ) && has_post_thumbnail( $post_openai ) );
		$report['size_quality_reflected'] = (
			is_array( $state['openai_last_body'] )
			&& ( $state['openai_last_body']['size'] ?? '' ) === '1792x1024'
			&& ( $state['openai_last_body']['quality'] ?? '' ) === 'hd'
		);
	}

	// 2) Gemini path.
	$settings['image_provider'] = 'gemini';
	$settings['ai_provider'] = 'gemini';
	update_option( 'blog_poster_settings', $settings );
	$state['mode'] = 'gemini_ok';

	$post_gemini = wp_insert_post(
		array(
			'post_title'   => 'Phase4 Gemini Image Test',
			'post_content' => '<p>gemini image test body</p>',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $post_gemini ) ) {
		$created_posts[] = $post_gemini;
		$result = Blog_Poster_Image_Helper::maybe_generate_and_attach_featured_image(
			$post_gemini,
			'Phase4 Gemini Image Test',
			array(
				'title' => 'Phase4 Gemini Image Test',
				'keywords' => array( 'Gemini', 'Imagen', 'Featured Image' ),
			),
			$settings
		);
		$report['gemini_featured_image_set'] = ( ! empty( $result['success'] ) && has_post_thumbnail( $post_gemini ) );
	}

	// 3) OFF path.
	$settings['enable_image_generation'] = false;
	update_option( 'blog_poster_settings', $settings );
	$state['mode'] = 'openai_ok';
	$post_off = wp_insert_post(
		array(
			'post_title'   => 'Phase4 Image Off Test',
			'post_content' => '<p>image off test</p>',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $post_off ) ) {
		$created_posts[] = $post_off;
		$result = Blog_Poster_Image_Helper::maybe_generate_and_attach_featured_image(
			$post_off,
			'Phase4 Image Off Test',
			array(),
			$settings
		);
		$report['image_off_no_effect'] = ( ! empty( $result['skipped'] ) && ! has_post_thumbnail( $post_off ) );
	}

	// 4) Failure path does not block post existence.
	$settings['enable_image_generation'] = true;
	$settings['image_provider'] = 'openai';
	$settings['ai_provider'] = 'openai';
	update_option( 'blog_poster_settings', $settings );
	$state['mode'] = 'openai_fail';
	$post_fail = wp_insert_post(
		array(
			'post_title'   => 'Phase4 Image Fail Test',
			'post_content' => '<p>image fail test</p>',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	if ( ! is_wp_error( $post_fail ) ) {
		$created_posts[] = $post_fail;
		$result = Blog_Poster_Image_Helper::maybe_generate_and_attach_featured_image(
			$post_fail,
			'Phase4 Image Fail Test',
			array(),
			$settings
		);
		$report['image_failure_no_block'] = ( empty( $result['success'] ) && get_post( $post_fail ) instanceof WP_Post );
	}
} catch ( Throwable $e ) {
	$report['notes'][] = 'exception: ' . $e->getMessage();
} finally {
	remove_filter( 'pre_http_request', $mock_filter, 10 );
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}

	$settings_restore = $original_settings;
	$settings_restore['subscription_plan'] = $original_plan;
	update_option( 'blog_poster_settings', $settings_restore );
}

echo wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
