> **This repository has moved.** Development continues at
> [drupal.org/project/instruckt_drupal](https://www.drupal.org/project/instruckt_drupal).
> Please open issues and submit patches there.

---

# Instruckt Drupal

A visual feedback toolbar for Drupal 10/11 that lets developers and reviewers click-annotate page elements, attach comments and screenshots, and expose those annotations to AI coding agents via the Model Context Protocol (MCP).

Authenticated users with the `access instruckt_drupal toolbar` permission can:

- Click any element on the page to create an annotation
- Attach a comment, intent (fix / change / question / approve), and severity level
- Capture a screenshot of the annotated area
- View and resolve existing annotations through the toolbar UI

Annotations are stored as JSON in the Drupal private filesystem (`private://_instruckt/annotations.json`) and screenshots alongside them (`private://_instruckt/screenshots/`). AI coding agents connect to the site's [drupal/mcp](https://www.drupal.org/project/mcp) endpoint via three MCP tools — see [Available tools](#available-tools) under Usage.

## Requirements

- Drupal 10 or 11
- [drupal/mcp](https://www.drupal.org/project/mcp) ^1.2
- PHP 8.1+

## Installation

### Quick install (3 commands)

```bash
composer require drupal/instruckt_drupal
drush en mcp instruckt_drupal && drush cr
drush instruckt:setup
```

- `composer require` downloads the module. The toolbar JS loads from jsDelivr CDN (0.4.x) until the local library is installed — no `installer-paths` configuration required to get started.
- `drush en` enables the module (installing with a warning if private filesystem isn't configured yet), enables the Instruckt MCP plugin, and grants `access instruckt_drupal toolbar` to the `authenticated` role.
- `drush instruckt:setup` handles everything else: creates the private directory at `../private` (outside web root), appends `$settings['file_private_path']` to `settings.php`, creates the `_instruckt/` storage directories, grants `use mcp server` to a role, creates an auth token key, configures `mcp.settings`, and prints the `.mcp.json` snippet ready to use.

| Option | Default | Description |
|--------|---------|-------------|
| `--role` | `authenticated` | Role to grant `use mcp server` |
| `--user` | `1` | UID or username MCP requests run as |
| `--key-id` | `instruckt_mcp_token` | Machine name for the auth token key entity |

### Optional: Install JS library locally (production / strict CSP)

By default the toolbar JS loads from CDN. To install it locally so that no external requests are made:

Allow the oomphinc plugin (Composer's plugin security gate requires explicit approval):

```bash
composer config allow-plugins.oomphinc/composer-installers-extender true
```

Then edit `composer.json` to add `"installer-types"` and append `"type:npm-asset"` to the existing `"web/libraries/{$name}"` installer-paths entry:

```json
"extra": {
    "installer-types": ["npm-asset"],
    "installer-paths": {
        "web/libraries/{$name}": ["type:drupal-library", "type:npm-asset"],
        ...
    }
}
```

_Append `"type:npm-asset"` to any existing `"web/libraries/{$name}"` entry — do not replace the entire `extra` block._

> **Note:** If Asset Packagist is not resolved automatically, also run:
> `composer config repositories.asset-packagist composer https://asset-packagist.org`

Then re-run `composer require drupal/instruckt_drupal`. Composer will place `instruckt.iife.js` at `web/libraries/instruckt/dist/instruckt.iife.js` and the CDN warning on the status page will disappear.

### Verify

Visit `/admin/reports/status` — Instruckt rows should show no errors. A **CDN fallback active** warning on the JS row is expected until local JS is installed.

<details>
<summary>Manual MCP auth alternative</summary>

Instead of `drush instruckt:setup`, you can configure MCP authentication manually:

1. `/admin/config/system/keys` → Add key (type: Authentication, provider: Configuration, input: text field; generate value with `openssl rand -hex 32`)
2. `/admin/config/mcp` → Authentication → enable Token Auth, select key, set token user
3. Base64-encode the raw token: `echo -n "your-token" | base64` — use as `Authorization: Basic <value>`

</details>

To confirm the MCP endpoint is returning the instruckt tools:

```bash
curl -s -X POST https://your-site.example.com/mcp/post \
  -H "Authorization: Basic <base64-encoded-token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

The response should include `instrucktdrupal_get_all_pending`, `instrucktdrupal_get_screenshot`, and `instrucktdrupal_resolve` in the `tools` array. If the array is empty, verify that the Instruckt Drupal plugin is enabled at `/admin/config/mcp/plugins` and that the authenticated token user has the `use mcp server` permission.

## Usage

### For developers and reviewers

1. Log in as a user with the `access instruckt_drupal toolbar` permission.
2. Navigate to any non-admin page — the instruckt toolbar appears in the bottom-right corner.
3. Click the toolbar icon, then click any element on the page to create an annotation.
4. Fill in a comment, choose an intent and severity, and optionally capture a screenshot.
5. Submit the annotation. It is immediately available to AI agents via MCP.

### Twig template context

When [Twig debug mode](https://www.drupal.org/docs/develop/theming-drupal/twig-in-drupal/debugging-twig-templates) is enabled in `settings.php`:

```php
$settings['twig_debug'] = TRUE;
```

Annotations created on front-end pages will automatically include the Twig template that rendered the annotated element. No additional configuration is required — the feature activates when debug HTML comments are present in the DOM.

The template name (`component`) and path (`source_file`) appear in the annotation's detail view at `/admin/content/instruckt/{id}` and are included in the JSON returned by the `instrucktdrupal_get_all_pending` MCP tool.

> **Note:** Enable Twig debug only in development environments. It adds HTML comments to every page response and degrades performance on production sites.

### For AI agents (MCP)

> For full documentation on transport options, authentication methods, and all supported clients, see [drupalmcp.io: Connect to LLMs](https://drupalmcp.io/en/mcp-server/connect-to-llms/) and [Streamable HTTP](https://drupalmcp.io/en/mcp-server/streamable-http/).

Configure your AI agent to send `POST` requests to:

```
https://your-site.example.com/mcp/post
```

#### Authentication

The MCP module uses HTTP Basic auth format for all authentication methods. When using token auth (see Installation step 5), base64-encode the raw token value — with no username or colon — and send it as:

```
Authorization: Basic <base64-encoded-token>
```

Without valid credentials, or if the authenticated user lacks the `use mcp server` permission, `tools/list` silently returns an empty array with no error message.

#### Client configuration

**Claude Code** — create or edit `.mcp.json` in the project root:

```json
{
  "mcpServers": {
    "instrucktdrupal": {
      "type": "http",
      "url": "https://your-site.example.com/mcp/post",
      "headers": {
        "Authorization": "Basic <base64-encoded-token>"
      }
    }
  }
}
```

**Cursor** — use the same block in `.cursor/mcp.json`, changing `"type"` to `"streamable-http"`.

**Claude Desktop** and other clients that do not natively support HTTP transport require the `mcp-remote` bridge. See [drupalmcp.io: Streamable HTTP](https://drupalmcp.io/en/mcp-server/streamable-http/) for setup instructions.

#### Available tools

The agent will discover at least three instruckt tools (additional tools from other enabled MCP plugins may also appear). The `drupal/mcp` module prefixes all tool names with the plugin ID, so they appear as:

- **`instrucktdrupal_get_all_pending`** — no arguments; returns all pending annotations as JSON
- **`instrucktdrupal_get_screenshot`** — `annotation_id` (ULID string); returns the screenshot as a base64 image
- **`instrucktdrupal_resolve`** — `id` (ULID string); marks the annotation resolved and deletes its screenshot

## Troubleshooting

### Node.js rejects the DDEV site certificate (DDEV users)

AI agents that use Node.js under the hood — including Claude Code — do not trust the macOS or Linux system keychain. This means they reject the mkcert-issued certificate that DDEV uses for `*.ddev.site`, even if the certificate is trusted in your browser.

**Symptom:** MCP tool calls fail with `unable to verify the first certificate` or the MCP server never connects.

**Verify this is the cause:** Run a quick Node.js connectivity test from your host machine (not inside the DDEV container):

```bash
node -e "require('https').get('https://your-site.ddev.site/', r => console.log('OK', r.statusCode)).on('error', e => console.error('FAIL', e.message))"
```

If you see `FAIL unable to verify the first certificate` (or similar TLS error), Node.js is rejecting the cert. If `curl https://your-site.ddev.site/` succeeds at the same time, this confirms the issue is Node.js-specific — `curl` trusts the system keychain while Node.js does not.

**Fix:** Set `NODE_EXTRA_CA_CERTS` to the mkcert root CA before starting your agent:

```bash
export NODE_EXTRA_CA_CERTS="$(mkcert -CAROOT)/rootCA.pem"
```

Add this line to your shell profile (`~/.zshrc`, `~/.bashrc`, etc.) to make it permanent, or set it in your project's `.env` file if your tooling loads one automatically.

> **Prerequisite:** `mkcert` must be installed and its CA must be installed in your system trust store (`mkcert -install`). DDEV runs `mkcert -install` automatically during `ddev start`, so this is usually already done.

## Configuration

Module settings can be managed through the admin UI at `/admin/config/development/instruckt` (requires the `administer instruckt_drupal` permission). Settings are stored in `instruckt_drupal.settings` config. The defaults are:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master on/off switch for the toolbar |
| `storage_path` | `private://_instruckt` | Where annotations and screenshots are stored |
| `max_screenshot_size` | `5242880` (5 MB) | Maximum uploaded screenshot size in bytes |
| `allowed_screenshot_extensions` | `['png', 'svg']` | Permitted screenshot file formats |

These can also be overridden via `drush config-set`, the Config Synchronization UI, or a `$config` override in `settings.php`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for instructions on running tests and mutation testing.

## Credits

`instruckt_drupal` was inspired by the [instruckt-laravel](https://github.com/joshcirre/instruckt-laravel) Laravel package and uses the [instruckt](https://github.com/joshcirre/instruckt) JavaScript library. All credit for the original concept, UI, and JavaScript implementation goes to the instruckt project contributors:

- [joshcirre](https://github.com/joshcirre)
- [JonPurvis](https://github.com/JonPurvis)
- [sgasser](https://github.com/sgasser)
