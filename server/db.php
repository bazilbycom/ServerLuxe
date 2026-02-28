<?php
/**
 * PHP MySQL Manager - SQLuxe
 * Single-file management tool with Master Password support.
 */

session_start();

// CORS Support for Mobile App - FIXED: Exact origin matching instead of substring matching
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = false;

// Exact match for capacitor
if ($origin === 'capacitor://localhost') {
    $allowed = true;
}
// Localhost with any port (e.g., http://localhost:8080)
elseif (preg_match('#^http://localhost(:\d+)?$#', $origin)) {
    $allowed = true;
}

if ($allowed) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-DB-CONFIG, X-CSRF-TOKEN");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        exit(0);
    }
}

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
define('APP_NAME', $_ENV['APP_NAME'] ?? 'SQLuxe');
define('VERSION', $_ENV['VERSION'] ?? '1.1.0');

// DEFAULTS
define('DEFAULT_HOST', $_ENV['DEFAULT_HOST'] ?? '127.0.0.1');
define('DEFAULT_PORT', $_ENV['DEFAULT_PORT'] ?? '3306');
define('DEFAULT_USER', $_ENV['DEFAULT_USER'] ?? 'root');
define('DEFAULT_PASS', $_ENV['DEFAULT_PASS'] ?? '');
define('MASTER_PASS', $_ENV['MASTER_PASS'] ?? '');
define('API_KEY', $_ENV['API_KEY'] ?? '2026');
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

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function is_api_request() {
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? $_POST['api_key'] ?? $_GET['api_key'] ?? '';
    
    // 1. Check API Key if defined on server
    if (!empty(API_KEY)) {
        if (hash_equals(API_KEY, $provided_key)) {
            header('X-API-Auth-Method: API-KEY');
            return true;
        }
        if (!empty($provided_key)) {
            error_log("[SQLuxe API] API Key Mismatch. Provided: $provided_key");
        }
    }

    // 2. Fallback: Authenticate via DB credentials (User/Pass) If no API Key is enforced or matches
    $api_config = $_SERVER['HTTP_X_DB_CONFIG'] ?? '';
    if ($api_config) {
        $decoded = json_decode($api_config, true);
        if ($decoded && !empty($decoded['username'])) {
            $conn = connect_with_config($decoded, true); // silent mode
            if ($conn instanceof PDO) {
                header('X-API-Auth-Method: DB-CREDS');
                return true;
            }
        }
    }

    return false;
}

function validate_csrf() {
    if (is_api_request()) return; // API requests are authenticated by API_KEY, skip CSRF
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['error' => "CSRF token mismatch or session expired."]);
            exit;
        }
    }
}

function validate_write_access() {
    if (!empty($_SESSION['read_only'])) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['error' => "Security Exception: System is in READ-ONLY mode. Disable to make changes."]);
        exit;
    }
}

// Database Connection Helper
function get_db_connection() {
    if (is_api_request()) {
        // API can provide config in headers for multi-server support
        $api_config = $_SERVER['HTTP_X_DB_CONFIG'] ?? '';
        $config = [];
        if ($api_config) {
            $decoded = json_decode($api_config, true);
            if ($decoded) $config = $decoded;
        }
        
        // MERGE: Honor header values first, but fall back to server-side DEFAULT constants
        if (empty($config['host']) && defined('DEFAULT_HOST')) $config['host'] = DEFAULT_HOST;
        if (empty($config['port']) && defined('DEFAULT_PORT')) $config['port'] = DEFAULT_PORT;
        if (empty($config['username']) && defined('DEFAULT_USER')) $config['username'] = DEFAULT_USER;
        if (empty($config['password']) && defined('DEFAULT_PASS')) $config['password'] = DEFAULT_PASS;
        if (empty($config['port'])) $config['port'] = '3306';
        if (empty($config['host'])) $config['host'] = '127.0.0.1';

        if (!empty($config['username'])) {
            error_log("[SQLuxe] Header Merge Success. Host: {$config['host']}, User: {$config['username']}, DB: " . ($config['database'] ?? 'None'));
            $db = connect_with_config($config);
            if ($db instanceof PDO) {
                $status = ensure_database_selected($db, $config);
                if ($status !== true) return $status;
            }
            return $db;
        }
        
        return "No valid database credentials found on server or in headers.";
    }

    if (!isset($_SESSION['db_config'])) {
        return "No database configuration found.";
    }
    return connect_with_config($_SESSION['db_config']);
}

// QR Data Generator
function get_qr_data() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = strtok($_SERVER['REQUEST_URI'], '?');
    $fullUrl = $protocol . "://" . $host . $uri;
    
    return json_encode([
        'name' => APP_NAME . " (" . $host . ")",
        'url' => $fullUrl,
        'apiKey' => API_KEY,
        'type' => 'sql'
    ]);
}

/**
 * Ensures the database specified in $config is active for the $db connection.
 * Returns true on success, or an error message string on failure.
 */
function ensure_database_selected($db, $config) {
    if (!($db instanceof PDO) || empty($config['database'])) return true;
    
    $target = $config['database'];
    try {
        $current = $db->query("SELECT DATABASE()")->fetchColumn();
        if ($current !== $target) {
            // Use backticks for safety
            $db->exec("USE `$target` ");
            
            // Re-verify
            $new = $db->query("SELECT DATABASE()")->fetchColumn();
            if (!$new || (strtolower($new) !== strtolower($target))) {
                 return "Failed to select database '$target'. Please check permissions or if the database exists.";
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("[SQLuxe] Failed to select database '$target': " . $e->getMessage());
        return "Database selection error: " . $e->getMessage();
    }
}

function connect_with_config($config, $silent = false) {
    if (empty($config['host']) || empty($config['username'])) return "Missing connection parameters.";
    
    $dbPart = !empty($config['database']) ? "dbname={$config['database']};" : "";
    $dsn = "mysql:host={$config['host']};port={$config['port']};{$dbPart}charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_TIMEOUT => 5, // Fast failure for auth checks
        ]);
        return $pdo;
    } catch (PDOException $e) {
        if (!$silent) error_log("[SQLuxe] DB Connection Failed: " . $e->getMessage());
        return "Connection failed: " . $e->getMessage();
    }
}

// Handle Login
if (isset($_POST['action']) && $_POST['action'] === 'connect') {
    $error = null;
    
    // Master Password Mode
    if (!empty($_POST['master_password'])) {
        if ($_POST['master_password'] === MASTER_PASS) {
            $_SESSION['db_config'] = [
                'host' => DEFAULT_HOST,
                'port' => DEFAULT_PORT,
                'username' => DEFAULT_USER,
                'password' => DEFAULT_PASS,
                'database' => $_POST['database'] ?? '',
                'is_master' => true
            ];
        } else {
            $error = "Invalid System Password.";
        }
    } else {
        // Manual Mode
        $_SESSION['db_config'] = [
            'host' => $_POST['host'] ?? DEFAULT_HOST,
            'port' => $_POST['port'] ?? DEFAULT_PORT,
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
            'database' => $_POST['database'] ?? '',
            'is_master' => false
        ];
    }
    
    if (!$error) {
        $conn = get_db_connection();
        if ($conn instanceof PDO) {
            $redirect = $_SERVER['PHP_SELF'];
            if (!empty($_POST['table'])) {
                $redirect .= "?table=" . urlencode($_POST['table']);
            }
            header("Location: " . $redirect);
            exit;
        } else {
            $error = $conn;
            unset($_SESSION['db_config']);
        }
    }
}

// Handle Row Deletion
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'delete_row') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => "Connection failed: " . (string)$db]);
        exit;
    }
    $table = $_POST['table'];
    $pk = $_POST['pk_column'];
    $val = $_POST['pk_value'];
    try {
        $stmt = $db->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
        $stmt->execute([$val]);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle Bulk Deletion
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'delete_rows') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => "Connection failed: " . (string)$db]);
        exit;
    }
    $table = $_POST['table'];
    $pk = $_POST['pk_column'];
    $ids = json_decode($_POST['ids'], true);
    
    if (empty($ids)) {
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare("DELETE FROM `$table` WHERE `$pk` IN ($placeholders)");
        $stmt->execute($ids);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle Row Insertion
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'insert_row') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => "Connection failed: " . (string)$db]);
        exit;
    }
    $table = $_POST['table'];
    $data = json_decode($_POST['data'], true);
    $cols = array_keys($data);
    $placeholders = array_fill(0, count($cols), "?");
    $sql = "INSERT INTO `$table` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $placeholders) . ")";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($data));
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle Row Update
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'update_row') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => "Connection failed: " . (string)$db]);
        exit;
    }
    $table = $_POST['table'];
    $data = json_decode($_POST['data'], true);
    $pk = $_POST['pk_column'];
    $val = $_POST['pk_value'];
    
    $sets = [];
    $params = [];
    foreach ($data as $col => $v) {
        $sets[] = "`$col` = ?";
        $params[] = $v;
    }
    $params[] = $val;
    
    $sql = "UPDATE `$table` SET " . implode(", ", $sets) . " WHERE `$pk` = ?";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle Raw SQL Execution
if (isset($_SESSION['db_config']) && isset($_POST['action']) && $_POST['action'] === 'execute_sql') {
    validate_csrf();
    // Allow SELECT queries even in read-only mode if we want, but simpler to block raw SQL execution entirely for now.
    // Or we could check if it starts with SELECT/SHOW/DESCRIBE
    $sql = trim($_POST['query'] ?? $_POST['sql'] ?? '');
    $is_readonly_query = preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+/i', $sql);
    if (!$is_readonly_query) {
        validate_write_access();
    }
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $query = $sql;
        try {
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            $results = [];
            if (stripos($query, 'SELECT') === 0 || stripos($query, 'SHOW') === 0 || stripos($query, 'DESC') === 0) {
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'data' => $results]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle PWA Manifest
if (isset($_GET['action']) && $_GET['action'] === 'manifest') {
    header('Content-Type: application/manifest+json');
    echo json_encode([
        'name' => APP_NAME,
        'short_name' => APP_NAME,
        'start_url' => './',
        'display' => 'standalone',
        'background_color' => '#0f172a',
        'theme_color' => '#22d3ee',
        'icons' => [
            [
                'src' => 'https://cdn-icons-png.flaticon.com/512/2906/2906206.png',
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable'
            ]
        ]
    ]);
    exit;
}

// Handle Service Worker
if (isset($_GET['action']) && $_GET['action'] === 'sw') {
    header('Content-Type: application/javascript');
    echo "
        const CACHE_NAME = 'sqluxe-v1';
        self.addEventListener('install', (e) => {
            e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(['./'])));
        });
        self.addEventListener('fetch', (e) => {
            e.respondWith(caches.match(e.request).then(response => response || fetch(e.request)));
        });
    ";
    exit;
}

if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'db_stats') {
        ob_start();
        header('Content-Type: application/json');
        $db = get_db_connection();
        if (!($db instanceof PDO)) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => "Connection failed: " . (string)$db]);
            exit;
        }
        try {
            // Check what the engine thinks is active
            $currentDb = $db->query("SELECT DATABASE()")->fetchColumn();
            
            error_log("[SQLuxe API] db_stats called. Active DB: " . ($currentDb ?: 'None'));

            if (empty($currentDb)) {
                // Return database list
                $stmt = $db->query("SHOW DATABASES");
                $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $stats = [];
                foreach ($databases as $dbName) {
                    $stats[] = ['Name' => $dbName, 'is_database' => true];
                }
                ob_clean();
                echo json_encode(['success' => true, 'stats' => $stats, 'db_name' => null]);
                exit;
            }

            // Return table list
            $stmt = $db->query("SHOW TABLE STATUS");
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_clean();
            echo json_encode(['success' => true, 'stats' => $stats, 'db_name' => $currentDb]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($_GET['ajax'] === 'process_list') {
        ob_start();
        header('Content-Type: application/json');
        $db = get_db_connection();
        if (!($db instanceof PDO)) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => "Connection failed: " . (string)$db]);
            exit;
        }
        try {
            $stmt = $db->query("SHOW FULL PROCESSLIST");
            $procs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_clean();
            echo json_encode(['success' => true, 'processes' => $procs]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_GET['ajax']) && $_GET['ajax'] === 'table_data' && isset($_GET['table'])) {
    ob_start();
    header('Content-Type: application/json');
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => "Connection failed: " . (string)$db]);
        exit;
    }

    $table = $_GET['table'];
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $sort = isset($_GET['sort']) ? $_GET['sort'] : '';
    $order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC') ? 'DESC' : 'ASC';
    
    try {
        // Fetch columns once
        $colsStmt = $db->prepare("DESCRIBE `$table`");
        $colsStmt->execute();
        $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
        $validCols = array_map(function($c) { return $c['Field']; }, $cols);
        
        $where = "";
        $queryParams = [];
        if ($search) {
            $whereParts = [];
            foreach ($validCols as $col) {
                $whereParts[] = "`$col` LIKE ?";
                $queryParams[] = "%$search%";
            }
            $where = "WHERE " . implode(" OR ", $whereParts);
        }

        $orderBy = "";
        if ($sort && in_array($sort, $validCols)) {
            $orderBy = "ORDER BY `$sort` $order";
        }
        
        // Count query for accurate pagination during search
        $countStmt = $db->prepare("SELECT COUNT(*) FROM `$table` $where");
        $countStmt->execute($queryParams);
        $totalRows = (int)$countStmt->fetchColumn();

        // Data query
        $stmt = $db->prepare("SELECT * FROM `$table` $where $orderBy LIMIT $limit OFFSET $offset");
        $stmt->execute($queryParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Table Status for engine/size meta
        $statusStmt = $db->query("SHOW TABLE STATUS LIKE " . $db->quote($table));
        $status = $statusStmt->fetch(PDO::FETCH_ASSOC);
        
        $json = json_encode([
            'columns' => $cols,
            'rows' => $rows,
            'meta' => [
                'total_rows' => $totalRows,
                'engine' => $status ? $status['Engine'] : 'N/A',
                'data_length' => $status ? (int)$status['Data_length'] : 0,
                'page' => $page,
                'limit' => $limit,
                'last_page' => $limit > 0 ? ceil($totalRows / $limit) : 1
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        ob_clean();
        echo $json;
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle CSV Export
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_GET['action']) && $_GET['action'] === 'export_csv' && isset($_GET['table'])) {
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $table = $_GET['table'];
        try {
            $stmt = $db->query("SELECT * FROM `$table` LIMIT 5000");
            $rows = $stmt->fetchAll();
            if (!empty($rows)) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $table . '_export.csv"');
                $output = fopen('php://output', 'w');
                fputcsv($output, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($output, $row);
                }
                fclose($output);
            }
            exit;
        } catch (PDOException $e) {
            die("Export failed: " . $e->getMessage());
        }
    }
}

// Handle SQL Export
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_GET['action']) && $_GET['action'] === 'export_sql') {
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $tables = isset($_GET['table']) ? [$_GET['table']] : [];
        if (empty($tables)) {
            $stmt = $db->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $output = "-- SQLuxe SQL Export\n";
        $output .= "-- Host: " . $_SESSION['db_config']['host'] . "\n";
        $dbName = $_SESSION['db_config']['database'] ?? '';
        if (!$dbName && is_api_request()) {
             $api_config = json_decode($_SERVER['HTTP_X_DB_CONFIG'] ?? '{}', true);
             $dbName = $api_config['database'] ?? '';
        }
        $output .= "-- Database: " . $dbName . "\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SHOW CREATE TABLE `$table`");
                $create = $stmt->fetch(PDO::FETCH_ASSOC);
                $output .= "DROP TABLE IF EXISTS `$table`;\n";
                $output .= $create['Create Table'] . ";\n\n";

                $stmt = $db->query("SELECT * FROM `$table` LIMIT 10000"); // Safety limit
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        $cols = array_keys($chunk[0]);
                        $output .= "INSERT INTO `$table` (`" . implode("`, `", $cols) . "`) VALUES \n";
                        $valParts = [];
                        foreach ($chunk as $row) {
                            $vals = [];
                            foreach ($row as $v) {
                                $vals[] = ($v === null) ? 'NULL' : $db->quote($v);
                            }
                            $valParts[] = "(" . implode(", ", $vals) . ")";
                        }
                        $output .= implode(",\n", $valParts) . ";\n";
                    }
                }
                $output .= "\n";
            } catch (Exception $e) {
                $output .= "-- Error exporting $table: " . $e->getMessage() . "\n\n";
            }
        }
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $filename = ($dbName ?: "export") . "_" . (isset($_GET['table']) ? $_GET['table'] . "_" : "") . date('Y-m-d_His') . ".sql";

        if (isset($_GET['gzip']) && $_GET['gzip'] === '1' && function_exists('gzencode')) {
            $output = gzencode($output, 9);
            $filename .= ".gz";
            header('Content-Type: application/x-gzip');
        } else {
            header('Content-Type: text/sql');
        }

        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $output;
        exit;
    }
}

// Handle SQL Import
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'import_sql' && isset($_FILES['sql_file'])) {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        try {
            if ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("File upload failed with error code: " . $_FILES['sql_file']['error']);
            }

            $content = file_get_contents($_FILES['sql_file']['tmp_name']);
            // Detect GZIP (Magic bytes: 1f 8b)
            if (substr($content, 0, 2) === "\x1f\x8b") {
                if (!function_exists('gzdecode')) {
                    throw new Exception("GZIP decoding not supported on this server.");
                }
                $content = gzdecode($content);
            }

            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            
            // Basic SQL splitting by semicolon followed by newline
            $queries = preg_split("/;[\r\n]+/", $content);
            $count = 0;
            foreach ($queries as $q) {
                $q = trim($q);
                if ($q) {
                    $db->exec($q);
                    $count++;
                }
            }
            
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            ob_clean();
            echo json_encode(['success' => true, 'count' => $count]);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json', true, 500);
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle Table Creation
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'create_table') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if (!($db instanceof PDO)) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => "Connection failed"]);
        exit;
    }
    $tableName = $_POST['name'];
    $columns = json_decode($_POST['columns'], true);
    
    $colStrings = [];
    foreach ($columns as $col) {
        $name = trim($col['name']);
        if (!$name) continue;
        $type = $col['type'];
        $len = !empty($col['length']) ? "(" . $col['length'] . ")" : "";
        $null = !empty($col['null']) ? "NULL" : "NOT NULL";
        $ai = !empty($col['ai']) ? "AUTO_INCREMENT" : "";
        $pri = !empty($col['primary']) ? "PRIMARY KEY" : "";
        $colStrings[] = " `$name` $type$len $null $ai $pri";
    }
    
    $sql = "CREATE TABLE `$tableName` (" . implode(", ", $colStrings) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $db->exec($sql);
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json', true, 500);
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle Table Rename
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'rename_table') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $old = $_POST['old_name'];
        $new = $_POST['new_name'];
        try {
            $db->exec("RENAME TABLE `$old` TO `$new`");
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle Table Drop
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'drop_table') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $table = $_POST['table'];
        try {
            $db->exec("DROP TABLE `$table`");
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle Table Optimization
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'optimize_table') {
    validate_csrf();
    validate_write_access();
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        $table = $_POST['table'];
        try {
            $db->exec("OPTIMIZE TABLE `$table`");
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle SQL Explain
if ((isset($_SESSION['db_config']) || is_api_request()) && isset($_POST['action']) && $_POST['action'] === 'explain_query') {
    validate_csrf();
    ob_start();
    $db = get_db_connection();
    if ($db instanceof PDO) {
        try {
            $query = $_POST['query'];
            $stmt = $db->query("EXPLAIN $query");
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_clean();
            echo json_encode(['success' => true, 'data' => $results]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json', true, 500);
            ob_clean();
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Database Switch
if (isset($_GET['action']) && $_GET['action'] === 'switch_db' && isset($_GET['db'])) {
    if (isset($_SESSION['db_config'])) {
        $_SESSION['db_config']['database'] = $_GET['db'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle Read-Only Toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle_readonly') {
    $_SESSION['read_only'] = !($_SESSION['read_only'] ?? false);
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?: $_SERVER['PHP_SELF']));
    exit;
}

$db = get_db_connection();
$isConnected = ($db instanceof PDO);

$tables = [];
$databases = [];
if ($isConnected) {
    try {
        // Fetch Databases (Always allowed on connection)
        $stmt = $db->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch Tables (Only if a specific DB is selected)
        if (!empty($_SESSION['db_config']['database'])) {
            $stmt = $db->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        $error = "Error fetching meta: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo APP_NAME; ?> - Modern MySQL Manager</title>
    <link rel="manifest" href="?action=manifest">
    <meta name="theme-color" content="#22d3ee">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
            .modal-overlay { padding: 0.5rem; }
            .modal-card { max-height: 95vh; }
            .stat-badge { padding: 0.4rem 0.6rem; font-size: 0.75rem; }
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
        .data-table td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-primary); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; height: 50px; vertical-align: middle; }
        .data-table tr:hover td { background: rgba(255, 255, 255, 0.02); }

        .bulk-action-bar { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: var(--bg-surface); border: 1px solid var(--accent); padding: 0.75rem 1.5rem; border-radius: 3rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 5000; display: flex; align-items: center; gap: 1.5rem; animation: slideUpFade 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideUpFade { from { opacity: 0; transform: translate(-50%, 20px); } to { opacity: 1; transform: translate(-50%, 0); } }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 2000; padding: 1.5rem; }
        .modal-card { background: var(--bg-surface); border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; border: 1px solid var(--border); box-shadow: var(--shadow-xl); display: flex; flex-direction: column; }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 1rem; }

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
            .page-header { flex-direction: column; align-items: flex-start !important; gap: 1.25rem; }
            .page-actions { width: 100%; display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-start; }
            .page-actions .btn { padding: 0.5rem 0.75rem; font-size: 0.75rem; flex: 1 1 auto; min-width: 0; }
            .page-actions .btn .btn-text { display: none; } 
            .page-actions .btn-primary { flex: 2 1 auto; }
            .btn-group { width: 100%; border-radius: var(--radius-md); display: flex; }
            .btn-group .btn-group-item { flex: 1; justify-content: center; padding: 0.6rem 0.5rem; }
            .db-grid { grid-template-columns: 1fr !important; }
            .stat-badges-container { width: 100%; overflow-x: auto; padding-bottom: 0.5rem; scrollbar-width: none; }
            .stat-badges-container::-webkit-scrollbar { display: none; }
        }

        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="app">

    <?php if (!$isConnected): ?>
        <div class="login-overlay">
            <div class="login-card" x-data="{ mode: 'master', form: { host: '127.0.0.1', username: '', port: '3306', password: '', database: '' } }">
                <div class="flex items-center gap-2" style="margin-bottom: 2rem;">
                    <div class="logo-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                    </div>
                    <h1 style="font-size: 1.5rem; font-weight: 700;"><?php echo APP_NAME; ?></h1>
                </div>

                <div class="tabs">
                    <div class="tab" :class="mode === 'master' ? 'active' : ''" @click="mode = 'master'">Master Access</div>
                    <div class="tab" :class="mode === 'manual' ? 'active' : ''" @click="mode = 'manual'">Custom Config</div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="error-toast">
                        <strong>Connection Alert:</strong><br>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="connect">
                    
                    <!-- Master Access Mode -->
                    <div x-show="mode === 'master'">
                        <div class="input-group" x-data="{ show: false }">
                            <label>System Password</label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input :type="show ? 'text' : 'password'" name="master_password" class="input-control" placeholder="Enter system password to auto-fill" :required="mode === 'master'" style="padding-right: 2.5rem;">
                                <button type="button" @click="show = !show" style="position: absolute; right: 0.75rem; background: none; border: none; color: var(--text-secondary); cursor: pointer;" title="Toggle Password Visibility">
                                    <svg x-show="!show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg x-show="show" x-cloak width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Default DB (Optional)</label>
                            <input type="text" name="database" class="input-control" placeholder="e.g. mysql" x-model="form.database">
                        </div>
                    </div>

                    <!-- Manual Config Mode -->
                    <div x-show="mode === 'manual'" x-cloak>
                        <div class="input-group">
                            <label>Host</label>
                            <input type="text" name="host" class="input-control" x-model="form.host" :required="mode === 'manual'">
                        </div>
                        <div class="flex gap-2">
                            <div class="input-group" style="flex: 2;">
                                <label>Username</label>
                                <input type="text" name="username" class="input-control" x-model="form.username" :required="mode === 'manual'">
                            </div>
                            <div class="input-group" style="flex: 1;">
                                <label>Port</label>
                                <input type="text" name="port" class="input-control" x-model="form.port">
                            </div>
                        </div>
                        <div class="input-group" x-data="{ show: false }">
                            <label>Password</label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input :type="show ? 'text' : 'password'" name="password" class="input-control" x-model="form.password" style="padding-right: 2.5rem;">
                                <button type="button" @click="show = !show" style="position: absolute; right: 0.75rem; background: none; border: none; color: var(--text-secondary); cursor: pointer;" title="Toggle Password Visibility">
                                    <svg x-show="!show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg x-show="show" x-cloak width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                                </button>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Database</label>
                            <input type="text" name="database" class="input-control" x-model="form.database" placeholder="Optional">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" @click="if(window.haptic) window.haptic('impactMedium')">
                        Connect Database
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
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
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                        </div>
                        <span style="font-weight: 700; font-size: 1.1rem;"><?php echo APP_NAME; ?></span>
                    </div>

                    <button @click="sidebarOpen = false" class="btn btn-ghost toggle-btn" style="padding: 0.25rem; width: auto; height: auto; border: none; display: none;" :style="window.innerWidth < 1024 ? 'display: block' : ''">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <div class="sidebar-section">
                    <?php if (file_exists(FM_FILE)): ?>
                    <a href="<?php echo FM_FILE; ?>" class="btn btn-primary" style="width: 100%; font-size: 0.75rem; padding: 0.6rem; background: rgba(34, 211, 238, 0.1); border: 1px solid var(--accent); color: var(--accent); text-decoration: none; margin-bottom: 0.5rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        SWITCH TO FILES
                    </a>
                    <?php endif; ?>
                    <button @click="showQR = true" class="btn btn-ghost" style="width: 100%; font-size: 0.75rem; padding: 0.6rem; border: 1px solid rgba(255,255,255,0.1); color: var(--text-secondary);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><rect x="7" y="7" width="3" height="3"></rect><rect x="14" y="7" width="3" height="3"></rect><rect x="7" y="14" width="3" height="3"></rect><rect x="14" y="14" width="3" height="3"></rect></svg>
                        CONNECT MOBILE APP
                    </button>
                </div>

                <div class="sidebar-section">
                    <h3 class="section-title">Database</h3>
                    <div x-data="{ open: false, selected: '<?php echo htmlspecialchars($_SESSION['db_config']['database'] ?: '-- Select --'); ?>' }" style="position: relative;">
                        <button @click="open = !open" class="input-control" style="text-align: left; display: flex; align-items: center; justify-content: space-between; padding: 0.625rem 0.875rem; font-size: 0.875rem;">
                            <span x-text="selected"></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" :style="open ? 'transform: rotate(180deg)' : ''" style="transition: transform 0.2s;"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak x-transition class="modal-card" style="position: absolute; top: 110%; left: 0; right: 0; z-index: 100; max-height: 250px; overflow-y: auto; padding: 0.25rem; border: 1px solid var(--border); background: var(--bg-elevated); box-shadow: var(--shadow-xl);">
                            <?php foreach ($databases as $dbName): ?>
                                <button 
                                    class="table-link" 
                                    :class="selected === '<?php echo $dbName; ?>' ? 'active' : ''"
                                    @click="window.location.href = '?action=switch_db&db=<?php echo $dbName; ?>'"
                                >
                                    <?php echo htmlspecialchars($dbName); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="sidebar-section" style="border-top: 1px solid var(--border); padding-top: 1.5rem; margin-top: 1rem;">
                    <div class="flex items-center justify-between" style="margin-bottom: 0.75rem;">
                        <h3 class="section-title" style="margin-bottom: 0;">Bookmarks</h3>
                        <button @click="saveCurrentAsBookmark" class="btn btn-ghost" style="padding: 0.25rem; width: auto; height: auto; border: none; font-size: 0.75rem; color: var(--accent);" title="Bookmark Current Connection">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                        </button>
                    </div>
                    <div class="flex flex-col gap-1">
                        <template x-for="bm in bookmarks">
                            <div class="bookmark-item" @click="loadBookmark(bm)" style="padding: 0.5rem 0.75rem;">
                                <div class="bookmark-info">
                                    <div class="flex items-center gap-1">
                                        <svg x-show="bm.table" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7m0-18v18"/></svg>
                                        <span class="bookmark-name" x-text="bm.table || bm.database || bm.host" style="font-size: 0.75rem;"></span>
                                    </div>
                                    <span x-text="(bm.table ? bm.database + '@' : '') + bm.host" style="font-size: 0.65rem; opacity: 0.6;"></span>
                                </div>
                                <button type="button" @click.stop="removeBookmark(bm)" style="background:none; border:none; color:var(--danger); font-size: 1rem; padding: 0.25rem; opacity: 0.5;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.5">&times;</button>
                            </div>
                        </template>
                        <template x-if="bookmarks.length === 0">
                            <span style="color: var(--text-secondary); font-size: 0.75rem; padding: 0.5rem; opacity: 0.6;">No bookmarks yet.</span>
                        </template>
                    </div>
                </div>

                <div class="sidebar-section" style="border-top: 1px solid var(--border); padding-top: 1.5rem; margin-top: 1rem;">
                    <h3 class="section-title">Maintenance</h3>
                    <div class="flex flex-col gap-1">
                        <button @click="$refs.sqlImportInput.click()" class="table-link" :disabled="loading || isReadOnly" :style="isReadOnly ? 'opacity: 0.4; cursor: not-allowed;' : ''" style="padding: 0.5rem 0.75rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Import SQL / GZIP
                        </button>
                        <input type="file" x-ref="sqlImportInput" style="display:none;" accept=".sql,.gz" @change="importSQL($event)">
                        
                        <a href="?action=export_sql" class="table-link" style="padding: 0.5rem 0.75rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export Full (.sql)
                        </a>
                        <a href="?action=export_sql&gzip=1" class="table-link" style="padding: 0.5rem 0.75rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export Full (.sql.gz)
                        </a>
                        <button @click="fetchProcesses" class="table-link" style="padding: 0.5rem 0.75rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Server Processes
                        </button>
                        <button @click="openCreateTableModal" class="table-link" :disabled="isReadOnly" :style="isReadOnly ? 'opacity: 0.4; cursor: not-allowed;' : ''" style="padding: 0.5rem 0.75rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3zM3 9h18M9 3v18"/></svg>
                            Create New Table
                        </button>
                    </div>
                </div>
                
                <div style="padding: 1.5rem;">
                    <div class="flex items-center justify-between" style="margin-bottom: 0.75rem;">
                        <div class="flex items-center gap-2">
                            <h3 class="section-title" style="margin-bottom: 0;">Tables</h3>
                            <div x-show="loading" class="sidebar-loader animate-spin" style="width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.1); border-top-color: var(--accent); border-radius: 50%;"></div>
                        </div>
                    </div>
                    
                    <div style="position: relative; margin-bottom: 1rem;">
                        <input 
                            type="text" 
                            class="input-control" 
                            placeholder="Find table..." 
                            x-model="tableSearchQuery" 
                            style="padding: 0.4rem 0.75rem 0.4rem 2.2rem; font-size: 0.75rem; border-radius: var(--radius-md);"
                        >
                        <div style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); opacity: 0.5;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <template x-for="table in filteredTables">
                            <div class="flex items-center gap-1">
                                <button @click="selectTable(table)" class="table-link" :class="selectedTable === table ? 'active' : ''" style="flex: 1;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.6;"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7m0-18v18"/></svg>
                                    <span x-text="table"></span>
                                </button>
                                <button @click="saveTableAsBookmark(table)" class="btn btn-ghost" style="padding: 0.25rem; width: auto; height: auto; border: none; opacity: 0.3; color: var(--accent);" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.3">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v11z"/></svg>
                                </button>
                            </div>
                        </template>
                        <template x-if="filteredTables.length === 0">
                            <span style="color: var(--text-secondary); font-size: 0.8rem; padding: 0.5rem; opacity: 0.6;">No tables found.</span>
                        </template>
                    </div>
                </div>
            </aside>

            <header class="header">
                <div class="header-left" style="overflow: hidden; padding-right: 0.5rem;">
                    <button @click.stop="sidebarOpen = !sidebarOpen" class="btn btn-ghost toggle-btn" style="padding: 0.4rem; flex-shrink: 0; margin-right: 0.25rem;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                    </button>
                    <div class="header-info" style="margin-top: 2px; display: flex; align-items: center; gap: 0.4rem; width: 100%;">
                        <span class="header-title mobile-hide" style="font-size: 0.7rem; opacity: 0.4; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo htmlspecialchars($_SESSION['db_config']['username']); ?>@<?php echo htmlspecialchars($_SESSION['db_config']['host']); ?></span>
                        <span class="header-separator mobile-hide" style="margin: 0; opacity: 0.3;">/</span>
                        <span class="header-subtitle" x-show="!selectedTable" style="color: var(--accent); font-weight: 800; background: rgba(34, 211, 238, 0.1); padding: 0.15rem 0.6rem; border-radius: 2rem; font-size: 0.75rem;"><?php echo $_SESSION['db_config']['database'] ?: 'Server View'; ?></span>
                        <template x-if="selectedTable">
                            <div class="flex items-center gap-1" style="min-width: 0; overflow: hidden; display: flex;">
                                <span class="header-subtitle" style="opacity: 0.3; font-size: 0.7rem;">/</span>
                                <span class="header-subtitle" x-text="selectedTable" style="color: var(--accent); font-weight: 800; background: rgba(34, 211, 238, 0.1); padding: 0.15rem 0.6rem; border-radius: 2rem; font-size: 0.75rem;"></span>
                            </div>
                        </template>
                    </div>
                </div>
                
                <div class="header-right flex items-center gap-4">
                    <?php if (file_exists(FM_FILE)): ?>
                    <a href="<?php echo FM_FILE; ?>" class="btn btn-ghost mobile-hide" style="display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--text-secondary); font-weight: 600; font-size: 0.85rem; padding: 0.5rem 0.75rem; border: 1px solid rgba(255,255,255,0.1); border-radius: 0.75rem;" title="Switch to Files">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        <span class="btn-text">Switch to Files</span>
                    </a>
                    <?php endif; ?>

                    <button 
                        @click="window.location.href='?action=toggle_readonly'" 
                        class="btn btn-ghost"
                        :style="isReadOnly ? 'background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3);' : 'opacity: 0.6;'"
                        style="padding: 0.35rem 0.75rem; border-radius: 2rem; font-size: 0.7rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem;"
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span class="btn-text" x-text="isReadOnly ? 'READ-ONLY (SAFE)' : 'UNLOCKED'"></span>
                    </button>

                    <a href="?action=logout" class="btn btn-ghost" style="color: var(--danger); font-size: 0.875rem; flex-shrink: 0; padding: 0.5rem;" title="Disconnect">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span class="btn-text">Disconnect</span>
                    </a>

                    <a href="https://bycomsolutions.com" target="_blank" class="bycom-logo" style="display: flex; align-items: center; padding-left: 0.5rem; border-left: 1px solid rgba(255,255,255,0.1); margin-left: 0.5rem;">
                        <img src="https://bycomsolutions.com/_astro/logo.Bz8u1fa6_Z2e3zIX.webp" style="width: 100px; height: auto; opacity: 0.8; filter: grayscale(100%); transition: all 0.3s ease;" onmouseover="this.style.opacity=1; this.style.filter='grayscale(0%)'" onmouseout="this.style.opacity=0.8; this.style.filter='grayscale(100%)'">
                    </a>
                </div>
            </header>

            <main class="main-content">
                <?php if (isset($error) && $isConnected): ?>
                    <div class="error-toast" style="margin-bottom: 1.5rem;">
                        <strong>System Alert:</strong><br>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <template x-if="isReadOnly">
                    <div style="background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); padding: 0.75rem 1.25rem; border-radius: 12px; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; font-weight: 600;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div style="flex: 1;">
                            <strong>Safety Mode Active:</strong> All data modification features are currently disabled.
                        </div>
                        <button @click="window.location.href='?action=toggle_readonly'" class="btn btn-ghost" style="padding: 0.25rem 0.75rem; font-size: 0.7rem; background: var(--warning); color: #000; font-weight: 800; border: none;">DISABLE</button>
                    </div>
                </template>

                <div x-show="!selectedTable" x-cloak>
                    <div class="flex items-center justify-between" style="margin-bottom: 2rem; gap: 1rem; flex-wrap: wrap;">
                        <h2 style="font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em;">Database Overview</h2>
                        <div style="flex: 1; max-width: 400px; position: relative; min-width: 250px;">
                            <input type="text" class="input-control" placeholder="Search tables..." x-model="homeSearchQuery" style="padding-left: 2.75rem; border-radius: 2rem; background: var(--bg-surface);">
                            <div style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--accent); opacity: 0.7;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                        </div>
                    </div>

                    <div class="db-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                        <template x-for="stat in filteredTableStats">
                            <div @click="selectTable(stat.Name)" class="modal-card clickable-row" style="padding:1.25rem; border: 1px solid var(--border); position: relative; overflow: hidden; height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                                <div>
                                    <div class="flex items-start justify-between" style="margin-bottom: 0.75rem;">
                                        <div class="flex flex-col min-width: 0; overflow: hidden;">
                                            <span style="font-weight: 800; font-size: 1.1rem; color: var(--accent); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" x-text="stat.Name"></span>
                                            <span style="font-size: 0.65rem; color: var(--text-secondary); opacity: 0.6; text-transform: uppercase; letter-spacing: 0.05em;" x-text="stat.Engine + (stat.Collation ? ' • ' + stat.Collation : '')"></span>
                                        </div>
                                        <div class="logo-icon" style="width: 28px; height: 28px; flex-shrink: 0; background: rgba(34, 211, 238, 0.1); color: var(--accent);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7m0-18v18"/></svg>
                                        </div>
                                    </div>
                                    <div class="flex gap-4" style="margin-bottom: 1rem;">
                                        <div class="flex flex-col">
                                            <span style="font-size: 0.6rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; margin-bottom: 0.15rem;">Rows</span>
                                            <span style="font-weight: 800; font-size: 1rem;" x-text="new Intl.NumberFormat().format(stat.Rows)"></span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span style="font-size: 0.6rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 700; margin-bottom: 0.15rem;">Size</span>
                                            <span style="font-weight: 800; font-size: 1rem;" x-text="(stat.Data_length > 1024*1024 ? (stat.Data_length / (1024*1024)).toFixed(2) + ' MB' : (stat.Data_length / 1024).toFixed(2) + ' KB')"></span>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.4rem; border-top: 1px solid var(--border); padding-top: 1rem; margin-top: auto;">
                                    <button @click.stop="renameTable(stat.Name)" class="btn btn-ghost" :disabled="isReadOnly" :style="isReadOnly ? 'opacity: 0.4; cursor: not-allowed; pointer-events: none;' : ''" style="flex: 1; padding: 0.4rem; font-size: 0.7rem; font-weight: 700;">RENAME</button>
                                    <button @click.stop="optimizeTable(stat.Name)" class="btn btn-ghost" :disabled="isReadOnly" :style="isReadOnly ? 'opacity: 0.4; cursor: not-allowed; pointer-events: none;' : ''" style="flex: 1; padding: 0.4rem; font-size: 0.7rem; font-weight: 700; color: var(--accent);">OPTI</button>
                                    <button @click.stop="dropTable(stat.Name)" class="btn btn-ghost" :disabled="isReadOnly" :style="isReadOnly ? 'opacity: 0.4; cursor: not-allowed; pointer-events: none;' : ''" style="flex: 1; padding: 0.4rem; font-size: 0.7rem; font-weight: 700; color: var(--danger); border-color: rgba(239, 68, 68, 0.2);">DROP</button>
                                </div>
                            </div>
                        </template>
                        
                        <!-- Add Table Card -->
                        <div @click="openCreateTableModal" class="modal-card clickable-row" style="padding:1.75rem; border: 2px dashed var(--border); background: transparent; display: flex; align-items: center; justify-content: center; min-height: 200px;">
                            <div class="flex flex-col items-center gap-3">
                                <div class="logo-icon" style="width: 48px; height: 48px; background: rgba(34, 211, 238, 0.05); color: var(--text-secondary);">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </div>
                                <span style="font-weight: 700; color: var(--text-secondary); font-size: 0.875rem; letter-spacing: 0.05em;">CREATE NEW TABLE</span>
                            </div>
                        </div>
                    </div>
                    
                    <template x-if="filteredTableStats.length === 0 && homeSearchQuery">
                        <div style="text-align: center; padding: 4rem;">
                            <span style="color: var(--text-secondary); font-size: 1rem; opacity: 0.5;">No tables found matching "<span x-text="homeSearchQuery"></span>"</span>
                        </div>
                    </template>
                </div>

                <div x-show="selectedTable" x-cloak>
                    <div class="flex items-center justify-between page-header" style="margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                        <div class="flex items-center gap-3">
                            <h2 style="font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em;" x-text="selectedTable"></h2>
                            <div class="stat-badge" style="background: var(--accent); color: #000; font-weight: 800; border: none; font-size: 0.65rem; padding: 0.2rem 0.6rem;">TABLE</div>
                        </div>
                        
                        <div class="page-actions">
                            <div class="btn-group" style="box-shadow: 0 4px 14px 0 rgba(0, 0, 0, 0.2);">
                                <button 
                                    @click="openInsertModal" 
                                    class="btn-group-item btn-primary-item" 
                                    :disabled="isReadOnly" 
                                    :style="isReadOnly ? 'opacity:0.4; cursor:not-allowed; background:#444; color:#999;' : ''"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    <span class="btn-text">New Row</span>
                                </button>
                                <button @click="showConsole = true" class="btn-group-item" title="SQL Console">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg>
                                    <span class="btn-text">SQL</span>
                                </button>
                                <button @click="selectTable(selectedTable)" class="btn-group-item" title="Refresh Data">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="loading ? 'animate-spin' : ''"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                    <span class="btn-text">Refresh</span>
                                </button>
                                <a :href="'?action=export_sql&table=' + selectedTable" class="btn-group-item" title="Export SQL">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mobile-hide"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <span style="font-size: 0.65rem; font-weight: 800; background: rgba(255,255,255,0.05); padding: 0.15rem 0.35rem; border-radius: 4px;">SQL</span>
                                </a>
                                <a :href="'?action=export_sql&table=' + selectedTable + '&gzip=1'" class="btn-group-item" title="Export Gzipped SQL">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mobile-hide"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <span style="font-size: 0.65rem; font-weight: 800; background: rgba(255,255,255,0.05); padding: 0.15rem 0.35rem; border-radius: 4px;">GZ</span>
                                </a>
                                <a :href="'?action=export_csv&table=' + selectedTable" class="btn-group-item" title="Download CSV">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mobile-hide"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <span style="font-size: 0.65rem; font-weight: 800; background: rgba(255,255,255,0.05); padding: 0.15rem 0.35rem; border-radius: 4px;">CSV</span>
                                </a>
                                <button @click="optimizeTable(selectedTable)" class="btn-group-item" title="Optimize Table" :disabled="isReadOnly" :style="isReadOnly ? 'opacity:0.4; cursor:not-allowed;' : ''" style="border-right: none;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                    <span class="btn-text">Optimize</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Metadata & Search Bar -->
                    <div class="flex items-center justify-between gap-4" style="margin-bottom: 2rem; flex-wrap: wrap;">
                        <div class="stat-badges-container flex items-center gap-2">
                            <div class="stat-badge">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                                <span class="stat-value" x-text="tableData.meta?.total_rows || 0"></span>
                                <span>rows</span>
                            </div>
                            <div class="stat-badge">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                <span class="stat-value" x-text="((tableData.meta?.data_length || 0) / 1024).toFixed(2)"></span>
                                <span>KB</span>
                            </div>
                            <div class="stat-badge">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="18" rx="2" ry="2"></rect><line x1="2" y1="9" x2="22" y2="9"></line><line x1="2" y1="15" x2="22" y2="15"></line><line x1="10" y1="9" x2="10" y2="21"></line></svg>
                                <span class="stat-value" x-text="tableData.meta?.engine || 'N/A'"></span>
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 200px; max-width: 400px; position: relative;">
                            <input 
                                type="text" 
                                class="input-control" 
                                placeholder="Search all columns..." 
                                x-model="searchQuery" 
                                @input.debounce.500ms="currentPage = 1; selectTable(selectedTable)"
                                style="padding: 0.5rem 1rem 0.5rem 2.5rem; font-size: 0.875rem;"
                            >
                            <div style="position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); pointer-events: none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                        </div>
                    </div>

                    <div class="data-table-container" :style="loading ? 'opacity: 0.5; pointer-events: none;' : ''">
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;">
                                            <input type="checkbox" @change="toggleSelectAllRows" :checked="selectedRows.length === tableData.rows.length && tableData.rows.length > 0" style="accent-color: var(--accent); width: 16px; height: 16px; cursor: pointer;">
                                        </th>
                                        <th style="width: 40px; text-align: center; color: var(--text-secondary); font-size: 0.7rem;">#</th>
                                        <template x-for="(col, index) in tableData.columns">
                                            <th @click="sortBy(col.Field)" style="cursor: pointer; user-select: none;">
                                                <div class="flex items-center justify-between gap-2">
                                                    <div class="flex flex-col">
                                                        <span style="font-size: 0.65rem; opacity: 0.5;" x-text="'C' + (index + 1)"></span>
                                                        <span x-text="col.Field"></span>
                                                    </div>
                                                    <div style="color: var(--accent);" x-show="sortField === col.Field">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                            <polyline points="18 15 12 9 6 15" x-show="sortOrder === 'ASC'"></polyline>
                                                            <polyline points="6 9 12 15 18 9" x-show="sortOrder === 'DESC'"></polyline>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </th>
                                        </template>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, index) in tableData.rows">
                                        <tr @click="editRow(row)" class="clickable-row" :style="selectedRows.includes(row) ? 'background: rgba(34, 211, 238, 0.05);' : ''">
                                            <td style="text-align: center;" @click.stop>
                                                <input type="checkbox" :value="row" x-model="selectedRows" style="accent-color: var(--accent); width: 16px; height: 16px; cursor: pointer;">
                                            </td>
                                            <td style="text-align: center; color: var(--text-secondary); border-right: 1px solid var(--border); font-size: 0.75rem;" x-text="index + 1"></td>
                                            <template x-for="col in tableData.columns">
                                                <td :title="row[col.Field]">
                                                    <template x-if="row[col.Field] === null">
                                                        <span style="color: var(--text-secondary); opacity: 0.5; font-size: 0.75rem;">NULL</span>
                                                    </template>
                                                    <template x-if="row[col.Field] !== null">
                                                        <div style="display: flex; align-items: center;">
                                                            <template x-if="typeof row[col.Field] === 'string' && (row[col.Field].match(/\.(jpeg|jpg|gif|png|webp|svg)/i) || row[col.Field].startsWith('data:image'))">
                                                                <div @click.stop="window.open(row[col.Field])" style="cursor: zoom-in; position: relative; display: flex; align-items: center; justify-content: center; background: #000; border-radius: 4px; overflow: hidden; height: 32px; width: 48px; border: 1px solid var(--border);">
                                                                    <img :src="row[col.Field]" style="height: 100%; width: 100%; object-fit: contain;">
                                                                </div>
                                                            </template>
                                                            <template x-if="!(typeof row[col.Field] === 'string' && (row[col.Field].match(/\.(jpeg|jpg|gif|png|webp|svg)/i) || row[col.Field].startsWith('data:image')))">
                                                                <span x-text="row[col.Field]" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </td>
                                            </template>
                                            <td style="text-align: right;" @click.stop>
                                                <div class="flex gap-2 justify-end" x-show="!isReadOnly">
                                                    <button @click="editRow(row)" style="background:none; border:none; cursor:pointer; color:var(--accent); padding: 0.5rem; opacity: 0.6;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                    </button>
                                                    <button @click="deleteRow(row)" style="background:none; border:none; cursor:pointer; color:var(--danger); padding: 0.5rem; opacity: 0.6;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                    </button>
                                                </div>
                                                <div x-show="isReadOnly" style="opacity: 0.2; text-align: right; padding-right: 0.5rem;" title="Modifications disabled">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination Footer -->
                        <div class="flex items-center justify-between" style="padding: 1.5rem; border-top: 1px solid var(--border); background: rgba(255, 255, 255, 0.01);">
                            <div style="font-size: 0.815rem; color: var(--text-secondary);">
                                Showing <span class="stat-value" x-text="tableData.rows?.length || 0"></span> of <span class="stat-value" x-text="tableData.meta?.total_rows || 0"></span> entries
                            </div>
                            <div class="flex items-center gap-2">
                                <button 
                                    class="btn btn-ghost" 
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem; border-radius: 2rem;"
                                    :disabled="currentPage === 1"
                                    @click="currentPage--; selectTable(selectedTable)"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                    PRV
                                </button>
                                <div class="flex items-center gap-1">
                                    <template x-for="p in Array.from({length: Math.min(5, tableData.meta?.last_page || 1)}, (_, i) => i + 1)">
                                        <div 
                                            class="flex items-center justify-center" 
                                            style="width: 28px; height: 28px; border-radius: 50%; font-size: 0.75rem; font-weight: 700; cursor: pointer;"
                                            :style="currentPage === p ? 'background: var(--accent); color: #000;' : 'color: var(--text-secondary);'"
                                            @click="currentPage = p; selectTable(selectedTable)"
                                            x-text="p"
                                        ></div>
                                    </template>
                                </div>
                                <button 
                                    class="btn btn-ghost" 
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem; border-radius: 2rem;"
                                    :disabled="currentPage >= (tableData.meta?.last_page || 1)"
                                    @click="currentPage++; selectTable(selectedTable)"
                                >
                                    NXT
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <!-- SQL Console Modal -->
            <div class="modal-overlay" x-show="showConsole" x-cloak x-transition>
                <div class="modal-card" style="max-width: 1100px; width: 95%; height: 90vh;" @click.outside="showConsole = false">
                    <div class="modal-header">
                        <div class="flex items-center gap-2">
                            <div class="logo-icon" style="width: 24px; height: 24px; background: #000; color: var(--accent);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="4 17 10 11 4 5"></polyline><line x1="12" y1="19" x2="20" y2="19"></line></svg>
                            </div>
                            <h3 style="font-weight: 700; font-size: 0.875rem; letter-spacing: 0.05em;">SQL WORKBENCH</h3>
                        </div>
                        <button @click="showConsole = false" class="btn btn-ghost" style="padding: 0.25rem;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="modal-body" style="flex: 1; display: grid; grid-template-columns: 240px 1fr; gap: 1rem; background: #000; padding: 1rem; overflow: hidden;">
                        <!-- Console Sidebar -->
                        <div style="border-right: 1px solid #222; padding-right: 1rem; display: flex; flex-direction: column; gap: 1.5rem; overflow-y: auto;">
                            <div>
                                <h4 style="font-size: 0.65rem; color: #666; text-transform: uppercase; margin-bottom: 0.75rem;">Snippets</h4>
                                <div class="flex flex-col gap-1">
                                    <template x-for="(s, idx) in snippets">
                                        <div class="flex items-center gap-1">
                                            <button @click="sqlQuery = s.query" class="table-link" style="flex: 1; padding: 0.4rem; font-size: 0.75rem; color: #ccc;" x-text="s.name"></button>
                                            <button @click="removeSnippet(idx)" style="color: var(--danger); background:transparent; border:none; padding: 0 0.25rem; font-size: 1.25rem; cursor: pointer;">&times;</button>
                                        </div>
                                    </template>
                                    <button @click="saveSnippet" class="btn btn-ghost" style="font-size: 0.65rem; padding: 0.3rem;">+ Save Snippet</button>
                                </div>
                            </div>
                            <div>
                                <h4 style="font-size: 0.65rem; color: #666; text-transform: uppercase; margin-bottom: 0.75rem;">History</h4>
                                <div class="flex flex-col gap-1">
                                    <template x-for="h in queryHistory.slice(0, 10)">
                                        <button @click="sqlQuery = h" class="table-link" style="padding: 0.4rem; font-size: 0.7rem; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border: none; background: transparent; text-align: left;" x-text="h"></button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Editor & Results -->
                        <div style="display: flex; flex-direction: column; gap: 1rem; overflow: hidden;">
                            <div style="flex: 0 0 200px; display: flex; flex-direction: column; gap: 0.75rem;">
                                <div class="flex items-center justify-between">
                                    <span style="color: #666; font-size: 0.75rem; font-family: monospace;">QUERY EDITOR</span>
                                    <div class="flex gap-2">
                                        <button class="btn btn-ghost" style="padding: 0.2rem 0.6rem; font-size: 0.7rem; color: #aaa;" @click="runExplain" :disabled="!sqlQuery">EXPLAIN</button>
                                        <select 
                                            class="input-control" 
                                            style="width: auto; padding: 0.2rem 0.6rem; font-size: 0.7rem; height: auto; background: #111; color: var(--accent);"
                                            @change="if($event.target.value) { sqlQuery = $event.target.value; $event.target.value = ''; }"
                                        >
                                            <option value="">Templates...</option>
                                            <option :value="'SELECT * FROM `' + selectedTable + '` LIMIT 10;'">Select All</option>
                                            <option :value="'INSERT INTO `' + (selectedTable || 'table') + '` (...) VALUES (...);'">Insert Row</option>
                                        </select>
                                    </div>
                                </div>
                                <textarea 
                                    class="input-control" 
                                    style="flex: 1; font-family: 'Fira Code', 'Courier New', monospace; resize: none; background: #000; color: #0f0; border-color: #333; font-size: 0.9rem;" 
                                    placeholder="-- Enter SQL query here...&#10;SELECT * FROM users LIMIT 10;"
                                    x-model="sqlQuery"
                                    @keydown.ctrl.enter="executeSQL"
                                ></textarea>
                                <div class="flex justify-between items-center">
                                    <span style="font-size: 0.65rem; color: #444;">Ctrl+Enter to run</span>
                                    <button 
                                        class="btn btn-primary" 
                                        :disabled="isReadOnly && !sqlQuery.trim().match(/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+/i)"
                                        :style="isReadOnly && !sqlQuery.trim().match(/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+/i) ? 'opacity:0.4; cursor:not-allowed; background:#444;' : 'background: var(--accent); color: #000;'"
                                        style="width: auto; margin-top: 0; font-size: 0.75rem; font-weight: 800;" 
                                        @click="executeSQL"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                        EXECUTE
                                    </button>
                                </div>
                            </div>
                            
                            <!-- SQL Result View -->
                            <div x-show="sqlResults" x-cloak style="flex: 1; overflow: auto; border: 1px solid #222; border-radius: 4px; background: #050505;">
                                <table class="data-table" style="background: transparent; min-width: 100%;">
                                    <thead style="position: sticky; top: 0; z-index: 1;">
                                        <tr>
                                            <template x-for="key in Object.keys(sqlResults?.[0] || {})">
                                                <th x-text="key" style="color: #0f0; border-color: #222; background: #0a0a0a; font-family: monospace; font-size: 0.75rem; padding: 0.5rem; border-bottom: 2px solid #333;"></th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="row in sqlResults">
                                            <tr>
                                                <template x-for="val in Object.values(row)">
                                                    <td x-text="val !== null ? val : 'NULL'" :style="val === null ? 'opacity: 0.3' : ''" style="color: #ccc; border-color: #111; font-family: monospace; font-size: 0.75rem; padding: 0.5rem;"></td>
                                                </template>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <template x-if="sqlResults && sqlResults.length === 0">
                                    <div style="padding: 2rem; text-align: center; color: #666; font-family: monospace; font-size: 0.875rem;">Command executed successfully.</div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Insert Modal -->
            <div class="modal-overlay" x-show="showInsertModal" x-cloak x-transition>
                <div class="modal-card" @click.outside="showInsertModal = false">
                    <div class="modal-header">
                        <h3 style="font-weight: 700;">Insert New Row</h3>
                        <button @click="showInsertModal = false" class="btn btn-ghost" style="padding: 0.25rem;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="modal-body">
                        <template x-for="col in tableData.columns">
                            <div class="input-group" x-show="col.Extra !== 'auto_increment'">
                                <label x-text="col.Field"></label>
                                <input type="text" class="input-control" x-model="insertForm[col.Field]" :placeholder="col.Type">
                            </div>
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button @click="showInsertModal = false" class="btn btn-ghost">Cancel</button>
                        <button @click="insertRow" class="btn btn-primary" style="margin-top:0; width:auto;">Save Record</button>
                    </div>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal-overlay" x-show="showEditModal" x-cloak x-transition>
                <div class="modal-card" @click.outside="showEditModal = false">
                    <div class="modal-header">
                        <h3 style="font-weight: 700;">Edit Record</h3>
                        <button @click="showEditModal = false" class="btn btn-ghost" style="padding: 0.25rem;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="modal-body">
                        <template x-for="col in tableData.columns">
                            <div class="input-group">
                                <label x-text="col.Field"></label>
                                <input type="text" class="input-control" x-model="editForm[col.Field]" :placeholder="col.Type" :disabled="col.Key === 'PRI' || col.Extra === 'auto_increment'">
                                <span x-show="col.Key === 'PRI' || col.Extra === 'auto_increment'" style="font-size: 0.7rem; color: var(--text-secondary); margin-top: 0.25rem; display: block;">Primary key (Read-only)</span>
                            </div>
                        </template>
                    </div>
                    <div class="modal-footer" style="gap: 0.5rem;">
                        <button @click="showEditModal = false" class="btn btn-ghost">Cancel</button>
                        <div style="flex: 1;"></div>
                        <button 
                            @click="if(await deleteRow(editForm)) showEditModal = false" 
                            x-show="!isReadOnly"
                            class="btn btn-ghost" 
                            style="color: var(--danger); border-color: rgba(239, 68, 68, 0.2);"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Delete
                        </button>
                        <button @click="updateRow" class="btn btn-primary" style="margin-top:0; width:auto;">Update Record</button>
                    </div>
                </div>
            </div>

            <!-- Create Table Modal -->
            <div class="modal-overlay" x-show="showCreateTableModal" x-cloak x-transition>
                <div class="modal-card" style="max-width: 800px; width: 95%;" @click.outside="showCreateTableModal = false">
                    <div class="modal-header">
                        <h3 style="font-weight: 700;">Create New Table</h3>
                        <button @click="showCreateTableModal = false" class="btn btn-ghost" style="padding: 0.25rem;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="input-group" style="margin-bottom: 2rem;">
                            <label style="font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 800; margin-bottom: 0.5rem; display: block;">Table Name</label>
                            <input type="text" class="input-control" x-model="newTable.name" placeholder="e.g. users, products..." style="font-size: 1.1rem; font-weight: 700;">
                        </div>
                        <div style="margin-top: 1.5rem;">
                            <h4 style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 1rem; text-transform: uppercase; font-weight: 800;">Columns Definition</h4>
                            <div class="flex flex-col gap-3">
                                <template x-for="(col, index) in newTable.columns">
                                    <div class="flex items-end gap-2" style="background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 12px; border: 1px solid var(--border); transition: border-color 0.2s;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
                                        <div style="flex: 2;">
                                            <label style="font-size: 0.6rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem; font-weight: 700;">NAME</label>
                                            <input type="text" class="input-control" x-model="col.name" style="padding: 0.5rem; font-size: 0.85rem; font-family: monospace;">
                                        </div>
                                        <div style="flex: 1.5;">
                                            <label style="font-size: 0.6rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem; font-weight: 700;">TYPE</label>
                                            <select class="input-control" x-model="col.type" style="padding: 0.5rem; font-size: 0.85rem;">
                                                <option>INT</option><option>VARCHAR</option><option>TEXT</option><option>DATE</option><option>DATETIME</option><option>TIMESTAMP</option><option>DECIMAL</option><option>BOOLEAN</option><option>JSON</option>
                                            </select>
                                        </div>
                                        <div style="flex: 1;">
                                            <label style="font-size: 0.6rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem; font-weight: 700;">LEN</label>
                                            <input type="text" class="input-control" x-model="col.length" placeholder="255" style="padding: 0.5rem; font-size: 0.85rem;">
                                        </div>
                                        <div class="flex gap-3" style="padding-bottom: 0.6rem; margin-bottom: 0.25rem;">
                                            <label class="flex flex-col items-center gap-1" style="font-size: 0.55rem; cursor: pointer; color: var(--text-secondary); font-weight: 800;"><input type="checkbox" x-model="col.primary" style="accent-color: var(--accent);"> PK</label>
                                            <label class="flex flex-col items-center gap-1" style="font-size: 0.55rem; cursor: pointer; color: var(--text-secondary); font-weight: 800;"><input type="checkbox" x-model="col.ai" style="accent-color: var(--accent);"> AI</label>
                                            <label class="flex flex-col items-center gap-1" style="font-size: 0.55rem; cursor: pointer; color: var(--text-secondary); font-weight: 800;"><input type="checkbox" x-model="col.null" style="accent-color: var(--accent);"> NULL</label>
                                        </div>
                                        <button @click="removeTableColumn(index)" style="background:none; border:none; color:var(--danger); font-size: 1.5rem; padding-bottom: 0.5rem; cursor: pointer; opacity: 0.5;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.5">&times;</button>
                                    </div>
                                </template>
                                <button @click="addTableColumn" class="btn btn-ghost" style="width: auto; padding: 0.75rem 1.5rem; font-size: 0.75rem; border-style: dashed; border-width: 2px;">+ Add Field</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding-top: 2rem;">
                        <button @click="showCreateTableModal = false" class="btn btn-ghost">Cancel</button>
                        <button @click="createTable" class="btn btn-primary" style="margin-top:0; width:auto; padding-left: 2rem; padding-right: 2rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v13a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                            Save Table Structure
                        </button>
                    </div>
                </div>
            </div>

            <!-- Process Monitor Modal -->
            <div class="modal-overlay" x-show="showProcessModal" x-cloak x-transition>
                <div class="modal-card" style="max-width: 1000px; width: 95%; height: 80vh;" @click.outside="showProcessModal = false">
                    <div class="modal-header">
                        <div class="flex items-center gap-2">
                            <div class="pulse-dot" style="width: 10px; height: 10px; background: #27c93f; border-radius: 50%;"></div>
                            <h3 style="font-weight: 700;">Live Process Monitor</h3>
                        </div>
                        <div class="flex gap-2">
                             <button @click="fetchProcesses" class="btn btn-ghost" style="padding: 0.25rem;" title="Refresh">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :class="loading ? 'animate-spin' : ''"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            </button>
                            <button @click="showProcessModal = false" class="btn btn-ghost" style="padding: 0.25rem;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                    </div>
                    <div class="modal-body" style="padding: 0; overflow: auto; background: #000;">
                        <table class="data-table" style="background: transparent; min-width: 900px;">
                            <thead style="position: sticky; top: 0; z-index: 1;">
                                <tr>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">ID</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">USER</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">HOST</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">DB</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">CMD</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">TIME</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">STATE</th>
                                    <th style="color: #0f0; border-color: #222; background: #0a0a0a; font-size: 0.7rem; font-weight: 800;">INFO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="p in processes">
                                    <tr style="border-bottom: 1px solid #111;">
                                        <td x-text="p.Id" style="color: #888; font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.User" style="color: #ccc; font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.Host" style="color: #666; font-family: monospace; font-size: 0.65rem;"></td>
                                        <td x-text="p.db" style="color: var(--accent); font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.Command" style="color: #aaa; font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.Time + 's'" :style="p.Time > 10 ? 'color: var(--danger)' : 'color: var(--success)'" style="font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.State" style="color: #666; font-family: monospace; font-size: 0.75rem;"></td>
                                        <td x-text="p.Info" style="color: #eee; font-family: monospace; font-size: 0.7rem; white-space: normal; max-width: 300px; word-break: break-all;"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Bar -->
            <div class="bulk-action-bar" x-show="selectedRows.length > 0" x-cloak x-transition>
                <div style="font-size: 0.875rem; font-weight: 600;">
                    <span x-text="selectedRows.length" style="color: var(--accent);"></span> selected
                </div>
                <div class="flex gap-2">
                    <button @click="selectedRows = []" class="btn btn-ghost" style="padding: 0.4rem 1rem; font-size: 0.75rem; border-radius: 2rem;">Clear</button>
                    <button @click="deleteSelectedRows" class="btn btn-primary" :disabled="isReadOnly" :style="isReadOnly ? 'opacity:0.4; cursor:not-allowed; background:#444;' : 'background: var(--danger);'" style="padding: 0.4rem 1rem; font-size: 0.75rem; border-radius: 2rem; color: #fff; margin: 0; width: auto; border: none;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Delete
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

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

        document.addEventListener('alpine:init', () => {
            Alpine.data('login', () => ({
                mode: 'master',
                saveBookmark: false,
                bookmarks: JSON.parse(localStorage.getItem('sqluxe_bookmarks') || '[]'),
                form: { host: '127.0.0.1', username: '', port: '3306', password: '', database: '' },

                saveToBookmarks() {
                    const exists = this.bookmarks.find(b => b.host === this.form.host && b.database === this.form.database);
                    if (!exists) {
                        this.bookmarks.push({...this.form});
                        localStorage.setItem('sqluxe_bookmarks', JSON.stringify(this.bookmarks));
                        window.haptic('success');
                    }
                },
                loadBookmark(bm) {
                    this.form = {...bm};
                    this.$nextTick(() => {
                        window.haptic('impactMedium');
                        this.$el.closest('form').submit();
                    });
                },
                removeBookmark(bm) {
                        this.bookmarks = this.bookmarks.filter(b => b !== bm);
                        localStorage.setItem('sqluxe_bookmarks', JSON.stringify(this.bookmarks));
                        window.haptic('warning');
                    }
            }))

            Alpine.data('app', () => ({
                sidebarOpen: false,
                selectedTable: null,
                tableData: { columns: [], rows: [] },
                loading: false,
                showInsertModal: false,
                showEditModal: false,
                showConsole: false,
                insertForm: {},
                editForm: {},
                originalRow: null,
                showQR: false,
                qrData: <?php echo json_encode(get_qr_data()); ?>,
                searchQuery: '',
                tableSearchQuery: '',
                sqlQuery: '',
                sqlResults: null,
                sortField: '',
                sortOrder: 'ASC',
                currentPage: 1,
                selectedRows: [],
                bookmarks: JSON.parse(localStorage.getItem('sqluxe_bookmarks') || '[]'),
                allTables: <?php echo json_encode($tables); ?>,
                tableStats: [],
                homeSearchQuery: '',
                showCreateTableModal: false,
                queryHistory: JSON.parse(localStorage.getItem('sqluxe_history') || '[]'),
                snippets: JSON.parse(localStorage.getItem('sqluxe_snippets') || '[]'),
                processes: [],
                showProcessModal: false,
                isReadOnly: <?php echo !empty($_SESSION['read_only']) ? 'true' : 'false'; ?>,
                csrfToken: '<?php echo $_SESSION['csrf_token']; ?>',
                newTable: { name: '', columns: [{ name: 'id', type: 'INT', length: '11', primary: true, ai: true, null: false }] },

                init() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const table = urlParams.get('table');
                    if (table) {
                        this.selectTable(table);
                    } else if (this.allTables.length > 0) {
                        this.fetchDbStats();
                    }
                },

                get filteredTables() {
                    if (!this.tableSearchQuery) return this.allTables;
                    const q = this.tableSearchQuery.toLowerCase();
                    return this.allTables.filter(t => t.toLowerCase().includes(q));
                },

                get filteredTableStats() {
                    if (!this.homeSearchQuery) return this.tableStats;
                    const q = this.homeSearchQuery.toLowerCase();
                    return this.tableStats.filter(s => s.Name.toLowerCase().includes(q));
                },

                async fetchDbStats() {
                    this.loading = true;
                    try {
                        const res = await fetch('?ajax=db_stats');
                        const data = await res.json();
                        if (data.success) {
                            this.tableStats = data.stats;
                        }
                    } catch (e) {
                        console.error('Stats error:', e);
                    } finally {
                        this.loading = false;
                    }
                },

                saveCurrentAsBookmark() {
                    const current = {
                        host: '<?php echo $_SESSION['db_config']['host']; ?>',
                        username: '<?php echo $_SESSION['db_config']['username']; ?>',
                        port: '<?php echo $_SESSION['db_config']['port']; ?>',
                        password: '<?php echo $_SESSION['db_config']['password']; ?>',
                        database: '<?php echo $_SESSION['db_config']['database']; ?>'
                    };
                    const exists = this.bookmarks.find(b => b.host === current.host && b.database === current.database && b.username === current.username);
                    if (!exists) {
                        this.bookmarks.push(current);
                        localStorage.setItem('sqluxe_bookmarks', JSON.stringify(this.bookmarks));
                        alert('Bookmark saved!');
                    } else {
                        alert('Bookmark already exists.');
                    }
                },
                saveTableAsBookmark(table) {
                    const current = {
                        host: '<?php echo $_SESSION['db_config']['host']; ?>',
                        username: '<?php echo $_SESSION['db_config']['username']; ?>',
                        port: '<?php echo $_SESSION['db_config']['port']; ?>',
                        password: '<?php echo $_SESSION['db_config']['password']; ?>',
                        database: '<?php echo $_SESSION['db_config']['database']; ?>',
                        table: table
                    };
                    const exists = this.bookmarks.find(b => b.host === current.host && b.database === current.database && b.table === current.table);
                    if (!exists) {
                        this.bookmarks.push(current);
                        localStorage.setItem('sqluxe_bookmarks', JSON.stringify(this.bookmarks));
                        alert('Table bookmarked!');
                    } else {
                        alert('Table bookmark already exists.');
                    }
                },
                loadBookmark(bm) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '?action=connect';
                    const action = document.createElement('input');
                    action.type = 'hidden'; action.name = 'action'; action.value = 'connect';
                    form.appendChild(action);
                    
                    Object.keys(bm).forEach(key => {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = key; input.value = bm[key];
                        form.appendChild(input);
                    });
                    document.body.appendChild(form);
                    form.submit();
                },
                removeBookmark(bm) {
                    this.bookmarks = this.bookmarks.filter(b => b !== bm);
                    localStorage.setItem('sqluxe_bookmarks', JSON.stringify(this.bookmarks));
                },

                openCreateTableModal() {
                    this.newTable = { name: '', columns: [{ name: 'id', type: 'INT', length: '11', primary: true, ai: true, null: false }] };
                    this.showCreateTableModal = true;
                },
                addTableColumn() {
                    this.newTable.columns.push({ name: '', type: 'VARCHAR', length: '255', primary: false, ai: false, null: true });
                },
                removeTableColumn(index) {
                    this.newTable.columns.splice(index, 1);
                },
                async createTable() {
                    if (!this.newTable.name) return alert('Table name required');
                    const fd = new FormData();
                    fd.append('action', 'create_table');
                    fd.append('name', this.newTable.name);
                    fd.append('columns', JSON.stringify(this.newTable.columns));
                    fd.append('csrf_token', this.csrfToken);
                    
                    this.loading = true;
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            alert('Table created!');
                            location.reload();
                        } else alert(data.error);
                    } catch (e) { alert('Creation failed'); }
                    finally { this.loading = false; window.haptic('success'); }
                },
                async renameTable(oldName) {
                    const newName = prompt('Enter new name for table ' + oldName + ':', oldName);
                    if (!newName || newName === oldName) return;
                    const fd = new FormData();
                    fd.append('action', 'rename_table');
                    fd.append('old_name', oldName);
                    fd.append('new_name', newName);
                    fd.append('csrf_token', this.csrfToken);
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) location.reload();
                        else alert(data.error);
                    } catch (e) { alert('Rename failed'); window.haptic('error'); }
                },
                async dropTable(table) {
                    window.haptic('warning');
                    if (!confirm('DROP table ' + table + '? THIS ACTION IS PERMANENT!')) return;
                    if (!confirm('Are you absolutely sure? All data in ' + table + ' will be lost.')) return;
                    const fd = new FormData();
                    fd.append('action', 'drop_table');
                    fd.append('table', table);
                    fd.append('csrf_token', this.csrfToken);
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) location.reload();
                        else alert(data.error);
                    } catch (e) { alert('Drop failed'); }
                },
                async optimizeTable(table) {
                    window.haptic('impactMedium');
                    if (!confirm('Optimize table ' + table + '? This will reclaim unused space and defragment indexes.')) return;
                    const fd = new FormData();
                    fd.append('action', 'optimize_table');
                    fd.append('table', table);
                    fd.append('csrf_token', this.csrfToken);
                    this.loading = true;
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            alert('Table ' + table + ' optimized successfully!');
                            if (!this.selectedTable) this.fetchDbStats();
                            else this.selectTable(this.selectedTable);
                        } else alert(data.error);
                    } catch (e) { alert('Optimization failed'); }
                    finally { this.loading = false; }
                },
                async fetchProcesses() {
                    this.showProcessModal = true;
                    this.loading = true;
                    try {
                        const res = await fetch('?ajax=process_list');
                        const data = await res.json();
                        if (data.success) this.processes = data.processes;
                    } catch (e) { alert('Failed to fetch processes'); }
                    finally { this.loading = false; }
                },
                async runExplain() {
                    if (!this.sqlQuery) return;
                    const fd = new FormData();
                    fd.append('action', 'explain_query');
                    fd.append('query', this.sqlQuery);
                    fd.append('csrf_token', this.csrfToken);
                    this.loading = true;
                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.sqlResults = data.data;
                        } else alert(data.error);
                    } catch (e) { alert('Explain failed'); }
                    finally { this.loading = false; }
                },
                saveSnippet() {
                    if (!this.sqlQuery) return;
                    const name = prompt('Name this snippet:');
                    if (!name) return;
                    this.snippets.push({ name, query: this.sqlQuery });
                    localStorage.setItem('sqluxe_snippets', JSON.stringify(this.snippets));
                },
                removeSnippet(index) {
                    this.snippets.splice(index, 1);
                    localStorage.setItem('sqluxe_snippets', JSON.stringify(this.snippets));
                },

                async selectTable(table) {
                    window.haptic('impactLight');
                    if (this.selectedTable !== table) {
                        this.currentPage = 1;
                        this.searchQuery = '';
                        this.sortField = '';
                        this.sortOrder = 'ASC';
                        this.selectedRows = [];
                    }
                    this.selectedTable = table;
                    this.loading = true;
                    this.showConsole = false;
                    this.selectedRows = [];
                    if (window.innerWidth < 768) this.sidebarOpen = false;

                    const url = new URL(window.location.href);
                    url.searchParams.set('ajax', 'table_data');
                    url.searchParams.set('table', table);
                    url.searchParams.set('page', this.currentPage);
                    if (this.searchQuery) {
                        url.searchParams.set('search', this.searchQuery);
                    }
                    if (this.sortField) {
                        url.searchParams.set('sort', this.sortField);
                        url.searchParams.set('order', this.sortOrder);
                    }

                    try {
                        const res = await fetch(url);
                        if (!res.ok) {
                            const err = await res.json();
                            throw new Error(err.error || 'Server error');
                        }
                        const data = await res.json();
                        this.tableData = data;
                    } catch (e) {
                        console.error('Fetch error:', e);
                        alert('Error loading data: ' + e.message);
                    } finally {
                        this.loading = false;
                    }
                },



                sortBy(field) {
                    if (this.sortField === field) {
                        this.sortOrder = this.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                    } else {
                        this.sortField = field;
                        this.sortOrder = 'ASC';
                    }
                    this.currentPage = 1;
                    this.selectTable(this.selectedTable);
                },

                async executeSQL() {
                    window.haptic('impactMedium');
                    if (!this.sqlQuery.trim()) return;
                    this.loading = true;
                    this.sqlResults = null;
                    const fd = new FormData();
                    fd.append('action', 'execute_sql');
                    fd.append('query', this.sqlQuery);
                    fd.append('csrf_token', this.csrfToken);

                    try {
                        // Save to history
                        const historyClean = this.sqlQuery.trim();
                        this.queryHistory = this.queryHistory.filter(h => h !== historyClean);
                        this.queryHistory.unshift(historyClean);
                        if (this.queryHistory.length > 50) this.queryHistory.pop();
                        localStorage.setItem('sqluxe_history', JSON.stringify(this.queryHistory));

                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.sqlResults = data.data || [];
                        } else {
                            alert('SQL Error: ' + data.error);
                        }
                    } catch (e) { alert('Execution failed.'); }
                    finally { this.loading = false; }
                },


                openInsertModal() {
                    this.insertForm = {};
                    this.showInsertModal = true;
                },

                editRow(row) {
                    this.originalRow = row;
                    this.editForm = JSON.parse(JSON.stringify(row));
                    this.showEditModal = true;
                },

                toggleSelectAllRows() {
                    if (this.selectedRows.length === this.tableData.rows.length) {
                        this.selectedRows = [];
                    } else {
                        this.selectedRows = [...this.tableData.rows];
                    }
                },

                async deleteSelectedRows() {
                    const count = this.selectedRows.length;
                    if (!count) return;
                    if (!confirm(`Delete ${count} selected records permanently?`)) return;

                    const pkField = this.tableData.columns.find(c => c.Key === 'PRI')?.Field || this.tableData.columns[0].Field;
                    const ids = this.selectedRows.map(r => r[pkField]);

                    const fd = new FormData();
                    fd.append('action', 'delete_rows');
                    fd.append('table', this.selectedTable);
                    fd.append('pk_column', pkField);
                    fd.append('ids', JSON.stringify(ids));
                    fd.append('csrf_token', this.csrfToken);

                    try {
                        this.loading = true;
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.selectedRows = [];
                            this.selectTable(this.selectedTable);
                        } else {
                            alert('Bulk Delete Error: ' + data.error);
                        }
                    } catch (e) { alert('Bulk delete failed.'); }
                    finally { this.loading = false; }
                },

                async deleteRow(row) {
                    window.haptic('warning');
                    if (!confirm('Permanently delete this record?')) return false;
                    const pkField = this.tableData.columns.find(c => c.Key === 'PRI')?.Field || this.tableData.columns[0].Field;
                    const pkValue = row[pkField];

                    const fd = new FormData();
                    fd.append('action', 'delete_row');
                    fd.append('table', this.selectedTable);
                    fd.append('pk_column', pkField);
                    fd.append('pk_value', pkValue);
                    fd.append('csrf_token', this.csrfToken);

                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.tableData.rows = this.tableData.rows.filter(r => r[pkField] !== pkValue);
                            return true;
                        } else {
                            alert('Error: ' + data.error);
                            return false;
                        }
                    } catch (e) { 
                        alert('Delete failed.'); 
                        return false;
                    }
                },

                async importSQL(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (!confirm(`Importing ${file.name} will execute SQL commands directly. Proceed?`)) {
                        event.target.value = '';
                        return;
                    }

                    const fd = new FormData();
                    fd.append('action', 'import_sql');
                    fd.append('sql_file', file);
                    this.loading = true;

                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            alert(`Success! Executed ${data.count} SQL commands.`);
                            location.reload();
                        } else {
                            alert('Import Error: ' + data.error);
                        }
                    } catch (e) {
                        alert('Import failed. Check file format.');
                    } finally {
                        this.loading = false;
                        event.target.value = '';
                    }
                },

                async insertRow() {
                    const fd = new FormData();
                    fd.append('action', 'insert_row');
                    fd.append('table', this.selectedTable);
                    fd.append('data', JSON.stringify(this.insertForm));
                    fd.append('csrf_token', this.csrfToken);

                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.showInsertModal = false;
                            this.selectTable(this.selectedTable);
                        } else {
                            alert('Insert Error: ' + data.error);
                        }
                    } catch (e) { alert('Insert failed'); window.haptic('error'); }
                },

                async updateRow() {
                    const pkField = this.tableData.columns.find(c => c.Key === 'PRI')?.Field || this.tableData.columns[0].Field;
                    const pkValue = this.originalRow[pkField];

                    const fd = new FormData();
                    fd.append('action', 'update_row');
                    fd.append('table', this.selectedTable);
                    fd.append('pk_column', pkField);
                    fd.append('pk_value', pkValue);
                    fd.append('data', JSON.stringify(this.editForm));
                    fd.append('csrf_token', this.csrfToken);

                    try {
                        const res = await fetch('', { method: 'POST', body: fd });
                        const data = await res.json();
                        if (data.success) {
                            this.showEditModal = false;
                            this.selectTable(this.selectedTable);
                        } else {
                            alert('Update Error: ' + data.error);
                        }
                    } catch (e) { alert('Update failed'); window.haptic('error'); }
                }
            }))
        })

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?action=sw');
        }
    </script>
        <!-- QR Modal -->
        <div class="modal-overlay" x-show="showQR" x-cloak @click.self="showQR = false">
            <div class="modal-card" style="max-width: 400px; text-align: center;">
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
                <div class="modal-footer">
                    <button @click="showQR = false" class="btn btn-primary">DONE</button>
                </div>
            </div>
        </div>
</body>
</html>
