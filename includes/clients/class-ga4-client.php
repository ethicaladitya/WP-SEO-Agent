<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_GA4_Client {

	const OPTION_ACCESS_TOKEN      = 'seo_agent_ai_google_access_token';
	const OPTION_GA4_PROPERTY_ID   = 'seo_agent_ai_ga4_property_id';
	const PAGE_METRICS_CACHE_TTL    = 15 * MINUTE_IN_SECONDS;
	const PAGE_METRICS_CACHE_PREFIX = 'seo_agent_ga4_page_';

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

		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) ) {
			return $property_id;
		}

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $property_id === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_ga4_not_configured', __( 'GA4 credentials are not configured.', 'seo-agent-ai' ) );
		}

		$page_path = wp_parse_url( $page_url, PHP_URL_PATH );
		$page_path = is_string( $page_path ) && $page_path !== '' ? $page_path : '/';

		$current = $this->run_report( $property_id, $access_token, $page_path, 28, 1 );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$previous = $this->run_report( $property_id, $access_token, $page_path, 56, 29 );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$current_sessions  = isset( $current['sessions'] ) ? (float) $current['sessions'] : 0.0;
		$previous_sessions = isset( $previous['sessions'] ) ? (float) $previous['sessions'] : 0.0;

		$result = array(
			'source'               => 'live',
			'engagement_rate'      => isset( $current['engagement_rate'] ) ? (float) $current['engagement_rate'] : 0.0,
			'avg_time_on_page_sec' => isset( $current['avg_time_on_page_sec'] ) ? (int) $current['avg_time_on_page_sec'] : 0,
			'sessions_28d'         => (int) round( $current_sessions ),
			'sessions_trend_28d'   => $this->trend_percent( $current_sessions, $previous_sessions ),
		);

		set_transient( $cache_key, $result, self::PAGE_METRICS_CACHE_TTL );
		return $result;
	}

	// -------------------------------------------------------------------
	// Advanced analytics methods
	// -------------------------------------------------------------------

	public function get_organic_traffic( $page_url, $days = 28 ) {
		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) || is_wp_error( $access_token ) ) {
			return is_wp_error( $property_id ) ? $property_id : $access_token;
		}

		if ( $property_id === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_ga4_not_configured', __( 'GA4 credentials are not configured.', 'seo-agent-ai' ) );
		}

		$page_path = wp_parse_url( $page_url, PHP_URL_PATH );
		$page_path = is_string( $page_path ) && $page_path !== '' ? $page_path : '/';

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$payload = array(
			'dateRanges'      => array(
				array( 'startDate' => $days . 'daysAgo', 'endDate' => '1daysAgo' ),
			),
			'dimensions'      => array(
				array( 'name' => 'pagePath' ),
				array( 'name' => 'sessionDefaultChannelGrouping' ),
			),
			'metrics'         => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'engagementRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
			'dimensionFilter' => array(
				'andGroup' => array(
					'expressions' => array(
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'EXACT', 'value' => $page_path ),
							),
						),
						array(
							'filter' => array(
								'fieldName'    => 'sessionDefaultChannelGrouping',
								'stringFilter' => array( 'matchType' => 'EXACT', 'value' => 'Organic Search' ),
							),
						),
					),
				),
			),
			'limit'           => '5',
		);

		$data = $this->api_post( $endpoint, $access_token, $payload );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$row = isset( $data['rows'][0]['metricValues'] ) && is_array( $data['rows'][0]['metricValues'] )
			? $data['rows'][0]['metricValues']
			: array();

		return array(
			'organic_sessions'        => isset( $row[0]['value'] ) ? (int) round( (float) $row[0]['value'] ) : 0,
			'organic_engagement_rate' => isset( $row[1]['value'] ) ? round( (float) $row[1]['value'], 4 ) : 0.0,
			'organic_avg_time_sec'    => isset( $row[2]['value'] ) ? (int) round( (float) $row[2]['value'] ) : 0,
		);
	}

	public function get_landing_page_quality( $days = 28, $limit = 50 ) {
		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) || is_wp_error( $access_token ) ) {
			return is_wp_error( $property_id ) ? $property_id : $access_token;
		}

		if ( $property_id === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_ga4_not_configured', __( 'GA4 credentials are not configured.', 'seo-agent-ai' ) );
		}

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$payload = array(
			'dateRanges' => array(
				array( 'startDate' => $days . 'daysAgo', 'endDate' => '1daysAgo' ),
			),
			'dimensions' => array( array( 'name' => 'landingPage' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'engagementRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ),
			),
			'limit'      => (string) (int) $limit,
		);

		$data = $this->api_post( $endpoint, $access_token, $payload );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = array();
		foreach ( $data['rows'] ?? array() as $row ) {
			$dims    = $row['dimensionValues'] ?? array();
			$metrics = $row['metricValues'] ?? array();
			$path    = $dims[0]['value'] ?? '';

			if ( $path === '' ) {
				continue;
			}

			$result[] = array(
				'page'            => $path,
				'sessions'        => (int) round( (float) ( $metrics[0]['value'] ?? 0 ) ),
				'bounce_rate'     => round( (float) ( $metrics[1]['value'] ?? 0.0 ), 4 ),
				'engagement_rate' => round( (float) ( $metrics[2]['value'] ?? 0.0 ), 4 ),
				'avg_time_sec'    => (int) round( (float) ( $metrics[3]['value'] ?? 0 ) ),
			);
		}

		usort( $result, fn( $a, $b ) => $b['bounce_rate'] <=> $a['bounce_rate'] );
		return $result;
	}

	public function get_exit_page_data( $days = 28, $limit = 50 ) {
		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) || is_wp_error( $access_token ) ) {
			return is_wp_error( $property_id ) ? $property_id : $access_token;
		}

		if ( $property_id === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_ga4_not_configured', __( 'GA4 credentials are not configured.', 'seo-agent-ai' ) );
		}

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$payload = array(
			'dateRanges' => array(
				array( 'startDate' => $days . 'daysAgo', 'endDate' => '1daysAgo' ),
			),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'exitRate' ),
				array( 'name' => 'screenPageViews' ),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'exitRate' ), 'desc' => true ),
			),
			'limit'      => (string) (int) $limit,
		);

		$data = $this->api_post( $endpoint, $access_token, $payload );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = array();
		foreach ( $data['rows'] ?? array() as $row ) {
			$dims     = $row['dimensionValues'] ?? array();
			$metrics  = $row['metricValues'] ?? array();
			$path     = $dims[0]['value'] ?? '';
			$sessions = (int) round( (float) ( $metrics[0]['value'] ?? 0 ) );

			if ( $path === '' || $sessions < 10 ) {
				continue;
			}

			$result[] = array(
				'page'       => $path,
				'sessions'   => $sessions,
				'exit_rate'  => round( (float) ( $metrics[1]['value'] ?? 0.0 ), 4 ),
				'page_views' => (int) round( (float) ( $metrics[2]['value'] ?? 0 ) ),
			);
		}

		return $result;
	}

	public function get_scroll_depth( $page_url, $days = 28 ) {
		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) || is_wp_error( $access_token ) ) {
			return array( 'scroll_event_count' => 0, 'sessions' => 0, 'scroll_rate' => 0.0 );
		}

		if ( $property_id === '' || $access_token === '' ) {
			return array( 'scroll_event_count' => 0, 'sessions' => 0, 'scroll_rate' => 0.0 );
		}

		$page_path = wp_parse_url( $page_url, PHP_URL_PATH );
		$page_path = is_string( $page_path ) && $page_path !== '' ? $page_path : '/';

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$payload = array(
			'dateRanges'      => array(
				array( 'startDate' => $days . 'daysAgo', 'endDate' => '1daysAgo' ),
			),
			'dimensions'      => array(
				array( 'name' => 'pagePath' ),
				array( 'name' => 'eventName' ),
			),
			'metrics'         => array(
				array( 'name' => 'eventCount' ),
				array( 'name' => 'sessions' ),
			),
			'dimensionFilter' => array(
				'andGroup' => array(
					'expressions' => array(
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'EXACT', 'value' => $page_path ),
							),
						),
						array(
							'filter' => array(
								'fieldName'    => 'eventName',
								'stringFilter' => array( 'matchType' => 'EXACT', 'value' => 'scroll' ),
							),
						),
					),
				),
			),
			'limit'           => '1',
		);

		$data = $this->api_post( $endpoint, $access_token, $payload );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array( 'scroll_event_count' => 0, 'sessions' => 0, 'scroll_rate' => 0.0 );
		}

		$row      = $data['rows'][0];
		$metrics  = $row['metricValues'] ?? array();
		$scroll   = (int) round( (float) ( $metrics[0]['value'] ?? 0 ) );
		$sessions = (int) round( (float) ( $metrics[1]['value'] ?? 0 ) );

		return array(
			'scroll_event_count' => $scroll,
			'sessions'           => $sessions,
			'scroll_rate'        => $sessions > 0 ? round( $scroll / $sessions, 4 ) : 0.0,
		);
	}

	// -------------------------------------------------------------------
	// Connection test & property listing
	// -------------------------------------------------------------------

	public function test_connection() {
		$property_id  = $this->normalize_property_id( $this->get_property_id() );
		$access_token = $this->get_access_token();

		if ( is_wp_error( $property_id ) ) {
			return $property_id;
		}

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( $property_id === '' || $access_token === '' ) {
			return new WP_Error( 'seo_agent_ai_ga4_not_configured', __( 'Google Analytics credentials are not configured.', 'seo-agent-ai' ) );
		}

		$response = wp_remote_post(
			'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'dateRanges' => array(
							array( 'startDate' => '7daysAgo', 'endDate' => 'yesterday' ),
						),
						'metrics' => array( array( 'name' => 'sessions' ) ),
						'limit'   => '1',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seo_agent_ai_ga4_connection_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown Analytics connection error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_ga4_connection_api_error', $message );
		}

		return array(
			'service'  => 'analytics',
			'property' => $property_id,
			'message'  => __( 'Google Analytics connection succeeded.', 'seo-agent-ai' ),
		);
	}

	public function list_properties() {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( empty( $access_token ) ) {
			return new WP_Error( 'seo_agent_ai_not_connected', __( 'Google account not connected.', 'seo-agent-ai' ) );
		}

		$response = wp_remote_get(
			'https://analyticsadmin.googleapis.com/v1beta/accountSummaries',
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
			if ( $code === 403 || $code === 404 ) {
				$msg .= ' ' . __( 'Make sure the Google Analytics Admin API is enabled in Google Cloud Console.', 'seo-agent-ai' );
			}
			return new WP_Error( 'seo_agent_ai_ga4_admin_api_error', $msg );
		}

		$properties = array();

		if ( isset( $data['accountSummaries'] ) && is_array( $data['accountSummaries'] ) ) {
			foreach ( $data['accountSummaries'] as $account ) {
				$account_name = isset( $account['displayName'] ) ? (string) $account['displayName'] : '';
				if ( isset( $account['propertySummaries'] ) && is_array( $account['propertySummaries'] ) ) {
					foreach ( $account['propertySummaries'] as $prop ) {
						$resource = isset( $prop['property'] ) ? (string) $prop['property'] : '';
						$id       = str_replace( 'properties/', '', $resource );
						if ( $id === '' || ! ctype_digit( $id ) ) {
							continue;
						}
						$display      = isset( $prop['displayName'] ) ? (string) $prop['displayName'] : $id;
						$properties[] = array(
							'id'   => $id,
							'name' => $display . ' (' . $id . ')' . ( $account_name ? ' — ' . $account_name : '' ),
						);
					}
				}
			}
		}

		return $properties;
	}

	// -------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------

	private function run_report( $property_id, $access_token, $page_path, $start_days_ago, $end_days_ago ) {
		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$payload = array(
			'dateRanges'      => array(
				array(
					'startDate' => $start_days_ago . 'daysAgo',
					'endDate'   => $end_days_ago . 'daysAgo',
				),
			),
			'dimensions'      => array(
				array( 'name' => 'pagePath' ),
			),
			'metrics'         => array(
				array( 'name' => 'engagementRate' ),
				array( 'name' => 'averageSessionDuration' ),
				array( 'name' => 'sessions' ),
			),
			'dimensionFilter' => array(
				'filter' => array(
					'fieldName'    => 'pagePath',
					'stringFilter' => array(
						'matchType' => 'EXACT',
						'value'     => $page_path,
					),
				),
			),
			'limit'           => '1',
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
			return new WP_Error( 'seo_agent_ai_ga4_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown GA4 API error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_ga4_api_error', $message );
		}

		$row = isset( $data['rows'][0]['metricValues'] ) && is_array( $data['rows'][0]['metricValues'] )
			? $data['rows'][0]['metricValues']
			: array();

		return array(
			'engagement_rate'      => isset( $row[0]['value'] ) ? (float) $row[0]['value'] : 0.0,
			'avg_time_on_page_sec' => isset( $row[1]['value'] ) ? (int) round( (float) $row[1]['value'] ) : 0,
			'sessions'             => isset( $row[2]['value'] ) ? (float) $row[2]['value'] : 0.0,
		);
	}

	private function api_post( $endpoint, $access_token, array $payload ) {
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
			return new WP_Error( 'seo_agent_ai_ga4_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Unknown GA4 API error.', 'seo-agent-ai' );
			return new WP_Error( 'seo_agent_ai_ga4_api_error', $msg );
		}

		return is_array( $data ) ? $data : array();
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

	private function get_property_id() {
		if ( class_exists( 'SEO_Agent_AI_SiteKit_Bridge' ) && SEO_Agent_AI_SiteKit_Bridge::is_ga4_active() ) {
			$sk_id = SEO_Agent_AI_SiteKit_Bridge::get_ga4_property_id();
			if ( $sk_id !== '' ) {
				return $sk_id;
			}
		}

		$constant = defined( 'SEO_AGENT_AI_GA4_PROPERTY_ID' ) ? SEO_AGENT_AI_GA4_PROPERTY_ID : '';
		if ( is_string( $constant ) && $constant !== '' ) {
			return trim( $constant );
		}

		$option_value = get_option( self::OPTION_GA4_PROPERTY_ID, '' );
		return is_string( $option_value ) ? trim( $option_value ) : '';
	}

	private function normalize_property_id( $property_id ) {
		$property_id = trim( (string) $property_id );

		if ( $property_id === '' ) {
			return '';
		}

		if ( stripos( $property_id, 'UA-' ) === 0 ) {
			return new WP_Error(
				'seo_agent_ai_ua_unsupported',
				__( 'Universal Analytics properties (UA-...) are retired by Google and cannot be queried for live reporting.', 'seo-agent-ai' )
			);
		}

		if ( stripos( $property_id, 'G-' ) === 0 ) {
			return new WP_Error(
				'seo_agent_ai_measurement_id_unsupported',
				__( 'Use a Google Analytics property ID, not a Measurement ID (G-...).', 'seo-agent-ai' )
			);
		}

		if ( preg_match( '/^properties\/(\d+)$/', $property_id, $matches ) ) {
			return $matches[1];
		}

		if ( preg_match( '/^\d+$/', $property_id ) ) {
			return $property_id;
		}

		return new WP_Error(
			'seo_agent_ai_invalid_property_id',
			__( 'Google Analytics property ID must be numeric or in the form properties/123456.', 'seo-agent-ai' )
		);
	}
}
