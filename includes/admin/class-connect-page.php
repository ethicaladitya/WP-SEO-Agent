<?php
/**
 * "Connect Google" admin page.
 *
 * Handles three states:
 *   1. Not configured — client ID / secret not saved yet.
 *   2. Configured but not connected — credentials saved, no tokens.
 *   3. Connected — refresh token present, shows account details.
 *
 * Also processes the OAuth callback (?code=, ?state=) when Google redirects
 * back to this page after the user grants consent.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Connect_Page {

	/** @var SEO_Agent_AI_Google_OAuth */
	private $oauth;

	public function __construct( SEO_Agent_AI_Google_OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_configured = $this->oauth->is_configured();
		$is_connected  = $this->oauth->is_connected();
		$email         = $this->oauth->get_connected_email();
		$redirect_uri  = $this->oauth->get_redirect_uri();

		// Always-visible auth health probe. Cached for 60s in a transient so
		// repeated page loads don't hit Google's token endpoint each time.
		$health = $this->probe_auth_health();

		// Notice from a previous action (e.g. disconnect, connect).
		$notice = filter_input( INPUT_GET, 'seo_agent_ai_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$notice = is_string( $notice ) ? sanitize_key( wp_unslash( $notice ) ) : '';

		// OAuth error passed back via admin_init redirect.
		$oauth_error = filter_input( INPUT_GET, 'seo_agent_ai_oauth_error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$oauth_error = is_string( $oauth_error ) ? rawurldecode( sanitize_text_field( wp_unslash( $oauth_error ) ) ) : '';
		?>
		<div class="wrap seo-agent-wrap">
			<h1><?php esc_html_e( 'Connect Google Account', 'seo-agent-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'SEO Agent AI needs access to Google Search Console and Google Analytics to analyze your content performance.', 'seo-agent-ai' ); ?>
			</p>

			<?php if ( $oauth_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $oauth_error ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'google_disconnected' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account disconnected.', 'seo-agent-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'google_connected' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account connected successfully!', 'seo-agent-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $is_connected && ! $health['ok'] ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Authentication is failing:', 'seo-agent-ai' ); ?></strong>
						<?php echo esc_html( $health['message'] ); ?>
					</p>
					<?php if ( false !== strpos( strtolower( $health['message'] ), 'client secret' ) ) : ?>
						<p>
							<strong><?php esc_html_e( 'Most likely cause:', 'seo-agent-ai' ); ?></strong>
							<?php esc_html_e( 'The OAuth client secret stored here does not match what Google has on file. Rotate or regenerate the secret in Google Cloud Console, paste the new value into Settings, then click Disconnect + Sign in with Google again.', 'seo-agent-ai' ); ?>
						</p>
					<?php elseif ( false !== strpos( strtolower( $health['message'] ), 'invalid_grant' ) || false !== strpos( strtolower( $health['message'] ), 'refresh' ) ) : ?>
						<p>
							<strong><?php esc_html_e( 'Most likely cause:', 'seo-agent-ai' ); ?></strong>
							<?php esc_html_e( 'The refresh token has been revoked. Click Disconnect + Sign in with Google to re-authorize.', 'seo-agent-ai' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php elseif ( $is_connected && $health['ok'] ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Authentication health check passed: access token can be refreshed successfully.', 'seo-agent-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Connection status card -->
			<div class="seo-agent-card">
				<?php if ( $is_connected ) : ?>
					<div class="seo-agent-connect-status connected">
						<span class="dashicons dashicons-yes-alt"></span>
						<div>
							<strong><?php esc_html_e( 'Google Account Connected', 'seo-agent-ai' ); ?></strong>
							<?php if ( $email ) : ?>
								<p style="margin:2px 0 0;font-size:13px;color:#3c434a;">
									<?php echo esc_html( $email ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'seo_agent_ai_google_disconnect' ); ?>
						<input type="hidden" name="action" value="seo_agent_ai_google_disconnect" />
						<button type="submit" class="button button-secondary"
							onclick="return confirm('<?php esc_attr_e( 'Disconnect your Google account? The agent will stop collecting data until reconnected.', 'seo-agent-ai' ); ?>')">
							<?php esc_html_e( 'Disconnect Google Account', 'seo-agent-ai' ); ?>
						</button>
					</form>

				<?php elseif ( $is_configured ) : ?>
					<div class="seo-agent-connect-status disconnected">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong><?php esc_html_e( 'Not Connected', 'seo-agent-ai' ); ?></strong>
							<p style="margin:2px 0 0;font-size:13px;color:#3c434a;">
								<?php esc_html_e( 'Click below to authorize SEO Agent AI with your Google account.', 'seo-agent-ai' ); ?>
							</p>
						</div>
					</div>

					<?php
					$auth_url = $this->oauth->get_authorize_url();
					if ( ! is_wp_error( $auth_url ) ) :
						?>
						<a href="<?php echo esc_url( $auth_url ); ?>" class="seo-agent-google-btn">
							<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
								<path fill="#fff" d="M17.64 9.2c0-.638-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z"/>
								<path fill="#fff" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/>
								<path fill="#fff" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>
								<path fill="#fff" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"/>
							</svg>
							<?php esc_html_e( 'Sign in with Google', 'seo-agent-ai' ); ?>
						</a>
					<?php endif; ?>

				<?php else : ?>
					<div class="notice notice-warning inline" style="margin:0 0 16px;">
						<p>
							<?php
							printf(
								/* translators: %s: settings page link */
								esc_html__( 'You need to save your OAuth Client ID and Client Secret before connecting. %s', 'seo-agent-ai' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) . '">' . esc_html__( 'Open Settings', 'seo-agent-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Setup instructions -->
			<div class="seo-agent-card">
				<h2><?php esc_html_e( 'Setup Instructions', 'seo-agent-ai' ); ?></h2>
				<ol style="font-size:13px;line-height:1.8;max-width:700px;">
					<li><?php esc_html_e( 'Go to Google Cloud Console → Create (or select) a project.', 'seo-agent-ai' ); ?></li>
					<li><?php esc_html_e( 'Enable the following APIs: Google Search Console API, Google Analytics Data API.', 'seo-agent-ai' ); ?></li>
					<li><?php esc_html_e( 'Go to APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID.', 'seo-agent-ai' ); ?></li>
					<li><?php esc_html_e( 'Application type: Web application.', 'seo-agent-ai' ); ?></li>
					<li>
						<?php esc_html_e( 'Add this Authorized Redirect URI:', 'seo-agent-ai' ); ?>
						<br />
						<code class="seo-agent-redirect-uri"><?php echo esc_html( $redirect_uri ); ?></code>
					</li>
					<li>
						<?php
						printf(
							/* translators: %s: settings page link */
							esc_html__( 'Save the Client ID and Client Secret in %s.', 'seo-agent-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'seo-agent-ai' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Return here and click "Sign in with Google".', 'seo-agent-ai' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * Quick auth-health probe used to render the persistent error banner on
	 * the Connect page. Cached in a 60-second transient so we never hit
	 * Google's token endpoint more than once a minute on page reloads.
	 *
	 * @return array{ok:bool,message:string}
	 */
	private function probe_auth_health() {
		$cache_key = 'seo_agent_ai_auth_health';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['ok'], $cached['message'] ) ) {
			return $cached;
		}

		if ( ! $this->oauth->is_connected() ) {
			$out = array( 'ok' => false, 'message' => __( 'No refresh token stored. Sign in with Google.', 'seo-agent-ai' ) );
			set_transient( $cache_key, $out, 30 );
			return $out;
		}

		$token = $this->oauth->get_access_token();
		if ( is_wp_error( $token ) ) {
			$out = array( 'ok' => false, 'message' => $token->get_error_message() );
		} else {
			$out = array( 'ok' => true, 'message' => '' );
		}
		set_transient( $cache_key, $out, 30 );
		return $out;
	}
}
