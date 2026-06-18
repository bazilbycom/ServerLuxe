<?php
/**
 * ServerLuxe Dashboard & Installer Entry point
 */

session_start();

$env_file = __DIR__ . '/.env';
$is_installed = file_exists($env_file);

// DotEnv Loader Helper
function load_env($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

if ($is_installed) {
    load_env($env_file);
}

// ----------------------------------------------------
// AJAX ACTIONS
// ----------------------------------------------------

// Test connection action (during installation or dashboard check)
if (isset($_POST['action']) && $_POST['action'] === 'test_conn') {
    header('Content-Type: application/json');
    $host = $_POST['host'] ?? 'localhost';
    $port = $_POST['port'] ?? '3306';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $db_name = $_POST['database'] ?? '';

    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if (!empty($db_name)) {
        $dsn .= ";dbname={$db_name}";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3
        ]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Perform Installation / Write .env
if (isset($_POST['action']) && $_POST['action'] === 'install' && !$is_installed) {
    $master_pass = $_POST['master_pass'] ?? '';
    $api_key = $_POST['api_key'] ?? '';
    $host = $_POST['host'] ?? 'localhost';
    $port = $_POST['port'] ?? '3306';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    $db_name = $_POST['database'] ?? '';

    if (empty($master_pass)) {
        $error = "Master Password is required.";
    } else {
        if (empty($api_key)) {
            $api_key = bin2hex(random_bytes(16));
        }

        $env_content = "# ServerLuxe Configuration\n";
        $env_content .= "APP_NAME=ServerLuxe\n";
        $env_content .= "APP_ENV=production\n";
        $env_content .= "VERSION=1.2.3\n\n";
        
        $env_content .= "# Database Connection Defaults\n";
        $env_content .= "DEFAULT_HOST={$host}\n";
        $env_content .= "DEFAULT_PORT={$port}\n";
        $env_content .= "DEFAULT_USER={$user}\n";
        $env_content .= "DEFAULT_PASS={$pass}\n\n";

        $env_content .= "# Security\n";
        $env_content .= "MASTER_PASS={$master_pass}\n";
        $env_content .= "API_KEY={$api_key}\n\n";

        $env_content .= "# Navigation\n";
        $env_content .= "DB_FILE=db.php\n";
        $env_content .= "FM_FILE=fm.php\n";

        if (file_put_contents($env_file, $env_content) !== false) {
            header("Location: index.php");
            exit;
        } else {
            $error = "Failed to write .env file. Please check folder write permissions.";
        }
    }
}

// Fetch some basic system info for dashboard
$sys_info = [];
if ($is_installed) {
    $sys_info['os'] = PHP_OS;
    $sys_info['php_version'] = PHP_VERSION;
    $sys_info['upload_max'] = ini_get('upload_max_filesize');
    $sys_info['post_max'] = ini_get('post_max_size');
    $sys_info['disk_free'] = disk_free_space(__DIR__);
    $sys_info['disk_total'] = disk_total_space(__DIR__);
    
    // Count active connections/folders from config
    $mcp_file = __DIR__ . '/mcp_config.json';
    $sys_info['mcp_configured'] = file_exists($mcp_file);
    if ($sys_info['mcp_configured']) {
        $mcp = json_decode(file_get_contents($mcp_file), true);
        $sys_info['mcp_dbs'] = count($mcp['databases'] ?? []);
        $sys_info['mcp_folders'] = count($mcp['folders'] ?? []);
    } else {
        $sys_info['mcp_dbs'] = 0;
        $sys_info['mcp_folders'] = 0;
    }
}

function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$suggested_api_key = bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ServerLuxe Suite</title>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-deep);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .container {
            width: 100%;
            max-width: 800px;
            background: var(--bg-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            animation: fadeIn 0.4s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .header {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(30, 41, 59, 0.4);
        }
        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
            font-weight: 800;
            font-size: 1.5rem;
        }
        .content { padding: 2rem; }
        .input-group { margin-bottom: 1.25rem; }
        .input-group label { display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem; font-weight: 500; }
        .input-control {
            width: 100%;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            color: #fff;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .input-control:focus { border-color: var(--accent); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            gap: 0.5rem;
            font-family: inherit;
        }
        .btn-primary { background: var(--accent); color: #0f172a; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-primary); }
        .btn-ghost:hover { background: rgba(255,255,255,0.05); }
        .card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        .card:hover {
            border-color: var(--accent);
            background: rgba(34, 211, 238, 0.05);
            transform: translateY(-2px);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 600px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        .badge {
            background: rgba(34, 211, 238, 0.1);
            color: var(--accent);
            padding: 0.25rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .alert {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

    <div class="container">
        
        <!-- Header -->
        <div class="header">
            <div class="logo-icon">S</div>
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 700; line-height: 1.2;">ServerLuxe</h1>
                <p style="font-size: 0.85rem; color: var(--text-secondary);">
                    <?php echo $is_installed ? 'Central Control Dashboard' : 'Setup & Installation Wizard'; ?>
                </p>
            </div>
        </div>

        <div class="content">

            <?php if (!$is_installed): ?>
                <!-- ==================== INSTALLER VIEW ==================== -->
                <div x-data="{
                    step: 1,
                    form: {
                        master_pass: '',
                        api_key: '<?php echo $suggested_api_key; ?>',
                        host: 'localhost',
                        port: '3306',
                        user: 'root',
                        pass: '',
                        database: ''
                    },
                    testStatus: '',
                    testing: false,
                    
                    async testConnection() {
                        this.testing = true;
                        this.testStatus = '';
                        const fd = new FormData();
                        fd.append('action', 'test_conn');
                        fd.append('host', this.form.host);
                        fd.append('port', this.form.port);
                        fd.append('user', this.form.user);
                        fd.append('pass', this.form.pass);
                        fd.append('database', this.form.database);
                        try {
                            const res = await fetch('', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.success) {
                                this.testStatus = 'success';
                            } else {
                                this.testStatus = 'error: ' + data.error;
                            }
                        } catch(e) {
                            this.testStatus = 'error: Network failure';
                        } finally {
                            this.testing = false;
                        }
                    }
                }">
                    <?php if (isset($error)): ?>
                        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="action" value="install">

                        <!-- Step 1: Security Settings -->
                        <div x-show="step === 1" x-transition>
                            <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem; color: var(--accent);">Step 1: Security Credentials</h2>
                            
                            <div class="input-group">
                                <label>Master Password</label>
                                <input type="password" name="master_pass" x-model="form.master_pass" class="input-control" placeholder="Choose a strong password to lock the panels" required>
                                <span style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; display: block;">This password is used to access the File Manager and Database connection defaults.</span>
                            </div>

                            <div class="input-group">
                                <label>API Access Key</label>
                                <input type="text" name="api_key" x-model="form.api_key" class="input-control" required>
                                <span style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem; display: block;">Used for AI Model Context Protocol (MCP) clients and mobile app synchronization.</span>
                            </div>

                            <div style="display: flex; justify-content: flex-end; margin-top: 2rem;">
                                <button type="button" @click="step = 2" class="btn btn-primary" :disabled="!form.master_pass || !form.api_key">Continue to DB Config</button>
                            </div>
                        </div>

                        <!-- Step 2: Database Settings -->
                        <div x-show="step === 2" x-transition x-cloak>
                            <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem; color: var(--accent);">Step 2: Database Connection Settings</h2>
                            
                            <div class="grid-2">
                                <div class="input-group">
                                    <label>Host Name</label>
                                    <input type="text" name="host" x-model="form.host" class="input-control">
                                </div>
                                <div class="input-group">
                                    <label>Port</label>
                                    <input type="text" name="port" x-model="form.port" class="input-control">
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="input-group">
                                    <label>Username</label>
                                    <input type="text" name="user" x-model="form.user" class="input-control">
                                </div>
                                <div class="input-group">
                                    <label>Password</label>
                                    <input type="password" name="pass" x-model="form.pass" class="input-control">
                                </div>
                            </div>

                            <div class="input-group">
                                <label>Default Database Name (Optional)</label>
                                <input type="text" name="database" x-model="form.database" class="input-control" placeholder="e.g. production_db">
                            </div>

                            <!-- Connection Test Box -->
                            <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                                <div class="flex items-center justify-between" style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">Test connection status:</span>
                                    <button type="button" @click="testConnection" class="btn btn-ghost" style="padding: 0.4rem 1rem; font-size: 0.8rem;" :disabled="testing">
                                        <span x-show="!testing">Verify Connection</span>
                                        <span x-show="testing">Verifying...</span>
                                    </button>
                                </div>
                                <div x-show="testStatus === 'success'" style="color: var(--success); font-size: 0.85rem; margin-top: 0.5rem; font-weight:600;">✓ MySQL Connection Successful!</div>
                                <div x-show="testStatus && testStatus.startsWith('error')" style="color: var(--danger); font-size: 0.85rem; margin-top: 0.5rem;" x-text="'✗ ' + testStatus"></div>
                            </div>

                            <div style="display: flex; justify-content: space-between; margin-top: 2rem;">
                                <button type="button" @click="step = 1" class="btn btn-ghost">Back</button>
                                <button type="submit" class="btn btn-primary">Complete Setup</button>
                            </div>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- ==================== DASHBOARD VIEW ==================== -->
                <div>
                    <h2 style="font-size: 1.15rem; margin-bottom: 1.5rem; font-weight:700;">Welcome back to your server suite</h2>
                    
                    <!-- Modules Grid -->
                    <div class="grid-2" style="margin-bottom: 2rem;">
                        <a href="db.php" class="card">
                            <div class="flex justify-between" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                                <div style="font-weight: 700; font-size: 1.1rem; color: var(--accent);">SQLuxe</div>
                                <span class="badge">MySQL</span>
                            </div>
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                                Modern, single-file database manager. Run queries, view processes, and inspect tables with ease.
                            </p>
                            <span style="font-size: 0.85rem; font-weight:600; color: var(--accent); display:flex; align-items:center; gap: 0.25rem;">
                                Open Database Manager →
                            </span>
                        </a>

                        <a href="fm.php" class="card">
                            <div class="flex justify-between" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                                <div style="font-weight: 700; font-size: 1.1rem; color: var(--accent);">FileLuxe</div>
                                <span class="badge">Files</span>
                            </div>
                            <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1.5rem;">
                                Slick, fully responsive web file manager. Chmod files, browse directories, edit code, and zip folders.
                            </p>
                            <span style="font-size: 0.85rem; font-weight:600; color: var(--accent); display:flex; align-items:center; gap: 0.25rem;">
                                Open File Manager →
                            </span>
                        </a>
                    </div>

                    <!-- System Status Info -->
                    <div style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; background: rgba(255,255,255,0.01);">
                        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
                            System Status
                        </h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.85rem;">
                            <div>
                                <div style="color: var(--text-secondary); margin-bottom: 0.25rem;">Operating System</div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($sys_info['os']); ?></div>
                            </div>
                            <div>
                                <div style="color: var(--text-secondary); margin-bottom: 0.25rem;">PHP Version</div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($sys_info['php_version']); ?></div>
                            </div>
                            <div>
                                <div style="color: var(--text-secondary); margin-bottom: 0.25rem;">Upload Limit</div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($sys_info['upload_max']); ?></div>
                            </div>
                            <div>
                                <div style="color: var(--text-secondary); margin-bottom: 0.25rem;">Free Disk Space</div>
                                <div style="font-weight: 600;"><?php echo format_bytes($sys_info['disk_free']); ?> / <?php echo format_bytes($sys_info['disk_total']); ?></div>
                            </div>
                        </div>

                        <h3 style="font-size: 1rem; font-weight: 700; margin-top: 1.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                            Model Context Protocol (MCP) Status
                            <span class="badge" style="background: <?php echo $sys_info['mcp_configured'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; color: <?php echo $sys_info['mcp_configured'] ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo $sys_info['mcp_configured'] ? 'Configured' : 'Inactive'; ?>
                            </span>
                        </h3>
                        <div style="font-size: 0.85rem;">
                            <?php if ($sys_info['mcp_configured']): ?>
                                <p style="color: var(--text-secondary); margin-bottom: 0.5rem;">AI agents have access rules configured for:</p>
                                <ul style="list-style: none; margin-left: 0; padding-left: 0; display:flex; gap: 1rem;">
                                    <li>📦 Databases: <strong style="color:#fff;"><?php echo $sys_info['mcp_dbs']; ?></strong></li>
                                    <li>📁 Folders: <strong style="color:#fff;"><?php echo $sys_info['mcp_folders']; ?></strong></li>
                                </ul>
                            <?php else: ?>
                                <p style="color: var(--text-secondary);">Go to SQLuxe or FileLuxe maintenance panels to enable granular database and folder read/write access for your local AI client.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>
