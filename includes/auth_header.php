<?php
// Shared layout for public (logged-out) pages: login, signup, forgot/reset password.
$asset_version = $_ENV['APP_VERSION'] ?? '1.0.0';

$csp_nonce = base64_encode(random_bytes(16));
$GLOBALS['csp_nonce'] = $csp_nonce;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' cdn.jsdelivr.net 'nonce-{$csp_nonce}'; style-src 'self' cdn.jsdelivr.net 'unsafe-inline'; font-src 'self' cdn.jsdelivr.net; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
header('Referrer-Policy: no-referrer'); // reset links carry a token in the URL
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($auth_title); ?> | Expense Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=<?php echo htmlspecialchars($asset_version); ?>">
</head>

<body>
    <div class="container-fluid">
        <div class="auth-wrapper">
            <div class="glass-panel auth-card">
                <div class="text-center mb-4">
                    <div class="brand-logo justify-content-center mb-0">
                        <i class="fa-solid fa-wallet"></i> ExpenseMngr
                    </div>
                </div>
                <?php echo App\Helpers\Flash::render(); ?>
