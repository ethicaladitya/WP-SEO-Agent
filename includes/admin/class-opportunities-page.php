<?php
/**
 * SEO Opportunities admin page.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Opportunities_Page {

	/** @var SEO_Agent_AI_Decision_Engine */
	private $decision_engine;

	public function __construct( SEO_Agent_AI_Decision_Engine $decision_engine ) {
		$this->decision_engine = $decision_engine;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$filter_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter_risk = isset( $_GET['risk'] ) ? sanitize_text_field( $_GET['risk'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page    = 20;

		$args = array(
			'status' => SEO_Agent_AI_DB_Manager::STATUS_PENDING,
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		);
		if ( $filter_type !== '' ) {
			$args['decision_type'] = $filter_type;
		}
		if ( $filter_risk !== '' ) {
			$args['risk_level'] = $filter_risk;
		}

		$decisions = SEO_Agent_AI_DB_Manager::get_decisions( $args );
		$total     = SEO_Agent_AI_DB_Manager::count_decisions( SEO_Agent_AI_DB_Manager::STATUS_PENDING );

		?>
		<div class="wrap seo-agent-ai-opportunities">
			<h1><?php esc_html_e( 'SEO Opportunities', 'seo-agent-ai' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of pending decisions. */
						__( '%d pending opportunities ready for review or auto-application.', 'seo-agent-ai' ),
						$total
					)
				);
				?>
			</p>

			<?php $this->render_filters( $filter_type, $filter_risk ); ?>

			<?php if ( empty( $decisions ) ) : ?>
				<p><?php esc_html_e( 'No opportunities found. Run an analysis to generate recommendations.', 'seo-agent-ai' ); ?></p>
			<?php else : ?>
				<?php $this->render_table( $decisions ); ?>
				<?php $this->render_pagination( $total, $per_page, $paged ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_filters( $filter_type, $filter_risk ) {
		$types = array(
			''                   => __( 'All Types', 'seo-agent-ai' ),
			'meta_update'        => __( 'Meta Update', 'seo-agent-ai' ),
			'content_expansion'  => __( 'Content Expansion', 'seo-agent-ai' ),
			'schema_update'      => __( 'Schema', 'seo-agent-ai' ),
			'page_two_push'      => __( 'Page 2 Push', 'seo-agent-ai' ),
			'monitor_decline'    => __( 'Decline Monitor', 'seo-agent-ai' ),
		);

		echo '<form method="get" class="seo-agent-filters">';
		echo '<input type="hidden" name="page" value="seo-agent-opportunities">';

		echo '<select name="type">';
		foreach ( $types as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $filter_type, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="risk">';
		echo '<option value=""' . selected( $filter_risk, '', false ) . '>' . esc_html__( 'All Risk Levels', 'seo-agent-ai' ) . '</option>';
		echo '<option value="safe"' . selected( $filter_risk, 'safe', false ) . '>' . esc_html__( 'Safe', 'seo-agent-ai' ) . '</option>';
		echo '<option value="risky"' . selected( $filter_risk, 'risky', false ) . '>' . esc_html__( 'Risky', 'seo-agent-ai' ) . '</option>';
		echo '</select>';

		submit_button( __( 'Filter', 'seo-agent-ai' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_table( array $decisions ) {
		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Field', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Risk', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Impact', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $decisions as $dec ) {
			$post_id    = (int) $dec['post_id'];
			$post       = get_post( $post_id );
			$title      = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";
			$confidence = round( (float) $dec['confidence'] * 100 );
			$conf_class = $confidence >= 70 ? 'conf-high' : ( $confidence >= 50 ? 'conf-med' : 'conf-low' );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td><code>' . esc_html( $dec['decision_type'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $dec['field'] ?? '' ) . '</td>';
			echo '<td><span class="confidence ' . esc_attr( $conf_class ) . '">' . esc_html( $confidence ) . '%</span></td>';
			echo '<td><span class="risk-badge ' . esc_attr( $dec['risk_level'] ?? '' ) . '">' . esc_html( $dec['risk_level'] ?? '' ) . '</span></td>';
			echo '<td>' . esc_html( $dec['expected_impact'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $dec['created_at'] ?? '' ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-approvals&decision_id=' . (int) $dec['id'] ) ) . '" class="button button-small">';
			esc_html_e( 'Review', 'seo-agent-ai' );
			echo '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_pagination( $total, $per_page, $paged ) {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav-pages">';
		echo paginate_links( array(
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => $pages,
		) );
		echo '</div>';
	}
}
