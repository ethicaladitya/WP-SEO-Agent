<?php
/**
 * SEO Scoring Engine.
 *
 * Produces a multi-dimensional 0-100 score per page across 7 dimensions,
 * saves snapshots to the page_insights table, and tracks trends over time.
 *
 * Dimensions (max points):
 *   metadata      — 20
 *   content       — 20
 *   internal_links— 15
 *   schema        — 10
 *   engagement    — 15
 *   freshness     — 10
 *   ctr           — 10
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_SEO_Scoring_Engine {

	/** @var SEO_Agent_AI_Content_Analyzer */
	private $content_analyzer;

	public function __construct( SEO_Agent_AI_Content_Analyzer $content_analyzer ) {
		$this->content_analyzer = $content_analyzer;
	}

	// -------------------------------------------------------------------
	// Main scoring entry point
	// -------------------------------------------------------------------

	/**
	 * Score a post and save the snapshot to the page_insights table.
	 *
	 * @param WP_Post $post
	 * @param array   $gsc_data    GSC metrics (may be empty).
	 * @param array   $ga4_data    GA4 metrics (may be empty).
	 * @param array   $seo_audit   On-page audit from SEO_Plugin_Bridge::audit_post().
	 * @param bool    $save        Whether to persist the snapshot to DB.
	 * @return array {
	 *   int $overall        0-100 overall score.
	 *   array $dimensions   Per-dimension scores.
	 *   array $signals      Human-readable signal descriptions.
	 *   array $improvements Actionable improvement hints.
	 * }
	 */
	public function score( WP_Post $post, array $gsc_data = array(), array $ga4_data = array(), array $seo_audit = array(), $save = true ) {
		$content = $this->content_analyzer->analyze( $post, $gsc_data );

		$dim_scores   = array();
		$signals      = array();
		$improvements = array();

		// 1. Metadata (0-20)
		list( $dim_scores['metadata'], $s, $i ) = $this->score_metadata( $seo_audit );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 2. Content depth (0-20)
		list( $dim_scores['content'], $s, $i ) = $this->score_content( $content, $seo_audit );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 3. Internal links (0-15)
		list( $dim_scores['internal_links'], $s, $i ) = $this->score_internal_links( $post->ID, $content );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 4. Schema (0-10)
		list( $dim_scores['schema'], $s, $i ) = $this->score_schema( $content );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 5. Engagement (0-15)
		list( $dim_scores['engagement'], $s, $i ) = $this->score_engagement( $ga4_data );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 6. Freshness (0-10)
		list( $dim_scores['freshness'], $s, $i ) = $this->score_freshness_dim( $content, $post );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		// 7. CTR (0-10)
		list( $dim_scores['ctr'], $s, $i ) = $this->score_ctr( $gsc_data );
		$signals      = array_merge( $signals, $s );
		$improvements = array_merge( $improvements, $i );

		$overall = min( 100, array_sum( $dim_scores ) );

		$result = array(
			'overall'      => $overall,
			'dimensions'   => $dim_scores,
			'signals'      => $signals,
			'improvements' => $improvements,
		);

		if ( $save ) {
			SEO_Agent_AI_DB_Manager::insert_page_insight( $post->ID, $dim_scores + array( 'overall' => $overall ), $signals );
		}

		return $result;
	}

	/**
	 * Get the latest score for a post.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public function get_latest( $post_id ) {
		return SEO_Agent_AI_DB_Manager::get_latest_insight( $post_id );
	}

	/**
	 * Get score trend for a post (array of overall scores with dates).
	 *
	 * @param int $post_id
	 * @param int $limit
	 * @return array
	 */
	public function get_trend( $post_id, $limit = 30 ) {
		$rows = SEO_Agent_AI_DB_Manager::get_insight_history( $post_id, $limit );
		return array_map( fn( $r ) => array(
			'date'    => $r['recorded_at'],
			'overall' => (int) $r['score_overall'],
		), $rows );
	}

	// -------------------------------------------------------------------
	// Dimension scorers
	// -------------------------------------------------------------------

	/**
	 * @return array [score, signals[], improvements[]]
	 */
	private function score_metadata( array $seo_audit ) {
		$score = 0;
		$s     = array();
		$i     = array();

		if ( ! empty( $seo_audit['has_title'] ) ) {
			$score += 7;
			$tlen   = $seo_audit['title_length'] ?? 0;
			if ( $tlen >= 30 && $tlen <= 60 ) {
				$score += 3;
				$s[]    = 'Title length is optimal (' . $tlen . ' chars).';
			} elseif ( $tlen > 0 ) {
				$i[] = 'Adjust title length to 30-60 characters (currently ' . $tlen . ').';
			}
		} else {
			$i[] = 'Add a meta title — it is missing from all detected SEO plugins.';
		}

		if ( ! empty( $seo_audit['has_description'] ) ) {
			$score += 7;
			$dlen   = $seo_audit['description_length'] ?? 0;
			if ( $dlen >= 80 && $dlen <= 160 ) {
				$score += 3;
				$s[]    = 'Meta description length is optimal (' . $dlen . ' chars).';
			} elseif ( $dlen > 0 ) {
				$i[] = 'Adjust description length to 80-160 characters (currently ' . $dlen . ').';
			}
		} else {
			$i[] = 'Add a meta description.';
		}

		return array( min( 20, $score ), $s, $i );
	}

	private function score_content( array $content, array $seo_audit ) {
		$score = 0;
		$s     = array();
		$i     = array();
		$words = $content['word_count'] ?? 0;

		// Word count.
		if ( $words >= 1500 ) {
			$score += 8;
			$s[]    = "Comprehensive content ({$words} words).";
		} elseif ( $words >= 800 ) {
			$score += 6;
		} elseif ( $words >= 400 ) {
			$score += 3;
			$i[]    = "Expand content to 800+ words (currently {$words}).";
		} else {
			$i[] = "Content is thin ({$words} words). Aim for 800+ words.";
		}

		// Headings structure.
		$h2_count = count( $content['h2s'] ?? array() );
		if ( $h2_count >= 3 ) {
			$score += 4;
			$s[]    = "Good heading structure ({$h2_count} H2 sections).";
		} elseif ( $h2_count >= 1 ) {
			$score += 2;
			$i[]    = 'Add more H2 headings to structure the content into sections.';
		} else {
			$i[] = 'Add H2 headings to break up the content.';
		}

		// FAQ presence.
		if ( ! empty( $content['has_faq'] ) ) {
			$score += 3;
			$s[]    = 'FAQ section detected — good for People Also Ask coverage.';
		} else {
			$i[] = 'Consider adding an FAQ section targeting common questions.';
		}

		// Images.
		if ( ( $content['image_count'] ?? 0 ) >= 1 ) {
			$score += 2;
			if ( ! empty( $content['images_missing_alt'] ) ) {
				$score -= 1;
				$i[]    = 'Some images are missing alt text.';
			}
		}

		// Intro.
		if ( ! empty( $content['has_intro'] ) ) {
			$score += 3;
		} else {
			$i[] = 'Ensure the first paragraph is at least 40 words long.';
		}

		return array( min( 20, max( 0, $score ) ), $s, $i );
	}

	private function score_internal_links( $post_id, array $content ) {
		$score   = 0;
		$s       = array();
		$i       = array();
		$inlinks = $content['internal_link_count'] ?? 0;

		// Count inbound links from other posts via DB table.
		$inbound = count( SEO_Agent_AI_DB_Manager::get_post_links( $post_id, 'target' ) );

		if ( $inlinks >= 3 ) {
			$score += 8;
			$s[]    = "Good internal linking outbound ({$inlinks} links).";
		} elseif ( $inlinks >= 1 ) {
			$score += 4;
			$i[]    = 'Add more internal links to related content.';
		} else {
			$i[] = 'No internal links found — add links to related posts.';
		}

		if ( $inbound >= 3 ) {
			$score += 7;
			$s[]    = "Well-linked page ({$inbound} inbound internal links).";
		} elseif ( $inbound >= 1 ) {
			$score += 3;
			$i[]    = 'Few inbound internal links — link to this page from other relevant posts.';
		} else {
			$i[] = 'Orphan page — no other posts link to it internally.';
		}

		return array( min( 15, $score ), $s, $i );
	}

	private function score_schema( array $content ) {
		$score = 0;
		$s     = array();
		$i     = array();

		if ( ! empty( $content['has_schema'] ) ) {
			$score += 6;
			$types  = $content['schema_types'] ?? array();
			$s[]    = 'Schema markup found: ' . implode( ', ', $types ) . '.';

			if ( in_array( 'FAQPage', $types, true ) || in_array( 'FAQPage', array_map( fn( $t ) => strpos( $t, 'FAQPage' ) !== false ? 'FAQPage' : $t, $types ), true ) ) {
				$score += 4;
				$s[]    = 'FAQ schema present — eligible for rich results.';
			}
		} else {
			$i[] = 'No structured data (JSON-LD) found — add Article or FAQ schema.';
		}

		return array( min( 10, $score ), $s, $i );
	}

	private function score_engagement( array $ga4_data ) {
		$score = 0;
		$s     = array();
		$i     = array();

		if ( empty( $ga4_data ) || ( $ga4_data['sessions_28d'] ?? 0 ) < 5 ) {
			// Not enough data — give partial credit.
			return array( 7, array( 'Insufficient GA4 data for engagement scoring.' ), array() );
		}

		$engagement = $ga4_data['engagement_rate'] ?? 0.0;
		$time       = $ga4_data['avg_time_on_page_sec'] ?? 0;

		if ( $engagement >= 0.7 ) {
			$score += 8;
			$s[]    = 'Excellent engagement rate (' . round( $engagement * 100 ) . '%).';
		} elseif ( $engagement >= 0.5 ) {
			$score += 5;
		} elseif ( $engagement >= 0.3 ) {
			$score += 2;
			$i[]    = 'Engagement rate is low (' . round( $engagement * 100 ) . '%). Improve the intro and content structure.';
		} else {
			$i[] = 'Very low engagement rate. Consider rewriting the introduction and adding more value above the fold.';
		}

		if ( $time >= 180 ) {
			$score += 7;
			$s[]    = 'Strong average time on page (' . gmdate( 'i:s', $time ) . ').';
		} elseif ( $time >= 90 ) {
			$score += 4;
		} elseif ( $time >= 30 ) {
			$score += 1;
			$i[]    = 'Short average time on page (' . $time . 's). Consider improving content depth.';
		} else {
			$i[] = 'Users leave quickly (' . $time . 's). Rewrite the introduction to immediately deliver value.';
		}

		return array( min( 15, $score ), $s, $i );
	}

	private function score_freshness_dim( array $content, WP_Post $post ) {
		$score   = 0;
		$s       = array();
		$i       = array();
		$fresh   = $content['freshness_score'] ?? 50;
		$decay   = $content['content_decay_risk'] ?? false;

		if ( $fresh >= 80 ) {
			$score += 10;
			$s[]    = 'Content is fresh and recently updated.';
		} elseif ( $fresh >= 60 ) {
			$score += 7;
		} elseif ( $fresh >= 40 ) {
			$score += 4;
			$i[]    = 'Content may be getting stale. Consider updating statistics and examples.';
		} else {
			$i[] = 'Content decay detected — outdated years or long time since last update.';
		}

		if ( $decay ) {
			$score = max( 0, $score - 3 );
		}

		return array( min( 10, $score ), $s, $i );
	}

	private function score_ctr( array $gsc_data ) {
		$score = 0;
		$s     = array();
		$i     = array();

		$impressions = $gsc_data['impressions_total'] ?? 0;
		$ctr         = $gsc_data['ctr_avg'] ?? 0.0;
		$position    = $gsc_data['position_avg'] ?? 99.0;

		if ( $impressions < 10 ) {
			return array( 5, array( 'Insufficient GSC data for CTR scoring.' ), array() );
		}

		// Compare actual CTR to position-expected CTR.
		$expected = $this->expected_ctr( $position );
		$ratio    = $expected > 0 ? $ctr / $expected : 0;

		if ( $ratio >= 1.0 ) {
			$score += 10;
			$s[]    = 'CTR is at or above position expectation (' . round( $ctr * 100, 1 ) . '%).';
		} elseif ( $ratio >= 0.7 ) {
			$score += 7;
		} elseif ( $ratio >= 0.4 ) {
			$score += 3;
			$i[]    = 'CTR is below average for this position — improve title and meta description.';
		} else {
			$i[] = 'CTR is significantly below expectation. The snippet is not compelling enough for position ' . round( $position, 1 ) . '.';
		}

		return array( min( 10, $score ), $s, $i );
	}

	// -------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------

	private function expected_ctr( $position ) {
		$map = array( 1 => 0.32, 2 => 0.18, 3 => 0.11, 4 => 0.08, 5 => 0.06, 6 => 0.05, 7 => 0.04, 8 => 0.035, 9 => 0.03, 10 => 0.025 );
		$pos = (int) round( $position );
		return $map[ $pos ] ?? ( $pos <= 20 ? 0.015 : 0.005 );
	}
}
