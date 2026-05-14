<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_GSC_Client {

	const OPTION_ACCESS_TOKEN  = 'seo_agent_ai_google_access_token';
	const OPTION_GSC_SITE_URL  = 'seo_agent_ai_gsc_site_url';
	const PAGE_METRICS_CACHE_TTL = 15 * MINUTE_IN_SECONDS;
	const PAGE_METRICS_CACHE_PREFIX = 'seo_agent_gsc_page_';

	private $google_auth;

	public function __construct( SEO_Agent_AI_Google_OAuth $google_auth = null ) {
		$this->google_auth = $google_auth ? $google_auth : new SEO_Agent_AI_Google_OAuth();
	}

	// -------------------------------------------------------------------
	// Core page metrics
	// -------------------------------------------------------------------

	public function get_page_metrics( $page_url ) {
		$cache_key = self::PAGE_METRICS_CACHE_PREFIX . md5( $page_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$current = $this->query_period( $site_url, $access_token, $page_url, 28, 1 );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$previous = $this->query_period( $site_url, $access_token, $page_url, 56, 29 );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$current_impressions  = isset( $current['impressions_total'] ) ? (float) $current['impressions_total'] : 0.0;
		$previous_impressions = isset( $previous['impressions_total'] ) ? (float) $previous['impressions_total'] : 0.0;

		$result = array(
			'source'                => 'live',
			'queries'               => isset( $current['queries'] ) ? $current['queries'] : array(),
			'impressions_total'     => (int) round( $current_impressions ),
			'ctr_avg'               => isset( $current['ctr_avg'] ) ? (float) $current['ctr_avg'] : 0.0,
			'position_avg'          => isset( $current['position_avg'] ) ? (float) $current['position_avg'] : 99.0,
			'impressions_trend_28d' => $this->trend_percent( $current_impressions, $previous_impressions ),
		);

		set_transient( $cache_key, $result, self::PAGE_METRICS_CACHE_TTL );
		return $result;
	}

	// -------------------------------------------------------------------
	// Advanced analytics methods
	// -------------------------------------------------------------------

	public function get_page_queries( $page_url, $days = 28 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$result = $this->query_period( $site_url, $access_token, $page_url, $days, 1 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['queries'] ) ? $result['queries'] : array();
	}

	public function get_page2_pages( $days = 28 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$start_date = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$payload = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 500,
		);

		$rows = $this->post_search_analytics( $site_url, $access_token, $payload );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$page2 = array();
		foreach ( $rows as $row ) {
			$position = (float) ( $row['position'] ?? 99.0 );
			if ( $position >= 11.0 && $position <= 20.0 ) {
				$page2[] = array(
					'page'        => $row['page'] ?? '',
					'position'    => round( $position, 1 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'ctr'         => round( (float) ( $row['ctr'] ?? 0.0 ), 4 ),
				);
			}
		}

		usort( $page2, fn( $a, $b ) => $b['impressions'] - $a['impressions'] );
		return $page2;
	}

	public function get_declining_pages( $days = 28, $threshold_pct = -15.0 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$curr_start = gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days ) . ' days' ) );
		$curr_end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days * 2 ) . ' days' ) );
		$prev_end   = gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days + 1 ) . ' days' ) );

		$curr_rows = $this->post_search_analytics( $site_url, $access_token, array(
			'startDate'  => $curr_start,
			'endDate'    => $curr_end,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 500,
		) );
		if ( is_wp_error( $curr_rows ) ) {
			return $curr_rows;
		}

		$prev_rows = $this->post_search_analytics( $site_url, $access_token, array(
			'startDate'  => $prev_start,
			'endDate'    => $prev_end,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 500,
		) );
		if ( is_wp_error( $prev_rows ) ) {
			return $prev_rows;
		}

		$prev_index = array();
		foreach ( $prev_rows as $row ) {
			$page = $row['page'] ?? '';
			if ( $page !== '' ) {
				$prev_index[ $page ] = (int) ( $row['impressions'] ?? 0 );
			}
		}

		$declining = array();
		foreach ( $curr_rows as $row ) {
			$page      = $row['page'] ?? '';
			$curr_impr = (int) ( $row['impressions'] ?? 0 );
			$prev_impr = isset( $prev_index[ $page ] ) ? $prev_index[ $page ] : 0;
			$trend     = $this->trend_percent( $curr_impr, $prev_impr );

			if ( $trend <= $threshold_pct && $curr_impr > 10 ) {
				$declining[] = array(
					'page'                 => $page,
					'impressions_current'  => $curr_impr,
					'impressions_previous' => $prev_impr,
					'impressions_trend'    => round( $trend, 1 ),
					'position'             => round( (float) ( $row['position'] ?? 99.0 ), 1 ),
					'ctr'                  => round( (float) ( $row['ctr'] ?? 0.0 ), 4 ),
				);
			}
		}

		usort( $declining, fn( $a, $b ) => $a['impressions_trend'] <=> $b['impressions_trend'] );
		return $declining;
	}

	public function get_ctr_anomalies( $days = 28, $ratio_cap = 0.60 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$start_date = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$rows = $this->post_search_analytics( $site_url, $access_token, array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 500,
		) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$anomalies = array();
		foreach ( $rows as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 99.0 );
			$ctr         = (float) ( $row['ctr'] ?? 0.0 );

			if ( $impressions < 50 || $position > 20.0 ) {
				continue;
			}

			$expected = $this->expected_ctr( $position );
			$ratio    = $expected > 0 ? $ctr / $expected : 0.0;

			if ( $ratio < $ratio_cap ) {
				$anomalies[] = array(
					'page'         => $row['page'] ?? '',
					'impressions'  => $impressions,
					'position'     => round( $position, 1 ),
					'ctr_actual'   => round( $ctr, 4 ),
					'ctr_expected' => round( $expected, 4 ),
					'ctr_ratio'    => round( $ratio, 3 ),
				);
			}
		}

		usort( $anomalies, fn( $a, $b ) => $a['ctr_ratio'] <=> $b['ctr_ratio'] );
		return $anomalies;
	}

	public function get_all_pages_queries( $days = 28, $limit = 50 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return array();
		}

		if ( $site_url === '' || $access_token === '' ) {
			return array();
		}

		$start_date = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$pages = $this->post_search_analytics( $site_url, $access_token, array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page' ),
			'rowLimit'   => (int) $limit,
		) );

		if ( is_wp_error( $pages ) || empty( $pages ) ) {
			return array();
		}

		$result = array();
		foreach ( $pages as $page_row ) {
			$page_url_item = $page_row['page'] ?? '';
			if ( $page_url_item === '' ) {
				continue;
			}

			$query_data = $this->query_period( $site_url, $access_token, $page_url_item, $days, 1 );
			if ( ! is_wp_error( $query_data ) && ! empty( $query_data['queries'] ) ) {
				$result[ $page_url_item ] = $query_data['queries'];
			}

			usleep( 500000 );
		}

		return $result;
	}

	public function get_keyword_history( $page_url, $days = 90 ) {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return array();
		}

		if ( $site_url === '' || $access_token === '' ) {
			return array();
		}

		$days       = min( (int) $days, 90 );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$endpoint = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$payload = array(
			'startDate'             => $start_date,
			'endDate'               => $end_date,
			'dimensions'            => array( 'date', 'query' ),
			'rowLimit'              => 500,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page_url,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || empty( $data['rows'] ) ) {
			return array();
		}

		$history = array();
		foreach ( $data['rows'] as $row ) {
			$keys  = $row['keys'] ?? array();
			$date  = $keys[0] ?? '';
			$query = isset( $keys[1] ) ? sanitize_text_field( (string) $keys[1] ) : '';

			if ( $date === '' || $query === '' ) {
				continue;
			}

			$history[] = array(
				'date'        => $date,
				'keyword'     => $query,
				'position'    => round( (float) ( $row['position'] ?? 99.0 ), 2 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0.0 ), 4 ),
			);
		}

		return $history;
	}

	// -------------------------------------------------------------------
	// Connection test & site listing
	// -------------------------------------------------------------------

	public function test_connection() {
		$site_url     = $this->get_site_url();
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $site_url === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_gsc_not_configured', __( 'Google Search Console credentials are not configured.', 'seo-agent-ai' ) );
		}

		$response = wp_remote_get(
			'https://searchconsole.googleapis.com/webmasters/v3/sites',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_gsc_connection_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown GSC connection error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_gsc_connection_api_error', $message );
		}

		$sites = isset( $data['siteEntry'] ) && is_array( $data['siteEntry'] ) ? $data['siteEntry'] : array();

		foreach ( $sites as $site ) {
			if ( isset( $site['siteUrl'] ) && (string) $site['siteUrl'] === $site_url ) {
				return array(
					'service'  => 'gsc',
					'property' => $site_url,
					'message'  => __( 'Search Console connection succeeded.', 'seo-agent-ai' ),
				);
			}
		}

		return new WP_Error(
			'seo_agent_ai_gsc_property_not_found',
			sprintf(
				/* translators: %s: configured Search Console property URL. */
				__( 'Connected to Search Console, but the configured property was not found: %s', 'seo-agent-ai' ),
				$site_url
			)
		);
	}

	public function list_sites() {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( empty( $access_token ) ) {
			return new WP_Error( 'seo_agent_ai_not_connected', __( 'Google account not connected.', 'seo-agent-ai' ) );
		}

		$response = wp_remote_get(
			'https://searchconsole.googleapis.com/webmasters/v3/sites',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_gsc_api_error', $msg );
		}

		return isset( $data['siteEntry'] ) && is_array( $data['siteEntry'] ) ? $data['siteEntry'] : array();
	}

	// -------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------

	private function query_period( $site_url, $access_token, $page_url, $start_days_ago, $end_days_ago ) {
		$endpoint   = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . (int) $start_days_ago . ' days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-' . (int) $end_days_ago . ' days' ) );

		$payload = array(
			'startDate'             => $start_date,
			'endDate'               => $end_date,
			'dimensions'            => array( 'page', 'query' ),
			'rowLimit'              => 250,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page_url,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_gsc_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown GSC API error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_gsc_api_error', $message );
		}

		return $this->normalize_rows( isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array() );
	}

	private function post_search_analytics( $site_url, $access_token, array $payload ) {
		$endpoint = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_gsc_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown GSC API error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_gsc_api_error', $msg );
		}

		$rows   = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
		$result = array();

		foreach ( $rows as $row ) {
			$keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
			$item = array(
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0.0 ), 4 ),
				'position'    => round( (float) ( $row['position'] ?? 99.0 ), 1 ),
			);

			$dims = $payload['dimensions'] ?? array();
			foreach ( $dims as $idx => $dim ) {
				$item[ $dim ] = isset( $keys[ $idx ] ) ? sanitize_text_field( (string) $keys[ $idx ] ) : '';
			}

			$result[] = $item;
		}

		return $result;
	}

	private function normalize_rows( array $rows ) {
		$query_metrics         = array();
		$impressions_total     = 0.0;
		$clicks_total          = 0.0;
		$position_weighted_sum = 0.0;

		foreach ( $rows as $row ) {
			$keys  = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
			$query = isset( $keys[1] ) ? sanitize_text_field( (string) $keys[1] ) : '';
			if ( $query === '' ) {
				continue;
			}

			$impressions = isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0;
			$clicks      = isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 99.0;

			if ( ! isset( $query_metrics[ $query ] ) ) {
				$query_metrics[ $query ] = array(
					'query'             => $query,
					'impressions'       => 0,
					'clicks'            => 0,
					'position_weighted' => 0.0,
				);
			}

			$query_metrics[ $query ]['impressions']       += (int) round( $impressions );
			$query_metrics[ $query ]['clicks']            += (int) round( $clicks );
			$query_metrics[ $query ]['position_weighted'] += ( $position * $impressions );

			$impressions_total     += $impressions;
			$clicks_total          += $clicks;
			$position_weighted_sum += ( $position * $impressions );
		}

		if ( empty( $query_metrics ) ) {
			return array(
				'queries'           => array(),
				'impressions_total' => 0,
				'ctr_avg'           => 0.0,
				'position_avg'      => 99.0,
			);
		}

		$queries = array();
		foreach ( $query_metrics as $query_data ) {
			$query_impressions = (float) $query_data['impressions'];
			$query_clicks      = (float) $query_data['clicks'];

			$queries[] = array(
				'query'       => $query_data['query'],
				'impressions' => (int) $query_data['impressions'],
				'clicks'      => (int) $query_data['clicks'],
				'ctr'         => $query_impressions > 0 ? round( $query_clicks / $query_impressions, 4 ) : 0.0,
				'position'    => $query_impressions > 0 ? round( $query_data['position_weighted'] / $query_impressions, 1 ) : 99.0,
			);
		}

		usort( $queries, fn( $a, $b ) => ( (int) $b['impressions'] ) - ( (int) $a['impressions'] ) );

		return array(
			'queries'           => array_slice( $queries, 0, 20 ),
			'impressions_total' => (int) round( $impressions_total ),
			'ctr_avg'           => $impressions_total > 0 ? round( $clicks_total / $impressions_total, 4 ) : 0.0,
			'position_avg'      => $impressions_total > 0 ? round( $position_weighted_sum / $impressions_total, 1 ) : 99.0,
		);
	}

	private function expected_ctr( $position ) {
		$map = array(
			1  => 0.32, 2  => 0.18, 3  => 0.11,
			4  => 0.08, 5  => 0.06, 6  => 0.05,
			7  => 0.04, 8  => 0.035, 9  => 0.03,
			10 => 0.025,
		);
		$pos = (int) round( $position );
		return $map[ $pos ] ?? ( $pos <= 20 ? 0.015 : 0.005 );
	}

	private function trend_percent( $current, $previous ) {
		if ( $previous <= 0 ) {
			return $current > 0 ? 100.0 : 0.0;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	private function get_access_token() {
		if ( class_exists( 'SEO_Agent_AI_SiteKit_Bridge' ) && SEO_Agent_AI_SiteKit_Bridge::is_active() ) {
			return SEO_Agent_AI_SiteKit_Bridge::get_access_token();
		}
		return $this->google_auth->get_access_token();
	}

	private function get_site_url() {
		if ( class_exists( 'SEO_Agent_AI_SiteKit_Bridge' ) && SEO_Agent_AI_SiteKit_Bridge::is_active() ) {
			$sk_url = SEO_Agent_AI_SiteKit_Bridge::get_gsc_site_url();
			if ( $sk_url !== '' ) {
				return $this->normalize_site_url( $sk_url );
			}
		}

		$constant = defined( 'SEO_AGENT_AI_GSC_SITE_URL' ) ? SEO_AGENT_AI_GSC_SITE_URL : '';
		if ( is_string( $constant ) && $constant !== '' ) {
			return $this->normalize_site_url( $constant );
		}

		$option_value = get_option( self::OPTION_GSC_SITE_URL, '' );
		if ( is_string( $option_value ) && trim( $option_value ) !== '' ) {
			return $this->normalize_site_url( $option_value );
		}

		return $this->normalize_site_url( home_url( '/' ) );
	}

	private function normalize_site_url( $site_url ) {
		$site_url = trim( (string) $site_url );

		if ( $site_url === '' ) {
			return '';
		}

		if ( strpos( $site_url, 'sc-domain:' ) === 0 ) {
			return $site_url;
		}

		if ( preg_match( '#^https?://#i', $site_url ) ) {
			return trailingslashit( $site_url );
		}

		return 'sc-domain:' . preg_replace( '#^www\.#i', '', $site_url );
	}
}
