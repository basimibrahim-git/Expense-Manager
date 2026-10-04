<?php
// zakath_calculator.php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\BalanceHelper;

Bootstrap::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Permission Check
if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    header("Location: zakath_tracker.php?error=" . urlencode("Unauthorized: Read-only access"));
    exit();
}

// Current bank balances (AED) for the default / Auto-Fill
$current_bank_total = 0.0;
try {
    $current_bank_total = round(BalanceHelper::totalAed($pdo, (int) $_SESSION['tenant_id']), 2);
} catch (Exception $e) {
    error_log("Zakath calculator balance lookup failed: " . $e->getMessage());
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $cycle = trim($_POST['cycle_name'] ?? '');
    $cash = floatval($_POST['cash_balance'] ?? 0);
    $gold = floatval($_POST['gold_silver'] ?? 0);
    $invest = floatval($_POST['investments'] ?? 0);
    $liab = floatval($_POST['liabilities'] ?? 0);

    // Server-side Calc
    $net_assets = ($cash + $gold + $invest) - $liab;
    $net_assets = max(0, $net_assets);
    $zakath = $net_assets * 0.025;

    if (!empty($cycle)) {
        $stmt = $pdo->prepare("INSERT INTO zakath_calculations (user_id, tenant_id, cycle_name, cash_balance, gold_silver,
            investments, liabilities, total_zakath) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['tenant_id'], $cycle, $cash, $gold, $invest, $liab, $zakath]);

        header("Location: zakath_tracker.php?success=" . urlencode("Saved Successfully"));
        exit;
    }
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <!-- Back and Header -->
    <div class="mb-4">
        <a href="zakath_tracker.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Tracker
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Zakath Calculator</h1>
        <p class="text-muted mb-0">Compute your asset categories to determine your annual obligation</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="glass-panel-premium p-4 shadow-sm">
                <form method="POST" id="zakathForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">

                    <!-- Section 1 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-primary rounded-circle me-2 d-inline-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 12px;">1</span>
                            Cycle Context
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <label for="cycleName" class="form-label fw-bold text-muted small">Cycle Identifier</label>
                            <input type="text" name="cycle_name" id="cycleName" class="form-control rounded-pill px-3"
                                placeholder="e.g. Ramadan <?php echo date('Y'); ?>" value="Ramadan <?php echo date('Y'); ?>" required>
                            <div class="form-text mt-1 text-muted">Use a descriptive label like 'Ramadan <?php echo date('Y'); ?>' to file this statement.</div>
                        </div>
                    </div>

                    <!-- Section 2 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-primary rounded-circle me-2 d-inline-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 12px;">2</span>
                            Zakatable Assets
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light d-flex flex-column gap-3">
                            <div>
                                <label for="cash" class="form-label fw-bold text-muted small">Cash in Hand & Bank Balances</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 px-3 text-muted" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                                    <input type="number" step="0.01" name="cash_balance" id="cash" class="form-control calc-input border-start-0 border-end-0" required value="<?php echo Html::e(number_format($current_bank_total, 2, '.', '')); ?>" style="outline: none;">
                                    <button type="button" class="btn btn-outline-secondary" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;"
                                        data-onclick="fillCash" data-args="<?php echo Html::args($current_bank_total); ?>">
                                        Auto-Fill (<?php echo number_format($current_bank_total); ?>)
                                    </button>
                                </div>
                                <div class="form-text mt-1 text-muted">Includes current accounts, deposits, gold-backed accounts, and physical cash.</div>
                            </div>

                            <div>
                                <label for="gold" class="form-label fw-bold text-muted small">Gold & Silver Valuations</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 px-3 text-muted" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                                    <input type="number" step="0.01" name="gold_silver" id="gold" class="form-control calc-input border-start-0" value="0" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;">
                                </div>
                                <div class="form-text mt-1 text-muted">Market value of precious jewelry and assets above Nisab.</div>
                            </div>

                            <div>
                                <label for="invest" class="form-label fw-bold text-muted small">Investments & Business Capital</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 px-3 text-muted" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                                    <input type="number" step="0.01" name="investments" id="invest" class="form-control calc-input border-start-0" value="0" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;">
                                </div>
                                <div class="form-text mt-1 text-muted">Stocks, funds, retirement pots (e.g. pension plans), or business trading assets.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-danger rounded-circle me-2 d-inline-flex align-items-center justify-content-center" style="width: 24px; height: 24px; font-size: 12px;">3</span>
                            Liabilities (Deductions)
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <label for="liab" class="form-label fw-bold text-muted small">Immediate Debts & Short-term Loans</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 px-3 text-muted" style="border-top-left-radius: 20px; border-bottom-left-radius: 20px;">AED</span>
                                <input type="number" step="0.01" name="liabilities" id="liab" class="form-control calc-input border-start-0" value="0" style="border-top-right-radius: 20px; border-bottom-right-radius: 20px;">
                            </div>
                            <div class="form-text mt-1 text-muted">Outstanding liabilities currently due that offset overall assets.</div>
                        </div>
                    </div>

                    <!-- Calculated Box -->
                    <div class="alert alert-info rounded-4 border-0 p-4 shadow-sm mb-4" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(16, 185, 129, 0.08));">
                        <div class="row align-items-center text-center text-md-start g-3">
                            <div class="col-md-6">
                                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px; letter-spacing: 0.5px;">Net Zakatable Wealth</small>
                                <h3 class="fw-bold text-dark mb-0">AED <span id="netAssets" class="blur-sensitive">0.00</span></h3>
                            </div>
                            <div class="col-md-6 text-md-end border-start-md border-light ps-md-4">
                                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px; letter-spacing: 0.5px;">Obligatory Zakath (2.5%)</small>
                                <h2 class="fw-bold text-success mb-0">AED <span id="payable" class="blur-sensitive">0.00</span></h2>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill shadow-sm hover-lift py-3">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> Save Calculation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const inputs = document.querySelectorAll('.calc-input');
    inputs.forEach(input => {
        input.addEventListener('input', calcZakath);
    });

    function fillCash(amount) {
        document.getElementById('cash').value = amount;
        calcZakath();
    }

    function calcZakath() {
        const cash = parseFloat(document.getElementById('cash').value) || 0;
        const gold = parseFloat(document.getElementById('gold').value) || 0;
        const invest = parseFloat(document.getElementById('invest').value) || 0;
        const liab = parseFloat(document.getElementById('liab').value) || 0;

        let net = (cash + gold + invest) - liab;
        if (net < 0) net = 0;

        let zakath = net * 0.025;

        document.getElementById('netAssets').innerText = net.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('payable').innerText = zakath.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    calcZakath();
</script>

<?php Layout::footer(); ?>
