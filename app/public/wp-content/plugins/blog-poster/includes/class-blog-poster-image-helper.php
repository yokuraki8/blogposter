<?php
/**
 * Featured image helper for Blog Poster.
 *
 * @package BlogPoster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blog_Poster_Image_Helper {

	/**
	 * Check whether image generation should run.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function is_image_generation_enabled( $settings = array() ) {
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			$settings = Blog_Poster_Settings::get_settings();
		}

		$enabled = ! empty( $settings['enable_image_generation'] );
		$plan    = isset( $settings['subscription_plan'] ) ? (string) $settings['subscription_plan'] : 'free';

		return $enabled && 'free' !== $plan;
	}

	/**
	 * Generate and set featured image for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $topic    Post topic/title.
	 * @param array  $context  Prompt context.
	 * @param array  $settings Settings array.
	 * @return array
	 */
	public static function maybe_generate_and_attach_featured_image( $post_id, $topic, $context = array(), $settings = array() ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return array(
				'success' => false,
				'skipped' => true,
				'message' => 'invalid_post_id',
			);
		}

		if ( ! is_array( $settings ) || empty( $settings ) ) {
			$settings = Blog_Poster_Settings::get_settings();
		}

		if ( ! self::is_image_generation_enabled( $settings ) ) {
			return array(
				'success' => false,
				'skipped' => true,
				'message' => 'image_generation_disabled',
			);
		}

		$generator = new Blog_Poster_Generator();
		$provider  = self::resolve_image_provider( $settings );
		$override  = array( 'ai_provider' => $provider );
		$generator->set_settings_override( $override );

		$result = $generator->generate_featured_image( $topic, $context );
		$generator->clear_settings_override();

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'skipped' => false,
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);
		}

		$data = isset( $result['data'] ) ? $result['data'] : '';
		$prompt = isset( $result['prompt'] ) ? $result['prompt'] : '';
		$attachment_id = self::persist_generated_image( $post_id, $data, $topic );

		if ( is_wp_error( $attachment_id ) ) {
			return array(
				'success' => false,
				'skipped' => false,
				'error'   => $attachment_id->get_error_message(),
				'code'    => $attachment_id->get_error_code(),
			);
		}

		set_post_thumbnail( $post_id, $attachment_id );
		self::apply_aspect_ratio_crop( $attachment_id, $settings );
		update_post_meta( $post_id, '_blog_poster_featured_image_prompt', sanitize_text_field( $prompt ) );
		update_post_meta( $post_id, '_blog_poster_featured_image_provider', sanitize_text_field( $provider ) );
		update_post_meta( $post_id, '_blog_poster_featured_image_aspect_ratio', self::resolve_aspect_ratio( $settings ) );
		update_post_meta( $post_id, '_blog_poster_featured_image_style', self::resolve_image_style( $settings ) );

		$url = wp_get_attachment_url( $attachment_id );
		update_post_meta( $post_id, '_blog_poster_og_image', esc_url_raw( $url ) );

		return array(
			'success'       => true,
			'skipped'       => false,
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'provider'      => $provider,
		);
	}

	/**
	 * Resolve image generation provider.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	private static function resolve_image_provider( $settings ) {
		$image_provider = isset( $settings['image_provider'] ) ? sanitize_key( $settings['image_provider'] ) : '';
		if ( in_array( $image_provider, array( 'openai', 'gemini' ), true ) ) {
			return $image_provider;
		}
		$provider = isset( $settings['ai_provider'] ) ? sanitize_key( $settings['ai_provider'] ) : 'openai';
		if ( in_array( $provider, array( 'openai', 'gemini' ), true ) ) {
			return $provider;
		}
		return 'openai';
	}

	/**
	 * Persist generated image payload to media library.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $data    URL or base64 payload.
	 * @param string $topic   Topic text.
	 * @return int|WP_Error
	 */
	private static function persist_generated_image( $post_id, $data, $topic ) {
		$data = is_string( $data ) ? trim( $data ) : '';
		if ( '' === $data ) {
			return new WP_Error( 'empty_image_data', __( '画像データが空です。', 'blog-poster' ) );
		}

		if ( 0 === strpos( $data, 'http://' ) || 0 === strpos( $data, 'https://' ) ) {
			return self::attach_image_from_url( $post_id, $data, $topic );
		}

		if ( 0 === strpos( $data, 'data:image/' ) ) {
			return self::attach_image_from_data_url( $post_id, $data, $topic );
		}

		return self::attach_image_from_base64( $post_id, $data, $topic, 'png' );
	}

	/**
	 * Attach image from remote URL.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Remote image URL.
	 * @param string $topic     Topic text.
	 * @return int|WP_Error
	 */
	private static function attach_image_from_url( $post_id, $image_url, $topic ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $image_url, 120 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$filename = sanitize_title( $topic );
		if ( '' === $filename ) {
			$filename = 'blog-poster-featured-image';
		}
		$filename .= '.png';

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $topic );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Attach image from data URL payload.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $data_url Data URL.
	 * @param string $topic    Topic text.
	 * @return int|WP_Error
	 */
	private static function attach_image_from_data_url( $post_id, $data_url, $topic ) {
		if ( ! preg_match( '#^data:image/([a-zA-Z0-9]+);base64,(.+)$#', $data_url, $matches ) ) {
			return new WP_Error( 'invalid_data_url', __( '画像データ形式が不正です。', 'blog-poster' ) );
		}

		$ext = strtolower( $matches[1] );
		$payload = $matches[2];
		return self::attach_image_from_base64( $post_id, $payload, $topic, $ext );
	}

	/**
	 * Attach image from raw base64 payload.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $base64  Base64 image payload.
	 * @param string $topic   Topic text.
	 * @param string $ext     Extension.
	 * @return int|WP_Error
	 */
	private static function attach_image_from_base64( $post_id, $base64, $topic, $ext = 'png' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$binary = base64_decode( $base64, true );
		if ( false === $binary ) {
			return new WP_Error( 'invalid_base64_image', __( '画像データのデコードに失敗しました。', 'blog-poster' ) );
		}

		$filename = sanitize_title( $topic );
		if ( '' === $filename ) {
			$filename = 'blog-poster-featured-image';
		}
		$clean_ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );
		if ( '' === $clean_ext ) {
			$clean_ext = 'png';
		}
		$filename .= '.' . $clean_ext;

		$tmp_file = wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new WP_Error( 'temp_file_error', __( '一時ファイル作成に失敗しました。', 'blog-poster' ) );
		}

		$written = file_put_contents( $tmp_file, $binary );
		if ( false === $written ) {
			@unlink( $tmp_file );
			return new WP_Error( 'temp_write_error', __( '一時ファイル書き込みに失敗しました。', 'blog-poster' ) );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $topic );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Apply configured aspect ratio crop to an attachment image.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $settings      Settings array.
	 * @return void
	 */
	private static function apply_aspect_ratio_crop( $attachment_id, $settings ) {
		$attachment_id = intval( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return;
		}

		$ratio = self::resolve_aspect_ratio( $settings );
		$ratio_map = array(
			'1:1'  => array( 1, 1 ),
			'3:2'  => array( 3, 2 ),
			'4:3'  => array( 4, 3 ),
			'16:9' => array( 16, 9 ),
		);
		if ( ! isset( $ratio_map[ $ratio ] ) ) {
			return;
		}

		$target_w = $ratio_map[ $ratio ][0];
		$target_h = $ratio_map[ $ratio ][1];
		if ( $target_w <= 0 || $target_h <= 0 ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			return;
		}

		$width = (int) $size['width'];
		$height = (int) $size['height'];
		if ( $width <= 1 || $height <= 1 ) {
			return;
		}

		$current_ratio = $width / $height;
		$desired_ratio = $target_w / $target_h;
		$crop_w = $width;
		$crop_h = $height;

		if ( $current_ratio > $desired_ratio ) {
			$crop_w = (int) round( $height * $desired_ratio );
		} elseif ( $current_ratio < $desired_ratio ) {
			$crop_h = (int) round( $width / $desired_ratio );
		}

		if ( $crop_w <= 0 || $crop_h <= 0 ) {
			return;
		}

		$src_x = (int) floor( ( $width - $crop_w ) / 2 );
		$src_y = (int) floor( ( $height - $crop_h ) / 2 );
		$cropped = $editor->crop( $src_x, $src_y, $crop_w, $crop_h );
		if ( is_wp_error( $cropped ) ) {
			return;
		}

		$saved = $editor->save( $file_path );
		if ( is_wp_error( $saved ) ) {
			return;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}

	/**
	 * Resolve configured image aspect ratio.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	private static function resolve_aspect_ratio( $settings ) {
		$ratio = isset( $settings['image_aspect_ratio'] ) ? sanitize_text_field( (string) $settings['image_aspect_ratio'] ) : '';
		if ( in_array( $ratio, array( '1:1', '3:2', '4:3', '16:9' ), true ) ) {
			return $ratio;
		}

		$legacy_size = isset( $settings['image_size'] ) ? sanitize_text_field( (string) $settings['image_size'] ) : '1024x1024';
		if ( '1536x1024' === $legacy_size ) {
			return '3:2';
		}
		if ( '1792x1024' === $legacy_size ) {
			return '16:9';
		}
		return '1:1';
	}

	/**
	 * Resolve configured image style.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	private static function resolve_image_style( $settings ) {
		$style = isset( $settings['image_style'] ) ? sanitize_key( (string) $settings['image_style'] ) : 'photo';
		return in_array( $style, array( 'photo', 'illustration' ), true ) ? $style : 'photo';
	}
}
