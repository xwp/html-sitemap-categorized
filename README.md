# HTML Sitemap Categorized

Category-based HTML sitemap pages with efficient caching and a bundled template.

## Requirements

- WordPress with pretty permalinks enabled
- Plugin activated in `wp-content/plugins/html-sitemap-categorized`

## Installation

1. Place the plugin in `wp-content/plugins/html-sitemap-categorized`.
2. Activate “HTML Sitemap Categorized” in wp-admin → Plugins.
3. Flush permalinks (see below).

### Using Composer

To install the plugin via Composer, follow these steps:

1. **Add the Repository:**
   - Open your project's `composer.json` file.
   - Add the following under the `repositories` section:

     ```json
     "repositories": [
         {
             "type": "vcs",
             "url": "https://github.com/xwp/html-sitemap-categorized"
         }
     ]
     ```

2. **Require the Plugin:**
   - Run the following command in your terminal:

     ```bash
     composer require xwp/html-sitemap-categorized
     ```

3. **Activate the Plugin:**
   - Once installed, activate the plugin through the 'Plugins' menu in WordPress.

## Usage

1. Create a new Page in wp-admin → Pages:
   - Title: Sitemap (or any title you prefer)
   - Permalink/Slug: `sitemap`
   - Publish the page
2. Flush permalinks:
   - Go to wp-admin → Settings → Permalinks → click “Save Changes”.
3. Visit `/sitemap/` to see the sitemap index.
4. Category pages are available at `/sitemap/<category-slug>-<page>/` (e.g., `/sitemap/news-1/`).

Notes:

- The plugin forces its own template for `/sitemap/` and matching category URLs.
- Breadcrumbs and pagination render automatically on category pages.

## Permalink flush (important)

If `/sitemap/` returns a 404 or does not use the sitemap template, flush permalinks:

- wp-admin → Settings → Permalinks → Save Changes

## Caching behavior

Uses WordPress object cache (`wp_cache_get`/`wp_cache_set`) with group `html_sitemap`:

- Category meta (counts/pages): ~24 hours
- Root sitemap HTML: ~6 hours
- Category page HTML: ~15 days

Automatic invalidation/regeneration on post save/status changes:

- Refreshes category meta
- Rebuilds the newest category sitemap page HTML
- Clears the root/index cache

To force a rebuild:

- Flush the object cache (e.g., `wp cache flush`) or clear entries in the `html_sitemap` group by `wp cache flush-group html-sitemap`.

## Cron

- Schedules single events on `html_sitemap_regenerate_category` after post changes.
- On plugin deactivation, scheduled jobs for this hook are cleared.

## Headers and performance

For sitemap requests, the plugin sends:

- `Cache-Control` with `s-maxage`, `stale-while-revalidate`, and `stale-if-error`
- `Surrogate-Key` headers (`sitemap-index` and `sitemap-cat-<slug>`) for targeted CDN purges

## Styling

- Stylesheet is loaded only for sitemap URLs: `assets/sitemap-html.css` (handle: `html-sitemap-categorized`).
- Customize by adding theme styles or dequeuing the handle and enqueuing your own stylesheet.

## Troubleshooting

- 404 on `/sitemap/` or category URLs: flush permalinks.
- Empty sitemap: ensure the Sitemap page exists (slug `sitemap`) and you have published posts with categories.
- Styles not applied: ensure the plugin is active and the `html-sitemap-categorized` style is not dequeued.

## Deactivation

- Deactivation clears scheduled regeneration cron events.
- Cached entries in the `html_sitemap` group expire naturally or can be flushed.
