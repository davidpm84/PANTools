<?php
session_start();

// --- 1. CONFIGURACIÓN DEL REPO Y UPDATE ---
$repoPath = '/var/www/html'; 
$updateAvailable = false;
$updateMessage = "";
$updateError = false;
$localHash = "Unknown";

if (is_dir("$repoPath/.git")) {
    // A. Intentar vía Comando Git (Con corrección de directorio seguro para Docker)
    exec("cd " . escapeshellarg($repoPath) . " && git config --global --add safe.directory /var/www/html 2>&1");
    $cmdHash = shell_exec("cd " . escapeshellarg($repoPath) . " && git rev-parse --short HEAD 2>&1");
    
    if ($cmdHash && preg_match('/^[a-f0-9]{7}/', trim($cmdHash))) {
        $localHash = trim($cmdHash);
    } 
    // B. PLAN B: Leer los archivos de Git directamente (si el comando falla por permisos)
    else {
        $headFile = "$repoPath/.git/HEAD";
        if (file_exists($headFile)) {
            $headContent = trim(file_get_contents($headFile));
            if (strpos($headContent, 'ref:') === 0) {
                // Es un puntero a una rama (ej: ref: refs/heads/main)
                $refPath = "$repoPath/.git/" . trim(substr($headContent, 5));
                if (file_exists($refPath)) {
                    $localHash = substr(trim(file_get_contents($refPath)), 0, 7);
                }
            } else {
                // Es un hash directo (Detached HEAD)
                $localHash = substr($headContent, 0, 7);
            }
        }
    }

    // Verificar actualizaciones (solo si tenemos un hash válido)
    if ($localHash !== "Unknown") {
        exec("cd " . escapeshellarg($repoPath) . " && git fetch origin main 2>&1");
        $remoteHashRaw = shell_exec("cd " . escapeshellarg($repoPath) . " && git rev-parse --short origin/main 2>&1");
        $remoteHash = trim($remoteHashRaw ?? "");

        if (!empty($remoteHash) && preg_match('/^[a-f0-9]{7}/', $remoteHash) && $localHash !== $remoteHash) {
            $updateAvailable = true;
        }
    }
}

// Ejecutar la actualización de PANTools
if (isset($_POST['action']) && $_POST['action'] === 'self_update') {
    exec("cd " . escapeshellarg($repoPath) . " && git reset --hard origin/main 2>&1", $updateOut, $updateRet);
    if ($updateRet === 0) {
        $updateMessage = "✅ PANTools updated successfully! Reloading...";
        header("Refresh:2"); 
    } else {
        $updateMessage = "❌ Update failed: " . implode(" ", $updateOut);
        $updateError = true;
    }
}

// --- 2. GESTIÓN DEL TOKEN DE GITHUB (Setup Wizard) ---
$configFile = __DIR__ . '/.config.json';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$setupError = "";

// Acción: Guardar Token
if (isset($_POST['action']) && $_POST['action'] === 'save_setup') {
    $token = trim($_POST['setup_token']);
    
    $ch = curl_init("https://api.github.com/user");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PANTools", "Authorization: token $token"]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $config = ['token' => $token, 'setup_date' => date('Y-m-d H:i:s')];
        file_put_contents($configFile, json_encode($config));
        unset($_SESSION['setup_skipped']);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $setupError = "❌ Invalid Token (HTTP Error $httpCode).";
    }
}

// Acción: Skip Setup
if (isset($_POST['action']) && $_POST['action'] === 'skip_setup') {
    $_SESSION['setup_skipped'] = true;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Acción: Reset (Desconectar)
if (isset($_POST['action']) && $_POST['action'] === 'reset_config') {
    if (file_exists($configFile)) unlink($configFile);
    unset($_SESSION['setup_skipped']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$hasToken = !empty($config['token']);
$showSetup = !$hasToken && !isset($_SESSION['setup_skipped']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PANTools - SE Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        :root {
            --strata-color: #EA212D;
            --cortex-color: #00C55E;
            --mgmt-color: #343a40;
            --bg-light: #F8F9FA;
        }
        
        body { background-color: var(--bg-light); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .navbar { background-color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px; color: #333 !important; display: flex; align-items: center; }
        
        .update-banner { border-radius: 8px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); animation: slideIn 0.5s ease-out; }
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .section-title { position: relative; padding-left: 15px; margin-bottom: 25px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #555; }
        .section-title::before { content: ''; position: absolute; left: 0; top: 5px; bottom: 5px; width: 5px; border-radius: 2px; }
        
        .title-strata::before { background-color: var(--strata-color); }
        .title-cortex::before { background-color: var(--cortex-color); }
        .title-mgmt::before { background-color: var(--mgmt-color); }

        .tool-card { border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: transform 0.2s; height: 100%; background: white; border: none; }
        .tool-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
        
        .card-strata { border-top: 4px solid var(--strata-color); }
        .card-cortex { border-top: 4px solid var(--cortex-color); }
        .card-mgmt { border-top: 4px solid var(--mgmt-color); }

        .card-icon { font-size: 2.5rem; margin-bottom: 15px; }
        .card-desc { font-size: 0.9rem; color: #6c757d; min-height: 40px; }

        .setup-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; display:flex; align-items:center; justify-content:center; backdrop-filter: blur(5px); }
        
        .card-disabled { opacity: 0.65; filter: grayscale(1); pointer-events: none; position: relative; }
        .lock-overlay { position: absolute; top: 15px; right: 15px; background: #e9ecef; color: #495057; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; border: 1px solid #ced4da; }

        .btn-strata { background-color: #fff; color: var(--strata-color); border: 1px solid var(--strata-color); }
        .btn-strata:hover { background-color: var(--strata-color); color: #fff; }
        .btn-cortex { background-color: #fff; color: var(--cortex-color); border: 1px solid var(--cortex-color); }
        .btn-cortex:hover { background-color: var(--cortex-color); color: #fff; }
    </style>
</head>
<body>

<?php if ($showSetup): ?>
<div class="setup-overlay">
    <div class="card shadow-lg p-4" style="max-width: 450px; width: 100%; border:none; border-radius: 12px;">
        <div class="text-center mb-4">
            <div class="display-4 mb-2">🐙</div>
            <h3 class="fw-bold">GitHub Connection</h3>
            <p class="text-muted small">Enter your Personal Access Token (PAT) to enable the Content Importer.</p>
        </div>
        <?php if ($setupError): ?><div class="alert alert-danger small py-2"><?= $setupError ?></div><?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <input type="password" name="setup_token" class="form-control bg-light" placeholder="github_pat_11..." required>
            </div>
            <button type="submit" name="action" value="save_setup" class="btn btn-dark w-100 fw-bold mb-2">CONNECT GITHUB</button>
            <button type="submit" name="action" value="skip_setup" class="btn btn-link w-100 text-muted btn-sm text-decoration-none" formnovalidate>
                Skip for now (Importer will be disabled)
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<nav class="navbar navbar-expand-lg navbar-light py-3">
    <div class="container">
        <a class="navbar-brand" href="#">
            <span style="border-left: 2px solid #ddd; padding-left: 15px;">PANTools</span>
            <span class="badge bg-light text-muted border ms-2" style="font-size: 0.6rem;"><?= htmlspecialchars($localHash) ?></span>
        </a>
        
        <div class="d-flex align-items-center gap-3">
            <?php if (!$hasToken): ?>
                <span class="badge bg-warning text-dark border border-warning"><i class="fas fa-lock me-1"></i> Limited Mode</span>
                <form method="POST" class="m-0">
                    <button type="submit" name="action" value="reset_config" class="btn btn-sm btn-outline-dark fw-bold">Setup GitHub</button>
                </form>
            <?php else: ?>
                <span class="badge bg-success text-white"><i class="fas fa-check-circle me-1"></i> GitHub Connected</span>
                <form method="POST" class="m-0" onsubmit="return confirm('Disconnect GitHub?');">
                    <button type="submit" name="action" value="reset_config" class="btn btn-link btn-sm text-danger text-decoration-none p-0"><i class="fas fa-unlink"></i> Disconnect</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <?php if ($updateMessage): ?>
        <div class="alert <?= $updateError ? 'alert-danger' : 'alert-success' ?> update-banner shadow-sm mb-4">
            <i class="fas <?= $updateError ? 'fa-exclamation-triangle' : 'fa-check-circle' ?> me-2"></i>
            <?= htmlspecialchars($updateMessage) ?>
        </div>
    <?php elseif ($updateAvailable): ?>
        <div class="alert alert-warning update-banner shadow-sm mb-4 d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-sparkles text-warning me-2"></i>
                <strong>New version available!</strong> Keep your SE tools up to date.
            </div>
            <form method="POST">
                <button type="submit" name="action" value="self_update" class="btn btn-dark btn-sm fw-bold px-3">
                    <i class="fas fa-cloud-download-alt me-1"></i> UPDATE PANTOOLS
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="row text-center mb-5">
        <div class="col-lg-8 mx-auto">
            <h1 class="fw-bold">Solution Engineering Hub</h1>
            <p class="text-muted">Select a tool to get started</p>
        </div>
    </div>

    <div class="mb-5">
        <h4 class="section-title title-strata">STRATA <span class="text-muted fs-6 fw-normal">(NGFW & SASE Tools)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-strata p-4 text-center">
                    <div class="card-icon" style="color: var(--strata-color);"><i class="fas fa-fire-alt"></i></div>
                    <h5 class="fw-bold mb-2">PAN Firewall Mapper</h5>
                    <p class="card-desc">Tool for mapping and migrating Firewall configurations.</p>
                    <a href="strata/panfirewallmapper/index.php" class="btn btn-strata w-100 fw-bold">Open Tool</a>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-5">
        <h4 class="section-title title-cortex">CORTEX <span class="text-muted fs-6 fw-normal">(SecOps & Cloud Tools)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 <?= !$hasToken ? 'card-disabled' : '' ?>">
                    <?php if (!$hasToken): ?><div class="lock-overlay"><i class="fas fa-lock"></i> Requires Token</div><?php endif; ?>
                    <div class="text-center">
                        <div class="card-icon" style="color: var(--cortex-color);"><i class="fas fa-box-open"></i></div>
                        <h5 class="fw-bold mb-2">Custom Content Importer</h5>
                        <p class="card-desc">Import integrations and playbooks from GitHub.</p>
                        <a href="cortex/contentimporter.php" class="btn btn-cortex w-100 fw-bold <?= !$hasToken ? 'disabled' : '' ?>">Open Tool</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 text-center">
                    <div class="card-icon" style="color: var(--cortex-color);"><i class="fas fa-clipboard-check"></i></div>
                    <h5 class="fw-bold mb-2">Cortex Health & Audit</h5>
                    <p class="card-desc">Tenant BPA and Policy Audit tool.</p>
                    <a href="cortex/cortexaudit.php" class="btn btn-cortex w-100 fw-bold">Open Tool</a>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-5">
        <h4 class="section-title title-mgmt">Management <span class="text-muted fs-6 fw-normal">(PoV & Tracking)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-mgmt p-4 text-center">
                    <div class="card-icon" style="color: var(--mgmt-color);"><i class="fas fa-bullseye"></i></div>
                    <h5 class="fw-bold mb-2">PoV Radar</h5>
                    <p class="card-desc">Track TRRs and PoV status globally.</p>
                    <a href="other/povradar.php" class="btn btn-dark w-100 fw-bold">Open Tracker</a>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="text-center py-4 text-muted small border-top mt-5">
    <p class="mb-0">PANTools SE Edition | Connected to GitHub</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>