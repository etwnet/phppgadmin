# copilot-instructions for phppgadmin

These instructions are for AI coding assistants working on the phpPgAdmin repository.
Be concise and follow the project's conventions — this file highlights the most useful, discoverable patterns.

**Project Overview**:

- **Type:** PHP web application for PostgreSQL administration.
- **Entry points:** `index.php`, `servers.php`, `intro.php`, `login.php`.
- **Config:** `conf/config.inc.php` controls servers, themes, plugins and debug flags.
- **Runtime:** Runs on PHP (>=7.2) with `ext-pgsql` and `ext-mbstring`. Use `composer install` to fetch `greenlion/php-sql-parser`.

**Important directories & files**:

- `conf/` : main configuration (`conf/config.inc.php`). Server definitions and plugin list live here.
- `classes/` : small OOP components (e.g. `PluginManager.php`).
- `libraries/` : helper libs and bootstrap (`libraries/lib.inc.php`). Many global helpers live here.
- `plugins/` : plugin folders; each plugin should expose `plugin.php` and a class named after the folder.
- `lang/` : translation files (one PHP file per language). Use language keys from these files.
- `js/`, `images/`, `themes/` : UI assets.
- `tests/` : contains tests if present; no centralized test runner is declared in `composer.json`.

**Key patterns and conventions**:

- Global configuration: many files rely on `$conf`, `$lang`, and `$misc` as globals. Prefer using existing globals rather than creating new global variables.
- Procedural + light OOP mix: Most features are implemented procedurally; only some subsystems (e.g. plugins) use classes.
- Plugin system: `classes/PluginManager.php` expects plugins under `./plugins/<Name>/plugin.php`. The plugin class must implement `get_name()`, `get_hooks()` and `get_actions()` and expose methods matching hook/action names. Hooks supported include: `head`, `toplinks`, `tabs`, `trail`, `navlinks`, `actionbuttons`, `tree`, `logout`.

  - Example: `./plugins/Example/plugin.php` should declare `class Example { function get_name(){return 'Example';} function get_hooks(){ return array('head' => array('myHead')); } function myHead(&$args){ ... } }

- Configuration editing: `conf/config.inc.php` is intended to be edited by administrators. Respect the file's "Don't modify anything below this line" section when possible.

**Development / run / debug**:

- Install PHP deps: run `composer install` in the repository root.
- PHP version: require PHP >=7.2 per `composer.json`. Ensure `ext-pgsql` (PostgreSQL extension) and `ext-mbstring` are enabled.
- Quick local server (development): from repo root run:
  - `php -S localhost:8080 -t .` (note: some pages may rely on particular web server behavior; use a full webserver config for production-like testing).
- Database: configure your PostgreSQL server(s) in `conf/config.inc.php` (`$conf['servers'][N]`). Set `pg_dump`/`pg_dumpall` paths there for export functionality.
- Debugging: `conf/config.inc.php` sets `display_errors` and several `ini_set` values — toggle them there. Session lifetimes are set near the top of that file.

**Composer & external deps**:

- `greenlion/php-sql-parser` is required (repository declared in `composer.json`). Use `composer install` to fetch it.
- No other build step is required; static assets are in repo.

**Testing & CI**:

- There is a `tests/` folder but no `phpunit`/CI configuration in `composer.json`. If adding tests, follow existing procedural style and prefer small isolated tests for key helper functions in `libraries/`.

**Patterns to follow when editing code**:

- Preserve existing procedural style and use global variables where patterns already use them (`$conf`, `$lang`, `$misc`).
- Keep changes minimal and focused for a single responsibility.
- When adding new features that need persistence across requests, prefer storing configuration in `conf/` or adding a plugin under `plugins/`.
- When adding UI changes, update assets under `js/`, `images/`, or `themes/` as appropriate; JS code is plain JS (no framework).

**Files to inspect for examples**:

- `conf/config.inc.php` — server and plugin configuration, debug flags.
- `classes/PluginManager.php` — plugin lifecycle, hook/action model.
- `libraries/lib.inc.php` — bootstrapping and common helpers used across pages.
- `index.php`, `servers.php`, `intro.php` — common entry flow and how pages include libraries.

**Safety & security notes** (discoverable):

- `extra_login_security` in config defaults to `true`. Avoid changing this lightly without understanding `pg_hba.conf` implications.
- User input is often passed to SQL generator/exec paths — prefer reusing existing escaping / helpers in `libraries/` and `helper.inc.php`.

If anything in these notes is unclear or you'd like more examples (e.g. a sample plugin skeleton or an example of how hooks are called in a page), tell me which part to expand and I'll iterate.
