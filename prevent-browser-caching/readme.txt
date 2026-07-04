=== Prevent Browser Caching ===
Contributors: kostyatereshchuk
Tags: browser cache, clear cache, cache busting, versioning, cache
Requires at least: 4.7
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 3.0.0
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
* **One-click update.** The "Update versions" toolbar button forces fresh copies of all assets for every visitor.

= Safe by default =

* External URLs (payment scripts, CDNs, third-party services) are left untouched — some of them break when an unexpected "ver" parameter is added. You can turn external versioning back on with one checkbox.
* Specific files (by part of the URL) or script/style handles can be excluded from versioning — CSS, JS and images alike.
* If a page-cache plugin is active, the HTML freshness headers step aside automatically.

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

Yes. Versioned asset URLs end up in the cached HTML like any others. Note: if your page cache serves stale HTML, visitors will get the old asset versions from it — purge the page cache after big changes. The plugin detects popular page-cache plugins and leaves HTML headers to them.

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

1. The settings page: choose what to keep fresh and when to update versions.
2. Upgrading from 2.x: your settings keep working as before, and one click enables the recommended setup (reversible).

== Upgrade Notice ==

= 3.0.0 =
Your saved settings keep working exactly as before. Open Settings → Prevent Browser Caching to enable the new recommended mode (versions from file modification time, external URLs untouched, image cache busting) with one click.

== Changelog ==

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
