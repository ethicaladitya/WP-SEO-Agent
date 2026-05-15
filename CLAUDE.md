# SEO Agent AI — Claude Code Instructions

## Repository
- **GitHub:** `ethicaladitya/wp-seo-agent`
- **Dev branch:** `claude/setup-seo-agent-plugin-ncASb`
- **Production branch:** `main`

## Server
- **Host:** `aditya@iadityame.tempurl.host`
- **Plugin path on server:** `/path/to/wp-content/plugins/seo-agent-ai` *(update this once confirmed)*
- **WP-CLI available:** yes

---

## MANDATORY: After every code change, always run steps 1–3 in order

### Step 1 — PHPCS Standards Check
Run WordPress Coding Standards review before committing. Fix any errors reported (warnings are acceptable but errors must be resolved):

```bash
cd /home/user/WP-SEO-Agent
./vendor/bin/phpcs --standard=phpcs.xml --severity=5 <changed-files>
```

For a full scan:
```bash
./vendor/bin/phpcs --standard=phpcs.xml
```

Auto-fix what PHPCS can fix automatically:
```bash
./vendor/bin/phpcbf --standard=phpcs.xml <changed-files>
```

**Do not commit code that has PHPCS errors (severity ≥ 5).**

### Step 2 — PHP Syntax Check
```bash
php -l <every-modified-php-file>
```

All files must return "No syntax errors detected."

### Step 3 — Commit & Push to GitHub
```bash
cd /home/user/WP-SEO-Agent
git add <changed-files>
git commit -m "clear descriptive message"
git push origin main
```

Use the merge-to-main workflow for large feature branches:
```bash
git merge --no-ff <feature-branch> -m "Merge: <description>"
git push origin main
```

### Step 4 — Sync to Production Server
After pushing to GitHub, sync to the live server via SSH:

```bash
ssh aditya@iadityame.tempurl.host "
  cd /path/to/wp-content/plugins/seo-agent-ai &&
  git pull origin main &&
  wp plugin deactivate seo-agent-ai --allow-root &&
  wp plugin activate seo-agent-ai --allow-root &&
  echo 'Deploy complete'
"
```

The deactivate→activate cycle runs `create_tables()` / `maybe_upgrade()` to apply any DB schema changes.

**If SSH is unreachable from this environment** (IPv6-only server), output the exact commands for the user to run manually.

---

## WordPress Plugin Development Standards

When writing or modifying any WordPress plugin code in this repository, **automatically apply all of the following** without being asked:

### Code Standards
- Follow **WordPress Coding Standards** (WPCS) — tabs not spaces, Allman-style braces, snake_case functions/variables, PascalCase classes
- All PHP must be compatible with **PHP 7.4+** and **WordPress 6.0+**
- Wrap all user-facing strings in `__( 'string', 'seo-agent-ai' )` or `_e()`
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, `absint()`
- Sanitize all input: `sanitize_text_field()`, `sanitize_textarea_field()`, `esc_url_raw()`, `absint()`

### Security
- Nonce on every form/AJAX: `wp_create_nonce()` / `check_ajax_referer()` / `check_admin_referer()`
- Capability check before every privileged action: `current_user_can('manage_options')` or `current_user_can('edit_post', $id)`
- Never interpolate user input into SQL — always use `$wpdb->prepare()`
- API keys stored encrypted via `SEO_Agent_AI_Crypto::encrypt()` / `::decrypt()`

### Database
- Use `dbDelta()` for schema creation/upgrades (idempotent)
- Add `// phpcs:ignore WordPress.DB.DirectDatabaseQuery` for approved direct DB calls
- Prefix all custom tables with `$wpdb->prefix`
- Always index foreign-key columns

### Performance
- Cache expensive queries with `get_transient()` / `set_transient()`
- Use `wp_next_scheduled()` guard + transient in `ensure_cron_schedules()` to avoid N DB queries per page load
- Load admin-only code conditionally: `if ( is_admin() ) { ... }`

### No-go rules (plugin must NEVER do these)
- Automatically publish fully AI-generated articles
- Keyword-stuff content
- Make destructive content changes without a backup in `_seo_agent_ai_backups` post meta
- Overwrite human-written content aggressively
- Violate Google Search guidelines

---

## Architecture Quick Reference

| Class | Responsibility |
|---|---|
| `SEO_Agent_AI_Plugin` | Orchestrator — registers all hooks, cron, admin actions |
| `SEO_Agent_AI_DB_Manager` | Custom table schema + all DB CRUD |
| `SEO_Agent_AI_Decision_Engine` | 3-tier routing: auto_apply / pending_approval / discarded |
| `SEO_Agent_AI_Fix_Executor` | Applies approved changes with backup |
| `SEO_Agent_AI_SEO_Scoring_Engine` | Scores posts 0-100 across 7 dimensions |
| `SEO_Agent_AI_GSC_Client` | Google Search Console API (with 15-min transient cache) |
| `SEO_Agent_AI_GA4_Client` | Google Analytics 4 Data API (with 15-min transient cache) |
| `SEO_Agent_AI_Image_SEO` | Alt text generation + image scoring |
| `SEO_Agent_AI_Social_Meta` | Open Graph + Twitter Card head tag output |
| `SEO_Agent_AI_Meta_Box` | Per-post edit-screen SEO panel |
| `SEO_Agent_AI_Taxonomy_SEO` | Category/tag/homepage SEO metadata |
| `SEO_Agent_AI_Redirect_Manager` | 404 logging + redirect CRUD |
| `SEO_Agent_AI_Feature_Flags` | `is_enabled($flag)` — togglable features via wp_options |
| `SEO_Agent_AI_Logger` | Structured logging |

### Key options
- `seo_agent_ai_score_target` — minimum score target for improvement cron (default 70)
- `seo_agent_ai_autopilot_enabled` — auto-apply safe fixes (default false)
- `seo_agent_ai_post_types` — which post types to analyse
- `seo_agent_ai_ai_provider` — `gemini` | `openai` | `auto`

### Cron hooks (all on WP-Cron)
| Hook | Schedule | Purpose |
|---|---|---|
| `seo_agent_ai_daily_analysis` | daily | Main analysis pipeline |
| `seo_agent_fetch_gsc_data` | daily | Pull GSC keyword history |
| `seo_agent_fetch_ga4_data` | daily | Pull GA4 engagement data |
| `seo_agent_generate_report` | daily | Build daily SEO report |
| `seo_agent_score_pages` | weekly | Score all posts 0-100 |
| `seo_agent_detect_decay` | weekly | Flag stale content |
| `seo_agent_detect_cannibalization` | weekly | Flag keyword overlap |
| `seo_agent_score_and_improve` | weekly | Fix posts below score target |
| `seo_agent_detect_orphans` | weekly | Flag pages with no inbound links |
| `seo_agent_run_internal_links` | weekly | Add internal link suggestions |
| `seo_agent_purge_old_data` | weekly | Clean up old DB rows |

---

## PHPCS / Codex Review Notes

- PHPCS config is in `phpcs.xml` at the project root
- Standards binary: `./vendor/bin/phpcs`
- Auto-fixer: `./vendor/bin/phpcbf`
- The WordPress ruleset used is `WordPress-Extra` (superset of Core + Docs)
- Inline suppressions allowed only for pre-approved patterns:
  - `// phpcs:ignore WordPress.DB.DirectDatabaseQuery` — custom table queries
  - `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` — table name interpolation
  - `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` — only when query is already fully prepared

Never suppress `WordPress.Security.*` rules.
