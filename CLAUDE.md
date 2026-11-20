# Plugin conventions

Private WordPress plugin by RHINNO IT, based on `rhinnoit-optimizer`. It runs on
any WordPress site and adds a second half when WooCommerce is active. It is
never published on wordpress.org. Read this before writing code.

Everything in this repository is written in English: code, comments, docblocks,
UI strings and documentation.

## Environment

- PHP 8.3 or higher (`Requires PHP` in the header, `testVersion` in
  `phpcs.xml.dist`, `"php"` in `composer.json`).
- WordPress 6.5+. WooCommerce is optional; its half needs 8.0+
  (`Plugin::MIN_WC_VERSION`). There is no `Requires Plugins` header, on purpose.

## Layout

- One class per file, under `includes/`, named `class-{name}.php` (hyphens
  instead of underscores, all lowercase).
- The subfolder inside `includes/` is the namespace segment:
  `includes/admin/class-settings-page.php` →
  `RhinnoIT\Optimizer\Admin\Settings_Page`. The autoloader
  (`includes/autoload.php`) relies on that mapping; do not use Composer to
  autoload the plugin code.
- The two halves are two places. The WordPress half lives at the top of
  `includes/` and runs on every site, so it touches WooCommerce only behind a
  guard: `Plugin::woocommerce_is_active()`, `function_exists()` /
  `class_exists()`, or ids that only exist where WooCommerce is supported. The
  shell's own cases are `Assets::scope_matches()` and `is_plain_page()`,
  `Logger::log()` and `Plugin::declare_woocommerce_compatibility()`. The
  WooCommerce half lives in `includes/frontend/` under `...\Frontend`, and
  `Plugin::__construct()` registers it only when
  `Plugin::woocommerce_is_supported()` is true - the branch that ships empty in
  the template. A service whose job needs WooCommerce (it asks `is_product()`
  unguarded, or touches the cart) belongs in `Frontend`, whatever else it does.
- Service classes are `final`, expose `register()` and hook **only** there. The
  logic goes in separate methods.
- Every new service is instantiated in `Plugin::__construct()`. Do not add
  containers or service providers.
- Every feature reads its own switch through `Settings::feature()`, which
  answers no for all of them while the master switch (**Enable**) is off, so
  unchecking it stops the plugin's work everywhere and not just its script
  tag. A switch is read once, in `register()`, never per callback. `Assets` is
  the exception: it registers either way, because the admin bundle has to load
  with the switch off, and `enqueue_frontend()` checks `Settings::is_enabled()`
  itself.
- `Settings` is not a service: it is a static helper (field definition and
  option reading). The UI lives in `Admin\Settings_Page`.

## Naming

- Visible name: `RHINNO IT - <what the plugin does>`, in English. The
  `RHINNO IT - ` half is literal in the `Plugin Name:` header, so it survives
  the rename; only the part after it is a placeholder.
- Directory and slug: `rhinnoit-<what-it-does>`, English, lowercase, hyphens.
  The slug is also the text domain and the log source.
  `bin/new-plugin.sh` forces the prefix on it.
- `ROPT_MENU_TITLE` carries the bare name, with no prefix: the shared node it
  hangs from already says RHINNO IT.
- `tests/PackagingTest.php` checks the two halves it can see from inside the
  plugin: the header must carry the prefix, the menu entry must not. The
  directory rule is enforced by `bin/new-plugin.sh`, not by a test.

## Prefixes

- Global constants: `ROPT_`
- Options, transient keys, own hooks: `ropt_`. CSS classes and script
  handles: `ropt-` (BEM inside the scope: `.ropt-settings .ropt-card__title`,
  handle `ropt-frontend`). JS globals: `ropt` + PascalCase
  (`window.roptFrontend`). The two group hooks are the exception: see
  Interface.
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
  find it.
- `composer test` runs PHPUnit with WordPress stubbed in `tests/bootstrap.php`.
  A fix for anything that could fatal on a storefront request gets a test that
  fails without the fix. Add new stubs to the bootstrap (WooCommerce ones to
  `tests/stubs/woocommerce.php`, below), not to the test files.
  A test class per file: PHPUnit only discovers the class whose name matches the
  filename, so a second one in the same file is silently never run.
- Behaviour gated on a constant needs a case in its own process, since the suite
  has already defined it. `WC_VERSION` comes from `ROPT_TESTS_WC` for that
  reason, and the child inherits the environment the class sets.
- The WooCommerce stubs live in `tests/stubs/woocommerce.php`, which the
  bootstrap loads only when `WC_VERSION` is defined. A process started with
  `ROPT_TESTS_WC=none` therefore has no WooCommerce function or class at all,
  exactly like a plain WordPress site, and code of the WordPress half that
  calls into WooCommerce by mistake fails its test with a fatal instead of
  passing on a stub. Keep them there.
- `tests/HookArityTest.php` registers every service and fails when a hooked
  method needs more arguments than its hook passes - an `ArgumentCountError` on
  every request that fires it. A new service goes into its list.
- A filter that WordPress core feeds is tested with the value core really
  passes, read from core's source - not with the value it resolves to later. A
  case fed the resolved value passes while the feature does nothing on the
  shop.
- `.github/workflows/ci.yml` runs all three plus the JS linters and the build.

## Options

They are declared once in `Settings::fields()`. From there come the default
values (`Settings::defaults()`, on activation), the form
(`Admin\Settings_Page`) and the list the uninstall guard checks
(`Settings::option_ids()`). Adding an option means touching `fields()`, the list
in `uninstall.php` and the catalogues in `languages/`, since its `title` and
`desc` are strings (see Translations). A test fails if you skip the uninstall
list, whether or not the field declares a `default`.
`PluginTest::test_defaults_come_from_the_field_definition` and
`SettingsPageTest::test_every_checkbox_is_drawn_as_a_switch` list the options
one by one, and take the new one too. The second counts only what the form
draws in the shared test process, so an option whose `'needs'` that process
lacks stays out of its count, and its message says it is left out.

A default reaches only a fresh install and an option an update adds
(`Install::seed_defaults()` uses `add_option()`): changing one never flips a
site that already has the row, except a list whose row is not an array, which
`Settings::chosen()` reads as the declared default (below) - a changed list
default reaches that site too.

The list has three sections: the master switch alone in an untitled one, then
WordPress, then WooCommerce - the platform before the addition. Every row of
the WooCommerce section, its `title` and `sectionend` included, carries
`'requires' => Settings::REQUIRES_WOOCOMMERCE`. `Settings::available_fields()`
drops those rows on a site without a supported WooCommerce, and the form draws
and saves that filtered list. Everything else reads the full list: an option
still exists while its section is hidden, and gets back its value the day
WooCommerce is installed. A test walks the list and fails when a row sits on
the wrong side of the flag.

The screen offers only the options that can act on the site (owner's request,
2026-10-16). An option that needs more than its section promises carries
`'needs'`, a list of marks (`Settings::NEEDS_*`, or a section mark such as
`REQUIRES_WOOCOMMERCE`), and `available_fields()` drops it where any is
missing. Each mark is answered in `Settings::conditions()` from what is
installed - a plugin's constant, the host, the theme's kind, a core function -
and must be the same condition the feature itself stops at: hiding an option
turns nothing off, and `Settings::feature()` still reads its row. Never a mark
read from another plugin's settings: a switch must not come and go when
someone changes a setting elsewhere. What depends on the host, the theme or
another plugin is read this way, never asked: there is no profile for the
admin to pick. A new mark goes into `conditions()` in the same change;
`SettingsNeedsTest` fails on a mark it does not answer, since a typo would hide
the option on every site. A card left with no option is not drawn at all. The
rules live in `Settings::fields_for_marks()`, pure so the cases pin them with
fields of their own; the template ships no `NEEDS_*` mark.

The master switch's declared default is what a row never saved means, for
`Settings::is_enabled()` as for every `feature()`: the template ships it off,
a plugin may ship it on, and the shell stays the same either way.

An option that holds a list is read through a helper that filters it against the
ids its own field can produce, before anything uses it - `Settings::chosen()`,
which `frontend_contexts()` calls once per list. A row can hold anything: a
non-array falls back to the field's declared default, because it cannot be
filtered at all (`array_filter()` on a string is a `TypeError` on a storefront
page), while an empty array is a real choice - it is what
`Settings_Page::sanitize_submission()` stores when every box of a multicheck is
unchecked. Filtering per field, not against the union, is what keeps an id one
list could never have offered from surviving a crafted post. The WooCommerce
list is read only where WooCommerce is supported: elsewhere it is a hidden
section, and the conditional tags its ids map to may not exist.

The scope lists ship empty, so a fresh install does nothing anywhere and only a
deliberate choice turns it on. That also makes the cleanup observable: a list
that comes back populated after a delete and reinstall means `uninstall.php`
never ran, which a non-empty default would have hidden. `WP_Stub::reset()`
therefore seeds a configured plugin, since the manifest cases need something to
enqueue.

The storefront contexts are two lists, `ropt_scope_wordpress` and
`ropt_scope_woocommerce`, one per half: each sits in its own section, so the
WooCommerce one hides with the rest of that half.

A row that is form structure rather than an option (`title`, `sectionend`)
carries `'is_option' => false`. `Settings_Page::sanitize_submission()` checks
that flag, and a test isolates it: without it a crafted post stores the section
id as an option nothing reads.

One switch per feature: never put two features behind one checkbox, because the
screen exists to turn off the one that misbehaves and keep the rest. `'group'`
is the small label the screen prints above a run of related switches. When an
existing switch is split, each piece carries `'inherits' => '<old key>'`:
`Install::inherit_split_options()` hands the old value down on the update, and
until it has run `Settings::feature()` reads the old row itself, so the
storefront never falls back to the defaults in between. The old row is kept for
a rollback; `Settings::retired_option_ids()` feeds the uninstall list, which a
test checks, and `Settings_Page::save()` refuses a form that still names a
retired switch. A switch removed outright, with nothing to inherit it, goes into
`Settings::REMOVED` for the same three reasons. Both ship empty in the
template.

Each `checkbox` says what it does in a few plain words (owner's request,
2026-10-28): a `title` of a few words that starts with a verb, and a `desc` of
one sentence saying what the owner or the shopper notices - no history,
measurements or internals. The only caveats it keeps are the ones needed to
decide: which pages to check where it can change a layout, and which switch it
needs, named by that switch's title. A `multicheck` puts the verb in its
`title` and lets each choice finish it (`Load the script on` ... `Cart`), and
its `desc` may also say how the choices combine - which is why the WordPress
scope list's runs to three sentences.

Do not add a switch for anything `wp-config.php` sets (`WP_POST_REVISIONS`,
`DISABLE_WP_CRON`, memory limits, debug): a constant there always wins, so the
switch could only be overruled or disagree with it.

## Interface

- The plugin contributes **one** entry to the shared menu node, and that entry
  is one screen: the settings form (`Admin\Settings_Page`) and nothing else.
  Reports and diagnostics do not go there; they crowd the switches the screen
  exists for. `Admin\Menu` draws the heading; the form prints itself. No tabs -
  the WordPress and WooCommerce halves are two cards of the one form, and a tab
  bar would cost a click on every visit.
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
  the group fires the same two, so the one that clears the page cache (today
  the Optimizer) hears all of them; with no listener they do nothing. They are
  the group's contract, not example code. Like the menu constants they hold the
  same value in every plugin, and `AdminTest` pins it.
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
  the same without the stylesheet. It knows four types: `title`, `sectionend`,
  `checkbox` and `multicheck` (one switch per choice, stored as one list).
  Adding one means adding it to `Settings_Page::render_field()` and
  `Settings_Page::sanitize_submission()` in the same change: a type one knows
  and the other does not is either unsaveable or unsafe. The save reads only
  the ids the field list declares, each through its own type's sanitizer,
  never the keys of `$_POST`, and only the fields the form says it drew
  (`Settings_Page::DRAWN_FIELD`). A post that lists nothing it drew is refused
  like one from an older version: it would otherwise save nothing and say it
  had.

## Assets

- Sources in `src/`, one entry point per context (`frontend`, `admin`).
- The entry points are the shell: `src/frontend.js`, `src/frontend.scss`,
  `src/admin.js` and `src/admin.scss` all exist even when empty, so writing a
  feature never starts by wiring a bundle. Empty scaffolding is fine; an
  example feature to delete is not.
- Build with `npm run build`. `build/` is in `.gitignore`, so it has to be
  compiled before deploying (or drop that line if the deployment copies the
  repository as-is).
- Enqueue only from `Assets`, through the helper that reads `build/*.asset.php`
  (dependencies and version come from there; never hardcode them). That helper
  validates the manifest and catches a `ParseError`: it runs on every storefront
  request, so a half-uploaded build must degrade to "no script", never to a
  fatal error.
- Pass data to JS with `wp_add_inline_script` + `wp_json_encode`, not with
  `wp_localize_script` (it casts the values to string).
- Load the scripts only on the screen where they are used. The storefront
  contexts are two settings (`ropt_scope_wordpress` and
  `ropt_scope_woocommerce`, merged by `Settings::frontend_contexts()`, which
  reads the second only where WooCommerce is supported); the admin bundle keys
  off `Menu::is_current_page()`. `Assets::is_plain_page()` asks no WooCommerce
  tag on a site without WooCommerce: the "Pages" choice belongs to the
  WordPress list and runs there too.
- Declaring `jquery` as a dependency does not load a second copy: WordPress
  deduplicates by handle. Keep it while the bundle listens to the WooCommerce
  refresh events, which never reach a native listener.

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

## What not to do

- Do not leave example features, empty hooks or unused options. An empty entry
  point in `src/` is the exception, and the only one: see Assets.
- Do not add abstraction layers for a single use case.
- Do not use `admin-ajax` when the REST API solves the case.
- Do not query the database directly when a WordPress or WooCommerce API
  exists (`wp_load_alloptions()`, `wp_count_posts()`, `wc_get_orders`,
  `wc_get_products`, `$order->get_meta()`).
- Do not add `readme.txt` or wordpress.org directory headers: the plugin is
  private and carries `Update URI: false`.
- Do not put anything a plugin needs later inside the "Creating a plugin from
  this template" section of the README: that whole section gets deleted in the
  copy, and deleting it is its own last step. Instructions for creating the
  plugin go in; rules the plugin lives by go outside.

## Translations

Visible strings are English in the code and ship translated to `es_ES` and
`es_CL` in `languages/`. WordPress does not fall back between Spanish locales,
so both catalogues are kept complete; they differ only in local vocabulary
("añadir" / "agregar"). Changing or adding a string means regenerating the
`.pot`, updating both `.po` files and recompiling both `.mo` files (commands in
the README). `tests/TranslationsTest.php` fails if any of the three steps is
skipped or a translation changes its placeholders; it reads the PHP and the
bundle sources in `src/`, and refuses plurals and contexts (`_n()`, `_x()`)
until it is taught to read them. The template ships the
shell's own strings translated, and `bin/new-plugin.sh` renames the catalogues
to the new slug and recompiles them.

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
