<?php
$page_title = "Travel Planner";
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;
use App\Helpers\SecurityHelper;
use App\Helpers\Html;
use App\Helpers\Layout;
use App\Helpers\AuditHelper;

Bootstrap::init();

$tenant_id = $_SESSION['tenant_id'];
$user_id = $_SESSION['user_id'];

// Schema (trips / trip_expenses) is created by install/install.php — no runtime DDL here.

/**
 * Returns the value when it is a real Y-m-d date, otherwise null.
 */
function tripValidDate($date): ?string
{
    $date = (string) $date;
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $date : null;
}

/**
 * Converts commercial 2-letter airline codes (IATA) to 3-letter (ICAO) codes for ADS-B tracking.
 */
function convertToCallsign($flight_number) {
    $flight_number = strtoupper(str_replace(' ', '', $flight_number));
    $iata_to_icao = [
        'EK' => 'UAE', // Emirates
        'QR' => 'QTR', // Qatar Airways
        'EY' => 'ETD', // Etihad
        'AI' => 'AIC', // Air India
        'FZ' => 'FDB', // Flydubai
        'WY' => 'OAS', // Oman Air
        'GF' => 'GFA', // Gulf Air
        'IX' => 'AXB', // Air India Express
        '6E' => 'IGO', // IndiGo
        'SG' => 'SEJ', // SpiceJet
        'UK' => 'VTI', // Vistara
        'LH' => 'DLH', // Lufthansa
        'BA' => 'BAW', // British Airways
        'AF' => 'AFR', // Air France
        'SQ' => 'SIA', // Singapore Airlines
        'CX' => 'CPA', // Cathay Pacific
        'MH' => 'MAS', // Malaysia Airlines
        'TG' => 'THA', // Thai Airways
        'TK' => 'THY', // Turkish Airlines
        'AA' => 'AAL', // American Airlines
        'DL' => 'DAL', // Delta Air Lines
        'UA' => 'UAL', // United Airlines
        'MS' => 'MSR', // EgyptAir
        'SV' => 'SVA', // Saudia
        'KU' => 'KAC', // Kuwait Airways
        'ME' => 'MEA', // Middle East Airlines
        'RJ' => 'RJA', // Royal Jordanian
    ];

    $prefix = substr($flight_number, 0, 2);
    $number = substr($flight_number, 2);
    
    if (isset($iata_to_icao[$prefix])) {
        return $iata_to_icao[$prefix] . $number;
    }
    
    return $flight_number;
}

/**
 * Queries the public ADSB.one API to check if a flight is currently active in the air.
 */
function isFlightCurrentlyActive($flight_number) {
    if (empty($flight_number)) {
        return false;
    }
    
    $callsign = convertToCallsign($flight_number);
    $url = "https://api.adsb.one/v2/callsign/" . urlencode($callsign);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Fast 2-second timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (!empty($data['ac'])) {
            return true;
        }
    }
    return false;
}

/**
 * Automatically determines flight status based on departure date and live ADS-B status.
 */
function getAutomaticFlightStatus($flight_number, $flight_date) {
    if (empty($flight_number)) {
        return 'No Flight';
    }
    
    $today = date('Y-m-d');
    
    if ($flight_date > $today) {
        return 'Scheduled';
    }
    
    if ($flight_date < $today) {
        return 'Landed';
    }
    
    // Flight is scheduled for today - check live ADSB status
    if (isFlightCurrentlyActive($flight_number)) {
        return 'Active';
    }
    
    return 'Scheduled';
}

/**
 * Automatically fetches scheduled flight details from Aviationstack API if configured,
 * and extracts the raw local time string + airport codes.
 */
function fetchFlightDataFromAPI($flight_number) {
    $key = $_ENV['AVIATIONSTACK_KEY'] ?? getenv('AVIATIONSTACK_KEY') ?? '';
    if (empty($key) || empty($flight_number)) {
        return null;
    }
    
    $flight_number = strtoupper(str_replace(' ', '', $flight_number));
    // Aviationstack's free plan only serves plain HTTP (HTTPS needs a paid plan). If the account is on a
    // paid plan, change this to https://.
    $url = "http://api.aviationstack.com/v1/flights?access_key=" . urlencode($key) . "&flight_iata=" . urlencode($flight_number) . "&limit=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4); // 4-second timeout
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($response)) {
        $res = json_decode($response, true);
        if (!empty($res['data'][0])) {
            $data = $res['data'][0];
            $scheduled_dt = $data['departure']['scheduled'] ?? '';
            $time = null;
            if (is_string($scheduled_dt) && strlen($scheduled_dt) >= 16) {
                $time = substr($scheduled_dt, 11, 5); // Returns "HH:MM"
                if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
                    $time = null;
                }
            }
            // Third-party data: keep only well-formed airport codes (stored in VARCHAR(10) and shown in the page)
            $code = function ($v) {
                return (is_string($v) && preg_match('/^[A-Za-z0-9]{3,4}$/', $v)) ? strtoupper($v) : null;
            };
            return [
                'time' => $time,
                'dep_airport' => $code($data['departure']['iata'] ?? null),
                'arr_airport' => $code($data['arrival']['iata'] ?? null),
            ];
        }
    }
    return null;
}

/**
 * Formats a stored time string nicely (e.g. "21:25" to "09:25 PM").
 */
function formatFlightTime($time_str) {
    if (empty($time_str)) {
        return '';
    }
    return date('h:i A', strtotime($time_str));
}

/**
 * Maps common destination names to high-quality travel banners from Unsplash.
 */
function getDestinationImage($location) {
    $location = strtolower(trim($location));
    $images = [
        'kerala' => 'https://images.unsplash.com/photo-1593693397690-362cb9666fc2?auto=format&fit=crop&w=1200&q=80',
        'cochin' => 'https://images.unsplash.com/photo-1593693397690-362cb9666fc2?auto=format&fit=crop&w=1200&q=80',
        'kochi' => 'https://images.unsplash.com/photo-1593693397690-362cb9666fc2?auto=format&fit=crop&w=1200&q=80',
        'dubai' => 'https://images.unsplash.com/photo-1512453979798-5ea266f8880c?auto=format&fit=crop&w=1200&q=80',
        'bali' => 'https://images.unsplash.com/photo-1537996194471-e657df975ab4?auto=format&fit=crop&w=1200&q=80',
        'london' => 'https://images.unsplash.com/photo-1513635269975-59663e0ac1ad?auto=format&fit=crop&w=1200&q=80',
        'paris' => 'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=format&fit=crop&w=1200&q=80',
        'singapore' => 'https://images.unsplash.com/photo-1525625293386-3f8f99389edd?auto=format&fit=crop&w=1200&q=80',
        'new york' => 'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?auto=format&fit=crop&w=1200&q=80',
        'tokyo' => 'https://images.unsplash.com/photo-1493976040374-85c8e12f0c0e?auto=format&fit=crop&w=1200&q=80',
        'maldives' => 'https://images.unsplash.com/photo-1514282401047-d79a71a590e8?auto=format&fit=crop&w=1200&q=80',
        'switzerland' => 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1200&q=80',
        'sydney' => 'https://images.unsplash.com/photo-1506973035872-a4ec16b8e8d9?auto=format&fit=crop&w=1200&q=80',
    ];
    
    foreach ($images as $key => $url) {
        if (strpos($location, $key) !== false) {
            return $url;
        }
    }
    
    return 'https://images.unsplash.com/photo-1488646953014-85cb44e25828?auto=format&fit=crop&w=1200&q=80';
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    SecurityHelper::verifyCsrfToken($_POST['csrf_token'] ?? '');

    // Permission Check: Read-only users cannot modify state
    if (($_SESSION['permission'] ?? 'edit') === 'read_only') {
        $trip_param = isset($_POST['trip_id']) ? '&trip_id=' . intval($_POST['trip_id']) : '';
        header("Location: travel_planner.php?error=" . urlencode('Unauthorized: Read-only access') . $trip_param);
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action == 'add_trip') {
        $location = trim($_POST['location'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $outbound = trim($_POST['outbound_flight_number'] ?? '');
        $outbound_time = trim($_POST['outbound_flight_time'] ?? '');
        $return = trim($_POST['return_flight_number'] ?? '');
        $return_time = trim($_POST['return_flight_time'] ?? '');

        if (empty($location) || mb_strlen($location) > 255 || !tripValidDate($start_date) || !tripValidDate($end_date)
            || mb_strlen($outbound) > 100 || mb_strlen($return) > 100 || mb_strlen($outbound_time) > 50 || mb_strlen($return_time) > 50) {
            header("Location: travel_planner.php?error=" . urlencode('Please fill in all required fields.'));
            exit();
        }

        // Automatic Flight Details Fetching
        $out_dep = null;
        $out_arr = null;
        if (!empty($outbound)) {
            $flight_info = fetchFlightDataFromAPI($outbound);
            if ($flight_info) {
                if (empty($outbound_time)) $outbound_time = $flight_info['time'];
                $out_dep = $flight_info['dep_airport'];
                $out_arr = $flight_info['arr_airport'];
            }
        }

        $ret_dep = null;
        $ret_arr = null;
        if (!empty($return)) {
            $flight_info = fetchFlightDataFromAPI($return);
            if ($flight_info) {
                if (empty($return_time)) $return_time = $flight_info['time'];
                $ret_dep = $flight_info['dep_airport'];
                $ret_arr = $flight_info['arr_airport'];
            }
        }

        $outbound_status = getAutomaticFlightStatus($outbound, $start_date);
        $return_status = getAutomaticFlightStatus($return, $end_date);

        $stmt = $pdo->prepare("INSERT INTO trips (user_id, tenant_id, location, start_date, end_date, outbound_flight_number, return_flight_number, outbound_flight_status, return_flight_status, outbound_flight_time, return_flight_time, outbound_dep_airport, outbound_arr_airport, return_dep_airport, return_arr_airport) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id, 
            $tenant_id, 
            $location, 
            $start_date, 
            $end_date, 
            empty($outbound) ? null : $outbound, 
            empty($return) ? null : $return,
            $outbound_status,
            $return_status,
            empty($outbound_time) ? null : $outbound_time,
            empty($return_time) ? null : $return_time,
            $out_dep,
            $out_arr,
            $ret_dep,
            $ret_arr
        ]);
        
        AuditHelper::log($pdo, 'add_trip', "Added trip to $location");
        header("Location: travel_planner.php?success=" . urlencode('Trip added successfully!'));
        exit();

    } elseif ($action == 'edit_trip') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $location = trim($_POST['location'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $outbound = trim($_POST['outbound_flight_number'] ?? '');
        $outbound_time = trim($_POST['outbound_flight_time'] ?? '');
        $return = trim($_POST['return_flight_number'] ?? '');
        $return_time = trim($_POST['return_flight_time'] ?? '');

        if (!$id || empty($location) || mb_strlen($location) > 255 || !tripValidDate($start_date) || !tripValidDate($end_date)
            || mb_strlen($outbound) > 100 || mb_strlen($return) > 100 || mb_strlen($outbound_time) > 50 || mb_strlen($return_time) > 50) {
            header("Location: travel_planner.php?error=" . urlencode('Invalid trip details.'));
            exit();
        }

        // Automatic Flight Details Fetching
        $out_dep = null;
        $out_arr = null;
        if (!empty($outbound)) {
            $flight_info = fetchFlightDataFromAPI($outbound);
            if ($flight_info) {
                if (empty($outbound_time)) $outbound_time = $flight_info['time'];
                $out_dep = $flight_info['dep_airport'];
                $out_arr = $flight_info['arr_airport'];
            }
        }

        $ret_dep = null;
        $ret_arr = null;
        if (!empty($return)) {
            $flight_info = fetchFlightDataFromAPI($return);
            if ($flight_info) {
                if (empty($return_time)) $return_time = $flight_info['time'];
                $ret_dep = $flight_info['dep_airport'];
                $ret_arr = $flight_info['arr_airport'];
            }
        }

        $outbound_status = getAutomaticFlightStatus($outbound, $start_date);
        $return_status = getAutomaticFlightStatus($return, $end_date);

        $stmt = $pdo->prepare("UPDATE trips SET location = ?, start_date = ?, end_date = ?, outbound_flight_number = ?, return_flight_number = ?, outbound_flight_status = ?, return_flight_status = ?, outbound_flight_time = ?, return_flight_time = ?, outbound_dep_airport = ?, outbound_arr_airport = ?, return_dep_airport = ?, return_arr_airport = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([
            $location, 
            $start_date, 
            $end_date, 
            empty($outbound) ? null : $outbound, 
            empty($return) ? null : $return, 
            $outbound_status,
            $return_status,
            empty($outbound_time) ? null : $outbound_time,
            empty($return_time) ? null : $return_time,
            $out_dep,
            $out_arr,
            $ret_dep,
            $ret_arr,
            $id, 
            $tenant_id
        ]);

        AuditHelper::log($pdo, 'edit_trip', "Updated trip ID: $id ($location)");
        header("Location: travel_planner.php?success=" . urlencode('Trip details updated!') . "&trip_id=" . (int) $id);
        exit();

    } elseif ($action == 'delete_trip') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM trips WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            AuditHelper::log($pdo, 'delete_trip', "Deleted trip ID: $id");
        }
        header("Location: travel_planner.php?success=" . urlencode('Trip deleted.'));
        exit();

    } elseif ($action == 'add_expense') {
        $trip_id = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
        $description = trim($_POST['description'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $expense_date = $_POST['expense_date'] ?? '';
        
        $tag_select = $_POST['tag_select'] ?? '';
        $tag = ($tag_select === 'custom') ? trim($_POST['tag_custom'] ?? '') : $tag_select;

        if (!$trip_id || empty($description) || mb_strlen($description) > 255 || $amount <= 0 || $amount > 9999999999999.99
            || !tripValidDate($expense_date) || empty($tag) || mb_strlen($tag) > 100) {
            header("Location: travel_planner.php?error=" . urlencode('Please fill in all expense fields correctly.') . "&trip_id=" . (int) $trip_id);
            exit();
        }

        // The trip must belong to this tenant
        $own = $pdo->prepare("SELECT id FROM trips WHERE id = ? AND tenant_id = ?");
        $own->execute([$trip_id, $tenant_id]);
        if (!$own->fetchColumn()) {
            header("Location: travel_planner.php?error=" . urlencode('Trip not found.'));
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO trip_expenses (trip_id, tenant_id, description, amount, expense_date, tag) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$trip_id, $tenant_id, $description, $amount, $expense_date, $tag]);

        AuditHelper::log($pdo, 'add_trip_expense', "Added expense of $amount for trip ID: $trip_id");
        header("Location: travel_planner.php?success=" . urlencode('Expense added!') . "&trip_id=" . (int) $trip_id);
        exit();

    } elseif ($action == 'delete_expense') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $trip_id = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);

        if ($id && $trip_id) {
            $stmt = $pdo->prepare("DELETE FROM trip_expenses WHERE id = ? AND trip_id = ? AND tenant_id = ?");
            $stmt->execute([$id, $trip_id, $tenant_id]);
            AuditHelper::log($pdo, 'delete_trip_expense', "Deleted trip expense ID: $id");
        }
        header("Location: travel_planner.php?success=" . urlencode('Expense deleted.') . "&trip_id=" . (int) $trip_id);
        exit();

    } elseif ($action == 'refresh_flights') {
        // Force refresh status & time & airports from APIs (POST + CSRF + edit permission, checked above)
        $trip_id = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("SELECT * FROM trips WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$trip_id, $tenant_id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) {
            header("Location: travel_planner.php?error=" . urlencode('Trip not found.'));
            exit();
        }

        $out_live = getAutomaticFlightStatus($trip['outbound_flight_number'], $trip['start_date']);
        $ret_live = getAutomaticFlightStatus($trip['return_flight_number'], $trip['end_date']);

        $out_time = null;
        $out_dep = null;
        $out_arr = null;
        if (!empty($trip['outbound_flight_number'])) {
            $flight_info = fetchFlightDataFromAPI($trip['outbound_flight_number']);
            if ($flight_info) {
                $out_time = $flight_info['time'];
                $out_dep = $flight_info['dep_airport'];
                $out_arr = $flight_info['arr_airport'];
            }
        }

        $ret_time = null;
        $ret_dep = null;
        $ret_arr = null;
        if (!empty($trip['return_flight_number'])) {
            $flight_info = fetchFlightDataFromAPI($trip['return_flight_number']);
            if ($flight_info) {
                $ret_time = $flight_info['time'];
                $ret_dep = $flight_info['dep_airport'];
                $ret_arr = $flight_info['arr_airport'];
            }
        }

        $update_stmt = $pdo->prepare("UPDATE trips SET outbound_flight_status = ?, return_flight_status = ?, outbound_flight_time = ?, return_flight_time = ?, outbound_dep_airport = ?, outbound_arr_airport = ?, return_dep_airport = ?, return_arr_airport = ? WHERE id = ? AND tenant_id = ?");
        $update_stmt->execute([
            $out_live,
            $ret_live,
            $out_time,
            $ret_time,
            $out_dep,
            $out_arr,
            $ret_dep,
            $ret_arr,
            (int) $trip['id'],
            $tenant_id
        ]);

        header("Location: travel_planner.php?trip_id=" . (int) $trip['id'] . "&success=" . urlencode('Flight schedules and status refreshed!'));
        exit();
    }

    header("Location: travel_planner.php");
    exit();
}

// Fetch all trips for tenant
$stmt = $pdo->prepare("SELECT t.*, (SELECT COALESCE(SUM(amount), 0) FROM trip_expenses WHERE trip_id = t.id AND tenant_id = t.tenant_id) as total_expenses FROM trips t WHERE t.tenant_id = ? ORDER BY t.start_date DESC");
$stmt->execute([$tenant_id]);
$all_trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Detailed Trip View
$selected_trip = null;
$trip_expenses = [];
$unique_tags = [];
$default_tags = ['Flight', 'Hotel', 'Food', 'Transport', 'Sightseeing', 'Shopping', 'Other'];

$selected_trip_id = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
if ($selected_trip_id) {
    $stmt = $pdo->prepare("SELECT * FROM trips WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$selected_trip_id, $tenant_id]);
    $selected_trip = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_trip) {
        // Automatically refresh flight status on page view for real-time live tracking
        $out_live = getAutomaticFlightStatus($selected_trip['outbound_flight_number'], $selected_trip['start_date']);
        $ret_live = getAutomaticFlightStatus($selected_trip['return_flight_number'], $selected_trip['end_date']);
        
        $update_fields = [];
        $update_params = [];
        
        if ($out_live !== $selected_trip['outbound_flight_status']) {
            $update_fields[] = "outbound_flight_status = ?";
            $update_params[] = $out_live;
            $selected_trip['outbound_flight_status'] = $out_live;
        }
        
        if ($ret_live !== $selected_trip['return_flight_status']) {
            $update_fields[] = "return_flight_status = ?";
            $update_params[] = $ret_live;
            $selected_trip['return_flight_status'] = $ret_live;
        }
        
        // Auto-fetch missing times and airport codes on detailed view load dynamically
        if ((empty($selected_trip['outbound_flight_time']) || empty($selected_trip['outbound_dep_airport'])) && !empty($selected_trip['outbound_flight_number'])) {
            $fetched = fetchFlightDataFromAPI($selected_trip['outbound_flight_number']);
            if ($fetched) {
                $update_fields[] = "outbound_flight_time = ?, outbound_dep_airport = ?, outbound_arr_airport = ?";
                $update_params[] = $fetched['time'];
                $update_params[] = $fetched['dep_airport'];
                $update_params[] = $fetched['arr_airport'];
                
                $selected_trip['outbound_flight_time'] = $fetched['time'];
                $selected_trip['outbound_dep_airport'] = $fetched['dep_airport'];
                $selected_trip['outbound_arr_airport'] = $fetched['arr_airport'];
            }
        }
        
        if ((empty($selected_trip['return_flight_time']) || empty($selected_trip['return_dep_airport'])) && !empty($selected_trip['return_flight_number'])) {
            $fetched = fetchFlightDataFromAPI($selected_trip['return_flight_number']);
            if ($fetched) {
                $update_fields[] = "return_flight_time = ?, return_dep_airport = ?, return_arr_airport = ?";
                $update_params[] = $fetched['time'];
                $update_params[] = $fetched['dep_airport'];
                $update_params[] = $fetched['arr_airport'];
                
                $selected_trip['return_flight_time'] = $fetched['time'];
                $selected_trip['return_dep_airport'] = $fetched['dep_airport'];
                $selected_trip['return_arr_airport'] = $fetched['arr_airport'];
            }
        }
        
        if (!empty($update_fields)) {
            $update_params[] = $selected_trip_id;
            $update_params[] = $tenant_id;
            $update_stmt = $pdo->prepare("UPDATE trips SET " . implode(', ', $update_fields) . " WHERE id = ? AND tenant_id = ?");
            $update_stmt->execute($update_params);
        }

        $stmt = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id = ? AND tenant_id = ? ORDER BY expense_date DESC");
        $stmt->execute([$selected_trip_id, $tenant_id]);
        $trip_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT DISTINCT tag FROM trip_expenses WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $custom_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $unique_tags = array_unique(array_merge($default_tags, $custom_tags));
    }
}

$has_key = !empty($_ENV['AVIATIONSTACK_KEY']) || !empty(getenv('AVIATIONSTACK_KEY'));

Layout::header();
Layout::sidebar();
?>

<!-- Scoped Custom Styling for Redesign -->
<style>
    /* Hero Banner Component */
    .trip-hero-banner {
        height: 280px;
        background-size: cover;
        background-position: center;
        border-radius: 16px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        margin-bottom: 2rem;
        transition: transform 0.3s ease;
    }
    .trip-hero-banner:hover {
        transform: translateY(-2px);
    }
    .trip-hero-overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(180deg, rgba(0,0,0,0.15) 0%, rgba(0,0,0,0.4) 40%, rgba(0,0,0,0.85) 100%);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 2rem;
    }
    .hero-top-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .hero-glass-button {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        color: #ffffff !important;
        font-weight: 500;
        transition: all 0.2s ease;
        font-size: 0.85rem;
    }
    .hero-glass-button:hover {
        background: rgba(255, 255, 255, 0.25);
        transform: translateY(-1px);
    }
    
    /* Animated Flight Route Trackers */
    .flight-tracker-card {
        border-radius: 12px;
        transition: all 0.3s ease;
    }
    .flight-tracker-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-hover);
    }
    .flight-route-animation {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 60px;
        position: relative;
    }
    .airport-code {
        font-size: 1.3rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        min-width: 60px;
    }
    .flight-path-line {
        height: 4px;
        background: #e2e8f0;
        border-radius: 2px;
        flex: 1;
        margin: 0 20px;
        position: relative;
    }
    body.theme-night .flight-path-line {
        background: #30363d;
    }
    .path-bar {
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        background: linear-gradient(to right, #3b82f6, #60a5fa);
        border-radius: 2px;
        transition: width 1.2s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    .plane-icon {
        position: absolute;
        top: -9px;
        font-size: 1.35rem;
        color: #1e3a8a;
        transition: all 1.2s cubic-bezier(0.25, 0.8, 0.25, 1);
        z-index: 2;
    }
    body.theme-night .plane-icon {
        color: #58a6ff;
    }
    
    /* Position States */
    .pos-scheduled {
        left: 0%;
        transform: rotate(0deg);
        animation: pulse-scheduled 2.5s infinite ease-in-out;
    }
    .pos-active {
        left: 48%;
        transform: rotate(0deg);
        animation: flight-vibe 4s infinite linear, plane-bob 2s infinite ease-in-out;
    }
    .pos-landed {
        left: 95%;
        transform: rotate(0deg);
        animation: pulse-landed 2.5s infinite ease-in-out;
    }
    
    /* Animations */
    @keyframes plane-bob {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-4px) rotate(1deg); }
    }
    @keyframes pulse-scheduled {
        0%, 100% { filter: drop-shadow(0 0 2px rgba(59, 130, 246, 0.4)); opacity: 0.8; }
        50% { filter: drop-shadow(0 0 10px rgba(59, 130, 246, 0.8)); opacity: 1; }
    }
    @keyframes pulse-landed {
        0%, 100% { filter: drop-shadow(0 0 2px rgba(16, 185, 129, 0.4)); }
        50% { filter: drop-shadow(0 0 10px rgba(16, 185, 129, 0.8)); }
    }
    @keyframes flight-vibe {
        0%, 100% { transform: translateX(0px); }
        50% { transform: translateX(4px); }
    }

    /* Floating cloud decorations in flight status cards */
    .cloud-bg {
        position: absolute;
        right: -10px;
        bottom: -15px;
        font-size: 6rem;
        opacity: 0.04;
        pointer-events: none;
        color: var(--text-muted);
    }
    
    /* Elegant tag buttons */
    .tag-badge {
        font-size: 0.75rem;
        font-weight: 500;
        padding: 5px 12px;
        border-radius: 20px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    body.theme-night .tag-badge {
        background: #21262d;
        color: #c9d1d9;
        border-color: #30363d;
    }
</style>

<!-- Alert Handler -->
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($_GET['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-xmark me-2"></i> <?php echo htmlspecialchars($_GET['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($selected_trip): ?>
    <?php
    $total_amount = array_sum(array_column($trip_expenses, 'amount'));
    $today = date('Y-m-d');
    $status_label = 'Upcoming';
    
    if ($today >= $selected_trip['start_date'] && $today <= $selected_trip['end_date']) {
        $status_label = 'Active Now';
    } elseif ($today > $selected_trip['end_date']) {
        $status_label = 'Completed';
    }
    ?>
    <!-- ---------------------------------------------------- -->
    <!-- DETAILED TRIP VIEW (REDESIGNED)                      -->
    <!-- ---------------------------------------------------- -->
    
    <!-- Hero Banner -->
    <div class="trip-hero-banner" style="background-image: url('<?php echo getDestinationImage($selected_trip['location']); ?>');">
        <div class="trip-hero-overlay">
            <div class="hero-top-nav w-100">
                <a href="travel_planner.php" class="btn btn-sm hero-glass-button">
                    <i class="fa-solid fa-arrow-left me-1.5"></i> Back to Trips
                </a>
                <div class="d-flex gap-2">
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <form method="POST" class="d-inline m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                            <input type="hidden" name="action" value="refresh_flights">
                            <input type="hidden" name="trip_id" value="<?php echo (int) $selected_trip['id']; ?>">
                            <button type="submit" class="btn btn-sm hero-glass-button">
                                <i class="fa-solid fa-arrows-rotate me-1.5"></i> Refresh Flight Info
                            </button>
                        </form>
                        <button class="btn btn-sm hero-glass-button" data-bs-toggle="modal" data-bs-target="#editTripModal">
                            <i class="fa-solid fa-pen-to-square me-1.5"></i> Edit Trip
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-end w-100 flex-wrap mt-auto">
                <div>
                    <h1 class="display-5 fw-bold text-white mb-1"><?php echo htmlspecialchars($selected_trip['location']); ?></h1>
                    <p class="text-white-50 mb-0">
                        <i class="fa-regular fa-calendar-days me-1.5"></i> 
                        <?php echo date('M d, Y', strtotime($selected_trip['start_date'])); ?> - <?php echo date('M d, Y', strtotime($selected_trip['end_date'])); ?>
                        <span class="ms-2 px-2 py-0.5 rounded text-white bg-primary-subtle bg-opacity-25 small fs-8"><?php echo $status_label; ?></span>
                    </p>
                </div>
                <div class="text-end">
                    <span class="text-white-50 small d-block mb-1">Travel Budget Logged</span>
                    <h2 class="display-6 fw-bold text-warning mb-0">AED <?php echo number_format($total_amount, 2); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Flight Status Board -->
    <div class="glass-panel p-4 mb-4 position-relative">
        <h5 class="fw-bold mb-4 d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-plane-departure text-primary me-2"></i> Live Flight Tracker</span>
            <span class="badge bg-success-subtle text-success fs-7"><i class="fa-solid fa-circle-dot me-1"></i> ADS-B Auto Update</span>
        </h5>
        
        <div class="row g-4">
            <!-- Outbound Flight Card -->
            <div class="col-lg-6">
                <div class="flight-tracker-card p-4 rounded-3 h-100 position-relative overflow-hidden bg-light border border-light-subtle">
                    <i class="fa-solid fa-cloud cloud-bg"></i>
                    
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Outbound Flight</span>
                            <h4 class="fw-bold text-dark mb-0">
                                <?php echo !empty($selected_trip['outbound_flight_number']) ? htmlspecialchars($selected_trip['outbound_flight_number']) : 'Not Added'; ?>
                            </h4>
                        </div>
                        <?php if (!empty($selected_trip['outbound_flight_number'])): ?>
                            <div class="text-end">
                                <span class="badge bg-dark text-white font-monospace mb-1 px-2.5 py-1">
                                    <i class="fa-regular fa-clock me-1"></i> <?php echo formatFlightTime($selected_trip['outbound_flight_time']); ?>
                                </span>
                                <span class="text-muted small d-block"><?php echo date('M d, Y', strtotime($selected_trip['start_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($selected_trip['outbound_flight_number'])): ?>
                        <?php 
                        $out_dep = $selected_trip['outbound_dep_airport'] ?? 'DEP';
                        $out_arr = $selected_trip['outbound_arr_airport'] ?? 'ARR';
                        $out_status = $selected_trip['outbound_flight_status'];
                        
                        $out_width = '0%';
                        $plane_pos_class = 'pos-scheduled';
                        
                        if ($out_status === 'Active') {
                            $out_width = '50%';
                            $plane_pos_class = 'pos-active';
                        } elseif ($out_status === 'Landed') {
                            $out_width = '100%';
                            $plane_pos_class = 'pos-landed';
                        }
                        ?>
                        <div class="flight-route-animation my-4">
                            <div class="airport-code text-start font-monospace text-primary"><?php echo Html::e($out_dep); ?></div>
                            
                            <div class="flight-path-line mx-3">
                                <div class="path-bar" style="width: <?php echo $out_width; ?>;"></div>
                                <div class="plane-icon <?php echo $plane_pos_class; ?>">
                                    <i class="fa-solid fa-plane"></i>
                                </div>
                            </div>
                            
                            <div class="airport-code text-end font-monospace text-primary"><?php echo Html::e($out_arr); ?></div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-light">
                            <span class="text-muted small">Status</span>
                            <?php 
                            $badge_class = 'bg-secondary';
                            if ($out_status === 'Active') $badge_class = 'bg-info text-dark';
                            elseif ($out_status === 'Landed') $badge_class = 'bg-success';
                            elseif ($out_status === 'Delayed') $badge_class = 'bg-warning text-dark';
                            elseif ($out_status === 'Cancelled') $badge_class = 'bg-danger';
                            ?>
                            <span class="badge <?php echo $badge_class; ?> px-3 py-1.5 fs-7 fw-semibold"><?php echo Html::e($out_status); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-muted mb-0 small">No outbound flight code provided. Edit trip to add flight information.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Return Flight Card -->
            <div class="col-lg-6">
                <div class="flight-tracker-card p-4 rounded-3 h-100 position-relative overflow-hidden bg-light border border-light-subtle">
                    <i class="fa-solid fa-cloud cloud-bg" style="left: -10px; right: auto;"></i>
                    
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="text-muted small fw-bold text-uppercase d-block mb-1">Return Flight</span>
                            <h4 class="fw-bold text-dark mb-0">
                                <?php echo !empty($selected_trip['return_flight_number']) ? htmlspecialchars($selected_trip['return_flight_number']) : 'Not Added'; ?>
                            </h4>
                        </div>
                        <?php if (!empty($selected_trip['return_flight_number'])): ?>
                            <div class="text-end">
                                <span class="badge bg-dark text-white font-monospace mb-1 px-2.5 py-1">
                                    <i class="fa-regular fa-clock me-1"></i> <?php echo formatFlightTime($selected_trip['return_flight_time']); ?>
                                </span>
                                <span class="text-muted small d-block"><?php echo date('M d, Y', strtotime($selected_trip['end_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($selected_trip['return_flight_number'])): ?>
                        <?php 
                        $ret_dep = $selected_trip['return_dep_airport'] ?? 'DEP';
                        $ret_arr = $selected_trip['return_arr_airport'] ?? 'ARR';
                        $ret_status = $selected_trip['return_flight_status'];
                        
                        $ret_width = '0%';
                        $plane_pos_class = 'pos-scheduled';
                        
                        if ($ret_status === 'Active') {
                            $ret_width = '50%';
                            $plane_pos_class = 'pos-active';
                        } elseif ($ret_status === 'Landed') {
                            $ret_width = '100%';
                            $plane_pos_class = 'pos-landed';
                        }
                        ?>
                        <div class="flight-route-animation my-4">
                            <div class="airport-code text-start font-monospace text-primary"><?php echo Html::e($ret_dep); ?></div>
                            
                            <div class="flight-path-line mx-3">
                                <div class="path-bar" style="width: <?php echo $ret_width; ?>;"></div>
                                <div class="plane-icon <?php echo $plane_pos_class; ?>">
                                    <i class="fa-solid fa-plane"></i>
                                </div>
                            </div>
                            
                            <div class="airport-code text-end font-monospace text-primary"><?php echo Html::e($ret_arr); ?></div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-light">
                            <span class="text-muted small">Status</span>
                            <?php 
                            $badge_class = 'bg-secondary';
                            if ($ret_status === 'Active') $badge_class = 'bg-info text-dark';
                            elseif ($ret_status === 'Landed') $badge_class = 'bg-success';
                            elseif ($ret_status === 'Delayed') $badge_class = 'bg-warning text-dark';
                            elseif ($ret_status === 'Cancelled') $badge_class = 'bg-danger';
                            ?>
                            <span class="badge <?php echo $badge_class; ?> px-3 py-1.5 fs-7 fw-semibold"><?php echo Html::e($ret_status); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-muted mb-0 small">No return flight code provided. Edit trip to add flight information.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Expenses Layout Below -->
    <div class="row g-4">
        <!-- Category Breakdown (Left) -->
        <div class="col-lg-4">
            <div class="glass-panel p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-chart-pie text-primary me-2"></i> Category Breakdown</h5>
                <h3 class="fw-bold text-primary mb-3">AED <?php echo number_format($total_amount, 2); ?></h3>

                <?php if (empty($trip_expenses)): ?>
                    <p class="text-muted small mb-0">No expenses logged yet.</p>
                <?php else: ?>
                    <?php
                    $breakdown = [];
                    foreach ($trip_expenses as $exp) {
                        $breakdown[$exp['tag']] = ($breakdown[$exp['tag']] ?? 0) + $exp['amount'];
                    }
                    // Sort breakdown descending
                    arsort($breakdown);
                    ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($breakdown as $tag => $amt): ?>
                            <?php $percent = $total_amount > 0 ? ($amt / $total_amount) * 100 : 0; ?>
                            <div>
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="fw-medium"><?php echo htmlspecialchars($tag); ?></span>
                                    <span class="fw-bold text-dark">AED <?php echo number_format($amt, 2); ?> <span class="text-muted font-normal" style="font-size:0.75rem;">(<?php echo round($percent); ?>%)</span></span>
                                </div>
                                <div class="progress" style="height: 6px; background: #e2e8f0;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $percent; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expenses Log (Right) -->
        <div class="col-lg-8">
            <div class="glass-panel p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fa-solid fa-receipt text-primary me-2"></i> Expenses Log</h5>
                    <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                            <i class="fa-solid fa-plus me-1"></i> Add Expense
                        </button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr class="small text-muted text-uppercase">
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <th class="text-end">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($trip_expenses)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-receipt fa-2x mb-2 opacity-25"></i>
                                        <p class="mb-0">No expenses logged for this trip.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($trip_expenses as $exp): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                                        <td><span class="fw-semibold"><?php echo htmlspecialchars($exp['description']); ?></span></td>
                                        <td>
                                            <span class="tag-badge">
                                                <?php echo htmlspecialchars($exp['tag']); ?>
                                            </span>
                                        </td>
                                        <td><span class="fw-bold text-danger">AED <?php echo number_format($exp['amount'], 2); ?></span></td>
                                        <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                            <td class="text-end">
                                                <form method="POST" style="display:inline;" data-confirm="Are you sure you want to delete this expense?">
                                                    <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                                    <input type="hidden" name="action" value="delete_expense">
                                                    <input type="hidden" name="id" value="<?php echo $exp['id']; ?>">
                                                    <input type="hidden" name="trip_id" value="<?php echo $selected_trip['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-link text-danger border-0 p-0"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Trip Modal -->
    <div class="modal fade" id="editTripModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Edit Trip Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="edit_trip">
                        <input type="hidden" name="id" value="<?php echo $selected_trip['id']; ?>">

                        <div class="mb-3">
                            <label for="editLocation" class="form-label small fw-bold">Trip Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" id="editLocation" class="form-control" value="<?php echo htmlspecialchars($selected_trip['location']); ?>" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="editStart" class="form-label small fw-bold">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="editStart" class="form-control" value="<?php echo $selected_trip['start_date']; ?>" required>
                            </div>
                            <div class="col-6">
                                <label for="editEnd" class="form-label small fw-bold">End Date <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" id="editEnd" class="form-control" value="<?php echo $selected_trip['end_date']; ?>" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="editOutbound" class="form-label small fw-bold">Outbound Flight Number</label>
                                <input type="text" name="outbound_flight_number" id="editOutbound" class="form-control" placeholder="e.g. EK398" value="<?php echo htmlspecialchars($selected_trip['outbound_flight_number'] ?? ''); ?>">
                            </div>
                            <div class="col-6">
                                <label for="editOutboundTime" class="form-label small fw-bold">Outbound Flight Time</label>
                                <input type="time" name="outbound_flight_time" id="editOutboundTime" class="form-control" value="<?php echo htmlspecialchars($selected_trip['outbound_flight_time'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="editReturn" class="form-label small fw-bold">Return Flight Number</label>
                                <input type="text" name="return_flight_number" id="editReturn" class="form-control" placeholder="e.g. EK399" value="<?php echo htmlspecialchars($selected_trip['return_flight_number'] ?? ''); ?>">
                            </div>
                            <div class="col-6">
                                <label for="editReturnTime" class="form-label small fw-bold">Return Flight Time</label>
                                <input type="time" name="return_flight_time" id="editReturnTime" class="form-control" value="<?php echo htmlspecialchars($selected_trip['return_flight_time'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <?php if (!$has_key): ?>
                            <div class="form-text text-muted x-small mb-3"><i class="fa-solid fa-circle-info me-1"></i> Add <code>AVIATIONSTACK_KEY</code> to <code>.env</code> to automatically fetch flight schedules.</div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary w-100 fw-bold">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Add Trip Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="add_expense">
                        <input type="hidden" name="trip_id" value="<?php echo $selected_trip['id']; ?>">

                        <div class="mb-3">
                            <label for="expDesc" class="form-label small fw-bold">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" id="expDesc" class="form-control" placeholder="e.g. Dinner at Airport" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="expAmt" class="form-label small fw-bold">Amount (AED) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="amount" id="expAmt" class="form-control" placeholder="0.00" required>
                            </div>
                            <div class="col-6">
                                <label for="expDate" class="form-label small fw-bold">Date <span class="text-danger">*</span></label>
                                <input type="date" name="expense_date" id="expDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="tagSelect" class="form-label small fw-bold">Tag <span class="text-danger">*</span></label>
                            <select name="tag_select" id="tagSelect" class="form-select" data-onchange="toggleCustomTag" data-args='["$value"]'>
                                <?php foreach ($unique_tags as $tag_item): ?>
                                    <option value="<?php echo htmlspecialchars($tag_item); ?>"><?php echo htmlspecialchars($tag_item); ?></option>
                                <?php endforeach; ?>
                                <option value="custom">[+ Create Custom Tag]</option>
                            </select>
                        </div>

                        <div class="mb-4" id="customTagDiv" style="display: none;">
                            <label for="customTagInput" class="form-label small fw-bold">Custom Tag Name <span class="text-danger">*</span></label>
                            <input type="text" name="tag_custom" id="customTagInput" class="form-control" placeholder="e.g. Souvenirs">
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold">Save Expense</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?php echo $GLOBALS['csp_nonce'] ?? ''; ?>">
        function toggleCustomTag(val) {
            const div = document.getElementById('customTagDiv');
            const input = document.getElementById('customTagInput');
            if (val === 'custom') {
                div.style.display = 'block';
                input.required = true;
            } else {
                div.style.display = 'none';
                input.required = false;
            }
        }
    </script>

<?php else: ?>
    <!-- ---------------------------------------------------- -->
    <!-- OVERVIEW LIST OF TRIPS                               -->
    <!-- ---------------------------------------------------- -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Travel Planner</h1>
            <p class="text-muted mb-0">Plan trips, track flights, and manage dedicated travel budgets.</p>
        </div>
        <div>
            <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addTripModal">
                    <i class="fa-solid fa-plus me-2"></i> Plan a Trip
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trips Grid -->
    <?php if (empty($all_trips)): ?>
        <div class="text-center py-5 glass-panel">
            <div class="mb-3 text-muted opacity-25">
                <i class="fa-solid fa-passport fa-4x"></i>
            </div>
            <h5 class="text-muted">No trips planned yet.</h5>
            <p class="text-muted small">Going somewhere? Add a trip location and track flight status + dedicated expenses.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($all_trips as $trip): ?>
                <?php
                $today = date('Y-m-d');
                $status_label = 'Upcoming';
                $border_class = 'border-info';
                
                if ($today >= $trip['start_date'] && $today <= $trip['end_date']) {
                    $status_label = 'Active Now';
                    $border_class = 'border-success';
                } elseif ($today > $trip['end_date']) {
                    $status_label = 'Completed';
                    $border_class = 'border-secondary';
                }
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="glass-panel p-4 h-100 border-top border-4 <?php echo $border_class; ?> d-flex flex-column justify-content-between" style="min-height: 280px;">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($trip['location']); ?></h4>
                                <span class="badge bg-light text-dark border"><?php echo $status_label; ?></span>
                            </div>
                            <p class="small text-muted mb-3">
                                <i class="fa-regular fa-calendar me-1"></i>
                                <?php echo date('M d', strtotime($trip['start_date'])); ?> - <?php echo date('M d, Y', strtotime($trip['end_date'])); ?>
                            </p>

                            <!-- Flight tracker summaries -->
                            <div class="p-3 bg-light rounded d-flex flex-column gap-2 mb-2">
                                <div class="d-flex align-items-center justify-content-between small">
                                    <span><i class="fa-solid fa-plane-departure text-primary me-1.5" style="font-size:0.8rem;"></i> Outbound</span>
                                    <span class="fw-bold text-dark">
                                        <?php if (!empty($trip['outbound_flight_number'])): ?>
                                            <?php echo htmlspecialchars($trip['outbound_flight_number']); ?>
                                            <?php if (!empty($trip['outbound_flight_time'])): ?>
                                                <span class="text-muted font-monospace" style="font-size:0.75rem;">(<?php echo formatFlightTime($trip['outbound_flight_time']); ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center justify-content-between small">
                                    <span><i class="fa-solid fa-plane-arrival text-primary me-1.5" style="font-size:0.8rem;"></i> Return</span>
                                    <span class="fw-bold text-dark">
                                        <?php if (!empty($trip['return_flight_number'])): ?>
                                            <?php echo htmlspecialchars($trip['return_flight_number']); ?>
                                            <?php if (!empty($trip['return_flight_time'])): ?>
                                                <span class="text-muted font-monospace" style="font-size:0.75rem;">(<?php echo formatFlightTime($trip['return_flight_time']); ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            None
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex align-items-center justify-content-between">
                            <div>
                                <span class="small text-muted d-block">Expenses</span>
                                <span class="fw-bold text-danger">AED <?php echo number_format($trip['total_expenses'], 2); ?></span>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="travel_planner.php?trip_id=<?php echo $trip['id']; ?>" class="btn btn-sm btn-primary">
                                    Manage
                                </a>
                                <?php if (($_SESSION['permission'] ?? 'edit') !== 'read_only'): ?>
                                    <form method="POST" style="display:inline;" data-confirm="Are you sure you want to delete this trip and all its expenses?">
                                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="delete_trip">
                                        <input type="hidden" name="id" value="<?php echo $trip['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Add Trip Modal -->
    <div class="modal fade" id="addTripModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-panel border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Plan New Trip</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo SecurityHelper::generateCsrfToken(); ?>">
                        <input type="hidden" name="action" value="add_trip">

                        <div class="mb-3">
                            <label for="addLocation" class="form-label small fw-bold">Trip Location <span class="text-danger">*</span></label>
                            <input type="text" name="location" id="addLocation" class="form-control" placeholder="e.g. Bali, Indonesia" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="addStart" class="form-label small fw-bold">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="addStart" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-6">
                                <label for="addEnd" class="form-label small fw-bold">End Date <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" id="addEnd" class="form-control" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="addOutbound" class="form-label small fw-bold">Outbound Flight Number</label>
                                <input type="text" name="outbound_flight_number" id="addOutbound" class="form-control" placeholder="e.g. EK398">
                            </div>
                            <div class="col-6">
                                <label for="addOutboundTime" class="form-label small fw-bold">Outbound Flight Time</label>
                                <input type="time" name="outbound_flight_time" id="addOutboundTime" class="form-control">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="addReturn" class="form-label small fw-bold">Return Flight Number</label>
                                <input type="text" name="return_flight_number" id="addReturn" class="form-control" placeholder="e.g. EK399">
                            </div>
                            <div class="col-6">
                                <label for="addReturnTime" class="form-label small fw-bold">Return Flight Time</label>
                                <input type="time" name="return_flight_time" id="addReturnTime" class="form-control">
                            </div>
                        </div>
                        
                        <?php if (!$has_key): ?>
                            <div class="form-text text-muted x-small mb-3"><i class="fa-solid fa-circle-info me-1"></i> Add <code>AVIATIONSTACK_KEY</code> to <code>.env</code> to automatically fetch flight schedules.</div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary w-100 fw-bold">Plan Trip</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php Layout::footer(); ?>
