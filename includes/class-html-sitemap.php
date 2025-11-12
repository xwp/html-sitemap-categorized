<?php
namespace HtmlSitemapCategorized;

class HTML_Sitemap {
	/**
	 * Plugin version used for asset cache-busting.
	 */
	const VERSION = '1.0.1';

	/**
	 * Query variable name for storing the sitemap category.
	 *
	 * @var string
	 */
	const QUERY_VAR_CATEGORY = 'sitemap_category';

	/**
	 * Query variable name for storing the sitemap page number.
	 *
	 * @var string
	 */
	const QUERY_VAR_PAGE = 'sitemap_page';

	/**
	 * Cache duration for edge cache (5 minutes for VIP s-maxage).
	 */
	const EDGE_CACHE_DURATION = 300;

	/**
	 * Stale-while-revalidate duration (1 hour).
	 */
	const STALE_WHILE_REVALIDATE = 3600;

	/**
	 * Stale-if-error duration (1 hour).
	 */
	const STALE_IF_ERROR = 3600;

	/**
	 * Cache manager instance.
	 *
	 * @var Sitemap_Cache
	 */
	public $cache;

	/**
	 * Initialize the HTML sitemap functionality.
	 */
	public function __construct() {
		// Initialize cache manager.
		$this->cache = new Sitemap_Cache();

		add_action( 'init', [ $this, 'add_rewrite_rules' ], 2 );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'template_include', [ $this, 'force_sitemap_template' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Title filter.
		add_filter( 'document_title_parts', [ $this, 'set_document_title' ] );

		// Canonical URL filter.
		add_filter( 'get_canonical_url', [ $this, 'set_canonical' ], 10, 2 );
		add_filter( 'wp_headers', [ $this, 'cache_headers' ] );

		// Hook into post status transitions (publish/unpublish) for targeted cache updates.
		add_action( 'transition_post_status', [ $this, 'handle_post_status_change' ], 10, 3 );
	}

	/**
	 * Add rewrite rules for HTML sitemap.
	 */
	public function add_rewrite_rules(): void {
		// Simple rewrite rule for category pages.
		add_rewrite_rule(
			'^sitemap/([^/]+?)-(\d+)/?$',
			'index.php?' . self::QUERY_VAR_CATEGORY . '=$matches[1]&' . self::QUERY_VAR_PAGE . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Register query variables.
	 *
	 * @param array<string> $vars Existing query variables.
	 * @return array<string> Modified query variables.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_CATEGORY;
		$vars[] = self::QUERY_VAR_PAGE;
		return $vars;
	}

	/**
	 * Force the sitemap template for sitemap URLs.
	 *
	 * @param string $template Current template path.
	 * @return string Template path to use.
	 */
	public function force_sitemap_template( $template ) {
		// Use query vars to detect sitemap requests (more reliable than URI parsing).
		if ( true !== $this->is_sitemap_request() ) {
			return $template;
		}

		// Prefer plugin-bundled template to keep sitemap logic self-contained.
		$plugin_template = dirname( __DIR__ ) . '/templates/page-template-sitemap.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Set document title for modern WordPress (4.4+).
	 *
	 * @param array<string, string> $title_parts Title parts array.
	 * @return array<string, string> Modified title parts.
	 */
	public function set_document_title( array $title_parts ): array {
		if ( true !== $this->is_sitemap_request() ) {
			return $title_parts;
		}

		$category_slug = get_query_var( self::QUERY_VAR_CATEGORY );
		if ( ! empty( $category_slug ) ) {
			$category_name = $this->get_category_name( $category_slug );
			$page_number   = (int) get_query_var( self::QUERY_VAR_PAGE, 1 );

			if ( ! empty( $category_name ) ) {
				$title_parts['title'] = $category_name . ( $page_number > 1 ? " - Page {$page_number}" : '' );
			}
		} else {
			$title_parts['title'] = 'Sitemap';
		}

		return $title_parts;
	}

	/**
	 * Set canonical URL for HTML sitemap pages.
	 *
	 * @param string   $url  Current canonical URL.
	 * @param \WP_Post $post Post object.
	 * @return string Modified canonical URL.
	 */
	public function set_canonical( $url, $post ) {
		if ( true === $this->is_sitemap_request() ) {
			$category_slug = get_query_var( self::QUERY_VAR_CATEGORY );
			$page          = (int) ( get_query_var( self::QUERY_VAR_PAGE ) ?: 1 );

			if ( ! empty( $category_slug ) ) {
				return $this->get_category_url( $category_slug, $page );
			}
		}

		return $url;
	}

	/**
	 * Set cache headers for HTML sitemap pages.
	 *
	 * @param array<string, string|null> $headers Current headers.
	 * @return array<string, string|null> Modified headers.
	 */
	public function cache_headers( array $headers ): array {
		if ( true !== $this->is_sitemap_request() ) {
			return $headers;
		}

		// VIP-optimized cache headers with enhanced stale handling.
		$cache_control            = sprintf(
			's-maxage=%d, max-age=0, stale-while-revalidate=%d, stale-if-error=%d',
			self::EDGE_CACHE_DURATION,
			self::STALE_WHILE_REVALIDATE,
			self::STALE_IF_ERROR
		);
		$headers['Cache-Control'] = $cache_control;

		// Add Surrogate-Key headers for VIP cache purging.
		$surrogate_keys = [ 'sitemap-index' ];

		$category_slug = get_query_var( self::QUERY_VAR_CATEGORY );
		if ( ! empty( $category_slug ) ) {
			$surrogate_keys[] = 'sitemap-cat-' . sanitize_title( $category_slug );
		}

		$headers['Surrogate-Key'] = implode( ' ', $surrogate_keys );

		return $headers;
	}

	/**
	 * Enqueue assets only for HTML sitemap requests.
	 */
	public function enqueue_assets(): void {
		if ( true !== $this->is_sitemap_request() ) {
			return;
		}

		$handle  = 'html-sitemap-categorized';
		$src     = plugins_url( 'assets/sitemap-html.css', dirname( __DIR__ ) . '/html-sitemap-categorized.php' );
		$version = self::VERSION;

		wp_enqueue_style( $handle, $src, [], $version );
	}

	/**
	 * Check if this is any sitemap request (root or category).
	 *
	 * @return bool True if sitemap request.
	 */
	protected function is_sitemap_request(): bool {
		// Check for category sitemap.
		if ( ! empty( get_query_var( self::QUERY_VAR_CATEGORY ) ) ) {
			return true;
		}

		global $wp;

		$path = '';
		if ( isset( $wp ) && isset( $wp->request ) ) {
			$path = ltrim( (string) $wp->request, '/' );
		}

		if ( 'sitemap' === $path || str_starts_with( $path, 'sitemap/' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get category name for a given slug.
	 *
	 * @param string $category_slug The category slug.
	 * @return string The category name or empty string.
	 */
	public function get_category_name( $category_slug ) {
		/**
		 * Filters the displayed name for a sitemap category slug.
		 *
		 * @param string $name          Category name. Default empty allows fallback to taxonomy term.
		 * @param string $category_slug Requested category slug.
		 */
		$filtered_name = apply_filters( 'html_sitemap_category_name', '', $category_slug );
		if ( is_string( $filtered_name ) && '' !== $filtered_name ) { // @phpstan-ignore-line -- We're filtering, so checking just in case.
			return $filtered_name;
		}

		$category = get_term_by( 'slug', $category_slug, 'category' );
		return $category ? $category->name : '';
	}

	/**
	 * Get URL for a category sitemap page.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return string The category sitemap URL.
	 */
	public function get_category_url( $category_slug, $page = 1 ) {
		// Always include page number to match XML sitemap pattern: ...-1, ...-2, etc.
		$encoded_slug = rawurlencode( $category_slug );
		return home_url( "/sitemap/{$encoded_slug}-{$page}/" );
	}

	/**
	 * Get breadcrumbs for category sitemap pages.
	 *
	 * @param string $category_slug The category slug.
	 * @param int    $page          The page number.
	 * @return array<array<string, mixed>> Breadcrumb data.
	 */
	public function get_breadcrumbs( string $category_slug, int $page = 1 ): array {
		$breadcrumbs = [];

		if ( ! empty( $category_slug ) ) {
			// Always show link back to sitemap index.
			$breadcrumbs[] = [
				'link'     => home_url( '/sitemap/' ),
				'label'    => __( 'Sitemap Index', 'html-sitemap-categorized' ),
				'position' => 1,
			];
			// Add current category as non-linked item.
			$category_name = $this->get_category_name( $category_slug );
			if ( ! empty( $category_name ) ) {
				$breadcrumbs[] = [
					'label'    => $category_name,
					'position' => 2,
				];
			}
		}

		return $breadcrumbs;
	}

	/**
	 * Handle post status changes for targeted cache updates.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function handle_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, Utils::get_supported_post_types(), true ) ) {
			return;
		}

		// Only care about publish status changes.
		if ( ( $new_status === $old_status ) || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}

		$slugs = [];

		// Get the deepest category it belongs to.
		$category_slug = $this->get_category_slug( $post->ID );
		if ( ! empty( $category_slug ) ) {
			$slugs[] = $category_slug;
		}

		/**
		 * Filters additional cache slugs that should be regenerated for a post transition.
		 *
		 * @param string[] $slugs       Additional cache slugs to regenerate.
		 * @param \WP_Post $post        Post object.
		 * @param string   $new_status  New status.
		 * @param string   $old_status  Previous status.
		 */
		$virtual_slugs = apply_filters( 'html_sitemap_virtual_category_slugs', [], $post, $new_status, $old_status );
		if ( is_array( $virtual_slugs ) ) { // @phpstan-ignore-line -- We're filtering, so checking just in case.
			foreach ( $virtual_slugs as $virtual_slug ) {
				$virtual_slug = sanitize_title( (string) $virtual_slug );
				if ( '' === $virtual_slug ) {
					continue;
				}
				$slugs[] = $virtual_slug;
			}
		}

		$slugs = array_values( array_unique( array_filter( $slugs ) ) );

		if ( empty( $slugs ) ) {
			return;
		}

		// Schedule regeneration which will invalidate and rebuild cache.
		foreach ( $slugs as $slug ) {
			$this->cache->schedule_category_regeneration( $slug );
		}
	}

	/**
	 * Get the category slug the post belongs to. Returns the deepest category slug.
	 * Replicates the same logic as a leaf in a categorized XML sitemap.
	 *
	 * @param int $post_id The post ID.
	 * @return string The category slug or empty string if the post doesn't belong to a category.
	 */
	public function get_category_slug( int $post_id ): string {
		$categories = get_the_category( $post_id );

		// If no categories are found, return an empty string.
		if ( empty( $categories ) ) {
			return '';
		}

		// Find the deepest category.
		$deepest_category = null;
		$max_depth        = -1; // Init max depth to -1.

		foreach ( $categories as $category ) {
			$depth   = 0;
			$current = $category;

			// Count depth by traversing up the hierarchy.
			while ( (int) $current->parent > 0 ) {
				++$depth;
				$parent = get_category( $current->parent );
				if ( $parent instanceof \WP_Term ) {
					$current = $parent;
				} else {
					break;
				}
			}

			// Update the deepest category if this one is deeper.
			if ( $depth > $max_depth ) {
				$max_depth        = $depth;
				$deepest_category = $category;
			}
		}

		// Return the deepest category slug.
		return $deepest_category->slug;
	}

	/**
	 * Get template data for the current sitemap request.
	 *
	 * @return array<string, mixed> Template data including links, breadcrumbs, page_title, etc.
	 */
	public function get_template_data(): array {
		$category_slug    = get_query_var( self::QUERY_VAR_CATEGORY );
		$sitemap_page_num = (int) ( get_query_var( self::QUERY_VAR_PAGE ) ?: 1 );

		if ( ! empty( $category_slug ) ) {
			return $this->get_category_template_data( $category_slug, $sitemap_page_num );
		} else {
			return $this->get_root_template_data();
		}
	}

	/**
	 * Get template data for category view.
	 *
	 * @param string $category_slug     The category slug.
	 * @param int    $sitemap_page_num  The page number.
	 * @return array<string, mixed> Template data.
	 */
	protected function get_category_template_data( string $category_slug, int $sitemap_page_num ): array {
		$category_name = $this->get_category_name( $category_slug );
		$sitemap_posts = $this->cache->get_category_posts( $category_slug, $sitemap_page_num );

		$links = [
			[
				'label'   => '', // No section title needed since page title already shows category.
				'classes' => [ 'c-sitemap__item--posts' ],
				'items'   => array_map(
					function ( $sitemap_post ) {
						$permalink = get_permalink( $sitemap_post['ID'] );
						return [
							'label' => $sitemap_post['post_title'],
							'link'  => is_string( $permalink ) ? $permalink : '',
						];
					},
					$sitemap_posts
				),
			],
		];

		return [
			'links'            => $links,
			'breadcrumbs'      => $this->get_breadcrumbs( $category_slug, $sitemap_page_num ),
			'page_title'       => ( $sitemap_page_num > 1 ) ? "{$category_name} {$sitemap_page_num}" : $category_name,
			'is_category_view' => true,
		];
	}

	/**
	 * Get template data for root sitemap view.
	 *
	 * @return array<string, mixed> Template data.
	 */
	protected function get_root_template_data(): array {
		$categories = $this->cache->get_categories();

		// Group pages per category name.
		$grouped_categories = [];
		foreach ( $categories as $category ) {
			$name = $category['name'];
			if ( ! isset( $grouped_categories[ $name ] ) ) {
				$grouped_categories[ $name ] = [];
			}
			$grouped_categories[ $name ][] = $category;
		}

		$links = [];
		foreach ( $grouped_categories as $category_name => $category_pages ) {
			// Sort pages ascending by page number.
			usort(
				$category_pages,
				static function ( $a, $b ) {
					$ap = isset( $a['page'] ) ? (int) $a['page'] : 0;
					$bp = isset( $b['page'] ) ? (int) $b['page'] : 0;
					return $ap <=> $bp;
				}
			);

			$items                      = [];
			$category_has_no_pagination = count( $category_pages ) === 1;
			foreach ( $category_pages as $idx => $category_page ) {
				$page_num = (int) $category_page['page'];
				$label    = $page_num;
				if ( 0 === $idx ) {
					$label  = $category_name;
					$label .= $category_has_no_pagination ? '' : ' ' . $page_num;
				}
				$items[] = [
					'label' => $label,
					'link'  => $this->get_category_url( $category_page['slug'], $page_num ),
				];
			}

			$links[] = [
				'label'   => '',
				'classes' => [ 'c-sitemap__item--index' ],
				'items'   => $items,
			];
		}

		$sitemap_page = get_queried_object();
		$breadcrumbs  = [
			[
				'link'     => ( $sitemap_page instanceof \WP_Post ) ? get_permalink( $sitemap_page ) : '',
				'label'    => __( 'Sitemap Index', 'html-sitemap-categorized' ),
				'position' => 1,
			],
		];

		return [
			'links'            => $links,
			'breadcrumbs'      => $breadcrumbs,
			'page_title'       => null, // Use default page title.
			'is_category_view' => false,
		];
	}

	/**
	 * Render sitemap content fragment HTML from a links array.
	 *
	 * @param array<int, array<string, mixed>> $links Structured links for the sitemap content.
	 * @return string Rendered HTML fragment (no header/footer wrappers).
	 */
	public function render_fragment( array $links ): string {
		ob_start();
		?>
		<div class="c-sitemap c-sitemap__container">
			<div class="c-sitemap__list">
				<?php if ( ! empty( $links ) ) : ?>
					<?php foreach ( $links as $section ) : ?>
						<div class="c-sitemap__item <?php echo esc_attr( implode( ' ', $section['classes'] ?? [] ) ); ?>">
							<?php if ( ! empty( $section['label'] ) ) : ?>
								<h2 class="c-sitemap__item-title"><?php echo esc_html( $section['label'] ); ?></h2>
							<?php endif; ?>
							<ul class="c-sitemap__item-list">
								<?php foreach ( $section['items'] as $item ) : ?>
									<li class="c-sitemap__item-list-item">
										<a href="<?php echo esc_url( $item['link'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No sitemap content available.', 'html-sitemap-categorized' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		$html = ob_get_clean();
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Render breadcrumbs HTML (plugin-owned, independent of theme helpers).
	 *
	 * @param array<int, array<string,mixed>> $breadcrumbs Breadcrumb items with optional 'link' and required 'label'.
	 * @return string Breadcrumbs markup.
	 */
	public function render_breadcrumbs( array $breadcrumbs ): string {
		if ( empty( $breadcrumbs ) ) {
			return '';
		}

		ob_start();
		?>
		<ol class="c-breadcrumbs__list" itemscope itemtype="http://schema.org/BreadcrumbList">
			<?php foreach ( $breadcrumbs as $index => $crumb ) : ?>
				<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">
					<?php if ( ! empty( $crumb['link'] ) ) : ?>
						<a class="c-breadcrumbs__item" itemprop="item" href="<?php echo esc_url( (string) $crumb['link'] ); ?>">
							<span itemprop="name"><?php echo esc_html( (string) $crumb['label'] ); ?></span>
						</a>
					<?php else : ?>
						<span class="c-breadcrumbs__item" itemprop="name"><?php echo esc_html( (string) $crumb['label'] ); ?></span>
					<?php endif; ?>
					<meta itemprop="position" content="<?php echo (int) ( $crumb['position'] ?? ( $index + 1 ) ); ?>" />
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		$html = ob_get_clean();
		return is_string( $html ) ? $html : '';
	}
}
