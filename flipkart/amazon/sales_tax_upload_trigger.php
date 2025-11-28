<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: flipkart_str_php.php");
    exit();
}

$host = 'localhost';
$db   = 'evanik_main';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Failed: " . $e->getMessage());
}

if (empty($_GET['log_id'])) {
    header('Location: dashboard.php?msg=' . urlencode('Missing log_id'));
    exit();
}

$log_id = intval($_GET['log_id']);

// Fetch the log row
$stmt = $pdo->prepare("SELECT * FROM flipkart_str_logs WHERE id = ? LIMIT 1");
$stmt->execute([$log_id]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    header('Location: dashboard.php?msg=' . urlencode('Log entry not found'));
    exit();
}

$report_id = isset($log['report_id']) ? trim($log['report_id']) : '';
$flipkart_user_id = isset($log['flipkart_user_id']) ? $log['flipkart_user_id'] : '';
$channel_id = isset($log['channel_id']) ? $log['channel_id'] : '';

if (!$report_id || $report_id === '0') {
    header('Location: dashboard.php?msg=' . urlencode('Report ID missing or invalid for selected log'));
    exit();
}

// Function to safely truncate response
function truncateResponse($response, $maxLength = 65535) {
    if (strlen($response) <= $maxLength) {
        return $response;
    }
    
    // Try to find a good breaking point
    $truncated = substr($response, 0, $maxLength - 20);
    $lastBrace = strrpos($truncated, "}");
    if ($lastBrace !== false) {
        $truncated = substr($truncated, 0, $lastBrace + 1);
    }
    
    return $truncated . "...[truncated]";
}

// First insert into run_reports
$stmt = $pdo->prepare("INSERT INTO run_reports (
    runner_user_id, 
    target_userid, 
    channel_id, 
    start_date,
    end_date,
    report_id,
    status
) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')");

$stmt->execute([
    $_SESSION['user_id'],
    $log['flipkart_user_id'],
    $log['channel_id'],
    $log['start_date'],
    $log['end_date'],
    $report_id
]);
// capture the run_reports insert id so we can reference it without sending large data in URL
$runId = $pdo->lastInsertId();

// Call the upload cron URL
$url2 = "https://cron.evanik.com/cronjobs/Flipkart/API/sales_tax_upload.php?UserID=" . urlencode($flipkart_user_id) . "&channel_id=" . urlencode($channel_id) . "&report_id=" . urlencode($report_id);

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    "User-Agent: Mozilla/5.0",
    "Accept: application/json"
]);
$response2 = curl_exec($ch2);
$curlErr2 = curl_error($ch2);
curl_close($ch2);

$response2_trim = trim($response2);
 // Do not include full response in redirect. Save full response to DB and use a short message.
 $statusMsg = '';
 if ($response2_trim !== '') {
     $statusMsg = "Upload response received";
 } else {
     $statusMsg = $curlErr2 ? "Upload curl error" : 'No response from upload cron';
 }

// Record upload into a separate table (safe, create if not exists)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flipkart_str_upload_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        report_id VARCHAR(255),
        response MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Truncate response if too large
    $truncatedResponse = truncateResponse($response2_trim);
    
    $ins = $pdo->prepare("INSERT INTO flipkart_str_upload_logs (log_id, report_id, response) VALUES (?, ?, ?)");
    $ins->execute([$log_id, $report_id, $truncatedResponse]);
} catch (Exception $e) {
    // ignore table/create errors but include message
    $statusMsg .= "; failed to record upload log: " . $e->getMessage();
}

// Redirect back to dashboard with short message. Avoid sending large response text in headers.
$shortMsg = "Upload triggered for report_id={$report_id}. Run ID={$runId}. " . $statusMsg;

// If called via AJAX, respond with JSON so the frontend doesn't navigate away
if (!empty($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'run_id' => $runId,
        'report_id' => $report_id,
        'message' => $shortMsg
    ]);
    exit();
} else {
    header('Location: dashboard.php?msg=' . urlencode($shortMsg));
    exit();
}
