<?php
/**
 * Landing page for Lean's Open Finance redirect (success_redirect_url / fail_redirect_url).
 *
 * The bank sends the browser here cross-site, so the SameSite=Strict session cookie is NOT
 * sent with this request. This page therefore deliberately does not call Bootstrap::init()
 * (starting a session here would issue a new session cookie and log the user out). It only
 * forwards the validated query parameters to lean_connect.php with a same-site navigation,
 * which does carry the session cookie. No database access, no secrets, nothing stored.
 */

$params = ['returned' => '1'];
foreach (['entity_id', 'customer_id', 'consent_attempt_id'] as $key) {
    $v = (string) ($_GET[$key] ?? '');
    if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $v)) {
        $params[$key] = $v;
    }
}
$code = (string) ($_GET['granular_status_code'] ?? '');
if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code)) {
    $params['granular_status_code'] = $code;
}
$info = trim((string) ($_GET['status_additional_info'] ?? ''));
if ($info !== '') {
    $params['status_additional_info'] = mb_substr(preg_replace('/[^\p{L}\p{N} .,:;()\'_-]/u', '', $info), 0, 200);
}

// Relative URL: works under both "/" and "/expenses/" without knowing BASE_URL.
$target = 'lean_connect.php?' . http_build_query($params);
$safe   = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$nonce  = base64_encode(random_bytes(16));

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url=<?php echo $safe; ?>">
    <title>Returning from your bank | Expense Manager</title>
</head>
<body style="font-family: system-ui, sans-serif; text-align: center; padding: 3rem 1rem; color: #334155;">
    <p>Returning to Expense Manager…</p>
    <p><a href="<?php echo $safe; ?>">Continue</a></p>
    <script nonce="<?php echo $nonce; ?>">
        window.location.replace(<?php echo json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    </script>
</body>
</html>
