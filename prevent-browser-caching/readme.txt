=== Prevent Browser Caching ===
Contributors: kostyatereshchuk
Tags: browser cache, clear cache, cache busting, versioning, cache
Requires at least: 4.7
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 3.1.0
Donate link: https://tutori.org/donate/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prevents browser cache problems: visitors always get the current version of your CSS, JS, images and pages, while caching keeps working.

== Description ==

You changed the site, but a client or visitor still sees the old version and you have to say "please clear your browser cache"? This plugin makes that conversation unnecessary.

Prevent Browser Caching makes sure browsers always load the current version of your site — without disabling browser caching and slowing the site down.

= What it does =

* **CSS & JS versions.** WordPress loads assets with a "ver" URL parameter (e.g. `style.css?ver=4.9.6`). Browsers cache the file until this parameter changes. In the recommended automatic mode the plugin sets the version from the file's own modification time: browser caching works at full strength, and the moment you update a file every visitor gets the new one.
* **Image versions.** When you edit or replace a file in the Media Library, visitors get the new image instead of the cached one.
* **HTML page freshness.** Asks browsers to check for a newer version of a page before showing a cached copy — fixes "I still see the old page on my phone".
* **One-click update.** The "Update versions" toolbar button forces fresh copies of all assets for every visitor — and shows a short report of what exactly happened.
* **Page cache stays in sync (opt-in).** If a page-cache plugin is active, updating versions can also clear its cache — so cached HTML stops referencing the old file versions and every visitor sees the new site immediately. One checkbox turns it on, and after every update the plugin reports what was refreshed and what happened to the page cache. Works with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, WP-Optimize, Breeze, Cache Enabler, Hummingbird, SiteGround Optimizer, Swift Performance and Comet Cache.
* **CLI & AI agents.** WP-CLI commands (`wp pbc update`, `wp pbc status`) and WordPress Abilities let deploy scripts and AI agents update versions safely.

= Safe by default =

* External URLs (payment scripts, CDNs, third-party services) are left untouched — some of them break when an unexpected "ver" parameter is added. You can turn external versioning back on with one checkbox.
* Specific files (by part of the URL) or script/style handles can be excluded from versioning — CSS, JS and images alike.
* If a page-cache plugin is active, the HTML freshness headers step aside automatically.
* Another plugin's page cache is never cleared unless you enable that yourself — the purge-on-update integration is opt-in, and the report after every update tells you whether the page cache was cleared or left alone.

= Update modes =

* **Automatically, when a file changes** (recommended) — version = file modification time. Zero clicks, full caching.
* **Every time a page loads** — development mode: CSS & JS are never cached (images and pages are unaffected). Use it only while actively developing.
* **Manually** — versions change only when you press the "Update versions" button.

= For developers =

The recommended way to set the CSS/JS version from code is the `pbc_assets_version` filter. Add this to the functions.php file of your theme and change the value whenever you need to update assets:

`add_filter( 'pbc_assets_version', function( $ver ) {
	return '123';
} );`

Because it uses WordPress's own `add_filter()`, it keeps working safely even if the plugin is ever deactivated — your site won't break.

Filters for fine-tuning:

* `pbc_skip_src( $skip, $src, $handle )` — return `true` to leave a given asset URL untouched.
* `pbc_assets_version( $ver, $src, $handle )` — change the version applied to a given asset.
* `pbc_purge_page_cache( $purge, $plugin_name )` — return `false` to prevent the page-cache purge on version updates.
* `pbc_after_bump( $result )` — action fired after every version update, with the new timestamp and the purge outcome.

= WP-CLI =

* `wp pbc update` — updates the versions (and clears the detected page cache when the settings option is on). Add `--skip-purge` to leave the page cache alone for that run.
* `wp pbc status` — shows the mode, what is versioned, the last manual update and the detected page-cache plugin. Supports `--format=table|json|yaml`.

= Abilities (AI agents & automation) =

On WordPress 6.9+ the plugin registers two Abilities, discoverable via the Abilities API, REST and the MCP adapter — so AI agents and site-management tools can operate the plugin without custom glue code:

* `prevent-browser-caching/bump-versions` — update the versions; optional boolean input `purge` (set `false` to skip the page-cache purge).
* `prevent-browser-caching/status` — read-only report of the current configuration.

Both require the `manage_options` capability.

Legacy: earlier versions documented a `prevent_browser_caching()` function instead. It still works exactly as before — it disables the plugin's admin settings and gives you full control — but I recommend the filter above: a bare function call in functions.php triggers a fatal error if the plugin is ever deactivated. If you keep using the function, guard it:

`if ( function_exists( 'prevent_browser_caching' ) ) {
	prevent_browser_caching( array(
		'assets_version' => '123'
	) );
}`

= Thank you =

Many of the improvements in 3.0.0 started as reports and questions in the [support forum](https://wordpress.org/support/plugin/prevent-browser-caching/) — thank you to everyone who took the time to describe a problem or share an idea. If something doesn't work as expected on your site, please open a topic there: it genuinely helps make the plugin better for everyone.

== Frequently Asked Questions ==

= Does it affect site speed or SEO? =

No. In the recommended automatic mode browser caching keeps working at full strength — repeat visitors load CSS/JS from their cache until a file really changes, so repeat views are as fast as ever (faster than the old 2.x default, which re-downloaded assets on every visit). The server cost is a few file-time lookups per page — negligible. The "ver" URL parameter is the same mechanism WordPress core uses, search engines are perfectly used to it, and cache headers are not a ranking signal — the plugin does not change your page content, markup or URLs seen by crawlers.

= Does it work together with page caching plugins? =

Yes — and since 3.1.0 they can actively cooperate. Versioned asset URLs end up in the cached HTML like any others, so serving stale HTML used to mean serving old asset versions with it. When the "Also clear the page cache" checkbox on the settings page is enabled, pressing "Update versions" (toolbar, settings page, WP-CLI or an ability) also clears the detected page-cache plugin's cache, so that HTML is regenerated with the new versions. Supported: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, WP-Optimize, Breeze, Cache Enabler, Hummingbird, SiteGround Optimizer, Swift Performance, Comet Cache. The checkbox is off by default — another plugin's cache is only touched when you say so (for example, if your page cache serves logged-out visitors only, you may prefer not to rebuild it on every update). Either way, the report shown after every update says whether the page cache was cleared, and the version update always completes even if a purge fails. The plugin also keeps leaving HTML cache headers to the page-cache plugin.

= Does the automatic mode clear my page cache when a file changes? =

No — and that's by design, not an oversight. In the automatic mode the version comes from the file's modification time, read at the moment a page is rendered; nothing "happens" on the server when you upload a changed file, so there is no event to clear the page cache on. Cached HTML keeps the old asset versions until the page cache expires or is cleared. After bigger changes, press "Update versions" — with the "Also clear the page cache" option enabled, that both updates the versions and clears the detected page cache in one click.

= Why don't external files get a version by default? =

Several external services — payment scripts in particular (PayPal, Braintree, Authorize.net) — reject requests with an unexpected "ver" query parameter, which used to break checkout forms. Since 3.0.0 only local files are versioned by default; there is a checkbox to include external URLs again if you relied on that.

= Do I lose browser caching with this plugin? =

Not in the recommended automatic mode. Files are cached normally; the version only changes when the file itself changes. The "every time a page loads" mode does disable caching of CSS/JS — use it during active development only.

= The version does not update every X minutes as I set it. Why? =

The legacy "every N minutes" mode works per visitor, using a cookie — it does not rebuild anything on the server by cron. Each visitor gets a new assets version no more often than the chosen interval. Since 3.0.0 the automatic mode is a better choice for almost every case.

= Does it version images inside post content? =

Yes, when "Images" is enabled: attachment URLs rendered by WordPress get versions immediately, and image URLs hardcoded in post content get the site-wide media version after the first update (the "Update versions" button or replacing a media file).

= My CDN ignores query strings. =

Then query-parameter versioning cannot bust that CDN's cache for those files. Configure the CDN to include query strings in its cache key, or use filename-based versioning (e.g. replace a file under a new name).

= My site shows an error after I deactivate the plugin. =

If you added `prevent_browser_caching( ... )` to your theme's functions.php, that line calls a function this plugin provides. Once the plugin is deactivated the function no longer exists, so PHP stops with a fatal error. Two ways to fix it: switch to the `pbc_assets_version` filter (recommended — it never causes this), or wrap the call in `if ( function_exists( 'prevent_browser_caching' ) ) { ... }`. See "For developers" above.

== Installation ==

= From WordPress dashboard =

1. Visit "Plugins > Add New".
2. Search for "Prevent Browser Caching".
3. Install and activate Prevent Browser Caching plugin.

= From WordPress.org site =

1. Download Prevent Browser Caching plugin.
2. Upload the "prevent-browser-caching" directory to your "/wp-content/plugins/" directory.
3. Activate Prevent Browser Caching on your Plugins page.

== Screenshots ==

1. The settings page: choose what to keep fresh, when to update versions, and whether to also clear the detected page cache.
2. Upgrading from 2.x: your settings keep working as before, and one click enables the recommended setup (reversible).
3. After a manual update the plugin reports what was refreshed and what happened to the page cache.

== Upgrade Notice ==

= 3.1.0 =
"Update versions" can now also clear the page cache of a detected caching plugin (12 supported; opt-in checkbox, off by default) and reports what happened after every update. New: WP-CLI commands and Abilities for AI agents. All existing settings keep working unchanged.

= 3.0.0 =
Your saved settings keep working exactly as before. Open Settings → Prevent Browser Caching to enable the new recommended mode (versions from file modification time, external URLs untouched, image cache busting) with one click.

== Changelog ==

= 3.1.0 =
* New: "Update versions" can now also clear the page cache when one of the supported caching plugins is active — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, WP-Optimize, Breeze, Cache Enabler, Hummingbird, SiteGround Optimizer, Swift Performance, Comet Cache. Fixes "I updated the versions, but visitors still got the old design from the page cache". Opt-in: a settings checkbox turns it on (off by default — another plugin's cache is only touched when you say so). Each plugin is purged through its own public API; every call is guarded, and the version update always completes even if a purge fails.
* New: after every "Update versions" click the plugin reports what happened — which asset types got new versions (per your settings) and whether the detected page cache was cleared. The report shows inline on the settings page and as a one-time notice after using the toolbar button.
* New: WP-CLI support — `wp pbc update [--skip-purge]` and `wp pbc status [--format=table|json|yaml]`.
* New: on WordPress 6.9+ the plugin registers two Abilities for AI agents and automation, `prevent-browser-caching/bump-versions` and `prevent-browser-caching/status` (Abilities API / REST / MCP adapter; require the `manage_options` capability).
* New for developers: the `pbc_purge_page_cache` filter (veto the purge) and the `pbc_after_bump` action (observe every version update and its purge outcome).
* Fixed: image URLs inside RSS feeds no longer get a "ver" parameter.
* Fixed: an existing "ver" query parameter in image URLs is now detected precisely — a "ver=" fragment inside another parameter name no longer counts as one.
* Housekeeping: uninstall on multisite now cleans up networks with more than 100 sites.

= 3.0.0 =
* New automatic mode (now the recommended default): the assets version is taken from the file modification time, so browser caching works at full strength and busts exactly when a file changes.
* External URLs (payment scripts, CDNs) are no longer versioned by default — this used to break PayPal/Braintree/Authorize.net checkouts. A checkbox brings external versioning back; sites upgrading with saved settings keep their previous behavior until they switch.
* New: image cache busting. Attachment URLs are versioned; editing or replacing a media file busts its cache.
* New: HTML page freshness — optional Cache-Control header asking browsers to revalidate pages, plus a back/forward-cache guard for stale pages on mobile. Steps aside automatically when a page-cache plugin is detected.
* New: exclusions list (URL substrings or script/style handles) and `pbc_skip_src` / `pbc_assets_version` filters for developers.
* New: optional cache busting in the admin area.
* New settings screen: a few clear switches, details unfold when you need them. Sites upgrading from 2.x get a one-click "Enable recommended settings" banner (reversible).
* The toolbar button is now called "Update versions": it updates the versions of CSS/JS files and images.
* After activation the plugin opens its settings page.
* Full backward compatibility: the `prevent_browser_caching()` function, all 2.x options and the filter timing work exactly as before.
* Recommended for developers: use the `pbc_assets_version` filter instead of the `prevent_browser_caching()` function — unlike a bare function call, it never causes a fatal error if the plugin is deactivated.
* Fixed: PHP warning "Cannot modify header information" when another plugin printed output before the cookie was set.
* Fixed: the manual update button on the settings page submitted the whole form.
* Housekeeping: uninstall now removes all plugin options (multisite-aware); all strings are translatable; added a POT file; direct-access guards on all files.
* Raised the minimum PHP version to 7.2 (matches the WordPress minimum). Tested on PHP up to 8.5.

= 2.3.7 =
* Fixed a bug with URLs that contain repeated query params: only the last one survived after adding the "ver" param. For example, Google Fonts URLs with several "family" params lost all font families except the last one.
* Tested the plugin in WordPress 7.0.
* Declared the minimum required PHP version (5.6).

= 2.3.6 =
* Tested the plugin in WordPress 6.9.

= 2.3.5 =
* Tested the plugin in WordPress 6.5.

= 2.3.4 =
* Tested the plugin in WordPress 6.1.

= 2.3.3 =
* Tested the plugin in WordPress 6.0.

= 2.3.2 =
* Fixed "Update CSS/JS" button in the admin bar.

= 2.3.1 =
* Tested the plugin in WordPress 5.1.

= 2.3 =
* Tested the plugin in WordPress 5.0-beta1 and optimized the code.

= 2.2 =
* Added function "prevent_browser_caching" which disables all admin settings of this plugin and allows to set the new settings.
* Changing "ver" param instead of adding additional "time" param.

= 2.1 =
* Added option to show "Update CSS/JS" button on the toolbar.

= 2.0 =
* Added setting page to the admin panel.
* Added automatically updating CSS and JS files every period for individual user
* Added manually updating CSS and JS files for all site visitors

= 1.1 =
* Added plugin text domain.

= 1.0 =
* First version of Prevent Browser Caching plugin.
