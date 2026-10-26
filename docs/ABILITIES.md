# Abilities (WordPress Abilities API)

Since **2.6.0**. Requires WordPress **6.9+** (Abilities API) and an enabled **SEO Inventory**. Abilities are registered in `includes/Abilities/Module.php`.

For AI clients (Claude Desktop, other MCP clients) enable them in **4WP MCP Connector** (Settings → 4WP MCP). MCP tool names replace `/` with `-`, e.g. `forwp-seo-helper-get-meta`.

- Category: `forwp-seo-helper`.
- Data source: active SEO adapter (`SeoMeta\Registry::get_active()`): Yoast SEO or All in One SEO. Without one → error `no_seo_adapter`.
- Scope: published posts of the SEO Inventory post types (`PostTypeDiscovery`).
- Permissions: read — `edit_posts`; update — `edit_posts` + `edit_post` on the target.

## `forwp-seo-helper/list-missing-meta`

Read-only. Posts whose SEO title and/or meta description are not set manually.

| Input | Type | Notes |
|---|---|---|
| `filter` | `none` \| `incomplete` \| `any` | `none` — both missing; `incomplete` — exactly one; `any` (default) — at least one |
| `post_type` | string | Optional |
| `page`, `per_page` | integer | Default 1 / 20, `per_page` ≤ 100 |

Output: `items[]`, `total`, `page`, `per_page`, `seo_plugin`. Item: `id`, `title`, `slug`, `post_type`, `url`, `edit_url`, `seo_title`, `seo_title_is_template`, `seo_description`, `seo_description_is_template`, `focus_keyword`, `missing[]` (`title`, `description`, `focus_keyword`, `og_image`).

`seo_title` / `seo_description` — what the SEO plugin outputs (Yoast template resolved when the field is empty).

```json
{"filter": "none", "post_type": "page", "per_page": 10}
```

## `forwp-seo-helper/get-meta`

Read-only. One post by `id` or `slug` (+ optional `post_type`).

Output: `id`, `title`, `slug`, `post_type`, `url`, `edit_url`, `seo_plugin`, `raw` (`title`, `description`, `focus_keyword`, `canonical`, `noindex` — set manually), `effective` (`title`, `description` — after templates), `missing[]`.

```json
{"slug": "about", "post_type": "page"}
```

## `forwp-seo-helper/update-meta`

Write; live immediately (SEO meta are not drafted). Annotations: not read-only, not destructive, idempotent.

| Input | Type | Notes |
|---|---|---|
| `id` or `slug` (+ `post_type`) | | Target |
| `title` | string | SEO title; Yoast variables allowed |
| `description` | string | Meta description |
| `focus_keyword` | string | Primary focus keyphrase |

At least one field. Empty string clears the field (SEO plugin falls back to its template). Writes through `Inventory\BulkUpdater` → adapter `write()`; recorded by `HistoryLogger`.

Output: `id`, `seo_plugin`, `before`, `after` (same shape as `raw`), `edit_url`. Use `before` to roll back.

```json
{"id": 123, "description": "Short, specific description of the page, 120–160 characters."}
```

## `forwp-seo-helper/search`

Read-only. `query` (title words or exact slug) and/or `keyword` (part of focus keyphrases); `post_type`; `limit` ≤ 50 (default 20). Exact slug match comes first.

Output: `items[]` (same item as `list-missing-meta`), `seo_plugin`.

```json
{"keyword": "wordpress mcp"}
```

## Errors

| Code | When |
|---|---|
| `no_seo_adapter` | No Yoast SEO / AIOSEO active |
| `no_target` | Neither `id` nor `slug` |
| `not_found` | Post not found |
| `out_of_scope` | Post type not in SEO Inventory |
| `no_fields` | `update-meta` without fields |
| `no_query` | `search` without `query` and `keyword` |
| `update_failed` | Adapter write failed or insufficient permissions |
