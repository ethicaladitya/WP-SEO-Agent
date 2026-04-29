<?php
/**
 * Admin page controller.
 *
 * Registers the WP Admin menu structure and delegates rendering to
 * specialised page classes:
 *   Overview   -> this file
 *   Connect    -> class-connect-page.php
 *   Report     -> class-report-page.php
 *   Settings   -> this file
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Admin_Page {

	/** @var SEO_Agent_AI_Data_Store */
	private $data_store;

	/** @var SEO_Agent_AI_Connect_Page */
	private $connect_page;

	/** @var SEO_Agent_AI_Report_Page */
	private $report_page;

	/** @var SEO_Agent_AI_Google_OAuth */
	private $oauth;

	/** @var SEO_Agent_AI_SEO_Plugin_Bridge */
	private $bridge;

	public function __construct(
		SEO_Agent_AI_Data_Store $data_store,
		SEO_Agent_AI_Connect_Page $connect_page,
		SEO_Agent_AI_Report_Page $report_page,
		SEO_Agent_AI_Google_OAuth $oauth,
		SEO_Agent_AI_SEO_Plugin_Bridge $bridge
	) {
		$this->data_store   = $data_store;
		$this->connect_page = $connect_page;
		$this->report_page  = $report_page;
		$this->oauth        = $oauth;
		$this->bridge       = $bridge;
	}

	// -------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------

	public function register_menu() {
		add_menu_page(
			__( 'SEO Agent AI', 'seo-agent-ai' ),
			__( 'SEO Agent AI', 'seo-agent-ai' ),
			'manage_options',
			'seo-agent-ai',
			array( $this, 'render_overview_page' ),
			'dashicons-chart-area',
			58
		);

		add_submenu_page(
			'seo-agent-ai',
			__( 'Overview', 'seo-agent-ai' ),
			__( 'Overview', 'seo-agent-ai' ),
			'manage_options',
			'seo-agent-ai',
			array( $this, 'render_overview_page' )
		);

		add_submenu_page(
			'seo-agent-ai',
			__( 'Connect Google', 'seo-agent-ai' ),
			__( 'Connect Google', 'seo-agent-ai' ),
			'manage_options',
			'seo-agent-ai-connect',
			array( $this->connect_page, 'render' )
		);

		add_submenu_page(
			'seo-agent-ai',
			__( 'Activity Report', 'seo-agent-ai' ),
			__( 'Activity Report', 'seo-agent-ai' ),
			'manage_options',
			'seo-agent-ai-report',
			array( $this->report_page, 'render' )
		);

		add_submenu_page(
			'seo-agent-ai',
			__( 'Settings', 'seo-agent-ai' ),
			__( 'Settings', 'seo-agent-ai' ),
			'manage_options',
			'seo-agent-ai-settings',
			array( $this, 'render_settings_page' )
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'seo-agent-ai' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'seo-agent-ai-admin',
			SEO_AGENT_AI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SEO_AGENT_AI_VERSION
		);
	}

	// -------------------------------------------------------------------
	// Overview page
	// -------------------------------------------------------------------

	public function render_overview_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice       = filter_input( INPUT_GET, 'seo_agent_ai_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$notice       = is_string( $notice ) ? sanitize_key( wp_unslash( $notice ) ) : '';
		$post_ids     = $this->data_store->get_posts_with_recommendations( 100 );
		$last_run     = $this->data_store->get_last_run();
		$is_connected = $this->oauth->is_connected();
		$autopilot    = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );
		?>
		<div class="wrap seo-agent-wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<?php esc_html_e( 'SEO Agent AI', 'seo-agent-ai' ); ?>
				<?php if ( $autopilot ) : ?>
					<span class="seo-agent-autopilot-badge on"><span class="dot"></span><?php esc_html_e( 'Autopilot ON', 'seo-agent-ai' ); ?></span>
				<?php else : ?>
					<span class="seo-agent-autopilot-badge off"><span class="dot"></span><?php esc_html_e( 'Autopilot OFF', 'seo-agent-ai' ); ?></span>
				<?php endif; ?>
			</h1>

			<?php if ( ! $is_connected ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: Connect Google page link */
							esc_html__( 'Google account not connected. %s to enable live data analysis.', 'seo-agent-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '">' . esc_html__( 'Connect Google', 'seo-agent-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_notice( $notice ); ?>

			<?php if ( ! empty( $last_run ) ) : ?>
				<div class="seo-agent-card" style="padding:14px 20px;">
					<p style="margin:0;font-size:13px;color:#3c434a;">
						<strong><?php esc_html_e( 'Last run:', 'seo-agent-ai' ); ?></strong>
						<?php
						echo esc_html(
							sprintf(
								'%s (%s) — %d posts analyzed, %d with recommendations',
								isset( $last_run['finished_at'] ) ? $last_run['finished_at'] : '-',
								isset( $last_run['mode'] ) ? $last_run['mode'] : 'manual',
								isset( $last_run['processed_posts'] ) ? (int) $last_run['processed_posts'] : 0,
								isset( $last_run['posts_with_recommendations'] ) ? (int) $last_run['posts_with_recommendations'] : 0
							)
						);
						?>
						<?php if ( ! empty( $last_run['failed_posts'] ) ) : ?>
							<span style="color:#b32d2e;margin-left:8px;"><?php echo esc_html( (int) $last_run['failed_posts'] . ' API failures' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<div id="seo-analysis-wrap" style="margin-bottom:24px;">
				<button id="seo-run-analysis" class="button button-primary" style="font-size:14px;height:38px;padding:0 20px;">
					<?php esc_html_e( 'Run Analysis Now', 'seo-agent-ai' ); ?>
				</button>
				<div id="seo-analysis-progress" style="display:none;max-width:540px;margin-top:16px;">
					<div class="seo-agent-progress-track">
						<div id="seo-progress-fill" class="seo-agent-progress-fill" style="width:0%"></div>
					</div>
					<p id="seo-progress-status" class="seo-agent-progress-status">
						<?php esc_html_e( 'Initializing&hellip;', 'seo-agent-ai' ); ?>
					</p>
				</div>
			</div>

			<script>
			(function($) {
				'use strict';
				var batchNonce = '<?php echo esc_js( wp_create_nonce( 'seo_agent_ai_analyze_batch' ) ); ?>';
				var strings = {
					analyzing: '<?php echo esc_js( __( 'Analyzing\u2026', 'seo-agent-ai' ) ); ?>',
					of:        '<?php echo esc_js( __( 'of', 'seo-agent-ai' ) ); ?>',
					done:      '<?php echo esc_js( __( 'Analysis complete', 'seo-agent-ai' ) ); ?>',
					posts:     '<?php echo esc_js( __( 'posts analyzed', 'seo-agent-ai' ) ); ?>',
					recs:      '<?php echo esc_js( __( 'with recommendations', 'seo-agent-ai' ) ); ?>',
					errors:    '<?php echo esc_js( __( 'API errors', 'seo-agent-ai' ) ); ?>',
					loading:   '<?php echo esc_js( __( 'Loading results\u2026', 'seo-agent-ai' ) ); ?>',
					retry:     '<?php echo esc_js( __( 'Run Analysis Now', 'seo-agent-ai' ) ); ?>',
					connErr:   '<?php echo esc_js( __( 'Connection error \u2014 please try again.', 'seo-agent-ai' ) ); ?>'
				};

				$('#seo-run-analysis').on('click', function() {
					$(this).prop('disabled', true).html(
						'<span class="spinner is-active" style="float:none;margin:-3px 6px 0 0;vertical-align:middle;width:16px;height:16px;"></span>' + strings.analyzing
					);
					$('#seo-analysis-progress').slideDown(200);
					runBatch(0);
				});

				function runBatch(offset) {
					$.post(ajaxurl, {
						action:      'seo_agent_ai_analyze_batch',
						offset:      offset,
						_ajax_nonce: batchNonce
					})
					.done(function(r) {
						if (!r.success) {
							setStatus((r.data ? r.data : 'Error'), 'error');
							resetButton();
							return;
						}
						var d = r.data;
						$('#seo-progress-fill').css('width', d.percent + '%');

						if (d.done) {
							$('#seo-progress-fill').css('width', '100%').addClass('complete');
							var msg = '\u2713 ' + strings.done + ' \u2014 ' + d.total + ' ' + strings.posts;
							if (d.with_recs > 0) {
								msg += ', ' + d.with_recs + ' ' + strings.recs;
							}
							if (d.failed > 0) {
								msg += ' (' + d.failed + ' ' + strings.errors + ')';
							}
							setStatus(msg, 'success');
							setTimeout(function() {
								setStatus(strings.loading, 'info');
								location.reload();
							}, 1400);
						} else {
							var status = d.processed + ' ' + strings.of + ' ' + d.total;
							if (d.current_title) {
								status += ' \u2014 ' + d.current_title;
							}
							setStatus(status, 'info');
							runBatch(d.processed);
						}
					})
					.fail(function() {
						setStatus(strings.connErr, 'error');
						resetButton();
					});
				}

				function setStatus(msg, type) {
					var colors = { success: '#1e8e3e', error: '#c5221f', info: '#50575e' };
					$('#seo-progress-status').text(msg).css('color', colors[type] || colors.info);
				}

				function resetButton() {
					$('#seo-run-analysis').prop('disabled', false).text(strings.retry);
				}
			})(jQuery);
			</script>

			<?php if ( empty( $post_ids ) ) : ?>
				<p><em><?php esc_html_e( 'No recommendations yet. Run an analysis to populate insights.', 'seo-agent-ai' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Severity', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Signals', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Recommendations', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Backups', 'seo-agent-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $post_ids as $post_id ) : ?>
							<?php $this->render_post_row( $post_id ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Overview: post row
	// -------------------------------------------------------------------

	private function render_post_row( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$metrics         = $this->data_store->get_post_metrics( $post_id );
		$analysis        = isset( $metrics['analysis'] ) && is_array( $metrics['analysis'] ) ? $metrics['analysis'] : array();
		$signals         = isset( $analysis['signals'] ) ? $analysis['signals'] : array();
		$severity        = isset( $analysis['severity'] ) ? (string) $analysis['severity'] : 'none';
		$confidence      = isset( $analysis['confidence'] ) ? (float) $analysis['confidence'] : 0.0;
		$recommendations = $this->data_store->get_recommendations( $post_id );
		$backups         = $this->data_store->get_backups( $post_id );
		$gsc_error       = isset( $metrics['gsc_error'] ) ? (string) $metrics['gsc_error'] : '';
		$ga4_error       = isset( $metrics['ga4_error'] ) ? (string) $metrics['ga4_error'] : '';
		$permalink       = get_permalink( $post_id );

		echo '<tr>';

		echo '<td>';
		echo '<strong><a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a></strong>';
		echo '<br/><span class="seo-agent-muted">' . esc_html( $permalink ? (string) $permalink : '' ) . '</span>';
		echo '</td>';

		echo '<td><span class="seo-agent-pill ' . esc_attr( $severity ) . '">' . esc_html( strtoupper( $severity ) ) . '</span></td>';

		echo '<td>';
		echo wp_kses_post( $this->format_signals( $signals ) );
		if ( $gsc_error !== '' || $ga4_error !== '' ) {
			echo '<br/><span class="seo-agent-muted" style="color:#b32d2e;">';
			if ( $gsc_error ) {
				echo esc_html( 'GSC: ' . $gsc_error );
			}
			if ( $ga4_error ) {
				echo '<br/>' . esc_html( 'GA4: ' . $ga4_error );
			}
			echo '</span>';
		}
		echo '</td>';

		echo '<td>';
		if ( empty( $recommendations ) ) {
			echo '<span class="seo-agent-muted">' . esc_html__( 'No actions suggested.', 'seo-agent-ai' ) . '</span>';
		} else {
			foreach ( $recommendations as $index => $rec ) {
				$this->render_recommendation( $post_id, $index, $rec, $confidence );
			}
		}
		echo '</td>';

		echo '<td>';
		if ( ! empty( $backups ) ) {
			$latest   = $backups[0];
			$captured = isset( $latest['captured_at'] ) ? $latest['captured_at'] : '';
			echo '<span class="seo-agent-muted">' . esc_html( count( $backups ) . ' saved — latest ' . $captured ) . '</span><br/>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px;">';
			wp_nonce_field( 'seo_agent_ai_rollback_backup' );
			echo '<input type="hidden" name="action" value="seo_agent_ai_rollback_backup" />';
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
			echo '<button type="submit" class="button button-small"'
				. ' onclick="return confirm(\'' . esc_js( __( 'Restore the most recent backup for this post?', 'seo-agent-ai' ) ) . '\')">'
				. esc_html__( 'Rollback', 'seo-agent-ai' )
				. '</button>';
			echo '</form>';
		} else {
			echo '<span class="seo-agent-muted">' . esc_html__( 'No backup yet.', 'seo-agent-ai' ) . '</span>';
		}
		echo '</td>';

		echo '</tr>';
	}

	// -------------------------------------------------------------------
	// Recommendation card
	// -------------------------------------------------------------------

	private function render_recommendation( $post_id, $index, array $rec, $page_confidence ) {
		$type     = isset( $rec['type'] ) ? sanitize_text_field( $rec['type'] ) : '';
		$risk     = isset( $rec['risk'] ) ? sanitize_text_field( $rec['risk'] ) : 'risky';
		$priority = isset( $rec['priority'] ) ? sanitize_text_field( $rec['priority'] ) : 'low';
		$reason   = isset( $rec['reason'] ) ? sanitize_text_field( $rec['reason'] ) : '';
		$proposed = isset( $rec['proposed'] ) && is_array( $rec['proposed'] ) ? $rec['proposed'] : array();
		$conf     = isset( $rec['confidence'] ) ? (float) $rec['confidence'] : $page_confidence;
		$conf_pct = round( $conf * 100 );
		$conf_cls = $conf >= 0.75 ? 'high' : ( $conf >= 0.5 ? 'medium' : 'low' );

		echo '<div class="seo-agent-rec">';
		echo '<div class="seo-agent-rec-header">';
		echo '<span class="seo-agent-pill ' . esc_attr( $risk ) . '">' . esc_html( strtoupper( $risk ) ) . '</span>';
		echo '<span class="seo-agent-pill ' . esc_attr( $priority ) . '">' . esc_html( strtoupper( $priority ) ) . '</span>';
		echo '<span class="seo-agent-muted seo-agent-mono">' . esc_html( $type ) . '</span>';
		echo '<div class="seo-agent-confidence" style="margin-left:auto;">';
		echo '<div class="seo-agent-confidence-bar"><div class="seo-agent-confidence-fill ' . esc_attr( $conf_cls ) . '" style="width:' . esc_attr( (string) $conf_pct ) . '%"></div></div>';
		echo '<span class="seo-agent-muted">' . esc_html( $conf_pct . '%' ) . '</span>';
		echo '</div>';
		echo '</div>';

		echo '<p class="seo-agent-rec-reason">' . esc_html( $reason ) . '</p>';

		if ( ! empty( $proposed['meta_title'] ) || ! empty( $proposed['meta_description'] ) ) {
			echo '<div class="seo-agent-rec-proposed">';
			if ( ! empty( $proposed['meta_title'] ) ) {
				echo '<strong>' . esc_html__( 'Proposed title:', 'seo-agent-ai' ) . '</strong> ' . esc_html( $proposed['meta_title'] ) . '<br/>';
			}
			if ( ! empty( $proposed['meta_description'] ) ) {
				echo '<strong>' . esc_html__( 'Proposed description:', 'seo-agent-ai' ) . '</strong> ' . esc_html( $proposed['meta_description'] );
			}
			echo '</div>';
		}

		if ( ! empty( $proposed['summary'] ) ) {
			echo '<p class="seo-agent-rec-proposed">' . esc_html( $proposed['summary'] ) . '</p>';
		}

		if ( $risk === 'safe' && in_array( $type, array( 'meta_update', 'monitor_decline' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'seo_agent_ai_apply_fix' );
			echo '<input type="hidden" name="action" value="seo_agent_ai_apply_fix" />';
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
			echo '<input type="hidden" name="rec_index" value="' . esc_attr( (string) $index ) . '" />';
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Approve &amp; Apply', 'seo-agent-ai' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice          = filter_input( INPUT_GET, 'seo_agent_ai_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$notice          = is_string( $notice ) ? sanitize_key( wp_unslash( $notice ) ) : '';
		$client_id       = (string) get_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_ID, '' );
		$client_secret   = (string) get_option( SEO_Agent_AI_Google_OAuth::OPTION_CLIENT_SECRET, '' );
		$gsc_site_url    = (string) get_option( SEO_Agent_AI_GSC_Client::OPTION_GSC_SITE_URL, home_url( '/' ) );
		$ga4_property_id = (string) get_option( SEO_Agent_AI_GA4_Client::OPTION_GA4_PROPERTY_ID, '' );
		$gemini_key      = (string) get_option( SEO_Agent_AI_Gemini_Client::OPTION_API_KEY, '' );
		$autopilot       = (bool) get_option( 'seo_agent_ai_autopilot_enabled', false );
		$max_daily       = (int) get_option( 'seo_agent_ai_autopilot_max_daily', 5 );
		$min_confidence  = (float) get_option( 'seo_agent_ai_autopilot_min_confidence', 0.7 );
		$log_retention   = (int) get_option( 'seo_agent_ai_log_retention_days', 90 );
		$conn_result     = get_transient( SEO_Agent_AI_Plugin::CONNECTION_TEST_TRANSIENT );
		$is_connected    = $this->oauth->is_connected();

		if ( $conn_result !== false ) {
			delete_transient( SEO_Agent_AI_Plugin::CONNECTION_TEST_TRANSIENT );
		}
		?>
		<div class="wrap seo-agent-wrap">
			<h1><?php esc_html_e( 'SEO Agent AI Settings', 'seo-agent-ai' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>
			<?php if ( is_array( $conn_result ) ) : ?>
				<?php $this->render_connection_results( $conn_result ); ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'seo_agent_ai_save_settings' ); ?>
				<input type="hidden" name="action" value="seo_agent_ai_save_settings" />

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'Google OAuth Credentials', 'seo-agent-ai' ); ?></h2>
					<p class="description" style="margin-bottom:14px;">
						<?php
						printf(
							/* translators: %s: Connect Google page link */
							esc_html__( 'Enter credentials from Google Cloud Console, then %s to complete OAuth authorisation.', 'seo-agent-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '">' . esc_html__( 'Connect Google', 'seo-agent-ai' ) . '</a>'
						);
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="google_client_id"><?php esc_html_e( 'Client ID', 'seo-agent-ai' ); ?></label></th>
							<td><input type="text" id="google_client_id" name="google_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="google_client_secret"><?php esc_html_e( 'Client Secret', 'seo-agent-ai' ); ?></label></th>
							<td><input type="password" id="google_client_secret" name="google_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" autocomplete="off" /></td>
						</tr>
					</table>
				</div>

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'Data Sources', 'seo-agent-ai' ); ?></h2>
					<?php if ( ! $is_connected ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 14px;">
							<p>
								<?php
								printf(
									/* translators: %s: HTML link to the Connect Google page. */
									esc_html__( 'Connect your Google account to load available properties automatically. %s', 'seo-agent-ai' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '">' . esc_html__( 'Connect Google', 'seo-agent-ai' ) . '</a>'
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="gsc_site_url"><?php esc_html_e( 'Search Console Property', 'seo-agent-ai' ); ?></label></th>
							<td>
								<div id="seo-gsc-property-wrap">
									<input type="text" id="gsc_site_url" name="gsc_site_url" value="<?php echo esc_attr( $gsc_site_url ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Full URL, sc-domain:example.com, or bare domain. Bare domains are auto-prefixed.', 'seo-agent-ai' ); ?></p>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ga4_property_id"><?php esc_html_e( 'Analytics Property ID', 'seo-agent-ai' ); ?></label></th>
							<td>
								<div id="seo-ga4-property-wrap">
									<input type="text" id="ga4_property_id" name="ga4_property_id" value="<?php echo esc_attr( $ga4_property_id ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Numeric GA4 property ID. Universal Analytics (UA-*) is retired by Google.', 'seo-agent-ai' ); ?></p>
								</div>
							</td>
						</tr>
					</table>
				</div>

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'SEO Plugin Integration', 'seo-agent-ai' ); ?></h2>
					<?php
					$detected_plugins = $this->bridge->get_detected_plugins();
					if ( ! empty( $detected_plugins ) ) :
						?>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'SEO Agent AI is automatically syncing changes with the following active plugins. When you apply a recommendation, meta titles and descriptions will be written to all of them simultaneously.', 'seo-agent-ai' ); ?>
					</p>
					<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
						<?php foreach ( $detected_plugins as $slug ) : ?>
							<span class="seo-agent-pill safe" style="font-size:13px;"><?php echo esc_html( $this->bridge->get_plugin_label( $slug ) ); ?></span>
						<?php endforeach; ?>
					</div>
					<?php else : ?>
					<div class="notice notice-info inline" style="margin:0;">
						<p><?php esc_html_e( 'No supported SEO plugin detected. Install Yoast SEO, RankMath SEO, or SmartCrawl to automatically sync generated metadata.', 'seo-agent-ai' ); ?></p>
					</div>
					<?php endif; ?>
				</div>

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'AI Enhancement (Gemini)', 'seo-agent-ai' ); ?></h2>
					<p class="description" style="margin-bottom:14px;">
						<?php
						printf(
							/* translators: %s: link to Google AI Studio */
							esc_html__( 'Optional. Provide a Gemini API key to generate smarter, context-aware meta titles, descriptions and focus keywords. Without a key the agent uses rule-based generation. %s', 'seo-agent-ai' ),
							'<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get a free API key at Google AI Studio', 'seo-agent-ai' ) . '</a>'
						);
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'seo-agent-ai' ); ?></label></th>
							<td>
								<input type="password" id="gemini_api_key" name="gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Stored encrypted at rest (AES-256-CBC) in WordPress options. Leave blank to keep the existing key — the field is intentionally empty even when a key is saved. Define SEO_AGENT_AI_GEMINI_API_KEY in wp-config.php to override the saved value.', 'seo-agent-ai' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'Autopilot Mode', 'seo-agent-ai' ); ?></h2>
					<p class="description" style="margin-bottom:14px;">
						<?php esc_html_e( 'When enabled, the agent automatically applies safe, high-confidence changes during scheduled analysis.', 'seo-agent-ai' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Autopilot', 'seo-agent-ai' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="autopilot_enabled" value="1" <?php checked( $autopilot ); ?> />
									<?php esc_html_e( 'Allow the agent to apply safe changes automatically', 'seo-agent-ai' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="autopilot_max_daily"><?php esc_html_e( 'Max Changes Per Day', 'seo-agent-ai' ); ?></label></th>
							<td>
								<input type="number" id="autopilot_max_daily" name="autopilot_max_daily" value="<?php echo esc_attr( (string) $max_daily ); ?>" min="1" max="50" style="width:80px;" />
								<p class="description"><?php esc_html_e( 'Hard safety limit. The agent stops making changes after reaching this number per day.', 'seo-agent-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="autopilot_min_confidence"><?php esc_html_e( 'Minimum Confidence', 'seo-agent-ai' ); ?></label></th>
							<td>
								<input type="number" id="autopilot_min_confidence" name="autopilot_min_confidence" value="<?php echo esc_attr( (string) $min_confidence ); ?>" min="0.1" max="1.0" step="0.05" style="width:80px;" />
								<p class="description"><?php esc_html_e( 'Only apply changes at or above this confidence score (0.0–1.0). Recommended: 0.70.', 'seo-agent-ai' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="seo-agent-card seo-agent-settings-section">
					<h2><?php esc_html_e( 'Maintenance', 'seo-agent-ai' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="log_retention_days"><?php esc_html_e( 'Activity Log Retention (days)', 'seo-agent-ai' ); ?></label></th>
							<td>
								<input type="number" id="log_retention_days" name="log_retention_days" value="<?php echo esc_attr( (string) $log_retention ); ?>" min="7" max="730" style="width:80px;" />
								<p class="description"><?php esc_html_e( 'Log entries older than this many days are deleted automatically.', 'seo-agent-ai' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'seo-agent-ai' ); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'seo_agent_ai_test_connection' ); ?>
				<input type="hidden" name="action" value="seo_agent_ai_test_connection" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Test Connection', 'seo-agent-ai' ); ?></button>
			</form>
		</div>

		<?php if ( $is_connected ) : ?>
		<script>
		(function($) {
			'use strict';
			$(function() {
				var nonce = '<?php echo esc_js( wp_create_nonce( 'seo_agent_ai_property_list' ) ); ?>';

				function escHtml(str) {
					return String(str)
						.replace(/&/g, '&amp;')
						.replace(/</g, '&lt;')
						.replace(/>/g, '&gt;')
						.replace(/"/g, '&quot;');
				}

				function fallbackInput(name, val) {
					return '<input type="text" name="' + escHtml(name) + '" id="' + escHtml(name) + '" value="' + escHtml(val) + '" class="regular-text" />';
				}

				function spinnerHtml(msg) {
					return '<span class="spinner is-active" style="float:none;margin-top:0;"></span>' +
						'<span style="vertical-align:middle;margin-left:6px;font-size:13px;color:#50575e;">' + escHtml(msg) + '</span>';
				}

				function loadGSCSites() {
					var $wrap = $('#seo-gsc-property-wrap');
					var currentVal = <?php echo wp_json_encode( $gsc_site_url ); ?>;
					$wrap.html(spinnerHtml('<?php echo esc_js( __( 'Loading Search Console properties\u2026', 'seo-agent-ai' ) ); ?>'));

					$.post(ajaxurl, { action: 'seo_agent_ai_list_gsc_sites', _ajax_nonce: nonce })
						.done(function(response) {
							if (response.success && response.data && response.data.length) {
								var html = '<select name="gsc_site_url" id="gsc_site_url" class="regular-text">';
								html += '<option value="">&mdash; <?php echo esc_js( __( 'Select property', 'seo-agent-ai' ) ); ?> &mdash;</option>';
								$.each(response.data, function(_, site) {
									var url = site.siteUrl || '';
									var sel = (url === currentVal) ? ' selected="selected"' : '';
									html += '<option value="' + escHtml(url) + '"' + sel + '>' + escHtml(url) + '</option>';
								});
								html += '</select>';
								html += '<p class="description"><?php echo esc_js( __( 'Select your verified Search Console property.', 'seo-agent-ai' ) ); ?></p>';
								$wrap.html(html);
							} else {
								var err = (response.data && typeof response.data === 'string') ? response.data : '';
								$wrap.html(
									fallbackInput('gsc_site_url', currentVal) +
									'<p class="description"' + (err ? ' style="color:#c5221f;"' : '') + '>' +
									(err ? '<?php echo esc_js( __( 'Could not load properties: ', 'seo-agent-ai' ) ); ?>' + escHtml(err)
										: '<?php echo esc_js( __( 'Full URL, sc-domain:example.com, or bare domain.', 'seo-agent-ai' ) ); ?>') +
									'</p>'
								);
							}
						})
						.fail(function() {
							$wrap.html(
								fallbackInput('gsc_site_url', currentVal) +
								'<p class="description"><?php echo esc_js( __( 'Full URL, sc-domain:example.com, or bare domain.', 'seo-agent-ai' ) ); ?></p>'
							);
						});
				}

				function loadGA4Properties() {
					var $wrap = $('#seo-ga4-property-wrap');
					var currentVal = <?php echo wp_json_encode( $ga4_property_id ); ?>;
					$wrap.html(spinnerHtml('<?php echo esc_js( __( 'Loading Analytics properties\u2026', 'seo-agent-ai' ) ); ?>'));

					$.post(ajaxurl, { action: 'seo_agent_ai_list_ga4_properties', _ajax_nonce: nonce })
						.done(function(response) {
							if (response.success && response.data && response.data.length) {
								var html = '<select name="ga4_property_id" id="ga4_property_id" class="regular-text">';
								html += '<option value="">&mdash; <?php echo esc_js( __( 'Select property', 'seo-agent-ai' ) ); ?> &mdash;</option>';
								$.each(response.data, function(_, prop) {
									var sel = (prop.id === currentVal) ? ' selected="selected"' : '';
									html += '<option value="' + escHtml(prop.id) + '"' + sel + '>' + escHtml(prop.name) + '</option>';
								});
								html += '</select>';
								html += '<p class="description"><?php echo esc_js( __( 'Select your GA4 Analytics property.', 'seo-agent-ai' ) ); ?></p>';
								$wrap.html(html);
							} else {
								var err = (response.data && typeof response.data === 'string') ? response.data : '';
								var hint = err
									? '<?php echo esc_js( __( 'Could not load properties: ', 'seo-agent-ai' ) ); ?>' + escHtml(err) +
										' &mdash; <a href="https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com" target="_blank"><?php echo esc_js( __( 'Enable Analytics Admin API', 'seo-agent-ai' ) ); ?></a>'
									: '<?php echo esc_js( __( 'Numeric GA4 property ID.', 'seo-agent-ai' ) ); ?>';
								$wrap.html(
									fallbackInput('ga4_property_id', currentVal) +
									'<p class="description"' + (err ? ' style="color:#c5221f;"' : '') + '>' + hint + '</p>'
								);
							}
						})
						.fail(function() {
							$wrap.html(
								fallbackInput('ga4_property_id', currentVal) +
								'<p class="description"><?php echo esc_js( __( 'Numeric GA4 property ID.', 'seo-agent-ai' ) ); ?></p>'
							);
						});
				}

				loadGSCSites();
				loadGA4Properties();
			});
		})(jQuery);
		</script>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function format_signals( array $signals ) {
		$labels = array(
			'content_refresh_needed'  => __( 'Content refresh needed', 'seo-agent-ai' ),
			'title_meta_optimization' => __( 'Title/meta optimization', 'seo-agent-ai' ),
			'intent_mismatch'         => __( 'Intent mismatch', 'seo-agent-ai' ),
			'declining_performance'   => __( 'Declining performance', 'seo-agent-ai' ),
			'thin_content'            => __( 'Thin content', 'seo-agent-ai' ),
			'missing_meta_basics'     => __( 'Missing meta basics', 'seo-agent-ai' ),
		);

		$active = array();
		foreach ( $signals as $key => $enabled ) {
			if ( $enabled && isset( $labels[ $key ] ) ) {
				$active[] = '<span class="seo-agent-pill low">' . esc_html( $labels[ $key ] ) . '</span>';
			}
		}

		return empty( $active )
			? '<span class="seo-agent-muted">' . esc_html__( 'No active signals.', 'seo-agent-ai' ) . '</span>'
			: implode( ' ', $active );
	}

	private function render_notice( $notice ) {
		$map = array(
			'analysis_complete'        => array( 'success', __( 'Analysis completed.', 'seo-agent-ai' ) ),
			'analysis_scheduled'       => array( 'info',    __( 'Analysis scheduled. WP-Cron will run it shortly; reload this page in a minute or two for results.', 'seo-agent-ai' ) ),
			'fix_applied'              => array( 'success', __( 'Safe metadata fix applied.', 'seo-agent-ai' ) ),
			'apply_failed'             => array( 'error',   __( 'Could not apply fix. Check recommendation risk and payload.', 'seo-agent-ai' ) ),
			'invalid_input'            => array( 'error',   __( 'Invalid input provided.', 'seo-agent-ai' ) ),
			'recommendation_not_found' => array( 'error',   __( 'Recommendation no longer exists.', 'seo-agent-ai' ) ),
			'settings_saved'           => array( 'success', __( 'Settings saved.', 'seo-agent-ai' ) ),
			'connection_tested'        => array( 'info',    __( 'Connection test completed. See results below.', 'seo-agent-ai' ) ),
			'rollback_done'            => array( 'success', __( 'Rollback applied. Previous metadata restored.', 'seo-agent-ai' ) ),
			'rollback_failed'          => array( 'error',   __( 'Rollback failed. No backup found for this post.', 'seo-agent-ai' ) ),
			'google_disconnected'      => array( 'success', __( 'Google account disconnected.', 'seo-agent-ai' ) ),
			'google_connected'         => array( 'success', __( 'Google account connected.', 'seo-agent-ai' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $notice ];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_connection_results( array $result ) {
		$services = array(
			'gsc'       => __( 'Search Console', 'seo-agent-ai' ),
			'analytics' => __( 'Google Analytics', 'seo-agent-ai' ),
		);

		echo '<div style="margin:16px 0;max-width:700px;">';
		foreach ( $services as $key => $label ) {
			$row     = isset( $result[ $key ] ) && is_array( $result[ $key ] ) ? $result[ $key ] : array();
			$success = ! empty( $row['success'] );
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';
			$bg      = $success ? '#edf7ed' : '#fcf0f1';
			$border  = $success ? '#1e8e3e' : '#b32d2e';
			$icon    = $success ? '&#10003;' : '&#10007;';

			echo '<div style="background:' . esc_attr( $bg ) . ';border:1px solid ' . esc_attr( $border ) . ';border-radius:4px;padding:10px 14px;margin-bottom:8px;">';
			echo '<strong>' . wp_kses_post( $icon . ' ' . $label ) . '</strong>';
			if ( $message ) {
				echo '<span style="margin-left:12px;font-size:13px;">' . esc_html( $message ) . '</span>';
			}
			echo '</div>';
		}
		echo '</div>';
	}
}
