<?php
/**
 * Main SEO Dashboard admin page.
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

	public function __construct(
		SEO_Agent_AI_Decision_Engine $decision_engine,
		SEO_Agent_AI_Report_Engine $report_engine
	) {
		$this->decision_engine = $decision_engine;
		$this->report_engine   = $report_engine;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$report         = $this->report_engine->get( gmdate( 'Y-m-d' ) );
		$pending_count  = $this->decision_engine->count_pending();
		$insights       = SEO_Agent_AI_DB_Manager::get_all_latest_insights( 10 );
		$score_dist     = $report ? ( $report['score_distribution'] ?? array() ) : array();
		$trends         = $report ? ( $report['trends'] ?? array() ) : array();
		$summary        = $report ? ( $report['summary'] ?? array() ) : array();

		$avg_score = 0;
		if ( ! empty( $insights ) ) {
			$avg_score = round( array_sum( array_column( $insights, 'score_overall' ) ) / count( $insights ) );
		}

		?>
		<div class="wrap seo-agent-ai-dashboard">
			<h1><?php esc_html_e( 'SEO Agent Dashboard', 'seo-agent-ai' ); ?></h1>

			<?php $this->render_summary_widgets( $summary, $pending_count, $avg_score ); ?>

			<div class="seo-agent-widget-grid" style="margin-top:20px">
				<div class="seo-agent-widget">
					<h2><?php esc_html_e( 'Score Distribution', 'seo-agent-ai' ); ?></h2>
					<?php $this->render_score_distribution( $score_dist ); ?>
				</div>

				<div class="seo-agent-widget">
					<h2><?php esc_html_e( 'Keyword Trends', 'seo-agent-ai' ); ?></h2>
					<?php $this->render_trends( $trends ); ?>
				</div>
			</div>

			<div class="seo-agent-widget" style="margin-top:20px">
				<h2><?php esc_html_e( 'Latest SEO Scores', 'seo-agent-ai' ); ?></h2>
				<?php $this->render_insights_table( $insights ); ?>
			</div>
		</div>
		<?php
	}

	private function render_summary_widgets( $summary, $pending_count, $avg_score ) {
		$widgets = array(
			array(
				'label' => __( 'Pages Analyzed', 'seo-agent-ai' ),
				'value' => $summary['pages_analyzed'] ?? '—',
				'class' => 'info',
			),
			array(
				'label' => __( 'Optimized Today', 'seo-agent-ai' ),
				'value' => $summary['pages_optimized'] ?? '—',
				'class' => 'ok',
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
				'label' => __( 'Problems', 'seo-agent-ai' ),
				'value' => $summary['problems_detected'] ?? '—',
				'class' => ( $summary['problems_detected'] ?? 0 ) > 0 ? 'error' : 'ok',
			),
			array(
				'label' => __( 'Avg SEO Score', 'seo-agent-ai' ),
				'value' => $avg_score > 0 ? $avg_score . '/100' : '—',
				'class' => $avg_score >= 60 ? 'ok' : ( $avg_score >= 40 ? 'pending' : 'error' ),
			),
		);

		echo '<div class="seo-agent-widget-grid">';
		foreach ( $widgets as $w ) {
			$val  = is_int( $w['value'] ) ? number_format_i18n( $w['value'] ) : esc_html( (string) $w['value'] );
			$mod  = 'widget-' . esc_attr( $w['class'] );
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

	private function render_score_distribution( array $dist ) {
		if ( empty( $dist ) ) {
			echo '<p>' . esc_html__( 'No score data yet. Run a full analysis to populate.', 'seo-agent-ai' ) . '</p>';
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

	private function render_trends( array $trends ) {
		$rising   = $trends['rising'] ?? array();
		$declining = $trends['declining'] ?? array();

		if ( empty( $rising ) && empty( $declining ) ) {
			echo '<p>' . esc_html__( 'No trend data yet. Requires keyword history from GSC.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		if ( ! empty( $rising ) ) {
			echo '<h3 style="color:#27ae60">' . esc_html__( 'Rising', 'seo-agent-ai' ) . '</h3>';
			echo '<ul class="trend-list">';
			foreach ( array_slice( $rising, 0, 5 ) as $r ) {
				echo '<li><strong>' . esc_html( $r['keyword'] ) . '</strong> '
					. esc_html( $r['post_title'] ) . ' '
					. '<span class="trend-change positive">+' . esc_html( $r['change'] ) . '</span></li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $declining ) ) {
			echo '<h3 style="color:#e74c3c">' . esc_html__( 'Declining', 'seo-agent-ai' ) . '</h3>';
			echo '<ul class="trend-list">';
			foreach ( array_slice( $declining, 0, 5 ) as $r ) {
				echo '<li><strong>' . esc_html( $r['keyword'] ) . '</strong> '
					. esc_html( $r['post_title'] ) . ' '
					. '<span class="trend-change negative">-' . esc_html( $r['change'] ) . '</span></li>';
			}
			echo '</ul>';
		}
	}

	private function render_insights_table( array $insights ) {
		if ( empty( $insights ) ) {
			echo '<p>' . esc_html__( 'No SEO score snapshots yet. Run wp seo-agent score or wait for the weekly cron.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Overall', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Metadata', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Content', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Schema', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'CTR', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $insights as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			$title   = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";
			$score   = (int) $row['score_overall'];
			$class   = $score >= 60 ? 'score-good' : ( $score >= 40 ? 'score-avg' : 'score-poor' );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank">' . esc_html( $title ) . '</a></td>';
			echo '<td><span class="seo-score ' . esc_attr( $class ) . '">' . esc_html( $score ) . '</span></td>';
			echo '<td>' . esc_html( $row['score_metadata'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $row['score_content'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $row['score_schema'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $row['score_ctr'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $row['recorded_at'] ?? '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
