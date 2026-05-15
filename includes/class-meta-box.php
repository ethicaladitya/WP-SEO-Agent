<?php
/**
 * Per-post SEO metabox — Focus/Score, Meta overrides, Advanced/Robots.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Meta_Box {

	// -------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------

	public function init_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'wp_ajax_seo_agent_ai_analyze_single_post', array( $this, 'ajax_analyze_single_post' ) );
	}

	// -------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------

	public function register_meta_boxes() {
		$post_types = (array) get_option( 'seo_agent_ai_post_types', array( 'post', 'page' ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'seo_agent_ai_meta_box',
				__( 'SEO Agent AI', 'seo-agent-ai' ),
				array( $this, 'render_meta_box' ),
				sanitize_key( $post_type ),
				'normal',
				'high'
			);
		}
	}

	// -------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------

	public function render_meta_box( WP_Post $post ) {
		wp_nonce_field( 'seo_agent_ai_meta_box_' . $post->ID, 'seo_agent_ai_meta_box_nonce' );

		// Read stored values.
		$score         = (int) get_post_meta( $post->ID, '_seo_agent_ai_score', true );
		$keyword       = (string) get_post_meta( $post->ID, '_seo_agent_ai_focus_keyword', true );
		$last_analyzed = (string) get_post_meta( $post->ID, '_seo_agent_ai_last_analyzed', true );
		$custom_title  = (string) get_post_meta( $post->ID, '_seo_agent_ai_custom_title', true );
		$custom_desc   = (string) get_post_meta( $post->ID, '_seo_agent_ai_custom_description', true );
		$canonical     = (string) get_post_meta( $post->ID, '_seo_agent_ai_canonical', true );
		$noindex       = (bool) get_post_meta( $post->ID, '_seo_agent_ai_robots_noindex', true );
		$nofollow      = (bool) get_post_meta( $post->ID, '_seo_agent_ai_robots_nofollow', true );
		$noarchive     = (bool) get_post_meta( $post->ID, '_seo_agent_ai_robots_noarchive', true );
		$nosnippet     = (bool) get_post_meta( $post->ID, '_seo_agent_ai_robots_nosnippet', true );
		$og_title      = (string) get_post_meta( $post->ID, '_seo_agent_ai_og_title', true );
		$og_desc       = (string) get_post_meta( $post->ID, '_seo_agent_ai_og_description', true );

		// Score badge colour.
		if ( $score >= 70 ) {
			$badge_color = '#46b450';
		} elseif ( $score >= 50 ) {
			$badge_color = '#f0b849';
		} else {
			$badge_color = '#dc3232';
		}

		$score_label = $score ? (string) $score : __( 'N/A', 'seo-agent-ai' );
		?>
		<style>
			.sai-tabs { display:flex; gap:4px; margin-bottom:12px; border-bottom:1px solid #ddd; padding-bottom:0; }
			.sai-tab-btn { cursor:pointer; padding:6px 14px; border:1px solid #ddd; border-bottom:none; background:#f6f7f7; color:#23282d; border-radius:3px 3px 0 0; }
			.sai-tab-btn.active { background:#fff; font-weight:600; }
			.sai-tab-panel { display:none; }
			.sai-tab-panel.active { display:block; }
			.sai-score-badge { display:inline-block; padding:2px 10px; border-radius:12px; color:#fff; font-weight:700; font-size:1.1em; background:<?php echo esc_attr( $badge_color ); ?>; }
			.sai-row { margin-bottom:10px; }
			.sai-row label { display:block; font-weight:600; margin-bottom:3px; }
			.sai-row input[type=text], .sai-row textarea { width:100%; }
			.sai-char-count { font-size:11px; color:#888; }
		</style>
		<div class="sai-tabs">
			<button type="button" class="sai-tab-btn active" data-tab="sai-tab-score"><?php esc_html_e( 'Focus & Score', 'seo-agent-ai' ); ?></button>
			<button type="button" class="sai-tab-btn" data-tab="sai-tab-meta"><?php esc_html_e( 'Meta', 'seo-agent-ai' ); ?></button>
			<button type="button" class="sai-tab-btn" data-tab="sai-tab-advanced"><?php esc_html_e( 'Advanced', 'seo-agent-ai' ); ?></button>
		</div>

		<div id="sai-tab-score" class="sai-tab-panel active">
			<div class="sai-row">
				<label><?php esc_html_e( 'SEO Score', 'seo-agent-ai' ); ?></label>
				<span class="sai-score-badge"><?php echo esc_html( $score_label ); ?></span>
			</div>
			<div class="sai-row">
				<label for="seo_agent_ai_focus_keyword"><?php esc_html_e( 'Focus Keyword', 'seo-agent-ai' ); ?></label>
				<input type="text" id="seo_agent_ai_focus_keyword" name="seo_agent_ai_focus_keyword" value="<?php echo esc_attr( $keyword ); ?>" />
			</div>
			<?php if ( $last_analyzed ) : ?>
			<div class="sai-row">
				<label><?php esc_html_e( 'Last Analyzed', 'seo-agent-ai' ); ?></label>
				<span><?php echo esc_html( $last_analyzed ); ?></span>
			</div>
			<?php endif; ?>
			<div class="sai-row">
				<button type="button" id="sai-analyze-btn" class="button button-secondary" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Analyze Now', 'seo-agent-ai' ); ?>
				</button>
				<span id="sai-analyze-status" style="margin-left:8px;"></span>
			</div>
		</div>

		<div id="sai-tab-meta" class="sai-tab-panel">
			<div class="sai-row">
				<label for="seo_agent_ai_custom_title"><?php esc_html_e( 'SEO Title Override', 'seo-agent-ai' ); ?></label>
				<input type="text" id="seo_agent_ai_custom_title" name="seo_agent_ai_custom_title"
					value="<?php echo esc_attr( $custom_title ); ?>"
					placeholder="<?php echo esc_attr( get_the_title( $post->ID ) ); ?>" />
			</div>
			<div class="sai-row">
				<label for="seo_agent_ai_custom_description"><?php esc_html_e( 'Meta Description', 'seo-agent-ai' ); ?></label>
				<textarea id="seo_agent_ai_custom_description" name="seo_agent_ai_custom_description" rows="3"><?php echo esc_textarea( $custom_desc ); ?></textarea>
				<span class="sai-char-count" id="sai-desc-count"><?php echo esc_html( mb_strlen( $custom_desc ) ); ?> / 160</span>
			</div>
			<div class="sai-row">
				<label for="seo_agent_ai_canonical"><?php esc_html_e( 'Canonical URL', 'seo-agent-ai' ); ?></label>
				<input type="url" id="seo_agent_ai_canonical" name="seo_agent_ai_canonical"
					value="<?php echo esc_url( $canonical ); ?>"
					placeholder="<?php echo esc_attr( get_permalink( $post->ID ) ); ?>" />
			</div>
		</div>

		<div id="sai-tab-advanced" class="sai-tab-panel">
			<div class="sai-row">
				<label><?php esc_html_e( 'Robots Directives', 'seo-agent-ai' ); ?></label>
				<label><input type="checkbox" name="seo_agent_ai_robots_noindex"   value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'noindex', 'seo-agent-ai' ); ?></label><br>
				<label><input type="checkbox" name="seo_agent_ai_robots_nofollow"  value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'nofollow', 'seo-agent-ai' ); ?></label><br>
				<label><input type="checkbox" name="seo_agent_ai_robots_noarchive" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'noarchive', 'seo-agent-ai' ); ?></label><br>
				<label><input type="checkbox" name="seo_agent_ai_robots_nosnippet" value="1" <?php checked( $nosnippet ); ?>> <?php esc_html_e( 'nosnippet', 'seo-agent-ai' ); ?></label>
			</div>
			<div class="sai-row">
				<label for="seo_agent_ai_og_title"><?php esc_html_e( 'Social OG Title Override', 'seo-agent-ai' ); ?></label>
				<input type="text" id="seo_agent_ai_og_title" name="seo_agent_ai_og_title" value="<?php echo esc_attr( $og_title ); ?>" />
			</div>
			<div class="sai-row">
				<label for="seo_agent_ai_og_description"><?php esc_html_e( 'Social OG Description Override', 'seo-agent-ai' ); ?></label>
				<textarea id="seo_agent_ai_og_description" name="seo_agent_ai_og_description" rows="2"><?php echo esc_textarea( $og_desc ); ?></textarea>
			</div>
		</div>

		<script>
		(function(){
			var tabs = document.querySelectorAll('.sai-tab-btn');
			tabs.forEach(function(btn){
				btn.addEventListener('click', function(){
					tabs.forEach(function(b){ b.classList.remove('active'); });
					document.querySelectorAll('.sai-tab-panel').forEach(function(p){ p.classList.remove('active'); });
					btn.classList.add('active');
					document.getElementById(btn.dataset.tab).classList.add('active');
				});
			});

			var descArea = document.getElementById('seo_agent_ai_custom_description');
			var descCount = document.getElementById('sai-desc-count');
			if(descArea && descCount){
				descArea.addEventListener('input', function(){
					descCount.textContent = descArea.value.length + ' / 160';
				});
			}

			var analyzeBtn = document.getElementById('sai-analyze-btn');
			if(analyzeBtn){
				analyzeBtn.addEventListener('click', function(){
					var status = document.getElementById('sai-analyze-status');
					status.textContent = '<?php echo esc_js( __( 'Analyzing…', 'seo-agent-ai' ) ); ?>';
					analyzeBtn.disabled = true;
					var data = new FormData();
					data.append('action', 'seo_agent_ai_analyze_single_post');
					data.append('post_id', analyzeBtn.dataset.postId);
					data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'seo_agent_ai_analyze_post' ) ); ?>');
					fetch(ajaxurl, { method:'POST', body:data, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(resp){
							if(resp.success){
								status.textContent = '<?php echo esc_js( __( 'Done! Score: ', 'seo-agent-ai' ) ); ?>' + (resp.data.score || '?');
								setTimeout(function(){ location.reload(); }, 1500);
							} else {
								status.textContent = resp.data || '<?php echo esc_js( __( 'Error.', 'seo-agent-ai' ) ); ?>';
							}
						})
						.catch(function(){ status.textContent = '<?php echo esc_js( __( 'Network error.', 'seo-agent-ai' ) ); ?>'; })
						.finally(function(){ analyzeBtn.disabled = false; });
				});
			}
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------

	public function save_meta_box( $post_id, WP_Post $post ) {
		// Bail on autosave, revisions, or missing nonce.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( empty( $_POST['seo_agent_ai_meta_box_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seo_agent_ai_meta_box_nonce'] ) ), 'seo_agent_ai_meta_box_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'seo_agent_ai_focus_keyword' => '_seo_agent_ai_focus_keyword',
			'seo_agent_ai_custom_title'  => '_seo_agent_ai_custom_title',
			'seo_agent_ai_canonical'     => '_seo_agent_ai_canonical',
			'seo_agent_ai_og_title'      => '_seo_agent_ai_og_title',
		);

		foreach ( $text_fields as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = $field === 'seo_agent_ai_canonical'
					? esc_url_raw( wp_unslash( $_POST[ $field ] ) )
					: sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		$textarea_fields = array(
			'seo_agent_ai_custom_description' => '_seo_agent_ai_custom_description',
			'seo_agent_ai_og_description'     => '_seo_agent_ai_og_description',
		);

		foreach ( $textarea_fields as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$checkbox_fields = array(
			'seo_agent_ai_robots_noindex'   => '_seo_agent_ai_robots_noindex',
			'seo_agent_ai_robots_nofollow'  => '_seo_agent_ai_robots_nofollow',
			'seo_agent_ai_robots_noarchive' => '_seo_agent_ai_robots_noarchive',
			'seo_agent_ai_robots_nosnippet' => '_seo_agent_ai_robots_nosnippet',
		);

		foreach ( $checkbox_fields as $field => $meta_key ) {
			update_post_meta( $post_id, $meta_key, ! empty( $_POST[ $field ] ) ? '1' : '0' );
		}
	}

	// -------------------------------------------------------------------
	// AJAX — single-post analysis
	// -------------------------------------------------------------------

	public function ajax_analyze_single_post() {
		check_ajax_referer( 'seo_agent_ai_analyze_post', 'nonce' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Unauthorized or invalid post.', 'seo-agent-ai' ), 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found.', 'seo-agent-ai' ) );
		}

		$result = SEO_Agent_AI_Plugin::instance()->analyze_post_for_cli( $post, false, true );

		$score = (int) get_post_meta( $post_id, '_seo_agent_ai_score', true );
		$recs  = isset( $result['recommendations'] ) ? count( $result['recommendations'] ) : 0;

		$top_issues = array();
		if ( ! empty( $result['recommendations'] ) ) {
			$slice = array_slice( $result['recommendations'], 0, 3 );
			foreach ( $slice as $rec ) {
				$top_issues[] = isset( $rec['reasoning'] ) ? $rec['reasoning'] : '';
			}
		}

		wp_send_json_success(
			array(
				'score'                 => $score,
				'recommendations_count' => $recs,
				'top_issues'            => $top_issues,
			)
		);
	}
}
