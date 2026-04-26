<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_Google_Auth
{
    const OPTION_ACCESS_TOKEN = 'seo_agent_ai_google_access_token';
    const OPTION_REFRESH_TOKEN = 'seo_agent_ai_google_refresh_token';
    const OPTION_CLIENT_ID = 'seo_agent_ai_google_client_id';
    const OPTION_CLIENT_SECRET = 'seo_agent_ai_google_client_secret';
    const OPTION_TOKEN_EXPIRES_AT = 'seo_agent_ai_google_access_token_expires_at';

    public function get_access_token()
    {
        $access_token = $this->get_option_or_constant('SEO_AGENT_AI_GOOGLE_ACCESS_TOKEN', self::OPTION_ACCESS_TOKEN);

        if ($this->is_likely_api_key($access_token)) {
            return new WP_Error(
                'seo_agent_ai_invalid_token_type',
                __('Saved token appears to be an API key. Use OAuth access token or refresh token flow.', 'seo-agent-ai')
            );
        }

        if ($access_token !== '' && !$this->is_expired()) {
            return $access_token;
        }

        $refresh_token = $this->get_option_or_constant('SEO_AGENT_AI_GOOGLE_REFRESH_TOKEN', self::OPTION_REFRESH_TOKEN);
        $client_id = $this->get_option_or_constant('SEO_AGENT_AI_GOOGLE_CLIENT_ID', self::OPTION_CLIENT_ID);
        $client_secret = $this->get_option_or_constant('SEO_AGENT_AI_GOOGLE_CLIENT_SECRET', self::OPTION_CLIENT_SECRET);

        if ($refresh_token === '' || $client_id === '' || $client_secret === '') {
            if ($access_token !== '') {
                return $access_token;
            }

            return new WP_Error(
                'seo_agent_ai_google_not_configured',
                __('Google OAuth is not fully configured. Provide access token or refresh token + client credentials.', 'seo-agent-ai')
            );
        }

        return $this->refresh_access_token($refresh_token, $client_id, $client_secret);
    }

    private function refresh_access_token($refresh_token, $client_id, $client_secret)
    {
        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 20,
                'body' => array(
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type' => 'refresh_token',
                ),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('seo_agent_ai_google_refresh_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $message = isset($data['error_description']) ? (string) $data['error_description'] : __('Could not refresh Google access token.', 'seo-agent-ai');
            return new WP_Error('seo_agent_ai_google_refresh_api_error', $message);
        }

        $new_access_token = sanitize_text_field((string) $data['access_token']);
        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        update_option(self::OPTION_ACCESS_TOKEN, $new_access_token, false);
        update_option(self::OPTION_TOKEN_EXPIRES_AT, time() + max(60, ($expires_in - 60)), false);

        return $new_access_token;
    }

    private function get_option_or_constant($constant_name, $option_key)
    {
        if (defined($constant_name)) {
            $value = constant($constant_name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $option_value = get_option($option_key, '');
        return is_string($option_value) ? trim($option_value) : '';
    }

    private function is_expired()
    {
        $expires_at = (int) get_option(self::OPTION_TOKEN_EXPIRES_AT, 0);
        if ($expires_at <= 0) {
            return false;
        }

        return time() >= $expires_at;
    }

    private function is_likely_api_key($token)
    {
        return is_string($token) && strpos($token, 'AIza') === 0;
    }
}
