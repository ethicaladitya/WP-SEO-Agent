<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_SEO_Analyzer {

	/** @var SEO_Agent_AI_Content_Analyzer|null */
	private $content_analyzer;

	/** @var SEO_Agent_AI_Keyword_Cluster|null */
	private $keyword_cluster;

	public function __construct(
		SEO_Agent_AI_Content_Analyzer $content_analyzer = null,
		SEO_Agent_AI_Keyword_Cluster $keyword_cluster = null
	) {
		$this->content_analyzer = $content_analyzer;
		$this->keyword_cluster  = $keyword_cluster;
	}

	/**
	 * Analyze a post and return signals, severity, confidence, and evidence.
	 *
	 * @param WP_Post $post
	 * @param array   $gsc        GSC metrics (may be empty on API error).
	 * @param array   $ga4        GA4 metrics (may be empty on API error).
	 * @param array   $seo_audit  On-page SEO audit from SEO_Plugin_Bridge::audit_post().
	 * @param array   $extra      Optional extra data: 'all_page_queries' for cannibalization.
	 * @return array
	 */
	public function analyze( WP_Post $post, array $gsc, array $ga4, array $seo_audit = array(), array $extra = array() ) {
		$signals = array(
			// Original 6 signals.
			'missing_meta_basics'      => false,
			'thin_content'             => false,
			'title_meta_optimization'  => false,
			'content_refresh_needed'   => false,
			'intent_mismatch'          => false,
			'declining_performance'    => false,

			// New 10 signals.
			'page_two_opportunity'     => false, // Ranking pos 11-20 with decent impressions.
			'ctr_anomaly'              => false, // CTR significantly below position expectation.
			'cannibalization_risk'     => false, // Multiple pages competing for same keywords.
			'content_decay'            => false, // Stale content with old years / no recent update.
			'orphan_page'              => false, // No inbound internal links.
			'missing_schema'           => false, // No JSON-LD structured data.
			'weak_engagement'          => false, // Low engagement across all metrics.
			'title_ctr_mismatch'       => false, // Good position, poor CTR (title not compelling).
			'missing_faq'              => false, // No FAQ section but keyword likely triggers PAA.
			'index_anomaly'            => false, // Very high impressions but poor position (≥50).
		);

		// ------------------------------------------------------------------
		// Baseline on-page signals (fire even with zero traffic)
		// ------------------------------------------------------------------

		if ( ! empty( $seo_audit ) ) {
			if ( ! $seo_audit['has_title'] || ! $seo_audit['has_description'] ) {
				$signals['missing_meta_basics'] = true;
			}
			if ( ! empty( $seo_audit['content_thin'] ) ) {
				$signals['thin_content'] = true;
			}
			if ( ! empty( $seo_audit['title_too_long'] ) || ! empty( $seo_audit['title_too_short'] ) ) {
				$signals['title_meta_optimization'] = true;
			}
		}

		// ------------------------------------------------------------------
		// Content analysis signals (via ContentAnalyzer, if available)
		// ------------------------------------------------------------------

		$content_data = array();
		if ( $this->content_analyzer instanceof SEO_Agent_AI_Content_Analyzer ) {
			$content_data = $this->content_analyzer->analyze( $post, $gsc );

			// Content decay: old content with stale signals.
			if ( ! empty( $content_data['content_decay_risk'] ) ) {
				$signals['content_decay'] = true;
			}

			// Missing schema: no JSON-LD detected in post content.
			if ( empty( $content_data['has_schema'] ) ) {
				$signals['missing_schema'] = true;
			}

			// Missing FAQ: no FAQ section detected.
			if ( empty( $content_data['has_faq'] ) ) {
				$signals['missing_faq'] = true;
			}

			// Thin content from word count (supplement seo_audit check).
			if ( empty( $signals['thin_content'] ) && ( $content_data['word_count'] ?? 0 ) < 300 ) {
				$signals['thin_content'] = true;
			}
		}

		// ------------------------------------------------------------------
		// Internal link signals: orphan detection via DB table
		// ------------------------------------------------------------------

		if ( class_exists( 'SEO_Agent_AI_DB_Manager' ) ) {
			$inbound = SEO_Agent_AI_DB_Manager::get_post_links( $post->ID, 'target' );
			if ( count( $inbound ) === 0 ) {
				$signals['orphan_page'] = true;
			}
		}

		// ------------------------------------------------------------------
		// Traffic-based signals (require impression/metric data)
		// ------------------------------------------------------------------

		$impressions       = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;
		$ctr               = isset( $gsc['ctr_avg'] ) ? (float) $gsc['ctr_avg'] : 0.0;
		$position          = isset( $gsc['position_avg'] ) ? (float) $gsc['position_avg'] : 100.0;
		$impressions_trend = isset( $gsc['impressions_trend_28d'] ) ? (float) $gsc['impressions_trend_28d'] : 0.0;

		$engagement     = isset( $ga4['engagement_rate'] ) ? (float) $ga4['engagement_rate'] : 0.0;
		$time_on_page   = isset( $ga4['avg_time_on_page_sec'] ) ? (int) $ga4['avg_time_on_page_sec'] : 0;
		$sessions_trend = isset( $ga4['sessions_trend_28d'] ) ? (float) $ga4['sessions_trend_28d'] : 0.0;

		// Low CTR opportunity (existing).
		if ( $impressions > 100 && $ctr < 0.03 && $position <= 25 ) {
			$signals['title_meta_optimization'] = true;
		}

		// Content refresh: mid-ranking with poor engagement (existing).
		if ( $impressions > 100 && $position >= 5 && $position <= 30 && ( $engagement < 0.5 || $time_on_page < 90 ) ) {
			$signals['content_refresh_needed'] = true;
		}

		// Intent mismatch: ranking but users bounce (existing).
		if ( $impressions > 50 && $position <= 12 && ( $engagement < 0.4 || $time_on_page < 70 ) ) {
			$signals['intent_mismatch'] = true;
		}

		// Declining performance (existing).
		if ( $impressions_trend < -10.0 || $sessions_trend < -10.0 ) {
			$signals['declining_performance'] = true;
		}

		// Page 2 opportunity: ranking 11-20 with meaningful traffic.
		if ( $impressions >= 30 && $position >= 11.0 && $position <= 20.0 ) {
			$signals['page_two_opportunity'] = true;
		}

		// CTR anomaly: actual CTR < 60% of expected for this position.
		if ( $impressions >= 50 && $position <= 20.0 ) {
			$expected = $this->expected_ctr( $position );
			if ( $expected > 0 && ( $ctr / $expected ) < 0.60 ) {
				$signals['ctr_anomaly'] = true;
			}
		}

		// Title/CTR mismatch: top-5 position but below-average CTR.
		if ( $impressions >= 100 && $position <= 5.0 && $ctr < $this->expected_ctr( $position ) * 0.75 ) {
			$signals['title_ctr_mismatch'] = true;
		}

		// Weak engagement: poor metrics across the board.
		if ( isset( $ga4['sessions_28d'] ) && (int) $ga4['sessions_28d'] >= 10
			&& $engagement < 0.35 && $time_on_page < 60
		) {
			$signals['weak_engagement'] = true;
		}

		// Index anomaly: visible in search (many impressions) but ranking very poorly (pos ≥50).
		if ( $impressions >= 200 && $position >= 50.0 ) {
			$signals['index_anomaly'] = true;
		}

		// Cannibalization: detected when cross-page query data is provided.
		if ( ! empty( $extra['all_page_queries'] ) && $this->keyword_cluster instanceof SEO_Agent_AI_Keyword_Cluster ) {
			$cannibal = $this->keyword_cluster->detect_cannibalization( $extra['all_page_queries'] );
			$page_url = get_permalink( $post );
			foreach ( $cannibal as $item ) {
				$pages = array_column( $item['pages'] ?? array(), 'page' );
				if ( in_array( $page_url, $pages, true ) && count( $pages ) >= 2 ) {
					$signals['cannibalization_risk'] = true;
					break;
				}
			}
		}

		// ------------------------------------------------------------------
		// Build evidence and return
		// ------------------------------------------------------------------

		$severity   = $this->calculate_severity( $signals );
		$confidence = $this->calculate_confidence( $gsc, $ga4, $signals, $seo_audit );

		return array(
			'signals'      => $signals,
			'severity'     => $severity,
			'confidence'   => $confidence,
			'evidence'     => array(
				'impressions_total'         => $impressions,
				'ctr_avg'                   => $ctr,
				'position_avg'              => $position,
				'engagement_rate'           => $engagement,
				'avg_time_on_page_sec'      => $time_on_page,
				'impressions_trend_28d_pct' => $impressions_trend,
				'sessions_trend_28d_pct'    => $sessions_trend,
				'word_count'                => $content_data['word_count'] ?? ( $seo_audit['word_count'] ?? 0 ),
				'has_meta_title'            => isset( $seo_audit['has_title'] ) ? (bool) $seo_audit['has_title'] : null,
				'has_meta_description'      => isset( $seo_audit['has_description'] ) ? (bool) $seo_audit['has_description'] : null,
				'has_schema'                => $content_data['has_schema'] ?? null,
				'schema_types'              => $content_data['schema_types'] ?? array(),
				'has_faq'                   => $content_data['has_faq'] ?? null,
				'freshness_score'           => $content_data['freshness_score'] ?? null,
				'content_decay_risk'        => $content_data['content_decay_risk'] ?? null,
			),
			'content_data' => $content_data,
		);
	}

	// -------------------------------------------------------------------
	// Confidence & severity calculation
	// -------------------------------------------------------------------

	private function calculate_confidence( array $gsc, array $ga4, array $signals, array $seo_audit = array() ) {
		$impressions   = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;
		$has_sessions  = isset( $ga4['sessions_28d'] ) && (int) $ga4['sessions_28d'] > 0;
		$has_trend_gsc = isset( $gsc['impressions_trend_28d'] ) && (float) $gsc['impressions_trend_28d'] !== 0.0;
		$has_trend_ga4 = isset( $ga4['sessions_trend_28d'] ) && (float) $ga4['sessions_trend_28d'] !== 0.0;
		$active_signals = count( array_filter( $signals ) );

		// Deterministic baseline checks: always high confidence.
		if ( ! empty( $signals['missing_meta_basics'] ) && $impressions === 0 ) {
			$score = 0.90;
			$score += ! empty( $signals['thin_content'] ) ? 0.05 : 0.0;
			return (float) round( min( 1.0, $score ), 3 );
		}

		// Base score from impression volume.
		if ( $impressions >= 5000 ) {
			$score = 0.40;
		} elseif ( $impressions >= 2000 ) {
			$score = 0.35;
		} elseif ( $impressions >= 500 ) {
			$score = 0.28;
		} elseif ( $impressions >= 100 ) {
			$score = 0.20;
		} elseif ( $impressions >= 10 ) {
			$score = 0.15;
		} else {
			$score = 0.12;
		}

		if ( $has_sessions ) {
			$score += 0.10;
		}

		if ( $has_trend_gsc || $has_trend_ga4 ) {
			$score += 0.05;
		}

		if ( ! empty( $seo_audit ) ) {
			$score += 0.05;
		}

		// Deterministic signals (schema missing, orphan, decay) add moderate confidence.
		$deterministic = array_intersect_key(
			array_filter( $signals ),
			array_flip( array( 'missing_schema', 'orphan_page', 'content_decay', 'missing_faq' ) )
		);
		if ( ! empty( $deterministic ) ) {
			$score += 0.08;
		}

		$score += min( $active_signals, 4 ) * 0.07;

		return (float) round( min( 1.0, max( 0.0, $score ) ), 3 );
	}

	private function calculate_severity( array $signals ) {
		$score = count( array_filter( $signals ) );

		if ( $score >= 5 ) {
			return 'critical';
		}
		if ( $score >= 3 ) {
			return 'high';
		}
		if ( $score === 2 ) {
			return 'medium';
		}
		if ( $score === 1 ) {
			return 'low';
		}
		return 'none';
	}

	private function expected_ctr( $position ) {
		$map = array(
			1  => 0.32, 2  => 0.18, 3  => 0.11,
			4  => 0.08, 5  => 0.06, 6  => 0.05,
			7  => 0.04, 8  => 0.035, 9  => 0.03,
			10 => 0.025,
		);
		$pos = (int) round( $position );
		return $map[ $pos ] ?? ( $pos <= 20 ? 0.015 : 0.005 );
	}
}
