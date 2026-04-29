<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_Recommendation_Engine
{
    /** @var SEO_Agent_AI_Gemini_Client|null */
    private $gemini;

    public function __construct(SEO_Agent_AI_Gemini_Client $gemini = null)
    {
        $this->gemini = $gemini;
    }

    /**
     * Generate an array of actionable recommendations for a post.
     *
     * @param WP_Post $post
     * @param array   $analysis    Result of SEO_Analyzer::analyze().
     * @param array   $gsc         GSC metrics.
     * @param array   $ga4         GA4 metrics.
     * @param array   $seo_audit   On-page SEO audit from SEO_Plugin_Bridge::audit_post().
     * @return array
     */
    public function generate(WP_Post $post, array $analysis, array $gsc, array $ga4, array $seo_audit = array())
    {
        $signals    = isset($analysis['signals']) ? $analysis['signals'] : array();
        $confidence = isset($analysis['confidence']) ? (float) $analysis['confidence'] : 0.5;
        $top_query  = $this->extract_top_query($gsc);

        $recommendations = array();

        // -------------------------------------------------------------------
        // 1. Missing meta basics (highest priority — fires even with no traffic)
        // -------------------------------------------------------------------

        if (!empty($signals['missing_meta_basics'])) {
            $has_title = isset($seo_audit['has_title']) ? (bool) $seo_audit['has_title'] : false;
            $has_desc  = isset($seo_audit['has_description']) ? (bool) $seo_audit['has_description'] : false;

            $missing_parts = array();
            if (!$has_title) {
                $missing_parts[] = __('meta title', 'seo-agent-ai');
            }
            if (!$has_desc) {
                $missing_parts[] = __('meta description', 'seo-agent-ai');
            }

            $reason = sprintf(
                /* translators: %s: comma-separated list of missing SEO meta fields. */
                __('No %s found in any active SEO plugin. These are the most fundamental SEO elements — without them, Google writes its own snippets which are typically less compelling and may hurt CTR.', 'seo-agent-ai'),
                implode(' / ', $missing_parts)
            );

            $recommendations[] = array(
                'type'       => 'meta_update',
                'risk'       => 'safe',
                'priority'   => 'high',
                'confidence' => max($confidence, 0.85),
                'reason'     => $reason,
                'proposed'   => array(
                    'meta_title'       => $this->build_meta_title($post, $top_query),
                    'meta_description' => $this->build_meta_description($post, $top_query),
                    'focus_keyword'    => $this->build_focus_keyword($post, $gsc),
                ),
            );
        } elseif (!empty($signals['title_meta_optimization'])) {
            // -------------------------------------------------------------------
            // 2. Title/meta optimization — low CTR, title length issues
            // -------------------------------------------------------------------

            $impressions = isset($gsc['impressions_total']) ? (int) $gsc['impressions_total'] : 0;
            $ctr         = isset($gsc['ctr_avg']) ? round((float) $gsc['ctr_avg'] * 100, 1) : 0;
            $position    = isset($gsc['position_avg']) ? round((float) $gsc['position_avg'], 1) : 0;

            $title_issue = '';
            $title_len   = isset($seo_audit['title_length']) ? (int) $seo_audit['title_length'] : 0;
            if (!empty($seo_audit['title_too_long'])) {
                $title_issue = ' ' . sprintf(
                    /* translators: %d: title length in characters. */
                    __('Current title (%d chars) exceeds the 60-char display limit and will be truncated in search results.', 'seo-agent-ai'),
                    $title_len
                );
            } elseif (!empty($seo_audit['title_too_short'])) {
                $title_issue = ' ' . sprintf(
                    /* translators: %d: title length in characters. */
                    __('Current title (%d chars) is too short — you are leaving valuable keyword space unused.', 'seo-agent-ai'),
                    $title_len
                );
            }

            $traffic_context = '';
            if ($impressions > 0) {
                $traffic_context = ' ' . sprintf(
                    /* translators: 1: impressions count, 2: average position, 3: CTR percent. */
                    __('With %1$s impressions at position %2$.1f, a CTR of %3$.1f%% is below what this position should yield.', 'seo-agent-ai'),
                    number_format_i18n($impressions),
                    $position,
                    $ctr
                );
            }

            $recommendations[] = array(
                'type'       => 'meta_update',
                'risk'       => 'safe',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => __('SEO snippet optimization opportunity.', 'seo-agent-ai') . $title_issue . $traffic_context
                    . ' ' . __('A stronger title and description will increase click-through rate.', 'seo-agent-ai'),
                'proposed'   => array(
                    'meta_title'       => $this->build_meta_title($post, $top_query),
                    'meta_description' => $this->build_meta_description($post, $top_query),
                    'focus_keyword'    => $this->build_focus_keyword($post, $gsc),
                ),
            );
        }

        // -------------------------------------------------------------------
        // 3. Thin content
        // -------------------------------------------------------------------

        if (!empty($signals['thin_content'])) {
            $word_count = isset($seo_audit['word_count']) ? (int) $seo_audit['word_count'] : 0;
            $recommendations[] = array(
                'type'       => 'content_expansion',
                'risk'       => 'risky',
                'priority'   => 'medium',
                'confidence' => max($confidence, 0.80),
                'reason'     => sprintf(
                    /* translators: %d: post word count. */
                    __('This post has only %d words. Google consistently favours comprehensive content (600+ words) that thoroughly covers a topic. Consider expanding with examples, FAQ sections, or deeper analysis.', 'seo-agent-ai'),
                    $word_count
                ),
                'proposed'   => array(
                    'summary' => sprintf(
                        /* translators: %s: target search query or post title. */
                        __('Expand content to at least 600 words. Add: (1) a FAQ block answering "People Also Ask" questions for "%s", (2) real examples or case studies, (3) a clear summary with actionable takeaways.', 'seo-agent-ai'),
                        $top_query !== '' ? $top_query : $post->post_title
                    ),
                ),
            );
        }

        // -------------------------------------------------------------------
        // 4. Content refresh
        // -------------------------------------------------------------------

        if (!empty($signals['content_refresh_needed'])) {
            $position = isset($gsc['position_avg']) ? round((float) $gsc['position_avg'], 1) : 0;
            $eng_pct  = isset($ga4['engagement_rate']) ? round((float) $ga4['engagement_rate'] * 100, 0) : 0;

            $recommendations[] = array(
                'type'       => 'content_refresh_plan',
                'risk'       => 'risky',
                'priority'   => 'medium',
                'confidence' => $confidence,
                'reason'     => sprintf(
                    /* translators: 1: average search position, 2: engagement rate percent. */
                    __('Page ranks at position %1$.1f but engagement rate is only %2$d%%. Users are not finding what they expected — likely an intent mismatch or outdated content. A content refresh can push this page from position 6-30 into the top 5.', 'seo-agent-ai'),
                    $position,
                    $eng_pct
                ),
                'proposed'   => array(
                    'summary' => sprintf(
                        /* translators: %s: target search query or post title. */
                        __('Audit this page against the top 3 ranking competitors for "%s". Add missing sections, update outdated statistics, improve the introduction, add structured H2/H3 headings, and include an FAQ block for People Also Ask coverage.', 'seo-agent-ai'),
                        $top_query !== '' ? $top_query : $post->post_title
                    ),
                ),
            );
        }

        // -------------------------------------------------------------------
        // 5. Intent mismatch
        // -------------------------------------------------------------------

        if (!empty($signals['intent_mismatch'])) {
            $position = isset($gsc['position_avg']) ? round((float) $gsc['position_avg'], 1) : 0;
            $time_sec = isset($ga4['avg_time_on_page_sec']) ? (int) $ga4['avg_time_on_page_sec'] : 0;

            $recommendations[] = array(
                'type'       => 'intent_alignment',
                'risk'       => 'risky',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => sprintf(
                    /* translators: 1: average search position, 2: average time on page in seconds. */
                    __('Strong ranking at position %1$.1f, but users spend only %2$ds on the page — a clear intent mismatch signal. The snippet promises something the content does not immediately deliver. Rewrite the introduction to answer the primary search intent within the first 100 words.', 'seo-agent-ai'),
                    $position,
                    $time_sec
                ),
                'proposed'   => array(
                    'summary' => sprintf(
                        /* translators: %s: target search query or post title. */
                        __('Revise the H1, introduction, and meta snippet to directly answer "%s" within the first visible paragraph. Move the most important answer/information above the fold.', 'seo-agent-ai'),
                        $top_query !== '' ? $top_query : $post->post_title
                    ),
                ),
            );
        }

        // -------------------------------------------------------------------
        // 6. Declining performance
        // -------------------------------------------------------------------

        if (!empty($signals['declining_performance'])) {
            $impr_trend = isset($gsc['impressions_trend_28d']) ? round((float) $gsc['impressions_trend_28d'], 1) : 0;
            $sess_trend = isset($ga4['sessions_trend_28d']) ? round((float) $ga4['sessions_trend_28d'], 1) : 0;

            $trend_str = $impr_trend < -10
                ? sprintf(
                    /* translators: %s: signed percent change for impressions. */
                    __('%s%% impressions', 'seo-agent-ai'),
                    sprintf('%+.1f', $impr_trend)
                )
                : sprintf(
                    /* translators: %s: signed percent change for sessions. */
                    __('%s%% sessions', 'seo-agent-ai'),
                    sprintf('%+.1f', $sess_trend)
                );

            $recommendations[] = array(
                'type'       => 'monitor_decline',
                'risk'       => 'safe',
                'priority'   => 'high',
                'confidence' => $confidence,
                'reason'     => sprintf(
                    /* translators: %s: trend descriptor like "-15.2% impressions". */
                    __('Performance has dropped %s over the past 28 days. Refreshing the meta title and description is a quick, low-risk first step while you investigate deeper content causes (competitor updates, algorithm changes).', 'seo-agent-ai'),
                    $trend_str
                ),
                'proposed'   => array(
                    'meta_title'       => $this->build_meta_title($post, $top_query),
                    'meta_description' => $this->build_meta_description($post, $top_query),
                ),
            );
        }

        return $this->dedupe_recommendations($recommendations);
    }

    // -----------------------------------------------------------------------
    // Meta generation (Gemini → rule-based fallback)
    // -----------------------------------------------------------------------

    private function build_meta_title(WP_Post $post, $top_query)
    {
        if ($this->gemini !== null && $this->gemini->is_configured()) {
            $generated = $this->gemini->generate_meta_title($post, $top_query);
            if ($generated !== null) {
                return $generated;
            }
        }

        $base      = trim((string) $post->post_title);
        $query     = ucwords((string) $top_query);
        $candidate = $query !== '' ? ($query . ' — ' . $base) : $base;
        return $this->truncate(wp_strip_all_tags($candidate), 60);
    }

    private function build_meta_description(WP_Post $post, $top_query)
    {
        if ($this->gemini !== null && $this->gemini->is_configured()) {
            $generated = $this->gemini->generate_meta_description($post, $top_query);
            if ($generated !== null) {
                return $generated;
            }
        }

        $excerpt = trim(wp_strip_all_tags($post->post_excerpt));
        if ($excerpt === '') {
            $excerpt = trim(wp_strip_all_tags(wp_trim_words($post->post_content, 30, '')));
        }

        $prefix    = $top_query !== '' ? ('Learn about ' . strtolower($top_query) . '. ') : '';
        return $this->truncate($prefix . $excerpt, 155);
    }

    private function build_focus_keyword(WP_Post $post, array $gsc)
    {
        if ($this->gemini !== null && $this->gemini->is_configured()) {
            $queries   = isset($gsc['queries']) && is_array($gsc['queries']) ? $gsc['queries'] : array();
            $generated = $this->gemini->suggest_focus_keyword($post, $queries);
            if ($generated !== null) {
                return $generated;
            }
        }
        return $this->extract_top_query($gsc);
    }

    private function extract_top_query(array $gsc)
    {
        if (empty($gsc['queries']) || !is_array($gsc['queries'])) {
            return '';
        }

        usort($gsc['queries'], function ($a, $b) {
            return ((int)(isset($b['impressions']) ? $b['impressions'] : 0))
                - ((int)(isset($a['impressions']) ? $a['impressions'] : 0));
        });

        return sanitize_text_field((string)(isset($gsc['queries'][0]['query']) ? $gsc['queries'][0]['query'] : ''));
    }

    private function truncate($text, $max_len)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        $len_fn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
        $sub_fn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
        if ($len_fn($text) <= $max_len) {
            return $text;
        }
        return rtrim($sub_fn($text, 0, $max_len - 1)) . '…';
    }

    private function dedupe_recommendations(array $recommendations)
    {
        $seen    = array();
        $deduped = array();

        foreach ($recommendations as $rec) {
            $hash = md5((string) wp_json_encode(array($rec['type'], $rec['priority'])));
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $deduped[]   = $rec;
            }
        }

        return $deduped;
    }
}
