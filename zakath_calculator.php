<?php
// zakath_calculator.php
$page_title = "Zakath Calculator";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\ZakathHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];

// Read-only members can view the tracker but not create calculations
if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
    Flash::redirect('zakath_tracker.php', 'error', 'Unauthorized: Read-only access');
}

$settings = ZakathHelper::settings($pdo, $tenant_id);
$prices   = ZakathHelper::prices($pdo, $settings);
$nisab    = ZakathHelper::nisab($settings, $prices);
$hawl     = ZakathHelper::hawl($settings['hawl_start_date']);

// ── Save ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    $num = function (string $key): float {
        $v = (float) ($_POST[$key] ?? 0);
        return is_finite($v) ? max(0.0, round($v, 2)) : 0.0;
    };

    $cycle  = mb_substr(trim($_POST['cycle_name'] ?? ''), 0, 100);
    $cash   = $num('cash_balance');
    $gold   = $num('gold_silver');
    $invest = $num('investments');
    $recv   = $num('receivables');
    $liab   = $num('liabilities');

    if ($cycle === '') {
        Flash::redirect('zakath_calculator.php', 'error', 'Please give this calculation a name.');
    }

    // Server-side calculation; the nisab comes from the server's prices, never the form.
    $calc = ZakathHelper::calculate($cash, $gold, $invest, $recv, $liab, $nisab['value']);

    try {
        $stmt = $pdo->prepare("INSERT INTO zakath_calculations
            (user_id, tenant_id, cycle_name, cash_balance, gold_silver, investments, receivables, liabilities,
             net_wealth, nisab_basis, nisab_value, gold_price_per_gram, silver_price_per_gram, total_zakath, due_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'], $tenant_id, $cycle, $cash, $gold, $invest, $recv, $liab,
            $calc['net'], $nisab['basis'], $nisab['value'],
            $prices['gold']['aed_per_gram'], $prices['silver']['aed_per_gram'],
            $calc['zakat'], $hawl['due'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Zakath calculation save failed: ' . $e->getMessage());
        Flash::redirect('zakath_calculator.php', 'error', 'Could not save the calculation. Has the Zakath database update been run?');
    }

    $msg = $calc['meets_nisab'] === false
        ? 'Saved. Your net wealth is below the nisab, so no Zakath is due for this calculation.'
        : 'Saved. Zakath due: AED ' . number_format($calc['zakat'], 2);
    Flash::redirect('zakath_tracker.php', 'success', $msg);
}

$auto = ZakathHelper::autoAssets($pdo, $tenant_id);

$default_cycle = $hawl
    ? 'Zakath ' . date('Y', strtotime($hawl['due'])) . ' (due ' . date('M d, Y', strtotime($hawl['due'])) . ')'
    : 'Ramadan ' . date('Y');

$fields = [
    'cash' => [
        'name' => 'cash_balance', 'label' => 'Cash in Hand & Bank Balances', 'auto' => $auto['cash'],
        'help' => 'Current and savings accounts, deposits and physical cash.',
    ],
    'gold' => [
        'name' => 'gold_silver', 'label' => 'Gold & Silver', 'auto' => $auto['gold_silver'],
        'help' => 'Market value of gold and silver you own (jewellery included — the cautious Hanafi view).',
    ],
    'invest' => [
        'name' => 'investments', 'label' => 'Investments & Business Assets', 'auto' => $auto['investments'],
        'help' => 'Shares, funds, crypto, business stock-in-trade. Net Worth items in the “Investment” category are pulled in.',
    ],
    'recv' => [
        'name' => 'receivables', 'label' => 'Money Owed to You', 'auto' => $auto['receivables'],
        'help' => 'Loans you expect to be repaid (Lending Tracker: Pending and Partially Paid).',
    ],
];

Layout::header();
Layout::sidebar();
?>

<style>
    .zc-input-start { border-top-left-radius: 20px; border-bottom-left-radius: 20px; }
    .zc-input-end { border-top-right-radius: 20px; border-bottom-right-radius: 20px; }
    .zc-source { font-size: 12px; }
    .zc-step { width: 24px; height: 24px; font-size: 12px; }
</style>

<div class="container-fluid py-4">
    <div class="mb-4 d-flex justify-content-between align-items-end flex-wrap gap-2">
        <div>
            <a href="zakath_tracker.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Tracker
            </a>
            <h1 class="h3 fw-bold mb-1 text-dark">Zakath Calculator</h1>
            <p class="text-muted mb-0">Values are pulled from your banks, Net Worth and Lending Tracker — review and adjust before saving</p>
        </div>
        <a href="zakath_settings.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fa-solid fa-gear me-1"></i> Nisab &amp; hawl settings
        </a>
    </div>

    <?php if (!empty($settings['_missing'])): ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">
            <i class="fa-solid fa-database me-2"></i>The Zakath database update (migrations/2026_10_05_zakath.sql) hasn't been run yet — saving won't work until it is.
        </div>
    <?php endif; ?>

    <div class="row g-4 justify-content-center">
        <div class="col-lg-8">
            <!-- Nisab & hawl summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="glass-panel-premium p-3 h-100">
                        <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px;">Nisab (<?php echo Html::e($nisab['basis']); ?>)</small>
                        <?php if ($nisab['value'] !== null): ?>
                            <h4 class="fw-bold mb-1">AED <?php echo number_format($nisab['value'], 2); ?></h4>
                            <div class="text-muted zc-source">
                                <?php echo Html::e(number_format($nisab['grams'], 2) . ' g × AED ' . number_format($nisab['price_per_gram'], 4) . '/g — ' . $prices[$nisab['basis']]['source']); ?>
                                <?php if ($prices[$nisab['basis']]['as_of']): ?>
                                    · <?php echo Html::e(date('M d, H:i', strtotime($prices[$nisab['basis']]['as_of']))); ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <h4 class="fw-bold mb-1 text-danger">Unknown</h4>
                            <div class="text-muted zc-source">No <?php echo Html::e($nisab['basis']); ?> price available. <a href="zakath_settings.php">Enter a manual price</a>.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="glass-panel-premium p-3 h-100">
                        <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px;">Hawl due date</small>
                        <?php if ($hawl): ?>
                            <h4 class="fw-bold mb-1"><?php echo Html::e(date('M d, Y', strtotime($hawl['due']))); ?></h4>
                            <div class="text-muted zc-source">
                                <?php echo (int) $hawl['days_left'] === 0 ? 'Due today' : (int) $hawl['days_left'] . ' days left'; ?>
                                <?php if ($hawl['due_hijri']): ?> · <?php echo Html::e($hawl['due_hijri']); ?><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <h4 class="fw-bold mb-1 text-muted">Not set</h4>
                            <div class="text-muted zc-source"><a href="zakath_settings.php">Set your hawl start date</a> to track the due date and get reminders.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="glass-panel-premium p-4 shadow-sm">
                <form method="POST" id="zakathForm">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">

                    <!-- Section 1 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-primary rounded-circle me-2 d-inline-flex align-items-center justify-content-center zc-step">1</span>
                            Cycle
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <label for="cycleName" class="form-label fw-bold text-muted small">Name</label>
                            <input type="text" name="cycle_name" id="cycleName" class="form-control rounded-pill px-3" maxlength="100"
                                value="<?php echo Html::e($default_cycle); ?>" required>
                            <?php if ($hawl): ?>
                                <div class="form-text mt-1 text-muted">Saved against the hawl ending <?php echo Html::e(date('M d, Y', strtotime($hawl['due']))); ?>.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Section 2 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-primary rounded-circle me-2 d-inline-flex align-items-center justify-content-center zc-step">2</span>
                            Zakatable Assets
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light d-flex flex-column gap-3">
                            <?php foreach ($fields as $id => $f): ?>
                                <div>
                                    <label for="<?php echo $id; ?>" class="form-label fw-bold text-muted small"><?php echo Html::e($f['label']); ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 px-3 text-muted zc-input-start">AED</span>
                                        <input type="number" step="0.01" min="0" name="<?php echo $f['name']; ?>" id="<?php echo $id; ?>"
                                            class="form-control calc-input border-start-0 border-end-0"
                                            value="<?php echo Html::e(number_format($f['auto']['amount'], 2, '.', '')); ?>">
                                        <button type="button" class="btn btn-outline-secondary zc-input-end" title="Reset to the auto-pulled value"
                                            data-onclick="fillAuto" data-args="<?php echo Html::args($id, $f['auto']['amount']); ?>">
                                            <i class="fa-solid fa-rotate-left me-1"></i>Auto
                                        </button>
                                    </div>
                                    <div class="text-muted zc-source mt-1"><i class="fa-solid fa-link me-1"></i>Source: <?php echo Html::e($f['auto']['source']); ?></div>
                                    <div class="form-text mt-0 text-muted"><?php echo Html::e($f['help']); ?></div>

                                    <?php if ($id === 'gold'): ?>
                                        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                                            <span class="small text-muted">Add by weight:</span>
                                            <input type="number" step="0.01" min="0" id="metalGrams" class="form-control form-control-sm rounded-pill" style="max-width: 110px;" placeholder="grams" aria-label="Weight in grams">
                                            <select id="metalPurity" class="form-select form-select-sm rounded-pill" style="max-width: 150px;" aria-label="Metal and purity">
                                                <option value="gold:1">Gold 24k</option>
                                                <option value="gold:0.9167">Gold 22k</option>
                                                <option value="gold:0.875">Gold 21k</option>
                                                <option value="gold:0.75">Gold 18k</option>
                                                <option value="silver:0.999">Silver .999</option>
                                                <option value="silver:0.925">Silver .925</option>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-onclick="addByWeight">
                                                <i class="fa-solid fa-plus me-1"></i>Add
                                            </button>
                                            <span class="small text-muted" id="weightHint"></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Section 3 -->
                    <div class="mb-4">
                        <h5 class="fw-bold mb-3 text-dark d-flex align-items-center">
                            <span class="badge bg-danger rounded-circle me-2 d-inline-flex align-items-center justify-content-center zc-step">3</span>
                            Deductible Debts
                        </h5>
                        <div class="p-3 bg-light rounded-4 border border-light">
                            <label for="liab" class="form-label fw-bold text-muted small">Debts due within the next 12 months</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 px-3 text-muted zc-input-start">AED</span>
                                <input type="number" step="0.01" min="0" name="liabilities" id="liab" class="form-control calc-input border-start-0 border-end-0"
                                    value="<?php echo Html::e(number_format($auto['liabilities']['amount'], 2, '.', '')); ?>">
                                <button type="button" class="btn btn-outline-secondary zc-input-end" title="Reset to the auto-pulled value"
                                    data-onclick="fillAuto" data-args="<?php echo Html::args('liab', $auto['liabilities']['amount']); ?>">
                                    <i class="fa-solid fa-rotate-left me-1"></i>Auto
                                </button>
                            </div>
                            <div class="text-muted zc-source mt-1"><i class="fa-solid fa-link me-1"></i>Source: <?php echo Html::e($auto['liabilities']['source']); ?></div>
                            <div class="form-text mt-0 text-muted">
                                Credit card balances, bills and loan instalments due this year. For a mortgage or long-term loan,
                                add only the next 12 months' instalments — not the whole principal. Card balances aren't pulled automatically.
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($auto['excluded'])): ?>
                        <details class="mb-4 small">
                            <summary class="text-muted">Net Worth items not counted (<?php echo count($auto['excluded']); ?>)</summary>
                            <ul class="mt-2 mb-0 text-muted">
                                <?php foreach ($auto['excluded'] as $x): ?>
                                    <li><?php echo Html::e($x['name'] . ' (' . $x['category'] . ', ' . $x['type'] . ') — AED ' . number_format((float) $x['amount'], 2)); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="mt-1 text-muted">Your home, car and other personal-use property are not zakatable. Property held for sale is — add it under Investments.</div>
                        </details>
                    <?php endif; ?>

                    <!-- Result -->
                    <div class="alert alert-info rounded-4 border-0 p-4 shadow-sm mb-4" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(16, 185, 129, 0.08));">
                        <div class="row align-items-center text-center text-md-start g-3">
                            <div class="col-md-6">
                                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px; letter-spacing: 0.5px;">Net Zakatable Wealth</small>
                                <h3 class="fw-bold text-dark mb-1">AED <span id="netAssets" class="blur-sensitive">0.00</span></h3>
                                <span id="nisabStatus" class="badge rounded-pill px-3 py-1"></span>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 11px; letter-spacing: 0.5px;">Zakath Due (2.5%)</small>
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

            <!-- How this is calculated -->
            <div class="glass-panel p-4 mt-4 small">
                <h6 class="fw-bold mb-2"><i class="fa-solid fa-circle-info me-2 text-primary"></i>How this is calculated</h6>
                <ol class="mb-2 ps-3">
                    <li><strong>Nisab</strong> = <?php echo Html::e(number_format($nisab['grams'], 2)); ?> g of <?php echo Html::e($nisab['basis']); ?> × today's price per gram.
                        Silver (612.36 g or 595 g) gives a lower threshold than gold (85 g or 87.48 g); many scholars prefer silver as safer for the poor.
                        You choose the basis and weights in <a href="zakath_settings.php">settings</a>.</li>
                    <li><strong>Prices</strong>: international spot price in USD per troy ounce, converted at 1 oz = 31.1034768 g and 1 USD = 3.6725 AED,
                        refreshed every 12 hours (or your manual price).</li>
                    <li><strong>Net zakatable wealth</strong> = cash &amp; bank + gold &amp; silver + investments + money owed to you
                        − debts due within the next 12 months.</li>
                    <li>If net wealth is <strong>at or above the nisab</strong>, Zakath is <strong>2.5% of the whole amount</strong> (not only the part above nisab).
                        Below the nisab, nothing is due.</li>
                    <li><strong>Hawl</strong>: Zakath is due once a lunar year (354 days) has passed since your wealth first reached the nisab.
                        Each due date is your hawl start date + 354 days, repeating.</li>
                </ol>
                <p class="text-muted mb-0">This is a calculation aid, not a fatwa. Rulings differ between schools (e.g. on jewellery worn for personal use
                    and on deducting long-term debts) — follow the scholar you trust and adjust the figures above accordingly.</p>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const ZAKAT_NISAB = <?php echo Html::json($nisab['value']); ?>;
    const ZAKAT_PRICES = <?php echo Html::json(['gold' => $prices['gold']['aed_per_gram'], 'silver' => $prices['silver']['aed_per_gram']]); ?>;
    const ZAKAT_RATE = <?php echo Html::json(ZakathHelper::RATE); ?>;

    document.querySelectorAll('.calc-input').forEach(function (input) {
        input.addEventListener('input', calcZakath);
    });

    function fmtMoney(n) {
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function fieldValue(id) {
        const v = parseFloat(document.getElementById(id).value);
        return (isFinite(v) && v > 0) ? v : 0;
    }

    function fillAuto(id, amount) {
        document.getElementById(id).value = Number(amount).toFixed(2);
        calcZakath();
    }

    function addByWeight() {
        const grams = parseFloat(document.getElementById('metalGrams').value);
        const parts = document.getElementById('metalPurity').value.split(':');
        const price = ZAKAT_PRICES[parts[0]];
        const hint = document.getElementById('weightHint');
        if (!isFinite(grams) || grams <= 0) {
            hint.textContent = 'Enter the weight in grams.';
            return;
        }
        if (!price) {
            hint.textContent = 'No ' + parts[0] + ' price available — set a manual price in settings.';
            return;
        }
        const value = grams * parseFloat(parts[1]) * price;
        const goldInput = document.getElementById('gold');
        goldInput.value = (fieldValue('gold') + value).toFixed(2);
        hint.textContent = 'Added AED ' + fmtMoney(value);
        document.getElementById('metalGrams').value = '';
        calcZakath();
    }

    function calcZakath() {
        const assets = fieldValue('cash') + fieldValue('gold') + fieldValue('invest') + fieldValue('recv');
        let net = assets - fieldValue('liab');
        if (net < 0) net = 0;

        const status = document.getElementById('nisabStatus');
        let zakat = Math.round(net * ZAKAT_RATE * 100) / 100;
        if (ZAKAT_NISAB === null) {
            status.className = 'badge rounded-pill px-3 py-1 bg-warning-subtle text-warning';
            status.textContent = 'Nisab unknown — threshold not checked';
        } else if (net > 0 && net >= ZAKAT_NISAB) {
            status.className = 'badge rounded-pill px-3 py-1 bg-success-subtle text-success';
            status.textContent = 'Above nisab (AED ' + fmtMoney(ZAKAT_NISAB) + ') — Zakath is due';
        } else {
            zakat = 0;
            status.className = 'badge rounded-pill px-3 py-1 bg-secondary-subtle text-secondary';
            status.textContent = 'Below nisab (AED ' + fmtMoney(ZAKAT_NISAB) + ') — no Zakath due';
        }

        document.getElementById('netAssets').textContent = fmtMoney(net);
        document.getElementById('payable').textContent = fmtMoney(zakat);
    }

    calcZakath();
</script>

<?php Layout::footer(); ?>
