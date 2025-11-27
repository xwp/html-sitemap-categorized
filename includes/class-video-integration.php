<?php
/**
 * Video CPT integration for HTML sitemap.
 * Provides two-level navigation: Videos Index → Video Categories → Individual Videos
 *
 * @package HtmlSitemapCategorized
 */

namespace HtmlSitemapCategorized;

/**
 * Class Video_Integration
 *
 * Helper class that provides video-specific functionality.
 * Methods are called directly by HTML_Sitemap, no filters needed.
 */
class Video_Integration {

	/**
	 * Track whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $hooks_registered = false;

	/**
	 * Video post type name.
	 *
	 * @var string
	 */
	private string $post_type_name;

	/**
	 * Video taxonomy name.
	 *
	 * @var string
	 */
	private string $taxonomy_name;

	/**
	 * Sitemap slug prefix for videos.
	 *
	 * @var string
	 */
	private const SITEMAP_PREFIX = 'videos';

	/**
	 * Initialize video integration.
	 *
	 * @return void
	 */
	public function __construct() {
		// Set names via filters.
		$this->post_type_name = apply_filters( 'html_sitemap_video_post_type', 'videos' );
		$this->taxonomy_name  = apply_filters( 'html_sitemap_video_taxonomy', 'video_category' );

		// Add rewrite rule early (CPT not needed for this).
		add_action( 'init', [ $this, 'add_rewrite_rules' ], 1 );

		// Defer filter registration until after CPTs are usually registered.
		add_action( 'init', [ $this, 'maybe_register_filters' ], 20 );
	}

	/**
	 * Register filters once, after CPT/taxonomy exist.
	 *
	 * @return void
	 */
	public function maybe_register_filters(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->register_filters();
		$this->hooks_registered = true;
	}

	/**
	 * Register filters.
	 *
	 * @return void
	 */
	private function register_filters(): void {
		add_filter( 'html_sitemap_categories', [ $this, 'filter_root_categories' ], 999, 2 );
		add_filter( 'html_sitemap_use_root_template', [ $this, 'use_root_template_for_videos' ], 10, 2 );
		add_filter( 'html_sitemap_breadcrumbs', [ $this, 'filter_breadcrumbs' ], 10, 3 );
		add_filter( 'html_sitemap_category_all_ids', [ $this, 'filter_all_ids' ], 10, 2 );
		add_filter( 'html_sitemap_category_meta', [ $this, 'filter_meta' ], 10, 2 );
		add_filter( 'html_sitemap_category_posts', [ $this, 'filter_category_posts' ], 10, 4 );
		add_filter( 'html_sitemap_category_name', [ $this, 'filter_category_name' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'handle_post_status_change' ], 10, 3 );
		add_filter(
			'html_sitemap_categories_cache_key',
			[ $this, 'filter_categories_cache_key' ],
			10,
			2
		);
	}

	/**
	 * Check if videos CPT and taxonomy are available.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return post_type_exists( $this->post_type_name ) && taxonomy_exists( $this->taxonomy_name );
	}

	/**
	 * Add rewrite rule for the videos sitemap index.
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^sitemap/videos/?$',
			'index.php?sitemap_category=videos&sitemap_page=1',
			'top'
		);
	}

	/**
	 * Filter: Use a dedicated categories cache key for the videos index.
	 *
	 * @param string      $cache_key     Default cache key.
	 * @param string|null $category_slug Current sitemap_category.
	 *
	 * @return string
	 */
	public function filter_categories_cache_key( string $cache_key, ?string $category_slug ): string {
		if ( 'videos' === $category_slug ) {
			return 'categories:videos';
		}

		return $cache_key;
	}

	/**
	 * Filter: Tell sitemap to use root template for videos index.
	 *
	 * @param bool   $use_root      Whether to use the root template.
	 * @param string $category_slug Current sitemap category slug.
	 *
	 * @return bool
	 */
	public function use_root_template_for_videos( bool $use_root, string $category_slug ): bool {
		if ( self::SITEMAP_PREFIX === $category_slug ) {
			return true;
		}

		return $use_root;
	}

	/**
	 * Filter: Add intermediate "Videos" breadcrumb.
	 *
	 * @param array<int, mixed> $breadcrumbs   Existing breadcrumbs.
	 * @param string $category_slug Current sitemap category slug.
	 * @param int    $page          Current sitemap page.
	 *
	 * @return array<int, mixed>
	 */
	public function filter_breadcrumbs( array $breadcrumbs, string $category_slug, int $page ): array {
		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $breadcrumbs;
		}

		// Insert "Videos" link.
		array_splice(
			$breadcrumbs,
			1,
			0,
			[
				[
					'link'     => home_url( '/sitemap/videos-1/' ),
					'label'    => __( 'Videos', 'html-sitemap-categorized' ),
					'position' => 2,
				],
			]
		);

		// Update positions.
		foreach ( $breadcrumbs as $index => &$crumb ) {
			$crumb['position'] = $index + 1;
		}

		return $breadcrumbs;
	}

	/**
	 * Provide video categories for the sitemap.
	 *
	 * @param array<int, mixed> $categories Existing sitemap categories.
	 * @param array<int, string> $post_types Post types included in the sitemap.
	 *
	 * @return array<int, mixed>
	 */
	public function filter_root_categories( array $categories, array $post_types ): array {
		$video_terms = get_terms(
			[
				'taxonomy'   => $this->taxonomy_name,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $video_terms ) || empty( $video_terms ) ) {
			return $categories;
		}

		$current_category = get_query_var( 'sitemap_category' );
		$is_videos_index  = self::SITEMAP_PREFIX === $current_category;

		if ( $is_videos_index ) {
			return $this->add_video_category_entries( [], $video_terms );
		} else {
			return $this->add_videos_index_entry( $categories, $video_terms );
		}
	}

	/**
	 * Add single "Videos" entry to main sitemap.
	 *
	 * @param array<int, mixed> $categories Existing sitemap categories.
	 * @param array<int, \WP_Term> $video_terms Video terms as returned by get_terms().
	 *
	 * @return array<int, mixed>
	 */
	private function add_videos_index_entry( array $categories, array $video_terms ): array {
		$total_count = 0;
		foreach ( $video_terms as $term ) {
			$total_count += (int) $term->count;
		}

		if ( $total_count <= 0 ) {
			return $categories;
		}

		$categories[] = [
			'slug'           => self::SITEMAP_PREFIX,
			'name'           => __( 'Videos', 'html-sitemap-categorized' ),
			'post_count'     => $total_count,
			'page'           => 1,
			'total_pages'    => 1,
			'posts_per_page' => Sitemap_Cache::POSTS_PER_PAGE,
			'url_suffix'     => '',
		];

		return $categories;
	}

	/**
	 * Add all video categories to videos index.
	 *
	 * @param array<int, mixed> $categories  Existing sitemap categories.
	 * @param array<int, \WP_Term> $video_terms Video terms as returned by get_terms().
	 *
	 * @return array<int, mixed>
	 */
	private function add_video_category_entries( array $categories, array $video_terms ): array {
		foreach ( $video_terms as $term ) {
			$post_count = (int) $term->count;

			if ( $post_count <= 0 ) {
				continue;
			}

			$total_pages = (int) ceil( $post_count / Sitemap_Cache::POSTS_PER_PAGE );
			$slug        = $this->build_category_slug( $term->slug );

			for ( $page = 1; $page <= $total_pages; $page++ ) {
				$categories[] = [
					'slug'           => $slug,
					'name'           => sprintf(
						/* translators: %s: video category name. */
						__( 'Videos: %s', 'html-sitemap-categorized' ),
						$term->name
					),
					'post_count'     => $post_count,
					'page'           => $page,
					'total_pages'    => $total_pages,
					'posts_per_page' => Sitemap_Cache::POSTS_PER_PAGE,
					'url_suffix'     => '-' . $page,
				];
			}
		}

		return $categories;
	}

	/**
	 * Supply all IDs for a video category.
	 *
	 * @param mixed  $ids           Original IDs value.
	 * @param string $category_slug Sitemap category slug.
	 *
	 * @return mixed IDs list or original value.
	 */
	public function filter_all_ids( $ids, string $category_slug ) {
		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $ids;
		}

		return $this->get_video_category_post_ids( $term_slug );
	}

	/**
	 * Supply meta data for a video category.
	 *
	 * @param mixed  $meta          Original meta value.
	 * @param string $category_slug Sitemap category slug.
	 *
	 * @return mixed Meta array or original value.
	 */
	public function filter_meta( $meta, string $category_slug ) {
		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $meta;
		}

		$post_ids    = $this->get_video_category_post_ids( $term_slug );
		$total_posts = count( $post_ids );
		$total_pages = (int) ceil( $total_posts / Sitemap_Cache::POSTS_PER_PAGE );

		return [
			'total_posts' => $total_posts,
			'total_pages' => $total_pages,
			'last_build'  => time(),
		];
	}

	/**
	 * Provide paginated video posts for video category pages.
	 *
	 * @param mixed  $posts         Original posts value.
	 * @param string $category_slug Sitemap category slug.
	 * @param int    $page          Current sitemap page.
	 * @param string $stage         Current processing stage.
	 *
	 * @return mixed Posts array or original value.
	 */
	public function filter_category_posts( $posts, string $category_slug, int $page, string $stage ) {
		if ( 'pre' !== $stage ) {
			return $posts;
		}

		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $posts;
		}

		$page     = max( 1, $page );
		$post_ids = $this->get_video_category_post_ids( $term_slug );

		if ( empty( $post_ids ) ) {
			return [];
		}

		$offset   = ( $page - 1 ) * Sitemap_Cache::POSTS_PER_PAGE;
		$page_ids = array_slice( $post_ids, $offset, Sitemap_Cache::POSTS_PER_PAGE );

		if ( empty( $page_ids ) ) {
			return [];
		}

		$videos = get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters is false, so this is cacheable.
			[
				'post_type'        => $this->post_type_name,
				'post_status'      => 'publish',
				'post__in'         => $page_ids,
				'orderby'          => 'post__in',
				'order'            => 'DESC',
				'posts_per_page'   => count( $page_ids ),
				'suppress_filters' => false, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SlowQueryDueToFilters
			]
		);

		$positions = array_flip( array_map( 'intval', $page_ids ) );

		usort(
			$videos,
			static function ( $a, $b ) use ( $positions ) {
				$ia = isset( $positions[ (int) $a->ID ] ) ? $positions[ (int) $a->ID ] : PHP_INT_MAX;
				$ib = isset( $positions[ (int) $b->ID ] ) ? $positions[ (int) $b->ID ] : PHP_INT_MAX;

				return $ia <=> $ib;
			}
		);

		return array_map(
			static function ( $video ) {
				return [
					'ID'            => (int) $video->ID,
					'post_title'    => $video->post_title,
					'post_name'     => $video->post_name,
					'post_date'     => $video->post_date,
					'post_modified' => $video->post_modified,
				];
			},
			$videos
		);
	}

	/**
	 * Override HTML sitemap label for video slugs.
	 *
	 * @param string     $label         Original label.
	 * @param string     $category_slug Sitemap category slug.
	 * @param null|mixed $category      Optional category data.
	 *
	 * @return string
	 */
	public function filter_category_name( string $label, string $category_slug, $category = null ): string {
		if ( self::SITEMAP_PREFIX === $category_slug ) {
			return __( 'Videos', 'html-sitemap-categorized' );
		}

		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $label;
		}

		$term = get_term_by( 'slug', $term_slug, $this->taxonomy_name );

		if ( ! $term instanceof \WP_Term ) {
			return $label;
		}

		return sprintf(
			/* translators: %s: video category name. */
			__( 'Videos: %s', 'html-sitemap-categorized' ),
			$term->name
		);
	}

	/**
	 * Override HTML sitemap URL for video slugs.
	 *
	 * @param string $url           Original URL.
	 * @param string $category_slug Sitemap category slug.
	 * @param int    $page          Current sitemap page.
	 *
	 * @return string
	 */
	public function filter_category_url( string $url, string $category_slug, int $page ): string {
		// Videos index should always be /sitemap/videos/ (ignore page parameter).
		if ( self::SITEMAP_PREFIX === $category_slug ) {
			return home_url( '/sitemap/videos/' );
		}

		$term_slug = $this->parse_category_slug( $category_slug );

		if ( null === $term_slug ) {
			return $url;
		}

		return home_url(
			sprintf(
				'/sitemap/%s-%d/',
				rawurlencode( $category_slug ),
				max( 1, $page )
			)
		);
	}

	/**
	 * Invalidate caches when a video is published/unpublished.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param \WP_Post $post      Post object.
	 *
	 * @return void
	 */
	public function handle_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $this->post_type_name !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}

		$terms = wp_get_object_terms(
			$post->ID,
			$this->taxonomy_name,
			[
				'fields' => 'slugs',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		foreach ( $terms as $term_slug ) {
			$category_slug = $this->build_category_slug( $term_slug );

			wp_cache_delete( $this->cache_key_ids( $term_slug ), Sitemap_Cache::CACHE_GROUP );
			wp_cache_delete( $this->cache_key_meta( $term_slug ), Sitemap_Cache::CACHE_GROUP );
			wp_cache_delete( "all_ids:{$category_slug}", Sitemap_Cache::CACHE_GROUP );
			wp_cache_delete( "meta:{$category_slug}", Sitemap_Cache::CACHE_GROUP );

			$page_bound = 20;
			$meta       = wp_cache_get( $this->cache_key_meta( $term_slug ), Sitemap_Cache::CACHE_GROUP );

			if ( is_array( $meta ) && isset( $meta['total_pages'] ) ) {
				$page_bound = max( 1, (int) $meta['total_pages'] ) + 2;
			}

			for ( $page = 1; $page <= $page_bound; $page++ ) {
				wp_cache_delete( $this->cache_key_posts( $term_slug, $page ), Sitemap_Cache::CACHE_GROUP );
				wp_cache_delete( "ids:{$category_slug}:{$page}", Sitemap_Cache::CACHE_GROUP );
				wp_cache_delete( "posts:{$category_slug}:{$page}", Sitemap_Cache::CACHE_GROUP );
				wp_cache_delete( "html:{$category_slug}:{$page}", Sitemap_Cache::CACHE_GROUP );
			}
		}

		// Clear global caches.
		wp_cache_delete( 'categories', Sitemap_Cache::CACHE_GROUP );
		wp_cache_delete( 'categories:videos', Sitemap_Cache::CACHE_GROUP );
		wp_cache_delete( 'root_html', Sitemap_Cache::CACHE_GROUP );
		wp_cache_delete( 'html:videos:1', Sitemap_Cache::CACHE_GROUP );
	}

	/**
	 * Get ordered video IDs for a term.
	 *
	 * @param string $term_slug Video taxonomy term slug.
	 *
	 * @return array<int>
	 */
	private function get_video_category_post_ids( string $term_slug ): array {
		$cache_key = $this->cache_key_ids( $term_slug );
		$cached    = wp_cache_get( $cache_key, Sitemap_Cache::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$query = new \WP_Query(
			[
				'post_type'           => $this->post_type_name,
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Single taxonomy lookup for sitemap.
					[
						'taxonomy' => $this->taxonomy_name,
						'field'    => 'slug',
						'terms'    => $term_slug,
					],
				],
			]
		);

		$post_ids = $query->posts;
		wp_cache_set( $cache_key, $post_ids, Sitemap_Cache::CACHE_GROUP );

		return $post_ids; // @phpstan-ignore-line We know we are returning an array of post IDs.
	}

	/**
	 * Build sitemap slug for a video category.
	 *
	 * @param string $term_slug Video taxonomy term slug.
	 *
	 * @return string
	 */
	private function build_category_slug( string $term_slug ): string {
		return self::SITEMAP_PREFIX . '-' . sanitize_title( $term_slug );
	}

	/**
	 * Parse sitemap slug and extract video term slug.
	 *
	 * @param string $category_slug Sitemap category slug.
	 *
	 * @return string|null Term slug or null if not a video category slug.
	 */
	private function parse_category_slug( string $category_slug ): ?string {
		$prefix = self::SITEMAP_PREFIX . '-';

		if ( ! str_starts_with( $category_slug, $prefix ) ) {
			return null;
		}

		return substr( $category_slug, strlen( $prefix ) );
	}

	/**
	 * Cache key helpers.
	 *
	 * @param string $term_slug Video taxonomy term slug.
	 *
	 * @return string Cache key for video IDs.
	 */
	private function cache_key_ids( string $term_slug ): string {
		return 'videos_html_ids:' . $term_slug;
	}

	/**
	 * Get cache key for video category meta.
	 *
	 * @param string $term_slug Video taxonomy term slug.
	 *
	 * @return string
	 */
	private function cache_key_meta( string $term_slug ): string {
		return 'videos_html_meta:' . $term_slug;
	}

	/**
	 * Get cache key for video posts.
	 *
	 * @param string $term_slug Video taxonomy term slug.
	 * @param int    $page      Sitemap page number.
	 *
	 * @return string
	 */
	private function cache_key_posts( string $term_slug, int $page ): string {
		return 'videos_html_posts:' . $term_slug . ':' . $page;
	}
}
