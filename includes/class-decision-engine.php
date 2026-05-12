<?php
/**
 * AI Decision Engine.
 *
 * Routes recommendations through a three-tier confidence pipeline:
 *
 *   High confidence (≥ autopilot threshold, default 0.70):
 *     → Auto-apply if autopilot is enabled and risk is 'safe'.
 *
 *   Medium confidence (≥ 0.50):
 *     → Queue to ai_decisions table with status 'pending' for admin approval.
 *
 *   Low confidence (< 0.50):
 *     → Log to ai_decisions table with status 'discarded'.
 *
 * Every decision is recorded, giving full audit trail regardless of tier.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Decision_Engine {

	const MEDIUM_CONFIDENCE_THRESHOLD = 0.50;

	// -------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------

	/**
	 * Process a recommendation through the confidence pipeline.
	 *
	 * @param int   $post_id
	 * @param array $recommendation  Full recommendation array from recommendation engine.
	 * @param float $autopilot_threshold Minimum confidence for auto-apply (from settings).
	 * @param bool  $dry_run         If true, classify but do not write to DB.
	 * @return array {
	 *   string $tier         'auto_apply' | 'pending_approval' | 'discarded'
	 *   int    $decision_id  ID of inserted ai_decisions row (0 in dry-run).
	 *   float  $confidence
	 *   string $risk_level
	 * }
	 */
	public function process( $post_id, array $recommendation, $autopilot_threshold = 0.70, $dry_run = false ) {
		$confidence = (float) ( $recommendation['confidence'] ?? 0.0 );
		$risk       = (string) ( $recommendation['risk'] ?? 'risky' );
		$type       = (string) ( $recommendation['type'] ?? '' );
		$proposed   = $recommendation['proposed'] ?? array();
		$reason     = (string) ( $recommendation['reason'] ?? '' );

		$tier         = $this->classify( $confidence, $risk, $autopilot_threshold );
		$decision_id  = 0;

		if ( ! $dry_run ) {
			$decision_id = $this->record( $post_id, $recommendation, $tier );
		}

		return array(
			'tier'        => $tier,
			'decision_id' => $decision_id,
			'confidence'  => $confidence,
			'risk_level'  => $risk,
			'type'        => $type,
		);
	}

	/**
	 * Process multiple recommendations for a post.
	 *
	 * @param int   $post_id
	 * @param array $recommendations
	 * @param float $autopilot_threshold
	 * @param bool  $dry_run
	 * @return array Array of decision results, keyed by recommendation index.
	 */
	public function process_batch( $post_id, array $recommendations, $autopilot_threshold = 0.70, $dry_run = false ) {
		$results = array();
		foreach ( $recommendations as $idx => $rec ) {
			$results[ $idx ] = $this->process( $post_id, $rec, $autopilot_threshold, $dry_run );
		}
		return $results;
	}

	/**
	 * Approve a pending decision (admin action).
	 *
	 * @param int $decision_id
	 * @param int $user_id     WordPress user ID performing the approval.
	 * @return bool
	 */
	public function approve( $decision_id, $user_id = 0 ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		SEO_Agent_AI_DB_Manager::update_decision_status(
			$decision_id,
			SEO_Agent_AI_DB_Manager::STATUS_APPROVED,
			$user_id ?: get_current_user_id()
		);
		return true;
	}

	/**
	 * Reject a pending decision (admin action).
	 *
	 * @param int $decision_id
	 * @param int $user_id
	 * @return bool
	 */
	public function reject( $decision_id, $user_id = 0 ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		SEO_Agent_AI_DB_Manager::update_decision_status(
			$decision_id,
			SEO_Agent_AI_DB_Manager::STATUS_REJECTED,
			$user_id ?: get_current_user_id()
		);
		return true;
	}

	/**
	 * Mark a decision as applied (called after fix executor successfully applies it).
	 *
	 * @param int $decision_id
	 */
	public function mark_applied( $decision_id ) {
		SEO_Agent_AI_DB_Manager::update_decision_status( $decision_id, SEO_Agent_AI_DB_Manager::STATUS_APPLIED );
	}

	/**
	 * Get pending decisions for admin review.
	 *
	 * @param array $args See SEO_Agent_AI_DB_Manager::get_decisions() for args.
	 * @return array
	 */
	public function get_pending( array $args = array() ) {
		$args['status'] = SEO_Agent_AI_DB_Manager::STATUS_PENDING;
		return SEO_Agent_AI_DB_Manager::get_decisions( $args );
	}

	/**
	 * Count pending approvals (for dashboard widget).
	 *
	 * @return int
	 */
	public function count_pending() {
		return SEO_Agent_AI_DB_Manager::count_decisions( SEO_Agent_AI_DB_Manager::STATUS_PENDING );
	}

	// -------------------------------------------------------------------
	// Classification
	// -------------------------------------------------------------------

	/**
	 * Classify a recommendation into a tier.
	 *
	 * @param float  $confidence
	 * @param string $risk
	 * @param float  $autopilot_threshold
	 * @return string 'auto_apply' | 'pending_approval' | 'discarded'
	 */
	public function classify( $confidence, $risk, $autopilot_threshold = 0.70 ) {
		if ( $confidence >= $autopilot_threshold && $risk === 'safe' ) {
			return 'auto_apply';
		}

		if ( $confidence >= self::MEDIUM_CONFIDENCE_THRESHOLD ) {
			return 'pending_approval';
		}

		return 'discarded';
	}

	// -------------------------------------------------------------------
	// Recording
	// -------------------------------------------------------------------

	private function record( $post_id, array $recommendation, $tier ) {
		$type       = (string) ( $recommendation['type'] ?? '' );
		$proposed   = $recommendation['proposed'] ?? array();
		$confidence = (float) ( $recommendation['confidence'] ?? 0.0 );

		// Determine field and proposed/current values for display.
		$field          = '';
		$proposed_value = '';
		$current_value  = '';

		if ( isset( $proposed['meta_title'] ) ) {
			$field          = 'meta_title';
			$proposed_value = (string) $proposed['meta_title'];
			$current_value  = (string) get_post_meta( $post_id, '_seo_agent_ai_meta_title', true );
		} elseif ( isset( $proposed['meta_description'] ) ) {
			$field          = 'meta_description';
			$proposed_value = (string) $proposed['meta_description'];
		} elseif ( isset( $proposed['summary'] ) ) {
			$field          = 'content_suggestion';
			$proposed_value = (string) $proposed['summary'];
		}

		$status_map = array(
			'auto_apply'       => SEO_Agent_AI_DB_Manager::STATUS_PENDING, // Will be updated to 'applied' after apply.
			'pending_approval' => SEO_Agent_AI_DB_Manager::STATUS_PENDING,
			'discarded'        => SEO_Agent_AI_DB_Manager::STATUS_DISCARDED,
		);

		$id = SEO_Agent_AI_DB_Manager::insert_decision( array(
			'post_id'         => $post_id,
			'decision_type'   => $type,
			'field'           => $field,
			'proposed_value'  => $proposed_value,
			'current_value'   => $current_value,
			'confidence'      => $confidence,
			'reasoning'       => $recommendation['reason'] ?? '',
			'expected_impact' => $recommendation['expected_impact'] ?? $this->infer_impact( $tier, $confidence ),
			'risk_level'      => $recommendation['risk'] ?? 'safe',
			'status'          => $status_map[ $tier ] ?? SEO_Agent_AI_DB_Manager::STATUS_PENDING,
		) );

		return (int) $id;
	}

	private function infer_impact( $tier, $confidence ) {
		if ( $tier === 'auto_apply' ) {
			return 'Expected positive impact on CTR and visibility.';
		}
		if ( $tier === 'pending_approval' ) {
			return sprintf( 'Moderate confidence (%d%%) — review before applying.', (int) ( $confidence * 100 ) );
		}
		return 'Low confidence — informational only.';
	}
}
