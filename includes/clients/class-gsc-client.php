<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_GSC_Client
{
    const OPTION_ACCESS_TOKEN = 'seo_agent_ai_google_access_token';
    const OPTION_GSC_SITE_URL = 'seo_agent_ai_gsc_site_url';

    private $google_auth;

    public function __construct(SEO_Agent_AI_Google_OAuth $google_auth = null)
    {
        $this->google_auth = $google_auth ? $google_auth : new SEO_Agent_AI_Google_OAuth();
    }

    public function get_page_metrics($page_url)
    {
        $site_url = $this->get_site_url();
        $access_token = $this->get_access_token();

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        if ($site_url === '' || $access_token === '') {
            return new WP_Error('seo_agent_ai_gsc_not_configured', __('Google Search Console credentials are not configured.', 'seo-agent-ai'));
        }

        $current = $this->query_period($site_url, $access_token, $page_url, 28, 1);
        if (is_wp_error($current)) {
            return $current;
        }

        $previous = $this->query_period($site_url, $access_token, $page_url, 56, 29);
        if (is_wp_error($previous)) {
            return $previous;
        }

        $current_impressions = isset($current['impressions_total']) ? (float) $current['impressions_total'] : 0.0;
        $previous_impressions = isset($previous['impressions_total']) ? (float) $previous['impressions_total'] : 0.0;

        return array(
            'source' => 'live',
            'queries' => isset($current['queries']) ? $current['queries'] : array(),
            'impressions_total' => (int) round($current_impressions),
            'ctr_avg' => isset($current['ctr_avg']) ? (float) $current['ctr_avg'] : 0.0,
            'position_avg' => isset($current['position_avg']) ? (float) $current['position_avg'] : 99.0,
            'impressions_trend_28d' => $this->trend_percent($current_impressions, $previous_impressions),
        );
    }

    public function test_connection()
    {
        $site_url = $this->get_site_url();
        $access_token = $this->get_access_token();

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        if ($site_url === '' || $access_token === '') {
            return new WP_Error('seo_agent_ai_gsc_not_configured', __('Google Search Console credentials are not configured.', 'seo-agent-ai'));
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

        if (is_wp_error($response)) {
            return new WP_Error('seo_agent_ai_gsc_connection_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = isset($data['error']['message']) ? (string) $data['error']['message'] : __('Unknown GSC connection error.', 'seo-agent-ai');
            return new WP_Error('seo_agent_ai_gsc_connection_api_error', $message);
        }

        $sites = isset($data['siteEntry']) && is_array($data['siteEntry']) ? $data['siteEntry'] : array();

        foreach ($sites as $site) {
            if (isset($site['siteUrl']) && (string) $site['siteUrl'] === $site_url) {
                return array(
                    'service' => 'gsc',
                    'property' => $site_url,
                    'message' => __('Search Console connection succeeded.', 'seo-agent-ai'),
                );
            }
        }

        return new WP_Error(
            'seo_agent_ai_gsc_property_not_found',
            sprintf(__('Connected to Search Console, but the configured property was not found: %s', 'seo-agent-ai'), $site_url)
        );
    }

    /**
     * List all Search Console properties accessible by the authenticated user.
     *
     * @return array[]|WP_Error  Array of siteEntry objects (each has 'siteUrl', 'permissionLevel').
     */
    public function list_sites()
    {
        $access_token = $this->get_access_token();

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        if (empty($access_token)) {
            return new WP_Error('seo_agent_ai_not_connected', __('Google account not connected.', 'seo-agent-ai'));
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

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $msg = isset($data['error']['message']) ? (string) $data['error']['message'] : __('Unknown error.', 'seo-agent-ai');
            return new WP_Error('seo_agent_ai_gsc_api_error', $msg);
        }

        return isset($data['siteEntry']) && is_array($data['siteEntry']) ? $data['siteEntry'] : array();
    }

    private function query_period($site_url, $access_token, $page_url, $start_days_ago, $end_days_ago)
    {
        $endpoint = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($site_url) . '/searchAnalytics/query';
        $start_date = gmdate('Y-m-d', strtotime('-' . (int) $start_days_ago . ' days'));
        $end_date = gmdate('Y-m-d', strtotime('-' . (int) $end_days_ago . ' days'));

        $payload = array(
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => array('page', 'query'),
            'rowLimit' => 250,
            'dimensionFilterGroups' => array(
                array(
                    'filters' => array(
                        array(
                            'dimension' => 'page',
                            'operator' => 'equals',
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
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('seo_agent_ai_gsc_request_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = isset($data['error']['message']) ? (string) $data['error']['message'] : __('Unknown GSC API error.', 'seo-agent-ai');
            return new WP_Error('seo_agent_ai_gsc_api_error', $message);
        }

        return $this->normalize_rows(isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array());
    }

    private function normalize_rows(array $rows)
    {
        $query_metrics = array();
        $impressions_total = 0.0;
        $clicks_total = 0.0;
        $position_weighted_sum = 0.0;

        foreach ($rows as $row) {
            $keys = isset($row['keys']) && is_array($row['keys']) ? $row['keys'] : array();
            $query = isset($keys[1]) ? sanitize_text_field((string) $keys[1]) : '';
            if ($query === '') {
                continue;
            }

            $impressions = isset($row['impressions']) ? (float) $row['impressions'] : 0.0;
            $clicks = isset($row['clicks']) ? (float) $row['clicks'] : 0.0;
            $ctr = isset($row['ctr']) ? (float) $row['ctr'] : 0.0;
            $position = isset($row['position']) ? (float) $row['position'] : 99.0;

            if (!isset($query_metrics[$query])) {
                $query_metrics[$query] = array(
                    'query' => $query,
                    'impressions' => 0,
                    'clicks' => 0,
                    'position_weighted' => 0.0,
                );
            }

            $query_metrics[$query]['impressions'] += (int) round($impressions);
            $query_metrics[$query]['clicks'] += (int) round($clicks);
            $query_metrics[$query]['position_weighted'] += ($position * $impressions);

            $impressions_total += $impressions;
            $clicks_total += $clicks;
            $position_weighted_sum += ($position * $impressions);
        }

        if (empty($query_metrics)) {
            return array(
                'queries' => array(),
                'impressions_total' => 0,
                'ctr_avg' => 0.0,
                'position_avg' => 99.0,
            );
        }

        $queries = array();
        foreach ($query_metrics as $query_data) {
            $query_impressions = (float) $query_data['impressions'];
            $query_clicks = (float) $query_data['clicks'];

            $queries[] = array(
                'query' => $query_data['query'],
                'impressions' => (int) $query_data['impressions'],
                'ctr' => $query_impressions > 0 ? round($query_clicks / $query_impressions, 4) : 0.0,
                'position' => $query_impressions > 0 ? round($query_data['position_weighted'] / $query_impressions, 1) : 99.0,
            );
        }

        usort($queries, function ($a, $b) {
            return ((int) $b['impressions']) - ((int) $a['impressions']);
        });

        return array(
            'queries' => array_slice($queries, 0, 20),
            'impressions_total' => (int) round($impressions_total),
            'ctr_avg' => $impressions_total > 0 ? round($clicks_total / $impressions_total, 4) : 0.0,
            'position_avg' => $impressions_total > 0 ? round($position_weighted_sum / $impressions_total, 1) : 99.0,
        );
    }

    private function trend_percent($current, $previous)
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function get_access_token()
    {
        return $this->google_auth->get_access_token();
    }

    private function get_site_url()
    {
        $constant = defined('SEO_AGENT_AI_GSC_SITE_URL') ? SEO_AGENT_AI_GSC_SITE_URL : '';
        if (is_string($constant) && $constant !== '') {
            return $this->normalize_site_url($constant);
        }

        $option_value = get_option(self::OPTION_GSC_SITE_URL, '');
        if (is_string($option_value) && trim($option_value) !== '') {
            return $this->normalize_site_url($option_value);
        }

        return $this->normalize_site_url(home_url('/'));
    }

    private function normalize_site_url($site_url)
    {
        $site_url = trim((string) $site_url);

        if ($site_url === '') {
            return '';
        }

        if (strpos($site_url, 'sc-domain:') === 0) {
            return $site_url;
        }

        if (preg_match('#^https?://#i', $site_url)) {
            return trailingslashit($site_url);
        }

        return 'sc-domain:' . preg_replace('#^www\.#i', '', $site_url);
    }
}
