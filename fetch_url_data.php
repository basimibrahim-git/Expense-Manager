<?php
// fetch_url_data.php
// A simple proxy to fetch Open Graph and Meta tags from a URL

require_once 'config.php';
header('Content-Type: application/json');

// 1. Validate Input
$data = json_decode(file_get_contents('php://input'), true);
$url = $data['url'] ?? '';

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
}

// 1.1 Protocol Check (SSRF Protection)
$parsed = parse_url($url);
if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
    echo json_encode(['success' => false, 'error' => 'Only HTTP/HTTPS URLs are allowed']);
    exit;
}

// 1.2 SSRF Protection: Block internal/private IP addresses
function isInternalIpAddress(string $ip): bool
{
    // Block private IPv4 ranges, localhost, link-local, and cloud metadata IPs
    $blocked_ranges = [
        '10.0.0.0/8',        // Private Class A
        '172.16.0.0/12',     // Private Class B
        '192.168.0.0/16',    // Private Class C
        '127.0.0.0/8',       // Localhost
        '0.0.0.0/8',         // Current network
        '169.254.0.0/16',    // Link-local (AWS/cloud metadata)
        '100.64.0.0/10',     // Carrier-grade NAT
        '192.0.0.0/24',      // IETF Protocol Assignments
        '192.0.2.0/24',      // TEST-NET-1
        '198.51.100.0/24',   // TEST-NET-2
        '203.0.113.0/24',    // TEST-NET-3
        '224.0.0.0/4',       // Multicast
        '240.0.0.0/4',       // Reserved
        '255.255.255.255/32' // Broadcast
    ];

    $ip_long = ip2long($ip);
    if ($ip_long === false) {
        return true; // Invalid IP, block it
    }

    foreach ($blocked_ranges as $range) {
        list($subnet, $mask) = explode('/', $range);
        $subnet_long = ip2long($subnet);
        $mask_long = ~((1 << (32 - intval($mask))) - 1);

        if (($ip_long & $mask_long) === ($subnet_long & $mask_long)) {
            return true;
        }
    }

    return false;
}

// Resolve hostname BEFORE making request to prevent DNS rebinding
$host = $parsed['host'] ?? '';
if (empty($host)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL: missing host']);
    exit;
}

$resolved_ip = gethostbyname($host);
if ($resolved_ip === $host) {
    // gethostbyname returns the hostname if it fails to resolve
    echo json_encode(['success' => false, 'error' => 'Unable to resolve hostname']);
    exit;
}

if (isInternalIpAddress($resolved_ip)) {
    echo json_encode(['success' => false, 'error' => 'Access to internal networks is not allowed']);
    exit;
}

// 1.5 Check Cache
$url_hash = hash('sha256', $url);
try {
    $stmt = $pdo->prepare("SELECT url_data FROM url_cache WHERE url_hash = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 30
DAY)");
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

// 2. Setup Curl with VALIDATED URL using resolved IP
// SSRF-SAFE: Build URL from scratch using only validated/sanitized components

// Extract and sanitize each component separately
// Scheme: Only allow http or https (default to https)
$url_scheme = 'https';  // Default secure
if (isset($parsed['scheme'])) {
    $lower_scheme = strtolower(trim($parsed['scheme']));
    if ($lower_scheme === 'http') {
        $url_scheme = 'http';
    }
    // Any other scheme stays as 'https' (safe default)
}

// Port: Only allow numeric ports
$url_port = '';
if (isset($parsed['port']) && is_numeric($parsed['port'])) {
    $port_num = intval($parsed['port']);
    if ($port_num > 0 && $port_num < 65536) {
        $url_port = ':' . $port_num;
    }
}

// Path: Sanitize to only safe characters 
$url_path = '/';
if (isset($parsed['path']) && strlen($parsed['path']) > 0) {
    // Allow only alphanumeric, dash, underscore, dot, forward slash
    $sanitized_path = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '', $parsed['path']);
    if (strlen($sanitized_path) > 0) {
        $url_path = '/' . ltrim($sanitized_path, '/');
    }
}

// Query: URL-encode for safety
$url_query = '';
if (isset($parsed['query']) && strlen($parsed['query']) > 0) {
    // Rebuild query string by parsing and re-encoding each parameter
    $query_params = [];
    parse_str($parsed['query'], $query_params);
    if (!empty($query_params)) {
        $url_query = '?' . http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);
    }
}

// CRITICAL: Use the VALIDATED IP address (already checked against internal ranges)
// This is the key SSRF protection - $resolved_ip was already validated by isInternalIpAddress()
$validated_ip = filter_var($resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
if ($validated_ip === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid or blocked IP address']);
    exit;
}

// Build the final URL using only validated components
$safe_url = $url_scheme . '://' . $validated_ip . $url_port . $url_path . $url_query;

// Store original host for the Host header (required for virtual hosting)
$original_host = $host;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $safe_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Disable following redirects to prevent SSRF via redirect
curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // Restrict protocols
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Host: ' . $original_host,  // Set Host header for virtual hosting
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Cache-Control: no-cache',
    'Pragma: no-cache'
]);
curl_setopt($ch, CURLOPT_ENCODING, ''); // Handle gzip
// Enable SSL verify for production security
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($html === false || $httpCode >= 400) {
    // Log detailed error server-side
    error_log("Fetch URL Error: $url - " . $error . " (HTTP $httpCode)");
    // Return generic error to client
    echo json_encode(['success' => false, 'error' => 'Failed to load page content.']);
    exit;
}

// 3. Parse HTML for Meta Tags
// We use DOMDocument to tolerate malformed HTML
$doc = new DOMDocument();
@$doc->loadHTML($html); // Suppress warnings

$xpath = new DOMXPath($doc);

// Extract Title
$title = '';
$ogTitle = $xpath->query('//meta[@property="og:title"]/@content');
if ($ogTitle->length > 0)
    $title = $ogTitle->item(0)->nodeValue;
if (!$title) {
    $pageTitle = $xpath->query('//title');
    if ($pageTitle->length > 0)
        $title = $pageTitle->item(0)->nodeValue;
}

// Extract Description (This usually has the offers!)
$description = '';
$ogDesc = $xpath->query('//meta[@property="og:description"]/@content');
if ($ogDesc->length > 0)
    $description = $ogDesc->item(0)->nodeValue;
if (!$description) {
    $metaDesc = $xpath->query('//meta[@name="description"]/@content');
    if ($metaDesc->length > 0)
        $description = $metaDesc->item(0)->nodeValue;
}

// Extract Image
$image = '';
$ogImage = $xpath->query('//meta[@property="og:image"]/@content');
if ($ogImage->length > 0)
    $image = $ogImage->item(0)->nodeValue;

// Clean up text
$title = trim($title);
$description = trim($description);
$image = trim($image);

$result_data = [
    'title' => $title,
    'description' => $description,
    'image' => $image,
    'source' => 'Live Website'
];

// Save to Cache
try {
    $json_data = json_encode($result_data);
    $stmt = $pdo->prepare("INSERT INTO url_cache (url_hash, url_data) VALUES (?, ?) ON DUPLICATE KEY UPDATE url_data = ?,
updated_at = NOW()");
    $stmt->execute([$url_hash, $json_data, $json_data]);
} catch (Exception $e) {
    // Ignore save errors
}

echo json_encode([
    'success' => true,
    'data' => $result_data
]);
?>