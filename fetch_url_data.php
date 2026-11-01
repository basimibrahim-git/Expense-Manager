<?php
// fetch_url_data.php
// A simple proxy to fetch Open Graph and Meta tags (and stated cashback rates) from a card page.
// The fetching itself, with its SSRF protections, lives in CardOfferHelper::fetch().

require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\CardOfferHelper;

Bootstrap::init(); // returns 401 JSON when not logged in
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// 1. Validate Input
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$url = is_string($data['url'] ?? null) ? $data['url'] : '';

// 0. CSRF: token from the X-CSRF-Token header, a JSON body field, or a form field
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || !is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
}

// 1.5 Check Cache (a day, so the sync button gives fresh offers; the weekly cron refreshes saved cards)
$url_hash = hash('sha256', $url);
try {
    $stmt = $pdo->prepare("SELECT url_data FROM url_cache WHERE url_hash = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stmt->execute([$url_hash]);
    $cached = $stmt->fetchColumn();
    if ($cached) {
        $data = json_decode($cached, true);
        $data['source'] = 'Cache'; // Mark as cached
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
} catch (Exception $e) {
    // Ignore cache errors, proceed to fetch
}

// 2. Fetch and parse
$page = CardOfferHelper::fetch($url);
if (!$page['ok']) {
    echo json_encode(['success' => false, 'error' => $page['error'] ?? 'Failed to load page content.']);
    exit;
}
CardOfferHelper::saveCache($pdo, $url, $page);

echo json_encode([
    'success' => true,
    'data' => [
        'title' => $page['title'],
        'description' => $page['description'],
        'image' => $page['image'],
        'rates' => $page['rates'],
        'source' => 'Live Website',
    ]
]);
