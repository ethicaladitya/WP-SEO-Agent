<?php
/**
 * Keyword Rankings admin page — position history from keyword_history table.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Rankings_Page {

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'seo-agent-ai' ) );
		}

		$search_query = isset( $_GET['keyword'] ) ? sanitize_text_field( $_GET['keyword'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$post_id      = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$days         = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$days         = in_array( $days, array( 7, 14, 30, 60, 90 ), true ) ? $days : 30;

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['triggered'] ) && sanitize_key( $_GET['triggered'] ) === 'seo_agent_fetch_gsc_data' ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'GSC keyword fetch triggered. Reload in a moment to see results.', 'seo-agent-ai' );
			echo '</p></div>';
		}

		?>
		<div class="wrap seo-agent-ai-rankings">
			<h1><?php esc_html_e( 'Keyword Rankings', 'seo-agent-ai' ); ?></h1>

			<?php $this->render_gsc_status_bar(); ?>
			<?php $this->render_filters( $search_query, $post_id, $days ); ?>

			<?php
			if ( $post_id > 0 ) {
				$this->render_post_rankings( $post_id, $days );
			} elseif ( $search_query !== '' ) {
				$this->render_keyword_rankings( $search_query, $days );
			} else {
				$this->render_top_movers( $days );
			}
			?>
		</div>
		<?php
	}

	private function render_gsc_status_bar() {
		$gsc_site    = (string) get_option( 'seo_agent_ai_gsc_site', '' );
		$last_sync   = (string) get_option( 'seo_agent_ai_last_run_seo_agent_fetch_gsc_data', '' );
		$gsc_hook    = 'seo_agent_fetch_gsc_data';
		$nonce_val   = wp_create_nonce( 'seo_agent_ai_trigger_' . $gsc_hook );

		if ( $gsc_site === '' ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 16px;padding:12px 16px">';
			echo '<p style="margin:0">';
			echo '<strong>' . esc_html__( 'Google Search Console not connected.', 'seo-agent-ai' ) . '</strong> ';
			esc_html_e( 'Keyword ranking data comes from GSC. Connect it first, then fetch data.', 'seo-agent-ai' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=seo-agent-ai-connect' ) ) . '" class="button button-small" style="margin-left:8px">';
			esc_html_e( 'Connect Google', 'seo-agent-ai' );
			echo '</a>';
			echo '</p></div>';
			return;
		}

		$sync_label = $last_sync !== ''
			? sprintf(
				/* translators: %s: human-readable time diff */
				__( 'Last sync: %s ago', 'seo-agent-ai' ),
				human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			)
			: __( 'Never synced', 'seo-agent-ai' );

		echo '<div style="display:flex;align-items:center;gap:16px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 16px;margin-bottom:16px">';
		echo '<span style="color:#555;font-size:13px">';
		echo '<strong>' . esc_html__( 'GSC:', 'seo-agent-ai' ) . '</strong> ' . esc_html( $gsc_site );
		echo ' &mdash; ' . esc_html( $sync_label );
		echo '</span>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
		echo '<input type="hidden" name="action" value="seo_agent_ai_trigger_cron">';
		echo '<input type="hidden" name="hook" value="' . esc_attr( $gsc_hook ) . '">';
		echo '<input type="hidden" name="redirect_page" value="seo-agent-rankings">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce_val ) . '">';
		echo '<button type="submit" class="button button-small">';
		esc_html_e( 'Fetch Keyword Data Now', 'seo-agent-ai' );
		echo '</button>';
		echo '</form>';
		echo '</div>';
	}

	private function render_filters( $search_query, $post_id, $days ) {
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		echo '<form method="get" class="seo-agent-filters">';
		echo '<input type="hidden" name="page" value="seo-agent-rankings">';

		echo '<input type="text" name="keyword" value="' . esc_attr( $search_query ) . '" placeholder="' . esc_attr__( 'Search keyword...', 'seo-agent-ai' ) . '">';

		echo '<select name="post_id">';
		echo '<option value="">' . esc_html__( 'All posts', 'seo-agent-ai' ) . '</option>';
		foreach ( $posts as $p ) {
			echo '<option value="' . esc_attr( $p->ID ) . '"' . selected( $post_id, $p->ID, false ) . '>';
			echo esc_html( $p->post_title );
			echo '</option>';
		}
		echo '</select>';

		echo '<select name="days">';
		foreach ( array( 7, 14, 30, 60, 90 ) as $d ) {
			echo '<option value="' . esc_attr( $d ) . '"' . selected( $days, $d, false ) . '>';
			/* translators: %d: number of days. */
			echo esc_html( sprintf( __( 'Last %d days', 'seo-agent-ai' ), $d ) );
			echo '</option>';
		}
		echo '</select>';

		submit_button( __( 'View', 'seo-agent-ai' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_post_rankings( $post_id, $days ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'seo_agent_keyword_history';
		$cutoff  = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT keyword, position, impressions, clicks, recorded_at
			 FROM {$table}
			 WHERE post_id = %d AND recorded_at >= %s
			 ORDER BY keyword, recorded_at ASC",
			$post_id,
			$cutoff
		), ARRAY_A );

		$post = get_post( $post_id );
		$title = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";

		echo '<h2>' . esc_html( sprintf( __( 'Rankings for: %s', 'seo-agent-ai' ), $title ) ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No keyword history for this post yet.', 'seo-agent-ai' ) . ' ';
			echo esc_html__( 'Use the "Fetch Keyword Data Now" button above to pull data from Google Search Console.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		// Group by keyword.
		$by_keyword = array();
		foreach ( $rows as $row ) {
			$kw = $row['keyword'];
			if ( ! isset( $by_keyword[ $kw ] ) ) {
				$by_keyword[ $kw ] = array();
			}
			$by_keyword[ $kw ][] = $row;
		}

		$this->render_rankings_table( $by_keyword );
	}

	private function render_keyword_rankings( $keyword, $days ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'seo_agent_keyword_history';
		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, keyword, position, impressions, clicks, recorded_at
			 FROM {$table}
			 WHERE keyword LIKE %s AND recorded_at >= %s
			 ORDER BY post_id, recorded_at ASC
			 LIMIT 200",
			'%' . $wpdb->esc_like( $keyword ) . '%',
			$cutoff
		), ARRAY_A );

		echo '<h2>' . esc_html( sprintf( __( 'Rankings for keyword: "%s"', 'seo-agent-ai' ), $keyword ) ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No results found for this keyword.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Avg Position', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Total Impressions', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Group by post.
		$by_post = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $by_post[ $pid ] ) ) {
				$by_post[ $pid ] = array( 'positions' => array(), 'impressions' => 0 );
			}
			$by_post[ $pid ]['positions'][]  = (float) $row['position'];
			$by_post[ $pid ]['impressions'] += (int) $row['impressions'];
		}

		foreach ( $by_post as $pid => $data ) {
			$post      = get_post( $pid );
			$title     = $post instanceof WP_Post ? $post->post_title : "(#{$pid})";
			$avg_pos   = round( array_sum( $data['positions'] ) / count( $data['positions'] ), 1 );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td>' . esc_html( $avg_pos ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $data['impressions'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_top_movers( $days ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'seo_agent_keyword_history';
		$recent_cut = gmdate( 'Y-m-d', strtotime( '-' . (int) round( $days / 2 ) . ' days' ) );
		$prior_cut  = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );

		// Rising — wrap in subquery to avoid HAVING-alias restriction in strict MySQL.
		$rising = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM (
			     SELECT post_id, keyword,
			         AVG(CASE WHEN recorded_at >= %s THEN position END) AS pos_recent,
			         AVG(CASE WHEN recorded_at < %s AND recorded_at >= %s THEN position END) AS pos_prior
			     FROM {$table}
			     GROUP BY post_id, keyword
			 ) AS agg
			 WHERE pos_recent IS NOT NULL AND pos_prior IS NOT NULL
			   AND (pos_prior - pos_recent) >= 1
			 ORDER BY (pos_prior - pos_recent) DESC
			 LIMIT 20",
			$recent_cut,
			$recent_cut,
			$prior_cut
		), ARRAY_A );

		echo '<h2>' . esc_html__( 'Top Rising Keywords', 'seo-agent-ai' ) . '</h2>';
		$this->render_mover_table( is_array( $rising ) ? $rising : array(), 'rising' );

		// Declining.
		$declining = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM (
			     SELECT post_id, keyword,
			         AVG(CASE WHEN recorded_at >= %s THEN position END) AS pos_recent,
			         AVG(CASE WHEN recorded_at < %s AND recorded_at >= %s THEN position END) AS pos_prior
			     FROM {$table}
			     GROUP BY post_id, keyword
			 ) AS agg
			 WHERE pos_recent IS NOT NULL AND pos_prior IS NOT NULL
			   AND (pos_recent - pos_prior) >= 1
			 ORDER BY (pos_recent - pos_prior) DESC
			 LIMIT 20",
			$recent_cut,
			$recent_cut,
			$prior_cut
		), ARRAY_A );

		echo '<h2>' . esc_html__( 'Top Declining Keywords', 'seo-agent-ai' ) . '</h2>';
		$this->render_mover_table( is_array( $declining ) ? $declining : array(), 'declining' );
	}

	private function render_mover_table( array $rows, $type ) {
		if ( empty( $rows ) ) {
			echo '<p style="color:#888">' . esc_html__( 'No data yet — keyword history needs at least two GSC syncs to calculate movement.', 'seo-agent-ai' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Keyword', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Post', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Prior Pos.', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Recent Pos.', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Change', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$post_id  = (int) $row['post_id'];
			$post     = get_post( $post_id );
			$title    = $post instanceof WP_Post ? $post->post_title : "(#{$post_id})";
			$prior    = round( (float) $row['pos_prior'], 1 );
			$recent   = round( (float) $row['pos_recent'], 1 );
			$change   = round( $prior - $recent, 1 );
			$cls      = $type === 'rising' ? 'positive' : 'negative';
			$sign     = $type === 'rising' ? '+' : '-';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['keyword'] ) . '</strong></td>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $title ) . '</a></td>';
			echo '<td>' . esc_html( $prior ) . '</td>';
			echo '<td>' . esc_html( $recent ) . '</td>';
			echo '<td><span class="trend-change ' . esc_attr( $cls ) . '">' . esc_html( $sign . abs( $change ) ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_rankings_table( array $by_keyword ) {
		echo '<table class="widefat striped seo-agent-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Keyword', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Latest Position', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Impressions', 'seo-agent-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Trend', 'seo-agent-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $by_keyword as $kw => $rows ) {
			$latest = end( $rows );
			$first  = reset( $rows );
			$pos    = round( (float) $latest['position'], 1 );
			$change = round( (float) $first['position'] - (float) $latest['position'], 1 );
			$cls    = $change > 0 ? 'positive' : ( $change < 0 ? 'negative' : '' );
			$total_impr = array_sum( array_column( $rows, 'impressions' ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $kw ) . '</strong></td>';
			echo '<td>' . esc_html( $pos ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $total_impr ) ) . '</td>';
			echo '<td>';
			if ( $change !== 0.0 ) {
				$sign = $change > 0 ? '+' : '';
				echo '<span class="trend-change ' . esc_attr( $cls ) . '">' . esc_html( $sign . $change ) . '</span>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
