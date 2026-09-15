# 4WP SEO Helper — developer docs

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg?style=flat-square)](https://wordpress.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg?style=flat-square)](https://www.php.net/)

**Site-wide SEO Inventory for WordPress.** Audit titles, meta descriptions, and completeness scores across posts and pages—then prioritize what matters with drag-and-drop P1–P3 lanes. Works alongside **Yoast SEO** and **All in One SEO**.

A plugin by **[4WP](https://4wp.dev/)**

> WordPress.org listing uses **[readme.txt](../readme.txt)** in the plugin root (not this file).

## Features (v2.2.0)

- **SEO Inventory table** — filters, missing-field views, CSV export, quick edit
- **Priority queue** — P1 / P2 / P3 business tiers (names configurable in Settings)
- **Drag-and-drop** — reorder and move items between priority groups without reload
- **SEO plugin adapters** — Yoast SEO, All in One SEO, safe fallback
- **Multilingual** — Polylang, WPML, or single-language sites
- **REST API** — sync with Google Sheets or external dashboards (`google-sheets-sync.gs`)
- **Post type discovery** — public CPTs with `show_ui`; exclude via filter
- **AI Assistant** — WordPress AI Client (`Settings → Connectors`); inventory Generate for title / description / keyphrases
- **Dashboard & Dynamics** — configurable widgets, 7/28/90d GSC ranges; change list with pagination

### Coming soon (in codebase)

TechArticle schema & blocks, LLMS.txt, and cross posting ship in future releases. They appear as **Coming soon** in wp-admin.

**Google Search Console** is enabled (`Release::MODULE_GSC` in public modules).

## How it works

1. Activate the plugin (requires Yoast or AIOSEO for full read/write).
2. Open **4WP SEO → SEO Inventory**.
3. Review completeness scores and missing SEO fields site-wide.
4. Drag rows into **P1 / P2 / P3** to mark business priority (independent of score).
5. Optional: enable the REST API under **Settings** and connect Sheets or your dashboard.
6. Optional: **Settings → Connectors** for AI SEO copy (WordPress AI Client).

Priority reflects **business importance** (e.g. main service pages), not SEO score—a page can stay in P1 at 100%.

## Install

| Source | Notes |
|--------|--------|
| **WordPress.org** | Search for *4WP SEO Helper* (v1.0.0 — SEO Inventory only). |
| **From source** | Copy into `wp-content/plugins/4wp-seo-helper` and activate. |

```bash
cd wp-content/plugins
git clone <your-repo-url> 4wp-seo-helper
```

On activation, the inventory API token is created and the module is enabled by default.

## Requirements

- WordPress **6.0+**
- PHP **8.0+**
- **Yoast SEO** or **All in One SEO** (recommended for inventory read/write)
- Optional: WordPress AI Client + a connector under **Settings → Connectors**

## Admin

| Screen | Path |
|--------|------|
| Dashboard | **4WP SEO** (`forwp-seo`) |
| Inventory table | **4WP SEO → SEO Inventory** (`forwp-seo-inventory`) |
| Dynamics | **4WP SEO → Dynamics** (`forwp-seo-dynamics`) |
| Search Console | **4WP SEO → GSC** (`forwp-seo-gsc`) |
| AI Assistant | **4WP SEO → AI Assistant** (`forwp-seo-ai`) |
| Settings / Inventory API | **4WP SEO → Settings** (`forwp-seo-settings`) |

## REST API

Base URL: `/wp-json/forwp-seo-helper/v1/seo-inventory`

| Route | Description |
|-------|-------------|
| `GET /meta` | API contract, fields, version |
| `GET /items` | Paginated inventory (Bearer token or `manage_options`) |
| `PUT /priority-queue` | Save P1–P3 lane order (logged-in admin) |

Auth header: `Authorization: Bearer <token>` — token under **4WP SEO → Inventory API**.

Sample Google Apps Script: [`google-sheets-sync.gs`](google-sheets-sync.gs).

## For developers

- **Namespace:** `Forwp\SeoHelper`
- **Text domain:** `4wp-seo-helper`
- **Release scope:** `includes/Core/Release.php` — public modules for wp.org 1.0.0 are `inventory` and `inventory_api` only.

### Enable all modules (staging / internal)

```php
add_filter( 'forwp_seo_public_modules', function () {
	return [
		\Forwp\SeoHelper\Core\Release::MODULE_INVENTORY,
		\Forwp\SeoHelper\Core\Release::MODULE_INVENTORY_API,
		\Forwp\SeoHelper\Core\Release::MODULE_TECHARTICLE,
		\Forwp\SeoHelper\Core\Release::MODULE_GSC,
		\Forwp\SeoHelper\Core\Release::MODULE_LLMS,
		\Forwp\SeoHelper\Core\Release::MODULE_CROSSPOSTING,
	];
} );
```

### Filters

```php
add_filter(
	'forwp_seo_inventory_exclude_post_types',
	fn( array $types ) => array_merge( $types, [ 'shop_order' ] )
);

add_filter(
	'forwp_seo_inventory_cors_origins',
	fn() => [ 'https://script.google.com' ]
);
```

### Focus keyphrases

Inventory stores **`focus_keyphrases: string[]`** in post meta `_forwp_seo_focus_keyphrases` (newline-separated).

| Rule | Value |
| --- | --- |
| **UI** | Quick Edit `<textarea>` — one phrase per line |
| **Primary** | first line → Yoast `_yoast_wpseo_focuskw` on save |
| **API** | `focus_keyphrases` (string or array) or legacy `focus_keyword` |
| **Why** | GSC returns dozens of queries per URL; one Yoast field is not enough |

Spec: [seo-metadata.md](../../../../docs/seo-metadata.md) (4wp.dev site docs).

## License

GPL v2 or later. See [`4wp-seo-helper.php`](../4wp-seo-helper.php).
