# wp-mcp-bridges

![License](https://img.shields.io/badge/license-GPL--2.0--or--later-3da638)

WordPress 6.9 shipped the [Abilities API](https://developer.wordpress.org/apis/abilities-api/) into core, and the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin turns any site's registered abilities into MCP tools an AI agent can call. But most WordPress plugins and themes don't register any abilities of their own — the API exists, the adapter exists, and there's nothing to expose.

**wp-mcp-bridges** fills that gap. Each directory in this repository is a small, focused WordPress plugin that registers abilities for one third-party plugin or theme, so its functionality becomes available to AI agents through the same standard interface WordPress itself uses.

## How it fits together

```
AI agent (e.g. Claude)
        │  MCP
        ▼
WordPress MCP Adapter  (default MCP server, discovers public abilities)
        │
        ▼
WordPress core Abilities API  (wp_register_ability, REST at /wp-json/wp-abilities/v1/)
        │
        ▼
A bridge plugin from this repo  (registers abilities for one target plugin/theme)
        │
        ▼
The target plugin/theme's own functions, options and data
```

Each bridge:

- registers its abilities on the standard `wp_abilities_api_init` / `wp_abilities_api_categories_init` hooks,
- flags every ability `meta.mcp.public => true` and `show_in_rest => true` so the MCP Adapter's default server and the core REST API both pick it up automatically,
- reads every write back and returns `{old, new}`,
- requires `confirm: true` on destructive or bulk operations, and
- never reads or returns secrets, license/activation data, or password hashes.

## Bridges

| Plugin | Target | Abilities | Requires |
| --- | --- | --- | --- |
| [`wp-core-mcp-ability`](wp-core-mcp-ability/) | WordPress core itself | 59 | WordPress only |
| [`wc-mcp-ability`](wc-mcp-ability/) | WooCommerce | 30 | WooCommerce |
| [`generatepress-mcp-ability`](generatepress-mcp-ability/) | GeneratePress / GP Premium / GenerateBlocks (Pro) | 39 | Any subset of the above |
| [`rank-math-mcp-ability`](rank-math-mcp-ability/) | Rank Math SEO | 24 | Rank Math SEO |
| [`contact-form-7-mcp-ability`](contact-form-7-mcp-ability/) | Contact Form 7 | 19 | Contact Form 7 |

Each bridge's own README has the full ability table, requirements, installation steps and a usage example.

## Installation

Every bridge is a self-contained plugin — install only the ones you need:

1. Open the bridge's own README (linked above) and download its zip from the linked release.
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, and install.
3. Activate it, then make sure the [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin is active too, so the abilities are exposed as MCP tools.

Each bridge checks for its own updates independently against a JSON file hosted in this repository — no separate update source or account needed. See the individual bridge READMEs for details.

## Requirements

- WordPress 7.0+ (Abilities API ships from WordPress 6.9).
- PHP 8.0+.
- The target plugin/theme each bridge is built for.
- The WordPress MCP Adapter plugin, to expose abilities as MCP tools. Abilities also work over the core Abilities REST API without it.

## Contributing

This repository is a **mirror** — it's generated from a private source repository, not developed here directly. Pull requests are welcome and will be read, but changes land by being applied upstream and then re-published here, not by merging directly into this repo. If you'd like to report a bug or request a bridge for another plugin/theme, please open an issue.

## License

GPLv2 or later — see [LICENSE](LICENSE), matching WordPress itself.
