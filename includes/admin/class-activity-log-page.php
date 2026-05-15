<?php
/**
 * Activity & Debug Log admin page.
 *
 * Tab 1 — Activity Log: every SEO change recorded in the DB (who, what, when, status).
 * Tab 2 — Debug Log: last N lines of the file-based logger output.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Activity_Log_Page {

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	public function __construct(
		SEO_Agent_AI_Activity_Log $activity_log,
		SEO_Agent_AI_Logger $logger
	) {
		$this->activity_log = $activity_log;
		$this->logger       = $logger;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'activity'; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audit & Debug Log', 'seo-agent-ai' ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-log&tab=activity' ) ); ?>"
					class="nav-tab <?php echo 'activity' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Activity Log', 'seo-agent-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-log&tab=debug' ) ); ?>"
					class="nav-tab <?php echo 'debug' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Debug Log', 'seo-agent-ai' ); ?>
				</a>
			</nav>

			<div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px;">
			<?php
			if ( 'debug' === $tab ) {
				$this->render_debug_tab();
			} else {
				$this->render_activity_tab();
			}
			?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Activity tab
	// -------------------------------------------------------------------

	private function render_activity_tab() {
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 30;
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$trigger  = isset( $_GET['trigger'] ) ? sanitize_key( $_GET['trigger'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$filters = array();
		if ( $status !== '' ) {
			$filters['status'] = $status;
		}
		if ( $trigger !== '' ) {
			$filters['triggered_by'] = $trigger;
		}

		$entries = $this->activity_log->get_entries( $filters, $paged, $per_page );
		$total   = $this->activity_log->get_count( $filters );

		// Filter bar.
		echo '<form method="get" style="margin-bottom:12px;">';
		echo '<input type="hidden" name="page" value="seo-agent-log">';
		echo '<input type="hidden" name="tab" value="activity">';

		echo '<select name="status">';
		echo '<option value=""' . selected( $status, '', false ) . '>' . esc_html__( 'All Statuses', 'seo-agent-ai' ) . '</option>';
		echo '<option value="applied"' . selected( $status, 'applied', false ) . '>' . esc_html__( 'Applied', 'seo-agent-ai' ) . '</option>';
		echo '<option value="rolled_back"' . selected( $status, 'rolled_back', false ) . '>' . esc_html__( 'Rolled Back', 'seo-agent-ai' ) . '</option>';
		echo '<option value="skipped"' . selected( $status, 'skipped', false ) . '>' . esc_html__( 'Skipped', 'seo-agent-ai' ) . '</option>';
		echo '</select>';

		echo ' <select name="trigger">';
		echo '<option value=""' . selected( $trigger, '', false ) . '>' . esc_html__( 'All Triggers', 'seo-agent-ai' ) . '</option>';
		echo '<option value="autopilot"' . selected( $trigger, 'autopilot', false ) . '>' . esc_html__( 'Autopilot', 'seo-agent-ai' ) . '</option>';
		echo '<option value="manual"' . selected( $trigger, 'manual', false ) . '>' . esc_html__( 'Manual', 'seo-agent-ai' ) . '</option>';
		echo '<option value="rollback"' . selected( $trigger, 'rollback', false ) . '>' . esc_html__( 'Rollback', 'seo-agent-ai' ) . '</option>';
		echo '</select>';

		echo ' ';
		submit_button( __( 'Filter', 'seo-agent-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No activity logged yet. Changes made by SEO Agent AI will appear here.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:40px;">ID</th>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:120px;">' . esc_html__( 'Change Type', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:100px;">' . esc_html__( 'Field', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Before → After', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Trigger', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Status', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:130px;">' . esc_html__( 'When', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $e ) {
			$post_id    = (int) $e['post_id'];
			$post       = $post_id ? get_post( $post_id ) : null;
			$post_title = $post instanceof WP_Post ? $post->post_title : ( $post_id ? "(#{$post_id})" : __( 'System', 'seo-agent-ai' ) );
			$edit_url   = $post instanceof WP_Post ? get_edit_post_link( $post_id ) : '';

			$status_colors = array(
				'applied'     => '#00a32a',
				'rolled_back' => '#d63638',
				'skipped'     => '#646970',
			);
			$s_color       = $status_colors[ $e['status'] ] ?? '#646970';

			echo '<tr>';
			echo '<td>' . esc_html( $e['id'] ) . '</td>';
			echo '<td>';
			if ( $edit_url ) {
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $post_title ) . '</a>';
			} else {
				echo esc_html( $post_title );
			}
			echo '</td>';
			echo '<td><code>' . esc_html( $e['change_type'] ) . '</code></td>';
			echo '<td>' . esc_html( $e['field_changed'] ) . '</td>';
			echo '<td style="font-size:11px;">';
			echo '<span style="color:#646970;">' . esc_html( wp_trim_words( $e['value_before'], 8, '…' ) ) . '</span>';
			echo ' → <strong>' . esc_html( wp_trim_words( $e['value_after'], 8, '…' ) ) . '</strong>';
			echo '</td>';
			echo '<td><span style="font-size:11px;">' . esc_html( $e['triggered_by'] ) . '</span></td>';
			echo '<td><span style="color:' . esc_attr( $s_color ) . ';font-weight:600;font-size:11px;">' . esc_html( $e['status'] ) . '</span></td>';
			echo '<td style="font-size:11px;">' . esc_html( $e['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Pagination.
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav-pages" style="margin-top:8px;">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div>';
		}

		echo '<p style="color:#646970;margin-top:8px;font-size:12px;">' .
			esc_html(
				sprintf(
				/* translators: %d: total entries */
					__( '%d total entries', 'seo-agent-ai' ),
					$total
				)
			) . '</p>';
	}

	// -------------------------------------------------------------------
	// Debug log tab
	// -------------------------------------------------------------------

	private function render_debug_tab() {
		$lines       = max( 50, min( 500, (int) ( $_GET['lines'] ?? 100 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$level       = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$log_path    = $this->logger->get_log_path();
		$log_entries = $this->logger->tail( $lines, $level );

		echo '<form method="get" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;">';
		echo '<input type="hidden" name="page" value="seo-agent-log">';
		echo '<input type="hidden" name="tab" value="debug">';

		echo '<select name="level">';
		echo '<option value=""' . selected( $level, '', false ) . '>' . esc_html__( 'All Levels', 'seo-agent-ai' ) . '</option>';
		foreach ( array( 'ERROR', 'WARNING', 'INFO', 'DEBUG' ) as $l ) {
			echo '<option value="' . esc_attr( strtolower( $l ) ) . '"' . selected( $level, strtolower( $l ), false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';

		echo ' <label style="font-size:13px;">' . esc_html__( 'Lines:', 'seo-agent-ai' ) . ' ';
		echo '<select name="lines">';
		foreach ( array( 50, 100, 250, 500 ) as $n ) {
			echo '<option value="' . esc_attr( $n ) . '"' . selected( $lines, $n, false ) . '>' . esc_html( $n ) . '</option>';
		}
		echo '</select></label>';

		echo ' ';
		submit_button( __( 'Apply', 'seo-agent-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( ! file_exists( $log_path ) ) {
			echo '<div class="notice notice-info inline"><p>' .
				esc_html__( 'No debug log file yet — it will appear here once SEO Agent AI processes its first cron or analysis.', 'seo-agent-ai' ) .
				'</p></div>';
			return;
		}

		echo '<p style="color:#646970;font-size:12px;margin:0 0 8px;">' .
			esc_html(
				sprintf(
				/* translators: 1: line count, 2: file path */
					__( 'Showing last %1$d lines from %2$s', 'seo-agent-ai' ),
					$lines,
					$log_path
				)
			) . '</p>';

		if ( empty( $log_entries ) ) {
			echo '<p>' . esc_html__( 'No log entries match the current filter.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<div style="background:#1d2327;border-radius:4px;padding:12px 16px;overflow-x:auto;max-height:600px;overflow-y:auto;">';
		echo '<pre style="margin:0;font-family:monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;">';
		foreach ( array_reverse( $log_entries ) as $line ) {
			$line  = esc_html( $line );
			$color = '#c3c4c7'; // default.
			if ( strpos( $line, '[ERROR]' ) !== false ) {
				$color = '#f86368';
			} elseif ( strpos( $line, '[WARNING]' ) !== false ) {
				$color = '#f0c33c';
			} elseif ( strpos( $line, '[INFO]' ) !== false ) {
				$color = '#72aee6';
			} elseif ( strpos( $line, '[DEBUG]' ) !== false ) {
				$color = '#8c8f94';
			}
			echo '<span style="color:' . esc_attr( $color ) . ';">' . $line . '</span>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $line is already esc_html'd above.
		}
		echo '</pre></div>';

		echo '<p style="margin-top:8px;">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-log&tab=debug&clear=1&_wpnonce=' . wp_create_nonce( 'seo_agent_ai_clear_log' ) ) ) . '" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Clear the debug log file?', 'seo-agent-ai' ) ) . '\')">';
		esc_html_e( 'Clear Log', 'seo-agent-ai' );
		echo '</a>';
		echo '</p>';

		// Handle clear action.
		if ( ! empty( $_GET['clear'] ) && ! empty( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'seo_agent_ai_clear_log' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				@file_put_contents( $log_path, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				wp_safe_redirect( admin_url( 'admin.php?page=seo-agent-log&tab=debug' ) );
				exit;
			}
		}
	}
}
