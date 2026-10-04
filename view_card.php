<?php
$page_title = "Card Details";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\CardCycleHelper;

Bootstrap::init();

// Get Card ID
$card_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$card_id) {
    header('Location: my_cards.php');
    exit;
}

// Fetch Card Data
$stmt = $pdo->prepare("SELECT * FROM cards WHERE id = :id AND tenant_id = :tenant_id");
$stmt->execute(['id' => $card_id, 'tenant_id' => $_SESSION['tenant_id']]);
$card = $stmt->fetch();

if (!$card) {
    header('Location: my_cards.php');
    exit;
}

// Billing cycle (credit cards with a statement day)
$cycle = CardCycleHelper::forCard($pdo, (int) $_SESSION['tenant_id'], (int) $card['id']);
$cycle_txn_count = 0;
if ($cycle !== null) {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE tenant_id = ? AND card_id = ? AND expense_date > ? AND expense_date <= ?");
    $cnt->execute([$_SESSION['tenant_id'], $card['id'], $cycle['last_statement_date'], $cycle['current_cycle_end']]);
    $cycle_txn_count = (int) $cnt->fetchColumn();
}
$can_edit = ($_SESSION['permission'] ?? 'edit') !== 'read_only';

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">Card Details</h1>
    <div>
        <a href="my_cards.php" class="btn btn-light me-2">
            <i class="fa-solid fa-arrow-left me-2"></i> Back
        </a>
        <?php if ($can_edit): ?>
        <a href="edit_card.php?id=<?php echo (int) $card['id']; ?>" class="btn btn-primary">
            <i class="fa-solid fa-edit me-2"></i> Edit
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Card Visual -->
    <div class="col-md-5 col-lg-4">
        <div class="card border-0 shadow-sm text-white mb-4"
            style="background: linear-gradient(45deg, #1e1e1e, #3a3a3a); border-radius: 16px; min-height: 220px; position: relative; overflow: hidden;">

            <div
                style="position: absolute; top: -20px; right: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;">
            </div>
            <div
                style="position: absolute; bottom: -40px; left: -20px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%;">
            </div>

            <div class="card-body d-flex flex-column justify-content-between p-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-0 fw-bold">
                            <?php echo Html::e($card['bank_name']); ?>
                        </h4>
                        <small class="text-white-50">
                            <?php echo Html::e($card['card_name']); ?>
                        </small>
                    </div>
                    <?php if ($card['network'] == 'Visa'): ?>
                        <i class="fa-brands fa-cc-visa fa-3x"></i>
                    <?php elseif ($card['network'] == 'Mastercard'): ?>
                        <i class="fa-brands fa-cc-mastercard fa-3x"></i>
                    <?php else: ?>
                        <i class="fa-brands fa-cc-amex fa-3x"></i>
                    <?php endif; ?>
                </div>

                <div class="mt-4">
                    <div class="h4 mb-1" style="letter-spacing: 3px;">**** **** **** ****</div>
                    <small class="text-white-50">
                        <?php echo Html::e($card['tier']); ?>
                    </small>
                </div>

                <div class="d-flex justify-content-between align-items-end mt-3">
                    <div>
                        <small class="text-white-50 d-block">Limit</small>
                        <span class="fw-bold fs-5">
                            <?php echo Html::e($card['currency'] ?: 'AED') . ' ' . number_format((float) $card['limit_amount'], 2); ?>
                        </span>
                    </div>
                    <span class="badge bg-white text-dark">
                        <?php echo Html::e($card['card_type']); ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($card['bank_url']) && preg_match('#^https?://#i', $card['bank_url'])): ?>
            <div class="d-grid">
                <a href="<?php echo Html::e($card['bank_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="btn btn-outline-primary py-3">
                    <i class="fa-solid fa-external-link-alt me-2"></i> Visit Bank Website
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details Section -->
    <div class="col-md-7 col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h5 class="fw-bold mb-4"><i class="fa-solid fa-gift me-2 text-primary"></i> Offers & Features</h5>

            <?php if (!empty($card['features'])): ?>
                <div class="p-3 bg-light rounded-3 border" style="white-space: pre-wrap; line-height: 1.6;">
                    <?php echo Html::e($card['features']); ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-clipboard-list fa-3x mb-3 opacity-25"></i>
                    <p>No offers or features added yet.</p>
                    <a href="edit_card.php?id=<?php echo (int) $card['id']; ?>" class="btn btn-sm btn-outline-primary">Add
                        Details</a>
                </div>
            <?php endif; ?>

            <hr class="my-4">

            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Card Type</div>
                    <div class="fw-bold">
                        <?php echo Html::e($card['card_type']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Network</div>
                    <div class="fw-bold">
                        <?php echo Html::e($card['network']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Currency</div>
                    <div class="fw-bold">
                        <?php echo Html::e($card['currency']); ?>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="text-muted small">Added On</div>
                    <div class="fw-bold">
                        <?php echo date('d M Y', strtotime($card['created_at'])); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($card['card_type'] === 'Credit'): ?>
<!-- Billing cycle -->
<div class="glass-panel p-4 mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-calendar-days me-2 text-primary"></i> Billing cycle</h5>
        <?php if ($cycle !== null && $can_edit): ?>
            <a href="pay_card.php?card_id=<?php echo (int) $card['id']; ?>" class="btn btn-success rounded-pill px-4">
                <i class="fa-solid fa-receipt me-1"></i> Pay now<?php echo $cycle['due_remaining'] > 0 ? ' · AED ' . number_format($cycle['due_remaining'], 2) : ''; ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($cycle === null): ?>
        <p class="text-muted mb-0">
            <i class="fa-solid fa-circle-info me-1"></i> No statement day is set for this card.
            <?php if ($can_edit): ?><a href="edit_card.php?id=<?php echo (int) $card['id']; ?>">Add the statement and payment due days</a> to see cycles and due dates.<?php endif; ?>
        </p>
    <?php else: ?>
        <?php
        $stColor = CardCycleHelper::statusColor($cycle['status']);
        $stLabel = ['overdue' => 'Overdue', 'due_soon' => 'Due soon', 'paid' => 'Paid', 'ok' => ($cycle['due_remaining'] > 0 ? 'Open' : 'Nothing due')][$cycle['status']];
        $fmt = function (?string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; };
        ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="p-3 rounded-4 border h-100">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Current cycle</div>
                    <div class="small text-muted mb-2"><?php echo Html::e($fmt($cycle['current_cycle_start'])); ?> → <?php echo Html::e($fmt($cycle['current_cycle_end'])); ?></div>
                    <div class="fs-4 fw-bold blur-sensitive">AED <?php echo number_format($cycle['current_cycle_spend'], 2); ?></div>
                    <div class="small text-muted"><?php echo $cycle_txn_count; ?> transaction<?php echo $cycle_txn_count === 1 ? '' : 's'; ?></div>
                    <div class="small mt-2">
                        <i class="fa-solid fa-gift text-success me-1"></i> Cashback expected
                        <span class="fw-bold blur-sensitive">AED <?php echo number_format($cycle['cashback_expected'], 2); ?></span>,
                        recorded <span class="fw-bold blur-sensitive">AED <?php echo number_format($cycle['cashback_recorded'], 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-4 border h-100">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Previous statement</div>
                    <div class="small text-muted mb-2">Closed <?php echo Html::e($fmt($cycle['last_statement_date'])); ?></div>
                    <div class="fs-4 fw-bold blur-sensitive">AED <?php echo number_format($cycle['last_statement_amount'], 2); ?></div>
                    <div class="small text-muted">
                        Due <?php echo Html::e($fmt($cycle['last_statement_due_date'])); ?>
                        <span class="badge rounded-pill bg-<?php echo $stColor; ?><?php echo $stColor === 'warning' ? ' text-dark' : ''; ?> ms-1"><?php echo Html::e($stLabel); ?></span>
                    </div>
                    <?php if ($cycle['due_remaining'] > 0 && $cycle['days_to_due'] !== null): ?>
                        <div class="small mt-1 text-<?php echo $stColor === 'secondary' ? 'muted' : $stColor; ?>"><?php echo Html::e(CardCycleHelper::dueLabel($cycle['days_to_due'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-4 border h-100">
                    <div class="text-muted small fw-bold text-uppercase mb-1">Payments since statement</div>
                    <div class="small text-muted mb-2">After <?php echo Html::e($fmt($cycle['last_statement_date'])); ?></div>
                    <div class="fs-4 fw-bold text-success blur-sensitive">AED <?php echo number_format($cycle['paid_since_statement'], 2); ?></div>
                    <div class="small">
                        Still due: <span class="fw-bold blur-sensitive <?php echo $cycle['due_remaining'] > 0 ? 'text-danger' : 'text-success'; ?>">AED <?php echo number_format($cycle['due_remaining'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php $util = $cycle['utilization_pct']; $utilColor = CardCycleHelper::utilizationColor($util); ?>
        <div class="mt-4">
            <div class="d-flex justify-content-between small mb-1">
                <span class="fw-bold text-muted">Utilization (estimated outstanding vs. limit)</span>
                <span class="fw-bold blur-sensitive"><?php echo $util !== null ? number_format($util, 1) . '%' : 'No limit set'; ?> · AED <?php echo number_format($cycle['outstanding_estimate'], 2); ?> / <?php echo number_format($cycle['limit_amount'], 2); ?></span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-<?php echo $utilColor; ?>" role="progressbar" style="width: <?php echo $util !== null ? min($util, 100) : 0; ?>%;"></div>
            </div>
            <div class="form-text">Outstanding is all spend recorded on this card minus all payments recorded, so it only reflects what was entered in the app.</div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php Layout::footer(); ?>
