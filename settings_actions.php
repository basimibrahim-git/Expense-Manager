<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action == 'toggle_currency') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $current = $_SESSION['preferences']['base_currency'] ?? 'AED';
    $new = ($current == 'AED') ? 'INR' : 'AED';

    try {
        $stmt = $pdo->prepare("UPDATE user_preferences SET base_currency = ? WHERE user_id = ?");
        $stmt->execute([$new, $user_id]);

        $_SESSION['preferences']['base_currency'] = $new;
        log_audit('preference_change', "Changed base currency to $new");

        header("Location: " . ($_SERVER['HTTP_REFERER'] ?: BASE_URL . 'dashboard.php'));
        exit();
    } catch (Exception $e) {
        die("Update failed: " . $e->getMessage());
    }
} elseif ($action == 'toggle_theme') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $current = $_SESSION['preferences']['theme_preference'] ?? 'dark';
    $new = ($current == 'dark') ? 'light' : 'dark';

    try {
        $stmt = $pdo->prepare("UPDATE user_preferences SET theme_preference = ? WHERE user_id = ?");
        $stmt->execute([$new, $user_id]);

        $_SESSION['preferences']['theme_preference'] = $new;
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?: BASE_URL . 'dashboard.php'));
        exit();
    } catch (Exception $e) {
        die("Update failed: " . $e->getMessage());
    }
}

header("Location: " . BASE_URL . "dashboard.php");
exit();
