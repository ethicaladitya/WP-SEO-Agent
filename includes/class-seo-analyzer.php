<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Agent_AI_SEO_Analyzer
{
    public function analyze(WP_Post $post, array $gsc, array $ga4)
    {
        $signals = array(
            'content_refresh_needed' => false,
            'title_meta_optimization' => false,
            'intent_mismatch' => false,
            'declining_performance' => false,
        );

        $impressions = isset($gsc['impressions_total']) ? (int) $gsc['impressions_total'] : 0;
        $ctr = isset($gsc['ctr_avg']) ? (float) $gsc['ctr_avg'] : 0.0;
        $position = isset($gsc['position_avg']) ? (float) $gsc['position_avg'] : 100.0;
        $impressions_trend = isset($gsc['impressions_trend_28d']) ? (float) $gsc['impressions_trend_28d'] : 0.0;

        $engagement = isset($ga4['engagement_rate']) ? (float) $ga4['engagement_rate'] : 0.0;
        $time_on_page = isset($ga4['avg_time_on_page_sec']) ? (int) $ga4['avg_time_on_page_sec'] : 0;
        $sessions_trend = isset($ga4['sessions_trend_28d']) ? (float) $ga4['sessions_trend_28d'] : 0.0;

        if ($impressions > 800 && $position >= 6 && $position <= 18 && ($engagement < 0.45 || $time_on_page < 80)) {
            $signals['content_refresh_needed'] = true;
        }

        if ($impressions > 1000 && $ctr < 0.03 && $position <= 15) {
            $signals['title_meta_optimization'] = true;
        }

        if ($position <= 8 && ($engagement < 0.4 || $time_on_page < 70)) {
            $signals['intent_mismatch'] = true;
        }

        if ($impressions_trend < -12 || $sessions_trend < -12) {
            $signals['declining_performance'] = true;
        }

        $severity   = $this->calculate_severity( $signals );
        $confidence = $this->calculate_confidence( $gsc, $ga4, $signals );

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
            ),
        );
    }

    /**
     * Score how confident the agent is in the analysis based on data richness.
     *
     * Formula components:
     *  - Impression volume  (0.15 – 0.40)
     *  - Both data sources  (+0.10)
     *  - Trend data present (+0.05)
     *  - Severity/signal strength (+0.05 – 0.15)
     *
     * Result is clamped to [0.0, 1.0].
     */
    private function calculate_confidence( array $gsc, array $ga4, array $signals ) {
        $impressions       = isset( $gsc['impressions_total'] ) ? (int) $gsc['impressions_total'] : 0;
        $has_sessions      = isset( $ga4['sessions'] ) && (int) $ga4['sessions'] > 0;
        $has_trend_gsc     = isset( $gsc['impressions_trend_28d'] ) && $gsc['impressions_trend_28d'] !== 0;
        $has_trend_ga4     = isset( $ga4['sessions_trend_28d'] ) && $ga4['sessions_trend_28d'] !== 0;
        $active_signals    = count( array_filter( $signals ) );

        // Base score from impressions volume.
        if ( $impressions >= 5000 ) {
            $score = 0.40;
        } elseif ( $impressions >= 2000 ) {
            $score = 0.35;
        } elseif ( $impressions >= 500 ) {
            $score = 0.28;
        } elseif ( $impressions >= 100 ) {
            $score = 0.20;
        } else {
            $score = 0.12;
        }

        // Bonus: both sources available.
        if ( $has_sessions ) {
            $score += 0.10;
        }

        // Bonus: trend data available.
        if ( $has_trend_gsc || $has_trend_ga4 ) {
            $score += 0.05;
        }

        // Bonus: more signals = more confidence.
        $score += min( $active_signals, 4 ) * 0.08;

        return (float) round( min( 1.0, max( 0.0, $score ) ), 3 );
    }

    private function calculate_severity(array $signals)
    {
        $score = 0;
        foreach ($signals as $active) {
            if ($active) {
                $score++;
            }
        }

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
