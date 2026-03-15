# Drupal instruckt_drupal Module — Technical Specification

## Overview / Context

The `instruckt_drupal` Drupal module ports the [instruckt-laravel](https://github.com/joshcirre/instruckt-laravel) package to Drupal 10/11. It provides a visual feedback toolbar powered by the [instruckt](https://github.com/joshcirre/instruckt) JavaScript library, allowing developers and reviewers to click-annotate page elements, attach comments and screenshots, and expose those annotations to AI coding agents via the Model Context Protocol (MCP).

The instruckt JS library is a pre-built, fully self-contained TypeScript/Vite bundle (IIFE format — `modern-screenshot` is bundled in via `noExternal`). The module declares `npm-asset/instruckt` as a Composer dependency via Asset Packagist, placing the file at `web/libraries/instruckt/dist/instruckt.iife.js`. A thin wrapper JS file initializes the library using `drupalSettings`.

## Goals and Non-Goals

**Goals:**
- Feature parity with instruckt-laravel: annotation CRUD, screenshot capture/storage, source resolution, MCP tool exposure
- Follow Drupal 10/11 best practices for file handling, routing, permissions, and services
- CSRF protection matching Drupal security conventions while remaining compatible with the unmodified instruckt JS (which reads the `XSRF-TOKEN` cookie, a Laravel convention)
- Single-user/small-team scale (development tool, not production user-facing)
- Reproducible JS asset installation via Composer + Asset Packagist

**Non-Goals:**
- Drupal entity storage for annotations (flat JSON is intentional for portability)
- Concurrent write safety beyond file locking
- Vue/React/Svelte/Livewire source resolution (Twig only for Drupal)
- Production-scale throughput or HA deployment
- A standalone HTTP/SSE MCP endpoint (the `drupal/mcp` module already provides `/mcp/post`)

## System Architecture

```
Browser (instruckt IIFE)
  │
  ├─ GET  /instruckt/annotations         → AnnotationController::list
  ├─ POST /instruckt/annotations         → AnnotationController::create
  ├─ PATCH /instruckt/annotations/{id}   → AnnotationController::update
  ├─ POST /instruckt/resolve-source      → AnnotationController::resolveSource
  └─ GET  /instruckt/screenshots/{file}  → AnnotationController::serveScreenshot

AI Agent (Claude / Cursor / etc.)
  └─ drupal/mcp contrib (/mcp/post HTTP)
       └─ InstrucktPlugin (#[Mcp] attribute) plugin
            └─ getTools() / executeTool()
                 ├─ instruckt_get_all_pending  → InstrucktStore::getPendingAnnotations
                 ├─ instruckt_get_screenshot   → InstrucktStore::getScreenshotRealPath + file_get_contents
                 └─ instruckt_resolve         → InstrucktStore::updateAnnotation (status → resolved)

Storage
  └─ private://_instruckt/
       ├─ annotations.json           (LOCK_EX on every write)
       └─ screenshots/
            ├─ {ulid}.png
            └─ {ulid}.svg
```

**CSRF flow:**
1. `InstrucktCsrfSubscriber` listens on `kernel.response`.
2. When the current user has `access instruckt_drupal toolbar`, it sets an `XSRF-TOKEN` cookie (value = Drupal CSRF token scoped to `InstrucktStore::CSRF_TOKEN_ID`).
3. The instruckt JS reads `XSRF-TOKEN` and sends `X-XSRF-TOKEN` header on all state-changing API requests.
4. `CsrfHeaderAccessCheck` validates the header via `CsrfTokenGenerator::validate($token, InstrucktStore::CSRF_TOKEN_ID)`.

**Note (March 2026):** `drupal/mcp_server` and `drupal/mcp` are actively merging under the `drupal/mcp` namespace. This module uses `drupal/mcp` (Omedia, v1.2+), the stable security-covered option with ~360 active installs.

**MCP transport:** `drupal/mcp` exposes an HTTP endpoint at `/mcp/post` (JSON-RPC 2.0). `instruckt_drupal` registers its tools via the `#[Mcp]` plugin attribute; they appear at `/admin/config/mcp/plugins` automatically once the module is enabled. No config entity is required — site administrators enable the plugin via the admin UI.

## Module Structure

```
instruckt_drupal/
├── composer.json
├── instruckt_drupal.info.yml
├── instruckt_drupal.permissions.yml
├── instruckt_drupal.libraries.yml
├── instruckt_drupal.routing.yml
├── instruckt_drupal.services.yml
├── instruckt_drupal.module
├── instruckt_drupal.install
├── config/
│   ├── install/
│   │   └── instruckt_drupal.settings.yml
│   └── schema/
│       └── instruckt_drupal.schema.yml
├── src/
│   ├── Access/
│   │   └── CsrfHeaderAccessCheck.php
│   ├── Controller/
│   │   └── AnnotationController.php
│   ├── EventSubscriber/
│   │   ├── InstrucktCsrfSubscriber.php
│   │   └── InstrucktJsonExceptionSubscriber.php
│   ├── Service/
│   │   ├── InstrucktStore.php
│   │   └── SourceResolver.php
│   └── Plugin/
│       └── Mcp/
│           └── InstrucktPlugin.php      ← one plugin, three MCP tools
├── js/
│   └── instruckt-toolbar.js             ← thin Drupal init wrapper
├── css/
│   └── instruckt-toolbar.css
└── tests/
    └── src/
        ├── Unit/
        │   ├── InstrucktStoreTest.php
        │   └── SourceResolverTest.php
        ├── Kernel/
        │   ├── InstrucktCsrfSubscriberTest.php
        │   ├── InstrucktJsonExceptionSubscriberTest.php
        │   └── InstrucktPluginTest.php
        └── Functional/
            ├── AnnotationApiTest.php
            ├── ScreenshotTest.php
            ├── PermissionTest.php
            ├── RequirementsTest.php
            └── McpPluginDiscoveryTest.php
```

**Note:** `instruckt.iife.js` is NOT in the module repository. It is installed by Composer into `web/libraries/instruckt/dist/instruckt.iife.js`.

## Annotation Data Model

The full annotation schema stored in `annotations.json`:

```json
{
  "id":            "string (ULID, server-generated via InstrucktStore::generateUlid())",
  "url":           "string (required, valid URL, max 2048)",
  "x":             "float (required, page-relative X coordinate, must be >= 0)",
  "y":             "float (required, page-relative Y coordinate, must be >= 0)",
  "comment":       "string (required, max 2000 chars)",
  "element":       "string (required, CSS selector, max 255)",
  "element_path":  "string|null (full DOM path, max 2048)",
  "css_classes":   "string|null (max 1024)",
  "nearby_text":   "string|null (max 500)",
  "selected_text": "string|null (max 500)",
  "bounding_box":  {"x": float, "y": float, "width": float, "height": float} | null  (ALL four fields required if object present; all must be >= 0),
  "screenshot":    "string|null (relative path: 'screenshots/{ulid}.png' or '.svg')",
  "intent":        "enum: fix|change|question|approve (default: fix)",
  "severity":      "enum: blocking|important|suggestion (default: important)",
  "status":        "enum: pending|resolved|dismissed (default: pending)",
  "framework":     {
    "framework":   "string (always 'twig' for Drupal; 'blade' is normalized to 'twig')",
    "component":   "string (Twig template machine name)",
    "source_file": "string|null (resolved server-side, relative to Drupal root)",
    "source_line": "int|null",
    "supported":   "bool (true if framework is Twig-compatible, false for React/Vue/etc.)"
  } | null,
  "thread":        "array of thread entries, max 100 (empty by default). Each entry: {author: string ('human'|'agent'), message: string (max 2000 chars), timestamp: string (ISO 8601)}",
  "resolved_by":   "string|null ('human' or 'agent')",
  "resolved_at":   "string|null (ISO 8601)",
  "created_at":    "string (ISO 8601)",
  "updated_at":    "string (ISO 8601)"
}
```

**Key points:**
- `id` is always server-generated as a ULID via `InstrucktStore::generateUlid()` — a self-contained Crockford Base32 implementation that avoids an undeclared `symfony/uid` dependency (Symfony's `Ulid` class is not guaranteed to be available in all Drupal 10 installs).
- `screenshot` stores a **relative path** (`screenshots/{ulid}.{ext}`), not base64 data.
- Both `.png` and `.svg` screenshot formats are supported (instruckt uses `modern-screenshot` which can produce SVG data URLs).
- The instruckt JS sends camelCase fields; the API receives snake_case (the instruckt JS `toSnake()` function converts before POST).

## Component Design

### 1. Composer Definition (`composer.json`)

```json
{
  "name": "drupal/instruckt_drupal",
  "description": "Visual feedback toolbar for AI coding agents",
  "type": "drupal-module",
  "license": "GPL-2.0-or-later",
  "require": {
    "drupal/mcp": "^1.2",
    "npm-asset/instruckt": "^0.4"
  }
}
```

> **Important:** The `repositories` key is intentionally omitted from the module's `composer.json`. Composer 2.x silently ignores `repositories` defined in non-root packages for security reasons — only the root project's `composer.json` is authoritative. Listing `asset-packagist.org` here would give a false impression that it works automatically.

**Required root `composer.json` configuration** (site-level prerequisite, documented in README, one-time project setup):

The root `composer.json` **must** include both the Asset Packagist repository and `oomphinc/composer-installers-extender` to configure `npm-asset` installer paths:

```json
{
  "repositories": [
    {
      "type": "composer",
      "url": "https://asset-packagist.org"
    }
  ],
  "require": {
    "oomphinc/composer-installers-extender": "^2.0"
  },
  "config": {
    "allow-plugins": {
      "oomphinc/composer-installers-extender": true,
      "composer/installers": true
    }
  },
  "extra": {
    "installer-types": ["npm-asset"],
    "installer-paths": {
      "web/libraries/{$name}": ["type:npm-asset"]
    }
  }
}
```

> **Composer 2.2+ requires `allow-plugins`** for `oomphinc/composer-installers-extender` and `composer/installers`. Without this block, `composer require drupal/instruckt_drupal` will fail in automated environments (CI, DDEV, Lando) with "Plugin … is not allowed" errors. `composer/installers` is likely already present in Drupal project templates, but both entries should be verified.

After `composer require drupal/instruckt_drupal`, the IIFE lands at `web/libraries/instruckt/dist/instruckt.iife.js`.

### 2. Module Definition (`instruckt_drupal.info.yml`)

```yaml
name: Instruckt
type: module
description: 'Visual feedback toolbar for AI coding agents'
core_version_requirement: ^10 || ^11
package: Development
dependencies:
  - mcp:mcp
```

### 3. Permissions (`instruckt_drupal.permissions.yml`)

```yaml
access instruckt_drupal toolbar:
  title: 'Access Instruckt toolbar'
  description: 'Allow users to see and use the Instruckt feedback toolbar'
  restrict access: true

administer instruckt_drupal:
  title: 'Administer Instruckt'
  description: 'Configure Instruckt settings'
  restrict access: true
```

### 4. Libraries (`instruckt_drupal.libraries.yml`)

```yaml
# The 'toolbar' library is defined programmatically via hook_library_info_build()
# in instruckt_drupal.module using \Drupal::root() for a layout-agnostic path.
# Only the wrapper JS and CSS are declared here for IDE tooling reference.
# Do NOT add a 'toolbar:' entry here — it conflicts with hook_library_info_build().
```

The instruckt IIFE is declared via `hook_library_info_build()` (see Module File section) using a **root-relative path** `/libraries/instruckt/dist/instruckt.iife.js` as the JS array key — the format Drupal's `LibraryDiscoveryParser` expects for files outside the module directory. The absolute filesystem path (`\Drupal::root() . '/libraries/...'`) is only used for the `file_exists()` existence check; using it as the library key would generate a broken `<script src="/var/www/html/web/libraries/...">` tag. This approach is layout-agnostic: it works regardless of whether the module is installed in `modules/contrib/`, `modules/custom/`, or any other location.

**Why not the YAML relative path**: `../../../libraries/instruckt/dist/instruckt.iife.js` would work for standard `drupal/recommended-project` layouts (both `contrib/` and `custom/` are 3 levels deep), but it silently fails for any non-standard layout. `hook_library_info_build()` is the robust default.

### 5. JS Wrapper (`js/instruckt-toolbar.js`)

```javascript
(function (Drupal, drupalSettings) {
  Drupal.behaviors.instruckt_drupal = {
    attach: function (context, settings) {
      if (context !== document) return;
      if (!window.Instruckt || !settings.instruckt_drupal) return;
      Instruckt.init({
        endpoint: settings.instruckt_drupal.endpoint,
        theme: settings.instruckt_drupal.theme || 'auto',
        position: settings.instruckt_drupal.position || 'bottom-right',
      });
    }
  };
})(Drupal, drupalSettings);
```

### 6. Routing (`instruckt_drupal.routing.yml`)

```yaml
instruckt_drupal.annotations.list:
  path: '/instruckt/annotations'
  defaults:
    _controller: '\Drupal\instruckt_drupal\Controller\AnnotationController::list'
  methods: [GET]
  requirements:
    _permission: 'access instruckt_drupal toolbar'

instruckt_drupal.annotations.create:
  path: '/instruckt/annotations'
  defaults:
    _controller: '\Drupal\instruckt_drupal\Controller\AnnotationController::create'
  methods: [POST]
  requirements:
    _permission: 'access instruckt_drupal toolbar'
    _csrf_header: 'TRUE'

instruckt_drupal.annotations.update:
  path: '/instruckt/annotations/{id}'
  defaults:
    _controller: '\Drupal\instruckt_drupal\Controller\AnnotationController::update'
  methods: [PATCH]
  requirements:
    _permission: 'access instruckt_drupal toolbar'
    _csrf_header: 'TRUE'

instruckt_drupal.resolve_source:
  path: '/instruckt/resolve-source'
  defaults:
    _controller: '\Drupal\instruckt_drupal\Controller\AnnotationController::resolveSource'
  methods: [POST]
  requirements:
    _permission: 'access instruckt_drupal toolbar'
    _csrf_header: 'TRUE'

instruckt_drupal.screenshot:
  path: '/instruckt/screenshots/{filename}'
  defaults:
    _controller: '\Drupal\instruckt_drupal\Controller\AnnotationController::serveScreenshot'
  methods: [GET]
  requirements:
    _permission: 'access instruckt_drupal toolbar'
```

`_csrf_header: 'TRUE'` is resolved by `CsrfHeaderAccessCheck` (tagged `access_check`, `applies_to: _csrf_header`), which reads the `X-XSRF-TOKEN` request header and validates it against `CsrfTokenGenerator::validate($token, 'instruckt_drupal')`.

### 7. Configuration (`config/install/instruckt_drupal.settings.yml`)

```yaml
enabled: true
storage_path: 'private://_instruckt'
max_screenshot_size: 5242880
allowed_screenshot_extensions:
  - png
  - svg
```

### 7b. Config Schema (`config/schema/instruckt_drupal.schema.yml`)

Required by Drupal for configuration validation, typed data, and UI generation. Without this, `drush config:export` produces warnings and config editing in the UI is unsupported.

```yaml
instruckt_drupal.settings:
  type: config_object
  label: 'Instruckt settings'
  mapping:
    enabled:
      type: boolean
      label: 'Enabled'
    storage_path:
      type: string
      label: 'Storage path'
    max_screenshot_size:
      type: integer
      label: 'Maximum screenshot size (bytes)'
    allowed_screenshot_extensions:
      type: sequence
      label: 'Allowed screenshot extensions'
      sequence:
        type: string
        label: 'Extension'
```

### 8. MCP Plugin Registration

`drupal/mcp` does **not** use config entities per plugin. Plugin discovery is automatic: when `instruckt_drupal` is enabled, the `#[Mcp]` attribute on `InstrucktPlugin` is scanned by the `drupal/mcp` plugin manager, and "Instruckt Drupal" appears at `/admin/config/mcp/plugins`. Site administrators enable or disable it there.

No `config/install/` file is required or shipped for this purpose. Plugin enable/disable state is stored in `mcp.settings` (managed by `drupal/mcp` itself).

### 9. Install / Requirements Hook (`instruckt_drupal.install`)

```php
<?php

/**
 * Implements hook_install().
 */
function instruckt_drupal_install() {
  $config = \Drupal::config('instruckt_drupal.settings');
  $storage_path = $config->get('storage_path');
  $file_system = \Drupal::service('file_system');

  // prepareDirectory() requires a variable (not expression) — PHP 8 pass-by-ref.
  $file_system->prepareDirectory(
    $storage_path,
    \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY
  );
  $screenshots_path = $storage_path . '/screenshots';
  $file_system->prepareDirectory(
    $screenshots_path,
    \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY
  );
}

/**
 * Implements hook_requirements().
 */
function instruckt_drupal_requirements($phase) {
  $requirements = [];

  if ($phase === 'install') {
    // Block installation if private:// stream wrapper is not configured.
    // Without this check, hook_install() would unconditionally call
    // prepareDirectory('private://...') and throw InvalidStreamWrapperException,
    // leaving the site's extension state broken.
    if (!\Drupal::service('stream_wrapper_manager')->isValidScheme('private')) {
      $requirements['instruckt_drupal_private_stream'] = [
        'title' => t('Instruckt storage: Private files'),
        'description' => t(
          'The <code>private://</code> stream wrapper must be configured in <code>settings.php</code> '
          . '(set <code>$settings["file_private_path"]</code>) before installing this module.'
        ),
        'severity' => REQUIREMENT_ERROR,
      ];
    }

    // Also enforce storage_path prefix at install time. Although the shipped default
    // is 'private://_instruckt', a site may have environment-specific config overrides.
    // Enforcing here prevents hook_install() from creating a public:// storage directory.
    $install_path = \Drupal::config('instruckt_drupal.settings')->get('storage_path');
    if (!str_starts_with($install_path ?? '', 'private://')) {
      $requirements['instruckt_drupal_storage_scheme_install'] = [
        'title' => t('Instruckt storage path'),
        'value' => t('Invalid scheme'),
        'description' => t(
          'The configured storage path <code>@path</code> must use the <code>private://</code> stream wrapper. '
          . 'Update <code>config/install/instruckt_drupal.settings.yml</code> or your config overrides before installing.',
          ['@path' => $install_path]
        ),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
  }

  if ($phase === 'runtime') {
    $iife_path = \Drupal::root() . '/libraries/instruckt/dist/instruckt.iife.js';
    if (!file_exists($iife_path) || !is_readable($iife_path)) {
      $requirements['instruckt_drupal_js'] = [
        'title' => t('Instruckt JS library'),
        'value' => t('Not found'),
        'description' => t(
          'The instruckt IIFE bundle is missing at <code>web/libraries/instruckt/dist/instruckt.iife.js</code>. '
          . 'Ensure <code>oomphinc/composer-installers-extender</code> is configured in your root <code>composer.json</code> '
          . 'and run <code>composer require drupal/instruckt_drupal</code>.'
        ),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
    else {
      $requirements['instruckt_drupal_js'] = [
        'title' => t('Instruckt JS library'),
        'value' => t('Installed'),
        'severity' => REQUIREMENT_OK,
      ];
    }

    $private_path = \Drupal::config('instruckt_drupal.settings')->get('storage_path');

    // Enforce that storage_path uses private:// stream to prevent accidental
    // web-accessible screenshot storage. Reject any other prefix (e.g. public://).
    if (!str_starts_with($private_path ?? '', 'private://')) {
      $requirements['instruckt_drupal_storage_scheme'] = [
        'title' => t('Instruckt storage path'),
        'value' => t('Invalid scheme'),
        'description' => t(
          'The storage path <code>@path</code> must use the <code>private://</code> stream wrapper to prevent web-accessible screenshot exposure.',
          ['@path' => $private_path]
        ),
        'severity' => REQUIREMENT_ERROR,
      ];
    }

    // Use is_writable() rather than prepareDirectory() — the hook_requirements()
    // runtime check must be read-only; prepareDirectory() would attempt to create
    // or chmod the directory as a side effect, which is inappropriate here.
    $real_private = \Drupal::service('file_system')->realpath($private_path);
    if (!$real_private || !is_dir($real_private) || !is_writable($real_private)) {
      $requirements['instruckt_drupal_storage'] = [
        'title' => t('Instruckt storage directory'),
        'value' => t('Not writable'),
        'description' => t(
          'The storage directory <code>@path</code> could not be created or is not writable. '
          . 'Ensure <code>$settings["file_private_path"]</code> is set in <code>settings.php</code>.',
          ['@path' => $private_path]
        ),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
  }

  return $requirements;
}

/**
 * Implements hook_uninstall().
 *
 * Intentionally does NOT delete private://_instruckt/ or its contents.
 * Annotation data is preserved across uninstall/reinstall for developer
 * continuity. To fully reset, delete the directory manually.
 */
function instruckt_drupal_uninstall() {
  // No-op: data is intentionally preserved. Document in README.
  \Drupal::messenger()->addWarning(t(
    'Instruckt data at private://_instruckt/ was preserved. Delete it manually if a full reset is desired.'
  ));
}
```

### 10. Module File (`instruckt_drupal.module`)

```php
<?php

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Implements hook_library_info_build().
 *
 * Defines the 'toolbar' library programmatically so that the instruckt IIFE
 * path is resolved using \Drupal::root() rather than a fragile relative path.
 * This works regardless of the module's install location (contrib, custom, etc.)
 */
function instruckt_drupal_library_info_build(): array {
  // Use the absolute FS path ONLY for file_exists() — never as a library key.
  // Drupal's LibraryDiscoveryParser expects files outside the module to use a
  // root-relative path with a leading slash (e.g. '/libraries/instruckt/...')
  // as the array key. Using the absolute FS path would emit a broken
  // <script src="/var/www/html/web/libraries/..."> tag and a 404 at runtime.
  $iife_fs = \Drupal::root() . '/libraries/instruckt/dist/instruckt.iife.js';
  $iife_key = '/libraries/instruckt/dist/instruckt.iife.js';

  if (!file_exists($iife_fs) || !is_readable($iife_fs)) {
    // File missing: hook_requirements() will report this as REQUIREMENT_ERROR.
    // Returning [] means 'instruckt_drupal/toolbar' won't exist as a library.
    // hook_page_attachments() checks _instruckt_drupal_iife_available() before
    // attaching, to avoid render-time "library not found" exceptions.
    return [];
  }

  return [
    'toolbar' => [
      'js' => [
        $iife_key => ['minified' => TRUE, 'weight' => -1],
        // Paths without a leading slash are relative to the module directory,
        // matching the convention used in .libraries.yml files.
        // DO NOT prefix with $modulePath — Drupal prepends the module path
        // automatically, and using $modulePath here causes path doubling
        // (e.g. modules/custom/instruckt_drupal/modules/custom/instruckt_drupal/js/...).
        'js/instruckt-toolbar.js' => ['weight' => 0],
      ],
      'css' => [
        'theme' => [
          'css/instruckt-toolbar.css' => [],
        ],
      ],
      'dependencies' => ['core/drupal', 'core/drupalSettings'],
    ],
  ];
}

/**
 * Implements hook_help().
 */
function instruckt_drupal_help($route_name, RouteMatchInterface $route_match) {
  if ($route_name === 'help.page.instruckt_drupal') {
    return '<p>' . t('Visual feedback toolbar for AI coding agents.') . '</p>';
  }
}

/**
 * Internal helper: returns TRUE if the instruckt IIFE library is available.
 * Checked before attaching to avoid a render-time "library not found" exception
 * when hook_library_info_build() returned [] due to a missing IIFE file.
 */
function _instruckt_drupal_iife_available(): bool {
  return file_exists(\Drupal::root() . '/libraries/instruckt/dist/instruckt.iife.js');
}

/**
 * Internal helper: returns TRUE if the instruckt toolbar should be shown
 * on the current request. Uses static caching to avoid repeated service calls.
 */
function _instruckt_drupal_toolbar_active(): bool {
  $active = &drupal_static(__FUNCTION__);
  if (!isset($active)) {
    $config = \Drupal::config('instruckt_drupal.settings');
    $user = \Drupal::currentUser();
    $active = $config->get('enabled')
      && $user->hasPermission('access instruckt_drupal toolbar')
      && !\Drupal::service('router.admin_context')->isAdminRoute();
  }
  return $active;
}

/**
 * Implements hook_page_attachments().
 */
function instruckt_drupal_page_attachments(array &$attachments) {
  // Cache metadata MUST be added unconditionally BEFORE any early returns.
  // Drupal's Dynamic Page Cache uses these to vary and invalidate responses.
  // Without this, an unauthenticated response gets cached and incorrectly
  // served to authenticated users (cache poisoning).
  // 'route.is_admin' is required because _instruckt_drupal_toolbar_active()
  // calls isAdminRoute() — the same user gets different attachments on admin
  // vs front-end pages, so the cache must vary by admin route status.
  $attachments['#cache']['tags'][] = 'config:instruckt_drupal.settings';
  $attachments['#cache']['contexts'][] = 'user.permissions';
  $attachments['#cache']['contexts'][] = 'route.is_admin';

  if (!_instruckt_drupal_toolbar_active() || !_instruckt_drupal_iife_available()) {
    return;
  }

  $attachments['#attached']['library'][] = 'instruckt_drupal/toolbar';
  // Use Url::fromRoute() so the endpoint is correct for subdirectory installs
  // AND multilingual sites with path-prefix language negotiation (e.g. /en/...).
  // base_path() alone omits language prefixes; Url::fromRoute() handles both.
  // We derive the /instruckt base from the /instruckt/annotations route URL by
  // trimming the '/annotations' suffix: strrpos() finds the last '/' in the path.
  $annotationsUrl = \Drupal\Core\Url::fromRoute('instruckt_drupal.annotations.list')->toString();
  $instrucktEndpoint = substr($annotationsUrl, 0, strrpos($annotationsUrl, '/'));
  $attachments['#attached']['drupalSettings']['instruckt_drupal'] = [
    'endpoint' => $instrucktEndpoint,
    'theme' => 'auto',
    'position' => 'bottom-right',
  ];
}

/**
 * Implements hook_page_bottom().
 *
 * Injects the toolbar mount point into the bottom of <body>.
 * hook_page_bottom() is the correct hook for this — not hook_page_attachments()
 * with html_head, which injects into <head>.
 */
function instruckt_drupal_page_bottom(array &$page_bottom) {
  // Add cache metadata unconditionally before any early return (see hook_page_attachments).
  // IMPORTANT: 'route.is_admin' is required here for the same reason as in
  // hook_page_attachments() — _instruckt_drupal_toolbar_active() calls isAdminRoute(),
  // so the output differs between admin and non-admin routes and the cache must vary.
  $page_bottom['instruckt_drupal_toolbar_placeholder'] = [
    '#markup' => '',
    '#cache' => [
      'tags' => ['config:instruckt_drupal.settings'],
      'contexts' => ['user.permissions', 'route.is_admin'],
    ],
  ];

  if (!_instruckt_drupal_toolbar_active() || !_instruckt_drupal_iife_available()) {
    return;
  }

  $page_bottom['instruckt_drupal_toolbar'] = [
    '#markup' => '<div id="instruckt-toolbar-root"></div>',
    '#cache' => [
      'tags' => ['config:instruckt_drupal.settings'],
      'contexts' => ['user.permissions', 'route.is_admin'],
    ],
  ];
}
```

### 11. CSRF Event Subscriber (`src/EventSubscriber/InstrucktCsrfSubscriber.php`)

```php
<?php

namespace Drupal\instruckt_drupal\EventSubscriber;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the XSRF-TOKEN cookie for instruckt JS compatibility.
 *
 * The instruckt JS reads the XSRF-TOKEN cookie (Laravel convention) and sends
 * it as the X-XSRF-TOKEN header on state-changing requests. This subscriber
 * emits the cookie containing Drupal's CSRF token (scoped to 'instruckt_drupal')
 * so the unmodified instruckt JS works without patching.
 */
class InstrucktCsrfSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $config = $this->configFactory->get('instruckt_drupal.settings');
    if (!$config->get('enabled')) {
      return;
    }

    if (!$this->currentUser->hasPermission('access instruckt_drupal toolbar')) {
      return;
    }

    $token = $this->csrfToken->get(InstrucktStore::CSRF_TOKEN_ID);

    // Only emit Set-Cookie if the cookie is absent or stale. Unconditionally
    // emitting Set-Cookie on every response disables Drupal's Dynamic Page Cache
    // for all authenticated users, causing severe performance regression.
    $existingCookie = $event->getRequest()->cookies->get('XSRF-TOKEN');
    if ($existingCookie === $token) {
      return;
    }

    // Not HttpOnly: the instruckt JS must read this cookie client-side.
    // Secure: auto-detected from the current request — true on HTTPS, false on HTTP.
    //   This means the module is secure-by-default on HTTPS environments (Lando, DDEV
    //   with TLS, production) and still works on plain HTTP dev setups.
    // SameSite=Lax: mitigates CSRF from cross-site navigations.
    // Path: base_path() (e.g. '/' for root installs, '/subdir/' for subdirectory installs).
    //   Using '/' alone would leak the cookie to sibling Drupal sites on the same domain;
    //   base_path() scopes it to this Drupal installation's path prefix.
    $secure = $event->getRequest()->isSecure();
    $event->getResponse()->headers->setCookie(
      new Cookie('XSRF-TOKEN', $token, 0, base_path(), null, $secure, false, false, 'Lax')
    );
  }
}
```

### 12. JSON Exception Subscriber (`src/EventSubscriber/InstrucktJsonExceptionSubscriber.php`)

Drupal returns HTML 403/404 pages by default. API clients on `/instruckt/*` paths expect JSON.
This subscriber intercepts HTTP exceptions **at `KernelEvents::EXCEPTION`** — before Drupal renders
the HTML error page — and short-circuits with a JSON response instead. This prevents the expensive
block/theme render that `KernelEvents::RESPONSE` would only catch after the fact.

```php
<?php

namespace Drupal\instruckt_drupal\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intercepts HTTP exceptions on /instruckt/* paths and returns JSON.
 *
 * Listens on KernelEvents::EXCEPTION (priority 0) — before Drupal's
 * DefaultExceptionSubscriber renders an HTML error page. By setting a response
 * here, we prevent the full theme/block render cycle for API routes.
 */
final class InstrucktJsonExceptionSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    // Priority 0 runs before Drupal's DefaultExceptionSubscriber (priority -50).
    return [KernelEvents::EXCEPTION => ['onException', 0]];
  }

  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    // Only intercept /instruckt/* paths.
    if (!str_starts_with($request->getPathInfo(), '/instruckt')) {
      return;
    }
    $exception = $event->getThrowable();

    // Determine HTTP status and message.
    if ($exception instanceof HttpExceptionInterface) {
      $statusCode = $exception->getStatusCode();
      // Drupal uses 403 for all auth failures (unauthenticated + unauthorized).
      // 401 is not emitted; exclude it to avoid misleading clients.
      $messages = [
        400 => 'Bad request',
        403 => 'Access denied',
        404 => 'Resource not found',
        405 => 'Method not allowed',
        413 => 'Payload too large',
        415 => 'Unsupported media type',
      ];
      $message = $messages[$statusCode] ?? $exception->getMessage() ?: 'An error occurred';
    }
    else {
      // Non-HTTP exceptions (PHP errors, unexpected server errors) are 500s.
      // Return a generic message — do not expose internal exception details.
      $statusCode = 500;
      $message = 'An unexpected error occurred';
    }

    $event->setResponse(new JsonResponse(['error' => $message], $statusCode));
  }

}
```

### 14. CSRF Header Access Check (`src/Access/CsrfHeaderAccessCheck.php`)

```php
<?php

namespace Drupal\instruckt_drupal\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Validates the X-XSRF-TOKEN header against Drupal's CSRF token system.
 *
 * Registered as an access_check service with applies_to: _csrf_header.
 * Routes that declare _csrf_header: 'TRUE' are protected by this check.
 */
class CsrfHeaderAccessCheck implements AccessInterface {

  public function __construct(private readonly CsrfTokenGenerator $csrfToken) {}

  public function access(Route $route, Request $request): AccessResultInterface {
    if ($route->getRequirement('_csrf_header') !== 'TRUE') {
      return AccessResult::neutral();
    }

    $token = $request->headers->get('X-XSRF-TOKEN', '');

    // Return forbidden (not neutral) when token is absent. All routes with
    // _csrf_header: 'TRUE' are state-changing POST/PATCH endpoints. There are
    // no legitimate non-JS callers for these endpoints: MCP tools call
    // InstrucktStore directly (not via HTTP), and Drush uses stdio transport.
    // neutral() + _permission:allowed = request succeeds without CSRF, so
    // we must use forbidden() to close that gap.
    if ($token === '') {
      return AccessResult::forbidden('Missing CSRF token')->setCacheMaxAge(0);
    }

    return $this->csrfToken->validate($token, InstrucktStore::CSRF_TOKEN_ID)
      ? AccessResult::allowed()->setCacheMaxAge(0)
      : AccessResult::forbidden('Invalid CSRF token')->setCacheMaxAge(0);
  }
}
```

### 14b. Storage Exception (`src/Exception/InstrucktStorageException.php`)

A thin exception class used by `InstrucktStore` to signal I/O failures (file open, lock, encode, write). Distinguishes storage errors from domain-level `NULL` returns ("not found").

```php
<?php

namespace Drupal\instruckt_drupal\Exception;

/**
 * Thrown by InstrucktStore when a storage operation fails (I/O, lock, encode).
 *
 * Callers that receive NULL from a store method interpret it as "not found".
 * Callers that catch InstrucktStorageException interpret it as a 500-class error.
 */
class InstrucktStorageException extends \RuntimeException {}
```

### 15. Storage Service (`src/Service/InstrucktStore.php`)

Implements ULID IDs, transactional file locking, PNG/SVG support, stream wrapper paths throughout. `realpath()` is used only where a true filesystem path is required (e.g., `BinaryFileResponse`).

**Locking strategy**: All annotation mutations use `fopen()` + `flock(LOCK_EX)` held across the entire read-modify-write transaction. This prevents the TOCTOU (Time-Of-Check to Time-Of-Use) race condition where two concurrent requests could each read the same snapshot and overwrite each other's writes. A plain `file_put_contents(..., LOCK_EX)` only locks during the write and does not protect the read-modify-write cycle.

```php
<?php

namespace Drupal\instruckt_drupal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\instruckt_drupal\Exception\InstrucktStorageException;

/**
 * Manages annotation and screenshot persistence for instruckt_drupal.
 */
class InstrucktStore {

  /**
   * CSRF token scope. Shared constant used by CsrfSubscriber and CsrfHeaderAccessCheck.
   * Using a named constant prevents typos across the codebase.
   */
  const CSRF_TOKEN_ID = 'instruckt_drupal';

  private string $storagePath;
  private \Drupal\Core\Config\ImmutableConfig $config;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->config = $configFactory->get('instruckt_drupal.settings');
    $this->storagePath = $this->config->get('storage_path');
  }

  private function annotationsPath(): string {
    return $this->storagePath . '/annotations.json';
  }

  private function screenshotsDir(): string {
    return $this->storagePath . '/screenshots';
  }

  private function now(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
  }

  // ---------------------------------------------------------------------------
  // Annotation read/write
  // ---------------------------------------------------------------------------

  /**
   * Returns all stored annotations.
   *
   * On parse failure (corrupted annotations.json), logs an error and returns []
   * so that read-only callers degrade gracefully. This means callers CANNOT
   * distinguish an empty store from a corrupted one from the return value alone;
   * they must consult the Drupal log. Write paths (withAnnotationsLocked) abort
   * on JSON parse error and throw InstrucktStorageException, preventing overwrites
   * on top of corrupted data.
   */
  public function getAnnotations(): array {
    $path = $this->annotationsPath();
    if (!file_exists($path)) {
      return [];
    }
    // LOCK_SH is no longer needed: writes use atomic rename(), so readers always
    // see a complete file. file_get_contents() is safe without a shared lock.
    $content = @file_get_contents($path);
    if ($content === FALSE || $content === '') {
      return [];
    }
    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
      // Log at error level so site admins see the corruption in the Drupal logs.
      // We return [] rather than throwing because getAnnotations() is used in
      // read-only contexts (list, MCP) where partial degradation is preferable
      // to a hard failure. Write paths protect against overwriting corrupt data
      // by aborting in withAnnotationsLocked() instead.
      $this->logger->error('InstrucktStore: getAnnotations() parse failure — annotations.json may be corrupted. Returning empty array to avoid a hard failure; check and restore the file manually.');
      return [];
    }
    return $data;
  }

  public function getPendingAnnotations(): array {
    return array_values(array_filter(
      $this->getAnnotations(),
      fn($a) => ($a['status'] ?? 'pending') === 'pending',
    ));
  }

  /**
   * Executes a callback with an exclusive lock held across the full read-modify-write cycle.
   *
   * This prevents TOCTOU races where two concurrent requests read the same snapshot
   * and overwrite each other's writes.
   *
   * Contract: The callback receives the current annotations array BY REFERENCE
   * (array &$all) and mutates it in place. The callback's return value is passed
   * through as the return of withAnnotationsLocked().
   *
   * Lock file design: The lock is taken on a DEDICATED `annotations.lock` file,
   * NOT on annotations.json itself. This is critical for correctness with atomic
   * rename() writes: if we locked annotations.json and a writer renamed it, the
   * next waiter would acquire flock on the old inode, read stale content, and then
   * overwrite the new file with an update based on stale state — a lost-update race.
   * The .lock file's inode never changes, so all writers always compete on the same
   * inode. Reads (getAnnotations) are lock-free because rename() is atomic on POSIX.
   */
  /**
   * @throws InstrucktStorageException on any I/O or parse failure.
   *   Callers distinguish storage errors (exception) from domain-level NULL
   *   (e.g. annotation not found, which the callback sets via reference).
   */
  private function withAnnotationsLocked(callable $fn): mixed {
    // prepareDirectory() requires a variable (not expression) — PHP 8 pass-by-ref.
    $storagePathVar = $this->storagePath;
    $prepared = $this->fileSystem->prepareDirectory(
      $storagePathVar,
      FileSystemInterface::CREATE_DIRECTORY
    );
    if (!$prepared) {
      $this->logger->error('InstrucktStore: could not prepare storage directory @path', ['@path' => $this->storagePath]);
      throw new InstrucktStorageException("Could not prepare storage directory: {$this->storagePath}");
    }

    $storageDir = $this->fileSystem->realpath($this->storagePath);
    $lockPath = $storageDir . '/annotations.lock';
    $realPath = $storageDir . '/annotations.json';

    // Use a permanent lock file whose inode never changes.
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === FALSE) {
      $this->logger->error('InstrucktStore: could not open lock file @path', ['@path' => $lockPath]);
      throw new InstrucktStorageException("Could not open lock file: $lockPath");
    }
    if (!flock($lockFp, LOCK_EX)) {
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to acquire write lock on @path', ['@path' => $lockPath]);
      throw new InstrucktStorageException("Could not acquire write lock: $lockPath");
    }

    // Read annotations.json fresh under the lock.
    $content = @file_get_contents($realPath);

    // Guard: abort on JSON parse failure to prevent overwriting a corrupted file
    // with an empty array, destroying all existing annotations.
    if ($content !== '' && $content !== FALSE) {
      $annotations = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE || !is_array($annotations)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $err = json_last_error_msg();
        $this->logger->error(
          'InstrucktStore: annotations.json parse failure (@err). Aborting write to prevent data loss.',
          ['@err' => $err]
        );
        throw new InstrucktStorageException("annotations.json parse failure: $err");
      }
    } else {
      $annotations = [];
    }

    // Let the callback transform the array. Return value is passed through;
    // void callbacks return NULL which is a valid domain result (e.g. "not found").
    $result = $fn($annotations);

    // Encode BEFORE touching the file — fail fast on invalid UTF-8.
    try {
      $json = json_encode(
        array_values($annotations),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
      ) . "\n";
    } catch (\JsonException $e) {
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: JSON encode failed: @msg', ['@msg' => $e->getMessage()]);
      throw new InstrucktStorageException("JSON encode failed: " . $e->getMessage(), 0, $e);
    }

    // Atomic write: write to temp then rename(). rename() is atomic on POSIX —
    // readers never see a partial file. The lock ensures only one writer at a time.
    $tmpPath = $realPath . '.tmp';
    if (file_put_contents($tmpPath, $json) === FALSE) {
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to write temp file @tmp', ['@tmp' => $tmpPath]);
      throw new InstrucktStorageException("Failed to write temp file: $tmpPath");
    }
    if (!rename($tmpPath, $realPath)) {
      @unlink($tmpPath);
      flock($lockFp, LOCK_UN);
      fclose($lockFp);
      $this->logger->error('InstrucktStore: failed to rename @tmp to @path', ['@tmp' => $tmpPath, '@path' => $realPath]);
      throw new InstrucktStorageException("Failed to rename $tmpPath to $realPath");
    }

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    return $result;
  }

  /**
   * Generates a ULID using a self-contained Crockford Base32 implementation.
   * Avoids a dependency on symfony/uid which is not guaranteed in all Drupal 10 installs.
   */
  private function generateUlid(): string {
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int) (microtime(TRUE) * 1000);
    $ts = '';
    for ($i = 9; $i >= 0; $i--) {
      $ts = $alphabet[$ms & 0x1F] . $ts;
      $ms >>= 5;
    }
    $rand = '';
    for ($i = 0; $i < 16; $i++) {
      $rand .= $alphabet[random_int(0, 31)];
    }
    return $ts . $rand;
  }

  /**
   * Creates a new annotation. ID is always server-generated as a ULID.
   */
  public function createAnnotation(array $data): ?array {
    $id = $this->generateUlid();
    $now = $this->now();

    $screenshot = NULL;
    if (!empty($data['screenshot'])) {
      $screenshot = $this->saveScreenshot($id, $data['screenshot']);
      // If a screenshot was provided but could not be saved (I/O failure), fail
      // the entire annotation creation rather than silently dropping the screenshot.
      if ($screenshot === NULL) {
        return NULL;
      }
    }

    $annotation = [
      'id'            => $id,
      'url'           => $data['url'] ?? '',
      'x'             => (float) ($data['x'] ?? 0),
      'y'             => (float) ($data['y'] ?? 0),
      'comment'       => $data['comment'] ?? '',
      'element'       => $data['element'] ?? '',
      'element_path'  => $data['element_path'] ?? NULL,
      'css_classes'   => $data['css_classes'] ?? NULL,
      'nearby_text'   => $data['nearby_text'] ?? NULL,
      'selected_text' => $data['selected_text'] ?? NULL,
      'bounding_box'  => $data['bounding_box'] ?? NULL,
      'screenshot'    => $screenshot,
      'intent'        => $data['intent'] ?? 'fix',
      'severity'      => $data['severity'] ?? 'important',
      'status'        => 'pending',
      'framework'     => $data['framework'] ?? NULL,
      'thread'        => [],
      'resolved_by'   => NULL,
      'resolved_at'   => NULL,
      'created_at'    => $now,
      'updated_at'    => $now,
    ];

    try {
      $this->withAnnotationsLocked(function (array &$all) use ($annotation): void {
        $all[] = $annotation;
      });
    } catch (InstrucktStorageException $e) {
      // Storage failure: clean up the screenshot we already wrote (if any).
      if ($screenshot !== NULL) {
        $this->deleteScreenshot($screenshot);
      }
      return NULL;
    }

    return $annotation;
  }

  /**
   * Updates allowed fields on an annotation.
   *
   * Returns the updated annotation array on success, NULL if no annotation with
   * $id exists (caller returns 404), or FALSE if InstrucktStorageException was
   * caught (caller returns 500). Only 'status', 'comment', 'thread' are accepted
   * from $data; resolved_by and resolved_at are always server-controlled.
   *
   * @param string $id
   *   Annotation ULID.
   * @param array $data
   *   Fields to update.
   * @param string $resolvedBy
   *   Who is resolving: 'human' (HTTP controller) or 'agent' (MCP tool).
   *   Only used when status changes to 'resolved' or 'dismissed'.
   */
  public function updateAnnotation(string $id, array $data, string $resolvedBy = 'human'): array|false|null {
    // resolved_by and resolved_at are server-controlled — not client-settable.
    // They are auto-populated on resolution and cleared on reopen to prevent spoofing.
    $allowed = ['status', 'comment', 'thread'];
    $updated = NULL;
    $screenshotToDelete = NULL;

    try {
    $this->withAnnotationsLocked(function (array &$all) use ($id, $data, $allowed, $resolvedBy, &$updated, &$screenshotToDelete): void {
      foreach ($all as &$annotation) {
        if ($annotation['id'] !== $id) {
          continue;
        }
        foreach ($data as $key => $value) {
          if (in_array($key, $allowed, TRUE)) {
            // 'thread' is REPLACED wholesale, not appended — the client owns
            // the full thread array and sends the complete new state each time.
            $annotation[$key] = $value;
          }
        }
        $annotation['updated_at'] = $this->now();

        $newStatus = $data['status'] ?? NULL;
        if (in_array($newStatus, ['resolved', 'dismissed'], TRUE)) {
          $screenshotToDelete = $annotation['screenshot'] ?? NULL;
          // Nullify the reference so the stored annotation doesn't dangle.
          $annotation['screenshot'] = NULL;
          // Auto-populate resolution metadata server-side — never from client input.
          // resolved_by and resolved_at are intentionally NOT in $allowed;
          // they are set here unconditionally to prevent spoofing.
          // $resolvedBy distinguishes 'human' (HTTP) from 'agent' (MCP).
          $annotation['resolved_by'] = $resolvedBy;
          $annotation['resolved_at'] = $this->now();
        }
        // Clear resolution fields when reopening to pending.
        if ($newStatus === 'pending') {
          $annotation['resolved_by'] = NULL;
          $annotation['resolved_at'] = NULL;
        }

        $updated = $annotation;
        break;
      }
      unset($annotation);
    });
    } catch (InstrucktStorageException $e) {
      // Propagate storage errors as FALSE so callers distinguish:
      //   NULL  → annotation not found (404)
      //   FALSE → storage/I/O error (500)
      return FALSE;
    }

    // Delete screenshot outside the lock to avoid holding the lock during I/O.
    if ($screenshotToDelete !== NULL) {
      $this->deleteScreenshot($screenshotToDelete);
    }

    // $updated is NULL when no annotation with $id was found in the store.
    return $updated;
  }

  // ---------------------------------------------------------------------------
  // Screenshot handling
  // ---------------------------------------------------------------------------

  /**
   * Saves a data URL screenshot. Returns relative path or NULL on failure.
   *
   * Supports both base64-encoded PNG and URL-encoded SVG data URLs, matching
   * instruckt's use of modern-screenshot which may produce either format.
   */
  public function saveScreenshot(string $id, string $dataUrl): ?string {
    if (!str_starts_with($dataUrl, 'data:image/')) {
      return NULL;
    }

    $dir = $this->screenshotsDir();
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY
    );

    $parts = explode(',', $dataUrl, 2);
    $header = $parts[0] ?? '';
    $rawData = $parts[1] ?? '';

    if (str_contains($header, ';base64')) {
      $binary = base64_decode($rawData, TRUE);  // strict mode
      $ext = str_contains($header, 'image/svg+xml') ? 'svg' : 'png';
    }
    else {
      // URL-encoded SVG (from snapdom/modern-screenshot)
      $binary = urldecode($rawData);
      $ext = 'svg';
    }

    if (!$binary) {
      return NULL;
    }

    // Enforce allowed extensions from config.
    $allowedExtensions = $this->config->get('allowed_screenshot_extensions') ?? ['png', 'svg'];
    if (!in_array($ext, $allowedExtensions, TRUE)) {
      $this->logger->warning('InstrucktStore: rejected screenshot with disallowed extension @ext', ['@ext' => $ext]);
      return NULL;
    }

    // Enforce max screenshot size from config (default: 5MB).
    $maxSize = $this->config->get('max_screenshot_size') ?? 5242880;
    if (strlen($binary) > $maxSize) {
      $this->logger->warning('InstrucktStore: rejected screenshot exceeding @max bytes', ['@max' => $maxSize]);
      return NULL;
    }

    $filename = "{$id}.{$ext}";
    // Use fileSystem->saveData() for Drupal-idiomatic file writing.
    $uri = $this->screenshotsDir() . '/' . $filename;
    if ($this->fileSystem->saveData($binary, $uri, FileSystemInterface::EXISTS_REPLACE) === FALSE) {
      $this->logger->error('InstrucktStore: failed to save screenshot to @uri', ['@uri' => $uri]);
      return NULL;
    }

    return "screenshots/{$filename}";
  }

  /**
   * Returns the real filesystem path for a screenshot, or NULL if not found.
   *
   * realpath() is used here because BinaryFileResponse requires a real path,
   * not a stream wrapper URI.
   */
  public function getScreenshotRealPath(string $relPath): ?string {
    $uri = $this->storagePath . '/' . $relPath;
    if (!file_exists($uri)) {
      return NULL;
    }
    return $this->fileSystem->realpath($uri) ?: NULL;
  }

  public function deleteScreenshot(?string $relPath): void {
    if (!$relPath) {
      return;
    }
    $uri = $this->storagePath . '/' . $relPath;
    if (file_exists($uri)) {
      // Use fileSystem->delete() for Drupal-idiomatic deletion with proper
      // stream wrapper support. Catch FileException so concurrent resolution
      // (e.g. two agents resolving simultaneously) does not bubble a 500.
      try {
        $this->fileSystem->delete($uri);
      } catch (\Drupal\Core\File\Exception\FileException $e) {
        $this->logger->warning('Could not delete screenshot @uri: @message', [
          '@uri' => $uri,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }
}
```

### 16. Source Resolver (`src/Service/SourceResolver.php`)

Scoped to Twig templates only. The API contract from the instruckt frontend is `{framework, component}`. For Drupal, only `framework: 'twig'` (with `'blade'` accepted as an alias) is resolved. Other framework types return a structured "unsupported" response rather than an error, matching the frontend's expectations.

```php
<?php

namespace Drupal\instruckt_drupal\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\Registry as ThemeRegistry;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Resolves Twig template names to their filesystem paths.
 *
 * Scoped to Twig only. The instruckt JS sends {framework, component} where
 * framework may be 'livewire', 'vue', 'svelte', 'react', or 'blade'. In the
 * Drupal context only 'twig' (and 'blade' as an alias) are meaningful.
 *
 * theme.registry is injected (not called statically) for testability.
 */
class SourceResolver {

  private const TWIG_FRAMEWORKS = ['twig', 'blade'];

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeExtensionList $themeList,
    private readonly ModuleExtensionList $moduleList,
    private readonly ThemeRegistry $themeRegistry,
    // Injected rather than using \Drupal::root() statically to preserve testability.
    private readonly string $appRoot,
  ) {}

  /**
   * Resolves a framework component to its source file.
   *
   * @param string $framework  e.g. 'twig', 'blade', 'livewire', 'vue'
   * @param string $component  Twig template machine name, e.g. 'node--article--teaser'
   *
   * @return array{framework: string, component: string, source_file: string|null, supported: bool}
   */
  public function resolve(string $framework, string $component): array {
    if (!in_array($framework, self::TWIG_FRAMEWORKS, TRUE)) {
      return [
        'framework'   => $framework,
        'component'   => $component,
        'source_file' => NULL,
        'source_line' => NULL,
        'supported'   => FALSE,
        'message'     => "Framework '$framework' is not supported by instruckt_drupal. Only Twig templates can be resolved.",
      ];
    }

    // Normalize 'blade' alias → 'twig' in the response and for storage.
    // The instruckt JS may send 'blade' for Laravel-style components; in the
    // Drupal context this is always Twig. Storing the canonical name prevents
    // ambiguity in clients and annotations.
    $canonicalFramework = 'twig';

    return [
      'framework'   => $canonicalFramework,
      'component'   => $component,
      'source_file' => $this->findTwigTemplate($component),
      'source_line' => NULL,  // Line resolution requires static analysis; not implemented.
      'supported'   => TRUE,
    ];
  }

  private function findTwigTemplate(string $templateName): ?string {
    // Normalize: Drupal template filenames use hyphens; dots may appear in
    // the component name sent by the JS.
    $templateName = str_replace('.', '-', $templateName);
    $templateFile = $templateName . '.html.twig';

    // 1. Active theme registry (most authoritative — respects overrides).
    $themeRegistry = $this->themeRegistry->get();
    $registryKey = str_replace('-', '_', $templateName);
    if (isset($themeRegistry[$registryKey]['path'])) {
      $candidate = $themeRegistry[$registryKey]['path'] . '/' . $templateFile;
      if (file_exists($candidate)) {
        return $this->relativePath($candidate);
      }
    }

    // 2. Active theme and its base theme chain.
    $activeTheme = $this->themeManager->getActiveTheme();
    $themesToSearch = [$activeTheme->getName()];
    foreach ($activeTheme->getBaseThemeExtensions() as $base) {
      $themesToSearch[] = $base->getName();
    }
    foreach ($themesToSearch as $themeName) {
      $themePath = $this->themeList->getPath($themeName);
      $templatesDir = $themePath . '/templates';
      // Use RecursiveDirectoryIterator so templates in deeply nested subdirs
      // (e.g. templates/content/node, templates/layout/page, or any custom depth)
      // are found without hardcoding directory names.
      if (is_dir($templatesDir)) {
        $rit = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rit as $file) {
          if ($file->getFilename() === $templateFile) {
            return $this->relativePath($file->getPathname());
          }
        }
      }
    }

    // 3. Enabled modules (for module-provided templates, searched non-recursively
    // since modules conventionally keep templates in a flat templates/ directory).
    foreach (array_keys($this->moduleList->getAllInstalledInfo()) as $moduleName) {
      $candidate = $this->moduleList->getPath($moduleName) . '/templates/' . $templateFile;
      if (file_exists($candidate)) {
        return $this->relativePath($candidate);
      }
    }

    return NULL;
  }

  private function relativePath(string $absolutePath): string {
    // Use $this->appRoot (injected) rather than \Drupal::root() (static).
    $root = rtrim($this->appRoot, '/') . '/';
    return str_starts_with($absolutePath, $root)
      ? substr($absolutePath, strlen($root))
      : $absolutePath;
  }
}
```

### 17. Annotation Controller (`src/Controller/AnnotationController.php`)

Full validation against the actual instruckt schema. The update endpoint returns the full updated annotation (not just `{success: true}`), matching the Laravel controller's behavior.

```php
<?php

namespace Drupal\instruckt_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\instruckt_drupal\Service\SourceResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for instruckt_drupal annotation API endpoints.
 */
class AnnotationController extends ControllerBase {

  // Reusable 503 response for when the module is administratively disabled.
  private function disabledResponse(): JsonResponse {
    return new JsonResponse(['error' => 'Instruckt is disabled'], 503, ['Cache-Control' => 'no-store']);
  }

  public function __construct(
    private readonly InstrucktStore $store,
    private readonly SourceResolver $sourceResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instruckt_drupal.store'),
      $container->get('instruckt_drupal.source_resolver'),
      $container->get('logger.channel.instruckt_drupal'),
    );
  }

  public function list(): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // no-store: annotation storage is shared across all authorized users.
    // Prevent proxies or browser from caching a snapshot that would be stale
    // for other users. Annotations are returned in insertion order.
    return new JsonResponse($this->store->getAnnotations(), 200, [
      'Cache-Control' => 'no-store',
    ]);
  }

  public function create(Request $request): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Enforce JSON Content-Type before attempting to decode body.
    // instruckt JS always sends application/json, so a missing/wrong type
    // indicates a misconfigured client — return 415 explicitly.
    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    // Required fields (matching Laravel's validation).
    $errors = [];
    foreach (['x', 'y', 'comment', 'element', 'url'] as $field) {
      if (!isset($data[$field]) || ($field === 'comment' && $data[$field] === '')) {
        $errors[] = "Missing required field: $field";
      }
    }
    if ($errors) {
      return new JsonResponse(['error' => implode('; ', $errors)], 400);
    }

    // PHP 8 TypeError guard: mb_strlen()/filter_var() on a non-string is a fatal.
    // Validate type before any string operations.
    foreach (['url', 'element', 'comment'] as $stringField) {
      if (isset($data[$stringField]) && !is_string($data[$stringField])) {
        return new JsonResponse(['error' => "$stringField must be a string"], 400);
      }
    }
    foreach (['element_path', 'nearby_text', 'selected_text', 'css_classes'] as $optStringField) {
      if (isset($data[$optStringField]) && !is_string($data[$optStringField])) {
        return new JsonResponse(['error' => "$optStringField must be a string"], 400);
      }
    }

    // URL format, scheme, and length validation.
    // filter_var(FILTER_VALIDATE_URL) accepts dangerous schemes like javascript:
    // and file: — explicitly restrict to http/https only.
    if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
      return new JsonResponse(['error' => 'Invalid URL format'], 400);
    }
    $urlScheme = parse_url($data['url'], PHP_URL_SCHEME);
    if (!in_array($urlScheme, ['http', 'https'], TRUE)) {
      return new JsonResponse(['error' => 'URL must use http or https scheme'], 400);
    }
    if (mb_strlen($data['url']) > 2048) {
      return new JsonResponse(['error' => 'url exceeds 2048 characters'], 400);
    }
    if (mb_strlen($data['element'] ?? '') > 255) {
      return new JsonResponse(['error' => 'element exceeds 255 characters'], 400);
    }
    if (isset($data['element_path']) && mb_strlen($data['element_path']) > 2048) {
      return new JsonResponse(['error' => 'element_path exceeds 2048 characters'], 400);
    }
    if (isset($data['nearby_text']) && mb_strlen($data['nearby_text']) > 500) {
      return new JsonResponse(['error' => 'nearby_text exceeds 500 characters'], 400);
    }
    if (isset($data['selected_text']) && mb_strlen($data['selected_text']) > 500) {
      return new JsonResponse(['error' => 'selected_text exceeds 500 characters'], 400);
    }
    // Prevent unbounded payloads: css_classes is read on every getAnnotations() call.
    if (isset($data['css_classes']) && mb_strlen($data['css_classes']) > 1024) {
      return new JsonResponse(['error' => 'css_classes exceeds 1024 characters'], 400);
    }

    // Coordinate bounds: must be non-negative numbers.
    if (!is_numeric($data['x']) || (float) $data['x'] < 0) {
      return new JsonResponse(['error' => 'x must be a non-negative number'], 400);
    }
    if (!is_numeric($data['y']) || (float) $data['y'] < 0) {
      return new JsonResponse(['error' => 'y must be a non-negative number'], 400);
    }

    // bounding_box: all four fields required if object present; all must be >= 0.
    // Explicitly reject empty objects: {"bounding_box": {}} is invalid.
    if (isset($data['bounding_box'])) {
      if (!is_array($data['bounding_box']) || empty($data['bounding_box'])) {
        return new JsonResponse(['error' => 'bounding_box must contain x, y, width, and height'], 400);
      }
      foreach (['x', 'y', 'width', 'height'] as $bbField) {
        if (!isset($data['bounding_box'][$bbField]) || !is_numeric($data['bounding_box'][$bbField]) || (float) $data['bounding_box'][$bbField] < 0) {
          return new JsonResponse(['error' => "bounding_box.$bbField must be a non-negative number"], 400);
        }
      }
    }

    // Enum validation.
    if (isset($data['intent']) && !in_array($data['intent'], ['fix', 'change', 'question', 'approve'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid intent value'], 400);
    }
    if (isset($data['severity']) && !in_array($data['severity'], ['blocking', 'important', 'suggestion'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid severity value'], 400);
    }
    if (mb_strlen($data['comment'] ?? '') > 2000) {
      return new JsonResponse(['error' => 'Comment exceeds 2000 characters'], 400);
    }

    // Screenshot pre-validation: check MIME and size before hitting the store,
    // so we can return 400 (bad MIME) or 413 (too large) rather than silently
    // saving an annotation with screenshot = NULL.
    if (!empty($data['screenshot'])) {
      $dataUrl = $data['screenshot'];
      // PNG MUST be base64-encoded (instruckt's modern-screenshot always produces
      // base64 PNG). Accepting bare 'data:image/png' without ';base64' would cause
      // the store to misidentify it as URL-encoded and corrupt the binary data.
      // SVG may be base64 or URL-encoded — both are supported by the store.
      if (!str_starts_with($dataUrl, 'data:image/png;base64,') && !str_starts_with($dataUrl, 'data:image/svg+xml')) {
        return new JsonResponse(['error' => 'Screenshot must be a base64-encoded PNG or an SVG data URL'], 400);
      }
      // Determine extension and validate against allowed_screenshot_extensions config.
      // Do this here (4xx) rather than letting saveScreenshot() return NULL (which
      // would propagate as a 500). This prevents the ambiguity between I/O failure
      // and admin-configured extension policy.
      $screenshotExt = str_starts_with($dataUrl, 'data:image/svg+xml') ? 'svg' : 'png';
      $allowedExts = $this->config('instruckt_drupal.settings')->get('allowed_screenshot_extensions') ?? ['png', 'svg'];
      if (!in_array($screenshotExt, $allowedExts, TRUE)) {
        return new JsonResponse(['error' => sprintf("Screenshot extension '%s' is not allowed by site configuration.", $screenshotExt)], 415);
      }
      // Estimate decoded size: base64 expands ~33%, URL-encoded is similar.
      $maxSize = $this->config('instruckt_drupal.settings')->get('max_screenshot_size') ?? 5242880;
      $parts = explode(',', $dataUrl, 2);
      $rawData = $parts[1] ?? '';
      $isBase64 = str_contains($parts[0] ?? '', ';base64');
      if ($isBase64) {
        // Validate base64 character set without decoding (avoids double memory allocation
        // for large screenshots). If the payload contains non-base64 characters,
        // saveScreenshot() would return NULL → 500. Return 400 deterministically here.
        // Accepts optional padding (=, ==) and strips whitespace Drupal may have added.
        if (!preg_match('/^[a-zA-Z0-9+\/]*={0,2}$/', trim($rawData))) {
          return new JsonResponse(['error' => 'Screenshot contains invalid base64-encoded data'], 400);
        }
        $estimatedSize = (int) (strlen($rawData) * 3 / 4);
      }
      else {
        $estimatedSize = strlen(urldecode($rawData));
      }
      if ($estimatedSize > $maxSize) {
        return new JsonResponse(['error' => 'Screenshot exceeds maximum allowed size'], 413);
      }
    }

    // Server-side source resolution when framework context is provided.
    // Validate framework is an array first — a string value triggers PHP 8 TypeError.
    // Stored fields: framework, component, source_file, source_line, supported.
    // 'message' from the resolver is transient and not stored.
    if (isset($data['framework']) && !is_array($data['framework'])) {
      return new JsonResponse(['error' => 'framework must be an object'], 400);
    }
    // If framework is provided, BOTH 'framework' and 'component' fields are required.
    // A partial object (only one field) would be stored raw, violating the schema.
    if (isset($data['framework']) && is_array($data['framework'])) {
      if (empty($data['framework']['framework']) || empty($data['framework']['component'])) {
        return new JsonResponse(['error' => "framework object requires both 'framework' and 'component' fields"], 400);
      }
    }
    if (!empty($data['framework']['framework']) && !empty($data['framework']['component'])) {
      $fw = $data['framework']['framework'];
      $comp = $data['framework']['component'];

      // Validate framework and component identifiers — same patterns as resolveSource().
      // Without this, the resolver's file_exists() calls could be used to probe filesystem paths.
      if (!is_string($fw) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $fw)) {
        return new JsonResponse(['error' => 'Invalid framework identifier'], 400);
      }
      if (!is_string($comp) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$/', $comp)) {
        return new JsonResponse(['error' => 'Invalid component identifier'], 400);
      }
      if (str_contains($comp, '..')) {
        return new JsonResponse(['error' => 'Invalid component identifier'], 400);
      }

      // Whitelist fields from resolver; include 'supported' so callers can distinguish
      // "unsupported framework (source_file=null, supported=false)" from
      // "supported but template not found (source_file=null, supported=true)".
      // Use the resolver's canonical framework name (normalizes 'blade' → 'twig').
      $enriched = $this->sourceResolver->resolve($fw, $comp);
      $data['framework'] = [
        'framework'   => $enriched['framework'],  // canonical: always 'twig' for supported
        'component'   => $comp,
        'source_file' => $enriched['source_file'],
        'source_line' => $enriched['source_line'],
        'supported'   => $enriched['supported'],
      ];
    }

    try {
      $annotation = $this->store->createAnnotation($data);
      if ($annotation === NULL) {
        $this->logger->error('AnnotationController::create: storage returned NULL for new annotation.');
        return new JsonResponse(['error' => 'Failed to save annotation'], 500);
      }
      return new JsonResponse($annotation, 201, [
        'Cache-Control' => 'no-store',
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('AnnotationController::create failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'An unexpected error occurred'], 500);
    }
  }

  public function update(Request $request, string $id): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Validate ULID format before any file I/O.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
      return new JsonResponse(['error' => 'Invalid annotation ID format'], 400);
    }

    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    if (isset($data['status']) && !in_array($data['status'], ['pending', 'resolved', 'dismissed'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid status value'], 400);
    }
    // PHP 8 TypeError guard: validate string type before mb_strlen().
    if (isset($data['comment']) && !is_string($data['comment'])) {
      return new JsonResponse(['error' => 'comment must be a string'], 400);
    }
    if (isset($data['comment']) && mb_strlen($data['comment']) > 2000) {
      return new JsonResponse(['error' => 'Comment exceeds 2000 characters'], 400);
    }
    // Validate thread structure: must be an array of {author, message, timestamp} entries.
    // Thread is replaced wholesale — the client sends the complete updated state.
    if (isset($data['thread'])) {
      if (!is_array($data['thread'])) {
        return new JsonResponse(['error' => 'thread must be an array'], 400);
      }
      // Limit thread size to prevent unbounded annotations.json growth.
      // At 2000 chars/entry × 100 entries = 200KB max thread payload per annotation.
      if (count($data['thread']) > 100) {
        return new JsonResponse(['error' => 'thread exceeds maximum of 100 entries'], 400);
      }
      foreach ($data['thread'] as $entry) {
        if (!is_array($entry) || !isset($entry['author'], $entry['message'], $entry['timestamp'])) {
          return new JsonResponse(['error' => 'Each thread entry must have author, message, and timestamp'], 400);
        }
        if (!in_array($entry['author'], ['human', 'agent'], TRUE)) {
          return new JsonResponse(['error' => "Thread entry author must be 'human' or 'agent'"], 400);
        }
        if (!is_string($entry['message']) || mb_strlen($entry['message']) > 2000) {
          return new JsonResponse(['error' => 'Thread entry message must be a string ≤ 2000 characters'], 400);
        }
        // Validate ISO-8601 timestamp format using a strict regex, then parse.
        // DateTimeImmutable constructor accepts relative strings like "next tuesday",
        // so we first enforce the structural shape with a regex before trusting the parse.
        // Accepted forms: "2024-03-11T12:34:56Z", "2024-03-11T12:34:56.789Z",
        //                 "2024-03-11T12:34:56+05:30", "2024-03-11T12:34:56.789-07:00".
        if (!is_string($entry['timestamp'])) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $entry['timestamp'])) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
        try {
          new \DateTimeImmutable($entry['timestamp']);
        } catch (\Exception $e) {
          return new JsonResponse(['error' => 'Thread entry timestamp must be an ISO-8601 date-time string'], 400);
        }
      }
    }

    // resolved_by and resolved_at are server-controlled — auto-populated inside
    // InstrucktStore::updateAnnotation() when status changes to resolved/dismissed.
    // Do NOT set them here; they are not in $allowed and would be silently dropped.
    $annotation = $this->store->updateAnnotation($id, $data);
    if ($annotation === FALSE) {
      // FALSE = storage/I/O error — must return 500, not 404.
      return new JsonResponse(['error' => 'Failed to update annotation'], 500);
    }
    if ($annotation === NULL) {
      return new JsonResponse(['error' => 'Annotation not found'], 404);
    }

    // Return full updated annotation, not just {success: true}.
    return new JsonResponse($annotation, 200, ['Cache-Control' => 'no-store']);
  }

  public function resolveSource(Request $request): JsonResponse {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    if (!str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
      return new JsonResponse(['error' => 'Content-Type must be application/json'], 415);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || empty($data['framework']) || empty($data['component'])) {
      return new JsonResponse(['error' => 'Missing framework or component'], 400);
    }

    // Validate framework and component to prevent arbitrary path probing.
    // Allowed: lowercase alphanumeric, hyphens, underscores, forward slashes, and dots.
    // e.g. "twig", "blade", "node--article--teaser", "paragraphs/my-para"
    // Explicitly reject ".." — the character class allows both "." and "/" which
    // combined form directory traversal strings like "../../../../etc/passwd".
    if (!is_string($data['framework']) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $data['framework'])) {
      return new JsonResponse(['error' => 'Invalid framework identifier'], 400);
    }
    if (!is_string($data['component']) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$/', $data['component'])) {
      return new JsonResponse(['error' => 'Invalid component identifier'], 400);
    }
    if (str_contains($data['component'], '..')) {
      return new JsonResponse(['error' => 'Invalid component identifier'], 400);
    }

    return new JsonResponse(
      $this->sourceResolver->resolve($data['framework'], $data['component']),
      200,
      ['Cache-Control' => 'no-store']
    );
  }

  public function serveScreenshot(string $filename): Response {
    if (!$this->config('instruckt_drupal.settings')->get('enabled')) {
      return $this->disabledResponse();
    }
    // Allow ULID-format filenames only: 26 chars from the Crockford base32 alphabet.
    // The original broader regex [a-zA-Z0-9_-]+ is intentionally tightened to prevent
    // path traversal probes and to match the actual ULID generation in InstrucktStore.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.(png|svg)$/i', $filename)) {
      return new JsonResponse(['error' => 'Invalid filename'], 400);
    }

    $realPath = $this->store->getScreenshotRealPath('screenshots/' . $filename);
    if (!$realPath) {
      return new JsonResponse(['error' => 'Screenshot not found'], 404);
    }

    $contentType = str_ends_with($filename, '.svg') ? 'image/svg+xml' : 'image/png';
    return new BinaryFileResponse($realPath, 200, [
      'Content-Type'              => $contentType,
      'Cache-Control'             => 'private, max-age=3600',
      'X-Content-Type-Options'    => 'nosniff',
      // Sandboxes SVG script execution: prevents <script> tags in user-supplied
      // SVG files from running even when served inline.
      'Content-Security-Policy'   => "default-src 'none'",
    ]);
  }
}
```

### 18. MCP Plugin (`src/Plugin/Mcp/InstrucktPlugin.php`)

A single plugin class exposing all three instruckt MCP tools using the `drupal/mcp` plugin system.

**Plugin system facts (confirmed from drupal/mcp source):**
- Uses PHP 8 `#[\Attribute]` syntax — `#[Mcp(...)]` — not docblock annotations
- Base class: `Drupal\mcp\Plugin\McpPluginBase`
- Interface: `Drupal\mcp\Plugin\McpInterface`
- Attribute: `Drupal\mcp\Attribute\Mcp` (uses `name:` not `label:`)
- Plugin location: `src/Plugin/Mcp/` (namespace: `Drupal\{module_name}\Plugin\Mcp\`)
- No config entity required — plugin appears automatically at `/admin/config/mcp/plugins`
- **Return format**: `executeTool()` returns an array of content items: `[['type' => 'text'|'image'|'resource', ...]]`
- **`Tool` object**: `Drupal\mcp\ServerFeatures\Tool` (data class, not plugin class)
- **`ToolAnnotations`**: `Drupal\mcp\ServerFeatures\ToolAnnotations` (with `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` booleans)
- Required methods beyond `getTools()`/`executeTool()`: `checkRequirements()`, `getRequirementsDescription()`, `getResources()`, `getResourceTemplates()`, `readResource()`

```php
<?php

namespace Drupal\instruckt_drupal\Plugin\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\instruckt_drupal\Service\InstrucktStore;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\mcp\ServerFeatures\ToolAnnotations;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP plugin exposing instruckt annotation tools.
 *
 * Provides three MCP tools:
 *   - instruckt_get_all_pending  (read-only)
 *   - instruckt_get_screenshot   (read-only)
 *   - instruckt_resolve          (destructive)
 */
#[Mcp(
  id: 'instruckt_drupal',
  name: new TranslatableMarkup('Instruckt Drupal'),
  description: new TranslatableMarkup('Tools for reading and resolving Instruckt annotations.'),
)]
class InstrucktPlugin extends McpPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    private readonly InstrucktStore $store,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    // McpPluginBase stores currentUser via setter in its create() factory, but
    // InstrucktPlugin overrides create() with a constructor-injection pattern.
    // PluginBase::__construct() only accepts 3 arguments and silently ignores
    // extras — passing $currentUser as the 4th arg to parent::__construct()
    // leaves $this->currentUser null. Explicitly assign it here instead.
    $this->currentUser = $currentUser;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('instruckt_drupal.store'),
      $container->get('config.factory'),
    );
  }

  public function checkRequirements(): bool {
    return TRUE;
  }

  public function getRequirementsDescription(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    return [
      new Tool(
        name: 'instruckt_get_all_pending',
        description: 'Retrieve all pending Instruckt annotations with full metadata.',
        inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
        annotations: new ToolAnnotations(readOnlyHint: TRUE),
      ),
      new Tool(
        name: 'instruckt_get_screenshot',
        description: 'Get the base64-encoded screenshot for an annotation by its ID.',
        inputSchema: [
          'type'       => 'object',
          'properties' => [
            'annotation_id' => [
              'type'        => 'string',
              'description' => 'The ULID of the annotation whose screenshot to retrieve.',
            ],
          ],
          'required'   => ['annotation_id'],
        ],
        annotations: new ToolAnnotations(readOnlyHint: TRUE),
      ),
      new Tool(
        name: 'instruckt_resolve',
        description: 'Mark an annotation as resolved by the agent. Deletes its screenshot.',
        inputSchema: [
          'type'       => 'object',
          'properties' => [
            'id' => [
              'type'        => 'string',
              'description' => 'The ULID of the annotation to resolve.',
            ],
          ],
          'required'   => ['id'],
        ],
        annotations: new ToolAnnotations(
          destructiveHint: TRUE,
          idempotentHint: FALSE,
        ),
      ),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Returns an array of content items, each being an associative array with
   * 'type' => 'text'|'image'|'resource' and type-specific fields.
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    // Tool-level authorization: drupal/mcp handles transport auth but tools must
    // also enforce Drupal permissions independently.
    // Respect the global enabled flag for all HTTP requests.
    if (!$this->configFactory->get('instruckt_drupal.settings')->get('enabled')) {
      return [['type' => 'text', 'text' => 'Instruckt is disabled.']];
    }
    if (!$this->currentUser->hasPermission('access instruckt_drupal toolbar')) {
      return [['type' => 'text', 'text' => 'Access denied: requires "access instruckt_drupal toolbar" permission.']];
    }

    return match ($toolId) {
      'instruckt_get_all_pending' => $this->getAllPending(),
      'instruckt_get_screenshot'  => $this->getScreenshot((array) $arguments),
      'instruckt_resolve'         => $this->resolve((array) $arguments),
      default => [['type' => 'text', 'text' => "Unknown tool ID: $toolId"]],
    };
  }

  public function getResources(): array { return []; }
  public function getResourceTemplates(): array { return []; }
  public function readResource(string $resourceId): array { return []; }

  private function getAllPending(): array {
    $pending = $this->store->getPendingAnnotations();
    $count = count($pending);
    return [['type' => 'text', 'text' => \json_encode(['annotations' => $pending, 'count' => $count])]];
  }

  private function getScreenshot(array $arguments): array {
    $annotationId = $arguments['annotation_id'] ?? '';
    if ($annotationId === '') {
      return [['type' => 'text', 'text' => 'annotation_id is required.']];
    }
    // Validate ULID format before any store lookup (prevents log noise / timing oracle).
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $annotationId)) {
      return [['type' => 'text', 'text' => 'Invalid annotation_id format.']];
    }

    // Find the annotation to get its screenshot path.
    $annotations = $this->store->getAnnotations();
    $annotation = NULL;
    foreach ($annotations as $a) {
      if ($a['id'] === $annotationId) {
        $annotation = $a;
        break;
      }
    }

    if ($annotation === NULL) {
      return [['type' => 'text', 'text' => 'Annotation not found.']];
    }

    $relPath = $annotation['screenshot'] ?? NULL;
    if (!$relPath) {
      return [['type' => 'text', 'text' => 'Annotation has no screenshot.']];
    }

    $realPath = $this->store->getScreenshotRealPath($relPath);
    if (!$realPath) {
      return [['type' => 'text', 'text' => 'Screenshot file not found on disk.']];
    }

    // Enforce max file size before loading into memory (default: 10MB for MCP tools).
    // Note: config max_screenshot_size (5MB) applies at upload; this is a separate
    // guard for MCP retrieval since files may pre-exist from older versions.
    $maxMcpSize = 10 * 1024 * 1024;
    if (filesize($realPath) > $maxMcpSize) {
      return [['type' => 'text', 'text' => 'Screenshot file exceeds 10MB limit for MCP retrieval.']];
    }

    $binary = file_get_contents($realPath);
    if ($binary === FALSE) {
      return [['type' => 'text', 'text' => 'Failed to read screenshot file.']];
    }

    $ext = pathinfo($realPath, PATHINFO_EXTENSION);
    $mimeType = $ext === 'svg' ? 'image/svg+xml' : 'image/png';

    return [[
      'type'      => 'image',
      'data'      => base64_encode($binary),
      'mimeType'  => $mimeType,
    ]];
  }

  private function resolve(array $arguments): array {
    $id = $arguments['id'] ?? '';
    if ($id === '') {
      return [['type' => 'text', 'text' => 'id is required.']];
    }
    // Validate ULID format before any store lookup.
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
      return [['type' => 'text', 'text' => 'Invalid id format.']];
    }

    // resolved_by and resolved_at are server-controlled inside updateAnnotation().
    // Pass 'agent' as the $resolvedBy parameter so the store records the actor correctly.
    $annotation = $this->store->updateAnnotation($id, ['status' => 'resolved'], 'agent');

    if ($annotation === FALSE) {
      return [['type' => 'text', 'text' => 'Storage error resolving annotation.']];
    }
    if ($annotation === NULL) {
      return [['type' => 'text', 'text' => 'Annotation not found.']];
    }

    return [['type' => 'text', 'text' => \json_encode($annotation)]];
  }
}
```

### 19. Services (`instruckt_drupal.services.yml`)

```yaml
services:
  instruckt_drupal.store:
    class: Drupal\instruckt_drupal\Service\InstrucktStore
    arguments: ['@config.factory', '@file_system', '@logger.channel.instruckt_drupal']

  logger.channel.instruckt_drupal:
    parent: logger.channel_base
    arguments: ['instruckt_drupal']

  instruckt_drupal.source_resolver:
    class: Drupal\instruckt_drupal\Service\SourceResolver
    arguments: ['@theme.manager', '@extension.list.theme', '@extension.list.module', '@theme.registry', '%app.root%']

  instruckt_drupal.csrf_subscriber:
    class: Drupal\instruckt_drupal\EventSubscriber\InstrucktCsrfSubscriber
    arguments: ['@current_user', '@csrf_token', '@config.factory']
    tags:
      - { name: event_subscriber }

  instruckt_drupal.json_exception_subscriber:
    class: Drupal\instruckt_drupal\EventSubscriber\InstrucktJsonExceptionSubscriber
    tags:
      - { name: event_subscriber }

  instruckt_drupal.csrf_header_access_check:
    class: Drupal\instruckt_drupal\Access\CsrfHeaderAccessCheck
    arguments: ['@csrf_token']
    tags:
      - { name: access_check, applies_to: _csrf_header }
```

## API Design

**Error envelope**: All `/instruckt/*` endpoints return errors in a consistent JSON envelope. The `InstrucktJsonExceptionSubscriber` additionally rewrites Drupal-generated HTML 403/404 pages to JSON for these paths.

```json
{"error": "Human-readable error message"}
```

| HTTP | Scenario |
|---|---|
| 400 | Invalid JSON, missing required fields, validation failure, bad screenshot MIME |
| 403 | Missing/invalid CSRF token, access denied, or unauthenticated (Drupal uses 403 for all auth failures — no 401) |
| 404 | Annotation or screenshot not found |
| 413 | Screenshot exceeds `max_screenshot_size` (returned by controller, not silently dropped) |
| 415 | Missing or non-JSON `Content-Type` on `POST`/`PATCH`/`resolve-source` endpoints |
| 500 | Storage/I/O failure (file locking, JSON encode error, rename failure) |
| 503 | Module disabled (`instruckt_drupal.settings.enabled = false`). All HTTP endpoints (`list`, `create`, `update`, `resolve-source`, `serveScreenshot`) return `{"error":"Instruckt is disabled"}`. MCP tools return a `text` content item with the same message. |

### GET `/instruckt/annotations`
- **Auth**: `access instruckt_drupal toolbar`
- **Response 200**: `Annotation[]` (all annotations, any status)
- **Ordering**: Insertion order (append order within `annotations.json`). Clients MUST NOT assume sort by timestamp or any other field — order is stable but not sorted. If a specific order is needed, sort client-side.
- **Response headers**: `Cache-Control: no-store`

### POST `/instruckt/annotations`
- **Auth**: `access instruckt_drupal toolbar` + valid `X-XSRF-TOKEN` header
- **Request body** (snake_case, via instruckt JS `toSnake()`):

| Field | Type | Required | Notes |
|---|---|---|---|
| `x` | float | yes | Page-relative X of click |
| `y` | float | yes | Page-relative Y of click |
| `comment` | string | yes | Max 2000 chars |
| `element` | string | yes | CSS selector, max 255 |
| `url` | string | yes | Valid URI, max 2048 |
| `element_path` | string | no | Full DOM path |
| `css_classes` | string | no | |
| `nearby_text` | string | no | Max 500 |
| `selected_text` | string | no | Max 500 |
| `bounding_box` | object | no | `{x, y, width, height}` all ≥ 0 |
| `screenshot` | string | no | data URL (PNG or SVG only) |
| `intent` | string | no | `fix\|change\|question\|approve` |
| `severity` | string | no | `blocking\|important\|suggestion` |
| `framework` | object | no | `{framework, component}` |

- **Response 201**: Full `Annotation` object with server-generated ULID, timestamps, `null` resolved fields
- **Response 400**: `{"error": "..."}` for validation failures
- **Response 403**: Invalid or missing CSRF token

### PATCH `/instruckt/annotations/{id}`
- **Auth**: `access instruckt_drupal toolbar` + valid `X-XSRF-TOKEN`
- **Request body** (all fields optional): `{"status": "...", "comment": "...", "thread": [...]}`
- **Server-controlled**: `resolved_by` and `resolved_at` are ignored if provided — auto-set on resolution, cleared on reopen.
- **Response 200**: Full updated `Annotation` object
- **Response 400/403/404**: `{"error": "..."}`

### POST `/instruckt/resolve-source`
- **Auth**: `access instruckt_drupal toolbar` + valid `X-XSRF-TOKEN`
- **Content-Type**: `application/json` required; returns `415` otherwise (consistent with all other JSON endpoints)
- **Request**: `{"framework": "twig", "component": "node--article--teaser"}`
- **Validation**: `framework` must match `^[a-z0-9][a-z0-9_-]*$`; `component` must match `^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$` and must not contain `..`. Returns `400` for invalid patterns. `blade` is accepted as an alias for `twig` and normalized in the response.
- **Response 200** (supported, template found): `{"framework":"twig","component":"node--article--teaser","source_file":"themes/custom/mytheme/templates/node--article--teaser.html.twig","source_line":null,"supported":true}` with `Cache-Control: no-store`
- **Response 200** (supported, template not found): `{"framework":"twig","component":"missing-template","source_file":null,"source_line":null,"supported":true}` with `Cache-Control: no-store`
- **Response 200** (unsupported framework): `{"framework":"react","component":"...","source_file":null,"source_line":null,"supported":false,"message":"Framework 'react' is not supported..."}` with `Cache-Control: no-store`

### GET `/instruckt/screenshots/{filename}`
- **Auth**: `access instruckt_drupal toolbar`
- **Filename**: validated by regex `/^[0-9A-HJKMNP-TV-Z]{26}\.(png|svg)$/` (ULID format)
- **Response 200**: Binary file, `Content-Type: image/png` or `image/svg+xml`
- **Response 400/404**: `{"error": "..."}`

### MCP Tool Output Schemas

All MCP tools return an array of content items (`[['type' => 'text'|'image'|'resource', ...]]`) as required by the `drupal/mcp` plugin interface.

**`instruckt_get_all_pending`** — returns a `text` item containing JSON:
```json
[{"type": "text", "text": "{\"annotations\": [...], \"count\": 3}"}]
```

**`instruckt_get_screenshot`** — returns an `image` item on success:
```json
[{"type": "image", "data": "<base64-encoded binary>", "mimeType": "image/png"}]
```
On failure: `[{"type": "text", "text": "<error message>"}]`.

**`instruckt_resolve`** — returns a `text` item containing the full updated Annotation JSON:
```json
[{"type": "text", "text": "{/* Full Annotation object, status: resolved, resolved_by: agent */}"}]
```
On failure: `[{"type": "text", "text": "<error message>"}]`.

On module disabled or permission denied: `[{"type": "text", "text": "Instruckt is disabled." | "Access denied..."}]`.

## Security Considerations

1. **Storage**: `private://` stream wrapper keeps files outside the web root.
2. **CSRF**: `XSRF-TOKEN` cookie emitted by `InstrucktCsrfSubscriber`; all state-changing routes validated by `CsrfHeaderAccessCheck` against Drupal's `CsrfTokenGenerator` scoped to `InstrucktStore::CSRF_TOKEN_ID`. The cookie is `HttpOnly: false` (JS must read it) and `Secure: auto-detected` from `$request->isSecure()` (true on HTTPS, false on HTTP — no manual config required).
3. **Permissions**: All routes require `access instruckt_drupal toolbar` (marked `restrict access: true`); grant only to developer/admin roles.
4. **Filename validation**: Screenshot filenames validated against ULID pattern `^[0-9A-HJKMNP-TV-Z]{26}\.(png|svg)$` before any filesystem access.
4a. **Source resolver input validation**: `framework` must match `^[a-z0-9][a-z0-9_-]*$`; `component` must match `^[a-zA-Z0-9][a-zA-Z0-9_\-\.\/]*$` **and must not contain `..`**. The regex alone allows both `.` and `/`, which when combined form traversal strings like `../../../../etc/passwd`. The explicit `str_contains($component, '..')` check closes this gap.
5. **Input validation**: Required fields, enum values, and string length limits enforced on every write.
6. **File locking**: `LOCK_EX` on all annotation file writes.
7. **Screenshot cleanup**: Screenshots deleted when annotations are resolved or dismissed (both via HTTP API and MCP tool). Concurrent resolution of the same annotation by two requests may cause the second `@unlink()` to fail silently — this is acceptable behavior at single-user/small-team scale.
8. **XSRF-TOKEN cookie**: `HttpOnly: false` (JS must read it), `Secure: auto-detected` from `$request->isSecure()` — automatically `true` on HTTPS, `false` on HTTP. `SameSite: Lax`. `Path: base_path()` — scoped to this Drupal installation's base path to prevent cookie leakage to sibling Drupal sites on the same domain in subdirectory installs. No manual configuration required.
9. **Content-Security-Policy**: The instruckt IIFE is served from `web/libraries/` (same origin). If the site enforces a strict `script-src` CSP, ensure `'self'` is allowed for scripts, or integrate a nonce/hash. The module does not modify CSP headers.
10. **Authentication**: All HTTP routes require an active Drupal session with the `access instruckt_drupal toolbar` permission. Anonymous requests receive 403. MCP tools via `drupal/mcp` (`/mcp/post`) enforce both the enabled flag and the Drupal permission check on every call. Token-based auth is configurable at `/admin/config/mcp` via `drupal/key`.
11. **XSS / SQL injection**: Not applicable. The module has no SQL queries (flat JSON storage via `file_put_contents`/`file_get_contents`). Annotation text is stored as raw strings and returned via `JsonResponse` (which enforces `Content-Type: application/json`), preventing the browser from parsing it as HTML. Screenshots are served as binary `image/png` or `image/svg+xml` with `X-Content-Type-Options: nosniff`.
12. **SVG security**: User-supplied SVG files could embed `<script>` tags. Mitigations applied: (a) `Content-Security-Policy: default-src 'none'` on all screenshot responses — this sandboxes SVG and prevents script execution even when served inline; (b) `X-Content-Type-Options: nosniff`; (c) screenshot endpoint requires `access instruckt_drupal toolbar` — only trusted developers can access. The combination of CSP + permission-gating is adequate for this dev-tool threat model.
13. **JSON error responses for auth failures**: `InstrucktJsonExceptionSubscriber` intercepts HTTP exceptions (403, 404, etc.) on `/instruckt/*` paths at `KernelEvents::EXCEPTION` and returns `{"error": "..."}` JSON before Drupal renders an HTML error page. All `/instruckt/*` routes return JSON errors — no special `Accept` header is needed from clients.

## Error Handling Strategy

| Scenario | HTTP Status | Response body |
|---|---|---|
| Invalid JSON body | 400 | `{"error": "Invalid JSON"}` |
| Missing required field | 400 | `{"error": "Missing required field: x"}` |
| Invalid URL format | 400 | `{"error": "Invalid URL format"}` |
| Negative coordinate | 400 | `{"error": "x must be a non-negative number"}` |
| bounding_box empty object `{}` | 400 | `{"error": "bounding_box must contain x, y, width, and height"}` |
| Invalid/incomplete bounding_box field | 400 | `{"error": "bounding_box.width must be a non-negative number"}` |
| Invalid enum value | 400 | `{"error": "Invalid intent value"}` |
| Comment too long | 400 | `{"error": "Comment exceeds 2000 characters"}` |
| Invalid ULID format (PATCH {id}) | 400 | `{"error": "Invalid annotation ID format"}` |
| Annotation not found | 404 | `{"error": "Annotation not found"}` |
| Invalid screenshot filename | 400 | `{"error": "Invalid filename"}` |
| Screenshot not found | 404 | `{"error": "Screenshot not found"}` |
| Missing X-XSRF-TOKEN header | 403 | `{"error": "Access denied"}` (via JSON exception subscriber) |
| Invalid X-XSRF-TOKEN value | 403 | `{"error": "Access denied"}` (via JSON exception subscriber) |
| Unauthenticated / no permission | 403 | `{"error": "Access denied"}` (via JSON exception subscriber) |
| instruckt JS missing or unreadable | — | Status Report error via `hook_requirements()` |
| Private filesystem not writable | — | Status Report error via `hook_requirements()` |
| MCP screenshot > 10MB | — | MCP `text` content item with error message |

**Logging policy**: Storage I/O failures and unexpected exceptions are logged to `instruckt_drupal` channel (Drupal watchdog/dblog). 4xx validation errors are intentionally NOT logged to avoid noise.

## Infrastructure Requirements

- **`$settings['file_private_path']`** must be set in `settings.php` **before** running `drush en instruckt_drupal`. The module blocks installation in `hook_requirements($phase === 'install')` by checking `stream_wrapper_manager->isValidScheme('private')` — without this guard, `hook_install()` would crash with `InvalidStreamWrapperException`.
- **`instruckt_drupal.settings.storage_path`** must start with `private://`. Enforced at two points: (1) `hook_requirements($phase === 'install')` blocks installation with a `REQUIREMENT_ERROR` if the configured path uses a non-private scheme; (2) `hook_requirements($phase === 'runtime')` shows an error on the Status Report page if the path is changed to a non-private scheme post-installation. Both checks together close the window where a post-install config import could silently switch to `public://` storage.
- **PHP `post_max_size`**: Must exceed `max_screenshot_size` plus base64 overhead (~33%). Recommended minimum: `10M` (for the default 5MB screenshot limit). Undersized `post_max_size` causes PHP to silently drop the request body before the controller sees it, resulting in confusing empty-body 400 errors.
- **PHP `memory_limit`**: Should be at least 2× the `max_screenshot_size` to accommodate base64 decoding into memory. Recommended: `128M` or higher.
- **Filesystem `flock()` support**: Required for read/write locking in `InstrucktStore`. NFS mounts without `flock` support are not recommended.

## Performance Requirements / SLAs

- **Target scale**: Development tool for 1–20 concurrent users on staging environments.
- **Annotation file size**: < 1,000 annotations expected before project completion; full file read per request is acceptable.
- **Screenshot size**: Individual files capped at 5MB (`max_screenshot_size` config). MCP retrieval enforces a separate 10MB guard.
- **File locking**: `LOCK_EX` may cause brief blocking under simultaneous writes; acceptable at this scale.
- **Response time targets** (measured on a dev server, p50, single concurrent user):
  - `GET /instruckt/annotations` (< 100 annotations): < 100ms
  - `POST /instruckt/annotations` (no screenshot): < 200ms
  - `POST /instruckt/annotations` (with 5MB PNG screenshot): < 1s
  - `GET /instruckt/screenshots/{file}` (< 5MB): < 500ms (file I/O bound)
  - MCP `instruckt_get_all_pending` (< 100 annotations): < 200ms
  - MCP `instruckt_get_screenshot` (< 5MB): < 500ms
- These are aspirational targets for a dev-tool; no formal SLA. Measurements assume local filesystem (`private://` on the same host), not network storage.

## Observability

- `InstrucktStore` injects `logger.channel.instruckt_drupal` (defined in `services.yml`) and logs **error-level** events for: file open failures, `flock()` failures, and screenshot save failures.
- `AnnotationController` logs unexpected exceptions (500-level) via `\Drupal::logger('instruckt_drupal')`. 4xx validation errors are **not logged** to avoid noise.
- `InstrucktJsonExceptionSubscriber` does not log — it only transforms HTTP exceptions to JSON.
- All logged errors are visible at `/admin/reports/dblog` (Drupal watchdog).
- No custom metrics or alerting required for a dev tool.

**MCP user context**: When `drupal/mcp` receives requests via HTTP (`/mcp/post`), it runs under the authenticated Drupal session or configured API token. The `executeTool()` permission check (`$this->currentUser->hasPermission(...)`) enforces tool-level authorization on every request.

## Testing Strategy

**PHPUnit setup:** Add `drupal/core-dev` to `require-dev` so PHPUnit is available:
```bash
composer require --dev drupal/core-dev:^10
```
Run tests from the Drupal root:
```bash
vendor/bin/phpunit web/modules/custom/instruckt_drupal/tests/ \
  --bootstrap web/core/tests/bootstrap.php
```

**Kernel test isolation:** Kernel tests that mock all dependencies should use `$modules = []` (empty array). Enabling `instruckt_drupal` or `mcp` in kernel tests pulls in transitive dependencies — `drupal/mcp` requires the `key` contrib module (`mcp.settings` depends on `key.repository`), which causes `ServiceNotFoundException` unless `key` is also installed. Since all services are mocked in kernel tests, no modules need to be loaded.

- **Unit tests** (`tests/src/Unit/`):
  - `InstrucktStoreTest`: annotation CRUD, ULID format validation (26 chars, Crockford Base32), `LOCK_EX` flag, PNG/SVG screenshot save/delete, `realpath()` for missing files.
  - `SourceResolverTest`: Twig resolution from theme registry, base theme fallback, module template fallback, unsupported framework response.
- **Functional tests** (`tests/src/Functional/`):
  - `AnnotationApiTest`: all CRUD endpoints with valid/invalid payloads, CSRF header validation, full schema conformance on create/update responses. Uses `prepareSettings()` override to set `file_private_path`.
  - `ScreenshotTest`: PNG and SVG upload and retrieval, filename validation.
  - `PermissionTest`: all routes return 403 without `access instruckt_drupal toolbar`.
  - `RequirementsTest`: `hook_requirements()` reports error when IIFE is absent, OK when present.
  - `McpPluginDiscoveryTest`: after `drush en instruckt_drupal`, verify the `drupal/mcp` plugin manager lists `instruckt_drupal` and all three tool names appear in `getTools()` output. This catches discovery failures from incorrect namespace/directory.
- **Kernel tests** (`tests/src/Kernel/`) — all use `$modules = []` with mocked dependencies:
  - `InstrucktCsrfSubscriberTest`: cookie set for permitted users, absent for unpermitted users.
  - `InstrucktJsonExceptionSubscriberTest`: 403/404 on `/instruckt/*` paths return `{"error": "..."}` JSON; non-`/instruckt` paths are not intercepted.
  - `InstrucktPluginTest`: `getTools()` returns correct `Tool` objects with `ToolAnnotations`; `executeTool()` dispatches correctly and returns content-item arrays.

## Deployment

1. Configure root `composer.json` with `oomphinc/composer-installers-extender` and Asset Packagist (one-time project setup — see Composer Definition section above).
2. `composer require drupal/instruckt_drupal` — installs the module and its transitive dependency `npm-asset/instruckt`, placing `instruckt.iife.js` at `web/libraries/instruckt/dist/instruckt.iife.js` automatically.
3. Ensure `$settings['file_private_path']` is set in `settings.php`.
4. `drush en mcp instruckt_drupal && drush cr` — enables modules, rebuilds container (required for new services/routes), creates `private://_instruckt/` directories.
5. Grant `access instruckt_drupal toolbar` to the developer role.
6. Verify toolbar appears on non-admin pages; verify Status Report shows no errors.
7. Verify MCP plugin is discovered: visit `/admin/config/mcp/plugins` — "Instruckt Drupal" should appear. Enable it and save.
8. Connect AI agent to `/mcp/post` endpoint (HTTP POST, JSON-RPC 2.0). Configure API token via `/admin/config/mcp` if authentication is enabled.

**Rollback**: `drush pmu instruckt_drupal`. The `private://_instruckt/` directory and `web/libraries/instruckt/` are not removed automatically and must be cleaned up manually if desired.

## Open Questions / Future Considerations

1. **Annotation deletion**: Neither the Laravel version nor this spec includes a DELETE endpoint. Resolution/dismissal is the only lifecycle close. A DELETE endpoint could be added in a future version if needed.
2. **Multi-site**: `private://` storage is per-site; no special handling required.
3. **instruckt JS updates**: When a new `instruckt` npm version is released, update the `"npm-asset/instruckt"` version constraint in `composer.json` and run `composer update npm-asset/instruckt`. Verify the IIFE is still self-contained (no new external dependencies).
4. **Admin UI**: No settings form is specified. The `instruckt_drupal.settings.yml` config could be exposed via a settings form at `/admin/config/development/instruckt` in a future version.
5. **Migration to entity storage**: If flat JSON proves insufficient (e.g., for multi-site or query flexibility), the migration path is: export `annotations.json` → write a `hook_post_update_*` function that reads the JSON and creates Drupal config or content entities. No migration module is required for the initial version.
