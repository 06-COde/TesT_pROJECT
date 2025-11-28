<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Not authenticated']));
}

if (empty($_GET['report_id'])) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Missing report_id']));
}

$report_id = $_GET['report_id'];

// Get latest log for this report_id to get UserID and channel_id
$host = 'localhost';
$db   = 'evanik_main';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'DB Error: ' . $e->getMessage()]));
}

$stmt = $pdo->prepare("SELECT * FROM flipkart_str_logs WHERE report_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$report_id]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Log not found for report_id']));
}

// Call Flipkart API to check status
$url = "https://cron.evanik.com/cronjobs/Flipkart/API/sales_tax_upload.php?UserID=" . 
       urlencode($log['flipkart_user_id']) . 
       "&channel_id=" . urlencode($log['channel_id']) . 
       "&report_id=" . urlencode($report_id);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "User-Agent: Mozilla/5.0",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Allow self-signed certificates
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Allow self-signed certificates

$response = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Curl error: ' . $curlErr]));
}

// Helper: truncate long responses
function truncateResponse($resp, $max = 65535) {
    if ($resp === null) return '';
    if (strlen($resp) <= $max) return $resp;
    $tr = substr($resp, 0, $max - 20);
    $last = strrpos($tr, "}\n");
    if ($last === false) $last = strrpos($tr, "}");
    if ($last !== false) $tr = substr($tr, 0, $last+1);
    return $tr . "...[truncated]";
}

// Determine status and records from response
$status = 'UNKNOWN';
$records = null;

// Try JSON decode first
$decoded = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
    // Common keys
    if (!empty($decoded['status'])) $status = strtoupper($decoded['status']);
    if (!empty($decoded['result'])) {
        if (is_string($decoded['result'])) {
            if (stripos($decoded['result'], 'completed') !== false) $status = 'COMPLETED';
            if (preg_match('/Record Updated\\s*(\\d+)/i', $decoded['result'], $m)) $records = (int)$m[1];
        } elseif (is_array($decoded['result']) && isset($decoded['result']['records'])) {
            $records = (int)$decoded['result']['records'];
        }
    }
    if ($status === 'UNKNOWN') {
        if (!empty($decoded['message']) && (stripos($decoded['message'],'completed')!==false)) $status = 'COMPLETED';
    }
} else {
    // Not JSON, check plain text
    if (stripos($response, 'COMPLETED') !== false || stripos($response, 'success') !== false) {
        $status = 'COMPLETED';
    } elseif (stripos($response, 'PROCESSING') !== false || stripos($response, 'pending') !== false) {
        $status = 'PROCESSING';
    }
    // Try to extract "Record Updated (N)" pattern
    if (preg_match('/Record Updated\\s*\\(?\\s*(\\d+)\\s*\\)?/i', $response, $m)) {
        $records = (int)$m[1];
    }
}

// Persist to flipkart_str_upload_logs (ensure MEDIUMTEXT if available)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flipkart_str_upload_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        report_id VARCHAR(255),
        response MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $ins = $pdo->prepare("INSERT INTO flipkart_str_upload_logs (log_id, report_id, response) VALUES (?, ?, ?)");
    $ins->execute([$log['id'], $report_id, truncateResponse($response, 200000)]);
} catch (Exception $e) {
    error_log("Failed to save upload log: " . $e->getMessage());
}

// Update run_reports latest row for this report_id
try {
    $q = $pdo->prepare("SELECT id FROM run_reports WHERE report_id = ? ORDER BY id DESC LIMIT 1");
    $q->execute([$report_id]);
    $runId = $q->fetchColumn();
    if ($runId) {
        $upd = $pdo->prepare("UPDATE run_reports SET status = ?, upload_response = ? WHERE id = ?");
        $upd->execute([$status, truncateResponse($response, 200000), $runId]);
    }
} catch (Exception $e) {
    error_log("Failed to update run_reports: " . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode([
    'status' => $status,
    'report_id' => $report_id,
    'records' => $records,
    'message' => ($decoded && isset($decoded['message'])) ? $decoded['message'] : null,
    'raw' => substr($response, 0, 10000)
]);