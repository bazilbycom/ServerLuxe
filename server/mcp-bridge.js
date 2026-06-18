#!/usr/bin/env node

/**
 * ServerLuxe MCP Bridge
 * Translates standard MCP stdio JSON-RPC messages into HTTP API calls for remote db.php and fm.php.
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

if (!urlStr || !apiKey) {
    console.error('Error: Missing required parameters.');
    console.error('Usage: node mcp-bridge.js --url <server_luxe_url> --key <api_key>');
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
