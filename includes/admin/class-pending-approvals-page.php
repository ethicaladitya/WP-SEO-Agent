<?php
/**
 * Pending AI Decision Approvals admin page.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Pending_Approvals_Page {

	/** @var SEO_Agent_AI_Decision_Engine */
	private $decision_engine;

	/** @var SEO_Agent_AI_Fix_Executor */
	private $fix_executor;

	/** @var SEO_Agent_AI_Internal_Link_Engine */
	private $link_engine;

	public function __construct(
		SEO_Agent_AI_Decision_Engine $decision_engine,
		SEO_Agent_AI_Fix_Executor $fix_executor,
		SEO_Agent_AI_Internal_Link_Engine $link_engine
	) {
		$this->decision_engine = $decision_engine;
		$this->fix_executor    = $fix_executor;
		$this->link_engine     = $link_engine;
	}

	/**
	 * Handle approve/reject POST actions.
	 * Called via admin_post_seo_agent_ai_decision_{action}.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'seo-agent-ai' ) );
		}

		$action      = sanitize_key( $_POST['seo_action'] ?? '' );
		$decision_id = (int) ( $_POST['decision_id'] ?? 0 );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'seo_agent_ai_decision_' . $decision_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'seo-agent-ai' ) );
		}

		if ( $action === 'approve' ) {
			$this->decision_engine->approve( $decision_id );
			$this->execute_decision( $decision_id );
		} elseif ( $action === 'reject' ) {
			$this->decision_engine->reject( $decision_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seo-agent-approvals&updated=1' ) );
		exit;
	}

	/**
	 * Execute the actual change for an approved decision.
	 * Routes to the correct engine based on decision_type.
	 */
	private function execute_decision( $decision_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'seo_agent_ai_decisions';
		$dec   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $decision_id ), ARRAY_A );

		if ( ! $dec ) {
			return;
		}

		$post_id = (int) $dec['post_id'];
		$type    = $dec['decision_type'] ?? '';

		switch ( $type ) {
			case 'meta_update':
			case 'monitor_decline':
				// Reconstruct recommendation from stored decision fields.
				$field   = $dec['field'] ?? '';
				$value   = $dec['proposed_value'] ?? '';
				$proposed = array();
				if ( $field === 'meta_title' ) {
					$proposed['meta_title'] = $value;
				} elseif ( $field === 'meta_description' ) {
					$proposed['meta_description'] = $value;
				} else {
					// Try to decode JSON payload (multi-field decisions).
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						$proposed = $decoded;
					}
				}
				if ( ! empty( $proposed ) ) {
					$this->fix_executor->apply(
						$post_id,
						array(
							'type'       => $type,
							'risk'       => 'safe',
							'proposed'   => $proposed,
							'reason'     => $dec['reasoning'] ?? '',
							'confidence' => (float) ( $dec['confidence'] ?? 0.7 ),
						),
						'manual'
					);
				}
				break;

			case 'internal_link_needed':
				// Find other posts that can link to this post and insert up to 3 links.
				$this->link_engine->run_for_post( $post_id );
				break;

			case 'schema_update':
				// Schema is injected via wp_head when enabled — store a flag so schema engine activates for this post.
				update_post_meta( $post_id, '_seo_agent_ai_schema_approved', 1 );
				break;
		}

		// Mark as applied in DB.
		$this->decision_engine->mark_applied( $decision_id );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$single_id = isset( $_GET['decision_id'] ) ? (int) $_GET['decision_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page  = 15;

		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Decision updated successfully.', 'seo-agent-ai' ) . '</p></div>';
		}

		?>
		<div class="wrap seo-agent-ai-approvals">
			<h1><?php esc_html_e( 'Pending Approvals', 'seo-agent-ai' ); ?></h1>
			<?php
			if ( $single_id > 0 ) {
				$this->render_single_decision( $single_id );
			} else {
				$this->render_decisions_list( $paged, $per_page );
			}
			?>
		</div>
		<?php
	}

	private function render_decisions_list( $paged, $per_page ) {
		$decisions = SEO_Agent_AI_DB_Manager::get_decisions( array(
			'status' => SEO_Agent_AI_DB_Manager::STATUS_PENDING,
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		) );
		$total = SEO_Agent_AI_DB_Manager::count_decisions( SEO_Agent_AI_DB_Manager::STATUS_PENDING );

		echo '<p class="description">';
		echo esc_html( sprintf(
			/* translators: %d: number of pending approvals. */
			__( '%d decisions pending your review.', 'seo-agent-ai' ),
			$total
		) );
		echo '</p>';

		if ( empty( $decisions ) ) {
			echo '<p>' . esc_html__( 'No decisions pending. All caught up!', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Proposed', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Risk', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $decisions as $dec ) {
			$post_id    = (int) $dec['post_id'];
			$post       = get_post( $post_id );
			$title      = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";
			$confidence = round( (float) $dec['confidence'] * 100 );

			echo '<tr>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-approvals&decision_id=' . (int) $dec['id'] ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td><code>' . esc_html( $dec['decision_type'] ?? '' ) . '</code></td>';
			echo '<td><span class="proposed-preview">' . esc_html( wp_trim_words( $dec['proposed_value'] ?? '', 10 ) ) . '</span></td>';
			echo '<td>' . esc_html( $confidence ) . '%</td>';
			echo '<td><span class="risk-badge ' . esc_attr( $dec['risk_level'] ?? '' ) . '">' . esc_html( $dec['risk_level'] ?? '' ) . '</span></td>';
			echo '<td>' . $this->action_buttons( (int) $dec['id'] ) . '</td>';  // phpcs:ignore WordPress.Security.EscapeOutput -- action_buttons outputs escaped HTML.
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Pagination.
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
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

	private function render_single_decision( $decision_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'seo_agent_ai_decisions';
		$dec   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $decision_id ), ARRAY_A );

		if ( ! $dec ) {
			echo '<p>' . esc_html__( 'Decision not found.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		$post_id = (int) $dec['post_id'];
		$post    = get_post( $post_id );
		$title   = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";

		echo '<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-approvals' ) ) . '" class="button">&larr; ' . esc_html__( 'Back to list', 'seo-agent-ai' ) . '</a>';
		echo '<br><br>';

		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th><td><strong>' . esc_html( $title ) . '</strong>';
		if ( $post instanceof WP_Post ) {
			echo ' <a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank">' . esc_html__( 'View', 'seo-agent-ai' ) . '</a>';
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Decision Type', 'seo-agent-ai' ) . '</th><td><code>' . esc_html( $dec['decision_type'] ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Field', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['field'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Current Value', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['current_value'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Proposed Value', 'seo-agent-ai' ) . '</th><td><strong>' . esc_html( $dec['proposed_value'] ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__( 'Confidence', 'seo-agent-ai' ) . '</th><td>' . esc_html( round( (float) $dec['confidence'] * 100 ) ) . '%</td></tr>';
		echo '<tr><th>' . esc_html__( 'Risk', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['risk_level'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Expected Impact', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['expected_impact'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Reasoning', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['reasoning'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Created', 'seo-agent-ai' ) . '</th><td>' . esc_html( $dec['created_at'] ) . '</td></tr>';
		echo '</table>';

		if ( $dec['status'] === SEO_Agent_AI_DB_Manager::STATUS_PENDING ) {
			echo '<p class="submit">' . $this->action_buttons( $decision_id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private function action_buttons( $decision_id ) {
		$nonce = wp_create_nonce( 'seo_agent_ai_decision_' . $decision_id );
		$url   = admin_url( 'admin-post.php' );

		$approve = '<form method="post" action="' . esc_url( $url ) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="seo_agent_ai_decision">'
			. '<input type="hidden" name="seo_action" value="approve">'
			. '<input type="hidden" name="decision_id" value="' . esc_attr( $decision_id ) . '">'
			. wp_nonce_field( 'seo_agent_ai_decision_' . $decision_id, '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Approve', 'seo-agent-ai' ) . '</button>'
			. '</form>';

		$reject = '<form method="post" action="' . esc_url( $url ) . '" style="display:inline;margin-left:6px">'
			. '<input type="hidden" name="action" value="seo_agent_ai_decision">'
			. '<input type="hidden" name="seo_action" value="reject">'
			. '<input type="hidden" name="decision_id" value="' . esc_attr( $decision_id ) . '">'
			. wp_nonce_field( 'seo_agent_ai_decision_' . $decision_id, '_wpnonce', true, false )
			. '<button type="submit" class="button">' . esc_html__( 'Reject', 'seo-agent-ai' ) . '</button>'
			. '</form>';

		return $approve . $reject;
	}
}
