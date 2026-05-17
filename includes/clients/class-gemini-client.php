<?php
/**
 * Gemini API Client.
 *
 * Uses Google Gemini (generativeLanguage API) to enhance SEO recommendations:
 *   - Generate keyword-rich meta titles (max 60 chars)
 *   - Generate compelling meta descriptions (max 155 chars)
 *   - Suggest a primary focus keyword from post content + GSC top queries
 *
 * The client is optional — if no API key is saved, all methods return null
 * and the caller falls back to rule-based generation.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Gemini_Client {

	const OPTION_API_KEY      = 'seo_agent_ai_gemini_api_key';
	const API_ENDPOINT        = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
	const REQUEST_TIMEOUT     = 20;
	const MAX_OUTPUT_TOKENS   = 256;
	const IMAGE_SIZE_LIMIT    = 4194304; // 4 MB — Gemini inline_data hard limit.

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Whether a Gemini API key has been configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->get_api_key() !== '';
	}

	/**
	 * Send a raw prompt and return the text response, or WP_Error on failure.
	 *
	 * @param string $prompt
	 * @return string|WP_Error
	 */
	public function complete( $prompt ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Gemini API key not configured.', 'seo-agent-ai' ) );
		}
		$result = $this->generate( (string) $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result === null ) {
			return new WP_Error( 'api_error', __( 'Gemini API returned no result.', 'seo-agent-ai' ) );
		}
		return $result;
	}

	/**
	 * Send a prompt WITH an image for vision-based generation (e.g., alt text).
	 *
	 * Fetches the image, base64-encodes it as inline_data, and sends a multimodal
	 * request. Falls back to text-only complete() if the image cannot be fetched
	 * or exceeds the size limit.
	 *
	 * @param string $prompt     Text prompt to accompany the image.
	 * @param string $image_url  Publicly accessible URL of the image attachment.
	 * @return string|WP_Error
	 */
	public function complete_with_image( $prompt, $image_url ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Gemini API key not configured.', 'seo-agent-ai' ) );
		}

		$image_url = (string) $image_url;
		if ( $image_url !== '' ) {
			$result = $this->generate_vision( (string) $prompt, $image_url );
			if ( ! is_wp_error( $result ) && $result !== null ) {
				return $result;
			}
		}

		// Fall back to text-only if vision is unavailable.
		return $this->complete( $prompt );
	}

	/**
	 * Generate an SEO meta title for a post.
	 *
	 * @param WP_Post $post
	 * @param string  $top_query  Top GSC search query for context.
	 * @return string|null  Generated title (≤60 chars), or null on failure.
	 */
	public function generate_meta_title( WP_Post $post, $top_query = '' ) {
		$api_key = $this->get_api_key();
		if ( $api_key === '' ) {
			return null;
		}

		$excerpt = $this->safe_excerpt( $post, 200 );

		$prompt  = "You are an expert SEO copywriter. Write a single SEO meta title for the blog post below.\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Maximum 60 characters (strictly enforced)\n";
		$prompt .= "- Include the primary keyword naturally near the front\n";
		$prompt .= "- Make it compelling and specific — no clickbait\n";
		$prompt .= "- Return ONLY the title text, no quotes, no explanation\n\n";

		if ( $top_query !== '' ) {
			$prompt .= 'Primary keyword (from Search Console): ' . $top_query . "\n";
		}
		$prompt .= 'Post title: ' . $post->post_title . "\n";
		$prompt .= 'Content snippet: ' . $excerpt . "\n\n";
		$prompt .= 'Meta title:';

		$result = $this->generate( $prompt );

		if ( $result === null || is_wp_error( $result ) ) {
			return null;
		}

		$result = $this->clean_output( $result );
		$result = $this->hard_truncate( $result, 60 );

		return $result !== '' ? $result : null;
	}

	/**
	 * Generate an SEO meta description for a post.
	 *
	 * @param WP_Post $post
	 * @param string  $top_query  Top GSC search query for context.
	 * @return string|null  Generated description (≤155 chars), or null on failure.
	 */
	public function generate_meta_description( WP_Post $post, $top_query = '' ) {
		$api_key = $this->get_api_key();
		if ( $api_key === '' ) {
			return null;
		}

		$excerpt = $this->safe_excerpt( $post, 400 );

		$prompt  = "You are an expert SEO copywriter. Write a single SEO meta description for the blog post below.\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Maximum 155 characters (strictly enforced)\n";
		$prompt .= "- Include the primary keyword naturally\n";
		$prompt .= "- Use active voice, clearly state what the reader will get\n";
		$prompt .= "- Return ONLY the description text, no quotes, no explanation\n\n";

		if ( $top_query !== '' ) {
			$prompt .= 'Primary keyword (from Search Console): ' . $top_query . "\n";
		}
		$prompt .= 'Post title: ' . $post->post_title . "\n";
		$prompt .= 'Content snippet: ' . $excerpt . "\n\n";
		$prompt .= 'Meta description:';

		$result = $this->generate( $prompt );

		if ( $result === null || is_wp_error( $result ) ) {
			return null;
		}

		$result = $this->clean_output( $result );
		$result = $this->hard_truncate( $result, 155 );

		return $result !== '' ? $result : null;
	}

	/**
	 * Suggest a primary focus keyword for a post.
	 *
	 * @param WP_Post $post
	 * @param array   $gsc_queries  Top queries from GSC (array of ['query' => string]).
	 * @return string|null  A 1–4 word focus keyword phrase, or null on failure.
	 */
	public function suggest_focus_keyword( WP_Post $post, array $gsc_queries = array() ) {
		$api_key = $this->get_api_key();
		if ( $api_key === '' ) {
			return null;
		}

		$excerpt    = $this->safe_excerpt( $post, 400 );
		$query_list = '';
		foreach ( array_slice( $gsc_queries, 0, 5 ) as $q ) {
			$query_list .= '- ' . ( is_array( $q ) ? $q['query'] : (string) $q ) . "\n";
		}

		$prompt  = "You are an SEO specialist. Suggest the single best focus keyword phrase for the blog post below.\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- 1 to 4 words only\n";
		$prompt .= "- It must match what users would realistically search for\n";
		$prompt .= "- Return ONLY the keyword phrase, lowercase, no quotes, no explanation\n\n";

		$prompt .= 'Post title: ' . $post->post_title . "\n";
		if ( $query_list !== '' ) {
			$prompt .= "Google Search Console top queries:\n" . $query_list;
		}
		$prompt .= 'Content snippet: ' . $excerpt . "\n\n";
		$prompt .= 'Focus keyword:';

		$result = $this->generate( $prompt );

		if ( $result === null || is_wp_error( $result ) ) {
			return null;
		}

		$result = strtolower( $this->clean_output( $result ) );
		$result = $this->hard_truncate( $result, 60 ); // Safety bound.

		return $result !== '' ? $result : null;
	}

	// -----------------------------------------------------------------------
	// Internal: API call
	// -----------------------------------------------------------------------

	/**
	 * Send a prompt to Gemini and return the text response, WP_Error on API/auth
	 * failure, or null when the response is empty (e.g. safety-filtered).
	 *
	 * @param string $prompt
	 * @return string|WP_Error|null
	 */
	private function generate( $prompt ) {
		$api_key = $this->get_api_key();

		$body = wp_json_encode(
			array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
					'temperature'     => 0.4,
					'topP'            => 0.9,
				),
			)
		);

		if ( $body === false ) {
			return new WP_Error( 'encode_error', __( 'Failed to encode Gemini request body.', 'seo-agent-ai' ) );
		}

		$response = wp_remote_post(
			self::API_ENDPOINT . '?key=' . rawurlencode( $api_key ),
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', $response->get_error_message() );
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data         = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$api_msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'api_error', sprintf( 'Gemini API error %d: %s', $code, $api_msg ) );
		}

		// Safety filter: Gemini returns 200 but no content.
		$finish_reason = isset( $data['candidates'][0]['finishReason'] )
			? (string) $data['candidates'][0]['finishReason']
			: '';

		if ( in_array( $finish_reason, array( 'SAFETY', 'RECITATION', 'BLOCKLIST' ), true ) ) {
			return new WP_Error(
				'safety_filter',
				sprintf(
					/* translators: %s: Gemini finish reason code. */
					__( 'Gemini blocked response (finishReason: %s).', 'seo-agent-ai' ),
					$finish_reason
				)
			);
		}

		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] )
			? (string) $data['candidates'][0]['content']['parts'][0]['text']
			: '';

		return $text !== '' ? $text : null;
	}

	/**
	 * Send a prompt + image to Gemini Vision (inline_data) and return the text
	 * response, or null if the image cannot be fetched / is too large / fails.
	 *
	 * @param string $prompt
	 * @param string $image_url  Publicly accessible image URL.
	 * @return string|null
	 */
	private function generate_vision( $prompt, $image_url ) {
		// Fetch the image — hard cap at IMAGE_SIZE_LIMIT to avoid OOM.
		$img_response = wp_remote_get(
			$image_url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $img_response ) ) {
			return null;
		}

		$img_code = (int) wp_remote_retrieve_response_code( $img_response );
		if ( $img_code !== 200 ) {
			return null;
		}

		$img_body = wp_remote_retrieve_body( $img_response );
		if ( empty( $img_body ) || strlen( $img_body ) > self::IMAGE_SIZE_LIMIT ) {
			return null;
		}

		// Detect MIME type from Content-Type header.
		$ct        = wp_remote_retrieve_header( $img_response, 'content-type' );
		$mime_type = $ct ? strtok( (string) $ct, ';' ) : 'image/jpeg';
		$mime_type = trim( $mime_type );

		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		if ( ! in_array( $mime_type, $allowed_mimes, true ) ) {
			// Try to infer from URL extension.
			$ext_map = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
				'gif'  => 'image/gif',
			);
			$ext       = strtolower( pathinfo( wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$mime_type = $ext_map[ $ext ] ?? 'image/jpeg';
		}

		$api_key = $this->get_api_key();

		$vision_body = wp_json_encode(
			array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $prompt ),
							array(
								'inline_data' => array(
									'mime_type' => $mime_type,
									'data'      => base64_encode( $img_body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
								),
							),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
					'temperature'     => 0.4,
					'topP'            => 0.9,
				),
			)
		);

		if ( $vision_body === false ) {
			return null;
		}

		$response = wp_remote_post(
			self::API_ENDPOINT . '?key=' . rawurlencode( $api_key ),
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $vision_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$finish_reason = isset( $data['candidates'][0]['finishReason'] )
			? (string) $data['candidates'][0]['finishReason']
			: '';

		if ( in_array( $finish_reason, array( 'SAFETY', 'RECITATION', 'BLOCKLIST' ), true ) ) {
			return null;
		}

		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] )
			? (string) $data['candidates'][0]['content']['parts'][0]['text']
			: '';

		return $text !== '' ? $text : null;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function get_api_key() {
		if ( defined( 'SEO_AGENT_AI_GEMINI_API_KEY' ) ) {
			$v = constant( 'SEO_AGENT_AI_GEMINI_API_KEY' );
			if ( is_string( $v ) && trim( $v ) !== '' ) {
				return trim( $v );
			}
		}
		$stored = (string) get_option( self::OPTION_API_KEY, '' );
		return $stored !== '' ? trim( SEO_Agent_AI_Crypto::decrypt( $stored ) ) : '';
	}

	private function safe_excerpt( WP_Post $post, $max_chars ) {
		$text = trim( wp_strip_all_tags( $post->post_excerpt ) );
		if ( $text === '' ) {
			$text = trim( wp_strip_all_tags( $post->post_content ) );
		}
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $max_chars ? mb_substr( $text, 0, $max_chars ) : $text;
		}
		return strlen( $text ) > $max_chars ? substr( $text, 0, $max_chars ) : $text;
	}

	private function clean_output( $text ) {
		$text = trim( (string) $text );
		// Strip leading/trailing straight and typographic quotation marks.
		$text = preg_replace( '/^["\x27\x{2018}\x{2019}\x{201C}\x{201D}]+|["\x27\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $text );
		$text = trim( $text );
		// Remove markdown bold artifacts.
		$text = preg_replace( '/^\*\*(.+)\*\*$/s', '$1', $text );
		return trim( $text );
	}

	private function hard_truncate( $text, $max_len ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $max_len ) {
				return $text;
			}
			return rtrim( mb_substr( $text, 0, $max_len - 1 ) ) . '…';
		}
		if ( strlen( $text ) <= $max_len ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $max_len - 1 ) ) . '…';
	}
}
