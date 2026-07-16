#!/usr/bin/env node

/**
 * ServerLuxe MCP Bridge
 * =====================
 * Translates standard MCP (Model Context Protocol) stdio JSON-RPC messages into
 * HTTP API calls against a remote ServerLuxe deployment (db.php / fm.php).
 *
 * WHAT THIS IS
 * ------------
 * A thin, dependency-free stdio<->HTTPS proxy. An AI agent (or any MCP client)
 * speaks to THIS process over stdin/stdout using JSON-RPC 2.0, and this bridge
 * forwards each request to the remote ServerLuxe endpoint over HTTPS, returning
 * the server's JSON response back over stdout. It does NOT implement the tools
 * itself — it brokers them from the server.
 *
 * USAGE
 * -----
 *   node mcp-bridge.js --url <SERVERLUXE_URL> --key <API_KEY>
 *
 *   --url  Base URL of the ServerLuxe endpoint, e.g.
 *          https://app.tharkistaan.com/serverluxe/fm.php  (file manager)
 *          https://app.tharkistaan.com/serverluxe/db.php  (database)
 *   --key  The API key (X-API-KEY). Required for all remote calls.
 *
 *   Run `node mcp-bridge.js --help` to print this information.
 *
 * WIRING IT INTO AN MCP CLIENT
 * ----------------------------
 * Add a server entry to your mcp_config.json:
 *   {
 *     "mcpServers": {
 *       "serverluxe": {
 *         "command": "node",
 *         "args": [
 *           "/absolute/path/to/mcp-bridge.js",
 *           "--url", "https://app.tharkistaan.com/serverluxe/fm.php",
 *           "--key", "YOUR_API_KEY"
 *         ]
 *       }
 *     }
 *   }
 *
 * PROTOCOL CONTRACT (what goes over the wire)
 * --------------------------------------------
 * Each stdin line is a JSON-RPC 2.0 request, e.g.:
 *   {"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}
 *   {"jsonrpc":"2.0","id":1,"method":"tools/call",
 *    "params":{"name":"read_file","arguments":{"path":"/abs/path/file.php"}}}
 *
 * The bridge appends `?action=mcp_api` to the URL and sends:
 *   POST <url>?action=mcp_api
 *   Headers: Content-Type: application/json, X-API-KEY: <key>
 * Body: the raw JSON-RPC request.
 *
 * The server replies with a JSON-RPC result that is printed verbatim to stdout.
 *
 * AVAILABLE TOOLS (delegated to the server; call tools/list for the live list)
 * ---------------------------------------------------------------------------
 *   File manager (fm.php):
 *     - list_directory { path }            -> lists a directory
 *     - read_file      { path }            -> reads a file (must be in an allowed folder)
 *     - write_file     { path, content }   -> writes/creates a file
 *     - delete_file    { path }            -> deletes a file or directory
 *   Database (db.php):
 *     - query_database, list_tables, describe_table, ... (per server config)
 *
 * NOTES FOR AI AGENTS
 * -------------------
 * - Paths are ABSOLUTE on the remote server (e.g. /www/wwwroot/app.tharkistaan.com/...).
 *   Relative paths are rejected ("Invalid path or directory traversal detected").
 * - Folder access is gated by server-side mcp_config.json `folders` (read/write ACLs).
 *   A "does not have Write/Read access" error means the target folder isn't granted
 *   on the server — not a bridge problem.
 * - The web UI (fm.php / db.php) asks for the master password once per day; this
 *   bridge authenticates with the API key and is unaffected by that gate.
 * - Responses are forwarded as-is; on HTTP error the bridge emits a JSON-RPC error.
 */

const http = require('http');
const https = require('https');
const readline = require('readline');

// Parse command line arguments
const args = process.argv.slice(2);
let urlStr = '';
let apiKey = '';

for (let i = 0; i < args.length; i++) {
    if (args[i] === '--url' && args[i + 1]) {
        urlStr = args[i + 1];
        i++;
    } else if (args[i] === '--key' && args[i + 1]) {
        apiKey = args[i + 1];
        i++;
    }
}

// --help flag: print documentation and exit (handy for AI agents / operators)
if (args.includes('--help') || args.includes('-h')) {
    console.error(`ServerLuxe MCP Bridge

Forwards MCP stdio JSON-RPC to a remote ServerLuxe endpoint (db.php / fm.php).

Usage:
  node mcp-bridge.js --url <SERVERLUXE_URL> --key <API_KEY>

Examples:
  node mcp-bridge.js --url https://app.tharkistaan.com/serverluxe/fm.php --key <API_KEY>
  node mcp-bridge.js --url https://app.tharkistaan.com/serverluxe/db.php --key <API_KEY>

Required:
  --url   ServerLuxe endpoint base URL
  --key   API key (sent as X-API-KEY header)

The bridge appends ?action=mcp_api to the URL and speaks JSON-RPC 2.0 over stdin/stdout.
See the file header comment for the full protocol contract and tool list.`);
    process.exit(0);
}

if (!urlStr || !apiKey) {
    console.error('Error: Missing required parameters.');
    console.error('Usage: node mcp-bridge.js --url <server_luxe_url> --key <api_key>');
    console.error('Run "node mcp-bridge.js --help" for details.');
    process.exit(1);
}

// Setup console readline for stdin/stdout communication
const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    terminal: false
});

rl.on('line', (line) => {
    if (!line.trim()) return;
    try {
        const jsonRequest = JSON.parse(line);
        forwardRequest(jsonRequest);
    } catch (e) {
        sendError(null, -32700, 'Parse error: ' + e.message);
    }
});

function forwardRequest(request) {
    const url = new URL(urlStr);
    const isHttps = url.protocol === 'https:';
    const client = isHttps ? https : http;

    const payload = JSON.stringify(request);

    // Append action=mcp_api to query string
    const targetPath = url.pathname + url.search + (url.search ? '&' : '?') + 'action=mcp_api';

    const options = {
        hostname: url.hostname,
        port: url.port || (isHttps ? 443 : 80),
        path: targetPath,
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(payload),
            'X-API-KEY': apiKey
        },
        timeout: 15000 // 15s timeout
    };

    const req = client.request(options, (res) => {
        let body = '';
        res.on('data', (chunk) => {
            body += chunk;
        });
        res.on('end', () => {
            if (res.statusCode >= 200 && res.statusCode < 300) {
                // Forward the response exactly as received back to stdout
                try {
                    // Validate JSON before outputting
                    const jsonRes = JSON.parse(body);
                    console.log(JSON.stringify(jsonRes));
                } catch (e) {
                    sendError(request.id, -32603, 'Internal server error: Invalid JSON response from remote node: ' + body);
                }
            } else {
                sendError(request.id, -32603, `HTTP Error ${res.statusCode}: ${body}`);
            }
        });
    });

    req.on('error', (e) => {
        sendError(request.id, -32603, 'Bridge connection error: ' + e.message);
    });

    req.on('timeout', () => {
        req.destroy();
        sendError(request.id, -32603, 'Bridge connection timeout.');
    });

    req.write(payload);
    req.end();
}

function sendError(id, code, message) {
    console.log(JSON.stringify({
        jsonrpc: '2.0',
        id: id,
        error: {
            code: code,
            message: message
        }
    }));
}
