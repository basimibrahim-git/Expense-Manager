<?php
// error_logs.php: the server's PHP error log and the cron log, grouped so repeats show once
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\AuditHelper;
use App\Helpers\Flash;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\SecurityHelper;

Bootstrap::init();

// Family admins and root admins. The logs cover the whole server (every family).
SecurityHelper::requireRole(['family_admin', 'root_admin']);

// Same locations config.php writes to
$sources = [
    'php'  => ['label' => 'PHP errors', 'icon' => 'fa-bug', 'files' => ['logs/php_errors.log', '.error.log']],
    'cron' => ['label' => 'Cron log', 'icon' => 'fa-clock', 'files' => ['logs/cronlog.txt']],
];
const ERROR_LOG_TAIL_BYTES = 1048576; // read at most the last 1 MB of each file

$tab = array_key_exists($_GET['tab'] ?? '', $sources) ? $_GET['tab'] : 'php';

// ── Clear ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');
    $clear = $_POST['clear'] ?? '';
    if (($_SESSION['permission'] ?? 'edit') === 'read_only' || !isset($sources[$clear])) {
        Flash::redirect('error_logs.php?tab=' . urlencode($tab), 'error', 'Unauthorized: Read-only access');
    }
    $failed = [];
    foreach ($sources[$clear]['files'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path) && @file_put_contents($path, '', LOCK_EX) === false) {
            $failed[] = $file;
        }
    }
    AuditHelper::log($pdo, 'clear_error_log', 'Cleared ' . $sources[$clear]['label']);
    Flash::redirect('error_logs.php?tab=' . urlencode($clear), $failed ? 'error' : 'success', $failed
        ? 'Could not clear ' . implode(', ', $failed) . ' (file permissions).'
        : $sources[$clear]['label'] . ' cleared.');
}

/** Last ERROR_LOG_TAIL_BYTES of a file, starting at a whole line. */
function errorLogTail(string $path): string
{
    $size = (int) filesize($path);
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    if ($size > ERROR_LOG_TAIL_BYTES) {
        fseek($fh, -ERROR_LOG_TAIL_BYTES, SEEK_END);
        fgets($fh); // skip the partial first line
    }
    $text = (string) stream_get_contents($fh);
    fclose($fh);
    return $text;
}

/**
 * PHP log entries ("[01-Nov-2026 11:07:47 Asia/Dubai] message", continuation lines appended),
 * grouped by message with the timestamp removed, newest first.
 *
 * @return array<int, array{message: string, level: string, count: int, first: int, last: int}>
 */
function errorLogGroups(string $text): array
{
    $entries = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\[([^\]]+)\]\s?(.*)$/', $line, $m) && ($ts = strtotime(preg_replace('/\s+[A-Za-z_]+\/[A-Za-z_\/]+$/', '', $m[1]))) !== false) {
            $entries[] = ['ts' => $ts, 'message' => $m[2]];
        } elseif ($entries && trim($line) !== '') {
            $entries[count($entries) - 1]['message'] .= "\n" . $line; // stack trace / wrapped line
        }
    }
    $groups = [];
    foreach ($entries as $e) {
        // Group repeats: numbers in "line 42" / ids stay, only the timestamp differs
        $key = md5($e['message']);
        if (!isset($groups[$key])) {
            $groups[$key] = ['message' => $e['message'], 'level' => errorLogLevel($e['message']), 'count' => 0, 'first' => $e['ts'], 'last' => $e['ts']];
        }
        $groups[$key]['count']++;
        $groups[$key]['first'] = min($groups[$key]['first'], $e['ts']);
        $groups[$key]['last'] = max($groups[$key]['last'], $e['ts']);
    }
    usort($groups, fn($a, $b) => $b['last'] <=> $a['last']);
    return $groups;
}

function errorLogLevel(string $message): string
{
    if (preg_match('/^(PHP )?(Fatal|Parse error)|\[Uncaught\]|Uncaught /i', $message)) {
        return 'danger';
    }
    if (preg_match('/^(PHP )?Warning|failed|error/i', $message)) {
        return 'warning';
    }
    if (preg_match('/^(PHP )?(Deprecated|Notice)/i', $message)) {
        return 'secondary';
    }
    return 'info';
}

$level_labels = ['danger' => 'Fatal', 'warning' => 'Warning', 'secondary' => 'Notice', 'info' => 'Info'];

// ── Read the selected source ─────────────────────────────────────────────────
$files_info = [];
$text = '';
foreach ($sources[$tab]['files'] as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $files_info[] = ['name' => $file, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
    $text .= errorLogTail($path) . "\n";
}
$search = trim((string) ($_GET['q'] ?? ''));

if ($tab === 'php') {
    $groups = errorLogGroups($text);
    if ($search !== '') {
        $groups = array_values(array_filter($groups, fn($g) => stripos($g['message'], $search) !== false));
    }
    $level_counts = array_count_values(array_column($groups, 'level'));
    $total_entries = array_sum(array_column($groups, 'count'));
} else {
    $lines = array_values(array_filter(preg_split('/\R/', $text), fn($l) => trim($l) !== ''));
    if ($search !== '') {
        $lines = array_values(array_filter($lines, fn($l) => stripos($l, $search) !== false));
    }
    $lines = array_reverse(array_slice($lines, -500)); // newest first
}

$can_clear = ($_SESSION['permission'] ?? 'edit') !== 'read_only' && $files_info && array_sum(array_column($files_info, 'size')) > 0;

$page_title = 'Error Logs';
Layout::header();
Layout::sidebar();
?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center g-3">
        <div class="col">
            <h2 class="fw-bold mb-0"><i class="fa-solid fa-bug text-danger me-2"></i>Error Logs</h2>
            <p class="text-muted mb-0">Server errors and scheduled-job output. Repeated errors are grouped; newest first.</p>
        </div>
        <div class="col-auto d-flex gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="tab" value="<?php echo Html::e($tab); ?>">
                <input type="search" name="q" class="form-control" placeholder="Search…" value="<?php echo Html::e($search); ?>" aria-label="Search logs">
                <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            </form>
            <?php if ($can_clear): ?>
                <form method="POST" data-confirm="<?php echo Html::e('Clear the ' . $sources[$tab]['label'] . '? This cannot be undone.'); ?>" data-confirm-btn="Clear">
                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                    <input type="hidden" name="clear" value="<?php echo Html::e($tab); ?>">
                    <button type="submit" class="btn btn-danger text-nowrap"><i class="fa-solid fa-trash me-1"></i> Clear</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach ($sources as $key => $src): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $key === $tab ? 'active fw-bold' : ''; ?>" href="error_logs.php?tab=<?php echo Html::e($key); ?>">
                    <i class="fa-solid <?php echo $src['icon']; ?> me-1"></i><?php echo Html::e($src['label']); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="small text-muted mb-3">
        <?php if (!$files_info): ?>
            No log file yet: nothing has been logged.
        <?php else: ?>
            <?php foreach ($files_info as $f): ?>
                <span class="me-3"><i class="fa-regular fa-file-lines me-1"></i><?php echo Html::e($f['name']); ?> · <?php echo number_format($f['size'] / 1024, 1); ?> KB · updated <?php echo Html::e(date('d M Y H:i', $f['modified'])); ?></span>
            <?php endforeach; ?>
            <?php if (array_sum(array_column($files_info, 'size')) > ERROR_LOG_TAIL_BYTES): ?>
                <span class="text-warning">Showing the most recent 1 MB.</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'php'): ?>
        <?php if ($groups): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge rounded-pill bg-dark-subtle text-dark-emphasis px-3 py-2"><?php echo count($groups); ?> distinct · <?php echo $total_entries; ?> total</span>
                <?php foreach ($level_labels as $lvl => $label): ?>
                    <?php if (!empty($level_counts[$lvl])): ?>
                        <span class="badge rounded-pill bg-<?php echo $lvl; ?>-subtle text-<?php echo $lvl; ?>-emphasis px-3 py-2"><?php echo $label; ?>: <?php echo (int) $level_counts[$lvl]; ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="glass-card shadow-sm border-0 rounded-4 overflow-hidden">
            <?php if (!$groups): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa-solid fa-circle-check fa-2x text-success mb-2 d-block"></i>
                    <?php echo $search !== '' ? 'No errors match your search.' : 'No errors logged.'; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 90px;">Level</th>
                                <th>Message</th>
                                <th class="text-end" style="width: 70px;">Times</th>
                                <th class="text-nowrap" style="width: 170px;">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $g): ?>
                                <?php [$first_line] = explode("\n", $g['message']); $has_more = strpos($g['message'], "\n") !== false; ?>
                                <tr>
                                    <td><span class="badge bg-<?php echo $g['level']; ?>"><?php echo $level_labels[$g['level']]; ?></span></td>
                                    <td style="word-break: break-word;">
                                        <code class="text-body small"><?php echo Html::e($first_line); ?></code>
                                        <?php if ($has_more): ?>
                                            <details class="small mt-1">
                                                <summary class="text-primary" style="cursor: pointer;">Details</summary>
                                                <pre class="small mb-0 mt-1" style="white-space: pre-wrap;"><?php echo Html::e($g['message']); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold"><?php echo (int) $g['count']; ?></td>
                                    <td class="small text-nowrap">
                                        <?php echo Html::e(date('d M Y H:i', $g['last'])); ?>
                                        <?php if ($g['count'] > 1): ?>
                                            <div class="text-muted">since <?php echo Html::e(date('d M', $g['first'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="glass-card shadow-sm border-0 rounded-4 p-3">
            <?php if (!$lines): ?>
                <div class="text-center text-muted py-5"><?php echo $search !== '' ? 'No lines match your search.' : 'The cron log is empty.'; ?></div>
            <?php else: ?>
                <div class="small text-muted mb-2">Latest <?php echo count($lines); ?> lines, newest first.</div>
                <pre class="small mb-0" style="white-space: pre-wrap; max-height: 70vh; overflow-y: auto;"><?php
                    foreach ($lines as $line) {
                        $cls = preg_match('/FAILED|ERROR|Errors=[1-9]/i', $line) ? 'text-danger fw-bold' : (preg_match('/skipped|WARN/i', $line) ? 'text-warning' : '');
                        echo '<span class="' . $cls . '">' . Html::e($line) . "</span>\n";
                    }
                ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php Layout::footer(); ?>
