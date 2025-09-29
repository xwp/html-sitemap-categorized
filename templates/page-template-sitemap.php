<?php
use HtmlSitemapCategorized\HTML_Sitemap;

$html_sitemap  = \HtmlSitemapCategorized\html_sitemap_categorized();
$category_slug = get_query_var( HTML_Sitemap::QUERY_VAR_CATEGORY );
$current_page  = (int) ( get_query_var( HTML_Sitemap::QUERY_VAR_PAGE ) ?: 1 );
$page_title    = null;
$breadcrumbs   = [];

if ( ! empty( $category_slug ) ) {
	$category_name = $html_sitemap->get_category_name( $category_slug );
	if ( ! empty( $category_name ) ) {
		$page_title = ( $current_page > 1 ) ? ( $category_name . ' ' . $current_page ) : $category_name;
	}
	$breadcrumbs = $html_sitemap->get_breadcrumbs( $category_slug, $current_page );
} else {
	$breadcrumbs[] = [
		'link'     => home_url( '/sitemap/' ),
		'label'    => __( 'Sitemap Index', 'html-sitemap-categorized' ),
		'position' => 1,
	];
}

get_header();
?>
<main id="main" class="l-container" itemscope itemprop="mainContentOfPage" itemtype="http://schema.org/WebPage">
	<div class="l-sitemap">
		<div class="l-article__header">
			<h1 class="c-headline c-headline--extra-large entry-title" itemprop="headline">
				<?php echo ! empty( $page_title ) ? esc_html( $page_title ) : esc_html( get_the_title() ); ?>
			</h1>

			<?php echo $html_sitemap->render_breadcrumbs( $breadcrumbs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( ! empty( $category_slug ) ) : ?>
				<?php
				$meta        = $html_sitemap->cache->get_category_meta( $category_slug );
				$total_pages = isset( $meta['total_pages'] ) ? (int) $meta['total_pages'] : 0;
				if ( $total_pages > 1 ) :
					?>
						<div class="c-sitemap__pagination c-sitemap__container">
							<?php
							// Normalize the current page within [1, total_pages].
							$current_page = max( 1, min( (int) $current_page, (int) $total_pages ) );
							// Window size controls how many neighbors of the current page we display.
							$window = 2; // Show current ±2 pages.

							// Build the list of page numbers to render:
							// - Always include first (1) and last (total_pages)
							// - Include a sliding window around the current page
							$page_numbers = [ 1, (int) $total_pages ];
							for ( $i = $current_page - $window; $i <= $current_page + $window; $i++ ) {
								if ( $i >= 1 && $i <= $total_pages ) {
									$page_numbers[] = (int) $i;
								}
							}
							// Ensure uniqueness and ascending order for consistent rendering.
							$page_numbers = array_values( array_unique( $page_numbers ) );
							sort( $page_numbers, SORT_NUMERIC );

							// Render the page number sequence with ellipses between gaps.
							$prev = null;
							foreach ( $page_numbers as $p ) :
								$p = (int) $p;
								if ( null !== $prev && $p > $prev + 1 ) :
									?>
									<span class="c-sitemap__page">&hellip;</span>
									<?php
								endif;

								if ( $p === $current_page ) :
									?>
									<span class="c-sitemap__page is-current"><?php echo esc_html( (string) $p ); ?></span>
									<?php
								else :
									$url = $html_sitemap->get_category_url( $category_slug, $p );
									?>
									<a class="c-sitemap__page" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $p ); ?></a>
									<?php
								endif;
								$prev = $p;
							endforeach;
							?>
						</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="c-content">
			<?php
			if ( ! empty( $category_slug ) ) {
				echo $html_sitemap->cache->get_category_html( $category_slug, $current_page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo $html_sitemap->cache->get_root_sitemap_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	</div>
</main>
<?php
get_sidebar( 'before-footer' );
get_footer();
