$current_page = basename($_SERVER['PHP_SELF']);

// Security Headers - Prevent clickjacking with strictest X-Frame-Options
$xFrameOptionsValue = 'DENY';
header('X-Frame-Options: ' . $xFrameOptionsValue);
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: frame-ancestors 'none';");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo isset($page_title) ? $page_title . ' | ' : ''; ?>Expense Manager
    </title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <script>
        // Dynamic Theme Logic
        function applyTheme() {
            const savedTheme = localStorage.getItem('userTheme') || 'theme-afternoon';
            // We now strictly use theme-afternoon (Light) and theme-night (Dark)
            document.body.classList.remove('theme-morning', 'theme-afternoon', 'theme-night');

            let activeTheme = savedTheme;
            if (activeTheme === 'theme-morning') activeTheme = 'theme-afternoon'; // Upgrade legacy users

            document.body.classList.add(activeTheme);

            // Update toggle button text if exists
            const themeBtn = document.getElementById('themeToggleBtn');
            if (themeBtn) {
                if (activeTheme === 'theme-night') {
                    themeBtn.innerHTML = '<i class="fa-solid fa-sun me-2"></i> Light Mode';
                } else {
                    themeBtn.innerHTML = '<i class="fa-solid fa-moon me-2"></i> Dark Mode';
                }
            }
        }

        function toggleTheme() {
            const currentTheme = localStorage.getItem('userTheme') || 'theme-afternoon';
            const newTheme = (currentTheme === 'theme-night') ? 'theme-afternoon' : 'theme-night';
            localStorage.setItem('userTheme', newTheme);
            applyTheme();
        }

        // Privacy Mode Logic
        function togglePrivacy() {
            document.body.classList.toggle('privacy-mode');
            const isPrivacy = document.body.classList.contains('privacy-mode');
            localStorage.setItem('privacyMode', isPrivacy);

            // Update button icon if exists
            const btn = document.getElementById('privacyBtn');
            if (btn) {
                btn.innerHTML = isPrivacy ? '<i class="fa-solid fa-eye-slash me-2"></i> Hidden' : '<i class="fa-solid fa-eye me-2"></i> Visible';
            }
        }

        // Init on load
        window.addEventListener('DOMContentLoaded', () => {
            applyTheme();
            // Check stored privacy setting
            if (localStorage.getItem('privacyMode') === 'true') {
                document.body.classList.add('privacy-mode');
            }
        });
    </script>
</head>

<body>
    <div class="dashboard-wrapper">
