<?php
/**
 * SEO Agent Activity Report admin page.
 *
 * Provides a filterable, paginated view of every change the agent has made,
 * including before/after diffs, the reasoning behind each change, and a
 * one-click rollback button per entry.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Report_Page {

	const PER_PAGE = 20;

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	/** @var SEO_Agent_AI_Data_Store */
	private $data_store;

	public function __construct(
		SEO_Agent_AI_Activity_Log $activity_log,
		SEO_Agent_AI_Data_Store $data_store
	) {
		$this->activity_log = $activity_log;
		$this->data_store   = $data_store;
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice   = filter_input( INPUT_GET, 'seo_agent_ai_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$notice   = is_string( $notice ) ? sanitize_key( wp_unslash( $notice ) ) : '';
		$filters  = $this->get_filters_from_request();
		$page     = max( 1, (int) filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT ) );
		$total    = $this->activity_log->get_count( $filters );
		$entries  = $this->activity_log->get_entries( $filters, $page, self::PER_PAGE );
		$pages    = (int) ceil( $total / self::PER_PAGE );

		// Summary stats (always without filters).
		$applied_count    = $this->activity_log->get_count( array( 'status' => SEO_Agent_AI_Activity_Log::STATUS_APPLIED ) );
		$rolled_back      = $this->activity_log->get_count( array( 'status' => SEO_Agent_AI_Activity_Log::STATUS_ROLLED_BACK ) );
		$autopilot_count  = $this->activity_log->get_count( array( 'triggered_by' => SEO_Agent_AI_Activity_Log::TRIGGER_AUTOPILOT ) );
		?>
		<div class="wrap seo-agent-wrap">
			<h1><?php esc_html_e( 'SEO Agent Activity Report', 'seo-agent-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A complete audit trail of changes made by the agent — what was changed, why, and the data behind each decision.', 'seo-agent-ai' ); ?>
			</p>

			<?php if ( 'rollback_done' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Change rolled back successfully.', 'seo-agent-ai' ); ?></p></div>
			<?php endif; ?>

			<!-- Summary stats -->
			<div class="seo-agent-stats-row">
				<div class="seo-agent-stat-box">
					<div class="stat-value"><?php echo esc_html( (string) $total ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Changes', 'seo-agent-ai' ); ?></div>
				</div>
				<div class="seo-agent-stat-box">
					<div class="stat-value"><?php echo esc_html( (string) $applied_count ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Active', 'seo-agent-ai' ); ?></div>
				</div>
				<div class="seo-agent-stat-box">
					<div class="stat-value"><?php echo esc_html( (string) $autopilot_count ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Autopilot', 'seo-agent-ai' ); ?></div>
				</div>
				<div class="seo-agent-stat-box">
					<div class="stat-value"><?php echo esc_html( (string) $rolled_back ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Rolled Back', 'seo-agent-ai' ); ?></div>
				</div>
			</div>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="seo-agent-report-filters">
				<input type="hidden" name="page" value="seo-agent-ai-report" />

				<label>
					<?php esc_html_e( 'Change Type', 'seo-agent-ai' ); ?>
					<select name="change_type">
						<option value=""><?php esc_html_e( 'All Types', 'seo-agent-ai' ); ?></option>
						<?php foreach ( $this->get_change_type_options() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filters['change_type'] ?? '', $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Triggered By', 'seo-agent-ai' ); ?>
					<select name="triggered_by">
						<option value=""><?php esc_html_e( 'Any Source', 'seo-agent-ai' ); ?></option>
						<option value="manual" <?php selected( $filters['triggered_by'] ?? '', 'manual' ); ?>><?php esc_html_e( 'Manual', 'seo-agent-ai' ); ?></option>
						<option value="autopilot" <?php selected( $filters['triggered_by'] ?? '', 'autopilot' ); ?>><?php esc_html_e( 'Autopilot', 'seo-agent-ai' ); ?></option>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Status', 'seo-agent-ai' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( 'Any Status', 'seo-agent-ai' ); ?></option>
						<option value="applied" <?php selected( $filters['status'] ?? '', 'applied' ); ?>><?php esc_html_e( 'Active', 'seo-agent-ai' ); ?></option>
						<option value="rolled_back" <?php selected( $filters['status'] ?? '', 'rolled_back' ); ?>><?php esc_html_e( 'Rolled Back', 'seo-agent-ai' ); ?></option>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'From', 'seo-agent-ai' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>" />
				</label>

				<label>
					<?php esc_html_e( 'To', 'seo-agent-ai' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>" />
				</label>

				<div>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'seo-agent-ai' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-ai-report' ) ); ?>" class="button button-link" style="margin-left:6px;"><?php esc_html_e( 'Reset', 'seo-agent-ai' ); ?></a>
				</div>
			</form>

			<?php if ( empty( $entries ) ) : ?>
				<p><em><?php esc_html_e( 'No activity logged yet. Run an analysis and apply (or enable autopilot) to see history here.', 'seo-agent-ai' ); ?></em></p>
			<?php else : ?>

				<table class="widefat striped" style="margin-top:0;">
					<thead>
						<tr>
							<th style="width:20%"><?php esc_html_e( 'Post', 'seo-agent-ai' ); ?></th>
							<th style="width:12%"><?php esc_html_e( 'Change', 'seo-agent-ai' ); ?></th>
							<th style="width:30%"><?php esc_html_e( 'Before → After', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Why', 'seo-agent-ai' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Confidence', 'seo-agent-ai' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Source', 'seo-agent-ai' ); ?></th>
							<th style="width:7%"><?php esc_html_e( 'Status', 'seo-agent-ai' ); ?></th>
							<th style="width:10%"><?php esc_html_e( 'Date', 'seo-agent-ai' ); ?></th>
							<th style="width:6%"><?php esc_html_e( 'Actions', 'seo-agent-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $this->render_row( $entry ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom" style="margin-top:12px;">
						<div class="tablenav-pages">
							<?php
							$base_url = add_query_arg(
								array_merge( $filters, array( 'page' => 'seo-agent-ai-report' ) ),
								admin_url( 'admin.php' )
							);
							echo wp_kses_post( paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%', $base_url ),
								'format'    => '',
								'current'   => $page,
								'total'     => $pages,
								'prev_text' => '&laquo; ' . __( 'Previous', 'seo-agent-ai' ),
								'next_text' => __( 'Next', 'seo-agent-ai' ) . ' &raquo;',
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Individual row
	// -----------------------------------------------------------------------

	private function render_row( array $entry ) {
		$post_id      = (int) $entry['id'];
		$entry_id     = (int) $entry['id'];
		$entry_post   = (int) $entry['post_id'];
		$change_type  = (string) $entry['change_type'];
		$field        = (string) $entry['field_changed'];
		$before       = (string) $entry['value_before'];
		$after        = (string) $entry['value_after'];
		$reason       = (string) $entry['reason'];
		$confidence   = (float)  $entry['confidence'];
		$triggered_by = (string) $entry['triggered_by'];
		$status       = (string) $entry['status'];
		$created_at   = (string) $entry['created_at'];
		$signal_data  = is_array( $entry['signal_data'] ) ? $entry['signal_data'] : array();

		$post       = $entry_post ? get_post( $entry_post ) : null;
		$post_title = $post instanceof WP_Post ? get_the_title( $entry_post ) : __( '(deleted)', 'seo-agent-ai' );
		$edit_link  = $post instanceof WP_Post ? get_edit_post_link( $entry_post ) : '';
		$is_applied = $status === SEO_Agent_AI_Activity_Log::STATUS_APPLIED;

		echo '<tr>';

		// Post
		echo '<td>';
		if ( $edit_link ) {
			echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $post_title ) . '</a>';
		} else {
			echo esc_html( $post_title );
		}
		if ( $field ) {
			echo '<br/><span class="seo-agent-muted seo-agent-mono">' . esc_html( $field ) . '</span>';
		}
		echo '</td>';

		// Change type
		echo '<td>';
		echo '<span class="seo-agent-pill ' . esc_attr( $this->change_type_class( $change_type ) ) . '">';
		echo esc_html( $this->change_type_label( $change_type ) );
		echo '</span>';
		echo '</td>';

		// Before → After diff
		echo '<td>';
		if ( $before !== '' || $after !== '' ) {
			echo '<div class="seo-agent-diff-block">';
			echo '<div class="seo-agent-diff-before"><span class="seo-agent-diff-label">' . esc_html__( 'Before', 'seo-agent-ai' ) . '</span>' . esc_html( $before ?: '—' ) . '</div>';
			echo '<div class="seo-agent-diff-after"><span class="seo-agent-diff-label">' . esc_html__( 'After', 'seo-agent-ai' ) . '</span>' . esc_html( $after ?: '—' ) . '</div>';
			echo '</div>';
		} else {
			echo '<span class="seo-agent-muted">—</span>';
		}
		echo '</td>';

		// Reason / signals
		echo '<td>';
		echo '<p style="margin:0 0 6px;font-size:12px;">' . esc_html( $reason ) . '</p>';
		if ( ! empty( $signal_data['evidence'] ) && is_array( $signal_data['evidence'] ) ) {
			echo '<ul style="margin:0;padding:0 0 0 14px;font-size:11px;color:#646970;">';
			$evidence_labels = array(
				'impressions_total'      => __( 'Impressions', 'seo-agent-ai' ),
				'ctr_avg'                => __( 'CTR', 'seo-agent-ai' ),
				'position_avg'           => __( 'Avg Position', 'seo-agent-ai' ),
				'engagement_rate'        => __( 'Engagement Rate', 'seo-agent-ai' ),
				'avg_time_on_page_sec'   => __( 'Avg Time on Page', 'seo-agent-ai' ),
				'impressions_trend_28d_pct' => __( 'Impressions Trend (28d)', 'seo-agent-ai' ),
				'sessions_trend_28d_pct'    => __( 'Sessions Trend (28d)', 'seo-agent-ai' ),
			);
			foreach ( $evidence_labels as $key => $label ) {
				if ( isset( $signal_data['evidence'][ $key ] ) ) {
					$val = $signal_data['evidence'][ $key ];
					if ( in_array( $key, array( 'ctr_avg', 'engagement_rate' ), true ) ) {
						$val = round( (float) $val * 100, 1 ) . '%';
					} elseif ( in_array( $key, array( 'impressions_trend_28d_pct', 'sessions_trend_28d_pct' ), true ) ) {
						$val = round( (float) $val, 1 ) . '%';
					} else {
						$val = round( (float) $val, 1 );
					}
					echo '<li>' . esc_html( $label ) . ': <strong>' . esc_html( (string) $val ) . '</strong></li>';
				}
			}
			echo '</ul>';
		}
		echo '</td>';

		// Confidence
		echo '<td>';
		$this->render_confidence( $confidence );
		echo '</td>';

		// Source
		echo '<td>';
		$source_labels = array(
			'manual'    => __( 'Manual', 'seo-agent-ai' ),
			'autopilot' => __( 'Autopilot', 'seo-agent-ai' ),
			'rollback'  => __( 'Rollback', 'seo-agent-ai' ),
		);
		echo '<span class="seo-agent-pill ' . esc_attr( $triggered_by ) . '" style="background:#e8f0fe;color:#1967d2;">';
		echo esc_html( $source_labels[ $triggered_by ] ?? $triggered_by );
		echo '</span>';
		echo '</td>';

		// Status
		echo '<td>';
		echo '<span class="seo-agent-status ' . esc_attr( $status ) . '">';
		$status_labels = array(
			'applied'     => __( 'Active', 'seo-agent-ai' ),
			'rolled_back' => __( 'Rolled back', 'seo-agent-ai' ),
			'skipped'     => __( 'Skipped', 'seo-agent-ai' ),
		);
		echo esc_html( $status_labels[ $status ] ?? $status );
		echo '</span>';
		echo '</td>';

		// Date
		echo '<td><span class="seo-agent-muted">' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $created_at ) ) ) . '</span></td>';

		// Actions
		echo '<td>';
		if ( $is_applied && $entry_post ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'seo_agent_ai_rollback' );
			echo '<input type="hidden" name="action" value="seo_agent_ai_rollback" />';
			echo '<input type="hidden" name="log_id" value="' . esc_attr( (string) $entry_id ) . '" />';
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $entry_post ) . '" />';
			echo '<button type="submit" class="button button-small"'
				. ' onclick="return confirm(\'' . esc_js( __( 'Roll back this change?', 'seo-agent-ai' ) ) . '\')">'
				. esc_html__( 'Rollback', 'seo-agent-ai' )
				. '</button>';
			echo '</form>';
		}
		echo '</td>';

		echo '</tr>';
	}

	// -----------------------------------------------------------------------
	// Confidence meter
	// -----------------------------------------------------------------------

	private function render_confidence( $confidence ) {
		$pct   = round( $confidence * 100 );
		$class = 'low';
		if ( $confidence >= 0.75 ) {
			$class = 'high';
		} elseif ( $confidence >= 0.5 ) {
			$class = 'medium';
		}
		echo '<div class="seo-agent-confidence">';
		echo '<div class="seo-agent-confidence-bar"><div class="seo-agent-confidence-fill ' . esc_attr( $class ) . '" style="width:' . esc_attr( (string) $pct ) . '%"></div></div>';
		echo '<span class="seo-agent-muted">' . esc_html( $pct . '%' ) . '</span>';
		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function get_filters_from_request() {
		$allowed_types = array_keys( $this->get_change_type_options() );
		$filters       = array();

		$change_type = filter_input( INPUT_GET, 'change_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $change_type && in_array( sanitize_key( wp_unslash( (string) $change_type ) ), $allowed_types, true ) ) {
			$filters['change_type'] = sanitize_key( wp_unslash( (string) $change_type ) );
		}

		$triggered_by = filter_input( INPUT_GET, 'triggered_by', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $triggered_by && in_array( sanitize_key( wp_unslash( (string) $triggered_by ) ), array( 'manual', 'autopilot', 'rollback' ), true ) ) {
			$filters['triggered_by'] = sanitize_key( wp_unslash( (string) $triggered_by ) );
		}

		$status = filter_input( INPUT_GET, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $status && in_array( sanitize_key( wp_unslash( (string) $status ) ), array( 'applied', 'rolled_back', 'skipped' ), true ) ) {
			$filters['status'] = sanitize_key( wp_unslash( (string) $status ) );
		}

		$date_from = filter_input( INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( (string) $date_from ) ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( (string) $date_from ) );
		}

		$date_to = filter_input( INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', wp_unslash( (string) $date_to ) ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( (string) $date_to ) );
		}

		return $filters;
	}

	private function get_change_type_options() {
		return array(
			'meta_update'          => __( 'Meta Update', 'seo-agent-ai' ),
			'monitor_decline'      => __( 'Decline Monitor', 'seo-agent-ai' ),
			'content_refresh_plan' => __( 'Content Refresh', 'seo-agent-ai' ),
			'intent_alignment'     => __( 'Intent Alignment', 'seo-agent-ai' ),
			'rollback'             => __( 'Rollback', 'seo-agent-ai' ),
		);
	}

	private function change_type_label( $type ) {
		$labels = $this->get_change_type_options();
		return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	private function change_type_class( $type ) {
		$map = array(
			'meta_update'     => 'safe',
			'monitor_decline' => 'medium',
			'rollback'        => 'low',
		);
		return $map[ $type ] ?? 'low';
	}
}
