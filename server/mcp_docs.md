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

1. Open **`db.php`** (for databases) or **`fm.php`** (for files) in your browser.
2. Open the **MCP & Auto-Update** panel from the sidebar.
3. Under **AI & MCP Permissions**:
   - **For databases (`db.php`)**: Check **Read** or **Write** next to each database you want to share.
   - **For folders (`fm.php`)**: Add folder absolute paths and check **Read** or **Write** for each.
4. Click **Save Permissions**. This automatically creates and configures a secure `mcp_config.json` configuration file on your server with appropriate permissions.

---

## Step 2: Set Up the AI Client Configuration

Copy the configuration block directly from the **MCP & Auto-Update** panel in your browser, or configure it manually:

### Database Manager (`db.php`) Configuration
Use this to let your AI read, describe, and query your database tables:

```json
{
  "mcpServers": {
    "serverluxe-db": {
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

### File Manager (`fm.php`) Configuration
Use this to let your AI view, read, write, or delete files in permitted directories:

```json
{
  "mcpServers": {
    "serverluxe-files": {
      "command": "node",
      "args": [
        "/absolute/path/to/serverluxe/server/mcp-bridge.js",
        "--url", "https://yourdomain.com/admin/fm.php",
        "--key", "YOUR_API_KEY"
      ]
    }
  }
}
```

---

## Exposed Tools

### Database Manager (`db.php`) Tools:
*   **`list_tables`**: Lists all tables in the specified database.
    *   *Arguments*: `database` (string, required)
*   **`describe_table`**: Gets the columns and description of a table.
    *   *Arguments*: `database` (string, required), `table` (string, required)
*   **`query_database`**: Runs custom SQL queries. Select queries require `read` permission, while write queries require both `read` and `write` permissions.
    *   *Arguments*: `database` (string, required), `query` (string, required)

### File Manager (`fm.php`) Tools:
*   **`list_directory`**: Lists directory contents.
    *   *Arguments*: `path` (string, required)
*   **`read_file`**: Reads text content of a file.
    *   *Arguments*: `path` (string, required)
*   **`write_file`**: Writes or creates text content for a file.
    *   *Arguments*: `path` (string, required), `content` (string, required)
*   **`delete_file`**: Deletes a file or folder.
    *   *Arguments*: `path` (string, required)

---

## Security Best Practices

1. **Granular Access Control**: Never grant Write access to the AI unless you explicitly need it to edit files or run data mutations.
2. **Path Traversal Protection**: Folder permissions are evaluated recursively. Granting access to `/var/www/html` does NOT grant the AI access to system configuration directories like `/etc`.
3. **API Key Protection**: Keep your `API_KEY` in `.env` secret. Anyone with your URL and API Key can access the allowed resources.
