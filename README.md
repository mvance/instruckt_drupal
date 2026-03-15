# Instruckt Drupal

A visual feedback toolbar for Drupal 10/11 that lets developers and reviewers click-annotate page elements, attach comments and screenshots, and expose those annotations to AI coding agents via the Model Context Protocol (MCP).

This module ports the [instruckt-laravel](https://github.com/joshcirre/instruckt-laravel) package to Drupal.

## Description

Instruckt Drupal embeds a floating toolbar on non-admin Drupal pages. Authenticated users with the `access instruckt_drupal toolbar` permission can:

- Click any element on the page to create an annotation
- Attach a comment, intent (fix / change / question / approve), and severity level
- Capture a screenshot of the annotated area
- View and resolve existing annotations through the toolbar UI

Annotations are stored as JSON in the Drupal private filesystem (`private://_instruckt/annotations.json`) and screenshots alongside them (`private://_instruckt/screenshots/`).

AI coding agents (Claude, Cursor, etc.) connect to the site's [drupal/mcp](https://www.drupal.org/project/mcp) endpoint and use three registered MCP tools:

| Tool | Description |
|------|-------------|
| `instruckt_get_all_pending` | Retrieve all pending annotations with full metadata |
| `instruckt_get_screenshot` | Get the base64-encoded screenshot for an annotation |
| `instruckt_resolve` | Mark an annotation as resolved by the agent |

## Requirements

- Drupal 10 or 11
- [drupal/mcp](https://www.drupal.org/project/mcp) ^1.2
- PHP 8.1+
- A configured private filesystem (`$settings['file_private_path']` in `settings.php`)
- Root `composer.json` configured with [Asset Packagist](https://asset-packagist.org) and `oomphinc/composer-installers-extender` (see Installation)

## Installation

### 1. Configure root `composer.json` (one-time project setup)

Before requiring this module, ensure your project's root `composer.json` includes Asset Packagist and the npm-asset installer. Most Drupal project templates do not include these by default.

Add the Asset Packagist repository:

```bash
composer config repositories.asset-packagist composer https://asset-packagist.org
```

Require the installer extender and allow its plugin:

```bash
composer require oomphinc/composer-installers-extender:^2.0
composer config allow-plugins.oomphinc/composer-installers-extender true
```

Add the `npm-asset` installer path to the `extra` section of your root `composer.json`:

```json
"extra": {
    "installer-types": ["npm-asset"],
    "installer-paths": {
        "web/libraries/{$name}": ["type:npm-asset"]
    }
}
```

### 2. Require the module

```bash
composer require drupal/instruckt_drupal
```

This installs the module and its JavaScript dependency (`npm-asset/instruckt`), automatically placing `instruckt.iife.js` at `web/libraries/instruckt/dist/instruckt.iife.js`.

### 3. Enable the module

```bash
drush en mcp instruckt_drupal && drush cr
```

### 4. Configure permissions

Grant the `access instruckt_drupal toolbar` permission to the developer role(s) at `/admin/people/permissions`.

### 5. Enable the MCP plugin

Visit `/admin/config/mcp/plugins`, enable the **Instruckt Drupal** plugin, and save. This exposes the three MCP tools to AI agents connecting to `/mcp/post`.

## Usage

### For developers and reviewers

1. Log in as a user with the `access instruckt_drupal toolbar` permission.
2. Navigate to any non-admin page — the instruckt toolbar appears in the bottom-right corner.
3. Click the toolbar icon, then click any element on the page to create an annotation.
4. Fill in a comment, choose an intent and severity, and optionally capture a screenshot.
5. Submit the annotation. It is immediately available to AI agents via MCP.

### For AI agents (MCP)

Configure your AI agent to connect to the site's MCP endpoint:

```
POST https://your-site.example.com/mcp/post
```

Authenticate using a Drupal session or API token (configured at `/admin/config/mcp`). The agent will discover three tools automatically:

- **`instruckt_get_all_pending`** — no arguments; returns all pending annotations as JSON
- **`instruckt_get_screenshot`** — `annotation_id` (ULID string); returns the screenshot as a base64 image
- **`instruckt_resolve`** — `id` (ULID string); marks the annotation resolved and deletes its screenshot

### Verifying the installation

Visit `/admin/reports/status` and search for "Instruckt" — the JS library row should show **Installed**. If it shows an error, verify that `web/libraries/instruckt/dist/instruckt.iife.js` exists and that `file_private_path` is set in `settings.php`.

## Configuration

Module settings are stored in `instruckt_drupal.settings` config. The defaults are:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master on/off switch for the toolbar |
| `storage_path` | `private://_instruckt` | Where annotations and screenshots are stored |
| `max_screenshot_size` | `5242880` (5 MB) | Maximum uploaded screenshot size in bytes |

These can be overridden via `drush config-set`, the Config Synchronization UI, or a `$config` override in `settings.php`.

## Credits

`instruckt_drupal` is a Drupal port of the [instruckt](https://github.com/joshcirre/instruckt) JavaScript library and the [instruckt-laravel](https://github.com/joshcirre/instruckt-laravel) Laravel package. All credit for the original concept, UI, and JavaScript implementation goes to the instruckt project contributors:

- [joshcirre](https://github.com/joshcirre)
- [JonPurvis](https://github.com/JonPurvis)
- [sgasser](https://github.com/sgasser)
