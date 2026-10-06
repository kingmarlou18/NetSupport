<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ai_json(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ai_json(405, ['error' => 'Use POST to send a message.']);
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfHeader) || !hash_equals($_SESSION['csrf'] ?? '', $csrfHeader)) {
    ai_json(419, ['error' => 'Your session token expired. Refresh the page and try again.']);
}

$request = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($request)) ai_json(400, ['error' => 'Invalid request.']);
$message = trim((string)($request['message'] ?? ''));
$agent = (string)($request['agent'] ?? 'operations');
$agentPrompts = [
    'operations' => 'Help explain NetSupport operations and common workflows.',
    'dispatch' => 'Focus on support requests, assignments, team leads, crew workflow, and dispatch.',
    'reports' => 'Focus on assignment status, completion, performance reporting, and analytics.',
    'gis' => 'Focus on barangay GIS maps, map layers, locations, and field support planning.',
];
if (!isset($agentPrompts[$agent])) ai_json(400, ['error' => 'Choose a valid assistant mode.']);
if ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
    ai_json(422, ['error' => 'Enter a message of 1 to 2,000 characters.']);
}

$privateAiConfig = 'D:/xampp/private/network_support/ai_config.php';
$aiConfig = is_file($privateAiConfig) ? require $privateAiConfig : [];
if (!is_array($aiConfig)) $aiConfig = [];
$baseUrl = rtrim(trim((string)(getenv('NETWORK_SUPPORT_AI_BASE_URL') ?: ($aiConfig['base_url'] ?? ''))), '/');
$model = trim((string)(getenv('NETWORK_SUPPORT_AI_MODEL') ?: ($aiConfig['model'] ?? '')));
$baseUrl = $baseUrl !== '' ? $baseUrl : 'http://127.0.0.1:11434';
$urlParts = parse_url($baseUrl);
// Local-only by design: no message, model context, or application data may be
// forwarded to a remote provider. Ollama exposes an OpenAI-compatible endpoint.
$isLocalUrl = is_array($urlParts)
    && ($urlParts['scheme'] ?? '') === 'http'
    && in_array(strtolower((string)($urlParts['host'] ?? '')), ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocalUrl || !empty($urlParts['user']) || !empty($urlParts['pass'])) {
    ai_json(503, ['error' => 'The AI assistant accepts local loopback URLs only. Configure Ollama on this computer.']);
}
if ($model === '') {
    ai_json(503, ['error' => 'The local Ollama model is not configured. Set its model name in D:\\xampp\\private\\network_support\\ai_config.php.']);
}
if (!function_exists('curl_init')) ai_json(503, ['error' => 'The server does not have PHP cURL enabled.']);

try {
    // Share aggregate counts only. Names, contact details, descriptions, notes,
    // locations, credentials, and individual event records are never exported.
    $summary = [];
    if (is_field_staff()) {
        $q = db()->prepare('SELECT status, COUNT(*) total FROM event_assignments WHERE technician_id = ? GROUP BY status');
        $q->execute([(int)user()['id']]);
        $summary['my_assignment_counts'] = [];
        foreach ($q->fetchAll() as $row) $summary['my_assignment_counts'][$row['status']] = (int)$row['total'];
        $summary['my_assignment_total'] = array_sum($summary['my_assignment_counts']);
    } else {
        $summary['event_counts_by_status'] = [];
        foreach (db()->query('SELECT status, COUNT(*) total FROM events GROUP BY status')->fetchAll() as $row) {
            $summary['event_counts_by_status'][$row['status']] = (int)$row['total'];
        }
        $summary['assignment_counts_by_status'] = [];
        foreach (db()->query('SELECT status, COUNT(*) total FROM event_assignments GROUP BY status')->fetchAll() as $row) {
            $summary['assignment_counts_by_status'][$row['status']] = (int)$row['total'];
        }
        $summary['active_user_count'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('NetSupport AI summary query failed: ' . $e->getMessage());
    ai_json(500, ['error' => 'Could not read the permitted system summary.']);
}

$systemMessage = 'You are the NetSupport in-app assistant. ' . $agentPrompts[$agent]
    . ' Answer clearly and concisely. Use only the provided aggregate summary and the user message. '
    . 'The summary contains counts, not individual records. Do not claim access to specific people, locations, event details, or private data. '
    . 'Never request passwords, API keys, or authentication secrets. If asked to perform an action, explain the relevant app workflow; do not claim to have changed data. '
    . 'Permitted aggregate context: ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$payload = json_encode([
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemMessage],
        ['role' => 'user', 'content' => $message],
    ],
    'stream' => false,
    // This computer's Vulkan GPU crashes Ollama inference. Force CPU per
    // request and disable Qwen's reasoning stream so it returns an answer.
    'think' => false,
    'options' => [
        'temperature' => 0.2,
        'num_predict' => 600,
        'num_ctx' => 2048,
        'num_gpu' => 0,
    ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// Small local models may need longer on CPU-only machines.
if (function_exists('set_time_limit')) set_time_limit(150);
$ch = curl_init($baseUrl . '/api/chat');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROXY => '',
    CURLOPT_NOPROXY => '*',
]);
$responseBody = curl_exec($ch);
$curlError = curl_errno($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($responseBody === false || $curlError !== 0) {
    error_log('NetSupport AI provider request failed (cURL ' . $curlError . ').');
    ai_json(502, ['error' => 'The local Ollama server could not be reached. Start Ollama on this computer and try again.']);
}
$providerData = json_decode($responseBody, true);
if ($httpCode < 200 || $httpCode >= 300 || !is_array($providerData)) {
    error_log('NetSupport AI provider returned HTTP ' . $httpCode . '.');
    if ($httpCode === 404) ai_json(502, ['error' => 'The configured local model is not installed. Run: ollama pull ' . $model]);
    ai_json(502, ['error' => 'The AI service returned an error. Please try again later.']);
}
$reply = trim((string)($providerData['message']['content'] ?? ''));
if ($reply === '') ai_json(502, ['error' => 'The AI service returned an empty response.']);
ai_json(200, ['reply' => mb_substr($reply, 0, 6000, 'UTF-8')]);
