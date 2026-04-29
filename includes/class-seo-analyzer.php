<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_SEO_Analyzer
{
    /**
     * Analyze a post and return signals, severity, confidence, and evidence.
     *
     * @param WP_Post $post
     * @param array   $gsc        GSC metrics (may be empty on API error).
     * @param array   $ga4        GA4 metrics (may be empty on API error).
     * @param array   $seo_audit  On-page SEO audit from SEO_Plugin_Bridge::audit_post().
     * @return array
     */
    public function analyze(WP_Post $post, array $gsc, array $ga4, array $seo_audit = array())
    {
        $signals = array(
            'missing_meta_basics'     => false, // No title or description set anywhere.
            'thin_content'            => false, // Post content under 300 words.
            'title_meta_optimization' => false, // High impressions, low CTR — title/desc needs work.
            'content_refresh_needed'  => false, // Mid-page ranking with poor engagement.
            'intent_mismatch'         => false, // Ranking but users bounce/don't engage.
            'declining_performance'   => false, // Traffic/sessions trending down >10%.
        );

        // -------------------------------------------------------------------
        // Baseline on-page SEO signals (fire even with zero traffic)
        // -------------------------------------------------------------------

        if (!empty($seo_audit)) {
            if (!$seo_audit['has_title'] || !$seo_audit['has_description']) {
                $signals['missing_meta_basics'] = true;
            }
            if (!empty($seo_audit['content_thin'])) {
                $signals['thin_content'] = true;
            }
            // Title length issues also flag title_meta_optimization.
            if (!empty($seo_audit['title_too_long']) || !empty($seo_audit['title_too_short'])) {
                $signals['title_meta_optimization'] = true;
            }
        }

        // -------------------------------------------------------------------
        // Traffic-based signals (require impression/metric data)
        // -------------------------------------------------------------------

        $impressions       = isset($gsc['impressions_total']) ? (int) $gsc['impressions_total'] : 0;
        $ctr               = isset($gsc['ctr_avg']) ? (float) $gsc['ctr_avg'] : 0.0;
        $position          = isset($gsc['position_avg']) ? (float) $gsc['position_avg'] : 100.0;
        $impressions_trend = isset($gsc['impressions_trend_28d']) ? (float) $gsc['impressions_trend_28d'] : 0.0;

        $engagement    = isset($ga4['engagement_rate']) ? (float) $ga4['engagement_rate'] : 0.0;
        $time_on_page  = isset($ga4['avg_time_on_page_sec']) ? (int) $ga4['avg_time_on_page_sec'] : 0;
        $sessions_trend = isset($ga4['sessions_trend_28d']) ? (float) $ga4['sessions_trend_28d'] : 0.0;

        // Low CTR opportunity: lowered from >1000 to >100 impressions.
        if ($impressions > 100 && $ctr < 0.03 && $position <= 25) {
            $signals['title_meta_optimization'] = true;
        }

        // Content refresh: lowered from >800 to >100, broadened position range.
        if ($impressions > 100 && $position >= 5 && $position <= 30 && ($engagement < 0.5 || $time_on_page < 90)) {
            $signals['content_refresh_needed'] = true;
        }

        // Intent mismatch: broadened from position <=8 to <=12.
        if ($impressions > 50 && $position <= 12 && ($engagement < 0.4 || $time_on_page < 70)) {
            $signals['intent_mismatch'] = true;
        }

        // Declining performance: lowered from -12% to -10%.
        if ($impressions_trend < -10.0 || $sessions_trend < -10.0) {
            $signals['declining_performance'] = true;
        }

        $severity   = $this->calculate_severity($signals);
        $confidence = $this->calculate_confidence($gsc, $ga4, $signals, $seo_audit);

        return array(
            'signals'    => $signals,
            'severity'   => $severity,
            'confidence' => $confidence,
            'evidence'   => array(
                'impressions_total'         => $impressions,
                'ctr_avg'                   => $ctr,
                'position_avg'              => $position,
                'engagement_rate'           => $engagement,
                'avg_time_on_page_sec'      => $time_on_page,
                'impressions_trend_28d_pct' => $impressions_trend,
                'sessions_trend_28d_pct'    => $sessions_trend,
                'word_count'                => isset($seo_audit['word_count']) ? $seo_audit['word_count'] : 0,
                'has_meta_title'            => isset($seo_audit['has_title']) ? (bool) $seo_audit['has_title'] : null,
                'has_meta_description'      => isset($seo_audit['has_description']) ? (bool) $seo_audit['has_description'] : null,
            ),
        );
    }

    /**
     * Score how confident the agent is in the analysis based on data richness.
     *
     * Baseline SEO signals (missing_meta_basics, thin_content) are treated
     * as deterministic — they get high confidence even with no traffic data.
     */
    private function calculate_confidence(array $gsc, array $ga4, array $signals, array $seo_audit = array())
    {
        $impressions    = isset($gsc['impressions_total']) ? (int) $gsc['impressions_total'] : 0;
        $has_sessions   = isset($ga4['sessions_28d']) && (int) $ga4['sessions_28d'] > 0;
        $has_trend_gsc  = isset($gsc['impressions_trend_28d']) && (float) $gsc['impressions_trend_28d'] !== 0.0;
        $has_trend_ga4  = isset($ga4['sessions_trend_28d']) && (float) $ga4['sessions_trend_28d'] !== 0.0;
        $active_signals = count(array_filter($signals));

        // Deterministic baseline checks: always high confidence.
        if (!empty($signals['missing_meta_basics']) && $impressions === 0) {
            $score = 0.90;
            $score += !empty($signals['thin_content']) ? 0.05 : 0.0;
            return (float) round(min(1.0, $score), 3);
        }

        // Base score from impression volume.
        if ($impressions >= 5000) {
            $score = 0.40;
        } elseif ($impressions >= 2000) {
            $score = 0.35;
        } elseif ($impressions >= 500) {
            $score = 0.28;
        } elseif ($impressions >= 100) {
            $score = 0.20;
        } elseif ($impressions >= 10) {
            $score = 0.15;
        } else {
            $score = 0.12;
        }

        // Bonus: both data sources available.
        if ($has_sessions) {
            $score += 0.10;
        }

        // Bonus: trend data available.
        if ($has_trend_gsc || $has_trend_ga4) {
            $score += 0.05;
        }

        // Bonus: baseline SEO audit available.
        if (!empty($seo_audit)) {
            $score += 0.05;
        }

        // Bonus: more active signals = more confidence.
        $score += min($active_signals, 4) * 0.08;

        return (float) round(min(1.0, max(0.0, $score)), 3);
    }

    private function calculate_severity(array $signals)
    {
        $score = count(array_filter($signals));

        if ($score >= 3) {
            return 'high';
        }
        if ($score === 2) {
            return 'medium';
        }
        if ($score === 1) {
            return 'low';
        }
        return 'none';
    }
}
