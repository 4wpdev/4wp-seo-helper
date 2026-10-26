=== 4WP SEO Helper ===
Contributors: 4wpdev, anatolikkk
Tags: seo, inventory, yoast, search console, google
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site-wide SEO inventory and Google Search Console in wp-admin—P1–P3 lanes, REST sync, URL inspection, indexing. Yoast & AIOSEO.

== Description ==

**4WP SEO Helper** gives you a **site-wide SEO Inventory** for WordPress: see missing titles and meta descriptions, score completeness, and mark business priority with drag-and-drop **P1 / P2 / P3** lanes.

Version **2.0** adds a full **Google Search Console** workspace inside wp-admin: connect your Google account, sync Search Analytics locally, inspect URLs, and see indexing status next to inventory rows.

Version **2.2** adds an **AI Assistant** that generates SEO titles, meta descriptions, and focus keyphrases through the standard **WordPress AI Client** (Settings → Connectors). There is no custom vendor SDK. Nothing is generated until an administrator configures a connector.

Version **2.3** adds **Trends**: import Google Trends JSON or CSV and match interest to inventory focus keyphrases.

Version **2.4** adds **Positions**: track Google SERP rank for queries (via Serper) against your Search Console property, store dated snapshots, and collect People also ask / related searches.

Version **2.5** adds **Cross posting** for packages imported from **4WP Drive** (`meta.json`): manage platforms, delay after the original post is published, share a Telegram list, send manually from the editor, and record publications (with live slug) from the Chrome extension.

Version **2.6** adds **Abilities** (WordPress 6.9+ Abilities API): AI agents can list posts with missing or incomplete SEO meta, read and update SEO title / meta description / focus keyword by ID or slug, and search posts by title or focus keyword. Values are saved through the active SEO plugin adapter. Expose them to Claude and other MCP clients with [4WP MCP Connector](https://4wp.dev/) and the official MCP Adapter.

Built for teams that use **Yoast SEO** or **All in One SEO** and need one place to audit SEO fields across posts, pages, and public custom post types.

A plugin by [4wp.dev](https://4wp.dev/). Overview and setup notes: [4WP SEO Helper on 4wp.dev](https://4wp.dev/plugin/4wp-seo-helper/).

= Perfect for =

* **SEO audits** — filter by missing title, meta description, or focus keyphrases
* **Content ops** — drag rows into P1–P3 lanes (business priority, not SEO score)
* **Bulk workflows** — quick edit SEO fields from the inventory table
* **AI copy** — generate title, description, and focus keyphrases via WordPress Connectors
* **Search Console ops** — overview, insights, performance breakdowns, URL inspection, and background data sync
* **Rank tracking** — Positions: query + country checks, SERP snapshots, People also ask / related searches
* **Trends** — import Google Trends snapshots and see interest next to matching keyphrases
* **Cross posting** — after a Drive package is published, schedule or send shares to Dev.to, daily.dev, Hashnode, Medium, X, and more; Telegram notify; status in the editor
* **Indexing workflows** — refresh index status and request indexing from the inventory table or admin bar
* **External sync** — REST API for Google Sheets or custom dashboards
* **AI agents (MCP)** — Abilities API: audit, search, and edit SEO meta from Claude via 4WP MCP Connector
* **Multilingual sites** — Polylang, WPML, or single-language

= How it works =

**SEO Inventory**

1. Activate the plugin (Yoast or All in One SEO recommended for read/write).
2. Open **4WP SEO → SEO Inventory**.
3. Review completeness scores and missing SEO fields site-wide.
4. Drag rows into **P1 / P2 / P3** to mark business priority.
5. Optional: enable the REST API under **Settings** and connect Sheets or your dashboard.
6. Optional: configure **Settings → Connectors** (WordPress AI Client), then Generate SEO copy from Inventory Quick Edit.

Priority reflects **business importance** (e.g. main service pages), not SEO score—a page can stay in P1 at 100% completeness.

**Google Search Console**

1. Enable **Google Search Console** under **4WP SEO → Settings**.
2. Open **Settings → Search Console** and add OAuth **Client ID** and **Client Secret** from [Google Cloud Console](https://console.cloud.google.com/).
3. Enable the **Google Search Console API** for the same Cloud project (OAuth alone is not enough).
4. Register the redirect URI shown on the settings screen in your OAuth client.
5. Click **Connect to Google** and sign in with an account that has access to this site in Search Console.
6. The plugin auto-matches the property for your WordPress site domain, or use **Local / staging mode** to pick a property manually.
7. Open **4WP SEO → GSC** for overview, insights, performance, URL inspection, and data sync.
8. Run **Sync now** to pull Search Analytics into WordPress (optional daily WP-Cron schedule).

If the connected Google account does **not** have access to this site's Search Console property, sync and GSC tools stay blocked until access is granted. The settings screen links to Search Console user management for the current site.

**Positions**

1. Connect Search Console and select the property you want to find in the SERP (this is the “looking for” domain).
2. Open **Settings → Position providers**, add a **Serper** API key, and set default country / language.
3. Open **4WP SEO → Positions**, add a query (country and language can override the defaults).
4. Click **Update** to fetch organic results (Top 10 / 20 / 30; extra pages only if your site is missing). Each check stores a dated JSON snapshot.
5. Hover the SERP icon for titles; hover **Ask / Suggest** for People also ask and related searches collected from that query. The **Related** tab lists unique phrases with source query IDs.

**Cross posting (4WP Drive packages)**

1. Enable **Cross posting** under **4WP SEO → Settings** and set the default delay (hours) after the original is published.
2. Open **4WP SEO → Platforms** and add the networks you use (Dev.to, daily.dev, Hashnode, Medium, X, etc.).
3. Import a package from **4WP Drive** with `meta.json` / republish settings when you need per-package delay or platform overrides.
4. After the WordPress post is published, the plugin schedules cross posts (or you send from the editor sidebar).
5. Use **Send to Telegram** for the share list; record a live publication URL/slug from the Chrome extension when you post on a network.
6. Open **4WP SEO → Cross posting** for the board / status overview.

= Google Search Console features =

* **OAuth connection** — per-site Client ID/Secret, connect/disconnect Google account (tokens only; credentials stay saved)
* **Property picker** — auto-match by domain (`https://example.com/` or `sc-domain:example.com`) or manual selection for staging
* **Property access guard** — blocks sync and GSC menu until the connected account can access this site's property
* **Overview & insights** — clicks, impressions, CTR, position, branded vs non-branded query breakdown (configurable brand terms)
* **Performance** — Search Analytics by query, page, country, device, and search appearance with selectable date ranges
* **URL Inspection** — live index status for a single URL on the selected property
* **Data sync** — background manual sync and optional daily WP-Cron; stores metrics locally for inventory columns and reporting
* **SEO Inventory integration** — GSC clicks/impressions/top queries per row; index status, last crawl, refresh/request indexing actions
* **Admin bar** — quick Search Console inspect link on the front end when connected
* **Local development** — loopback OAuth redirect helper for `.local` / localhost sites

= AI Assistant =

* Uses the **WordPress AI Client** and **Settings → Connectors** (`wp_supports_ai()`, `wp_ai_client_prompt()`)
* Generates **SEO title**, **meta description**, and **focus keyphrases** from Inventory Quick Edit
* Default context is **page content** (plain text stored in WordPress). **Page URL** uses fewer tokens
* **4WP SEO → AI Assistant** documents what already works; prompt-pattern settings will follow
* Nothing is written to the post until you review, Apply, and Update
* If no connector is available, the Generate panel is not shown

= Positions =

* **4WP SEO → Positions** tracks queries per country against the Search Console property
* Provider: **Serper** (API key under Settings → Position providers); default `gl` / `hl` with per-query override
* Unique key is query + country; **Update** stores a dated organic snapshot (JSON)
* Depth Top 10 / 20 / 30 — extra Serper pages only if the site is not found yet
* Hover preview of organic titles; **Ask / Suggest** tooltip for People also ask and related searches
* Related tab keeps unique phrases and an array of source query IDs when the same idea appears on several checks

= Cross posting =

* **4WP SEO → Platforms** — taxonomy of share targets (Dev.to, daily.dev, Hashnode, Medium, X, and custom)
* **Delay** — hours (and optional minutes) after the original post is published before cross posting; `meta.json` republish settings can override
* **Editor sidebar** — status per platform, manual send, Telegram notify for the share list
* **Publication log** — URL and live slug recorded on the post (Chrome extension / REST); Cross posting board in wp-admin
* Works with packages imported from **4WP Drive** (`meta.json`)

= Modules =

**Available in wp-admin:** SEO Inventory, Inventory REST API, Google Search Console, Trends, Positions, Cross posting, AI Assistant (when a WordPress AI connector is configured).

**Coming soon:** TechArticle schema and LLMS.txt (visible as badges in Settings until a future release).

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install through **Plugins → Add New**.
2. Activate **4WP SEO Helper** through the Plugins screen.
3. Install and activate **Yoast SEO** or **All in One SEO** for full inventory read/write.
4. Open **4WP SEO → SEO Inventory** to start auditing.

On activation, the inventory API token is created and the inventory module is enabled by default.

**Optional — Google Search Console**

1. Create a Google Cloud project and OAuth 2.0 **Web application** credentials.
2. Enable **Google Search Console API** in APIs & Services → Library.
3. Add the redirect URI from **4WP SEO → Settings → Search Console** to Authorized redirect URIs.
4. Complete OAuth consent / branding in Google Cloud (Testing mode is fine for private sites; add test users).
5. Verify the site exists in [Google Search Console](https://search.google.com/search-console) for the Google account you connect.
6. Connect in WordPress and run an initial sync under **G Webmaster Con. → Data sync**.

**Optional — Positions (Serper)**

1. Create an API key at [serper.dev](https://serper.dev/).
2. Save it under **4WP SEO → Settings → Position providers**.
3. Select a Search Console property so Positions knows which domain to look for.
4. Add queries on **4WP SEO → Positions** and run **Update**.

== Frequently Asked Questions ==

= Do I need Yoast SEO or All in One SEO? =

**Recommended.** The inventory reads and writes SEO meta through adapter plugins. Without Yoast or AIOSEO, the table still loads but bulk updates and meta writes are limited.

= What is P1 / P2 / P3 priority? =

Three configurable business tiers in the inventory table. **Priority is not SEO score**—you might keep a fully complete page in P1 because it is a key landing page.

= Can I export the inventory? =

Yes. Use the CSV icon on the SEO Inventory tabs row to download titles, meta fields, completeness, priority, and missing-field flags.

= How does the AI Assistant work? =

It uses the standard **WordPress AI Client**. Configure a text-generation connector under **Settings → Connectors**. When `wp_supports_ai()` reports a working connector, Inventory Quick Edit can **Generate with AI** (SEO title, meta description, focus keyphrases). Default context is page content from WordPress; you can switch to page URL. Review the result, Apply, then Update. This plugin does not ship API keys and does not call a model until a connector is configured.

= Is there a REST API? =

Yes. Base URL: `/wp-json/forwp-seo-helper/v1/seo-inventory`

Routes include `GET /meta`, `GET /items`, and `PUT /priority-queue`. Authenticate with a Bearer token from **4WP SEO → Inventory API** (Settings tab), or as a logged-in administrator with `manage_options`.

= Does it work with Polylang or WPML? =

Yes. Language filters and per-language rows are supported when a multilingual plugin is active.

= Which post types are included? =

Public post types with admin UI are discovered automatically. Core **post** and **page** are always included. Internal types without public front-end URLs (Elementor library, FSE templates, attachments, etc.) are excluded by default. Customize with the `forwp_seo_inventory_exclude_post_types` or `forwp_seo_inventory_post_type_is_viewable` filters.

= How do I connect Google Search Console? =

1. Enable the module under **4WP SEO → Settings**.
2. Open **Settings → Search Console**.
3. Paste OAuth Client ID and Secret from Google Cloud.
4. Enable **Google Search Console API** in the same Cloud project.
5. Copy the redirect URI into your OAuth client.
6. Click **Connect to Google**.

= Why does OAuth succeed but no property appears? =

Common causes:

* **Search Console API not enabled** in Google Cloud (OAuth and API are separate steps).
* The connected Google account has **no access** to this site in Search Console.
* The site is **not added or verified** in Search Console yet.

The settings screen shows the API error when Google returns one. Use **Disconnect**, fix Cloud Console / Search Console access, then connect again.

= Why are GSC tools or sync blocked? =

The plugin requires a matched Search Console property for **this WordPress site**. If your Google account cannot see that property, inventory GSC columns, the GSC menu, and sync stay disabled until access is granted. The settings screen provides a **Request access in Search Console** link for the current site (built from `home_url()`, not hardcoded).

= Can I disconnect Google without losing OAuth credentials? =

Yes. **Disconnect** removes tokens and the selected property only. Client ID and Secret remain saved so you can reconnect quickly.

= What does Local / staging mode do? =

Lets you pick any property from the connected Google account manually—useful when the WordPress site URL differs from the GSC property (local dev, staging, or multi-site workflows).

= Does sync run in the background? =

Yes. **Sync now** queues a WP-Cron job so you can leave the page. Optional **daily automatic sync** can be enabled under **G Webmaster Con. → Data sync**.

= How do Positions work? =

Save a Serper key under **Settings → Position providers**. The Search Console property is the domain Positions looks for in organic results. Each **Update** on **4WP SEO → Positions** stores a dated snapshot. People also ask and related searches are saved (unique phrases, with every source query ID). No Serper calls run until an administrator saves a key and clicks Update.

= What modules are coming soon? =

TechArticle schema and blocks, LLMS.txt, and cross posting are in the codebase but hidden behind **Coming soon** badges until a future release.

= Does it depend on other 4WP plugins? =

No. **4WP SEO Helper** runs on its own. The REST API can integrate with external tools such as Google Sheets or the 4WP Analytics Dashboard. Optional AI copy uses the **WordPress AI Client** (Settings → Connectors), not a separate 4WP AI plugin.

= Is this plugin affiliated with Yoast or WordPress? =

No. Yoast SEO and All in One SEO are separate products. **4WP** is our project brand name—**WP** appears only as part of that name and is not a reference to WordPress. This plugin is not affiliated with, endorsed, or sponsored by WordPress.

== External services ==

This plugin optionally connects to **Google APIs** when a site administrator enables **Google Search Console** integration under **4WP SEO → Settings**.

**Google OAuth 2.0** (`accounts.google.com`, `oauth2.googleapis.com`)

Used so an administrator can connect their Google account to WordPress. When the admin clicks **Connect Google**, the plugin redirects to Google sign-in and exchanges an authorization code for access and refresh tokens. Tokens are stored in the WordPress database and used only for Search Console features initiated in wp-admin.

**Google Search Console API** (`searchconsole.googleapis.com`)

Used to list verified properties, inspect URLs, and fetch Search Analytics for the connected property. The plugin sends the selected property URL, requested page URLs, and date ranges when an administrator runs sync, URL inspection, or live analytics tools in wp-admin. Synced metrics are stored locally in WordPress for reporting screens and inventory columns.

No Google API calls are made until an administrator configures OAuth credentials and connects an account. The plugin does not send site visitor or front-end user data to Google.

**Serper Search API** (`google.serper.dev`)

Used only when an administrator saves a Serper API key under **Settings → Position providers** and runs **Update** on **4WP SEO → Positions**. The plugin sends the search query, country (`gl`), language (`hl`), optional date-range (`tbs`), and page number. Organic titles/URLs, People also ask, and related searches from the response are stored in WordPress for rank snapshots. The plugin does not send visitor or front-end user data to Serper.

* [Serper](https://serper.dev/)
* [Serper privacy policy](https://serper.dev/privacy)

**WordPress AI Client / Connectors** (optional)

When an administrator configures a connector under **Settings → Connectors** and runs **Generate with AI** in SEO Inventory, this plugin sends a prompt (page title plus either stripped page content or the page URL) through `wp_ai_client_prompt()`. The WordPress AI Client routes that prompt to the connector the administrator selected. This plugin does not call model vendors directly; which remote service receives the prompt depends on that connector.

* [Google Terms of Service](https://policies.google.com/terms)
* [Google Privacy Policy](https://policies.google.com/privacy)
* [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy)

== Screenshots ==

1. SEO Inventory table with completeness scores and P1–P3 priority lanes
2. Missing-field filters, post type labels, focus keyphrases, and quick edit panel
3. Settings — module toggles, Search Console OAuth, and property selection
4. Google Search Console — overview, insights, and performance tabs
5. GSC data sync, inventory Search Console metrics, and indexing actions

== Changelog ==

= 2.6.0 =
* **Abilities API** (WordPress 6.9+): category `forwp-seo-helper` with four abilities for AI agents / MCP:
* `forwp-seo-helper/list-missing-meta` — posts with no SEO title and description (`none`), one of them missing (`incomplete`) or any (`any`); shows the SEO plugin template value.
* `forwp-seo-helper/get-meta` — SEO meta of a post by ID or slug: raw (set manually) and effective (after templates).
* `forwp-seo-helper/update-meta` — set SEO title, meta description, focus keyword by ID or slug through the active SEO plugin (Yoast SEO / AIOSEO); returns previous values; logged in SEO history.
* `forwp-seo-helper/search` — find posts by title / slug or focus keyword.
* Abilities are registered only when the Abilities API exists and SEO Inventory is enabled. See `docs/ABILITIES.md`.

= 2.5.0 =
* **Cross posting** — enable under Settings; default delay (hours) after the original post is published.
* **Platforms** — manage share targets (Dev.to, daily.dev, Hashnode, Medium, X, and more) under 4WP SEO → Platforms.
* **Editor** — sidebar status per platform, manual send, and Telegram notify for the share list (4WP Drive `meta.json` packages).
* **Schedule** — WP-Cron after publish; per-package republish delay from `meta.json` when set.
* **Publication log** — REST + Chrome extension record live URL/slug on the post; Cross posting board in wp-admin.
* **Material** screen and REST board/payload/notify endpoints for the cross-post workflow.
* **4WP Drive** — TechArticle Steps wrap can span contiguous Step H2 sections (`span: multi`).

= 2.4.1 =
* **4WP Drive** — register TechArticle wraps for import mapping: Goal, Context, Steps, Common mistakes. Drive Patterns → Integrations Plugins lists this plugin when it is active. See [4WP Drive](https://wordpress.org/plugins/4wp-drive/).
* Heading aliases include Ukrainian seeds (Мета, Контекст, Кроки, Типові помилки); titles stay editable in Drive Patterns.

= 2.4.0 =
* **Positions:** 4WP SEO → Positions. Track SERP rank per query + country via Serper; Search Console property is the domain we look for.
* Dated organic snapshots (JSON); Update icon; depth Top 10 / 20 / 30 (extra pages only if the site is missing).
* Hover SERP titles; Ask / Suggest tooltip for People also ask and related searches. Related tab stores unique phrases with source query IDs.
* Settings → Position providers: Serper key, searchable country/language defaults.

= 2.3.0 =
* **Trends:** 4WP SEO → Trends. Upload Google Trends JSON, related-queries CSV, or time-series CSV (daily values stored as monthly averages). Key is query + region; new file adds a date snapshot (nothing overwritten). Language is a filter. Inventory column Trends shows latest interest and Δ for a matching focus keyphrase.

= 2.2.0 =
* AI Assistant: generate SEO title, meta description, and focus keyphrases via the standard WordPress AI Client (Settings → Connectors).
* Inventory Generate panel defaults to page content; page URL is the lighter-token option. Review before Apply/Update.
* Dashboard: configurable widgets (Screen Options columns, drag/hide) with 7 / 28 / 90 day Search Console ranges.
* Dynamics: list shows what changed (search clicks/impressions, content, score) and cleaner pagination.
* Inventory: CSV export icon on the tabs row; quieter header.

= 2.1.0 =
* Inventory: real pagination tied to Screen Options; type filter no longer collides with WP `post_type`.
* Inventory: persist excluded post types; two-column Quick Edit with GSC keyword suggestions.
* Dynamics: per-URL change list and detail (index, crawl, content, SEO, GSC); inventory row action opens it.
* Dashboard: weak-page and weekly-growth KPIs (change timeline lives under Dynamics).
* Admin bar: fewer items — page permalink, last indexed date, Search Console, inventory jump (works with pagination), Edit.

= 2.0.0 =
* **Major release:** full Google Search Console integration merged with SEO Inventory.
* GSC: OAuth connect/disconnect, property auto-match, local/staging manual picker, and property access guard.
* GSC: Overview, Insights, Performance, URL Inspection, and background data sync with optional daily WP-Cron.
* GSC: Inventory columns for clicks/impressions/queries, index status, and request-indexing actions; admin bar inspect shortcut.
* Inventory: focus keyphrases filter and quick edit; post type labels; smarter post type discovery (exclude internal CPTs only).
* GSC: clearer API error messages; Search Console user-access link when the connected account lacks property access.
* Security: GSC report range preference requires nonce + `manage_options`.

= 1.0.2 =
* Security: GSC date range preference saves only with nonce + manage_options (fixes CSRF on ReportPeriod range GET).

= 1.0.1 =
* Plugin Review fixes: enqueue inventory admin CSS, safe JSON-LD output, block render escaping, Google API disclosure in readme.

= 1.0.0 =
* Initial WordPress.org release: **SEO Inventory** admin table with filters, CSV export, and quick edit.
* **P1 / P2 / P3** drag-and-drop priority queue with configurable tier names.
* SEO adapters for **Yoast SEO** and **All in One SEO**.
* Multilingual support: Polylang, WPML, single-language.
* **REST API** (`forwp-seo-helper/v1/seo-inventory`) for external sync.
* Basic **Google Search Console** OAuth and property picker foundation.
* Future modules (TechArticle, LLMS.txt, cross posting) visible as Coming soon in admin.

== Upgrade Notice ==

= 2.5.0 =
Cross posting for 4WP Drive packages: Platforms list, publish delay, editor sidebar (manual send + Telegram), and publication log with live slug.

= 2.4.1 =
Registers TechArticle section wraps with 4WP Drive so imported documents can map Goal / Context / Steps / Common mistakes. https://wordpress.org/plugins/4wp-drive/

= 2.4.0 =
New Positions screen: Serper rank checks against your Search Console property, dated SERP snapshots, and People also ask / related searches.

= 2.3.0 =
New Trends screen: import Google Trends JSON (query + region snapshots). Inventory shows latest interest and Δ next to matching focus keyphrases.

= 2.2.0 =
AI Assistant via WordPress Connectors, configurable dashboard with GSC date ranges, and a Dynamics list that shows what changed.

= 2.1.0 =
Inventory pagination, exclude post types, dashboard history/KPIs, and a simpler admin bar with last-indexed date.

= 2.0.0 =
Major update: Google Search Console workspace, inventory GSC columns, property access guard, and indexing tools. Enable Search Console API in Google Cloud and verify site access after upgrading.

= 1.0.2 =
Security fix for GSC report range preference (nonce required before saving site option).

= 1.0.1 =
Plugin Review compliance fixes for CSS enqueue, JSON-LD escaping, and external services documentation.

= 1.0.0 =
Initial release — SEO Inventory and REST API for Yoast and All in One SEO sites.
