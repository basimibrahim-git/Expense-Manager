<?php
$page_title = "My Cards";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\CardCycleHelper;

Bootstrap::init();
Layout::header();
Layout::sidebar();

// Fetch cards with current month usage
try {
    $curr_month = date('n');
    $curr_year = date('Y');

    $stmt = $pdo->prepare("
        SELECT c.*,
        (SELECT SUM(amount) FROM expenses e
         WHERE e.card_id = c.id
         AND e.tenant_id = c.tenant_id
         AND MONTH(e.expense_date) = :month1
         AND YEAR(e.expense_date) = :year1) as total_expenses,
        (SELECT SUM(amount) FROM card_payments p
         WHERE p.card_id = c.id
         AND p.tenant_id = c.tenant_id
         AND MONTH(p.payment_date) = :month2
         AND YEAR(p.payment_date) = :year2) as total_payments
        FROM cards c
        WHERE c.tenant_id = :tenant_id
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([
        'tenant_id' => $_SESSION['tenant_id'],
        'month1' => $curr_month,
        'year1' => $curr_year,
        'month2' => $curr_month,
        'year2' => $curr_year
    ]);
    $cards = $stmt->fetchAll();

    // Billing cycle per credit card (only cards with a statement day)
    $cycles = [];
    foreach (CardCycleHelper::cycles($pdo, (int) $_SESSION['tenant_id']) as $cycleRow) {
        $cycles[$cycleRow['card_id']] = $cycleRow;
    }
} catch (\PDOException $e) {
    error_log("Database Error in my_cards.php: " . $e->getMessage());
    die("A system error occurred. Please contact support.");
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold mb-0">My Cards</h1>
    <div class="d-flex gap-2">
        <a href="my_banks.php" class="btn btn-outline-primary">
            <i class="fa-solid fa-landmark me-2"></i> Manage Banks
        </a>
        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
            <a href="add_card.php" class="btn btn-primary">
                <i class="fa-solid fa-plus me-2"></i> Add New Card
            </a>
        <?php endif; ?>
    </div>
</div>

<style>
    .cycle-strip { background: rgba(var(--bs-secondary-rgb), 0.06); border-radius: 0.9rem; padding: 0.75rem 0.9rem; }
    .cycle-strip .cycle-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--bs-secondary-color); font-weight: 600; }
    .cycle-strip .cycle-value { font-weight: 700; font-size: 0.9rem; }
</style>

<?php if (empty($cards)): ?>
    <div class="text-center py-5 glass-panel">
        <div class="mb-3 text-muted" style="font-size: 3rem;">
            <i class="fa-regular fa-credit-card"></i>
        </div>
        <h4>No cards added yet</h4>
        <p class="text-muted">Add your credit or debit cards to track your spending.</p>
        <a href="add_card.php" class="btn btn-primary mt-2">Add Card</a>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($cards as $card): ?>
            <?php
            $used = ($card['total_expenses'] ?: 0) - ($card['total_payments'] ?: 0);
            $limit = $card['limit_amount'] ?: 0;
            $usage_pct = $limit > 0 ? min(($used / $limit) * 100, 100) : 0;
            
            // Usage bar color logic
            $usage_color_class = 'bg-success';
            if ($usage_pct >= 85) {
                $usage_color_class = 'bg-danger';
            } elseif ($usage_pct >= 50) {
                $usage_color_class = 'bg-warning text-dark';
            }

            $f4 = $card['first_four'] ?: '****';
            $l4 = $card['last_four'] ?: '****';
            
            // Dynamic premium background logic based on card network or level
            $card_bg = "linear-gradient(135deg, #0f172a 0%, #1e293b 100%)"; // Classic Slate Dark
            if (stripos($card['tier'], 'Signature') !== false || stripos($card['tier'], 'Infinite') !== false || stripos($card['tier'], 'World') !== false) {
                $card_bg = "linear-gradient(135deg, #1e1b4b 0%, #311042 100%)"; // Deep Royal Indigo-Purple
            } elseif (stripos($card['tier'], 'Platinum') !== false) {
                $card_bg = "linear-gradient(135deg, #334155 0%, #475569 100%)"; // Metallic Platinum
            } elseif (stripos($card['card_type'], 'Debit') !== false) {
                $card_bg = "linear-gradient(135deg, #064e3b 0%, #065f46 100%)"; // Emerald Green for debit
            }
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="glass-panel-premium p-4 h-100 d-flex flex-column justify-content-between hover-lift shadow-sm">
                    <!-- Physical Mockup Area -->
                    <div class="d-flex justify-content-center mb-4">
                        <div class="credit-card-mockup" style="background: <?php echo $card_bg; ?>;">
                            <div class="credit-card-brand">
                                <?php if ($card['network'] == 'Visa'): ?>
                                    <i class="fa-brands fa-cc-visa"></i>
                                <?php elseif ($card['network'] == 'Mastercard'): ?>
                                    <i class="fa-brands fa-cc-mastercard"></i>
                                <?php else: ?>
                                    <i class="fa-brands fa-cc-amex"></i>
                                <?php endif; ?>
                            </div>
                            <div class="credit-card-chip"></div>
                            <div class="credit-card-number">
                                <?php echo Html::e($f4); ?> **** **** <?php echo Html::e($l4); ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-end mt-2">
                                <div>
                                    <div class="credit-card-holder mb-0" style="font-size: 0.85rem; font-weight: 600; text-shadow: 1px 1px 2px rgba(0,0,0,0.5);">
                                        <?php echo Html::e($card['card_name']); ?>
                                    </div>
                                    <small style="font-size: 0.65rem; opacity: 0.7; letter-spacing: 0.5px; text-transform: uppercase;">
                                        <?php echo Html::e($card['bank_name']); ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span style="font-size: 0.6rem; opacity: 0.7; display: block;">TIER</span>
                                    <span class="fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px; opacity: 0.9; text-transform: uppercase;">
                                        <?php echo Html::e($card['tier'] ?: 'Standard'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php $cy = $cycles[(int) $card['id']] ?? null; ?>
                    <?php if ($cy !== null): ?>
                    <?php
                    $utilPct   = $cy['utilization_pct'];
                    $utilColor = CardCycleHelper::utilizationColor($utilPct);
                    $stColor   = CardCycleHelper::statusColor($cy['status']);
                    if ($cy['status'] === 'paid') {
                        $dueBadge = 'Paid';
                    } elseif ($cy['due_remaining'] <= 0) {
                        $dueBadge = 'Nothing due';
                    } else {
                        $dueBadge = CardCycleHelper::dueLabel($cy['days_to_due']);
                    }
                    ?>
                    <!-- Billing cycle strip -->
                    <div class="cycle-strip mb-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="cycle-label">Next statement</div>
                                <div class="cycle-value"><?php echo Html::e(date('d M', strtotime($cy['current_cycle_end']))); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="cycle-label">Payment due</div>
                                <div class="cycle-value">
                                    <?php echo $cy['last_statement_due_date'] ? Html::e(date('d M', strtotime($cy['last_statement_due_date']))) : '—'; ?>
                                    <span class="badge rounded-pill bg-<?php echo $stColor; ?><?php echo $stColor === 'warning' ? ' text-dark' : ''; ?> ms-1"><?php echo Html::e($dueBadge); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-end mb-2">
                            <div>
                                <div class="cycle-label">Due remaining</div>
                                <div class="cycle-value blur-sensitive <?php echo $cy['due_remaining'] > 0 ? 'text-' . $stColor : 'text-success'; ?>">AED <?php echo number_format($cy['due_remaining'], 2); ?></div>
                            </div>
                            <div class="text-end small text-muted blur-sensitive">
                                Statement <?php echo Html::e(date('d M', strtotime($cy['last_statement_date']))); ?>: AED <?php echo number_format($cy['last_statement_amount'], 2); ?>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="cycle-label">Utilization</span>
                            <span class="small fw-bold blur-sensitive">
                                <?php echo $utilPct !== null ? number_format($utilPct, 1) . '%' : 'No limit set'; ?>
                                <span class="text-muted fw-normal">· AED <?php echo number_format($cy['outstanding_estimate'], 2); ?> / <?php echo number_format($cy['limit_amount'], 0); ?></span>
                            </span>
                        </div>
                        <div class="progress mb-2" style="height: 6px;" title="Estimated outstanding (all card spend minus all payments) vs. limit">
                            <div class="progress-bar bg-<?php echo $utilColor; ?>" role="progressbar" style="width: <?php echo $utilPct !== null ? min($utilPct, 100) : 0; ?>%;"></div>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">This cycle: <span class="fw-bold text-body blur-sensitive">AED <?php echo number_format($cy['current_cycle_spend'], 2); ?></span></span>
                            <span class="text-muted" title="Expected from the card's cashback rates vs. recorded on expenses">
                                <i class="fa-solid fa-gift text-success me-1"></i><span class="blur-sensitive"><?php echo number_format($cy['cashback_expected'], 2); ?> / <?php echo number_format($cy['cashback_recorded'], 2); ?></span>
                            </span>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php if ($card['card_type'] === 'Credit' && ($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <div class="small text-muted mb-2">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            <a href="edit_card.php?id=<?php echo (int) $card['id']; ?>">Set a statement day</a> to track billing cycles and due dates.
                        </div>
                    <?php endif; ?>
                    <!-- Usage Details -->
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Card Utilization</span>
                            <span class="badge rounded-pill <?php echo $usage_pct >= 85 ? 'bg-danger' : ($usage_pct >= 50 ? 'bg-warning text-dark' : 'bg-success'); ?> small">
                                <?php echo number_format($usage_pct, 1); ?>%
                            </span>
                        </div>
                        <div class="progress mb-3" style="height: 6px;">
                            <div class="progress-bar <?php echo $usage_color_class; ?>" role="progressbar" style="width: <?php echo min($usage_pct, 100); ?>%;"></div>
                        </div>

                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <span class="text-muted small d-block mb-1">Spent / Outstanding</span>
                                <span class="fw-bold text-dark blur-sensitive">AED <?php echo number_format($used, 2); ?></span>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small d-block mb-1">Total Credit Limit</span>
                                <span class="fw-bold text-secondary blur-sensitive">AED <?php echo number_format($limit, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Card Actions -->
                    <div class="d-flex gap-2 justify-content-end pt-3 border-top">
                        <a href="view_card.php?id=<?php echo (int) $card['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Details & billing cycle">
                            <i class="fa-solid fa-eye"></i>
                        </a>
                        <?php if (!empty($card['bank_url']) && preg_match('#^https?://#i', $card['bank_url'])): ?>
                            <a href="<?php echo Html::e($card['bank_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="Visit Bank Site">
                                <i class="fa-solid fa-external-link-alt"></i> Bank
                            </a>
                        <?php endif; ?>
                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                            <a href="pay_card.php?card_id=<?php echo (int) $card['id']; ?>" class="btn btn-sm btn-success text-white rounded-pill px-3">
                                <i class="fa-solid fa-receipt me-1"></i> Pay
                            </a>
                            <a href="edit_card.php?id=<?php echo (int) $card['id']; ?>" class="btn btn-sm btn-light text-muted rounded-pill px-3" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <form action="card_actions.php" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete_card">
                                <input type="hidden" name="id" value="<?php echo (int) $card['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-light text-danger rounded-pill px-3" title="Delete" data-confirm="<?php echo Html::e('Delete ' . $card['bank_name'] . ' ' . $card['card_name'] . '?'); ?>">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small align-self-center"><i class="fa-solid fa-lock me-1"></i> Read Only</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php Layout::footer(); ?>
