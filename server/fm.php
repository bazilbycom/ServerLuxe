<?php
/**
 * Web File Manager - FMLuxe (Local File System)
 * Single-file management tool for interacting with server files.
 */

session_start();

// CORS Support for Mobile App - FIXED: Exact origin matching instead of substring matching
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = false;

// Exact match for capacitor (iOS)
if ($origin === 'capacitor://localhost') {
    $allowed = true;
}
// Localhost with any port (e.g., http://localhost:8080) - Android Capacitor
elseif (preg_match('#^https?://localhost(:\d+)?$#', $origin)) {
    $allowed = true;
}
// Some Android WebView versions send null origin
elseif ($origin === 'null' || $origin === '') {
    $allowed = true;
}

if ($allowed) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-CSRF-TOKEN");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        exit(0);
    }
}
ob_start();


// DotEnv Loader
function load_env($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}
load_env(__DIR__ . '/.env');

// Configuration & Constants
define('VERSION', '1.3.5');
define('API_KEY', $_ENV['API_KEY'] ?? '2026');
define('MASTER_PASS', $_ENV['MASTER_PASS'] ?? '');
define('DB_FILE', $_ENV['DB_FILE'] ?? 'db.php');
define('FM_FILE', $_ENV['FM_FILE'] ?? 'fm.php');


// SECURITY: Environment-based error reporting
error_reporting(E_ALL);
$is_production = ($_ENV['APP_ENV'] ?? 'development') === 'production';
ini_set('display_errors', $is_production ? 0 : 1);
if ($is_production) {
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error.log');
}

// Ensure base dir is set to system root for full access
$base_dir = (DIRECTORY_SEPARATOR === '\\') ? substr(realpath(__DIR__), 0, 3) : '/';
$abs_dir = realpath(__DIR__);
$norm_base = str_replace('\\', '/', $base_dir);
$norm_abs = str_replace('\\', '/', $abs_dir);

// For Windows, ensure norm_base doesn't have a double slash if mapped from root
$norm_base = rtrim($norm_base, '/');
$initial_path = '/' . ltrim(str_ireplace($norm_base, '', $norm_abs), '/');
$initial_path = str_replace('//', '/', $initial_path);
// Final normalization for Windows paths to ensure they start with a single slash and use forward slashes
$initial_path = rtrim($initial_path, '/');
if (empty($initial_path)) $initial_path = '/';





function format_permissions($mode) {
    if (!$mode) return '---------';
    $owner = (($mode & 0x0100) ? 'r' : '-') . (($mode & 0x0080) ? 'w' : '-') . (($mode & 0x0040) ? (($mode & 0x0800) ? 's' : 'x') : (($mode & 0x0800) ? 'S' : '-'));
    $group = (($mode & 0x0020) ? 'r' : '-') . (($mode & 0x0010) ? 'w' : '-') . (($mode & 0x0008) ? (($mode & 0x0400) ? 's' : 'x') : (($mode & 0x0400) ? 'S' : '-'));
    $world = (($mode & 0x0004) ? 'r' : '-') . (($mode & 0x0002) ? 'w' : '-') . (($mode & 0x0001) ? (($mode & 0x0200) ? 't' : 'x') : (($mode & 0x0200) ? 'T' : '-'));
    return $owner . $group . $world;
}

// ============ SECURITY HARDENING FUNCTIONS ============

// SECURITY: Path validation to prevent directory traversal attacks
function validate_path($base_dir, $user_path) {
    $user_path = ltrim($user_path, '/');
    $target = $base_dir . '/' . $user_path;
    $real_path = realpath($target);

    // If path doesn't exist (e.g., for mkdir, upload), validate parent
    if ($real_path === false) {
        $parent = dirname($target);
        $real_path = realpath($parent);
        if ($real_path === false || stripos($real_path, $base_dir) !== 0) {
            return ['valid' => false, 'path' => $base_dir, 'normalized' => '/'];
        }
        $real_path = $real_path . '/' . basename($target);
    }

    // Check if resolved path is within base_dir
    if (stripos($real_path, $base_dir) !== 0) {
        return ['valid' => false, 'path' => $base_dir, 'normalized' => '/'];
    }

    // Calculate normalized path
    $normalized = '/' . ltrim(str_ireplace($base_dir, '', str_replace('\\', '/', $real_path)), '/');

    return ['valid' => true, 'path' => $real_path, 'normalized' => $normalized];
}

define('SESSION_TIMEOUT', 1800); // 30 minutes

function verify_master_password($provided_pass) {
    $master_pass = MASTER_PASS;

    if (empty($master_pass) || empty($provided_pass)) {
        return false;
    }

    return hash_equals($master_pass, $provided_pass);
}

// SECURITY: Session timeout and regeneration
function check_session_timeout() {
    if (!isset($_SESSION['fm_authenticated']) || !$_SESSION['fm_authenticated']) {
        return false;
    }

    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];

        if ($elapsed > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            return false;
        }
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function init_session_after_login() {
    session_regenerate_id(true);
    $_SESSION['fm_authenticated'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    // Daily master-password gate: require re-auth once per calendar day.
    $_SESSION['auth_date'] = date('Y-m-d');
}

// SECURITY: CSRF Token protection
function get_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token() {
    // API key requests don't need CSRF (stateless)
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!empty(API_KEY) && hash_equals(API_KEY, $provided_key)) {
        return true;
    }

    // Session-based requests need CSRF
    if (empty($_SESSION['fm_authenticated'])) {
        return false;
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

// SECURITY: Rate limiting for login attempts
function check_rate_limit($action = 'login', $max_attempts = 5, $window = 900) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . $action . '_' . md5($ip);

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['attempts' => 0, 'first_attempt' => time()];
    }

    $data = &$_SESSION[$key];

    // Check if window has expired
    if (time() - $data['first_attempt'] > $window) {
        $data = ['attempts' => 0, 'first_attempt' => time()];
    }

    // Check if limit exceeded
    if ($data['attempts'] >= $max_attempts) {
        $remaining = $window - (time() - $data['first_attempt']);
        return ['allowed' => false, 'wait' => $remaining];
    }

    return ['allowed' => true];
}

function record_failed_login() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_login_' . md5($ip);

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['attempts' => 0, 'first_attempt' => time()];
    }

    $_SESSION[$key]['attempts']++;
}

function is_api_request() {
    // If we have a session, it's not a "pure" API request from a remote node, but it's authorized.
    if (!empty($_SESSION['fm_authenticated'])) return true;

    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? $_POST['api_key'] ?? $_GET['api_key'] ?? '';
    
    // Check API Key
    if (!empty(API_KEY)) {
        if (hash_equals(API_KEY, $provided_key)) {
            return true;
        }
    }
    return false;
}

// ============ MCP & AUTO-UPDATE INTEGRATION ============

function load_mcp_config() {
    $config_file = __DIR__ . '/mcp_config.json';
    if (!file_exists($config_file)) {
        @file_put_contents($config_file, json_encode(['databases' => [], 'folders' => []], JSON_PRETTY_PRINT));
        @chmod($config_file, 0666);
    } else {
        @chmod($config_file, 0666);
    }
    if (file_exists($config_file)) {
        return json_decode(file_get_contents($config_file), true) ?: ['databases' => [], 'folders' => []];
    }
    return ['databases' => [], 'folders' => []];
}

function save_mcp_config($config) {
    $config_file = __DIR__ . '/mcp_config.json';
    if (file_exists($config_file)) {
        @chmod($config_file, 0666);
    }
    $res = file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    if ($res !== false) {
        @chmod($config_file, 0666);
    }
    return $res;
}

function has_mcp_permission($type, $name, $access_type) {
    $config = load_mcp_config();
    if (!isset($config[$type])) return false;
    
    if ($type === 'folders') {
        // Resolve the path; for new/non-existent files fall back to the parent dir
        // so we can still grant create access inside an allowed folder.
        $name_real = realpath($name);
        if ($name_real === false) {
            $parent = dirname($name);
            $parent_real = realpath($parent);
            if ($parent_real === false) return false;
            $name_real = rtrim($parent_real, '/') . '/' . basename($name);
        }
        $name_normalized = str_replace('\\', '/', $name_real);
        foreach ($config['folders'] as $path => $perms) {
            $path_real = realpath($path);
            if ($path_real === false) continue;
            $path_normalized = str_replace('\\', '/', $path_real);
            if (stripos($name_normalized, $path_normalized) === 0) {
                return !empty($perms[$access_type]);
            }
        }
        return false;
    }
    
    return !empty($config[$type][$name][$access_type]);
}

function check_for_updates() {
    $repo = 'bazilbycom/ServerLuxe';
    $branch = 'main';
    $git_available = false;
    $git_update_available = false;
    $local_hash = '';
    $remote_hash = '';
    $commits_behind = 0;
    
    if (is_dir(__DIR__ . '/../.git')) {
        $git_check = @shell_exec('git rev-parse --is-inside-work-tree 2>&1');
        if (trim($git_check) === 'true') {
            $git_available = true;
            @shell_exec('git fetch origin main 2>&1');
            $local_hash = trim(@shell_exec('git rev-parse HEAD') ?? '');
            $remote_hash = trim(@shell_exec('git rev-parse origin/main') ?? '');
            if ($local_hash !== $remote_hash && $local_hash !== '' && $remote_hash !== '') {
                $git_update_available = true;
                $commits_behind = (int)trim(@shell_exec('git rev-list --count HEAD..origin/main') ?? '0');
            }
        }
    }
    
    $remote_version = VERSION;
    $http_update_available = false;
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ServerLuxe-Updater\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $github_db = @file_get_contents("https://raw.githubusercontent.com/{$repo}/{$branch}/server/db.php?t=" . time(), false, $context);
    
    if ($github_db) {
        if (preg_match("/define\('VERSION',\s*'([^']+)'\)/", $github_db, $matches)) {
            $remote_version = $matches[1];
            if (version_compare(VERSION, $remote_version, '<')) {
                $http_update_available = true;
            }
        }
    }
    
    return [
        'git_available' => $git_available,
        'git_update_available' => $git_update_available,
        'http_update_available' => $http_update_available,
        'update_available' => ($git_update_available || $http_update_available),
        'current_version' => VERSION,
        'latest_version' => $remote_version,
        'commits_behind' => $commits_behind,
        'local_hash' => substr($local_hash, 0, 7),
        'remote_hash' => substr($remote_hash, 0, 7)
    ];
}

function apply_update() {
    $repo = 'bazilbycom/ServerLuxe';
    $branch = 'main';
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ServerLuxe-Updater\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    
    $github_db = @file_get_contents("https://raw.githubusercontent.com/{$repo}/{$branch}/server/db.php?t=" . time(), false, $context);
    $github_fm = @file_get_contents("https://raw.githubusercontent.com/{$repo}/{$branch}/server/fm.php?t=" . time(), false, $context);
    
    if (!$github_db || !$github_fm) {
        return "Failed to download update files from GitHub.";
    }
    
    $current_db_pass = '';
    $current_fm_pass = '';
    $db_file_path = __DIR__ . '/' . (defined('DB_FILE') ? DB_FILE : 'db.php');
    $fm_file_path = __DIR__ . '/' . (defined('FM_FILE') ? FM_FILE : 'fm.php');
    $current_db_file = '';
    $current_fm_file = '';
    
    if (file_exists($db_file_path)) {
        $old_db = file_get_contents($db_file_path);
        if (preg_match("/define\('MASTER_PASS',\s*'([^']*)'\)/", $old_db, $m)) {
            $current_db_pass = $m[1];
        }
        if (preg_match("/define\('DB_FILE',\s*'([^']*)'\)/", $old_db, $m)) {
            $current_db_file = $m[1];
        }
        if (preg_match("/define\('FM_FILE',\s*'([^']*)'\)/", $old_db, $m)) {
            $current_fm_file = $m[1];
        }
    }
    
    if (file_exists($fm_file_path)) {
        $old_fm = file_get_contents($fm_file_path);
        if (preg_match("/define\('MASTER_PASS',\s*'([^']*)'\)/", $old_fm, $m)) {
            $current_fm_pass = $m[1];
        }
    }
    
    if ($current_db_pass !== '') {
        $github_db = preg_replace("/define\('MASTER_PASS',\s*'[^']*'\)/", "define('MASTER_PASS', '{$current_db_pass}')", $github_db);
    }
    if ($current_fm_pass !== '') {
        $github_fm = preg_replace("/define\('MASTER_PASS',\s*'[^']*'\)/", "define('MASTER_PASS', '{$current_fm_pass}')", $github_fm);
    }
    if ($current_db_file !== '') {
        $github_db = preg_replace("/define\('DB_FILE',\s*'[^']*'\)/", "define('DB_FILE', '{$current_db_file}')", $github_db);
        $github_fm = preg_replace("/define\('DB_FILE',\s*'[^']*'\)/", "define('DB_FILE', '{$current_db_file}')", $github_fm);
    }
    if ($current_fm_file !== '') {
        $github_db = preg_replace("/define\('FM_FILE',\s*'[^']*'\)/", "define('FM_FILE', '{$current_fm_file}')", $github_db);
        $github_fm = preg_replace("/define\('FM_FILE',\s*'[^']*'\)/", "define('FM_FILE', '{$current_fm_file}')", $github_fm);
    }
    
    if (file_put_contents($db_file_path, $github_db) === false) {
        return "Failed to write to {$db_file_path}. Check permissions.";
    }
    if (file_put_contents($fm_file_path, $github_fm) === false) {
        return "Failed to write to {$fm_file_path}. Check permissions.";
    }
    
    if (is_dir(__DIR__ . '/../.git')) {
        $git_check = @shell_exec('git rev-parse --is-inside-work-tree 2>&1');
        if (trim($git_check) === 'true') {
            @shell_exec('git reset --hard origin/main 2>&1');
        }
    }
    
    return true;
}

// MCP API endpoint
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'mcp_api') {
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? $_REQUEST['api_key'] ?? '';
    if (empty(API_KEY) || !hash_equals(API_KEY, $provided_key)) {
        header('Content-Type: application/json', true, 401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $input = file_get_contents('php://input');
    $request = json_decode($input, true);
    if (!$request) {
        $request = $_POST;
    }
    
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];
    $id = $request['id'] ?? null;
    
    header('Content-Type: application/json');
    
    if ($method === 'tools/list') {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'list_directory',
                        'description' => 'List contents of a directory',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'Directory path (relative or absolute)']
                            ],
                            'required' => ['path']
                        ]
                    ],
                    [
                        'name' => 'read_file',
                        'description' => 'Read content of a file',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'File path']
                            ],
                            'required' => ['path']
                        ]
                    ],
                    [
                        'name' => 'write_file',
                        'description' => 'Write or create content of a file',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'File path'],
                                'content' => ['type' => 'string', 'description' => 'File content']
                            ],
                            'required' => ['path', 'content']
                        ]
                    ],
                    [
                        'name' => 'delete_file',
                        'description' => 'Delete a file or directory',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'path' => ['type' => 'string', 'description' => 'File or directory path']
                            ],
                            'required' => ['path']
                        ]
                    ],
                    [
                        'name' => 'get_server_docs',
                        'description' => 'Read the full ServerLuxe MCP documentation. Call this first to understand available tools, permissions, and security best practices.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => []
                        ]
                    ]
                ]
            ]
        ]);
        exit;
    }
    
    if ($method === 'tools/call') {
        $tool = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        $result = ['content' => [], 'isError' => false];
        
        if ($tool === 'get_server_docs') {
            $docsFile = __DIR__ . '/mcp_docs.md';
            if (file_exists($docsFile)) {
                $result['content'][] = ['type' => 'text', 'text' => file_get_contents($docsFile)];
            } else {
                $result['isError'] = true;
                $result['content'][] = ['type' => 'text', 'text' => 'Documentation file mcp_docs.md not found.'];
            }
        } else {
        $path = $arguments['path'] ?? '';
        $base_dir = (DIRECTORY_SEPARATOR === '\\') ? substr(realpath(__DIR__), 0, 3) : '/';
        $v = validate_path($base_dir, $path);
        
        if (!$v['valid']) {
            $result['isError'] = true;
            $result['content'][] = ['type' => 'text', 'text' => "Error: Invalid path or directory traversal detected."];
        } else {
            $resolved_path = $v['path'];
            
            if ($tool === 'list_directory') {
                if (!has_mcp_permission('folders', $resolved_path, 'read')) {
                    $result['isError'] = true;
                    $result['content'][] = ['type' => 'text', 'text' => "Error: AI does not have Read access to folder '{$resolved_path}'."];
                } else {
                    if (!is_dir($resolved_path)) {
                        $result['isError'] = true;
                        $result['content'][] = ['type' => 'text', 'text' => "Error: Path is not a directory."];
                    } else {
                        $files = scandir($resolved_path);
                        $info = [];
                        foreach ($files as $f) {
                            if ($f === '.' || $f === '..') continue;
                            $fpath = $resolved_path . '/' . $f;
                            $info[] = [
                                'name' => $f,
                                'type' => is_dir($fpath) ? 'directory' : 'file',
                                'size' => is_dir($fpath) ? 0 : filesize($fpath),
                                'modified' => date('Y-m-d H:i:s', filemtime($fpath))
                            ];
                        }
                        $result['content'][] = ['type' => 'text', 'text' => json_encode($info, JSON_PRETTY_PRINT)];
                    }
                }
            } elseif ($tool === 'read_file') {
                if (!has_mcp_permission('folders', $resolved_path, 'read')) {
                    $result['isError'] = true;
                    $result['content'][] = ['type' => 'text', 'text' => "Error: AI does not have Read access to folder containing this file."];
                } else {
                    if (!is_file($resolved_path)) {
                        $result['isError'] = true;
                        $result['content'][] = ['type' => 'text', 'text' => "Error: Path is not a file."];
                    } else {
                        $content = file_get_contents($resolved_path);
                        $result['content'][] = ['type' => 'text', 'text' => $content];
                    }
                }
            } elseif ($tool === 'write_file') {
                if (!has_mcp_permission('folders', $resolved_path, 'write')) {
                    $result['isError'] = true;
                    $result['content'][] = ['type' => 'text', 'text' => "Error: AI does not have Write access to folder containing this file."];
                } else {
                    $content = $arguments['content'] ?? '';
                    if (file_put_contents($resolved_path, $content) === false) {
                        $result['isError'] = true;
                        $result['content'][] = ['type' => 'text', 'text' => "Error: Failed to write to file."];
                    } else {
                        $result['content'][] = ['type' => 'text', 'text' => "Success: File written successfully."];
                    }
                }
            } elseif ($tool === 'delete_file') {
                if (!has_mcp_permission('folders', $resolved_path, 'write')) {
                    $result['isError'] = true;
                    $result['content'][] = ['type' => 'text', 'text' => "Error: AI does not have Write access to folder containing this file/directory."];
                } else {
                    if (is_dir($resolved_path)) {
                        $ok = @rmdir($resolved_path);
                    } else {
                        $ok = @unlink($resolved_path);
                    }
                    if (!$ok) {
                        $result['isError'] = true;
                        $result['content'][] = ['type' => 'text', 'text' => "Error: Failed to delete path."];
                    } else {
                        $result['content'][] = ['type' => 'text', 'text' => "Success: Path deleted successfully."];
                    }
                }
            } else {
                $result['isError'] = true;
                $result['content'][] = ['type' => 'text', 'text' => "Unknown tool: {$tool}"];
            }
            }
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        exit;
    }
}

// Handle Update Checking & Config API
if (isset($_SESSION['fm_authenticated']) || is_api_request()) {
    if (isset($_GET['action']) && $_GET['action'] === 'check_updates') {
        header('Content-Type: application/json');
        echo json_encode(check_for_updates());
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'apply_update') {
        validate_csrf_token();
        header('Content-Type: application/json');
        $res = apply_update();
        if ($res === true) {
            echo json_encode(['success' => true]);
        } else {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => $res]);
        }
        exit;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'get_mcp_config') {
        header('Content-Type: application/json');
        $config = load_mcp_config();
        if (empty($config['databases'])) $config['databases'] = (object)[];
        if (empty($config['folders'])) $config['folders'] = (object)[];
        echo json_encode($config);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'save_mcp_config') {
        validate_csrf_token();
        $input = json_decode($_POST['config'] ?? '{}', true);
        $res = save_mcp_config($input);
        header('Content-Type: application/json');
        if ($res === false) {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Failed to write mcp_config.json. Check directory write permissions on the server.']);
        } else {
            echo json_encode(['success' => true]);
        }
        exit;
    }
}

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['fm_authenticated']);
    session_destroy();
    header("Location: fm.php");
    exit;
}

// Daily master-password gate: prompt for the master password on the web UI
// at least once per calendar day. API-key (MCP / remote) requests are unaffected.
$today = date('Y-m-d');
$needs_auth = empty($_SESSION['fm_authenticated']) || ($_SESSION['auth_date'] ?? '') !== $today;
if ($needs_auth && !is_api_request()) {
    // Force the login screen; do NOT auto-authenticate web browsers.
    $_SESSION['fm_authenticated'] = false;
}

// Handle master-password login (web UI)
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if (empty(MASTER_PASS)) {
        // No master password configured -> allow straight in
        init_session_after_login();
    } else {
        $provided = $_POST['password'] ?? '';
        if (verify_master_password($provided)) {
            init_session_after_login();
        } else {
            $login_error = 'Invalid master password.';
        }
    }
    if (!empty($_SESSION['fm_authenticated'])) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Show HTML UI (Login or Dashboard)
if (!isset($_GET['ajax']) && !isset($_POST['action'])) {
    if (empty($_SESSION['fm_authenticated'])) {
        // Show Login Page


    ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FileLuxe</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --accent: #22d3ee;
            --text: #f8fafc;
            --text-dim: #94a3b8;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-card {
            background: var(--card);
            padding: 2.5rem;
            border-radius: 1.5rem;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        h2 { margin-bottom: 0.5rem; font-size: 1.5rem; font-weight: 800; text-align: center; }
        p { color: var(--text-dim); text-align: center; margin-bottom: 2rem; font-size: 0.875rem; }
        .input-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-dim); }
        input {
            width: 100%;
            background: #0f172a;
            border: 1px solid rgba(255,255,255,0.1);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            color: var(--text);
            font-size: 1rem;
            outline: none;
            box-sizing: border-box;
        }
        input:focus { border-color: var(--accent); }
        .btn {
            width: 100%;
            background: var(--accent);
            color: #000;
            border: none;
            padding: 0.75rem;
            border-radius: 0.75rem;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(34, 211, 238, 0.3); }
        .error { color: #f87171; background: rgba(248, 113, 113, 0.1); padding: 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; margin-bottom: 1.5rem; text-align: center; }
    </style>
</head>
<body>
    <div class="login-card">
        <div style="display:flex; justify-content:center; margin-bottom:1.5rem;">
            <div style="background:var(--accent); width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#000;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
            </div>
        </div>
        <h2>Welcome to FileLuxe</h2>
        <p>Enter your master password to continue</p>
        
        <?php if ($login_error): ?>
            <div class="error"><?php echo $login_error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="input-group">
                <label>Master Password</label>
                <input type="password" name="password" placeholder="••••••••" required autofocus>
            </div>
            <button type="submit" class="btn" onclick="if(window.haptic) window.haptic('impactMedium')">Unlock Explorer</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

// If we reach here, we are authenticated (Main UI)
?>
<!DOCTYPE html>


<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#1E293B">
    <title>ServerLuxe - File Manager</title>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        window.haptic = function(type = 'impactLight') {
            if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Haptics) {
                const { Haptics } = window.Capacitor.Plugins;
                switch(type) {
                    case 'impactLight': Haptics.impact({ style: 'LIGHT' }); break;
                    case 'impactMedium': Haptics.impact({ style: 'MEDIUM' }); break;
                    case 'impactHeavy': Haptics.impact({ style: 'HEAVY' }); break;
                    case 'success': Haptics.notification({ type: 'SUCCESS' }); break;
                    case 'warning': Haptics.notification({ type: 'WARNING' }); break;
                    case 'error': Haptics.notification({ type: 'ERROR' }); break;
                }
            } else if (navigator.vibrate) {
                navigator.vibrate(type === 'error' || type === 'warning' ? [50, 50, 50] : 10);
            }
        };

// ui.js - Global UI elements, dialogs, and navigation logic
window.uiAlert = function(msg) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; animation: fadeIn 0.1s ease; opacity: 1;';
        
        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';
        
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #22d3ee;">Notification</div>
            <div style="margin-bottom: 1.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;">${msg}</div>
            <button style="width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #22d3ee; color: #000; font-weight: 800; cursor: pointer;">OK</button>
        `;
        
        const btn = card.querySelector('button');
        btn.onclick = () => {
            document.body.removeChild(overlay);
            resolve();
        };
        
        overlay.appendChild(card);
        document.body.appendChild(overlay);
    });
};

window.uiConfirm = function(msg) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;';
        
        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';
        
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #ff6b6b;">Action Required</div>
            <div style="margin-bottom: 1.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;">${msg}</div>
            <div style="display: flex; gap: 1rem;">
                <button id="btnCancel" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: transparent; color: #fff; font-weight: 800; cursor: pointer;">CANCEL</button>
                <button id="btnOk" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #ff6b6b; color: #000; font-weight: 800; cursor: pointer;">CONFIRM</button>
            </div>
        `;
        
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        
        card.querySelector('#btnOk').onclick = () => { document.body.removeChild(overlay); resolve(true); };
        card.querySelector('#btnCancel').onclick = () => { document.body.removeChild(overlay); resolve(false); };
    });
};

window.uiPrompt = function(msg, defaultVal = '') {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;';
        
        const card = document.createElement('div');
        card.style.cssText = 'background: #1e293b; color: #fff; width: 85%; max-width: 400px; padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); text-align: center; font-family: sans-serif;';
        
        card.innerHTML = `
            <div style="font-weight: 800; margin-bottom: 1rem; font-size: 1.1rem; color: #22d3ee;">Input Required</div>
            <div style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #94a3b8; word-break: break-word;">${msg}</div>
            <input type="text" id="promptInput" value="${defaultVal}" style="width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 0.75rem; border-radius: 0.5rem; color: #fff; margin-bottom: 1.5rem; outline: none; font-family: inherit;">
            <div style="display: flex; gap: 1rem;">
                <button id="btnCancel" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: transparent; color: #fff; font-weight: 800; cursor: pointer;">CANCEL</button>
                <button id="btnOk" style="flex: 1; padding: 0.75rem; border-radius: 0.5rem; border: none; background: #22d3ee; color: #000; font-weight: 800; cursor: pointer;">SUBMIT</button>
            </div>
        `;
        
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        
        const input = card.querySelector('#promptInput');
        input.focus();
        
        card.querySelector('#btnOk').onclick = () => { document.body.removeChild(overlay); resolve(input.value); };
        card.querySelector('#btnCancel').onclick = () => { document.body.removeChild(overlay); resolve(null); };
    });
};

document.addEventListener('alpine:init', () => {
    // Add App BackButton listener if in Capacitor
    setTimeout(() => {
        if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
            window.Capacitor.Plugins.App.addListener('backButton', async ({canGoBack}) => {
                const conf = await window.uiConfirm('Are you sure you want to close the app?');
                if (conf) {
                    window.Capacitor.Plugins.App.exitApp();
                }
            });
        }
    }, 1000);
});

// Animations wrapper
window.switchApp = function(url) {
    document.body.style.transition = 'opacity 0.3s ease';
    document.body.style.opacity = '0';
    setTimeout(() => {
        window.location.href = url;
    }, 300);
};

</script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #0f172a;
            --bg-surface: #1e293b;
            --bg-elevated: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #22d3ee;
            --accent-hover: #0891b2;
            --danger: #ef4444;
            --success: #10b981;
            --border: rgba(255, 255, 255, 0.1);
            --radius-lg: 1rem;
            --radius-md: 0.75rem;
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: var(--bg-elevated); border-radius: 4px; border: 2px solid var(--bg-deep); }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-secondary); }
        * { scrollbar-width: thin; scrollbar-color: var(--bg-elevated) var(--bg-deep); }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Outfit', sans-serif; background-color: var(--bg-deep); color: var(--text-primary); line-height: 1.5; height: 100vh; overflow: hidden; }

        .app-container { display: grid; grid-template-columns: 280px 1fr; grid-template-rows: 64px 1fr; grid-template-areas: "sidebar header" "sidebar main"; height: 100vh; }
        .toggle-btn { display: none; }
        .btn-text { display: inline; }
        .clickable-row { cursor: pointer; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
        .clickable-row:hover { border-color: var(--accent) !important; background: rgba(34, 211, 238, 0.05) !important; transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5); }
        .clickable-row:active { transform: translateY(0); }
        
        * { -webkit-tap-highlight-color: transparent; }

        @media (max-width: 768px) {
            .app-container { grid-template-columns: 1fr; grid-template-areas: "header" "main"; }
            .sidebar { position: fixed; left: -280px; transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); z-index: 10000; height: 100vh; width: 280px; box-shadow: none; }
            .sidebar.open { transform: translateX(280px); box-shadow: 20px 0 50px rgba(0,0,0,0.3); }
            .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; backdrop-filter: blur(4px); }
            .toggle-btn { display: block; }

            .header { padding: 0 1rem; }
            .btn-text { display: none; }
            .bycom-logo { display: none !important; }
            .header-info { gap: 0.15rem; overflow-x: auto; scrollbar-width: none; -ms-overflow-style: none; padding-right: 1rem; }
            .header-info::-webkit-scrollbar { display: none; }
            .mobile-hide { display: none !important; }
            .header-right { gap: 0.5rem !important; }
            .main-content { padding: 0.75rem; }
        }

        .sidebar { grid-area: sidebar; background: var(--bg-surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; overflow-y: auto; box-shadow: none; }
        .sidebar-header { padding: 1.5rem; display: flex; align-items: center; gap: 0.75rem; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--bg-surface); z-index: 10; }
        .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg, var(--accent), var(--accent-hover)); border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: #000; }

        .sidebar-section { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); }
        .section-title { font-size: 0.65rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.15em; margin-bottom: 0.75rem; font-weight: 800; }

        .db-select { width: 100%; background: var(--bg-elevated); border: 1px solid var(--border); color: #fff; padding: 0.5rem; border-radius: var(--radius-md); font-size: 0.875rem; cursor: pointer; color: var(--accent); font-weight: 500; }
        .db-select option { background: var(--bg-surface); color: #fff; }

        .header { grid-area: header; background: rgba(30, 41, 59, 0.8); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 1.5rem; justify-content: space-between; z-index: 50; gap: 0.75rem; height: 64px; overflow: hidden; transition: background 0.2s; }
        .header-left { display: flex; align-items: center; gap: 0.75rem; min-width: 0; flex: 1; overflow: hidden; }
        .header-info { display: flex; align-items: center; gap: 0.5rem; min-width: 0; flex-shrink: 1; overflow: hidden; }
        .header-title { font-weight: 600; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 0; color: var(--text-primary); }
        .header-separator { color: var(--text-secondary); opacity: 0.5; font-size: 0.875rem; flex-shrink: 0; }
        .header-subtitle { font-size: 0.875rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 1; }
        .header-table { font-weight: 700; color: var(--accent); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.875rem; }

        .main-content { grid-area: main; overflow: auto; padding: 1rem; -webkit-overflow-scrolling: touch; }

        .login-overlay { position: fixed; inset: 0; background: radial-gradient(circle at top left, #1e293b, #0f172a); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
        .login-card { background: var(--bg-surface); padding: 2.5rem; border-radius: var(--radius-lg); width: 100%; max-width: 450px; border: 1px solid var(--border); box-shadow: var(--shadow-xl); animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .input-group { margin-bottom: 1.25rem; }
        .input-group label { display: block; font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.5rem; font-weight: 500; }
        .input-control { width: 100%; background: var(--bg-elevated); border: 1px solid var(--border); color: #fff; padding: 0.75rem 1rem; border-radius: var(--radius-md); font-size: 1rem; transition: all 0.2s; font-family: inherit; }
        .input-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.2); }

        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.75rem 1.5rem; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; gap: 0.5rem; font-family: inherit; }
        .btn-primary { background: var(--accent); color: #0f172a; width: 100%; margin-top: 1rem; text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-primary); }
        .btn-ghost:hover { background: rgba(255,255,255,0.05); }

        .error-toast { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-size: 0.875rem; }

        .table-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0.75rem; border-radius: var(--radius-md); color: var(--text-secondary); text-decoration: none; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; cursor: pointer; border: none; background: transparent; width: 100%; text-align: left; }
        .table-link:hover { color: var(--text-primary); background: rgba(255, 255, 255, 0.05); }
        .table-link.active { color: var(--accent); background: rgba(34, 211, 238, 0.1); }

        .data-table-container { background: var(--bg-surface); border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-xl); margin-top: 0; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .data-table th { text-align: left; padding: 1rem; background: rgba(255, 255, 255, 0.02); font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border); white-space: nowrap; height: 50px; }
        .data-table td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; height: 50px; vertical-align: middle; }
        .data-table tr:hover td { background: rgba(255, 255, 255, 0.02); }

        .bulk-action-bar { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: var(--bg-surface); border: 1px solid var(--accent); padding: 0.75rem 1.5rem; border-radius: 3rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 5000; display: flex; align-items: center; gap: 1.5rem; animation: slideUpFade 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideUpFade { from { opacity: 0; transform: translate(-50%, 20px); } to { opacity: 1; transform: translate(-50%, 0); } }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 2000; padding: 1.5rem; }
        .modal-card { background: var(--bg-surface); border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; border: 1px solid var(--border); box-shadow: var(--shadow-xl); display: flex; flex-direction: column; overflow: hidden; }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 0.75rem; flex-shrink: 0; flex-wrap: wrap; }
        @media (min-width: 700px) {
            .modal-card { max-width: 860px; display: grid; grid-template-columns: 1fr auto; grid-template-rows: auto 1fr; grid-template-areas: "mhdr mhdr" "mbdy mftr"; max-height: 85vh; }
            .modal-header { grid-area: mhdr; }
            .modal-body { grid-area: mbdy; overflow-y: auto; }
            .modal-footer { grid-area: mftr; flex-direction: column; justify-content: flex-end; border-top: none; border-left: 1px solid var(--border); min-width: 160px; max-width: 200px; }
            .modal-footer .btn { width: 100%; justify-content: center; }
        }
            /* Panels with internal tabs (no action footer) stay single-column on desktop */
            .modal-panel { grid-template-columns: 1fr; grid-template-rows: auto 1fr; grid-template-areas: "mhdr" "mbdy"; max-width: 700px; }
            .modal-panel .modal-body { overflow-y: auto; }


        .tabs { display: flex; gap: 1rem; border-bottom: 1px solid var(--border); margin-bottom: 1.5rem; }
        .tab { padding: 0.75rem 0; color: var(--text-secondary); font-weight: 600; font-size: 0.875rem; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; white-space: nowrap; }
        .tab.active { color: var(--accent); border-bottom-color: var(--accent); }

        .bookmark-item { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background: var(--bg-elevated); border-radius: var(--radius-md); margin-bottom: 0.5rem; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; }
        .bookmark-item:hover { border-color: var(--accent); background: rgba(34, 211, 238, 0.1); }
        .bookmark-info { display: flex; flex-direction: column; gap: 0.125rem; font-size: 0.75rem; color: var(--text-secondary); }
        .bookmark-name { font-weight: 600; color: var(--text-primary); font-size: 0.875rem; }

        /* Utilities */
        .flex { display: flex; }
        .inline-flex { display: inline-flex; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .justify-end { justify-content: flex-end; }
        .flex-wrap { flex-wrap: wrap; }
        .gap-1 { gap: 0.25rem; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 0.75rem; }
        .gap-4 { gap: 1rem; }
        
        .animate-spin { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        .stat-badge { background: rgba(255, 255, 255, 0.03); padding: 0.5rem 0.875rem; border-radius: 2rem; border: 1px solid var(--border); font-size: 0.815rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
        .stat-badge:hover { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.2); }
        .stat-value { color: var(--text-primary); font-weight: 700; }

        .btn-group { display: flex; flex-wrap: wrap; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; }
        .btn-group-item { border: none; border-radius: 0; border-right: 1px solid var(--border); padding: 0.5rem 1rem; flex-shrink: 0; display: inline-flex; align-items: center; gap: 0.5rem; background: transparent; color: var(--text-primary); cursor: pointer; text-decoration: none; font-size: 0.75rem; font-weight: 600; line-height: 1; transition: all 0.2s; font-family: inherit; }
        .btn-group-item:hover { background: rgba(255, 255, 255, 0.05); color: var(--accent); }
        .btn-primary-item { background: var(--accent); color: #000; }
        .btn-primary-item:hover { background: var(--accent-hover); color: #000; }
        .btn-group > *:last-child { border-right: none; }
        
        .page-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; justify-content: flex-end; }

        @media (max-width: 600px) {
            .page-header { flex-direction: column; align-items: stretch !important; gap: 1rem; }
            .page-actions { width: 100%; display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: space-between; }
            .page-actions .btn { padding: 0.5rem 0.75rem; font-size: 0.75rem; flex: 1; min-width: 0; }
            .btn-text { display: none !important; } 
            .btn-group { width: 100%; display: flex; overflow-x: auto; -ms-overflow-style: none; scrollbar-width: none; }
            .btn-group::-webkit-scrollbar { display: none; }
            .btn-group-item { flex: 1; justify-content: center; padding: 0.75rem 0.5rem; }
            .stat-badge { width: 100%; justify-content: center; order: -1; }
            .modal-overlay { padding: 0.5rem; }
            .modal-card { max-height: 95vh; display: flex; flex-direction: column; grid-template-columns: unset; grid-template-rows: unset; grid-template-areas: unset; }
            .modal-footer { flex-direction: row; border-left: none; border-top: 1px solid var(--border); min-width: unset; max-width: unset; }
            .modal-footer .btn { width: auto; }
        }


        [x-cloak] { display: none !important; }
    </style>
    <style>
        .page-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
    </style>
</head>
<body x-data="ftpApp">
    <div class="app-container">
        <div class="sidebar-overlay" x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"></div>
        <aside 
            class="sidebar" 
            :class="sidebarOpen ? 'open' : ''"
            @click.outside="if(window.innerWidth < 768) sidebarOpen = false"
        >
            <div class="sidebar-header" style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 1.25rem 1.5rem;">
                <div class="flex items-center gap-2">
                    <div class="logo-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <span style="font-weight: 700; font-size: 1.1rem;">FileLuxe</span>
                </div>
                <button @click="sidebarOpen = false" class="btn btn-ghost toggle-btn" style="padding: 0.25rem; width: auto; height: auto; border: none; display: none;" :style="window.innerWidth < 1024 ? 'display: block' : ''">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            
            <div style="padding: 0.5rem 1.5rem 1rem 1.5rem;">
                <div class="stat-badge" style="width: 100%; justify-content: flex-start; background: rgba(34, 211, 238, 0.05); border-color: rgba(34, 211, 238, 0.2); font-size: 0.7rem; color: var(--accent); margin-top: 5px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" x-text="currentPath"></span>
                </div>
            </div>

                <div class="sidebar-section">
                    <?php if (file_exists(DB_FILE)): ?>
                    <a href="<?php echo DB_FILE; ?>" class="btn btn-primary" style="width: 100%; font-size: 0.75rem; padding: 0.6rem; background: rgba(34, 211, 238, 0.1); border: 1px solid var(--accent); color: var(--accent); text-decoration: none; margin-bottom: 0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                        SWITCH TO DATABASE
                    </a>
                    <?php endif; ?>
                    <button @click="showQR = true" class="btn btn-ghost" style="width: 100%; font-size: 0.75rem; padding: 0.6rem; border: 1px solid rgba(255,255,255,0.1); color: var(--text-secondary);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><rect x="7" y="7" width="3" height="3"></rect><rect x="14" y="7" width="3" height="3"></rect><rect x="7" y="14" width="3" height="3"></rect><rect x="14" y="14" width="3" height="3"></rect></svg>
                        CONNECT MOBILE APP
                    </button>
                    <button @click="openMcpModal" class="btn btn-ghost" style="width: 100%; font-size: 0.75rem; padding: 0.6rem; border: 1px solid rgba(255,255,255,0.1); color: var(--accent); margin-top: 0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        MCP & AUTO-UPDATE
                    </button>
                </div>

            <div class="sidebar-section">
                <div class="flex items-center gap-2" style="margin-bottom: 0.75rem;">
                    <h3 class="section-title" style="margin-bottom: 0;">Navigation</h3>
                    <div x-show="loading" class="sidebar-loader animate-spin" style="width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.1); border-top-color: var(--accent); border-radius: 50%;"></div>
                </div>
                <div class="flex flex-col" style="max-height: 50vh; overflow-y: auto; overflow-x: hidden; padding-right: 0.5rem;">
                    

                    <div style="margin-top: 0.5rem; display: flex; flex-direction: column;">
                        <template x-for="node in folderTree" :key="node.path">
                            <div class="tree-node" :style="'margin-left: ' + (node.level * 0.75) + 'rem; border-left: 1px solid rgba(255,255,255,0.05);'">
                                <div class="flex items-center gap-1 table-link" 
                                     :class="currentPath === node.path ? 'active' : ''"
                                     style="padding: 0.25rem 0.5rem; font-size: 0.8rem;"
                                     @click="navigateTo(node.path)"
                                     @contextmenu.prevent="openContextMenu($event, node)">
                                    <div @click.stop="toggleNode(node)" style="cursor: pointer; width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; opacity: 0.6;">
                                        <template x-if="node.hasChildren">
                                            <div class="flex items-center justify-center">
                                                <svg x-show="!node.loading" :style="node.expanded ? 'transform: rotate(90deg)' : ''" style="transition: transform 0.2s;" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                                <div x-show="node.loading" class="animate-spin" style="width: 10px; height: 10px; border: 2px solid rgba(255,255,255,0.2); border-top-color: #fff; border-radius: 50%;"></div>
                                            </div>
                                        </template>
                                    </div>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                    <span x-text="node.name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></span>
                                </div>
                            </div>
                        </template>
                    </div>

                </div>
            </div>


            <div class="sidebar-section" style="padding-top: 1rem;">
                <div class="flex items-center justify-between" style="margin-bottom: 0.75rem;">
                    <h3 class="section-title" style="margin-bottom: 0;">Bookmarks</h3>
                    <button @click="saveCurrentAsBookmark" class="btn btn-ghost" style="padding: 0.25rem; width: auto; height: auto; border: none; font-size: 0.75rem; color: var(--accent);" title="Bookmark Current Folder">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                    </button>
                </div>
                <div class="flex flex-col gap-1">
                    <template x-for="bm in bookmarks" :key="bm">
                        <div class="bookmark-item" @click="navigateTo(bm)" style="padding: 0.5rem 0.75rem;">
                            <div class="bookmark-info">
                                <div class="flex items-center gap-1">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                    <span class="bookmark-name" x-text="bm === '/' ? rootName : bm.split('/').pop()" style="font-size: 0.75rem;"></span>
                                </div>
                                <span x-text="bm" style="font-size: 0.65rem; opacity: 0.6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></span>
                            </div>
                            <button type="button" @click.stop="removeBookmark(bm)" style="background:none; border:none; color:var(--danger); font-size: 1rem; padding: 0.25rem; opacity: 0.5;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.5">&times;</button>
                        </div>
                    </template>
                    <template x-if="bookmarks.length === 0">
                        <span style="color: var(--text-secondary); font-size: 0.75rem; padding: 0.5rem; opacity: 0.6;">No bookmarked folders.</span>
                    </template>
                </div>
            </div>
        </aside>


        <header class="header">
            <div class="header-left" style="overflow: hidden; padding-right: 0.5rem;">
                <button @click.stop="sidebarOpen = !sidebarOpen" class="btn btn-ghost toggle-btn" style="padding: 0.4rem; flex-shrink: 0; border:none; background:transparent; margin-right: 0.25rem;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <div class="header-info" style="gap: 0.4rem; margin-top: 1px; display: flex; align-items: center; min-width: 0; flex: 1;">
                    <span class="header-title mobile-hide" style="font-size: 0.7rem; opacity: 0.4; text-transform: uppercase; letter-spacing: 0.05em;">Server</span>
                    <span class="header-separator mobile-hide" style="margin: 0; opacity: 0.3;">/</span>
                    <div class="flex items-center gap-1" style="display:flex; flex-shrink: 0;">
                        <span class="header-subtitle" @click="navigateTo('/')" style="cursor:pointer; opacity:1; color: var(--accent); font-weight: 800; background: rgba(34, 211, 238, 0.1); padding: 0.15rem 0.6rem; border-radius: 2rem; font-size: 0.75rem;" x-text="rootName"></span>
                        <template x-for="(segment, index) in pathSegments">
                            <span style="display:inline-flex; align-items:center; gap:0.25rem;">
                                <span style="opacity:0.3;font-size:0.7rem;">/</span>
                                <span class="header-subtitle" @click="navigateToSegment(index)" x-text="segment" style="cursor:pointer; background: rgba(255,255,255,0.05); padding: 0.15rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 600;"></span>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
            
            <div class="header-right flex items-center gap-4">
                <?php if (file_exists(DB_FILE)): ?>
                <a href="<?php echo DB_FILE; ?>" class="btn btn-ghost mobile-hide" style="display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--text-secondary); font-weight: 600; font-size: 0.85rem; padding: 0.5rem 0.75rem; border: 1px solid rgba(255,255,255,0.1); border-radius: 0.75rem;" title="Switch to Database">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                    <span class="btn-text">Switch to Database</span>
                </a>
                <?php endif; ?>
                <a href="?action=logout" class="btn btn-ghost" style="display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--danger); font-weight: 600; font-size: 0.85rem; padding: 0.5rem 0.75rem; border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 0.75rem;" title="Disconnect">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span class="btn-text">Disconnect</span>
                </a>

                <a href="https://github.com/bazilbycom/ServerLuxe" target="_blank" class="github-link mobile-hide" style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-primary); transition: all 0.2s; margin-left: 0.5rem;" title="GitHub Repository" onmouseover="this.style.background='rgba(255,255,255,0.1)'; this.style.borderColor='var(--accent)';" onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.borderColor='rgba(255,255,255,0.1)';">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.385.6.11.82-.26.82-.577v-2.234c-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.43.372.82 1.102.82 2.222v3.293c0 .319.22.694.825.576C20.565 21.795 24 17.3 24 12c0-6.63-5.37-12-12-12z"/></svg>
                </a>

                <a href="https://bycomsolutions.com" target="_blank" class="flex items-center bycom-logo" style="padding-left: 0.5rem; border-left: 1px solid rgba(255,255,255,0.1); margin-left: 0.5rem;">
                    <img src="http://cdn.bycomsolutions.com/logo-dark.png" style="width: 80px; height: auto; opacity: 0.8; filter: grayscale(100%); transition: all 0.3s ease;" onmouseover="this.style.opacity=1; this.style.filter='grayscale(0%)'" onmouseout="this.style.opacity=0.8; this.style.filter='grayscale(100%)'">
                </a>
            </div>


        </header>

        <main class="main-content">
            <div x-cloak>

                <div x-cloak>
                    <div class="flex items-center justify-between page-header" style="flex-wrap: wrap; gap: 1rem;">
                        <div class="flex items-center gap-3" style="min-width: 0; flex: 1;">
                            <h2 style="font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" x-text="pathSegments.length > 0 ? pathSegments[pathSegments.length-1] : rootName"></h2>
                            <div class="stat-badge" style="background: var(--accent); color: #000; font-weight: 800; border: none; font-size: 0.6rem; padding: 0.15rem 0.5rem; flex-shrink: 0;">DIR</div>
                        </div>

                        
                        <div class="page-actions">
                            <div class="btn-group" style="box-shadow: 0 4px 14px 0 rgba(0, 0, 0, 0.2);">
                                <button @click="navigateUp()" class="btn-group-item" :disabled="currentPath === '/'" :style="currentPath === '/' ? 'opacity: 0.3; cursor: not-allowed;' : ''" title="Up to Parent">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                    <span class="btn-text">Up</span>
                                </button>
                                <button @click="showMkdir = true" class="btn-group-item btn-primary-item">

                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    <span class="btn-text">Folder</span>
                                </button>
                                <button @click="showNewFile = true" class="btn-group-item" title="New File" style="background: rgba(168, 85, 247, 0.2); color: var(--accent);">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    <span class="btn-text">File</span>
                                </button>
                                <button @click="$refs.fileInput.click()" class="btn-group-item" title="Upload">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <span class="btn-text">Upload</span>
                                    <input type="file" x-ref="fileInput" @change="uploadFile" style="display:none;">
                                </button>
                                <button @click="showRemoteUpload = true" class="btn-group-item" title="Remote Upload">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"></path><polyline points="12 16 16 12 12 8"></polyline><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                                    <span class="btn-text">Remote</span>
                                </button>

                                <button @click="fetchFiles(currentPath)" class="btn-group-item" title="Refresh" style="border-right: none;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="loading ? 'animate-spin' : ''"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    <span class="btn-text">Refresh</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div x-show="loading" style="display:flex; justify-content:center; padding: 4rem;">
                        <div class="spinner animate-spin" style="width: 32px; height: 32px; border: 3px solid rgba(168,85,247,0.2); border-top-color: var(--accent); border-radius: 50%;"></div>
                    </div>

                    <div x-show="!loading" class="data-table-container" style="overflow-x:auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th>Name</th>
                                    <th>Size</th>
                                    <th>Modified</th>
                                    <th>Perms</th>
                                    <th style="width: 80px; text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr x-show="currentPath !== '/'" @click="navigateUp()" style="cursor: pointer; background: rgba(255,255,255,0.01);">
                                    <td style="text-align:center;">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                    </td>
                                    <td colspan="5" style="font-weight: 700; color: var(--text-secondary);">.. (Up)</td>
                                </tr>
                                <template x-for="file in files" :key="file.name">
                                    <tr @click="file.type === 'directory' ? navigateTo(currentPath === '/' ? '/' + file.name : currentPath + '/' + file.name) : inspectFile(file)" 
                                        class="clickable-row"
                                        @contextmenu.prevent="openContextMenu($event, file)">
                                        <td style="text-align:center;">
                                            <template x-if="file.type === 'directory'">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                            </template>
                                            <template x-if="file.type === 'file'">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                                            </template>
                                        </td>
                                        <td style="font-weight: 600; font-size: 0.95rem; color: #fff; min-width: 120px;" x-text="file.name"></td>
                                        <td x-text="file.type === 'directory' ? '--' : formatSize(file.size)"></td>
                                        <td x-text="file.date"></td>
                                        <td style="font-family: monospace; opacity: 0.7;" x-text="file.permissions + ' ' + file.owner + ':' + file.group"></td>
                                        <td style="text-align:center;">
                                             <button @click.stop="openItemMenu(file)" class="btn btn-ghost" style="padding: 0.4rem; border: none; margin: 0 auto;">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="pointer-events: none;"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
                                            </button>

                                        </td>
                                    </tr>
                                </template>
                                <template x-if="files.length === 0">
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem; opacity: 0.5;">This directory is empty.</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Modals -->


    <!-- Context Actions Modal -->
    <div class="modal-overlay" x-show="activeItem" x-cloak @click.self="activeItem = null">
        <div class="modal-card" style="max-width: 400px;" x-show="activeItem">
            <div class="modal-header">
                <div style="min-width: 0;">
                    <h3 style="font-size: 1.25rem; font-weight: 700; margin: 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" x-text="activeItem?.name"></h3>
                    <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;" x-text="activeItem?.type === 'file' ? formatSize(activeItem?.size) : 'Directory'"></div>
                </div>
                <button @click="activeItem = null" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
            <div class="modal-body" style="padding: 1rem 1.5rem;">
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <template x-if="activeItem?.type === 'file'">
                        <button @click="downloadFile(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download File
                        </button>
                    </template>
                    <template x-if="activeItem?.type === 'directory'">
                        <button @click="currentPath = currentPath === '/' ? '/' + activeItem.name : currentPath + '/' + activeItem.name; showNewFile = true; activeItem = null;" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            New File Here
                        </button>
                    </template>
                    <template x-if="activeItem?.type === 'directory'">
                        <button @click="currentPath = currentPath === '/' ? '/' + activeItem.name : currentPath + '/' + activeItem.name; $refs.fileInput.click(); activeItem = null;" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Upload File Here
                        </button>
                    </template>
                    <template x-if="activeItem?.type === 'directory'">
                        <button @click="currentPath = currentPath === '/' ? '/' + activeItem.name : currentPath + '/' + activeItem.name; showRemoteUpload = true; activeItem = null;" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"></path><polyline points="12 16 16 12 12 8"></polyline><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                            Remote Upload Here
                        </button>
                    </template>

                    <button @click="promptRename(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="16 3 21 8 8 21 3 21 3 16 16 3"></polygon></svg>
                        Rename
                    </button>
                    <button @click="promptChmod(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        Permissions (CHMOD)
                    </button>
                    <button @click="promptMove(activeItem, 'move')" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="5 9 2 12 5 15"></polyline><polyline points="9 5 12 2 15 5"></polyline><polyline points="19 9 22 12 19 15"></polyline><polyline points="9 19 12 22 15 19"></polyline><line x1="2" y1="12" x2="22" y2="12"></line><line x1="12" y1="2" x2="12" y2="22"></line></svg>
                        Move
                    </button>
                    <button @click="promptMove(activeItem, 'copy')" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        Copy
                    </button>
                    <button @click="promptZip(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                        Compress to ZIP
                    </button>
                    <template x-if="activeItem && activeItem.type === 'file' && activeItem.name.toLowerCase().endsWith('.zip')">
                        <button @click="unzipItem(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                            Extract ZIP Here
                        </button>
                    </template>
                    <div style="border-top: 1px solid var(--border); margin: 0.5rem 0;"></div>
                    <button @click="deleteItem(activeItem)" class="table-link" style="justify-content: flex-start; padding: 0.75rem; color: var(--danger);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                        Delete Permanently
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Folder / Touch File Modal -->
    <div class="modal-overlay" x-show="showMkdir || showNewFile" x-cloak @click.self="showMkdir = false; showNewFile = false">
        <div class="modal-card" x-show="showMkdir || showNewFile">
            <div class="modal-header">
                <h3 style="font-size: 1.25rem; font-weight: 700; margin: 0;" x-text="showMkdir ? 'New Folder' : 'New File'"></h3>
                <button @click="showMkdir = false; showNewFile = false" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
            <div class="modal-body">
                <div class="input-group">
                    <label>Path Context</label>
                    <input type="text" class="input-control" :value="currentPath" disabled style="opacity: 0.6; background: rgba(0,0,0,0.2);">
                </div>
                <div class="input-group">
                    <label x-text="showMkdir ? 'Folder Name' : 'File Name'"></label>
                    <input type="text" class="input-control" placeholder="example" x-model="newFolderName" @keyup.enter="showMkdir ? createFolder() : createFile()">
                </div>
            </div>
            <div class="modal-footer">
                <button @click="showMkdir = false; showNewFile = false" class="btn btn-ghost" style="flex: 1;">Cancel</button>
                <button @click="showMkdir ? createFolder() : createFile()" class="btn btn-primary" style="flex: 2;">Create</button>
            </div>
        </div>
    </div>

    <!-- Remote Upload Modal -->
    <div class="modal-overlay" x-show="showRemoteUpload" x-cloak @click.self="showRemoteUpload = false">
        <div class="modal-card" x-show="showRemoteUpload">
            <div class="modal-header">
                <h3 style="font-size: 1.25rem; font-weight: 700; margin: 0;">Remote Upload</h3>
                <button @click="showRemoteUpload = false" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
            <div class="modal-body">
                <div class="input-group">
                    <label>Download URL</label>
                    <input type="url" class="input-control" placeholder="https://example.com/file.zip" x-model="remoteUrl" @keyup.enter="remoteUpload()">
                </div>
                <div class="input-group">
                    <label>Destination Path</label>
                    <input type="text" class="input-control" :value="currentPath" disabled style="opacity: 0.6; background: rgba(0,0,0,0.2);">
                </div>
                <p style="font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.5rem;">The file will be downloaded directly to the server.</p>
            </div>
            <div class="modal-footer">
                <button @click="showRemoteUpload = false" class="btn btn-ghost" style="flex: 1;">Cancel</button>
                <button @click="remoteUpload()" class="btn btn-primary" style="flex: 2;">Start Download</button>
            </div>
        </div>
    </div>


    <!-- File Editor Modal -->
    <div class="modal-overlay" x-show="showEditor" x-cloak style="padding: 0; align-items: stretch; background: var(--bg-deep);">
        <div style="width: 100%; height: 100%; display: flex; flex-direction: column;">
            <div class="header" style="border-radius: 0; border-bottom: 1px solid var(--border);">
                <div class="header-left">
                    <button @click="showEditor = false" class="btn btn-ghost" style="padding: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back
                    </button>
                    <div class="header-info" style="margin-left: 1rem;">
                        <span class="header-title" x-text="editingFile?.name"></span>
                    </div>
                </div>
                <div class="header-right">
                    <button @click="saveFileContent" class="btn btn-primary" :disabled="loading" style="display:flex; align-items:center; gap:0.5rem;">
                        <svg x-show="!loading" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span x-show="!loading">Save Changes</span>
                        <div x-show="loading" class="spinner animate-spin" style="width: 16px; height: 16px; border: 2px solid rgba(0,0,0,0.2); border-top-color: #000; border-radius: 50%;"></div>
                        <span x-show="loading">Saving...</span>
                    </button>
                </div>
            </div>
            <textarea x-model="fileContent" style="flex:1; width:100%; height:100%; font-family: monospace; font-size: 0.9rem; padding: 1.5rem; resize: none; white-space: pre; border: none; outline:none; background: var(--bg-deep); color:var(--text-primary); line-height: 1.5;"></textarea>
        </div>
    </div>

    <script>
        window.addEventListener('hardwareBackPress', (e) => {
            const app = document.querySelector('[x-data="ftpApp"]').__x.$data;
            if (app) {
                if (app.showAddServer || app.openServerManager || app.showMkdir || app.showNewFile || app.activeItem || app.showEditor) {
                    e.preventDefault();
                    app.showAddServer = false;
                    app.openServerManager = false;
                    app.showMkdir = false;
                    app.showNewFile = false;
                    app.activeItem = null;
                    app.showEditor = false;
                } else if (app.currentPath && app.currentPath !== '/') {
                    e.preventDefault();
                    app.navigateUp();
                } else if (app.currentServer) {
                    e.preventDefault();
                    app.currentServer = null;
                }
            }
        });

        document.addEventListener('alpine:init', () => {
            Alpine.data('ftpApp', () => ({
                currentPath: '<?php echo $initial_path; ?>',
                folderTree: [], // Flattened tree nodes

                files: [],
                loading: false,
                sidebarOpen: false,
                showMkdir: false,
                showQR: false,
                qrData: <?php echo json_encode(get_qr_data()); ?>,
                rootName: '<?php echo (DIRECTORY_SEPARATOR === "\\") ? $norm_base . "/" : "Root Directory"; ?>',
                showNewFile: false,
                newFolderName: '',
                csrf_token: '<?php echo get_csrf_token(); ?>', // SECURITY: CSRF token for session auth

                contextMenu: { show: false, x: 0, y: 0, item: null },

                activeItem: null,
                showEditor: false,
                editingFile: null,
                fileContent: '',
                bookmarks: [],
                showRemoteUpload: false,
                remoteUrl: '',
                selectedItems: [],
                showMcpModal: false,
                mcpConfig: { databases: {}, folders: {} },
                newMcpFolder: '',
                updateInfo: { checked: false, available: false, current: '<?php echo VERSION; ?>', latest: '', commitsBehind: 0, loading: false, error: '' },
                mcpCopied: false,


                get pathSegments() {
                    return this.currentPath === '/' ? [] : this.currentPath.split('/').filter(p => p);
                },
                get subfolders() {
                    return this.files.filter(f => f.type === 'directory');
                },

                toggleSelection(item) {
                    window.haptic('impactLight');
                    const idx = this.selectedItems.findIndex(i => i.name === item.name);
                    if (idx > -1) {
                        this.selectedItems.splice(idx, 1);
                    } else {
                        this.selectedItems.push(item);
                    }
                },
                
                clearSelection() {
                    this.selectedItems = [];
                },


                init() {
                    const saved = localStorage.getItem('fileluxe_bookmarks');
                    if (saved) this.bookmarks = JSON.parse(saved);
                    
                    // Initialize tree with root
                    this.folderTree = [{ path: '/', name: this.rootName, type: 'directory', level: 0, expanded: false, hasChildren: true, loading: false }];

                    // Always expand the root
                    this.expandNodeByPath('/');
                    
                    // If we are starting in a subfolder, we need to build the tree branch to it
                    if (this.currentPath !== '/') {
                        this.buildTreeToPath(this.currentPath);
                    }
                    this.fetchFiles(this.currentPath);
                },

                async buildTreeToPath(path) {
                    if (path === '/') return;
                    let parts = path.split('/').filter(p => p);
                    let current = '';
                    for (let part of parts) {
                        let parent = current || '/';
                        current += '/' + part;
                        await this.expandNodeByPath(parent);
                    }
                    await this.expandNodeByPath(path);
                },

                async expandNodeByPath(path) {
                    let node = this.folderTree.find(n => n.path === path);
                    if (node && !node.expanded) {
                        await this.toggleNode(node, true);
                    }
                },

                async toggleNode(node, forceExpand = false) {
                    if (forceExpand) node.expanded = true;
                    else if (node.expanded) node.expanded = false;
                    else node.expanded = true;

                    if (node.expanded && node.type === 'directory') {
                        // Fetch children - silent loading to avoid main UI flash
                        node.loading = true;
                        let res = await this.apiReq({ ajax: 'files', path: node.path }, 'GET', false, true);
                        node.loading = false;
                        if (res && res.success) {
                            let children = res.files.map(f => ({
                                path: node.path === '/' ? '/' + f.name : node.path + '/' + f.name,
                                name: f.name,
                                type: f.type,
                                level: node.level + 1,
                                expanded: false,
                                hasChildren: f.type === 'directory',
                                loading: false
                            }));
                            
                            node.hasChildren = children.some(c => c.type === 'directory');

                            // Insert children after the parent node
                            let index = this.folderTree.indexOf(node);
                            this.folderTree.splice(index + 1, 0, ...children);
                        }
                    } else if (!node.expanded) {
                        // Collapse: Remove all nodes that start with this path and have a higher level
                        let index = this.folderTree.indexOf(node);
                        let count = 0;
                        for (let i = index + 1; i < this.folderTree.length; i++) {
                            if (this.folderTree[i].level > node.level) count++;
                            else break;
                        }
                        this.folderTree.splice(index + 1, count);
                    }
                },

                openContextMenu(e, item) {
                    this.contextMenu = {
                        show: true,
                        x: e.clientX,
                        y: e.clientY,
                        item: item
                    };
                    
                    // Reposition if menu goes off screen
                    this.$nextTick(() => {
                        const menu = this.$refs.ctxMenu;
                        if (!menu) return;
                        if (this.contextMenu.x + menu.offsetWidth > window.innerWidth) {
                            this.contextMenu.x -= menu.offsetWidth;
                        }
                        if (this.contextMenu.y + menu.offsetHeight > window.innerHeight) {
                            this.contextMenu.y -= menu.offsetHeight;
                        }
                    });
                },

                closeContextMenu() {
                    this.contextMenu.show = false;
                },




                saveCurrentAsBookmark() {
                    if (this.bookmarks.includes(this.currentPath)) {
                        window.uiAlert('Folder already bookmarked.');
                        return;
                    }
                    this.bookmarks.push(this.currentPath);
                    this.saveBookmarksToStorage();
                    window.uiAlert('Folder bookmarked!');
                },

                removeBookmark(path) {
                    this.bookmarks = this.bookmarks.filter(p => p !== path);
                    this.saveBookmarksToStorage();
                },

                saveBookmarksToStorage() {
                    localStorage.setItem('fileluxe_bookmarks', JSON.stringify(this.bookmarks));
                },

                

                async apiReq(params, method = 'POST', isFormData = false, silent = false) {
                    if (!silent) this.loading = true;
                    // Store silent flag to use in cleanup
                    const isSilent = silent;


                    try {
                        let url = new URL(window.location.href);
                        let body = null;

                        if (method === 'GET') {
                            Object.keys(params).forEach(k => url.searchParams.append(k, params[k]));
                            // SECURITY: Removed hardcoded API key - use session authentication
                        } else {
                            if (isFormData) {
                                body = params;
                            } else {
                                body = new FormData();
                                Object.keys(params).forEach(k => body.append(k, params[k]));
                                // SECURITY: Add CSRF token to POST requests
                                if (this.csrf_token) {
                                    body.append('csrf_token', this.csrf_token);
                                }
                            }
                        }

                        let res = await fetch(url.toString(), { method, body, headers: { 'X-CSRF-TOKEN': this.csrf_token || '' } });
                        let text = await res.text();
                        if (!isSilent) this.loading = false;


                        try {
                            let data = JSON.parse(text);
                            if (data.error) {
                                window.uiAlert(data.error);
                                return null;
                            }
                            return data;
                        } catch (e) {
                            // If we see "<!DOCTYPE" or "Welcome to", we likely got redirected to login
                            if (text.trim().startsWith('<!DOCTYPE')) {
                                window.uiAlert("Session expired. Please refresh the page.");
                            } else {
                                window.uiAlert("API Error: " + e.message + " (Response: " + text.substring(0, 50) + "...)");
                            }
                            return null;
                        }
                    } catch (e) {
                        this.loading = false;
                        window.uiAlert("Network Error: " + e.message);
                        return null;
                    }
                },


                async openMcpModal() {
                    this.showMcpModal = true;
                    this.loading = true;
                    try {
                        const res = await fetch('?action=get_mcp_config');
                        this.mcpConfig = await res.json();
                        if (!this.mcpConfig.databases || Array.isArray(this.mcpConfig.databases)) this.mcpConfig.databases = {};
                        if (!this.mcpConfig.folders || Array.isArray(this.mcpConfig.folders)) this.mcpConfig.folders = {};
                    } catch(e) {
                        console.error('Failed to load MCP config:', e);
                    } finally {
                        this.loading = false;
                    }
                },
                addMcpFolder() {
                    if (!this.newMcpFolder) return;
                    if (!this.mcpConfig.folders[this.newMcpFolder]) {
                        this.mcpConfig.folders = {
                            ...this.mcpConfig.folders,
                            [this.newMcpFolder]: { read: true, write: false }
                        };
                    }
                    this.newMcpFolder = '';
                },
                addCurrentFolderToMcp() {
                    const currentFolder = this.currentPath;
                    if (!this.mcpConfig.folders[currentFolder]) {
                        this.mcpConfig.folders = {
                            ...this.mcpConfig.folders,
                            [currentFolder]: { read: true, write: false }
                        };
                    }
                },
                removeMcpFolder(folder) {
                    const next = { ...this.mcpConfig.folders };
                    delete next[folder];
                    this.mcpConfig.folders = next;
                },

                async saveMcpConfig() {
                    this.loading = true;
                    const fd = new FormData();
                    fd.append('action', 'save_mcp_config');
                    fd.append('config', JSON.stringify(this.mcpConfig));
                    fd.append('csrf_token', this.csrf_token);
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            window.uiAlert('MCP Configuration saved successfully!');
                        } else {
                            window.uiAlert('Error: ' + data.error);
                        }
                    } catch(e) {
                        window.uiAlert('Failed to save configuration.');
                    } finally {
                        this.loading = false;
                    }
                },                
                async copyMcpConfig() {
                    try {
                        const text = this.$refs.mcpConfigPre ? this.$refs.mcpConfigPre.textContent : "";
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(text);
                        } else {
                            const ta = document.createElement("textarea");
                            ta.value = text; document.body.appendChild(ta); ta.select();
                            document.execCommand("copy"); document.body.removeChild(ta);
                        }
                        this.mcpCopied = true;
                        window.haptic("success");
                        setTimeout(() => { this.mcpCopied = false; }, 2000);
                    } catch(e) {
                        window.haptic("error");
                        window.uiAlert("Failed to copy MCP config.");
                    }
                },


                async checkUpdates() {
                    this.updateInfo.loading = true;
                    this.updateInfo.error = '';
                    try {
                        const res = await fetch('?action=check_updates');
                        const data = await res.json();
                        this.updateInfo.checked = true;
                        this.updateInfo.available = data.update_available;
                        this.updateInfo.current = data.current_version;
                        this.updateInfo.latest = data.latest_version;
                        this.updateInfo.commitsBehind = data.commits_behind;
                    } catch(e) {
                        this.updateInfo.error = 'Failed to check for updates.';
                    } finally {
                        this.updateInfo.loading = false;
                    }
                },
                async triggerUpdate() {
                    const conf = await window.uiConfirm('Are you sure you want to update SQLuxe & FileLuxe now? This will overwrite db.php and fm.php.');
                    if (!conf) return;
                    this.updateInfo.loading = true;
                    const fd = new FormData();
                    fd.append('action', 'apply_update');
                    fd.append('csrf_token', this.csrf_token);
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            window.uiAlert('Updates applied successfully! The page will now reload.');
                            location.reload();
                        } else {
                            window.uiAlert('Update failed: ' + data.error);
                        }
                    } catch(e) {
                        window.uiAlert('Failed to apply update.');
                    } finally {
                        this.updateInfo.loading = false;
                    }
                },

                async fetchFiles(path = '/') {
                    let res = await this.apiReq({ ajax: 'files', path: path }, 'GET');
                    if (res && res.success) {
                        this.files = res.files;
                        this.currentPath = res.pwd;
                    }
                },

                navigateTo(path) {
                    this.fetchFiles(path);
                },

                navigateUp() {
                    if (this.currentPath === '/') {
                        this.navigateTo('/..');
                    } else if (this.currentPath.endsWith('..')) {
                        this.navigateTo(this.currentPath + '/..');
                    } else {
                        let parts = this.currentPath.split('/');
                        parts.pop();
                        this.navigateTo(parts.join('/') || '/');
                    }
                },

                navigateToSegment(index) {
                    let parts = this.pathSegments.slice(0, index + 1);
                    this.navigateTo('/' + parts.join('/'));
                },

                formatSize(bytes) {
                    if (bytes === 0) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                },

                openItemMenu(item) {
                    this.activeItem = item;
                },

                async createFolder() {
                    if (!this.newFolderName) return;
                    let target = this.currentPath === '/' ? '/' + this.newFolderName : this.currentPath + '/' + this.newFolderName;
                    let res = await this.apiReq({ action: 'mkdir', dir: target });
                    if (res && res.success) {
                        this.showMkdir = false;
                        this.newFolderName = '';
                        this.fetchFiles(this.currentPath);
                    }
                },

                async createFile() {
                    if (!this.newFolderName) return;
                    let target = this.currentPath === '/' ? '/' + this.newFolderName : this.currentPath + '/' + this.newFolderName;
                    // Provide empty content to create file using logic from write
                    let res = await this.apiReq({ action: 'write', file: target, content: btoa('') });
                    if (res && res.success) {
                        this.showNewFile = false;
                        this.newFolderName = '';
                        this.fetchFiles(this.currentPath);
                    }
                },

                async promptChmod(item) {
                    let val = await window.uiPrompt('Change Permissions Ext (e.g. 0644)', '0644');
                    if (val) {
                        let target = this.currentPath === '/' ? '/' + item.name : this.currentPath + '/' + item.name;
                        let res = await this.apiReq({ action: 'chmod', file: target, perms: val });
                        if (res && res.success) {
                            this.activeItem = null;
                            this.fetchFiles(this.currentPath);
                        }
                    }
                },

                async promptMove(item, action) {
                    let destName = await window.uiPrompt((action === 'copy' ? 'Copy to name/path' : 'Move to name/path'), item.name);
                    if (destName) {
                        let baseDir = this.currentPath === '/' ? '/' : this.currentPath + '/';
                        let oldPath = baseDir + item.name;
                        let newPath = destName.startsWith('/') ? destName : baseDir + destName;
                        let res = await this.apiReq({ action: action, source: oldPath, dest: newPath });
                        if (res && res.success) {
                            this.activeItem = null;
                            this.fetchFiles(this.currentPath);
                        }
                    }
                },

                async promptZip(item) {
                    let archiveName = await window.uiPrompt('ZIP Archive Name', item.name + '.zip');
                    if (archiveName) {
                        let baseDir = this.currentPath === '/' ? '/' : this.currentPath + '/';
                        let sourcePath = baseDir + item.name;
                        let destPath = baseDir + archiveName;
                        this.loading = true; // might take a while
                        let res = await this.apiReq({ action: 'zip', source: sourcePath, dest: destPath });
                        if (res && res.success) {
                            this.activeItem = null;
                            this.fetchFiles(this.currentPath);
                        }
                    }
                },
                
                async unzipItem(item) {
                    window.haptic('impactLight');
                    if (!await window.uiConfirm('Extract ' + item.name + ' here?')) return;
                    let target = this.currentPath === '/' ? '/' + item.name : this.currentPath + '/' + item.name;
                    this.loading = true;
                    let res = await this.apiReq({ action: 'unzip', file: target });
                    if (res && res.success) {
                        this.activeItem = null;
                        this.fetchFiles(this.currentPath);
                        window.haptic('success');
                        window.uiAlert('Extracted successfully!');
                    } else if (res && res.error) {
                        window.uiAlert('Extraction failed: ' + res.error);
                    }
                    this.loading = false;
                },

                async deleteItem(item) {
                    window.haptic('warning');
                    if (!await window.uiConfirm('Are you sure you want to delete ' + item.name + '?')) return;
                    let target = this.currentPath === '/' ? '/' + item.name : this.currentPath + '/' + item.name;
                    let res = await this.apiReq({ action: 'delete', file: target });
                    if (res && res.success) {
                        this.activeItem = null;
                        this.fetchFiles(this.currentPath);
                        window.haptic('success');
                    }
                },

                async promptRename(item) {
                    let newName = await window.uiPrompt('Enter new name for ' + item.name, item.name);
                    if (newName && newName !== item.name) {
                        let baseDir = this.currentPath === '/' ? '/' : this.currentPath + '/';
                        let oldPath = baseDir + item.name;
                        let newPath = baseDir + newName;
                        let res = await this.apiReq({ action: 'rename', old: oldPath, new: newPath });
                        if (res && res.success) {
                            this.activeItem = null;
                            this.fetchFiles(this.currentPath);
                        }
                    }
                },

                async remoteUpload() {
                    if (!this.remoteUrl) return;
                    let res = await this.apiReq({ action: 'remote_upload', url: this.remoteUrl, path: this.currentPath });
                    if (res && res.success) {
                        this.showRemoteUpload = false;
                        this.remoteUrl = '';
                        this.fetchFiles(this.currentPath);
                        window.uiAlert('Remote file downloaded: ' + res.filename);
                    }
                },

                async uploadFile(e) {

                    let file = e.target.files[0];
                    if (!file) return;
                    let fd = new FormData();
                    fd.append('action', 'upload');
                    fd.append('path', this.currentPath);
                    fd.append('file', file);

                    let res = await this.apiReq(fd, 'POST', true);
                    if (res && res.success) {
                        this.fetchFiles(this.currentPath);
                        window.haptic('success');
                    }
                    e.target.value = '';
                },

                async inspectFile(item) {
                    window.haptic('impactLight');
                    return this.inspectFileByPath(this.currentPath === '/' ? '/' + item.name : this.currentPath + '/' + item.name, item);
                },

                async inspectFileByPath(target, item = null) {
                    // Native editor for easily viewable files
                    this.loading = true;
                    let res = await this.apiReq({ action: 'read', file: target });
                    this.loading = false;
                    if (res && res.success) {
                        this.editingFile = { name: target.split('/').pop(), ...item, fullPath: target };
                        // Decode base64 to string safely
                        try {
                            this.fileContent = decodeURIComponent(atob(res.content).split('').map(function (c) {
                                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
                            }).join(''));
                            this.showEditor = true;
                        } catch (e) {
                            window.uiAlert("File is binary or uses an unsupported encoding.");
                        }
                    }
                },


                async saveFileContent() {
                    // Encode back to base64
                    let base64Content = btoa(encodeURIComponent(this.fileContent).replace(/%([0-9A-F]{2})/g,
                        function toSolidBytes(match, p1) {
                            return String.fromCharCode('0x' + p1);
                        }));
                    let res = await this.apiReq({ action: 'write', file: this.editingFile.fullPath, content: base64Content });
                    if (res && res.success) {
                        window.uiAlert('Saved successfully!');
                        window.haptic('success');
                        this.showEditor = false;
                        this.fetchFiles(this.currentPath);
                        window.haptic('success');
                    }
                },

                downloadFile(item) {
                    let target = this.currentPath === '/' ? '/' + item.name : this.currentPath + '/' + item.name;

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    form.target = '_blank';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'download';

                    const fileInput = document.createElement('input');
                    fileInput.type = 'hidden';
                    fileInput.name = 'file';
                    fileInput.value = target;

                    const apiKeyInput = document.createElement('input');
                    apiKeyInput.type = 'hidden';
                    apiKeyInput.name = 'api_key';
                    apiKeyInput.value = '2026';

                    form.appendChild(actionInput);
                    form.appendChild(fileInput);
                    form.appendChild(apiKeyInput);

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
            }));
        });
    </script>

    <!-- Context Menu -->
    <div x-show="contextMenu.show" 
         x-cloak 
         @click.outside="closeContextMenu()" 
         @scroll.window="closeContextMenu()"
         x-ref="ctxMenu"
         class="data-table-container"
         :style="'position: fixed; z-index: 10000; left: ' + contextMenu.x + 'px; top: ' + contextMenu.y + 'px; min-width: 180px; padding: 0.5rem; background: var(--bg-surface); box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--accent);'">
        <div class="flex flex-col gap-1">
            <template x-if="contextMenu.item?.type === 'directory'">
                <button @click="navigateTo(contextMenu.item.path); closeContextMenu();" class="table-link" style="padding: 0.5rem 0.75rem; font-size: 0.8rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    Open Folder
                </button>
            </template>
            <template x-if="contextMenu.item?.type === 'file'">
                <button @click="inspectFile(contextMenu.item); closeContextMenu();" class="table-link" style="padding: 0.5rem 0.75rem; font-size: 0.8rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit File
                </button>
            </template>
            <template x-if="contextMenu.item?.type === 'file'">
                <button @click="downloadFile(contextMenu.item); closeContextMenu();" class="table-link" style="padding: 0.5rem 0.75rem; font-size: 0.8rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download File
                </button>
            </template>
            <button @click="openItemMenu(contextMenu.item); closeContextMenu();" class="table-link" style="padding: 0.5rem 0.75rem; font-size: 0.8rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                More Actions...
            </button>
            <div style="border-top: 1px solid var(--border); margin: 0.25rem 0;"></div>
            <button @click="deleteItem(contextMenu.item); closeContextMenu();" class="table-link" style="padding: 0.5rem 0.75rem; font-size: 0.8rem; color: var(--danger);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Delete
            </button>
        </div>
    </div>

    <!-- MCP & Auto-Update Modal -->
    <div class="modal-overlay" x-show="showMcpModal" x-cloak x-transition>
        <div class="modal-card modal-panel" style="width: 95%;" @click.outside="showMcpModal = false" x-data="{ subTab: 'mcp' }">
            <div class="modal-header">
                <div class="flex items-center gap-2">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <h3 style="font-weight: 700;">MCP & Auto-Update Panel</h3>
                </div>
                <button @click="showMcpModal = false" class="btn btn-ghost" style="padding: 0.25rem; border: none;">&times;</button>
            </div>
            
            <div style="padding: 0 1.5rem; border-bottom: 1px solid var(--border);">
                <div class="tabs" style="margin-bottom: 0;">
                    <div class="tab" :class="subTab === 'mcp' ? 'active' : ''" @click="subTab = 'mcp'">AI & MCP Permissions</div>
                    <div class="tab" :class="subTab === 'update' ? 'active' : ''" @click="subTab = 'update'">System Auto-Update</div>
                </div>
            </div>

            <div class="modal-body" style="overflow-y: auto; flex: 1;">
                
                <!-- MCP Tab -->
                <div x-show="subTab === 'mcp'" class="flex flex-col gap-4">
                    <p style="font-size: 0.85rem; color: var(--text-secondary);">
                        Grant specific read/write access to AI clients connecting via Model Context Protocol (MCP).
                    </p>
                    


                    <!-- Folders Permissions -->
                    <div>
                        <h4 style="font-size: 0.85rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--accent);">Folder Access Rules</h4>
                        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 0.5rem;">
                            <div class="flex gap-2" style="margin-bottom: 1rem;">
                                <input type="text" x-model="newMcpFolder" class="input-control" placeholder="Absolute folder path (e.g. /var/www)" style="font-size: 0.8rem; padding: 0.5rem;">
                                <button @click="addMcpFolder" class="btn btn-ghost" style="padding: 0.5rem 1rem; font-size: 0.8rem;">Add Path</button>
                                <button @click="addCurrentFolderToMcp" class="btn btn-ghost" style="padding: 0.5rem 1rem; font-size: 0.8rem; color: var(--accent);">+ Add Current</button>
                            </div>
                            <div class="flex flex-col gap-2">
                                <template x-for="(perms, path) in mcpConfig.folders" :key="path">
                                    <div class="flex items-center justify-between" style="padding: 0.5rem; background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: var(--radius-md);">
                                        <span style="font-size: 0.75rem; font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 300px;" x-text="path"></span>
                                        <div class="flex items-center gap-4">
                                            <label class="flex items-center gap-1" style="font-size: 0.75rem;">
                                                <input type="checkbox" x-model="perms.read"> R
                                            </label>
                                            <label class="flex items-center gap-1" style="font-size: 0.75rem;">
                                                <input type="checkbox" x-model="perms.write"> W
                                            </label>
                                            <button @click="removeMcpFolder(path)" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 1.1rem; padding: 0 0.25rem;">&times;</button>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="Object.keys(mcpConfig.folders || {}).length === 0">
                                    <span style="color: var(--text-secondary); font-size: 0.75rem; text-align: center; display: block; opacity: 0.6;">No folders configured.</span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Setup Instructions -->
                    <div>
                        <h4 style="font-size: 0.85rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--accent);">AI Client Config (Claude Desktop / Cursor)</h4>
                                    <div style="display: flex; justify-content: flex-end; margin-bottom: 0.5rem;">
                                        <button @click="copyMcpConfig" class="btn btn-ghost" style="padding: 0.35rem 0.75rem; font-size: 0.7rem; color: var(--accent); border-color: rgba(34,211,238,0.3);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                            <span x-text="mcpCopied ? 'Copied!' : 'Copy JSON'"></span>
                                        </button>
                                    </div>
                        <div style="background: #000; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); font-family: monospace; font-size: 0.75rem; color: #fff; overflow-x: auto;">
                            <pre x-ref="mcpConfigPre" style="margin: 0; white-space: pre-wrap; word-break: break-all;">{
  "mcpServers": {
    "serverluxe": {
      "command": "node",
      "args": [
        "<?php echo str_replace('\\', '/', __DIR__ . '/mcp-bridge.js'); ?>",
        "--url", "<?php 
           $proto = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
           echo $proto . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?'); 
        ?>",
        "--key", "<?php echo htmlspecialchars(API_KEY); ?>"
      ]
    }
  }
}</pre>
                        </div>
                        <span style="font-size: 0.7rem; color: var(--text-secondary); display: block; margin-top: 0.25rem;">
                            Make sure to copy the <span style="color:#fff;font-family:monospace;">mcp-bridge.js</span> script to your local machine (or keep it in the project path) to allow your local AI to communicate with the server. <a href="mcp_docs.md" target="_blank" style="color: var(--accent); font-weight: 600;">Full MCP Docs</a> &mdash; your AI can also call the <code style="color:#fff;">get_server_docs</code> tool for in-protocol documentation.
                        </span>
                    </div>

                    <button @click="saveMcpConfig" class="btn btn-primary" style="margin-top: 1rem;">Save Permissions</button>
                </div>

                <!-- Updates Tab -->
                <div x-show="subTab === 'update'" class="flex flex-col gap-4">
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; text-align: center;">
                        <div style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Current Version</div>
                        <div style="font-size: 2.5rem; font-weight: 800; color: #fff; line-height: 1;" x-text="updateInfo.current"></div>
                        
                        <div style="margin-top: 1.5rem; display: flex; justify-content: center; gap: 1rem;">
                            <button @click="checkUpdates" class="btn btn-ghost" :disabled="updateInfo.loading" style="padding: 0.5rem 1.5rem; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.1);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="updateInfo.loading ? 'animate-spin' : ''" style="margin-right: 0.25rem;"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Check for Updates
                            </button>
                        </div>
                    </div>

                    <!-- Update Results -->
                    <div x-show="updateInfo.checked" x-cloak style="background: rgba(34, 211, 238, 0.05); border: 1px solid rgba(34, 211, 238, 0.2); border-radius: var(--radius-md); padding: 1rem;">
                        <div class="flex items-center justify-between">
                            <div>
                                <div style="font-weight: 700; font-size: 0.9rem; color: var(--accent);" x-show="updateInfo.available">Update Available!</div>
                                <div style="font-weight: 700; font-size: 0.9rem; color: var(--success);" x-show="!updateInfo.available">You are up to date!</div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                    Latest version on GitHub: <span style="font-weight: 700; color:#fff;" x-text="updateInfo.latest"></span>
                                </div>
                                <div x-show="updateInfo.commitsBehind > 0" style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                    Commits behind main: <span style="font-weight:700; color:#fff;" x-text="updateInfo.commitsBehind"></span>
                                </div>
                            </div>
                            <button x-show="updateInfo.available" @click="triggerUpdate" class="btn btn-primary" :disabled="updateInfo.loading" style="padding: 0.5rem 1rem; font-size: 0.8rem; margin: 0; background: var(--danger); color: #fff;">
                                Update Now
                            </button>
                        </div>
                    </div>
                    
                    <div x-show="updateInfo.error" x-cloak style="color: var(--danger); font-size: 0.8rem; text-align: center;" x-text="updateInfo.error"></div>
                </div>

            </div>
        </div>
    </div>

    <!-- QR Modal -->
    <div class="modal-overlay" x-show="showQR" x-cloak @click.self="showQR = false">
        <div class="modal-card" style="max-width: 400px; text-align: center; display: flex; flex-direction: column; grid-template-columns: unset; grid-template-rows: unset; grid-template-areas: unset;">
            <div class="modal-header">
                <h3 style="font-weight: 800;">Connect to Mobile App</h3>
                <button @click="showQR = false" class="btn btn-ghost" style="padding: 0.25rem; border: none;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 2rem;">
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">Scan this QR code with the <strong>ServerLuxe</strong> mobile app to add this server automatically.</p>
                <div style="background: #fff; padding: 1.5rem; border-radius: 1rem; display: inline-block; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                    <img :src="'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrData)" alt="QR Code" style="display: block;">
                </div>
                <div style="font-size: 0.7rem; color: var(--text-secondary); font-family: monospace; word-break: break-all; background: var(--bg-deep); padding: 0.75rem; border-radius: 0.5rem; opacity: 0.7;" x-text="qrData"></div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border); border-left: none; flex-direction: row; min-width: unset; max-width: unset; justify-content: flex-end;">
                <button @click="showQR = false" class="btn btn-primary" style="width: auto;">DONE</button>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// QR Data Generator - SECURITY: Removed API key exposure
function get_qr_data() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = strtok($_SERVER['REQUEST_URI'], '?');
    $fullUrl = $protocol . "://" . $host . $uri;

    return json_encode([
        'name' => "FileLuxe (" . $host . ")",
        'url' => $fullUrl,
        'type' => 'fm'
        // Note: API key should be configured separately in mobile app
    ]);
}

// Handle AJAX/API requests
if (isset($_GET['ajax']) || isset($_POST['action'])) {
    if (!is_api_request()) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required. Please login.']);
        exit;
    }

    // SECURITY: Check session timeout for authenticated sessions
    if (!empty($_SESSION['fm_authenticated'])) {
        if (!check_session_timeout()) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Session expired. Please login.']);
            exit;
        }
    }

    if (isset($_GET['ajax']) && $_GET['ajax'] === 'files') {
        ob_start();
        header('Content-Type: application/json');

        $current_path = (isset($_GET['path']) && $_GET['path'] !== '') ? $_GET['path'] : $initial_path;
        $target_dir = realpath($base_dir . '/' . ltrim($current_path, '/'));
        
        if ($target_dir === false || stripos($target_dir, $base_dir) !== 0) {
            $target_dir = $base_dir;
            $current_path = '/';
        }

        $files = [];
        if (is_dir($target_dir)) {
            $items = scandir($target_dir);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $target_dir . '/' . $item;
                $isDir = is_dir($path);
                $stat = @stat($path);
                $files[] = [
                    'name' => $item,
                    'type' => $isDir ? 'directory' : 'file',
                    'size' => $isDir ? 0 : ($stat ? $stat['size'] : 0),
                    'permissions' => ($isDir ? 'd' : '-') . format_permissions($stat ? $stat['mode'] : 0),
                    'date' => $stat ? date('Y-m-d H:i:s', $stat['mtime']) : 'N/A',
                    'owner' => ($stat && function_exists('posix_getpwuid') && ($pw = @posix_getpwuid($stat['uid']))) ? $pw['name'] : ($stat ? $stat['uid'] : 'N/A'),
                    'group' => ($stat && function_exists('posix_getgrgid') && ($gr = @posix_getgrgid($stat['gid']))) ? $gr['name'] : ($stat ? $stat['gid'] : 'N/A')
                ];
            }
            usort($files, function($a, $b) {
                if ($a['type'] !== $b['type']) return $a['type'] === 'directory' ? -1 : 1;
                return strcasecmp($a['name'], $b['name']);
            });
        }
        
        if (is_dir($target_dir)) {
            echo json_encode(['success' => true, 'files' => $files, 'pwd' => $current_path, 'realpath' => $target_dir]);
        } else {
            echo json_encode(['error' => 'Directory not found.']);
        }
        
        $res_json = ob_get_clean();
        header('Content-Type: application/json');
        echo $res_json;
        exit;
    }

    if (isset($_POST['action'])) {
        ob_start();
        header('Content-Type: application/json');

        // SECURITY: CSRF validation for state-changing operations
        if (!validate_csrf_token()) {
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }

        $action = $_POST['action'];
        switch($action) {
            case 'delete':
                $file = $_POST['file'] ?? '';
                $validation = validate_path($base_dir, $file);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }
                $target = $validation['path'];
                $res = is_dir($target) ? @rmdir($target) : @unlink($target);
                echo json_encode(['success' => (bool)$res, 'error' => $res ? null : "Could not delete item."]);
                break;
            case 'rename':
                $old = trim($_POST['old'] ?? '');
                $new = trim($_POST['new'] ?? '');

                $old_validation = validate_path($base_dir, $old);
                if (!$old_validation['valid']) {
                    echo json_encode(['error' => 'Invalid source path']);
                    break;
                }

                $oldTarget = $old_validation['path'];
                $newName = basename($new);

                // Validate new name (no path traversal in filename)
                if (strpos($newName, '..') !== false || strpos($newName, '/') !== false || strpos($newName, '\\') !== false) {
                    echo json_encode(['error' => 'Invalid filename']);
                    break;
                }

                $newTarget = dirname($oldTarget) . '/' . $newName;
                $res = @rename($oldTarget, $newTarget);
                echo json_encode(['success' => (bool)$res, 'error' => $res ? null : "Could not rename."]);
                break;
            case 'mkdir':
                $dir = $_POST['dir'] ?? '';
                $validation = validate_path($base_dir, $dir);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }
                $target = $validation['path'];
                $res = @mkdir($target, 0755, true);
                echo json_encode(['success' => (bool)$res, 'error' => $res ? null : "Could not create directory."]);
                break;
            case 'upload':
                if (!empty($_FILES['file'])) {
                    $destPath = $_POST['path'] ?? '.';
                    $validation = validate_path($base_dir, $destPath);

                    if (!$validation['valid']) {
                        echo json_encode(['error' => 'Invalid destination path']);
                        break;
                    }

                    // SECURITY: Sanitize filename and validate extension
                    $original_name = $_FILES['file']['name'];
                    $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($original_name));

                    // Block dangerous extensions
                    $blocked_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'sh', 'exe', 'bat', 'cmd', 'com'];
                    $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));

                    if (in_array($ext, $blocked_extensions)) {
                        echo json_encode(['error' => 'File type not allowed']);
                        break;
                    }

                    $targetFile = $validation['path'] . '/' . $safe_name;
                    $res = @move_uploaded_file($_FILES['file']['tmp_name'], $targetFile);
                    echo json_encode(['success' => (bool)$res, 'filename' => $safe_name]);
                } else {
                    echo json_encode(['error' => 'No file']);
                }
                break;
            case 'write':
                $file = $_POST['file'] ?? '';
                $content = $_POST['content'] ?? '';

                $validation = validate_path($base_dir, $file);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }

                $target = $validation['path'];
                $res = @file_put_contents($target, base64_decode($content));
                echo json_encode(['success' => $res !== false]);
                break;
            case 'read':
                $file = $_POST['file'] ?? '';

                $validation = validate_path($base_dir, $file);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }

                $target = $validation['path'];
                $content = is_file($target) ? @file_get_contents($target) : false;

                if ($content !== false) {
                    echo json_encode(['success' => true, 'content' => base64_encode($content)]);
                } else {
                    echo json_encode(['error' => 'Read failed']);
                }
                break;
            case 'download':
                $file = $_POST['file'] ?? '';

                $validation = validate_path($base_dir, $file);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }

                $target = $validation['path'];

                if (is_file($target)) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="'.basename($target).'"');
                    readfile($target);
                    exit;
                }
                echo json_encode(['error' => "Not found"]);
                break;
            case 'remote_upload':
                $url = $_POST['url'] ?? '';
                $path = $_POST['path'] ?? '.';

                // SECURITY: Validate URL scheme to prevent SSRF
                $parsed_url = parse_url($url);
                $allowed_schemes = ['http', 'https'];

                if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], $allowed_schemes)) {
                    echo json_encode(['error' => 'Invalid URL scheme. Only HTTP/HTTPS allowed.']);
                    break;
                }

                // Prevent internal network access
                $host = $parsed_url['host'] ?? '';
                $blocked_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];

                if (in_array($host, $blocked_hosts) || strpos($host, '192.168.') === 0 || strpos($host, '10.') === 0) {
                    echo json_encode(['error' => 'Access to internal networks not allowed.']);
                    break;
                }

                $validation = validate_path($base_dir, $path);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid destination path']);
                    break;
                }

                $content = @file_get_contents($url);
                if ($content === false) {
                    echo json_encode(['error' => 'Could not fetch remote URL.']);
                    break;
                }

                $filename = basename($parsed_url['path'] ?? '');
                if (empty($filename)) $filename = 'downloaded_file_' . time();

                // Sanitize filename
                $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

                $targetFile = $validation['path'];
                if (!is_dir($targetFile)) $targetFile = dirname($targetFile);
                $targetFile .= '/' . $filename;

                $res = @file_put_contents($targetFile, $content);
                echo json_encode(['success' => $res !== false, 'filename' => $filename, 'error' => $res === false ? 'Could not save file.' : null]);
                break;
            case 'unzip':
                $file = $_POST['file'] ?? '';

                $validation = validate_path($base_dir, $file);
                if (!$validation['valid']) {
                    echo json_encode(['error' => 'Invalid path']);
                    break;
                }

                $target = $validation['path'];

                if (!is_file($target)) {
                    echo json_encode(['error' => 'File not found.']);
                    break;
                }

                // SECURITY: Verify it's actually a zip file
                $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                if ($ext !== 'zip') {
                    echo json_encode(['error' => 'Not a zip file.']);
                    break;
                }

                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive;
                    if ($zip->open($target) === TRUE) {
                        $zip->extractTo(dirname($target));
                        $zip->close();
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['error' => 'Failed to open zip.']);
                    }
                } else {
                    $res = @shell_exec("unzip -o ".escapeshellarg($target)." -d ".escapeshellarg(dirname($target)));
                    echo json_encode(['success' => !empty($res), 'error' => empty($res) ? 'Unzip failed or unsupported.' : null]);
                }
                break;
            default: echo json_encode(['error' => "Unknown action: $action"]);
        }
        $res_json = ob_get_clean();
        header('Content-Type: application/json');
        echo $res_json;
        exit;
    }
}
?>


