# AI Site Manager

A secure, stateless **MCP (Model Context Protocol) server for WordPress**. It lets external AI agents - Claude, ChatGPT, Gemini, n8n, custom apps - discover and execute WordPress actions over plain HTTP POST with JSON-RPC 2.0. No SSE, no long-lived connections.

Built by [FocusWeb Ltd.](https://focusweb.co.il)

## What it does

- **MCP endpoint** (JSON-RPC 2.0): `POST /wp-json/wp-mcp/v1/mcp` - speaks `initialize`, `ping`, `tools/list`, `tools/call`. Plain JSON responses.
- **OAuth 2.1 server** for connector-style clients: dynamic client registration (RFC 7591), authorization code + PKCE (S256), refresh-token rotation, revocation (RFC 7009), discovery metadata under `/.well-known/`.
- **Plain REST** for simple integrations: `POST /wp-json/wp-mcp/v1/tools/list` and `POST /wp-json/wp-mcp/v1/tools/execute`.
- **Dynamic tool registry**: posts, pages, media, users (read-only), site info, every public custom post type and public taxonomy on the site, plus adapters that appear automatically when their plugin is active (WooCommerce, Yoast SEO).
- **Scoped API keys**: Bearer tokens stored as keyed HMAC-SHA256 hashes (the plaintext is shown once). Every key is bound to a WordPress user and can only do what that user may do.
- **Human-in-the-loop approvals**: destructive tools (`*_delete`) are off by default; when enabled, they queue for an admin's approval instead of executing.
- **Full audit log**: every call (tool, key prefix, sanitized payload, status) in `{prefix}mcp_audit_logs`, with a filterable wp-admin viewer.

## Requirements

- WordPress 6.0+
- PHP 8.0+

## Install

1. Download the release ZIP (or build one with `./build-zip.sh`).
2. Plugins > Add New > Upload Plugin, choose the ZIP, activate.
3. Open **AI Site Manager > API Keys**, create a key bound to a WordPress user, and copy it - it is shown once.

The MCP endpoint is `https://yoursite.example/wp-json/wp-mcp/v1/mcp`.

## Connecting an AI client

### Claude (claude.ai connector)

Add a custom connector pointing at the MCP endpoint. Claude runs the built-in OAuth 2.1 flow (dynamic registration + PKCE): you approve access in your browser while logged into wp-admin, and Claude receives its own tokens. No API key to paste.

Claude Code CLI can also use a plain Bearer key:

```bash
claude mcp add --transport http my-site https://yoursite.example/wp-json/wp-mcp/v1/mcp \
  --header "Authorization: Bearer wpmcp_..."
```

### ChatGPT

ChatGPT's developer-mode MCP connectors authenticate with OAuth. Register the MCP endpoint URL; the plugin's OAuth 2.1 server handles client registration and the consent flow the same way as for Claude.

### Gemini

Gemini CLI supports remote MCP servers over HTTP with custom headers in `settings.json`:

```json
{
  "mcpServers": {
    "my-site": {
      "httpUrl": "https://yoursite.example/wp-json/wp-mcp/v1/mcp",
      "headers": { "Authorization": "Bearer wpmcp_..." }
    }
  }
}
```

### n8n / anything else

Use the plain REST endpoints with an `Authorization: Bearer <key>` header.

## Tools

Core (always available):

| Group | Tools |
| --- | --- |
| Posts / Pages / any public CPT | `{posts,pages,<cpt>}_list`, `_get`, `_create`, `_update`, `_delete` |
| Media | `media_list`, `media_get`, `media_upload_from_url`, `media_upload` (base64; JPEG/PNG/GIF/WebP, 5 MB max) |
| Taxonomies | `terms_list`, `terms_create` (categories, tags, any public taxonomy) |
| Users | `users_list`, `users_get` (read-only by design) |
| Site | `site_info` |

Conditional adapters:

- **WooCommerce** (when active): `woo_products_*` (full CRUD), `woo_orders_list`, `woo_orders_get`, `woo_orders_update` (status + notes).
- **Yoast SEO** (when active): `yoast_meta_get`, `yoast_meta_set` (SEO title, meta description, focus keyphrase, canonical, noindex).

Create/update accept `meta` (public custom fields), `terms` (assign categories/tags by ID) and `featured_media` in one call.

## Security model

- **Authentication**: Bearer API keys (hashed at rest) or OAuth 2.1 tokens.
- **Authorization**: every key and token is bound to a WordPress user; each tool call checks the real WordPress capability for that user and that specific item. An AI can never do more than its bound user.
- **Validation**: strict JSON-schema argument validation, union types and anyOf/oneOf supported; string arguments capped (1 MB default).
- **Sanitization**: content passes through kses/field sanitizers; protected `_`-prefixed meta keys are never read or written and fail the whole call loudly.
- **No privilege-escalation surface**: user creation and editing are deliberately not exposed.
- **Rate limits** on the OAuth registration/token/revocation endpoints; daily cleanup of expired codes and tokens.
- **Uploads**: base64 uploads are images only, detected from file content (magic bytes), never from the filename.

## Approval queue

Destructive tools (`*_delete`) ship **off by default**. When enabled in AI Site Manager > Settings, a destructive call does not execute: it returns `pending_approval` with an `approval_id`, an admin reviews it under AI Site Manager > Approvals, and the client polls with the `approval_id` to get the outcome.

## Development

```bash
php tests/harness.php   # 136 tests, no WordPress needed (stubbed core)
./build-zip.sh          # dist/ai-site-manager-<version>.zip (tests excluded)
```

## License

GPL-2.0-or-later. Copyright (C) 2026 FocusWeb Ltd. See [LICENSE](LICENSE).
