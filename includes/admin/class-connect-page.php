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

		$sitekit_active    = class_exists( 'SEO_Agent_AI_SiteKit_Bridge' ) && SEO_Agent_AI_SiteKit_Bridge::is_active();
		$sitekit_installed = defined( 'GOOGLESITEKIT_VERSION' );

		// Only probe OAuth health when Site Kit is not handling auth.
		$health = ! $sitekit_active ? $this->probe_auth_health() : array( 'ok' => true, 'message' => '' );

		$notice = filter_input( INPUT_GET, 'seo_agent_ai_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$notice = is_string( $notice ) ? sanitize_key( wp_unslash( $notice ) ) : '';

		$oauth_error = filter_input( INPUT_GET, 'seo_agent_ai_oauth_error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$oauth_error = is_string( $oauth_error ) ? rawurldecode( sanitize_text_field( wp_unslash( $oauth_error ) ) ) : '';
		?>
		<div class="wrap seo-agent-wrap">
			<h1><?php esc_html_e( 'Connect Google Account', 'seo-agent-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'SEO Agent AI needs access to Google Search Console and Google Analytics to analyze your content performance. Choose one of the two methods below.', 'seo-agent-ai' ); ?>
			</p>

			<?php if ( 'google_disconnected' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account disconnected.', 'seo-agent-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'google_connected' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account connected successfully!', 'seo-agent-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $oauth_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $oauth_error ); ?></p></div>
			<?php endif; ?>

			<?php // ---------------------------------------------------------------
			// OPTION A — Google Site Kit (automatic, zero-config)
			// --------------------------------------------------------------- ?>
			<div class="seo-agent-card" style="border-left:4px solid <?php echo $sitekit_active ? '#27ae60' : ( $sitekit_installed ? '#f39c12' : '#0073aa' ); ?>;">
				<h2 style="margin-top:0">
					<?php if ( $sitekit_active ) : ?>
						<span style="color:#27ae60">&#10003;</span>
					<?php else : ?>
						<span style="color:#aaa">&#9312;</span>
					<?php endif; ?>
					<?php esc_html_e( 'Option A — Google Site Kit (Recommended)', 'seo-agent-ai' ); ?>
				</h2>

				<?php if ( $sitekit_active ) : ?>
					<div class="seo-agent-connect-status connected" style="margin-bottom:12px">
						<span class="dashicons dashicons-yes-alt"></span>
						<div>
							<strong><?php esc_html_e( 'Connected via Site Kit — no manual setup needed.', 'seo-agent-ai' ); ?></strong>
							<p style="margin:4px 0 0;font-size:13px;color:#3c434a">
								<?php
								printf(
									/* translators: 1: GSC property URL  2: GA4 property ID */
									esc_html__( 'Search Console: %1$s — Analytics property: %2$s', 'seo-agent-ai' ),
									'<code>' . esc_html( SEO_Agent_AI_SiteKit_Bridge::get_gsc_site_url() ) . '</code>',
									'<code>' . esc_html( SEO_Agent_AI_SiteKit_Bridge::get_ga4_property_id() ) . '</code>'
								);
								?>
							</p>
						</div>
					</div>
					<p style="font-size:13px;color:#555;margin:0">
						<?php esc_html_e( 'SEO Agent AI is reading your Search Console and Analytics data directly from Site Kit. All data collection is active.', 'seo-agent-ai' ); ?>
					</p>

				<?php elseif ( $sitekit_installed ) : ?>
					<div class="seo-agent-connect-status disconnected" style="margin-bottom:12px">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong><?php esc_html_e( 'Site Kit is installed but not fully connected.', 'seo-agent-ai' ); ?></strong>
							<p style="margin:4px 0 0;font-size:13px;color:#3c434a">
								<?php esc_html_e( 'Complete the Site Kit setup wizard so SEO Agent AI can read your data automatically.', 'seo-agent-ai' ); ?>
							</p>
						</div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=googlesitekit-splash' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Complete Site Kit Setup →', 'seo-agent-ai' ); ?>
					</a>

				<?php else : ?>
					<p style="font-size:13px;color:#555;margin:0 0 12px">
						<?php esc_html_e( 'Install the free Google Site Kit plugin. Once you connect it, SEO Agent AI automatically reads your Search Console and Analytics data — no API keys or OAuth credentials required.', 'seo-agent-ai' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=google+site+kit&tab=search&type=term' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Install Google Site Kit →', 'seo-agent-ai' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php // ---------------------------------------------------------------
			// OPTION B — Manual OAuth (own Google Cloud credentials)
			// Hidden if Site Kit is already active to avoid confusion.
			// --------------------------------------------------------------- ?>
			<?php if ( ! $sitekit_active ) : ?>
			<div class="seo-agent-card" style="border-left:4px solid <?php echo $is_connected ? '#27ae60' : '#ccc'; ?>;">
				<h2 style="margin-top:0">
					<span style="color:#aaa">&#9313;</span>
					<?php esc_html_e( 'Option B — Manual OAuth (own Google Cloud project)', 'seo-agent-ai' ); ?>
				</h2>
				<p style="font-size:13px;color:#555;margin:0 0 14px">
					<?php esc_html_e( 'Use your own Google Cloud OAuth credentials. Useful when you already have a project set up or prefer not to use Site Kit.', 'seo-agent-ai' ); ?>
				</p>

				<?php if ( $is_connected && ! $health['ok'] ) : ?>
					<div class="notice notice-error inline" style="margin:0 0 12px">
						<p>
							<strong><?php esc_html_e( 'Authentication failing:', 'seo-agent-ai' ); ?></strong>
							<?php echo esc_html( $health['message'] ); ?>
						</p>
						<?php if ( false !== strpos( strtolower( $health['message'] ), 'client secret' ) ) : ?>
							<p><?php esc_html_e( 'The OAuth secret stored here no longer matches Google. Regenerate it in Google Cloud Console and re-enter it in Settings, then reconnect.', 'seo-agent-ai' ); ?></p>
						<?php elseif ( false !== strpos( strtolower( $health['message'] ), 'invalid_grant' ) || false !== strpos( strtolower( $health['message'] ), 'refresh' ) ) : ?>
							<p><?php esc_html_e( 'The refresh token was revoked. Disconnect and sign in again.', 'seo-agent-ai' ); ?></p>
						<?php endif; ?>
					</div>
				<?php elseif ( $is_connected && $health['ok'] ) : ?>
					<div class="notice notice-success inline" style="margin:0 0 12px">
						<p><?php esc_html_e( 'Access token refreshes successfully.', 'seo-agent-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $is_connected ) : ?>
					<div class="seo-agent-connect-status connected" style="margin-bottom:12px">
						<span class="dashicons dashicons-yes-alt"></span>
						<div>
							<strong><?php esc_html_e( 'Google Account Connected', 'seo-agent-ai' ); ?></strong>
							<?php if ( $email ) : ?>
								<p style="margin:2px 0 0;font-size:13px;color:#3c434a"><?php echo esc_html( $email ); ?></p>
							<?php endif; ?>
						</div>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'seo_agent_ai_google_disconnect' ); ?>
						<input type="hidden" name="action" value="seo_agent_ai_google_disconnect" />
						<button type="submit" class="button button-secondary"
							onclick="return confirm('<?php esc_attr_e( 'Disconnect your Google account?', 'seo-agent-ai' ); ?>')">
							<?php esc_html_e( 'Disconnect Google Account', 'seo-agent-ai' ); ?>
						</button>
					</form>

				<?php elseif ( $is_configured ) : ?>
					<div class="seo-agent-connect-status disconnected" style="margin-bottom:12px">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong><?php esc_html_e( 'Credentials saved — not yet connected', 'seo-agent-ai' ); ?></strong>
							<p style="margin:2px 0 0;font-size:13px;color:#3c434a">
								<?php esc_html_e( 'Click Sign in with Google to complete the OAuth flow.', 'seo-agent-ai' ); ?>
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
					<p style="font-size:13px;color:#555;margin:0 0 8px">
						<?php
						printf(
							/* translators: %s: settings page link */
							esc_html__( 'Save your OAuth Client ID and Client Secret in %s first, then return here to sign in.', 'seo-agent-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'seo-agent-ai' ) . '</a>'
						);
						?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=seo-agent-ai-settings' ) ); ?>" class="button">
						<?php esc_html_e( 'Open Settings', 'seo-agent-ai' ); ?>
					</a>
				<?php endif; ?>

				<details style="margin-top:16px">
					<summary style="cursor:pointer;font-size:13px;color:#0073aa"><?php esc_html_e( 'How to set up a Google Cloud OAuth project', 'seo-agent-ai' ); ?></summary>
					<ol style="font-size:13px;line-height:1.8;max-width:680px;margin-top:10px">
						<li><?php esc_html_e( 'Google Cloud Console → create or select a project.', 'seo-agent-ai' ); ?></li>
						<li><?php esc_html_e( 'Enable: Google Search Console API and Google Analytics Data API.', 'seo-agent-ai' ); ?></li>
						<li><?php esc_html_e( 'APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID.', 'seo-agent-ai' ); ?></li>
						<li><?php esc_html_e( 'Application type: Web application.', 'seo-agent-ai' ); ?></li>
						<li>
							<?php esc_html_e( 'Add this as an Authorized Redirect URI:', 'seo-agent-ai' ); ?>
							<br />
							<code class="seo-agent-redirect-uri"><?php echo esc_html( $redirect_uri ); ?></code>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: settings page link */
								esc_html__( 'Paste the Client ID and Client Secret into %s.', 'seo-agent-ai' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'seo-agent-ai' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Return here and click "Sign in with Google".', 'seo-agent-ai' ); ?></li>
					</ol>
				</details>
			</div>
			<?php endif; ?>

			<?php if ( $sitekit_active ) : ?>
				<p style="font-size:12px;color:#888;margin-top:4px">
					<?php esc_html_e( 'Manual OAuth (Option B) is hidden because Site Kit is already handling authentication.', 'seo-agent-ai' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'show_oauth', '1' ) ); ?>"><?php esc_html_e( 'Show anyway', 'seo-agent-ai' ); ?></a>
				</p>

				<?php if ( isset( $_GET['show_oauth'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="seo-agent-card" style="border-left:4px solid #ccc;opacity:.85">
					<h2 style="margin-top:0;color:#888"><?php esc_html_e( 'Option B — Manual OAuth (inactive while Site Kit is connected)', 'seo-agent-ai' ); ?></h2>
					<?php if ( $is_connected ) : ?>
						<p style="color:#555;font-size:13px"><?php esc_html_e( 'Manual OAuth credentials are also saved. Site Kit takes priority.', 'seo-agent-ai' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'seo_agent_ai_google_disconnect' ); ?>
							<input type="hidden" name="action" value="seo_agent_ai_google_disconnect" />
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Remove manual OAuth tokens', 'seo-agent-ai' ); ?></button>
						</form>
					<?php else : ?>
						<p style="color:#555;font-size:13px"><?php esc_html_e( 'No manual OAuth credentials saved. Not needed while Site Kit is active.', 'seo-agent-ai' ); ?></p>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			<?php endif; ?>
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
