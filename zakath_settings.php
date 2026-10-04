<?php
$page_title = "Zakath Settings";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Flash;
use App\Helpers\ZakathHelper;

Bootstrap::init();

$tenant_id = (int) $_SESSION['tenant_id'];
$is_read_only = ($_SESSION['permission'] ?? 'edit') === 'read_only';
$can_edit = !$is_read_only && in_array($_SESSION['role'] ?? '', ['family_admin', 'root_admin'], true);

$settings = ZakathHelper::settings($pdo, $tenant_id);

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    if ($is_read_only) {
        Flash::redirect('zakath_settings.php', 'error', 'Unauthorized: Read-only access');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'refresh_prices') {
        $market = ZakathHelper::marketPrices($pdo, true);
        if ($market['gold'] && $market['gold']['fresh'] && $market['silver'] && $market['silver']['fresh']) {
            Flash::redirect('zakath_settings.php', 'success', 'Gold and silver prices refreshed.');
        }
        Flash::redirect('zakath_settings.php', 'warning', 'Could not reach the price service right now. The last known or manual prices are used instead.');
    }

    if ($action === 'save_settings') {
        SecurityHelper::requireRole(['family_admin', 'root_admin'], 'zakath_settings.php');

        $errors = [];
        $new = [
            'nisab_basis'         => ($_POST['nisab_basis'] ?? '') === 'gold' ? 'gold' : 'silver',
            'gold_grams'          => (float) ($_POST['gold_grams'] ?? 0),
            'silver_grams'        => (float) ($_POST['silver_grams'] ?? 0),
            'hawl_start_date'     => null,
            'use_manual_prices'   => !empty($_POST['use_manual_prices']) ? 1 : 0,
            'manual_gold_price'   => null,
            'manual_silver_price' => null,
        ];

        [$gLo, $gHi] = ZakathHelper::GOLD_GRAMS_RANGE;
        [$sLo, $sHi] = ZakathHelper::SILVER_GRAMS_RANGE;
        if ($new['gold_grams'] < $gLo || $new['gold_grams'] > $gHi) {
            $errors[] = "Gold nisab must be between {$gLo} and {$gHi} grams.";
        }
        if ($new['silver_grams'] < $sLo || $new['silver_grams'] > $sHi) {
            $errors[] = "Silver nisab must be between {$sLo} and {$sHi} grams.";
        }

        $hawl = trim($_POST['hawl_start_date'] ?? '');
        if ($hawl !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', $hawl);
            if (!$d || $d->format('Y-m-d') !== $hawl) {
                $errors[] = 'Hawl start date is not a valid date.';
            } elseif ($hawl > date('Y-m-d')) {
                $errors[] = 'Hawl start date cannot be in the future.';
            } elseif ($hawl < '1970-01-01') {
                $errors[] = 'Hawl start date is too far in the past.';
            } else {
                $new['hawl_start_date'] = $hawl;
            }
        }

        foreach (['gold' => 'manual_gold_price', 'silver' => 'manual_silver_price'] as $metal => $field) {
            $raw = trim($_POST[$field] ?? '');
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 100000) {
                $errors[] = 'Manual ' . $metal . ' price must be a positive number (AED per gram).';
                continue;
            }
            $new[$field] = round((float) $raw, 4);
        }
        if ($new['use_manual_prices'] && $new['manual_' . $new['nisab_basis'] . '_price'] === null) {
            $errors[] = 'Enter a manual ' . $new['nisab_basis'] . ' price to use manual prices.';
        }

        if ($errors) {
            foreach ($errors as $err) {
                Flash::error($err);
            }
            Flash::redirect('zakath_settings.php');
        }

        try {
            ZakathHelper::saveSettings($pdo, $tenant_id, $new);
        } catch (Throwable $e) {
            error_log('Zakath settings save failed: ' . $e->getMessage());
            Flash::redirect('zakath_settings.php', 'error', 'Could not save settings. Has the Zakath database update been run?');
        }
        Flash::redirect('zakath_settings.php', 'success', 'Zakath settings saved.');
    }

    Flash::redirect('zakath_settings.php');
}

$market = ZakathHelper::marketPrices($pdo);
$prices = ZakathHelper::prices($pdo, $settings);
$nisab  = ZakathHelper::nisab($settings, $prices);
$hawl   = ZakathHelper::hawl($settings['hawl_start_date']);

function zsFmtDateTime(?string $dt): string
{
    return $dt ? date('M d, Y H:i', strtotime($dt)) : 'never';
}

$disabled = $can_edit ? '' : 'disabled';

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="zakath_tracker.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Tracker
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Zakath Settings</h1>
        <p class="text-muted mb-0">Nisab basis, your hawl (lunar year) start date and gold/silver prices</p>
    </div>

    <?php if (!empty($settings['_missing'])): ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">
            <i class="fa-solid fa-database me-2"></i>The Zakath database update hasn't been run yet
            (migrations/2026_10_05_feature_release.sql). Settings can't be saved until it is.
        </div>
    <?php endif; ?>

    <?php if (!$can_edit): ?>
        <div class="alert alert-info rounded-4 border-0 shadow-sm">
            <i class="fa-solid fa-lock me-2"></i>Only a family admin can change these settings.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" class="glass-panel-premium p-4 shadow-sm">
                <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="save_settings">

                <!-- Nisab basis -->
                <h5 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-scale-balanced me-2 text-primary"></i>Nisab basis</h5>
                <p class="text-muted small mb-3">
                    The nisab is the minimum wealth on which Zakath is due. The silver nisab is much lower than
                    the gold one, so more people pay — many scholars prefer it as the safer choice for the poor.
                </p>
                <div class="d-flex flex-column gap-2 mb-4">
                    <?php foreach (['silver' => 'Silver (recommended — safer for the poor)', 'gold' => 'Gold'] as $val => $label): ?>
                        <div class="form-check p-3 bg-light rounded-4 border border-light ps-5">
                            <input class="form-check-input" type="radio" name="nisab_basis" id="basis_<?php echo $val; ?>" value="<?php echo $val; ?>"
                                <?php echo $settings['nisab_basis'] === $val ? 'checked' : ''; ?> <?php echo $disabled; ?>>
                            <label class="form-check-label fw-semibold" for="basis_<?php echo $val; ?>"><?php echo Html::e($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="goldGrams" class="form-label fw-bold text-muted small">Gold nisab (grams)</label>
                        <input type="number" step="0.001" min="<?php echo ZakathHelper::GOLD_GRAMS_RANGE[0]; ?>" max="<?php echo ZakathHelper::GOLD_GRAMS_RANGE[1]; ?>"
                            name="gold_grams" id="goldGrams" class="form-control rounded-pill px-3" list="goldPresets"
                            value="<?php echo Html::e(rtrim(rtrim(number_format($settings['gold_grams'], 3, '.', ''), '0'), '.')); ?>" required <?php echo $disabled; ?>>
                        <datalist id="goldPresets">
                            <?php foreach (ZakathHelper::GOLD_GRAM_PRESETS as $g => $lbl): ?>
                                <option value="<?php echo Html::e($g); ?>"><?php echo Html::e($lbl); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">85 g (contemporary) or 87.48 g (7.5 tola).</div>
                    </div>
                    <div class="col-md-6">
                        <label for="silverGrams" class="form-label fw-bold text-muted small">Silver nisab (grams)</label>
                        <input type="number" step="0.001" min="<?php echo ZakathHelper::SILVER_GRAMS_RANGE[0]; ?>" max="<?php echo ZakathHelper::SILVER_GRAMS_RANGE[1]; ?>"
                            name="silver_grams" id="silverGrams" class="form-control rounded-pill px-3" list="silverPresets"
                            value="<?php echo Html::e(rtrim(rtrim(number_format($settings['silver_grams'], 3, '.', ''), '0'), '.')); ?>" required <?php echo $disabled; ?>>
                        <datalist id="silverPresets">
                            <?php foreach (ZakathHelper::SILVER_GRAM_PRESETS as $g => $lbl): ?>
                                <option value="<?php echo Html::e($g); ?>"><?php echo Html::e($lbl); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">612.36 g (52.5 tola) or 595 g.</div>
                    </div>
                </div>

                <!-- Hawl -->
                <h5 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-moon me-2 text-primary"></i>Hawl (Zakath year)</h5>
                <p class="text-muted small mb-2">
                    The date your family's wealth first reached the nisab. Zakath falls due every lunar year
                    (354 days) from this date; we'll email reminders 30 days, 7 days and on the day.
                </p>
                <div class="mb-4">
                    <label for="hawlStart" class="form-label fw-bold text-muted small">Hawl start date</label>
                    <input type="date" name="hawl_start_date" id="hawlStart" class="form-control rounded-pill px-3" style="max-width: 260px;"
                        max="<?php echo date('Y-m-d'); ?>" value="<?php echo Html::e($settings['hawl_start_date'] ?? ''); ?>" <?php echo $disabled; ?>>
                    <div class="form-text">Leave empty to turn off due-date tracking and reminders.</div>
                </div>

                <!-- Manual prices -->
                <h5 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-coins me-2 text-warning"></i>Manual prices (AED per gram)</h5>
                <p class="text-muted small mb-2">
                    Used when the market price can't be fetched — or always, if you tick the box (e.g. to use your
                    local jeweller's 24k rate).
                </p>
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <label for="manualGold" class="form-label fw-bold text-muted small">Gold 24k, AED / g</label>
                        <input type="number" step="0.0001" min="0" name="manual_gold_price" id="manualGold" class="form-control rounded-pill px-3"
                            value="<?php echo Html::e($settings['manual_gold_price'] ?? ''); ?>" <?php echo $disabled; ?>>
                    </div>
                    <div class="col-md-6">
                        <label for="manualSilver" class="form-label fw-bold text-muted small">Silver, AED / g</label>
                        <input type="number" step="0.0001" min="0" name="manual_silver_price" id="manualSilver" class="form-control rounded-pill px-3"
                            value="<?php echo Html::e($settings['manual_silver_price'] ?? ''); ?>" <?php echo $disabled; ?>>
                    </div>
                </div>
                <div class="form-text mb-3">Manual prices last updated: <?php echo Html::e(zsFmtDateTime($settings['manual_prices_updated_at'])); ?></div>
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" name="use_manual_prices" id="useManual" value="1"
                        <?php echo $settings['use_manual_prices'] ? 'checked' : ''; ?> <?php echo $disabled; ?>>
                    <label class="form-check-label" for="useManual">Always use my manual prices instead of the market price</label>
                </div>

                <?php if ($can_edit): ?>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill shadow-sm hover-lift py-2">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Settings
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="col-lg-5 d-flex flex-column gap-4">
            <!-- Current nisab -->
            <div class="glass-panel-premium p-4 shadow-sm">
                <h6 class="text-muted fw-bold text-uppercase small mb-3">Current nisab</h6>
                <?php if ($nisab['value'] !== null): ?>
                    <h3 class="fw-bold text-dark mb-1"><small class="fs-6 text-muted">AED</small> <?php echo number_format($nisab['value'], 2); ?></h3>
                    <div class="text-muted small">
                        <?php echo Html::e(number_format($nisab['grams'], 2) . ' g ' . $nisab['basis'] . ' × AED ' . number_format($nisab['price_per_gram'], 4) . '/g'); ?>
                    </div>
                <?php else: ?>
                    <p class="text-danger small mb-0">No <?php echo Html::e($nisab['basis']); ?> price available — enter a manual price.</p>
                <?php endif; ?>
                <?php if ($hawl): ?>
                    <hr>
                    <div class="small">
                        <div class="d-flex justify-content-between"><span class="text-muted">Next due date</span>
                            <span class="fw-bold"><?php echo Html::e(date('M d, Y', strtotime($hawl['due']))); ?></span></div>
                        <?php if ($hawl['due_hijri']): ?>
                            <div class="d-flex justify-content-between"><span class="text-muted">Hijri</span><span><?php echo Html::e($hawl['due_hijri']); ?></span></div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between"><span class="text-muted">Days left</span><span class="fw-bold"><?php echo (int) $hawl['days_left']; ?></span></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Market prices -->
            <div class="glass-panel-premium p-4 shadow-sm">
                <h6 class="text-muted fw-bold text-uppercase small mb-3">Market prices</h6>
                <?php foreach (['gold' => 'Gold (24k)', 'silver' => 'Silver'] as $m => $lbl): $mk = $market[$m]; ?>
                    <div class="d-flex justify-content-between align-items-start py-2 border-bottom border-light">
                        <div>
                            <div class="fw-semibold"><?php echo Html::e($lbl); ?></div>
                            <div class="text-muted small">
                                <?php if ($mk): ?>
                                    USD <?php echo number_format($mk['usd_per_ounce'], 2); ?>/oz · updated <?php echo Html::e(zsFmtDateTime($mk['fetched_at'])); ?>
                                    <?php if (!$mk['fresh']): ?><span class="badge bg-warning-subtle text-warning ms-1">old</span><?php endif; ?>
                                <?php else: ?>
                                    not available
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fw-bold text-end"><?php echo $mk ? 'AED ' . number_format($mk['aed_per_gram'], 4) . '/g' : '—'; ?></div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted small mt-3 mb-3">
                    Source: <?php echo Html::e(ZakathHelper::providerLabel()); ?> spot price (USD per troy ounce),
                    converted at 1 oz = 31.1034768 g and 1 USD = 3.6725 AED. Refreshed every 12 hours.
                    Spot prices exclude dealer premiums and VAT.
                </p>
                <?php if (!$is_read_only && ZakathHelper::provider() !== 'none'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="refresh_prices">
                        <button type="submit" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                            <i class="fa-solid fa-rotate me-1"></i> Refresh now
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php Layout::footer(); ?>
