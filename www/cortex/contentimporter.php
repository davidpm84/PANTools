<?php
session_start();

// --- CONFIGURATION ---
$localRepoPath = '/var/www/html/cortex/content-repo';
exec("rm -rf /tmp/xsiam_builder"); 

// 1. LEER EL TOKEN DEL ARCHIVO DE CONFIGURACIÓN
$configFile = '/var/www/html/.config.json';
$githubToken = '';
if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    $githubToken = $config['token'] ?? '';
}

// 2. CONSTRUIR URLs (¡Importante para la seguridad!)
$repoOwner = "davidpm84";
$repoName = "cortexcustomintegrations"; // El repositorio donde tienes tu contenido

if (!empty($githubToken)) {
    // A) URL PRIVADA: Usada internamente por exec() para hacer git clone/pull. Nunca se muestra.
    $repoUrl = "https://{$githubToken}@github.com/{$repoOwner}/{$repoName}.git";
} else {
    // Fallback por si entran sin token
    $repoUrl = "https://github.com/{$repoOwner}/{$repoName}.git";
}

// B) URL PÚBLICA: Usada en el HTML para los links. ¡SIN TOKEN para que no se filtre en el navegador!
$repoBaseUrl = "https://github.com/{$repoOwner}/{$repoName}";
$branch = "main"; // Cambia a "master" si tu repo usa la rama antigua

$message = ""; $outputLog = ""; $messageType = "";

// --- SMART REPO DETECTION ---
$repoExists = is_dir($localRepoPath . '/.git');
$updateAvailable = false;

if ($repoExists) {
    // Check if remote has new changes without pulling them yet
    exec("cd " . escapeshellarg($localRepoPath) . " && git fetch origin && git status -uno", $statusOutput);
    foreach ($statusOutput as $line) {
        if (strpos($line, 'Your branch is behind') !== false) {
            $updateAvailable = true;
            break;
        }
    }
}

if (!$repoExists && !isset($_POST['action'])) {
    $message = "⚠️ No local content detected. Please click 'SYNC REPOSITORY'.";
    $messageType = "error";
} elseif ($updateAvailable) {
    $message = "✨ New content detected on GitHub! Please sync to update your local files.";
    $messageType = "success";
}

// --- HELPER: SEVERITY MAPPING ---
function mapSeverity($guiValue) {
    $map = [
        'Informational' => 'SEV_010_INFO',
        'Low'           => 'SEV_020_LOW',
        'Medium'        => 'SEV_030_MEDIUM',
        'High'          => 'SEV_040_HIGH',
        'Critical'      => 'SEV_050_CRITICAL',
    ];
    return $map[$guiValue] ?? 'SEV_010_INFO';
}

// --- HELPER: API XSIAM CORRELATIONS (LÓGICA COMPLETA) ---
// --- HELPER: API XSIAM CORRELATIONS (OPTIMIZADA CON TIMEOUTS) ---
function uploadCorrelationRulesViaApi($filePath, $baseUrl, $apiKey, $authId) {
    global $outputLog;
    
    // Aumentar el tiempo de ejecución de PHP para que no corte el script si hay muchas reglas
    set_time_limit(300); 

    $baseUrl = rtrim($baseUrl, '/');
    $baseUrl = str_replace('/xsoar', '', $baseUrl); 
    $endpoint = "$baseUrl/public_api/v1/correlations/insert";

    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);
    if (!$data) {
        $outputLog .= "      ❌ JSON Error: El fichero está corrupto o vacío.\n";
        return ["success" => false];
    }

    $rulesList = isset($data['request_data']) ? $data['request_data'] : (isset($data['rules']) ? $data['rules'] : (!isset($data[0]) ? [$data] : $data));
    $globalSuccess = true;

    // Inicializar cURL una sola vez (Reutilización de conexión = Más velocidad)
    $ch = curl_init();
    
    foreach ($rulesList as $index => $rule) {
        $ruleName = $rule['name'] ?? "Rule #$index";
        
        // Limpieza de campos que XSIAM rechaza si van en el insert
        if (isset($rule['rule_id'])) unset($rule['rule_id']);
        if (isset($rule['id'])) unset($rule['id']); 

        // Normalización de Severidad
        if (isset($rule['user_defined_severity']) && !empty($rule['user_defined_severity'])) {
             $rule['severity'] = "User Defined";
        } else {
             if (isset($rule['severity']) && $rule['severity'] !== 'User Defined') $rule['severity'] = mapSeverity($rule['severity']);
        }

        // Normalización de Categoría
        if (isset($rule['user_defined_category']) && !empty($rule['user_defined_category'])) {
             $rule['alert_category'] = "User Defined";
        } else {
             if (isset($rule['alert_category']) && $rule['alert_category'] === 'User Defined') $rule['alert_category'] = "DISCOVERY"; 
        }

        if (!isset($rule['description'])) $rule['description'] = "";
        if (isset($rule['mitre_defs']) && empty($rule['mitre_defs'])) $rule['mitre_defs'] = new stdClass();
        if (isset($rule['alert_fields']) && empty($rule['alert_fields'])) $rule['alert_fields'] = new stdClass();

        $payload = json_encode(["request_data" => [$rule]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Configuración de cURL
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "x-xdr-auth-id: $authId", "Authorization: $apiKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        
        // --- TIMEOUTS CRÍTICOS PARA EVITAR QUE SE CUELGUE ---
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Si no conecta en 5s, error.
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);       // Si la API tarda más de 20s en responder, error.

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch); // Capturar error de red si lo hay

        if ($curlErr) {
            $outputLog .= "      ❌ '$ruleName': Error de Red/Timeout - $curlErr\n";
            $globalSuccess = false;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $respData = json_decode($response, true);
            $displayId = "OK";
            if (isset($respData['added_objects'][0])) {
                $obj = $respData['added_objects'][0];
                $displayId = is_array($obj) ? ($obj['id'] ?? $obj['rule_id'] ?? json_encode($obj)) : $obj;
            } elseif (isset($respData['reply'])) {
                $displayId = is_array($respData['reply']) ? json_encode($respData['reply']) : $respData['reply'];
            }
            $outputLog .= "      ✅ '$ruleName': Created (ID: $displayId)\n";
        } else {
            $errMsg = strip_tags($response);
            $jsonErr = json_decode($response, true);
            if (isset($jsonErr['reply']['err_msg'])) $errMsg = $jsonErr['reply']['err_msg'];
            if (isset($jsonErr['errors'][0]['status'])) $errMsg .= " -> " . $jsonErr['errors'][0]['status'];

            $outputLog .= "      ❌ '$ruleName': Error ($httpCode) - $errMsg\n";
            $globalSuccess = false;
        }
    }
    curl_close($ch); // Cerramos conexión al final del bucle
    return ["success" => $globalSuccess];
}

function uploadDashboardViaApi($filePath, $baseUrl, $apiKey, $authId) {
    global $outputLog;

    $baseUrl = rtrim($baseUrl, '/');
    $baseUrl = str_replace('/xsoar', '', $baseUrl);
    $endpoint = "$baseUrl/public_api/v1/dashboards/insert";

    $jsonContent = file_get_contents($filePath);
    $data = json_decode($jsonContent, true);

    if (!$data) {
        $outputLog .= "   ❌ Dashboard JSON inválido o vacío.\n";
        return ["success" => false];
    }

    // La doc muestra request_data como objeto en el esquema,
    // pero el ejemplo oficial de request lo envía como ARRAY.
    // Para este endpoint, usamos el formato del ejemplo oficial.
    if (isset($data['request_data'])) {
        $payloadData = $data;
    } elseif (isset($data['dashboards_data'])) {
        $payloadData = [
            "request_data" => [[
                "dashboards_data" => $data['dashboards_data'],
                "widgets_data" => $data['widgets_data'] ?? []
            ]]
        ];
    } else {
        $outputLog .= "   ❌ Formato de dashboard no soportado. Falta dashboards_data.\n";
        return ["success" => false];
    }

    $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $outputLog .= "   ↳ Endpoint: $endpoint\n";
    $outputLog .= "   ↳ Payload size: " . strlen($payload) . " bytes\n";
    // opcional para depurar:
    // $outputLog .= "   ↳ Payload: $payload\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Content-Type: application/json",
        "Authorization: $apiKey",
        "x-xdr-auth-id: $authId"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $outputLog .= "   ❌ Dashboard API error de red/timeout: $curlErr\n";
        return ["success" => false];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $outputLog .= "   ✅ Dashboard subido por API\n";
        $outputLog .= "   ↳ HTTP $httpCode\n";
        $outputLog .= "   ↳ Respuesta: $response\n";
        return ["success" => true];
    }

    $errMsg = $response;
    $jsonErr = json_decode($response, true);

    if (isset($jsonErr['reply']['err_msg'])) {
        $errMsg = $jsonErr['reply']['err_msg'];
    } elseif (isset($jsonErr['err_msg'])) {
        $errMsg = $jsonErr['err_msg'];
    } elseif (isset($jsonErr['errors'][0]['error'])) {
        $errMsg = $jsonErr['errors'][0]['error'];
    } elseif (isset($jsonErr['errors'][0]['status'])) {
        $errMsg = $jsonErr['errors'][0]['status'];
    }

    $outputLog .= "   ❌ Dashboard API error ($httpCode): $errMsg\n";
    $outputLog .= "   ↳ Respuesta RAW: $response\n";
    return ["success" => false];
}

// --- HELPER SDK ---
function prepareFileForSdk($originalPath) {
    $tempBaseDir = '/tmp/xsiam_builder/' . uniqid();
    $folderMapping = [
        'Parsers' => 'ParsingRules', 'Modeling' => 'ModelingRules', 'Playbooks' => 'Playbooks',
        'Scripts' => 'Scripts', 'Integrations' => 'Integrations', 'Layouts' => 'Layouts',
        'Dashboards' => 'Dashboards', 'Widgets' => 'Widgets', 'IncidentTypes' => 'IncidentTypes',
        'IncidentFields' => 'IncidentFields', 'IndicatorFields' => 'IndicatorFields',
        'Classifiers' => 'Classifiers', 'Reports' => 'Reports', 'XQLQueries' => 'XQLQueries', 'Lists' => 'Lists'
    ];

    $fileName = basename($originalPath);
    // Sanitize filename
    $cleanFileName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fileName);
    $parentDir = basename(dirname($originalPath));
    $rawVendorDir = basename(dirname(dirname($originalPath)));

    // Sanitize Vendor/Pack name
    $cleanVendorDir = preg_replace('/[^A-Za-z0-9_\-]/', '_', $rawVendorDir);
    $standardFolder = $folderMapping[$parentDir] ?? $parentDir;

    $targetDir = "$tempBaseDir/Packs/$cleanVendorDir/$standardFolder";
    mkdir($targetDir, 0777, true);
    copy($originalPath, "$targetDir/$cleanFileName");

    $dummyMeta = ["name" => $rawVendorDir, "id" => $cleanVendorDir, "currentVersion" => "1.0.0", "author" => "Deployer", "support" => "community"];
    file_put_contents("$tempBaseDir/Packs/$cleanVendorDir/pack_metadata.json", json_encode($dummyMeta));
    return "$targetDir/$cleanFileName";
}
// --- HELPER: EXTRAER DESCRIPCIÓN Y METADATOS DEL CONTENIDO ---
// --- HELPER: EXTRAER DESCRIPCIÓN Y DATASETS DEL CONTENIDO ---
function getContentMetadata($filePath) {
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $content = file_get_contents($filePath);
    $result = ['desc' => '', 'count' => 0, 'datasets' => []];

    if ($ext === 'json') {
        $data = json_decode($content, true);
        if (!$data) return $result;

        $rules = (is_array($data) && isset($data[0])) ? $data : [$data];
        
        $visibleRules = [];
        $hiddenRules = [];
        $allDatasets = [];

        foreach ($rules as $index => $rule) {
            $name = $rule['name'] ?? "Regla #".($index+1);
            $d = !empty($rule['description']) ? $rule['description'] : ($rule['alert_description'] ?? 'No description');
            if (strlen($d) > 75) $d = substr($d, 0, 72) . '...';
            
            // --- LÓGICA DE EXTRACCIÓN DE DATASET MEJORADA ---
            
            // 1. Miramos en el campo 'dataset' de la raíz (evitando el genérico 'alerts')
            if (!empty($rule['dataset']) && $rule['dataset'] !== 'alerts') {
                $allDatasets[] = $rule['dataset'];
            }

            // 2. Escaneamos la XQL Query (Aquí es donde está el dato real en tu ejemplo)
            if (!empty($rule['xql_query'])) {
                // Buscamos: dataset = nombre_dataset (con o sin comillas)
                if (preg_match('/dataset\s*=\s*"?([a-zA-Z0-9_]+)"?/i', $rule['xql_query'], $matches)) {
                    $foundDs = $matches[1];
                    // Solo lo añadimos si no es 'alerts'
                    if ($foundDs !== 'alerts') {
                        $allDatasets[] = $foundDs;
                    }
                }
            }

            // Formateo de lista (igual que antes)
            $formattedRow = "• <b>$name</b>: $d";
            if ($index < 3) { $visibleRules[] = $formattedRow; } 
            else { $hiddenRules[] = $formattedRow; }
        }

        // Generar el HTML de descripción (el mismo que ya tenías)
        $output = implode("<br>", $visibleRules);
        if (count($hiddenRules) > 0) {
            $output .= '<details style="margin-top: 5px; cursor: pointer;">';
            $output .= '<summary style="color: var(--cortex-blue); font-size: 0.7rem; font-weight: bold;">Ver ' . count($hiddenRules) . ' reglas más...</summary>';
            $output .= '<div style="margin-top: 5px; border-top: 1px dashed #ddd; padding-top:5px;">' . implode("<br>", $hiddenRules) . '</div>';
            $output .= '</details>';
        }

        $result['desc'] = $output;
        $result['count'] = count($rules);
        $result['datasets'] = array_unique($allDatasets); // Quitamos duplicados (dedup)
    } 
    // ... resto de la función para YAML ...
    elseif ($ext === 'yml' || $ext === 'yaml') {
        if (preg_match('/^(?:description|comment):\s*(.*)$/m', $content, $matches)) {
            $result['desc'] = trim($matches[1], " \"'");
        }
        $result['count'] = 1;
    }
    
    return $result;
}

function getContentDescription($filePath) {
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $content = file_get_contents($filePath);
    $result = ['desc' => '', 'count' => 0];

    if ($ext === 'json') {
        $data = json_decode($content, true);
        if (!$data) return ['desc' => '<i>Fichero JSON corrupto</i>', 'count' => 0];

        // Caso: Múltiples correlaciones (Array)
        if (isset($data[0]) && is_array($data[0])) {
            $visibleRules = [];
            $hiddenRules = [];
            
            foreach ($data as $index => $rule) {
                $name = $rule['name'] ?? "Regla #".($index+1);
                $d = !empty($rule['description']) ? $rule['description'] : ($rule['alert_description'] ?? 'No description');
                if (strlen($d) > 75) $d = substr($d, 0, 72) . '...';
                
                $formattedRule = "• <b>$name</b>: $d";
                
                // Las primeras 3 siempre se ven, el resto van al "baúl"
                if ($index < 3) {
                    $visibleRules[] = $formattedRule;
                } else {
                    $hiddenRules[] = $formattedRule;
                }
            }

            $output = implode("<br>", $visibleRules);
            
            // Si hay más de 3, creamos el desplegable
            if (count($hiddenRules) > 0) {
                $output .= '<details style="margin-top: 5px; cursor: pointer;">';
                $output .= '<summary style="color: var(--cortex-blue); font-size: 0.7rem; font-weight: bold;">Ver ' . count($hiddenRules) . ' reglas más...</summary>';
                $output .= '<div style="margin-top: 5px;">' . implode("<br>", $hiddenRules) . '</div>';
                $output .= '</details>';
            }
            
            $result['desc'] = $output;
            $result['count'] = count($data);
        } else {
            // Caso: Una sola regla u otro contenido JSON
            $rule = isset($data['request_data'][0]) ? $data['request_data'][0] : $data;
            $result['desc'] = $rule['description'] ?? $rule['alert_description'] ?? $rule['comment'] ?? 'No description';
            $result['count'] = 1;
        }
    } 
    elseif ($ext === 'yml' || $ext === 'yaml') {
        // Scripts suelen usar 'comment', Playbooks/Integrations usan 'description'
        if (preg_match('/^(?:description|comment):\s*(.*)$/m', $content, $matches)) {
            $result['desc'] = trim($matches[1], " \"'");
        } else {
            // Fallback al nombre si no hay descripción
            if (preg_match('/^name:\s*(.*)$/m', $content, $matches)) {
                $result['desc'] = "Name: " . trim($matches[1], " \"'");
            }
        }
        $result['count'] = 1;
    }
    
    // Limpieza final de longitud para YAML
    if (strlen($result['desc']) > 150 && $ext !== 'json') {
        $result['desc'] = substr($result['desc'], 0, 147) . '...';
    }

    return $result;
}
// --- ACTIONS ---
if (isset($_POST['action']) && $_POST['action'] === 'sync') {
    // Opción para saltar la verificación SSL (equivalente a -k en curl)
    $gitNoVerify = "git -c http.sslVerify=false";

    if (is_dir($localRepoPath)) {
        // En el pull, inyectamos la configuración antes del comando 'pull'
        exec("cd " . escapeshellarg($localRepoPath) . " && $gitNoVerify pull 2>&1", $out, $ret);
    } else {
        // En el clone, igual: git -c ... clone URL DIR
        // Nota: Asegúrate de escapar las variables para evitar problemas de seguridad
        $safeUrl = escapeshellarg($repoUrl);
        $safePath = escapeshellarg($localRepoPath);
        
        exec("$gitNoVerify clone $safeUrl $safePath 2>&1", $out, $ret);
    }
    
    $message = ($ret === 0) ? "✅ Repository synchronized." : "❌ Git Error: " . htmlspecialchars(implode(" | ", $out));    
    $messageType = ($ret === 0) ? "success" : "error";
    $updateAvailable = false;
}

if (isset($_POST['action']) && $_POST['action'] === 'deploy') {
    $apiUrl = $_POST['api_url']; $apiKey = $_POST['api_key']; $authId = $_POST['auth_id'];
    $selectedFiles = $_POST['selected_files'] ?? [];
    $outputLog = "--- STARTING DEPLOYMENT --- \n"; $successCount = 0;

    
    foreach ($selectedFiles as $file) {
    $realPath = $localRepoPath . '/' . $file;
    $outputLog .= "\n📂 " . basename($file);

    if (stripos(dirname($realPath), 'Correlation') !== false) {
        $res = uploadCorrelationRulesViaApi($realPath, $apiUrl, $apiKey, $authId);
        if ($res['success']) {
            $successCount++;
        }

    } elseif (stripos(dirname($realPath), 'Dashboards') !== false) {
        $res = uploadDashboardViaApi($realPath, $apiUrl, $apiKey, $authId);
        if ($res['success']) {
            $successCount++;
        }

    } elseif (stripos(dirname($realPath), 'Reports') !== false || stripos(dirname($realPath), 'Widgets') !== false) {
        $sdkPath = prepareFileForSdk($realPath);
        $packDir = dirname(dirname($sdkPath));

        $cmd = "env DEMISTO_BASE_URL=" . escapeshellarg($apiUrl) .
               " DEMISTO_API_KEY=" . escapeshellarg($apiKey) .
               " XSIAM_AUTH_ID=" . escapeshellarg($authId) .
               " DEMISTO_VERIFY_SSL=false HOME=/tmp demisto-sdk upload -i " .
               escapeshellarg($packDir) . " -z --xsiam --insecure 2>&1";

        exec($cmd, $outSDK, $retSDK);

        if ($retSDK === 0) {
            $outputLog .= "   ✅ SDK OK (Zipped Pack)\n";
            $successCount++;
        } else {
            $errorDetails = implode("\n", array_slice($outSDK, -10));
            $outputLog .= "   ❌ ERROR SDK (Code: $retSDK):\n$errorDetails\n";
        }

    } else {
        $sdkPath = prepareFileForSdk($realPath);

        $cmd = "env DEMISTO_BASE_URL=" . escapeshellarg($apiUrl) .
               " DEMISTO_API_KEY=" . escapeshellarg($apiKey) .
               " XSIAM_AUTH_ID=" . escapeshellarg($authId) .
               " DEMISTO_VERIFY_SSL=false HOME=/tmp demisto-sdk upload -i " .
               escapeshellarg($sdkPath) . " --xsiam --insecure 2>&1";

        exec($cmd, $outSDK, $retSDK);

        if ($retSDK === 0) {
            $outputLog .= "   ✅ SDK OK\n";
            $successCount++;
        } else {
            $errorDetails = implode("\n", array_slice($outSDK, -10));
            $outputLog .= "   ❌ ERROR SDK (Code: $retSDK):\n$errorDetails\n";
        }
    }
}


    $message = "Deployment process finished. ($successCount/" . count($selectedFiles) . ")"; $messageType = "success";
}

// --- PROCESS FILES FOR UI ---
// --- PROCESS FILES FOR UI ---
// --- PROCESS FILES FOR UI ---
$vendorGroups = [];
if (is_dir($localRepoPath)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localRepoPath));
    foreach ($rii as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['yml', 'json', 'py'])) {
            $relPath = str_replace($localRepoPath . '/', '', $file->getPathname());
            $parts = explode('/', $relPath);
            if (count($parts) >= 3) {
                $vendor = $parts[0]; 
                $type = $parts[1];
                
                // Usamos la nueva función metadata (la del paso 1)
                $info = getContentMetadata($file->getPathname());

                $vendorGroups[$vendor][] = [
                    'path' => $relPath, 
                    'name' => basename($relPath), 
                    'type' => $type,
                    'desc' => $info['desc'],
                    'count' => $info['count'],
                    'datasets' => $info['datasets'] // <--- NUEVO: Guardamos la lista de datasets
                ];
            }
        }
    }
    ksort($vendorGroups);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cortex Content Deployer</title>
    <style>

        :root { --cortex-blue: #005fdb; --cortex-dark: #2c3e50; --cortex-green: #00bf63; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; margin: 0; display: flex; height: 100vh; }
.sidebar { 
    width: 260px; /* Reducido de 340px */
    background: white; 
    border-right: 1px solid #ddd; 
    padding: 20px 15px; /* Padding más ajustado para aprovechar el ancho */
    display: flex; 
    flex-direction: column; 
    box-shadow: 2px 0 5px rgba(0,0,0,0.05); 
    flex-shrink: 0; /* Evita que el sidebar se encoja si la tabla es muy grande */
}

/* Ajustamos el tamaño del logo para que no se corte en el nuevo ancho */
.logo-text {
    font-size: 20px; /* Bajado de 24px */
    font-weight: 800;
    color: #2D2D2D;
    letter-spacing: -0.5px;
}  
        .logo-container { text-align: center; margin-bottom: 20px; } .logo-container img { max-width: 180px; }
        .form-group { margin-bottom: 15px; } .form-group label { display: block; font-size: 0.8rem; font-weight: bold; color: #444; margin-bottom: 5px; text-transform: uppercase; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 0.9rem; }
        
.btn { 
    width: 100%; 
    padding: 10px; /* Bajado de 12px */
    border: none; 
    border-radius: 4px; 
    font-weight: bold; 
    cursor: pointer; 
    margin-bottom: 8px; 
    font-size: 0.85rem; /* Fuente ligeramente más pequeña */
    text-decoration: none; 
    display: block; 
    text-align: center; 
    box-sizing: border-box; 
}
        .btn-deploy { background: var(--cortex-green); color: white; } 
        .btn-sync { background: var(--cortex-dark); color: white; }
        
        /* Botón de volver mejorado */
        .btn-back { background: #607d8b; color: white; margin-top: auto; } /* margin-top: auto lo empuja al fondo */
        .btn-back:hover { background: #546e7a; }

        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        .filters { display: flex; gap: 15px; margin-bottom: 20px; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .filters input { padding: 10px; border: 1px solid #ddd; border-radius: 4px; flex: 1; }
        .vendor-section { background: white; border-radius: 8px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .vendor-header { background: #fafafa; padding: 12px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; font-weight: bold; color: var(--cortex-blue); }
        table { width: 100%; border-collapse: collapse; } th, td { padding: 12px 20px; text-align: left; font-size: 0.9rem; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; color: #666; text-transform: uppercase; font-size: 0.7rem; }
        
        /* ESTILOS DE BADGES */
        .badge { 
            padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; 
            text-transform: uppercase; display: inline-block; color: white; 
            background-color: #9e9e9e; /* Gris por defecto */
        }
        
        .type-integrations { background: #673ab7; font-weight: 900; border: 2px solid #512da8; box-shadow: 0 2px 4px rgba(103,58,183,0.3); }
        .type-playbooks { background: #005fdb; } .type-scripts { background: #ff9800; }
        .type-layouts { background: #4caf50; } 
        .type-correlation, .type-correlations, .type-correlationrules { background: #d32f2f; }
        .type-parsingrules { background: #00bcd4; } .type-modelingrules { background: #009688; }
        .type-dashboards { background: #e91e63; }
        .type-widgets { background: #607d8b; }
        
        .log-box { background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 6px; font-family: 'Consolas', monospace; font-size: 0.8rem; margin-bottom: 20px; white-space: pre-wrap; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .alert-success { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        .logo-container {
    display: flex;
    align-items: center;
    /* Asegura que no se deforme si el contenedor es flexible */
    flex-shrink: 0; 
}

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
.dataset-badge {
    background-color: #f0f4f8;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 0.7rem;
    font-family: 'Consolas', monospace;
    display: inline-block;
    margin-bottom: 4px;
}
.file-row a:hover {
    text-decoration: underline !important;
    color: #003e91 !important; /* Un azul un poco más oscuro */
}
    </style>
</head>
<body>

<form method="POST" id="mainForm" class="sidebar">
    <div class="logo-container">
        <div class="logo-text">Content<span>Importer</span></div>
    </div>

    <div class="form-group">
        <label>Cortex API URL</label>
        <input type="text" name="api_url" value="<?= htmlspecialchars($_POST['api_url'] ?? '') ?>" 
               placeholder="https://api-XX.xdr.XX.paloaltonetworks.com" required>
    </div>
    <div class="form-group">
        <label>Auth ID</label>
        <input type="text" name="auth_id" value="<?= htmlspecialchars($_POST['auth_id'] ?? '') ?>" placeholder="Enter Auth ID" required>
    </div>
    <div class="form-group">
        <label>API Key</label>
        <input type="password" name="api_key" value="<?= htmlspecialchars($_POST['api_key'] ?? '') ?>" placeholder="••••••••••••••••" required>
    </div>

    <button type="submit" name="action" value="deploy" class="btn btn-deploy">🚀 DEPLOY SELECTION</button>
    <button type="submit" name="action" value="sync" class="btn btn-sync" formnovalidate>🔄 SYNC REPOSITORY</button>
    
    <div id="selectionCounter" style="text-align:center; font-size:0.8rem; color:#666; margin-top:10px; margin-bottom: 15px;">
        0 items selected
    </div>

    <a href="../index.php" class="btn btn-back">⬅️ Back to PANTools</a>
</form>

<div class="main-content">
    <?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= $message ?></div><?php endif; ?>
    <?php if ($outputLog): ?><div class="log-box"><?= htmlspecialchars($outputLog) ?></div><?php endif; ?>

    <div class="filters">
        <input type="text" id="filterVendor" placeholder="🔍 Filter by Vendor..." onkeyup="applyFilters()">
        <input type="text" id="filterType" placeholder="🔍 Filter by Type (Playbook, Script...)" onkeyup="applyFilters()">
        <input type="text" id="filterFile" placeholder="🔍 Search by file name..." onkeyup="applyFilters()">
    </div>

    <?php foreach ($vendorGroups as $vendor => $files): 
            // --- FIX: Crear un ID seguro para CSS (sin espacios ni símbolos) ---
            $vendorId = preg_replace('/[^a-zA-Z0-9]/', '', $vendor);
        ?>
            <div class="vendor-section" data-vendor="<?= strtolower($vendor) ?>">
                <div class="vendor-header">
                    <input type="checkbox" class="vendor-master-checkbox" onclick="toggleVendor('<?= $vendorId ?>', this)">
                    <span>📦 VENDOR: <?= htmlspecialchars($vendor) ?></span>
                </div>
                <table>
                   <thead>
                        <tr>
                            <th width="40"></th>
                            <th width="120">Content Type</th>
                            <th width="50%">Resource Name & Description</th>
                            <th>Dependencies (Datasets)</th> </tr>
                    </thead>
                    <tbody id="body-<?= $vendorId ?>">
                        <?php foreach ($files as $file): 
                            $cssType = "type-" . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $file['type']));
                        ?>
                            <tr class="file-row" data-type="<?= strtolower($file['type']) ?>" data-name="<?= strtolower($file['name']) ?>">
                                <td><input type="checkbox" form="mainForm" name="selected_files[]" value="<?= $file['path'] ?>" class="check-<?= $vendorId ?> file-checkbox" onclick="updateCounter()"></td>
                                
                                <td><span class="badge <?= $cssType ?>"><?= htmlspecialchars($file['type']) ?></span></td>
                                
                                <td>
                                    <div style="font-weight:bold; margin-bottom: 3px;">
                                        <a href="<?= $repoBaseUrl ?>/blob/<?= $branch ?>/<?= $file['path'] ?>" 
                                        target="_blank" 
                                        style="text-decoration: none; color: var(--cortex-blue);"
                                        title="View on GitHub">
                                        <i class="fab fa-github me-1"></i> <?= htmlspecialchars($file['name']) ?>
                                        </a>

                                        <?php if ($file['count'] > 1): ?>
                                            <span class="badge bg-secondary" style="font-size: 0.6rem; vertical-align: middle;">
                                                <?= $file['count'] ?> rules
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="description-container" style="font-size: 0.75rem; color: #555; line-height: 1.4; border-left: 2px solid #eee; padding-left: 10px;">
                                        <?= $file['desc'] ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($file['datasets'])): ?>
                                        <?php foreach ($file['datasets'] as $ds): ?>
                                            <div class="dataset-badge">
                                                <i class="fas fa-database small me-1"></i> <?= htmlspecialchars($ds) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
</div>

<script>
    function updateCounter() {
        const count = document.querySelectorAll('input[name="selected_files[]"]:checked').length;
        document.getElementById('selectionCounter').innerText = count + " items selected";
    }

    function toggleVendor(vendor, master) {
        const checkboxes = document.querySelectorAll('.check-' + vendor);
        checkboxes.forEach(cb => {
            if (cb.closest('tr').style.display !== 'none') {
                cb.checked = master.checked;
            }
        });
        updateCounter();
    }

    function applyFilters() {
        const vFilter = document.getElementById('filterVendor').value.toLowerCase();
        const tFilter = document.getElementById('filterType').value.toLowerCase();
        const fFilter = document.getElementById('filterFile').value.toLowerCase();

        document.querySelectorAll('.vendor-section').forEach(section => {
            const vendorName = section.getAttribute('data-vendor');
            let hasVisibleFiles = false;

            section.querySelectorAll('.file-row').forEach(row => {
                const type = row.getAttribute('data-type');
                const name = row.getAttribute('data-name');

                const matchesVendor = vendorName.includes(vFilter);
                const matchesType = type.includes(tFilter);
                const matchesFile = name.includes(fFilter);

                if (matchesVendor && matchesType && matchesFile) {
                    row.style.display = '';
                    hasVisibleFiles = true;
                } else {
                    row.style.display = 'none';
                }
            });
            section.style.display = hasVisibleFiles ? '' : 'none';
        });
    }
</script>

</body>
</html>