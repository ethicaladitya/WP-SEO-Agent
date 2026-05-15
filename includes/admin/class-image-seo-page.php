<?php
/**
 * Image SEO admin page — stats, missing alt text table, bulk generation.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Image_SEO_Page {

	/** @var SEO_Agent_AI_Image_SEO */
	private $image_seo;

	public function __construct( SEO_Agent_AI_Image_SEO $image_seo ) {
		$this->image_seo = $image_seo;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats   = $this->image_seo->get_image_stats();
		$missing = $this->image_seo->get_images_missing_alt( 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Image SEO', 'seo-agent-ai' ); ?></h1>

			<div class="seo-agent-stats-row" style="display:flex;gap:16px;margin:16px 0;">
				<div class="seo-agent-stat-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div>
					<div style="color:#646970;margin-top:4px;"><?php esc_html_e( 'Total Images', 'seo-agent-ai' ); ?></div>
				</div>
				<div class="seo-agent-stat-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:<?php echo $stats['missing_alt'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( number_format_i18n( $stats['missing_alt'] ) ); ?></div>
					<div style="color:#646970;margin-top:4px;"><?php esc_html_e( 'Missing Alt Text', 'seo-agent-ai' ); ?></div>
				</div>
				<div class="seo-agent-stat-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 24px;min-width:120px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html( number_format_i18n( $stats['ai_generated'] ) ); ?></div>
					<div style="color:#646970;margin-top:4px;"><?php esc_html_e( 'AI Generated', 'seo-agent-ai' ); ?></div>
				</div>
			</div>

			<?php if ( $stats['missing_alt'] > 0 ) : ?>
			<div style="margin-bottom:16px;">
				<button id="seo-bulk-alt-btn" class="button button-primary">
					<?php esc_html_e( 'Generate Alt Text for All Missing (up to 20)', 'seo-agent-ai' ); ?>
				</button>
				<span id="seo-bulk-alt-status" style="margin-left:12px;color:#646970;"></span>
			</div>
			<?php endif; ?>

			<?php if ( empty( $missing ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'All images have alt text. Great job!', 'seo-agent-ai' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:80px;"><?php esc_html_e( 'Preview', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Filename', 'seo-agent-ai' ); ?></th>
							<th><?php esc_html_e( 'Parent Post', 'seo-agent-ai' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Size', 'seo-agent-ai' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Action', 'seo-agent-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $missing as $img ) : ?>
						<tr id="seo-img-row-<?php echo esc_attr( $img['id'] ); ?>">
							<td><?php echo wp_get_attachment_image( $img['id'], array( 60, 60 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $img['id'] . '&action=edit' ) ); ?>">
									<?php echo esc_html( $img['filename'] ); ?>
								</a>
							</td>
							<td>
								<?php if ( $img['parent_post_id'] ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $img['parent_post_id'] . '&action=edit' ) ); ?>">
										<?php echo esc_html( $img['parent_post_title'] ?: __( 'View Post', 'seo-agent-ai' ) ); ?>
									</a>
								<?php else : ?>
									<span style="color:#646970;"><?php esc_html_e( 'Unattached', 'seo-agent-ai' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $img['filesize_kb'] . ' KB' ); ?></td>
							<td>
								<button class="button seo-gen-alt-btn"
									data-id="<?php echo esc_attr( $img['id'] ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'seo_agent_ai_generate_alt' ) ); ?>">
									<?php esc_html_e( 'Generate Alt Text', 'seo-agent-ai' ); ?>
								</button>
								<span class="seo-alt-result" style="display:block;font-size:11px;color:#2271b1;margin-top:4px;"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script>
		(function($){
			// Single image alt generation.
			$(document).on('click', '.seo-gen-alt-btn', function(){
				var btn    = $(this);
				var id     = btn.data('id');
				var nonce  = btn.data('nonce');
				var result = btn.siblings('.seo-alt-result');
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Generating…', 'seo-agent-ai' ) ); ?>');
				$.post(ajaxurl, {
					action: 'seo_agent_ai_generate_alt',
					attachment_id: id,
					nonce: nonce
				}, function(res){
					if ( res.success ) {
						result.text(res.data.alt_text);
						btn.closest('tr').fadeOut(1000, function(){ $(this).remove(); });
					} else {
						result.css('color','#d63638').text(res.data || '<?php echo esc_js( __( 'Error', 'seo-agent-ai' ) ); ?>');
						btn.prop('disabled', false).text('<?php echo esc_js( __( 'Retry', 'seo-agent-ai' ) ); ?>');
					}
				});
			});

			// Bulk generation.
			$('#seo-bulk-alt-btn').on('click', function(){
				var btn    = $(this);
				var status = $('#seo-bulk-alt-status');
				btn.prop('disabled', true);
				status.text('<?php echo esc_js( __( 'Running bulk generation…', 'seo-agent-ai' ) ); ?>');
				$.post(ajaxurl, {
					action: 'seo_agent_ai_bulk_generate_alt',
					nonce: '<?php echo esc_js( wp_create_nonce( 'seo_agent_ai_bulk_alt' ) ); ?>'
				}, function(res){
					btn.prop('disabled', false);
					if ( res.success ) {
						status.css('color','#00a32a').text(
							'<?php echo esc_js( __( 'Done! Processed', 'seo-agent-ai' ) ); ?> ' +
							res.data.processed + ', <?php echo esc_js( __( 'success', 'seo-agent-ai' ) ); ?>: ' + res.data.success
						);
						setTimeout(function(){ location.reload(); }, 2000);
					} else {
						status.css('color','#d63638').text(res.data || '<?php echo esc_js( __( 'Error', 'seo-agent-ai' ) ); ?>');
					}
				});
			});
		}(jQuery));
		</script>
		<?php
	}
}
