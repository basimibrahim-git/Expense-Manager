<?php
require_once 'config.php'; // NOSONAR

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$tenant_id = $_SESSION['tenant_id'];
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?? date('n');
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?? date('Y');
$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Fetch Data
$stmt = $pdo->prepare("SELECT SUM(amount) FROM income WHERE tenant_id = ? AND MONTH(income_date) = ? AND YEAR(income_date) = ?");
$stmt->execute([$tenant_id, $month, $year]);
$total_income = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ?");
$stmt->execute([$tenant_id, $month, $year]);
$total_expense = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? ORDER BY expense_date ASC");
$stmt->execute([$tenant_id, $month, $year]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE tenant_id = ? AND MONTH(expense_date) = ? AND YEAR(expense_date) = ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$tenant_id, $month, $year]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Statement -
        <?php echo $month_name . ' ' . $year; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            color: #333;
        }

        .statement-header {
            border-bottom: 3px solid #0d6efd;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                padding: 20px;
            }

            .glass-panel {
                border: 1px solid #ddd !important;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="container my-5">
        <div class="no-print mb-4">
            <button onclick="window.print()" class="btn btn-primary">Print to PDF</button>
            <a href="monthly_expenses.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>"
                class="btn btn-outline-secondary">Back</a>
        </div>

        <div class="statement-header d-flex justify-content-between align-items-end">
            <div>
                <h1 class="fw-bold text-primary mb-1">Monthly Statement</h1>
                <p class="text-muted mb-0">
                    <?php echo $month_name . ' ' . $year; ?> | User:
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </p>
            </div>
            <div class="text-end text-muted small">
                Generated on
                <?php echo date('d M Y, H:i'); ?>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-4">
                <div class="stat-box">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Total Income</div>
                    <div class="h3 fw-bold text-success mb-0">AED
                        <?php echo number_format($total_income, 2); ?>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-box">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Total Expenses</div>
                    <div class="h3 fw-bold text-danger mb-0">AED
                        <?php echo number_format($total_expense, 2); ?>
                    </div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-box">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Net Savings</div>
                    <div class="h3 fw-bold text-primary mb-0">AED
                        <?php echo number_format($total_income - $total_expense, 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-5">
            <div class="col-6">
                <h5 class="fw-bold mb-3">Spending by Category</h5>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat):
                            $pct = ($total_expense > 0) ? ($cat['total'] / $total_expense) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </td>
                                <td class="text-end">AED
                                    <?php echo number_format($cat['total'], 2); ?>
                                </td>
                                <td class="text-end text-muted">
                                    <?php echo number_format($pct, 1); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <h5 class="fw-bold mb-3">Transaction Detail</h5>
        <table class="table table-striped table-sm" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Method</th>
                    <th class="text-end">Amount (AED)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td>
                            <?php echo date('d M', strtotime($e['expense_date'])); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($e['description']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($e['category']); ?>
                        </td>
                        <td>
                            <?php echo $e['payment_method']; ?>
                        </td>
                        <td class="text-end fw-bold">
                            <?php echo number_format($e['amount'], 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-5 pt-5 text-center text-muted small border-top">
            This is a computer-generated report from Antigravity Expense Manager.
        </div>
    </div>
</body>

</html>