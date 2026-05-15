<?php
/**
 * Social Meta — Open Graph and Twitter Card tags, plus webmaster verification.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Social_Meta {

	// -------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------

	public function init_hooks() {
		add_action( 'wp_head', array( $this, 'output_meta_tags' ),        1 );
		add_action( 'wp_head', array( $this, 'output_verification_tags' ), 2 );
	}

	// -------------------------------------------------------------------
	// Main output
	// -------------------------------------------------------------------

	public function output_meta_tags() {
		if ( ! (bool) get_option( 'seo_agent_ai_social_meta_enabled', true ) ) {
			return;
		}

		if ( is_singular() ) {
			$this->output_singular_tags();
		} elseif ( is_front_page() || is_home() ) {
			$this->output_homepage_tags();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$this->output_term_tags();
		}
	}

	// -------------------------------------------------------------------
	// Singular posts / pages
	// -------------------------------------------------------------------

	private function output_singular_tags() {
		$post    = get_queried_object();
		$post_id = (int) $post->ID;

		// Determine values — custom overrides take precedence.
		$og_title = (string) get_post_meta( $post_id, '_seo_agent_ai_og_title', true );
		if ( ! $og_title ) {
			$og_title = get_the_title( $post_id );
		}

		$og_desc = (string) get_post_meta( $post_id, '_seo_agent_ai_og_description', true );
		if ( ! $og_desc ) {
			$og_desc = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( strip_tags( $post->post_content ), 30, '' );
		}

		$og_url  = (string) get_permalink( $post_id );
		$og_type = ( $post->post_type === 'post' ) ? 'article' : 'website';

		// Image — custom override first.
		$og_image    = '';
		$og_image_id = (int) get_post_meta( $post_id, '_seo_agent_ai_og_image_id', true );
		if ( $og_image_id ) {
			$src      = wp_get_attachment_image_src( $og_image_id, 'large' );
			$og_image = $src ? (string) $src[0] : '';
		} elseif ( has_post_thumbnail( $post_id ) ) {
			$src      = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			$og_image = $src ? (string) $src[0] : '';
		}

		$site_name = get_bloginfo( 'name' );

		// Output OG tags.
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $og_url ) . '" />' . "\n";
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";

		if ( $og_image ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image ) . '" />' . "\n";
		}

		if ( $og_type === 'article' ) {
			echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post_id ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post_id ) ) . '" />' . "\n";
			$author = get_the_author_meta( 'display_name', $post->post_author );
			if ( $author ) {
				echo '<meta property="article:author" content="' . esc_attr( $author ) . '" />' . "\n";
			}
		}

		// Twitter Cards.
		$twitter_card = $og_image ? 'summary_large_image' : 'summary';
		echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";
		if ( $og_image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------
	// Homepage
	// -------------------------------------------------------------------

	private function output_homepage_tags() {
		$title     = (string) get_option( 'seo_agent_ai_homepage_og_title',       '' );
		$desc      = (string) get_option( 'seo_agent_ai_homepage_og_description', '' );
		$image_url = (string) get_option( 'seo_agent_ai_homepage_og_image',       '' );
		$site_name = get_bloginfo( 'name' );

		if ( ! $title ) {
			$title = $site_name;
		}
		if ( ! $desc ) {
			$desc = get_bloginfo( 'description' );
		}

		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( home_url( '/' ) ) . '" />' . "\n";
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";

		if ( $image_url ) {
			echo '<meta property="og:image" content="' . esc_url( $image_url ) . '" />' . "\n";
		}

		$twitter_card = $image_url ? 'summary_large_image' : 'summary';
		echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		if ( $image_url ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------
	// Taxonomy archives
	// -------------------------------------------------------------------

	private function output_term_tags() {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$title     = $term->name;
		$desc      = wp_strip_all_tags( term_description( $term->term_id, $term->taxonomy ) );
		$url       = get_term_link( $term );
		$site_name = get_bloginfo( 'name' );

		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( is_string( $url ) ? $url : '' ) . '" />' . "\n";
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";

		echo '<meta name="twitter:card" content="summary" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------
	// Webmaster verification
	// -------------------------------------------------------------------

	public function output_verification_tags() {
		$google  = (string) get_option( 'seo_agent_ai_google_verification', '' );
		$bing    = (string) get_option( 'seo_agent_ai_bing_verification',   '' );
		$yandex  = (string) get_option( 'seo_agent_ai_yandex_verification', '' );

		if ( $google ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $google ) . '" />' . "\n";
		}
		if ( $bing ) {
			echo '<meta name="msvalidate.01" content="' . esc_attr( $bing ) . '" />' . "\n";
		}
		if ( $yandex ) {
			echo '<meta name="yandex-verification" content="' . esc_attr( $yandex ) . '" />' . "\n";
		}
	}

	// -------------------------------------------------------------------
	// Data helper (used by metabox)
	// -------------------------------------------------------------------

	/**
	 * Get current Open Graph / Twitter data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_post_social_data( $post_id ) {
		$post_id  = (int) $post_id;
		$post     = get_post( $post_id );
		$og_title = (string) get_post_meta( $post_id, '_seo_agent_ai_og_title',       true );
		$og_desc  = (string) get_post_meta( $post_id, '_seo_agent_ai_og_description', true );
		$img_id   = (int) get_post_meta( $post_id, '_seo_agent_ai_og_image_id',       true );

		return array(
			'og_title'       => $og_title,
			'og_description' => $og_desc,
			'og_image_id'    => $img_id,
			'og_image_url'   => $img_id ? (string) ( wp_get_attachment_image_src( $img_id, 'thumbnail' )[0] ?? '' ) : '',
			'post_title'     => $post ? $post->post_title : '',
			'post_excerpt'   => $post ? $post->post_excerpt : '',
		);
	}
}
