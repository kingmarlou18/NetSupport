<?php
/** Configurable Smart HTTP SMS adapter; provider credentials stay outside htdocs. */

function sms_settings(): array
{
    static $settings;
    if ($settings === null) {
        $baseDir = defined('APP_ROOT') ? dirname(APP_ROOT, 2) : dirname(__DIR__, 3);
        $privatePath = $baseDir . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'network_support' . DIRECTORY_SEPARATOR . 'sms_config.php';

        if (!is_file($privatePath)) {
            $altPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private'
                . DIRECTORY_SEPARATOR . 'network_support' . DIRECTORY_SEPARATOR . 'sms_config.php';
            if (is_file($altPath)) {
                $privatePath = $altPath;
            }
        }

        if (!is_file($privatePath) || !is_readable($privatePath)) {
            error_log('Private SMS configuration is missing or unreadable; SMS remains disabled.');
            $settings = ['enabled' => false];
        } else {
            $settings = require $privatePath;
            if (!is_array($settings)) $settings = ['enabled' => false];
        }
    }
    return $settings;
}

/**
 * Send one SMS when the provider is configured. Returns false when disabled or
 * when delivery fails; assignment creation itself must continue either way.
 */
function send_sms(string $phoneNumber, string $message): bool
{
    $settings = sms_settings();
    if (empty($settings['enabled'])) return false;

    $endpoint = trim((string)($settings['endpoint'] ?? ''));
    $authName = trim((string)($settings['auth_header_name'] ?? ''));
    $authValue = trim((string)($settings['auth_header_value'] ?? ''));
    if (($settings['provider'] ?? '') !== 'smart'
        || !filter_var($endpoint, FILTER_VALIDATE_URL)
        || stripos($endpoint, 'https://') !== 0) {
        error_log('Smart SMS is enabled but provider or HTTPS endpoint is not configured.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('SMS could not be sent because PHP cURL is not enabled.');
        return false;
    }

    $digits = preg_replace('/[^0-9+]/', '', trim($phoneNumber));
    if (preg_match('/^09\d{9}$/', $digits)) {
        $digits = '+63' . substr($digits, 1);
    } elseif (preg_match('/^639\d{9}$/', $digits)) {
        $digits = '+' . $digits;
    } elseif (preg_match('/^\+639\d{9}$/', $digits)) {
        // Already in Philippine E.164 format.
    }
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
        error_log('Smart SMS was skipped because the recipient number is not a valid international number.');
        return false;
    }

    $format = strtolower((string)($settings['request_format'] ?? 'json'));
    if (!in_array($format, ['json', 'form'], true)) {
        error_log('Smart SMS request format is not supported by the generic adapter.');
        return false;
    }
    $payload = (array)($settings['extra_payload'] ?? []);
    $payload[(string)($settings['recipient_field'] ?? 'recipient')] = $digits;
    $payload[(string)($settings['message_field'] ?? 'message')] = $message;
    $senderField = trim((string)($settings['sender_field'] ?? ''));
    if ($senderField !== '' && !empty($settings['sender_id'])) $payload[$senderField] = $settings['sender_id'];

    $contentType = $format === 'json' ? 'application/json' : 'application/x-www-form-urlencoded';
    $body = $format === 'json'
        ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : http_build_query($payload);
    $headers = ['Content-Type: ' . $contentType, 'Accept: application/json'];
    if ($authName !== '' && $authValue !== '') $headers[] = $authName . ': ' . $authValue;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => strtoupper((string)($settings['http_method'] ?? 'POST')) === 'POST',
        CURLOPT_CUSTOMREQUEST => strtoupper((string)($settings['http_method'] ?? 'POST')),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(1, (int)($settings['timeout_seconds'] ?? 15)),
    ]);
    $responseBody = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if ($curlError !== '' || $httpStatus < 200 || $httpStatus >= 300 || (is_array($response) && array_key_exists('success', $response) && !$response['success'])) {
        // Do not log the request body or headers; those may contain secrets/PII.
        error_log('Smart SMS request failed. HTTP status: ' . $httpStatus . ($curlError ? '; transport error.' : '.'));
        return false;
    }
    return true;
}

function sms_assignment_reminder(int $technicianId, int $eventId): bool
{
    if (empty(sms_settings()['enabled'])) return false;

    $stmt = db()->prepare('SELECT u.phone_number, e.title, e.start_at, e.location
                           FROM users u
                           JOIN event_assignments a ON a.technician_id = u.id
                           JOIN events e ON e.id = a.event_id
                           WHERE u.id = ? AND e.id = ? AND a.status = "New Assignment"');
    $stmt->execute([$technicianId, $eventId]);
    $row = $stmt->fetch();
    if (!$row || trim((string)$row['phone_number']) === '') return false;

    $crewStmt = db()->prepare('SELECT u.full_name
                               FROM event_assignments a
                               JOIN users u ON u.id = a.technician_id
                               WHERE a.event_id = ? AND a.technician_id <> ?
                                 AND a.status <> "Cancelled"
                               ORDER BY u.full_name');
    $crewStmt->execute([$eventId, $technicianId]);
    $otherTechnicians = $crewStmt->fetchAll(PDO::FETCH_COLUMN);
    $crewLine = $otherTechnicians
        ? ' Other technicians: ' . implode(', ', $otherTechnicians) . '.'
        : ' Other technicians: None yet.';

    $message = sprintf(
        'NetSupport: You are assigned to %s on %s at %s. Location: %s.%s',
        $row['title'],
        date('M j, Y', strtotime($row['start_at'])),
        date('g:i A', strtotime($row['start_at'])),
        $row['location'],
        $crewLine
    );
    return send_sms((string)$row['phone_number'], $message);
}
