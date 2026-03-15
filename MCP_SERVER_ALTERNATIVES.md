# Drupal MCP Server: Viability Assessment & Alternatives

*Research date: March 2026*

---

## Bottom Line Up Front

**Do not use `drupal/mcp_server` for real projects right now.** Its own project page declares it "stalled looking for funding," it has 5 open bugs blocking basic functionality (two of which we hit ourselves during install), and only 1 site reports using it.

**The recommended path:**

1. **`drupal/mcp` (Omedia)** — stable, security-covered, ~360 sites, active maintenance. The right default for general MCP integration.
2. **`drupal/mcp_tools` (CodeWheel)** — if the goal is AI-driven site building, its 222 purpose-built tools far exceed what any other module offers.

A significant piece of context: **`drupal/mcp` (Omedia) and `drupal/mcp_server` are actively merging.** The `drupal/mcp` namespace will eventually host all Drupal MCP projects. New work is being directed toward the merged `mcp_server` codebase, but the current `mcp` module remains stable and usable during the transition.

---

## `drupal/mcp_server` (e0ipso / Lullabot): Current State

### Status: Not Viable

| Field | Value |
|-------|-------|
| Latest release | `1.x-dev` only — no stable release |
| Sites reporting usage | 1 (down from 2 in Jan–Feb 2026) |
| Security advisory coverage | Notional only (requires a stable release; none exists) |
| Last updated | March 9, 2026 |
| Maintainers | e0ipso, gagosha, jibla |

The project page carries an explicit warning: **"This module is stalled looking for funding 💳 Several projects are using home-grown alternatives as a result."**

### Open Bugs

All five open bugs represent blocking or near-blocking issues:

| Issue | Title | Status |
|-------|-------|--------|
| #3568799 | `Mcp\Server\Builder::addLoaders()` expects an array of loaders | Active |
| #3562536 | `PromptConfigLoader` must be iterable | Active |
| #3560993 | `drupal/simple_oauth_21` dependency cannot be resolved | Active |
| #3560997 | `Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents` not found | Active |
| #3569042 | Missing declared dependency for `jsonapi` | Needs Review |

The two OAuth/dependency bugs (#3560993, #3560997) are exactly what we encountered during install — they are known, unfixed, and upstream. The `addLoaders()` and `PromptConfigLoader` bugs indicate the integration with the official PHP MCP SDK is broken at a fundamental level. Even if install succeeds, the module cannot run tools or prompts correctly.

### Other Signals

- A support issue titled **"Offering to maintain MCP Server"** was opened a month ago — suggesting the original author is disengaged.
- Several open tasks involve migrating tools from the Omedia `mcp` module into `mcp_server`, which has not happened.
- Architecture is sound on paper (official PHP MCP SDK, OAuth 2.1, STDIO + HTTP transports, config-driven tools via admin UI), but none of it works reliably in practice today.

---

## Alternative 1: `drupal/mcp` (Omedia) — Recommended Default

| Field | Value |
|-------|-------|
| Latest stable | 1.2.3 (released November 14, 2025) |
| Sites reporting usage | ~360 (328 on 1.2.x, 31 on 1.1.x) |
| Security advisory coverage | **Yes** — stable releases are covered |
| Maintainers | gagosha (creator), jibla, marcus_johansson, joshmiller |
| Documentation | [drupalmcp.io](https://drupalmcp.io) |

### What It Delivers

- Full MCP protocol implementation for Drupal 10 and 11
- Plugin-based architecture for tools and resources
- STDIO transport (via Docker, compiled binary, or JSR package companion server)
- HTTP transport (basic POST endpoint)
- Token authentication integrated with Drupal's Key module
- Configuration UI at `/admin/config/mcp`
- Two optional submodules: `mcp_extra` (AI module function call actions) and `mcp_dev_tools` (Drush command access)
- **MCP Studio** — a low-code tool builder added in v1.2
- OAuth authentication (added in v1.2)

### Current Limitations

- **Streaming HTTP / SSE**: In progress, not yet shipped — long-running operations may time out
- **Prompts system**: Not started — no reusable prompt templates
- **ECA integration**: Not started — cannot yet trigger Event-Condition-Action workflows
- **Gemini 2.5 Pro compatibility**: Active bug (#3526493)
- **Write operations**: Not fully supported (active feature request #3551588)
- **Claude Desktop disconnections**: Active support issue (#3531590) — the companion STDIO server occasionally drops the connection

### Install

```bash
composer require drupal/mcp
ddev drush en mcp -y
```

No dependency workarounds required. Stable, straightforward install.

---

## Alternative 2: `drupal/mcp_tools` (CodeWheel) — Most Feature-Rich

| Field | Value |
|-------|-------|
| Latest release | 1.0.0-beta7 (March 10, 2026) |
| Sites reporting usage | 31 |
| Security advisory coverage | **No** — explicit warning: "Use at your own risk" |
| Maintainers | mowens (creator), guillaumeg |
| GitHub | github.com/code-wheel/mcp-tools |

### What It Delivers

222 tools across 34 optional submodules in five categories:

- **Site Building**: Content types, fields, taxonomies, roles, permissions, menus
- **Content Management**: Node CRUD, media, bulk operations, revisions
- **Views & Display**: Views, blocks, Layout Builder, image styles
- **Administration**: Cache, cron, config management, security audits
- **Contrib Integration**: Paragraphs, Webform, Scheduler, Search API, Redirect, Pathauto

Supports both STDIO (for local dev with Claude Code, Codex, etc.) and HTTP transport with scoped API keys. Three security presets — Development, Staging, Production — provide graduated access control. The Production preset is read-only by default.

### Current Limitations

- **Beta status**: Not security-advisory-covered — evaluate carefully before production use
- **PHP 8.3+ required**
- **Requires `drupal/tool` module** as a dependency
- **Tool overload risk**: 222 tools can overwhelm AI context windows — enable only the submodules you need
- No built-in prompts or resources (tools only)

### Open Bugs: Zero

Both previously reported bugs (#3575317, #3572001) are fixed. The issue queue is clean.

### Install

```bash
composer require drupal/mcp_tools
ddev drush en mcp_tools -y
# Enable only the submodules needed, e.g.:
ddev drush en mcp_tools_content mcp_tools_taxonomy -y
```

---

## Alternative 3: `drupal/jsonrpc_mcp` — Lightweight Developer Option

Not on drupal.org (Packagist only, v1.5.4, ~49 installs). Bridges existing Drupal JSON-RPC plugins to MCP via a PHP 8 `#[McpTool]` attribute. Requires writing code; no admin UI or pre-built tools. Good for developers who already have JSON-RPC plugins and want zero-overhead MCP exposure. No security advisory coverage.

---

## Comparison Matrix

| | `mcp_server` (e0ipso) | `mcp` (Omedia) | `mcp_tools` (CodeWheel) | `jsonrpc_mcp` |
|---|---|---|---|---|
| **Status** | Stalled / no stable release | Active, v1.2.3 | Active beta, v1.0.0-beta7 | Active, v1.5.4 |
| **Sites** | 1 | ~360 | 31 | N/A |
| **Security coverage** | No (no stable release) | **Yes** | No | No |
| **Pre-built tools** | None (config-driven, broken) | Basic (content + dev) | 222 across 34 submodules | None (code-driven) |
| **Transport** | STDIO + HTTP | STDIO + HTTP | STDIO + HTTP | HTTP only |
| **Auth** | OAuth 2.1 (install broken) | Token + OAuth | Scoped API keys | OAuth 2.1 |
| **Open bugs** | 5 blocking | 3 minor | 0 | Unknown |
| **Install complexity** | Very high (see notes) | Low | Low | Medium |

---

## Our Install Experience vs. Known Issues

The two OAuth dependency bugs we hit during install (#3560993 and #3560997) are confirmed upstream bugs that the maintainer has not fixed. What we worked around:

| What broke | Why | Our fix |
|------------|-----|---------|
| `drupal/simple_oauth_21 *` unresolvable | drupal.org registry injects an extra package name not in the GitHub repo | Inline `package` repos for both `e0ipso/simple_oauth_21` and `drupal/simple_oauth_21` |
| GitHub auth fails inside DDev | Container has no host Composer credentials | `ddev composer config --global github-oauth.github.com "$(gh auth token)"` |
| `ResourceMetadataEvents` class not found | `mcp_server` uses `simple_oauth_server_metadata` but doesn't declare it as a dep | Manually enable `simple_oauth_server_metadata` before `mcp_server` |

These are not one-time setup quirks — they are unfixed bugs. Anyone else attempting to install `mcp_server` today will hit them.

---

## Recommendation

**For this project (`instruckt-drupal`):**

Replace `drupal/mcp_server` with `drupal/mcp` (Omedia) as the MCP protocol foundation. If AI-driven site building is a goal, add `drupal/mcp_tools` selectively.

Steps to migrate:

```bash
# Remove mcp_server
ddev drush pmu mcp_server simple_oauth_server_metadata simple_oauth_21 tool -y
ddev composer remove drupal/mcp_server e0ipso/simple_oauth_21

# Clean up the inline package repos from composer.json manually

# Install the recommended alternative
ddev composer require drupal/mcp
ddev drush en mcp -y

# Optionally add mcp_tools for site-building tasks
ddev composer require drupal/mcp_tools
ddev drush en mcp_tools_content mcp_tools_taxonomy -y   # enable only what you need
```

The Omedia `mcp` module will absorb `mcp_server`'s functionality as the merger progresses — so adopting it now puts this project on the right long-term path.
