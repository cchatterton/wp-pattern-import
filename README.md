# WP Pattern Import

WP Pattern Import is an AlphaSys WordPress plugin for importing repeated HTML patterns from source pages into WordPress posts.

It provides an administrator workflow for:

- Saving one scraping pattern per source URL.
- Scanning repeated item selectors and detail pages.
- Mapping item and detail fields to post fields, native meta, ACF meta, and featured images.
- Building post content from tokenised HTML templates.
- Running manual imports for the current pattern.
- Running scheduled imports for all saved patterns through WP-Cron.

The plugin stores its recipe in WordPress options and creates new posts only. It does not update or delete existing imported posts.

## Updates

This plugin is distributed through GitHub releases and managed by AS Update Controller.

- Repository: https://github.com/cchatterton/wp-pattern-import
- Release ZIP: `wp-pattern-import.zip`
- Controller: AS Update Controller

The controller is not required for normal feature operation.

## Requirements

- WordPress 7.0 or later
- PHP 7.4 or later

## Build

Run:

```bash
bash scripts/build-plugin-zip.sh
```

The build creates:

- `dist/wp-pattern-import.zip`
- `wp-pattern-import.zip`

