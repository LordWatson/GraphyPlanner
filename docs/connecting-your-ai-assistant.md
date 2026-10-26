# Connect your AI assistant to Graphy (MCP)

Graphy is an **MCP server**. That means AI assistants that speak the Model Context Protocol —
Claude (desktop & web), ChatGPT, Cursor, and others — can connect to your Graphy organisation and
read your clients, posts, calendar and analytics, draft content, and (if you allow it) schedule and
publish, all on your behalf. A person always confirms anything irreversible.

This guide gets you connected in a couple of minutes.

---

## 1. Turn MCP on and create a connection token (Owner, one‑time)

1. In Graphy, go to **Settings → AI connections** (visible to the organisation Owner).
2. Switch **MCP access** on. This is the per‑organisation master switch — while it is off, every
   assistant is rejected.
3. Under **New connection**, give it a name (e.g. “Claude Desktop”) and choose what it may do:
   - **Read** — explore clients, posts, calendar, campaigns, analytics (the safe default).
   - **Write drafts** — create and edit drafts, move posts up to *approved*.
   - **Publish & schedule** — schedule, cancel and start account connections. *Grant with care.*
   - **AI** — use Graphy’s own AI (trends, ideas, captions), subject to your AI budget.
4. Click **Create connection token**. **Copy the token now** — it is shown only once.
5. Copy the **Connection URL** at the top of the page. It looks like `https://your‑graphy‑domain/mcp`.

> **The URL must be `https`.** Hosted assistants (Claude, ChatGPT) refuse a plain‑`http` connector,
> so the page always shows the `https` form. That means a local `http://localhost` instance can’t be
> reached by a hosted assistant directly — for local testing use the **stdio** transport (section 4)
> or put an https tunnel (e.g. `ngrok`, `cloudflared`) in front and point the connector at the tunnel.

> The token is a secret, like a password. Anyone with it can act as that connection (within its
> scopes). Revoke it any time from the same page — revocation takes effect immediately.

---

## 2. Point your assistant at Graphy

Graphy authenticates with a **bearer token** you paste as an `Authorization` header. The most
portable way to add a remote MCP server with a custom header today is the `mcp-remote` bridge
(`npx mcp-remote`), which every stdio‑based client below can launch.

Replace `https://your‑graphy‑domain/mcp` with your Connection URL and `YOUR_TOKEN` with the token
you copied.

### Claude Desktop

Edit your `claude_desktop_config.json` (Settings → Developer → Edit Config):

```json
{
  "mcpServers": {
    "graphy": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-graphy-domain/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

Restart Claude Desktop. You should see Graphy’s tools appear (the hammer / tools icon).

### Cursor

Add to `~/.cursor/mcp.json` (or the project’s `.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "graphy": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-graphy-domain/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

### ChatGPT / Claude.ai custom connectors

Hosted clients that add a “custom connector” by URL currently expect an OAuth flow. Graphy issues
**manual bearer tokens** today (OAuth dynamic client registration is a planned addition), so the
reliable path for those clients is the same `mcp-remote` bridge above, or any connector field that
lets you set a static `Authorization` header. Point it at your Connection URL with
`Authorization: Bearer YOUR_TOKEN`.

---

## 3. Try it

Ask your assistant things like:

- “List my clients in Graphy and tell me which look unhealthy.”
- “Show me what’s on the calendar for Northwind Coffee next week.”
- “Draft three on‑brand posts for Acme and leave them as drafts.” *(needs Write drafts)*
- “Schedule the approved post 1423.” *(needs Publish & schedule)*

Or run a **prompt** (a packaged workflow): `plan_week` or `fill_calendar_gaps` for a client.

Everything is scoped to the one organisation your token belongs to — an assistant can never see or
touch another organisation’s data.

---

## 4. Local development (for engineers)

The same server runs over stdio locally, with no token (it resolves a single dev org):

```bash
# Launch the server as a stdio command (Claude Desktop can point at this directly)
php artisan mcp:start graphy

# Or open the MCP Inspector to browse tools/resources and call them by hand
php artisan mcp:inspector graphy
```

To test the remote HTTP transport locally, set `MCP_HTTP_ENABLED=true`, create a token
(`App\Actions\Mcp\CreateMcpTokenAction`), and POST JSON‑RPC to `/mcp` with the bearer token.

---

## What your assistant can do (and the scope it needs)

| Capability | Example tools | Scope |
|---|---|---|
| Explore the agency | `list_clients`, `get_client`, `get_brand_brain`, `list_posts`, `get_post`, `get_calendar`, `list_campaigns`, `get_campaign`, `list_assets`, `get_analytics_summary` | Read |
| Create & refine content | `create_draft_post`, `update_post`, `transition_post_status` (up to *approved*) | Write drafts |
| Add & attach media | `request_media_upload` + `get_media_upload` (the reliable upload-link flow, incl. media for several posts at once), `upload_media` (a small image as base64), `set_post_media` (replace / add / remove a post's media) | Write drafts |
| Publish, schedule, connect | `schedule_post`, `cancel_scheduled_post`, `start_account_connection` | Publish & schedule |
| Use Graphy’s AI | `discover_trends`, `recommend_posts`, `suggest_captions` | AI |
| Packaged workflows | `plan_week`, `fill_calendar_gaps` (prompts) | (use the tools above) |

### Uploading your media

An assistant can't reliably carry a real photo as text, so for images you need to provide, Graphy uses
a one-click upload link that handles any number of files across any number of posts.

Say e.g. *"create 3 posts for Northwind — I'll upload media for each."* The assistant creates the posts,
then calls **`request_media_upload`** and gives you **one** link. You open it in any browser (no login),
and the page shows each post with its own drop area — drop **one or more** files onto each (or into the
library area if no post was named). The assistant picks up what landed with **`get_media_upload`** and
finishes the posts. The link is scoped to that one client, expires after ~60 minutes, and every file is
validated exactly like a web upload (file type, size, content sniffing and a virus scan).

For a small image the assistant already holds, it can instead send it straight to `upload_media` as
base64. Either way the media attaches via `create_draft_post` (`asset_ids`) or `set_post_media`. All of
these need the **Write drafts** scope. Graphy never hands an assistant a media download URL, so sharing
media works one way: into Graphy.

### Safety

- **Read‑only by default.** Elevated scopes are an explicit opt‑in.
- **Publishing is gated and annotated destructive**, so good clients ask you to confirm first.
- **No secrets ever leave Graphy** — assistants never receive API keys, OAuth tokens or signed media
  URLs.
- **Every call is audited** — see them under Settings → AI connections → Activity.
- **Kill switch** — turn the organisation’s MCP access off, or revoke a token, and access stops
  immediately.
