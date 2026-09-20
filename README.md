# 4WP SEO Helper

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg?style=flat-square)](https://wordpress.org/)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg?style=flat-square)](https://www.php.net/)

**Site-wide SEO Inventory, Google Search Console, Trends, and SERP Positions in wp-admin.** Audit titles, meta descriptions, and completeness; mark **P1–P3** business priority; sync Search Analytics; inspect URLs; track ranks via Serper. Optional **AI Assistant** generates SEO title, meta description, and focus keyphrases through the standard **WordPress AI Client / Connectors** — not a custom vendor SDK.

A plugin by **[4WP](https://4wp.dev/)** · Directory listing: [`readme.txt`](readme.txt) (canonical for WordPress.org)

Developer notes: **[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)**

## Version 2.4.1

- **4WP Drive** — TechArticle wraps for document import: Goal, Context, Steps, Common mistakes. Activate this plugin so Drive Patterns can list the wraps. [4WP Drive on WordPress.org](https://wordpress.org/plugins/4wp-drive/).

## Version 2.4.0

- **Positions:** query + country rank checks via Serper. Search Console property is the domain we look for. Dated organic snapshots, SERP title hover, Ask / Suggest (People also ask + related searches) with source query IDs.

## Version 2.3.0

- **Trends** submenu: Google Trends JSON, related-queries CSV, or time-series CSV (daily → monthly averages). Key = query + region. Snapshots by date, Δ. Inventory **Trends** column uses the latest snapshot.

## Version 2.2.0

- Configurable **Dashboard** (Screen Options layout, drag/hide widgets) with 7 / 28 / 90 day Search Console ranges
- **AI Assistant** submenu and inventory Generate panel, powered by **Settings → Connectors** (`wp_ai_client_prompt` / `wp_supports_ai`)
- **Dynamics** list shows *what changed* (search clicks/impressions, content, score)
- Inventory CSV export on the tabs row; quieter inventory header

## Features

- **4WP Drive** — TechArticle Goal / Context / Steps / Common mistakes on import ([4WP Drive](https://wordpress.org/plugins/4wp-drive/))
- **Trends** — Google Trends JSON, related-queries CSV, or time-series CSV (query + region snapshots)
- **Positions** — Serper SERP checks vs Search Console property; snapshots; People also ask / related searches
- **SEO Inventory** — filters, missing fields, CSV export, two-column Quick Edit
- **Priority queue** — P1 / P2 / P3 lanes (business priority, not SEO score)
- **Yoast SEO** and **All in One SEO** adapters
- **Google Search Console** — OAuth, overview, insights, performance, URL inspection, local sync
- **Indexing** — refresh status and request indexing from inventory or the admin bar
- **Dynamics** — per-URL history of index requests, crawls, content, SEO fields, and search metrics
- **AI Assistant** — WordPress AI Client connectors; generate title / description / keyphrases from page content (or URL)
- **REST API** — Sheets or custom dashboards
- **Multilingual** — Polylang, WPML, or single-language

## AI Assistant (WordPress Connectors)

Generation uses the **WordPress AI Client**. Configure a text-generation connector under **Settings → Connectors**. Until a connector is available, the inventory AI panel stays off and **4WP SEO → AI Assistant** explains how to enable it.

1. Install/enable **WordPress AI Client** (and a connector) so `wp_supports_ai()` is true.
2. Open **Settings → Connectors** and complete the connector for text generation.
3. In **SEO Inventory**, Quick Edit a row and **Generate with AI**. Default context is **page content** (plain text from WordPress, not a live crawl). **Page URL** is the lighter-token option.
4. Review SEO title, meta description, and focus keyphrases, then **Update**. Nothing is saved until you apply.

The plugin does not ship API keys or talk to a model until the site administrator has configured a connector.

## Admin menu

| Screen | Slug |
|--------|------|
| Dashboard | `forwp-seo` |
| SEO Inventory | `forwp-seo-inventory` |
| Dynamics | `forwp-seo-dynamics` |
| GSC | `forwp-seo-gsc` |
| Trends | `forwp-seo-trends` |
| Positions | `forwp-seo-positions` |
| AI Assistant | `forwp-seo-ai` |
| Settings | `forwp-seo-settings` |

## Requirements

- WordPress **6.0+**
- PHP **8.0+**
- **Yoast SEO** or **All in One SEO** (recommended for inventory read/write)
- Optional: Google Cloud OAuth + Search Console API
- Optional: Serper API key for Positions
- Optional: **WordPress AI Client** + a connector under **Settings → Connectors**

## License

GPL v2 or later. See [`4wp-seo-helper.php`](4wp-seo-helper.php).
