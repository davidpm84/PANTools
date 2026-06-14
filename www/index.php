<?php
session_start();

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  CONFIGURATION
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
define('PARTNER_PASSWORD',     'Partners4Life');
define('TOKEN_LIFETIME_MONTHS', 6);

$repoPath  = '/var/www/html';
$configFile = $repoPath . '/.config.json';
$toolsFile  = $repoPath . '/.tools.json';   // tool metadata only — no PATs stored

$config = [];
if (file_exists($configFile)) {
    $cfg = json_decode(file_get_contents($configFile), true);
    if (is_array($cfg)) $config = $cfg;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  HELPERS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

function validateGithubPat(string $pat): bool
{
    $ch = curl_init('https://api.github.com/repos/davidpm84/cortexcustomintegrations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: PANTools-Hub', "Authorization: token $pat"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function decodeAccessToken(string $input): ?array
{
    $raw = base64_decode($input, true);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['pat'])) return null;
    return $data;
}

function savePat(string $file, string $pat): void
{
    file_put_contents($file, json_encode([
        'token'      => $pat,
        'setup_date' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d', strtotime('+' . TOKEN_LIFETIME_MONTHS . ' months')),
    ]));
}

function expiryStatus(string $expiresAt): array
{
    $days = (int) floor((strtotime($expiresAt) - time()) / 86400);
    if ($days < 0)   return ['status' => 'expired', 'days' => $days, 'class' => 'danger',  'label' => 'Expired'];
    if ($days <= 30) return ['status' => 'soon',    'days' => $days, 'class' => 'warning', 'label' => "Expires in {$days}d"];
    return             ['status' => 'ok',      'days' => $days, 'class' => 'success', 'label' => date('Y-m-d', strtotime($expiresAt))];
}

function sectionToPath(string $section): string
{
    return match($section) {
        'strata'     => 'strata',
        'management' => 'other',
        default      => 'cortex',
    };
}

function downloadAndActivateTool(array $tok, string $repoPath): array
{
    $pat     = $tok['pat']  ?? '';
    $repo    = trim($tok['repo'] ?? '');
    $slug    = preg_replace('/[^a-zA-Z0-9_-]/', '', $tok['slug'] ?? '');
    $section = $tok['section'] ?? ($tok['tools'][0] ?? 'cortex');
    $secPath = sectionToPath($section);

    // Accept both "owner/repo" and "https://github.com/owner/repo"
    $repo = preg_replace('#^https?://(www\.)?github\.com/#', '', rtrim($repo, '/'));
    // Keep only "owner/repo" (drop any trailing path segments)
    $repoParts = array_filter(explode('/', $repo));
    if (count($repoParts) < 2) {
        return ['success' => false, 'url' => '', 'message' => 'Invalid repo format. Use "owner/repo" or the GitHub URL.'];
    }
    $repo     = implode('/', array_slice(array_values($repoParts), 0, 2));
    $repoName = array_values($repoParts)[1];                 // e.g. "rfp-generator"

    if (!$slug) {
        return ['success' => false, 'url' => '', 'message' => 'Token is missing slug (main PHP filename).'];
    }

    $destDir  = "$repoPath/$secPath/$repoName";

    // --- Clone repo directly (same method as contentimporter.php) ---
    // Token embedded in URL; git -c http.sslVerify=false skips SSL issues in Docker
    $cloneUrl = $pat
        ? "https://{$pat}@github.com/{$repo}.git"
        : "https://github.com/{$repo}.git";

    exec("rm -rf " . escapeshellarg($destDir));
    $cloneOut = [];
    exec("git -c http.sslVerify=false clone --depth=1 "
         . escapeshellarg($cloneUrl) . " " . escapeshellarg($destDir) . " 2>&1",
         $cloneOut, $cloneRet);

    if ($cloneRet !== 0) {
        return ['success' => false, 'url' => '', 'message' => 'Clone failed: ' . implode(' | ', $cloneOut)];
    }

    // URL: {secPath}/{repoName}/{slug}.php
    $toolUrl = "$secPath/$repoName/$slug.php";

    return ['success' => true, 'url' => $toolUrl, 'message' => "Deployed to $toolUrl"];
}

/**
 * Upserts tool metadata into .tools.json (no PATs stored).
 * Matches by 'name' — same name = update, new name = insert.
 */
function upsertToolMetadata(array $tok, string $toolUrl, string $toolsFile): void
{
    $tools = [];
    if (file_exists($toolsFile)) {
        $t = json_decode(file_get_contents($toolsFile), true);
        if (is_array($t)) $tools = $t;
    }

    $name    = $tok['name'] ?? $tok['description'] ?? 'Unnamed Tool';
    $section = $tok['section'] ?? ($tok['tools'][0] ?? 'cortex');

    $entry = [
        'name'        => $name,
        'description' => $tok['description'] ?? '',
        'slug'        => preg_replace('/[^a-zA-Z0-9_-]/', '', $tok['slug'] ?? ''),
        'section'     => $section,
        'icon'        => $tok['icon'] ?? 'fas fa-plug',
        'url'         => $toolUrl,
        'creator'     => $tok['creator'] ?? '',
        'created'     => $tok['created'] ?? date('Y-m-d'),
        'expires_at'  => $tok['expires_at'] ?? '',
        'activated'   => date('Y-m-d H:i:s'),
    ];

    $found = false;
    foreach ($tools as &$existing) {
        if (($existing['name'] ?? '') === $name) { $existing = $entry; $found = true; break; }
    }
    unset($existing);
    if (!$found) $tools[] = $entry;

    file_put_contents($toolsFile, json_encode(array_values($tools), JSON_PRETTY_PRINT));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ACTIONS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$loginError   = '';
$adminMsg     = '';
$genToken     = '';
$bulkResults  = [];
$action       = $_POST['action'] ?? '';

// — Logout
if ($action === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// — Login
if ($action === 'login') {
    $pass = trim($_POST['password'] ?? '');

    if ($pass === PARTNER_PASSWORD) {
        $_SESSION['pt_edition'] = 'partner';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (preg_match('/^(github_pat_|ghp_)/', $pass)) {
        if (validateGithubPat($pass)) {
            $_SESSION['pt_edition'] = 'se';
            $_SESSION['pt_pat']     = $pass;
            savePat($configFile, $pass);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $loginError = 'Invalid GitHub PAT or insufficient repository access.';
    } elseif ($pass !== '') {
        $tok = decodeAccessToken($pass);
        if ($tok !== null && validateGithubPat($tok['pat'])) {
            $_SESSION['pt_edition']    = 'se';
            $_SESSION['pt_pat']        = $tok['pat'];
            $_SESSION['pt_token_info'] = $tok;
            savePat($configFile, $tok['pat']);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $loginError = 'Invalid access code. Please try again.';
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  AUTH STATE
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$edition     = $_SESSION['pt_edition'] ?? null;
$isAuth      = !empty($edition);
$isPartner   = ($edition === 'partner');
$isSE        = ($edition === 'se');
$tokenInfo   = $_SESSION['pt_token_info'] ?? null;
$currentPat  = $_SESSION['pt_pat'] ?? ($config['token'] ?? null);
$hasToken    = !empty($currentPat);
$isAdminView = ($isSE && isset($_GET['admin']));

// PAT expiry (shown in navbar)
$patExpiry = null;
if ($isSE && !empty($config['expires_at'])) {
    $patExpiry = expiryStatus($config['expires_at']);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  VERSION & UPDATE CHECK
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$updateAvailable   = false;
$updateMessage     = '';
$updateError       = false;
$localHash         = 'Unknown';
$changelogData     = [];
$latestVersionName = 'v1.0';

if (file_exists("$repoPath/versions.json")) {
    $p = json_decode(file_get_contents("$repoPath/versions.json"), true);
    if (is_array($p) && !empty($p)) {
        $changelogData     = $p;
        $latestVersionName = $changelogData[0]['version'] ?? 'v1.0';
    }
}

if (!isset($_SESSION['last_version_check']) || time() - $_SESSION['last_version_check'] > 1800) {
    $ch = curl_init('https://api.github.com/repos/davidpm84/pantools/commits/main');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: PANTools-Hub'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $ghData = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (isset($ghData['sha'])) {
        $_SESSION['remote_hash']        = substr($ghData['sha'], 0, 7);
        $_SESSION['last_version_check'] = time();
    }
}

if (is_dir("$repoPath/.git")) {
    exec("cd $repoPath && git config --global --add safe.directory $repoPath 2>&1");
    $localHash = trim(shell_exec("cd $repoPath && git rev-parse --short HEAD 2>&1") ?? 'Unknown');
} elseif (file_exists("$repoPath/.version")) {
    $localHash = trim(file_get_contents("$repoPath/.version"));
} else {
    $localHash = $_SESSION['remote_hash'] ?? 'Unknown';
    if ($localHash !== 'Unknown') file_put_contents("$repoPath/.version", $localHash);
}

if (isset($_SESSION['remote_hash']) && $localHash !== 'Unknown' && $localHash !== $_SESSION['remote_hash'])
    $updateAvailable = true;

if ($action === 'self_update' && isset($_SESSION['remote_hash'])) {
    $tarUrl  = 'https://github.com/davidpm84/pantools/archive/refs/heads/main.tar.gz';
    $tarFile = '/tmp/pantools_update.tar.gz';
    $extPath = '/tmp/pantools_extract';

    exec('curl -fL -k -s -o ' . escapeshellarg($tarFile) . ' ' . escapeshellarg($tarUrl) . ' 2>&1', $outDl, $retDl);
    if ($retDl !== 0 || !file_exists($tarFile) || filesize($tarFile) < 1000) {
        $updateError = true; $updateMessage = '❌ Download failed: ' . implode(' ', $outDl);
    } else {
        exec('rm -rf ' . escapeshellarg($extPath) . ' && mkdir -p ' . escapeshellarg($extPath));
        exec('tar -xzf ' . escapeshellarg($tarFile) . ' -C ' . escapeshellarg($extPath) . ' 2>&1', $outTar, $retTar);
        if ($retTar !== 0) {
            $updateError = true; $updateMessage = '❌ Unzip failed: ' . implode(' ', $outTar);
        } else {
            $wwwDirs = glob($extPath . '/*/www', GLOB_ONLYDIR);
            if (empty($wwwDirs)) {
                $updateError = true;
                $updateMessage = '❌ "www" not found. Roots: ' . implode(', ', glob($extPath . '/*', GLOB_ONLYDIR));
            } else {
                exec('cp -a ' . escapeshellarg($wwwDirs[0] . '/.') . ' ' . escapeshellarg($repoPath . '/') . ' 2>&1', $outCp, $retCp);
                if ($retCp === 0) {
                    $localHash = $_SESSION['remote_hash'];
                    file_put_contents("$repoPath/.version", $localHash);
                    $updateMessage = '✅ PANTools updated from GitHub!';
                    $updateAvailable = false;
                    header('Refresh:2');
                } else {
                    $updateError = true; $updateMessage = '❌ Copy failed: ' . implode(' ', $outCp);
                }
            }
        }
    }
    exec('rm -rf ' . escapeshellarg($tarFile) . ' ' . escapeshellarg($extPath));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ADMIN ACTIONS (SE only)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($isSE) {

    // Generate token (no storage — just produces the base64 string)
    if ($action === 'generate_token') {
        $gPat     = trim($_POST['gen_pat']     ?? '');
        $gRepo    = trim($_POST['gen_repo']    ?? '');
        $gSlug    = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['gen_slug'] ?? ''));
        $gSection = trim($_POST['gen_section'] ?? 'cortex');
        $gName    = trim($_POST['gen_name']    ?? '');
        $gDesc    = trim($_POST['gen_desc']    ?? '');
        $gIcon    = trim($_POST['gen_icon']    ?? 'fas fa-plug');
        $gCr      = trim($_POST['gen_creator'] ?? '');
        $gExp     = trim($_POST['gen_expires'] ?? '') ?: date('Y-m-d', strtotime('+' . TOKEN_LIFETIME_MONTHS . ' months'));

        if ($gPat && $gRepo && $gSlug && $gSection && $gName && $gCr) {
            $payload  = [
                'version'    => '1',
                'pat'        => $gPat,
                'repo'       => $gRepo,
                'slug'       => $gSlug,
                'section'    => $gSection,
                'name'       => $gName,
                'description'=> $gDesc,
                'icon'       => $gIcon,
                'creator'    => $gCr,
                'created'    => date('Y-m-d'),
                'expires_at' => $gExp,
            ];
            $genToken = base64_encode(json_encode($payload));
            $adminMsg = '✅ Token generated.';
        } else {
            $adminMsg = '❌ All fields (PAT, Repo, Slug, Section, Tool Name, Creator) are required.';
        }
    }

    // Bulk load: one base64 token per line — download + activate each tool
    if ($action === 'bulk_load') {
        $lines = preg_split('/[\r\n]+/', trim($_POST['bulk_tokens'] ?? ''));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $tok = decodeAccessToken($line);
            if (!$tok) {
                $bulkResults[] = ['ok' => false, 'name' => '(invalid)', 'msg' => 'Could not decode token.'];
                continue;
            }

            $result = downloadAndActivateTool($tok, $repoPath);
            if ($result['success']) {
                upsertToolMetadata($tok, $result['url'], $toolsFile);
            }
            $bulkResults[] = [
                'ok'   => $result['success'],
                'name' => $tok['name'] ?? $tok['description'] ?? 'Unknown',
                'msg'  => $result['message'],
                'url'  => $result['url'] ?? '',
            ];
        }
        $adminMsg = count($bulkResults) . ' token(s) processed.';
    }

    // Remove a dynamic tool
    if ($action === 'remove_tool') {
        $toolName = $_POST['tool_name'] ?? '';
        $tools = file_exists($toolsFile) ? (json_decode(file_get_contents($toolsFile), true) ?: []) : [];
        // Find the tool entry to get its folder path before removing
        foreach ($tools as $t) {
            if (($t['name'] ?? '') === $toolName && !empty($t['url'])) {
                $toolDir = $repoPath . '/' . dirname($t['url']);
                // Safety: must be under $repoPath and no directory traversal
                $real = realpath($toolDir);
                if ($real && str_starts_with($real, $repoPath . '/') && !str_contains($t['url'], '..')) {
                    exec('rm -rf ' . escapeshellarg($real));
                }
                break;
            }
        }
        $tools = array_values(array_filter($tools, fn($t) => ($t['name'] ?? '') !== $toolName));
        file_put_contents($toolsFile, json_encode($tools, JSON_PRETTY_PRINT));
        $back = !empty($_POST['from_admin']) ? '?admin=1' : '';
        header('Location: ' . $_SERVER['PHP_SELF'] . $back);
        exit;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  LOAD ACTIVE TOOLS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$activeTools = [];
if ($isSE && file_exists($toolsFile)) {
    $at = json_decode(file_get_contents($toolsFile), true);
    if (is_array($at)) $activeTools = $at;
}

function toolsForSection(array $activeTools, string $section): array
{
    return array_values(array_filter($activeTools, fn($t) => ($t['section'] ?? '') === $section));
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  DISPLAY VARS
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$editionLabel = $isPartner ? 'Partner Edition' : ($isSE ? 'SE Edition' : '');
$pageTitle    = $isPartner ? 'PANTools - Partner Edition' : ($isSE ? 'PANTools - SE Edition' : 'PANTools');

$iconCatalogue = [
    'fas fa-fire-alt'             => 'Firewall',
    'fas fa-shield-alt'           => 'Shield',
    'fas fa-clipboard-check'      => 'Audit',
    'fas fa-box-open'             => 'Import',
    'fas fa-bullseye'             => 'Target',
    'fas fa-network-wired'        => 'Network',
    'fas fa-server'               => 'Server',
    'fas fa-cloud'                => 'Cloud',
    'fas fa-lock'                 => 'Lock',
    'fas fa-key'                  => 'Key',
    'fas fa-bug'                  => 'Bug',
    'fas fa-chart-bar'            => 'Analytics',
    'fas fa-search'               => 'Search',
    'fas fa-exclamation-triangle' => 'Alert',
    'fas fa-sitemap'              => 'Topology',
    'fas fa-database'             => 'Database',
    'fas fa-code'                 => 'Code',
    'fas fa-cogs'                 => 'Settings',
    'fas fa-eye'                  => 'Visibility',
    'fas fa-globe'                => 'Global',
    'fas fa-wifi'                 => 'Wireless',
    'fas fa-user-shield'          => 'User Security',
    'fas fa-plug'                 => 'Integration',
    'fas fa-stream'               => 'Logs',
    'fas fa-satellite-dish'       => 'Threat Intel',
    'fas fa-fingerprint'          => 'Identity',
    'fas fa-terminal'             => 'Terminal',
    'fas fa-robot'                => 'Automation',
    'fas fa-chart-line'           => 'Trends',
    'fas fa-virus'                => 'Malware',
    'fas fa-laptop-code'          => 'Endpoint',
    'fas fa-binoculars'           => 'Monitoring',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ── Variables ── */
        :root {
            --strata-color:  #EA212D;
            --cortex-color:  #00a84f;
            --mgmt-color:    #4f46e5;
            --partner-color: #7c3aed;
            --bg:            #f0f4f8;
            --bg-card:       #ffffff;
            --bg-dark:       #0d1728;
            --border:        rgba(0,0,0,.08);
            --border-hi:     rgba(0,0,0,.18);
            --text:          #475569;
            --text-bright:   #1e293b;
            --text-dim:      #94a3b8;
        }

        /* ── Base ── */
        body { background-color: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; min-height: 100vh; }
        h1,h2,h3,h4,h5,h6 { color: var(--text-bright); }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 3px; }

        /* ── Navbar (dark frame) ── */
        .navbar {
            background: var(--bg-dark) !important;
            box-shadow: 0 2px 20px rgba(0,0,0,.2);
        }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; letter-spacing: -.5px; color: #fff !important; display: flex; align-items: center; }
        .navbar-brand span { border-left-color: rgba(255,255,255,.18) !important; }
        .navbar .badge.bg-light { background: rgba(255,255,255,.1) !important; color: #c4b5fd !important; border-color: rgba(255,255,255,.18) !important; }
        .navbar .badge.bg-success { background: #059669 !important; }
        .navbar .btn-outline-secondary { border-color: rgba(255,255,255,.25) !important; color: rgba(255,255,255,.75) !important; }
        .navbar .btn-outline-secondary:hover { background: rgba(255,255,255,.1) !important; color: #fff !important; }
        .navbar .btn-link.text-danger { color: #f87171 !important; }
        .navbar .badge-partner { background-color: var(--partner-color) !important; }

        /* ── Login ── */
        .login-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: var(--bg-dark); z-index: 9999;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .login-overlay::before, .login-overlay::after {
            content: ''; position: absolute; border-radius: 50%;
            filter: blur(80px); pointer-events: none;
            animation: drift 10s ease-in-out infinite alternate;
        }
        .login-overlay::before {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(0,168,79,.18), transparent 70%);
            top: -100px; left: -100px;
        }
        .login-overlay::after {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(234,33,45,.14), transparent 70%);
            bottom: -90px; right: -90px; animation-delay: -5s;
        }
        @keyframes drift { from { transform: translate(0,0); } to { transform: translate(40px,30px); } }

        .login-card {
            background: rgba(21,34,58,.88) !important;
            border: 1px solid rgba(255,255,255,.12) !important;
            border-radius: 20px !important;
            backdrop-filter: blur(20px);
            box-shadow: 0 24px 60px rgba(0,0,0,.5) !important;
            position: relative; z-index: 1; color: #e2e8f0;
        }
        .login-card h3, .login-card p { color: inherit; }
        .login-card .text-muted { color: #94a3b8 !important; }
        .login-card .form-control {
            background: rgba(255,255,255,.08) !important; border: 1px solid rgba(255,255,255,.14) !important; color: #f1f5f9 !important;
        }
        .login-card .form-control:focus { background: rgba(255,255,255,.13) !important; border-color: rgba(255,255,255,.3) !important; box-shadow: 0 0 0 3px rgba(255,255,255,.06) !important; }
        .login-card .form-control::placeholder { color: #64748b !important; }
        .login-card .alert-danger { background: rgba(234,33,45,.15) !important; border-color: rgba(234,33,45,.4) !important; color: #fca5a5 !important; }

        /* ── Section titles ── */
        .section-title {
            position: relative; padding-left: 18px;
            margin-bottom: 24px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.5px;
            font-size: .78rem; color: #94a3b8;
        }
        .section-title span.text-muted { font-size: .85rem !important; letter-spacing: 0; text-transform: none; color: #94a3b8 !important; }
        .section-title::before {
            content: ''; position: absolute;
            left: 0; top: 50%; transform: translateY(-50%);
            width: 7px; height: 7px; border-radius: 50%;
        }
        .title-strata::before { background: var(--strata-color); box-shadow: 0 0 8px rgba(234,33,45,.5); }
        .title-cortex::before { background: var(--cortex-color); box-shadow: 0 0 8px rgba(0,168,79,.5); }
        .title-mgmt::before   { background: var(--mgmt-color);   box-shadow: 0 0 8px rgba(79,70,229,.5); }

        /* ── Tool cards ── */
        .tool-card {
            background: var(--bg-card) !important; border: 1px solid var(--border) !important;
            border-radius: 14px !important; height: 100%;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            transition: transform .28s cubic-bezier(.34,1.56,.64,1), box-shadow .28s, border-color .2s;
        }
        .tool-card:hover { transform: translateY(-6px); border-color: var(--border-hi) !important; }
        .card-strata { border-left: 3px solid var(--strata-color) !important; }
        .card-cortex { border-left: 3px solid var(--cortex-color) !important; }
        .card-mgmt   { border-left: 3px solid var(--mgmt-color)   !important; }
        .card-strata:hover { box-shadow: 0 16px 40px rgba(234,33,45,.12), 0 2px 8px rgba(0,0,0,.06) !important; }
        .card-cortex:hover { box-shadow: 0 16px 40px rgba(0,168,79,.12),  0 2px 8px rgba(0,0,0,.06) !important; }
        .card-mgmt:hover   { box-shadow: 0 16px 40px rgba(79,70,229,.12), 0 2px 8px rgba(0,0,0,.06) !important; }

        .card-icon    { font-size: 2.2rem; margin-bottom: 14px; }
        .card-desc    { font-size: .88rem; color: #64748b; min-height: 40px; }
        .card-creator { font-size: .75rem; color: #94a3b8; margin-bottom: .5rem; }
        .card-expired { opacity: .5; }
        .card-expired .card-icon { filter: grayscale(1); }

        /* ── Buttons ── */
        .btn-strata { background: #fff; color: var(--strata-color); border: 1px solid rgba(234,33,45,.3); transition: all .2s; }
        .btn-strata:hover { background: var(--strata-color); color: #fff; box-shadow: 0 4px 14px rgba(234,33,45,.3); }

        .btn-cortex { background: #fff; color: var(--cortex-color); border: 1px solid rgba(0,168,79,.3); transition: all .2s; }
        .btn-cortex:hover { background: var(--cortex-color); color: #fff; box-shadow: 0 4px 14px rgba(0,168,79,.3); }

        .btn-mgmt {
            display: block; text-align: center; width: 100%;
            padding: .375rem .75rem; border-radius: .375rem; font-weight: 700; text-decoration: none;
            background: #fff; color: var(--mgmt-color);
            border: 1px solid rgba(79,70,229,.3); transition: all .2s;
        }
        .btn-mgmt:hover { background: var(--mgmt-color); color: #fff; box-shadow: 0 4px 14px rgba(79,70,229,.3); }

        /* ── Update banner ── */
        .update-banner { border-radius: 12px !important; animation: slideIn .4s ease-out; }
        @keyframes slideIn { from { transform: translateY(-12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .border-se { border-left: 3px solid var(--cortex-color) !important; }
        .badge-partner { background-color: var(--partner-color) !important; }

        /* ── Admin ── */
        .admin-header { background: linear-gradient(135deg, #0f1e34, #1a2d48); border-radius: 12px; margin-bottom: 2rem; padding: 1.25rem 1.75rem; color: #fff; }
        .admin-header * { color: #fff !important; }

        /* ── Icon picker ── */
        .icon-picker { display: flex; flex-wrap: wrap; gap: 6px; }
        .icon-option input[type="radio"] { display: none; }
        .icon-btn { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; border: 1px solid #dee2e6; cursor: pointer; font-size: 1rem; color: #6c757d; transition: all .12s; }
        .icon-btn:hover { border-color: #6c757d; background: #f8f9fa; }
        .icon-option input[type="radio"]:checked + .icon-btn { border-color: var(--mgmt-color); background: rgba(79,70,229,.08); color: var(--mgmt-color); }
    </style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- LOGIN OVERLAY -->
<div class="login-overlay">
    <div class="card login-card p-4" style="max-width:440px;width:100%;">
        <div class="text-center mb-4">
            <div class="mb-3" style="font-size:2.8rem;filter:drop-shadow(0 0 18px rgba(0,197,94,.5));">🛡️</div>
            <h3 class="fw-bold mb-1">PANTools</h3>
            <p class="text-muted small mb-0">Enter your access code to continue</p>
        </div>
        <?php if ($loginError): ?>
        <div class="alert alert-danger small py-2 mb-3"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <input type="password" name="password" class="form-control form-control-lg"
                       placeholder="Access code..." required autofocus autocomplete="off">
            </div>
            <button type="submit" name="action" value="login" class="btn btn-dark w-100 fw-bold py-2">
                <i class="fas fa-sign-in-alt me-2"></i>Access PANTools
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light py-3">
    <div class="container position-relative d-flex align-items-center">
        <!-- Left -->
        <div class="d-flex align-items-center">
            <a class="navbar-brand m-0" href="<?= $_SERVER['PHP_SELF'] ?>">
                <span style="border-left:2px solid rgba(255,255,255,.12);padding-left:15px;">PANTools</span>
            </a>
            <?php if ($isAuth): ?>
            <a href="#" data-bs-toggle="modal" data-bs-target="#changelogModal"
               class="badge bg-light text-primary border ms-2 text-decoration-none"
               style="font-size:.75rem;padding:6px 12px;" title="Changelog">
                <i class="fas fa-code-branch me-1"></i><?= htmlspecialchars($latestVersionName) ?>.<?= htmlspecialchars($localHash) ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- Center -->
        <?php if ($isAuth): ?>
        <div class="position-absolute start-50 translate-middle-x text-center">
            <span class="text-white fw-semibold" style="font-size:.9rem;letter-spacing:-.2px;opacity:.85;">
                <?= $isPartner ? 'Partner Solutions Hub' : 'Solution Engineering Hub' ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Right -->
        <?php if ($isAuth): ?>
        <div class="d-flex align-items-center gap-3 flex-wrap ms-auto">
            <?php if ($patExpiry && $patExpiry['status'] !== 'ok'): ?>
            <span class="badge bg-<?= $patExpiry['class'] ?> <?= $patExpiry['class'] === 'warning' ? 'text-dark' : '' ?>">
                <i class="fas fa-<?= $patExpiry['status'] === 'expired' ? 'exclamation-triangle' : 'clock' ?> me-1"></i>
                PAT <?= $patExpiry['status'] === 'expired' ? 'Expired — renew now' : "expires in {$patExpiry['days']}d" ?>
            </span>
            <?php endif; ?>
            <?php if ($isPartner): ?>
                <span class="badge text-white badge-partner"><i class="fas fa-handshake me-1"></i>Partner Edition</span>
            <?php elseif ($isSE): ?>
                <span class="badge bg-success text-white"><i class="fas fa-star me-1"></i>SE Edition</span>
                <a href="<?= $_SERVER['PHP_SELF'] ?>?admin=1" class="btn btn-sm btn-outline-secondary fw-bold">
                    <i class="fas fa-cog me-1"></i>Admin
                </a>
            <?php endif; ?>
            <form method="POST" class="m-0">
                <button type="submit" name="action" value="logout" class="btn btn-link btn-sm text-danger text-decoration-none p-0">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</nav>

<?php if ($isAdminView): ?>
<!-- ═══════════════════════════════════════════════════════
     ADMIN VIEW
════════════════════════════════════════════════════════ -->
<div class="container mt-4 mb-5">

    <div class="admin-header">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-light btn-sm fw-bold flex-shrink-0">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
            <div>
                <h5 class="fw-bold mb-0"><i class="fas fa-cog me-2 opacity-75"></i>PANTools Admin</h5>
            </div>
        </div>
    </div>

    <?php if ($adminMsg && empty($bulkResults)): ?>
    <div class="alert <?= str_starts_with($adminMsg, '✅') ? 'alert-success' : 'alert-danger' ?> mb-4">
        <?= htmlspecialchars($adminMsg) ?>
        <?php if ($genToken): ?>
        <hr class="my-2">
        <div class="fw-semibold small mb-1">Token — copy and share:</div>
        <div class="input-group">
            <input type="text" class="form-control font-monospace small" id="genTokenOut"
                   value="<?= htmlspecialchars($genToken) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="copyField('genTokenOut',this)">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        <div class="mt-2 small text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Expires <?= htmlspecialchars($gExp) ?>.
            To renew, generate a new token with the same Tool Name.
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($bulkResults)): ?>
    <div class="alert alert-light border mb-4">
        <div class="fw-semibold mb-2"><i class="fas fa-layer-group me-1"></i>Bulk Load Results</div>
        <?php foreach ($bulkResults as $r): ?>
        <div class="d-flex align-items-start gap-2 mb-1 small">
            <i class="fas fa-<?= $r['ok'] ? 'check-circle text-success' : 'times-circle text-danger' ?> mt-1" style="flex-shrink:0;"></i>
            <div>
                <strong><?= htmlspecialchars($r['name']) ?></strong>
                <?php if ($r['ok'] && !empty($r['url'])): ?>
                → <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank"><?= htmlspecialchars($r['url']) ?></a>
                <?php else: ?>
                — <?= htmlspecialchars($r['msg']) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Token Generator -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100" style="border-top:3px solid var(--mgmt-color) !important;">
                <div class="card-header bg-dark text-white fw-bold py-3">
                    <i class="fas fa-key me-2"></i>Token Generator
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label fw-semibold small">GitHub PAT <span class="text-danger">*</span></label>
                                <input type="password" name="gen_pat" class="form-control form-control-sm"
                                       placeholder="github_pat_..." required autocomplete="off">
                                <div class="form-text">PAT with read access to the tool's repository.</div>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label fw-semibold small">GitHub Repository <span class="text-danger">*</span></label>
                                <input type="text" name="gen_repo" id="genRepo" class="form-control form-control-sm"
                                       placeholder="owner/repo  or  https://github.com/owner/repo" required
                                       oninput="updateSlugPreview()">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Section <span class="text-danger">*</span></label>
                                <select name="gen_section" id="genSection" class="form-select form-select-sm" required
                                        onchange="updateSlugPreview()">
                                    <option value="cortex">Cortex</option>
                                    <option value="strata">Strata</option>
                                    <option value="management">Management</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Main PHP filename <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="gen_slug" id="genSlug" class="form-control"
                                           placeholder="rfp-generator" required pattern="[a-zA-Z0-9_-]+"
                                           oninput="updateSlugPreview()">
                                    <span class="input-group-text text-muted">.php</span>
                                </div>
                                <div class="form-text">
                                    URL: <code id="slugHint" class="text-primary">cortex/{repo-name}/filename.php</code>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Tool Name <span class="text-danger">*</span>
                                    <span class="text-muted fw-normal">(renewal key)</span>
                                </label>
                                <input type="text" name="gen_name" class="form-control form-control-sm"
                                       placeholder="e.g. Cortex Health Check" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Created By <span class="text-danger">*</span></label>
                                <input type="text" name="gen_creator" class="form-control form-control-sm"
                                       placeholder="Your name" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Expiry Date <span class="text-danger">*</span></label>
                                <input type="date" name="gen_expires" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d', strtotime('+' . TOKEN_LIFETIME_MONTHS . ' months')) ?>" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">Tool Description</label>
                                <input type="text" name="gen_desc" class="form-control form-control-sm"
                                       placeholder="Brief description shown on the hub card...">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold small">Icon</label>
                                <div class="icon-picker mt-1">
                                    <?php $first = true; foreach ($iconCatalogue as $cls => $tip): ?>
                                    <label class="icon-option" title="<?= htmlspecialchars($tip) ?>">
                                        <input type="radio" name="gen_icon" value="<?= htmlspecialchars($cls) ?>"
                                               <?= $first ? 'checked' : '' ?>>
                                        <span class="icon-btn"><i class="<?= htmlspecialchars($cls) ?>"></i></span>
                                    </label>
                                    <?php $first = false; endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="action" value="generate_token" class="btn btn-dark w-100 fw-bold mt-4">
                            <i class="fas fa-plus-circle me-2"></i>Generate / Renew Token
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Load -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm" style="border-top:3px solid var(--border-hi) !important;">
                <div class="card-header bg-secondary text-white fw-bold py-3">
                    <i class="fas fa-layer-group me-2"></i>Bulk Tool Load
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Paste one or more base64 access tokens (one per line).
                        The system will decode each token, download its repository, and register the tool in the hub.
                        If a tool with the same name already exists it will be updated (renewed).
                    </p>
                    <form method="POST">
                        <div class="mb-3">
                            <textarea name="bulk_tokens" class="form-control font-monospace small"
                                      rows="10" placeholder="eyJ2ZXJzaW9uIjoiMSIsInBhd..." required></textarea>
                        </div>
                        <button type="submit" name="action" value="bulk_load" class="btn btn-secondary w-100 fw-bold">
                            <i class="fas fa-layer-group me-2"></i>Load Tools
                        </button>
                    </form>
                </div>
            </div>

            <!-- Active tools quick list -->
            <?php if (!empty($activeTools)): ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-light fw-bold py-2 d-flex justify-content-between">
                    <span><i class="fas fa-th-large me-2 text-muted"></i>Active Tools</span>
                    <span class="badge bg-secondary"><?= count($activeTools) ?></span>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($activeTools as $at):
                        $atExp = !empty($at['expires_at']) ? expiryStatus($at['expires_at']) : null;
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="<?= htmlspecialchars($at['icon'] ?? 'fas fa-plug') ?> text-muted"></i>
                            <div>
                                <div class="small fw-semibold"><?= htmlspecialchars($at['name'] ?? '') ?></div>
                                <div style="font-size:.7rem;" class="text-muted"><?= htmlspecialchars($at['section'] ?? '') ?> / <?= htmlspecialchars($at['slug'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($atExp): ?>
                            <span class="badge bg-<?= $atExp['class'] ?> <?= $atExp['class'] === 'warning' ? 'text-dark' : '' ?>" style="font-size:.65rem;">
                                <?= htmlspecialchars($atExp['label']) ?>
                            </span>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($at['name'] ?? '')) ?> from hub?');">
                                <input type="hidden" name="tool_name" value="<?= htmlspecialchars($at['name'] ?? '') ?>">
                                <input type="hidden" name="from_admin" value="1">
                                <button type="submit" name="action" value="remove_tool" class="btn btn-sm btn-outline-danger py-0 px-1" title="Remove">
                                    <i class="fas fa-times" style="font-size:.7rem;"></i>
                                </button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════
     MAIN HUB VIEW
════════════════════════════════════════════════════════ -->
<div class="container mt-4">

    <?php if ($updateMessage): ?>
    <div class="alert <?= $updateError ? 'alert-danger' : 'alert-success' ?> update-banner shadow-sm mb-4">
        <i class="fas <?= $updateError ? 'fa-exclamation-triangle' : 'fa-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($updateMessage) ?>
    </div>
    <?php elseif ($updateAvailable): ?>
    <div class="alert alert-warning update-banner shadow-sm mb-4 d-flex justify-content-between align-items-center">
        <div><i class="fas fa-sparkles text-warning me-2"></i><strong>New version available!</strong></div>
        <form method="POST">
            <button type="submit" name="action" value="self_update" class="btn btn-dark btn-sm fw-bold px-3">
                <i class="fas fa-cloud-download-alt me-1"></i>UPDATE PANTOOLS
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Token info banner (when logged in via base64 token) -->
    <?php if ($tokenInfo && $isAuth):
        $bannerIcon = $tokenInfo['icon'] ?? 'fas fa-id-badge';
        $tokExp     = !empty($tokenInfo['expires_at']) ? expiryStatus($tokenInfo['expires_at']) : null;
    ?>
    <div class="alert alert-light border mb-4 d-flex align-items-center border-se">
        <i class="<?= htmlspecialchars($bannerIcon) ?> me-3" style="font-size:2rem;color:var(--cortex-color);flex-shrink:0;"></i>
        <div class="flex-grow-1">
            <span class="fw-semibold"><?= htmlspecialchars($tokenInfo['name'] ?? $tokenInfo['description'] ?? 'Access Token') ?></span>
            <?php if (!empty($tokenInfo['description'])): ?>
            <div class="small text-muted"><?= htmlspecialchars($tokenInfo['description']) ?></div>
            <?php endif; ?>
            <div class="small text-muted mt-1">
                Created by <strong><?= htmlspecialchars($tokenInfo['creator'] ?? '') ?></strong>
                <?php if ($tokExp): ?>
                · <span class="badge bg-<?= $tokExp['class'] ?> <?= $tokExp['class'] === 'warning' ? 'text-dark' : '' ?>" style="font-size:.7rem;">
                    <?= htmlspecialchars($tokExp['label']) ?>
                  </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- STRATA -->
    <div class="mb-5">
        <h4 class="section-title title-strata">STRATA <span class="text-muted fs-6 fw-normal">(NGFW & SASE Tools)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-strata p-4 text-center">
                    <div class="card-icon" style="color:var(--strata-color);"><i class="fas fa-fire-alt"></i></div>
                    <h5 class="fw-bold mb-2">PAN Firewall Mapper</h5>
                    <p class="card-desc">Tool for mapping Firewall specs to the new Generation.</p>
                    <a href="strata/panfirewallmapper/index.php" class="btn btn-strata w-100 fw-bold">Open Tool</a>
                </div>
            </div>
            <?php foreach (toolsForSection($activeTools, 'strata') as $dt):
                $dtExp = !empty($dt['expires_at']) ? expiryStatus($dt['expires_at']) : null;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-strata p-4 text-center position-relative <?= ($dtExp && $dtExp['status'] === 'expired') ? 'card-expired' : '' ?>">
                    <?php if ($dtExp && $dtExp['status'] !== 'ok'): ?>
                    <span class="position-absolute top-0 end-0 m-2 badge bg-<?= $dtExp['class'] ?>" style="font-size:.65rem;"><?= htmlspecialchars($dtExp['label']) ?></span>
                    <?php endif; ?>
                    <div class="card-icon" style="color:var(--strata-color);"><i class="<?= htmlspecialchars($dt['icon'] ?? 'fas fa-plug') ?>"></i></div>
                    <h5 class="fw-bold mb-2"><?= htmlspecialchars($dt['name']) ?></h5>
                    <?php if (!empty($dt['creator'])): ?><p class="card-creator">by <?= htmlspecialchars($dt['creator']) ?></p><?php endif; ?>
                    <p class="card-desc"><?= htmlspecialchars($dt['description'] ?? '') ?></p>
                    <a href="<?= htmlspecialchars($dt['url'] ?? '#') ?>" class="btn btn-strata w-100 fw-bold">Open Tool</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CORTEX -->
    <div class="mb-5">
        <h4 class="section-title title-cortex">CORTEX <span class="text-muted fs-6 fw-normal">(SecOps & Cloud Tools)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 text-center">
                    <div class="card-icon" style="color:var(--cortex-color);"><i class="fas fa-clipboard-check"></i></div>
                    <h5 class="fw-bold mb-2">Cortex Health & Audit</h5>
                    <p class="card-desc">Review policies and profiles in use for XDR and XSIAM tenants (BPA/Health Check).</p>
                    <a href="cortex/cortexaudit.php" class="btn btn-cortex w-100 fw-bold">Open Tool</a>
                </div>
            </div>
            <?php if ($isSE): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 text-center">
                    <div class="card-icon" style="color:var(--cortex-color);"><i class="fas fa-box-open"></i></div>
                    <h5 class="fw-bold mb-2">Custom Content Importer</h5>
                    <p class="card-desc">Import custom integrations, layouts, scripts, and playbooks into Cortex.</p>
                    <a href="cortex/contentimporter.php" class="btn btn-cortex w-100 fw-bold">Open Tool</a>
                </div>
            </div>
            <?php foreach (toolsForSection($activeTools, 'cortex') as $dt):
                $dtExp = !empty($dt['expires_at']) ? expiryStatus($dt['expires_at']) : null;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-cortex p-4 text-center position-relative <?= ($dtExp && $dtExp['status'] === 'expired') ? 'card-expired' : '' ?>">
                    <?php if ($dtExp && $dtExp['status'] !== 'ok'): ?>
                    <span class="position-absolute top-0 end-0 m-2 badge bg-<?= $dtExp['class'] ?>" style="font-size:.65rem;"><?= htmlspecialchars($dtExp['label']) ?></span>
                    <?php endif; ?>
                    <div class="card-icon" style="color:var(--cortex-color);"><i class="<?= htmlspecialchars($dt['icon'] ?? 'fas fa-plug') ?>"></i></div>
                    <h5 class="fw-bold mb-2"><?= htmlspecialchars($dt['name']) ?></h5>
                    <?php if (!empty($dt['creator'])): ?><p class="card-creator">by <?= htmlspecialchars($dt['creator']) ?></p><?php endif; ?>
                    <p class="card-desc"><?= htmlspecialchars($dt['description'] ?? '') ?></p>
                    <a href="<?= htmlspecialchars($dt['url'] ?? '#') ?>" class="btn btn-cortex w-100 fw-bold">Open Tool</a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- MANAGEMENT (SE only) -->
    <?php if ($isSE): ?>
    <div class="mb-5">
        <h4 class="section-title title-mgmt">Management <span class="text-muted fs-6 fw-normal">(PoV & Tracking)</span></h4>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-mgmt p-4 text-center">
                    <div class="card-icon" style="color:var(--mgmt-color);"><i class="fas fa-bullseye"></i></div>
                    <h5 class="fw-bold mb-2">PoV Radar</h5>
                    <p class="card-desc">Track TRRs, PoV status, Global Timeline, and direct SFDC links.</p>
                    <a href="other/povradar.php" class="btn-mgmt">Open Tracker</a>
                </div>
            </div>
            <?php foreach (toolsForSection($activeTools, 'management') as $dt):
                $dtExp = !empty($dt['expires_at']) ? expiryStatus($dt['expires_at']) : null;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card card-mgmt p-4 text-center position-relative <?= ($dtExp && $dtExp['status'] === 'expired') ? 'card-expired' : '' ?>">
                    <?php if ($dtExp && $dtExp['status'] !== 'ok'): ?>
                    <span class="position-absolute top-0 end-0 m-2 badge bg-<?= $dtExp['class'] ?>" style="font-size:.65rem;"><?= htmlspecialchars($dtExp['label']) ?></span>
                    <?php endif; ?>
                    <div class="card-icon" style="color:var(--mgmt-color);"><i class="<?= htmlspecialchars($dt['icon'] ?? 'fas fa-plug') ?>"></i></div>
                    <h5 class="fw-bold mb-2"><?= htmlspecialchars($dt['name']) ?></h5>
                    <?php if (!empty($dt['creator'])): ?><p class="card-creator">by <?= htmlspecialchars($dt['creator']) ?></p><?php endif; ?>
                    <p class="card-desc"><?= htmlspecialchars($dt['description'] ?? '') ?></p>
                    <a href="<?= htmlspecialchars($dt['url'] ?? '#') ?>" class="btn-mgmt">Open Tool</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<footer class="text-center py-4 text-muted small border-top mt-5">
    <p class="mb-0">PANTools <?= htmlspecialchars($editionLabel) ?></p>
</footer>

<!-- Changelog Modal -->
<div class="modal fade" id="changelogModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fas fa-history me-2" style="color:var(--mgmt-color);"></i>What's New in PANTools</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <?php if (empty($changelogData)): ?>
        <p class="text-muted text-center">No changelog data found.</p>
        <?php else: ?>
        <?php foreach ($changelogData as $index => $release): ?>
        <div class="mb-4 <?= $index > 0 ? 'opacity-75' : '' ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="fw-bold text-dark mb-0">
                    <?= htmlspecialchars($release['version']) ?>
                    <?php if ($index === 0): ?><span class="badge bg-success ms-2" style="font-size:.6rem;">LATEST</span><?php endif; ?>
                </h5>
                <span class="text-muted small"><i class="far fa-calendar-alt me-1"></i><?= htmlspecialchars($release['date']) ?></span>
            </div>
            <ul class="text-muted small mb-0" style="padding-left:20px;">
                <?php foreach ($release['features'] as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php if ($index < count($changelogData) - 1): ?><hr style="border-top:1px dashed #ddd;"><?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyField(id, btn) {
    const el = document.getElementById(id);
    el.select();
    document.execCommand('copy');
    const icon = btn.querySelector('i');
    icon.className = 'fas fa-check text-success';
    setTimeout(() => { icon.className = 'fas fa-copy'; }, 1500);
}

function updateSlugPreview() {
    const slug    = document.getElementById('genSlug').value    || 'filename';
    const section = document.getElementById('genSection').value || 'cortex';
    const repoRaw = (document.getElementById('genRepo')?.value || '').trim();
    // Strip GitHub URL prefix: accept both "owner/repo" and "https://github.com/owner/repo"
    const repoClean = repoRaw.replace(/^https?:\/\/(www\.)?github\.com\//, '').replace(/\/$/, '');
    const parts     = repoClean.split('/').filter(Boolean);
    const repoName  = parts.length >= 2 ? parts[1] : (parts[0] || '{repo-name}');
    const secPath   = section === 'management' ? 'other' : section;
    document.getElementById('slugHint').textContent = secPath + '/' + repoName + '/' + slug + '.php';
}

document.getElementById('genRepo')?.addEventListener('input', updateSlugPreview);
</script>
</body>
</html>
