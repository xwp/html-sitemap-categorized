<?php
namespace HtmlSitemapCategorized;

/**
 * Shared helpers for HTML Sitemap plugin.
 */
class Utils {
	/**
	 * Retrieve sanitized list of post types monitored by the HTML sitemap.
	 *
	 * @return array<int, string>
	 */
	public static function get_supported_post_types(): array {
		/**
		 * Filters the list of post types monitored by the HTML sitemap.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = apply_filters( 'html_sitemap_supported_post_types', [ 'post' ] );

		if ( ! is_array( $post_types ) ) { // @phpstan-ignore-line -- We're filtering, so checking just in case.
			return [ 'post' ];
		}

		$post_types = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $post_types )
				)
			)
		);

		return ! empty( $post_types ) ? $post_types : [ 'post' ];
	}
}

