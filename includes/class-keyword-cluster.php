<?php
/**
 * Keyword clustering engine.
 *
 * Groups GSC queries into semantic clusters, detects cannibalization, and
 * scores ranking opportunities. No external API calls — uses word-overlap
 * and impressions-weighted scoring.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Keyword_Cluster {

	// Minimum word overlap ratio to merge two queries into the same cluster.
	const CLUSTER_SIMILARITY_THRESHOLD = 0.4;

	// Minimum impressions for a cluster to qualify as a "ranking opportunity".
	const MIN_OPPORTUNITY_IMPRESSIONS = 30;

	// -------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------

	/**
	 * Cluster GSC queries for a single page.
	 *
	 * @param array $queries  Array of GSC query rows: [query, impressions, ctr, position].
	 * @return array {
	 *   array $clusters        Array of cluster objects.
	 *   string $primary_keyword The top-impression keyword for the page.
	 *   array $opportunities   Clusters representing ranking opportunities.
	 * }
	 */
	public function cluster_page_queries( array $queries ) {
		if ( empty( $queries ) ) {
			return array(
				'clusters'        => array(),
				'primary_keyword' => '',
				'opportunities'   => array(),
			);
		}

		$clusters = $this->build_clusters( $queries );
		$clusters = $this->score_clusters( $clusters );

		usort( $clusters, fn( $a, $b ) => $b['total_impressions'] - $a['total_impressions'] );

		$primary     = $clusters ? ( $clusters[0]['label'] ?? '' ) : '';
		$opps        = $this->find_opportunities( $clusters );

		return array(
			'clusters'        => $clusters,
			'primary_keyword' => $primary,
			'opportunities'   => $opps,
		);
	}

	/**
	 * Detect keyword cannibalization across multiple pages.
	 *
	 * @param array $page_query_map  Keys: page URL (or post ID), values: array of query rows.
	 * @return array  Array of cannibalization records: [keyword, pages, severity].
	 */
	public function detect_cannibalization( array $page_query_map ) {
		$keyword_pages = array();

		foreach ( $page_query_map as $page_id => $queries ) {
			foreach ( $queries as $q ) {
				$kw = strtolower( trim( (string) ( $q['query'] ?? '' ) ) );
				if ( $kw === '' || ( $q['impressions'] ?? 0 ) < 5 ) {
					continue;
				}
				if ( ! isset( $keyword_pages[ $kw ] ) ) {
					$keyword_pages[ $kw ] = array();
				}
				$keyword_pages[ $kw ][] = array(
					'page'        => $page_id,
					'impressions' => (int) ( $q['impressions'] ?? 0 ),
					'position'    => (float) ( $q['position'] ?? 99 ),
					'ctr'         => (float) ( $q['ctr'] ?? 0 ),
				);
			}
		}

		$cannibalized = array();
		foreach ( $keyword_pages as $kw => $pages ) {
			if ( count( $pages ) < 2 ) {
				continue;
			}
			// Sort by impressions desc.
			usort( $pages, fn( $a, $b ) => $b['impressions'] - $a['impressions'] );

			$total_impressions = array_sum( array_column( $pages, 'impressions' ) );
			$severity          = $total_impressions >= 500 ? 'high'
				: ( $total_impressions >= 100 ? 'medium' : 'low' );

			$cannibalized[] = array(
				'keyword'           => $kw,
				'pages'             => $pages,
				'total_impressions' => $total_impressions,
				'severity'          => $severity,
				'recommendation'    => $this->cannibalization_recommendation( $pages ),
			);
		}

		usort( $cannibalized, fn( $a, $b ) => $b['total_impressions'] - $a['total_impressions'] );
		return $cannibalized;
	}

	/**
	 * Score a set of queries for ranking opportunity.
	 *
	 * Opportunity = high impressions + position just outside top 3 or top 10.
	 *
	 * @param array $queries  GSC query rows.
	 * @return array  Sorted opportunity rows with opportunity_score added.
	 */
	public function score_opportunities( array $queries ) {
		$opps = array();

		foreach ( $queries as $q ) {
			$impressions = (int) ( $q['impressions'] ?? 0 );
			$position    = (float) ( $q['position'] ?? 99 );
			$ctr         = (float) ( $q['ctr'] ?? 0 );

			if ( $impressions < self::MIN_OPPORTUNITY_IMPRESSIONS ) {
				continue;
			}

			// Page 2 = positions 11-20: highest opportunity (already visible, needs push).
			// Top 10 positions 4-10: still valuable.
			$score = 0;
			if ( $position >= 11 && $position <= 20 ) {
				$score = 90 + ( log( $impressions + 1 ) * 5 );
			} elseif ( $position >= 4 && $position <= 10 ) {
				$score = 70 + ( log( $impressions + 1 ) * 4 );
			} elseif ( $position > 20 && $position <= 30 ) {
				$score = 40 + ( log( $impressions + 1 ) * 3 );
			} else {
				continue; // Top 3 or position > 30: less actionable.
			}

			// Bonus: low CTR relative to position (room to improve snippet).
			$expected_ctr = $this->expected_ctr( $position );
			if ( $ctr < $expected_ctr * 0.7 ) {
				$score += 15;
			}

			$q['opportunity_score']    = (int) min( 100, $score );
			$q['opportunity_type']     = $position >= 11 ? 'page_2' : 'top_10';
			$q['expected_ctr']         = $expected_ctr;
			$opps[] = $q;
		}

		usort( $opps, fn( $a, $b ) => $b['opportunity_score'] - $a['opportunity_score'] );
		return $opps;
	}

	// -------------------------------------------------------------------
	// Clustering internals
	// -------------------------------------------------------------------

	private function build_clusters( array $queries ) {
		$clusters = array();

		foreach ( $queries as $q ) {
			$kw    = strtolower( trim( (string) ( $q['query'] ?? '' ) ) );
			$words = $this->tokenize( $kw );

			$best_cluster = -1;
			$best_score   = 0.0;

			foreach ( $clusters as $ci => $cluster ) {
				$score = $this->similarity( $words, $cluster['label_words'] );
				if ( $score > $best_score && $score >= self::CLUSTER_SIMILARITY_THRESHOLD ) {
					$best_score   = $score;
					$best_cluster = $ci;
				}
			}

			if ( $best_cluster >= 0 ) {
				$clusters[ $best_cluster ]['queries'][] = $q;
			} else {
				$clusters[] = array(
					'label'        => $kw,
					'label_words'  => $words,
					'queries'      => array( $q ),
				);
			}
		}

		// Re-label each cluster with the highest-impression query.
		foreach ( $clusters as &$cluster ) {
			usort( $cluster['queries'], fn( $a, $b ) => (int) ( $b['impressions'] ?? 0 ) - (int) ( $a['impressions'] ?? 0 ) );
			$cluster['label'] = $cluster['queries'][0]['query'] ?? $cluster['label'];
		}
		unset( $cluster );

		return $clusters;
	}

	private function score_clusters( array $clusters ) {
		foreach ( $clusters as &$cluster ) {
			$total_impressions = 0;
			$total_clicks      = 0;
			$position_sum      = 0.0;

			foreach ( $cluster['queries'] as $q ) {
				$impr               = (int) ( $q['impressions'] ?? 0 );
				$total_impressions += $impr;
				$total_clicks      += (int) ( $q['clicks'] ?? 0 );
				$position_sum      += ( (float) ( $q['position'] ?? 99 ) ) * $impr;
			}

			$cluster['total_impressions'] = $total_impressions;
			$cluster['total_clicks']      = $total_clicks;
			$cluster['avg_position']      = $total_impressions > 0
				? round( $position_sum / $total_impressions, 1 )
				: 99.0;
			$cluster['avg_ctr']           = $total_impressions > 0
				? round( $total_clicks / $total_impressions, 4 )
				: 0.0;
			$cluster['query_count']       = count( $cluster['queries'] );

			unset( $cluster['label_words'] ); // Not needed in output.
		}
		unset( $cluster );

		return $clusters;
	}

	private function find_opportunities( array $clusters ) {
		$opps = array();
		foreach ( $clusters as $cluster ) {
			if ( $cluster['total_impressions'] < self::MIN_OPPORTUNITY_IMPRESSIONS ) {
				continue;
			}
			$pos = $cluster['avg_position'];
			if ( $pos >= 4 && $pos <= 20 ) {
				$opps[] = $cluster;
			}
		}
		return $opps;
	}

	// -------------------------------------------------------------------
	// Similarity / tokenization
	// -------------------------------------------------------------------

	private function tokenize( $text ) {
		$stopwords = array( 'a', 'an', 'the', 'is', 'in', 'on', 'at', 'to', 'of', 'and', 'or', 'for', 'with', 'how', 'what', 'why', 'when', 'does', 'do' );
		$words     = preg_split( '/\s+/', strtolower( trim( $text ) ) );
		return array_values( array_filter( $words, fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, $stopwords, true ) ) );
	}

	/**
	 * Jaccard similarity between two token sets.
	 */
	private function similarity( array $a, array $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$intersection = count( array_intersect( $a, $b ) );
		$union        = count( array_unique( array_merge( $a, $b ) ) );
		return $union > 0 ? $intersection / $union : 0.0;
	}

	// -------------------------------------------------------------------
	// CTR / cannibalization helpers
	// -------------------------------------------------------------------

	/**
	 * Expected CTR by position (approximate industry averages).
	 */
	private function expected_ctr( $position ) {
		$map = array(
			1  => 0.32, 2  => 0.18, 3  => 0.11,
			4  => 0.08, 5  => 0.06, 6  => 0.05,
			7  => 0.04, 8  => 0.035, 9  => 0.03,
			10 => 0.025,
		);
		$pos = (int) round( $position );
		if ( isset( $map[ $pos ] ) ) {
			return $map[ $pos ];
		}
		if ( $pos <= 20 ) {
			return 0.015;
		}
		return 0.005;
	}

	private function cannibalization_recommendation( array $pages ) {
		if ( count( $pages ) < 2 ) {
			return '';
		}
		$winner = $pages[0];
		$losers = array_slice( $pages, 1 );

		$loser_ids = implode( ', ', array_column( $losers, 'page' ) );
		return sprintf(
			'Consolidate keyword onto page "%s". Consider canonical tags or noindex for: %s.',
			$winner['page'],
			$loser_ids
		);
	}
}
