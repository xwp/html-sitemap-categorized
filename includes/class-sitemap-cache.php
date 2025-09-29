<?php
namespace HtmlSitemapCategorized;

/**
 * HTML Sitemap Cache Management (newest-first, 500 per page)
 *
 * Overview
 * - This class provides a multi-layer, in-memory (object cache) strategy to serve
 *   HTML sitemaps efficiently while reflecting newest-first ordering.
 * - Page 1 contains the newest posts. Adding a new post shifts all subsequent pages.
 *
 * Cache layers and keys (group: html_sitemap)
 * - all_ids:{slug}: Complete ordered list of post IDs for a category, newest → oldest.
 *   Source of truth for slicing; cached indefinitely and explicitly invalidated.
 * - meta:{slug}: Derived metadata: total_posts, total_pages, last_build.
 * - ids:{slug}:{page}: Page slice of IDs (size = POSTS_PER_PAGE = 500) from all_ids.
 * - posts:{slug}:{page}: Array of post rows for the page IDs, normalized shape.
 * - html:{slug}:{page}: Pre-rendered HTML fragment for a category page.
 * - categories: Pre-built listing of category pages for the root sitemap view.
 * - root_html: Pre-rendered HTML for the root sitemap page (30 days TTL, also invalidated).
 *
 * Ordering
 * - all_ids is fetched with ORDER BY post_date DESC, ID DESC (ID tie-breaker is applied
 *   in SQL for stability). Page slices derive from this list; the fetch then reorders
 *   results to match the slice order.
 *
 * Invalidation (if a post gets publshed/unpublished)
 * - invalidate_category_cache(slug) deletes:
 *   all_ids, meta, categories, root_html; and for each page up to a safe bound:
 *   ids:{slug}:{page}, posts:{slug}:{page}, html:{slug}:{page}.
 * - This ensures no page-level stale content survives after a mutation.
 *
 * Regeneration & warmup
 * - schedule_category_regeneration(slug): queues a single category job with a 60s debounce.
 * - regenerate_category_cache(slug):
 *   1) invalidates caches for the category;
 *   2) rebuilds all_ids and meta;
 *   3) warms pages 1–5 right away within the job (ids, posts, html);
 *   4) schedules one deferred job (1h) to warm remaining pages (warm_category_old_pages).
 * - warm_category_old_pages(slug, start_page): warms pages start_page..N in-process.
 *
 * Root page caching
 * - get_root_sitemap_html() uses a TTL 24 hours and is also invalidated on publish.
 *
 * Notes
 * - POSTS_PER_PAGE is 500 per requirements.
 * - Edge caching is configured by the HTML plugin outer class via Cache-Control headers
 *   (s-maxage + stale-while-revalidate/stale-if-error). This class handles only object caching.
 */
class Sitemap_Cache {
	/**
	 * Cache group for all HTML sitemap caches.
	 */
	const CACHE_GROUP = 'html_sitemap';

	/**
	 * Number of posts per sitemap page.
	 */
	const POSTS_PER_PAGE = 500;

	/**
	 * Action for regenerating category cache after post changes.
	 */
	const ACTION_REGENERATE_CATEGORY = 'html_sitemap_regenerate_category';

	/**
	 * Action for warming remaining category pages in a single deferred job.
	 */
	const ACTION_WARM_CATEGORY_OLD = 'html_sitemap_warm_category_old_pages';

	/**
	 * Initialize cache management.
	 */
	public function __construct() {
		// Register background regeneration action.
		add_action( self::ACTION_REGENERATE_CATEGORY, [ $this, 'regenerate_category_cache' ], 10, 1 );
		add_action( self::ACTION_WARM_CATEGORY_OLD, [ $this, 'warm_category_old_pages' ], 10, 2 );
	}

	/**
	 * Get post IDs for a specific category page (chunked for O(1) access).
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return array<int> Array of post IDs for this page.
	 */
	public function get_category_page_ids( string $category_slug, int $page ): array {
		$cache_key = "ids:{$category_slug}:{$page}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// If page cache miss, rebuild from full list.
		$all_ids = $this->get_all_category_post_ids( $category_slug );

		// Calculate pagination.
		$offset   = ( $page - 1 ) * self::POSTS_PER_PAGE;
		$page_ids = array_slice( $all_ids, $offset, self::POSTS_PER_PAGE );

		// Cache this page's IDs indefinitely.
		wp_cache_set( $cache_key, $page_ids, self::CACHE_GROUP );

		return $page_ids;
	}

	/**
	 * Get all post IDs for a category (used for chunking).
	 *
	 * @param string $category_slug The category slug.
	 * @return array<int> Array of all post IDs ordered by date DESC.
	 */
	protected function get_all_category_post_ids( string $category_slug ): array {
		$cache_key = "all_ids:{$category_slug}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE p.post_status = 'publish'
				AND p.post_type = 'post'
				AND tt.taxonomy = 'category'
				AND t.slug = %s
				ORDER BY p.post_date DESC, p.ID DESC",
				$category_slug
			)
		);

		$post_ids = array_map( 'intval', $post_ids );

		// Cache indefinitely - will be purged when posts change.
		wp_cache_set( $cache_key, $post_ids, self::CACHE_GROUP );

		return $post_ids;
	}

	/**
	 * Get category metadata (total pages, etc.).
	 *
	 * @param string $category_slug The category slug.
	 * @return array<string, mixed> Category metadata.
	 */
	public function get_category_meta( string $category_slug ): array {
		$cache_key = "meta:{$category_slug}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$all_ids     = $this->get_all_category_post_ids( $category_slug );
		$total_posts = count( $all_ids );
		$total_pages = (int) ceil( $total_posts / self::POSTS_PER_PAGE );

		$meta = [
			'total_posts' => $total_posts,
			'total_pages' => $total_pages,
			'last_build'  => time(),
		];

		wp_cache_set( $cache_key, $meta, self::CACHE_GROUP );

		return $meta;
	}

	/**
	 * Get all categories with published posts for HTML sitemap.
	 *
	 * @return array<array<string, mixed>> Array of category data.
	 */
	public function get_categories(): array {
		$cache_key = 'categories';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		// Get category data with post counts.
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"SELECT
				t.slug as category_slug,
				t.name as category_name,
				COUNT(p.ID) as post_count
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			WHERE tt.taxonomy = 'category'
			AND p.post_status = 'publish'
			AND p.post_type = 'post'
			GROUP BY t.term_id, t.slug, t.name
			HAVING post_count > 0
			ORDER BY t.name ASC"
		);

		$categories = [];
		foreach ( $results as $result ) {
			$post_count  = (int) $result->post_count;
			$total_pages = (int) ceil( $post_count / self::POSTS_PER_PAGE );

			// Add entries for each page if category has more than POSTS_PER_PAGE posts.
			for ( $page = 1; $page <= $total_pages; $page++ ) {
				$categories[] = [
					'slug'           => $result->category_slug,
					'name'           => $result->category_name,
					'post_count'     => $post_count,
					'page'           => $page,
					'total_pages'    => $total_pages,
					'posts_per_page' => self::POSTS_PER_PAGE,
					'url_suffix'     => "-{$page}", // Always include page number.
				];
			}
		}

		// Categories change rarely; also invalidated on post changes.
		wp_cache_set( $cache_key, $categories, self::CACHE_GROUP, DAY_IN_SECONDS );

		return $categories;
	}

	/**
	 * Get posts for a specific category and page using efficient chunked ID approach.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return array<array<string, mixed>> Array of post data.
	 */
	public function get_category_posts( string $category_slug, int $page = 1 ): array {
		$cache_key = "posts:{$category_slug}:{$page}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		// Get post IDs for this specific page (O(1) access).
		$page_ids = $this->get_category_page_ids( $category_slug, $page );

		if ( empty( $page_ids ) ) {
			return [];
		}

		// Bulk fetch post data for this page's IDs.
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$sql          = "SELECT ID, post_title, post_name, post_date, post_modified FROM {$wpdb->posts} WHERE ID IN ($placeholders)";
		$posts        = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( $sql, $page_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		/**
		 * Reorder in PHP.
		 * This reduces SQL complexity and avoids very large ORDER BY clauses.
		 */
		$pos = array_flip( array_map( 'intval', $page_ids ) );

		usort(
			$posts,
			static function ( $a, $b ) use ( $pos ) {
				$ia = isset( $pos[ (int) $a->ID ] ) ? $pos[ (int) $a->ID ] : PHP_INT_MAX;
				$ib = isset( $pos[ (int) $b->ID ] ) ? $pos[ (int) $b->ID ] : PHP_INT_MAX;

				if ( $ia === $ib ) {
					return 0;
				}

				return ( $ia < $ib ) ? -1 : 1;
			}
		);

		// Convert to expected format.
		$formatted_posts = array_map(
			static function ( $post ) {
				return [
					'ID'            => (int) $post->ID,
					'post_title'    => $post->post_title,
					'post_name'     => $post->post_name,
					'post_date'     => $post->post_date,
					'post_modified' => $post->post_modified,
				];
			},
			$posts
		);

		wp_cache_set( $cache_key, $formatted_posts, self::CACHE_GROUP );
		return $formatted_posts;
	}

	/**
	 * Get pre-rendered HTML for a category page.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return string|false Pre-rendered HTML or false if not cached.
	 */
	public function get_cached_category_html( string $category_slug, int $page ): string|false {
		$cache_key = "html:{$category_slug}:{$page}";
		return wp_cache_get( $cache_key, self::CACHE_GROUP );
	}

	/**
	 * Store pre-rendered HTML for a category page.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @param string $html          The rendered HTML.
	 */
	public function set_cached_category_html( string $category_slug, int $page, string $html ): void {
		$cache_key = "html:{$category_slug}:{$page}";
		// Cache indefinitely - will be purged when posts change.
		wp_cache_set( $cache_key, $html, self::CACHE_GROUP );
	}

	/**
	 * Get pre-rendered HTML for a category page, or generate it if not cached.
	 * Uses the actual page template to ensure consistency.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return string The rendered HTML content.
	 */
	public function get_category_html( string $category_slug, int $page = 1 ): string {
		// Try to get pre-rendered HTML from cache first.
		$cached_html = $this->get_cached_category_html( $category_slug, $page );
		if ( false !== $cached_html ) {
			return $cached_html;
		}

		// Generate HTML using the actual template.
		$html = $this->render_with_template( $category_slug, $page );

		// Cache the generated HTML for next time.
		$this->set_cached_category_html( $category_slug, $page, $html );

		return $html;
	}

	/**
	 * Get pre-rendered HTML for the root sitemap page, or generate it if not cached.
	 *
	 * @return string The rendered HTML content.
	 */
	public function get_root_sitemap_html(): string {
		$cache_key   = 'root_html';
		$cached_html = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached_html ) {
			return $cached_html;
		}

		// Generate HTML using the actual template.
		$html = $this->render_with_template( null, 1 );

		// Cache for 30 days; also explicitly invalidated on publish/unpublish.
		wp_cache_set( $cache_key, $html, self::CACHE_GROUP, MONTH_IN_SECONDS );

		return $html;
	}

	/**
	 * Render HTML using the actual page template with ob_start() capture.
	 * This ensures we have a single source of truth for HTML generation.
	 *
	 * @param string|null $category_slug The category slug (null for root).
	 * @param int         $page          The page number.
	 * @return string The rendered HTML content.
	 */
	protected function render_with_template( ?string $category_slug, int $page ): string {
		// Set up query vars to simulate the request.
		global $wp_query;
		$original_query_vars = $wp_query->query_vars;

		// Temporarily set query vars for template.
		if ( ! empty( $category_slug ) ) {
			$wp_query->set( 'sitemap_category', $category_slug );
			$wp_query->set( 'sitemap_page', $page );
		} else {
			$wp_query->set( 'sitemap_category', '' );
			$wp_query->set( 'sitemap_page', 1 );
		}

		// Build links using the same data providers the template uses, then render via plugin helper.
		$plugin        = html_sitemap_categorized();
		$template_data = $plugin->get_template_data();
		$links         = $template_data['links'] ?? [];
		$html          = $plugin->render_fragment( $links );

		// Restore original query vars.
		$wp_query->query_vars = $original_query_vars;

		return $html;
	}

	/**
	 * Invalidate all cache for a specific category.
	 *
	 * @param string $category_slug The category slug.
	 */
	public function invalidate_category_cache( string $category_slug ): void {
		// Get page count from metadata for targeted invalidation.
		$meta       = wp_cache_get( "meta:{$category_slug}", self::CACHE_GROUP );
		$page_count = 100; // Fallback safety bound; current upper bound ~78 pages.

		if ( false !== $meta && isset( $meta['total_pages'] ) ) {
			$page_count = ( (int) $meta['total_pages'] ) + 2; // +2 margin.
		}

		// Clear all cache keys for this category.
		wp_cache_delete( "all_ids:{$category_slug}", self::CACHE_GROUP );
		wp_cache_delete( "meta:{$category_slug}", self::CACHE_GROUP );

		// Clear chunked ID caches and HTML fragments for each page.
		for ( $page = 1; $page <= $page_count; $page++ ) {
			wp_cache_delete( "ids:{$category_slug}:{$page}", self::CACHE_GROUP );
			wp_cache_delete( "posts:{$category_slug}:{$page}", self::CACHE_GROUP );
			wp_cache_delete( "html:{$category_slug}:{$page}", self::CACHE_GROUP );
		}

		// Clear categories cache since post counts may have changed.
		wp_cache_delete( 'categories', self::CACHE_GROUP );

		// Clear root sitemap HTML cache since category list may have changed.
		wp_cache_delete( 'root_html', self::CACHE_GROUP );
	}

	/**
	 * Schedule background regeneration for a category.
	 *
	 * @param string $category_slug The category slug.
	 */
	public function schedule_category_regeneration( string $category_slug ): void {
		// Schedule background job to regenerate this category's cache.
		// wp_schedule_single_event ignores duplicate events with same hook and args.
		// +60 seconds debounce to better coalesce multiple edits/publishes.
		wp_schedule_single_event( time() + 60, self::ACTION_REGENERATE_CATEGORY, [ $category_slug ] );
	}

	/**
	 * Background job to regenerate cache for a specific category.
	 * This method invalidates old cache and rebuilds it, ensuring users get fresh data.
	 *
	 * @param string $category_slug The category slug.
	 */
	public function regenerate_category_cache( string $category_slug ): void {
		// Step 1: Invalidate old cache (now that we're ready to rebuild).
		$this->invalidate_category_cache( $category_slug );

		// Step 2: Regenerate all post IDs and metadata.
		$this->get_all_category_post_ids( $category_slug );
		$meta        = $this->get_category_meta( $category_slug );
		$total_pages = (int) ( $meta['total_pages'] ?? 0 );

		if ( $total_pages < 1 ) {
			return;
		}

		// Step 3: Warm first 5 pages eagerly within this scheduled job.
		$fast_pages = min( 5, $total_pages );
		for ( $page = 1; $page <= $fast_pages; $page++ ) {
			$this->regenerate_category_page_cache( $category_slug, (int) $page );
		}

		// Step 4: Schedule a single deferred job to warm the remaining pages in 1 hour.
		if ( $total_pages > $fast_pages ) {
			wp_schedule_single_event( time() + 3600, self::ACTION_WARM_CATEGORY_OLD, [ $category_slug, (int) ( $fast_pages + 1 ) ] );
		}
	}

	/**
	 * Warm remaining pages for a category starting from a specific page.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $start_page    The page number to start warming from.
	 */
	public function warm_category_old_pages( string $category_slug, int $start_page ): void {
		$meta        = $this->get_category_meta( $category_slug );
		$total_pages = (int) ( $meta['total_pages'] ?? 0 );
		$start       = max( 1, (int) $start_page );

		if ( $total_pages < 1 || $start > $total_pages ) {
			return;
		}

		// Ensure IDs list is built.
		$this->get_all_category_post_ids( $category_slug );

		for ( $page = $start; $page <= $total_pages; $page++ ) {
			$this->regenerate_category_page_cache( $category_slug, (int) $page );
		}
	}

	/**
	 * Regenerate cache for a specific category page: rebuild IDs slice and HTML.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 */
	public function regenerate_category_page_cache( string $category_slug, int $page ): void {
		// Ensure IDs are available and regen page slice.
		$this->get_all_category_post_ids( $category_slug );
		$this->get_category_page_ids( $category_slug, (int) $page );

		// Rebuild HTML for the page.
		$html = $this->render_with_template( $category_slug, (int) $page );
		$this->set_cached_category_html( $category_slug, (int) $page, $html );
	}
}
