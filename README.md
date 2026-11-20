# Optimizer

Base template for private WordPress plugins by RHINNO IT, with a second half
that runs when WooCommerce is active: the whole structure already solved
(bootstrap, settings, assets, i18n, linters) so only the functional part has to
be written on top.

Requires **PHP 8.3+** and WordPress 6.5+. WooCommerce is optional; its half
needs 8.0+.

## Creating a plugin from this template

Each plugin is its own repository, a sibling of this one — never a folder
inside it. See *Where the plugins live* below for why.

**1. Create it**

From inside the template:

```bash
bin/new-plugin.sh "Checkout Tweaks" checkout-tweaks CHKTW chktw 'RhinnoIT\CheckoutTweaks'
```

That creates `../rhinnoit-checkout-tweaks` and replaces the placeholders inside
the copy. Every plugin of the group lives in a `rhinnoit-*` directory, so the
prefix is added for you: pass `checkout-tweaks` or `rhinnoit-checkout-tweaks`
and you get the same result. In this template the directory, the main PHP file,
the text domain and the log source are all the same string, so the prefix
reaches all of them at once.

It never modifies the template: there is no in-place rename, so forgetting to
`cd` cannot cost you the template. It also refuses to run from a plugin that was
already created out of it, and deletes itself in the copy.

The five arguments:

| Placeholder               | What it is                              | Example                    |
| ------------------------- | --------------------------------------- | -------------------------- |
| `Optimizer`      | Name, printed after `RHINNO IT - `      | `Checkout Tweaks`          |
| `rhinnoit-optimizer`      | Slug, text domain and log source        | `rhinnoit-checkout-tweaks` |
| `ROPT`                    | Constant prefix                         | `CHKTW`                    |
| `ropt`                    | Option and JS variable prefix           | `chktw`                    |
| `RhinnoIT\Optimizer` | PHP namespace                           | `RhinnoIT\CheckoutTweaks` |

Both prefixes need 4 characters or more: WPCS rejects shorter ones because they
are likely to collide with other plugins. The two shared menu constants keep
their values on purpose, so the plugin joins the shared node.

Name and slug are always English and say what the plugin does. The plugins list
shows `RHINNO IT - Checkout Tweaks`, so a shop running several of them reads
the group at a glance; that half is already in the header of the template, so
pass the bare name (passing it with the prefix changes nothing, the script
strips it). The directory is `rhinnoit-checkout-tweaks`. Inside the shared node
the entry stays `Checkout Tweaks`: the node itself already says RHINNO IT.

`composer.json` gets `rhinnoit/checkout-tweaks`: the vendor half already says
`rhinnoit`, so the package half drops the prefix.

**2. Finish the header and the manifests**

- `Description` in the plugin header, and the same string in `languages/`: the
  `.pot`, both `.po` files and both `.mo` files (see *Translations*).
  `tests/TranslationsTest.php` fails until all of them match the header.
- `description` in `composer.json` — the `name` is already right.

**3. Strip what the plugin does not need**

Follow *Stripping what the plugin does not need*, further down. It sits outside
this section on purpose: it is still there once this one is gone.

**4. Write the feature**

Create the class under `includes/`, give it a `register()` that only hooks, and
add one line to `Plugin::__construct()`. Follow `CLAUDE.md`.

**5. Check it and package it**

```bash
composer install && npm install
composer release
```

`composer release` runs phpcs, PHPStan, PHPUnit, the JS and CSS linters and the
build, and only then writes `dist/rhinnoit-checkout-tweaks-1.0.0.zip` — the
zip is named after the main PHP file, prefix included. It stops at the
first failure, so nothing is ever packaged out of a red tree. The zip is what
you upload through **Plugins → Add New → Upload Plugin**.

Use `composer dist` on its own when you already know the tree is green, and the
individual commands (`composer lint`, `composer test`...) while working.

**6. Put it on GitHub**

```bash
git init && git add . && git commit -m "Initial commit"
git config core.hooksPath .githooks
gh repo create rhinnoit/checkout-tweaks --private --source=. --push
```

The CI workflow comes along with the copy and runs on the first push, and
`core.hooksPath` turns on the pre-push hook described in *Before every push*.

**7. Delete this section, and only this section**

Last of all: the six steps above live inside it, and everything the plugin
needs from here on lives below it.

It used to be a bullet of step 2, which deleted steps 3 to 6 along with itself
— including the one that says to strip what the plugin does not need. That is
how a working product-page notice, meant as an example, ended up in a client's
settings form.

## Where the plugins live

Siblings, each its own repository:

```
~/gitlab/ce/
├── rhinnoit-optimizer/           this repository
├── rhinnoit-checkout-tweaks/     one plugin, one repository
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

## Layout

```
rhinnoit-optimizer.php          Header, constants, bootstrap hooks
uninstall.php                   Option cleanup when the plugin is deleted
includes/
├── autoload.php                Namespace to file autoloader
├── class-plugin.php            Container: the WordPress half always, the WooCommerce half when supported
├── class-install.php           Default options on activation and update
├── class-settings.php          Declaration and reading of the options
├── class-assets.php            build/ enqueueing (script, style, JS translations)
├── class-logger.php            wc_get_logger() wrapper, PHP's error log without it; throttled write
└── admin/
    ├── class-menu.php          Shared node, its place in the menu, the page
    ├── class-settings-page.php The settings form: drawn, sanitized and saved
    └── class-plugin-links.php  "Settings" link on the plugins list
src/                            JS/SCSS sources (entry points: frontend, admin)
build/                          @wordpress/scripts output (not committed)
languages/                      .pot, and the es_CL and es_ES catalogues
tests/                          PHPUnit suite with WordPress stubbed
└── stubs/woocommerce.php       WooCommerce, loaded only where WC_VERSION is defined
bin/new-plugin.sh               Creates a new plugin next to the template
bin/release.sh                  Every check, then the zip
bin/build-zip.sh                Packages dist/<slug>-<version>.zip
phpstan-bootstrap.php           Plugin constants for the static analyser only
.github/workflows/ci.yml        Lint, static analysis and tests on every push
```

Every class is registered from `Plugin::__construct()`. To add functionality:
create the class in the matching folder with a `register()` method that only
hooks, and add one line to the constructor.

The constructor has two halves. The WordPress half registers on every site and
touches WooCommerce only behind a guard; a class whose job needs WooCommerce
goes in `includes/frontend/` and its line goes in the
`if ( self::woocommerce_is_supported() )` branch, which the template ships
empty. An unguarded WooCommerce call registered anywhere else is a fatal, on a
site without WooCommerce, on every request that reaches it.
`BootWithoutWooCommerceTest` runs with no WooCommerce function defined at all,
but it only boots the plugin: it catches a call made while booting (a
constructor or a `register()`), not one inside a callback. A service whose
callbacks would call WooCommerce needs its own case in a `ROPT_TESTS_WC=none`
process, or the branch it is registered in is its only guard.

Each feature reads its own switch in `register()` through
`Settings::feature( 'key' )`, which answers no for every feature while the
**Enable** checkbox is off. A feature that hooks without asking keeps working
with the box unchecked - which turns the first question support asks ("uncheck
it and tell me if the checkout still breaks") into a false negative. `Assets` is
the exception: it registers either way, because the admin bundle has to load
with the plugin off, and `enqueue_frontend()` checks the switch itself.

## Stripping what the plugin does not need

The template carries scaffolding so that adding is cheap, which only pays off
if removing is routine. A plugin that only filters a price needs no build at
all. Delete first, write second: whatever is still there when the plugin ships
is something you chose to keep.

Four things follow whatever goes: the option list in `uninstall.php`, the
constants in `phpstan-bootstrap.php`, the catalogues in `languages/` (a string
that leaves the code leaves the `.pot`, see *Translations*), and the tests
covering what is left.

A plugin with nothing to configure has no admin page at all: `Settings`,
`Admin\Settings_Page`, `Admin\Menu`, `Admin\Plugin_Links` and the whole admin
half of `Assets` go together, and `Install` with them, since seeding defaults is
all it does. What survives is a plugin that hooks the storefront and nothing
else — which also means its script has no screen to be configured from, so
`Assets::enqueue_frontend()` needs its contexts hardcoded instead of read.

**The two storefront lists** go in both cases below, so their part comes first.
Delete the two `multicheck` rows in `Settings::fields()` with
`woocommerce_scope_choices()`, `wordpress_scope_choices()`,
`frontend_contexts()` and `chosen()`, and their two options in `uninstall.php`.
The WordPress and WooCommerce cards stay for the plugin's own switches. The
screen draws no card without an option (`Settings::fields_for_marks()`), so
until the plugin's switches are in, `SettingsPageTest::test_a_shop_sees_both_sections`
and `BootWithoutWooCommerceTest::test_the_form_draws_only_the_wordpress_section`
fail: they pass again once each card holds one, and lose the heading of a card
that stays empty. Drop a card's rows from `fields()` only if nothing of the
plugin goes in it. The WooCommerce rows carry the only section mark the
template ships, so dropping them also takes their entry in
`PluginTest::test_each_conditional_section_is_marked_row_by_row`, and
`SettingsNeedsTest::test_every_mark_is_a_condition_the_screen_reads` until the
plugin's first `NEEDS_*` mark brings it back.

The lists were also the sample fields of the form's own cases, so those need a
field of the plugin's own or go. Point them at one where it has one of that
type, and delete them where it has none:

- `PluginTest`: the defaults case, `test_a_fresh_install_loads_the_script_nowhere`,
  and the `storedScopes` provider with its case.
- `SettingsPageTest`: `test_a_list_keeps_only_the_ids_its_field_offers`,
  `test_a_list_is_drawn_as_a_labelled_group_of_switches`,
  `test_a_choice_is_checked_exactly_when_its_list_holds_it`, the list half of
  `test_a_stored_value_cannot_reach_the_page`, the count in
  `test_every_checkbox_is_drawn_as_a_switch`, the ids in
  `test_a_field_that_was_not_drawn_is_left_alone` and
  `test_every_drawn_option_carries_its_marker`, and
  `test_the_drawn_list_cannot_name_a_field_this_site_lacks`, which needs an
  option of the WooCommerce card.
- `AdminTest`: `test_a_valid_save_redirects_back_to_the_page` and
  `test_a_save_leaves_every_undrawn_option_alone`.
- `BootWithOldWooCommerceTest`: the `frontend_contexts()` and `scope_matches()`
  assertions; the `capability()` one stays.
- `BootWithoutWooCommerceTest`: `test_the_woocommerce_section_is_not_on_the_screen`,
  `test_a_crafted_post_cannot_write_the_woocommerce_options`,
  `test_the_storefront_bundle_follows_the_wordpress_list_alone`, and the
  `ropt_scope_woocommerce` assertion of
  `test_the_form_draws_only_the_wordpress_section`.

With no `multicheck` field left, the type itself can go too:
`Settings_Page::render_multicheck()`, its branch in `render_field()` and
`sanitize_submission()`, and `.ropt-group__desc` in `src/admin.scss`. Then trim
`WP_Stub::reset()`, which seeds a scope nothing reads any more.

**If it ships no JS or CSS** — there is no bundle. On top of the lists, delete
`package.json`, `package-lock.json`, `src/`, `build/`,
`includes/class-assets.php` and the `( new Assets() )->register();` line in
`class-plugin.php`; both `ropt_broken_build_*` transients in `uninstall.php`
with the bundle loop of `PluginTest`'s uninstall case; and in the suite
`tests/AssetsTest.php`, the `new Assets()` line of `HookArityTest` with its
floor lowered by two, the `enqueue_frontend` and `enqueue_admin` callbacks in
`BootTest`, and the `enqueue_frontend` one in `BootWithOldWooCommerceTest` and
`BootWithoutWooCommerceTest` - assert `admin_menu` there instead, which is what
is left of the WordPress half.

The `js` job of `.github/workflows/ci.yml` goes too, since every step of it
needs `package.json`. Its last two steps, `bin/build-zip.sh` and the
`actions/upload-artifact` one, move to the end of the `php` job, after
`composer test`: packaging still waits for the PHP checks, and the zip is
still on the run page. `Menu::is_current_page()` loses its only caller,
so it goes too, with `test_a_non_string_page_parameter_does_not_match_this_plugin`.
The form loses its stylesheet with `src/` and falls back to plain labels and
checkboxes, which work the same.

`Logger` does lose its only caller — keep that one, a plugin without a build
still logs. `PackagingTest` skips its `package.json` case on its own once the
file is gone, and `composer lint:fix` cleans up the whitespace the deletions
leave behind.

**If it ships admin JS but no storefront JS** — the common case for a plugin
whose work is all PHP filters — keep `Assets` and delete only the storefront
half, on top of the lists: `src/frontend.js`, `src/frontend.scss`, the
`wp_enqueue_scripts` line in `Assets::register()`, `Assets::enqueue_frontend()`,
`Assets::scope_matches()` and `Assets::is_plain_page()`, `src/frontend.js` from
the two `wp-scripts` lines in `package.json`, and
`ropt_broken_build_frontend` from `uninstall.php` with `'frontend'` from the
bundle loop of `PluginTest`'s uninstall case. In the boot tests, the
`wp_enqueue_scripts:enqueue_frontend` callback becomes
`admin_enqueue_scripts:enqueue_admin`; `BootTest` already lists that one, so
there it is simply dropped. `HookArityTest` loses one hooked method, so its
floor drops by one.

In `AssetsTest`, `test_the_admin_bundle_loads_only_on_the_page_of_this_plugin`
stays as it is. Of the storefront ones, keep the manifest cases — the broken
and truncated manifests, the missing build, the throttled log, the non-string
dependencies, the translations — by pointing the `manifest()` helper at `admin`
and `enqueue_frontend()` at `enqueue_admin()`, with `$_GET['page']` set: without
it `enqueue_admin()` returns before it ever reads a manifest, since
`WP_Stub::reset()` clears `$_GET`. Every one of those was a real fatal and the
manifest helper is shared. Two of them name the storefront bundle and need the
admin one instead: the admin bundle adds no `jquery` of its own, so
`test_non_string_dependencies_are_filtered_out` expects the manifest's
`wp-i18n` alone, and `test_translations_are_registered_only_for_bundles_that_use_i18n`
asserts on `ropt-admin:i18n`. Delete the rest: the `scopes()` provider, whose
`scope_matches()` no longer exists; the inline-data and switch cases, since the
admin bundle carries neither;
`test_an_admin_page_view_does_not_silence_the_storefront_for_the_hour`, since
there is no storefront window left to silence; and
`test_valid_manifest_enqueues_with_merged_and_deduplicated_dependencies`, since
nothing merges any more - the `$extra_deps` parameter of `Assets::enqueue()`
goes with it. Repointed, those would pass while asserting nothing, which is
worse than not having them.

Both recipes were run on a copy of this commit, with every listed case
deleted rather than repointed: the suite is green, PHPStan is clean, and the
zip builds once the catalogues are regenerated.

## Admin interface

The plugin hangs off a shared node, right below Dashboard:

```
Dashboard
RHINNO IT                ← shared node
├── Optimizer   ← this plugin
├── Another Plugin       ← another plugin of the group
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
prefix so that `bin/new-plugin.sh` leaves them alone.

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
without the stylesheet, and no JavaScript is involved. It knows four types:
`title`, `sectionend`, `checkbox`, and `multicheck`, a labelled group of
switches stored as one list. A new one goes into `Settings_Page::render_field()`
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
plugin of the group fires the same two, so the plugin that clears the page cache
hears all of them. Without it they do nothing.

The WordPress and WooCommerce halves are two cards of the one form, not two
tabs. One screen needs no tab bar, and a second tab meant a click on every
visit to reach the thing anyone came for.

The screen offers only what can act on the site. The WooCommerce card is drawn
only where a supported WooCommerce is, and inside any card an option that
carries `'needs'` is drawn only where the site has every mark it names.
`Settings::conditions()` answers each mark from what is installed - a plugin's
constant, the host, the theme's kind, a core function - never from another
plugin's settings, and with the same condition the feature itself stops at,
since hiding an option turns nothing off. A mark it does not answer hides its
option on every site, which is what `SettingsNeedsTest` catches. A hidden
option keeps its row, so the day what it needs is installed it comes back with
the value it had. The template declares no `NEEDS_*` mark; a plugin adds one
together with the option that needs it.

The page slug is the plugin slug, so the URL is
`admin.php?page=rhinnoit-optimizer` and says which plugin the screen belongs to
— the way core's own `page=wc-settings` does. It lives in `Menu::PAGE_SLUG`,
and the renamer rewrites it through the **slug**, not the function prefix, so
anything comparing against it uses the constant: a literal `'ropt'` somewhere
else would be rewritten through the prefix and the two would drift apart in
every copy.

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

The template itself stays at 1.0.0. It is the version a new plugin starts from,
not a release of anything, and `bin/new-plugin.sh` prints that number in the
command it hands you. Bump the copies, never this.

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
template's catalogues hold the shell's own strings, and `bin/new-plugin.sh`
renames them to the new slug and recompiles them.

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

A string from a bundle is read from `build/`, not `src/`:
`wp_set_script_translations()` finds the JSON make-json writes by a hash of the
built file's path, so the `.po` has to name that file. `build/` is not in git,
which is why the build comes first: a stale one gives a stale template.

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
WooCommerce functions, is not loaded either, so the WordPress half is tested on
exactly what a plain WordPress site has. The child process inherits whatever
the test class put in the environment before it was spawned. Anything else
gated on a constant needs the same treatment.

`HookArityTest` registers every service and checks each hooked method against
the arguments its hook passes; a method that needs more is an
`ArgumentCountError` on every request that fires the hook, which no other case
would see because the stubs never call anything. A new service goes into its
list.

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

`Assets::enqueue_frontend()` runs on every storefront request, and the plugin
runs on sites with and without WooCommerce, so those paths are hardened:

| Situation                                   | Behavior                                  |
| ------------------------------------------- | ----------------------------------------- |
| `build/` missing (deployed without a build)  | No script, one log entry per hour          |
| `build/*.asset.php` truncated mid-upload     | `ParseError` caught, no script, logged     |
| Manifest returns a non-array or lacks keys   | No script, logged                          |
| `wp_json_encode()` fails                     | Inline data skipped, no broken JS emitted  |
| `?page[]=x` (array in the query string)      | The plugin page simply does not match      |
| Switch holds a non-string value              | Reads as off; no cast, no `TypeError`      |
| A scope list is not an array                 | Falls back to its default, which ships empty |
| A scope list holds unknown ids               | Dropped; the known ones still apply        |
| No WooCommerce (plain WordPress)             | WordPress half only; the WooCommerce list kept, not read |
| WooCommerce below the minimum               | WordPress half, and a warning to admins    |
| No WooCommerce to log to                     | Written to PHP's error log instead         |

None of them raise a fatal error. The rule for anything reachable from a
storefront hook: fail soft, log once, never take the shop down. "Log once" is
`Logger::error_once( $key, $message )` — a key per failure, since one shared
key means the first failure silences the second for the whole hour.


## Where the storefront bundle loads

A setting, not a constant: two lists of switches on the plugin's own screen,
one per half. The WordPress list sits in the WordPress card; the WooCommerce
list sits in the WooCommerce card, which is hidden on a site without a
supported WooCommerce - and `Settings::frontend_contexts()` does not read it
there either, since the tags its ids map to may not exist.

**WordPress pages**

| Choice                        | Loads on                                     |
| ----------------------------- | -------------------------------------------- |
| Blog posts                    | `is_singular( 'post' )`                      |
| Pages, WooCommerce ones aside | `is_page()`, minus the four WooCommerce ones |
| Every page of the site        | Everything the front end serves              |

**WooCommerce pages**

| Choice                  | Loads on                             |
| ----------------------- | ------------------------------------ |
| Shop and category pages | `is_shop()`, `is_product_taxonomy()` |
| Single product          | `is_product()`                       |
| Cart                    | `is_cart()`                          |
| Checkout                | `is_checkout()`                      |
| My account              | `is_account_page()`                  |

Two of those need a word. **Blog posts** is `is_singular( 'post' )` and not
`is_single()`, which is also true on a product. And **Pages** carries its
exclusions because cart, checkout and my account *are* WordPress pages, and so
is the shop whenever its page is what got queried — a bare `is_page()` would
load the bundle on screens the other list did not ask for, which makes both
lists lie. Two cases in `AssetsTest` fail the moment those exclusions go. On a
site without WooCommerce there is nothing to exclude, and
`Assets::is_plain_page()` asks none of those tags: they do not exist there.

**Every page of the site** sits in the WordPress list because that is what it
is, and it wins over both: it also covers what neither list names — archives,
search results, the blog index, a custom post type. Splitting each of those out
would be a screen full of checkboxes for cases a WooCommerce plugin rarely
wants on their own. Adding one is an entry in the matching
`Settings::*_scope_choices()` and a branch in `Assets::scope_matches()`.

**Both lists ship empty.** A fresh install loads the script nowhere, on top of
`Enable` being off, so the plugin does nothing at all until someone says where.
That is one deliberate act more than the old `ROPT_FRONTEND_SCOPE` constant
asked for, and it buys something the shipped-with-defaults version could not:
after deleting and reinstalling the plugin, a list that comes back populated
means `uninstall.php` did not run and the row survived. With a non-empty
default, a re-seed and a leftover row look identical on screen.

`Settings::frontend_contexts()` merges the two lists, and neither is trusted:

- Anything that is not an array - a corrupt row, another plugin writing the
  option - falls back to that list's declared default, because it cannot be
  filtered at all: `array_filter()` on a string is a `TypeError` on every page
  of the shop. In this template that default is empty, so a corrupt row and an
  unchecked list mean the same thing; a plugin that declares a real default
  gets that default back.
- An array **is** trusted to be a choice, including an empty one:
  `Settings_Page::sanitize_submission()` stores exactly that when every box is
  unchecked, so it means "nowhere", not "unset".
- Each list is filtered against **its own** field's choices, so a WooCommerce id
  posted into the WordPress list does not survive. Elements that are not strings
  go too, `is_string()` first: casting an array to a string is a warning on a
  storefront page.

`ropt_enabled` is still the master switch, and it is checked before any of this.

About the `jquery` dependency: declaring it does **not** load a second copy —
WordPress deduplicates by handle, so it only guarantees jQuery is printed before
the bundle. That guarantee is the point: `frontend.js` listens to the
WooCommerce refresh events, which are fired through jQuery and never reach a
native listener, and its own `window.jQuery` check would run too early without
it.

On the WooCommerce contexts it costs nothing, since every WooCommerce page
loads jQuery anyway. **Every page of the site** is where it stops being free: it pulls
jQuery into a blog post a block theme would have served without it. Drop the
dependency in `enqueue_frontend()` and the listeners in `frontend.js` together
if the bundle stops needing the events.

## WooCommerce notes

- There is no `Requires Plugins: woocommerce` header, on purpose: the plugin
  activates on any site. `Plugin::woocommerce_is_supported()` decides whether
  the WooCommerce half registers, and `Plugin::MIN_WC_VERSION` is its minimum.
  A site running an older WooCommerce gets the WordPress half and a notice
  saying why the other half is missing.
- `Plugin::declare_woocommerce_compatibility()` declares HPOS
  (`custom_order_tables`) and cart/checkout blocks. If the plugin hooks into the
  classic checkout in a way that breaks the block one, set
  `cart_checkout_blocks` to `false`.
- Errors go to **WooCommerce → Status → Logs** through `Logger`, or to PHP's
  error log on a site without WooCommerce.
- The storefront JS re-initialises on `updated_cart_totals` /
  `updated_checkout`: WooCommerce replaces that markup over AJAX and the events
  are fired through jQuery.
