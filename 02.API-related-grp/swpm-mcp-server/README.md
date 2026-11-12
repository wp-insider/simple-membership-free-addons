# Simple Membership MCP server

Lets Claude (and other AI assistants that support MCP) manage a Simple Membership site: look up,
create, update and delete members and membership levels, and read payment transactions.

It runs on your computer and talks to your site through the **Simple Membership REST API**
add-on. Your AI app starts it for you; you never run it by hand.

## Tools

| Tool | Does |
| --- | --- |
| `list_members`, `get_member` | Find and read member profiles |
| `create_member`, `update_member`, `delete_member` | Change member profiles |
| `list_levels`, `get_level` | Read membership levels |
| `create_level`, `update_level`, `delete_level` | Change membership levels |
| `list_transactions`, `get_transaction` | Read payment records |

The write tools only work if **Allow write access** is ticked in the add-on settings. Even then, you
can hide them with `SWPM_READ_ONLY=1` (below).

## What you need

1. **Node.js 20 or newer** from <https://nodejs.org> (the LTS version is fine). Check with
   `node --version`.
2. On the WordPress site, the **Simple Membership REST API** add-on, active, with **Enable REST
   API** ticked under **WP Membership > Settings > Addon Settings**. Copy the **API Key** from
   there.
3. The site must use HTTPS. The key travels with every request.

Then install this server's packages once, from this folder:

```bash
npm install
```

## Settings

The server reads these from its environment. Your AI app passes them in (see the setup sections).

| Variable | Required | Meaning |
| --- | --- | --- |
| `SWPM_SITE_URL` | Yes | Your site address, e.g. `https://example.com` |
| `SWPM_API_KEY` | Yes* | The API key from the add-on settings |
| `SWPM_WP_USERNAME`, `SWPM_WP_APP_PASSWORD` | * | Instead of the key: a WordPress admin username and an Application Password (Users > Profile > Application Passwords) |
| `SWPM_READ_ONLY` | No | `1` to offer only the read tools |
| `SWPM_API_URL` | No | Full API address, for a site without pretty permalinks: `https://example.com/?rest_route=/swpm/v1` |
| `SWPM_TIMEOUT_SECONDS` | No | How long to wait for the site (default 30) |

## Set up in Claude Code

Replace the path with where this folder is on your computer:

```bash
claude mcp add --transport stdio \
  --env SWPM_SITE_URL=https://your-test-site.com \
  --env SWPM_API_KEY=your-api-key \
  simple-membership -- node "/full/path/to/swpm-mcp-server/src/index.js"
```

Add `--scope user` to make it available in every project, not just the current folder. Then start
Claude Code and run `/mcp`: `simple-membership` should show as connected with its tools.

## Set up in Claude Desktop

Open **Settings > Developer > Edit Config**, which opens `claude_desktop_config.json`
(Windows: `%APPDATA%\Claude\claude_desktop_config.json`; macOS:
`~/Library/Application Support/Claude/claude_desktop_config.json`). Add:

```json
{
  "mcpServers": {
    "simple-membership": {
      "command": "node",
      "args": ["C:/full/path/to/swpm-mcp-server/src/index.js"],
      "env": {
        "SWPM_SITE_URL": "https://your-test-site.com",
        "SWPM_API_KEY": "your-api-key"
      }
    }
  }
}
```

Use forward slashes in the Windows path, or double every backslash. Save, then quit and reopen
Claude Desktop fully. The tools appear under the tools icon in the message box.

## Try it

- "How many active members do we have, and on which levels?"
- "Find the member with the email jane@example.com and show their expiry date."
- "Create a level called Gold that lasts 12 months."
- "Move every member on the Trial level to Silver." (needs write access)

Claude asks before it runs a tool, unless you have told it not to. Read write and delete requests
before you approve them.

## Safety

- **Start on a test site.** A delete is permanent.
- **Start read-only.** Leave **Allow write access** off in the add-on, or set `SWPM_READ_ONLY=1`,
  until you are comfortable with what Claude does.
- **The API key is stored in your AI app's config file** in plain text. Anyone who can read that
  file can use the key. If it leaks, tick **Regenerate API Key** in the add-on settings.
- Every API call is recorded in the plugin's debug log when debug logging is on.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Server does not appear or shows as failed | Run `node src/index.js` in a terminal: a missing setting is reported there. Check the path and that `npm install` was run. |
| "The REST API is switched off" | Tick **Enable REST API** in the add-on settings |
| "The API key was rejected" | The key was mistyped or regenerated |
| "The site only allows reading" | Tick **Allow write access**, if you want Claude to make changes |
| "Could not reach the site" | Wrong `SWPM_SITE_URL`, or the site is down |
| "The add-on is not active" (`rest_no_route`) | Activate the add-on, or set `SWPM_API_URL` if the site has no pretty permalinks |

## Development

```bash
npm test
```

The tests include a check that every tool's arguments match the add-on's
`swpm-rest-api/docs/openapi.yaml`. After changing a route in the add-on, regenerate that file
(`swpm-rest-api-dev`), then update `src/tools.js` until `npm test` passes.

Never write to stdout in this server: it carries the MCP protocol. Log with `console.error`.
