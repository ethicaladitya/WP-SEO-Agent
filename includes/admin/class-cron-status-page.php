<?php
/**
 * Cron Status admin page — health check, last-run times, manual triggers.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Cron_Status_Page {

	/**
	 * All managed cron hooks with their schedule and description.
	 */
	private static function cron_hooks() {
		return array(
			'seo_agent_ai_daily_analysis' => array(
				'schedule'    => 'daily',
				'description' => __( 'Main daily analysis: fetch GSC/GA4, analyze posts, apply autopilot.', 'seo-agent-ai' ),
			),
			'seo_agent_fetch_gsc_data' => array(
				'schedule'    => 'daily',
				'description' => __( 'Dedicated GSC keyword history fetch → keyword_history table.', 'seo-agent-ai' ),
			),
			'seo_agent_fetch_ga4_data' => array(
				'schedule'    => 'daily',
				'description' => __( 'Dedicated GA4 engagement metrics fetch.', 'seo-agent-ai' ),
			),
			'seo_agent_generate_report' => array(
				'schedule'    => 'daily',
				'description' => __( 'Generate and store daily SEO report.', 'seo-agent-ai' ),
			),
			'seo_agent_score_pages' => array(
				'schedule'    => 'weekly',
				'description' => __( 'Run SEO scoring engine on all published posts.', 'seo-agent-ai' ),
			),
			'seo_agent_detect_decay' => array(
				'schedule'    => 'weekly',
				'description' => __( 'Content decay + freshness detection pass.', 'seo-agent-ai' ),
			),
			'seo_agent_run_internal_links' => array(
				'schedule'    => 'weekly',
				'description' => __( 'Internal link opportunity detection and insertion.', 'seo-agent-ai' ),
			),
			'seo_agent_purge_old_data' => array(
				'schedule'    => 'weekly',
				'description' => __( 'Purge keyword_history and page_insights rows beyond retention window.', 'seo-agent-ai' ),
			),
		);
	}

	/**
	 * Handle manual trigger POST action.
	 */
	public function handle_trigger() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'seo-agent-ai' ) );
		}

		$hook = sanitize_key( $_POST['hook'] ?? '' );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'seo_agent_ai_trigger_' . $hook ) ) {
			wp_die( esc_html__( 'Security check failed.', 'seo-agent-ai' ) );
		}

		$allowed = array_keys( self::cron_hooks() );
		if ( ! in_array( $hook, $allowed, true ) ) {
			wp_die( esc_html__( 'Unknown hook.', 'seo-agent-ai' ) );
		}

		// Fire the event now.
		do_action( $hook );

		wp_safe_redirect( admin_url( 'admin.php?page=seo-agent-cron&triggered=' . rawurlencode( $hook ) ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		if ( ! empty( $_GET['triggered'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$hook = sanitize_key( $_GET['triggered'] ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html( sprintf( __( 'Hook "%s" triggered manually.', 'seo-agent-ai' ), $hook ) );
			echo '</p></div>';
		}

		?>
		<div class="wrap seo-agent-ai-cron">
			<h1><?php esc_html_e( 'Cron Status', 'seo-agent-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'All scheduled SEO Agent cron jobs. Use "Run Now" to trigger any job immediately.', 'seo-agent-ai' ); ?>
			</p>

			<table class="widefat striped seo-agent-table">
				<thead><tr>
					<th><?php esc_html_e( 'Hook', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Schedule', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Next Run', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Last Run', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Description', 'seo-agent-ai' ); ?></th>
					<th><?php esc_html_e( 'Action', 'seo-agent-ai' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::cron_hooks() as $hook => $info ) : ?>
					<?php
					$next_run  = wp_next_scheduled( $hook );
					$last_run  = (string) get_option( 'seo_agent_ai_last_run_' . $hook, '' );
					$scheduled = $next_run !== false;
					$next_str  = $scheduled ? $this->human_time( $next_run ) : __( 'Not scheduled', 'seo-agent-ai' );
					$last_str  = $last_run !== '' ? $last_run : __( 'Never', 'seo-agent-ai' );
					$status_cls = $scheduled ? 'cron-ok' : 'cron-error';
					$status_lbl = $scheduled ? __( 'Scheduled', 'seo-agent-ai' ) : __( 'Missing', 'seo-agent-ai' );
					?>
					<tr>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td><?php echo esc_html( $info['schedule'] ); ?></td>
						<td><?php echo esc_html( $next_str ); ?></td>
						<td><?php echo esc_html( $last_str ); ?></td>
						<td><span class="cron-status <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
						<td class="description"><?php echo esc_html( $info['description'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="seo_agent_ai_trigger_cron">
								<input type="hidden" name="hook" value="<?php echo esc_attr( $hook ); ?>">
								<?php wp_nonce_field( 'seo_agent_ai_trigger_' . $hook ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Run Now', 'seo-agent-ai' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Queue Status', 'seo-agent-ai' ); ?></h2>
			<?php $this->render_queue_status(); ?>
		</div>
		<?php
	}

	private function render_queue_status() {
		$raw    = get_option( SEO_Agent_AI_Queue_Manager::OPTION_KEY, '' );
		$queue  = $raw !== '' ? json_decode( $raw, true ) : null;

		if ( ! is_array( $queue ) ) {
			echo '<p>' . esc_html__( 'Queue not initialized.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="form-table">';
		$fields = array(
			'pending'               => __( 'Posts in queue', 'seo-agent-ai' ),
			'total_queued'          => __( 'Total ever queued', 'seo-agent-ai' ),
			'total_processed'       => __( 'Total processed', 'seo-agent-ai' ),
			'total_errors'          => __( 'Total errors', 'seo-agent-ai' ),
			'last_run'              => __( 'Last batch run', 'seo-agent-ai' ),
			'last_batch_processed'  => __( 'Posts in last batch', 'seo-agent-ai' ),
		);

		foreach ( $fields as $key => $label ) {
			$val = $key === 'pending' ? count( $queue['items'] ?? array() ) : ( $queue[ $key ] ?? '—' );
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $val ) . '</td></tr>';
		}
		echo '</table>';
	}

	private function human_time( $timestamp ) {
		$diff = $timestamp - time();
		if ( $diff < 0 ) {
			return __( 'Overdue', 'seo-agent-ai' );
		}
		/* translators: Human-readable time difference. */
		return sprintf( __( 'in %s', 'seo-agent-ai' ), human_time_diff( time(), $timestamp ) );
	}
}
