<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
}

if ($action == 'add_balance' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $bank_id = filter_input(INPUT_POST, 'bank_id', FILTER_VALIDATE_INT);
    $amount = floatval($_POST['amount']);
    $currency = $_POST['currency'] ?? 'AED';
    $date = htmlspecialchars($_POST['balance_date']);

    if (!$bank_id) {
        header("Location: add_balance.php?error=Select a bank");
        exit();
    }

    // Fetch bank name
    $bstmt = $pdo->prepare("SELECT bank_name FROM banks WHERE id = ? AND user_id = ?");
    $bstmt->execute([$bank_id, $user_id]);
    $bank_name = $bstmt->fetchColumn();


    try {
        $stmt = $pdo->prepare("INSERT INTO bank_balances (user_id, bank_id, bank_name, amount, balance_date, currency) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $bank_id, $bank_name, $amount, $date, $currency]);

        $month = date('n', strtotime($date));
        $year = date('Y', strtotime($date));
        header("Location: monthly_balances.php?month=$month&year=$year&success=Balance Added");
        exit();
    } catch (PDOException $e) {
        header("Location: add_balance.php?error=Failed to add balance");
        exit();
    }

} elseif ($action == 'delete_balance' && isset($_GET['id'])) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM bank_balances WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }
    header("Location: bank_balances.php?success=Deleted");
    exit();
}

header("Location: bank_balances.php");
exit();
?>