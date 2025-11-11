<?php
/**
 * HTML Sitemap Categorized
 *
 * Plugin Name: HTML Sitemap Categorized
 * Plugin URI: https://github.com/xwp/html-sitemap-categorized
 * Description: Category-based HTML sitemaps.
 * Version: 1.0.1
 * Author: XWP
 * Author URI: https://xwp.co
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: html-sitemap-categorized
 */

namespace HtmlSitemapCategorized;

// Load classes.
require_once __DIR__ . '/includes/class-sitemap-cache.php';
require_once __DIR__ . '/includes/class-html-sitemap.php';

/**
 * Accessor for the plugin main instance.
 */
function html_sitemap_categorized(): HTML_Sitemap {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new HTML_Sitemap();
	}
	return $instance;
}

// Bootstrap the plugin.
html_sitemap_categorized();

// Deactivation: clear scheduled regeneration jobs.
register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( '\\HtmlSitemapCategorized\\Sitemap_Cache' ) ) {
			wp_unschedule_hook( \HtmlSitemapCategorized\Sitemap_Cache::ACTION_REGENERATE_CATEGORY );
		}
	}
);
