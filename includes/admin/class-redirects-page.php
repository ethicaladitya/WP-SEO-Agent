<?php
/**
 * Redirects & 404s admin page.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Redirects_Page {

	/** @var SEO_Agent_AI_Redirect_Manager */
	private $redirect_manager;

	public function __construct( SEO_Agent_AI_Redirect_Manager $redirect_manager ) {
		$this->redirect_manager = $redirect_manager;
	}

	/**
	 * Handle add/delete redirect form POST actions.
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'seo-agent-ai' ) );
		}

		$action = isset( $_POST['seo_redirect_action'] ) ? sanitize_key( $_POST['seo_redirect_action'] ) : '';

		if ( 'add' === $action ) {
			check_admin_referer( 'seo_agent_ai_add_redirect' );
			$source = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) ) : '';
			$target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
			$type   = isset( $_POST['redirect_type'] ) ? absint( $_POST['redirect_type'] ) : 301;
			$notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

			if ( $source && $target ) {
				$this->redirect_manager->add_redirect( $source, $target, $type, $notes );
			}
		} elseif ( 'delete' === $action ) {
			check_admin_referer( 'seo_agent_ai_delete_redirect' );
			$id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
			if ( $id ) {
				$this->redirect_manager->delete_redirect( $id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seo-agent-redirects' ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'redirects'; // phpcs:ignore WordPress.Security.NonceVerification
		$stats     = $this->redirect_manager->get_stats();
		$redirects = $this->redirect_manager->get_redirects( 100 );
		$log_404   = $this->redirect_manager->get_404_log( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects & 404 Monitor', 'seo-agent-ai' ); ?></h1>

			<div style="display:flex;gap:16px;margin:16px 0 24px;">
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html( number_format_i18n( $stats['total_redirects'] ) ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Active Redirects', 'seo-agent-ai' ); ?></div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#d63638;"><?php echo esc_html( number_format_i18n( $stats['total_404s'] ) ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Total 404s Logged', 'seo-agent-ai' ); ?></div>
				</div>
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#f0c33c;"><?php echo esc_html( number_format_i18n( $stats['unresolved_404s'] ) ); ?></div>
					<div style="color:#646970;"><?php esc_html_e( 'Unresolved 404s', 'seo-agent-ai' ); ?></div>
				</div>
			</div>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-redirects&tab=redirects' ) ); ?>"
					class="nav-tab <?php echo 'redirects' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Redirects', 'seo-agent-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-redirects&tab=404s' ) ); ?>"
					class="nav-tab <?php echo '404s' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '404 Log', 'seo-agent-ai' ); ?>
				</a>
			</nav>

			<?php if ( 'redirects' === $tab ) : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:20px;margin-top:0;border-top:none;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Add Redirect', 'seo-agent-ai' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'seo_agent_ai_add_redirect' ); ?>
					<input type="hidden" name="action" value="seo_agent_ai_manage_redirect">
					<input type="hidden" name="seo_redirect_action" value="add">
					<table class="form-table" style="width:auto;">
						<tr>
							<th><?php esc_html_e( 'Source URL / Path', 'seo-agent-ai' ); ?></th>
							<td><input type="text" name="source_url" class="regular-text" placeholder="/old-page/" required></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Target URL', 'seo-agent-ai' ); ?></th>
							<td><input type="url" name="target_url" class="regular-text" placeholder="https://example.com/new-page/" required></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Type', 'seo-agent-ai' ); ?></th>
							<td>
								<select name="redirect_type">
									<option value="301">301 — <?php esc_html_e( 'Permanent', 'seo-agent-ai' ); ?></option>
									<option value="302">302 — <?php esc_html_e( 'Temporary', 'seo-agent-ai' ); ?></option>
									<option value="307">307 — <?php esc_html_e( 'Temporary (preserve method)', 'seo-agent-ai' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Notes', 'seo-agent-ai' ); ?></th>
							<td><input type="text" name="notes" class="regular-text" placeholder="<?php esc_attr_e( 'Optional reason', 'seo-agent-ai' ); ?>"></td>
						</tr>
					</table>
					<p><?php submit_button( __( 'Add Redirect', 'seo-agent-ai' ), 'primary', 'submit', false ); ?></p>
				</form>

				<?php if ( $redirects ) : ?>
				<h3><?php esc_html_e( 'Active Redirects', 'seo-agent-ai' ); ?></h3>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Target', 'seo-agent-ai' ); ?></th>
							<th style="width:60px;"><?php esc_html_e( 'Type', 'seo-agent-ai' ); ?></th>
							<th style="width:60px;"><?php esc_html_e( 'Hits', 'seo-agent-ai' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Last Hit', 'seo-agent-ai' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Action', 'seo-agent-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $redirects as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r['source_url'] ); ?></code></td>
							<td><a href="<?php echo esc_url( $r['target_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['target_url'] ); ?></a></td>
							<td><?php echo esc_html( $r['redirect_type'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $r['hit_count'] ) ); ?></td>
							<td><?php echo esc_html( $r['last_hit'] ?? '—' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'seo_agent_ai_delete_redirect' ); ?>
									<input type="hidden" name="action" value="seo_agent_ai_manage_redirect">
									<input type="hidden" name="seo_redirect_action" value="delete">
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $r['id'] ); ?>">
									<button type="submit" class="button button-small button-link-delete"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'seo-agent-ai' ) ); ?>')">
										<?php esc_html_e( 'Delete', 'seo-agent-ai' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
					<p style="color:#646970;"><?php esc_html_e( 'No redirects configured yet.', 'seo-agent-ai' ); ?></p>
				<?php endif; ?>
			</div>

			<?php else : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:20px;margin-top:0;border-top:none;">
				<h3 style="margin-top:0;"><?php esc_html_e( '404 Error Log', 'seo-agent-ai' ); ?></h3>
				<?php if ( $log_404 ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'seo-agent-ai' ); ?></th>
							<th style="width:60px;"><?php esc_html_e( 'Hits', 'seo-agent-ai' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'First Seen', 'seo-agent-ai' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Last Seen', 'seo-agent-ai' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Action', 'seo-agent-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log_404 as $e ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $e['url'] ); ?></code>
								<?php if ( $e['referrer'] ) : ?>
									<br><small style="color:#646970;"><?php echo esc_html__( 'Referrer:', 'seo-agent-ai' ); ?> <?php echo esc_html( $e['referrer'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $e['hit_count'] ) ); ?></td>
							<td><?php echo esc_html( $e['first_seen'] ); ?></td>
							<td><?php echo esc_html( $e['last_seen'] ); ?></td>
							<td>
								<?php if ( ! $e['redirect_created'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-redirects&tab=redirects&prefill=' . rawurlencode( $e['url'] ) ) ); ?>"
									class="button button-small">
									<?php esc_html_e( '+ Create Redirect', 'seo-agent-ai' ); ?>
								</a>
								<?php else : ?>
									<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Redirected', 'seo-agent-ai' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
					<p style="color:#646970;"><?php esc_html_e( 'No 404 errors logged yet. They appear here automatically when visitors hit broken URLs.', 'seo-agent-ai' ); ?></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>

		<script>
		(function($){
			// Pre-fill source URL from 404 log link.
			var params = new URLSearchParams(window.location.search);
			var prefill = params.get('prefill');
			if ( prefill ) {
				$('input[name="source_url"]').val(decodeURIComponent(prefill));
			}
		}(jQuery));
		</script>
		<?php
	}
}
