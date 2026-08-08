# Contact Form 7 MCP Ability

![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-3da638)

A WordPress plugin that exposes full Contact Form 7 management to AI agents as **MCP tools**, built on the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/). It registers 19 abilities — form CRUD, form-tag inspection, mail templates, per-status messages, additional settings, configuration validation, site status and a real test submission — and marks them public so the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) surfaces them to AI clients such as Claude.

The abilities also register on the core Abilities REST API (`/wp-json/wp-abilities/v1/`), so you can call them over plain HTTP with an application password.

Part of [wp-mcp-bridges](../README.md) — see that page for the project overview and the full list of bridges.

## Requirements

- **WordPress 7.0+** (the Abilities API ships from WordPress 6.9).
- **PHP 8.0+**.
- **Contact Form 7** active.
- To expose the abilities over MCP, the **WordPress MCP Adapter** plugin must be active (see [Installing the WordPress MCP Adapter](#installing-the-wordpress-mcp-adapter) below — it is not on the wordpress.org plugin directory). Abilities still register on the core Abilities API (and its REST endpoints) without it.

## Installation

1. Download `contact-form-7-mcp-ability.zip` from the [latest release](https://github.com/bucagdas/wp-mcp-bridges/releases?q=contact-form-7-mcp-ability).
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and install.
3. Click **Activate**.

After activating, confirm the abilities registered by visiting (authenticated):

```
https://example.com/wp-json/wp-abilities/v1/abilities?category=contact-form-7-mcp
```

### Installing the WordPress MCP Adapter

The adapter is **not published on the wordpress.org plugin directory** — install it from its official source, [github.com/WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter):

- **WP-CLI:**
  ```bash
  wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
  ```
- **Composer** (for plugin developers integrating it into their own build):
  ```bash
  composer require wordpress/mcp-adapter
  ```
- **Manual:** download the [latest release ZIP](https://github.com/WordPress/mcp-adapter/releases/latest) and upload it via **Plugins → Add New → Upload Plugin** in wp-admin.

See the project's own [installation instructions](https://github.com/WordPress/mcp-adapter#installation) for more detail. The wordpress.org plugin search does list unrelated third-party plugins with similar names (e.g. "Royal MCP", "Easy MCP AI", "Enable Abilities for MCP") — none of those are this official adapter.

### Updates

This plugin checks for updates against a JSON file hosted in this repository, roughly every 12 hours, the same way any self-hosted WordPress plugin does — no account or token needed. Because that file is served through GitHub's raw-content CDN, a freshly published release can take a few minutes to become visible as an update in wp-admin.

## Usage

### Over MCP

```json
{
  "ability_name": "contact-form-7-mcp/create-form",
  "parameters": {
    "title": "Support Request",
    "locale": "en_US"
  }
}
```

### Over the REST API

```bash
# List forms (GET)
curl -u 'USER:APP_PASSWORD' \
  "https://example.com/wp-json/wp-abilities/v1/abilities/contact-form-7-mcp/list-forms/run"

# Run a real submission through the validation/spam pipeline without sending mail
curl -u 'USER:APP_PASSWORD' -X POST -H 'Content-Type: application/json' \
  -d '{"input":{"id":12,"fields":{"your-email":"test@example.com"}}}' \
  "https://example.com/wp-json/wp-abilities/v1/abilities/contact-form-7-mcp/submit-test/run"
```

## Abilities

| Ability | Type | Description |
| --- | --- | --- |
| `list-forms` | read-only | List forms. |
| `get-form` | read-only | Full form detail. |
| `create-form` | write | Create a form. |
| `update-form` | write | Update a form's title/locale. |
| `delete-form` | destructive | Permanently delete a form (Contact Form 7 has no trash). Requires `confirm: true`. |
| `duplicate-form` | write | Duplicate a form. |
| `get-form-tags` | read-only | Scan a form's input fields. |
| `update-form-content` | write | Replace a form's HTML/tags. |
| `validate-form` | read-only | Run Contact Form 7's own configuration validator. |
| `get-mail-settings` / `update-mail-settings` | read-only / write | Admin notification and auto-reply mail templates. |
| `get-messages` / `update-message` | read-only / write | Per-status response text. |
| `get-additional-settings` / `update-additional-settings` | read-only / write | Raw settings block; sensitive-looking lines filtered. |
| `get-status` | read-only | Version, form count, and presence of common integrations (Flamingo, Akismet, reCAPTCHA, Stripe, etc). |
| `search-forms` | read-only | Find forms by form-tag or content substring. |
| `bulk-update-message` | destructive | Set one message key across all forms. Supports `dry_run` and requires `confirm: true`. |
| `submit-test` | write | Run a form through the real submission pipeline (validation, spam checks). Mail sending is skipped unless `confirm: true`. |

All writes read the value back after writing and return `{old, new}`. Destructive and bulk verbs require `confirm: true`; bulk verbs also support `dry_run: true`.

## Tested versions

- WordPress: 7.0
- Contact Form 7: 6.1
- WordPress MCP Adapter: 0.5.0

## License

GPLv2 or later — see the [repository LICENSE](../LICENSE).
