<?php
/**
 * Fix executor — applies safe recommendations to posts with backup & rollback.
 *
 * Supports dry-run mode: when $dry_run is true, all validation logic runs but
 * no post meta or activity log writes happen. WP-CLI uses this to preview actions.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Fix_Executor {

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	/** @var SEO_Agent_AI_SEO_Plugin_Bridge */
	private $bridge;

	public function __construct( SEO_Agent_AI_Activity_Log $activity_log, SEO_Agent_AI_SEO_Plugin_Bridge $bridge ) {
		$this->activity_log = $activity_log;
		$this->bridge       = $bridge;
	}

	/**
	 * Apply a safe recommendation to a post.
	 *
	 * @param int    $post_id        Target post ID.
	 * @param array  $recommendation Recommendation payload.
	 * @param string $triggered_by   'manual' | 'autopilot' — controls cap check.
	 * @param array  $signal_data    Analyzer evidence for activity log.
	 * @param bool   $dry_run        If true, validate but do not write anything.
	 * @return true|WP_Error
	 */
	public function apply( $post_id, array $recommendation, $triggered_by = 'manual', array $signal_data = array(), $dry_run = false ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return new WP_Error( 'seo_agent_ai_invalid_post', __( 'Invalid or non-published post target.', 'seo-agent-ai' ) );
		}

		// Only gate on user capability for manual requests; cron/autopilot runs
		// as a scheduled background task with no user context.
		if ( $triggered_by === 'manual' && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'seo_agent_ai_forbidden', __( 'You are not allowed to edit this post.', 'seo-agent-ai' ) );
		}

		$type       = isset( $recommendation['type'] ) ? (string) $recommendation['type'] : '';
		$risk       = isset( $recommendation['risk'] ) ? (string) $recommendation['risk'] : 'risky';
		$proposed   = isset( $recommendation['proposed'] ) && is_array( $recommendation['proposed'] ) ? $recommendation['proposed'] : array();
		$reason     = isset( $recommendation['reason'] ) ? (string) $recommendation['reason'] : '';
		$confidence = isset( $recommendation['confidence'] ) ? (float) $recommendation['confidence'] : 0.0;

		// When autopilot is fully enabled, allow risky recommendations too (agent mode).
		if ( $risk !== 'safe' && ! (bool) get_option( 'seo_agent_ai_autopilot_enabled', false ) ) {
			return new WP_Error( 'seo_agent_ai_risky_recommendation', __( 'Only safe recommendations can be auto-applied.', 'seo-agent-ai' ) );
		}

		if ( ! in_array( $type, array( 'meta_update', 'monitor_decline', 'schema_update' ), true ) ) {
			return new WP_Error( 'seo_agent_ai_unsupported_recommendation', __( 'Recommendation type is not supported for auto-apply.', 'seo-agent-ai' ) );
		}

		$new_title       = isset( $proposed['meta_title'] ) ? sanitize_text_field( $proposed['meta_title'] ) : '';
		$new_description = isset( $proposed['meta_description'] ) ? sanitize_textarea_field( $proposed['meta_description'] ) : '';

		if ( $new_title === '' && $new_description === '' ) {
			return new WP_Error( 'seo_agent_ai_empty_payload', __( 'No safe metadata payload found.', 'seo-agent-ai' ) );
		}

		// In dry-run mode: validation passes, but no writes occur.
		if ( $dry_run ) {
			return true;
		}

		$this->backup_meta( $post_id );

		$changed = false;

		if ( $new_title !== '' ) {
			$bounded_title = $this->bounded_value( $new_title, 60 );
			$prev_title    = $this->bridge->get_meta_title( $post_id );

			if ( $bounded_title !== $prev_title ) {
				$this->bridge->set_meta_title( $post_id, $bounded_title );
				$this->activity_log->log(
					$post_id,
					$type,
					'meta_title',
					$prev_title,
					$bounded_title,
					$reason,
					$signal_data,
					$confidence,
					$triggered_by
				);
				$changed = true;
			}
		}

		if ( $new_description !== '' ) {
			$bounded_desc = $this->bounded_value( $new_description, 155 );
			$prev_desc    = $this->bridge->get_meta_description( $post_id );

			if ( $bounded_desc !== $prev_desc ) {
				$this->bridge->set_meta_description( $post_id, $bounded_desc );
				$this->activity_log->log(
					$post_id,
					$type,
					'meta_description',
					$prev_desc,
					$bounded_desc,
					$reason,
					$signal_data,
					$confidence,
					$triggered_by
				);
				$changed = true;
			}
		}

		// Nothing actually changed — bail without updating the timestamp.
		if ( ! $changed && ! isset( $proposed['focus_keyword'] ) ) {
			return new WP_Error( 'seo_agent_ai_no_change', __( 'Proposed values are identical to current values — no change applied.', 'seo-agent-ai' ) );
		}

		if ( isset( $proposed['focus_keyword'] ) && $proposed['focus_keyword'] !== '' ) {
			$this->bridge->set_focus_keyword( $post_id, sanitize_text_field( $proposed['focus_keyword'] ) );
		}

		update_post_meta( $post_id, '_seo_agent_ai_last_applied_at', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Preview what would be written without making changes.
	 *
	 * @param int   $post_id
	 * @param array $recommendation
	 * @return array|WP_Error Array with 'current' and 'proposed' keys, or WP_Error.
	 */
	public function preview( $post_id, array $recommendation ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seo_agent_ai_invalid_post', __( 'Invalid post.', 'seo-agent-ai' ) );
		}

		$proposed = isset( $recommendation['proposed'] ) && is_array( $recommendation['proposed'] ) ? $recommendation['proposed'] : array();

		return array(
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
			'current'    => array(
				'meta_title'       => $this->bridge->get_meta_title( $post_id ),
				'meta_description' => $this->bridge->get_meta_description( $post_id ),
			),
			'proposed'   => array(
				'meta_title'       => isset( $proposed['meta_title'] ) ? $this->bounded_value( sanitize_text_field( $proposed['meta_title'] ), 60 ) : '',
				'meta_description' => isset( $proposed['meta_description'] ) ? $this->bounded_value( sanitize_textarea_field( $proposed['meta_description'] ), 155 ) : '',
				'focus_keyword'    => $proposed['focus_keyword'] ?? '',
			),
			'confidence' => $recommendation['confidence'] ?? 0.0,
			'risk'       => $recommendation['risk'] ?? 'safe',
			'reason'     => $recommendation['reason'] ?? '',
		);
	}

	/**
	 * Restore the most recent backup for a post.
	 *
	 * @param int  $post_id
	 * @param bool $dry_run If true, return what would be restored without writing.
	 * @return true|array|WP_Error
	 */
	public function rollback( $post_id, $dry_run = false ) {
		$history = get_post_meta( $post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, true );
		if ( ! is_array( $history ) || empty( $history ) ) {
			return new WP_Error( 'seo_agent_ai_no_backup', __( 'No backup available for this post.', 'seo-agent-ai' ) );
		}

		$latest = end( $history );

		if ( $dry_run ) {
			return array(
				'would_restore' => $latest,
				'backup_count'  => count( $history ),
			);
		}

		foreach ( $latest as $meta_key => $value ) {
			if ( $meta_key === 'captured_at' ) {
				continue;
			}
			update_post_meta( $post_id, $meta_key, (string) $value );
		}

		array_pop( $history );
		update_post_meta( $post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, $history );

		return true;
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	private function backup_meta( $post_id ) {
		$history = get_post_meta( $post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$snap       = array( 'captured_at' => current_time( 'mysql' ) );
		$title_keys = $this->bridge->get_all_backup_keys( 'title' );
		$desc_keys  = $this->bridge->get_all_backup_keys( 'description' );

		foreach ( array_merge( $title_keys, $desc_keys ) as $key ) {
			$snap[ $key ] = (string) get_post_meta( $post_id, $key, true );
		}

		$history[] = $snap;

		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}

		update_post_meta( $post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, $history );
	}

	private function bounded_value( $value, $max_len ) {
		$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

		if ( $this->str_len( $value ) <= $max_len ) {
			return $value;
		}

		return rtrim( $this->str_sub( $value, 0, $max_len - 1 ) ) . '...';
	}

	private function str_len( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private function str_sub( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, $start, $length, 'UTF-8' ) : substr( $value, $start, $length );
	}
}
