=== Search Atlas SEO - OTTO AI SEO Automation for WordPress ===
Contributors: shahrukhlinkgraph
Tags: seo, ai seo, otto, otto seo, schema
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.7.1
License: GPL v3
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

AI-powered SEO & GEO for WordPress: OTTO auto-optimizes meta, schema & content, and helps you rank on Google and in AI search.

== Description ==

**AI-powered SEO for WordPress — built for Google rankings and the new era of AI search.**

Search Atlas connects your WordPress site to the Search Atlas platform and its OTTO AI optimization engine, automating the technical SEO work that used to take hours. Optimize for traditional search engines and for AI answer engines (GEO — Generative Engine Optimization), publish content in one click, and manage your entire on-page SEO from a single dashboard.

### OTTO — AI SEO on autopilot
OTTO server-side optimizes your pages for search crawlers and AI engines: meta titles and descriptions, image alt text, headings, and internal links are applied automatically, without changing your theme. Roll fixes out across up to 100 pages at once, exclude any URL, and optionally save optimizations into native WordPress fields so they persist even if you switch tools.

### Get found in AI search (GEO)
A built-in /llms.txt endpoint serves clean, AI-readable content to AI crawlers such as ChatGPT and Perplexity — so your site can surface in AI answers and AI Overviews, not just classic search results.

### One-click publishing from Search Atlas
Push blog posts, landing pages, and content updates straight from the Search Atlas dashboard to WordPress — complete with optimized titles, meta descriptions, image alt text, formatted headings, and internal links.

### Bulk AI optimization
- AI bulk meta updates — rewrite titles and descriptions across your whole site in one pass
- AI alt-text generation & auditing — find missing or weak alt text and fix it library-wide
- Bulk status and content operations to manage many posts at once

### A complete on-page SEO toolkit
- 13 schema / structured-data types (Article, FAQ, Product, Recipe, Event, LocalBusiness, HowTo, VideoObject, and more) with JSON-LD output
- XML sitemaps, including dedicated news and video sitemaps
- Virtual robots.txt editor — manage rules with no file editing
- Redirection manager — 5 match types (exact, wildcard, regex, contains, start/end) and 4 status codes (301/302/307/308), with automatic redirects on slug changes
- 404 monitoring with hit tracking and smart redirect suggestions
- Breadcrumb schema, canonical URLs, and hreflang (multi-language) support
- Open Graph and Twitter Card meta with automatic de-duplication

### Instant indexing
Submit new and updated URLs directly to Google (Instant Indexing API) and Bing (IndexNow) for faster crawling — one at a time or in bulk.

### MCP server — control WordPress with AI agents
A built-in Model Context Protocol (MCP) server exposes 138 tools across 31 categories, letting AI assistants create and edit content, manage SEO and schema, submit URLs for indexing, handle redirects, and audit your site — secured with API-key or token authentication.

### Media & performance
- Convert images to WebP/AVIF, smart lazy-loading, and automatic width/height attributes to reduce layout shift (CLS)
- Automatic cache purging for popular caching plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, and more)
- Edge/CDN cache purging for Cloudflare, Fastly, Akamai, and Sucuri

### Works with your stack
- Page builders: Gutenberg, Elementor, Divi, Oxygen, and Beaver Builder
- WooCommerce breadcrumbs and shop pages
- Coexistence and migration with Yoast SEO, Rank Math, and All in One SEO
- Google Search Console and GA4 integration
- Agency-ready white-label settings to show or hide features per client

### Search Atlas account & external service
Search Atlas is a companion plugin for the Search Atlas platform, an external service. A Search Atlas account is required to use the AI optimization, OTTO, and content-publishing features. When connected, your site sends and receives data from Search Atlas servers to provide these features. Learn more in the Search Atlas Terms of Service (https://searchatlas.com/terms/) and Privacy Policy (https://searchatlas.com/privacy-policy/).

== Installation ==
1. In your WordPress admin, go to Plugins, then Add New, and search for Search Atlas SEO. Click Install Now, then Activate.
2. You need a Search Atlas account. If you do not have one, start a free trial at searchatlas.com.
3. In Search Atlas, add your site as a project. Then, in WordPress, open the Search Atlas menu and click Connect to Search Atlas. The plugin will automatically complete the secure SSO connection.
4. Switch OTTO on.

Installing by hand: download the ZIP, upload the `metasync` folder to `/wp-content/plugins/`, then activate it from the Plugins menu. After that, continue from step 2.

== Frequently Asked Questions ==

= What does the Search Atlas SEO plugin do? =
It connects your WordPress site to Search Atlas so OTTO, our AI optimization engine, can work on your pages. It applies titles, descriptions, alt text, headings, internal links and schema, and it lets you publish content straight from Search Atlas into WordPress in one click. You need a Search Atlas account to use it.

= Do I need a paid Search Atlas account to use this plugin? =
Yes. The plugin connects to your Search Atlas account, and OTTO's AI-driven SEO features are available on paid Search Atlas plans.

= Where do I get my API key? =
You don't need to enter one manually. Use the "Connect to Search Atlas" option in the plugin settings, and it will generate your API key and connect your account automatically.

= Does it work alongside Yoast SEO or Rank Math? =
Yes. The plugin detects Yoast SEO, Rank Math, and All in One SEO, avoids duplicate meta tags and schema conflicts, and includes an "Import from Other SEO Plugins" feature that imports meta titles, descriptions, canonical tags, schema, and redirects from those plugins directly into Search Atlas SEO.

= How do I turn OTTO off for one page? =
You can exclude individual URLs from OTTO processing in the plugin's OTTO settings, so those pages render as they normally would without OTTO's changes.

= Can I manage 404 errors and redirects with this plugin? =
Yes, along with a lot more - the plugin also includes media optimization, code snippets, code minification, cache busting, and much more.

== Screenshots ==
1. General Settings

== Upgrade Notice ==
= 2.6.22 =
2.6.22 fixes OTTO breaking Divi-built pages and stops a partly finished update from taking your site's front end down. Update as soon as you can.

== Changelog ==
= 2.7.1 =
* Fix: The Changes Log admin page no longer shows misaligned headings or inconsistent toolbar control sizing
* Fix: A "%" character in a saved settings value no longer fatals the page, and the plugin now survives partially-completed upgrades
* Fix: Direct-access protection was added to PHP files that were missing it
* Fix: Discouraged PHP functions were replaced with WordPress-native APIs across the codebase, confirmed dead code was removed, and the remaining intentional native calls are now documented
* Fix: Audited and resolved SQL query safety findings across the plugin
* Fix: Audited and resolved input sanitization and output escaping gaps across admin and public-facing code, including AJAX and settings save paths
* Fix: Added missing nonce and capability checks across admin endpoints, AJAX handlers, and settings page actions
* Fix: Redirects are now routed through safe WordPress redirect functions, data-directory writes are centralized, and legacy redirect rows with un-sanitized source URLs are healed automatically
* Fix: Removed stray production debug logging and correctly scoped error handlers
* Fix: The plugin FAQ now uses American spelling ("optimization"), matching the rest of the listing
* Fix: Audited direct database queries, caching, and schema changes for WordPress.org compliance
* Fix: Resolved internationalization findings so plugin text is properly translatable
* Fix: Resolved the remaining zero-risk and newly-reported findings from ongoing WordPress.org Plugin Check scans
* Improvement: Compatibility-checker plugin icons are now bundled locally instead of loaded from an external host

= 2.7.0 =
* New Feature: Headless WordPress support — the WordPress backend and a decoupled public frontend now stay in sync through SSO and heartbeat, with SEO data exposed over WPGraphQL, frontend-aware canonical and og:url rehosting, taxonomy canonical overrides, bulk payload resolution, and a dedicated Headless Mode section in Advanced settings
* New Feature: Safe OTTO Persistence restore — original Yoast, Rank Math and AIOSEO values are backed up before OTTO writes to them, nothing is written without explicit consent, and a restore action rolls every value back to its original state
* New Feature: Language Alternates (hreflang) can now be switched on or off per post and page from the editor settings
* New Feature: A global setting now controls whether OTTO or custom SEO values take priority across the site
* Fix: Core infrastructure sweep — resolved deactivation data loss, wp-config fatals and media metadata corruption across the plugin
* Fix: SEO Health now credits titles and descriptions that OTTO has already applied instead of reporting them as missing
* Fix: Classic Editor post saves no longer discard persisted OTTO schema
* Fix: OTTO titles and descriptions containing curly quotes or dashes now save correctly instead of failing silently
* Fix: OTTO renders now keep third-party og:title and twitter:title instead of stripping them
* Fix: Sitemaps no longer include URLs that redirect elsewhere, whether the redirect comes from MetaSync, Rank Math or Yoast
* Fix: The Visual HTML Editor now refuses saves that cannot preserve the stored document, protecting landing pages and full-document templates
* Fix: Redirect destination and loop guards now apply on every write path, closing the gaps that allowed self-referencing and looping redirects
* Fix: Language Alternates no longer emit duplicate tags on WPML sites, always include a self-reference, and validate their entries
* Fix: The Instant Indexing service account file can now be saved, with clear upload confirmation and the ability to remove or reselect it
* Fix: Plugin updates no longer fatal on a stale admin class during a White Label import
* Improvement: SEO Health missing title and description filters are now optimized for large catalogues
* Improvement: SEO Health exclusion queries, counting and CSV export are optimized for sites with heavy post metadata
* Improvement: Frontend assets are now loaded only where they are needed, and duplicate WebPage schema nodes are removed
* Improvement: The third-party SEO plugin sync screen has been redesigned and now respects white-label branding
* Improvement: The duplicate Instant Indexing admin page has been removed and its functionality consolidated into Indexation Control
* Improvement: The duplicate Primary Category control has been removed from the MetaSync editor sidebar
* Improvement: The redirection list no longer repeats the match type in the From column
* Improvement: The Changes Log now distinguishes OTTO cleanup events from new SEO deployments, so log entries reflect what actually happened

= 2.6.26 =
* Fix: Yoast schema conflict handling no longer emits invalid JSON-LD on OTTO-covered pages
* Fix: Third-party SEO sync now reports success only when SEO data was actually written, preventing stale titles from being treated as current
* Fix: The Gutenberg SEO sidebar no longer uses OTTO suggestions as field values, so pages are not silently frozen out of OTTO
* Improvement: Breadcrumb settings are now managed from one dedicated settings page instead of duplicate controls
* Fix: News and video sitemaps now use consistent source ordering and regenerate news entries within the required freshness window
* Improvement: The Visual HTML Editor now bundles its own runtime, so it loads correctly for raw-HTML pages without relying on a CDN
* Fix: Redirection and 404 list filters no longer trigger a selected bulk action accidentally
* Fix: Open Graph opt-out now suppresses all matching MetaSync social output paths
* Fix: The Gutenberg SEO sidebar no longer shows a duplicate URL Slug field
* Fix: Social titles and descriptions no longer persist pre-filled post defaults, so renamed posts and edited excerpts update their social metadata correctly
* Security: White Label JSON imports no longer lock out the settings password recovery flow, so Forgot Password keeps working when a new whitelabel-settings.json is deployed

Older release notes are available in the CHANGELOG.md file included with the plugin.
