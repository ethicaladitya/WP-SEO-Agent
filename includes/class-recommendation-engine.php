<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Recommendation_Engine {

	/** @var SEO_Agent_AI_Gemini_Client|null */
	private $gemini;

	/** @var SEO_Agent_AI_OpenAI_Client|null */
	private $openai;

	/** @var SEO_Agent_AI_Decision_Engine|null */
	private $decision_engine;

	public function __construct(
		SEO_Agent_AI_Gemini_Client $gemini = null,
		SEO_Agent_AI_OpenAI_Client $openai = null,
		SEO_Agent_AI_Decision_Engine $decision_engine = null
	) {
		$this->gemini          = $gemini;
		$this->openai          = $openai;
		$this->decision_engine = $decision_engine;
	}

	/**
	 * Generate an array of actionable recommendations for a post.
	 *
	 * @param WP_Post $post
	 * @param array   $analysis    Result of SEO_Analyzer::analyze().
	 * @param array   $gsc         GSC metrics.
	 * @param array   $ga4         GA4 metrics.
	 * @param array   $seo_audit   On-page SEO audit from SEO_Plugin_Bridge::audit_post().
	 * @param float   $autopilot_threshold Confidence threshold for auto-apply routing.
	 * @param bool    $dry_run     If true, skip decision engine DB writes.
	 * @return array
	 */
	public function generate(
		WP_Post $post,
		array $analysis,
		array $gsc,
		array $ga4,
		array $seo_audit = array(),
		$autopilot_threshold = 0.70,
		$dry_run = false
	) {
		$signals    = isset( $analysis['signals'] ) ? $analysis['signals'] : array();
		$confidence  = isset( $analysis['confidence'] ) ? (float) $analysis['confidence'] : 0.5;
		$content     = isset( $analysis['content_data'] ) ? $analysis['content_data'] : array();
		$top_query   = $this->extract_top_query( $gsc );
		$impressions = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;

		$recommendations = array();

		// ------------------------------------------------------------------
		// 1. Missing meta basics (highest priority — fires even with no traffic)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['missing_meta_basics'] ) ) {
			$has_title = isset( $seo_audit['has_title'] ) ? (bool) $seo_audit['has_title'] : false;
			$has_desc  = isset( $seo_audit['has_description'] ) ? (bool) $seo_audit['has_description'] : false;

			$missing_parts = array();
			if ( ! $has_title ) {
				$missing_parts[] = __( 'meta title', 'seo-agent-ai' );
			}
			if ( ! $has_desc ) {
				$missing_parts[] = __( 'meta description', 'seo-agent-ai' );
			}

			$recommendations[] = array(
				'type'            => 'meta_update',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => max( $confidence, 0.85 ),
				'expected_impact' => 'High — adds missing fundamental SEO elements.',
				'reason'          => sprintf(
					/* translators: %s: comma-separated list of missing SEO meta fields. */
					__( 'No %s found in any active SEO plugin. These are the most fundamental SEO elements — without them, Google writes its own snippets.', 'seo-agent-ai' ),
					implode( ' / ', $missing_parts )
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
					'focus_keyword'    => $this->build_focus_keyword( $post, $gsc ),
				),
			);
		} elseif ( ! empty( $signals['title_meta_optimization'] ) ) {
			// ------------------------------------------------------------------
			// 2. Title/meta optimization
			// ------------------------------------------------------------------

			$impressions = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;
			$ctr         = isset( $gsc['ctr_avg'] ) ? round( (float) $gsc['ctr_avg'] * 100, 1 ) : 0;
			$position    = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;

			$title_issue = '';
			$title_len   = isset( $seo_audit['title_length'] ) ? (int) $seo_audit['title_length'] : 0;
			if ( ! empty( $seo_audit['title_too_long'] ) ) {
				$title_issue = ' ' . sprintf(
					/* translators: %d: title length in characters. */
					__( 'Current title (%d chars) exceeds the 60-char display limit.', 'seo-agent-ai' ),
					$title_len
				);
			} elseif ( ! empty( $seo_audit['title_too_short'] ) ) {
				$title_issue = ' ' . sprintf(
					/* translators: %d: title length in characters. */
					__( 'Current title (%d chars) is too short.', 'seo-agent-ai' ),
					$title_len
				);
			}

			$traffic_context = '';
			if ( $impressions > 0 ) {
				$traffic_context = ' ' . sprintf(
					/* translators: 1: impressions count, 2: average position, 3: CTR percent. */
					__( 'With %1$s impressions at position %2$.1f, a CTR of %3$.1f%% is below expectation.', 'seo-agent-ai' ),
					number_format_i18n( $impressions ),
					$position,
					$ctr
				);
			}

			$recommendations[] = array(
				'type'            => 'meta_update',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => $confidence,
				'expected_impact' => 'High — improved snippet can lift CTR by 1-3%.',
				'reason'          => __( 'SEO snippet optimization opportunity.', 'seo-agent-ai' ) . $title_issue . $traffic_context,
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
					'focus_keyword'    => $this->build_focus_keyword( $post, $gsc ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 3. Thin content
		// ------------------------------------------------------------------

		if ( ! empty( $signals['thin_content'] ) ) {
			$word_count = isset( $seo_audit['word_count'] ) ? (int) $seo_audit['word_count'] : ( $content['word_count'] ?? 0 );
			$recommendations[] = array(
				'type'            => 'content_expansion',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => max( $confidence, 0.80 ),
				'expected_impact' => 'Medium — more content enables ranking for long-tail keywords.',
				'reason'          => sprintf(
					/* translators: %d: post word count. */
					__( 'This post has only %d words. Google consistently favours comprehensive content (600+ words).', 'seo-agent-ai' ),
					$word_count
				),
				'proposed'        => array(
					'summary' => sprintf(
						/* translators: %s: target search query or post title. */
						__( 'Expand content to at least 600 words. Add an FAQ block for "%s", real examples, and a clear summary.', 'seo-agent-ai' ),
						$top_query !== '' ? $top_query : $post->post_title
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// 4. Content refresh
		// ------------------------------------------------------------------

		if ( ! empty( $signals['content_refresh_needed'] ) ) {
			$position = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$eng_pct  = isset( $ga4['engagement_rate'] ) ? round( (float) $ga4['engagement_rate'] * 100, 0 ) : 0;

			$recommendations[] = array(
				'type'            => 'content_refresh_plan',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => $confidence,
				'expected_impact' => 'High — refreshed content can push rankings from page 2 to top 5.',
				'reason'          => sprintf(
					/* translators: 1: average search position, 2: engagement rate percent. */
					__( 'Page ranks at position %1$.1f but engagement rate is only %2$d%%. Content refresh needed.', 'seo-agent-ai' ),
					$position,
					$eng_pct
				),
				'proposed'        => array(
					'summary' => sprintf(
						/* translators: %s: target search query or post title. */
						__( 'Audit against top 3 competitors for "%s". Add missing sections, update statistics, improve introduction, add FAQ.', 'seo-agent-ai' ),
						$top_query !== '' ? $top_query : $post->post_title
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// 5. Intent mismatch
		// ------------------------------------------------------------------

		if ( ! empty( $signals['intent_mismatch'] ) ) {
			$position = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$time_sec = isset( $ga4['avg_time_on_page_sec'] ) ? (int) $ga4['avg_time_on_page_sec'] : 0;

			$recommendations[] = array(
				'type'            => 'intent_alignment',
				'risk'            => 'risky',
				'priority'        => 'high',
				'confidence'      => $confidence,
				'expected_impact' => 'High — fixing intent mismatch reduces pogo-sticking and improves rankings.',
				'reason'          => sprintf(
					/* translators: 1: average search position, 2: average time on page in seconds. */
					__( 'Strong ranking at position %1$.1f, but users spend only %2$ds — clear intent mismatch. Rewrite introduction to answer primary search intent within first 100 words.', 'seo-agent-ai' ),
					$position,
					$time_sec
				),
				'proposed'        => array(
					'summary' => sprintf(
						/* translators: %s: target search query or post title. */
						__( 'Revise H1, introduction, and meta snippet to directly answer "%s" within first visible paragraph.', 'seo-agent-ai' ),
						$top_query !== '' ? $top_query : $post->post_title
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// 6. Declining performance
		// ------------------------------------------------------------------

		if ( ! empty( $signals['declining_performance'] ) ) {
			$impr_trend = isset( $gsc['impressions_trend_28d'] ) ? round( (float) $gsc['impressions_trend_28d'], 1 ) : 0;
			$sess_trend = isset( $ga4['sessions_trend_28d'] ) ? round( (float) $ga4['sessions_trend_28d'], 1 ) : 0;

			$trend_str = $impr_trend < -10
				? sprintf( '%+.1f%% impressions', $impr_trend )
				: sprintf( '%+.1f%% sessions', $sess_trend );

			$recommendations[] = array(
				'type'            => 'monitor_decline',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => $confidence,
				'expected_impact' => 'Medium — meta refresh is a quick win while investigating root cause.',
				'reason'          => sprintf(
					/* translators: %s: trend descriptor like "-15.2% impressions". */
					__( 'Performance has dropped %s over 28 days. Refreshing meta is a quick, low-risk first step.', 'seo-agent-ai' ),
					$trend_str
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 7. Page 2 opportunity (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['page_two_opportunity'] ) ) {
			$position    = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$impressions = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;

			$recommendations[] = array(
				'type'            => 'page_two_push',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => max( $confidence, 0.72 ),
				'expected_impact' => 'Very High — moving from page 2 to page 1 can increase clicks 3-5x.',
				'reason'          => sprintf(
					/* translators: 1: position, 2: impressions. */
					__( 'Ranking at position %1$.1f with %2$d impressions — this is a page-2 keyword just one push away from first-page visibility.', 'seo-agent-ai' ),
					$position,
					$impressions
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
					'focus_keyword'    => $top_query,
					'summary'          => sprintf(
						/* translators: %s: target search query. */
						__( 'Strengthen the keyword signal for "%s": update title tag, add the keyword to the first paragraph and at least one H2, add internal links pointing to this page.', 'seo-agent-ai' ),
						$top_query !== '' ? $top_query : $post->post_title
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// 8. CTR anomaly (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['ctr_anomaly'] ) && empty( $signals['title_ctr_mismatch'] ) ) {
			$position = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$ctr_pct  = isset( $gsc['ctr_avg'] ) ? round( (float) $gsc['ctr_avg'] * 100, 1 ) : 0;

			$recommendations[] = array(
				'type'            => 'meta_update',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => max( $confidence, 0.70 ),
				'expected_impact' => 'High — CTR improvement at this position can significantly increase organic traffic.',
				'reason'          => sprintf(
					/* translators: 1: actual CTR%, 2: position. */
					__( 'CTR of %1$.1f%% at position %2$.1f is significantly below the industry average for this position. The snippet is not compelling enough.', 'seo-agent-ai' ),
					$ctr_pct,
					$position
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 9. Title/CTR mismatch (new) — top-5 position, poor CTR
		// ------------------------------------------------------------------

		if ( ! empty( $signals['title_ctr_mismatch'] ) ) {
			$position = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$ctr_pct  = isset( $gsc['ctr_avg'] ) ? round( (float) $gsc['ctr_avg'] * 100, 1 ) : 0;

			$recommendations[] = array(
				'type'            => 'meta_update',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => max( $confidence, 0.80 ),
				'expected_impact' => 'Very High — top-5 position means even a small CTR lift = major traffic gain.',
				'reason'          => sprintf(
					/* translators: 1: position, 2: CTR%. */
					__( 'Top-5 ranking (position %1$.1f) but CTR is only %2$.1f%% — well below what this position should yield. A more compelling title and description can double your clicks without any ranking change.', 'seo-agent-ai' ),
					$position,
					$ctr_pct
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 10. Cannibalization risk (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['cannibalization_risk'] ) ) {
			$recommendations[] = array(
				'type'            => 'cannibalization_fix',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => max( $confidence, 0.65 ),
				'expected_impact' => 'High — consolidating cannibalizing pages focuses link equity on the winner.',
				'reason'          => sprintf(
					/* translators: %s: post title. */
					__( 'Multiple pages are competing for the same keywords as "%s". This splits link equity and confuses Google about which page to rank.', 'seo-agent-ai' ),
					$post->post_title
				),
				'proposed'        => array(
					'summary' => __( 'Audit which page has stronger backlinks and engagement. Set canonical tags on weaker pages, or consolidate content into the stronger page via 301 redirect.', 'seo-agent-ai' ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 11. Content decay (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['content_decay'] ) ) {
			$freshness = $content['freshness_score'] ?? 50;

			$recommendations[] = array(
				'type'            => 'content_refresh_plan',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => max( $confidence, 0.70 ),
				'expected_impact' => 'Medium — fresh content signals can stop ranking decay.',
				'reason'          => sprintf(
					/* translators: %d: freshness score. */
					__( 'Content freshness score is %d/100 — outdated years and statistics detected. Stale content loses rankings as competitors publish fresher information.', 'seo-agent-ai' ),
					$freshness
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
					'summary'          => __( 'Update statistics, replace old years with current data, add a "Last updated" date, and refresh the meta description to include the current year.', 'seo-agent-ai' ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 12. Orphan page (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['orphan_page'] ) ) {
			$recommendations[] = array(
				'type'            => 'internal_link_needed',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => max( $confidence, 0.75 ),
				'expected_impact' => 'Medium — internal links pass authority and help Google discover the page.',
				'reason'          => sprintf(
					/* translators: %s: post title. */
					__( '"%s" has no internal links pointing to it. Orphan pages receive no PageRank from internal linking and are harder for Google to discover and value.', 'seo-agent-ai' ),
					$post->post_title
				),
				'proposed'        => array(
					'summary' => __( 'Find 3-5 related posts and add contextual links to this page using keyword-rich anchor text matching the target query.', 'seo-agent-ai' ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 13. Missing schema (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['missing_schema'] ) ) {
			$recommendations[] = array(
				'type'            => 'schema_update',
				'risk'            => 'safe',
				'priority'        => 'medium',
				'confidence'      => max( $confidence, 0.80 ),
				'expected_impact' => 'Medium — schema markup enables rich results in Google Search.',
				'reason'          => sprintf(
					/* translators: %s: post title. */
					__( '"%s" has no JSON-LD structured data. Schema markup enables rich results like FAQ dropdowns, breadcrumbs, and article metadata that improve CTR.', 'seo-agent-ai' ),
					$post->post_title
				),
				'proposed'        => array(
					'summary' => __( 'Add Article (BlogPosting) schema, BreadcrumbList, and — if the post has an FAQ section — FAQPage schema. The plugin schema engine can auto-inject these via wp_head.', 'seo-agent-ai' ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 14. Weak engagement (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['weak_engagement'] ) ) {
			$engagement_pct = isset( $ga4['engagement_rate'] ) ? round( (float) $ga4['engagement_rate'] * 100, 0 ) : 0;
			$time_sec       = isset( $ga4['avg_time_on_page_sec'] ) ? (int) $ga4['avg_time_on_page_sec'] : 0;

			$recommendations[] = array(
				'type'            => 'intent_alignment',
				'risk'            => 'risky',
				'priority'        => 'medium',
				'confidence'      => $confidence,
				'expected_impact' => 'High — improved engagement directly signals quality to Google.',
				'reason'          => sprintf(
					/* translators: 1: engagement %, 2: time on page seconds. */
					__( 'Engagement rate of %1$d%% and average time on page of %2$ds are both critically low. Users are not finding value in this content.', 'seo-agent-ai' ),
					$engagement_pct,
					$time_sec
				),
				'proposed'        => array(
					'summary' => __( 'Rewrite the introduction to deliver immediate value. Add visuals, a TL;DR summary, and improve internal navigation (jump links, table of contents).', 'seo-agent-ai' ),
				),
			);
		}

		// ------------------------------------------------------------------
		// 15. Missing FAQ (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['missing_faq'] ) && $impressions >= 50 ) {
			$recommendations[] = array(
				'type'            => 'content_expansion',
				'risk'            => 'risky',
				'priority'        => 'low',
				'confidence'      => $confidence,
				'expected_impact' => 'Medium — FAQ sections can earn People Also Ask rich results.',
				'reason'          => sprintf(
					/* translators: %s: post title. */
					__( '"%s" has no FAQ section. Pages ranking for question-based queries often earn People Also Ask (PAA) slots, significantly increasing CTR.', 'seo-agent-ai' ),
					$post->post_title
				),
				'proposed'        => array(
					'summary' => sprintf(
						/* translators: %s: target search query. */
						__( 'Add an FAQ section at the bottom of the post answering 4-6 common questions around "%s". Format with H3 headings and concise paragraph answers.', 'seo-agent-ai' ),
						$top_query !== '' ? $top_query : $post->post_title
					),
				),
			);
		}

		// ------------------------------------------------------------------
		// 16. Index anomaly (new)
		// ------------------------------------------------------------------

		if ( ! empty( $signals['index_anomaly'] ) ) {
			$position    = isset( $gsc['position_avg'] ) ? round( (float) $gsc['position_avg'], 1 ) : 0;
			$impressions = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;

			$recommendations[] = array(
				'type'            => 'monitor_decline',
				'risk'            => 'safe',
				'priority'        => 'high',
				'confidence'      => max( $confidence, 0.65 ),
				'expected_impact' => 'High — investigating indexing issues can unlock significant ranking recovery.',
				'reason'          => sprintf(
					/* translators: 1: impressions, 2: position. */
					__( '%1$d impressions at position %2$.1f suggests Google is aware of this page but not ranking it well. Possible causes: thin content, duplicate content, or a technical SEO issue.', 'seo-agent-ai' ),
					$impressions,
					$position
				),
				'proposed'        => array(
					'meta_title'       => $this->build_meta_title( $post, $top_query ),
					'meta_description' => $this->build_meta_description( $post, $top_query ),
					'summary'          => __( 'Check Google Search Console Coverage report. Verify canonical tags are correct, check for duplicate content, and ensure the page passes Core Web Vitals.', 'seo-agent-ai' ),
				),
			);
		}

		$recommendations = $this->dedupe_recommendations( $recommendations );

		// ------------------------------------------------------------------
		// Route through decision engine (if available)
		// ------------------------------------------------------------------

		if ( $this->decision_engine instanceof SEO_Agent_AI_Decision_Engine ) {
			foreach ( $recommendations as &$rec ) {
				$decision = $this->decision_engine->process(
					$post->ID,
					$rec,
					$autopilot_threshold,
					$dry_run
				);
				$rec['decision_tier']    = $decision['tier'];
				$rec['decision_id']      = $decision['decision_id'];
			}
			unset( $rec );
		}

		return $recommendations;
	}

	// -------------------------------------------------------------------
	// Meta generation (provider routing: Gemini / OpenAI / rule-based)
	// -------------------------------------------------------------------

	private function build_meta_title( WP_Post $post, $top_query ) {
		$generated = $this->ai_generate( 'generate_meta_title', $post, $top_query );
		if ( $generated !== null ) {
			return $generated;
		}

		$base      = trim( (string) $post->post_title );
		$query     = ucwords( (string) $top_query );
		$candidate = $query !== '' ? ( $query . ' — ' . $base ) : $base;
		return $this->truncate( wp_strip_all_tags( $candidate ), 60 );
	}

	private function build_meta_description( WP_Post $post, $top_query ) {
		$generated = $this->ai_generate( 'generate_meta_description', $post, $top_query );
		if ( $generated !== null ) {
			return $generated;
		}

		$excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
		if ( $excerpt === '' ) {
			$excerpt = trim( wp_strip_all_tags( wp_trim_words( $post->post_content, 30, '' ) ) );
		}

		$prefix = $top_query !== '' ? ( 'Learn about ' . strtolower( $top_query ) . '. ' ) : '';
		return $this->truncate( $prefix . $excerpt, 155 );
	}

	private function build_focus_keyword( WP_Post $post, array $gsc ) {
		$queries   = isset( $gsc['queries'] ) && is_array( $gsc['queries'] ) ? $gsc['queries'] : array();
		$generated = $this->ai_generate( 'suggest_focus_keyword', $post, $queries );
		if ( $generated !== null ) {
			return $generated;
		}
		return $this->extract_top_query( $gsc );
	}

	/**
	 * Route an AI generation call through the configured provider.
	 *
	 * Provider priority (from seo_agent_ai_ai_provider setting):
	 *   gemini  → Gemini only
	 *   openai  → OpenAI only
	 *   auto    → try Gemini, fall back to OpenAI, fall back to rule-based
	 *
	 * @param string  $method  Method name on the AI client.
	 * @param WP_Post $post
	 * @param mixed   $arg     Second argument passed to the method (string or array).
	 * @return string|null  Generated value, or null to signal fall-through to rule-based.
	 */
	private function ai_generate( $method, WP_Post $post, $arg ) {
		$provider = (string) get_option( 'seo_agent_ai_ai_provider', 'gemini' );

		if ( $provider === 'openai' ) {
			return $this->try_client( $this->openai, $method, $post, $arg );
		}

		if ( $provider === 'gemini' ) {
			return $this->try_client( $this->gemini, $method, $post, $arg );
		}

		// 'auto': try Gemini first, then OpenAI.
		$result = $this->try_client( $this->gemini, $method, $post, $arg );
		if ( $result !== null ) {
			return $result;
		}
		return $this->try_client( $this->openai, $method, $post, $arg );
	}

	/**
	 * Call a method on a client instance safely.
	 *
	 * @param object|null $client
	 * @param string      $method
	 * @param WP_Post     $post
	 * @param mixed       $arg
	 * @return string|null
	 */
	private function try_client( $client, $method, WP_Post $post, $arg ) {
		if ( $client === null ) {
			return null;
		}
		if ( ! method_exists( $client, 'is_configured' ) || ! $client->is_configured() ) {
			return null;
		}
		if ( ! method_exists( $client, $method ) ) {
			return null;
		}
		return $client->$method( $post, $arg );
	}

	private function extract_top_query( array $gsc ) {
		if ( empty( $gsc['queries'] ) || ! is_array( $gsc['queries'] ) ) {
			return '';
		}

		usort( $gsc['queries'], fn( $a, $b ) =>
			( (int) ( $b['impressions'] ?? 0 ) ) - ( (int) ( $a['impressions'] ?? 0 ) )
		);

		return sanitize_text_field( (string) ( $gsc['queries'][0]['query'] ?? '' ) );
	}

	private function truncate( $text, $max_len ) {
		$text   = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$len_fn = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		$sub_fn = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
		if ( $len_fn( $text ) <= $max_len ) {
			return $text;
		}
		return rtrim( $sub_fn( $text, 0, $max_len - 1 ) ) . '…';
	}

	private function dedupe_recommendations( array $recommendations ) {
		$seen    = array();
		$deduped = array();

		foreach ( $recommendations as $rec ) {
			$hash = md5( (string) wp_json_encode( array( $rec['type'], $rec['priority'] ) ) );
			if ( ! isset( $seen[ $hash ] ) ) {
				$seen[ $hash ] = true;
				$deduped[]     = $rec;
			}
		}

		return $deduped;
	}
}
