<?php
/**
 * OpenAI-compatible AI client.
 *
 * Implements the same interface as SEO_Agent_AI_Gemini_Client so either
 * provider can be swapped in the recommendation engine transparently.
 *
 * Supports any endpoint that speaks the OpenAI chat-completions API format:
 *   - Standard OpenAI:            https://api.openai.com/v1  (default)
 *   - Azure legacy (per-deploy):  https://{resource}.openai.azure.com/openai/deployments/{deploy}
 *   - Azure OpenAI-compat (/v1):  https://{resource}.openai.azure.com/openai/v1
 *   - Azure AI Foundry:           https://{resource}.services.ai.azure.com/api/projects/{proj}/inference
 *   - Local / custom:             any OpenAI-compatible base URL
 *
 * Azure legacy endpoints: use `api-key` header + append ?api-version=2024-02-01.
 * Azure /openai/v1 endpoints: use `Authorization: Bearer` (OpenAI-compatible) — no api-version.
 * Azure AI Foundry endpoints: use `api-key` header + append ?api-version=2025-01-01.
 * Detection is automatic; the admin UI presents a neutral "Base URL" field.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_OpenAI_Client {

	const OPTION_API_KEY      = 'seo_agent_ai_openai_api_key';
	const OPTION_BASE_URL     = 'seo_agent_ai_openai_base_url';
	const OPTION_MODEL        = 'seo_agent_ai_openai_model';
	const OPTION_API_VERSION  = 'seo_agent_ai_openai_api_version';

	// Azure legacy GA api-version (2024-02-01 was retired 2025-03-31).
	const AZURE_LEGACY_API_VERSION  = '2024-10-21';
	const AZURE_FOUNDRY_API_VERSION = '2025-01-01';

	const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
	const DEFAULT_MODEL    = 'gpt-4o-mini';

	const REQUEST_TIMEOUT = 25; // seconds
	const MAX_TOKENS      = 300;
	const TEMPERATURE     = 0.4;

	/**
	 * Lazily-resolved API key. Null means not yet resolved.
	 * Kept null in the constructor so crypto (wp_salt) is never called
	 * during early plugin load — only resolved on the first actual API call.
	 * @var string|null
	 */
	private $api_key = null;
	/** @var string */
	private $base_url;
	/** @var string */
	private $model;
	/**
	 * True for any *.openai.azure.com URL (legacy Azure OpenAI).
	 * @var bool
	 */
	private $is_azure;

	/**
	 * True when using the new Azure /openai/v1 OpenAI-compatible endpoint.
	 * This format uses Bearer auth and does not require an api-version param.
	 * @var bool
	 */
	private $is_azure_v1;

	/**
	 * True for Azure AI Foundry endpoints (*.services.ai.azure.com).
	 * Uses api-key header and api-version=2025-01-01.
	 * @var bool
	 */
	private $is_azure_foundry;

	public function __construct() {
		// Do NOT resolve the API key here — Crypto::decrypt() calls wp_salt()
		// which is not available during early plugin load (the plugin calls
		// SEO_Agent_AI_Plugin::instance() at global scope). Key is resolved
		// lazily on the first API call via get_api_key().
		$this->base_url         = rtrim( $this->resolve_base_url(), '/' );
		$this->model            = $this->resolve_model();
		$this->is_azure         = ( strpos( $this->base_url, '.openai.azure.com' ) !== false );
		$this->is_azure_foundry = ( strpos( $this->base_url, '.services.ai.azure.com' ) !== false );
		$this->is_azure_v1      = $this->is_azure && (
			str_ends_with( $this->base_url, '/openai/v1' ) ||
			strpos( $this->base_url, '/openai/v1/' ) !== false
		);
	}

	// -------------------------------------------------------------------
	// Public interface (mirrors SEO_Agent_AI_Gemini_Client)
	// -------------------------------------------------------------------

	/**
	 * Lazily resolve and return the API key (decrypts on first call).
	 */
	private function get_api_key(): string {
		if ( $this->api_key === null ) {
			$this->api_key = $this->resolve_api_key();
		}
		return $this->api_key;
	}

	/**
	 * Whether an API key is configured.
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
			return new WP_Error( 'not_configured', __( 'OpenAI API key not configured.', 'seo-agent-ai' ) );
		}
		$result = $this->chat( (string) $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result === null ) {
			return new WP_Error( 'api_error', __( 'OpenAI API returned no result.', 'seo-agent-ai' ) );
		}
		return $result;
	}

	/**
	 * Send a prompt WITH an image URL for vision-based generation (e.g., alt text).
	 *
	 * Uses the configured model if it supports vision, otherwise upgrades to
	 * gpt-4o-mini which is the cheapest OpenAI model with vision capability.
	 * Falls back to text-only complete() if vision fails.
	 *
	 * Only used for standard OpenAI and Azure /openai/v1 endpoints — Azure legacy
	 * deployments are not guaranteed to support vision inputs.
	 *
	 * @param string $prompt     Text prompt to accompany the image.
	 * @param string $image_url  Publicly accessible URL of the image attachment.
	 * @return string|WP_Error
	 */
	public function complete_with_image( $prompt, $image_url ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'OpenAI API key not configured.', 'seo-agent-ai' ) );
		}

		$image_url = (string) $image_url;

		// Vision is only reliable on standard OpenAI and Azure /openai/v1 endpoints.
		// For Azure legacy deployments (deployment-specific URLs) skip vision.
		$can_use_vision = ! ( $this->is_azure && ! $this->is_azure_v1 ) && ! $this->is_azure_foundry;

		if ( $image_url !== '' && $can_use_vision ) {
			$result = $this->chat_with_image( (string) $prompt, $image_url );
			if ( ! is_wp_error( $result ) && $result !== null ) {
				return $result;
			}
		}

		// Fall back to text-only.
		return $this->complete( $prompt );
	}

	/**
	 * Generate an SEO meta title (≤ 60 characters).
	 *
	 * @param WP_Post $post
	 * @param string  $top_query Top GSC query for the page.
	 * @return string|null Generated title, or null on failure.
	 */
	public function generate_meta_title( WP_Post $post, $top_query = '' ) {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$excerpt = $this->excerpt( $post, 200 );
		$query   = $top_query ? " The main search query is: \"{$top_query}\"." : '';

		$prompt = "Write a single SEO meta title for the blog post below.{$query} "
			. 'Maximum 60 characters. Plain text only — no quotes, no markdown. '
			. "Make it compelling, specific, and aligned with search intent.\n\n"
			. "Post title: {$post->post_title}\n"
			. "Content excerpt: {$excerpt}";

		$result = $this->chat( $prompt );
		return $this->clean_short( $result, 60 );
	}

	/**
	 * Generate an SEO meta description (≤ 155 characters).
	 *
	 * @param WP_Post $post
	 * @param string  $top_query
	 * @return string|null
	 */
	public function generate_meta_description( WP_Post $post, $top_query = '' ) {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$excerpt = $this->excerpt( $post, 400 );
		$query   = $top_query ? " The main search query is: \"{$top_query}\"." : '';

		$prompt = "Write a single SEO meta description for the blog post below.{$query} "
			. 'Maximum 155 characters. Plain text only — no quotes, no markdown. '
			. "Include the main keyword naturally and a clear value proposition.\n\n"
			. "Post title: {$post->post_title}\n"
			. "Content excerpt: {$excerpt}";

		$result = $this->chat( $prompt );
		return $this->clean_short( $result, 155 );
	}

	/**
	 * Suggest a focus keyword (1-4 words).
	 *
	 * @param WP_Post $post
	 * @param array   $queries Top GSC queries for the page.
	 * @return string|null
	 */
	public function suggest_focus_keyword( WP_Post $post, array $queries = array() ) {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$excerpt      = $this->excerpt( $post, 400 );
		$queries_text = implode( ', ', array_slice( $queries, 0, 5 ) );
		$query_line   = $queries_text ? "\nTop search queries driving traffic: {$queries_text}" : '';

		$prompt = "Suggest the single best focus keyword phrase for the blog post below.{$query_line} "
			. "Between 1 and 4 words. Plain text only — no quotes, no markdown, no explanation.\n\n"
			. "Post title: {$post->post_title}\n"
			. "Content excerpt: {$excerpt}";

		$result = $this->chat( $prompt );
		return $this->clean_short( $result, 60 );
	}

	/**
	 * Generate FAQ schema entries from post content.
	 *
	 * @param WP_Post $post
	 * @param array   $queries Top GSC queries (used as FAQ seed questions).
	 * @return array  Array of ['question' => '', 'answer' => ''] pairs, or empty on failure.
	 */
	public function generate_faq_items( WP_Post $post, array $queries = array() ) {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$excerpt      = $this->excerpt( $post, 600 );
		$queries_text = implode( "\n- ", array_slice( $queries, 0, 5 ) );
		$seed         = $queries_text ? "\n\nCommon search queries about this topic:\n- {$queries_text}" : '';

		$prompt = "Based on the blog post excerpt below, write 2-3 FAQ items that would make good FAQ schema.{$seed}\n\n"
			. "Format each item as:\nQ: [question]\nA: [answer in 1-2 sentences]\n\n"
			. "Write plain text only. No markdown. No numbering.\n\n"
			. "Post title: {$post->post_title}\n"
			. "Content excerpt: {$excerpt}";

		$result = $this->chat( $prompt, 500 );
		return $this->parse_faq_output( is_string( $result ) ? $result : '' );
	}

	/**
	 * Suggest an improved heading (H1) for a post given its current heading
	 * and ranking query context.
	 *
	 * @param WP_Post $post
	 * @param string  $current_h1
	 * @param string  $top_query
	 * @return string|null
	 */
	public function suggest_heading( WP_Post $post, $current_h1, $top_query = '' ) {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$query = $top_query ? " The main search query is: \"{$top_query}\"." : '';

		$prompt = "Suggest an improved H1 heading for the blog post below.{$query} "
			. "Keep it under 70 characters. Plain text only — no quotes, no markdown.\n\n"
			. "Current heading: {$current_h1}\n"
			. "Post title: {$post->post_title}";

		$result = $this->chat( $prompt );
		return $this->clean_short( $result, 70 );
	}

	// -------------------------------------------------------------------
	// HTTP
	// -------------------------------------------------------------------

	/**
	 * Send a chat completion request (text-only).
	 *
	 * @param string   $user_prompt
	 * @param int|null $max_tokens  Override default max tokens.
	 * @return string|WP_Error|null Response text, WP_Error on API/HTTP failure, or null when response is empty.
	 */
	private function chat( $user_prompt, $max_tokens = null ) {
		$endpoint = $this->build_endpoint();
		$headers  = $this->build_headers();

		$body = array(
			'model'       => $this->model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are an expert SEO copywriter. Follow instructions precisely.',
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			'max_tokens'  => $max_tokens ?? self::MAX_TOKENS,
			'temperature' => self::TEMPERATURE,
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', $response->get_error_message() );
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code !== 200 ) {
			$api_msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : "HTTP {$code}";
			return new WP_Error( 'api_error', sprintf( 'OpenAI API error %d: %s', $code, $api_msg ) );
		}

		return $data['choices'][0]['message']['content'] ?? null;
	}

	/**
	 * Send a chat completion request with an image URL (vision).
	 *
	 * Uses 'detail: low' for images — sufficient for alt text and avoids extra
	 * token cost from high-resolution analysis.
	 *
	 * If the configured model does not support vision, upgrades to gpt-4o-mini.
	 *
	 * @param string $user_prompt
	 * @param string $image_url  Publicly accessible image URL.
	 * @return string|null Response text or null on failure.
	 */
	private function chat_with_image( $user_prompt, $image_url ) {
		$endpoint = $this->build_endpoint();
		$headers  = $this->build_headers();

		// Determine a vision-capable model.
		$vision_capable = array( 'gpt-4o', 'gpt-4-turbo', 'gpt-4-vision' );
		$model          = $this->model;
		$is_vision      = false;
		foreach ( $vision_capable as $vm ) {
			if ( strpos( $model, $vm ) !== false ) {
				$is_vision = true;
				break;
			}
		}
		// gpt-4o-mini also supports vision.
		if ( ! $is_vision && strpos( $model, 'gpt-4o-mini' ) !== false ) {
			$is_vision = true;
		}
		if ( ! $is_vision ) {
			$model = 'gpt-4o-mini';
		}

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are an expert SEO copywriter. Follow instructions precisely.',
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $user_prompt,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url'    => $image_url,
								'detail' => 'low',
							),
						),
					),
				),
			),
			'max_tokens'  => self::MAX_TOKENS,
			'temperature' => self::TEMPERATURE,
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['choices'][0]['message']['content'] ?? null;
	}

	/**
	 * Build the completions endpoint URL.
	 *
	 * - Azure AI Foundry  → {base_url}/chat/completions?api-version=2025-01-01
	 * - Azure /openai/v1  → {base_url}/chat/completions  (no api-version)
	 * - Azure legacy      → {base_url}/chat/completions?api-version=<configured|2024-10-21>
	 * - Standard/custom   → {base_url}/chat/completions
	 *
	 * The Azure legacy api-version can be overridden via the
	 * seo_agent_ai_openai_api_version option or the
	 * SEO_AGENT_AI_OPENAI_API_VERSION constant (useful when Azure retires a version).
	 */
	private function build_endpoint() {
		if ( $this->is_azure_foundry ) {
			return $this->base_url . '/chat/completions?api-version=' . self::AZURE_FOUNDRY_API_VERSION;
		}
		if ( $this->is_azure && ! $this->is_azure_v1 ) {
			$api_version = $this->resolve_azure_api_version();
			return $this->base_url . '/chat/completions?api-version=' . rawurlencode( $api_version );
		}
		// OpenAI format (standard, custom, and Azure /openai/v1).
		return $this->base_url . '/chat/completions';
	}

	/**
	 * Resolve the Azure legacy api-version, with constant → option → default fallback.
	 *
	 * @return string
	 */
	private function resolve_azure_api_version() {
		if ( defined( 'SEO_AGENT_AI_OPENAI_API_VERSION' ) ) {
			$v = trim( (string) constant( 'SEO_AGENT_AI_OPENAI_API_VERSION' ) );
			if ( $v !== '' ) {
				return $v;
			}
		}
		$stored = trim( (string) get_option( self::OPTION_API_VERSION, '' ) );
		return $stored !== '' ? $stored : self::AZURE_LEGACY_API_VERSION;
	}

	/**
	 * Build request headers.
	 *
	 * - Azure legacy (/openai/deployments/…) → api-key header
	 * - Azure AI Foundry (services.ai.azure.com) → api-key header
	 * - Everything else (standard OpenAI, Azure /openai/v1) → Authorization: Bearer
	 */
	private function build_headers() {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( $this->is_azure_foundry || ( $this->is_azure && ! $this->is_azure_v1 ) ) {
			// Azure Foundry and legacy Azure deployment both use the api-key header.
			$headers['api-key'] = $this->get_api_key();
		} else {
			// Standard OpenAI + Azure /openai/v1 both use Bearer auth.
			$headers['Authorization'] = 'Bearer ' . $this->get_api_key();
		}

		return $headers;
	}

	// -------------------------------------------------------------------
	// Option resolution (supports wp-config.php constant overrides)
	// -------------------------------------------------------------------

	private function resolve_api_key() {
		if ( defined( 'SEO_AGENT_AI_OPENAI_API_KEY' ) ) {
			return (string) SEO_AGENT_AI_OPENAI_API_KEY;
		}
		$stored = (string) get_option( self::OPTION_API_KEY, '' );
		if ( $stored === '' ) {
			return '';
		}
		return (string) SEO_Agent_AI_Crypto::decrypt( $stored );
	}

	private function resolve_base_url() {
		if ( defined( 'SEO_AGENT_AI_OPENAI_BASE_URL' ) ) {
			return (string) SEO_AGENT_AI_OPENAI_BASE_URL;
		}
		$stored = (string) get_option( self::OPTION_BASE_URL, '' );
		return $stored !== '' ? $stored : self::DEFAULT_BASE_URL;
	}

	private function resolve_model() {
		if ( defined( 'SEO_AGENT_AI_OPENAI_MODEL' ) ) {
			return (string) SEO_AGENT_AI_OPENAI_MODEL;
		}
		$stored = (string) get_option( self::OPTION_MODEL, '' );
		return $stored !== '' ? $stored : self::DEFAULT_MODEL;
	}

	// -------------------------------------------------------------------
	// Text helpers
	// -------------------------------------------------------------------

	/**
	 * Get a plain-text excerpt from post content.
	 */
	private function excerpt( WP_Post $post, $max_chars ) {
		$text = wp_strip_all_tags( $post->post_content );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		return mb_substr( $text, 0, $max_chars );
	}

	/**
	 * Trim, strip quotes/markdown, and enforce a maximum character length.
	 */
	private function clean_short( $text, $max ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}
		$text = trim( $text );
		$text = trim( $text, '"\'`*#' );
		// Take only the first line in case the model added extras.
		$text = strtok( $text, "\n" );
		$text = trim( $text );
		if ( $text === '' ) {
			return null;
		}
		return mb_substr( $text, 0, $max );
	}

	/**
	 * Parse "Q: ...\nA: ..." FAQ format from model output.
	 *
	 * @param string $output
	 * @return array
	 */
	private function parse_faq_output( $output ) {
		$items = array();
		$lines = explode( "\n", $output );
		$q     = '';
		$a     = '';

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^Q:\s*(.+)/i', $line, $m ) ) {
				if ( $q !== '' && $a !== '' ) {
					$items[] = array(
						'question' => $q,
						'answer'   => $a,
					);
				}
				$q = trim( $m[1] );
				$a = '';
			} elseif ( preg_match( '/^A:\s*(.+)/i', $line, $m ) ) {
				$a = trim( $m[1] );
			} elseif ( $a !== '' && $line !== '' ) {
				$a .= ' ' . $line; // Continuation line.
			}
		}

		if ( $q !== '' && $a !== '' ) {
			$items[] = array(
				'question' => $q,
				'answer'   => $a,
			);
		}

		return array_slice( $items, 0, 5 ); // Safety cap.
	}
}
