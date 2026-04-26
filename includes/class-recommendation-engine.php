<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_Recommendation_Engine
{
    public function generate(WP_Post $post, array $analysis, array $gsc, array $ga4)
    {
        $signals    = isset($analysis['signals']) ? $analysis['signals'] : array();
        $confidence = isset($analysis['confidence']) ? (float) $analysis['confidence'] : 0.5;
        $top_query  = $this->extract_top_query($gsc);

        $recommendations = array();

        if (!empty($signals['title_meta_optimization'])) {
            $recommendations[] = array(
                'type'       => 'meta_update',
                'risk'       => 'safe',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => 'High impressions with low CTR indicates a snippet optimization opportunity. Improving the meta title and description should increase click-through rate.',
                'proposed'   => array(
                    'meta_title'       => $this->build_meta_title($post->post_title, $top_query),
                    'meta_description' => $this->build_meta_description($post, $top_query),
                ),
            );
        }

        if (!empty($signals['content_refresh_needed'])) {
            $recommendations[] = array(
                'type'       => 'content_refresh_plan',
                'risk'       => 'risky',
                'priority'   => 'medium',
                'confidence' => $confidence,
                'reason'     => 'Page has ranking potential (positions 6–18) but low engagement metrics. Content likely needs expanded coverage of user intent or a freshness update.',
                'proposed'   => array(
                    'summary' => 'Expand sections covering user intent clusters from top queries, refresh outdated statistics, and add structured data where relevant.',
                ),
            );
        }

        if (!empty($signals['intent_mismatch'])) {
            $recommendations[] = array(
                'type'       => 'intent_alignment',
                'risk'       => 'risky',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => 'Strong rankings but weak engagement suggests the page content does not deliver on the promise of the search snippet. Rewrite the introduction to immediately address dominant search intent.',
                'proposed'   => array(
                    'summary' => 'Revise headings, introduction paragraph, and meta snippet to align tightly with the dominant query intent.',
                ),
            );
        }

        if (!empty($signals['declining_performance'])) {
            $recommendations[] = array(
                'type'       => 'monitor_decline',
                'risk'       => 'safe',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => 'Impressions or sessions have dropped more than 12% over 28 days. Tightening metadata is a low-risk first step while deeper content causes are investigated.',
                'proposed'   => array(
                    'meta_title'       => $this->build_meta_title($post->post_title, $top_query),
                    'meta_description' => $this->build_meta_description($post, $top_query),
                ),
            );
        }

        return $this->dedupe_recommendations($recommendations);
    }

    private function extract_top_query(array $gsc)
    {
        if (empty($gsc['queries']) || !is_array($gsc['queries'])) {
            return 'wordpress seo';
        }

        usort($gsc['queries'], function ($a, $b) {
            $a_impressions = isset($a['impressions']) ? (int) $a['impressions'] : 0;
            $b_impressions = isset($b['impressions']) ? (int) $b['impressions'] : 0;
            return $b_impressions - $a_impressions;
        });

        $query = isset($gsc['queries'][0]['query']) ? (string) $gsc['queries'][0]['query'] : 'wordpress seo';
        return sanitize_text_field($query);
    }

    private function build_meta_title($post_title, $top_query)
    {
        $base = trim((string) $post_title);
        $query = ucwords($top_query);

        $candidate = $query . ' | ' . $base;
        $candidate = wp_strip_all_tags($candidate);

        return $this->truncate($candidate, 60);
    }

    private function build_meta_description(WP_Post $post, $top_query)
    {
        $excerpt = trim(wp_strip_all_tags($post->post_excerpt));
        if ($excerpt === '') {
            $excerpt = trim(wp_strip_all_tags(wp_trim_words($post->post_content, 24, '')));
        }

        $prefix = 'Learn ' . strtolower($top_query) . '. ';
        $candidate = $prefix . $excerpt;

        return $this->truncate($candidate, 155);
    }

    private function truncate($text, $max_len)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        if ($this->str_len($text) <= $max_len) {
            return $text;
        }

        return rtrim($this->str_sub($text, 0, $max_len - 1)) . '...';
    }

    private function dedupe_recommendations(array $recommendations)
    {
        $seen = array();
        $deduped = array();

        foreach ($recommendations as $recommendation) {
            $hash = md5(wp_json_encode($recommendation));
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $deduped[] = $recommendation;
        }

        return $deduped;
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
