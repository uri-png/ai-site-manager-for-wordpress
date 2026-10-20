=== AI Site Manager ===
Contributors: focusweb
Tags: mcp, ai, json-rpc, api, automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure, stateless MCP gateway: AI agents discover and run WordPress actions over HTTP POST (JSON-RPC 2.0).

== Description ==

AI Site Manager turns a WordPress site into an MCP (Model Context Protocol) server that external AI agents - Claude, n8n, custom apps - can safely use.

* MCP endpoint (JSON-RPC 2.0): POST /wp-json/wp-mcp/v1/mcp (initialize, ping, tools/list, tools/call; plain JSON, no SSE)
* OAuth 2.1 server: dynamic client registration (RFC 7591), authorization code + PKCE S256, refresh-token rotation, revocation (RFC 7009), discovery metadata
* Dynamic tool registry: posts, pages, media, users (read-only), site info, every public custom post type, WooCommerce products/orders when active
* Scoped, hashed API keys, each bound to a WordPress user and limited to that user's capabilities
* Human-in-the-loop approvals: destructive tools are off by default and queue for admin approval
* Full audit log with an admin viewer, plus OAuth lifecycle events
* Rate-limited token/registration endpoints and daily cleanup of expired codes and tokens

== Installation ==

1. Upload the ZIP via Plugins > Add New > Upload Plugin and activate.
2. Open AI Site Manager > API Keys, create a key bound to a user, and copy it (shown once).
3. Connect your MCP client to https://yoursite/wp-json/wp-mcp/v1/mcp - either with an Authorization: Bearer <key> header or via the built-in OAuth flow.

== Changelog ==

= 1.2.1 =
* New: Settings > "Bridged ability namespaces" (default * = every WordPress Abilities API namespace, or a comma-separated list such as elementor, focusweb-ai-visibility). Abilities from any plugin now become tools named ability_<namespace>_<name> (elementor_* for Elementor). Read-only abilities (meta.annotations.readonly) are on; all others stay off and approval-gated.
* New: meta tools abilities_list (discover all registered abilities and schemas), ability_read (run any read-only ability) and ability_execute (run any ability; off by default, approval-gated). They find abilities registered after the client connected, without a tools/list refresh.
* Changed: elementor_status now also lists every registered ability and the bridged namespaces.
* Fixed: very long ability ids get a unique 64-character tool name.

= 1.2.0 =
* New: Elementor bridge (Elementor 4.2+, Atomic Editor). Elementor's own MCP abilities (WordPress Abilities API, category "elementor") are exposed as tools named elementor_* through this plugin's endpoint, with OAuth/API-key auth, per-tool ON/OFF, the approval queue and the audit log. Write abilities are OFF by default and queued for approval.
* New: elementor_status (diagnoses missing Elementor version, Atomic Editor, MCP exposure, Abilities API, MCP Adapter plugin), elementor_backups_list and elementor_backup_restore. Before every Elementor write the page's _elementor_data is backed up (last 5 kept) and can be restored.
* Changed: tools may declare a pass-through validation schema (additionalProperties true) when the target validates input itself.
* Requires for the Elementor tools: WordPress 6.9+ (Abilities API), the WordPress MCP Adapter plugin, and "MCP" turned on in Elementor > Elementor MCP. Other tools are unaffected.

= 1.1.11 =
* New: media_upload tool - upload images sent directly as base64 (no URL needed). JPEG/PNG/GIF/WebP only, detected from file content, 5 MB max after decoding; optional alt text, attach-to-post and set-as-featured in one call.
* New: Yoast SEO adapter (yoast_meta_get / yoast_meta_set) - SEO title, meta description, focus keyphrase, canonical URL and noindex, registered only when Yoast SEO is active.
* Fixed: schema validation no longer crashes on tools declaring a union "type" (e.g. ["string","array"]); union types, "null" and basic anyOf/oneOf are now supported.


= 1.1.10 =
* Fixed leftover "WP MCP Gateway" strings: admin page heading now reads "AI Site Manager MCP"; Settings label renamed to "MCP server enabled".


= 1.1.9 =
* Minimum PHP lowered to 8.0 (was declared 8.1 only because of `readonly` properties; nothing else needed it).


= 1.1.8 =
* Fix: JSON-RPC error messages no longer HTML-escaped (meta keys came through as &quot;_&quot;).
* Tool descriptions now state that protected meta keys fail the whole call - resend without them.


= 1.1.7 =
* Fix: draft items no longer return "-001-11-30" dates (zero post_date_gmt falls back to post_date).
* Fix: users_list now returns each user's roles.
* Fix: writes carrying "_" protected meta keys fail loudly with a clear error instead of silently dropping them.
* Fix: a missing item ID now reports "not found" instead of a misleading permission error.
* New tools: terms_list / terms_create (categories, tags, any public taxonomy), featured_media and terms arguments on create/update (set the featured image and assign terms in one call).


= 1.1.6 =
* Renamed to AI Site Manager (slug ai-site-manager) - no "wp" in name or slug. All data is fully preserved: database tables, options, REST namespace (/wp-json/wp-mcp/v1), OAuth endpoints, API keys and tokens are unchanged, so existing connections (including Claude) keep working after the swap.
* Fix: the new OAuth Clients tab fataled (its render method was missing from the build). All six admin tabs are now covered by render tests.
* Plugin Check: "Tested up to" bumped to 7.1; database-access sniffs addressed with a documented file-level annotation (custom tables, prepared values).


= 1.1.5 =
* Security/hardening: rate limits on the registration, token and revocation endpoints; RFC 7009 token revocation endpoint; daily cleanup of expired OAuth codes/tokens; admin "OAuth Clients" tab to review and revoke registered clients.
* Plugin Check: fixed all reported errors in production code (escaping, translators comment, array_is_list compatibility with WP 6.0, proper readme.txt) and annotated intentional patterns (custom-table queries, read-only filter params).
* Tests are no longer shipped in the release ZIP.

= 1.1.4 =
* Fix: the authorize/consent flow moved out of the REST API to a front-end endpoint (/oauth/authorize) - REST cookie auth dropped the logged-in user without X-WP-Nonce, causing an endless wp-login bounce.

= 1.1.3 =
* Fix: consent redirect to the client's callback used wp_safe_redirect, which refuses external hosts; the authorization code never reached the client.
* Fix: CORS headers + preflight on the /.well-known discovery documents.
* New: OAuth steps written to the audit log.

= 1.1.2 =
* Fix: admin menu/tab URLs rebuilt (they rendered the front-end theme).
* Upgrade routine: ZIP-replace updates self-heal tables/rewrite rules.

= 1.1.1 =
* Fix: OAuth discovery documents served without rewrite-flush dependence.

= 1.1.0 =
* OAuth 2.1 server (DCR, PKCE, refresh rotation, discovery) for claude.ai-style connectors.

= 1.0.1 =
* 405 + Allow on GET/DELETE /mcp, WWW-Authenticate on 401, top-level admin menu.

= 1.0.0 =
* Initial release: stateless MCP endpoint, dynamic tools (core + CPT + WooCommerce), scoped API keys, approvals, audit log.


