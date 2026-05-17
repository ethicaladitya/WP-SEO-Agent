<?php
/**
 * Main SEO Dashboard admin page.
 *
 * Shows: agent activity (what changed), traffic/ranking trends, score distribution.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Dashboard_Page {

	/** @var SEO_Agent_AI_Decision_Engine */
	private $decision_engine;

	/** @var SEO_Agent_AI_Report_Engine */
	private $report_engine;

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	public function __construct(
		SEO_Agent_AI_Decision_Engine $decision_engine,
		SEO_Agent_AI_Report_Engine $report_engine,
		SEO_Agent_AI_Activity_Log $activity_log
	) {
		$this->decision_engine = $decision_engine;
		$this->report_engine   = $report_engine;
		$this->activity_log    = $activity_log;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$report        = $this->report_engine->get( gmdate( 'Y-m-d' ) );
		$pending_count = $this->decision_engine->count_pending();
		$score_dist    = $report ? ( $report['score_distribution'] ?? array() ) : array();
		$trends        = $report ? ( $report['trends'] ?? array() ) : array();
		$summary       = $report ? ( $report['summary'] ?? array() ) : array();

		$recent_changes = $this->activity_log->get_entries( array(), 1, 15 );
		$total_changes  = $this->activity_log->get_count( array() );
		$gsc_connected  = '' !== (string) get_option( 'seo_agent_ai_gsc_site', '' );
		$sitekit_active = class_exists( 'SEO_Agent_AI_SiteKit_Bridge' ) && SEO_Agent_AI_SiteKit_Bridge::is_active();
		$is_first_run   = 0 === $total_changes && empty( $report ) && ! $gsc_connected && ! $sitekit_active;

		?>
		<div class="wrap seo-agent-ai-dashboard">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
				<h1 style="margin:0"><?php esc_html_e( 'SEO Agent Dashboard', 'seo-agent-ai' ); ?></h1>
				<?php $this->render_scan_button( 'button-secondary' ); ?>
			</div>

			<?php if ( $is_first_run ) : ?>
				<?php $this->render_onboarding_banner(); ?>
			<?php endif; ?>

			<?php $this->render_summary_widgets( $summary, $pending_count, $total_changes ); ?>

			<div class="seo-agent-widget" style="margin-top:20px">
				<h2><?php esc_html_e( 'Recent Agent Activity', 'seo-agent-ai' ); ?></h2>
				<p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Changes the agent has made — what was modified, what it looked like before, and the confidence level.', 'seo-agent-ai' ); ?></p>
				<?php $this->render_activity_table( $recent_changes ); ?>
			</div>

			<div class="seo-agent-widget-grid" style="margin-top:20px">
				<div class="seo-agent-widget">
					<h2><?php esc_html_e( 'Traffic & Keyword Trends', 'seo-agent-ai' ); ?></h2>
					<p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Keyword ranking movements detected from Search Console since the last GSC sync.', 'seo-agent-ai' ); ?></p>
					<?php $this->render_trends( $trends ); ?>
				</div>

				<div class="seo-agent-widget">
					<h2><?php esc_html_e( 'Score Distribution', 'seo-agent-ai' ); ?></h2>
					<p class="description" style="margin-bottom:12px"><?php esc_html_e( 'How your pages rank across SEO score tiers from the last scoring run.', 'seo-agent-ai' ); ?></p>
					<?php $this->render_score_distribution( $score_dist ); ?>
				</div>
			</div>
		</div>
		<?php

		$this->render_scan_js();
	}

	// -------------------------------------------------------------------
	// Scan button, notice, and onboarding banner
	// -------------------------------------------------------------------

	private function render_scan_button( $extra_class = '' ) {
		static $progress_rendered = false;
		echo '<button class="seo-agent-dash-scan-btn button ' . esc_attr( $extra_class ) . '" style="display:inline-flex;align-items:center;gap:6px">';
		echo '<span class="dashicons dashicons-search" style="font-size:16px;width:16px;height:16px;margin-top:1px"></span>';
		echo '<span class="seo-agent-dash-scan-label">' . esc_html__( 'Run Full Scan', 'seo-agent-ai' ) . '</span>';
		echo '</button>';
		// Render the shared progress bar only once (first button).
		if ( ! $progress_rendered ) {
			$progress_rendered = true;
			echo '<div id="seo-agent-dash-scan-wrap" style="display:none;margin-top:8px;min-width:260px">';
			echo '<div style="background:#e5e5e5;border-radius:3px;height:5px;overflow:hidden;margin-bottom:5px">';
			echo '<div id="seo-agent-dash-scan-bar" style="height:100%;width:0%;background:#2271b1;transition:width .3s ease"></div>';
			echo '</div>';
			echo '<p id="seo-agent-dash-scan-status" style="margin:0;font-size:12px;color:#555"></p>';
			echo '</div>';
		}
	}

	private function render_onboarding_banner() {
		$google_connected = (bool) get_option( 'seo_agent_ai_gsc_site', '' );
		?>
		<div style="background:#fff;border:2px solid #2271b1;border-radius:6px;padding:28px 32px;margin-bottom:24px;display:flex;gap:28px;align-items:flex-start">
			<div style="flex-shrink:0;width:48px;height:48px;background:#2271b1;border-radius:50%;display:flex;align-items:center;justify-content:center">
				<span class="dashicons dashicons-chart-line" style="color:#fff;font-size:24px;width:24px;height:24px;line-height:1"></span>
			</div>
			<div style="flex:1">
				<h2 style="margin:0 0 8px"><?php esc_html_e( "Welcome — let's scan your site", 'seo-agent-ai' ); ?></h2>
				<p style="margin:0 0 16px;color:#555;max-width:620px">
					<?php esc_html_e( "SEO Agent hasn't analyzed your site yet. Run a scan to score every page, detect SEO problems, surface keyword opportunities, and generate AI-powered recommendations. Once done, you can review suggestions manually or switch on Autopilot.", 'seo-agent-ai' ); ?>
				</p>

				<ol style="margin:0 0 20px;padding-left:18px;color:#333;line-height:1.9">
					<li>
						<?php if ( $google_connected ) : ?>
							<span style="color:#27ae60;font-weight:600">&#10003; <?php esc_html_e( 'Google Search Console connected', 'seo-agent-ai' ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ); ?>">
								<?php esc_html_e( 'Connect Google Search Console', 'seo-agent-ai' ); ?>
							</a>
							<span style="color:#888;font-size:12px"> — <?php esc_html_e( 'optional, unlocks keyword & traffic data', 'seo-agent-ai' ); ?></span>
						<?php endif; ?>
					</li>
					<li style="font-weight:600"><?php esc_html_e( 'Run your first site scan (below)', 'seo-agent-ai' ); ?></li>
					<li style="color:#888"><?php esc_html_e( 'Review recommendations or enable Autopilot in Settings', 'seo-agent-ai' ); ?></li>
				</ol>

				<?php $this->render_scan_button( 'button-primary button-hero' ); ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------
	// Summary widgets
	// -------------------------------------------------------------------

	private function render_summary_widgets( $summary, $pending_count, $total_changes = 0 ) {
		$today_changes  = $this->activity_log->get_count(
			array( 'date_from' => gmdate( 'Y-m-d' ) . ' 00:00:00' )
		);

		$widgets = array(
			array(
				'label' => __( 'Changes Today', 'seo-agent-ai' ),
				'value' => $today_changes,
				'class' => $today_changes > 0 ? 'ok' : 'info',
				'link'  => admin_url( 'admin.php?page=seo-agent-activity-log' ),
			),
			array(
				'label' => __( 'Total Changes', 'seo-agent-ai' ),
				'value' => $total_changes,
				'class' => 'info',
				'link'  => admin_url( 'admin.php?page=seo-agent-activity-log' ),
			),
			array(
				'label' => __( 'Pending Approvals', 'seo-agent-ai' ),
				'value' => $pending_count,
				'class' => $pending_count > 0 ? 'pending' : 'ok',
				'link'  => admin_url( 'admin.php?page=seo-agent-approvals' ),
			),
			array(
				'label' => __( 'Opportunities', 'seo-agent-ai' ),
				'value' => $summary['opportunities_detected'] ?? '—',
				'class' => 'ok',
				'link'  => admin_url( 'admin.php?page=seo-agent-opportunities' ),
			),
			array(
				'label' => __( 'Pages Analyzed', 'seo-agent-ai' ),
				'value' => $summary['pages_analyzed'] ?? '—',
				'class' => 'info',
			),
			array(
				'label' => __( 'Problems Found', 'seo-agent-ai' ),
				'value' => $summary['problems_detected'] ?? '—',
				'class' => ( $summary['problems_detected'] ?? 0 ) > 0 ? 'error' : 'ok',
			),
		);

		echo '<div class="seo-agent-widget-grid">';
		foreach ( $widgets as $w ) {
			$val = is_int( $w['value'] ) ? number_format_i18n( $w['value'] ) : esc_html( (string) $w['value'] );
			$mod = 'widget-' . esc_attr( $w['class'] );
			echo '<div class="seo-agent-widget ' . esc_attr( $mod ) . '">';
			if ( ! empty( $w['link'] ) ) {
				echo '<a href="' . esc_url( $w['link'] ) . '" style="text-decoration:none;color:inherit">';
			}
			echo '<span class="widget-value">' . $val . '</span>';
			echo '<span class="widget-label">' . esc_html( $w['label'] ) . '</span>';
			if ( ! empty( $w['link'] ) ) {
				echo '</a>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// -------------------------------------------------------------------
	// Activity table
	// -------------------------------------------------------------------

	private function render_activity_table( array $entries ) {
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No changes recorded yet. Run a scan to let the agent analyze your site and generate recommendations.', 'seo-agent-ai' ) . '</p>';
			$this->render_scan_button( 'button-primary' );
			return;
		}

		$change_labels = array(
			'meta_title'       => __( 'Meta Title', 'seo-agent-ai' ),
			'meta_description' => __( 'Meta Description', 'seo-agent-ai' ),
			'focus_keyword'    => __( 'Focus Keyword', 'seo-agent-ai' ),
			'alt_text'         => __( 'Image Alt Text', 'seo-agent-ai' ),
			'heading'          => __( 'H1 Heading', 'seo-agent-ai' ),
			'faq_schema'       => __( 'FAQ Schema', 'seo-agent-ai' ),
			'internal_links'   => __( 'Internal Links', 'seo-agent-ai' ),
			'redirect'         => __( 'Redirect', 'seo-agent-ai' ),
		);

		echo '<div style="overflow-x:auto">';
		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th style="width:22%">' . esc_html__( 'Page', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:12%">' . esc_html__( 'Change Type', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:22%">' . esc_html__( 'Before', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:22%">' . esc_html__( 'After', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:8%">' . esc_html__( 'Confidence', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:8%">' . esc_html__( 'By', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:6%">' . esc_html__( 'Status', 'seo-agent-ai' ) . '</th>';
		echo '<th style="width:10%">' . esc_html__( 'When', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$post_id = (int) $entry['post_id'];
			$post    = get_post( $post_id );
			$title   = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";

			$change_key   = (string) $entry['field_changed'];
			$change_label = $change_labels[ $change_key ] ?? ucwords( str_replace( '_', ' ', $change_key ) );

			$before = (string) $entry['value_before'];
			$after  = (string) $entry['value_after'];

			$confidence = round( (float) $entry['confidence'] * 100 );
			$conf_class = $confidence >= 80 ? 'score-good' : ( $confidence >= 50 ? 'score-avg' : 'score-poor' );

			$triggered = (string) $entry['triggered_by'];
			$status    = (string) $entry['status'];

			$status_colors = array(
				'applied'     => '#27ae60',
				'rolled_back' => '#e74c3c',
				'pending'     => '#f39c12',
			);
			$status_color = $status_colors[ $status ] ?? '#888';

			$when_raw = (string) $entry['created_at'];
			$when     = $when_raw ? human_time_diff( strtotime( $when_raw ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'seo-agent-ai' ) : '—'; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" style="font-weight:600">' . esc_html( $title ) . '</a>';
			if ( $post instanceof WP_Post ) {
				echo '<br><small style="color:#888">' . esc_html( get_post_type_labels( get_post_type_object( $post->post_type ) )->singular_name ) . '</small>';
			}
			echo '</td>';
			echo '<td><span style="background:#eef;color:#335;padding:2px 6px;border-radius:3px;font-size:11px;white-space:nowrap">' . esc_html( $change_label ) . '</span></td>';
			echo '<td><span style="color:#888;font-size:12px;font-style:italic">' . esc_html( mb_strimwidth( $before, 0, 80, '…' ) ) . '</span></td>';
			echo '<td style="font-size:12px">' . esc_html( mb_strimwidth( $after, 0, 80, '…' ) ) . '</td>';
			echo '<td><span class="seo-score ' . esc_attr( $conf_class ) . '" style="font-size:11px">' . esc_html( $confidence ) . '%</span></td>';
			echo '<td style="font-size:12px;color:#555">' . esc_html( $triggered ) . '</td>';
			echo '<td><span style="color:' . esc_attr( $status_color ) . ';font-size:11px;font-weight:600">' . esc_html( $status ) . '</span></td>';
			echo '<td style="font-size:12px;color:#888">' . esc_html( $when ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo '<p style="margin-top:10px"><a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-activity-log' ) ) . '">' . esc_html__( 'View full activity log →', 'seo-agent-ai' ) . '</a></p>';
	}

	// -------------------------------------------------------------------
	// Async scan JS
	// -------------------------------------------------------------------

	private function render_scan_js() {
		$nonce = wp_create_nonce( 'seo_agent_ai_analyze_batch' );
		?>
		<script>
		(function () {
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var btns   = document.querySelectorAll('.seo-agent-dash-scan-btn');
			var wrap   = document.getElementById('seo-agent-dash-scan-wrap');
			var bar    = document.getElementById('seo-agent-dash-scan-bar');
			var status = document.getElementById('seo-agent-dash-scan-status');
			if (!btns.length) return;

			var labelText = {
				idle:     <?php echo wp_json_encode( __( 'Run Full Scan', 'seo-agent-ai' ) ); ?>,
				scanning: <?php echo wp_json_encode( __( 'Scanning…', 'seo-agent-ai' ) ); ?>
			};

			function setScanning(active) {
				btns.forEach(function (b) {
					b.disabled = active;
					var lbl = b.querySelector('.seo-agent-dash-scan-label');
					if (lbl) lbl.textContent = active ? labelText.scanning : labelText.idle;
				});
				if (wrap) wrap.style.display = active ? 'block' : 'none';
			}

			function updateProgress(pct, text) {
				if (bar)    bar.style.width    = pct + '%';
				if (status) status.textContent = text;
			}

			function runBatch(offset) {
				var body = new FormData();
				body.append('action',      'seo_agent_ai_analyze_batch');
				body.append('_ajax_nonce', nonce);
				body.append('offset',      offset);

				fetch(ajaxUrl, { method: 'POST', body: body })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.success) {
							showError(res.data || <?php echo wp_json_encode( __( 'Scan failed. Please try again.', 'seo-agent-ai' ) ); ?>);
							return;
						}
						var d    = res.data;
						var pct  = d.percent || 0;
						var text = d.done
							? <?php echo wp_json_encode( __( 'Scan complete!', 'seo-agent-ai' ) ); ?> + ' ' + d.with_recs + ' ' + <?php echo wp_json_encode( __( 'recommendation(s) generated. Reloading…', 'seo-agent-ai' ) ); ?>
							: <?php echo wp_json_encode( __( 'Scanning', 'seo-agent-ai' ) ); ?> + ' ' + pct + '%' + (d.current_title ? ' — ' + d.current_title : '');
						updateProgress(pct, text);
						if (d.done) {
							setTimeout(function () { window.location.reload(); }, 1800);
						} else {
							runBatch(d.processed);
						}
					})
					.catch(function () {
						showError(<?php echo wp_json_encode( __( 'Network error. Please try again.', 'seo-agent-ai' ) ); ?>);
					});
			}

			function showError(msg) {
				setScanning(false);
				if (status) {
					status.style.color = '#d63638';
					status.textContent  = msg;
					if (wrap) wrap.style.display = 'block';
				}
			}

			btns.forEach(function (b) {
				b.addEventListener('click', function () {
					setScanning(true);
					runBatch(0);
				});
			});
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------
	// Traffic & keyword trends
	// -------------------------------------------------------------------

	private function render_trends( array $trends ) {
		$rising    = $trends['rising'] ?? array();
		$declining = $trends['declining'] ?? array();

		if ( empty( $rising ) && empty( $declining ) ) {
			echo '<p>' . esc_html__( 'No trend data yet. Connect Google Search Console and wait for the daily GSC sync to populate this panel.', 'seo-agent-ai' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '">' . esc_html__( 'Connect Google →', 'seo-agent-ai' ) . '</a></p>';
			return;
		}

		if ( ! empty( $rising ) ) {
			echo '<h3 style="color:#27ae60;margin-top:0">' . esc_html__( '↑ Improving', 'seo-agent-ai' ) . '</h3>';
			echo '<table class="widefat striped" style="margin-bottom:16px"><thead><tr>';
			echo '<th>' . esc_html__( 'Keyword', 'seo-agent-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Page', 'seo-agent-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Change', 'seo-agent-ai' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rising, 0, 5 ) as $r ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $r['keyword'] ) . '</strong></td>';
				echo '<td>' . esc_html( $r['post_title'] ) . '</td>';
				echo '<td style="color:#27ae60;font-weight:600">+' . esc_html( $r['change'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $declining ) ) {
			echo '<h3 style="color:#e74c3c;margin-top:0">' . esc_html__( '↓ Declining', 'seo-agent-ai' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Keyword', 'seo-agent-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Page', 'seo-agent-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Change', 'seo-agent-ai' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $declining, 0, 5 ) as $r ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $r['keyword'] ) . '</strong></td>';
				echo '<td>' . esc_html( $r['post_title'] ) . '</td>';
				echo '<td style="color:#e74c3c;font-weight:600">-' . esc_html( $r['change'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	// -------------------------------------------------------------------
	// Score distribution
	// -------------------------------------------------------------------

	private function render_score_distribution( array $dist ) {
		if ( empty( $dist ) ) {
			echo '<p>' . esc_html__( 'No score data yet. Wait for the weekly scoring cron or run it manually via Cron Status.', 'seo-agent-ai' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-cron' ) ) . '">' . esc_html__( 'Go to Cron Status →', 'seo-agent-ai' ) . '</a></p>';
			return;
		}

		$labels = array(
			'excellent' => __( 'Excellent (80-100)', 'seo-agent-ai' ),
			'good'      => __( 'Good (60-79)', 'seo-agent-ai' ),
			'average'   => __( 'Average (40-59)', 'seo-agent-ai' ),
			'poor'      => __( 'Poor (20-39)', 'seo-agent-ai' ),
			'critical'  => __( 'Critical (0-19)', 'seo-agent-ai' ),
		);
		$colors = array(
			'excellent' => '#2ecc71',
			'good'      => '#27ae60',
			'average'   => '#f39c12',
			'poor'      => '#e67e22',
			'critical'  => '#e74c3c',
		);

		$total = array_sum( $dist );

		echo '<div class="seo-score-dist">';
		foreach ( $labels as $key => $label ) {
			$count = (int) ( $dist[ $key ] ?? 0 );
			$pct   = $total > 0 ? round( $count / $total * 100 ) : 0;
			echo '<div class="dist-row">';
			echo '<span class="dist-label">' . esc_html( $label ) . '</span>';
			echo '<div class="dist-bar-wrap">';
			echo '<div class="dist-bar" style="width:' . esc_attr( $pct ) . '%;background:' . esc_attr( $colors[ $key ] ) . '"></div>';
			echo '</div>';
			echo '<span class="dist-count">' . esc_html( $count ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}
}
