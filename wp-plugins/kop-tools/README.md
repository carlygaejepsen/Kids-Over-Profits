# KOP Tools

WordPress plugin: a "KOP Tools" menu in wp-admin that gathers the Kids Over
Profits admin tools (the self-contained pages in the child theme's `api/`
directory) plus the admin-facing WordPress pages, so nothing has to be reached
by a memorized URL.

## How it works

- The dashboard links tools through the ACTIVE child theme
  (`get_stylesheet_directory_uri()`), so it survives theme redeploys and only
  needs the theme's `api/` files to exist. Missing tools are shown greyed out
  instead of breaking, which makes the plugin safe to reuse on a site that
  carries only part of the toolset.
- `page` tools open in a new tab; `action` tools (POST-only endpoints such as
  Rebuild Story Groups) run from a dashboard button and show their JSON
  response inline. Every tool still enforces its own admin capability check —
  the plugin adds discoverability, not authorization.
- Extend or override the lists from other code via the `kop_tools_registry`
  and `kop_tools_admin_page_templates` filters.

## Install

Deployment copies this folder to `wp-content/plugins/kop-tools/` (see
`.cpanel.yml` at the repo root). Activate "KOP Tools" once under
Plugins in wp-admin. For local Flywheel, copy or symlink this folder into
`app/public/wp-content/plugins/`.
