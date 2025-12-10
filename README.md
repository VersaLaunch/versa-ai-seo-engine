# Versa AI SEO Engine

AI-assisted planning, writing, schema, and on-page remediation for WordPress. Automates topic planning, drafting, site audits, and schema generation while keeping an approval gate when you want it.

## Highlights
- **Plan & Write**: Weekly topic ideas and daily drafts (title, meta, optional FAQ) targeted to your business profile.
- **Optimize & Audit**: Lightweight crawler flags thin content, missing meta, missing H1, canonical mismatches, HTTP errors, noindex, and now robots.txt presence/blocks/sitemap hints.
- **Schema-aware**: Generates WebSite, Organization, LocalBusiness, Product, Service, and Event JSON-LD; avoids duplicate LocalBusiness/Organization/WebSite tasks when schema already exists.
- **Business data**: Profile stores address, geo, phone, category, sameAs URLs, opening hours, price range, payment methods, currencies, contact point—fed into LocalBusiness schema prompts.
- **Approval-first**: Optional human-in-the-loop; tasks queue as “Awaiting Approval” and only run/apply after you approve.
- **Task summaries**: Every generated task carries a summary, recommended action, and priority for quick triage.

## Requirements
- WordPress 6.x
- PHP 7.4+ (8.x recommended)
- OpenAI API key with the configured model (default: `gpt-4.1-mini`)

## Installation
1) Upload the plugin directory or zip to `/wp-content/plugins/`.
2) Activate **Versa AI SEO Engine** in WP Admin → Plugins.
3) On activation, custom tables are created and cron events are scheduled.

## Configuration (WP Admin → Versa AI)
- **Business Profile**: Name, services, locations, audience, tone, posts/week, max words, auto-publish, address (street/city/state/postcode/country), geo (lat/lng), phone, business category, sameAs URLs, opening hours, price range, payment methods, currencies, contact type/phone, default product currency/availability.
- **AI Settings**: OpenAI API key and model.
- **Cadence & Approval**: Toggle “Require Approval for AI Edits”, set crawl limits/cooldowns.

## Automation cadence
- Weekly: Planner enqueues topics (`versa_ai_weekly_planner`).
- Daily: Writer drafts the next topic (`versa_ai_daily_writer`).
- Daily: SEO scan audits posts/pages (`versa_ai_daily_seo_scan`).
- Every ~10 minutes: Worker processes pending tasks (`versa_ai_seo_worker`).

## Task types
- `expand_content`: Enrich thin posts.
- `write_snippet`: Add meta title/description.
- `internal_linking`: Suggest internal links to service URLs.
- `faq_schema`: Generate FAQPage JSON-LD from existing FAQ sections.
- `website_schema`, `org_schema`, `localbusiness_schema`, `product_schema`, `service_schema`, `event_schema`.
- `site_audit`: Site-wide issues (HTTP errors, noindex, canonical mismatch, missing title/description/H1, thin content, robots.txt missing/blocked/no-sitemap).

## Approval flow
- With approval ON, new tasks start as `awaiting_approval`.
- Approve → moves to `pending`; worker calls OpenAI and applies changes (for post tasks) or marks audit items as reviewed.
- Decline → marks `failed`; nothing applies.
- Site-audit tasks surface with summaries/recommended actions; approving just clears them through the queue.

## Admin UI
- **Settings**: Configure business profile, schema inputs (address/geo/contact/commerce), AI model/key, cadence, approval.
- **Tasks**: Tabbed view (Awaiting Approval / Awaiting Apply / Recent) with summaries, priority/status chips, and spawned task references.

## Data stored
- Tables: `wp_versa_ai_content_queue`, `wp_versa_ai_seo_tasks`, `wp_versa_ai_logs`.
- Post meta: Rank Math and Yoast fields, `versa_ai_faq_schema`, `_versa_ai_seo_snapshot`, `_versa_ai_content_backup`, schema meta for applied JSON-LD.
- Options: `versa_ai_business_profile`, `versa_ai_service_urls`.

## CLI shortcuts (WP-CLI)
```bash
wp cron event run versa_ai_daily_seo_scan
wp cron event run versa_ai_seo_worker
wp cron event run versa_ai_weekly_planner
wp cron event run versa_ai_daily_writer
```

## Troubleshooting
- Logs: Check PHP error log for `[Versa AI SEO Engine][context]` entries.
- Nothing happening? Ensure API key/model are set, posts/pages exist, and cron can run; trigger via WP-CLI if needed.
- Approval enabled? Approve tasks in **Versa AI → Tasks** or run the worker manually.
- Robots.txt warnings? Create `/robots.txt`, avoid `Disallow: /` for `User-agent: *`, and add a `Sitemap:` line.