<?php
/**
 * Schema Engine — JSON-LD structured data generation.
 *
 * Responsibility: inject supplementary schema that active SEO plugins do not
 * auto-generate from post content — primarily FAQPage (and, when no SEO plugin
 * is active, BlogPosting and BreadcrumbList as a fallback).
 *
 * When a schema-capable SEO plugin is present (Yoast, RankMath, SmartCrawl,
 * AIOSEO, SEOPress, The SEO Framework) we defer Article/BreadcrumbList entirely
 * to that plugin and only add what it cannot detect: FAQPage items found in the
 * post content.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Schema_Engine {

	/** @var SEO_Agent_AI_Content_Analyzer */
	private $content_analyzer;

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	const CACHE_PREFIX = 'seo_agent_schema_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	public function __construct( SEO_Agent_AI_Content_Analyzer $content_analyzer, SEO_Agent_AI_Logger $logger ) {
		$this->content_analyzer = $content_analyzer;
		$this->logger           = $logger;
	}

	// -------------------------------------------------------------------
	// SEO plugin detection
	// -------------------------------------------------------------------

	/**
	 * Returns true when any schema-capable SEO plugin is active.
	 *
	 * These plugins all output Article/BlogPosting/NewsArticle and BreadcrumbList
	 * in their own schema graphs, so we must not duplicate those blocks.
	 */
	private function active_seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend', false ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			return 'rankmath';
		}
		if (
			defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'SmartCrawl_Settings', false )
			|| class_exists( 'Smartcrawl\\Smartcrawl', false )
		) {
			return 'smartcrawl';
		}
		if (
			defined( 'AIOSEO_VERSION' )
			|| class_exists( 'AIOSEO\\Plugin\\AIOSEO', false )
			|| function_exists( 'aioseo' )
		) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || class_exists( 'SeoPress_Admin_Pages', false ) ) {
			return 'seopress';
		}
		if ( function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load', false ) ) {
			return 'seoframework';
		}
		return '';
	}

	/** True when any SEO plugin handles Article + BreadcrumbList schema. */
	private function seo_plugin_handles_post_schema(): bool {
		return $this->active_seo_plugin() !== '';
	}

	// -------------------------------------------------------------------
	// WordPress hook integration
	// -------------------------------------------------------------------

	public function register_hooks() {
		add_action( 'wp_head', array( $this, 'inject_schema' ), 5 );
	}

	public function inject_schema() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$blocks = $this->get_schema_blocks( $post );
		if ( empty( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			echo "\n<script type=\"application/ld+json\">\n";
			echo wp_json_encode( $block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			echo "\n</script>\n";
		}
	}

	// -------------------------------------------------------------------
	// Schema block generation
	// -------------------------------------------------------------------

	public function get_schema_blocks( WP_Post $post ) {
		$cache_key = self::CACHE_PREFIX . $post->ID;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$content        = $this->content_analyzer->analyze( $post );
		$existing_types = array_map( 'strtolower', $content['schema_types'] ?? array() );
		$blocks         = array();

		// --- Article / BlogPosting ---
		// Defer to the active SEO plugin (Yoast, RankMath, SmartCrawl, etc.) when one
		// is present — they all output an Article/NewsArticle node in their schema graph.
		// Only inject ourselves as a fallback when no SEO plugin is active.
		if ( ! $this->seo_plugin_handles_post_schema() ) {
			$has_article = array_filter(
				$existing_types,
				static fn( $t ) => str_contains( $t, 'article' ) || str_contains( $t, 'blogposting' )
			);
			if ( empty( $has_article ) ) {
				$article = $this->build_article( $post, $content );
				if ( $article ) {
					$blocks[] = $article;
				}
			}
		}

		// --- FAQPage ---
		// No major SEO plugin auto-detects FAQ pairs in post content; this is unique
		// value seo-agent-ai adds regardless of which SEO plugin is active.
		$has_faq_schema = array_filter(
			$existing_types,
			static fn( $t ) => str_contains( $t, 'faq' )
		);
		if ( empty( $has_faq_schema ) && ! empty( $content['faq_items'] ) ) {
			$faq = $this->build_faq( $content['faq_items'] );
			if ( $faq ) {
				$blocks[] = $faq;
			}
		}

		// --- BreadcrumbList ---
		// All major SEO plugins include a BreadcrumbList node in their schema graph.
		// Only inject ourselves when no SEO plugin is active.
		if ( ! $this->seo_plugin_handles_post_schema() ) {
			$breadcrumb = $this->build_breadcrumb( $post );
			if ( $breadcrumb ) {
				$blocks[] = $breadcrumb;
			}
		}

		set_transient( $cache_key, $blocks, self::CACHE_TTL );
		return $blocks;
	}

	public function invalidate_cache( $post_id ) {
		delete_transient( self::CACHE_PREFIX . $post_id );
	}

	public function generate( WP_Post $post ) {
		$this->invalidate_cache( $post->ID );
		return $this->get_schema_blocks( $post );
	}

	// -------------------------------------------------------------------
	// Block builders
	// -------------------------------------------------------------------

	private function build_article( WP_Post $post, array $content ) {
		$author_id    = (int) $post->post_author;
		$author_name  = get_the_author_meta( 'display_name', $author_id );
		$author_url   = get_author_posts_url( $author_id );
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$thumbnail    = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'large' ) : '';

		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'BlogPosting',
			'headline'         => wp_strip_all_tags( $post->post_title ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => $author_name,
				'url'   => $author_url,
			),
			'publisher'        => array(
				'@type' => 'Person',
				'name'  => $author_name,
				'url'   => home_url( '/' ),
			),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
			'description'      => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
			'wordCount'        => $content['word_count'] ?? 0,
			'url'              => get_permalink( $post ),
		);

		if ( $thumbnail ) {
			$schema['image'] = $thumbnail;
		}

		if ( ! empty( $content['h2s'] ) ) {
			$schema['articleSection'] = $content['h2s'][0] ?? '';
		}

		return $schema;
	}

	private function build_faq( array $faq_items ) {
		$entities = array();
		foreach ( $faq_items as $item ) {
			$q = trim( wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$a = trim( wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) );
			if ( $q === '' || $a === '' ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}

		if ( empty( $entities ) ) {
			return null;
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	private function build_breadcrumb( WP_Post $post ) {
		$items = array();
		$pos   = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => get_bloginfo( 'name' ),
			'item'     => home_url( '/' ),
		);

		$cats = get_the_category( $post->ID );
		if ( ! empty( $cats ) ) {
			$cat     = $cats[0];
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => esc_html( $cat->name ),
				'item'     => get_category_link( $cat->term_id ),
			);
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => wp_strip_all_tags( $post->post_title ),
			'item'     => get_permalink( $post ),
		);

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}
}
