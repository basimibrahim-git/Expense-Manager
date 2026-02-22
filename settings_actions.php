<?php
require_once __DIR__ . '/vendor/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\AuditHelper;

Bootstrap::init();

const REDIRECT_LOCATION = "Location: ";
const REDIRECT_DASHBOARD = REDIRECT_LOCATION . BASE_URL . "dashboard.php";

if (!isset($_SESSION['user_id'])) {
    header(REDIRECT_LOCATION . BASE_URL . "index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action == 'toggle_currency') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $current = $_SESSION['preferences']['base_currency'] ?? 'AED';
    $new = ($current == 'AED') ? 'INR' : 'AED';

    try {
        $stmt = $pdo->prepare("UPDATE user_preferences SET base_currency = ? WHERE user_id = ?");
        $stmt->execute([$new, $user_id]);

        $_SESSION['preferences']['base_currency'] = $new;
        AuditHelper::log($pdo, 'preference_change', "Changed base currency to $new");

        header(REDIRECT_DASHBOARD);
        exit();
    } catch (Exception $e) {
        error_log("Currency toggle error: " . $e->getMessage());
        die("Update failed: A system error occurred.");
    }
} elseif ($action == 'toggle_theme') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    $current = $_SESSION['preferences']['theme_preference'] ?? 'dark';
    $new = ($current == 'dark') ? 'light' : 'dark';

    try {
        $stmt = $pdo->prepare("UPDATE user_preferences SET theme_preference = ? WHERE user_id = ?");
        $stmt->execute([$new, $user_id]);

        $_SESSION['preferences']['theme_preference'] = $new;
        header(REDIRECT_DASHBOARD);
        exit();
    } catch (Exception $e) {
        error_log("Theme toggle error: " . $e->getMessage());
        die("Update failed: A system error occurred.");
    }
}

header(REDIRECT_DASHBOARD);
exit();
