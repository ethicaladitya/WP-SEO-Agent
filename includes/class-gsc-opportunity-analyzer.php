<?php
/**
 * Aggregates site-level GSC opportunities into a single cached report.
 *
 * Combines page-2 rankings, CTR anomalies, and declining pages so the
 * admin dashboard and WP-CLI commands can read one pre-built array
 * instead of making three separate API calls.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_GSC_Opportunity_Analyzer {

	const CACHE_TRANSIENT = 'seo_agent_ai_site_opportunities';
	const CACHE_TTL       = 6 * HOUR_IN_SECONDS;

	/** @var SEO_Agent_AI_GSC_Client */
	private $gsc_client;

	public function __construct( SEO_Agent_AI_GSC_Client $gsc_client ) {
		$this->gsc_client = $gsc_client;
	}

	/**
	 * Return the aggregated opportunity report, using cached data when available.
	 *
	 * @param bool $force  Bypass cache and re-fetch from Google.
	 * @return array  Keys: page2_pages, ctr_anomalies, declining_pages, generated_at.
	 */
	public function get_opportunities( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		return $this->refresh();
	}

	/**
	 * Force-fetch fresh data from GSC, update the cache, and return the report.
	 *
	 * @return array
	 */
	public function refresh() {
		$page2     = $this->gsc_client->get_page2_pages( 28 );
		$anomalies = $this->gsc_client->get_ctr_anomalies( 28 );
		$declining = $this->gsc_client->get_declining_pages( 28 );

		$report = array(
			'page2_pages'     => is_array( $page2 )     ? $page2     : array(),
			'ctr_anomalies'   => is_array( $anomalies ) ? $anomalies : array(),
			'declining_pages' => is_array( $declining ) ? $declining : array(),
			'generated_at'    => current_time( 'mysql' ),
		);

		set_transient( self::CACHE_TRANSIENT, $report, self::CACHE_TTL );
		return $report;
	}

	/**
	 * Invalidate the cached opportunity report.
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_TRANSIENT );
	}
}
