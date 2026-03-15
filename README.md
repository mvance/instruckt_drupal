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
- A configured private filesystem (`$settings['file_private_path']` in `settings.php`)
- Root `composer.json` configured with [Asset Packagist](https://asset-packagist.org) and `oomphinc/composer-installers-extender` (see Installation)
- `npm-asset/instruckt` JS library ([MIT](https://github.com/joshcirre/instruckt/blob/main/LICENSE)) — not bundled; downloaded automatically via Asset Packagist when you run `composer require`

## Installation

### 1. Create the private filesystem directory

Create the directory and configure its path in `settings.php`.

```bash
mkdir -p web/private
```

Then add to `web/sites/default/settings.php`:

```php
$settings['file_private_path'] = '/absolute/server/path/to/web/private';
```

> **DDEV users:** Use the container path, not the host path:
> ```php
> $settings['file_private_path'] = '/var/www/html/web/private';
> ```
> The directory you created on the host (`web/private`) is bind-mounted at that path inside the container.

### 2. Configure root `composer.json` (one-time project setup)

This module declares `oomphinc/composer-installers-extender` as a dependency and includes Asset Packagist in its own repository list, so Composer 2 resolves both automatically. You only need to allow the plugin and add the installer configuration to your project's root `composer.json`.

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

> **Note:** If you are on Composer 1, or if Asset Packagist is not resolved automatically, also run:
> `composer config repositories.asset-packagist composer https://asset-packagist.org`

### 3. Require the module

```bash
composer require drupal/instruckt_drupal
```

This downloads the module and its JS dependency, placing `instruckt.iife.js` at `web/libraries/instruckt/dist/instruckt.iife.js`.

### 4. Enable the module

```bash
drush en mcp instruckt_drupal && drush cr
```

(Alternatively: `/admin/modules`.)

**Automatically configured on install:** enabling the module enables the Instruckt MCP plugin and grants `access instruckt_drupal toolbar` to the `authenticated` role. Run `drush instruckt:setup` (next step) to grant `use mcp server` and configure token authentication.

### 5. Configure MCP authentication

```bash
drush instruckt:setup
```

| Option | Default | Description |
|--------|---------|-------------|
| `--role` | `authenticated` | Role to grant `use mcp server` |
| `--user` | `1` | UID or username MCP requests run as |
| `--key-id` | `instruckt_mcp_token` | Machine name for the auth token key entity |

The command prints the `.mcp.json` snippet with the base64-encoded token ready to use (replace the URL).

> **Troubleshooting:** If the command exits with *"Could not find user"*, pass the UID explicitly:
> ```bash
> drush instruckt:setup --user=1
> ```

**Manual alternative:**

1. `/admin/config/system/keys` → Add key (type: Authentication, provider: Configuration, input: text field; generate value with `openssl rand -hex 32`)
2. `/admin/config/mcp` → Authentication → enable Token Auth, select key, set token user
3. Base64-encode the raw token: `echo -n "your-token" | base64` — use as `Authorization: Basic <value>`

### 6. Verify the installation

Visit `/admin/reports/status` and search for "Instruckt" — the JS library row should show **Installed**. If it shows an error, verify that `web/libraries/instruckt/dist/instruckt.iife.js` exists and that `file_private_path` is set in `settings.php`.

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
