=== SEO Agent AI ===
Contributors: seoagentai
Tags: seo, analytics, search-console, ga4, recommendations
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Autonomous SEO agent that connects Google Search Console and GA4, surfaces post-level opportunities, and proposes safe, reversible metadata fixes.

== Description ==

SEO Agent AI continuously analyzes your blog using Google Search Console and Google Analytics 4 data, scores each post for SEO opportunities, and proposes prioritized recommendations.

Where it adds value:

* Pulls real Search Console queries, impressions, clicks, CTR, and average position per post.
* Pulls GA4 sessions, engagement rate, and average time-on-page per post.
* Detects six SEO signals per post: missing meta basics, title/meta optimization opportunity, thin content, content refresh needed, intent mismatch, and declining performance.
* Builds prioritized recommendations with confidence scores and a clear "safe vs risky" classification.
* Optional Gemini AI integration to generate keyword-rich meta titles, meta descriptions, and focus-keyword suggestions.
* Writes meta updates to all detected SEO plugins (Yoast, RankMath, SmartCrawl, The SEO Framework) plus the plugin's own meta keys.
* Every change is recorded in an activity log table with full before/after, signals, and confidence — and is one-click reversible.
* Optional autopilot mode applies only safe recommendations above a confidence floor, capped at a configurable daily limit.

This plugin makes no remote calls until you connect a Google account and provide an OAuth client ID and secret. The Gemini integration is fully optional and is gated on a user-supplied API key.

== Installation ==

1. Upload the `seo-agent-ai` folder to `/wp-content/plugins/`, or install via the WordPress plugin browser.
2. Activate SEO Agent AI from the Plugins screen.
3. Open SEO Agent AI → Settings and enter your Google OAuth Client ID + Client Secret. (Create a project at https://console.cloud.google.com/, enable the Search Console API and the Google Analytics Data API, and add the wp-admin URL of your "Connect Google" page as an authorized redirect URI.)
4. Open SEO Agent AI → Connect Google and complete the OAuth flow.
5. In Settings, pick your verified Search Console property and your GA4 property.
6. Optionally paste a Gemini API key to enable AI-powered meta-title and description generation.
7. Click "Run Analysis Now" once to seed the data; daily WP-Cron will keep it fresh.

== Frequently Asked Questions ==

= Does the plugin auto-publish changes to my live posts? =

No. Default mode is recommendation-only. You must click "Approve & Apply" on each safe recommendation. Autopilot mode is opt-in, applies only "safe" classifications above your confidence floor, and is capped to a daily limit you set.

= Does it call any external service before I configure it? =

No. The plugin makes no remote calls until you supply OAuth credentials and connect a Google account. The optional Gemini integration is only invoked when you save an API key.

= Where are my OAuth tokens stored? =

In the WordPress options table, encrypted with AES-256-CBC using a key derived from your `wp_salt('secure_auth')`. Refresh tokens, access tokens, and (since v2.1.0) the Gemini API key are all encrypted at rest.

= Which SEO plugins does it write to? =

Yoast SEO, RankMath, SmartCrawl, and The SEO Framework — all that are detected as active. The plugin also stores its own copy in dedicated post-meta keys, so you keep your data even if you swap SEO plugins later.

= How do I roll back a change? =

Open SEO Agent AI → Activity Report, find the entry, click Rollback. The previous value is restored across every SEO plugin meta key that was originally written.

= Will the plugin uninstall cleanly? =

Yes. Uninstalling removes the activity log table, every plugin option, every plugin post-meta key, all transients, and unschedules the cron event.

== External Services ==

This plugin connects to third-party services **only after you provide credentials and authorize the connection**. No external call is made on plugin activation.

**Google APIs (mandatory for core functionality)**
When you complete the Google OAuth flow, the plugin communicates with:
- `accounts.google.com` — OAuth 2.0 authorization and token exchange
- `oauth2.googleapis.com` — Access-token refresh
- `searchconsole.googleapis.com` — Fetching Search Console query data, impressions, clicks, and ranking positions for your verified property
- `analyticsdata.googleapis.com` — Fetching GA4 sessions, engagement rate, and time-on-page
- `analyticsadmin.googleapis.com` — Listing available GA4 properties
- Privacy policy: https://policies.google.com/privacy | Terms: https://policies.google.com/terms

**Gemini AI (optional)**
When you save a Gemini API key in Settings, AI-generated meta titles and descriptions are fetched from:
- `generativelanguage.googleapis.com`
- Privacy policy: https://ai.google.dev/gemini-api/terms

**OpenAI-compatible endpoint (optional)**
When you configure an API key and base URL for an OpenAI-compatible provider (defaults to `https://api.openai.com/v1`), AI meta suggestions are fetched from your configured endpoint. This could be the standard OpenAI endpoint or any compatible alternative you specify.
- OpenAI privacy policy: https://openai.com/policies/privacy-policy | Terms: https://openai.com/policies/terms-of-use

No data is sent to any service until you explicitly configure and connect it.

== Screenshots ==

1. Overview dashboard showing per-post signals and recommendations.
2. Connect Google page (OAuth flow).
3. Settings page with Google + Gemini configuration and autopilot controls.
4. Activity Report with filters and one-click rollback.
5. Per-post recommendation card with proposed metadata.

== Changelog ==

= 3.0.0 =
* Full autonomous SEO growth engine: multi-dimensional 0-100 SEO scoring per page, AI decision queue with confidence tiers, admin approval workflow.
* Five new database tables: keyword_history, page_insights, ai_decisions, daily_reports, internal_links.
* Seven specialized cron jobs: dedicated GSC fetch, GA4 fetch, daily report generation, weekly page scoring, content decay detection, internal link scan, old-data purge.
* OpenAI-compatible AI provider: configure any OpenAI-compatible endpoint (standard OpenAI or custom base URL) alongside or instead of Gemini; auto-fallback logic.
* New admin pages: SEO Dashboard with widgets, Opportunities ranked list, Keyword Rankings chart, Pending Approvals queue, Rollback Center with search, Cron Status table.
* Internal link engine: detects orphan pages, inserts up to 3 contextual links per post per run, fully reversible via internal_links table.
* Schema engine: auto-injects Article, BlogPosting, FAQPage, HowTo, and BreadcrumbList JSON-LD via wp_head.
* WP-CLI suite: 10 subcommands (analyze, optimize, report, rollback, fetch-gsc, fetch-ga4, score, opportunities, status, logs).
* Dry-run mode throughout the analysis and fix chain; --dry-run flag in all WP-CLI commands.
* Deduplication of AI decisions: repeated cron runs update existing pending decisions rather than inserting duplicates.
* SEO plugin bridge expanded: added AIOSEO and SEOPress support alongside Yoast, RankMath, SmartCrawl, The SEO Framework.
* Verbose logger: configurable log levels (debug/info/warning/error) written to WP debug log or seo-agent-ai.log.
* Queue manager: persistent batch processing with API rate limiting and exponential backoff on 429/503 responses.

= 2.1.1 =
* Always-visible authentication health banner on the Connect page that explains the most likely cause when token refresh fails (rotated client secret vs revoked refresh token).
* Health probe result cached for 60 seconds so reloading does not hit Google's token endpoint repeatedly.

= 2.1.0 =
* Added uninstall.php for clean removal of all options, post meta, transients, custom table, and cron.
* Encrypted Gemini API key at rest using shared crypto helper.
* Activity-log schema upgrades automatically on plugin update (no deactivate/reactivate needed).
* Activity-log rollback now restores meta keys across all detected SEO plugins (previously only Yoast/RankMath).
* Replaced synchronous "Run Analysis Now" with a single-shot scheduled event to avoid PHP timeouts on slow hosts.
* Persistent admin notice on consecutive Search Console / GA4 API failures.
* Defensive cron rescheduling on init survives migrations and clones.
* Wrapped previously untranslated user-facing strings; full i18n coverage under text domain seo-agent-ai.
* Plugin header completed with Plugin URI, Author URI, License URI, Requires at least, Requires PHP, Domain Path.
* Removed dead legacy auth class.

= 2.0.0 =
* OAuth-based Google integration (Search Console + Analytics 4).
* AJAX batch analysis path with progress reporting.
* Gemini AI-assisted meta title / description / focus-keyword generation.
* Multi-SEO-plugin write bridge.

= 1.0.0 =
* Initial production release with analysis, recommendations, and safe metadata execution.

== Upgrade Notice ==

= 3.0.0 =
Major release. Run database upgrade automatically on activation. Adds autonomous SEO scoring, AI decision queue, 5 new DB tables, OpenAI provider support, 7 cron jobs, 6 new admin pages, and WP-CLI suite.

= 2.1.1 =
Adds an always-visible authentication health banner on the Connect page so credential failures are obvious without clicking Test Connection.

= 2.1.0 =
Hardening release: clean uninstall, encrypted API key storage, automatic schema upgrade, full rollback parity across SEO plugins, async manual analysis, persistent error notices.
