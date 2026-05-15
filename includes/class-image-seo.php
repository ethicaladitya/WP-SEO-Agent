<?php
/**
 * Image SEO — alt text scoring, generation, and bulk operations.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Image_SEO {

	/** @var SEO_Agent_AI_Gemini_Client */
	private $gemini;

	/** @var SEO_Agent_AI_OpenAI_Client */
	private $openai;

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param SEO_Agent_AI_Gemini_Client $gemini  Gemini AI client.
	 * @param SEO_Agent_AI_OpenAI_Client $openai  OpenAI client.
	 * @param SEO_Agent_AI_Logger        $logger  Logger.
	 */
	public function __construct(
		SEO_Agent_AI_Gemini_Client $gemini,
		SEO_Agent_AI_OpenAI_Client $openai,
		SEO_Agent_AI_Logger $logger
	) {
		$this->gemini = $gemini;
		$this->openai = $openai;
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------

	public function init_hooks() {
		add_action( 'wp_ajax_seo_agent_ai_generate_alt', array( $this, 'ajax_generate_alt' ) );
		add_action( 'wp_ajax_seo_agent_ai_bulk_generate_alt', array( $this, 'ajax_bulk_generate_alt' ) );
	}

	// -------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------

	/**
	 * Return attachments that have no (or empty) alt text.
	 *
	 * @param int $limit Max results.
	 * @return array[]
	 */
	public function get_images_missing_alt( $limit = 50 ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => (int) $limit,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
				),
			)
		);

		$results = array();
		if ( ! $query->have_posts() ) {
			return $results;
		}

		foreach ( $query->posts as $post ) {
			$parent_title = '';
			if ( $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				if ( $parent ) {
					$parent_title = $parent->post_title;
				}
			}

			$filepath    = get_attached_file( $post->ID );
			$filesize_kb = 0;
			if ( $filepath && file_exists( $filepath ) ) {
				$filesize_kb = round( filesize( $filepath ) / 1024, 1 );
			}

			$results[] = array(
				'id'                => (int) $post->ID,
				'url'               => wp_get_attachment_url( $post->ID ),
				'filename'          => basename( $filepath ?: '' ),
				'parent_post_id'    => (int) $post->post_parent,
				'parent_post_title' => $parent_title,
				'filesize_kb'       => $filesize_kb,
			);
		}

		return $results;
	}

	/**
	 * Overall image stats.
	 *
	 * @return array{total: int, missing_alt: int, ai_generated: int}
	 */
	public function get_image_stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_status = 'inherit'
			   AND post_mime_type LIKE 'image/%'"
		);

		$missing_alt = count( $this->get_images_missing_alt( 9999 ) );

		$ai_generated = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_seo_agent_ai_alt_generated' AND meta_value = '1'"
		);

		return compact( 'total', 'missing_alt', 'ai_generated' );
	}

	// -------------------------------------------------------------------
	// Scoring
	// -------------------------------------------------------------------

	/**
	 * Score a single image attachment (0-100).
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $focus_keyword Optional focus keyword.
	 * @return array{score: int, breakdown: array}
	 */
	public function score_image( $attachment_id, $focus_keyword = '' ) {
		$attachment_id = (int) $attachment_id;
		$post          = get_post( $attachment_id );

		if ( ! $post || $post->post_type !== 'attachment' ) {
			return array(
				'score'     => 0,
				'breakdown' => array(),
			);
		}

		$alt      = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$title    = $post->post_title;
		$filepath = get_attached_file( $attachment_id );
		$filename = $filepath ? pathinfo( $filepath, PATHINFO_FILENAME ) : '';

		$breakdown = array();
		$score     = 0;

		// Alt text presence (30 pts).
		if ( $alt !== '' ) {
			$breakdown['alt_present'] = 30;
			$score                   += 30;
		} else {
			$breakdown['alt_present'] = 0;
		}

		// Alt length 50-125 chars (10 pts).
		$alt_len = mb_strlen( $alt );
		if ( $alt !== '' && $alt_len >= 50 && $alt_len <= 125 ) {
			$breakdown['alt_length'] = 10;
			$score                  += 10;
		} else {
			$breakdown['alt_length'] = 0;
		}

		// Focus keyword in alt (20 pts).
		if ( $focus_keyword !== '' && $alt !== '' && stripos( $alt, $focus_keyword ) !== false ) {
			$breakdown['keyword_in_alt'] = 20;
			$score                      += 20;
		} elseif ( $focus_keyword === '' ) {
			// No focus keyword supplied — give partial credit if alt is not empty.
			$breakdown['keyword_in_alt'] = $alt !== '' ? 10 : 0;
			$score                      += $breakdown['keyword_in_alt'];
		} else {
			$breakdown['keyword_in_alt'] = 0;
		}

		// Title quality — not just the filename (10 pts).
		$filename_clean = preg_replace( '/[-_]/', ' ', $filename );
		if ( $title !== '' && strtolower( trim( $title ) ) !== strtolower( trim( $filename_clean ) ) ) {
			$breakdown['title_quality'] = 10;
			$score                     += 10;
		} else {
			$breakdown['title_quality'] = 0;
		}

		// Filename has words, not just a hash (10 pts).
		// A hash-like filename is all hex/numbers with minimal word chars.
		$has_words = preg_match( '/[a-z]{3,}/i', $filename );
		if ( $has_words ) {
			$breakdown['filename_words'] = 10;
			$score                      += 10;
		} else {
			$breakdown['filename_words'] = 0;
		}

		// Filesize < 200 KB (20 pts).
		$filesize_kb = 0;
		if ( $filepath && file_exists( $filepath ) ) {
			$filesize_kb = filesize( $filepath ) / 1024;
		}
		if ( $filesize_kb > 0 && $filesize_kb < 200 ) {
			$breakdown['filesize'] = 20;
			$score                += 20;
		} else {
			$breakdown['filesize'] = 0;
		}

		return array(
			'score'     => min( 100, max( 0, $score ) ),
			'breakdown' => $breakdown,
		);
	}

	// -------------------------------------------------------------------
	// AI generation
	// -------------------------------------------------------------------

	/**
	 * Generate alt text for a single attachment using AI and save it.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @param int $post_id       Optional parent/context post ID.
	 * @return string|WP_Error Generated alt text or error.
	 */
	public function generate_alt_text( $attachment_id, $post_id = 0 ) {
		$attachment_id = (int) $attachment_id;
		$post          = get_post( $attachment_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'seo-agent-ai' ) );
		}

		$filepath = get_attached_file( $attachment_id );
		$filename = $filepath ? basename( $filepath ) : '';
		$title    = $post->post_title;
		$caption  = $post->post_excerpt;

		$post_title = '';
		$keyword    = '';
		if ( $post_id ) {
			$parent = get_post( $post_id );
			if ( $parent ) {
				$post_title = $parent->post_title;
				$keyword    = (string) get_post_meta( $post_id, '_seo_agent_ai_focus_keyword', true );
			}
		} elseif ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				$post_title = $parent->post_title;
				$keyword    = (string) get_post_meta( $post->post_parent, '_seo_agent_ai_focus_keyword', true );
			}
		}

		$prompt = sprintf(
			"Generate SEO-optimized alt text for an image. Filename: %s. Title: %s. Caption: %s. Parent post title: %s. Focus keyword: %s. Rules: max 125 characters, descriptive, include focus keyword naturally if relevant, do not start with 'image of' or 'photo of'. Return only the alt text, nothing else.",
			$filename,
			$title,
			$caption,
			$post_title,
			$keyword
		);

		$provider = get_option( 'seo_agent_ai_ai_provider', 'gemini' );

		// Try primary provider, automatically fall back to the other if it fails.
		if ( $provider === 'openai' ) {
			$result = $this->openai->complete( $prompt );
			if ( is_wp_error( $result ) && $this->gemini->is_configured() ) {
				$this->logger->warning( 'OpenAI alt text failed for #' . $attachment_id . ', falling back to Gemini.' );
				$result = $this->gemini->complete( $prompt );
			}
		} else {
			$result = $this->gemini->complete( $prompt );
			if ( is_wp_error( $result ) && $this->openai->is_configured() ) {
				$this->logger->warning( 'Gemini alt text failed for #' . $attachment_id . ', falling back to OpenAI.' );
				$result = $this->openai->complete( $prompt );
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Image alt generation failed for attachment ' . $attachment_id . ': ' . $result->get_error_message() );
			return $result;
		}

		$alt_text = trim( $result );
		// Truncate to 125 chars safely.
		if ( mb_strlen( $alt_text ) > 125 ) {
			$alt_text = mb_substr( $alt_text, 0, 125 );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		update_post_meta( $attachment_id, '_seo_agent_ai_alt_generated', '1' );

		$this->logger->info( 'Generated alt text for attachment ' . $attachment_id );

		return $alt_text;
	}

	/**
	 * Bulk-generate alt text for images missing it.
	 *
	 * @param int $limit Max images to process.
	 * @return array{processed: int, success: int, failed: int}
	 */
	public function bulk_generate_alt_text( $limit = 20 ) {
		$images    = $this->get_images_missing_alt( (int) $limit );
		$processed = 0;
		$success   = 0;
		$failed    = 0;

		foreach ( $images as $image ) {
			$result = $this->generate_alt_text( $image['id'], $image['parent_post_id'] );
			++$processed;

			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$success;
			}

			if ( $processed < count( $images ) ) {
				sleep( 1 );
			}
		}

		$this->logger->info( sprintf( 'Bulk alt generation: processed=%d success=%d failed=%d', $processed, $success, $failed ) );

		return compact( 'processed', 'success', 'failed' );
	}

	// -------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------

	public function ajax_generate_alt() {
		check_ajax_referer( 'seo_agent_ai_image_seo', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'seo-agent-ai' ), 403 );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		$post_id       = (int) ( $_POST['post_id'] ?? 0 );

		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'seo-agent-ai' ) );
		}

		$result = $this->generate_alt_text( $attachment_id, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'alt_text' => $result ) );
	}

	public function ajax_bulk_generate_alt() {
		check_ajax_referer( 'seo_agent_ai_image_seo', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'seo-agent-ai' ), 403 );
		}

		$limit  = (int) ( $_POST['limit'] ?? 20 );
		$limit  = max( 1, min( 100, $limit ) );
		$result = $this->bulk_generate_alt_text( $limit );

		wp_send_json_success( $result );
	}
}
