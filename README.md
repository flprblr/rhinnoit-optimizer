# Optimizer

Private WordPress plugin by RHINNO IT that makes WordPress and WooCommerce
pages load faster, and keeps the host's page cache fresh. It runs on any
WordPress site, and adds a second set of corrections when WooCommerce is
active. Built from `wp-plugin-template`, which carries the structure already
solved (bootstrap, settings, assets, i18n, linters), so only the functional
part is written here.

Requires **PHP 8.3+** and WordPress 6.5+. WooCommerce is optional; its half
needs WooCommerce 8.0+.

It was split out of **RHINNO IT - WP Optimizer** 2.8.13, together with
**RHINNO IT - Customizer**, which took the cart, checkout, order and store
half. See [Migrating from WP Optimizer](#migrating-from-wp-optimizer).

## Where the plugins live

Siblings, each its own repository:

```
~/gitlab/ce/
├── wp-plugin-template/           the template this came from
├── rhinnoit-optimizer/           this repository
├── rhinnoit-customizer/          the other half of WP Optimizer
└── rhinnoit-wholesale-pricing/
```

Not a `plugins/` directory inside this one. Nesting them would give every
plugin a shared git history, so a fix to one shows up in the log of all;
`composer.json` and `phpunit.xml.dist` would nest inside each other, which
neither tool handles cleanly; one CI run would cover unrelated plugins and go
red for a change that has nothing to do with them; and tagging `v1.2.0` would
be ambiguous about which plugin it refers to, which breaks any update checker
reading releases.

Keep the built zips in each plugin's own `dist/`, which `composer dist`
regenerates in a second. Committing zips means storing a binary that goes stale
the moment the source changes.

The first commit of this repository is the template at `634cb2b` as
`bin/new-plugin.sh` copied it, before any change of this plugin's own: the
fork point a resync from the template merges against. The `upstream` remote
points at the template, with push disabled.

## Layout

```
rhinnoit-optimizer.php          Header, constants, bootstrap hooks
uninstall.php                   Option cleanup when the plugin is deleted
includes/
├── autoload.php                Namespace to file autoloader
├── class-plugin.php            Container: the WordPress half always, the WooCommerce half when supported
├── class-install.php           Default options on activation and update, and the copy of WP Optimizer's
├── class-settings.php          Declaration and reading of the options
├── class-assets.php            build/ enqueueing (the admin script and style)
├── class-logger.php            wc_get_logger() wrapper, PHP's error log without it; throttled write
├── class-delivery.php          WordPress half: images, fonts, CSS, block styles, hover prefetching
├── class-viewport-hero.php     WordPress half: preloads each screen size's own banner
├── class-housekeeping.php      WordPress half: page head, pings
├── class-databot-loader.php    WordPress half: the Databot chat, once the page has loaded
├── class-page-cache.php        WordPress half: clears cPanel's NGINX cache, and Kinsta's gaps
├── class-canonical-redirect.php WordPress half: no redirect of a request to itself
├── frontend/                   WooCommerce half
│   ├── class-product-image.php  The product's own image loads first
│   ├── class-first-row.php      The first row of a listing loads first
│   ├── class-gallery-strip.php  Lazy-loads Porto's gallery thumbnail strip
│   ├── class-cart-fragments.php No mini cart refresh without a cart, on Porto and Kinsta
│   ├── class-shop-prefetch.php  WooCommerce half of hover prefetching
│   └── class-shop-cache.php     Reports price, stock and sale changes to Page_Cache
└── admin/
    ├── class-menu.php          Shared node, its place in the menu, the page
    ├── class-settings-page.php The settings form: drawn, sanitized and saved
    └── class-plugin-links.php  "Settings" link on the plugins list
src/                            JS/SCSS sources (one entry point, admin: the scss draws the screen)
build/                          @wordpress/scripts output (not committed)
languages/                      .pot, and the es_CL and es_ES catalogues
tests/                          PHPUnit suite with WordPress stubbed
├── stubs/
│   ├── woocommerce.php         WooCommerce, loaded only where WC_VERSION is defined
│   ├── html-api/               WordPress 7.1.2's HTML API, the real parser Viewport_Hero uses
│   ├── porto.php               The slice of Porto the plugin touches
│   └── smush.php               The slice of Smush's settings API Viewport_Hero asks
└── fixtures/viewport-hero/     the_content as the shops print it, cut to what a case needs
bin/release.sh                  Every check, then the zip
bin/build-zip.sh                Packages dist/<slug>-<version>.zip
phpstan-bootstrap.php           Plugin constants for the static analyser only
.github/workflows/ci.yml        Lint, static analysis and tests on every push
```

Every class is registered from `Plugin::__construct()`. To add functionality:
create the class in the matching folder with a `register()` method that only
hooks, and add one line to the constructor.

The constructor has two halves. The WordPress half registers on every site and
touches WooCommerce only behind a guard: `Delivery`, `Viewport_Hero`,
`Housekeeping`, `Databot_Loader`, `Page_Cache` and `Canonical_Redirect`, then
`Assets`. A correction whose job needs WooCommerce goes in
`includes/frontend/` and its line in the
`if ( self::woocommerce_is_supported() )` branch, after `Product_Image`,
`First_Row`, `Gallery_Strip`, `Cart_Fragments`, `Shop_Prefetch` and
`Shop_Cache`. An unguarded WooCommerce call registered anywhere else is a
fatal, on a site without WooCommerce, on every request that reaches it.
`BootWithoutWooCommerceTest` runs with no WooCommerce function defined at all,
but it only boots the plugin: it catches a call made while booting (a
constructor or a `register()`), not one inside a callback. A service whose
callbacks would call WooCommerce needs its own case in a `ROPT_TESTS_WC=none`
process, or the branch it is registered in is its only guard.

Each correction reads its own switch in `register()` through
`Settings::feature( 'key' )`, which answers no for every correction while the
**Apply the optimizations** switch is off. A correction that hooks without
asking keeps working with the switch off - which turns the first question
support asks ("turn it off and tell me if the page still breaks") into a false
negative. `Databot_Loader` has no switch of its own and reads only the master
one (see [What it does](#what-it-does)), and `Shop_Prefetch`, the WooCommerce
half of **Preload pages on hover**, reads that one. Two services hook whatever
the switches say: `Assets`, because the admin bundle has to load with the
plugin off, and `Page_Cache`, which hears every save of the group's screens
even with its switch off, because the save that turns it off still has pages
to clear.

## What it does

Two halves - WordPress, and WooCommerce with one correction to Porto's own
markup - drawn as three cards of the settings screen, one per domain, and
**one switch per correction**, so the one that misbehaves on a site can be
turned off and the rest kept. A master switch above them, **Apply the
optimizations**, turns everything off at once. Every correction decides from
what WordPress knows at that moment - the real weight of a file, its
modification time, the content of a stylesheet, which theme is active - never
from a hard-coded list of paths or handles. A correction that finds the theme
or another plugin already covering it steps aside.

The screen only offers what can act on the site. A card is drawn only where
the site has what it works on (a supported WooCommerce; Porto on one), and a
switch only where what it needs is installed: Kinsta for the Kinsta purge and
the mini cart; Kinsta, or cPanel's uapi on a server with ea-nginx, for both
page cache switches; a classic theme for the block styles (and no
`theme.json` for the classic theme styles, which core then never adds);
WordPress 6.8 for hover prefetching. Read from what is installed, never from
another plugin's settings (a correction that steps aside while Porto's lazy
load is on keeps its switch). A hidden switch keeps its stored value and the
correction still reads it. A card left with nothing to switch is not drawn at
all: the Porto card's one switch needs Kinsta, so a cPanel shop sees no Porto
card.

Every switch ships on, and so does the master switch (owner's decision,
2026-10-16: the plugin goes on every client with its defaults). Defaults apply
to a fresh install and to a switch added by an update; a site that already
saved the screen keeps its choices, and a site that ran WP Optimizer starts
from the choices it made there.

The version numbers below (2.x) are WP Optimizer's releases, where these
corrections were written and measured. This plugin starts at 1.0.0 with the
code of 2.8.13.

**WordPress** - on any site:

| Correction | Default | What it does |
| --- | --- | --- |
| Drop heavier image copies | on | Drops a srcset candidate when a larger one weighs less. |
| Load images later, except the first | on | Core exempts the first three images from lazy loading; this exempts one. Steps aside while Porto's lazy load is on. |
| Preload each screen's own banner | on | For a page whose content opens with two containers that Bootstrap's display classes show on complementary screen widths - hoka's and joia's `#banner-mobile` / `#banner-desktop` sections, easyrider's two rows - prints one `<link rel="preload" as="image" fetchpriority="high">` per banner, with the banner's own `srcset` and `sizes` (no `href`: WebKit before 17.2 ignores `imagesrcset` and would preload the full-size `src`) and the `media` of the widths that show it (`(max-width: 767.98px)` / `(min-width: 768px)` on all three), and makes both banners `loading="lazy"`, `skip-lazy`, without `fetchpriority` and without core's `auto` in `sizes`. Each screen then fetches its own banner first and never the hidden one: core used to leave the first eager on every screen and the second waiting for layout (lab: hoka home desktop 85 to 96, joia home mobile LCP 12-16 s to 7 s). Only the queried page's outermost `the_content` in the main loop (Porto's nested calls never count), only when nothing that renders precedes the pair, each banner is the first `<img>` of its container and at least core's `wp_min_priority_img_pixels`, and the split falls at sm, md or lg (576, 768, 992 px, fixed in Porto; xl and xxl follow its container width and are never trusted). A third screen size, overlapping or gapped widths, a missing `srcset`, a `<picture>`, another lazy loader, a hidden attribute, an inline `display: none`, a `<noscript>`, `<template>` or closed `<details>`/`<dialog>` around it, WPBakery's or Elementor's hidden classes, or a parse that stops leave the content byte for byte as it came. Smush's Local WebP is told to rewrite `imagesrcset` as it rewrites `srcset`; steps aside while Porto's lazy load, Smush's CDN or Smush's critical image preload is on (as Smush's own settings API reports, asked on `wp`; the stored row only when that API is missing). |
| Embed font stylesheets in the page | on | Inlines a small font-only sheet under uploads and gives every face `font-display: swap`. |
| Avoid outdated styles in browsers | on | Versions stylesheets under uploads by their mtime, so a regenerated one is not cached for a year. A URL that already carries another query argument, its own cache-buster, is left as it came: Trustindex prints the same URL in a `<link>` of its own, and a second URL meant a second download. |
| Remove global styles | on | The ~11 KB `global-styles` CSS, head and footer copies. Not offered on a block theme. Core's `:where(body){margin:0}` stays, inline and at zero specificity: Hello Elementor without its reset.css framed every page in the browser's 8 px margin. Block-editor pages and WooCommerce blocks use its layout rules: check them after installing. |
| Remove block button styles | on | `classic-theme-styles`, the Button and File block CSS. Not offered on a block theme or a theme with `theme.json`, which core gives none. |
| Remove the RSD link | on | Discovery for old XML-RPC clients; XML-RPC itself stays. |
| Remove the shortlink | on | In the head and in the `Link` response header. |
| Remove the oEmbed links | on | Other sites then show a plain link instead of an embed card. |
| Remove the version tags | on | Empties core's `the_generator` filter, which carries WordPress's `<meta name="generator">` and the WooCommerce one `wc_generator_tag()` appends to it, in the head and in every feed's `<generator>`. Site Kit's, Elementor's and WPBakery's tags are printed by those plugins on `wp_head` and stay. |
| Disable pingbacks and trackbacks | on | `pings_open` false, the two XML-RPC pingback methods and the `X-Pingback` header. The rest of XML-RPC and the REST API link stay. |
| Clear the cache after changes | on | Saving this screen, or the settings screen of any plugin of the group, always clears it. cPanel NGINX: clears the account's cache after content, menu, theme option, Porto builder, plugin and settings changes. Kinsta: only what its own plugin misses (Porto options, the WordPress Customizer, Porto builder, plugin updates, the group's settings screens). See [Page cache](#page-cache). |
| Avoid redirects to the same page | on | Where `redirect_canonical` builds a target of its own - the static front page, and page 2 onwards of a listing - it rebuilds the query with `add_query_arg()`, which drops the `=` of an empty value and writes a space as `%20`, so `?utm_term=` or `?utm_term=hoka+bondi` (both sent by Google Ads) get a 301 to the same URL written the other way (measured on hoka's and joia's home and on hoka's `/page/2/` listings, 2026-10-05; other pages answer 200). A cache that leaves tracking parameters out of its key, as Kinsta's does once asked to, stores that 301 as the bare URL and sends every visitor to the ad's address; hoka's home served one from 10:43 to about 10:50, and Kinsta purges the home page on every stock change, so each purge reopens the race. `redirect_canonical` at priority 99 returns false when the target has the same scheme, host, port, path (the case of `%XX` octets aside: core lowercases them in the request only) and decoded query as the request; `page_id`, trailing-slash, https, `?p=` and `paged` redirects change one of those and stay. |
| Preload pages on hover | on | WordPress 6.8+ hands its speculation rules eagerness `auto` (resolved to prefetch on press); this makes it `moderate` (on hover). An explicit eagerness, a `WP_SPECULATIVE_LOADING_DEFAULT_*` setting in `wp-config.php` or the environment, and a prerender mode are left alone. With WooCommerce: the cart, checkout and account pages are never prefetched, a visitor with a cart keeps prefetch on press (every page they request is uncached), and adding to the cart sends `Clear-Site-Data: "prefetchCache"` so a page prefetched with an empty cart is not shown afterwards. The page where an item goes in over AJAX was built without a cart, so a footer script switches its rules to press on `added_to_cart` (on a phone, moderate prefetches on every pause of the scroll). |

Without a switch, by the owner's decision, and never shown on the settings
screen: when the Databot chat plugin is active, `Databot_Loader` takes its place
on `wp_footer` (priority 9, removing `databot_window`). Since 2.7.5 it prints a
few bytes of inline script that insert the same script (same `src`, `id` and
`bot`, which the chat reads through `document.currentScript` and from which it
derives its server) and the same stylesheet once the page has loaded and the
browser is idle (two seconds at most). The plugin's deferred script held
DOMContentLoaded, and the two iframes it builds from Heroku held the load event,
the browser's progress bar and Porto's product carousels, which start on load:
measured on hoka's home, load at 1.74 s on desktop and 2.05 s on phones with the
chat, 0.79 s and 0.78 s with it inserted after load; the chat bubble shows about
half a second later and opens the same. If the settings cannot be JSON-encoded
it falls back to the plugin's tags with the stylesheet as `media="print"`
(2.7.1's fix: the plain link, served from Heroku in 0.6-0.9 s, held the first
paint in 3 to 4 of every 10 mobile runs). Only the master switch and the
`ropt_databot_async` filter turn it off.

**WooCommerce** - shown only when WooCommerce 8.0+ is active:

| Correction | Default | What it does |
| --- | --- | --- |
| Load the product image first | on | Keeps the product's featured image eager, with `fetchpriority="high"`, on its own page. The product and its image are read once on `wp`, and only their first large rendering inside the product's own loop is marked (a thumbnail-size copy, such as Porto's sticky add-to-cart bar, never takes the mark): asked inside the image filter, Porto's posts grid (which sets the queried object to each item) made every grid thumbnail of a static home page, and every related thumbnail, eager and high priority. |
| Load the gallery thumbnails later | on | Every `<img>` printed inside `woocommerce_product_thumbnails` (the strip under a product's gallery) gets `loading="lazy" decoding="async"` unless it has a loading attribute or `fetchpriority="high"`. Porto prints the strip raw from `wp_get_attachment_image_src()`, so core never lazies it; with a lazy-load plugin off, its eight 786 px thumbs were fetched with the main image and the main image painted a cached thumb instead of its own request (hoka product mobile 4.9 s LCP, 3.9 s with this). The main image is printed before the action, so it is never touched; WooCommerce's own strip already carries loading, so without Porto nothing changes. Steps aside while Porto's lazy load is on. |
| Prioritize the first product row | on | Shop, product categories and tags, product search, main loop only. The first products the loop renders, as many as a row holds (`wc_get_loop_prop( 'columns' )`), get their image `loading="eager"` and `skip-lazy` (so Smush's lazy load leaves it alone); Porto's hover image leaves lazy and gives up the `fetchpriority="high"` core handed it, and the first product's image takes it. A hint core gave outside the loop stays there, and a listing that opens with subcategory tiles or Porto's category image (plain `<img>` tags core never sees) keeps its row lazy. Past the row, `loading="lazy"` is written out, so a later pass that decides on the final HTML (Elementor's image optimisation) cannot wave the next images through. An eager image picks its file from `sizes` before layout, and WooCommerce's says full width (phones fetched the 768 px file for a 180 px tile), so the row's `sizes` is rebuilt from the `ccols-*` classes of Porto's products list, read from `woocommerce_product_loop_start`: one `(min-width: <Porto's breakpoint>) <100/n>vw` step per column count, which is never below the tile at any width (hoka and joia: `(min-width: 1220px) 25vw, (min-width: 576px) 33.34vw, 50vw`). List view, the creative grid, sliders and any other markup keep WooCommerce's value. An image this correction makes lazy gets the `auto` core gives its own lazy images (WordPress 6.7+). Porto's shop builder column count wins over WooCommerce's, as in Porto's loop; a Porto posts grid keeps its count in the shortcode, where the row can come out a product or two off. Positional, so it holds whatever the products are. Steps aside while Porto's lazy load is on. |
| Cut cache clears on stock changes | on | Kinsta only. Turns off Kinsta's purge on every stock count change (each sale, each API sync), leaving the purge when a product, or one of its WeLaunch Multi Inventory stores, sells out or comes back. For shops that show "In stock" rather than a count. The quantity field's limit may lag the cache's hour; the cart refuses more than there is. Needs both page cache switches. |
| Clear the cache when products change | on | Both hosts: prices, sale dates, featured and catalog visibility, product order and attributes saved without a post update (variations panel, bulk price actions, REST, CSV, an ERP), scheduled sales, WooCommerce settings and product permalinks. A product going in or out of stock, or one of its WeLaunch Multi Inventory stores: on Kinsta that product and its archives, on cPanel deferred to at most one clear every 15 minutes. |

**Porto** - shown only on Kinsta, with WooCommerce and the Porto theme (a child
theme included): its one switch needs Kinsta, and a card with nothing to switch
is not drawn.

| Correction | Default | What it does |
| --- | --- | --- |
| Avoid refreshing an empty mini cart | on | Porto on Kinsta only (cPanel's NGINX cache serves cached pages to visitors with a cart). Dequeues `wc-cart-fragments` for visitors without the `woocommerce_items_in_cart` cookie, which saves one uncached `get_refreshed_fragments` request per visit, and draws the empty mini cart on the server where Porto prints its loading spinner (`porto_minicart` filter). A few bytes in the footer reload a page restored by the back button or left open in a second tab once a cart exists. WooCommerce makes the cart cookies browser-session cookies while the cart lives as long as the session cookie (two days for a guest), so a shopper who closed the browser came back to cached pages and an empty mini cart: on every request with a cart both are sent again with the session cookie's expiry. Needs a page cache that skips visitors with a cart, as Kinsta does. |

WP Optimizer 2.0 had one switch for the whole page head, and its global styles
switch also removed `classic-theme-styles`; 2.1 split them, and every site on
2.1 or later has each piece's own row. That is why this plugin declares no
`'inherits'` and no removed switch: the copy from WP Optimizer finds every
switch already split. The 2.0 head switch also removed the WordPress generator
tag. 2.1 dropped it: it was already gone on every site measured then. 2.8.2
brought it back as its own switch, because on 2026-10-16 hoka and joia printed
`WordPress 7.1.3` and `WooCommerce 11.2.1` in the head and `<generator>` in the
feed; grupoferia and tucasafacil printed neither, and easyrider answers anything
but a browser with a bot challenge. 2.2 dropped the revision limit:
`WP_POST_REVISIONS` in `wp-config.php` is where that belongs, and
`wp-config.php` always wins.

What it deliberately does **not** do, because every shop it runs on uses Porto
and Porto (or the page builder, or Yoast) already does it: disable emoji,
embeds, jQuery Migrate, Heartbeat or the block library; minify; move jQuery;
preload icon fonts; rules in `robots.txt` (both shops already block the cart,
checkout, account and search there). Nor anything set in `wp-config.php` (cron,
revisions, memory): a constant there always wins, so a switch here could only
be overruled.

### cPanel and Kinsta

One set of defaults serves both hosts; there are no profiles to pick. What
does depend on the host, the plugin reads itself: `Plugin::kinsta_is_active()`
(the constants of Kinsta's MU plugin, unless cPanel's uapi is present) decides
which cache `Page_Cache` clears and whether the mini cart correction may run.

Hover prefetching shipped on only on Kinsta in 2.2 to 2.4, on the idea that a
host without a page cache pays a full render per hover. Both hosts the shops
run on cache pages - Kinsta, and cPanel's NGINX, which caches anonymous pages
for 60 minutes - and Chrome keeps at most two hover prefetches in flight, so
since 2.4.1 it ships on everywhere. A site that stored "off" in WP Optimizer
keeps it, copied with the rest: turn it on once on the screen.

On a WeLaunch Multi Inventory shop, a returning shopper's links carry
`?inventory=<store>`, which Kinsta never caches, unless RHINNO IT -
Customizer's **Remove the store from links** is on: for those shoppers, this
plugin's page cache and hover prefetching pay off only with it. A WeLaunch shop
runs both plugins.

## Page cache

Kinsta's MU plugin clears its cache on posts, comments, terms, menus and
stock. cPanel's NGINX cache (the `ea-nginx` package, "NGINX Manager" in cPanel)
clears nothing on its own: pages stay for 60 minutes unless someone presses
**Clear Cache**. `Page_Cache` does that press for them.

- **How.** cPanel's own API, `NginxCaching::clear_cache`, run as
  `/usr/local/cpanel/bin/uapi --output=json NginxCaching clear_cache` through
  `proc_open` with a fixed argument list and no shell. PHP already runs as
  the account user, so no API token is stored anywhere - a cPanel token cannot
  be limited to one function and would give whoever reads it the whole
  hosting account. Only `result.status` 1 counts as done.
- **Only everything.** ea-nginx has no per-URL purge, so every clear empties
  the whole account's cache, every domain in it. Hence the grouping: changes
  only record a reason, and one clear runs at the end of the request, after
  the response has been sent. Editors' changes clear at most every 30
  seconds. Stock changes and anything a visitor triggers never clear on the
  shopper's request: they schedule one WP-Cron event, at most one every 15
  minutes. So do writes through the WooCommerce API (`/wc/v1`-`/wc/v3` and the
  legacy `/wc-api/`), where an ERP can send one request per product, and price
  changes a connector plugin makes from WP-Cron; the block editor's REST saves
  (`/wp/v2`) clear right away like any other edit. Drafts and private
  products report nothing. A stock *count* change on
  every order is deliberately not hooked, only a stock *status* change - and,
  with WeLaunch Multi Inventory, one store running out or restocking
  (`woocommerce_multi_inventory_inventories_stock`, watched through
  `added_post_meta` / `update_post_meta` / `delete_post_meta`, which every
  writer reaches; a blank store counts as none, as Multi Inventory reads it). Its
  "Modify Stock Quantity" option makes the page show the default store's
  availability, which empties while the product's total, and so WooCommerce's
  status, stays in stock.
- **Kinsta.** Kinsta purges one post per request itself - only one: a shared
  flag stops its post and stock purges after the first. A product whose price
  or visibility changed without a post update (an ERP writing through the
  API), or that went in or out of stock, is purged here alone, with its home
  and archives, through Kinsta's per-post purge (`initiate_purge()`), never
  the whole cache; more than 20 products at once is one full purge. Kinsta's
  own stock purge also runs on every stock *count* change - each sale, each API
  sync. Its settings screen has no switch for that one; **Cut cache clears on
  stock changes**, shown only on Kinsta, turns it off through Kinsta's
  documented `kinsta/kmp/cache/autopurge` filter, leaving the status
  and store purges above. The quantity field's limit still follows the count
  and may lag the cache's hour; the cart refuses more than there is.
  Porto's options and header builder, the WordPress Customizer, Porto
  builder posts, plugin updates, WooCommerce settings, scheduled sales,
  changes to many products at once, and every post after the first of a bulk
  edit call Kinsta's full purge
  (`$kinsta_cache->kinsta_cache_purge->purge_complete_caches()`, what WP
  Rocket calls; checked before use, and Kinsta's one-purge-per-request flag
  is reset first so a purge earlier in the request does not swallow it). A
  site that kept Kinsta's MU plugin after moving to cPanel counts as cPanel.
- **Elementor.** Regenerating Elementor's CSS (Tools > Regenerate, a Site
  Settings save, an experiment toggle, the database upgrade after an update,
  Replace URL, `wp elementor flush-css`) deletes the files under
  `uploads/elementor/css/` that every cached page links, and a cached page
  never asks for new ones: on cPanel, `elementor/core/files/clear_cache`
  clears at once, from whatever request ran it. Switching Elementor's
  maintenance or coming-soon mode (`elementor_maintenance_mode_mode`) clears
  as an editor's change: a coming-soon page answers 200 and was cached for
  every address visitors opened, so a launch kept showing it. Picking or
  clearing the mode's page (`elementor_maintenance_mode_template_id`) turns
  the mode on or off too, as Elementor applies it only with both set, so
  that clears while a mode is set. Kinsta's plugin purges on the CSS and on
  mode changes itself, so there those are left to it; the mode's first save
  (which adds the option; only when it sets a mode, as any Tools setting
  saved for the first time adds it too) and the page change fire nothing
  Kinsta hears, and are purged here on both hosts. Elementor Pro also deletes the CSS when it
  refreshes its license data, from the first logged-in request - a
  customer's included - about every 12 hours, or every 30 minutes while its
  license server cannot be reached: each one clears the account, because the
  files are already gone.
- **Root.** WP-CLI with `--allow-root` or a root cron runs uapi with
  `--user=<owner of the plugin files>`, the account the site belongs to.
- **Saving a settings screen of the group** - this plugin's, the
  Customizer's, or any other that fires `rhinnoit_settings_saved` - always
  clears, at once, whether or not a value changed: on cPanel without waiting
  the 30 seconds, on Kinsta even with Kinsta's own autopurge off
  (`purge_complete_caches( true )`). It clears when **Clear the cache after
  changes** was on before the save or is on after it, so turning everything
  off, or back on, updates the pages too. "Save" doubles as "clear
  the cache".
  On Kinsta the save then schedules a check five minutes later, once
  Kinsta's edge has had the 2-5 minutes it documents to clear: WP-Cron asks
  for the home page through the same edge visitors use, and if that is still
  a copy the edge stored before the purge (a HIT whose Age, or failing that
  its Last-Modified, is older), it purges once more, forced, and checks again five minutes
  later; still older, and the plugin's log says so. Twice, on joia and
  tucasafacil, Kinsta's purge left the home page cached for an hour, and a
  controlled test right after cleared both times. Kinsta's dates are when
  the edge stored a copy, so a copy re-stored from an older origin copy
  passes; what is caught is the edge keeping its own. An answer it cannot
  read - a bot challenge, a redirect, no dates - is left alone.
- **Only with NGINX, on the screen.** On cPanel the settings screen offers
  the two cache switches only where PHP sees a file the ea-nginx package
  installs (`/etc/nginx/conf.d/ea-nginx.conf` or
  `/usr/local/cpanel/scripts/ea-nginx`): without one, Apache or LiteSpeed serve
  the pages and there is nothing to clear. The clear itself never asks: a
  CloudLinux cage or a jailed cron can hide `/etc/nginx` from PHP while uapi
  still clears, and a clear that stopped silently would leave every edit
  stale for an hour. Whether the account itself has NGINX caching turned on
  cannot be read from the account - uapi answers `status: 1` either way and
  the cache directory belongs to root, checked on two servers with caching on
  and off on 2026-10-16 - so an account with caching off still gets both
  switches, and its clears are harmless no-ops.
- **Anywhere else** the service does nothing and says nothing.
- **Failures** are logged once a day each and back off for an hour: PHP with
  any of the process functions disabled, uapi that cannot start, times out
  (20 s, then TERM, then KILL), answers something that is not JSON, or
  refuses. While a clear runs, a lease keeps other requests from starting
  another.

Two facts about ea-nginx shape the rest of the plugin: it skips its cache only
for logged-in users (and only when WP Toolkit knows the site), never for a
visitor with a cart - which is why the mini cart correction is Kinsta only -
and it caches anonymous REST responses too.

To check a cPanel server once, as the account user in cPanel's Terminal:

```bash
time /usr/local/cpanel/bin/uapi --output=json NginxCaching clear_cache
```

A `"status":1` means the plugin can clear it too.

### The group's hooks

`Page_Cache` is the one listener of the two hooks every plugin of the group
fires, so what the other plugins print in cached pages stays fresh through it:

- **`rhinnoit_settings_saved`**, which a save of any settings screen of the
  group fires (`Settings_Page::SAVED_HOOK`), is the save described above:
  heard whatever this plugin's switches say, it clears when **Clear the cache
  after changes** was on before the save or is on after it.
- **`rhinnoit_page_cache_dirty`** (`Install::DIRTY_HOOK`) carries a reason
  and, for one product, its id. Every plugin of the group fires it with
  `'site'` when an update changes its version - a build copied over the
  folder fires no upgrader hook - and `Shop_Cache` fires it with `'stock'`,
  `'sale'` or `'product'` and the product id. It takes `content`, `site`,
  `stock`, `sale` and `product`, and drops any other reason without a word.

The second hook, like every other change listed in this section, is heard only
while **Clear the cache after changes** is on. Activating or deactivating any
plugin is a change to every page, through core's `activated_plugin` and
`deactivated_plugin`. So is an option of this plugin's written outside its
screen (WP-CLI, code), but not one of another plugin of the group: only this
plugin's own options are watched, so after changing the Customizer's from
WP-CLI, save one of the screens.

`Page_Cache::SAVED` and `Page_Cache::DIRTY` hold the two names written out, so
a storefront request loads neither the admin class nor `Install` to read them,
and `PageCacheTest` holds each to the constant the shell fires.

## Admin interface

The plugin hangs off a shared node, right below Dashboard:

```
Dashboard
RHINNO IT                ← shared node
├── Optimizer            ← this plugin
├── Customizer           ← another plugin of the group
└── ...
────────────────
Posts
```

The node is created by the first plugin of the group to load, and the rest
detect it through the trace `add_menu_page()` leaves in `$admin_page_hooks`.
There is no shared library and no stored state: deactivate the one that created
it and the next one creates it. The three names live in constants at the top of
`rhinnoit-optimizer.php`:

| Constant                 | What it is                | Shared |
| ------------------------ | ------------------------- | ------ |
| `ROPT_MENU_PARENT_SLUG`  | Parent node slug          | Yes    |
| `ROPT_MENU_PARENT_TITLE` | Parent node label         | Yes    |
| `ROPT_MENU_TITLE`        | Label of this plugin      | No     |

The two shared ones must hold **the same value in every plugin of the group**,
together with `Menu::PARENT_ICON`, `Menu::MENU_POSITION` and
`Menu::CAPABILITY`: whichever wins the race defines how the node looks, and the
order depends on the folder name. Their values deliberately carry no plugin
prefix, so creating a plugin from the template leaves them alone.

**Staying below Dashboard** is not something `MENU_POSITION` can promise. When
the slot is already taken, `add_menu_page()` resolves the tie by adding a
fraction derived from `md5( slug . title )`, so between two plugins asking for 3
the winner is effectively random - on a shop with Elementor and a theme addon
the node ended up fourth. `Menu::move_below_dashboard()` filters `menu_order`
instead, which runs once every menu is registered and is the only place the
answer is deterministic. It runs at `PHP_INT_MAX`: Site Kit splices its own
node right after Dashboard from a `menu_order` filter it adds on `init`, after
this plugin's, so at the same priority Site Kit ran last and took the slot on
the shops that have it. It needs `custom_menu_order` to return true, which is
what `Menu::enable_menu_order()` is for. Every plugin of the group moves the
same slug to the same place, so it does not matter how many of them do it.

The icon is `Menu::PARENT_ICON`, a [Dashicons](https://developer.wordpress.org/resource/dashicons/)
class. Change it in every plugin of the group at once: the one that creates the
node is the one whose icon shows, and which one that is depends on load order.

The node renders nothing itself, so clicking it opens the first plugin listed
under it — WordPress takes the href of a top-level item from its first child,
and which plugin that is depends on load order. That is also why
`Menu::register_pages()` ends with a `remove_submenu_page()`: core inserts a
back-link to the parent as the first child of a node that had none, and with a
callback-less parent both the node and that back-link resolve to
`/wp-admin/rhinnoit-tools`, a hard 404. Do not drop that line when rewriting
the method.

With two plugins of the group installed, whichever loads first creates the node
and gives it its href; the second finds the submenu already declared, so core
inserts no back-link for it and its own `remove_submenu_page()` is a harmless
no-op. Mixed versions are safe in both directions: a plugin built on this
template repairs a node that an older sibling left dead, and an older sibling
registering afterwards cannot break it again, because core only inserts that
back-link for a node with no children at all. Three cases in `AdminTest` hold
that down.

The node has one screen per plugin, and this plugin's is its settings form and
nothing else: the versions a support request asks for are already in Tools >
Site Health > Info and on the plugins list, and reports beside the switches
make them harder to find.

The form draws its own fields, because `WC_Admin_Settings` does not exist on a
site without WooCommerce. It uses the card-and-row pattern of
`@wordpress/components` (the look of Site Health and the newer WooCommerce
screens) in plain PHP markup, styled by `src/admin.scss`: a card per section,
small group labels inside it, and a row per setting with its title and one line
of description on the left and an on/off switch on the right. The switches are
real checkboxes with `role="switch"`, so the form posts the same thing with or
without the stylesheet, and no JavaScript is involved. It knows three types:
`title`, `sectionend` and `checkbox` (the template's fourth, `multicheck`, went
with the storefront lists). A new one goes into `Settings_Page::render_field()`
and `Settings_Page::sanitize_submission()` together. The save reads only the ids
the field list declares, each through its own type's sanitizer, so a crafted
post cannot create an option - and only the fields the form says it drew, so a
section that appeared while the form was open (WooCommerce activated in another
tab) is not saved as all unchecked.

The form posts to `admin-post.php` with a nonce and a check of
`Menu::capability()`: `manage_woocommerce` where WooCommerce is active, shared
by the group, and `manage_options` where it is not - WooCommerce registers
`manage_woocommerce` itself, so on a plain WordPress site nobody would hold it.
It redirects after saving.

Two hooks are the group's, not the plugin's, and carry no prefix for the same
reason as the menu constants: `rhinnoit_settings_saved`
(`Settings_Page::SAVED_HOOK`), which the form fires after every save, and
`rhinnoit_page_cache_dirty` (`Install::DIRTY_HOOK`), which
`Install::check_version()` fires with `'site'` when an update changes the
version. A switch or an update can change what every page prints, and every
plugin of the group fires the same two. This plugin fires both and is the one
that hears them: see [The group's hooks](#the-groups-hooks).

Each domain is a card of the one form, not a tab: WordPress, WooCommerce and
Porto, under the master switch's untitled card. One screen needs no tab bar,
and a second tab meant a click on every visit to reach the thing anyone came
for.

The screen offers only what can act on the site. A card is drawn only where its
section mark holds - `Settings::REQUIRES_WOOCOMMERCE`, or `REQUIRES_PORTO`
(Porto as the parent theme, on a supported WooCommerce; the same mark and the
same answer as the Customizer's) - and inside any card an option that carries
`'needs'` is drawn only where the site has every mark it names.
`Settings::conditions()` answers each mark from what is installed - a plugin's
constant, the host, the theme's kind, a core function - never from another
plugin's settings, and with the same condition the feature itself stops at,
since hiding an option turns nothing off. A mark it does not answer hides its
option on every site, which is what `SettingsNeedsTest` catches. A hidden
option keeps its row, so the day what it needs is installed it comes back with
the value it had. This plugin's needs are `NEEDS_KINSTA`
(`Plugin::kinsta_is_active()`), `NEEDS_HOST_CACHE`
(`Page_Cache::can_clear()`: Kinsta, or uapi with ea-nginx's files),
`NEEDS_CLASSIC_THEME` (no block theme), `NEEDS_NO_THEME_JSON` (no
`theme.json`) and `NEEDS_SPECULATION_RULES` (core's
`wp_get_speculation_rules_configuration()`, WordPress 6.8); a new one comes
with the option that needs it. A card left with no option to draw is not drawn
at all.

The page slug is the plugin slug, so the URL is
`admin.php?page=rhinnoit-optimizer` and says which plugin the screen belongs to
— the way core's own `page=wc-settings` does. It lives in `Menu::PAGE_SLUG`,
and the renamer rewrites it through the **slug**, not the function prefix, so
anything comparing against it uses the constant: a literal `'ropt'` somewhere
else would be rewritten through the prefix and the two would drift apart in
every copy.

## Stripped from the template

The template carries scaffolding so that adding is cheap. This plugin ran two
of its strip recipes - the storefront lists, and a plugin with admin JS but no
storefront JS - so these pieces of the template are not here, on purpose, and a
resync from the template leaves them out:

- The two storefront lists, `ropt_scope_wordpress` and
  `ropt_scope_woocommerce`, with `Settings::wordpress_scope_choices()`,
  `woocommerce_scope_choices()`, `frontend_contexts()` and `chosen()`, and the
  `multicheck` type that drew them (`Settings_Page::render_multicheck()` and
  `.ropt-group__desc`).
- The storefront bundle: `src/frontend.js`, `src/frontend.scss`,
  `Assets::enqueue_frontend()`, `scope_matches()` and `is_plain_page()`, the
  `$extra_deps` parameter of `Assets::enqueue()`, and the
  `ropt_broken_build_frontend` transient.

Everything this plugin adds to a page is printed inline (see
[Assets](#assets)), so nothing here needs a storefront bundle, and without one
the lists had nothing to choose. What each case of those pieces became is in the
commit that removed them, `34204dd`.

## Conventions

- **Files**: `class-{hyphenated-name}.php`, one class per file.
- **Namespace**: the subfolder of `includes/` is the namespace segment
  (`includes/admin/class-settings-page.php` →
  `RhinnoIT\Optimizer\Admin\Settings_Page`; a class of the WooCommerce
  half goes in `includes/frontend/` and lands under `...\Frontend`). The
  autoloader relies on this.
- **Types**: every signature carries parameter and return types. No
  `declare( strict_types=1 )`: WordPress passes loosely typed values through the
  hooks and turning it on raises `TypeError` in production.
- **Options**: always prefixed with `ropt_` and declared in
  `Settings::fields()`, which is where the activation defaults come from. Add
  them to `uninstall.php` too.
- **WP Optimizer's names**: `Install::LEGACY_PREFIX` (`rwpo_`) and
  `Plugin::LEGACY_CONSTANT` (`RWPO_VERSION`) name the plugin this one replaces,
  and never follow a rename (see *Migrating from WP Optimizer*).
- **Escaping**: sanitize on input, escape on output (`esc_html`, `esc_attr`,
  `esc_url`, `wp_kses_post`).
- **Strings**: everything visible goes through `__()` / `esc_html__()` with the
  `rhinnoit-optimizer` text domain.

## Commands

```bash
composer install     # PHP linters and static analysis
composer lint        # phpcs (WooCommerce-Core)
composer lint:fix    # phpcbf
composer analyse     # phpstan level 8, with WordPress and WooCommerce stubs
composer test        # phpunit
composer dist        # rebuilds build/, then packages dist/<slug>-<version>.zip
composer release     # all of the above, then the zip; stops at the first failure
npm install
npm run build        # compiles src/ into build/
npm start            # build in watch mode
npm run format       # prettier over src/
npm run lint:js
npm run lint:css
```

`composer analyse` gives PHPStan 2G, where the template gives it 1G: from a
cold cache this plugin's code needs about 1.1G.

## Private plugin

- The header carries `Update URI: false`, which stops wordpress.org from serving
  updates should the slug collide with a public plugin.
- There is no `Plugin URI`: it is the public website of the plugin and WordPress
  renders it as a link on the plugins list. `Author URI` points to
  `mailto:dev@rhinnoit.cloud`, so the author name becomes a contact link.
- No `readme.txt` is needed: only the wordpress.org directory uses it.
- `load_plugin_textdomain()` **is** needed: WordPress only resolves the
  translations on its own for plugins hosted on wordpress.org.
- To update the plugin across several shops without copying it by hand, the
  usual route is `yahnis-elsts/plugin-update-checker`, which reads releases
  straight from a GitHub repository and supports private ones through a token.

## Versioning

Bump the `Version:` plugin header and the `ROPT_VERSION` constant together.
WordPress and `composer dist` read the header; `Install::check_version()` reads
the constant, and a mismatch means the upgrade path silently
stops re-seeding new options. `tests/PackagingTest.php` fails when they drift.

Bump both on every release of this plugin. It started at 1.0.0, the number the
template gives a new plugin, with the corrections of WP Optimizer 2.8.13: a
version that starts with 2 in this README is WP Optimizer's.

## PHP version

The floor is PHP 8.3, declared in `Requires PHP` (plugin header), `testVersion`
(`phpcs.xml.dist`) and `"php"` (`composer.json`). WordPress blocks activation
when the server does not reach the minimum, so keep it at the lowest version any
of the shops actually runs.

## Translations

The plugin is written in English and ships Spanish for Spain (`es_ES`) and for
Chile (`es_CL`). WordPress does not fall back from one Spanish locale to
another, so each needs its own files; they differ only where the two countries
use different words ("añadir" / "agregar"). Any other locale sees English. The
catalogues carry WP Optimizer's translations of every string that came from
it.

After changing a visible string, build first, then regenerate the template,
update both catalogues and compile them (WP-CLI and gettext):

```bash
npm run build
wp i18n make-pot . languages/rhinnoit-optimizer.pot --domain=rhinnoit-optimizer --exclude=tests,vendor,node_modules,src
wp i18n update-po languages/rhinnoit-optimizer.pot languages/
msgfmt -o languages/rhinnoit-optimizer-es_ES.mo languages/rhinnoit-optimizer-es_ES.po
msgfmt -o languages/rhinnoit-optimizer-es_CL.mo languages/rhinnoit-optimizer-es_CL.po
wp i18n make-json languages --no-purge   # strings coming from the .js files
```

Without WP-CLI, write the entries into the `.pot` and both `.po` files in the
format they already use - a `#:` line naming the file, without line numbers -
and run the two `msgfmt` lines.

A string from a bundle is read from `build/`, not `src/`:
`wp_set_script_translations()` finds the JSON make-json writes by a hash of the
built file's path, so the `.po` has to name that file. `build/` is not in git,
which is why the build comes first: a stale one gives a stale template. Today
`src/admin.js` holds no string.

`tests/TranslationsTest.php` fails when a string in the code - the PHP or a
bundle source in `src/` - is missing from the template, when either locale
leaves one untranslated or changes its placeholders, and when a `.mo` no longer
matches its `.po`. It cannot read plurals or contexts yet, so it also fails on
any `_n()` or `_x()`: teach it those before using one.

## Tests

`composer test` runs PHPUnit against the plugin classes with the slice of
WordPress they touch stubbed in `tests/bootstrap.php`. No database and no
containers, so it runs in CI in seconds.

`ROPT_PATH` points at a temporary sandbox whose `includes/` is a symlink to the
real source, which lets the tests create and corrupt `build/` files without
touching the repository.

Some cases run in their own process, because they depend on a constant and a
constant cannot be changed once the suite has defined it. The WooCommerce
support gate reads `WC_VERSION`, so `tests/bootstrap.php` takes it from
`ROPT_TESTS_WC`: unset is a supported version, `7.9` one below the minimum,
`none` no WooCommerce at all - and then `tests/stubs/woocommerce.php`, the
WooCommerce functions and classes, is not loaded either, so the WordPress half
is tested on exactly what a plain WordPress site has. The child process
inherits whatever the test class put in the environment before it was spawned.
Anything else gated on a constant needs the same treatment:

- `ROPT_TESTS_KINSTA=1` defines Kinsta's `KINSTAMU_VERSION`, for
  `BootOnKinstaTest`, `CartFragmentsTest`, `KinstaDefaultsTest`,
  `PageCacheOnKinstaTest`, `SettingsOnKinstaTest` (the switches only Kinsta is
  offered), `SettingsWithoutPortoTest` and `ShopCacheOnKinstaTest`.
  `KinstaNamespacedConstantTest` defines the newer `Kinsta\KMP\VERSION` alone in
  its own process, and `HookArityTest` runs a second time on Kinsta in one.
  `CartFragmentsOffKinstaTest` runs in the shared process, where neither is
  defined.
- `ROPT_TESTS_SMUSH=1` defines `WP_SMUSH_VERSION`, for
  `ViewportHeroWithSmushTest`; `ROPT_TESTS_SMUSH=api` loads
  `tests/stubs/smush.php`, Smush's settings API, too, for
  `ViewportHeroWithSmushApiTest`.
- `ROPT_TESTS_WP=6.6` leaves out what core added in 6.7 and 6.8 - auto sizes
  and speculative loading - for `FirstRowBeforeAutoSizesTest` and
  `SettingsBeforeSpeculationRulesTest`.
- `tests/stubs/porto.php` is the slice of Porto the plugin touches;
  `ROPT_TESTS_PORTO=none` boots without it, which is how
  `CartFragmentsWithoutPortoTest`, `DeliveryWithoutPortoTest` and
  `SettingsWithoutPortoTest` check a theme that is not Porto. The stub of
  `get_template()` answers `porto` exactly while that file is loaded: it asks
  for `porto_minicart()`, a function of this plugin's Porto stub.
- `ROPT_TESTS_WP_OPTIMIZER=1` defines WP Optimizer's `RWPO_VERSION`, for
  `BootWithWpOptimizerTest`: the guard and the copy while WP Optimizer is
  still active.

`HookArityTest` registers every service and checks each hooked method against
the arguments its hook passes; a method that needs more is an
`ArgumentCountError` on every request that fires the hook, which no other case
would see because the stubs never call anything. A new service goes into its
list. It runs once off Kinsta and once on it, where the mini cart correction
and `Shop_Cache`'s Kinsta filter hook too.

`Viewport_Hero` parses the page content with WordPress's HTML API, so the
bootstrap loads a copy of the classes its Tag Processor needs from
`tests/stubs/html-api/` (WordPress 7.1.2, unchanged) whenever
`WP_HTML_Tag_Processor` is not already defined, and its cases run the real
parser on `tests/fixtures/viewport-hero/`: the_content as hoka, joia,
easyrider and grupoferia printed it on 2026-09-24, cut after the part the rule
reads. Refresh the copy from a newer core by replacing the seven files.

The suite is not decoration. Most of it covers a defect that reached a fatal
error or a dead admin page before it was fixed; the rest pins a decision that
is cheap to undo by accident — that the node stays under Dashboard, that the
page prints no tab bar, that the header carries the group prefix. Either
way every case was checked by breaking what it covers and watching it go red.
Do that too when adding one: a case that cannot fail is worse than no case.

`tests/` is excluded from phpcs (PHPUnit's `*Test.php` naming conflicts with the
WordPress file convention) and from PHPStan (the bootstrap redeclares core
functions the WordPress stubs already define).

## Before every push

`.githooks/pre-push` runs `bin/release.sh`, so a push checks everything and
writes `dist/<slug>-<version>.zip` before anything leaves the machine. A red
tree aborts the push instead of reaching CI, and the installable is waiting in
`dist/` afterwards, of exactly the commit that went out.

Git does not version hooks, only this directory, so each clone turns it on once:

```bash
git config core.hooksPath .githooks
```

`git push --no-verify` skips it, for a push that cannot break anything. A push
that only deletes a remote branch skips the checks on its own. The hook needs
`composer install` and `npm install` to have run; without them `bin/release.sh`
says so and stops.

## Continuous integration

`.github/workflows/ci.yml` runs two jobs on every push: `composer lint`,
`composer analyse` and `composer test` on PHP 8.3, and `npm run lint:js`,
`npm run lint:css`, `npm run build` and `bin/build-zip.sh` on Node 22. That last
step uploads the zip as a run artifact, so the installable can be downloaded
from the run page with no clone, no Node and no build - kept 14 days, since it
is rebuilt on every push and artifacts bill against private storage.

It only triggers on `push` (plus manual dispatch) and cancels superseded runs of
the same branch, because Actions minutes are metered on private repositories.
Add `pull_request:` to the `on:` block if the repository starts taking PRs.

`shivammathur/setup-php` is the only third-party action; the rest are official.

## Failure modes

`Delivery` hooks run on every storefront request and read from the filesystem,
`Page_Cache` runs a process on cPanel, and `Assets::enqueue()` reads a file the
deploy writes, so those paths are hardened:

| Situation                                   | Behavior                                  |
| ------------------------------------------- | ----------------------------------------- |
| `build/` missing (deployed without a build)  | No admin script or style, one log entry per hour |
| `build/*.asset.php` truncated mid-upload     | `ParseError` caught, no script, logged     |
| Manifest returns a non-array or lacks keys   | No script, logged                          |
| `wp_json_encode()` fails on the Databot settings | Databot's own tags, its stylesheet applied on load; no broken JS |
| `?page[]=x` (array in the query string)      | The plugin page simply does not match      |
| Switch holds a non-string value              | Reads as off; no cast, no `TypeError`      |
| cPanel PHP disables `proc_open`              | Page cache not cleared, logged once a day   |
| Page content the HTML API cannot read through the banner pair | Content returned byte for byte, no preload |
| cPanel uapi fails, stalls or refuses         | Logged once a day, retried after an hour    |
| Font sheet unreadable or not rewritable      | Left linked as it was, one log entry per hour |
| No WooCommerce (plain WordPress)             | WordPress half only; the WooCommerce and Porto options kept, not read |
| WooCommerce below the minimum               | WordPress half, and a warning to admins    |
| WP Optimizer still active                    | Nothing but a notice to admins; activation still copies its settings |
| No WooCommerce to log to                     | Written to PHP's error log instead         |

None of them raise a fatal error. The rule for anything reachable from a
storefront hook: fail soft, log once, never take the shop down. "Log once" is
`Logger::error_once( $key, $message )` — a key per failure, since one shared
key means the first failure silences the second for its whole window.

## Assets

The plugin ships **no storefront bundle**: every correction it makes is a PHP
filter, or a few bytes printed inline. `src/admin.js` with `src/admin.scss` is
the only entry point, and it loads on one screen only - the plugin's own,
which is where any admin behaviour would go. `Assets::enqueue()` reads
`build/admin.asset.php` for the dependencies and the version, and validates
it: a half-uploaded build degrades to "no script" and logs once, never to a
fatal error.

What the corrections print on the storefront costs no request:

| Correction | Prints |
| --- | --- |
| Remove global styles | core's `:where(body){margin:0}`, on its own file-less handle `ropt-body-reset` |
| Embed font stylesheets in the page | each small font sheet as `<style id="ropt-fonts-<handle>">`, in place of its `<link>` |
| The Databot chat (no switch) | a script `id="ropt-databot-loader"` in the footer, which inserts the chat's own |
| Avoid refreshing an empty mini cart | a footer script that reloads a page restored once a cart exists |
| Preload pages on hover | with WooCommerce, a footer script that switches the page's rules to press once a cart exists |

Custom CSS, or a JS-delay or cache exclusion, keyed on those names must follow
them: under WP Optimizer they started with `rwpo-`.

## WooCommerce notes

- There is no `Requires Plugins: woocommerce` header, on purpose: the plugin
  activates on any site. `Plugin::woocommerce_is_supported()` decides whether
  the WooCommerce half registers, and `Plugin::MIN_WC_VERSION` is its minimum.
  A site running an older WooCommerce gets the WordPress half and a notice
  saying why the other half is missing.
- `Plugin::declare_woocommerce_compatibility()` declares HPOS
  (`custom_order_tables`) and cart/checkout blocks. Set `cart_checkout_blocks`
  to `false` only for a correction that would break the block checkout.
- Errors go to **WooCommerce → Status → Logs** through `Logger`, under the
  source `rhinnoit-optimizer`, or to PHP's error log on a site without
  WooCommerce.

## Migrating from WP Optimizer

This plugin and **RHINNO IT - Customizer** replace **RHINNO IT - WP Optimizer**
(`rhinnoit-wp-optimizer`, prefix `rwpo_`): this one took its delivery and page
cache corrections, the Customizer its cart, checkout, order and store
features. Both slugs are new, so WordPress treats them as new plugins. On a
shop that runs WP Optimizer, in this order:

1. **Activate** the Optimizer and the Customizer while WP Optimizer is still
   active, each installed from its zip (**Plugins > Add New > Upload**) into
   its own folder. Activation copies the `rwpo_*` row of each of this plugin's
   settings, the master switch included, into its `ropt_*` equivalent - only
   where this plugin has no value yet, so nothing set here is ever
   overwritten. Then both wait: while WP Optimizer is active they boot nothing
   but a notice, this plugin's reading "Optimizer waits until WP Optimizer is
   deactivated, and does nothing until then", so no page gets a correction
   twice and no change clears the cache twice.
2. **Deactivate** WP Optimizer straight away. From the next request the two
   take over. The request that deactivates it is still WP Optimizer's, and
   its own clear on deactivation runs - on Kinsta, not forced.
3. On Kinsta, **save this plugin's screen** once: that forces a purge and runs
   the 5-minute check, so no page cached before the switch outlives it.
4. **Delete** WP Optimizer later, from the admin. Its uninstaller removes its
   own `rwpo_*` options; the copies here carry another prefix, and this
   plugin's uninstaller never touches WP Optimizer's rows.

Never delete WP Optimizer first: its `uninstall.php` deletes every `rwpo_`
row, so nothing would be left to copy and this plugin would start from its
defaults. Settings changed in WP Optimizer after step 1 are not carried over:
the copy runs on activation and on each version change, and never overwrites
a row that already exists here. Nothing else is copied: not WP Optimizer's
version, nor its page cache state - when it last cleared, and any backoff
after a failure, start over here - nor its scheduled clears, which its own
deactivation drops.

What changes around the shop:

- **The public filters** are renamed: `rwpo_databot_async` is now
  `ropt_databot_async`, and `rwpo_prefetch_on_hover` is now
  `ropt_prefetch_on_hover`, which `Delivery` asks and `Shop_Prefetch` answers.
  Look for the old names in the shop's must-use plugins and child theme
  before the switch: code that hooks them must follow, or it stops doing
  anything.
- **Handles and ids** follow the prefix: `ropt-body-reset`, `ropt-fonts-*` and
  `ropt-databot-loader` (see [Assets](#assets)).
- **Log entries** go under the source `rhinnoit-optimizer` from now on; the
  older ones stay under `rhinnoit-wp-optimizer`.

`Install::LEGACY_PREFIX` (`rwpo_`) and `Plugin::LEGACY_CONSTANT`
(`RWPO_VERSION`) are the two strings in the plugin that must not follow a
rename, because they name what existed before: renamed to this plugin's own,
the copy would read its own rows back and the guard would hold on every shop.
`PluginTest` and `BootTest` pin them with the old prefix built in two pieces,
so a rename pass cannot rewrite the expectation along with the code. The
guard, its notice and the copy go once no shop runs WP Optimizer.
