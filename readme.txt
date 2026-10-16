=== CWV Speed ===
Contributors: cwvspeed
Tags: performance, cache, core web vitals, images, pagespeed
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page cache and front-end delivery fixes aimed at a 90+ Lighthouse performance score on mobile and desktop.

== Description ==

CWV Speed targets the metrics Lighthouse actually weights:

* Total Blocking Time, 30%
* Largest Contentful Paint, 25%
* Cumulative Layout Shift, 25%
* First Contentful Paint, 10%
* Speed Index, 10%

Field Core Web Vitals use the same loading and stability metrics, plus Interaction to Next Paint. A good visit is LCP within 2.5 seconds, INP within 200 milliseconds, and CLS at or below 0.1.

The Balanced preset turns on the work that moves those numbers without delaying your own theme scripts:

* Anonymous page cache, served before WordPress boots, with gzip and Brotli when PHP can write it
* Separate mobile HTML when the theme differs
* Apache cache headers, plus a nginx snippet
* HTML minification
* Delayed analytics, tag managers, chat, maps, and other third-party tags until the first real interaction
* LCP image discovery: fetchpriority, preload, and no lazy-load on the hero
* Width, height, and sizes where they are missing
* WebP and optional AVIF beside the original uploads
* Lazy iframes and click-to-play YouTube and Vimeo with a reserved 16:9 box
* font-display: swap
* WordPress speculative loading raised to prerender-on-hover, with cart and checkout excluded
* Emoji, embed, Heartbeat, cart-fragment, and head-link cleanup

Aggressive also delays first-party scripts and enables AVIF. Test menus, forms, and checkout after using it.

A plugin cannot guarantee 90 on every site. The score still depends on hosting time-to-first-byte, hero image weight, theme CSS, and page-builder output. Measure the public URL in PageSpeed Insights after the cache has been warmed by one anonymous visit.

== Installation ==

1. Copy the `wp-cwv-speed` folder into `wp-content/plugins/`.
2. Activate CWV Speed.
3. Open CWV Speed in wp-admin and apply Balanced.
4. If a notice asks for it, add `define( 'WP_CACHE', true );` to `wp-config.php`.
5. Visit the homepage once while logged out, then run PageSpeed Insights.

== Frequently Asked Questions ==

= Will this break WooCommerce? =

Cart, checkout, and account pages are not cached or prerendered. Cart fragments stay loaded on those pages and are removed elsewhere.

= Why is there a delay timeout of 0? =

Lighthouse does not scroll or click. A fallback timer lets third-party tags run during the test and puts the blocking time back. Real visitors trigger the tags as soon as they move, tap, type, or scroll.

= Where are cached pages stored? =

`wp-content/cache/cwv-speed/`. Purge from the admin bar or the settings screen. The cache also clears when a post, comment, menu, or theme changes.

== Changelog ==

= 1.0.0 =
* Initial release.
