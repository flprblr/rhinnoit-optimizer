# Plugin conventions

Private WordPress plugin by RHINNO IT, based on `wp-plugin-template`: its first
commit is the template at `634cb2b`, the fork point of every resync. It makes
WordPress and WooCommerce pages load faster, and keeps the host's page cache
fresh. It runs on any WordPress site and adds a second half when WooCommerce is
active. It is never published on wordpress.org. Read this before writing code.

Its features come from RHINNO IT - WP Optimizer 2.8.13
(`rhinnoit-wp-optimizer`, prefixes `RWPO_` / `rwpo_`, namespace
`RhinnoIT\WpOptimizer`), which was split in two: this plugin took the delivery
and page cache corrections, and RHINNO IT - Customizer the cart, checkout,
order and store features. Each feature file is WP Optimizer's, byte for byte,
with the identity renamed. What the split changed in them is the page
cache's hooks: `Page_Cache` hears the group's two instead of its own, and
`Shop_Cache` fires the group's (see Page cache).

Everything in this repository is written in English: code, comments, docblocks,
UI strings and documentation.

## Environment

- PHP 8.3 or higher (`Requires PHP` in the header, `testVersion` in
  `phpcs.xml.dist`, `"php"` in `composer.json`).
- WordPress 6.5+. WooCommerce is optional; its half needs 8.0+
  (`Plugin::MIN_WC_VERSION`). There is no `Requires Plugins` header, on purpose.
- The plugin goes on every client, on any host and theme, with its defaults
  (owner's decision, 2026-10-16). The measured shops run Porto on Kinsta or
  on cPanel, and those are the cases the corrections are tuned for; anything
  else must fail soft. There are no per-host profiles: one set of defaults
  serves every site. What really depends on the host, the theme or another
  plugin is decided by the plugin from what it can read, as
  `Plugin::kinsta_is_active()` does for which cache `Page_Cache` clears, and
  a switch that cannot act there is not offered - never by asking the admin
  to pick a profile. Hover prefetching is on everywhere: without a page
  cache a hover costs a render, and Chrome keeps at most two in flight.

## Layout

- One class per file, under `includes/`, named `class-{name}.php` (hyphens
  instead of underscores, all lowercase).
- The subfolder inside `includes/` is the namespace segment:
  `includes/admin/class-settings-page.php` →
  `RhinnoIT\Optimizer\Admin\Settings_Page`. The autoloader
  (`includes/autoload.php`) relies on that mapping; do not use Composer to
  autoload the plugin code.
- The two halves are two places. The WordPress half lives at the top of
  `includes/` (`Delivery`, `Viewport_Hero`, `Housekeeping`, `Databot_Loader`,
  `Page_Cache`, `Canonical_Redirect`, beside the shell) and runs on every
  site, so it touches WooCommerce only behind a guard:
  `Plugin::woocommerce_is_active()`, `function_exists()` / `class_exists()`,
  or ids that only exist where WooCommerce is supported. The shell's own cases
  are `Logger::log()` and `Plugin::declare_woocommerce_compatibility()`; none
  of the WordPress corrections calls a WooCommerce function or class at all.
  The WooCommerce half lives in `includes/frontend/` under `...\Frontend`
  (`Product_Image`, `First_Row`, `Gallery_Strip`, `Cart_Fragments`,
  `Shop_Prefetch`, `Shop_Cache`), and `Plugin::__construct()` registers it
  only when `Plugin::woocommerce_is_supported()` is true. A correction that
  asks `is_product()` or touches the cart belongs in `Frontend`, even when it
  is a delivery correction.
- Service classes are `final`, expose `register()` and hook **only** there. The
  logic goes in separate methods.
- Every new service is instantiated in `Plugin::__construct()`. Do not add
  containers or service providers.
- Every correction reads its own switch through `Settings::feature()`, which
  answers no for all of them while the master switch (**Apply the
  optimizations**) is off, so unchecking it stops the plugin's work
  everywhere. A switch is read once, in `register()`, never per callback.
  `Assets` is the exception: it registers either way, because the admin
  bundle has to load with the switch off. So does `Page_Cache` for the save,
  its check and its flush: the save that turns the switch off still has pages
  to clear, which is also why `settings_saved()` reads the switch again.
- Another exception, by the owner's decision (2026-09-24): `Databot_Loader`
  has no switch and must not appear on the settings screen. It reads only the
  master switch (`Settings::is_enabled()`) and the `ropt_databot_async`
  filter. Do not add a field for it.
- `Shop_Prefetch` has no switch of its own either: it is the WooCommerce half
  of `speculative_moderate`, which it reads, and it answers
  `ropt_prefetch_on_hover`, which `Delivery` asks. Rename the filter on both
  sides in the same change, or hover prefetching silently comes back for
  shoppers with a cart.
- `Settings` is not a service: it is a static helper (field definition and
  option reading). The UI lives in `Admin\Settings_Page`.
- Never name a class, constant or function of another plugin of the group,
  RHINNO IT - Customizer above all. PHP evaluates every class constant on the
  first `new`, so one constant built from another plugin's class is a "Class
  not found" fatal on every request of every shop, admin included. What has to
  cross between the two goes through the group hooks (see Interface), never
  through code. The Customizer keeps its own copies of WooCommerce's cookie
  names (`Cart_Fragments::CART_COOKIE`, `SESSION_COOKIE`) and of
  `Shop_Prefetch::PAGES` for the same reason: a change to `PAGES` here has to
  be made there by hand.

## Naming

- Visible name: `RHINNO IT - <what the plugin does>`, in English. The
  `RHINNO IT - ` half is literal in the `Plugin Name:` header, so it survives
  the rename; only the part after it is a placeholder.
- Directory and slug: `rhinnoit-<what-it-does>`, English, lowercase, hyphens.
  The slug is also the text domain and the log source. The template's
  `bin/new-plugin.sh` forced the prefix when this plugin was created; nothing
  here re-checks it.
- `ROPT_MENU_TITLE` carries the bare name, with no prefix: the shared node it
  hangs from already says RHINNO IT.
- `tests/PackagingTest.php` checks the two halves it can see from inside the
  plugin: the header must carry the prefix, the menu entry must not. The
  directory rule has no test.

## Prefixes

- Global constants: `ROPT_`
- Options, transient keys, cron hooks, own hooks: `ropt_`. CSS classes, ids
  and script and style handles: `ropt-` (BEM inside the scope: `.ropt-settings
  .ropt-card__title`, handle `ropt-body-reset`). JS globals: `ropt` +
  PascalCase, though there is none today: every inline script keeps to its own
  closure. The two group hooks are the exception: see Interface.
- `Install::LEGACY_PREFIX` (`rwpo_`) and `Plugin::LEGACY_CONSTANT`
  (`RWPO_VERSION`) keep WP Optimizer's spelling, because they name WP
  Optimizer itself: renamed to this plugin's own, the copy would read its own
  rows back and the guard would hold on every shop. Never rename either, and
  never give a new key the `rwpo` prefix. `PluginTest` and `BootTest` pin them
  with the old prefix built in two pieces, so a rename pass cannot rewrite the
  expectation along with the code.
- The admin page slug is the **plugin slug**, not the prefix: `?page=` says
  which plugin the screen is. `Menu::PAGE_SLUG` holds it, and comparisons use
  the constant - a literal would be renamed through the prefix instead and
  drift.
- PHP namespace: `RhinnoIT\Optimizer`
- Text domain and log source: `rhinnoit-optimizer`

## PHP

- Types on every parameter and return value, hook callbacks included.
- Do **not** use `declare( strict_types=1 )`: WordPress passes loosely typed
  values through the hooks and it raises `TypeError` in production.
- Register hooks with `array( $this, 'method' )`, not with the first-class
  callable syntax (`$this->method(...)`): that one creates a Closure and makes
  the hook impossible to remove with `remove_action()`.
- Use what 8.3 already offers (`str_starts_with`, `match`, typed class
  constants, typed properties, constructor promotion) instead of the old forms.

## WordPress rules

- Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.
- Sanitize on input (`sanitize_text_field`, `absint`; `wc_clean` only in the
  WooCommerce half), escape on output (`esc_html`, `esc_attr`, `esc_url`,
  `wp_kses_post`). Every dynamic value the admin screen prints has a case fed a
  value with markup in it: today that is the stored option values
  (`SettingsPageTest`). Print a new one and it needs its own.
- Nonce + `current_user_can()` on anything that writes.
- Every visible string inside `__()` / `esc_html__()` with the plugin text
  domain.
- Yoda conditions and docblocks on classes, methods and properties: `composer
  lint` runs the `WooCommerce-Core` ruleset and fails without them.
- `composer analyse` runs PHPStan at level 8 and must stay clean. Add a new
  runtime constant to `phpstan-bootstrap.php` as well, or the analyser will not
  find it. It asks for 2G, where the template asks for 1G: from a cold cache
  this plugin needs about 1.1G.
- `composer test` runs PHPUnit with WordPress stubbed in `tests/bootstrap.php`.
  A fix for anything that could fatal on a storefront request gets a test that
  fails without the fix. Add new stubs to the bootstrap (WooCommerce ones to
  `tests/stubs/woocommerce.php`, below), not to the test files.
  A test class per file: PHPUnit only discovers the class whose name matches the
  filename, so a second one in the same file is silently never run.
- Behaviour gated on a constant needs a case in its own process, since the suite
  has already defined it. `WC_VERSION` comes from `ROPT_TESTS_WC` for that
  reason (`7.9` below the minimum, `none` no WooCommerce), and the child
  inherits the environment the class sets.
- `ROPT_TESTS_KINSTA=1` makes the bootstrap define `KINSTAMU_VERSION`, for the
  cases that run on Kinsta in their own process (`BootOnKinstaTest`,
  `CartFragmentsTest`, `PageCacheOnKinstaTest`, `SettingsOnKinstaTest`,
  `SettingsWithoutPortoTest`, `ShopCacheOnKinstaTest`);
  `KinstaNamespacedConstantTest`, `KinstaDefaultsTest` and `HookArityTest`'s
  Kinsta case define a Kinsta constant in a process of their own. `ROPT_TESTS_SMUSH=1` defines `WP_SMUSH_VERSION` the same way
  (`ViewportHeroWithSmushTest`), and `ROPT_TESTS_SMUSH=api` loads Smush's
  settings API from `tests/stubs/smush.php` too
  (`ViewportHeroWithSmushApiTest`). `ROPT_TESTS_WP=6.6` leaves out what core
  added in 6.7 and 6.8 (auto sizes, speculative loading).
  `ROPT_TESTS_WP_OPTIMIZER=1` defines WP Optimizer's `RWPO_VERSION`
  (`BootWithWpOptimizerTest`).
- `Viewport_Hero` parses the page content with core's `WP_HTML_Tag_Processor`,
  using only what 6.5 already has (`next_token()`, `get_token_type()`,
  `get_tag()`, `is_tag_closer()`, `get_attribute()`, `set_attribute()`,
  `remove_attribute()`, `add_class()`, `get_updated_html()`; not
  `class_list()`, which lowercases on 6.5 and not on 7.x). The processor
  cannot insert markup, which is why its preloads go at the start of the
  content. The bootstrap loads core's HTML API from `tests/stubs/html-api/`
  (7.1.2, unchanged) when it is missing, so those cases run the real parser,
  on real markup saved under `tests/fixtures/`.
- The Porto stubs live in `tests/stubs/porto.php`, loaded unless
  `ROPT_TESTS_PORTO=none`. A correction built on a Porto function checks that
  the function exists and steps aside otherwise, and has a case in its own
  process proving it (`CartFragmentsWithoutPortoTest`,
  `DeliveryWithoutPortoTest`, `SettingsWithoutPortoTest`). The stub of
  `get_template()` answers `porto` exactly while that file is loaded, by
  asking for `porto_minicart()`: key it on a function this plugin's Porto stub
  defines, or every Porto case silently runs off Porto.
- A filter that WordPress core feeds is tested with the value core really
  passes, read from core's source - not with the value it resolves to later.
  The hover prefetch case once fed "conservative" while core sends "auto", and
  the switch did nothing on either shop for a release.
- The WooCommerce stubs live in `tests/stubs/woocommerce.php`, which the
  bootstrap loads only when `WC_VERSION` is defined. A process started with
  `ROPT_TESTS_WC=none` therefore has no WooCommerce function or class at all,
  exactly like a plain WordPress site, and code of the WordPress half that
  calls into WooCommerce by mistake fails its test with a fatal instead of
  passing on a stub. Keep them there.
- `tests/HookArityTest.php` registers every service and fails when a hooked
  method needs more arguments than its hook passes - an `ArgumentCountError` on
  every request that fires it. A new service goes into its list. It runs once
  off Kinsta and once on it, where the mini cart correction and `Shop_Cache`'s
  Kinsta filter hook too.
- `.github/workflows/ci.yml` runs all three plus the JS linters and the build.

## Options

They are declared once in `Settings::fields()`. From there come the default
values (`Settings::defaults()`, on activation), the form
(`Admin\Settings_Page`), the list the uninstall guard checks
(`Settings::option_ids()`) and the copy of WP Optimizer's values (see WP
Optimizer, below). Adding an option means touching `fields()`, the list in
`uninstall.php` and the catalogues in `languages/`, since its `title` and
`desc` are strings (see Translations). A test fails if you skip the uninstall
list, whether or not the field declares a `default`.
`PluginTest::test_defaults_come_from_the_field_definition` and
`SettingsPageTest::test_every_checkbox_is_drawn_as_a_switch` list the options
one by one, and take the new one too. The second counts only what the form
draws in the shared test process, so an option whose `'needs'` that process
lacks stays out of its count, and its message says it is left out.

A default reaches only a fresh install and an option an update adds
(`Install::seed_defaults()` uses `add_option()`): changing one never flips a
site that already has the row.

The list has one section per domain, in this order: the master switch alone in
an untitled one, then WordPress, WooCommerce and Porto - the platform before
the addition - each with its own groups. Every row of the last two, its
`title` and `sectionend` included, carries `'requires'` with its section's
mark: `Settings::REQUIRES_WOOCOMMERCE`, or `REQUIRES_PORTO` (the theme's own
WooCommerce markup: a supported WooCommerce and `get_template()` is `porto`,
the same mark and the same answer as the Customizer's).
`Settings::available_fields()` drops a section whole on a site without what it
needs, and the form draws and saves that filtered list. Everything else reads
the full list: an option still exists while its section is hidden, and gets
back its value the day the missing piece is installed. A correction that works
on Porto's own output goes in Porto's section, not in WooCommerce's.
`PluginTest::test_each_conditional_section_is_marked_row_by_row` walks the list
and fails when a row sits on the wrong side of a mark; a new conditional card
adds its title id and mark to the map there.

The screen offers only the options that can act on the site (owner's request,
2026-10-16). An option that needs more than its section promises carries
`'needs'`, a list of marks (`Settings::NEEDS_*`, or a section mark such as
`REQUIRES_PORTO`), and `available_fields()` drops it where any is missing.
Each mark is answered in `Settings::conditions()` from what is installed - a
plugin's constant, the host (`Plugin::kinsta_is_active()`,
`Page_Cache::can_clear()`), the theme's kind, a core function - and must be
the same condition the correction itself stops at: hiding an option turns
nothing off, and `Settings::feature()` still reads its row. Never a mark read
from another plugin's settings (Porto's lazy load, Smush's CDN): a switch
must not come and go when someone changes a setting elsewhere. What depends
on the host, the theme or another plugin is read this way, never asked: there
is no profile for the admin to pick. Today's needs are `NEEDS_KINSTA`,
`NEEDS_HOST_CACHE`, `NEEDS_CLASSIC_THEME`, `NEEDS_NO_THEME_JSON` and
`NEEDS_SPECULATION_RULES`. A new mark goes into
`conditions()` in the same change as its first field; `SettingsNeedsTest`
fails on a mark it does not answer, since a typo would hide the option on
every site. Each plugin of the group declares and answers only its own marks:
one built on another plugin's class is a fatal whenever the screen is drawn
or saved. The one mark that is not the correction's own stop is
`NEEDS_HOST_CACHE` on cPanel: the screen also asks for ea-nginx's files, the
clear never does (a cage can hide them while uapi works, and a silent stop
would leave every edit stale). A card left with no option is not drawn at
all, which off Kinsta is the Porto card. The rules live in
`Settings::fields_for_marks()`, pure so the cases pin them with fields of their
own. `available_fields()` and `conditions()` take an optional `Page_Cache`,
which the template's do not: the cPanel case passes one built on a fake uapi,
so the answer `conditions()` gives for `NEEDS_HOST_CACHE` is the one tested.

Every switch ships `'default' => 'yes'`, and so does the master switch
(owner's decision, 2026-10-16: the plugin goes on every client with its
defaults, and hidden switches keep it from doing anything where it cannot).
The master switch's declared default is what a row never saved means, for
`Settings::is_enabled()` as for every `feature()`: the template ships it off,
this plugin ships it on, and the shell stays the same either way. When a
correction can break a page's layout, its description says which pages to
check.

A row that is form structure rather than an option (`title`, `sectionend`)
carries `'is_option' => false`. `Settings_Page::sanitize_submission()` checks
that flag, and a test isolates it: without it a crafted post stores the section
id as an option nothing reads.

One switch per correction: never put two corrections behind one checkbox,
because the screen exists to turn off the one that misbehaves and keep the
rest. `'group'` is the small label the screen prints above a run of related
switches. When an existing switch is split, each piece carries
`'inherits' => '<old key>'`: `Install::inherit_split_options()` hands the old
value down on the update, and until it has run `Settings::feature()` reads the
old row itself, so the storefront never falls back to the defaults in between.
The old row is kept for a rollback; `Settings::retired_option_ids()` feeds the
uninstall list, which a test checks, and `Settings_Page::save()` refuses a form
that still names a retired switch. A switch removed outright, with nothing to
inherit it, goes into `Settings::REMOVED` for the same three reasons. Both are
empty: the plugin started at 1.0.0 with WP Optimizer's switches already split,
and nothing has been retired since.

Each `checkbox` says what it does in a few plain words (owner's request,
2026-10-28): a `title` of a few words that starts with a verb, and a `desc` of
one sentence saying what the owner or the shopper notices - no history,
measurements or internals. The only caveats it keeps are the ones needed to
decide: which pages to check where it can change a layout, and which switch it
needs, named by that switch's title.

Do not add a switch that does nothing on the sites measured, however standard
it is elsewhere, and measure again before relying on an old answer: WP
Optimizer's 2.0 head switch removed the generator tag, 2.1 dropped it because
none of the three sites printed it then, and 2.8.2 brought it back as its own
switch because on 2026-10-16 hoka and joia printed WordPress's and
WooCommerce's, in the head and the feed (grupoferia and tucasafacil did not;
easyrider answers a bot challenge to anything but a browser). Check the live
HTML of both shops before adding one.

Do not add a switch for anything `wp-config.php` sets (`WP_POST_REVISIONS`,
`DISABLE_WP_CRON`, memory limits, debug): a constant there always wins, so the
switch could only be overruled or disagree with it. That is why WP Optimizer
dropped the revision limit of its 2.0 and 2.1.

## Interface

- The plugin contributes **one** entry to the shared menu node, and that entry
  is one screen: the settings form (`Admin\Settings_Page`) and nothing else.
  Reports and diagnostics do not go there; they crowd the switches the screen
  exists for. `Admin\Menu` draws the heading; the form prints itself. No tabs -
  each domain is a card of the one form, and a tab bar would cost a click on
  every visit.
- `Menu::register_pages()` ends with `remove_submenu_page()`. Without it the
  node and the back-link core adds to it both 404: WordPress gives a top-level
  item the href of its first child, and the shared parent has no callback.
- `Menu::move_below_dashboard()` filters `menu_order` to keep the node under
  Dashboard, at `PHP_INT_MAX` so it runs last (Site Kit splices its own node
  right after Dashboard from a filter it adds on `init`, and at the same
  priority it took the slot), and `Menu::enable_menu_order()` returns true so
  WordPress honours it. `MENU_POSITION` alone cannot: on a taken slot `add_menu_page()` breaks the
  tie with a hash of the slug, so the node lands wherever chance puts it.
- The parent node and entry names live in the `ROPT_MENU_PARENT_SLUG`,
  `ROPT_MENU_PARENT_TITLE` and `ROPT_MENU_TITLE` constants of
  `rhinnoit-optimizer.php`. The first two, plus `Menu::PARENT_ICON`,
  `Menu::MENU_POSITION` and `Menu::CAPABILITY`, must hold the same value across
  every plugin of the group. Do not give them the plugin prefix.
- Two hooks belong to the group, not the plugin, and carry no prefix either:
  `Settings_Page::SAVED_HOOK` (`rhinnoit_settings_saved`), fired after every
  save of the screen, and `Install::DIRTY_HOOK` (`rhinnoit_page_cache_dirty`),
  fired with `'site'` when an update changes the version, and with a reason and
  a product id by anything else that makes cached pages stale. Every plugin of
  the group fires the same two, so the one that clears the page cache hears
  all of them; with no listener they do nothing. They are the group's
  contract, not example code. Like the menu constants they hold the same value
  in every plugin, and `AdminTest` pins it. This plugin is the listener:
  `Page_Cache` hears both (see Page cache), and `Shop_Cache` fires the second
  for a product.
- Check permissions with `Menu::capability()`, never `Menu::CAPABILITY`
  directly. It answers `Menu::CAPABILITY` (`manage_woocommerce`, shared by the
  group) where WooCommerce is active and `Menu::FALLBACK_CAPABILITY`
  (`manage_options`) where it is not: WooCommerce registers
  `manage_woocommerce` itself, so on a plain WordPress site nobody holds it and
  the page would be invisible to everyone.
- `Menu::PARENT_ICON` is a Dashicons class. The plugin that creates the node is
  the one whose icon shows, and load order decides which, so it changes in every
  plugin of the group at once or not at all.
- Do not create loose pages outside the node: one entry per plugin is what
  keeps it readable on a shop running several.
- Forms post to `admin-post.php` with `wp_nonce_field()` +
  `check_admin_referer()` + `current_user_can()`, and redirect after saving.
- The form draws its own fields, because `WC_Admin_Settings` does not exist
  without WooCommerce. The look is the card-and-row pattern of
  `@wordpress/components` in plain markup, styled by `src/admin.scss` and
  scoped under `.ropt-settings`: stay inside WordPress's own design language,
  no framework and no React app for a form of switches. Checkboxes are drawn
  as switches but stay real checkboxes with `role="switch"`, so the page works
  the same without the stylesheet. It knows three types: `title`, `sectionend`
  and `checkbox`. Adding one means adding it to `Settings_Page::render_field()`
  and `Settings_Page::sanitize_submission()` in the same change: a type one
  knows and the other does not is either unsaveable or unsafe. The save reads
  only the ids the field list declares, each through its own type's sanitizer,
  never the keys of `$_POST`, and only the fields the form says it drew
  (`Settings_Page::DRAWN_FIELD`). A post that lists nothing it drew is refused
  like one from an older version: it would otherwise save nothing and say it
  had.

## Assets

- Sources in `src/`, one entry point: `admin`, loaded only on the plugin page
  (`Menu::is_current_page()`). `src/admin.js` and `src/admin.scss` are the
  shell and stay even while the first is empty, so admin behaviour never
  starts by wiring a bundle. Empty scaffolding is fine; an example feature to
  delete is not.
- The storefront has no bundle: every correction is server-side, and what one
  prints on a page is inline - `wp_add_inline_style()` on a file-less handle
  of its own (`Delivery::BODY_RESET`), a `<style>` in place of a font sheet's
  `<link>`, or `wp_print_inline_script_tag()` in the footer. It costs no
  request. Add a `frontend` entry point only with a real need for it, and
  enqueue it from `Assets` behind a switch, because anything it loads costs
  every visitor; the template's storefront bundle is the one to bring back.
- Build with `npm run build`. `build/` is in `.gitignore`, so it has to be
  compiled before deploying (or drop that line if the deployment copies the
  repository as-is).
- Enqueue a built file only from `Assets`, through the helper that reads
  `build/*.asset.php` (dependencies and version come from there; never
  hardcode them). That helper validates the manifest and catches a
  `ParseError`: a half-uploaded build must degrade to "no script", never to a
  fatal error.
- Pass data to JS with `wp_add_inline_script` + `wp_json_encode`, not with
  `wp_localize_script` (it casts the values to string).

## Never break the shop

`wp_enqueue_scripts` and the storefront hooks run on every request, so anything
reached from there has to fail soft:

- Validate whatever comes from the filesystem or the database before using it.
  A `require`d file can return anything, or fail to parse.
- Never print the result of `wp_json_encode()` into inline JS without checking
  it: `false` would emit `window.x = ;` and break every script on the page.
- Guard `$_GET` / `$_POST` reads with `is_string()` before sanitizing: a query
  string can send an array.
- Decide which page this is on the `wp` action and keep the answer. Inside a
  render filter (`the_content`, `wp_get_attachment_image_attributes`) the
  queried object may already point somewhere else - a theme's posts grid
  re-points it at every item it renders, so a filter asking `is_product()`
  there answers about the grid item.
- An option that holds a list gets filtered against the ids the code knows
  before it is used, elements included: `is_string()` before comparing, since
  casting an array to a string is a warning on a storefront page.
- Throttle anything that writes to the log from a storefront code path:
  `Logger::error_once( $key, $message )`, with a key specific to that failure.
  One key shared by two failures means the first one silences the second for
  the whole window. `Logger::error()` has no window on purpose, for the paths
  that run once.
- Do not wrap service registration in try/catch to hide bugs. Fix the failure
  mode instead; swallowing errors only moves the breakage somewhere harder to
  find.

## Page cache

`Page_Cache` clears the host's page cache; read its class docblock before
touching it. Rules that are easy to break:

- On cPanel it runs uapi through `proc_open` with a fixed argument list, no
  shell and no user input. Never store a cPanel API token, in the database or
  anywhere else: a token cannot be scoped to one function and is the whole
  hosting account. Never delete cache files: they belong to `nobody`.
- Every cPanel clear empties the whole account. Never clear from a shopper's
  request and never on every order: record a reason, let `flush()` decide,
  and keep stock on stock *status* changes only - or a WeLaunch Multi
  Inventory store crossing zero, which is the status its product page shows.
  Never on a count.
- On Kinsta, never a full purge for one product: `Shop_Cache` reports the
  product id (the parent, for a variation, and nothing for a draft) and
  `Page_Cache` purges that post through Kinsta's `initiate_purge()`. Id 0, more
  than `Page_Cache::SINGLE_LIMIT` products, and a scheduled sale (it moves
  prices on archives nobody can list) are the product changes that purge
  everything. Kinsta's own stock purge is turned off only through its
  `kinsta/kmp/cache/autopurge` filter, behind its own switch; never reach
  into its controllers.
- A save of any settings screen of the group fires `rhinnoit_settings_saved`,
  and that clear is the one exception to the rules above: immediate, and
  forced past Kinsta's autopurge switch, because the admin asked for it. On
  Kinsta it is checked five minutes later from WP-Cron (`Page_Cache::VERIFY`;
  Kinsta's edge takes 2-5 minutes to clear): one request to the home page,
  one more forced purge if the edge copy predates the save, a log line if it
  still does after that. Never on any other clear: that request costs a
  render.
- Off Kinsta, Elementor deleting its CSS files (`elementor/core/files/clear_cache`)
  clears at once from whatever request did it, a logged-in customer's
  included when Elementor Pro refreshes its license data: every cached page
  links the deleted files, so waiting serves them unstyled. SPACING still
  holds. Nothing else a visitor's request can do may clear inline.
- It hears the group's two hooks, never names of its own: `Page_Cache::SAVED`
  is `rhinnoit_settings_saved` and `Page_Cache::DIRTY` is
  `rhinnoit_page_cache_dirty`, written out so that `register()`, which runs on
  every request, loads neither `Admin\Settings_Page` nor `Install`.
  `PageCacheTest` holds each to the constant the shell fires. The save is
  heard whatever the switch says; `DIRTY` only while `page_cache_clear` is on.
  `DIRTY` calls `dirty()`, which takes two arguments, the reason and a
  product id that defaults to 0, and passes them to `mark()` unless the
  reason is `'saved'`: that one forces a clear, and only `settings_saved()`
  may record it. `mark()` drops a reason outside `Page_Cache::REASONS`
  without a word, so what the hook takes is `REASONS` minus `'saved'`, the
  list `Install::DIRTY_HOOK`'s docblock keeps. `Shop_Cache` fires
  `do_action( Page_Cache::DIRTY, $reason, $id )`, with the shell's
  `DynamicHooknameFound` ignore.
- Only this plugin's own options count as a change to every page when they are
  written (`Settings::option_ids()`, read once in `register()`). An option of
  another plugin of the group changed outside its screen clears nothing; the
  save of that screen is what clears for it.
- `Install::deactivate()`, registered from the main file, drops the deferred
  clear and the save's check, and `uninstall.php` removes the state row, both
  cron hooks and every failure window. `PackagingTest` pins the hook and
  those lines, and `PluginTest` what the deactivation drops.

## WP Optimizer

Every shop this plugin goes on ran WP Optimizer first. Two pieces hand it
over, and both go once no shop runs WP Optimizer:

- `Plugin::boot()` boots nothing but a notice while WP Optimizer's constant
  (`Plugin::LEGACY_CONSTANT`) is defined: that plugin runs the same
  corrections under its own switches, and with both booted every page would
  get each correction twice and every change two clears. It asks on
  `plugins_loaded`, never at file scope: WordPress loads the active plugins
  in the order of their folder names, and this one comes before
  `rhinnoit-wp-optimizer/`. The activation hook is not booted from there, so
  the copy still runs beside WP Optimizer. The notice,
  `Plugin::render_waiting_notice()`, is static and loads the text domain
  itself, because the `init` callback that loads it never registers while the
  plugin waits.
- `Install::migrate_legacy_options()` copies the `rwpo_` row of each of
  `Settings::option_ids()` with `add_option()`, first in `seed_defaults()`:
  after the defaults, `add_option()` would keep them and the copy would do
  nothing. It never overwrites and never deletes, and runs on activation and
  on each version change.
- `uninstall.php` never deletes an `rwpo_` row: WP Optimizer's own uninstaller
  does, and deleting them here would reset WP Optimizer on a site that still
  runs it.
- A switch moved between this plugin and the Customizer later needs its own
  hand-over: the copy reads only `rwpo_`.

The two public filters followed the rename, as `ropt_databot_async` and
`ropt_prefetch_on_hover`, and WP Optimizer's two actions became the group's,
`rhinnoit_settings_saved` and `rhinnoit_page_cache_dirty`: a shop that fired
or hooked the `rwpo_` names must follow.

## What not to do

- Do not leave example features, empty hooks or unused options. An empty entry
  point in `src/` is the exception, and the only one: see Assets.
- Do not add a switch for something Porto, the page builder or the SEO plugin
  already does. Emoji, embeds, jQuery Migrate, Heartbeat, the block library,
  minification, jQuery in the footer, icon font preloads and the `robots.txt`
  rules were already handled on every shop - a toggle for them would be a
  correction that does nothing, which is the one defect this plugin exists to
  stop. Measure first.
- Do not key a correction to a file name, an attachment id or a specific
  banner or product. Rules are positional or structural - the first row, the
  queried product - so they hold when the shop's content changes and nobody
  has to re-apply anything.
- Do not add abstraction layers for a single use case.
- Do not use `admin-ajax` when the REST API solves the case.
- Do not query the database directly when a WordPress or WooCommerce API
  exists (`wp_load_alloptions()`, `wp_count_posts()`, `wc_get_orders`,
  `wc_get_products`, `$order->get_meta()`).
- Do not add `readme.txt` or wordpress.org directory headers: the plugin is
  private and carries `Update URI: false`.

## Translations

Visible strings are English in the code and ship translated to `es_ES` and
`es_CL` in `languages/`. WordPress does not fall back between Spanish locales,
so both catalogues are kept complete; they differ only in local vocabulary
("añadir" / "agregar"). Changing or adding a string means regenerating the
`.pot`, updating both `.po` files and recompiling both `.mo` files (commands in
the README). `tests/TranslationsTest.php` fails if any of the three steps is
skipped or a translation changes its placeholders; it reads the PHP and the
bundle sources in `src/`, and refuses plurals and contexts (`_n()`, `_x()`)
until it is taught to read them. A string that came from WP Optimizer keeps
WP Optimizer's translations.

## Versioning

The version lives in two places that must match: the `Version:` plugin header
(read by WordPress and by the zip builder) and the `ROPT_VERSION` constant (read
by `Install::check_version()`). Bump both. A test fails if they drift, so CI
catches it. `package.json` deliberately carries no version:
nothing reads it and it would only go stale.

## Packaging

`composer release` runs every check and only then packages the zip, stopping at
the first failure. `composer dist` skips the checks, for when the tree is
already known to be green. Both read the slug and version from the plugin
header, both rebuild `build/` first when `node_modules` is there (the zip has no
`src/`, and a stale manifest ships the old version hash too, so the previous
bundle stays cached), and both exclude every hidden file by rule so
`.distignore` never has to be kept in step with `.gitignore`.

The `.distignore` patterns are anchored to the plugin root. A bare `vendor` line
unanchored also matched `includes/vendor/`, which is where a plugin vendors its
update library. A pattern that has to match at any depth starts with a `*`.

The zip is a build artefact: `dist/` is gitignored and the zip is never
committed. It gets written on its own twice: `.githooks/pre-push` runs
`bin/release.sh` before a push leaves the machine (enable it per clone with
`git config core.hooksPath .githooks`; `git push --no-verify` skips it), and the
CI uploads it as a run artifact. Neither one is a place to put a check that
`bin/release.sh` does not already run.

## Commands

```bash
composer lint        # phpcs
composer lint:fix    # phpcbf
composer analyse     # phpstan level 8
composer test        # phpunit
npm run build        # compiles src/ into build/
npm run lint:js
```
