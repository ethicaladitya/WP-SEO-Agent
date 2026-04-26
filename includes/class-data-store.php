<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_Data_Store
{
    const META_METRICS = '_seo_agent_ai_metrics';
    const META_RECOMMENDATIONS = '_seo_agent_ai_recommendations';
    const META_BACKUPS = '_seo_agent_ai_backups';
    const OPTION_LAST_RUN = 'seo_agent_ai_last_run';

    public function save_post_metrics($post_id, array $metrics)
    {
        update_post_meta($post_id, self::META_METRICS, $metrics);
    }

    public function get_post_metrics($post_id)
    {
        $metrics = get_post_meta($post_id, self::META_METRICS, true);
        return is_array($metrics) ? $metrics : array();
    }

    public function save_recommendations($post_id, array $recommendations)
    {
        if (empty($recommendations)) {
            delete_post_meta($post_id, self::META_RECOMMENDATIONS);
            return;
        }

        update_post_meta($post_id, self::META_RECOMMENDATIONS, array_values($recommendations));
    }

    public function get_recommendations($post_id)
    {
        $recommendations = get_post_meta($post_id, self::META_RECOMMENDATIONS, true);
        return is_array($recommendations) ? $recommendations : array();
    }

    public function add_backup($post_id, array $backup)
    {
        $history = get_post_meta($post_id, self::META_BACKUPS, true);
        if (!is_array($history)) {
            $history = array();
        }

        $history[] = $backup;

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        update_post_meta($post_id, self::META_BACKUPS, $history);
    }

    /**
     * Get the full backup history for a post.
     *
     * @param int $post_id
     * @return array
     */
    public function get_backups($post_id)
    {
        $history = get_post_meta($post_id, self::META_BACKUPS, true);
        return is_array($history) ? array_reverse($history) : array(); // newest first
    }

    /**
     * Clear all backups for a post.
     *
     * @param int $post_id
     */
    public function clear_backups($post_id)
    {
        delete_post_meta($post_id, self::META_BACKUPS);
    }

    public function get_posts_with_recommendations($limit = 100)
    {
        $query = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- controlled key lookup for recommendation index.
            'meta_key' => self::META_RECOMMENDATIONS,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ));

        $post_ids = array_map('intval', $query->posts);

        return array_values(array_filter($post_ids, array($this, 'has_recommendations')));
    }

    public function set_last_run(array $data)
    {
        update_option(self::OPTION_LAST_RUN, $data, false);
    }

    public function get_last_run()
    {
        $value = get_option(self::OPTION_LAST_RUN, array());
        return is_array($value) ? $value : array();
    }

    private function has_recommendations($post_id)
    {
        $recommendations = $this->get_recommendations($post_id);
        return !empty($recommendations);
    }
}
