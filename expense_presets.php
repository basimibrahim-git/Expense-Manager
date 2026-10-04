<?php
$page_title = "Saved Spends";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Categories;
use App\Helpers\ExpensePresets;
use App\Helpers\Flash;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];
$can_edit  = ($_SESSION['permission'] ?? 'edit') !== 'read_only';
$ready     = ExpensePresets::ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    if (!$can_edit) {
        Flash::redirect('expense_presets.php', 'error', 'Unauthorized: Read-only access');
    }
    if (!$ready) {
        Flash::redirect('expense_presets.php', 'error', 'Saved spends are not set up yet (database update pending).');
    }

    $action   = $_POST['action'] ?? '';
    $id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $name     = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
    $category = (string) ($_POST['category'] ?? '');

    if ($action === 'delete') {
        if ($id) {
            $pdo->prepare("DELETE FROM expense_presets WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
            AuditHelper::log($pdo, 'delete_expense_preset', "Deleted saved spend #$id");
        }
        Flash::redirect('expense_presets.php', 'success', 'Saved spend removed.');
    }

    if ($action === 'add' || $action === 'update') {
        if ($name === '' || mb_strlen($name) > 255) {
            Flash::redirect('expense_presets.php', 'error', 'Enter a name (up to 255 characters).');
        }
        if (!Categories::isExpense($category)) {
            Flash::redirect('expense_presets.php', 'error', 'Choose a valid category.');
        }
        try {
            if ($action === 'add') {
                $pdo->prepare("INSERT INTO expense_presets (tenant_id, name, category, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$tenant_id, $name, $category, (int) $_SESSION['user_id']]);
                AuditHelper::log($pdo, 'add_expense_preset', "Saved spend: $name ($category)");
                Flash::redirect('expense_presets.php', 'success', "Saved \"$name\".");
            }
            if ($id) {
                $pdo->prepare("UPDATE expense_presets SET name = ?, category = ? WHERE id = ? AND tenant_id = ?")
                    ->execute([$name, $category, $id, $tenant_id]);
                AuditHelper::log($pdo, 'update_expense_preset', "Updated saved spend #$id: $name ($category)");
            }
            Flash::redirect('expense_presets.php', 'success', "Updated \"$name\".");
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Flash::redirect('expense_presets.php', 'error', "\"$name\" is already saved.");
            }
            error_log('Saved spend error: ' . $e->getMessage());
            Flash::redirect('expense_presets.php', 'error', 'Could not save. Please try again.');
        }
    }

    Flash::redirect('expense_presets.php');
}

$presets = $ready ? ExpensePresets::forTenant($pdo, $tenant_id) : [];

// Suggestions: descriptions used at least twice in the last 12 months that aren't saved yet,
// with the category they were most often recorded under.
$suggestions = [];
if ($ready && $can_edit) {
    $saved = [];
    foreach ($presets as $p) {
        $saved[mb_strtolower($p['name'])] = true;
    }
    $stmt = $pdo->prepare("SELECT description, category, COUNT(*) AS uses
                           FROM expenses
                           WHERE tenant_id = ? AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                           GROUP BY description, category
                           ORDER BY uses DESC, description ASC
                           LIMIT 200");
    $stmt->execute([$tenant_id]);
    foreach ($stmt->fetchAll() as $r) {
        $key = mb_strtolower(trim($r['description']));
        if ($key === '' || isset($saved[$key]) || isset($suggestions[$key]) || (int) $r['uses'] < 2
            || !Categories::isExpense($r['category'])) {
            continue;
        }
        $suggestions[$key] = ['name' => trim($r['description']), 'category' => $r['category'], 'uses' => (int) $r['uses']];
        if (count($suggestions) >= 12) {
            break;
        }
    }
}

Layout::header();
Layout::sidebar();
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="fa-solid fa-bookmark text-primary me-2"></i>Saved Spends</h1>
        <p class="text-muted mb-0">Spends you record often. Start typing one on Add Expense and its category fills in automatically.</p>
    </div>
    <a href="add_expense.php" class="btn btn-primary rounded-pill px-4"><i class="fa-solid fa-plus me-2"></i>Add Expense</a>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-warning border-0 shadow-sm">
        <i class="fa-solid fa-database me-2"></i>Saved spends need a database update: run
        <code>migrations/2026_10_06_expense_presets.sql</code> in phpMyAdmin.
    </div>
<?php else: ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="glass-panel p-4">
            <?php if ($can_edit): ?>
                <form method="POST" class="row g-2 align-items-end mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="col-md-6">
                        <label for="presetName" class="form-label small fw-bold text-muted">Spend name</label>
                        <input type="text" name="name" id="presetName" class="form-control" maxlength="255" required
                            placeholder="e.g. Carrefour, ENOC, Netflix">
                    </div>
                    <div class="col-md-4">
                        <label for="presetCategory" class="form-label small fw-bold text-muted">Category</label>
                        <select name="category" id="presetCategory" class="form-select" required>
                            <option value="" disabled selected>Choose…</option>
                            <?php echo Categories::expenseOptions(); ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Save</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold text-muted text-uppercase small mb-0"><?php echo count($presets); ?> saved</h6>
                <?php if (count($presets) > 8): ?>
                    <input type="search" id="presetFilter" class="form-control form-control-sm" style="max-width:220px"
                        placeholder="Filter…" aria-label="Filter saved spends"
                        data-oninput="filterPresets" data-args="<?php echo Html::args('$value'); ?>">
                <?php endif; ?>
            </div>

            <?php if (!$presets): ?>
                <p class="text-muted text-center py-4 mb-0">Nothing saved yet<?php echo $suggestions ? ' — pick from the suggestions on the right, or add one above.' : '.'; ?></p>
            <?php endif; ?>

            <?php foreach ($presets as $p): ?>
                <?php if ($can_edit): ?>
                    <form method="POST" class="row g-2 align-items-center py-2 border-bottom preset-item"
                        data-name="<?php echo Html::e(mb_strtolower($p['name'])); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <div class="col-md-6">
                            <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required
                                value="<?php echo Html::e($p['name']); ?>" aria-label="Spend name">
                        </div>
                        <div class="col-md-4">
                            <select name="category" class="form-select form-select-sm" required aria-label="Category">
                                <?php echo Categories::expenseOptions($p['category']); ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-1 justify-content-end">
                            <button type="submit" name="action" value="update" class="btn btn-sm btn-outline-primary" title="Save changes">
                                <i class="fa-solid fa-check"></i>
                            </button>
                            <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Remove"
                                formnovalidate
                                data-confirm="<?php echo Html::e('Remove the saved spend "' . $p['name'] . '"? Past expenses are not affected.'); ?>"
                                data-confirm-btn="Remove">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="d-flex justify-content-between py-2 border-bottom preset-item"
                        data-name="<?php echo Html::e(mb_strtolower($p['name'])); ?>">
                        <span class="fw-bold"><?php echo Html::e($p['name']); ?></span>
                        <span class="badge rounded-pill bg-light text-dark border"><?php echo Html::e(Categories::EXPENSE[$p['category']] ?? $p['category']); ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($suggestions): ?>
        <div class="col-lg-4">
            <div class="glass-panel p-4">
                <h6 class="fw-bold mb-1"><i class="fa-solid fa-wand-magic-sparkles text-warning me-2"></i>Suggestions</h6>
                <p class="text-muted small">Spends you recorded at least twice in the last year.</p>
                <?php foreach ($suggestions as $s): ?>
                    <form method="POST" class="d-flex justify-content-between align-items-center py-2 border-bottom gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="name" value="<?php echo Html::e($s['name']); ?>">
                        <input type="hidden" name="category" value="<?php echo Html::e($s['category']); ?>">
                        <div class="text-truncate">
                            <div class="fw-bold small text-truncate"><?php echo Html::e($s['name']); ?></div>
                            <div class="text-muted small"><?php echo Html::e(Categories::EXPENSE[$s['category']]); ?> · <?php echo $s['uses']; ?>×</div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-success flex-shrink-0" title="Save this spend">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    function filterPresets(text) {
        const q = (text || '').trim().toLowerCase();
        document.querySelectorAll('.preset-item').forEach(function (item) {
            item.classList.toggle('d-none', q !== '' && !item.dataset.name.includes(q));
        });
    }
</script>
<?php endif; ?>

<?php Layout::footer(); ?>
