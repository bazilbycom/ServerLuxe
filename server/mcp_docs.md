# ServerLuxe Model Context Protocol (MCP) Integration

ServerLuxe supports the **Model Context Protocol (MCP)**, allowing AI agents (like Claude Desktop, Cursor, and VS Code) to securely interact with your server databases and files directly from your editor or chat window.

---

## How It Works

MCP communication is established using a lightweight local script (`mcp-bridge.js`) acting as a proxy. This bridge translates standard `stdio`-based JSON-RPC commands from your local AI app into HTTP/HTTPS requests sent to your remote `db.php` or `fm.php` instances.

```
[ Local AI Client ] <--- stdio ---> [ mcp-bridge.js ] <--- HTTPS (API Key) ---> [ Remote db.php / fm.php ]
```

---

## Step 1: Configure Permissions in ServerLuxe

Before your AI can read or write any directories or databases, you must explicitly enable permissions in the Web panel.

1. Open `db.php` or `fm.php` in your browser.
2. Open the **MCP & Auto-Update** panel from the sidebar (or Maintenance section).
3. Under **AI & MCP Permissions**:
   - For databases: Check **Read** or **Write** next to each database you want to share.
   - For folders: Add folder absolute paths and check **Read** or **Write** for each.
4. Click **Save Permissions**. This creates a secure `mcp_config.json` configuration file on your server.

---

## Step 2: Set Up the AI Client Configuration

Copy the configuration block directly from the **MCP & Auto-Update** panel in your browser, or configure it manually:

### Claude Desktop

Add this configuration to your Claude Desktop config file (located at `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, or `%APPDATA%\Claude\claude_desktop_config.json` on Windows):

```json
{
  "mcpServers": {
    "serverluxe": {
      "command": "node",
      "args": [
        "/absolute/path/to/serverluxe/server/mcp-bridge.js",
        "--url", "https://yourdomain.com/admin/db.php",
        "--key", "YOUR_API_KEY"
      ]
    }
  }
}
```

### Cursor

1. Go to **Settings** -> **Features** -> **MCP**.
2. Click **+ Add New MCP Server**.
3. Fill out:
   - **Name**: `serverluxe`
   - **Type**: `command`
   - **Command**: `node /absolute/path/to/serverluxe/server/mcp-bridge.js --url https://yourdomain.com/admin/db.php --key YOUR_API_KEY`
4. Click **Save**.

---

## Security Best Practices

1. **Granular Access Control**: Never grant Write access to the AI unless you explicitly need it to edit files or run data mutations.
2. **Path Traversal Protection**: Folder permissions are evaluated recursively. Granting access to `/var/www/html` does NOT grant the AI access to system configuration directories like `/etc`.
3. **API Key Protection**: Keep your `API_KEY` in `.env` secret. Anyone with your URL and API Key can access the allowed resources.
