<?php
/**
 * SEO Plugin Bridge.
 *
 * Detects active SEO plugins and provides a unified read/write API for post
 * meta (title, description, focus keyword) across:
 *   - Yoast SEO / Yoast SEO Premium
 *   - RankMath SEO
 *   - SmartCrawl (WPMU DEV)
 *   - The SEO Framework
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_SEO_Plugin_Bridge {

	/**
	 * Meta key definitions keyed by plugin slug.
	 * Each plugin entry may have: title, description, focus_keyword.
	 */
	const PLUGIN_META = array(
		'yoast' => array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		),
		'rankmath' => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		),
		'smartcrawl' => array(
			'title'         => '_wds_title',
			'description'   => '_wds_metadesc',
			'focus_keyword' => '_wds_focus_keywords',
		),
		'seoframework' => array(
			'title'         => '_genesis_title',
			'description'   => '_genesis_description',
			'focus_keyword' => '',
		),
	);

	/** @var string[]|null  Cached detected plugin slugs. */
	private $detected = null;

	// -----------------------------------------------------------------------
	// Detection
	// -----------------------------------------------------------------------

	/**
	 * Return slugs of all currently active SEO plugins.
	 *
	 * @return string[]
	 */
	public function get_detected_plugins() {
		if ( $this->detected !== null ) {
			return $this->detected;
		}

		$found = array();

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend', false ) ) {
			$found[] = 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			$found[] = 'rankmath';
		}

		if (
			defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'SmartCrawl_Settings', false )
			|| class_exists( 'Smartcrawl\\Smartcrawl', false )
		) {
			$found[] = 'smartcrawl';
		}

		if ( function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load', false ) ) {
			$found[] = 'seoframework';
		}

		$this->detected = $found;
		return $found;
	}

	/**
	 * Human-readable label for a plugin slug.
	 *
	 * @param string $slug
	 * @return string
	 */
	public function get_plugin_label( $slug ) {
		$labels = array(
			'yoast'        => 'Yoast SEO',
			'rankmath'     => 'RankMath SEO',
			'smartcrawl'   => 'SmartCrawl',
			'seoframework' => 'The SEO Framework',
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	// -----------------------------------------------------------------------
	// Read
	// -----------------------------------------------------------------------

	/**
	 * Get the SEO meta title for a post.
	 * Checks our own storage first, then each active plugin in detection order.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_meta_title( $post_id ) {
		$own = (string) get_post_meta( $post_id, '_seo_agent_ai_meta_title', true );
		if ( $own !== '' ) {
			return $own;
		}
		return $this->read_field( $post_id, 'title' );
	}

	/**
	 * Get the SEO meta description for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_meta_description( $post_id ) {
		$own = (string) get_post_meta( $post_id, '_seo_agent_ai_meta_description', true );
		if ( $own !== '' ) {
			return $own;
		}
		return $this->read_field( $post_id, 'description' );
	}

	/**
	 * Get the focus keyword for a post.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_focus_keyword( $post_id ) {
		return $this->read_field( $post_id, 'focus_keyword' );
	}

	// -----------------------------------------------------------------------
	// Write
	// -----------------------------------------------------------------------

	/**
	 * Write meta title to our own key AND all active plugin meta keys.
	 *
	 * @param int    $post_id
	 * @param string $value
	 */
	public function set_meta_title( $post_id, $value ) {
		$value = sanitize_text_field( (string) $value );
		update_post_meta( $post_id, '_seo_agent_ai_meta_title', $value );
		$this->write_field( $post_id, 'title', $value );
	}

	/**
	 * Write meta description to our own key AND all active plugin meta keys.
	 *
	 * @param int    $post_id
	 * @param string $value
	 */
	public function set_meta_description( $post_id, $value ) {
		$value = sanitize_textarea_field( (string) $value );
		update_post_meta( $post_id, '_seo_agent_ai_meta_description', $value );
		$this->write_field( $post_id, 'description', $value );
	}

	/**
	 * Write focus keyword to all active plugin meta keys that support it.
	 *
	 * @param int    $post_id
	 * @param string $value
	 */
	public function set_focus_keyword( $post_id, $value ) {
		$value = sanitize_text_field( (string) $value );
		$this->write_field( $post_id, 'focus_keyword', $value );
	}

	// -----------------------------------------------------------------------
	// Audit
	// -----------------------------------------------------------------------

	/**
	 * Audit the current on-page SEO state of a post.
	 * This is deterministic — no API calls needed.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @return array
	 */
	public function audit_post( $post_id, WP_Post $post ) {
		$title       = $this->get_meta_title( $post_id );
		$description = $this->get_meta_description( $post_id );
		$focus_kw    = $this->get_focus_keyword( $post_id );
		$word_count  = $this->count_words( $post->post_content );
		$title_len   = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $title ) : (int) strlen( $title );
		$desc_len    = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $description ) : (int) strlen( $description );

		return array(
			'has_title'           => $title !== '',
			'has_description'     => $description !== '',
			'has_focus_keyword'   => $focus_kw !== '',
			'title'               => $title,
			'description'         => $description,
			'focus_keyword'       => $focus_kw,
			'title_length'        => $title_len,
			'description_length'  => $desc_len,
			'word_count'          => $word_count,
			'title_too_long'      => $title !== '' && $title_len > 60,
			'title_too_short'     => $title !== '' && $title_len < 30,
			'desc_too_long'       => $description !== '' && $desc_len > 160,
			'desc_too_short'      => $description !== '' && $desc_len < 80,
			'content_thin'        => $word_count > 0 && $word_count < 300,
		);
	}

	/**
	 * Return all known meta keys for a given field (title/description).
	 * Used by fix executor to backup and restore across all plugins.
	 *
	 * @param string $field 'title' | 'description'
	 * @return string[]
	 */
	public function get_all_backup_keys( $field ) {
		$keys = array( '_seo_agent_ai_meta_' . $field );
		foreach ( self::PLUGIN_META as $plugin_meta ) {
			if ( ! empty( $plugin_meta[ $field ] ) ) {
				$keys[] = $plugin_meta[ $field ];
			}
		}
		return array_unique( $keys );
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	private function read_field( $post_id, $field ) {
		foreach ( $this->get_detected_plugins() as $plugin ) {
			if ( empty( self::PLUGIN_META[ $plugin ][ $field ] ) ) {
				continue;
			}
			$value = (string) get_post_meta( $post_id, self::PLUGIN_META[ $plugin ][ $field ], true );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}

	private function write_field( $post_id, $field, $value ) {
		foreach ( $this->get_detected_plugins() as $plugin ) {
			if ( empty( self::PLUGIN_META[ $plugin ][ $field ] ) ) {
				continue;
			}
			update_post_meta( $post_id, self::PLUGIN_META[ $plugin ][ $field ], $value );
		}
	}

	private function count_words( $content ) {
		$text = wp_strip_all_tags( (string) $content );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( $text === '' ) {
			return 0;
		}
		return str_word_count( $text );
	}
}
