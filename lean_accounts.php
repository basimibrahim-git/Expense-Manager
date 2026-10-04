<?php
/**
 * Open Banking overview: connected banks (Lean entities), their accounts, the bank each
 * account feeds its balance into, last sync time, Sync now and Disconnect.
 */
$page_title = "Open Banking";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;

Bootstrap::init();

$tenant_id  = (int) $_SESSION['tenant_id'];
$can_edit   = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
$can_manage = $can_edit && LeanSync::isAdmin();
$configured = LeanClient::isConfigured();
$tables     = LeanSync::tablesReady($pdo);

$entities = [];
$accounts_by_entity = [];
$banks = [];
$new_count = 0;
if ($tables) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM lean_entities WHERE tenant_id = ? AND status <> 'disconnected' ORDER BY created_at ASC, id ASC");
        $stmt->execute([$tenant_id]);
        $entities = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT a.*, b.bank_name AS linked_bank_name
               FROM lean_accounts a
               LEFT JOIN banks b ON b.id = a.bank_id AND b.tenant_id = a.tenant_id
              WHERE a.tenant_id = ?
              ORDER BY a.display_name ASC"
        );
        $stmt->execute([$tenant_id]);
        foreach ($stmt->fetchAll() as $a) {
            $accounts_by_entity[$a['entity_id']][] = $a;
        }

        $stmt = $pdo->prepare("SELECT id, bank_name, COALESCE(currency, 'AED') AS currency FROM banks WHERE tenant_id = ? ORDER BY bank_name ASC");
        $stmt->execute([$tenant_id]);
        $banks = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lean_transactions WHERE tenant_id = ? AND status = 'new'");
        $stmt->execute([$tenant_id]);
        $new_count = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('lean_accounts: ' . $e->getMessage());
    }
}

$status_badges = [
    'active'             => ['bg-success', 'Connected'],
    'pending'            => ['bg-info text-dark', 'Preparing data'],
    'reconnect_required' => ['bg-warning text-dark', 'Reconnect needed'],
    'consent_expired'    => ['bg-warning text-dark', 'Consent expired'],
];

function leanWhen(?string $ts): string
{
    return $ts ? date('d M Y, H:i', strtotime($ts)) : 'Never';
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <div class="row align-items-center mb-4 g-3">
        <div class="col-lg-6">
            <h1 class="h3 fw-bold mb-1 text-dark">Open Banking</h1>
            <p class="text-muted mb-0">Bank accounts linked through Lean. Balances sync daily; transactions wait for your review.</p>
        </div>
        <?php if ($configured && $tables): ?>
        <div class="col-lg-6">
            <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                <a href="lean_transactions.php" class="btn btn-outline-primary rounded-pill px-4 shadow-sm hover-lift">
                    <i class="fa-solid fa-list-check me-1"></i> Review transactions
                    <?php if ($new_count > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?php echo $new_count; ?></span><?php endif; ?>
                </a>
                <?php if ($can_edit && $entities): ?>
                    <form method="POST" action="lean_actions.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="sync">
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm hover-lift">
                            <i class="fa-solid fa-rotate me-1"></i> Sync now
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($can_manage): ?>
                    <a href="lean_connect.php" class="btn btn-primary rounded-pill px-4 shadow-sm hover-lift">
                        <i class="fa-solid fa-link me-1"></i> Connect a bank
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$configured || !$tables): ?>
        <div class="glass-panel-premium p-5 text-center mx-auto" style="max-width: 640px;">
            <i class="fa-solid fa-plug-circle-xmark fa-3x text-muted opacity-50 mb-3"></i>
            <h5 class="fw-bold text-dark">Open banking is not configured</h5>
            <p class="text-muted small mb-3">The administrator has not set up the Lean integration for this site yet. Balances can still be recorded manually.</p>
            <a href="my_banks.php" class="btn btn-outline-primary rounded-pill px-4">Go to My Banks</a>
        </div>

    <?php elseif (empty($entities)): ?>
        <div class="glass-panel-premium p-5 text-center mx-auto" style="max-width: 640px;">
            <i class="fa-solid fa-building-columns fa-3x text-muted opacity-25 mb-3"></i>
            <h5 class="fw-bold text-dark">No banks connected yet</h5>
            <p class="text-muted small mb-3">Connect a UAE bank to keep balances up to date automatically and import transactions in a few clicks.</p>
            <?php if ($can_manage): ?>
                <a href="lean_connect.php" class="btn btn-primary rounded-pill px-4"><i class="fa-solid fa-link me-1"></i> Connect a bank</a>
            <?php else: ?>
                <p class="text-muted small mb-0">Ask your family admin to connect a bank.</p>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php foreach ($entities as $entity): ?>
            <?php
            [$badge_class, $badge_text] = $status_badges[$entity['status']] ?? ['bg-secondary', ucfirst((string) $entity['status'])];
            $accounts = $accounts_by_entity[$entity['entity_id']] ?? [];
            ?>
            <div class="glass-panel-premium p-4 mb-4 shadow-sm">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                            <i class="fa-solid fa-building-columns"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark"><?php echo Html::e($entity['bank_name'] ?: 'Bank'); ?>
                                <span class="badge rounded-pill <?php echo $badge_class; ?> ms-2 small"><?php echo Html::e($badge_text); ?></span>
                            </h5>
                            <small class="text-muted">Connected <?php echo Html::e(date('d M Y', strtotime($entity['created_at']))); ?> · Last sync <?php echo Html::e(leanWhen($entity['last_synced_at'])); ?></small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($can_manage && in_array($entity['status'], ['reconnect_required', 'consent_expired'], true)): ?>
                            <a href="lean_connect.php" class="btn btn-sm btn-warning rounded-pill px-3"><i class="fa-solid fa-plug me-1"></i> Reconnect</a>
                        <?php endif; ?>
                        <?php if ($can_manage): ?>
                            <form method="POST" action="lean_actions.php"
                                  data-confirm="<?php echo Html::e('Disconnect ' . ($entity['bank_name'] ?: 'this bank') . '? Balances stop syncing and unreviewed transactions are removed. Recorded balances and imported entries are kept.'); ?>"
                                  data-confirm-btn="Disconnect">
                                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                <input type="hidden" name="action" value="disconnect">
                                <input type="hidden" name="entity_row_id" value="<?php echo (int) $entity['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fa-solid fa-link-slash me-1"></i> Disconnect</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($entity['last_error'])): ?>
                    <div class="alert alert-warning rounded-4 small py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo Html::e($entity['last_error']); ?></div>
                <?php endif; ?>

                <?php if (empty($accounts)): ?>
                    <p class="text-muted small mb-0">No accounts received yet. They appear after the first successful sync.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="small text-muted text-uppercase">
                                    <th>Account</th>
                                    <th class="text-end">Balance</th>
                                    <th>Last sync</th>
                                    <th style="min-width: 280px;">Feeds balance of</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo Html::e($acc['display_name'] ?: 'Account'); ?></div>
                                            <small class="text-muted"><?php echo Html::e(implode(' · ', array_filter([(string) $acc['masked_number'], ucfirst(strtolower((string) $acc['account_type'])), $acc['currency']]))); ?></small>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?php if ($acc['last_balance'] !== null): ?>
                                                <small class="text-muted"><?php echo Html::e($acc['currency']); ?></small>
                                                <span class="fw-bold blur-sensitive"><?php echo number_format((float) $acc['last_balance'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted text-nowrap"><?php echo Html::e(leanWhen($acc['last_synced_at'])); ?></td>
                                        <td>
                                            <?php if ($can_edit): ?>
                                                <form method="POST" action="lean_actions.php" class="d-flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="map_account">
                                                    <input type="hidden" name="account_row_id" value="<?php echo (int) $acc['id']; ?>">
                                                    <select name="bank_target" class="form-select form-select-sm rounded-pill" aria-label="Linked bank">
                                                        <option value="">— Not linked —</option>
                                                        <?php foreach ($banks as $b): ?>
                                                            <?php $same = strtoupper($b['currency']) === strtoupper($acc['currency']); ?>
                                                            <option value="<?php echo (int) $b['id']; ?>"
                                                                <?php echo (int) $acc['bank_id'] === (int) $b['id'] ? 'selected' : ''; ?>
                                                                <?php echo $same ? '' : 'disabled'; ?>>
                                                                <?php echo Html::e($b['bank_name'] . ($same ? '' : ' (' . $b['currency'] . ')')); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                        <option value="new">+ Create a new bank from this account</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3">Save</button>
                                                </form>
                                            <?php else: ?>
                                                <?php echo $acc['linked_bank_name'] ? Html::e($acc['linked_bank_name']) : '<span class="text-muted">Not linked</span>'; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="text-muted small">
            <i class="fa-solid fa-circle-info me-1"></i>
            A linked bank gets a new balance snapshot whenever its synced balance changes. Imported transactions do not move bank balances again, so nothing is counted twice.
        </p>
    <?php endif; ?>
</div>

<?php Layout::footer(); ?>
