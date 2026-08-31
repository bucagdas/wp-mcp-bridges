# Connecting your agent

Installing a bridge registers abilities. Installing the [WordPress MCP
Adapter](https://github.com/WordPress/mcp-adapter) turns them into MCP tools.
This page covers the step in between: pointing an actual AI agent at your site.

Everything below was checked against WordPress 7.1 with MCP Adapter 0.5.0,
talking to the adapter's default server.

## Before you start

- WordPress 7.0+ (the Abilities API ships from 6.9) with at least one bridge
  installed and activated.
- The WordPress MCP Adapter installed and activated.
- **HTTPS.** WordPress only offers application passwords when the site is served
  over TLS or the environment type is `local`
  (`is_ssl() || 'local' === wp_get_environment_type()`). On a plain-HTTP
  production site the Application Passwords panel simply will not appear.

## 1. Create an application password

Your agent authenticates as a WordPress user, using the same application
passwords the REST API uses. Do not use your login password — it will not work.

1. In wp-admin, go to **Users → Profile** (or edit the user you want the agent
   to act as).
2. Scroll to **Application Passwords**, enter a name that identifies the agent
   (`claude-code`, `cursor`, …) and click **Add New Application Password**.
3. Copy the 24-character password. WordPress shows it once.

The spaces WordPress puts in the password are decorative — it strips every
non-alphanumeric character before checking, so `abcd efgh …` and `abcdefgh…`
both authenticate.

To revoke an agent's access later, delete that application password. Nothing
else about the user changes.

## 2. The endpoint

The adapter's default server lives at one route:

```
https://example.com/wp-json/mcp/mcp-adapter-default-server
```

It speaks MCP over HTTP: a single `POST` endpoint, JSON-RPC in the body, an
`Mcp-Session-Id` header returned by `initialize` and required on every request
after it. Any compliant MCP client handles that for you.

Nothing needs configuring to bring that route up. The adapter creates its
default server on its own and has no settings screen; the abilities appear on it
because each bridge flags its own as public.

One exception: on a site still using plain permalinks (`?p=123`), WordPress does
not serve `/wp-json/` at all. Use the query form of the same route, which
behaves identically:

```
https://example.com/?rest_route=/mcp/mcp-adapter-default-server
```

## 3. Point your client at it

### Clients that speak remote HTTP MCP

Any client that lets you add an HTTP MCP server with custom headers — Claude
Code among them — can talk to WordPress directly. Authentication is HTTP Basic:

```bash
claude mcp add --transport http mysite \
  https://example.com/wp-json/mcp/mcp-adapter-default-server \
  --header "Authorization: Basic $(printf '%s' 'USERNAME:APP PASSWORD' | base64)"
```

The base64 value has to be a single line. macOS `base64` does not wrap; GNU
`base64` does, so use `base64 -w0` there.

The same thing as configuration, for clients that take a JSON block:

```json
{
  "mcpServers": {
    "mysite": {
      "type": "http",
      "url": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_USERNAME_COLON_APP_PASSWORD"
      }
    }
  }
}
```

### Clients that only launch local processes

Not every client can add a remote HTTP server, and some that can make a static
`Authorization` header awkward to set. Automattic's proxy covers both cases: the
client launches it locally, it takes the credentials as environment variables,
and it speaks HTTP to your site on the client's behalf:

```json
{
  "mcpServers": {
    "mysite": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your application password"
      }
    }
  }
}
```

This path needs Node.js on the machine running the client. It is also the
easier one to debug: set `LOG_FILE` in the same `env` block and the proxy writes
every exchange to that file.

## 4. Check that it worked

Your agent should now see three tools:

| Tool | What it does |
| --- | --- |
| `mcp-adapter-discover-abilities` | Lists the abilities the site exposes |
| `mcp-adapter-get-ability-info` | Returns one ability's description and input schema |
| `mcp-adapter-execute-ability` | Runs an ability with parameters |

Those three are the whole interface. The bridges' abilities are not separate MCP
tools — the agent discovers them through `discover-abilities` and calls them
through `execute-ability`, passing the ability's name:

```json
{
  "ability_name": "wp-core-mcp/list-posts",
  "parameters": { "per_page": 2 }
}
```

To check the connection without an agent, two curl calls do it. The first
returns a session id in the `Mcp-Session-Id` response header, the second uses
it:

```bash
curl -si -u 'USERNAME:APP PASSWORD' \
  -X POST https://example.com/wp-json/mcp/mcp-adapter-default-server \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'

curl -s -u 'USERNAME:APP PASSWORD' \
  -X POST https://example.com/wp-json/mcp/mcp-adapter-default-server \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Mcp-Session-Id: THE_ID_FROM_THE_FIRST_RESPONSE' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

## Which user should the agent connect as?

The agent can do exactly what that user can do, and the check happens in two
places:

1. **Reaching the server at all** requires the `read` capability, so any logged-in
   user — a subscriber included — can connect.
2. **Running an ability** goes through that ability's own `permission_callback`,
   and those are narrow: `wp-core-mcp/list-posts` wants `edit_posts`,
   `wp-core-mcp/update-option` wants `manage_options`,
   `wc-mcp/update-product-images` wants `manage_woocommerce`, and object-level
   abilities check the specific object as well.

So the role you pick is the boundary, and it is decided ability by ability
rather than all at once. Connected as a subscriber, `wp-core-mcp/get-post`
returned a published post — it checks `read_post` against that specific post —
while `wp-core-mcp/list-posts` refused, because it wants `edit_posts`. Connect
as an administrator and the agent has administrator reach.

One thing to be aware of: **discovery is not filtered by capability.** A
subscriber calling `discover-abilities` sees the full catalogue, including
abilities they cannot run — the capability check happens when the ability
executes, not when it is listed. The catalogue is a list of names, descriptions
and schemas, but if that is more than you want a low-privileged account to see,
give the account a role that matches its job rather than relying on the listing
to hide anything.

The practical setup: create a dedicated WordPress user for the agent, give it
the narrowest role that covers the work, and connect as that user rather than
as yourself.

## Troubleshooting

**Every call returns 401 `rest_forbidden`.** The credentials are not arriving.
Check the username and application password first. If they are right, the server
may be dropping the `Authorization` header — common on Apache with PHP as CGI or
FastCGI. WordPress's own `.htaccess` block handles this; if the block was
removed or the server is nginx, restore it or pass the header through in the
vhost.

**`Invalid Request: Missing Mcp-Session-Id header`.** The caller is not carrying
the session id returned by `initialize`. Real MCP clients do this; hand-written
scripts have to do it themselves.

**A tool call fails with `Permission denied: Access denied for tool:
mcp-adapter-execute-ability`.** This is not about the tool. The *ability* you
asked for refused the connected user, and the adapter reports it as a denial on
the meta-tool. Connect as a user whose role covers that ability.

**The endpoint returns 404.** Check that `https://example.com/wp-json/` responds
at all — on plain permalinks it does not, and you need the
`?rest_route=/mcp/mcp-adapter-default-server` form. If `/wp-json/` is fine but
the MCP route is not, the adapter is not activated.

**The agent connects but sees no bridge abilities.** Count the tools first. On
the adapter's default server an agent sees exactly three, and the bridges'
abilities arrive through them. If instead it sees a handful of named tools, the
client is pointed at a different MCP server on the same site: WooCommerce runs
its own at `/wp-json/woocommerce/mcp`, and only WooCommerce's own abilities are
ever exposed there — no bridge appears on it, by design on both sides. Point the
client back at `/wp-json/mcp/mcp-adapter-default-server`.

If the tool count is right and the abilities are still missing, the bridge is
not activated, or its target plugin or theme is missing — every bridge registers
its abilities only when the thing it bridges is present. Confirm with an
authenticated request to
`https://example.com/wp-json/wp-abilities/v1/abilities?per_page=100`.

**The Application Passwords panel is missing in wp-admin.** The site is not on
HTTPS and its environment type is not `local`. WordPress hides the feature
entirely in that case.

## A note on what you are handing over

An application password is full API access as that user; it is scoped by the
user's role and by nothing else. Treat it like a password:

- one application password per agent, so you can revoke one without disturbing
  the others,
- a dedicated user with the narrowest workable role rather than your own admin
  account,
- and keep it out of anything you commit or share — MCP client configuration
  files hold it in plain text.

Every bridge in this repository requires `confirm: true` on destructive and bulk
operations, so an agent cannot delete or mass-edit anything by accident. That is
a guard rail, not a permission system: the role is what decides what is
reachable.
