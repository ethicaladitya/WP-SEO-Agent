<?php
/**
 * Rollback Center admin page.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Rollback_Center_Page {

	/** @var SEO_Agent_AI_Fix_Executor */
	private $fix_executor;

	/** @var SEO_Agent_AI_Activity_Log */
	private $activity_log;

	public function __construct( SEO_Agent_AI_Fix_Executor $fix_executor, SEO_Agent_AI_Activity_Log $activity_log ) {
		$this->fix_executor = $fix_executor;
		$this->activity_log = $activity_log;
	}

	/**
	 * Handle rollback POST action.
	 */
	public function handle_rollback() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'seo-agent-ai' ) );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'seo_agent_ai_rollback_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'seo-agent-ai' ) );
		}

		$result = $this->fix_executor->rollback( $post_id );

		if ( is_wp_error( $result ) ) {
			$redirect = admin_url( 'admin.php?page=seo-agent-rollback&error=' . rawurlencode( $result->get_error_message() ) );
		} else {
			$redirect = admin_url( 'admin.php?page=seo-agent-rollback&rolled_back=' . $post_id );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! empty( $_GET['rolled_back'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$pid = (int) $_GET['rolled_back']; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Post #%d rolled back successfully.', 'seo-agent-ai' ), $pid ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( urldecode( $_GET['error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		?>
		<div class="wrap seo-agent-ai-rollback">
			<h1><?php esc_html_e( 'Rollback Center', 'seo-agent-ai' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Restore any post\'s meta title and description to their last backup snapshot.', 'seo-agent-ai' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="seo-agent-rollback">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by post title...', 'seo-agent-ai' ); ?>">
				<?php submit_button( __( 'Search', 'seo-agent-ai' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php $this->render_activity_log( $search, $paged ); ?>
		</div>
		<?php
	}

	private function render_activity_log( $search, $paged ) {
		$per_page = 20;

		// Build filters for the activity log. If searching by title, resolve post IDs first.
		$filters = array();
		if ( $search !== '' ) {
			$matching_posts = get_posts( array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => 50,
				's'              => $search,
				'fields'         => 'ids',
			) );
			if ( empty( $matching_posts ) ) {
				echo '<p>' . esc_html__( 'No activity log entries found.', 'seo-agent-ai' ) . '</p>';
				return;
			}
			// Filter to first matching post ID (activity log supports singular post_id only).
			$filters['post_id'] = (int) $matching_posts[0];
		}

		$rows  = $this->activity_log->get_entries( $filters, $paged, $per_page );
		$total = $this->activity_log->get_count( $filters );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No activity log entries found.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Field', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Previous Value', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'New Value', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Applied', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Track per-post backup availability.
		$backup_status = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			$title   = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";

			// Check if rollback is available (backup exists).
			if ( ! isset( $backup_status[ $post_id ] ) ) {
				$preview = $this->fix_executor->rollback( $post_id, true );
				$backup_status[ $post_id ] = ! is_wp_error( $preview );
			}
			$has_backup = $backup_status[ $post_id ];

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td><code>' . esc_html( $row['change_type'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $row['field_changed'] ?? '' ) . '</td>';
			echo '<td class="value-cell">' . esc_html( wp_trim_words( $row['value_before'] ?? '', 10 ) ) . '</td>';
			echo '<td class="value-cell">' . esc_html( wp_trim_words( $row['value_after'] ?? '', 10 ) ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ?? '' ) . '</td>';
			echo '<td>';
			if ( $has_backup ) {
				$nonce = wp_create_nonce( 'seo_agent_ai_rollback_' . $post_id );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Restore previous meta values for this post?', 'seo-agent-ai' ) ) . '\')">';
				echo '<input type="hidden" name="action" value="seo_agent_ai_rollback">';
				echo '<input type="hidden" name="post_id" value="' . esc_attr( $post_id ) . '">';
				echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Rollback', 'seo-agent-ai' ) . '</button>';
				echo '</form>';
			} else {
				echo '<span class="description">' . esc_html__( 'No backup', 'seo-agent-ai' ) . '</span>';
			}
			echo '</td>';
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
}
