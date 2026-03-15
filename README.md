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

| Tool (as exposed at the MCP endpoint) | Description |
|---------------------------------------|-------------|
| `instruckt-drupal_instruckt_get_all_pending` | Retrieve all pending annotations with full metadata |
| `instruckt-drupal_instruckt_get_screenshot` | Get the base64-encoded screenshot for an annotation |
| `instruckt-drupal_instruckt_resolve` | Mark an annotation as resolved by the agent |

> **Note:** The `drupal/mcp` module prefixes every tool name with the plugin ID (`instruckt-drupal`), so the names above are what appear in `tools/list` and must be used in `tools/call` requests.

## Requirements

- Drupal 10 or 11
- [drupal/mcp](https://www.drupal.org/project/mcp) ^1.2
- PHP 8.1+
- A configured private filesystem (`$settings['file_private_path']` in `settings.php`)
- Root `composer.json` configured with [Asset Packagist](https://asset-packagist.org) and `oomphinc/composer-installers-extender` (see Installation)

## Third-party Libraries

This module depends on the [instruckt](https://github.com/joshcirre/instruckt)
JavaScript library, which is licensed under the
[MIT License](https://github.com/joshcirre/instruckt/blob/main/LICENSE).

The library is **not bundled** with this module. It is downloaded automatically
by Composer via [Asset Packagist](https://asset-packagist.org) when you run
`composer require drupal/instruckt_drupal`. See the Installation section below
for the one-time project setup required.

## Installation

### 1. Create the private filesystem directory

Instruckt stores annotations and screenshots in Drupal's private filesystem. Create the directory before requiring the module and configure its path in `settings.php`.

Create the directory from your project root:

```bash
mkdir -p web/private
```

Then add to `web/sites/default/settings.php`:

```php
$settings['file_private_path'] = '/absolute/server/path/to/web/private';
```

> **DDEV:** Inside a DDEV container the project root is mounted at `/var/www/html`, so use:
> ```php
> $settings['file_private_path'] = '/var/www/html/web/private';
> ```

### 2. Configure root `composer.json` (one-time project setup)

Before requiring this module, ensure your project's root `composer.json` includes Asset Packagist and the npm-asset installer. Most Drupal project templates do not include these by default.

Add the Asset Packagist repository:

```bash
composer config repositories.asset-packagist composer https://asset-packagist.org
```

Require the installer extender and allow its plugin:

```bash
composer config allow-plugins.oomphinc/composer-installers-extender true
composer require oomphinc/composer-installers-extender:^2.0
```

Add the `npm-asset` installer path to the `extra` section of your root `composer.json`.

> **Note:** There is no `composer config` command for the `extra` block — you must edit `composer.json` directly.

```json
"extra": {
    "installer-types": ["npm-asset"],
    "installer-paths": {
        "web/libraries/{$name}": ["type:npm-asset"]
    }
}
```

> **Important — merge, don't replace:** `drupal/recommended-project` already has an `extra` section containing an `installer-paths` block with a `"web/libraries/{$name}"` entry for `type:drupal-library`. Add `"installer-types"` as a new key and append `"type:npm-asset"` to that existing array. The merged entry should read:
> ```json
> "web/libraries/{$name}": ["type:drupal-library", "type:npm-asset"]
> ```
> Replacing the entire `extra` block will remove the scaffold, module, theme, and recipe installer paths required by Drupal core.

### 3. Require the module

```bash
composer require drupal/instruckt_drupal
```

This installs the module and its JavaScript dependency (`npm-asset/instruckt`), automatically placing `instruckt.iife.js` at `web/libraries/instruckt/dist/instruckt.iife.js`.

### 4. Enable the module

**Via the admin UI:** navigate to `/admin/modules`, search for and enable both **MCP** and **Instruckt Drupal**, then save. Clear caches at `/admin/config/development/performance`.

**Alternatively, with Drush:**

```bash
drush en mcp instruckt_drupal && drush cr
```

### 5. Configure permissions

At `/admin/people/permissions`, grant two permissions to the relevant role(s):

- **`access instruckt_drupal toolbar`** — allows users to create and view annotations via the browser toolbar
- **`use mcp server`** — allows AI agents (and any authenticated user) to access the MCP endpoint; without this permission, `tools/list` returns an empty array with no error message

### 6. Enable the MCP plugin

Visit `/admin/config/mcp/plugins`, enable the **Instruckt Drupal** plugin, and save. This exposes the three MCP tools to AI agents connecting to `/mcp/post`.

### 7. Configure MCP authentication

> For full details on all authentication methods and transport options, see the [drupal/mcp documentation at drupalmcp.io](https://drupalmcp.io/en/mcp-server/setup-configure/).

The steps below set up token authentication, the recommended method for AI agent access.

#### Create an authentication token

1. Visit `/admin/config/system/keys` → **Add key**
2. Set **Key type** to *Authentication* and **Key provider** to *Configuration*
3. Enter a long random string in **Key value** — for example:
   ```bash
   openssl rand -hex 32
   ```
4. Give the key a machine name (e.g., `mcp_auth_token`) and save

#### Enable token authentication in the MCP module

1. Visit `/admin/config/mcp`
2. Under **Authentication**, enable **Token authentication**
3. Select the key you just created
4. Set **Token user** to a Drupal user account — MCP requests authenticated with this token will run as that user, so its roles and permissions apply
5. Save

#### Encode the token for use in client config

The MCP authentication provider requires credentials in HTTP Basic auth format. Base64-encode the raw token value (no username, no colon) to produce the value you'll use in `Authorization` headers:

```bash
echo -n "your-raw-token-value" | base64
```

Keep both values — the raw token (stored in the Key) and the base64-encoded string (used in client headers).

## Usage

### For developers and reviewers

1. Log in as a user with the `access instruckt_drupal toolbar` permission.
2. Navigate to any non-admin page — the instruckt toolbar appears in the bottom-right corner.
3. Click the toolbar icon, then click any element on the page to create an annotation.
4. Fill in a comment, choose an intent and severity, and optionally capture a screenshot.
5. Submit the annotation. It is immediately available to AI agents via MCP.

### Twig template context

When [Twig debug mode](https://www.drupal.org/docs/develop/theming-drupal/twig-in-drupal/debugging-twig-templates)
is enabled in `settings.php`:

```php
$settings['twig_debug'] = TRUE;
```

annotations created on front-end pages will automatically include the Twig template that
rendered the annotated element. No additional configuration is required — the feature
activates when debug HTML comments are present in the DOM.

The template name (`component`) and path (`source_file`) appear in the annotation's detail
view at `/admin/content/instruckt/{id}` and are included in the JSON returned by the
`instruckt-drupal_instruckt_get_all_pending` MCP tool.

> **Note:** Enable Twig debug only in development environments. It adds HTML comments to
> every page response and degrades performance on production sites.

### For AI agents (MCP)

> For full documentation on transport options, authentication methods, and all supported clients, see [drupalmcp.io: Connect to LLMs](https://drupalmcp.io/en/mcp-server/connect-to-llms/) and [Streamable HTTP](https://drupalmcp.io/en/mcp-server/streamable-http/).

Configure your AI agent to send `POST` requests to:

```
https://your-site.example.com/mcp/post
```

#### Authentication

The MCP module uses HTTP Basic auth format for all authentication methods. When using token auth (see Installation step 7), base64-encode the raw token value — with no username or colon — and send it as:

```
Authorization: Basic <base64-encoded-token>
```

Without valid credentials, or if the authenticated user lacks the `use mcp server` permission, `tools/list` silently returns an empty array with no error message.

#### Client configuration

**Claude Code** — create or edit `.mcp.json` in the project root:

```json
{
  "mcpServers": {
    "instruckt-drupal": {
      "type": "http",
      "url": "https://your-site.example.com/mcp/post",
      "headers": {
        "Authorization": "Basic <base64-encoded-token>"
      }
    }
  }
}
```

**Cursor** — add to `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "instruckt-drupal": {
      "type": "streamable-http",
      "url": "https://your-site.example.com/mcp/post",
      "headers": {
        "Authorization": "Basic <base64-encoded-token>"
      }
    }
  }
}
```

**Claude Desktop** and other clients that do not natively support HTTP transport require the `mcp-remote` bridge. See [drupalmcp.io: Streamable HTTP](https://drupalmcp.io/en/mcp-server/streamable-http/) for setup instructions.

#### Available tools

The agent will discover three tools. The `drupal/mcp` module prefixes all tool names with the plugin ID, so they appear as:

- **`instruckt-drupal_instruckt_get_all_pending`** — no arguments; returns all pending annotations as JSON
- **`instruckt-drupal_instruckt_get_screenshot`** — `annotation_id` (ULID string); returns the screenshot as a base64 image
- **`instruckt-drupal_instruckt_resolve`** — `id` (ULID string); marks the annotation resolved and deletes its screenshot

### Verifying the installation

Visit `/admin/reports/status` and search for "Instruckt" — the JS library row should show **Installed**. If it shows an error, verify that `web/libraries/instruckt/dist/instruckt.iife.js` exists and that `file_private_path` is set in `settings.php`.

To confirm the MCP endpoint is returning the instruckt tools, send a `tools/list` request:

```bash
curl -s -X POST https://your-site.example.com/mcp/post \
  -H "Authorization: Basic <base64-encoded-token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

The response should include `instruckt-drupal_instruckt_get_all_pending`, `instruckt-drupal_instruckt_get_screenshot`, and `instruckt-drupal_instruckt_resolve` in the `tools` array. If the array is empty, verify that the Instruckt Drupal plugin is enabled at `/admin/config/mcp/plugins` and that the authenticated token user has the `use mcp server` permission.

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

## Running Tests

Tests require a running DDEV environment and the private filesystem configured.

Run all tests in parallel (default 4 workers):

    ddev test

Specify a worker count explicitly:

    ddev test 4

To find the optimal worker count for your machine, run the one-time benchmark
(~9–12 min):

    ddev benchmark-tests

Pick the process count with the shortest wall time and no timeout failures,
then update the default in `.ddev/commands/web/test`.

**OOM / hang mitigation:** each worker enforces a 512 MB PHP memory limit
(`.ddev/php/memory.ini`). If tests hang or fail with memory errors, reduce
the process count or increase Docker Desktop's memory allocation in its settings.

### Mutation Testing

[Infection](https://infection.github.io/) measures test-suite effectiveness by
injecting synthetic bugs and verifying the tests kill them.

Run mutation tests (default 4 threads):

    ddev mutate

Or with an explicit thread count:

    ddev mutate 2

After the first run, review `infection.log` (text) or `infection.html` (browser)
for escaped mutants. Common follow-up actions:
- Add assertions to kill escaped mutants.
- Set `minMsi` / `minCoveredMsi` in `infection.json5` once you have a stable
  baseline to enforce in CI.

**Scope:** targets Unit tests only (fast). Kernel/Functional tests are excluded
to keep each mutant run sub-second.

## Credits

`instruckt_drupal` is a Drupal port of the [instruckt](https://github.com/joshcirre/instruckt) JavaScript library and the [instruckt-laravel](https://github.com/joshcirre/instruckt-laravel) Laravel package. All credit for the original concept, UI, and JavaScript implementation goes to the instruckt project contributors:

- [joshcirre](https://github.com/joshcirre)
- [JonPurvis](https://github.com/JonPurvis)
- [sgasser](https://github.com/sgasser)
