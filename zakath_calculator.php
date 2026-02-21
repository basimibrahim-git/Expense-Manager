<?php
// zakath_calculator.php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Permission Check
if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    header("Location: zakath_tracker.php?error=Unauthorized: Read-only access");
    exit();
}

// Fetch Current Bank Balance for Auto-Fill
$current_bank_total = 0;
try {
    // Sum latest balance of all banks
    $stmt = $pdo->prepare("
        SELECT SUM(
            CASE
                WHEN currency = 'INR' THEN amount / 24
                ELSE amount
            END
        )
        FROM bank_balances b1
        WHERE tenant_id = ?
        AND id = (SELECT MAX(id) FROM bank_balances b2 WHERE b2.bank_name = b1.bank_name AND b2.tenant_id = b1.tenant_id)
    ");
    $stmt->execute([$_SESSION['tenant_id']]);
    $current_bank_total = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) { /* Ignore */
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $cycle = $_POST['cycle_name'];
    $cash = floatval($_POST['cash_balance']);
    $gold = floatval($_POST['gold_silver']);
    $invest = floatval($_POST['investments']);
    $liab = floatval($_POST['liabilities']);

    // Server-side Calc
    $net_assets = ($cash + $gold + $invest) - $liab;
    $net_assets = max(0, $net_assets);
    $zakath = $net_assets * 0.025;

    if (!empty($cycle)) {
        $stmt = $pdo->prepare("INSERT INTO zakath_calculations (user_id, tenant_id, cycle_name, cash_balance, gold_silver,
            investments, liabilities, total_zakath) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $cycle, $cash, $gold, $invest, $liab, $zakath]);

        header("Location: zakath_tracker.php?success=Saved Successfully");
        exit;
    }
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="zakath_tracker.php" class="text-decoration-none text-muted small mb-1">
            <i class="fa-solid fa-arrow-left"></i> Back to Tracker
        </a>
        <h1 class="h3 fw-bold mb-0">Zakath Calculator</h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="glass-panel p-4">
            <form method="POST" id="zakathForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">1. Cycle Information</h5>
                <div class="mb-4">
                    <label for="cycleName" class="form-label fw-bold">Cycle Name</label>
                    <input type="text" name="cycle_name" id="cycleName" class="form-control form-control-lg"
                        placeholder="e.g. Ramadan <?php echo date('Y'); ?>" value="Ramadan <?php echo date('Y'); ?>"
                        required>
                    <div class="form-text">Give this calculation a name to identify it later.</div>
                </div>

                <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">2. Zakatable Assets</h5>

                <div class="mb-3">
                    <label for="cash" class="form-label">Cash in Hand & Bank Balances</label>
                    <div class="input-group">
                        <span class="input-group-text">AED</span>
                        <input type="number" step="0.01" name="cash_balance" id="cash" class="form-control calc-input"
                            required value="0">
                        <button type="button" class="btn btn-outline-secondary"
                            onclick="document.getElementById('cash').value = <?php echo $current_bank_total; ?>; calcZakath();">
                            Auto-Fill (<?php echo number_format($current_bank_total); ?>)
                        </button>
                    </div>
                    <div class="form-text">Includes savings, current accounts, and cash on hand.</div>
                </div>

                <div class="mb-3">
                    <label for="gold" class="form-label">Gold & Silver Value</label>
                    <div class="input-group">
                        <span class="input-group-text">AED</span>
                        <input type="number" step="0.01" name="gold_silver" id="gold" class="form-control calc-input"
                            value="0">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="invest" class="form-label">Investments & Business Assets</label>
                    <div class="input-group">
                        <span class="input-group-text">AED</span>
                        <input type="number" step="0.01" name="investments" id="invest" class="form-control calc-input"
                            value="0">
                    </div>
                    <div class="form-text">Stocks, shares, or business goods for sale.</div>
                </div>

                <h5 class="fw-bold mb-4 text-danger border-bottom pb-2 mt-5">3. Liabilities</h5>

                <div class="mb-3">
                    <label for="liab" class="form-label">Immediate Debts / Loans</label>
                    <div class="input-group">
                        <span class="input-group-text">AED</span>
                        <input type="number" step="0.01" name="liabilities" id="liab" class="form-control calc-input"
                            value="0">
                    </div>
                    <div class="form-text">Debts due immediately that reduce your net wealth.</div>
                </div>

                <div class="alert alert-info mt-5 border-0 shadow-sm">
                    <div class="row align-items-center text-center text-md-start">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <small class="text-uppercase text-muted fw-bold ls-1">Net Zakatable Assets</small>
                            <h3 class="fw-bold text-dark mb-0">AED <span id="netAssets">0.00</span></h3>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <small class="text-uppercase text-muted fw-bold ls-1">Zakath Payable (2.5%)</small>
                            <h2 class="fw-bold text-primary mb-0">AED <span id="payable">0.00</span></h2>
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold">
                        <i class="fa-solid fa-save me-2"></i> Save Calculation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const inputs = document.querySelectorAll('.calc-input');
    inputs.forEach(input => {
        input.addEventListener('input', calcZakath);
    });

    function calcZakath() {
        // Get values
        const cash = parseFloat(document.getElementById('cash').value) || 0;
        const gold = parseFloat(document.getElementById('gold').value) || 0;
        const invest = parseFloat(document.getElementById('invest').value) || 0;
        const liab = parseFloat(document.getElementById('liab').value) || 0;

        // Calc Net
        let net = (cash + gold + invest) - liab;
        if (net < 0) net = 0;

        // Calc Zakath (2.5%)
        let zakath = net * 0.025;

        // Update DOM
        document.getElementById('netAssets').innerText = net.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('payable').innerText = zakath.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Run on load
    calcZakath();
</script>

<?php require_once 'includes/footer.php'; ?>
