# KOP Tools

WordPress plugin: a "KOP Tools" menu in wp-admin and the admin bar that
gathers every Kids Over Profits admin tool (the admin pages, the theme's
wp-admin screens and the self-contained tools in the child theme's `api/`
directory), so nothing has to be reached by a memorized URL.

## How it works

- One registry (`kop_tools_registry()`) lists every tool by category. Each
  entry is one of four types: `wp-page` (a WordPress page with an admin
  template, URL resolved live), `screen` (a wp-admin screen the theme
  registers, such as Approve Facility Edits, Bug Reports or the Glossary
  Editor), `page` (a self-contained tool in the theme's `api/` directory,
  optionally with a `query` such as `run=1&dry=1`) or `action` (a POST-only
  endpoint run from the dashboard's Run button).
- The same registry feeds three places: the dashboard
  (admin.php?page=kop-tools), the "KOP Tools" admin bar dropdown on every
  wp-admin and front-end page (every tool, grouped by category), and the
  "KOP Tools" sidebar submenu (All Tools plus the Review queue and Records &
  editors categories; `kop_tools_sidebar_categories()`), sorted into registry
  order. Add a tool to the registry once and it appears everywhere.
- Page templates resolve through the theme's `kop_find_template_page_url()`,
  which picks the page at the slug `kop_tool_page_specs()` names when several
  pages share a template, so the menus and the notification emails always
  open the same page.
- Tools whose file, page or screen is missing are greyed out on the dashboard
  and left out of the menus. Every tool still enforces its own admin
  capability check: the plugin adds discoverability, not authorization.
- Extend or override the list via the `kop_tools_registry` and
  `kop_tools_sidebar_categories` filters.
- When this plugin is active the child theme stops registering its own
  "KOP Data Tools" menu and admin bar node and hangs its screens under this
  menu; the old `admin.php?page=kop-data-tools` URL redirects here.
- Test offline against the prod mirror:
  `php -d extension=pdo_sqlite scripts/test-kop-tools-menu.php`.

## Install

Deployment copies this folder to `wp-content/plugins/kop-tools/` (see
`.cpanel.yml` at the repo root). Activate "KOP Tools" once under
Plugins in wp-admin. For local Flywheel, copy or symlink this folder into
`app/public/wp-content/plugins/`.
