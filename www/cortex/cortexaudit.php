<?php
// --- CONFIGURACIÓN ---
set_time_limit(600);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);
// --- VERSIONADO ---
$toolVersion = "v1.0 (10 Feb 2026)";
$compatibilityMsg = "Supports: Cortex XDR 5.0 & XSIAM 3.4 and lower";

$resultData = [];      // Datos de Policy Audit
$healthResults = [];   // Datos de Health Checks
$errorMsg = "";
$debugMsg = "";
$runPolicyAudit = false;
$runHealthChecks = false;

// ==========================================
// 1. FUNCIONES AUXILIARES (CORE)
// ==========================================

function processExportFile($filePath) {
    if (!file_exists($filePath)) return null;

    $rawContent = file_get_contents($filePath);
    $cleanContent = trim($rawContent);
    $cleanContent = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $cleanContent);

    // 1) JSON directo
    $json = json_decode($rawContent, true);
    if (json_last_error() === JSON_ERROR_NONE && (isset($json['data']) || isset($json['profiles']) || isset($json['rules']))) {
        return $json;
    }

    // 2) Base64 en contenedor
    if (is_array($json)) {
        foreach (['export_data', 'content', 'data', 'blob'] as $k) {
            if (isset($json[$k]) && is_string($json[$k])) {
                $cleanContent = $json[$k];
                break;
            }
        }
    }

    // 3) Decode + Decompress
    $base64Clean = preg_replace('/[^a-zA-Z0-9\/\+=]/', '', $cleanContent);
    $decodedBin = base64_decode($base64Clean, true);

    if ($decodedBin !== false) {
        $decompressed = @gzuncompress($decodedBin);
        if (!$decompressed) $decompressed = @gzinflate($decodedBin);
        if (!$decompressed) $decompressed = @gzdecode($decodedBin);

        if ($decompressed) {
            return json_decode($decompressed, true);
        }
    }

    return json_decode($rawContent, true);
}

// Helpers de lectura de configuración
function normKey($s) {
    $s = strtolower((string)$s);
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function findFirstModule($modules, $keys) {
    if (!is_array($keys)) $keys = [$keys];
    foreach ($keys as $k) {
        $m = findModule($modules, $k);
        if (is_array($m)) return $m;
    }
    return null;
}

// Lee value “flexible” de un sub-objeto (puede ser {value:...} o {mode:{value:...}})
function getFlexibleValue($obj) {
    if (!is_array($obj)) return null;

    // Caso { value: "block" }
    if (array_key_exists('value', $obj) && (is_string($obj['value']) || is_bool($obj['value']) || is_numeric($obj['value']))) {
        return $obj['value'];
    }

    // Caso { mode: { value: "block" } }
    if (isset($obj['mode']) && is_array($obj['mode'])) {
        $v = $obj['mode']['value'] ?? null;
        if (is_string($v) || is_bool($v) || is_numeric($v)) return $v;
    }

    return null;
}

// =========================
// PLATFORM NORMALIZATION
// =========================
function normPlatform($plat) {
    $p = strtolower(trim((string)$plat));
    if ($p === '') return 'other';

    if (strpos($p, 'win') !== false) return 'windows';
    if (strpos($p, 'linux') !== false) return 'linux';
    if (strpos($p, 'mac') !== false || strpos($p, 'osx') !== false) return 'mac';

    // futuro: ios/android suelen venir como mobile
    if (strpos($p, 'mobile') !== false || strpos($p, 'android') !== false || strpos($p, 'ios') !== false) return 'mobile';

    return 'other';
}

// =========================
// ROW MATRICES (BY PLATFORM)
// =========================
function getAgentSettingsRowsByPlatform($platform) {
    $plat = normPlatform($platform);

    // 1. Definimos una base MÍNIMA común (sin password)
    $base = [
        ['key' => 'hostinsights',       'label' => 'Host Insights',                'type' => 'mode'],
        ['key' => 'itmetrics',          'label' => 'IT Metrics',                   'type' => 'mode'],
        ['key' => 'prosettings',        'label' => 'EDR PRO',                      'type' => 'mode'],
    ];

    // 2. Configuración específica para WINDOWS
    if ($plat === 'windows') {
        // En Windows SÍ añadimos el Uninstall Password y el resto
        return array_merge([
            ['key' => 'uninstallPassword', 'label' => 'Default Uninstall Password', 'type' => 'uninstall_isDefault']
        ], $base, [
            ['key' => 'weakPasswordsExtraction', 'label' => 'Weak Passwords Extraction',       'type' => 'mode'],
            ['key' => 'backupManagement',        'label' => 'Backup Management',               'type' => 'mode'],
            ['key' => 'sslEnforcement',          'label' => 'Agent Certificate Enforcement',   'type' => 'mode'],
        ]);
    }

    // 3. Configuración específica para MAC
    if ($plat === 'mac') {
        // En Mac también suele aplicar el Uninstall Password
        return array_merge([
            ['key' => 'uninstallPassword', 'label' => 'Default Uninstall Password', 'type' => 'uninstall_isDefault']
        ], $base);
    }

    // 4. Configuración específica para LINUX
    if ($plat === 'linux') {
        // ✅ AQUÍ ES EL CAMBIO: Usamos $base (que NO tiene password) y añadimos lo de Linux
        return array_merge($base, [
            ['key' => 'complianceCollection', 'label' => 'Enable Compliance Collection', 'type' => 'mode'],
        ]);
    }

    if ($plat === 'mobile') {
        return [];
    }

    // Default para "other" (por si acaso, lo dejamos sin password o con él, según prefieras)
    return $base;
}

function getExploitRowsByPlatform($platform) {
    $plat = normPlatform($platform);

    // Mobile normalmente no aplica a estos perfiles
    if ($plat === 'mobile') return [];

    // ✅ Linux: SOLO 3 (los que de verdad aplican)
    if ($plat === 'linux') {
        return [
            ['label' => 'Known Vulnerable Processes Protection',       'kind' => 'mode', 'module' => 'vulnerableApps'],
            ['label' => 'Operating System Exploit Protection',         'kind' => 'mode', 'module' => 'osKernelExploits'],
            ['label' => 'Exploit Protection for Additional Processes', 'kind' => 'mode', 'module' => 'additionalProcesses'],
        ];
    }

    // ✅ Windows/Mac/Other: dejamos el set completo (como lo tenías)
    return [
        ['label' => 'Browser Exploits Protection',                 'kind' => 'mode',  'module' => 'browserExploitKits'],
        ['label' => 'Logical Exploits Protection',                 'kind' => 'mode',  'module' => 'logicalExploits'],
        ['label' => 'Known Vulnerable Processes Protection',       'kind' => 'mode',  'module' => 'vulnerableApps'],
        ['label' => 'Java Deserialization Protection',             'kind' => 'value', 'module' => 'vulnerableApps', 'subkey' => 'javaProtection', 'field' => 'value'],
        ['label' => 'Operating System Exploit Protection',         'kind' => 'mode',  'module' => 'osKernelExploits'],
        ['label' => 'Exploit Protection for Additional Processes', 'kind' => 'mode',  'module' => 'additionalProcesses'],
        ['label' => 'Unpatched Vulnerabilities Protection',        'kind' => 'mode',  'module' => 'autoVulnerabilitiesProtection'],
    ];
}


function getMalwareRowsByPlatform($platform) {
    $plat = normPlatform($platform);

    if ($plat === 'mobile') return [];

 // ✅ macOS: matriz específica (solo lo que quieres revisar)
if ($plat === 'mac') {
    return [
        ['section' => 'File Examination'],

        // Mach-O Execution Examination
        // En tu JSON: examineMacho (no examineMachoExecutables)
        ['label' => 'Mach-O Execution Examination', 'kind' => 'mode', 'module' => ['examineMacho', 'examineMachoExecutables']],

        // Mach-O Loading Examination
        ['label' => 'Mach-O Loading Examination', 'kind' => 'mode', 'module' => ['examineMachoLoading']],

        // DMG File Examination (NO es un módulo plano: está anidado)
        // En tu JSON: examineMacOSInstallers.dmg.mode.value
        ['label' => 'DMG File Examination', 'kind' => 'nested_mode', 'module' => 'examineMacOSInstallers', 'path' => ['dmg']],

        // Local File Threat Examination (en tu JSON existe ltee, no localFileThreatExamination)
        ['label' => 'Local File Threat Examination', 'kind' => 'mode', 'module' => ['ltee', 'localFileThreatExamination']],

        ['section' => 'Threat and Malware Protection'],

        ['label' => 'Global Behavioral Threat Protection Rules', 'kind' => 'mode', 'module' => ['dynamicSecurityEngine']],
        ['label' => 'Credential Gathering Protection',           'kind' => 'mode', 'module' => ['passwordStealing']],
        ['label' => 'Anti Webshell Protection',                  'kind' => 'mode', 'module' => ['webshellDroppers']],
        ['label' => 'Financial Malware Threat Protection',       'kind' => 'mode', 'module' => ['financialMalwareThreat']],
        ['label' => 'Cryptominers Protection',                   'kind' => 'mode', 'module' => ['cryptominers']],
        ['label' => 'Malicious Device Prevention',               'kind' => 'mode', 'module' => ['maliciousDevice']],
        ['label' => 'Anti Tampering Protection',                 'kind' => 'mode', 'module' => ['antiTampering']],

        // Ransomware: en tu JSON es ransomwareProtection (no ransomware)
        ['label' => 'Ransomware Protection',                     'kind' => 'mode', 'module' => ['ransomwareProtection', 'ransomware']],

        // “Malicious Child Process Protection”: en tu JSON encaja con legitimateProcesses
        ['label' => 'Malicious Child Process Protection',        'kind' => 'mode', 'module' => ['legitimateProcesses', 'maliciousChildProcessProtection']],

        ['label' => 'Respond to Malicious Causality Chains',     'kind' => 'mode', 'module' => ['maliciousCausalityChainsResponse']],
        ['label' => 'Network Packet Inspection Engine',          'kind' => 'mode', 'module' => ['networkSignature']],
    ];
}



    // ✅ LINUX: matriz propia (la tuya)
    // ✅ LINUX: matriz ajustada a la UI moderna de Cortex XDR
    if ($plat === 'linux') {
        return [
            ['section' => 'File Examination'],

            ['label' => 'ELF Execution Examination',         'kind' => 'mode', 'module' => ['examineELFs']],
            ['label' => 'Loaded Kernel Modules Examination', 'kind' => 'mode', 'module' => ['examineLoadedKMs']],
            // Añadimos el LTEE que sí sale en la Web
            ['label' => 'Local File Threat Examination',     'kind' => 'mode', 'module' => ['ltee', 'localFileThreatExamination']],

            // Quitamos la genérica de On-write y dejamos solo las 3 que se ven en la UI
            ['label' => 'On-write: ELF files', 'kind' => 'value', 
                'module' => 'examineELFs', 'field' => 'onWriteProtection', 'subfield' => 'value'
            ],
            ['label' => 'On-write: Portable executable files (Windows)', 'kind' => 'value', 
                'module' => 'examinePortableExecutablesLinux', 'field' => 'onWriteProtection', 'subfield' => 'value'
            ],
            ['label' => 'On-write: Mach-O files (MacOs)', 'kind' => 'value', 
                'module' => 'examineMachoLinux', 'field' => 'onWriteProtection', 'subfield' => 'value'
            ],

            ['section' => 'Threat and Malware Protection'],

            ['label' => 'Global Behavioral Threat Protection Rules', 'kind' => 'mode', 'module' => ['dynamicSecurityEngine']],
            ['label' => 'Credential Gathering Protection',           'kind' => 'mode', 'module' => ['passwordStealing']],
            ['label' => 'Financial Malware Threat Protection',       'kind' => 'mode', 'module' => ['financialMalwareThreat']],
            ['label' => 'Cryptominers Protection',                   'kind' => 'mode', 'module' => ['cryptominers']],
            ['label' => 'Container Escaping Protection',             'kind' => 'mode', 'module' => ['containerEscapingProtection']],
            ['label' => 'Reverse Shell Protection',                  'kind' => 'mode', 'module' => ['reverseShell']],
            ['label' => 'Anti Webshell Protection',                  'kind' => 'mode', 'module' => ['webshellDroppers']],
        ];
    }

    // ✅ Windows/Other: tu matriz “larga” (la de siempre)
    return [
        ['section' => 'File Examination'],
        ['label' => 'Portable Executables and DLL Examination', 'kind' => 'mode',  'module' => 'examinePortableExecutables'],
        ['label' => 'Office Files with Macros Examination',     'kind' => 'mode',  'module' => 'examineOfficeFiles'],
        ['label' => 'JScript File Examination',                'kind' => 'mode',  'module' => 'examineJScriptFiles'],
        ['label' => 'ASP & ASPX Files',                        'kind' => 'mode',  'module' => 'aspFiles'],
        ['label' => 'PowerShell Script Files',                 'kind' => 'mode',  'module' => 'powerShellScriptFiles'],
        ['label' => 'On-write File Examination',               'kind' => 'mode',  'module' => 'onWriteProtection'],

        ['label' => 'On-write: Portable Executables and DLL', 'kind' => 'value', 'module' => 'examinePortableExecutables', 'field' => 'onWriteProtection', 'subfield' => 'value'],
        ['label' => 'On-write: Office Files with Macros',     'kind' => 'value', 'module' => 'examineOfficeFiles',        'field' => 'onWriteProtection', 'subfield' => 'value'],
        ['label' => 'On-write: PowerShell Script Files',      'kind' => 'value', 'module' => 'powerShellScriptFiles',     'field' => 'onWriteProtection', 'subfield' => 'value'],
        ['label' => 'On-write: ASP & ASPX Files',             'kind' => 'value', 'module' => 'aspFiles',                  'field' => 'onWriteProtection', 'subfield' => 'value'],
        ['label' => 'On-write: VB Script Files',              'kind' => 'value', 'module' => 'examineVBScriptFiles',      'field' => 'onWriteProtection', 'subfield' => 'value'],
        ['label' => 'On-write: JScript Files',                'kind' => 'value', 'module' => 'examineJScriptFiles',       'field' => 'onWriteProtection', 'subfield' => 'value'],

        ['label' => 'VB Scripts Examination',                 'kind' => 'mode',  'module' => 'examineVBScriptFiles'],

        ['section' => 'Threat and Malware Protection'],
        ['label' => 'Global Behavioral Threat Protection Rules', 'kind' => 'mode',  'module' => 'dynamicSecurityEngine'],
        ['label' => 'Advanced API Monitoring',                  'kind' => 'value', 'module' => 'dynamicSecurityEngine', 'field' => 'advancedApiMonitoring', 'subfield' => 'value'],
        ['label' => 'Global Vulnerable Drivers Protection',      'kind' => 'value', 'module' => 'dynamicSecurityEngine', 'field' => 'driversProtectionMode', 'subfield' => 'value'],

        ['label' => 'Credential Gathering Protection',           'kind' => 'mode',  'module' => 'passwordStealing'],
        ['label' => 'Anti Webshell Protection',                  'kind' => 'mode',  'module' => 'webshellDroppers'],
        ['label' => 'Financial Malware Threat Protection',       'kind' => 'mode',  'module' => 'financialMalwareThreat'],
        ['label' => 'Crypto Wallet Protection',                  'kind' => 'value', 'module' => 'financialMalwareThreat', 'field' => 'cryptoWalletProtection', 'subfield' => 'value'],
        ['label' => 'Cryptominers Protection',                   'kind' => 'mode',  'module' => 'cryptominers'],

        ['label' => 'In-process shellcode protection',           'kind' => 'mode',  'module' => 'inProcessShellcode'],
        ['label' => 'Process Injection 32 Bit',                  'kind' => 'value', 'module' => 'inProcessShellcode', 'field' => 'processInjection32Bit', 'subfield' => 'value'],
        ['label' => 'AI Powered Shellcode Protection',           'kind' => 'value', 'module' => 'inProcessShellcode', 'field' => 'aiPoweredShellcodeProtection', 'subfield' => 'value'],

        ['label' => 'Malicious Device Prevention',               'kind' => 'mode',  'module' => 'maliciousDevice'],
        ['label' => 'UAC Bypass Prevention',                     'kind' => 'mode',  'module' => 'uacBypass'],
        ['label' => 'Anti Tampering Protection',                 'kind' => 'mode',  'module' => 'antiTampering'],
        ['label' => 'Malicious Safe Mode Rebooting Protection',  'kind' => 'value', 'module' => 'antiTampering', 'field' => 'safeMode', 'subfield' => 'value'],

        ['label' => 'IIS Protection',                            'kind' => 'mode',  'module' => 'iisProtection'],
        ['label' => 'UEFI Protection',                           'kind' => 'mode',  'module' => 'uefiProtection'],

        ['label' => 'Ransomware Protection',                     'kind' => 'mode',  'module' => 'ransomware'],
        ['label' => 'Ransomware Protection Mode',                'kind' => 'value_neutral', 'module' => 'ransomware', 'field' => 'protectionMode', 'subfield' => 'value'],

        ['label' => 'Password Theft Protection',                 'kind' => 'mode',  'module' => 'passwordTheftProtection'],
        ['label' => 'Respond to Malicious Causality Chains',     'kind' => 'mode',  'module' => 'maliciousCausalityChainsResponse'],

        ['label' => 'Network Packet Inspection Engine',          'kind' => 'mode',  'module' => 'networkSignature'],
        ['label' => 'Dynamic Kernel Protection',                 'kind' => 'mode',  'module' => 'dynamicKernelProtection'],
        ['label' => 'Dynamic Driver Protection',                 'kind' => 'mode',  'module' => 'dynamicDriverProtection'],
        ['label' => 'Security Measures Bypass',                  'kind' => 'mode',  'module' => 'securityMeasuresBypass'],
    ];
}


function findModule($modules, $wantedKey) {
    if (!is_array($modules)) return null;
    $wanted = normKey($wantedKey);
    foreach ($modules as $k => $v) {
        if (!is_string($k)) continue;
        if (normKey($k) === $wanted) return $v;
    }
    return null;
}

function getModeValue($moduleVal) {
    if (!is_array($moduleVal)) return null;
    if (!isset($moduleVal['mode']) || !is_array($moduleVal['mode'])) return null;
    $v = $moduleVal['mode']['value'] ?? null;
    return (is_string($v) || is_bool($v) || is_numeric($v)) ? $v : null;
}

function getFieldValue($moduleVal, $field) {
    if (!is_array($moduleVal)) return null;
    $v = $moduleVal[$field] ?? null;
    return (is_string($v) || is_bool($v) || is_numeric($v)) ? $v : null;
}

// Badges visuales
function badgeAction($value) {
    if ($value === null) return '<span class="text-muted">—</span>';
    $lv = strtolower((string)$value);

    if ($lv === 'block' || $lv === 'enabled' || $value === true || $value === 1 || $value === '1') {
        return '<span class="status-green">🟢 ' . htmlspecialchars((string)$value) . '</span>';
    }
    if ($lv === 'disabled' || $value === false || $value === 0 || $value === '0') {
        return '<span class="status-red">🔴 ' . htmlspecialchars((string)$value) . '</span>';
    }
    if ($lv === 'report') {
        return '<span class="status-orange">⚠️ report</span>';
    }
    return '<span class="status-muted">' . htmlspecialchars((string)$value) . '</span>';
}

function badgeNeutral($value) {
    if ($value === null) return '<span class="text-muted">—</span>';
    return '<span class="status-muted">' . htmlspecialchars((string)$value) . '</span>';
}

// Renderizado genérico de configuración
function formatConfigToHtml($data) {
    if (empty($data)) return '<span class="text-muted">No configuration</span>';
    if (!is_array($data)) return htmlspecialchars((string)$data);

    $html = '<table class="config-table">';
    foreach ($data as $key => $value) {
        if (in_array($key, ['_id', 'id', 'tenant_id', 'insert_time', 'update_time'])) continue;

        $html .= '<tr>';
        $html .= '<td class="cfg-key">' . htmlspecialchars(str_replace('_', ' ', strtoupper($key))) . '</td>';
        $html .= '<td class="cfg-val">';

        if (is_array($value)) {
            if (array_keys($value) === range(0, count($value) - 1) && !is_array($value[0] ?? null)) {
                $html .= implode(', ', $value);
            } else {
                $html .= formatConfigToHtml($value);
            }
        } else {
            if ($value === true || $value === 'true') $html .= '<span class="status-green">ENABLED</span>';
            elseif ($value === false || $value === 'false') $html .= '<span class="status-red">DISABLED</span>';
            else $html .= htmlspecialchars((string)$value);
        }
        $html .= '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

// ==========================================
// 2. FUNCIONES DE FILTRADO (VISUALIZACIÓN)
// ==========================================

function formatAgentSettingsFiltered($profileData, $platform = 'other') {
    if (empty($profileData) || !is_array($profileData)) return '<span class="text-muted">No configuration</span>';
    $modules = $profileData['modules'] ?? null;
    if (!is_array($modules)) return '<span class="text-muted">No modules in profile</span>';

    $rows = getAgentSettingsRowsByPlatform($platform);
    if (empty($rows)) return '<span class="text-muted">Not applicable for this platform</span>';

    $html = '<table class="config-table">';
    foreach ($rows as $r) {
        $m = findModule($modules, $r['key']);
        $html .= '<tr><td class="cfg-key">' . htmlspecialchars($r['label']) . '</td><td class="cfg-val">';

        if (($r['type'] ?? '') === 'uninstall_isDefault') {
            $isDef = (is_array($m) && array_key_exists('isDefault', $m)) ? $m['isDefault'] : null;
            if ($isDef === 'true' || $isDef === true || $isDef === 1) $html .= '<span class="status-red">🔴 Default Password</span>';
            elseif ($isDef === 'false' || $isDef === false || $isDef === 0) $html .= '<span class="status-green">🟢 Custom Password</span>';
            else $html .= '<span class="text-muted">—</span>';
        } else {
            $html .= badgeAction(getModeValue($m));
        }

        $html .= '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

function formatExploitFiltered($profileData, $platform = 'other') {
    if (empty($profileData) || !is_array($profileData)) return '<span class="text-muted">No configuration</span>';
    $modules = $profileData['modules'] ?? null;
    if (!is_array($modules)) return '<span class="text-muted">No modules in profile</span>';

    $rows = getExploitRowsByPlatform($platform);
    if (empty($rows)) return '<span class="text-muted">Not applicable for this platform</span>';

    $html = '<table class="config-table">';
    foreach ($rows as $r) {
        $html .= '<tr><td class="cfg-key">' . htmlspecialchars($r['label']) . '</td><td class="cfg-val">';

        if (($r['kind'] ?? '') === 'mode') {
            $m = findModule($modules, $r['module']);
            if (($r['module'] ?? '') === 'additionalProcesses') $html .= badgeNeutral(getModeValue($m));
            else $html .= badgeAction(getModeValue($m));
        } else {
            $parent = findModule($modules, $r['module']);
            $sub = (is_array($parent) && isset($parent[$r['subkey']])) ? $parent[$r['subkey']] : null;
            $html .= badgeAction(getFieldValue($sub, $r['field']));
        }

        $html .= '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}

function formatMalwareFiltered($profileData, $platform = 'other') {
    if (empty($profileData) || !is_array($profileData)) return '<span class="text-muted">No configuration</span>';
    $modules = $profileData['modules'] ?? null;
    if (!is_array($modules)) return '<span class="text-muted">No modules in profile</span>';

    $rows = getMalwareRowsByPlatform($platform);
    if (empty($rows)) return '<span class="text-muted">Not applicable for this platform</span>';

    $html = '<table class="config-table">';
    foreach ($rows as $r) {
        if (isset($r['section'])) {
            $html .= '<tr><td class="cfg-key section-row" colspan="2">' . htmlspecialchars($r['section']) . '</td></tr>';
            continue;
        }

        $html .= '<tr><td class="cfg-key">' . htmlspecialchars($r['label']) . '</td><td class="cfg-val">';
        $m = findModule($modules, $r['module']);

     $kind = $r['kind'] ?? '';

if ($kind === 'mode') {
    // module puede ser string o array de aliases
    $m = is_array($r['module']) ? findFirstModule($modules, $r['module']) : findModule($modules, $r['module']);
    $html .= badgeAction(getModeValue($m));

} elseif ($kind === 'value' || $kind === 'value_neutral') {
    $m = findModule($modules, $r['module']);
    $fieldObj = (is_array($m) && isset($m[$r['field']]) && is_array($m[$r['field']])) ? $m[$r['field']] : null;
    $val = getFieldValue($fieldObj, $r['subfield']);
    if ($kind === 'value_neutral') $html .= badgeNeutral($val);
    else $html .= badgeAction($val);

} elseif ($kind === 'onwrite_per_type') {
    // Lee onWriteProtection + subkey (elf/pe/macho)
    $parent = is_array($r['module']) ? findFirstModule($modules, $r['module']) : findModule($modules, $r['module']);
    $val = null;

    if (is_array($parent)) {
        $subkeys = $r['subkey'] ?? [];
        if (!is_array($subkeys)) $subkeys = [$subkeys];

        foreach ($subkeys as $sk) {
            if (isset($parent[$sk]) && is_array($parent[$sk])) {
                $val = getFlexibleValue($parent[$sk]);
                if ($val !== null) break;
            }
        }
    }

    // si no se encuentra, “—”
    $html .= badgeAction($val);
} elseif ($kind === 'nested_mode') {
    // ejemplo: module = examineMacOSInstallers, path = ['dmg']
    $parent = findModule($modules, $r['module']);
    $val = null;

    if (is_array($parent)) {
        $node = $parent;
        $path = $r['path'] ?? [];
        if (!is_array($path)) $path = [$path];

        foreach ($path as $p) {
            if (is_array($node) && isset($node[$p]) && is_array($node[$p])) {
                $node = $node[$p];
            } else {
                $node = null;
                break;
            }
        }

        if (is_array($node)) {
            // aquí node debería tener structure { mode: { value: ... } }
            $val = getModeValue($node);
        }
    }

    $html .= badgeAction($val);

} else {
    $html .= '<span class="text-muted">—</span>';
}


        $html .= '</td></tr>';
    }
    $html .= '</table>';
    return $html;
}


// ==========================================
// 3. FUNCIÓN API XDR GENERICA
// ==========================================
function callXdrApi($baseUrl, $headers, $path, $method = 'POST', $body = ["request_data" => []]) {
    $url = rtrim($baseUrl, '/') . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['error' => "cURL error: $err"];
    }

    $res = json_decode($raw, true);
    if (!is_array($res)) {
        return ['error' => "Invalid JSON response (HTTP $httpCode): " . substr($raw, 0, 200)];
    }

    if ($httpCode >= 400) {
        $msg = $res['reply']['err_msg'] ?? $res['reply']['error'] ?? json_encode($res['reply'] ?? $res);
        return ['error' => "HTTP $httpCode: " . substr((string)$msg, 0, 300)];
    }

    return $res;
}

function runXqlQuery($baseUrl, $headers, $query) {
    // 7 días de timeframe fijo (para queries que no pongan config timeframe)
    $payload = ["request_data" => ["query" => $query, "tenants" => [], "timeframe" => ["relativeTime" => 86400000 * 7]]];

    // PASO 1: START QUERY
    $ch = curl_init("$baseUrl/public_api/v1/xql/start_xql_query");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $rawStart = curl_exec($ch);
    $startRes = json_decode($rawStart, true);
    curl_close($ch);

    // Si falla el inicio, devolvemos el error crudo
    if (!isset($startRes['reply']) || !is_string($startRes['reply'])) {
        $errMsg = $startRes['reply']['err_msg'] ?? json_encode($startRes);
        return ['error' => "Start Failed: " . substr($errMsg, 0, 200)];
    }

    $queryId = $startRes['reply'];
    sleep(4);

    // PASO 2: GET RESULTS
    $payloadRes = ["request_data" => ["query_id" => $queryId, "pending_flag" => false, "limit" => 1000, "format" => "json"]];

    $ch = curl_init("$baseUrl/public_api/v1/xql/get_query_results");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadRes));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $rawFinal = curl_exec($ch);
    $finalRes = json_decode($rawFinal, true);
    curl_close($ch);

    if (($finalRes['reply']['status'] ?? '') !== 'SUCCESS') {
        $status = $finalRes['reply']['status'] ?? 'Unknown';
        $err = $finalRes['reply']['error'] ?? $finalRes['reply']['err_msg'] ?? null;
        $errTxt = $err ? (" | " . (is_string($err) ? $err : json_encode($err))) : "";
        return ['error' => "Status: $status$errTxt"];
    }

    return $finalRes['reply']['results']['data'] ?? [];
}

// ==========================================
// 4. LÓGICA DE NEGOCIO (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerName = trim($_POST['customer_name'] ?? '');
    $apiKeyId = trim($_POST['api_key_id'] ?? '');
    $apiKey   = trim($_POST['api_key'] ?? '');
    $baseUrl  = rtrim(trim($_POST['base_url'] ?? ''), '/');

    // VARIABLES DE CONTROL
    $mode = $_POST['run_mode'] ?? 'health'; // por defecto health

    $runHealthChecks = ($mode === 'health');
    $runPolicyAudit  = ($mode === 'policy');


    $headers = [
        "x-xdr-auth-id: $apiKeyId",
        "Authorization: $apiKey",
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    // --- A. EJECUTAR HEALTH CHECKS (SI SE SELECCIONÓ) ---
    if ($runHealthChecks) {
        $checkDefinitions = [
            [
            'name' => 'Tenant Type (XDR vs XSIAM)',
            'desc' => 'Detects tenant type.',
            'api_call' => [
                'path' => '/public_api/v1/system/get_tenant_info',
                'method' => 'POST',
                'body' => ['request_data' => new stdClass()]
            ],
            'validate' => function($apiResponse) {
                $reply = $apiResponse['reply'] ?? null;
                if (!is_array($reply)) {
                    return ['status' => 'ERROR', 'msg' => 'Unexpected response format (missing reply).'];
                }

                $keys = array_map('strtolower', array_keys($reply));

                // ✅ ÚNICO criterio: existe alguna key que contenga "xsiam"
                $isXsiam = false;
                $xsiamKeys = [];
                foreach ($keys as $k) {
                    if (strpos($k, 'xsiam') !== false) {
                        $isXsiam = true;
                        $xsiamKeys[] = $k;
                    }
                }

                // Info opcional para el mensaje
                $notes = '';
                $additional = $reply['additional_data'] ?? null;
                if (is_array($additional) && !empty($additional)) {
                    $notes = ' | Notes: ' . htmlspecialchars(implode(' ; ', $additional));
                }

                if ($isXsiam) {
                    // intenta sacar algo útil si existe
                    $exp = $reply['xsiam_premium_expiration'] ?? '-';
                    $sampleKey = $xsiamKeys[0] ?? 'xsiam_*';
                    return [
                        'status' => 'OK',
                        'msg' => "Tenant detected: <strong>XSIAM</strong> (key: <code>" . htmlspecialchars($sampleKey) . "</code>) | Exp: " . htmlspecialchars((string)$exp) . $notes
                    ];
                }

                // Si no hay ninguna key 'xsiam', lo marcamos como XDR (práctico para tus tenants)
                return [
                    'status' => 'OK',
                    'msg' => "Tenant detected: <strong>XDR</strong>" . $notes
                ];
            }
            ],
            [
    'name' => 'Tenant Licensing (Purchased + Expiration)',
    'desc' => 'Shows purchased entitlements and expirations.',
    'api_call' => [
        'path' => '/public_api/v1/system/get_tenant_info',
        'method' => 'POST',
        'body' => ['request_data' => new stdClass()]
    ],
    'validate' => function($apiResponse) {

        $reply = $apiResponse['reply'] ?? null;
        if (!is_array($reply)) {
            return ['status' => 'ERROR', 'msg' => 'Unexpected response (missing reply).'];
        }

        // Helpers
        $normLabel = function($s) {
            $s = str_replace(['purchased_'], '', $s);
            $s = str_replace('_', ' ', $s);
            return strtoupper($s);
        };

        $toScalar = function($v) {
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_numeric($v)) return (string)$v;
            if (is_string($v)) return $v;
            return '';
        };

        // Normaliza purchased_* para pintar SOLO números:
        //  - {gb:0} -> "0"
        //  - "agents=50" -> "50"
        //  - 50 -> "50"
        //  - {agents:200, gb:100} -> "200, 100"
        $extractPurchasedNormalized = function($v) use ($toScalar) {
            if (is_array($v)) {
                $vals = [];
                foreach ($v as $k => $vv) {
                    if (is_array($vv)) continue;
                    $s = trim($toScalar($vv));
                    if ($s === '') continue;
                    $vals[] = $s;
                }
                return empty($vals) ? '—' : implode(', ', $vals);
            }

            $s = trim($toScalar($v));
            if ($s === '') return '—';

            // "agents=50" / "gb=1" / "workloads=0" -> "50"/"1"/"0"
            if (preg_match('/^\s*[a-zA-Z_]+\s*=\s*([0-9]+)\s*$/', $s, $m)) {
                return $m[1];
            }

            return $s;
        };

        // 1) Construimos módulos desde purchased_*
        //    Guardamos flags RAW para filtrar correctamente:
        //    - quitar si raw era scalar 0 (p.ej. prevent=0)
        //    - quitar si raw era array con ONLY users (p.ej. {users:0})
        //    - quitar si purchased es "—"
        $modules = []; // base => row
        foreach ($reply as $k => $v) {
            if (strpos($k, 'purchased_') === 0) {
                $base = substr($k, strlen('purchased_'));

                // raw scalar 0
                $rawPlainZero = false;
                if (is_numeric($v) && (float)$v == 0.0) $rawPlainZero = true;
                if (is_string($v) && trim($v) === '0') $rawPlainZero = true;

                // raw array ONLY users (Agentix SOAR/TIM típico)
                $rawOnlyUsers = false;
                if (is_array($v)) {
                    $keys = array_map('strtolower', array_keys($v));
                    if (count($keys) === 1 && $keys[0] === 'users') {
                        $rawOnlyUsers = true;
                    }
                }

                // legacy string "users=0"
                $rawUsersString = false;
                if (is_string($v) && stripos($v, 'users=') !== false) {
                    $rawUsersString = true;
                }

                $modules[$base] = [
                    'module' => $base,
                    'purchased' => $extractPurchasedNormalized($v),
                    'expiration' => '—',
                    'raw_plain_zero' => $rawPlainZero,
                    'raw_only_users' => $rawOnlyUsers,
                    'raw_users_string' => $rawUsersString
                ];
            }
        }

        // 2) Añadimos expiraciones *_expiration
        foreach ($reply as $k => $v) {
            if (substr($k, -11) === '_expiration') {
                $base = substr($k, 0, -11);
                if (!isset($modules[$base])) {
                    $modules[$base] = [
                        'module' => $base,
                        'purchased' => '—',
                        'expiration' => '—',
                        'raw_plain_zero' => false,
                        'raw_only_users' => false,
                        'raw_users_string' => false
                    ];
                }
                $exp = trim($toScalar($v));
                $modules[$base]['expiration'] = ($exp !== '') ? $exp : '—';
            }
        }

        // 3) Filtrado:
        //    - quitar purchased "—"
        //    - quitar los que en purchased eran ONLY users (users=0) (Agentix SOAR/TIM)
        //    - quitar los legacy string users=
        //    - quitar los scalar 0 (p.ej. prevent=0)
        //    (IMPORTANTE: NO quitamos {gb:0}, porque raw_only_users solo aplica a users)
        foreach ($modules as $base => $row) {
            $p = trim((string)($row['purchased'] ?? '—'));
            $isDash = ($p === '' || $p === '—' || $p === '-');

            if ($isDash) { unset($modules[$base]); continue; }
            if (!empty($row['raw_users_string'])) { unset($modules[$base]); continue; }
            if (!empty($row['raw_only_users'])) { unset($modules[$base]); continue; }
            if (!empty($row['raw_plain_zero'])) { unset($modules[$base]); continue; }
        }

        if (empty($modules)) {
            return ['status' => 'OK', 'msg' => 'No relevant purchased entitlements found.'];
        }

        // Status global: WARN si hay additional_data
        $globalStatus = 'OK';
        $additional = $reply['additional_data'] ?? [];
        $notes = '';
        if (is_array($additional) && !empty($additional)) {
            $globalStatus = 'WARN';
            $notes = ' | Notes: ' . htmlspecialchars(implode(' ; ', $additional));
        }

        // Orden por nombre
        ksort($modules);

        // Render tabla (3 columnas)
        $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
        $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
        $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                    <th style="padding:8px 12px; text-align:left;">Module</th>
                    <th style="padding:8px 12px; text-align:left;">Purchased</th>
                    <th style="padding:8px 12px; text-align:left;">Expiration</th>
                  </tr></thead><tbody>';

        foreach ($modules as $m) {
            $mod = htmlspecialchars($normLabel($m['module']));
            $pur = htmlspecialchars($m['purchased'] ?? '—'); // ya normalizado sin agents=/gb=/workloads=
            $exp = htmlspecialchars($m['expiration'] ?? '—');

            $html .= "<tr style='border-bottom:1px solid #eee;'>
                        <td style='padding:8px 12px; font-weight:bold; color:#333;'>$mod</td>
                        <td style='padding:8px 12px; color:#555;'>$pur</td>
                        <td style='padding:8px 12px; color:#555;'>$exp</td>
                      </tr>";
        }

        $html .= '</tbody></table></div>';

        return [
            'status' => $globalStatus,
            'msg' => 'Licensing snapshot loaded. See details below ↴' . $notes,
            'detail_html' => $html
        ];
    }
],

            [
                'name' => 'Analytics Engine Activity',
                'desc' => 'Checks if the Analytics Engine has generated any alerts (Bioc/Analytics) in the last 7 days.',
                'query' => 'config timeframe = 30d case_sensitive = false | dataset = alerts | filter alert_source in (ENUM.XDR_ANALYTICS, ENUM.XDR_ANALYTICS_BIOC) | comp count() as Alerts',
                'validate' => function($rows) {
                    $count = $rows[0]['Alerts'] ?? 0;
                    if ($count > 0) return ['status' => 'OK', 'msg' => "Functional ($count alerts generated)"];
                    return ['status' => 'FAIL', 'msg' => 'No analytics alerts in 7 days. Check coverage.'];
                }
            ],

            // --- CHECK DE NGFW (INGESTA) ---
            [
                'name' => 'NGFW Log Ingestion',
                'desc' => 'Checks if connected Firewalls are sending Traffic and EAL (Enhanced Application Logs) correctly.',
                'query' => 'config timeframe = 7d | dataset = metrics_source | filter _PRODUCT = "NGFW" | alter ngfw_serial = _device_id | filter array_length(regextract(ngfw_serial, "[A-Za-z]")) = 0 | fields ngfw_serial, _log_type | dedup ngfw_serial, _log_type | comp count(if(_log_type = "traffic", 1, null)) as has_traffic, count(if(_log_type = "eal", 1, null)) as has_eal by ngfw_serial | alter status = if(has_eal > 0 and has_traffic > 0, "OK", if(has_eal > 0 and has_traffic = 0, "FAIL_only_eal", if(has_eal = 0 and has_traffic > 0, "FAIL_only_traffic", "OTHER"))) | alter eal = if(has_eal > 0, "yes", "no") | alter traffic = if(has_traffic > 0, "yes", "no") | fields ngfw_serial, eal, traffic, status | sort asc status, asc ngfw_serial',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'NEUTRAL', 'msg' => 'No NGFW detected (Normal if no firewall is connected)'];
                    }

                    $globalStatus = 'OK';
                    $html = '<div style="margin-top:8px; font-size:0.9em; line-height:1.6;">';

                    foreach($rows as $r) {
                        $sn = htmlspecialchars($r['ngfw_serial'] ?? '-');
                        $st = $r['status'] ?? 'OTHER';

                        if ($st === 'OK') {
                            $html .= "<div style='color:#2e7d32;'>✔ <strong>$sn</strong>: OK (Traffic + EAL)</div>";
                        } elseif ($st === 'FAIL_only_traffic') {
                            $html .= "<div style='color:#c62828;'>✖ <strong>$sn</strong>: Missing EAL Logs!</div>";
                            $globalStatus = 'WARN';
                        } elseif ($st === 'FAIL_only_eal') {
                            $html .= "<div style='color:#c62828;'>✖ <strong>$sn</strong>: Missing Traffic Logs!</div>";
                            $globalStatus = 'WARN';
                        } else {
                            $html .= "<div style='color:#777;'>● <strong>$sn</strong>: Passive/Other</div>";
                        }
                    }
                    $html .= '</div>';

                    return ['status' => $globalStatus, 'msg' => $html];
                }
            ],

            [
                'name' => 'Identity Sources (CIE)',
                'desc' => 'Validates presence of Hybrid Identity sources (AD + Cloud/SCIM).',
                'query' => 'dataset = pan_dss_raw | fields domain_name as IdentityDomain, source | dedup IdentityDomain, source',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'FAIL', 'msg' => 'No Identity Sources found (Check CIE configuration).'];
                    }

                    $hasAD = false;
                    $hasCloud = false;
                    $sourcesList = [];

                    foreach ($rows as $r) {
                        $srcRaw = $r['source'] ?? 'Unknown';
                        $src = strtolower($srcRaw);
                        $domain = $r['IdentityDomain'] ?? '-';

                        $sourcesList[] = "<strong>$domain</strong> ($srcRaw)";

                        if ($src === 'ad' || (strpos($src, 'active directory') !== false && strpos($src, 'azure') === false)) {
                            $hasAD = true;
                        }
                        if ($src === 'aad' || $src === 'scim' || strpos($src, 'azure') !== false || strpos($src, 'scim') !== false || strpos($src, 'okta') !== false) {
                            $hasCloud = true;
                        }
                    }

                    $details = implode(', ', array_unique($sourcesList));

                    if ($hasAD && $hasCloud) {
                        return ['status' => 'OK', 'msg' => "Hybrid Identity Configured: " . $details];
                    }

                    return ['status' => 'WARN', 'msg' => "Partial/Non-Hybrid Identity detected: " . $details];
                }
            ],

            [
                'name' => 'CIE Synchronization Status',
                'desc' => 'Checks for Identity domains that have not synced with Cortex in over 24 hours.',
                'query' => 'dataset = pan_dss_raw | fields record_generated_time, domain_name | sort desc record_generated_time | dedup domain_name | filter timestamp_diff(current_time(), record_generated_time, "DAY") > 1 | alter daysNoUpdate = timestamp_diff(current_time(), record_generated_time, "DAY") | fields domain_name as name, daysNoUpdate',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'All domains syncing correctly (last update < 24h).'];
                    }

                    $list = [];
                    foreach ($rows as $r) {
                        $name = htmlspecialchars($r['name'] ?? 'Unknown');
                        $days = $r['daysNoUpdate'] ?? '?';
                        $list[] = "<strong>$name</strong> ($days days ago)";
                    }

                    return [
                        'status' => 'WARN',
                        'msg' => "Sync delays detected:<br>" . implode('<br>', $list)
                    ];
                }
            ],

            [
                'name' => 'Automation Scripts Reliability',
                'desc' => 'Monitors the failure rate of automated scripts over the last 90 days.',
                'query' => 'config timeframe = 90d | dataset = scripts_and_commands_metrics | filter type = "automation" and not is_manual | top is_error | alter percentage_of_failed_scripts = round(top_percent) | filter is_error',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'Stable: 0% failure rate detected.'];
                    }

                    $failRate = $rows[0]['percentage_of_failed_scripts'] ?? 0;

                    if ($failRate >= 5) {
                        return [
                            'status' => 'WARN',
                            'msg' => "High Failure Rate detected: <strong>{$failRate}%</strong> of scripts are failing."
                        ];
                    }

                    return ['status' => 'OK', 'msg' => "Failure rate within limits ({$failRate}%)."];
                }
            ],

            // --- NUEVO CHEQUEO AVANZADO: UPGRADE LOOPS ---
            [
                'name' => 'Persistent Upgrade Failures',
                'desc' => 'Detects endpoints stuck in a failed upgrade loop (3+ attempts in 30 days) and currently failing.',
                'query' => 'config timeframe = 30d | dataset = agent_auditing | filter agent_auditing_type = ENUM.AGENT_AUDIT_INSTALLATION and agent_auditing_subtype = ENUM.AGENT_AUDIT_UPGRADE and agent_auditing_result = ENUM.AGENT_AUDIT_FAIL | comp count(endpoint_id) as endpoint_failure_Count by endpoint_id, endpoint_name | filter endpoint_failure_Count >=3 | alter excessive_upgrade_failures = if(endpoint_id in(dataset = endpoints | filter last_upgrade_status = "FAILED"| fields endpoint_id),true,false) | filter excessive_upgrade_failures = true | join type = left (dataset = endpoints | alter status_description = arraystring(arraydistinct(arraymap(json_extract_array(operational_status_description , "$."),  json_extract_scalar("@element", "$.reason"))), " , ") | alter detailed_description = arraystring(arraydistinct(arraymap(json_extract_array(operational_status_description , "$."),  json_extract_scalar("@element", "$.title"))), " , ") | fields endpoint_name, last_upgrade_failure_reason,endpoint_id ) as endpoint_fields endpoint_fields.endpoint_id = endpoint_id',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'No persistent upgrade loops detected.'];
                    }

                    $count = count($rows);

                    // TABLA DETALLE (se pintará en una fila extra debajo)
                    $html = '<div style="background:#fff; border:1px solid #ddd; border-top:none; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';

                    $html .= '<thead style="background:#37474f; color:#ffffff;">
                                <tr>
                                    <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                                    <th style="padding:8px 12px; text-align:center;">Failures (30d)</th>
                                    <th style="padding:8px 12px; text-align:left;">Last Failure Reason</th>
                                </tr>
                              </thead>';
                    $html .= '<tbody>';

                    foreach ($rows as $r) {
                        $name = htmlspecialchars($r['endpoint_name'] ?? 'Unknown');
                        $fails = htmlspecialchars((string)($r['endpoint_failure_Count'] ?? '0'));
                        $reason = htmlspecialchars($r['last_upgrade_failure_reason'] ?? 'N/A');

                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; color:#333; font-weight:bold;'>$name</td>
                                    <td style='padding:8px 12px; text-align:center; color:#c62828; font-weight:bold;'>$fails</td>
                                    <td style='padding:8px 12px; color:#555;'>$reason</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';

                    return [
                        'status' => 'WARN',
                        'msg' => "<strong>$count endpoints</strong> require attention (Upgrade Loop). See details below ↴",
                        'detail_html' => $html
                    ];
                }
            ],
           [
                'name' => 'Agent Upgrade Failures (Last 24h)',
                'desc' => 'Lists endpoints that failed to upgrade in the last day.',
                'query' => 'config timeframe = 1d case_sensitive = false | dataset = agent_auditing | filter agent_auditing_result = ENUM.AGENT_AUDIT_FAIL and agent_auditing_subtype = ENUM.AGENT_AUDIT_UPGRADE | alter fail_reason = to_string(regextract(description, "\serror:\s*(.+)")) | alter hostname = to_string(regextract(description, "\bon\s+(\S+)\s+with\s+error:")) | comp count() as total by hostname, fail_reason | sort desc total',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'No upgrade failures detected in the last 24h.'];
                    }

                    $count = count($rows);
                    
                    // Construcción de Tabla
                    $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
                    $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                                <th style="padding:8px 12px; text-align:left;">Hostname</th>
                                <th style="padding:8px 12px; text-align:left;">Error Reason</th>
                                <th style="padding:8px 12px; text-align:center;">Count</th>
                              </tr></thead><tbody>';

                    foreach ($rows as $r) {
                        $host = htmlspecialchars($r['hostname'] ?? 'Unknown Host');
                        $reason = htmlspecialchars($r['fail_reason'] ?? 'Unknown Error');
                        $total = htmlspecialchars($r['total'] ?? 0);
                        
                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; font-weight:bold; color:#333;'>$host</td>
                                    <td style='padding:8px 12px; color:#555;'>$reason</td>
                                    <td style='padding:8px 12px; text-align:center; font-weight:bold; color:#c62828;'>$total</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';
                    
                    return [
                        'status' => 'WARN', 
                        'msg' => "<strong>$count endpoints</strong> failed to upgrade recently.",
                        'detail_html' => $html
                    ];
                }
            ],
            [
    'name' => 'Stale Content Updates (Connected Endpoints)',
    'desc' => 'Finds connected endpoints whose content has not been updated in more than 7 days.',
    'query' => 'dataset = endpoints | filter endpoint_status = ENUM.CONNECTED | alter days_since_update = timestamp_diff(current_time(), last_content_update_time , "DAY") | filter days_since_update > 7 | fields endpoint_name as name, content_version as content, days_since_update as days | sort desc days',
    'validate' => function($rows) {
        if (empty($rows)) {
            return ['status' => 'OK', 'msg' => 'All connected endpoints have updated content within the last 7 days.'];
        }

        $count = count($rows);

        // Construcción de Tabla
        $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
        $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
        $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                    <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                    <th style="padding:8px 12px; text-align:left;">Content Version</th>
                    <th style="padding:8px 12px; text-align:center;">Days Since Update</th>
                  </tr></thead><tbody>';

        foreach ($rows as $r) {
            $name    = htmlspecialchars($r['name'] ?? 'Unknown');
            $content = htmlspecialchars((string)($r['content'] ?? 'N/A'));
            $days    = htmlspecialchars((string)($r['days'] ?? '?'));

            $html .= "<tr style='border-bottom:1px solid #eee;'>
                        <td style='padding:8px 12px; font-weight:bold; color:#333;'>$name</td>
                        <td style='padding:8px 12px; color:#555;'>$content</td>
                        <td style='padding:8px 12px; text-align:center; color:#c62828; font-weight:bold;'>$days</td>
                      </tr>";
        }

        $html .= '</tbody></table></div>';

        return [
            'status' => 'WARN',
            'msg' => "<strong>$count endpoints</strong> have stale content updates (> 7 days). See details below ↴",
            'detail_html' => $html
        ];
    }
],

            [
                'name' => 'EDR Disabled Endpoints',
                'desc' => 'Identifies active endpoints where EDR capability is disabled.',
                'query' => 'dataset = endpoints | filter endpoint_status in (ENUM.CONNECTED, ENUM.DISCONNECTED) | filter endpoint_type not contains "mobile" | filter is_edr_enabled = ENUM.NO | fields endpoint_name as name, assigned_prevention_policy as Policy',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'All active endpoints have EDR enabled.'];
                    }

                    $count = count($rows);

                    // Construcción de Tabla
                    $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
                    $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                                <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                                <th style="padding:8px 12px; text-align:left;">Assigned Policy</th>
                              </tr></thead><tbody>';

                    foreach ($rows as $r) {
                        $name = htmlspecialchars($r['name'] ?? 'Unknown');
                        $policy = htmlspecialchars($r['Policy'] ?? 'No Policy');
                        
                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; font-weight:bold; color:#333;'>$name</td>
                                    <td style='padding:8px 12px; color:#555;'>$policy</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';
                    
                    return [
                        'status' => 'WARN', 
                        'msg' => "<strong>$count endpoints</strong> have EDR disabled.",
                        'detail_html' => $html
                    ];
                }
            ],
            [
                'name' => 'Unprotected Endpoints',
                'desc' => 'Identifies endpoints that are connected but not fully PROTECTED.',
                'query' => 'config case_sensitive = false | dataset = endpoints | filter endpoint_status in (ENUM.CONNECTED, ENUM.DISCONNECTED) | filter operational_status != ENUM.PROTECTED | alter opstat_reason = arraymap (json_extract_array (to_json_string(operational_status_description),"$."), json_extract_scalar ("@element", "$.reason")) | arrayexpand opstat_reason | dedup endpoint_id, opstat_reason | fields endpoint_name, opstat_reason',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'All endpoints are reporting as PROTECTED.'];
                    }

                    $count = count($rows);

                    // Construcción de Tabla
                    $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
                    $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                                <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                                <th style="padding:8px 12px; text-align:left;">Operational Status Reason</th>
                              </tr></thead><tbody>';

                    foreach ($rows as $r) {
                        $name = htmlspecialchars($r['endpoint_name'] ?? 'Unknown');
                        $reason = htmlspecialchars($r['opstat_reason'] ?? 'Unknown Reason');
                        
                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; font-weight:bold; color:#333;'>$name</td>
                                    <td style='padding:8px 12px; color:#c62828;'>$reason</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';
                    
                    return [
                        'status' => 'WARN', 
                        'msg' => "<strong>$count endpoints</strong> with issues.",
                        'detail_html' => $html
                    ];
                }
            ],
            [
    'name' => 'Linux Kernel Module Issues',
    'desc' => 'Detects Linux endpoints with operational status reasons related to kernel/support and shows a single reason per endpoint.',
    'query' => 'dataset = endpoints | filter platform = ENUM.LINUX | filter operational_status_description contains "kernel" or operational_status_description contains "supported" | alter reasons = arraydistinct(arraymap(json_extract_array(operational_status_description, "$."), json_extract_scalar("@element", "$.reason"))) | alter reason = arrayindex(reasons, 0) | filter reason != null | fields endpoint_name as name, os_version as os, reason',
    'validate' => function($rows) {
        if (empty($rows)) {
            return ['status' => 'OK', 'msg' => 'No Linux kernel/support operational issues detected.'];
        }

        $count = count($rows);

        // Construcción de Tabla
        $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
        $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
        $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                    <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                    <th style="padding:8px 12px; text-align:left;">OS Version</th>
                    <th style="padding:8px 12px; text-align:left;">Reason</th>
                  </tr></thead><tbody>';

        foreach ($rows as $r) {
            $name = htmlspecialchars($r['name'] ?? 'Unknown');
            $os   = htmlspecialchars($r['os'] ?? 'Unknown');
            $reason = htmlspecialchars($r['reason'] ?? 'N/A');

            $html .= "<tr style='border-bottom:1px solid #eee;'>
                        <td style='padding:8px 12px; font-weight:bold; color:#333;'>$name</td>
                        <td style='padding:8px 12px; color:#555;'>$os</td>
                        <td style='padding:8px 12px; color:#c62828; font-weight:bold;'>$reason</td>
                      </tr>";
        }

        $html .= '</tbody></table></div>';

        return [
            'status' => 'WARN',
            'msg' => "<strong>$count Linux endpoints</strong> show kernel/support operational issues. See details below ↴",
            'detail_html' => $html
        ];
    }
],

            [
                'name' => 'Lost Connectivity Endpoints',
                'desc' => 'Lists endpoints with CONNECTION_LOST status and days since last seen.',
                'query' => 'dataset = endpoints | filter endpoint_status in (ENUM.CONNECTION_LOST) | alter daysNotSeen = timestamp_diff(current_time(), last_seen, "DAY") | fields endpoint_name as name, endpoint_type as type, daysNotSeen | sort desc daysNotSeen',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'No endpoints with lost connectivity.'];
                    }

                    $count = count($rows);

                    // Construcción de Tabla
                    $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
                    $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                                <th style="padding:8px 12px; text-align:left;">Endpoint Name</th>
                                <th style="padding:8px 12px; text-align:left;">Type</th>
                                <th style="padding:8px 12px; text-align:center;">Days Not Seen</th>
                              </tr></thead><tbody>';

                    foreach ($rows as $r) {
                        $name = htmlspecialchars($r['name'] ?? 'Unknown');
                        $type = htmlspecialchars($r['type'] ?? 'Unknown');
                        $days = htmlspecialchars($r['daysNotSeen'] ?? '0');
                        
                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; font-weight:bold; color:#333;'>$name</td>
                                    <td style='padding:8px 12px; color:#555;'>$type</td>
                                    <td style='padding:8px 12px; text-align:center; font-weight:bold; color:#c62828;'>$days</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';
                    
                    return [
                        'status' => 'WARN', 
                        'msg' => "<strong>$count endpoints</strong> have lost connectivity.",
                        'detail_html' => $html
                    ];
                }
            ],
            [
    'name' => 'Stale Log Sources (>= 7 days)',
    'desc' => 'Lists products/log types/collectors that have not reported in 7 days or more (last_seen).',
    'query' => 'config timeframe = 10d | dataset = metrics_source | comp max(_time) as last_seen by _PRODUCT, _log_type, _collector_id | alter days_since_last_seen = timestamp_diff(current_time(), last_seen, "DAY") | filter days_since_last_seen >= 7 | fields _PRODUCT as Product, _log_type as LogType, _collector_id as Collector, last_seen as LastSeen, days_since_last_seen as Days | sort desc Days | limit 50',
    'validate' => function($rows) {
        if (empty($rows)) {
            return ['status' => 'OK', 'msg' => 'All log sources have reported within the last 7 days.'];
        }

        $count = count($rows);

        // Construcción de Tabla
        $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
        $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
        $html .= '<thead style="background:#37474f; color:#ffffff;"><tr>
                    <th style="padding:8px 12px; text-align:left;">Product</th>
                    <th style="padding:8px 12px; text-align:left;">Log Type</th>
                    <th style="padding:8px 12px; text-align:left;">Collector</th>
                    <th style="padding:8px 12px; text-align:center;">Days Since</th>
                    <th style="padding:8px 12px; text-align:left;">Last Seen</th>
                  </tr></thead><tbody>';

        foreach ($rows as $r) {
            $product   = htmlspecialchars($r['Product'] ?? 'Unknown');
            $logType   = htmlspecialchars($r['LogType'] ?? 'Unknown');
            $collector = htmlspecialchars((string)($r['Collector'] ?? 'Unknown'));
            $days      = htmlspecialchars((string)($r['Days'] ?? '?'));
            $lastSeen  = htmlspecialchars((string)($r['LastSeen'] ?? 'N/A'));

            $html .= "<tr style='border-bottom:1px solid #eee;'>
                        <td style='padding:8px 12px; font-weight:bold; color:#333;'>$product</td>
                        <td style='padding:8px 12px; color:#555;'>$logType</td>
                        <td style='padding:8px 12px; color:#555;'>$collector</td>
                        <td style='padding:8px 12px; text-align:center; color:#c62828; font-weight:bold;'>$days</td>
                        <td style='padding:8px 12px; color:#555;'>$lastSeen</td>
                      </tr>";
        }
        $html .= '</tbody></table></div>';

        return [
            'status' => 'WARN',
            'msg' => "<strong>$count sources</strong> have not reported in 7+ days. See details below ↴",
            'detail_html' => $html
        ];
    }
],
[
                'name' => 'Misconfigured Data Sources (Unparsed)',
                'desc' => 'Identifies sources sending logs to Broker VM that are not matching any parser (landing in unknown_unknown_raw).',
                'query' => 'config timeframe = 1d | dataset = unknown_unknown_raw | comp count() as LogVolume by _reporting_device_ip | sort desc LogVolume',
                'validate' => function($rows) {
                    if (empty($rows)) {
                        return ['status' => 'OK', 'msg' => 'All incoming logs are being parsed correctly.'];
                    }

                    $count = count($rows);

                    // Construcción de Tabla
                    $html = '<div style="background:#fff; border:1px solid #ddd; margin-top:5px; box-shadow:0 2px 3px rgba(0,0,0,0.05);">';
                    $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.9em; font-family:sans-serif;">';
                    $html .= '<thead style="background:#795548; color:#ffffff;"><tr>
                                <th style="padding:8px 12px; text-align:left;">Source IP</th>
                                <th style="padding:8px 12px; text-align:center;">Unparsed # LOGs (24h)</th>
                              </tr></thead><tbody>';

                    foreach ($rows as $r) {
                        $ip = htmlspecialchars($r['_reporting_device_ip'] ?? 'Unknown');
                        $vol = htmlspecialchars((string)($r['LogVolume'] ?? '0'));
                        
                        $html .= "<tr style='border-bottom:1px solid #eee;'>
                                    <td style='padding:8px 12px; font-weight:bold; color:#333;'>$ip</td>
                                    <td style='padding:8px 12px; text-align:center; color:#c62828; font-weight:bold;'>$vol</td>
                                  </tr>";
                    }
                    $html .= '</tbody></table></div>';
                    
                    return [
                        'status' => 'WARN', 
                        'msg' => "<strong>$count sources</strong> are sending unparsed logs (Unknown).",
                        'detail_html' => $html
                    ];
                }
            ]
        ];

  foreach ($checkDefinitions as $check) {

            // =========================
            // A) CHECKS POR API DIRECTA (no XQL)
            // =========================
            if (isset($check['api_call'])) {
                $api = $check['api_call'];

                $resp = callXdrApi(
                    $baseUrl,
                    $headers,
                    $api['path'],
                    $api['method'] ?? 'POST',
                    $api['body'] ?? ["request_data" => new stdClass()]
                );

                if (isset($resp['error'])) {
                    $healthResults[] = [
                        'name' => $check['name'],
                        'desc' => $check['desc'],
                        'status' => 'ERROR',
                        'message' => 'API Error: ' . htmlspecialchars($resp['error']),
                        'detail_html' => null
                    ];
                    continue;
                }

                $eval = $check['validate']($resp);

                $healthResults[] = [
                    'name' => $check['name'],
                    'desc' => $check['desc'],
                    'status' => $eval['status'] ?? 'ERROR',
                    'message' => $eval['msg'] ?? 'Validation returned no message',
                    'detail_html' => $eval['detail_html'] ?? null
                ];
                continue;
            }

            // =========================
            // B) CHECKS POR XQL
            // =========================
            $result = runXqlQuery($baseUrl, $headers, $check['query']);

            // --- EXCEPCIÓN: DATASET UNKNOWN INEXISTENTE ---
            // Si la query falla y es la de "Misconfigured Data Sources",
            // asumimos que el dataset no existe porque no hay logs de ese tipo (es algo bueno).
            if (isset($result['error']) && strpos($check['name'], 'Misconfigured Data Sources') !== false) {
                // Forzamos resultado vacío para que el validador diga "OK - 0 logs"
                $result = []; 
            }
            // ----------------------------------------------

            if (isset($result['error'])) {
                $healthResults[] = [
                    'name' => $check['name'],
                    'desc' => $check['desc'],
                    'status' => 'ERROR',
                    'message' => 'API Error: ' . htmlspecialchars($result['error']),
                    'detail_html' => null
                ];
                continue;
            }

            if ($result !== null) {
                $eval = $check['validate']($result);

                $healthResults[] = [
                    'name' => $check['name'],
                    'desc' => $check['desc'],
                    'status' => $eval['status'] ?? 'ERROR',
                    'message' => $eval['msg'] ?? 'Validation returned no message',
                    'detail_html' => $eval['detail_html'] ?? null
                ];
            } else {
                $healthResults[] = [
                    'name' => $check['name'],
                    'desc' => $check['desc'],
                    'status' => 'ERROR',
                    'message' => 'Unknown Error (Null Response)',
                    'detail_html' => null
                ];
            }
        }

    }

    // --- B. EJECUTAR AUDITORÍA DE POLÍTICAS (SI SE SELECCIONÓ) ---
    // --- B. EJECUTAR AUDITORÍA DE POLÍTICAS (SI SE SELECCIONÓ) ---
    if ($runPolicyAudit) {
        $profilesDB = [];
        $policyMap = [];

        // ✅ HELPER: Función para extraer y cargar perfiles desde cualquier JSON
        $loadProfiles = function($json) use (&$profilesDB) {
            if (!$json) return;
            $pList = [];
            
            if (isset($json['data']['profiles']['profiles']) && is_array($json['data']['profiles']['profiles'])) {
                $pList = $json['data']['profiles']['profiles']; 
            } elseif (isset($json['data']['profiles']) && is_array($json['data']['profiles'])) {
                $pList = $json['data']['profiles']; 
            } elseif (isset($json['profiles']['profiles']) && is_array($json['profiles']['profiles'])) {
                 $pList = $json['profiles']['profiles'];
            } elseif (isset($json['profiles']) && is_array($json['profiles'])) {
                 $pList = $json['profiles'];
            } elseif (isset($json['data']) && is_array($json['data']) && !isset($json['data']['rules'])) {
                 $pList = $json['data'];
            }
            
            foreach ($pList as $p) {
                $pName = $p['name'] ?? $p['profile_name'] ?? null;
                $pType = $p['type'] ?? $p['profile_type'] ?? 'UNKNOWN';
                $pOs   = $p['os'] ?? $p['platform'] ?? 'UNKNOWN';
                
                if ($pName) {
                    $key = $pName . '|' . $pType . '|' . $pOs;
                    $profilesDB[$key] = $p;
                }
            }
        };

        // 1. Cargar Políticas (Contiene tanto las reglas como los perfiles)
        if (isset($_FILES['policy_file']) && $_FILES['policy_file']['error'] === UPLOAD_ERR_OK) {
            try {
                $jsonPolicies = processExportFile($_FILES['policy_file']['tmp_name']);
                if ($jsonPolicies) {
                    // A) Extraer las políticas
                    $rules = $jsonPolicies['data']['rules'] ?? $jsonPolicies['policies'] ?? [];
                    foreach ($rules as $item) {
                        $pName = $item['NAME'] ?? $item['name'] ?? null;
                        if ($pName) {
                            $policyMap[$pName] = [
                                'platform' => $item['PLATFORM'] ?? $item['platform'] ?? 'Unknown',
                                'malware'  => $item['MALWARE'] ?? $item['malware_profile_name'] ?? null,
                                'exploit'  => $item['EXPLOIT'] ?? $item['exploit_profile_name'] ?? null,
                                'agent'    => $item['AGENT_SETTINGS'] ?? $item['agent_settings_profile_name'] ?? null,
                            ];
                        }
                    }
                    // B) Extraer los perfiles incrustados
                    $loadProfiles($jsonPolicies);
                }
            } catch (Exception $e) { $errorMsg = "Policies Error: " . $e->getMessage(); }
        }

        // 2. Consultar Endpoints y Cruzar
        if (empty($errorMsg) && !empty($policyMap)) {
            $xql = "dataset = endpoints | fields assigned_prevention_policy, platform | comp count() as TOTAL by assigned_prevention_policy, platform";
            $rows = runXqlQuery($baseUrl, $headers, $xql);

            if (isset($rows['error'])) {
                $errorMsg = "Failed to query endpoints: " . $rows['error'];
            } elseif ($rows !== null) {
                
                $getProfileData = function($profName, $profType, $profOs) use ($profilesDB) {
                    if (!$profName || $profName === '-' || $profName === 'N/A' || $profName === 'Default') return null;
                    
                    $exactKey = $profName . '|' . $profType . '|' . $profOs;
                    if (isset($profilesDB[$exactKey])) return $profilesDB[$exactKey];
                    
                    foreach ($profilesDB as $key => $pData) {
                        $parts = explode('|', $key);
                        if (count($parts) >= 2 && $parts[0] === $profName && $parts[1] === $profType) return $pData;
                    }
                    
                    foreach ($profilesDB as $key => $pData) {
                        $parts = explode('|', $key);
                        if ($parts[0] === $profName) return $pData;
                    }
                    return null;
                };

                // Array para ir guardando los nombres de políticas en uso
                $usedPolicyNames = [];

                foreach ($rows as $row) {
                    $polName = $row['assigned_prevention_policy'] ?? '';
                    $platName = $row['platform'] ?? 'Unknown';
                    $count = $row['TOTAL'] ?? 0;

                    if (empty($polName)) $polName = "[No Policy]";
                    $mapped = $policyMap[$polName] ?? null;

                    if ($polName !== "[No Policy]") {
                        $usedPolicyNames[] = $polName; // Lo guardamos para la resta luego
                    }

                    $policyOs = $mapped['platform'] ?? 'Unknown';

                    $resultData[] = [
                        'policy' => $polName,
                        'platform' => $platName,
                        'count' => $count,
                        'profiles' => [
                            'Malware' => [
                                'name' => $mapped['malware'] ?? '-', 
                                'data' => $getProfileData($mapped['malware'] ?? '', 'MALWARE', $policyOs)
                            ],
                            'Exploit' => [
                                'name' => $mapped['exploit'] ?? '-', 
                                'data' => $getProfileData($mapped['exploit'] ?? '', 'EXPLOIT', $policyOs)
                            ],
                            'Agent Settings' => [
                                'name' => $mapped['agent'] ?? '-', 
                                'data' => $getProfileData($mapped['agent'] ?? '', 'AGENT_SETTINGS', $policyOs)
                            ]
                        ]
                    ];
                }
                usort($resultData, function($a, $b) {
                    $platA = strtolower($a['platform'] ?? '');
                    $platB = strtolower($b['platform'] ?? '');
                    $cmp = strcmp($platA, $platB);
                    if ($cmp !== 0) return $cmp;
                    return ((int)($b['count']??0)) <=> ((int)($a['count']??0));
                });

                // ✅ NUEVO: CALCULAR POLÍTICAS HUÉRFANAS (SIN USO)
                $allPolicyNames = array_keys($policyMap);
                $orphanedNames = array_diff($allPolicyNames, $usedPolicyNames);
                
                $unusedPolicies = [];
                // Nombres exactos de políticas del sistema que no se pueden borrar
                $ignoreList = ['Windows Default', 'Linux Default', 'macOS Default']; 

                foreach ($orphanedNames as $oName) {
                    // Si el nombre de la política está en la lista de ignoradas, pasamos a la siguiente
                    if (in_array($oName, $ignoreList)) {
                        continue;
                    }

                    $unusedPolicies[] = [
                        'name' => $oName,
                        'platform' => $policyMap[$oName]['platform'] ?? 'Unknown'
                    ];
                }
                // Ordenar huérfanas por plataforma y nombre
                usort($unusedPolicies, function($a, $b) {
                    $cmp = strcmp($a['platform'], $b['platform']);
                    if ($cmp === 0) return strcmp($a['name'], $b['name']);
                    return $cmp;
                });

            } else {
                $errorMsg = "Failed to query endpoints via XQL (Null response).";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cortex Health & Audit</title>
    <script>
    function togglePolicyFields() {
        const modePolicy = document.getElementById("modePolicy");
        const fields = document.getElementById("policyFilesArea");

        if (modePolicy && modePolicy.checked) {
            fields.style.display = "block";
            document.getElementById("p_file").required = true;
        } else {
            fields.style.display = "none";
            document.getElementById("p_file").required = false;
        }
    }

    function validateForm(e) {
        const modeHealth = document.getElementById('modeHealth').checked;
        const modePolicy = document.getElementById('modePolicy').checked;

        if (!modeHealth && !modePolicy) {
            e.preventDefault();
            alert("Please select one action (Health Checks OR Policy Audit).");
        }
    }
    // ... tus funciones existentes (togglePolicyFields, validateForm) ...

  function downloadReport() {
        const content = document.querySelector('.main-content').innerHTML;
        const date = new Date();
        const dateStr = `${date.getFullYear()}-${(date.getMonth()+1).toString().padStart(2,'0')}-${date.getDate()}`;
        
        // --- NUEVO: Obtener nombre del cliente desde PHP incrustado o del DOM ---
        // Buscamos el elemento donde pintamos el nombre para usarlo en el fichero
        let clientName = "Customer";
        const clientEl = document.getElementById('client-name-display');
        if (clientEl && clientEl.innerText.trim() !== "") {
            clientName = clientEl.innerText.trim().replace(/[^a-zA-Z0-9]/g, '_'); // Limpiar caracteres raros
        }

        // Construir nombre: Cortex_Audit_NombreCliente_Fecha.html
        const filename = `Cortex_Audit_${clientName}_${dateStr}.html`;

        const styles = document.querySelector('style').innerHTML;
        
        const htmlContent = `
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>Cortex Audit Report - ${clientName}</title>
                <style>
                    ${styles}
                    body { background: #fff; display: block; margin: 20px; font-family: 'Segoe UI', sans-serif; }
                    .main-content { padding: 0; box-shadow: none; }
                    .export-btn-container { display: none; }
                    /* Asegurar que el header del reporte se vea bien impreso */
                    .report-header { border-bottom: 2px solid #005fdb; padding-bottom: 15px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
                </style>
            </head>
            <body>
                <div class="main-content">
                    ${content}
                </div>
            </body>
            </html>
        `;

        const blob = new Blob([htmlContent], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
    </script>

    <style>
        :root { --cortex-blue: #005fdb; --cortex-dark: #2c3e50; --cortex-green: #00bf63; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; margin: 0; display: flex; height: 100vh; color: #333; }

        
        /* Estilo para la cabecera del reporte (Título + Cliente) */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .report-title {
            font-size: 1.5rem;
            color: var(--cortex-blue);
            font-weight: 800;
            margin: 0;
        }
        .client-badge {
            background: #f5f5f5;
            padding: 8px 15px;
            border-radius: 6px;
            border-left: 4px solid var(--cortex-green);
            font-weight: bold;
            color: #444;
            font-size: 1.1rem;
        }
        .client-label {
            font-size: 0.7em;
            text-transform: uppercase;
            color: #888;
            display: block;
            margin-bottom: 2px;
        }
 
        .version-date {
            font-weight: bold;
            color: #546e7a;
            display: block;
            margin-bottom: 4px;
        }
        .compat-badge {
            background: #e3f2fd;
            color: #0277bd;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.7rem;
            display: inline-block;
            margin-top: 5px;
            border: 1px solid #b3e5fc;
        }

        /* SIDEBAR */
        /* SIDEBAR AJUSTADO A 260px */
.sidebar { 
    width: 260px; /* Antes 340px */
    background: white; 
    border-right: 1px solid #ddd; 
    padding: 20px 15px; 
    display: flex; 
    flex-direction: column; 
    overflow-y: auto; 
    box-shadow: 2px 0 5px rgba(0,0,0,0.05); 
    flex-shrink: 0;
}

/* LOGO MÁS COMPACTO */
.logo-text {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 20px; /* Antes 24px */
    font-weight: 800;
    color: #2D2D2D;
    letter-spacing: -0.5px;
    line-height: 1;
    user-select: none;
}

/* BOTONES ESTILIZADOS */
.btn { 
    width: 100%; 
    padding: 10px; /* Antes 12px */
    border: none; 
    border-radius: 4px; 
    font-weight: bold; 
    cursor: pointer; 
    margin-bottom: 8px; 
    font-size: 0.85rem; /* Antes 0.9rem */
    text-decoration: none; 
    display: block; 
    text-align: center; 
    box-sizing: border-box; 
}

/* AJUSTE PARA EL FOOTER DEL SIDEBAR */
.version-footer {
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px solid #eee;
    text-align: center;
    font-size: 0.7rem; /* Un poco más pequeña para el nuevo ancho */
    color: #90a4ae;
}
        .logo-container { text-align: center; margin-bottom: 30px; margin-top: 0; }
        .logo-container img { max-width: 180px; }

        .form-group { margin-bottom: 15px; }
    
        .form-group label { display: block; font-size: 0.75rem; font-weight: 800; color: #444; margin-bottom: 5px; text-transform: uppercase; }
        .form-group input[type="text"], .form-group input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 0.9rem; }
        .form-group input[type="file"] { width: 100%; padding: 8px; font-size: 0.8rem; background: #fafafa; border: 1px dashed #ccc; border-radius: 4px; }

        .checkbox-container { background: #e8f5e9; padding: 10px; border-radius: 5px; border: 1px solid #c8e6c9; margin-bottom: 10px; display: flex; align-items: center; }
        .checkbox-container label { font-size: 0.9rem; font-weight: 600; color: #2e7d32; cursor: pointer; margin-left: 10px; }

        .btn-deploy { background: var(--cortex-green); color: white; transition: 0.3s; }
        .btn-deploy:hover { opacity: 0.9; }
        .btn-back { background: #607d8b; color: white; margin-top: auto; }
        .btn-back:hover { background: #546e7a; }

        /* MAIN CONTENT */
.main-content { 
    flex: 1; 
    padding: 40px; /* Aumentamos un poco el padding interno para que respire el reporte */
    overflow-y: auto; 
    background: #f8f9fa; /* Un gris muy tenue para resaltar las tarjetas blancas */
}        h1 { color: var(--cortex-blue); margin-top:0; border-bottom: 3px solid var(--cortex-green); padding-bottom: 10px; display:inline-block; margin-bottom: 20px;}
        h2 { font-size: 1.4rem; color: var(--cortex-dark); border-bottom: 2px solid #ccc; padding-bottom: 5px; margin-top: 40px; margin-bottom: 20px; }

        .health-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .health-table th { background: #455a64; color: white; padding: 12px; text-align: left; font-size: 0.9rem; text-transform: uppercase;}
        .health-table td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: top; }
        .badge-status { padding: 5px 12px; border-radius: 12px; font-weight: bold; color: white; font-size: 0.8rem; text-transform: uppercase; }
        .bg-ok { background: var(--cortex-green); }
        .bg-warn { background: #ff9800; }
        .bg-fail { background: #e53935; }
        .bg-neutral { background: #999; }
        .bg-error { background: #333; }

        /* POLICY TABLE */
        .main-table { width: 100%; border-collapse: collapse; margin-top: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .main-table th { background: var(--cortex-dark); color: white; padding: 15px; text-align: left; text-transform: uppercase; font-size: 0.85rem;}
        .main-table td { background: white; padding: 15px; border-bottom: 1px solid #eee; vertical-align: top; }
        .main-table tr:hover td { background: #f9fbfd; }

        .badge-plat { padding: 4px 8px; border-radius: 4px; font-size: 0.75em; color: white; font-weight: bold; text-transform: uppercase; }
        .plat-win { background: #0078d7; } .plat-linux { background: #e95420; } .plat-mac { background: #555; } .plat-unk { background: #999; }
        .badge-count { background: var(--cortex-green); color: white; padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 1.1em; }

        details { margin-bottom: 8px; border: 1px solid #eee; border-radius: 4px; overflow: hidden; }
        summary { background: #f8f9fa; padding: 8px 12px; cursor: pointer; font-size: 0.9em; font-weight: 600; color: #444; outline: none; }
        .details-content { padding: 10px; background: #fff; border-top: 1px solid #eee; overflow-x: auto; max-height: 520px; overflow-y: auto; }

        .config-table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
        .config-table td { padding: 6px 8px; border: 1px solid #eee; text-align: left; vertical-align: top; }
        .config-table .cfg-key { background: #f4f6f8; font-weight: bold; color: #555; width: 45%; }
        .config-table .cfg-val { color: #333; font-family: monospace; }
        .section-row { background: #eef2f6 !important; font-weight: 800; color: var(--cortex-dark); text-transform: uppercase; }

        .prof-type-lbl { font-size: 0.7em; text-transform: uppercase; color: var(--cortex-blue); letter-spacing: 1px; margin-bottom: 2px; display: block; font-weight: bold; margin-top: 10px;}
        .prof-type-lbl:first-child { margin-top: 0; }

        .text-muted { color: #999; }
        .status-green { color: #0a7a2f; font-weight: 900; }
        .status-red { color: #c1121f; font-weight: 900; }
        .status-orange { color: #c46a00; font-weight: 900; }
        .status-muted { color: #444; font-weight: 800; }
    
        .export-btn {
            background: #607d8b; 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 4px; 
            cursor: pointer; 
            font-weight: bold;
            text-decoration: none;
            float: right; /* Lo alineamos a la derecha */
        }
        .export-btn:hover { background: #455a64; }
        
        /* Limpieza para cuando hay floats */
        h1 { overflow: hidden; }
        .logo-container {
    display: flex;
    align-items: center;
    flex-shrink: 0;
}



.logo-text span {
    color: #FA582D;         /* Naranja intenso (estilo Cortex/PaloAlto) */
    /* Si prefieres azul, usa: #00C0F3 */
    font-weight: 400;       /* Más fino para diferenciar las dos palabras */
}
    </style>
</head>
<body>

<form method="POST" enctype="multipart/form-data" class="sidebar">
    <div class="logo-container">
        <div class="logo-text">Cortex<span>Audit</span></div>
    </div>
<div class="form-group">
        <label>0. Customer Name</label>
        <input type="text" name="customer_name" placeholder="e.g. Acme Corp" value="<?php echo htmlspecialchars($customerName ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label>1. API Base URL (FQDN)</label>
        <input type="text" name="base_url" placeholder="https://api-xxxx.xdr.us.paloaltonetworks.com" value="<?php echo htmlspecialchars($_POST['base_url'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label>2. API Key ID</label>
        <input type="text" name="api_key_id" value="<?php echo htmlspecialchars($_POST['api_key_id'] ?? ''); ?>" required>
    </div>
    <div class="form-group">
        <label>3. API Key</label>
        <input type="password" name="api_key" value="<?php echo htmlspecialchars($_POST['api_key'] ?? ''); ?>" required>
    </div>

    <div class="checkbox-container">
        <input type="radio" id="modeHealth" name="run_mode" value="health"
            onclick="togglePolicyFields()" <?php echo (!isset($_POST['run_mode']) || $_POST['run_mode']==='health') ? 'checked' : ''; ?>>
        <label for="modeHealth">Run System Health Checks</label>
    </div>

    <div class="checkbox-container">
        <input type="radio" id="modePolicy" name="run_mode" value="policy"
            onclick="togglePolicyFields()" <?php echo (isset($_POST['run_mode']) && $_POST['run_mode']==='policy') ? 'checked' : ''; ?>>
        <label for="modePolicy">Run Policy & Profile Audit</label>
    </div>


    <div id="policyFilesArea" style="display: <?php echo $runPolicyAudit ? 'block' : 'none'; ?>;">
        <div class="form-group">
            <label>4. File: Policy Rules (.export)</label>
            <input type="file" name="policy_file" id="p_file">
        </div>
      
    </div>

    <button type="submit" class="btn btn-deploy" onclick="validateForm(event)">RUN AUDIT</button>
    <a href="../index.php" class="btn btn-back">⬅️ Back to PANTools</a>
    <div class="version-footer">
        <span class="version-date">Last Update: <?php echo $toolVersion; ?></span>
        <div class="compat-badge"><?php echo $compatibilityMsg; ?></div>
    </div>
</form>

<div class="main-content">
    <?php if (!empty($healthResults) || !empty($resultData)): ?>
        <div class="report-header">
            <div>
                <div class="report-title">Security Configuration Audit</div>
                <div style="color:#777; font-size:0.9em; margin-top:5px;">Generated on: <?php echo date("Y-m-d H:i"); ?></div>
            </div>
            <div class="client-badge">
                <span class="client-label">Customer</span>
                <span id="client-name-display"><?php echo htmlspecialchars($customerName); ?></span>
            </div>
        </div>
        <div class="export-btn-container" style="margin-bottom: 10px; text-align: right;">
            <button onclick="downloadReport()" class="export-btn">📥 Download Report</button>
        </div>
    <?php endif; ?>

        
    <?php if (!empty($healthResults)): ?>
        <h1>System Health Status</h1>
        <table class="health-table">
            <thead>
                <tr>
                    <th width="25%">Check Name</th>
                    <th width="35%">Description</th>
                    <th width="10%">Status</th>
                    <th width="30%">Result / Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($healthResults as $h):
                    $badge = 'bg-warn';
                    if ($h['status'] === 'OK') $badge = 'bg-ok';
                    if ($h['status'] === 'FAIL') $badge = 'bg-fail';
                    if ($h['status'] === 'NEUTRAL') $badge = 'bg-neutral';
                    if ($h['status'] === 'ERROR') $badge = 'bg-error';
                ?>
                <tr>
                    <td style="font-weight:bold; color: #2c3e50;"><?php echo htmlspecialchars($h['name']); ?></td>
                    <td style="color:#555; font-size:0.9em;"><?php echo htmlspecialchars($h['desc']); ?></td>
                    <td><span class="badge-status <?php echo $badge; ?>"><?php echo htmlspecialchars($h['status']); ?></span></td>
                    <td style="font-family: monospace; color:#333;">
                        <?php echo $h['message']; // ⚠️ sin htmlspecialchars para permitir HTML controlado ?>
                    </td>
                </tr>

                <?php if (!empty($h['detail_html'])): ?>
                <!-- ✅ FILA EXTRA DE DETALLE: ocupa columnas 2, 3 y 4 -->
                <tr>
                    <td style="background:#fafafa;"></td>
                    <td colspan="3" style="padding:0; background:#fafafa;">
                        <?php echo $h['detail_html']; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
<div class="empty-state" style="text-align:center; padding:40px; color:#666;">
            <h3>Cortex Health & Audit</h3>
            <p>Please enter your credentials to run automated Health Checks.</p>
            
            <div style="background:#e3f2fd; color:#0d47a1; padding:12px; border-radius:6px; border:1px solid #90caf9; display:inline-block; margin:15px 0; font-size:0.95em; max-width:400px;">
                ℹ️ <strong>Requirement:</strong><br>
                API Key must be of type <strong>Standard</strong> and have the <strong>Viewer</strong> (or higher) Role.
            </div>

        </div>
    <?php endif; ?>

    <?php if ($runPolicyAudit): ?>
        <h2>Policy & Profile Configuration</h2>
        <?php if ($errorMsg): ?><div class="error"><strong>Error:</strong> <?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

        <?php if (!empty($resultData)): ?>
            <table class="main-table">
                <thead>
                    <tr>
                        <th width="20%">Policy & Platform</th>
                        <th width="70%">Profile Configuration</th>
                        <th width="10%" style="text-align: center;">Endpoints</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultData as $row): 
                        $plat = strtolower($row['platform'] ?? 'unknown');
                        $badgeClass = 'plat-unk';
                        if (strpos($plat, 'win') !== false) $badgeClass = 'plat-win';
                        elseif (strpos($plat, 'linux') !== false) $badgeClass = 'plat-linux';
                        elseif (strpos($plat, 'mac') !== false) $badgeClass = 'plat-mac';
                    ?>
                    <tr>
                        <td>
                            <div style="font-size: 1.1em; font-weight: bold; margin-bottom: 5px; color: var(--cortex-blue);">
                                <?php echo htmlspecialchars($row['policy']); ?>
                            </div>
                            <span class="badge-plat <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['platform']); ?></span>
                        </td>
                        <td>
                            <?php foreach ($row['profiles'] as $type => $info): ?>
                                <?php 
                                    $pName = $info['name'] ?? '-';
                                    if ($pName !== '-'): 
                                        // --- LÓGICA DE COINCIDENCIA EXACTA ---
                                        // Definimos la lista de nombres EXACTOS que queremos tratar como Defaults del sistema.
                                        // Cualquier cosa que no esté aquí (como "Default (XDR Pro)") se tratará como custom.
                                        $systemDefaults = [
                                            'Default', 
                                            'iOS Default', 
                                            'Android Default',
                                            'Linux Default' // Por si acaso
                                        ];
                                        
                                        // in_array hace comparación exacta (string completo)
                                        $isDefault = in_array($pName, $systemDefaults);
                                        
                                        $hasData = !empty($info['data']);
                                ?>
                                    <span class="prof-type-lbl"><?php echo htmlspecialchars($type); ?></span>
                                    <details>
                                        <summary>
                                            <?php echo htmlspecialchars($pName); ?>
                                            
                                            <?php 
                                            // Lógica visual para el resumen (Summary)
                                            if ($isDefault) {
                                                if ($type === 'Exploit') echo ' <span style="color:#2e7d32; font-size:0.8em;">(✅ Best Practice)</span>';
                                                else echo ' <span style="color:#c62828; font-size:0.8em;">(⚠️ Weak Config)</span>';
                                            } elseif (!$hasData) {
                                                echo ' <span style="color:red; font-size:0.8em;">(Config not found)</span>';
                                            }
                                            ?>
                                        </summary>
                                        
                                        <div class="details-content">
                                            <?php 
                                            // LÓGICA PRINCIPAL DE MENSAJES
                                            if ($isDefault) {
                                                // 1. Caso EXPLOIT Default -> OK
                                                if ($type === 'Exploit') {
                                                    echo '<div style="padding:10px; border:1px solid #a5d6a7; background:#e8f5e9; color:#1b5e20; border-radius:4px;">
                                                            <strong>✅ Configuration is correct.</strong><br>
                                                            The Default Exploit profile meets Palo Alto Networks best practices for this OS.
                                                          </div>';
                                                }
                                                // 2. Caso AGENT SETTINGS Default -> MAL (EDR OFF)
                                                elseif ($type === 'Agent Settings') {
                                                    echo '<div style="padding:10px; border:1px solid #ef9a9a; background:#ffebee; color:#b71c1c; border-radius:4px;">
                                                            <strong>❌ Non-Compliant / Security Risk</strong><br>
                                                            The Default Agent Settings profile typically has <strong>EDR Disabled</strong> and other features turned off. 
                                                            <br><em>Recommendation: Create a custom profile enabling EDR and assign it.</em>
                                                          </div>';
                                                }
                                                // 3. Caso MALWARE Default -> MAL (Protección básica)
                                                elseif ($type === 'Malware') {
                                                    echo '<div style="padding:10px; border:1px solid #ef9a9a; background:#ffebee; color:#b71c1c; border-radius:4px;">
                                                            <strong>❌ Non-Compliant / Weak Protection</strong><br>
                                                            The Default Malware profile often has protection modules disabled or set to "Report Only".
                                                            <br><em>Recommendation: Create a custom profile with modules set to BLOCK.</em>
                                                          </div>';
                                                }
                                                // Otros Defaults
                                                else {
                                                    echo '<div style="padding:5px; color:#555;">System Default Profile.</div>';
                                                }

                                            } elseif (!$hasData) {
                                                // No es default y no hay datos (error original)
                                                echo '<div style="color:red; padding:10px;">❌ Configuration data not found in the export file.</div>';
                                            } else {
                                                // TIENE DATOS Y NO ES DEFAULT -> PINTAR TABLA
                                                $platNorm = normPlatform($row['platform'] ?? 'other');

                                                if ($type === 'Agent Settings') echo formatAgentSettingsFiltered($info['data'], $platNorm);
                                                elseif ($type === 'Exploit') echo formatExploitFiltered($info['data'], $platNorm);
                                                elseif ($type === 'Malware') echo formatMalwareFiltered($info['data'], $platNorm);
                                                else echo formatConfigToHtml($info['data']);
                                            }
                                            ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                        <td style="text-align: center;"><span class="badge-count"><?php echo htmlspecialchars((string)$row['count']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($unusedPolicies)): ?>
            <div style="margin-top: 40px; background: #fffcf0; border-left: 5px solid #ffc107; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                <h3 style="color: #b28900; margin-top: 0; font-size: 1.2rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-broom"></i> Clean-up Recommendation (Unused Policies)
                </h3>
                <p style="color: #555; font-size: 0.95rem; margin-bottom: 20px; line-height: 1.5;">
                    The following policies exist in the tenant configuration but are <strong>not assigned to any active or disconnected endpoint</strong>. To maintain a clean and secure environment, consider reviewing and deleting these policies if they are no longer needed.
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <?php foreach ($unusedPolicies as $up): 
                        $plat = strtolower($up['platform']);
                        $badgeClass = 'plat-unk';
                        if (strpos($plat, 'win') !== false) $badgeClass = 'plat-win';
                        elseif (strpos($plat, 'linux') !== false) $badgeClass = 'plat-linux';
                        elseif (strpos($plat, 'mac') !== false) $badgeClass = 'plat-mac';
                    ?>
                        <div style="background: white; border: 1px solid #ffe69c; padding: 8px 15px; border-radius: 6px; font-size: 0.9rem; color: #333; display: flex; align-items: center; gap: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                            <span class="badge-plat <?php echo $badgeClass; ?>" style="font-size:0.7rem;"><?php echo htmlspecialchars($up['platform']); ?></span>
                            <strong><?php echo htmlspecialchars($up['name']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
</div>

</body>
</html>

