<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_Fix_Executor
{
    /** @var SEO_Agent_AI_Activity_Log */
    private $activity_log;

    public function __construct(SEO_Agent_AI_Activity_Log $activity_log)
    {
        $this->activity_log = $activity_log;
    }

    /**
     * Apply a safe recommendation to a post.
     *
     * @param int    $post_id        Target post ID.
     * @param array  $recommendation Recommendation payload.
     * @param string $triggered_by   'manual' | 'autopilot' — controls cap check.
     * @param array  $signal_data    Analyzer evidence for activity log.
     * @return true|WP_Error
     */
    public function apply($post_id, array $recommendation, $triggered_by = 'manual', array $signal_data = array())
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return new WP_Error('seo_agent_ai_invalid_post', __('Invalid post target.', 'seo-agent-ai'));
        }

        // Only gate on user capability for manual requests; cron/autopilot runs
        // as a scheduled background task with no user context.
        if ($triggered_by === 'manual' && !current_user_can('edit_post', $post_id)) {
            return new WP_Error('seo_agent_ai_forbidden', __('You are not allowed to edit this post.', 'seo-agent-ai'));
        }

        $type     = isset($recommendation['type']) ? (string) $recommendation['type'] : '';
        $risk     = isset($recommendation['risk']) ? (string) $recommendation['risk'] : 'risky';
        $proposed = isset($recommendation['proposed']) && is_array($recommendation['proposed']) ? $recommendation['proposed'] : array();
        $reason   = isset($recommendation['reason']) ? (string) $recommendation['reason'] : '';
        $confidence = isset($recommendation['confidence']) ? (float) $recommendation['confidence'] : 0.0;

        if ($risk !== 'safe') {
            return new WP_Error('seo_agent_ai_risky_recommendation', __('Only safe recommendations can be auto-applied.', 'seo-agent-ai'));
        }

        if (!in_array($type, array('meta_update', 'monitor_decline'), true)) {
            return new WP_Error('seo_agent_ai_unsupported_recommendation', __('Recommendation type is not supported for auto-apply.', 'seo-agent-ai'));
        }

        $new_title       = isset($proposed['meta_title']) ? sanitize_text_field($proposed['meta_title']) : '';
        $new_description = isset($proposed['meta_description']) ? sanitize_textarea_field($proposed['meta_description']) : '';

        if ($new_title === '' && $new_description === '') {
            return new WP_Error('seo_agent_ai_empty_payload', __('No safe metadata payload found.', 'seo-agent-ai'));
        }

        $this->backup_meta($post_id);

        if ($new_title !== '') {
            $bounded_title = $this->bounded_value($new_title, 60);
            $prev_title    = (string) get_post_meta($post_id, '_seo_agent_ai_meta_title', true);

            update_post_meta($post_id, '_seo_agent_ai_meta_title', $bounded_title);
            update_post_meta($post_id, '_yoast_wpseo_title', $bounded_title);
            update_post_meta($post_id, 'rank_math_title', $bounded_title);

            $this->activity_log->log(
                $post_id, $type, 'meta_title',
                $prev_title, $bounded_title,
                $reason, $signal_data,
                $confidence, $triggered_by
            );
        }

        if ($new_description !== '') {
            $bounded_desc = $this->bounded_value($new_description, 155);
            $prev_desc    = (string) get_post_meta($post_id, '_seo_agent_ai_meta_description', true);

            update_post_meta($post_id, '_seo_agent_ai_meta_description', $bounded_desc);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $bounded_desc);
            update_post_meta($post_id, 'rank_math_description', $bounded_desc);

            $this->activity_log->log(
                $post_id, $type, 'meta_description',
                $prev_desc, $bounded_desc,
                $reason, $signal_data,
                $confidence, $triggered_by
            );
        }

        update_post_meta($post_id, '_seo_agent_ai_last_applied_at', current_time('mysql'));

        return true;
    }

    /**
     * Restore the most recent backup for a post.
     *
     * @param int $post_id
     * @return true|WP_Error
     */
    public function rollback($post_id)
    {
        $history = get_post_meta($post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, true);
        if (!is_array($history) || empty($history)) {
            return new WP_Error('seo_agent_ai_no_backup', __('No backup available for this post.', 'seo-agent-ai'));
        }

        $latest = end($history);

        $fields = array(
            '_seo_agent_ai_meta_title'       => 'meta_title',
            '_seo_agent_ai_meta_description' => 'meta_description',
            '_yoast_wpseo_title'             => 'yoast_meta_title',
            '_yoast_wpseo_metadesc'          => 'yoast_meta_description',
            'rank_math_title'                => 'rank_math_title',
            'rank_math_description'          => 'rank_math_description',
        );

        foreach ($fields as $meta_key => $backup_key) {
            if (isset($latest[$backup_key])) {
                update_post_meta($post_id, $meta_key, (string) $latest[$backup_key]);
            }
        }

        // Remove the entry we just restored from the history stack.
        array_pop($history);
        update_post_meta($post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, $history);

        return true;
    }

    private function backup_meta($post_id)
    {
        $history = get_post_meta($post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, true);
        if (!is_array($history)) {
            $history = array();
        }

        $history[] = array(
            'captured_at' => current_time('mysql'),
            'meta_title' => (string) get_post_meta($post_id, '_seo_agent_ai_meta_title', true),
            'meta_description' => (string) get_post_meta($post_id, '_seo_agent_ai_meta_description', true),
            'yoast_meta_title' => (string) get_post_meta($post_id, '_yoast_wpseo_title', true),
            'yoast_meta_description' => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            'rank_math_title' => (string) get_post_meta($post_id, 'rank_math_title', true),
            'rank_math_description' => (string) get_post_meta($post_id, 'rank_math_description', true),
        );

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        update_post_meta($post_id, SEO_Agent_AI_Data_Store::META_BACKUPS, $history);
    }

    private function bounded_value($value, $max_len)
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        if ($this->str_len($value) <= $max_len) {
            return $value;
        }

        return rtrim($this->str_sub($value, 0, $max_len - 1)) . '...';
    }

    private function str_len($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function str_sub($value, $start, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        return substr($value, $start, $length);
    }
}
