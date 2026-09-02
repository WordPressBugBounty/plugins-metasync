=== Search Atlas SEO - OTTO AI SEO Automation for WordPress ===
Contributors: shahrukhlinkgraph
Tags: seo, ai seo, otto, otto seo, schema
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.6.24
License: GPL-2.0+
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
It connects your WordPress site to Search Atlas so OTTO, our AI optimisation engine, can work on your pages. It applies titles, descriptions, alt text, headings, internal links and schema, and it lets you publish content straight from Search Atlas into WordPress in one click. You need a Search Atlas account to use it.

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
= 2.6.24 =
* Improvement: OTTO crawl notifications are now held in a durable queue with bounded retries and backoff, so large webhook batches are no longer silently truncated and each URL's outcome is recorded
* Improvement: The sitemap settings screen no longer offers page-builder and internal post types in its post-type pickers
* Improvement: The sitemap taxonomy picker now lists only genuine content taxonomies, hiding navigation menus, link categories and similar internal ones
* Improvement: The promotional sidebar is now hidden once the site is connected, giving admin pages their full width back, and Quick Links point to working pages — the dead SEO Academy link is gone, Documentation and Contact Support were added, and branded links are hidden on whitelabeled installs
* Improvement: The Local Business Type selector now shows a neutral "Select Type" placeholder instead of appearing pre-selected when no type has been chosen
* Improvement: The Compatibility page now detects Bricks Builder when it is installed as a theme rather than a plugin
* Security: MCP JWT tokens are now bound to the API key that issued them, so rotating the key or disconnecting the site revokes existing tokens immediately instead of leaving them valid for 24 hours
* Security: Failed MCP API-key authentication attempts are now counted, throttled and locked out instead of being accepted at unlimited speed
* Security: The schema markup editor's background requests now require edit permission on the target post, so lower-privileged users can no longer read other authors' drafts, private posts or password-protected content
* Security: Removed the shared whitelabel/debug credential, required administrator rights for OTTO debug tools, gated Google Index credential and destructive debug actions, disabled raw database and option reads over MCP, and reduced telemetry to path-only context that honors opt-out
* Security: The HTML editor and whitelabel recovery now require the matching capability, Developer Tools passwords are hashed and migrated off plaintext, and both MCP bridges run through one authenticated, rate-limited handler
* Security: Redirect destinations are now validated before use, closing an open-redirect risk in the redirections module
* Fix: A /sitemapN.xml URL with no sitemap behind it now returns 404 instead of serving the home page under a 200, which was creating unlimited soft-404 duplicate URLs for crawlers
* Fix: Sitemap generation now reports a real failure when the sitemap could not be stored, instead of showing success while the site serves no sitemap
* Fix: Opening an OTTO preview no longer signs administrators out of wp-admin or breaks the admin session check
* Fix: MetaSync no longer emits a second Article JSON-LD block on posts that already have one from Yoast, Rank Math or AIOSEO
* Fix: Slashes, backslashes and quotes are preserved in Local Business and Article schema values, so a name like 24/7 "Test" Plumbing is no longer output as 247 Test Plumbing
* Fix: Search engine crawlers whose user agent omits the brand name (Yahoo's Slurp, Applebot, Sogou, Exabot and others) are now recognized as search engines and served optimized pages instead of being throttled
* Fix: OTTO's category and tag SEO values are no longer mirrored into Yoast / Rank Math / AIOSEO storage when the matching OTTO Persistence setting is turned off
* Fix: OTTO's structured data is no longer copied into Rank Math or Yoast storage when the structured data Persistence setting is turned off
* Fix: Rank Math's robots tag is now suppressed when MetaSync holds a robots value for the post, so exactly one robots meta tag ships
* Fix: Removing MetaSync's breadcrumb from a third-party schema graph no longer breaks Rank Math's own schema logic
* Fix: Posts synced from Content Genius keep the page template the site actually uses, instead of being forced onto the theme's plain template and losing their layout
* Fix: Redirects now preserve the incoming query string, including through redirect chains, and OTTO no longer serves mismatched HTML and SEO metadata when its internal fetch is redirected elsewhere
* Fix: The WordPress admin sidebar no longer drifts down the screen while scrolling on MetaSync admin pages
* Fix: The connect status box no longer squeezes the "Website Not Registered" message into a one-word-per-line column with a detached warning icon
* Fix: The "Page Builder Detected" notice now appears only on the plugin settings screen where the setting lives, instead of repeating on every plugin page
* Fix: Previewing a draft no longer serves the home page instead of the draft on sites using SiteGround Optimizer — OTTO now skips WordPress core preview requests
* Fix: The Local Business logo is no longer lost when the settings are saved — the selected image survives the save instead of being rewritten into a broken link, so both the admin preview and the logo published in structured data stay correct
* Fix: The SearchAtlas Dashboard admin page no longer fatals after an in-place upgrade when one plugin file is still being served from a stale cache
* Fix: Redirections sweep — 24 verified issues fixed, including importers failing on serialized data, the search box triggering a bulk delete, a hit-counter race, non-deterministic rule ordering, duplicate rules on re-import, missing redirect-loop guards and silently dropped save failures
* Fix: Media optimization sweep — 18 verified issues fixed, including data loss in the replace strategy (sub-size originals deleted while only the full-size URL was rewritten), an author-role privilege escalation, srcset entries pointing at missing files, and a race between cron and browser batch ticks
* Fix: Robots / sitemap / indexing sweep — 25 verified issues fixed, including core's "discourage search engines" setting being overwritten, the virtual robots.txt never being served on the frontend, IndexNow rejecting platform keys, existing Sitemap: lines being destroyed on update, and uninstall leaving everything behind

= 2.6.23 =
* Improvement: The plugin now automatically detects when a host is blocking GET/POST traffic to Search Atlas, both on activation and on a weekly recheck, instead of requiring the site owner to run the check manually
* Improvement: Pages that don't rely on sessions can now be cached by the host again on SiteGround/Oxygen sites using OTTO's HTTP render path, restoring page-load speed
* Improvement: The Custom Pages STATUS column now shows "Published" / "Draft" / "Pending Review" instead of the raw "Publish" verb, and no longer looks like a clickable button
* Improvement: The Excluded URLs table's description text is now readable in dark mode, and the Recheck button no longer deletes the entry it was rechecking
* Improvement: OTTO's stale suggestion fallback is now retained for a fixed 12 hours
* Improvement: Removed the non-functional "Grant Temporary Support Access" option from the Report Issue page, since it never actually granted any access
* Improvement: Lowered the minimum required PHP version from 8.2 to 8.1 (AVIF image conversion still requires PHP 8.2+; WebP is unaffected)
* Security: Schema/JSON-LD output is now safely encoded everywhere it's emitted, closing a script-injection risk from crafted field values
* Fix: "Auto Draft" no longer gets stored as the page's social title/description when the social meta box is opened before a post has a real title
* Fix: On non-English sites, a localized "Auto Draft" placeholder (e.g. "Automatischer Entwurf") no longer leaks into the page title, og:title or twitter:title — the earlier fix only recognized the English placeholder text
* Fix: OTTO's status indicators (admin bar, debug bar, SEO columns) now correctly show a manually excluded URL as disabled instead of still reporting it as enabled
* Fix: "Ends with" and "regex" OTTO URL exclusion rules are now honored on the page a visitor actually loads, not just when a URL is queued for optimization
* Fix: A redirect rule no longer causes OTTO's internal page fetch to re-enter itself repeatedly, which could exhaust the site's PHP workers and take it offline
* Fix: Restored host-level page caching on sites combining a booking plugin with SiteGround Optimizer, which had been forced into an always-uncached state
* Fix: The page's <head> tag is no longer rewritten in a way that breaks other plugins or host layers that inject scripts or styles into the head
* Fix: Google Fonts using multiple font families no longer get truncated to a single font on OTTO-rendered pages
* Fix: Blog posts synced from Content Genius no longer lose their tables, and post markup is no longer mangled on PHP 8.4
* Fix: Deleting the last excluded URL on a page beyond the first no longer dead-ends the list on a false "No excluded URLs found" state
* Fix: Hidden admin pages (Local Business, Breadcrumbs, Site Verification, Setup Wizard) now show a proper browser tab title instead of a blank one
* Fix: OTTO's internal values are no longer mirrored into Yoast / Rank Math / AIOSEO storage when the matching OTTO Persistence setting is turned off

= 2.6.22 =
* Improvement: The WordPress admin sidebar now shows a focused 8-item menu by default, with the remaining pages reachable from the plugin's in-page sidebar and a new search box for finding them
* Improvement: The Redirections, 404 Monitor, Heartbeat Error Monitor and Media Optimization lists now have a results-per-page selector that remembers each user's choice
* Improvement: The classic editor's separate MetaSync meta boxes (SEO, Robots, Canonical, Redirection, Social and Open Graph, Schema, Video Sitemap) are consolidated into a single tabbed "SEO Suite" box with a live social preview
* Improvement: OTTO diagnostic response headers are now sent only while Debug Mode is on, so they no longer appear on public responses or leak the product name on white-labeled installs
* Security: Refreshing the Plugin Auth Token now requires administrator permissions
* Fix: Pages built with Divi are no longer broken by OTTO — an HTML parsing flaw discarded every attribute following an unquoted attribute value, which stacked carousel slides, threw the header and footer out of position and left the page growing without settling
* Fix: Articles synced from Content Genius no longer repeat the contents of a bulleted list as plain paragraphs directly beneath it
* Fix: SEO titles and descriptions imported from All in One SEO now have their smart tags resolved instead of being stored literally, which had left tokens such as #post_title rendering as the page title and suppressing OTTO
* Fix: A page no longer emits two conflicting og:description tags when a third-party SEO plugin owns the output for that post
* Fix: og:title and twitter:title are no longer left off the page when a third-party SEO plugin's tags are suppressed — a replacement is now emitted from the MetaSync title instead of the raw post title
* Fix: When OTTO cannot apply its suggestions, the un-optimized page is now cached for at most 60 seconds instead of the host's full cache lifetime, so a brief hiccup no longer pins un-optimized markup for hours and looks like the OTTO title reverting
* Fix: A slow or unreachable OTTO connection no longer ties up the site's PHP workers — repeated calls to a failing endpoint now pause briefly before being retried, and requests that never needed optimization (404s, static files, robots.txt and well-known paths) skip the lookup entirely
* Fix: A partially updated install no longer takes the front end down with a fatal error when another plugin writes post meta
* Fix: Stale or malformed scheduled OTTO background jobs are now skipped instead of causing fatal errors
* Fix: Exporting white-label settings no longer downloads the file twice
* Fix: The Local Business logo remove icon is now a small badge offset from the image instead of an oversized button overlapping the preview

= 2.6.21 =
* Improvement: The Sitemap Settings screen now warns when a generated News or Video sitemap contains 0 URLs, so it is clear whether that is expected or a misconfiguration
* Improvement: Added optional SEO Title, Meta Description, Noindex and Nofollow columns to the Posts and Pages admin list (enable via Screen Options)
* Fix: OTTO no longer emits a redundant Vary: Accept-Encoding header, which could cause CDN edge caches to treat pages as non-cacheable and route every visitor to origin PHP
* Fix: Importing Yoast advanced robots meta no longer throws a fatal error when the stored value is not a plain string
* Fix: Page-cache warmer and performance-auditor requests are no longer throttled by OTTO rendering, which had been preventing some sites page caches from populating and made PageSpeed scores appear artificially low
* Fix: Removed the redundant Index checkbox from Common Robots settings, fixed a case where No Index set via the meta box could be silently ignored when advanced robots data was present, and simplified noindex output to only include indexing/crawling directives
* Fix: The SEO Health dashboard now reads the same SEO title and description a page actually uses, instead of showing OTTO value when the customer own sidebar value was what really rendered
* Fix: The plugin REST API no longer returns 401 on sites with a strict logged-in-users-only REST restriction from a security plugin or snippet, which had been silently breaking publishing and syncing on those sites

= 2.6.20 =
* Improvement: The OTTO Debug comparison panel now shows the Meta Description when OTTO delivers it as raw header HTML instead of a structured replacement
* Improvement: Added a tooltip to the Content Genius Synchronization card explaining that "Sync Now" sends the site's categories and authors so published articles are assigned correctly
* Security: Added the missing permission and nonce checks to the Heartbeat Error Monitor's bulk actions
* Fix: The trash icon on the "Remove" button in the Schema Markup meta box is aligned correctly again
* Fix: Sitemaps are no longer corrupted by stray PHP notices or warnings emitted by other plugins, which previously produced invalid XML that search engines rejected
* Fix: The "Default Page Builder" dropdown no longer renders its option labels invisible unless they are hovered
* Fix: Corrected the malformed robots meta tag produced when SEO data is synced into Rank Math
* Fix: Open Graph and Twitter tags are no longer stripped when the per-post "Enable Open Graph and Social Media Tags" toggle is off, so Yoast SEO and All in One SEO can supply their own again
* Fix: The delete icon in the Robots.txt Backup History panel now uses a consistent color across WordPress versions
* Fix: The Compatibility page no longer reports the site's active theme as "Not installed" when the theme's display name differs from its folder name
* Fix: The Local Business logo "Add or Upload File" button opens the media library again
* Fix: Bulk actions chosen from the control at the bottom of the Redirections, 404 Monitor and Heartbeat Error Monitor lists now apply instead of doing nothing
* Fix: Saved Local Business details are now output as LocalBusiness structured data on the front end
* Fix: The per-page "Disable OTTO" toggle now also suppresses previously stored OTTO titles and descriptions instead of continuing to serve them
* Fix: Values entered in the "Advanced Robots Meta" meta box are kept after saving a post instead of resetting on refresh
* Fix: The "no results" placeholder no longer appears under short checkbox lists in Sitemap Settings when nothing has been searched
* Fix: A custom SEO title or meta description set in the SEO sidebar now always takes precedence on the live page, instead of being overridden by OTTO when OTTO is connected

= 2.6.19 =
* Improvement: Removed the unused legacy "Optimal Settings" page
* Improvement: Reworked the Search Atlas connection to a pull-based flow so sites behind firewalls that block inbound callbacks can still connect reliably
* Fix: Resolved a case where content failed to sync to WordPress due to a www/non-www hostname mismatch during connection
* Fix: robots.txt no longer continues to reference sitemap URLs after the sitemaps have been deleted or disabled
* Fix: Sitemap generation, including video and news sitemaps, no longer exhausts server memory on sites with large content catalogs
* Fix: The video sitemap no longer produces invalid XML when a post description contains HTML entities or shortcodes
* Fix: OTTO no longer breaks concatenated CSS/JS URLs (for example Jetpack Boost), so optimized pages render with their styles intact
* Fix: "Delete All Sitemaps" now reliably removes all sitemaps even when one is served from a stale cache
* Fix: Restored compatibility with the Enfold/Avia builder so the Accordion Toggle Title field accepts letters, not just numbers
* Fix: Deleted robots.txt backup history entries no longer reappear after refreshing the page

= 2.6.18 =
* Improvement: Increased the White Label plugin-name limit so custom plugin names of up to 18 characters are no longer truncated
* Improvement: Added explanatory tooltips across the admin settings so the purpose of each option is clear
* Improvement: Made the API key error message readable when a stored key cannot be read and re-authentication is required
* Security: The Search Atlas API key is now masked in the settings UI and encrypted at rest
* Fix: Customer-set Open Graph and Twitter/social values now take priority over OTTO on optimized pages, and a social image is always output
* Fix: Reverting a single optimized image no longer deletes its converted WebP file, and re-syncing content restores images that were previously broken
* Fix: Bing IndexNow verification no longer fails with "key not found" on hosts that serve the key as a static file
* Fix: robots.txt no longer advertises a sitemap URL when the XML sitemap is disabled or not generated

= 2.6.17 =
* Improvement: Fixed the Refresh Token button's oversized icon and misalignment on the settings page
* Improvement: The Redirections search field now triggers a search when pressing Enter, not only when clicking the Search button
* Fix: Prevented a fatal error when WordPress internally checks for a white screen after saving a theme file
* Fix: Prevented a fatal error when clearing edge cache for a single URL
* Fix: Prevented invalid `http://Array` canonical URLs from being output and cleaned up already-corrupted stored values

= 2.6.16 =
* Security: Whitelabel password is now encrypted at rest instead of stored in plain text
* Security: Prevented path traversal from unvalidated slugs in imported ZIP manifests
* Security: Validated file paths built from ZIP manifest slugs before reading them
* Performance: Capped image-processing resource usage to prevent unbounded memory consumption
* Performance: Added a maximum image dimensions setting that downscales oversized images before conversion
* Performance: Cached image dimensions to avoid repeated file reads on every page render
* Performance: Batched the content URL rewrite query to prevent database table locks
* Performance: Raised the memory limit before heavy image processing to avoid out-of-memory failures
* Performance: Reduced memory usage during HTML rendering on large pages
* Performance: Prevented memory exhaustion caused by bot and vulnerability-scanner traffic
* Performance: Prevented image conversion from exceeding PHP's maximum execution time during media optimization
* Improvement: Surfaced the Focus Keyword as a read-only field in the Gutenberg SEO sidebar
* Improvement: Excluded Website Studio / custom HTML pages from SEO audit, inventory, and analysis surfaces
* Improvement: Hid SEO meta fields on Website Studio-synced pages and show a read-only notice instead
* Improvement: Labeled Website Studio-synced pages with a post state in the admin Pages list
* Improvement: Stopped admin notices from causing layout shift and viewport scroll when navigating between plugin features
* Improvement: Fixed checkbox alignment with their labels on the Redirections screen
* Improvement: Bundled the default plugin logo locally instead of hotlinking it
* Improvement: Fixed icon alignment on the Schema Markup metabox
* Improvement: Show a sitemap conflict notice on the Video and News sitemap tabs when another SEO plugin is active
* Improvement: Fixed a color mismatch and text crowding on the Schema Markup metabox Remove button
* Improvement: Fixed the "Hide Dashboard" option so it actually hides the dashboard from the admin menu, and corrected its help text
* Improvement: Streamlined the plugin entry file for maintainability
* Fix: Fixed several bugs surfaced by static analysis
* Fix: Fixed broken thumbnails for orphaned images and cleaned up converted files when the originals are deleted
* Fix: Made multi-page imports idempotent so re-importing no longer creates duplicate pages when a slug collides
* Fix: MetaSync REST authentication now coexists with third-party JWT authentication plugins
* Fix: Heartbeat status badge now reflects a stale or dead sync instead of always showing "Connected"
* Fix: Purge page and edge cache after import to prevent stale-asset white screens on republish
* Fix: Fixed white-label leaks where product names appeared across admin, Site Health, toolbar, and import screens
* Fix: Restored the XML Sitemap and other REST responses that could return blank
* Fix: Prevented OTTO from silently serving un-optimized HTML on Divi and other page-builder pages
* Fix: Ensured canonical optimization is reliably applied
* Fix: Stopped query strings from being stripped, which caused search and query-only URLs to serve the home page for logged-in and cache-bypass users
* Fix: The post-edit Redirection metabox now applies per-post redirects on the frontend
* Fix: Stopped serving schema markup when no schema has been deployed
* Fix: The canonical metabox now emits the configured canonical URL
* Fix: Prevented the CPU load gate from dropping sync jobs on hosts where CPU-core detection fails
* Fix: The XML sitemap no longer hijacks sitemap URLs when disabled or deleted, allowing Rank Math, Yoast, and AIOSEO sitemaps to work
* Fix: Purge page and edge cache when clearing the OTTO cache, so stale optimized markup (such as a hidden injected heading) is no longer served
* Fix: Removed duplicate <meta name="description"> tags on OTTO-optimized pages when MetaSync is the only active SEO plugin
* Fix: Stopped the meta description from being dropped when a synced third-party SEO plugin (Yoast, Rank Math, AIOSEO) has an empty description field

= 2.6.15 =
* Improvement: Added a search icon to the SEO Health search field
* Improvement: Fixed the sort-arrow placement in the SEO Health table column headers
* Improvement: Prevented multi-page imports from nesting imported pages under existing unrelated (non-imported) parent pages
* Fix: Stopped the plugin from removing featured images on sites that offload media with WP Stateless
* Fix: Prevented og:description from auto-filling with raw page-builder shortcodes on Divi and Elementor pages, so social and link previews show clean text
* Fix: Served the IndexNow key file dynamically instead of writing it to disk, so key verification works on read-only web roots and on nginx hosts that block direct access to static .txt files
* Fix: Resolved the "IndexNow key not found" (400) error when configuring Bing IndexNow, so setup completes successfully
* Fix: Made the Instant Indexing service-account JSON upload accept the file by its extension, so it saves reliably across all browsers and operating systems
* Fix: Restored the white-label light logo in the admin header on light theme

= 2.6.14 =
* Improvement: Content Genius "User Roles to Sync" now lists only roles that can author content
* Improvement: Added a dropdown arrow indicator to the Image Library "Bulk Actions" and "All Images" filter fields
* Improvement: Added consistent top padding between Advanced Settings section headers and their content
* Improvement: Stopped the OTTO debug toolbar from rendering twice on Oxygen Builder pages
* Fix: Prevented OpenGraph description generation from rendering the full page in the head, which dropped Elementor loop-grid CSS and broke page layout
* Fix: Scoped the OTTO Cache-Control private header to the HTTP render path so it no longer disables host/CDN caching site-wide
* Fix: Scoped OTTO SiteGround no-cache to pages with suggestions, preventing a site-wide cache bypass and CPU spikes
* Fix: Resolved a 500 error on plugin activate/deactivate on SiteGround and stopped OTTO from interfering with the Elementor editor
* Fix: Fixed the Instant Indexing "Clear Configuration" button on the Indexation Control page

= 2.6.13 =
* Improvement: Made the Media Optimization "Optimize" and "Revert" buttons equal in size
* Improvement: Fixed stacked filter dropdowns in the Changes Log filter bar
* Improvement: Fixed the cache directory path overflowing its container on the Cache tab
* Improvement: Improved breadcrumb code-box contrast in light mode and added click-to-copy on snippet boxes
* Improvement: Added vertical spacing between the Auth Token field and Refresh Token button on the Settings page
* Improvement: Added vertical spacing between settings form rows so blocks no longer merge together
* Security: Wrapped admin nonce outputs in esc_attr() to prevent XSS in HTML attributes
* Fix: Prevented lazy-load video iframe CSS from breaking page layout and hiding homepage content
* Fix: Prevented a fatal "undefined constant" error during OTTO rendering on pre-2.6.11 installs
* Fix: Corrected OTTO double-encoding of "&" in HTML attributes that broke theme icon fonts
* Fix: Stopped OTTO from renumbering Divi column width classes and collapsing page layouts

= 2.6.12 =
* New Feature: The imported Webstudio(LPS) home page is now set as the WordPress front page
* New Feature: Added persistent audit logging for Webstudio(LPS) ZIP imports
* Improvement: Execution Settings fields now display side-by-side in a responsive two-column layout
* Improvement: Hardened the White Label menu slug and fixed settings-save validation
* Improvement: OTTO rendering is now skipped on custom and Webstudio(LPS)-imported pages
* Improvement: Deleting a custom or Webstudio(LPS) page now cleans up shared assets and resets the front page when needed
* Improvement: Webstudio(LPS) home imports now use the project UUID as the per-project deduplication key
* Security: Added nonce and capability checks to the Instant Indexing AJAX handlers (Google GIAPI and Bing IndexNow)
* Security: Added nonce and capability checks to the redirections list-table actions
* Security: Added nonce and capability checks to the 404 Monitor list-table actions
* Security: Hardened input sanitization for per-post snippets and the hero image URL
* Fix: Prevented duplicate H1 headings on Content Genius synced posts
* Fix: Resolved a fatal error during ZIP import caused by an undefined temporary-file helper in REST/MCP context
* Fix: Prevented a fatal "class not found" error on plugin update when the installed directory name differs from the package slug
* Fix: Batched SEO indexation option imports to prevent PHP memory exhaustion on large sites
* Fix: Corrected connection status to show "Not Connected" after manual API key entry and Sync Now
* Fix: Guarded get_current_screen() calls to prevent fatal errors in REST context
* Fix: Execution Settings now reports the real server upload limit instead of a hardcoded 128 MB
* Fix: Fixed a case where activating the plugin caused 404s on all pages except the home page
* Fix: Prevented a TypeError when a non-string value was passed to meta element handling
* Fix: The "Disable SEO Meta Box" setting now correctly hides the SEO meta box in the Classic editor
* Fix: The "Disallow Empty Archives" toggle is now saved correctly on SEO Controls

= 2.6.11 =
* New Feature: Added SEO Title & Meta Description fields to the Classic editor, matching the Gutenberg sidebar
* New Feature: Added a configurable OTTO cache TTL setting under Advanced Settings → Cache Management
* New Feature: Added a ZIP upload endpoint and MCP tool for importing landing pages
* New Feature: Import Open Graph and Twitter social titles and descriptions from Yoast, Rank Math, and AIOSEO
* New Feature: Import Open Graph and Twitter social images from Yoast, Rank Math, and AIOSEO
* Improvement: Added a pre-flight memory check before image conversion to avoid exhausting PHP memory
* Improvement: Batch optimizer batch size now adapts to available server memory
* Improvement: Replaced unbounded media queries with paginated queries in the batch optimizer
* Improvement: Sub-size image conversion is now split across requests to reduce peak memory usage
* Improvement: Cron batch processing now respects the server's max_execution_time limit
* Improvement: WordPress core sitemap is automatically disabled while the MetaSync sitemap is active
* Improvement: Redirect saves now warn when the destination URL does not resolve
* Improvement: The MCP sitemap check now uses the plugin's virtual sitemap tracking instead of physical files
* Improvement: 404 Monitor no longer logs WordPress system paths as broken links
* Improvement: Added a magnifier icon to the Media Optimization search field
* Fix: OTTO no longer corrupts inline JavaScript by collapsing modern operators during rendering
* Fix: Resolved fatal errors on Divi sites during OTTO HTTP rendering and from a missing admin navigation class
* Fix: Prevented fatal errors from missing dependencies — telemetry autoload, the 404 Monitor database class, the DOMDocument extension, and a removed Yoast SEO title separator method
* Fix: OTTO titles, descriptions, and schema now persist reliably — saved even when unchanged, back-filled to SEO plugin fields, kept after un-deploy, and output when OTTO is off
* Fix: Stopped duplicate Open Graph/Twitter tags and cleared social meta when duplicating a post
* Fix: Archive pages now output their own canonical URL and og:url instead of the first post's
* Fix: WebP picture tag rewriting now applies to Divi featured images
* Fix: Sitemap now includes pages and custom post types when category/tag filters are set
* Fix: Resolved a schema markup settings key inconsistency in the settings API
* Fix: Settings saves no longer show a false error notice, meta-box checkboxes persist, and a race condition that could wipe settings was resolved
* Fix: OTTO image alt text now applies when pages use relative image URLs
* Fix: Polished admin UI — Optimization Rate stats card alignment, tab icon centering, and XML Sitemap checkbox spacing

= 2.6.10 =
* New Feature: Media Optimization — compress images and serve next-gen WebP/AVIF formats to improve page load speed
* New Feature: Added post type, taxonomy, and URL exclusion controls across all sitemaps
* New Feature: Added per-file Delete buttons for individual XML sitemaps (General, News, Video)
* Improvement: Switched sitemap generation to virtual-only mode with regenerate-on-miss to resolve nginx 403 errors
* Improvement: Renamed sitemap Save buttons to "Save & Generate Sitemap" across the General, News, and Video tabs
* Improvement: Throttled bot crawl-log requests to reduce server load on high-traffic sites
* Fix: Resolved a case where the plugin caused site outages on cart/checkout and non-GET requests
* Fix: Restored meta boxes and SEO fields on custom post types
* Fix: OTTO no longer reports as installed on projects with non-matching UUIDs
* Fix: Preserved Divi's late-injected CSS during OTTO server-side rendering and fixed Divi blog/portfolio AJAX pagination
* Fix: Respected Yoast SEO titles and descriptions when OTTO is undeployed
* Fix: Scoped OTTO cache keys and rate limits per site on Multisite installs
* Fix: Skipped OTTO rendering on XML endpoints to prevent invalid output
* Fix: Sanitized malformed self-closing tags during OTTO rendering that could expand site navigation
* Fix: Prevented duplicate H1 headings when content already contains the post title
* Fix: Guarded the mime_content_type() call to prevent fatal errors on hosts without the fileinfo extension
* Fix: Deduplicated URLs and filtered junk entries from the XML sitemap
* Fix: The XML Sitemap sidebar now stays fixed instead of scrolling with the page
* Fix: 404 Monitor date filters now persist across pagination and sorting and apply only when the filter button is clicked
* Fix: Connection status no longer flips to "Not Connected" on idle plugin pages
* Fix: Corrected WP Rocket compatibility render-mode detection
* Fix: Suppressed admin notices from rendering inside the setup wizard header
* Fix: Removed dangling breadcrumb references when stripping third-party BreadcrumbList schema
* Fix: Breadcrumbs checkbox values now persist correctly when unchecked
* Fix: Fixed the Quick Links table background and borders in dark mode
* Fix: Fixed dashicon button icon misalignment on some sites
* Fix: Tightened REST API auth callbacks and fixed primary category cleanup on custom post types
* Fix: Fixed the perpetual Sync Now throttle message by separating the manual-sync cooldown from the heartbeat

= 2.6.9 =
* Security: Move API key authentication from query parameter to Authorization Bearer header
* Security: Fix X-Forwarded-For IP spoofing in OTTO bot detector — trust Cloudflare header first
* Security: Cache-Control changed from public to private to prevent CDN nonce sharing
* Fix: OTTO heading replacement corrupted when heading contains inline elements (e.g. span tags)
* Fix: SSO one-click auth fails on sites with external object cache
* Fix: Guard against missing DOMDocument extension in check_and_fix_post_content
* Fix: POST heartbeat before GET ping on manual API key save to register WPWebsiteHeartbeat
* Fix: Strict-equality bug in is_virtual_mode() causing Virtual badge not to show after first request
* Fix: Remove newline-to-br replacement that injects whitespace into synced content
* Improvement: API key input field now reverts to previous valid key on validation failure
* Improvement: Deployed OTTO titles no longer show on frontend when Disable OTTO for Logged in Users is enabled
* Improvement: Robots.txt Disallow / warning now triggers correctly with fixed regex matching
* Improvement: Robots.txt timestamps now respect WordPress timezone setting instead of hardcoded UTC

= 2.6.8 =
* Security: Use hash_equals() for timing-safe API key comparison in custom pages API
* Security: Resolve 4 Snyk Code MEDIUM findings — DOM XSS and info exposure
* Security: Fix open-redirect vulnerability in redirection plugin with domain validation
* Fix: Original image permanently deleted before converted file is validated — data loss on Imagick memory failure
* Fix: Light SA logo not visible on light theme
* Fix: Strip OTTO post meta when WordPress pages are cloned or duplicated
* Fix: Sitemap and LLMs.txt transients not invalidated when content is updated
* Improvement: Make bot statistics table responsive at narrow viewports
* Improvement: Refactor sitemap collect_all_urls() to eliminate N+1 query explosion
* Improvement: Exclude Beaver Builder templates from sitemap generation
* Improvement: Upgrade routine flag logic and unit tests for Beaver Builder template exclusion

= 2.6.7 =
- Fix: OTTO buffer caching empty/error pages
  - Fix: PHP lexicographic string comparison in execution time validation
  - Fix: Plugin causing server overload via unbounded cron accumulation
  - Fix: Replace TRUNCATE with bounded delete in 404 monitor add()
  - Fix: Add redirect-loop detection to edit and import paths
  - Fix: Non-atomic OTTO cache lock allowing concurrent workers to bypass lock
  - Fix: Remove duplicate inline JS causing double POST on excluded URL add
  - Fix: Replace TOCTOU rate limiter with atomic wp_cache_add/incr pattern
  - Fix: Fill empty catch blocks in OTTO render strategy with error_log and telemetry
  - Fix: Harden JWT verification and protect sensitive wp_options in MCP server
  - Fix: Breadcrumbs disable_schema checkbox state not persisting on save
  - Fix: OTTO title/schema override checks live suggestions when third-party SEO plugin active
  - Fix: Centralize cron-hook cleanup on plugin deactivation
  - Fix: API key validation fails when WordPress Home URL uses www subdomain
  - Fix: Add per-user transient caching to admin bar status indicator
  - Fix: Make sitemap generation memory-safe with streaming and filesystem fallbacks
  - Fix: Add static flag guard to eliminate redundant schema queries
  - Fix: Create Redirect button from 404 Monitor not opening redirection form
  - Security: Remediate 47 semgrep findings across plugin codebase

= 2.6.6 =
- Fix: Invalidate OTTO JS detection transient on settings save and cache clear
  - Fix: Harden MCP tool registration to prevent fatal error on missing class
  - Fix: Heartbeat public-hash cache-key mismatch
  - Fix: TypeError on strtolower() with array @type in OTTO crawl-notify endpoint
  - Fix: Gate admin JS loading to MetaSync pages only
  - Fix: Process OTTO webhook URLs in background via WP-Cron
  - Fix: Optimize sync history cleanup with fetch-then-delete pattern
  - Fix: Cache OTTO manual exclusion list with transient and add composite index
  - Fix: Use SELECT COUNT(*) instead of loading all records in get_count()
  - Fix: Remove blocking Facebook Graph API call from wp_head
  - Fix: Only load MetaSync admin CSS on plugin pages
  - Fix: Lazy-load MCP server and SEO-inventory controller for non-MCP requests
  - Fix: Broken SQL placeholder in get_attachment_by_name()
  - Fix: Refactor rate limiter to use per-key transients instead of wp_options blob

= 2.6.5 =
- Feat: Native-First SEO Write Layer — sync all MetaSync/OTTO optimizations to native WordPress SEO meta fields
  - Feat: Per-post advanced robots directives — nofollow, noarchive, nosnippet, noimageindex, max-snippet, max-image-preview, max-video-preview
  - Feat: Redirect health checker — MCP tool + admin panel
  - Feat: Redirect loop detection on create/save
  - Fix: Reduce memory footprint by gating heavy init on non-MetaSync AJAX requests
  - Fix: ArgumentCountError resolved by replacing printf with echo in code-snippets template
  - Fix: Unhook Elementor filters during MCP update_site_info under API key auth
  - Fix: Rename 16 non-prefixed AJAX actions to use metasync_ prefix
  - Fix: Promo sidebar overlap with main nav at narrow viewports
  - Fix: OTTO DOM corruption when page text contains less-than followed by digits
  - Fix: Restore case-sensitive SVG/HTML5 attributes after OTTO DOM processing
  - Fix: MetaSync status badge not updating on disconnect/reconnect
  - Fix: Remove duplicate canonical tags on sites without third-party SEO plugin
  - Fix: Submit Report button icon vertical alignment
  - Fix: 404 Monitor pagination layout and filter row overflow
  - Fix: Responsive layout for Redirections tablenav filters and search

= 2.6.4 =
- Fix: LLMs.txt settings now correctly persisted on save
  - Fix: Hidden diagnostic post no longer triggers Jetpack Social or RSS auto-share
  - Fix: mb_encode_numericentity() fatal on hosts without mbstring extension replaced with pure-PHP fallback
  - Fix: class-metasync-term-plugin-sync.php require_once guarded with file_exists to prevent fatal on Nexcess hosting
  - Fix: wp_tempnam() undefined fatal in Dimension Injector when called outside wp-admin context
  - Fix: WpeCommon::purge_url() undefined fatal in cache purge on WP Engine now guarded with method_exists
  - Fix: MCP_Tool_Cache_Purge_All class not found fatal guarded with class_exists check
  - Fix: Google Index require_once calls guarded with file_exists to prevent fatal when files are missing
  - Fix: PHP 8.1 add_submenu_page(null) deprecation fixed in import page and HTML visual editor

= 2.6.3 =
- Feature: BreadcrumbList schema auto-generated from post hierarchy with cross-plugin deduplication for Yoast, Rank Math, and AIOSEO
  - Feature: Hreflang language alternates support with WPML integration for multilingual sites
  - Feature: LLMs.txt generator with dedicated settings page and MCP tools
  - Feature: News sitemap and video sitemap generation
  - Feature: Taxonomy term SEO meta synced to Yoast, Rank Math, and AIOSEO for category and tag archive pages
  - Feature: Open Graph output extended with article timestamps, author, section, tags, and og:image dimensions
  - Feature: MCP tools for SEO plugin audit — read, diff, sync, and conflict detection across installed SEO plugins
  - Feature: MCP tools for OTTO pipeline — trigger optimization, check status, and verify SEO output
  - Feature: MCP cache-purge tools for per-URL and full-site cache invalidation
  - Feature: Whitelabel color personalization for admin UI
  - Improvement: Composer classmap autoloader covering all plugin classes, removing 55+ scattered require_once calls
  - Improvement: MCP rate limiter wired into all tool calls with proper 429 responses
  - Improvement: GA4 event tracking replaces Mixpanel for plugin analytics
  - Improvement: Execution time limit added to MCP database query tool to prevent long-running queries
  - Fix: OTTO title and meta replacement no longer strips dollar signs from values like $500
  - Fix: Google Instant Indexing now auto-submits on post publish
  - Fix: LLMs.txt generation now excludes noindex posts
  - Fix: Missing PHP DOM extension handled gracefully in HTML-to-builder converter
  - Fix: Yoast and AIOSEO term meta storage corrected; SEO conflict handler fixed for term archive pages
  - Fix: WP Engine cache integration no longer calls non-existent purge_url() method; Throwable errors now caught
  - Fix: Beaver Builder templates excluded from sitemap generation
  - Fix: Gutenberg blockquote block no longer shows invalid content error after OTTO applies changes
  - Fix: Redirections page filter and search controls no longer trigger add-redirection form validation
  - Fix: Settings page no longer shows Synced label and invalid API key error simultaneously
  - Fix: Unsaved changes notification close button now works correctly
  - Fix: Composer autoloader updated with missing class entries to prevent fatal errors on fresh installs

= 2.6.2 =
- Improvement: Whitelabel now supports separate logo uploads for light and dark admin themes
  - Improvement: Breadcrumbs settings panel redesigned with cleaner layout and more configuration options
  - Fix: Page title tags now update correctly when Yoast SEO is active
  - Fix: OTTO optimizations no longer disappear when Rank Math is installed alongside the plugin
  - Fix: Duplicate keywords meta tag no longer appears in page source after OTTO applies keyword recommendations
  - Fix: Theme builder selector no longer lists builders that are not installed or detected on the site
  - Fix: MCP server now returns descriptive error messages instead of generic failures
  - Fix: MCP server correctly handles post status and primary category fields
  - Fix: MCP server now supports Twitter card meta fields and bulk taxonomy term operations
  - Fix: Plugin sidebar icon and menu now match standard WordPress appearance
  - Fix: Long field labels in Settings no longer overflow their column
  - Fix: 404 Monitor bulk actions bar stays on one line instead of wrapping
  - Fix: Breadcrumbs settings section now shows its icon correctly

= 2.6.1 =
- Feature: Bulk SEO health dashboard — check pages missing meta title, description and schema
  - Feature: New schema types: LocalBusiness, HowTo, VideoObject
  - Feature: Extended schema types: Event, JobPosting, Review, Course, Organization, Person, WebSite
  - Feature: Breadcrumbs module with HTML output and BreadcrumbList schema 
  - Feature: Primary category selector in post editor sidebar 
  - Feature: Internal link suggestions in post editor 
  - Feature: Site Verification page added to SEO navigation
  - Feature: CPU load awareness and deferral settings
  - Feature: Whitelabel-aware promo sidebar and quick links settings
  - Improvement: Replace all emoji icons with WordPress Dashicons across admin UI
  - Improvement: Compatibility page redesign: accordion rows, themes, CDN, troubleshooting section
  - Fix: Duplicate schema markup and meta descriptions on multiple pages 
  - Fix: Sync handlers returning HTTP 200 with empty body on internal WP_Error — PHP 8 fatal prevented 
  - Fix: Old UI flashing before new UI loads on page transition 
  - Fix: UI misalignment on Dashboard and various admin pages 
  - Fix: Schema markup not rendering correctly 
  - Fix: NitroPack purge conflict on page-load path 
  - Fix: Image alt-text fallback on Oxygen HTTP render path 
  - Fix: Content Genius page sync full-width on Oxygen Builder 
  - Fix: Crawl monitoring showing no data despite successful crawls
  - Fix: Oxygen Builder synced post content not visible on front-end 
  - Fix: Restore Performance and CPU settings tab removed during refactor
  - Fix: Replace burst mode heartbeat with lightweight connection-ping 
  - Fix: Render OTTO-persisted otto_jsonld in frontend schema output 
  - Fix: schema_types returning empty array in wordpress_get_post_by_url
  - Fix: Correct Rate Now review URL and replace emoji star with Dashicon
  - Fix: Telemetry noise and whitelabel JSON decode bug
  - Fix: Add save button to Site Verification, Local Business, Code Snippets pages
  - Fix: Remove dead wp_ajax_metasync hook pointing to non-existent method
  - Fix: robots_txt_backups table missing on fresh activation
  - Fix: WP admin sidebar plugin icon vertical misalignment

= 2.5.27 =
- Fix: Image alt-text unreliable on Oxygen theme HTTP render path; added string-based fallback
- Fix: Content Genius page sync renders full-width on Oxygen Builder
- Fix: Apostrophe truncation in SEO extract functions causing content to be cut short
- Fix: Crawl monitoring module shows no data despite successful crawl executions
- Fix: Oxygen Builder synced post content not visible on front-end
- Fix: CPU performance tab missing from Advanced Settings after refactor
- Fix: JSON-LD schema deduplication: duplicate blocks on the same page
- Fix: Schema/JSON-LD suppression for OTTO when third-party SEO plugins (Yoast, Rank Math, AIOSEO) are active
- Fix: Remove dead `wp_ajax_metasync` hook pointing to non-existent `sync_items` method
- Fix: Add missing `display_cpu_deferral_notice` method to `Metasync_Admin`
- Fix: Oxygen Builder page template override guard in `create_page()` and `update_page()` (REST API)
- Fix: Stale-options overwrite in `test_heartbeat_api_connection()`; replace burst-mode heavy heartbeat with lightweight connection-ping endpoint
- Fix: Image alt-text string-based fallback in OTTO HTTP render path; Divi `data-et-multi-view` JSON attribute handling
- Feature: Internal link suggestions in post editor sidebar
- Feature: Replace burst-mode heavy heartbeat (`SyncCustomerParams`) with lightweight `connection-ping` endpoint — reduces payload and server load during key-pending state
- Feature: MCP sync log: log changes, rollback, 90-day auto-clear, and manual clear button
- Feature: Code minification and delivery (JS minifier)
- Feature: CPU load awareness: adaptive deferral based on server load
- Improvement: MCP `wordpress_get_post_by_url` extended to return full SEO metadata (title, description, schema, OG fields, focus keyword)
- Improvement: MCP System diagnostics and read-only database access tools (`wordpress_get_system_info`, `wordpress_get_active_plugins`, `wordpress_get_cron_jobs`, `wordpress_db_query`)
- Improvement: MCP `wordpress_get_seo_inventory` tool: bulk SEO health data across all posts in one call
- Improvement: Log - fatal-only filtering — `capture_php_error()` now passes only `E_USER_ERROR` and `E_RECOVERABLE_ERROR`; warnings, notices, and deprecations are dropped
- Improvement: Log - level gate in `send_to_sentry()` and `TelemetryManager::send_message()` — info/warning/debug events never reach the proxy
- Improvement: Log - debug window reduced from 60 min to 15 min (900s transient TTL) for faster re-reporting of recurring fatal errors
- Improvement: Logging cleanup - removed 15+ dead `log_heartbeat('info', ...)` calls (method already early-returns on `'info'`)
- Improvement: Logging cleanup - removed operational noise from `Metasync_Rate_Limiter`, `Metasync_Cache_Purge`, and `Metasync_API_Backoff_Manager`

= 2.5.26 =
* Improvement: AIOSEO compatibility 
* Fix: preg_replace backreference injection in title deduplication (titles with \$N sequences)
* Fix: Title tag deduplication and SEO plugin conflict handlers
* Fix: API rate limiting causes stale cache and robots/canonical settings ignored
* Fix: Dollar signs being stripped from image alt text 
* Fix: Heading deployment on Divi/page-builder sites
* Fix: PHP 7.4 compatibility — polyfills for str_contains/str_starts_with/str_ends_with and match expression rewrites
* Feature: NitroPack full flush + targeted object cache
* Feature: WP Database & Object Caching cleanup

= 2.5.25 =
**Bug Fixes:**
* Fix: OTTO persistence not syncing titles, descriptions, OG/Twitter fields were only written when persistence was enabled, but OTTO's own render filters read those same staging keys; added coverage for regular posts/pages; OG/Twitter persistence now also writes to RankMath and Yoast equivalents so SEO plugins pick up OTTO data; OG renderer falls back to OTTO staging keys when persistence keys are empty
* Fix: DB column missing on older installs now self-heals all three required columns
* Fix: Cap WP announce ping to max 5 per plugin activation lifecycle — removed unbounded cron retry; counter resets on fresh activation and clears on deactivation
* Fix: Dollar signs stripped from image alt text during deployment
* Fix: Heading deployment broken on some outdated Divi versions
* Fix: One case of duplicate meta name="description tags when MetaSync and AIOSEO coexist
* Fix: XML sitemap auto-update not triggering when posts published via Gutenberg
* Fix: Divi header/footer hidden on blog posts synced via Content Genius

= 2.5.24 =
**Bug Fixes:**
* Fix: Whitelabel slug not respected in admin links — \"Add Redirect\", \"404 Monitor\" tab, and \"Import from SEO Plugins\" buttons were using hardcoded `searchatlas-*` slugs instead of the configured WL slug
* Fix: Hardcoded SearchAtlas/Search Atlas brand strings replaced with whitelabel-aware output across admin views, dev panel, site health checks, and MCP tool descriptions
* Fix: \"SearchAtlas AI Pages\" dashboard widget title ignoring whitelabel plugin name setting
* Fix: Whitelabel icon lost after exporting and importing the plugin on a different site — icon is now bundled inside the export ZIP and restored to the new site's uploads directory on import
* Fix: Whitelabel icon not shown on WordPress Dashboard → Updates page — icon injected into the `update_plugins` site transient so the correct branded icon appears regardless of the update API response

= 2.5.23 =
**Bug Fixes:**
* Fix: MetaSync WP plugin breaks Elementor front-end spacing — SimpleHtmlDOM `stripRN` was stripping whitespace between inline elements; now set to `false`
* Fix: Elementor headings override Global Fonts after Content Genius sync — removed forced Roboto font so headings inherit site's Global Font settings
* Fix: Elementor Canvas template incorrectly applied when syncing articles as posts
* Fix: Admin bar status icon now reflects missing UUID as a distinct warning state (orange) rather than generic disconnected state

**Improvements:**
* Performance: Added transient cache to OTTO JS check to reduce overhead on every request
* Improvement: Cache Purge — Added per-URL purge support
* Improvement: Cache Purge — Query string normalization strips UTM/gclid/fbclid params before purge so all URL variants resolve to the same canonical URL
* Improvement: Cache Purge — Edge CDN purge integrations targeted via configuration
* Improvement: Remove non-publishable roles (Subscriber, Contributor) from Content Genius User Roles to Sync setting
* Improvement: Validation message added when publishing AI Landing Pages with plain permalink structure

**Features:**
* Feature: WordPress Site Health Integration — MetaSync now registers checks in the WP Site Health panel
* Feature: Media Optimization
* Feature: Rate the Plugin notice — shows a dismissible prompt after 7 days of usage; hidden when whitelabel is enabled 

**Refactoring:**
* Removed `instant-index/` vendor tree (~714 files); replaced Google SDK with native PHP implementation 
* Decomposed `Metasync_Admin` god object (17,849 lines) into 10 focused classes; admin reduced to ~3,986 lines
* Decomposed `Metasync_Public` into focused classes; REST API routes extracted to `Metasync_Rest_Api` 
* Extracted 23 inline JavaScript blocks into proper `.js` files loaded via `wp_enqueue_script`

= 2.5.22 =
* Improvement: Heartbeat connection/pool logic to reduce the number of external requests
* Improvement: Adapt scenarios of Idle WpRocket + Active Kinsta Cache that was preventing the cache to be purged.

= 2.5.21 =
###Features                        
  - Add hosting cache integration (WP Engine + Kinsta) with per-provider toggles in Advanced → Cache
  Management
  - Add WP Engine native cache purge (Varnish + Memcached) via `WpeCommon`
  - Add Kinsta native full-page cache purge via `KinstaCache::kinsta_cache_purge_full()`

  ###Bug Fixes
  - Fix: SEO placeholder tokens (%%title%%, %sitename%, #post_title#, etc.) now resolved to real values on
  import from Yoast / Rank Math / AIOSEO
  - Fix: Customer schema markup being injected into the global site header
  - Fix: WP Rocket compatibility — OTTO no longer disables WP Rocket JS/CSS optimization features
  - Fix: WP Rocket + Kinsta cache conflict causing meta title/description to revert
  - Fix: CSS injection methods (`enqueue_page_custom_css`, Elementor, Divi) missing from `Metasync_Public`
  - Fix: Plugin URI and Author URI fields silently discarding valid URLs

= 2.5.20 =
* Fixed a case where a empty Otto meta description was overwriting the existing one.

= 2.5.19 =
New Features

  - SEO Sidebar in Post Editor — Edit Meta Title, Meta Description and URL slug directly from the post/page
   editor sidebar
  - Bot Detection Layer for OTTO — Detects and tracks bots with statistics dashboard
  - Bing Instant Indexing — Submit URLs directly to Bing
  - Plugin Setup Wizard — Guided setup process for new users
  - Execution Settings — New controls under Advanced Settings
  - Debug Mode Auto-Disable & Safety Limits — Automatically disables after a set period
  - Enhanced Error Categorization — Improved monitoring with categorized error tracking
  - Virtual robots.txt / sitemap.xml — Served virtually when host blocks direct file writes
  - OTTO 404-Specific Filtering — Filter OTTO suggestions for 404 pages
  - Heartbeat reliability improvements

  Improvements

  - Schema Markup disable option — Can now be toggled per Post/Page Editor settings
  - Whitelabel form validation — Prevents partial saves without whitelabel mode activation
  - Host Blocking in whitelabel — Host blocking now covered by whitelabel settings
  - Support Token rate limiting — Properly enforced during login
  - Report Issue with Sentry User Feedback — Image attachments supported
  - MCP Tool API key auth — Fixed permission checks for API key-authenticated requests

  Bug Fixes

  - Fixed OTTO removing original meta description when no OTTO description deployed
  - Fixed plugin breaking Elementor front-end spacing when span tag was inner H1
  - Fixed WP Rocket + Kinsta cache conflict causing meta titles/descriptions to disappear
  - Fixed ALT text not applied on home page and other pages for Custom Theme
  - Fixed Plugin URI and Author URI not being saved
  - Fixed UI issues in admin screens
  - Fixed sync logs not showing newest info after filtering
  - Fixed exported whitelabel settings not uploading to another site
  - Fixed plugin settings hiding in plugin menu but not under WordPress General settings
  - Fixed Import SEO Data missing from access control list

  Stability / PHP 8.1+ Compatibility

  - 13 improvements with deprecated PHP functions below PHP V8.0

= 2.5.18 =
* Removed JWT-based temporary support access token system
* Improved internal code documentation

= 2.5.17 =
* New: Administrator-Controlled Remote Support - Support access now requires explicit consent with secure, time-limited JWT tokens you generate and control
* New: Support Access Management UI - Generate, view, and revoke support tokens directly from plugin settings
* New: Email notifications when support tokens are generated, used, or revoked
* Security: Connect to Search Atlas now uses time-limited, single-use tokens instead of persistent credentials
* Security: Added rate limiting to Connect to Search Atlas authentication

= 2.5.16 =
* New: Administrator-Controlled Remote Support - Support access now requires explicit consent with secure, time-limited tokens you generate and control
* Security: Enhanced Search Atlas SSO authentication with time-limited tokens
* Security: Improved access control for administrative functions
* Security: Added rate limiting to authentication endpoints
* Improvement: Optimized token validation for better performance

= 2.5.15 =
* **Fix:** Improved compatibility with the **NitroPack** cache plugin.
* **Fix:** Resolved a compatibility issue with the **Divi Timeline** widget.
* **Fix:** Resolved a compatibility issue with the **Divi MultiView** widget.
* **Fix:** Fixed an issue where static text in Advanced Settings displayed "OTTO/SA" instead of the **White Label** name.
* **Fix:** Improved input validation within the **Redirection** form.
* **Fix:** Fixed an issue where the **Cancel** button in the Redirection feature was not triggering.
* **Fix:** Resolved an issue where **General Settings** would reset after a White Label password was set.
* **Improvement:** Enhanced the filter and search modules in the **Redirection** section.
* **Improvement:** When a new sitemap is generated, the **robots.txt** file will now automatically update with the correct link.
* **Improvement:** Simplified plugin navigation for a better user experience.
* **Improvement:** Removed deprecated files and unnecessary logs to optimize performance.
* **Improvement:** Added **MCP** support.
* **Improvement:** Added data persistence with **granularity** support.
* **Improvement:** Improved notification messaging to better inform users about plugin updates.
* **Feature:** Added full support for **Oxygen Builder**.
* **Feature:** **Plugin Access Control:** Added a setting under Advanced Settings to define which user roles can access the plugin.
* **Feature:** **Advanced Access Control:** White Label users can now disable specific settings or features per user role (**Settings \> White Label \> Advanced Access Control**).
* **Feature:** Added a dedicated **Import SEO Data** screen.
* **Feature:** Added an option to set all external links to `target="_blank"` (**Settings \> General \> Post/Page Settings**).
* **Feature:** Added an option to add `rel="nofollow"` to all external links (**SEO \> Indexation \> Indexation Control**).
* **Miscellaneous:** Minor cross-compatibility improvements for legacy PHP versions.

= 2.5.14 =
* **Fix:** Fixed a case of compatibility with Formidable Form Builder
* **Fix:** Fixed a case of compatibility with Essentials Theme
* **Fix:** Small adjustments in overall code for performance
* **Improvement:** Improvement of OTTO SSR system
* **Improvement:** Small adjustments in the UX
* **Improvement:** Improvement of suggestions persistence in WP DB.

= 2.5.13 =
* **Fix:** Fixed a case where new published article was only showing headers 
* **Fix:** Fixed a case of compatibility with Essentials Theme
* **Fix:** Small adjustments in overall code for performance
* **New Feature: Whitelabel Plugin Export -** We included a feature to export a version of the plugin with your WL info. You could now easily install the plugin through different hosts, without the need to manually update/include the WL settings.
* **New Feature: Import from RankMath/Yoast/AIOSEO -** We included a feature to import Robots.txt, Sitemap.xml, Schemas and Indexation Options from SEO Plugins.
* **New Feature: Advanced Settings -> Clear Transient Cache -** We included a feature to clear the transient cache used to boost page performance. The cache stores the deployed changes from the platform, avoiding unnecessary process to apply the changes. 
* **Improvement:** Small adjustments in OTTO SSR
* **Improvement:** Small adjustments in Light Theme version
* **Improvement:** We included a option in the Settings to Disable OTTO Toolbar.
* **Improvement:** Otto Toolbar is Whitelabel friendly (Apply WL name)

= 2.5.12 =
* **Fix:** Fixed a case of compatibility with Slider Revolution
* **Fix:** Fixed a case of duplication in content synced
* **Fix:** Small adjustments in PHP Warnings being triggered
* **Fix:** Fixed a case of custom child theme triggering errors in error log
* **New Feature: Light/Dark Theme -** We included a feature that allow users to switch the plugin colors to a light version.
* **New Feature: Content Genius Authors -** We included a feature under settings where you could choose what user roles would be synced with Content Genius
* **New Feature: OTTO Changes Viewer -** We included a feature to check what changes are being applied in a page optimized by OTTO.
* **Improvement:** We improved the OTTO detection system.
* **Improvement:** Changes in Robots.txt could be rollbacked with a historic of changes
* **Improvement:** We included a option in the Indexation feature to disallow Category pages.
* **Improvement:** Small optimizations in the code for page speed improvement.

= 2.5.11 =
* **Fix:** Fixed a case of compatibility with Woocommerce
* **Fix:** Fixed a case of redirections not working when the target URL was deleted
* **Fix:** Fixed a case of Error Logs not displaying correctly
* **Fix:** Fixed a case of PHP Warning due outdated PHP version
* **Fix:** Fixed a case of custom child theme triggering errors in error log
* **New Feature: Report a Issue -** We included a feature that allow users to notify Issues directly from the plugin.
* **Improvement:** We improved the Whitelabel settings to hide specific features if needed.
* **Improvement:** Improvements on system messages

= 2.5.10 =
* **Fix:** Fixed a case of compatibility with Gravity Forms
* **Fix:** Fixed a case of compatibility with Elementor Pro
* **Fix:** Fixed a case of compatibility with DIVI
* **Fix:** Fixed a case where the post editor preview was blocked
* **Fix:** Fixed a case of original meta title and meta description being overwritten by OTTO
* **New Feature: Custom Pages -** We included a feature that allow users to create custom pages bypassing existing Themes and Styles.
* **New Feature:** **OTTO Excluded URLs -** We included under Compatibility Tab, a option to Turn off OTTO changes per URL.
* **New Feature:** **Import Redirections -** Users could now import redirections from .csv file or directly from Yoast/RankMath/AIOSEO.
* **Improvement:** We improved the Whitelabel settings to hide specific features if needed.
* **Improvement:** Minor changes in core functions.
* **Improvement:** Excluded from Sitemap generator URLs that aren't related with live pages.

= 2.5.9 =
* **Fix:** Fixed a case where the plugin was duplicanting the meta description tag
* **Fix:** Fixed a case where the post editor was preventing user to save OG tags.
* **New Feature:** We included in advanced settings a option to test the connectivity between host and the platform.
* **New Feature:** Users could generate SITEMAP.xml directly in the plugin (This function will disable current sitemap.xml plugins)
* **New Feature:** User could Enable/Disable storing OTTO Meta Title and Meta Description into the Database. When enabled, it will overwrite Meta Title and Meta Description of Yoast/RankMath, etc, and the old meta title and meta description
* **Improvement:** We improved the Whitelabel settings to hide specific features if needed.
* **Improvement:** Minor changes in cross-compatibility with outdated PHP versions.

= 2.5.8 =
* **Fix:** Some conditions was preventing saving new/edit posts directly from the editor
* **Fix:** Non-standard tags found in body of the page, are being detect as plain text.
* **New Feature:** White Label Clients could turn on/off Plugin features in Whitelabel Settings.
* **Improvement:** Minor changes in cross-compatibility with outdated PHP versions.

= 2.5.7 =


= 2.5.6 =
* **Prevention**: New error detection system.
* **White Label**: 1-click Auth will sync the logo from the WL dashboard
* **Fixes & UX**: Allow Rollback changes in Meta Title and Meta Description that was being stored in the Database.

= 2.5.5 =
* **Performance**: Significant improvement to dashboard loading speed.
* **White Label**:
  * New dedicated White Label settings screen (Settings\>White Label).
  * Added password protection for White Label settings.
* **Logging**: Introduced Sync Logs to display implemented website changes.
* **Fixes & UX**: Minor UX adjustments and a fix for a false positive XSS issue in unused code.
* **System**: Minor OTTO system optimizations to reduce disk space and memory usage.

= 2.5.4 =
* HotFix: In some cases, the **error log** was becoming too large, causing host performance to slow down.

= 2.5.3 =
* Included Compatibility Screen V.1, that shows most common Themes and plugins and compatibility
* Improved error logs system

= 2.5.2 =
No changelog available.

= 2.5.1 =
* **Improved Dashboard Loading** for public dashboard URLs.
* **Enhanced compatibility** with naked URLs (non-www) on the platform.
* **Refined the Connection Status Bar.**
* **Fixed a bug** where the Connection Tips Box didn't expand when clicked.
* **Enabled the storage** of meta titles and descriptions in the database.
* **Added an option** to disable the dashboard view from the settings page.
* **Created a setting** to disable the Connection Status Bar from the admin bar.
* **Implemented a new Telemetry system** to streamline bug detection.
* **Resolved an issue** that caused error logs to be generated multiple times.
* **Optimized the rendering of AMP pages** to prevent unrecognized HTML tags.

= 2.5.1 =
* **Improved Dashboard Loading** for public dashboard URLs.
* **Enhanced compatibility** with naked URLs (non-www) on the platform.
* **Refined the Connection Status Bar.**
* **Fixed a bug** where the Connection Tips Box didn't expand when clicked.
* **Enabled the storage** of meta titles and descriptions in the database.
* **Added an option** to disable the dashboard view from the settings page.
* **Created a setting** to disable the Connection Status Bar from the admin bar.
* **Implemented a new Telemetry system** to streamline bug detection.
* **Resolved an issue** that caused error logs to be generated multiple times.
* **Optimized the rendering of AMP pages** to prevent unrecognized HTML tags.

= 2.5.0 =
* **Search Atlas SSO**
  * Users can now authenticate the plugin with a single click, eliminating manual setup steps and streamlining the entire process.
* **OTTO One-Click Activation**
  * After successful authentication, if an OTTO project exists in Search Atlas, its configurations are automatically imported and applied within WordPress.
* **White Label Branding**
  * The white label experience has been significantly improved. Upon one-click authentication, any configured white label settings are automatically imported. This includes:
    * Plugin Name
    * Logo
    * OTTO Name
    * Dashboard URL
  * From then on, all plugin connections reflect the customer’s white label branding. These settings are also accessible in a new **Advanced tab** within the plugin settings.
* **Dashboard Access**
  * Direct access to the customer’s OTTO project dashboard is now available from within the plugin.
* **Plugin Redesign**
  * The plugin has been fully rebranded with a refreshed design that aligns with the current Search Atlas dashboard, offering a more consistent and modern user experience.
* **Reset Plugin**
  * A new option has been added to fully reset plugin configurations back to their default values.
* **Error Logs**
  * Enhanced error logging is now available. When WordPress debug modes are enabled, the plugin can generate anonymous logs that may be shared with the specialist team for troubleshooting.
* **Search Atlas SSO**
  * Users can now authenticate the plugin with a single click, eliminating manual setup steps and streamlining the entire process.
* **OTTO One-Click Activation**
  * After successful authentication, if an OTTO project exists in Search Atlas, its configurations are automatically imported and applied within WordPress.
* **White Label Branding**
  * The white label experience has been significantly improved. Upon one-click authentication, any configured white label settings are automatically imported. This includes:
    * Plugin Name
    * Logo
    * OTTO Name
    * Dashboard URL
  * From then on, all plugin connections reflect the customer’s white label branding. These settings are also accessible in a new **Advanced tab** within the plugin settings.
* **Dashboard Access**
  * Direct access to the customer’s OTTO project dashboard is now available from within the plugin.
* **Plugin Redesign**
  * The plugin has been fully rebranded with a refreshed design that aligns with the current Search Atlas dashboard, offering a more consistent and modern user experience.
* **Reset Plugin**
  * A new option has been added to fully reset plugin configurations back to their default values.
* **Error Logs**
  * Enhanced error logging is now available. When WordPress debug modes are enabled, the plugin can generate anonymous logs that may be shared with the specialist team for troubleshooting.
* **Disable SSO**
  * Disable 1 Click WP Authentication from Dashboard

BUG FIXES:

* Disabled Canonical redirects generating loops
* Check if URL could be crawled
* Duplicated element with YITH plugin
* FAQ format conflict with Theme Builder
* Conflict with Hostify Booking Engine plugin
* Old version of Elementro Pro breaking CSS

= 2.4.4 =
* Bug Fix: When user is logged via SSO, validate if the auth token is set

= 2.4.3 =
* HotFix 1: When user is logged via SSO, persist the session in the admin area

= 2.4.2 =
* Bug fix 1: large log size for heavy traffic sites

= 2.4.1 =
* Bugfix 1: Imported Posts published with duplicate images

= 2.4.0 =
* Feature 1: Single Sign On to WP from Dashboard
* Feature 2: Modifying Existing Posts with CA AI
* Improvement 1: Improved Error Handling for menu Image field
* Improvement 2: Notification about permalink compatibility
* Improvement 3: Remove outdate UI component (Clear Cache Button)
* Improvement 4: Delete Zipped logs older than 30 days
* Improvement 5: Displaying Block Quote elements from CA
* Bug fix 1: Table of Contents not Working for some sites
* Bug fix 2: FAQ Section not comming in correctly for some sites
* Bug fix 3: Error warning while publishing to WP Elementor on some sites
* Bug fix 4: Fatal Error triggered on invalid URI
* Bug fix 5: Preserve redirection for forward slash ending urls

= 2.3.11 =
* Feature 1: Modifying Existing Posts with Content Assistant AI

= 2.3.10 =
* Bug fix 1: Critical Error with undeclared global Post Object

= 2.3.8 =
* Bug fix 1: Check out flow breaking on some sites

= 2.3.7 =
* Bug fix 1: Otto Interfering with some non-native Ajax requests

= 2.3.6 =
* Bug fix 1: Activating Otto SSR breaks UI on some sites

= 2.3.5 =
* Bug fix 1: Argument Count Error on some custom themes
* Bug fix 2: Missing Title on some sites
* Improvement 1: Serve Otto improved changes to Bot

= 2.3.4 =
* Bug fix 1: Error Viewing blog posts in some Nginx sites
* Improvement 1: Otto Crawl monitoring
* Improvement 2: Remove redundant requests to back end

= 2.3.3 =
* Bug fix 1: Checkout failure on some sites

= 2.3.2 =
* Bug fix 1: Fatal Error on empty tag attributes
* Bug fix 2: Plugin Breaking Grid Page Sites

= 2.3.1 =
* Improvement 1: Real Time Server Side Rendering - No HTML Cache

= 2.3.0 =
* Bug fix 1: Fix 500 error when publishing a post  
* Bug fix 2: Fix OTTO SSR disabling the WordPress edit menu  
* Bug fix 3: Fix rest_forbidden error when publishing blog post   
* Bug fix 4: Fix OTTO breaking website layout (top-bar)  
* Improvement 1: Session Based Rendering
* Improvement 2: Reset Heartbeat API calls to 5-minute intervals  
* Improvement 3: Update plugin layout and implement white-labelling  
* Improvement 4: Implement new caching mechanism

= 2.2.7 =
* Bug fix 1: Fix 429 page issue
* Improvement 1: Disabling Otto on error pages like 404

= 2.2.6 =
* Improvement 1: Refactored CI/CD Pipelines and added releases

= 2.2.5 =
* Bug fix 1: Critical error on metasync logs page
* Bug fix 2: Purchase process affected on Ecommerce Sites

= 2.2.4 =
* Bug fix 1: AI page builder formatting
* Bug fix 2: Categories not syncing

= 2.2.3 =
* Bug Fix 1: Compatibility Improvements
* Bug Fix 2: Hero Image not showing on some posts
* Bug Fix 3: Post title missing on some posts

= 2.2.2 =
* Feature 1: Clear Otto Caches Button
* Feature 2: Option to disable Otto for logged in Users
* Bug fix 1: Hero Image not getting Updated

= 2.2.1 =
* Bugfix 1: Fix login Loop Issue on Sites with OTTO SSR
* Bugfix 2: Fix Otto SSR Caching Problem on the Admin Side
* Bugfix 3: Fix H1 rendering on published Posts

= 2.2.0 =
* Bugfix 1: Fix login Issues on Sites with OTTO SSR
* Bugfix 2: Accepting UUID to activate OTTO on Wordpress
* Feature 1: Alerting users to remove OTTO JS before activating OTTO SSR
* Feature 2: Instant deployment of changes made on OTTO dashboard

= 2.1.1 =
* bugfix 1: Logging System - Fix Corrupted Zip Files

= 2.1.0 =
* Feature : Server Side Rendering of the Otto Pixel

= 2.0.0 =
* Feature 1: Metasync Specific Site Log Monitoring & Logging
* Bugfix 1: Failed Image Syncing
* Bugfix 2: Fix AI landing Page Visibility
* Bugfix 3: Headings Coming over with #000000

= 1.9.3 =
* Fixed : Error Publishing Blog Article

= 1.9.2 =
* Fix : Renamed DB Migration Class to Prevent Conflict

= 1.9.1 =
* Feature 1: Stability Improvements

= 1.9.0 =
* Bug fixes
* Fixed PHP 7.1.x compatibility issues

= 1.8.9 =
* Enhanced security measures

= 1.8.8 =
* Improved general page settings

= 1.8.7 =
* Enhanced compatibility with WordPress Dashboard

= 1.8.6 =
* Server-side improvements

= 1.8.5 =
* Additional server optimizations

= 1.8.4 =
* Resolved logic issues for white labels

= 1.8.3 =
* Fixed isset issues for white labels and public view

= 1.8.2 =
* Addressed isset issues for white labels and error logging

= 1.8.1 =
* Fixed isset issues for white labels

= 1.8.0 =
* Fixed "Sync now" plugin list addition

= 1.7.9 =
* Fixed content removal in Debug Log

= 1.7.8 =
* Fixed content removal in Debug Log

= 1.7.7 =
* Improved Default Debug Log

= 1.7.6 =
* Enhanced Default Debug Log

= 1.7.5 =
* Improved Debug functionality

= 1.7.4 =
* General code improvements

= 1.7.3 =
* Continued code enhancements

= 1.7.2 =
* Fixed various errors

= 1.7.1 =
* Addressed metasync error logging

= 1.7.0 =
* Enhanced code and integrated Otto

= 1.6.9 =
* Improved compatibility with Divi Child theme

= 1.6.8 =
* Fixed issues with figure tags

= 1.6.7 =
* Enhanced support for Iframe tags

= 1.6.6 =
* Redirects updated after White Label changes

= 1.6.5 =
* Code and White Label improvements

= 1.6.4 =
* General code improvements

= 1.6.3 =
* Continued code enhancements

= 1.6.2 =
* Code improvements and menu renaming

= 1.6.1 =
* Code enhancements and White Label updates

= 1.6.0 =
* Code improvements and added Sub Pages functionality

= 1.5.9 =
* Code improvements and HTML format fixes

= 1.5.8 =
* Code improvements and HTML format fixes

= 1.5.7 =
* Code enhancements and bug fixes

= 1.5.6 =
* Code improvements and bug fixes

= 1.5.5 =
* General code improvements

= 1.5.4 =
* Code enhancements
* Fixed List Item components

= 1.5.3 =
* Code improvements
* Integrated Divi

= 1.5.2 =
* Code enhancements
* Fixed Elementor activation bug

= 1.5.1 =
* General code improvements

= 1.5.0 =
* Continued code enhancements

= 1.4.9 =
* General code improvements

= 1.4.8 =
* Added CSS for Search Atlas
* Code enhancements

= 1.4.7 =
* Added Permalink Structure Validator
* Enhanced Page Editor checks
* Option to choose Page Editor
* Code improvements and bug fixes

= 1.4.6 =
* Code enhancements and bug fixes

= 1.4.5 =
* Code improvements and bug fixes
* Removed deprecated features from codebase

= 1.4.4 =
* Code enhancements and bug fixes
* Added option to enable/disable features menu in General settings

= 1.4.3 =
* Changed post permalink approach to prevent conflicts
* Updated page template to default instead of blank
* Set page parent to main if page doesn't exist
* Fixed permalink function in getPagesList
* Resolved minor bugs and improved code

= 1.4.2 =
* Added new API endpoint `getPostByURL` to find posts by URL
* Updated README content and "Tested up to" attribute to latest version
* Updated `getPagesList` API to display all pages in JSON response
* Fixed bug in retrieving `post_id` in API codes
* Improved Pages management API for sub pages

= 1.4.1 =
* Added three new API endpoints for creating pages
* Renamed heartbeat endpoints and functions
* Updated heartbeat categories and user sync limits and responses
* Updated plugin name and description
* Added plugin banner for marketplace page
* Removed Sitemaps feature, functions, and routes
* Added option to enable/disable Schema on Posts and Pages
* Improved General Settings UI/UX
* Fixed bugs and enhanced code

= 1.4.0 =
* Feature: Basic Markdown support `[markdown] MARKDOWN CONTENT [/markdown]`
* Feature: Set a new Landing Page via API
* API code improvements and bug fixes

= 1.3.4 =
* Enhanced Error Logs viewer and messages
* Added new error logs API
* Fixed `createPost` API code bug

= 1.3.3 =
* Performance enhancements and bug fixes

= 1.3.2 =
* Fixed bugs related to post and page images

= 1.3.1 =
* Updated UI for HTML Accordion components

= 1.3.0 =
* Editor/APIs: Added HTML Accordion support via shortcode `[accordion]CONTENT[/accordion]`
* APIs: Enabled editing drafts or published posts and pages multiple times without duplication via permalink
* APIs: Allowed manual addition of schema/scripts/styles to posts and pages
* APIs: Improved `deleteItem` endpoint code to comply with REST standards
* APIs: Numerous bug fixes and code enhancements for better performance and stability

= 1.2.8 =
* Added new business type "Tree Services" to Business SEO page

= 1.2.7 =
* Added Error Logs GET API endpoint
* Enabled functionality to trigger on-demand payload requests
* Added heartbeat HTTP request error information to Error Logs
* Set a limit of one thousand records for categories and users

= 1.2.6 =
* Fixed issue displaying all fields in Optimal Settings

= 1.2.5 =
* Enabled selecting the author of a post/page randomly in Create/Update item APIs
* Added ALT text for hero images in Create/Update item API payloads
* Set specific post dates within the last two months in Create API
* Added validation for `post_date` in Create/Update item APIs
* Refactored Create/Update item API code

= 1.2.4 =
* Added feature and menu to enable/disable Open Graph, Facebook, and Twitter meta tags for all posts and pages
* Updated plugin version and README file

= 1.2.3 =
* Enabled returning revisions for posts or pages in the update endpoint
* Added or updated post tags in Create and Update endpoints
* Included tags list in heartbeat API payload

= 1.2.2 =
* Optimized Create and Update endpoints for media uploads
* Added redirection functionality in Create and Update endpoints
* Enabled/disabling comments in Create and Update APIs
* Fixed category updates in heartbeat API
* Resolved issue uploading previously unuploaded images to media in Create API

= 1.2.1 =
* Added hero image attribute `hero_image_url` in Create or Update post endpoint
* Refactored image upload code to use URLs in Create/Update endpoints
* Updated plugin README file

= 1.2.0 =
* Added post author in Create post endpoint
* Included users in heartbeat API with `users` attribute
* Assigned authors to attachments when uploading images to the media library
* Rebuilt post content tags based on HTML rules

= 1.1.9 =
* Enabled saving images from URLs to the WordPress media library
* Added functionality to save images from URLs in Create/Update endpoints
* Implemented checks for existing images from URLs in Update endpoint

= 1.1.8 =
* Corrected home URL storage in the 404 Monitor database
* Identified correct page URLs in the 404 Monitor for redirection
* Utilized correct URLs from the 404 Monitor for Redirection

= 1.1.7 =
* Sent post categories as a list to backend requests
* Enabled auto-generation of the plugin's authorization key
* Displayed a message when the Search Atlas API key is not saved

= 1.1.6 =
* Updated the title of General Settings
* Updated plugin screenshots

= 1.1.5 =
* Added/Replaced categories for posts with a key to append categories in update items
* Synchronized post categories in Create/Update item endpoints
* Refactored code to sync customer website configurations and post categories

= 1.1.4 =
* Fixed issue sending categories as JSON in heartbeat payload
* Removed special characters from category JSON
* Fixed heartbeat request interval settings

= 1.1.3 =
* Removed login fields for obtaining JWT token from General Settings
* Removed dashboard link from the plugin
* Set heartbeat frequency to every 5 minutes
* Configured heartbeat requests to trigger on saving General Settings

= 1.1.2 =
* Removed double quotes and forward slashes from JSON-LD Schema

= 1.1.1 =
* Fixed article name issue in JSON-LD Schema
* Added plugin icon to the plugin directory
* Added plugin screenshots to the plugin directory
* Included changelogs in the plugin's README file

= 1.1.0 =
* Added a button to manually force heartbeat sending to the server
* Displayed the last sync timestamp to monitor heartbeat status
* Added input field to save the Search Atlas API key in General Settings
* Included Search Atlas API key in the header of Heartbeat API
* Removed JWT token and Customer ID from Heartbeat Request

= 1.0.9 =
* Added `meta_description` and `meta_robots` to the payload
* Fixed issue updating categories consistently in the payload
* Fixed issue adding two different `meta_robots` values in the header
* Included blog categories in the Heartbeat payload

= 1.0.8 =
* Updated post/page permalinks during post updates via API payload
* Updated post categories during post updates via API payload

= 1.0.7 =
* Added categories during post creation
* Included Permalink URL during page creation
* Set default post type to Post and post status to Publish

= 1.0.6 =
* Added new sanitization for JSON-LD to echo in the header
* Removed third-party URLs from post data in the plugin

= 1.0.5 =
* Created new `deleteItem` API to delete posts
* Refactored JSON-LD schema code to include all posts and pages
* Added prepare method in WHERE clause fields of queries

= 1.0.4 =
* Added new sanitization for all POST and GET variables
* Refactored all plugin code

= 1.0.3 =
* Added sanitization for all missing POST/REQUEST variables
* Implemented escape methods for all internal print variables
* Refactored all select boxes to use select options
* Removed redundant code from the plugin

= 1.0.2 =
* Fixed issue excluding post IDs from sitemaps via API or General Settings
* Fixed issue sanitizing null values in common settings

= 1.0.1 =
* Fixed invalid token issue in Heartbeat API
* Fixed exception handling in LinkGraph login response

= 1.0.0 =
Initial release.