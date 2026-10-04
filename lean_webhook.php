<?php
/**
 * Lean webhook receiver (public: listed in Bootstrap::PUBLIC_SCRIPTS).
 *
 * Every request must carry a valid "lean-signature" header: HMAC-SHA512 of the raw body
 * with LEAN_WEBHOOK_SECRET (https://docs.leantech.me/docs/webhooks). Anything else is
 * rejected with 401 before the body is parsed.
 *
 * Handled events (https://docs.leantech.me/docs/webhook-library, /docs/data-workflow):
 *   entity.created               → store the new connection (status pending)
 *   entity.reconnected           → mark active, sync it
 *   entity.data.refresh.updated  → FINISHED: mark active, sync it; CONSENT_EXPIRED/RECONNECT_REQUIRED: flag it
 *   consent.status.updated       → revoked/expired/suspended data consent: flag the connection
 * Lean expects a 200 within 10 seconds, so the sync runs after the response is flushed.
 */
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;

Bootstrap::init();

header('Content-Type: application/json');
header('Cache-Control: no-store');

function leanWebhookReply(int $status, string $result): void
{
    http_response_code($status);
    echo json_encode(['result' => $result]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    leanWebhookReply(405, 'method_not_allowed');
    exit();
}

$raw = (string) file_get_contents('php://input', false, null, 0, 262144);
if (!LeanClient::verifyWebhookSignature($raw, (string) ($_SERVER['HTTP_LEAN_SIGNATURE'] ?? ''))) {
    leanWebhookReply(401, 'invalid_signature');
    exit();
}

$event = json_decode($raw, true);
if (!is_array($event) || !isset($event['type'])) {
    leanWebhookReply(400, 'invalid_payload');
    exit();
}

if (!LeanSync::tablesReady($pdo)) {
    leanWebhookReply(503, 'not_ready'); // Lean retries later
    exit();
}

try {
    $toSync = LeanSync::handleWebhook($pdo, $event);
} catch (Throwable $e) {
    error_log('Lean webhook ' . substr((string) $event['type'], 0, 60) . ': ' . $e->getMessage());
    leanWebhookReply(500, 'error'); // handlers are idempotent; let Lean retry
    exit();
}

leanWebhookReply(200, 'ok');

// Sync the connection after the response has been sent (only when the SAPI can do that,
// otherwise the daily cron / "Sync now" picks the data up).
if ($toSync !== null && LeanClient::isConfigured()) {
    $finished = false;
    if (function_exists('fastcgi_finish_request')) {
        $finished = fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        $finished = true;
    }
    if ($finished) {
        ignore_user_abort(true);
        @set_time_limit(120);
        try {
            [$tenantId, $entityId] = $toSync;
            LeanSync::syncTenant($pdo, $tenantId, null, false, $entityId);
        } catch (Throwable $e) {
            error_log('Lean webhook sync: ' . $e->getMessage());
        }
    }
}
