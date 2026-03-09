<?php
session_start();

// --- 1. CONFIGURACIÓN DEL REPO Y VERSIÓN ---
$repoPath = '/var/www/html'; 
$updateAvailable = false;
$updateMessage = "";
$updateError = false;
$localHash = "Unknown";

// --- LEER CHANGELOG Y VERSIÓN DINÁMICA ---
$changelogData = [];
$latestVersionName = "v1.0"; // Valor por defecto si no existe el fichero
if (file_exists("$repoPath/versions.json")) {
    $fileContent = file_get_contents("$repoPath/versions.json");
    $parsed = json_decode($fileContent, true);
    if (is_array($parsed) && !empty($parsed)) {
        $changelogData = $parsed;
        $latestVersionName = $changelogData[0]['version'] ?? "v1.0";
    }
}

// A. INTENTO POR API DE GITHUB (Caché de 30 mins para no saturar la API)
if (!isset($_SESSION['last_version_check']) || (time() - $_SESSION['last_version_check'] > 1800)) {
    $ch = curl_init("https://api.github.com/repos/davidpm84/pantools/commits/main");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PANTools-Hub"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $ghData = json_decode($response, true);
    curl_close($ch);

    if (isset($ghData['sha'])) {
        $_SESSION['remote_hash'] = substr($ghData['sha'], 0, 7);
        $_SESSION['last_version_check'] = time();
    }
}

// B. OBTENER EL HASH LOCAL
if (is_dir("$repoPath/.git")) {
    exec("cd $repoPath && git config --global --add safe.directory $repoPath 2>&1");
    $localHash = trim(shell_exec("cd $repoPath && git rev-parse --short HEAD 2>&1") ?? "Unknown");
} elseif (file_exists("$repoPath/.version")) {
    $localHash = trim(file_get_contents("$repoPath/.version"));
} else {
    // Si no hay .git ni archivo .version, asume el remoto actual y crea el archivo
    $localHash = $_SESSION['remote_hash'] ?? "Unknown";
    if ($localHash !== "Unknown") {
        file_put_contents("$repoPath/.version", $localHash);
    }
}

// C. DETECTAR SI HAY ACTUALIZACIÓN
if (isset($_SESSION['remote_hash']) && $localHash !== "Unknown" && $localHash !== $_SESSION['remote_hash']) {
    $updateAvailable = true;
}

// D. ACCIÓN DE ACTUALIZAR (Descarga directa del tar.gz desde GitHub)
if (isset($_POST['action']) && $_POST['action'] === 'self_update' && isset($_SESSION['remote_hash'])) {
    $tarUrl      = "https://github.com/davidpm84/pantools/archive/refs/heads/main.tar.gz";
    $tarFile     = "/tmp/pantools_update.tar.gz";
    $extractPath = "/tmp/pantools_extract";

    // Descargar (falla si no es 200)
    $cmdDownload = "curl -fL -s -o " . escapeshellarg($tarFile) . " " . escapeshellarg($tarUrl) . " 2>&1";
    exec($cmdDownload, $outDl, $retDl);

    if ($retDl !== 0 || !file_exists($tarFile) || filesize($tarFile) < 1000) {
        $updateError = true;
        $updateMessage = "❌ Download failed: " . implode(" ", $outDl);
    } else {
        // Preparar extracción
        exec("rm -rf " . escapeshellarg($extractPath) . " && mkdir -p " . escapeshellarg($extractPath) . " 2>&1");

        // Extraer
        $cmdTar = "tar -xzf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($extractPath) . " 2>&1";
        exec($cmdTar, $outTar, $retTar);

        if ($retTar !== 0) {
            $updateError = true;
            $updateMessage = "❌ Unzip failed: " . implode(" ", $outTar);
        } else {
            // Buscar el directorio 'www' dentro de lo extraído (sin asumir pantools-main)
            $wwwCandidates = glob($extractPath . "/*/www", GLOB_ONLYDIR);

            if (empty($wwwCandidates)) {
                // Para ayudarte a depurar: lista lo que hay
                $roots = glob($extractPath . "/*", GLOB_ONLYDIR);
                $updateError = true;
                $updateMessage = "❌ 'www' folder not found inside extracted archive. Found roots: " . implode(", ", $roots);
            } else {
                $srcWww = $wwwCandidates[0];

                // Copiar SOLO el contenido de 'www' hacia /var/www/html
                $cmdCp = "cp -a " . escapeshellarg($srcWww . "/.") . " " . escapeshellarg($repoPath . "/") . " 2>&1";
                exec($cmdCp, $outCp, $retCp);

                if ($retCp === 0) {
                    $localHash = $_SESSION['remote_hash'];
                    file_put_contents("$repoPath/.version", $localHash);
                    $updateMessage = "✅ PANTools successfully updated from GitHub!";
                    $updateAvailable = false;
                    header("Refresh:2");
                } else {
                    $updateError = true;
                    $updateMessage = "❌ Copy failed: " . implode(" ", $outCp);
                }
            }
        }
    }

    // Limpiar temporales
    exec("rm -rf " . escapeshellarg($tarFile) . " " . escapeshellarg($extractPath) . " 2>&1");
}

// --- 2. GESTIÓN DEL TOKEN (SETUP WIZARD) ---
$configFile = $repoPath . '/.config.json';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$setupError = "";

// Acción: Guardar Token
if (isset($_POST['action']) && $_POST['action'] === 'save_setup') {
    $token = trim($_POST['setup_token']);
    $ch = curl_init("https://api.github.com/user");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PANTools", "Authorization: token $token"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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

        .tool-card { border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: transform 0.2s, box-shadow 0.2s; height: 100%; background: white; border: none; }
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
    <div class="container d-flex justify-content-between align-items-center">
        
        <div class="d-flex align-items-center">
            <a class="navbar-brand m-0" href="#">
                <span style="border-left: 2px solid #ddd; padding-left: 15px;">PANTools</span>
            </a>
            
            <a href="#" data-bs-toggle="modal" data-bs-target="#changelogModal" class="badge bg-light text-primary border ms-2 text-decoration-none" style="font-size: 0.75rem; padding: 6px 12px;" title="View Changelog">
                <i class="fas fa-code-branch me-1"></i><?= htmlspecialchars($latestVersionName) ?>.<?= htmlspecialchars($localHash) ?>
            </a>
        </div>
        
        <div class="d-flex align-items-center gap-3">
            <?php if (!$hasToken): ?>
                <span class="badge bg-warning text-dark border border-warning"><i class="fas fa-lock me-1"></i> Limited Mode</span>
                <form method="POST" class="m-0">
                    <button type="submit" name="action" value="reset_config" class="btn btn-sm btn-outline-dark fw-bold">Setup GitHub</button>
                </form>
            <?php else: ?>
                <span class="badge bg-success text-white"><i class="fas fa-check-circle me-1"></i> GitHub Connected</span>
                <form method="POST" class="m-0" onsubmit="return confirm('Disconnect GitHub and remove local token?');">
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
                    <p class="card-desc">Tool for mapping Firewall specs to the new Generation.</p>
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
                        <p class="card-desc">Import custom integrations, layouts, scripts, and playbooks into Cortex.</p>
                        <a href="cortex/contentimporter.php" class="btn btn-cortex w-100 fw-bold <?= !$hasToken ? 'disabled' : '' ?>">Open Tool</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 text-center">
                    <div class="card-icon" style="color: var(--cortex-color);"><i class="fas fa-clipboard-check"></i></div>
                    <h5 class="fw-bold mb-2">Cortex Health & Audit</h5>
                    <p class="card-desc">Review policies and profiles in use for XDR and XSIAM tenants (BPA/Health Check).</p>
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
                    <p class="card-desc">Track TRRs, PoV status, Global Timeline, and direct SFDC links.</p>
                    <a href="other/povradar.php" class="btn btn-dark w-100 fw-bold">Open Tracker</a>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="text-center py-4 text-muted small border-top mt-5">
    <p class="mb-0">PANTools SE Edition</p>
</footer>

<div class="modal fade" id="changelogModal" tabindex="-1" aria-labelledby="changelogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold" id="changelogModalLabel">
            <i class="fas fa-history text-primary me-2"></i> What's New in PANTools
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        
        <?php if (empty($changelogData)): ?>
            <p class="text-muted text-center">No changelog data found. Create 'versions.json' to track updates.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($changelogData as $index => $release): ?>
                    <div class="mb-4 <?= $index === 0 ? '' : 'opacity-75' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="fw-bold text-dark mb-0">
                                <?= htmlspecialchars($release['version']) ?>
                                <?php if ($index === 0): ?><span class="badge bg-success ms-2" style="font-size:0.6rem;">LATEST</span><?php endif; ?>
                            </h5>
                            <span class="text-muted small"><i class="far fa-calendar-alt me-1"></i> <?= htmlspecialchars($release['date']) ?></span>
                        </div>
                        <ul class="text-muted small mb-0" style="padding-left: 20px;">
                            <?php foreach ($release['features'] as $feature): ?>
                                <li><?= htmlspecialchars($feature) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($index < count($changelogData) - 1): ?>
                        <hr style="border-top: 1px dashed #ddd;">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>