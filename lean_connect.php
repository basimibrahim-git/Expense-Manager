<?php
/**
 * Connect a UAE bank through Lean's Link SDK (open banking).
 *
 * 1. The family's Lean customer is created/reused and a customer-scoped token minted server-side.
 * 2. The Link SDK (Lean.connect) runs in the page. When it reports SUCCESS, or when the bank
 *    redirects back (via lean_return.php), the page POSTs to lean_actions.php which asks Lean
 *    for the family's connections, stores them and runs the first sync.
 */
$page_title = "Connect a Bank";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Layout;
use App\Helpers\Html;
use App\Helpers\Notifier;
use App\Helpers\LeanClient;
use App\Helpers\LeanSync;
use App\Helpers\LeanException;

Bootstrap::init();

$tenant_id  = (int) $_SESSION['tenant_id'];
$can_manage = LeanSync::isAdmin() && ($_SESSION['permission'] ?? 'edit') !== 'read_only';
$configured = LeanClient::isConfigured() && LeanSync::tablesReady($pdo);

// Coming back from the bank's Open Finance redirect (forwarded by lean_return.php)
$returned   = isset($_GET['returned']);
$ret_entity = LeanClient::isId($_GET['entity_id'] ?? null) ? (string) $_GET['entity_id'] : '';
$ret_code   = preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) ($_GET['granular_status_code'] ?? '')) ? (string) $_GET['granular_status_code'] : '';
$ret_info   = mb_substr(trim((string) ($_GET['status_additional_info'] ?? '')), 0, 200);
$ret_failed = $returned && $ret_entity === '' && $ret_code !== '' && stripos($ret_code, 'SUCCESS') === false;

$sdk_config = null;
$error      = null;
if ($configured && $can_manage && !$returned) {
    try {
        $client      = new LeanClient();
        $customer_id = LeanSync::ensureCustomer($pdo, $client, $tenant_id);
        $return_url  = Notifier::appUrl('lean_return.php');
        $sdk_config  = [
            'app_token'            => LeanClient::appToken(),
            'access_token'         => $client->customerToken($customer_id), // customer-scoped, meant for the browser
            'customer_id'          => $customer_id,
            'permissions'          => LeanClient::PERMISSIONS,
            'account_type'         => 'PERSONAL',
            'sandbox'              => LeanClient::isSandbox(),
            'access_to'            => date('Y-m-d', strtotime('+364 days')), // consent lasts 24h unless set (max 1 year)
            'success_redirect_url' => $return_url,
            'fail_redirect_url'    => $return_url,
        ];
    } catch (LeanException $e) {
        error_log('Lean connect tenant ' . $tenant_id . ': ' . $e->getMessage());
        $error = 'The open banking service could not be reached. Please try again later.';
    } catch (PDOException $e) {
        error_log('Lean connect DB: ' . $e->getMessage());
        $error = 'Open banking is not set up yet (database tables missing).';
    }
}

if ($sdk_config !== null) {
    // Origins required by the Link SDK (docs.leantech.me/docs/web → Content Security Policy)
    foreach (['https://cdn.leantech.me', 'https://*.leantech.me', 'https://cdn.segment.com', 'https://cdn.mxpnl.com'] as $src) {
        Layout::allowCsp('script-src', $src);
    }
    foreach (['https://cdn.leantech.me', 'https://*.leantech.me'] as $src) {
        Layout::allowCsp('frame-src', $src);
    }
    foreach (['https://*.leantech.me', 'https://graphql.contentful.com', 'https://api.segment.io', 'https://cdn.segment.com',
              'https://api-js.mixpanel.com', 'https://cdn.growthbook.io'] as $src) {
        Layout::allowCsp('connect-src', $src);
    }
    // The SDK's documented minimum also includes data:/blob: frames and blob: fetches
    Layout::allowCsp('frame-src', 'data:');
    Layout::allowCsp('frame-src', 'blob:');
    Layout::allowCsp('connect-src', 'blob:');
    Layout::allowCsp('img-src', 'https://*.leantech.me');
    Layout::allowCsp('style-src', 'https://*.leantech.me');
    Layout::allowCsp('font-src', 'https://*.leantech.me');
}

Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="lean_accounts.php" class="btn btn-sm btn-light rounded-pill px-3 shadow-sm mb-2 hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> Open Banking
        </a>
        <h1 class="h3 fw-bold mb-1 text-dark">Connect a Bank</h1>
        <p class="text-muted mb-0">Link your UAE bank securely through Lean. Balances sync automatically and transactions wait for your review.</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-7 col-md-9">
            <?php if (!$configured): ?>
                <div class="glass-panel-premium p-5 text-center">
                    <i class="fa-solid fa-plug-circle-xmark fa-3x text-muted opacity-50 mb-3"></i>
                    <h5 class="fw-bold text-dark">Open banking is not configured</h5>
                    <p class="text-muted small mb-0">The administrator has not set up the Lean integration for this site yet. You can keep recording balances manually under My Banks.</p>
                </div>

            <?php elseif (!$can_manage): ?>
                <div class="glass-panel-premium p-5 text-center">
                    <i class="fa-solid fa-user-shield fa-3x text-muted opacity-50 mb-3"></i>
                    <h5 class="fw-bold text-dark">Ask your family admin</h5>
                    <p class="text-muted small mb-3">Only a family admin can connect or disconnect bank accounts.</p>
                    <a href="lean_accounts.php" class="btn btn-outline-primary rounded-pill px-4">View connected banks</a>
                </div>

            <?php elseif ($ret_failed): ?>
                <div class="glass-panel-premium p-5 text-center">
                    <i class="fa-solid fa-circle-xmark fa-3x text-danger opacity-75 mb-3"></i>
                    <h5 class="fw-bold text-dark">The bank connection was not completed</h5>
                    <p class="text-muted small mb-1">Status: <?php echo Html::e($ret_code); ?></p>
                    <?php if ($ret_info !== ''): ?>
                        <p class="text-muted small"><?php echo Html::e($ret_info); ?></p>
                    <?php endif; ?>
                    <a href="lean_connect.php" class="btn btn-primary rounded-pill px-4 mt-2">Try again</a>
                </div>

            <?php elseif ($returned): ?>
                <div class="glass-panel-premium p-5 text-center">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <h5 class="fw-bold text-dark">Finishing your bank connection…</h5>
                    <p class="text-muted small mb-3">This takes a few seconds.</p>
                    <form id="leanLinkForm" method="POST" action="lean_actions.php" data-auto-finish="1">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="link_entity">
                        <input type="hidden" name="entity_id" value="<?php echo Html::e($ret_entity); ?>">
                        <button type="submit" class="btn btn-primary rounded-pill px-4">Continue</button>
                    </form>
                </div>

            <?php elseif ($error !== null): ?>
                <div class="glass-panel-premium p-5 text-center">
                    <i class="fa-solid fa-triangle-exclamation fa-3x text-warning mb-3"></i>
                    <h5 class="fw-bold text-dark">Could not start the connection</h5>
                    <p class="text-muted small mb-3"><?php echo Html::e($error); ?></p>
                    <a href="lean_connect.php" class="btn btn-primary rounded-pill px-4">Try again</a>
                </div>

            <?php else: ?>
                <div class="glass-panel-premium p-4 p-md-5">
                    <div class="d-flex align-items-center mb-4">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-3" style="width:56px;height:56px;">
                            <i class="fa-solid fa-building-columns fa-lg"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">Secure bank link</h5>
                            <small class="text-muted">Powered by Lean · UAE Open Finance<?php echo LeanClient::isSandbox() ? ' · Sandbox' : ''; ?></small>
                        </div>
                    </div>
                    <ul class="list-unstyled small text-muted mb-4">
                        <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i>Read-only access: accounts, balances and transactions. No payments.</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i>You sign in on your bank's own screen; this app never sees your bank password.</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i>Access lasts up to one year and can be removed at any time from Open Banking.</li>
                    </ul>
                    <div id="leanStatus" class="alert alert-warning rounded-4 small d-none" role="alert"></div>
                    <div class="d-grid">
                        <button type="button" id="leanConnectBtn" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm hover-lift" data-onclick="leanConnect">
                            <i class="fa-solid fa-link me-2"></i>Connect a bank
                        </button>
                    </div>
                    <form id="leanLinkForm" method="POST" action="lean_actions.php" class="d-none">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="link_entity">
                        <input type="hidden" name="entity_id" value="">
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($sdk_config !== null): ?>
<script src="<?php echo Html::e(LeanClient::SDK_URL); ?>" nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>"></script>
<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    const LEAN_CONFIG = <?php echo Html::json($sdk_config); ?>;

    function leanShowStatus(text) {
        const box = document.getElementById('leanStatus');
        box.textContent = text;
        box.classList.remove('d-none');
    }

    function leanCallback(data) {
        const status = data && data.status ? String(data.status) : '';
        if (status === 'SUCCESS') {
            leanShowStatus('Bank linked. Saving your connection…');
            document.getElementById('leanLinkForm').submit();
        } else if (status === 'REDIRECT') {
            leanShowStatus('Continuing at your bank…');
        } else if (status === 'CANCELLED' || status === 'LINK_CLOSED_PROGRAMMATICALLY') {
            leanShowStatus('Connection cancelled. You can try again whenever you are ready.');
        } else if (status !== '') {
            const detail = data.secondary_status ? ' (' + String(data.secondary_status) + ')' : '';
            leanShowStatus('The bank connection did not complete' + detail + '. Please try again.');
        }
    }

    function leanConnect() {
        if (typeof Lean === 'undefined' || typeof Lean.connect !== 'function') {
            leanShowStatus('The secure bank link could not load. Check your connection or ad-blocker and reload the page.');
            return;
        }
        Lean.connect(Object.assign({}, LEAN_CONFIG, { callback: leanCallback }));
    }
</script>
<?php elseif ($returned && !$ret_failed && $configured && $can_manage): ?>
<script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('form[data-auto-finish]');
        if (form) {
            form.submit();
        }
    });
</script>
<?php endif; ?>

<?php Layout::footer(); ?>
