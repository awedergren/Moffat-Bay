<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 18, 2026
Project: Moffat Bay Marina Project
File: employee_dashboard.php
  Employee Dashboard
  Allows employees to search and manage reservations, waitlist entries, users, and boats.
*/
session_start();

// Detect likely AJAX/JSON requests so we can return JSON responses instead of HTML redirects
$isAjaxRequest = !empty($_REQUEST['ajax'])
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

// For AJAX requests, capture any accidental HTML output and return it as JSON for easier debugging on the client.
if ($isAjaxRequest) {
    // Append a short debug record (headers, session, POST) to a local log file for troubleshooting.
    try {
        $dbg = [
            'ts' => microtime(true),
            'session_id' => session_id(),
            'session_is_employee' => !empty($_SESSION['is_employee']),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'post' => $_POST,
            'get' => $_GET,
            'request' => $_REQUEST,
        ];
        if (function_exists('getallheaders')) { $dbg['headers'] = getallheaders(); }
        else {
            $hdrs = [];
            foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $hdrs[$k] = $v; } }
            $dbg['headers'] = $hdrs;
        }
        @file_put_contents(__DIR__ . '/ajax_debug.log', json_encode($dbg) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Exception $_) { /* ignore logging errors */ }
    if (!ob_get_level()) ob_start();
    register_shutdown_function(function() {
        // If a JSON content-type was already sent by the handler, let it pass through.
        $headers = headers_list();
        $hasJson = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'application/json') !== false) { $hasJson = true; break; }
        }
        // If no JSON header, convert any buffered HTML into a JSON error wrapper so client parsing won't fail.
        if (!$hasJson) {
            $out = '';
            try { $out = ob_get_clean() ?: ''; } catch (Exception $_) { $out = ''; }
            http_response_code(http_response_code() ?: 200);
            header('Content-Type: application/json');
            $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', preg_replace('/<!--.*?-->/s','',$out))), 0, 2000);
            echo json_encode(['status' => 'unexpected_html', 'message' => 'Server returned HTML for an AJAX request; HTML snippet included for debugging', 'html_snippet' => $snippet]);
            // ensure any other output is discarded
            exit;
        }
        // otherwise flush normally
        if (ob_get_level()) @ob_end_flush();
    });
}

if (empty($_SESSION['is_employee'])) {
    if ($isAjaxRequest) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'auth_required', 'message' => 'Authentication required']);
        exit;
    }
    header('Location: BlueTeam_LoginPage.php');
    exit;
}

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function formatDate($d){ if (empty($d)) return ''; $t = strtotime($d); if ($t===false) return htmlspecialchars($d); return date('F j, Y', $t); }

function formatDateShort($d){ if (empty($d)) return ''; $t = strtotime($d); if ($t===false) return htmlspecialchars($d); return date('M j, Y', $t); }

$pdo = null;
$dbPaths = [ __DIR__ . '/config/db.php', __DIR__ . '/db.php', __DIR__ . '/../db.php' ];
foreach ($dbPaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        if (isset($pdo)) break;
        if (isset($conn)) { $pdo = $conn; break; }
    }
}

// fetch distinct values for filters (safe, non-fatal)
$availableStatuses = [];
$availableSlipSizes = [];
$availableDocks = [];
try {
    if ($pdo) {
        $st = $pdo->query("SELECT DISTINCT reservation_status FROM reservations WHERE reservation_status IS NOT NULL");
        $availableStatuses = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
        $ss = $pdo->query("SELECT DISTINCT slip_size FROM slips WHERE slip_size IS NOT NULL ORDER BY slip_size");
        $availableSlipSizes = $ss ? $ss->fetchAll(PDO::FETCH_COLUMN) : [];
        $sd = $pdo->query("SELECT DISTINCT location_code FROM slips WHERE location_code IS NOT NULL ORDER BY location_code");
        $availableDocks = $sd ? $sd->fetchAll(PDO::FETCH_COLUMN) : [];
    }
} catch (Exception $ex) {
    // ignore; leave arrays empty
}

// Ensure employee_payments exists with expected columns used in queries (defensive)
try {
    if ($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_ID INT,
            amount DECIMAL(10,2),
            method VARCHAR(50),
            card_last4 VARCHAR(10) NULL,
            employee_ID INT,
            date_created DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ensure card_last4 column exists (older installs may not have it)
        $col = $pdo->query("SHOW COLUMNS FROM employee_payments LIKE 'card_last4'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE employee_payments ADD COLUMN card_last4 VARCHAR(10) NULL AFTER method");
        }
    }
} catch (Exception $ex) { /* ignore creation errors; searches will fail later with DB error shown */ }

// JSON endpoint: fetch boats for a given user_ID, grouped by length and ordered alphabetically
if ($pdo && isset($_GET['fetch_boats_for_user'])) {
    $uid = intval($_GET['fetch_boats_for_user']);
    header('Content-Type: application/json');
    try {
        // fetch user's email (may be null)
        $uemail = null;
        $ue = $pdo->prepare('SELECT email FROM users WHERE user_ID = ? LIMIT 1');
        $ue->execute([$uid]);
        $uemail = $ue->fetchColumn() ?: null;

        // build flexible boats query depending on available columns
        $conds = [];
        $params = [];
        $colUser = $pdo->query("SHOW COLUMNS FROM boats LIKE 'user_ID'")->fetch(PDO::FETCH_ASSOC);
        if ($colUser) { $conds[] = 'b.user_ID = ?'; $params[] = $uid; }
        $colOwnerId = $pdo->query("SHOW COLUMNS FROM boats LIKE 'owner_id'")->fetch(PDO::FETCH_ASSOC);
        if ($colOwnerId) { $conds[] = 'b.owner_id = ?'; $params[] = $uid; }
        $colOwnerEmail = $pdo->query("SHOW COLUMNS FROM boats LIKE 'owner_email'")->fetch(PDO::FETCH_ASSOC);
        if ($colOwnerEmail && $uemail) { $conds[] = 'b.owner_email = ?'; $params[] = $uemail; }

        if (empty($conds)) { echo json_encode([]); exit; }

        $sql = 'SELECT b.* FROM boats b WHERE ' . implode(' OR ', $conds) . ' ORDER BY b.boat_length DESC, b.boat_name ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $boats = $st->fetchAll(PDO::FETCH_ASSOC);

        // group by boat_length
        $grouped = [];
        foreach ($boats as $b) {
            $len = isset($b['boat_length']) && $b['boat_length'] !== '' ? $b['boat_length'] : 'Unknown';
            if (!isset($grouped[$len])) $grouped[$len] = [];
            $grouped[$len][] = $b;
        }
        // ensure groups sorted: lengths already ordered by query, keep them
        echo json_encode($grouped);
    } catch (Exception $ex) {
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}
        

$statusMsg = '';
$errors = [];
$searchResults = [];
$editReservation = null;
$editWaitlist = null;
$editUser = null;
$editBoat = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = $_POST['action'] ?? '';

    try {
        // support Post-Redirect-Get for searches so refresh clears form inputs
        $isPostSearch = ($action === 'search');
        if ($action === 'search') {
            $scope = $_POST['scope'] ?? 'reservations';
            $resultType = $scope;
            $taskType = $_POST['task_type'] ?? '';
            // task-driven searches
            if (!empty($taskType) && $taskType === 'checkins_today') {
                $sql = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length, s.slip_size, s.location_code, (SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) AS payments_count, (SELECT ep.amount FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_amount, (SELECT ep.card_last4 FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_last4, (SELECT ep.method FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_method FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID LEFT JOIN boats b ON r.boat_ID = b.boat_ID LEFT JOIN slips s ON r.slip_ID = s.slip_ID WHERE DATE(r.start_date) = CURDATE() AND (r.reservation_status = 'confirmed' OR r.reservation_status = 'pending') ORDER BY r.start_date ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([]);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $resultType = 'tasks_checkins';
            }
            elseif (!empty($taskType) && $taskType === 'payments_due_24h') {
                // reservations starting within the next 24 hours that have no recorded payments and are not checked in/completed/canceled
                $sql = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length, s.slip_size, s.location_code, (SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) AS payments_count, (SELECT ep.amount FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_amount, (SELECT ep.card_last4 FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_last4, (SELECT ep.method FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_method FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID LEFT JOIN boats b ON r.boat_ID = b.boat_ID LEFT JOIN slips s ON r.slip_ID = s.slip_ID WHERE r.start_date >= NOW() AND r.start_date <= DATE_ADD(NOW(), INTERVAL 1 DAY) AND COALESCE(r.reservation_status,'') NOT IN ('checked_in','completed','canceled') HAVING payments_count = 0 ORDER BY r.start_date ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([]);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $resultType = 'tasks_payments_due';
            }
            elseif (!empty($taskType) && $taskType === 'completions_today') {
                $sql = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length, s.slip_size, s.location_code, (SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) AS payments_count, (SELECT ep.amount FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_amount, (SELECT ep.card_last4 FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_last4, (SELECT ep.method FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID ORDER BY ep.date_created DESC LIMIT 1) AS last_payment_method FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID LEFT JOIN boats b ON r.boat_ID = b.boat_ID LEFT JOIN slips s ON r.slip_ID = s.slip_ID WHERE DATE(r.end_date) = CURDATE() AND r.reservation_status = 'checked_in' ORDER BY r.end_date ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([]);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $resultType = 'tasks_completions';
            }
            elseif (!empty($taskType) && $taskType === 'waitlist_deletions') {
                // select waitlist entries with preferred_start_date within 72 hours and check availability per entry
                $stmt = $pdo->prepare("SELECT w.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length FROM waitlist w LEFT JOIN users u ON w.user_ID = u.user_ID LEFT JOIN boats b ON w.boat_ID = b.boat_ID WHERE w.preferred_start_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND w.preferred_start_date >= CURDATE() ORDER BY w.preferred_start_date ASC");
                $stmt->execute([]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $searchResults = [];
                foreach ($candidates as $w) {
                    $size = $w['preferred_slip_size'] ?? null;
                    $start = $w['preferred_start_date'] ?? null;
                    $end = $w['preferred_end_date'] ?? null;
                    if (empty($size) || empty($start) || empty($end)) continue;
                    // count total slips of that size
                    $sc = $pdo->prepare('SELECT COUNT(*) FROM slips WHERE slip_size = ?');
                    $sc->execute([$size]);
                    $totalSlips = intval($sc->fetchColumn() ?? 0);
                    // count reserved slips overlapping the preferred window for that size
                    $rc = $pdo->prepare("SELECT COUNT(DISTINCT r.slip_ID) FROM reservations r JOIN slips sl ON r.slip_ID = sl.slip_ID WHERE sl.slip_size = ? AND r.reservation_status IN ('confirmed','checked_in') AND NOT (r.end_date < ? OR r.start_date > ?)");
                    $rc->execute([$size, $start, $end]);
                    $reserved = intval($rc->fetchColumn() ?? 0);
                    $available = max(0, $totalSlips - $reserved);
                    if ($available === 0) {
                        $searchResults[] = $w;
                    }
                }
                $resultType = 'tasks_waitlist_delete';
            }
            else {
                // regular searches continue below
            }
                // Reservations search (default)
                if ($scope === 'reservations') {
                $clauses = [];
                $params = [];
                if (!empty($_POST['date'])) {
                    $clauses[] = '(COALESCE(r.start_date, r.end_date) = ?)';
                    $params[] = $_POST['date'];
                }

                if (!empty($_POST['dock'])) {
                    $clauses[] = 's.location_code = ?';
                    $params[] = $_POST['dock'];
                }
                if (!empty($_POST['email'])) {
                    $clauses[] = 'u.email = ?';
                    $params[] = $_POST['email'];
                }
                if (!empty($_POST['filter_status'])) { $clauses[] = 'r.reservation_status = ?'; $params[] = $_POST['filter_status']; }
                if (!empty($_POST['filter_slip_size'])) { $clauses[] = 's.slip_size = ?'; $params[] = $_POST['filter_slip_size']; }
                if (!empty($_POST['filter_owner'])) { $clauses[] = '(u.first_name LIKE ? OR u.last_name LIKE ?)'; $params[] = '%'.$_POST['filter_owner'].'%'; $params[] = '%'.$_POST['filter_owner'].'%'; }
                if (!empty($_POST['filter_phone'])) { $clauses[] = '(u.phone = ? OR u.phone LIKE ?)'; $params[] = $_POST['filter_phone']; $params[] = '%'.$_POST['filter_phone'].'%'; }
                if (!empty($_POST['filter_boat'])) { $clauses[] = 'b.boat_name LIKE ?'; $params[] = '%'.$_POST['filter_boat'].'%'; }
                if (!empty($_POST['filter_payment'])) {
                    if ($_POST['filter_payment'] === 'paid') {
                        $clauses[] = '(SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) > 0';
                    } elseif ($_POST['filter_payment'] === 'unpaid') {
                        $clauses[] = '(SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) = 0';
                    }
                }
                $where = count($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
                // Order primarily by start date (soonest first), secondarily by slip size for stable grouping
                $sql = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length, s.slip_size, s.location_code, (SELECT COUNT(*) FROM employee_payments ep WHERE ep.reservation_ID = r.reservation_ID) AS payments_count FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID LEFT JOIN boats b ON r.boat_ID = b.boat_ID LEFT JOIN slips s ON r.slip_ID = s.slip_ID $where ORDER BY COALESCE(r.start_date,r.end_date) ASC, s.slip_size ASC LIMIT 200";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // move canceled reservations to the end for clearer listing
                if (!empty($searchResults) && is_array($searchResults)) {
                    $others = [];
                    $completed = [];
                    $canceled = [];
                    foreach ($searchResults as $rr) {
                        $st = strtolower(trim((string)($rr['reservation_status'] ?? '')));
                        if ($st === 'canceled' || $st === 'cancelled') { $canceled[] = $rr; }
                        elseif ($st === 'completed') { $completed[] = $rr; }
                        else { $others[] = $rr; }
                    }
                    // order: active/other -> completed -> canceled
                    $searchResults = array_merge($others, $completed, $canceled);

                    // Group and sort results by slip_size but keep category ordering:
                    // 1) active/other, 2) completed, 3) canceled. Each category is grouped by slip_size
                    $final_flat = [];
                    $categories = [ 'others' => $others, 'completed' => $completed, 'canceled' => $canceled ];
                    foreach ($categories as $catRows) {
                        if (empty($catRows)) continue;
                        $groups = [];
                        foreach ($catRows as $rr) {
                            $size = isset($rr['slip_size']) && $rr['slip_size'] !== '' ? $rr['slip_size'] : 'Unknown';
                            if (!isset($groups[$size])) $groups[$size] = ['rows' => [], 'min_ts' => PHP_INT_MAX];
                            $groups[$size]['rows'][] = $rr;
                            $d = !empty($rr['start_date']) ? strtotime($rr['start_date']) : (!empty($rr['end_date']) ? strtotime($rr['end_date']) : PHP_INT_MAX);
                            if ($d !== false && $d < $groups[$size]['min_ts']) $groups[$size]['min_ts'] = $d;
                        }
                        // sort rows within each group by start date
                        foreach ($groups as &$g) {
                            usort($g['rows'], function($a,$b){
                                $ta = !empty($a['start_date']) ? strtotime($a['start_date']) : (!empty($a['end_date']) ? strtotime($a['end_date']) : PHP_INT_MAX);
                                $tb = !empty($b['start_date']) ? strtotime($b['start_date']) : (!empty($b['end_date']) ? strtotime($b['end_date']) : PHP_INT_MAX);
                                return $ta <=> $tb;
                            });
                        }
                        unset($g);
                        // order groups by earliest start date within each group
                        uasort($groups, function($a,$b){ return $a['min_ts'] <=> $b['min_ts']; });
                        // append flattened category groups to final array
                        foreach ($groups as $g) {
                            foreach ($g['rows'] as $rrow) $final_flat[] = $rrow;
                        }
                    }
                    $searchResults = $final_flat;
                }
            }

            
            // Waitlist search
            elseif ($scope === 'waitlist') {
                $clauses = [];
                $params = [];
                if (!empty($_POST['date'])) {
                    $clauses[] = '(w.preferred_start_date = ? OR w.preferred_end_date = ?)';
                    $params[] = $_POST['date']; $params[] = $_POST['date'];
                }

                if (!empty($_POST['email'])) {
                    $clauses[] = 'u.email = ?';
                    $params[] = $_POST['email'];
                }
                if (!empty($_POST['filter_slip_size'])) { $clauses[] = 'w.preferred_slip_size = ?'; $params[] = $_POST['filter_slip_size']; }
                if (!empty($_POST['filter_owner'])) { $clauses[] = '(u.first_name LIKE ? OR u.last_name LIKE ?)'; $params[] = '%'.$_POST['filter_owner'].'%'; $params[] = '%'.$_POST['filter_owner'].'%'; }
                if (!empty($_POST['filter_phone'])) { $clauses[] = '(u.phone = ? OR u.phone LIKE ?)'; $params[] = $_POST['filter_phone']; $params[] = '%'.$_POST['filter_phone'].'%'; }
                if (!empty($_POST['filter_boat'])) { $clauses[] = 'b.boat_name LIKE ?'; $params[] = '%'.$_POST['filter_boat'].'%'; }
                $where = count($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
                $sql = "SELECT w.*, u.email, u.first_name, u.last_name, u.phone AS user_phone, b.boat_name, b.boat_length FROM waitlist w LEFT JOIN users u ON w.user_ID = u.user_ID LEFT JOIN boats b ON w.boat_ID = b.boat_ID $where ORDER BY COALESCE(w.preferred_start_date, w.date_created) ASC, w.position_in_queue ASC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            // Users search
            elseif ($scope === 'users') {
                $clauses = [];
                $params = [];
                if (!empty($_POST['email'])) { $clauses[] = 'email = ?'; $params[] = $_POST['email']; }
                if (!empty($_POST['date'])) { $clauses[] = 'date_created = ?'; $params[] = $_POST['date']; }
                $where = count($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
                $sql = "SELECT user_ID, email, first_name, last_name, date_created FROM users $where LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            // Boats search
            elseif ($scope === 'boats') {
                $clauses = [];
                $params = [];

                if (!empty($_POST['email'])) { $clauses[] = 'b.owner_email = ? OR b.owner_email = ?'; $params[] = $_POST['email']; $params[] = $_POST['email']; }
                $where = count($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';

                // Build boats query defensively: detect which owner column exists and join users accordingly
                $ownerSelect = '';
                $ownerJoin = '';
                try {
                    if ($pdo) {
                        $col = $pdo->query("SHOW COLUMNS FROM boats LIKE 'owner_email'")->fetch(PDO::FETCH_ASSOC);
                        if ($col) {
                            $ownerJoin = " LEFT JOIN users u ON b.owner_email = u.email ";
                            $ownerSelect = ", u.first_name, u.last_name, u.email AS owner_email";
                        } else {
                            // try common alternative column names
                            $col2 = $pdo->query("SHOW COLUMNS FROM boats LIKE 'user_ID'")->fetch(PDO::FETCH_ASSOC);
                            if ($col2) {
                                $ownerJoin = " LEFT JOIN users u ON b.user_ID = u.user_ID ";
                                $ownerSelect = ", u.first_name, u.last_name, u.email AS owner_email";
                            } else {
                                $col3 = $pdo->query("SHOW COLUMNS FROM boats LIKE 'owner_id'")->fetch(PDO::FETCH_ASSOC);
                                if ($col3) {
                                    $ownerJoin = " LEFT JOIN users u ON b.owner_id = u.user_ID ";
                                    $ownerSelect = ", u.first_name, u.last_name, u.email AS owner_email";
                                }
                            }
                        }
                    }
                } catch (Exception $ex) {
                    // ignore and fall back to selecting boats only
                    $ownerJoin = '';
                    $ownerSelect = '';
                }

                $sql = "SELECT b.* $ownerSelect FROM boats b $ownerJoin $where LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            // Payments search
            elseif ($scope === 'payments') {
                $clauses = [];
                $params = [];
                if (!empty($_POST['date'])) { $clauses[] = 'DATE(ep.date_created) = ?'; $params[] = $_POST['date']; }
                if (!empty($_POST['email'])) { $clauses[] = 'u.email = ?'; $params[] = $_POST['email']; }
                $where = count($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
                $sql = "SELECT ep.*, u.email, r.confirmation_number FROM employee_payments ep LEFT JOIN reservations r ON ep.reservation_ID = r.reservation_ID LEFT JOIN users u ON r.user_ID = u.user_ID $where ORDER BY ep.date_created DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            // if this was a POST search, stash results (and debug info) in session and redirect (PRG)
            if ($isPostSearch) {
                // store results and type so the redirected GET can render them
                $_SESSION['employee_search_results'] = $searchResults;
                $_SESSION['employee_search_resultType'] = $resultType ?? null;
                // store last SQL + params (if present) for quick debugging
                $_SESSION['employee_search_debug'] = [
                    'sql' => isset($sql) ? $sql : null,
                    'params' => isset($params) ? $params : []
                ];
                // redirect to clear POST and reset form fields on reload
                header('Location: ' . basename(__FILE__) . '?show_search=1');
                exit;
            }
        }

        // Reservation actions
        if ($action === 'edit_reservation' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
            $s->execute([$id]);
            $editReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!empty($editReservation)) {
                // attempt to enrich reservation with slip size and location from slips table
                $slipId = $editReservation['slip_ID'] ?? $editReservation['slipId'] ?? $editReservation['slip_id'] ?? null;
                if (!empty($slipId)) {
                    try {
                        $rq = $pdo->prepare("SELECT COALESCE(slip_size,size,length_ft,size_ft) AS slip_size, COALESCE(location_code,location,slip_number,CONCAT('Slip ', slip_ID)) AS location_code FROM slips WHERE slip_ID = ? LIMIT 1");
                        $rq->execute([$slipId]);
                        $srow = $rq->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($srow) {
                            if (!empty($srow['slip_size'])) $editReservation['slip_size'] = $srow['slip_size'];
                            if (!empty($srow['location_code'])) $editReservation['location_code'] = $srow['location_code'];
                        }
                    } catch (Exception $_) { /* ignore */ }
                }
            }
        }

        if ($action === 'save_reservation' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            // allow employees to edit any reservation column (except primary key)
            $sets = [];
            $params = [];
            // fetch reservation column names defensively
            $cols = [];
            try {
                $colRows = $pdo->query("SHOW COLUMNS FROM reservations")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($colRows as $c) { $cols[] = $c['Field']; }
            } catch (Exception $ex) { $cols = []; }

            // If the form provided a location_code (from slips) but the reservations table stores slip_ID,
            // translate the submitted location_code into the corresponding slip_ID so the reservation can be updated.
            try {
                if (!empty($_POST['location_code']) && empty($_POST['slip_ID']) && in_array('slip_ID', $cols, true)) {
                    $loc = trim($_POST['location_code']);
                    if ($loc !== '') {
                        $lk = $pdo->prepare('SELECT slip_ID FROM slips WHERE location_code = ? LIMIT 1');
                        $lk->execute([$loc]);
                        $found = $lk->fetchColumn();
                        if ($found) { $_POST['slip_ID'] = $found; }
                    }
                }
            } catch (Exception $_) { /* ignore mapping failures */ }

            // build proposed updates from POST keys that exist as columns (exclude reservation_ID)
            foreach ($_POST as $k => $v) {
                if ($k === 'reservation_id' || $k === 'action' || $k === 'confirm_password' || $k === 'ajax') continue;
                if (!empty($cols) && !in_array($k, $cols)) continue;
                $sets[] = "$k = ?";
                $params[] = $v === '' ? null : $v;
            }

            if ($sets) {
                // require employee password to save edits
                $requirePass = !empty($_SESSION['is_employee']);

                // Detect attempted changes even for fields that may not map directly to reservations columns
                $attemptedChange = false;
                foreach ($_POST as $k => $v) {
                    if (in_array($k, ['reservation_id','action','ajax'])) continue;
                    if ($v !== null && $v !== '') { $attemptedChange = true; break; }
                }

                if ($requirePass && $attemptedChange) {
                    $pwd = $_POST['confirm_password'] ?? '';
                    if (empty($pwd)) {
                        $errors[] = 'Please enter your employee password to save the changes to this reservation.';
                        $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $s->execute([$id]); $editReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                        // clear sets to avoid performing updates when password missing
                        $sets = [];
                    }
                }

                if ($requirePass) {
                    $pwd = $_POST['confirm_password'] ?? '';
                    // If employee attempted to change fields but did not provide password, block and show error
                    if ($attemptedChange && empty($pwd)) {
                        // error already set above; ensure editReservation refreshed
                        $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $s->execute([$id]); $editReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                        // clear sets to avoid accidental updates
                        $sets = [];
                    } else {
                        if (!empty($pwd)) {
                            $empId = intval($_SESSION['employee_id'] ?? 0);
                            $ep = $pdo->prepare('SELECT password FROM Employees WHERE employee_ID = ? LIMIT 1');
                            $ep->execute([$empId]);
                            $hash = $ep->fetchColumn() ?: '';
                            if (!password_verify($pwd, $hash)) {
                                $errors[] = 'Invalid employee password.';
                                $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $s->execute([$id]); $editReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                            } else {
                                // availability check: if dates or slip changed, ensure target slip/size is available
                                try {
                                    $curStmt = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
                                    $curStmt->execute([$id]);
                                    $current = $curStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                                } catch (Exception $ex) { $current = []; }

                                $new_start = $_POST['start_date'] ?? ($current['start_date'] ?? null);
                                $new_end = $_POST['end_date'] ?? ($current['end_date'] ?? null);
                                $new_slip_id = isset($_POST['slip_ID']) ? ($_POST['slip_ID'] === '' ? null : intval($_POST['slip_ID'])) : ($current['slip_ID'] ?? null);
                                $needAvailabilityCheck = false;
                                if ($new_start || $new_end) {
                                    if (($new_start !== ($current['start_date'] ?? null)) || ($new_end !== ($current['end_date'] ?? null))) $needAvailabilityCheck = true;
                                }
                                if (isset($_POST['slip_ID']) && intval($current['slip_ID'] ?? 0) !== intval($new_slip_id ?? 0)) $needAvailabilityCheck = true;

                                // compute months_duration based on new dates when available
                                if ($new_start && $new_end) {
                                    $startTs = strtotime($new_start);
                                    $endTs = strtotime($new_end);
                                    $days = 1;
                                    if ($startTs && $endTs && $endTs > $startTs) { $days = ceil(($endTs - $startTs) / (24*3600)); }
                                    $months = max(1, (int)ceil($days / 30));
                                    // avoid duplicating months_duration set if already provided
                                    $already = false;
                                    foreach ($sets as $ss) { if (stripos($ss, 'months_duration') !== false) { $already = true; break; } }
                                    if (!$already) { $sets[] = 'months_duration = ?'; $params[] = $months; }
                                }

                                if ($needAvailabilityCheck && $new_start && $new_end) {
                                    // if a specific slip selected, check that slip is free
                                    $conflict = 0;
                                    if (!empty($new_slip_id)) {
                                        $rc = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_ID != ? AND slip_ID = ? AND reservation_status IN ('confirmed','checked_in') AND NOT (end_date < ? OR start_date > ?)");
                                        $rc->execute([$id, $new_slip_id, $new_start, $new_end]);
                                        $conflict = intval($rc->fetchColumn() ?? 0);
                                    } else {
                                        // if no specific slip_ID, but a slip_size provided in POST, check availability by size
                                        $size = $_POST['slip_size'] ?? null;
                                        if ($size) {
                                            $sc = $pdo->prepare('SELECT COUNT(*) FROM slips WHERE slip_size = ?'); $sc->execute([$size]); $total = intval($sc->fetchColumn() ?? 0);
                                            $rc = $pdo->prepare("SELECT COUNT(DISTINCT r.slip_ID) FROM reservations r JOIN slips sl ON r.slip_ID = sl.slip_ID WHERE sl.slip_size = ? AND r.reservation_status IN ('confirmed','checked_in') AND NOT (r.end_date < ? OR r.start_date > ?)");
                                            $rc->execute([$size, $new_start, $new_end]);
                                            $reserved = intval($rc->fetchColumn() ?? 0);
                                            if ($reserved >= $total) $conflict = 1; else $conflict = 0;
                                        }
                                    }
                                    if ($conflict > 0) {
                                        $errors[] = 'Selected slip is not available for the specified dates.';
                                        $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $s->execute([$id]); $editReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                                    }
                                }

                                // If there are no availability errors and we have dates, recalc cost
                                if (empty($errors) && $new_start && $new_end) {
                                    // compute total_cost based on boat length and months
                                    $PRICE_PER_FOOT = 10.50;
                                    $HOOKUP_PER_MONTH = 10.50;
                                    $startTs = strtotime($new_start);
                                    $endTs = strtotime($new_end);
                                    $days = 1;
                                    if ($startTs && $endTs && $endTs > $startTs) { $days = ceil(($endTs - $startTs) / (24*3600)); }
                                    $months = max(1, (int)ceil($days / 30));
                                    $boatIdForCost = isset($_POST['boat_ID']) ? ($_POST['boat_ID'] === '' ? null : intval($_POST['boat_ID'])) : intval($current['boat_ID'] ?? 0);
                                    $boatLen = 0;
                                    if (!empty($boatIdForCost)) {
                                        try { $bq = $pdo->prepare('SELECT boat_length FROM boats WHERE boat_ID = ? LIMIT 1'); $bq->execute([$boatIdForCost]); $boatLen = floatval($bq->fetchColumn() ?: 0); } catch (Exception $_) { $boatLen = 0; }
                                    }
                                    $baseMonthly = $boatLen * $PRICE_PER_FOOT;
                                    $monthlySum = $baseMonthly + $HOOKUP_PER_MONTH;
                                    $computedTotal = round($monthlySum * $months, 2);
                                    // attach to update sets (override if user supplied total_cost)
                                    $sets[] = 'total_cost = ?'; $params[] = $computedTotal;
                                    // also update months_duration
                                    $sets[] = 'months_duration = ?'; $params[] = $months;
                                    // reflect in editReservation for immediate re-render if needed
                                    $editReservation['total_cost'] = $computedTotal;
                                    $editReservation['months_duration'] = $months;
                                }

                                if (empty($errors)) {
                                    // ensure audit columns exist and stamp last_modified for employees
                                    try {
                                        $colm = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_by'")->fetch(PDO::FETCH_ASSOC);
                                        if (!$colm) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_by INT NULL AFTER reservation_status"); }
                                        $colm2 = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_at'")->fetch(PDO::FETCH_ASSOC);
                                        if (!$colm2) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_at DATETIME NULL AFTER last_modified_by"); }
                                    } catch (Exception $_) { /* ignore */ }

                                    // if employee is setting status to checked_in, record stamps if not already
                                    if (isset($_POST['reservation_status']) && $_POST['reservation_status'] === 'checked_in') {
                                        try {
                                            $cur = $pdo->prepare('SELECT reservation_status FROM reservations WHERE reservation_ID = ? LIMIT 1');
                                            $cur->execute([$id]);
                                            $old = strtolower(trim((string)$cur->fetchColumn()));
                                        } catch (Exception $ex) { $old = ''; }
                                        if ($old !== 'checked_in') {
                                            try {
                                                $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_at'" )->fetch(PDO::FETCH_ASSOC);
                                                if (!$col) { $pdo->exec("ALTER TABLE reservations ADD COLUMN checked_in_at DATETIME NULL AFTER reservation_status"); }
                                                $colb = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_by'" )->fetch(PDO::FETCH_ASSOC);
                                                if (!$colb) { $pdo->exec("ALTER TABLE reservations ADD COLUMN checked_in_by INT NULL AFTER checked_in_at"); }
                                            } catch (Exception $_) { }
                                            $sets[] = 'checked_in_at = NOW()';
                                            $sets[] = 'checked_in_by = ?';
                                            $params[] = $empId;
                                        }
                                    }

                                    // always stamp last_modified for employee edits
                                    $sets[] = 'last_modified_by = ?'; $params[] = $empId;
                                    $sets[] = 'last_modified_at = NOW()';

                                    $params[] = $id;
                                    $sql = 'UPDATE reservations SET ' . implode(', ', $sets) . ' WHERE reservation_ID = ?';
                                    $u = $pdo->prepare($sql);
                                    $u->execute($params);
                                    $statusMsg = 'Reservation updated.';
                                    // respond appropriately: JSON for AJAX, otherwise redirect to read-only reservation view
                                    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok','message'=>$statusMsg,'reservation_id'=>$id]); exit; }
                                    $_SESSION['statusMsg'] = $statusMsg;
                                    header('Location: ' . basename(__FILE__) . '?view_after_save=1&reservation_id=' . $id);
                                    exit;
                                }
                            }
                        }
                    }
                } else {
                    // non-employee path: still allow update but do not auto-stamp employee fields
                    $params[] = $id;
                    $sql = 'UPDATE reservations SET ' . implode(', ', $sets) . ' WHERE reservation_ID = ?';
                    $u = $pdo->prepare($sql);
                    $u->execute($params);
                    $statusMsg = 'Reservation updated.';
                    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok','message'=>$statusMsg,'reservation_id'=>$id]); exit; }
                    $_SESSION['statusMsg'] = $statusMsg;
                    header('Location: ' . basename(__FILE__) . '?view_after_save=1&reservation_id=' . $id);
                    exit;
                }
            }
        }

        if ($action === 'cancel_reservation' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            $employeeId = intval($_SESSION['employee_id'] ?? 0);
            // ensure last_modified columns exist (defensive)
            try {
                $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_by'")->fetch(PDO::FETCH_ASSOC);
                if (!$col) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_by INT NULL AFTER reservation_status"); }
                $col2 = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_at'")->fetch(PDO::FETCH_ASSOC);
                if (!$col2) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_at DATETIME NULL AFTER last_modified_by"); }
            } catch (Exception $ex) { /* ignore */ }

            $u = $pdo->prepare('UPDATE reservations SET reservation_status = ?, last_modified_by = ?, last_modified_at = NOW() WHERE reservation_ID = ?');
            $u->execute(['canceled', $employeeId, $id]);
            $statusMsg = 'Reservation canceled.';
            if (!empty($_POST['ajax'])) { echo $statusMsg; exit; }
        }

        if ($action === 'checkin_reservation' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            // enforce 24-hour check-in window
            $s = $pdo->prepare('SELECT start_date FROM reservations WHERE reservation_ID = ? LIMIT 1');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            $allowed = false;
            if ($row && !empty($row['start_date'])) {
                $start = strtotime($row['start_date']);
                $now = time();
                if (abs($start - $now) <= 24 * 3600) { $allowed = true; }
            }
            $ajaxReq = !empty($_POST['ajax']);
            if (!$allowed) {
                $errors[] = 'Check-in is allowed only within 24 hours of the reservation start date.';
                if ($ajaxReq) { header('Content-Type: application/json'); echo json_encode(['status'=>'blocked','message'=>implode('; ',$errors),'errors'=>$errors,'reservation_id'=>$id]); exit; }
            } else {
                // require payment before allowing check-in
                try {
                    $p = $pdo->prepare('SELECT COUNT(*) AS c FROM employee_payments WHERE reservation_ID = ?');
                    $p->execute([$id]);
                    $cnt = intval($p->fetchColumn() ?? 0);
                } catch (Exception $ex) { $cnt = 0; }

                if ($cnt === 0) {
                    // fetch reservation for context and show payment UI
                    $s2 = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
                    $s2->execute([$id]);
                    $editReservation = $s2->fetch(PDO::FETCH_ASSOC) ?: null;
                    $requirePaymentFor = $id;
                    $errors[] = 'A payment must be recorded before checking in. Use the Record Payment form below.';
                    if ($ajaxReq) { header('Content-Type: application/json'); echo json_encode(['status'=>'blocked','message'=>implode('; ',$errors),'errors'=>$errors,'reservation_id'=>$id]); exit; }
                } else {
                    // ensure reservations has checked_in and audit columns
                    try {
                        $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_at'")->fetch(PDO::FETCH_ASSOC);
                        if (!$col) { $pdo->exec("ALTER TABLE reservations ADD COLUMN checked_in_at DATETIME NULL AFTER reservation_status"); }
                        $colb = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_by'")->fetch(PDO::FETCH_ASSOC);
                        if (!$colb) { $pdo->exec("ALTER TABLE reservations ADD COLUMN checked_in_by INT NULL AFTER checked_in_at"); }
                        $colm = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_by'")->fetch(PDO::FETCH_ASSOC);
                        if (!$colm) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_by INT NULL AFTER checked_in_by"); }
                        $colm2 = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_at'")->fetch(PDO::FETCH_ASSOC);
                        if (!$colm2) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_at DATETIME NULL AFTER last_modified_by"); }
                    } catch (Exception $ex) { /* ignore */ }

                    $employeeId = intval($_SESSION['employee_id'] ?? 0);
                    $u = $pdo->prepare('UPDATE reservations SET reservation_status = ?, checked_in_at = NOW(), checked_in_by = ?, last_modified_by = ?, last_modified_at = NOW() WHERE reservation_ID = ?');
                    $u->execute(['checked_in', $employeeId, $employeeId, $id]);
                    $statusMsg = 'Reservation checked in.';
                    if ($ajaxReq) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok','message'=>$statusMsg,'reservation_id'=>$id]); exit; }
                }
            }
        }

        if ($action === 'complete_reservation' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            // some installations use `checked_in_date`, others `checked_in_at` - accept either
            // some installations use `checked_in_date`, others `checked_in_at` - accept either
            try {
                $hasCheckedInDate = false;
                $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_date'")->fetch(PDO::FETCH_ASSOC);
                if ($col) $hasCheckedInDate = true;
            } catch (Exception $_) { $hasCheckedInDate = false; }
            if ($hasCheckedInDate) {
                $q = $pdo->prepare('SELECT reservation_status, COALESCE(checked_in_date, checked_in_at) AS checked_in_ts FROM reservations WHERE reservation_ID = ? LIMIT 1');
            } else {
                $q = $pdo->prepare('SELECT reservation_status, checked_in_at AS checked_in_ts FROM reservations WHERE reservation_ID = ? LIMIT 1');
            }
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $canComplete = false;
            $isEmployee = !empty($_SESSION['is_employee']);
            if ($r) {
                // debug: fetch current stored status/timestamps for visibility when troubleshooting
                try {
                    if (!isset($hasCheckedInDate)) {
                        $hasCheckedInDate = false;
                        $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'checked_in_date'")->fetch(PDO::FETCH_ASSOC);
                        if ($col) $hasCheckedInDate = true;
                    }
                    if ($hasCheckedInDate) {
                        $cq = $pdo->prepare('SELECT reservation_status, COALESCE(checked_in_at, checked_in_date) AS checked_in_ts FROM reservations WHERE reservation_ID = ? LIMIT 1');
                    } else {
                        $cq = $pdo->prepare('SELECT reservation_status, checked_in_at AS checked_in_ts FROM reservations WHERE reservation_ID = ? LIMIT 1');
                    }
                    $cq->execute([$id]);
                    $cur = $cq->fetch(PDO::FETCH_ASSOC) ?: [];
                    $dbgLine = 'DEBUG[res=' . $id . '] current_status=' . ($cur['reservation_status'] ?? 'NULL') . ' checked_in_ts=' . ($cur['checked_in_ts'] ?? 'NULL') . ' isEmployee=' . ($isEmployee ? '1' : '0');
                    if (empty($_POST['ajax'])) { $statusMsg = trim(($statusMsg ? $statusMsg . ' | ' : '') . $dbgLine); }
                } catch (Exception $ex) { /* ignore debug failures */ }
                // Employees may override and mark reservations completed regardless of current status/timestamps
                if ($isEmployee) {
                    $canComplete = true;
                } else {
                    // For non-employees, require the reservation to be checked_in and have a valid checked-in timestamp,
                    // and either be older than 30 days since check-in or otherwise be disallowed.
                    if (strtolower($r['reservation_status'] ?? '') !== 'checked_in') {
                        $errors[] = 'Reservation must be in checked_in status before it can be completed.';
                    } else {
                        $checked = !empty($r['checked_in_ts']) ? strtotime($r['checked_in_ts']) : false;
                        if ($checked === false) {
                            $errors[] = 'Checked-in timestamp missing; cannot complete reservation.';
                        } else {
                            if (time() - $checked >= 30 * 24 * 3600) {
                                $canComplete = true;
                            } else {
                                $errors[] = 'Reservation must be checked in for at least 30 days before completion.';
                            }
                        }
                    }
                }
            }
            $ajaxReq = !empty($_POST['ajax']);
            // If this was an AJAX request but we determined the action is not allowed,
            // return structured JSON so the client can present useful diagnostics.
            if (!$canComplete && $ajaxReq) {
                header('Content-Type: application/json');
                $msg = $statusMsg ?: (empty($errors) ? 'Action not allowed' : implode('; ', $errors));
                echo json_encode(['status' => 'blocked', 'message' => $msg, 'reservation_id' => $id, 'before' => $r, 'errors' => $errors]);
                exit;
            }

            if ($canComplete) {
                // ensure audit columns exist
                try {
                    $colm = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_by'")->fetch(PDO::FETCH_ASSOC);
                    if (!$colm) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_by INT NULL AFTER reservation_status"); }
                    $colm2 = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'last_modified_at'")->fetch(PDO::FETCH_ASSOC);
                    if (!$colm2) { $pdo->exec("ALTER TABLE reservations ADD COLUMN last_modified_at DATETIME NULL AFTER last_modified_by"); }
                } catch (Exception $ex) { /* ignore */ }
                $employeeId = intval($_SESSION['employee_id'] ?? 0);
                $u = $pdo->prepare('UPDATE reservations SET reservation_status = ?, last_modified_by = ?, last_modified_at = NOW() WHERE reservation_ID = ?');
                try {
                    // capture the row before update for diagnostics
                    $pre = null;
                    try { $ps = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $ps->execute([$id]); $pre = $ps->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $_ex) { $pre = null; }

                    $ok = $u->execute(['completed', $employeeId, $id]);
                    $affected = $u->rowCount();
                    if ($ok && $affected > 0) {
                        $statusMsg = 'Reservation marked completed.';
                        if ($ajaxReq) {
                            header('Content-Type: application/json');
                            echo json_encode(['status'=>'ok','message'=>$statusMsg,'reservation_id'=>$id,'affected'=>$affected,'before'=>$pre]);
                            exit;
                        }
                    } else {
                        $statusMsg = 'No reservation updated.';
                        // write diagnostic record for no-update cases so we can see parameters and PDO errors
                        try {
                            $err = $u->errorInfo();
                            $updateDbg = [
                                'ts' => microtime(true),
                                'action' => 'complete_reservation_no_update',
                                'reservation_id' => $id,
                                'employee_id' => $employeeId,
                                'params' => ['completed', $employeeId, $id],
                                'ok' => (bool)$ok,
                                'affected' => $affected,
                                'pdo_error' => $err,
                                'before' => $pre,
                                'request' => $_REQUEST
                            ];
                            @file_put_contents(__DIR__ . '/ajax_debug.log', json_encode($updateDbg) . "\n", FILE_APPEND | LOCK_EX);
                        } catch (Exception $_e) { /* ignore logging errors */ }

                        if ($ajaxReq) {
                            header('Content-Type: application/json');
                            $dbg = ['status'=>'no_update','message'=>$statusMsg,'reservation_id'=>$id,'affected'=>$affected,'before'=>$pre,'params'=>['completed',$employeeId,$id],'pdo_error'=>$err ?? null];
                            echo json_encode($dbg);
                            exit;
                        }
                    }
                } catch (Exception $ex) {
                    $errors[] = 'Database error completing reservation: ' . $ex->getMessage();
                    if ($ajaxReq) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$ex->getMessage(),'reservation_id'=>$id]); exit; }
                }
            }
        }

        // Record a payment (create payments table if necessary)
        if ($action === 'record_payment' && !empty($_POST['reservation_id'])) {
            $id = intval($_POST['reservation_id']);
            $amount = floatval($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'unknown';
            $card_last4 = isset($_POST['card_last4']) ? preg_replace('/\D/','',$_POST['card_last4']) : null;
            if (!empty($card_last4)) { $card_last4 = substr($card_last4, -4); }
            $employeeId = intval($_SESSION['employee_id'] ?? 0);
            $pdo->exec('CREATE TABLE IF NOT EXISTS employee_payments (payment_id INT AUTO_INCREMENT PRIMARY KEY, reservation_ID INT, amount DECIMAL(10,2), method VARCHAR(50), card_last4 VARCHAR(10) NULL, employee_ID INT, date_created DATETIME DEFAULT CURRENT_TIMESTAMP)');
            $ins = $pdo->prepare('INSERT INTO employee_payments (reservation_ID, amount, method, card_last4, employee_ID) VALUES (?,?,?,?,?)');
            $ins->execute([$id, $amount, $method, $card_last4, $employeeId]);
            $statusMsg = 'Payment recorded.';
        }

        // Waitlist edit
        if ($action === 'edit_waitlist' && !empty($_POST['waitlist_id'])) {
            $id = intval($_POST['waitlist_id']);
            $s = $pdo->prepare('SELECT * FROM waitlist WHERE waitlist_ID = ? LIMIT 1');
            $s->execute([$id]);
            $editWaitlist = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($action === 'save_waitlist' && !empty($_POST['waitlist_id'])) {
            $id = intval($_POST['waitlist_id']);
            $fields = ['preferred_start_date','preferred_end_date','preferred_slip_size','position_in_queue','boat_ID','user_ID','months_duration'];
            $sets = []; $params = [];
            foreach ($fields as $f) { if (isset($_POST[$f])) { $sets[] = "$f = ?"; $params[] = $_POST[$f] === '' ? null : $_POST[$f]; } }
            if ($sets) {
                // require employee password when editing via dashboard
                $requirePass = !empty($_SESSION['is_employee']);
                if ($requirePass) {
                    $pwd = $_POST['confirm_password'] ?? '';
                    if (empty($pwd)) { $errors[] = 'Employee password is required to save waitlist changes.'; $s = $pdo->prepare('SELECT * FROM waitlist WHERE waitlist_ID = ? LIMIT 1'); $s->execute([$id]); $editWaitlist = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
                    else {
                        $empId = intval($_SESSION['employee_id'] ?? 0);
                        $ep = $pdo->prepare('SELECT password FROM Employees WHERE employee_ID = ? LIMIT 1'); $ep->execute([$empId]); $hash = $ep->fetchColumn() ?: '';
                        if (!password_verify($pwd,$hash)) { $errors[] = 'Invalid employee password.'; $s = $pdo->prepare('SELECT * FROM waitlist WHERE waitlist_ID = ? LIMIT 1'); $s->execute([$id]); $editWaitlist = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
                        else { $params[] = $id; $sql = 'UPDATE waitlist SET '.implode(', ',$sets).' WHERE waitlist_ID = ?'; $u = $pdo->prepare($sql); $u->execute($params); $statusMsg = 'Waitlist entry updated.'; }
                    }
                } else { $params[] = $id; $sql = 'UPDATE waitlist SET '.implode(', ',$sets).' WHERE waitlist_ID = ?'; $u = $pdo->prepare($sql); $u->execute($params); $statusMsg = 'Waitlist entry updated.'; }
            }
        }

        // User edit
        if ($action === 'edit_user' && !empty($_POST['user_id'])) {
            $id = intval($_POST['user_id']);
            $s = $pdo->prepare('SELECT user_ID, email, first_name, last_name FROM users WHERE user_ID = ? LIMIT 1');
            $s->execute([$id]);
            $editUser = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($action === 'save_user' && !empty($_POST['user_id'])) {
            $id = intval($_POST['user_id']);
            $fields = ['email','first_name','last_name']; $sets=[]; $params=[];
            foreach ($fields as $f) { if (isset($_POST[$f])) { $sets[] = "$f = ?"; $params[] = $_POST[$f]; } }
            if ($sets) {
                $requirePass = !empty($_SESSION['is_employee']);
                if ($requirePass) {
                    $pwd = $_POST['confirm_password'] ?? '';
                    if (empty($pwd)) { $errors[] = 'Employee password is required to save user changes.'; $s = $pdo->prepare('SELECT user_ID, email, first_name, last_name FROM users WHERE user_ID = ? LIMIT 1'); $s->execute([$id]); $editUser = $s->fetch(PDO::FETCH_ASSOC) ?: null; }
                    else { $empId = intval($_SESSION['employee_id'] ?? 0); $ep = $pdo->prepare('SELECT password FROM Employees WHERE employee_ID = ? LIMIT 1'); $ep->execute([$empId]); $hash = $ep->fetchColumn() ?: ''; if (!password_verify($pwd,$hash)) { $errors[] = 'Invalid employee password.'; $s = $pdo->prepare('SELECT user_ID, email, first_name, last_name FROM users WHERE user_ID = ? LIMIT 1'); $s->execute([$id]); $editUser = $s->fetch(PDO::FETCH_ASSOC) ?: null; } else { $params[] = $id; $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE user_ID = ?'; $u = $pdo->prepare($sql); $u->execute($params); $statusMsg='User updated.'; } }
                } else { $params[] = $id; $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE user_ID = ?'; $u = $pdo->prepare($sql); $u->execute($params); $statusMsg='User updated.'; }
            }
        }

        // Boat edit
        if ($action === 'edit_boat' && !empty($_POST['boat_id'])) {
            $id = intval($_POST['boat_id']);
            $s = $pdo->prepare('SELECT * FROM boats WHERE boat_ID = ? LIMIT 1'); $s->execute([$id]); $editBoat = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($action === 'save_boat' && !empty($_POST['boat_id'])) {
            $id = intval($_POST['boat_id']); $fields = ['boat_name','boat_length']; $sets=[];$params=[]; foreach($fields as $f){ if(isset($_POST[$f])){ $sets[]="$f = ?"; $params[] = $_POST[$f]; }} if($sets){ $params[]=$id; $sql='UPDATE boats SET '.implode(', ',$sets).' WHERE boat_ID = ?'; $u=$pdo->prepare($sql); $u->execute($params); $statusMsg='Boat updated.'; }
        }

    } catch (Exception $ex) {
        $errors[] = 'Database error: ' . $ex->getMessage();
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Employee Dashboard</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    :root{ --success:#10b981 }
    .dashboard { max-width:1000px; margin:24px auto; padding:0 20px; box-sizing:border-box; }
    /* ensure every section inside the dashboard uses the same horizontal padding so inner content widths match */
    .dashboard > section, .dashboard section { width:100%; box-sizing:border-box; padding:16px !important; }
    .search-panel{ background:#fff;padding:18px;border-radius:10px;box-shadow:0 8px 20px rgba(15,30,60,0.06); width:100%; }
    /* overlap the hero by 50px (fixed visual offset using transform so layout changes won't cancel it) */
    .search-panel.hero-overlap{ margin-top:0 !important; position:relative; z-index:10000; transform:translateY(-50px) !important; }
        /* spacer so subsequent content is pushed below the visually-overlapping panel */
        .search-panel.hero-overlap::after{ content:''; display:block; height:50px; width:100%; }
        /* allow pointer events to pass through the empty overlapped area while keeping child controls interactive */
        .search-panel.hero-overlap{ pointer-events: none; }
        .search-panel.hero-overlap *{ pointer-events: auto; }
    /* ensure inline edit cards sit below the search panel */
    .inline-edit{ margin-top:60px !important; position:relative; z-index:1 }
    /* ensure the overlap remains on small screens as well */
    @media (max-width:680px){ .search-panel.hero-overlap{ transform:translateY(-50px) !important; } .search-panel.hero-overlap::after{ height:50px; } }
    /* reusable panel style to keep all cards consistent */
    .panel{ background:#fff; padding:16px; border-radius:10px; box-shadow:0 8px 20px rgba(15,30,60,0.06); box-sizing:border-box }
    .results table{ width:100%; border-collapse:separate; border-spacing:0 8px; }
    .results th, .results td{ padding:8px; }
    /* left-align headers and cells in search results for consistent reading */
    .results th, .results td { text-align: left !important; }
    .results tbody tr{ background:#fff; border-radius:8px; box-shadow:0 6px 18px rgba(15,30,60,0.04); }
    .btn-ghost{ background:transparent;border:1px solid #ddd;padding:6px 10px;border-radius:8px }

    /* Reservation card layout for quick day-of view */
    .res-cards{ display:flex; flex-direction:column; gap:12px; align-items:stretch; }
    /* present content within the outer .panel only — remove inner card chrome to avoid double-card look */
    .res-card{ display:flex; gap:20px; background:#fff; padding:14px; border-radius:10px; align-items:flex-start; box-shadow:0 6px 18px rgba(15,30,60,0.02); max-width:100%; width:100%; box-sizing:border-box; transition:transform .12s ease, box-shadow .12s ease }
    .res-card:hover{ transform:translateY(-2px); box-shadow:0 14px 40px rgba(15,30,60,0.06) }
    .res-card .left{ flex:0 0 16%; min-width:120px }
    .res-card .left .slip{ font-weight:700; color:var(--ocean); font-size:1.05rem }
    /* columns: left | details | meta | actions — proportions set so spacing is consistent across cards */
    .res-card .body{ flex:1 1 56%; display:flex; gap:18px; align-items:flex-start; min-width:0 }
    .res-card .body > .details{ flex:2 1 56%; min-width:0 }
    .res-card .body > .dates{ flex:1 1 48%; min-width:220px; color:#374151; font-size:0.95rem; margin-left:-85px }
    .res-card .left .status{ margin-top:8px }
    .res-card .body > .actions{ display:grid; grid-template-columns:repeat(2, max-content); gap:8px 12px; align-items:center; justify-content:end; min-width:220px }
    .res-card .body > .actions > * { justify-self:end }
    /* when only a couple of action buttons are visible, center them for balance */
    .res-card .body > .actions.actions-centered{ justify-content:center }
    .res-card .body > .actions.actions-centered > *{ justify-self:center }
    
    /* mobile: stack vertically while keeping internal spacing consistent */
    @media (max-width:680px){
        .res-card{ flex-direction:column; align-items:stretch }
        .res-card .left{ flex:0 0 auto; width:100% }
        .res-card .body{ flex-direction:column; gap:10px }
        .res-card .body > .actions{ width:100%; grid-template-columns:repeat(2,1fr); justify-content:start }
        .res-card .body > .actions > * { justify-self:center }
        .res-card .body > .dates{ margin-left:0 }
    }
    .res-card .badge{ display:inline-block; padding:6px 8px; border-radius:8px; background:#f1f5f9; color:#0f1724; font-weight:600 }
    .more-details{ margin-top:8px; padding:10px 12px; border-radius:8px; background:rgba(15,30,60,0.02); color:#102033; font-size:0.95rem }
    .payment-summary{ margin-left:8px; font-weight:700; color:#065f46; font-size:0.9rem }
    .res-card.completed{ background:rgba(212,175,55,0.08); padding:12px; border-radius:10px }
    .res-card.completed .left .slip{ color:var(--gold,#D4AF37) }
    /* faint separators between reservation rows (not full inner cards) */
    .res-cards .res-card{ padding:10px 0 }
    .res-cards .res-card + .res-card{ border-top:1px solid rgba(15,30,60,0.04); margin-top:8px; padding-top:12px }
    @media (min-width:900px){ .res-cards{ flex-direction:column; align-items:stretch } .res-card{ width:100% } }

    /* Search form — site consistent inputs */
    .search-panel .search-form{ display:flex; flex-wrap:wrap; gap:12px; align-items:center }
    .search-panel .field{ display:flex; flex-direction:column; min-width:160px }
    /* Quick tabs for task-driven searches */
    .search-panel .quick-tabs{ display:flex; gap:10px; margin-bottom:10px; align-items:center }
    .search-panel .quick-tabs .btn{ padding:9px 14px; border-radius:999px; font-weight:700; font-size:0.92rem; background:transparent; border:1px solid rgba(15,30,60,0.06); color:#0f1724 }
    .search-panel .quick-tabs .btn:hover{ background:rgba(3,102,214,0.04); cursor:pointer }
    /* color coding for quick tabs */
    #task-checkins{ border-color: rgba(63,135,166,0.12); color:var(--ocean) }
    #task-completions{ border-color: rgba(212,175,55,0.12); color:var(--gold) }
    #task-waitlist-delete{ border-color: rgba(255,127,80,0.12); color:var(--coral) }
    /* Payment needed quick link is coral with white text by default */
    #task-payment-24h{ background:var(--coral,#FF7F50); color:#fff; border-color:var(--coral,#FF7F50) }
    /* Today's Reservations quick link: full gold */
    #task-today-res{ background:var(--gold,#D4AF37); color:#071018; border-color:transparent }
    #task-today-res[aria-pressed="true"]{ box-shadow:0 8px 22px rgba(212,175,55,0.12); border-color:transparent }
    #task-checkins[aria-pressed="true"]{ background:var(--ocean,#3F87A6); color:#fff; border-color:transparent; box-shadow:0 8px 22px rgba(63,135,166,0.14) }
    #task-completions[aria-pressed="true"]{ background:var(--gold,#D4AF37); color:#071018; border-color:transparent; box-shadow:0 8px 22px rgba(212,175,55,0.12) }
    #task-waitlist-delete[aria-pressed="true"]{ background:var(--coral,#FF7F50); color:#fff; border-color:transparent; box-shadow:0 8px 22px rgba(255,127,80,0.12) }
    #task-payment-24h[aria-pressed="true"]{ background:var(--coral,#FF7F50); color:#fff; border-color:transparent; box-shadow:0 8px 22px rgba(255,127,80,0.12) }
    .search-panel .quick-tabs .btn:focus{ outline:2px solid rgba(3,102,214,0.12); }
    /* Search options dropdown panel */
    .search-options-panel{ background:#fff;padding:12px;border-radius:10px;border:1px solid rgba(15,30,60,0.04); box-shadow:0 10px 24px rgba(15,30,60,0.06); margin-bottom:16px; }
    #search-toggle[aria-expanded="true"]{ background:rgba(3,102,214,0.08); }
    .search-panel label{ font-size:0.85rem; color:#334155; margin-bottom:6px }
    .search-panel input[type="text"], .search-panel input[type="email"], .search-panel input[type="date"], .search-panel input[type="number"], .search-panel select{ padding:10px 12px; border-radius:10px; border:1px solid rgba(31,47,69,0.08); box-shadow:0 6px 18px rgba(15,30,60,0.04); background:#fff; font-size:0.95rem }
    .search-panel .actions .btn{ min-width:140px; height:44px }
    .search-panel .search-form button{ height:44px }
    .search-panel .btn-ghost{ border-color: rgba(31,47,69,0.06) }

    /* Button color variants for this page only */
    .btn-gold{ background:var(--gold,#D4AF37); color:#0b1220; border:1px solid rgba(0,0,0,0.06); box-shadow:0 8px 20px rgba(212,175,55,0.12); }
    .btn-ocean{ background:var(--ocean,#3F87A6); color:#fff; border:1px solid rgba(0,0,0,0.06); box-shadow:0 8px 20px rgba(63,135,166,0.12); }
    .btn-outline{ background:transparent; color:var(--navy,#0f1724); border:1px solid rgba(31,47,69,0.12); box-shadow:none }
    .btn-outline.btn-cancel{ border-color:var(--coral,#FF7F50); color:var(--coral,#FF7F50); transition:all .18s ease; }
    .btn-outline.btn-cancel:hover{ background:var(--coral,#FF7F50); color:#fff; border-color:var(--coral,#FF7F50); box-shadow:0 8px 20px rgba(255,127,80,0.12); }
    .btn-outline.btn-cancel:focus{ outline:2px solid rgba(255,127,80,0.18); }
    .btn-outline.btn-ocean{ border-color:var(--ocean,#3F87A6); color:var(--ocean,#3F87A6); background:rgba(63,135,166,0.06); }
    .btn-outline.btn-ocean:hover{ background:rgba(63,135,166,0.10); color:var(--ocean,#3F87A6); box-shadow:0 8px 20px rgba(63,135,166,0.12); }
    /* Gold-outline variant for 'See more details' buttons */
    .btn-outline.btn-gold{ border-color:var(--gold,#D4AF37); color:#071018; background:transparent; }
    .btn-outline.btn-gold:hover{ background:rgba(212,175,55,0.08); color:#071018; box-shadow:0 8px 20px rgba(212,175,55,0.12); }
    /* Display Boats button: ocean blue with white text */
    button[data-show-boats]{ background:var(--ocean,#3F87A6); color:#fff; border-color:transparent; box-shadow:0 8px 20px rgba(63,135,166,0.12); padding:8px 12px; border-radius:8px }
    button[data-show-boats]:hover{ filter:brightness(0.98); cursor:pointer }
    /* Take Payment button: pine green with white text */
    button[data-take-payment]{ background:#2E8B57; color:#fff; border-color:transparent; box-shadow:0 8px 20px rgba(46,139,87,0.12); padding:8px 12px; border-radius:8px }
    button[data-take-payment]:hover{ filter:brightness(0.96); cursor:pointer }
    /* 'See more details' button removed — details are shown inline */
    .btn { padding:10px 14px; border-radius:10px; font-weight:600 }

    /* slip-size subgroup header */
    .slip-subheader{ margin:8px 0 6px; padding:6px 10px; border-radius:8px; background:rgba(3,102,214,0.04); color:#083047; font-weight:700; font-size:0.95rem }
    .slip-subheader.size-26{ background: rgba(63,135,166,0.08); color: #083047 }
    .slip-subheader.size-40{ background: rgba(212,175,55,0.10); color: #4b3a00 }
    .slip-subheader.size-50{ background: rgba(229,231,235,0.8); color: #1f2937 }
    .slip-subheader.size-unknown{ background: rgba(3,102,214,0.04); }

    /* Status card wrapper to visually separate reservation statuses */
    .status-card{ margin:14px 0; padding:12px; border-radius:10px; background:linear-gradient(180deg, #ffffff 0%, rgba(246,249,255,0.6) 100%); border:1px solid rgba(15,30,60,0.04); box-shadow:0 10px 26px rgba(15,30,60,0.04) }
    .status-card + .status-card{ margin-top:18px }
    .status-card .status-title{ font-weight:800; color:#0b2740; margin-bottom:10px; padding:6px 10px; border-radius:8px; background:rgba(3,102,214,0.03); display:inline-block }

    </style>
</head>
<body>
    <?php include __DIR__ . '/nav.php'; ?>
    <?php
        $hero_title='Employee Dashboard';
        $hero_subtitle='Search and manage reservations, waitlist entries, users, boats, and payments.';
        $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>';
        $hero_classes = 'hero-dashboard';
        // If redirected after a POST search (PRG), restore results from session for one render
        if (!empty($_GET['show_search']) && !empty($_SESSION['employee_search_results'])) {
            $searchResults = $_SESSION['employee_search_results'];
            $resultType = $_SESSION['employee_search_resultType'] ?? null;
            unset($_SESSION['employee_search_results'], $_SESSION['employee_search_resultType']);
        }
        // determine which scope should be selected in the form (default to reservations)
        // Always default to 'reservations' unless explicitly provided via POST/GET
        $selectedScope = isset($_POST['scope']) ? $_POST['scope'] : (isset($_GET['scope']) ? $_GET['scope'] : 'reservations');
        include __DIR__ . '/hero.php';
        // If we're rendering an edit form, ensure we have related boat, user and slip metadata available
        if (!empty($editReservation)) {
            try {
                $editBoat = null; $editUser = null; $slipSizes = []; $locationCodes = [];
                // boat (if present under several possible column names)
                $boatId = $editReservation['boat_ID'] ?? $editReservation['boatId'] ?? $editReservation['boat_id'] ?? null;
                if (!empty($boatId)) {
                    $bq = $pdo->prepare('SELECT * FROM boats WHERE boat_ID = ? LIMIT 1'); $bq->execute([ $boatId ]); $editBoat = $bq->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                // user (tolerate multiple naming variants). If not present, try to detect user-id-like column in the reservation row
                $uid = $editReservation['user_ID'] ?? $editReservation['userId'] ?? $editReservation['user_id'] ?? $editReservation['owner_id'] ?? null;
                if (empty($uid) && is_array($editReservation)) {
                    foreach ($editReservation as $k => $v) {
                        if ($v === null || $v === '') continue;
                        $lk = strtolower($k);
                        if (strpos($lk, 'user') !== false && (strpos($lk, 'id') !== false || preg_match('/user_?id/', $lk))) { $uid = $v; break; }
                    }
                }
                if (!empty($uid)) {
                    try {
                        $uq = $pdo->prepare("SELECT user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE user_ID = ? LIMIT 1"); $uq->execute([ $uid ]); $editUser = $uq->fetch(PDO::FETCH_ASSOC) ?: null;
                        if (empty($editUser)) {
                            // some installations use `id` as the PK in users table
                            $uq2 = $pdo->prepare("SELECT id AS user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE id = ? LIMIT 1"); $uq2->execute([ $uid ]); $editUser = $uq2->fetch(PDO::FETCH_ASSOC) ?: null;
                        }
                    } catch (Exception $_) { $editUser = null; }
                }
                // fallback: if we still don't have a user record, try matching by email from reservation
                if (empty($editUser) && !empty($editReservation['email'])) {
                    try { $uq2 = $pdo->prepare("SELECT user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE email = ? LIMIT 1"); $uq2->execute([ $editReservation['email'] ]); $editUser = $uq2->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Exception $_) { }
                }

                // additional heuristic: some schemas use alternate FK names (owner_id, customer_id, renter_id, userId etc.)
                if (empty($editUser)) {
                    foreach ($editReservation as $k => $v) {
                        if ($v === null || $v === '') continue;
                        $lk = strtolower($k);
                        // consider any field that looks like an id or contains owner/customer/user keywords
                        if (preg_match('/(_id$|id$)/', $lk) || preg_match('/owner|customer|renter|user/', $lk)) {
                            // try numeric lookup first
                            if (is_numeric($v)) {
                                try {
                                    $try = $pdo->prepare("SELECT user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE user_ID = ? LIMIT 1"); $try->execute([ intval($v) ]);
                                    $found = $try->fetch(PDO::FETCH_ASSOC) ?: null;
                                    if (!$found) {
                                        $try2 = $pdo->prepare("SELECT id AS user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE id = ? LIMIT 1"); $try2->execute([ intval($v) ]); $found = $try2->fetch(PDO::FETCH_ASSOC) ?: null;
                                    }
                                    if ($found) { $editUser = $found; break; }
                                } catch (Exception $_) { /* ignore lookup errors */ }
                            } else {
                                // if value looks like an email, try matching by email
                                if (strpos((string)$v, '@') !== false) {
                                    try { $te = $pdo->prepare("SELECT user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE email = ? LIMIT 1"); $te->execute([ $v ]); $found = $te->fetch(PDO::FETCH_ASSOC) ?: null; if ($found) { $editUser = $found; break; } } catch (Exception $_) { }
                                }
                            }
                        }
                    }
                }

                    // If still empty, perform a direct probe on the users table using the detected user FK (for debugging)
                    $probeUserRow = null;
                    $detectedUid = $editReservation['user_ID'] ?? $editReservation['userId'] ?? $editReservation['user_id'] ?? $editReservation['owner_id'] ?? null;
                    if (empty($editUser) && !empty($detectedUid) && is_numeric($detectedUid)) {
                        try {
                            $p = $pdo->prepare('SELECT * FROM users WHERE user_ID = ? LIMIT 1'); $p->execute([ intval($detectedUid) ]);
                            $probeUserRow = $p->fetch(PDO::FETCH_ASSOC) ?: null;
                            if ($probeUserRow) {
                                // normalize into editUser
                                $editUser = [
                                    'user_ID' => $probeUserRow['user_ID'] ?? ($probeUserRow['id'] ?? null),
                                    'email' => $probeUserRow['email'] ?? '',
                                    'first_name' => $probeUserRow['first_name'] ?? '',
                                    'last_name' => $probeUserRow['last_name'] ?? '',
                                    'user_phone' => $probeUserRow['user_phone'] ?? ($probeUserRow['phone'] ?? '')
                                ];
                            }
                        } catch (Exception $_) { $probeUserRow = null; }
                    }
                // slip metadata: try several strategies to discover size and location columns and fetch distinct values
                $slipSizes = [];
                $locationCodes = [];
                $possibleSizeCols = ['slip_size','size','length_ft','size_ft'];
                $possibleLocCols = ['location_code','location','dock','dock_code'];
                // Query each candidate column individually and merge distinct non-null values
                try {
                    $seenSizes = [];
                    foreach ($possibleSizeCols as $col) {
                        try {
                            $q = $pdo->query("SELECT DISTINCT " . $col . " AS v FROM slips WHERE " . $col . " IS NOT NULL ORDER BY " . $col . " ASC");
                            if ($q) {
                                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($rows as $r) {
                                    $v = isset($r['v']) ? $r['v'] : null;
                                    if ($v === null || $v === '') continue;
                                    if (!in_array((string)$v, $seenSizes, true)) { $seenSizes[] = (string)$v; $slipSizes[] = $v; }
                                }
                            }
                        } catch (Exception $_) { /* ignore column not found or other DB errors */ }
                    }
                } catch (Exception $_) { $slipSizes = []; }

                try {
                    $seenLocs = [];
                    foreach ($possibleLocCols as $col) {
                        try {
                            $q2 = $pdo->query("SELECT DISTINCT " . $col . " AS v FROM slips WHERE " . $col . " IS NOT NULL ORDER BY " . $col . " ASC");
                            if ($q2) {
                                $rows2 = $q2->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($rows2 as $r2) {
                                    $v2 = isset($r2['v']) ? $r2['v'] : null;
                                    if ($v2 === null || $v2 === '') continue;
                                    if (!in_array((string)$v2, $seenLocs, true)) { $seenLocs[] = (string)$v2; $locationCodes[] = $v2; }
                                }
                            }
                        } catch (Exception $_) { /* ignore */ }
                    }
                } catch (Exception $_) { $locationCodes = []; }

                // If we couldn't detect slip sizes, offer the standard set (26,40,50)
                if (empty($slipSizes)) {
                    $slipSizes = ['26','40','50'];
                }

                // If location codes list is empty, try to build a list of all slips (one entry per slip)
                if (empty($locationCodes)) {
                    try {
                        $fallback = $pdo->query("SELECT slip_ID, COALESCE(location_code, location, slip_number, CONCAT('Slip ', slip_ID)) AS loc FROM slips ORDER BY loc ASC");
                        if ($fallback) {
                            $rowsf = $fallback->fetchAll(PDO::FETCH_ASSOC);
                            $locationCodes = [];
                            foreach ($rowsf as $rf) {
                                $v = $rf['loc'] ?? $rf['slip_ID'];
                                if ($v === null || $v === '') continue;
                                $locationCodes[] = $v;
                            }
                        }
                    } catch (Exception $_) { /* ignore fallback failures */ }
                }
            } catch (Exception $_) { /* ignore */ }
        }
    ?>
    <main class="dashboard">

    <!-- Confirmation modal (page-scoped) -->
    <div id="confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(11,17,32,0.5);align-items:center;justify-content:center;z-index:100001">
        <div style="background:#fff;padding:18px;border-radius:12px;max-width:520px;width:calc(100% - 40px);box-shadow:0 8px 28px rgba(11,17,32,0.2);">
            <div id="confirm-text" style="margin-bottom:14px;font-weight:600;color:#0f1724"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" id="confirm-cancel" class="btn btn-outline btn-cancel">No Changes</button>
                <button type="button" id="confirm-ok" class="btn btn-gold">Complete</button>
            </div>
        </div>
    </div>

    <!-- Boats modal -->
    <div id="boats-modal" style="display:none;position:fixed;inset:0;background:rgba(11,17,32,0.5);align-items:center;justify-content:center;z-index:100002">
        <div style="background:#fff;padding:16px;border-radius:12px;max-width:720px;width:calc(100% - 40px);box-shadow:0 12px 36px rgba(11,17,32,0.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <strong id="boats-modal-title">Boats</strong>
                <button id="boats-modal-close" class="btn btn-outline">Close</button>
            </div>
            <div id="boats-modal-body" style="max-height:60vh;overflow:auto;padding-top:6px"></div>
        </div>
    </div>

    <!-- Payment modal -->
    <div id="payment-modal" style="display:none;position:fixed;inset:0;background:rgba(11,17,32,0.5);align-items:center;justify-content:center;z-index:100003">
        <div style="background:#fff;padding:16px;border-radius:12px;max-width:520px;width:calc(100% - 40px);box-shadow:0 12px 36px rgba(11,17,32,0.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <strong id="payment-modal-title">Record Payment</strong>
                <button id="payment-modal-close" class="btn btn-outline">Close</button>
            </div>
            <form id="payment-form" method="post" style="display:flex;flex-direction:column;gap:8px">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="reservation_id" id="payment-reservation-id" value="">
                <label style="display:flex;flex-direction:column"><span>Amount</span><input type="number" step="0.01" name="amount" id="payment-amount" required></label>
                <label style="display:flex;flex-direction:column"><span>Method</span>
                    <select name="method" id="payment-method">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="check">Check</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label id="payment-card-last4-wrapper" style="display:none;flex-direction:column"><span>Card last 4</span>
                    <input type="text" name="card_last4" id="payment-card-last4" maxlength="4" pattern="\d{4}" placeholder="1234" inputmode="numeric" style="width:110px">
                </label>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
                    <button type="button" id="payment-cancel" class="btn btn-outline btn-cancel">Cancel</button>
                    <button type="submit" class="btn btn-ocean">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

        <?php if ($statusMsg): ?><div class="cb-success" style="margin:12px 0;padding:10px"><?php echo e($statusMsg); ?></div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;margin-bottom:8px"><?php echo e($err); ?></div><?php endforeach; ?>
        <?php if (!empty($_SESSION['employee_search_debug']) && !empty($_SESSION['employee_search_results']) && !empty($_SESSION['is_employee'])): ?>
            <?php $dbg = $_SESSION['employee_search_debug']; unset($_SESSION['employee_search_debug']); ?>
            <div style="background:#fff7ed;border:1px solid rgba(212,175,55,0.12);padding:10px;border-radius:8px;margin-bottom:12px;color:#3b2f00">
                <strong>Debug — last search</strong>
                <div style="margin-top:8px;font-family:monospace;white-space:pre-wrap;font-size:0.9rem;color:#2b2b2b">SQL: <?php echo e($dbg['sql'] ?? 'N/A'); ?>
Params: <?php echo e(json_encode($dbg['params'] ?? [])); ?></div>
            </div>
        <?php endif; ?>

        <?php
            // Only show the inline Edit User/Boat forms when explicitly requested via the edit actions
            $showEditUserForm = (isset($action) && $action === 'edit_user') || (!empty($_POST['action']) && $_POST['action'] === 'edit_user');
            $showEditBoatForm = (isset($action) && $action === 'edit_boat') || (!empty($_POST['action']) && $_POST['action'] === 'edit_boat');
        ?>
        <!-- inline-edit sections moved below the search-panel to ensure they render underneath the overlapping card -->

        <section class="search-panel hero-overlap">
            <h3>Search</h3>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div class="quick-tabs" role="tablist" aria-label="Quick searches">
                    <button type="button" id="task-checkins" class="btn btn-outline" role="tab" aria-pressed="false">Today's Check-ins</button>
                    <button type="button" id="task-completions" class="btn btn-outline" role="tab" aria-pressed="false">Today's Completions</button>
                    <button type="button" id="task-waitlist-delete" class="btn btn-outline" role="tab" aria-pressed="false">Waitlist Deletions (72h)</button>
                    <button type="button" id="task-payment-24h" class="btn btn-outline" role="tab" aria-pressed="false">Payment Needed (24h)</button>
                    <button type="button" id="task-today-res" class="btn btn-gold" role="tab" aria-pressed="false">Today's Reservations</button>
                </div>
                <button type="button" id="search-toggle" class="btn btn-outline" aria-expanded="false">Advanced Options ▾</button>
            </div>

            <form id="search-form" method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="action" value="search">
                <!-- Basic search options always visible -->
                <div id="basic-search-row" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;width:100%">
                    <label class="field" style="min-width:140px"><span>Date</span><input type="date" name="date" value="<?php echo e($_POST['date'] ?? ''); ?>"></label>
                    <label class="field" style="min-width:160px"><span>Scope</span>
                        <select name="scope">
                            <option value="reservations" <?php if(($selectedScope ?? '')==='reservations') echo 'selected'; ?>>Reservations</option>
                            <option value="waitlist" <?php if(($selectedScope ?? '')==='waitlist') echo 'selected'; ?>>Waitlist</option>
                            <option value="users" <?php if(($selectedScope ?? '')==='users') echo 'selected'; ?>>Users</option>
                            <option value="boats" <?php if(($selectedScope ?? '')==='boats') echo 'selected'; ?>>Boats</option>
                        </select>
                    </label>
                    <label class="field" style="min-width:140px"><span>Slip Size</span>
                        <select name="filter_slip_size">
                            <option value="">Any</option>
                            <?php foreach($availableSlipSizes as $ssx): ?>
                                <option value="<?php echo e($ssx); ?>" <?php if(($_POST['filter_slip_size'] ?? '')===$ssx) echo 'selected'; ?>><?php echo e($ssx); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field" style="min-width:160px"><span>Status</span>
                        <select name="filter_status">
                            <option value="">Any</option>
                            <?php foreach ($availableStatuses as $sopt) : ?>
                                <option value="<?php echo e($sopt); ?>" <?php if(($_POST['filter_status'] ?? '')===$sopt) echo 'selected'; ?>><?php echo e(ucfirst($sopt)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field" style="min-width:220px"><span>Email</span><input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>"></label>
                </div>

                <div class="search-options-panel" id="search-options-panel" aria-hidden="true" style="display:none;width:100%">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
                        <div style="flex:1;min-width:420px">
                            <strong style="display:block;margin-bottom:8px">Advanced Filters</strong>
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Group By</span>
                                <select name="group_by">
                                    <option value="">None</option>
                                    <option value="date" <?php if(($_POST['group_by'] ?? '')==='date') echo 'selected'; ?>>Date</option>
                                    <option value="status" <?php if(($_POST['group_by'] ?? '')==='status') echo 'selected'; ?>>Status</option>
                                    <option value="dock" <?php if(($_POST['group_by'] ?? '')==='dock') echo 'selected'; ?>>Dock</option>
                                </select>
                            </label>
                            <!-- Status and Slip Size are already present in the basic row; avoid duplicates here -->
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Owner</span><input type="text" name="filter_owner" value="<?php echo e($_POST['filter_owner'] ?? ''); ?>" placeholder="Name contains"></label>
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Phone</span><input type="text" name="filter_phone" value="<?php echo e($_POST['filter_phone'] ?? ''); ?>" placeholder="Phone"></label>
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Boat</span><input type="text" name="filter_boat" value="<?php echo e($_POST['filter_boat'] ?? ''); ?>" placeholder="Boat name contains"></label>
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Payment</span>
                                <select name="filter_payment">
                                    <option value="">Any</option>
                                    <option value="paid" <?php if(($_POST['filter_payment'] ?? '')==='paid') echo 'selected'; ?>>Paid</option>
                                    <option value="unpaid" <?php if(($_POST['filter_payment'] ?? '')==='unpaid') echo 'selected'; ?>>Unpaid</option>
                                </select>
                            </label>
                            <label style="display:flex;flex-direction:column;margin-bottom:6px"><span>Slip</span>
                                <select name="dock">
                                    <option value="">Any</option>
                                    <?php foreach($availableDocks as $dck): ?>
                                        <option value="<?php echo e($dck); ?>" <?php if(($_POST['dock'] ?? '')===$dck) echo 'selected'; ?>><?php echo e($dck); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </div>
                    <div style="display:flex;gap:8px;align-items:center">
                    <button type="submit" class="btn btn-ocean">Search</button>
                    <button type="button" id="reset-search" class="btn btn-outline btn-cancel">Reset Search Fields</button>
                    <input type="hidden" name="task_type" id="task_type" value="">
                </div>
            </form>
        </section>
        <script>
        (function(){
            var btn = document.getElementById('search-toggle');
            var panel = document.getElementById('search-options-panel');
            if(!btn || !panel) return;
            btn.addEventListener('click', function(){
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                panel.setAttribute('aria-hidden', expanded ? 'true' : 'false');
                panel.style.display = expanded ? 'none' : 'block';
            });
            // If advanced filters were used in the last POST, open the panel so employee sees them
            var shouldOpen = <?php echo (!empty($_POST['filter_owner'])||!empty($_POST['filter_boat'])||!empty($_POST['filter_phone'])||!empty($_POST['filter_payment'])||!empty($_POST['group_by'])||!empty($_POST['filter_slip_size'])) ? 'true' : 'false'; ?>;
            if(shouldOpen){ btn.setAttribute('aria-expanded','true'); panel.setAttribute('aria-hidden','false'); panel.style.display='block'; }
        })();
        </script>
            <script>
            (function(){
                var select = document.getElementById('payment-method');
                var wrapper = document.getElementById('payment-card-last4-wrapper');
                var input = document.getElementById('payment-card-last4');
                function update(){
                    if(!select || !wrapper || !input) return;
                    if(select.value === 'card'){
                        wrapper.style.display = 'flex';
                        input.required = true;
                    } else {
                        wrapper.style.display = 'none';
                        input.required = false;
                        input.value = '';
                    }
                }
                if(select){ select.addEventListener('change', update); update(); }
                // also ensure when modal opens the field updates (if you use programmatic reset)
                document.addEventListener('click', function(e){
                    if(e.target && e.target.matches && e.target.matches('button[data-take-payment], button[data-show-payment]')){
                        setTimeout(update, 10);
                    }
                });
            })();
            </script>

    <script>
    (function(){
        var modal = document.getElementById('confirm-modal');
        var modalText = document.getElementById('confirm-text');
        var ok = document.getElementById('confirm-ok');
        var cancel = document.getElementById('confirm-cancel');
        var activeForm = null;
        var confirmToken = null;

        // Ensure we capture click on action buttons so we have a form reference
        // This helps in cases where submit isn't fired or the reference is lost.
        document.addEventListener('click', function(evt){
            try{
                var btn = evt.target.closest && evt.target.closest('button, input[type="submit"]');
                if(!btn) return;
                var f = btn.closest && btn.closest('form.confirm-action');
                if(!f) return;
                // don't act on explicit buttons that are type=button
                var t = (btn.getAttribute && (btn.getAttribute('type') || '')).toLowerCase();
                if(t === 'button') return;
                // store snapshot on modal so confirm can use it
                try{ modal._activeForm = f; modal._formSnapshot = []; Array.prototype.forEach.call(f.elements, function(el){ if(el && el.name && el.type !== 'submit' && el.type !== 'button') modal._formSnapshot.push({name: el.name, value: el.value}); }); } catch(e){}
                // attach token mirror
                try{ confirmToken = 'ct_' + Date.now() + '_' + Math.floor(Math.random()*100000); f.setAttribute('data-_confirm_token', confirmToken); if(modal) modal.setAttribute('data-_confirm_token', confirmToken); } catch(e){}
            }catch(e){}
        }, true);

        function showConfirm(message, form){
            if(!modal) return;
            console.debug('showConfirm:', message, form);
            modalText.textContent = message || 'Are you sure?';
            activeForm = form || null;
            // also attach on modal so we have a stable reference if closure is lost
            try { modal._activeForm = activeForm; } catch(e){}
            // snapshot form inputs for programmatic fallback
            modal._formSnapshot = [];
            try{
                if(activeForm && activeForm.elements){
                    Array.prototype.forEach.call(activeForm.elements, function(el){ if(el && el.name && el.type !== 'submit' && el.type !== 'button') modal._formSnapshot.push({name: el.name, value: el.value}); });
                }
            } catch(e){ console.warn('snapshot failed', e); }
            // attach a temporary token to the form so we can find it later if the reference is lost
            try{
                confirmToken = 'ct_' + Date.now() + '_' + Math.floor(Math.random()*100000);
                if(activeForm && activeForm.dataset) activeForm.dataset._confirm_token = confirmToken;
                // mirror token on modal attribute for recovery
                if(confirmToken) modal.setAttribute('data-_confirm_token', confirmToken);
                // record the form's data-action so confirm-ok can know which action to perform
                try {
                    var act = (activeForm && activeForm.getAttribute) ? (activeForm.getAttribute('data-action') || '') : '';
                    if(act) modal.setAttribute('data-confirm-action', act);
                    // set OK button label and style based on action for clearer UX
                    try {
                        if(ok && typeof ok.textContent !== 'undefined'){
                            if(act === 'complete') { ok.textContent = 'Complete'; ok.className = 'btn btn-gold'; }
                            else if(act === 'cancel') { ok.textContent = 'Confirm Cancel'; ok.className = 'btn btn-outline btn-cancel'; }
                            else if(act === 'checkin') { ok.textContent = 'Confirm Check-In'; ok.className = 'btn btn-ocean'; }
                            else { ok.textContent = 'Confirm'; ok.className = 'btn btn-gold'; }
                        }
                    } catch(e){}
                } catch(e){}
            } catch(e){ console.warn('token attach failed', e); confirmToken = null; }
            modal.style.display = 'flex';
        }
        function hideConfirm(){
            if(!modal) return;
            modal.style.display = 'none';
            try{ if(confirmToken){ var f = document.querySelector('form[data-_confirm_token="'+confirmToken+'"]'); if(f) delete f.dataset._confirm_token; } }catch(e){}
            activeForm = null;
            confirmToken = null;
            try { if(modal){ delete modal._activeForm; modal._formSnapshot = null; modal.removeAttribute('data-_confirm_token'); } } catch(e){}
        }

        // delegate submit of any form that requires confirmation
        document.addEventListener('submit', function(e){
            var f = e.target;
            if(!(f instanceof HTMLFormElement)) return;
            if(f.classList && f.classList.contains('confirm-action')){
                // if already confirmed, allow submit
                if(f.dataset && f.dataset._confirmed === '1'){
                    // remove the flag and allow submission
                    delete f.dataset._confirmed;
                    return;
                }
                // prevent immediate submit and show confirm dialog
                e.preventDefault();
                var actionType = f.getAttribute('data-action') || '';
                var msg = '';
                // include reservation id in the confirmation for clarity
                var rid = f.querySelector('input[name="reservation_id"]') ? f.querySelector('input[name="reservation_id"]').value : '';
                if(actionType === 'complete') msg = 'Confirm marking reservation #' + rid + ' as completed?';
                else if(actionType === 'cancel') msg = 'Confirm canceling reservation #' + rid + '?';
                else if(actionType === 'checkin') msg = 'Check-in reservation #' + rid + '? Please make sure payment has been confirmed.';
                else msg = 'Confirm action';
                showConfirm(msg, f);
            }
        }, true);

        function safeSubmit(formEl){
            if(!formEl) throw new Error('no form');
            if(!(formEl instanceof HTMLFormElement)) throw new Error('not a form');
            try{
                var fn = formEl.submit;
                if(typeof fn === 'function') return fn.call(formEl);
            }catch(e){}
            // fallback to native submit invocation in case .submit is shadowed
            try{ return HTMLFormElement.prototype.submit.call(formEl); }catch(e){ throw e; }
        }

        if(cancel){ cancel.addEventListener('click', function(){ hideConfirm(); }); }
        if(ok){ ok.addEventListener('click', function(){
            try{
                console.debug('confirm-ok clicked, activeForm=', activeForm);
                if(!activeForm && confirmToken){
                    // try to recover form by token
                    try{ activeForm = document.querySelector('form[data-_confirm_token="'+confirmToken+'"]'); console.debug('recovered activeForm by token', activeForm); } catch(e){ console.warn('recover failed', e); }
                }
                // also check modal-stored reference
                if(!activeForm && modal && modal._activeForm){ activeForm = modal._activeForm; console.debug('recovered activeForm from modal._activeForm', activeForm); }
                // If this was a 'checkin' action, perform AJAX check-in (preferred UX)
                var confirmAct = modal && modal.getAttribute ? modal.getAttribute('data-confirm-action') : null;
                if (confirmAct === 'checkin') {
                    // try to obtain reservation_id
                    var rid = null;
                    if (activeForm) { var rr = activeForm.querySelector && activeForm.querySelector('input[name="reservation_id"]'); if (rr) rid = rr.value; }
                    if (!rid && modal && modal._formSnapshot) { for (var i = 0; i < modal._formSnapshot.length; i++) { if (modal._formSnapshot[i].name === 'reservation_id') { rid = modal._formSnapshot[i].value; break; } } }
                    if (!rid) { hideConfirm(); alert('Reservation id not found for check-in'); return; }
                    var fd = new FormData(); fd.append('action','checkin_reservation'); fd.append('reservation_id', rid); fd.append('ajax','1');
                    hideConfirm();
                    fetch(window.location.pathname, { 
                        method: 'POST', 
                        body: fd, 
                        credentials: 'same-origin', 
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    })
                    .then(function(resp){
                        return resp.text().then(function(txt){
                            var parsed = null;
                            try { parsed = JSON.parse(txt); } catch(e){ parsed = null; }
                            if (parsed) {
                                if (parsed.status === 'ok') { window.location.reload(); return; }
                                if (parsed.status === 'blocked') { alert('Check-in blocked: ' + (parsed.message || '')); return; }
                                if (parsed.status === 'error') { alert('Server error: ' + (parsed.message || '')); return; }
                            }
                            // fallback: text inspection
                            if (txt && txt.indexOf('Reservation checked in') !== -1) { window.location.reload(); return; }
                            alert('Server response: ' + (txt ? txt.substring(0,1200) : '(no response)'));
                        });
                    }).catch(function(err){ console.error('checkin fetch error', err); alert('Network error during check-in: ' + (err && err.message ? err.message : err)); });
                    return;
                }

                // If this was a 'complete' action, perform AJAX POST (preferred UX)
                if (confirmAct === 'complete') {
                    var rid = null;
                    if (activeForm) { var rr = activeForm.querySelector && activeForm.querySelector('input[name="reservation_id"]'); if (rr) rid = rr.value; }
                    if (!rid && modal && modal._formSnapshot) { for (var i = 0; i < modal._formSnapshot.length; i++) { if (modal._formSnapshot[i].name === 'reservation_id') { rid = modal._formSnapshot[i].value; break; } } }
                    if (!rid) { hideConfirm(); alert('Reservation id not found for completion'); return; }
                    var fd = new FormData(); fd.append('action', 'complete_reservation'); fd.append('reservation_id', rid); fd.append('ajax', '1');
                    hideConfirm();
                    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (resp) {
                        return resp.text().then(function (txt) {
                            var parsed = null;
                            try { parsed = JSON.parse(txt); } catch(e) { parsed = null; }
                            if (parsed) {
                                if (parsed.status === 'ok') { window.location.reload(); return; }
                                if (parsed.status === 'no_update') { alert('No rows updated. Server debug:\n' + JSON.stringify(parsed, null, 2)); return; }
                                if (parsed.status === 'error') { alert('Server error: ' + parsed.message); return; }
                                if (parsed.status === 'blocked') { alert('Action blocked: ' + (parsed.message || '')); return; }
                            }
                            if (txt && txt.indexOf('Reservation marked completed.') !== -1) { window.location.reload(); return; }
                            var snippet = txt ? txt.substring(0, 1600) : '(no response)';
                            alert('Server response:\n' + snippet);
                        });
                    }).catch(function (err) { console.error('fetch complete error', err); alert('Submission error: ' + (err && err.message ? err.message : 'network')); });
                    return;
                }
                // If this was a 'cancel' action, prefer submitting the original form (fallback behavior)
                if (confirmAct === 'cancel') {
                    var rid = null;
                    if (activeForm) { var rr2 = activeForm.querySelector && activeForm.querySelector('input[name="reservation_id"]'); if (rr2) rid = rr2.value; }
                    if (!rid && modal && modal._formSnapshot) { for (var i = 0; i < modal._formSnapshot.length; i++) { if (modal._formSnapshot[i].name === 'reservation_id') { rid = modal._formSnapshot[i].value; break; } } }
                    if (activeForm) {
                        try { hideConfirm(); if (activeForm.dataset) activeForm.dataset._confirmed = '1'; safeSubmit(activeForm); return; } catch (e) { console.warn('form submit fallback failed', e); }
                    }
                    if (rid) {
                        var fd2 = new FormData(); fd2.append('action', 'cancel_reservation'); fd2.append('reservation_id', rid); fd2.append('ajax', '1');
                        hideConfirm();
                        fetch(window.location.pathname, { method: 'POST', body: fd2, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (resp) {
                            return resp.text().then(function (txt) {
                                var parsed = null;
                                try { parsed = JSON.parse(txt); } catch(e) { parsed = null; }
                                if (parsed) { if (parsed.status === 'ok') { window.location.reload(); return; } if (parsed.status === 'error') { alert('Server error: ' + parsed.message); return; } if (parsed.status === 'blocked') { alert('Action blocked: ' + (parsed.message || '')); return; } }
                                if (txt && txt.indexOf('Reservation canceled.') !== -1) { window.location.reload(); return; }
                                var snippet = txt ? txt.substring(0, 1600) : '(no response)';
                                alert('Server response:\n' + snippet);
                            });
                        }).catch(function (err) { console.error('fetch cancel error', err); alert('Submission error: ' + (err && err.message ? err.message : 'network')); });
                        return;
                    }
                }
                if(activeForm){
                    // mark form as confirmed then submit; submit handler will allow it
                    try{ activeForm.dataset._confirmed = '1'; } catch(e){}
                    hideConfirm();
                    // use safeSubmit to avoid issues when a form control shadows `submit`
                    try{ safeSubmit(activeForm); }
                    catch(subEx){ console.error('submit error', subEx); alert('Submission error: ' + (subEx && subEx.message ? subEx.message : 'unknown')); }
                } else {
                    // fallback: build programmatic POST from snapshot if available
                    var snap = (modal && modal._formSnapshot) ? modal._formSnapshot : [];
                    if(snap && snap.length){
                        var f = document.createElement('form'); f.method='POST'; f.action = window.location.pathname;
                        snap.forEach(function(kv){ var inp = document.createElement('input'); inp.type='hidden'; inp.name = kv.name; inp.value = kv.value; f.appendChild(inp); });
                        document.body.appendChild(f);
                        try{ hideConfirm(); safeSubmit(f); return; } catch(subEx){ console.error('fallback submit error', subEx); }
                    }
                    hideConfirm();
                    alert('Unable to locate the original form to submit.');
                }
            }catch(e){ console.error('confirm handler error', e); alert('Confirm handler error: ' + (e && e.message ? e.message : 'unknown')); }
        }); }

        // close when clicking backdrop
        if(modal){ modal.addEventListener('click', function(evt){ if(evt.target === modal) hideConfirm(); }); }
    })();
    </script>

        <?php
        // If redirected here after a successful save, load the fresh reservation for a read-only view
        $showSavedView = false;
        $viewReservation = null;
        if (!empty($_GET['view_after_save']) && !empty($_GET['reservation_id'])) {
            $vid = intval($_GET['reservation_id']);
            try {
                $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1'); $s->execute([$vid]);
                $viewReservation = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($viewReservation) {
                    // If the reservation row has only slip_ID, try to resolve location_code from slips table for display
                    $slipId = $viewReservation['slip_ID'] ?? $viewReservation['slipId'] ?? $viewReservation['slip_id'] ?? null;
                    if (empty($viewReservation['location_code']) && !empty($slipId)) {
                        try {
                            $sq = $pdo->prepare('SELECT COALESCE(location_code, location, slip_number) AS location_code FROM slips WHERE slip_ID = ? LIMIT 1');
                            $sq->execute([ $slipId ]);
                            $srow = $sq->fetch(PDO::FETCH_ASSOC) ?: null;
                            if ($srow && !empty($srow['location_code'])) { $viewReservation['location_code'] = $srow['location_code']; }
                        } catch (Exception $_) { /* ignore */ }
                    }

                    // If user details are not present on the reservation, try to load them from the users table
                    $uid = $viewReservation['user_ID'] ?? $viewReservation['userId'] ?? $viewReservation['user_id'] ?? $viewReservation['owner_id'] ?? null;
                    if ((empty($viewReservation['first_name']) && empty($viewReservation['email'])) && !empty($uid)) {
                        try {
                            $uq = $pdo->prepare("SELECT user_ID, email, first_name, last_name, COALESCE(user_phone, phone, '') AS user_phone FROM users WHERE user_ID = ? LIMIT 1");
                            $uq->execute([$uid]);
                            $urow = $uq->fetch(PDO::FETCH_ASSOC) ?: null;
                            if ($urow) {
                                $viewReservation['first_name'] = $viewReservation['first_name'] ?? $urow['first_name'] ?? '';
                                $viewReservation['last_name'] = $viewReservation['last_name'] ?? $urow['last_name'] ?? '';
                                $viewReservation['email'] = $viewReservation['email'] ?? $urow['email'] ?? '';
                                $viewReservation['user_phone'] = $viewReservation['user_phone'] ?? $urow['user_phone'] ?? '';
                            }
                        } catch (Exception $_) { /* ignore */ }
                    }
                    $showSavedView = true;
                }
            } catch (Exception $_) { $viewReservation = null; $showSavedView = false; }
        }
        ?>

        <?php if ($showSavedView && !empty($viewReservation)): ?>
            <section style="margin-top:16px;background:#fff;padding:16px;border-radius:10px;">
                <div style="background:#f4fff4;border:1px solid #c2f5c2;padding:10px;border-radius:8px;margin-bottom:12px;color:#1f7a1f">
                    <strong>Reservation updated.</strong>
                </div>
                <h3>Reservation #<?php echo e($viewReservation['reservation_ID'] ?? ($viewReservation['reservation_id'] ?? '')); ?></h3>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <div style="flex:1;min-width:220px;padding:10px;border:1px solid #eef2f5;border-radius:8px;background:#fbfdff">
                        <strong>User</strong><br>
                        <?php echo e(trim(($viewReservation['first_name'] ?? '') . ' ' . ($viewReservation['last_name'] ?? '')) ?: ($viewReservation['email'] ?? '')); ?><br>
                        <small><?php echo e($viewReservation['email'] ?? ''); ?></small><br>
                    </div>
                    <div style="flex:1;min-width:220px;padding:10px;border:1px solid #eef2f5;border-radius:8px;background:#fbfdff">
                        <strong>Reservation</strong><br>
                        <small>Slip: <?php echo e($viewReservation['slip_ID'] ?? ($viewReservation['location_code'] ?? '')); ?></small><br>
                        <small>Dates: <?php echo e($viewReservation['start_date'] ?? ''); ?> → <?php echo e($viewReservation['end_date'] ?? ''); ?></small><br>
                        <small>Status: <?php echo e($viewReservation['reservation_status'] ?? ''); ?></small><br>
                        <small>Total: $<?php echo e($viewReservation['total_cost'] ?? '0.00'); ?></small>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($editReservation)): ?>
            <section style="margin-top:16px;background:#fff;padding:16px;border-radius:10px;">
                <h3>Edit Reservation #<?php echo e($editReservation['reservation_ID'] ?? $editReservation['reservation_id'] ?? $editReservation['reservationId'] ?? ''); ?></h3>
                <?php if (!empty($errors)): ?>
                    <div style="background:#fff4f4;border:1px solid #f5c2c2;padding:10px;border-radius:8px;margin-bottom:12px;color:#7a1f1f">
                        <strong>Errors:</strong>
                        <ul style="margin:8px 0 0 18px;padding:0">
                            <?php foreach ($errors as $err) { echo '<li>'.htmlspecialchars($err).'</li>'; } ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save_reservation">
                    <input type="hidden" name="reservation_id" value="<?php echo e($editReservation['reservation_ID'] ?? $editReservation['reservation_id'] ?? $editReservation['reservationId'] ?? ''); ?>">

                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:12px">
                        <div style="flex:1;min-width:220px;padding:10px;border:1px solid #eef2f5;border-radius:8px;background:#fbfdff">
                            <strong>User</strong><br>
                            <?php if (!empty($editUser)): ?>
                                <?php echo e(trim(($editUser['first_name'] ?? '') . ' ' . ($editUser['last_name'] ?? '')) ?: ($editUser['email'] ?? '')); ?><br>
                                <small><?php echo e($editUser['email'] ?? ''); ?></small><br>
                                <small><?php echo e($editUser['user_phone'] ?? ''); ?></small>
                            <?php elseif (!empty($editReservation['first_name']) || !empty($editReservation['last_name']) || !empty($editReservation['email'])): ?>
                                <?php echo e(trim(($editReservation['first_name'] ?? '') . ' ' . ($editReservation['last_name'] ?? '')) ?: ($editReservation['email'] ?? '')); ?><br>
                                <small><?php echo e($editReservation['email'] ?? ''); ?></small><br>
                                <small><?php echo e($editReservation['user_phone'] ?? ''); ?></small>
                            <?php else: ?>
                                <small>No user details available</small>
                                <?php if (!empty($_SESSION['is_employee'])): ?>
                                    <?php $detectedUid = $editReservation['user_ID'] ?? $editReservation['userId'] ?? $editReservation['user_id'] ?? $editReservation['owner_id'] ?? null; ?>
                                    <div style="margin-top:8px;background:#fff7ed;border:1px solid rgba(212,175,55,0.12);padding:8px;border-radius:6px;color:#3b2f00;font-size:0.9rem">
                                        <strong>Debug:</strong><br>
                                        Detected user FK candidate: <?php echo e($detectedUid ?? '(none)'); ?><br>
                                        Reservation email: <?php echo e($editReservation['email'] ?? '(none)'); ?><br>
                                        Reservation keys: <small style="display:block;margin-top:6px;font-family:monospace;background:#fff;padding:6px;border-radius:6px;color:#222;white-space:pre-wrap"><?php
                                            $keys = array_intersect_key($editReservation, array_flip(['user_ID','userId','user_id','owner_id','email','first_name','last_name']));
                                            echo htmlspecialchars(json_encode($keys, JSON_PRETTY_PRINT));
                                        ?></small>
                                        <div style="margin-top:6px">Lookup attempted: <?php echo e(empty($editUser) ? 'no matching user found' : 'found'); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:220px;padding:10px;border:1px solid #eef2f5;border-radius:8px;background:#fbfdff">
                            <strong>Boat</strong><br>
                            <?php if (!empty($editBoat)): ?>
                                <?php echo e($editBoat['boat_name'] ?? 'Unnamed'); ?> <br>
                                <small>Length: <?php echo e($editBoat['boat_length'] ?? ''); ?> ft</small>
                            <?php else: ?>
                                <small>No boat details available</small>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px">
                            <button type="button" class="btn btn-outline" data-edit-action="edit_user" data-edit-id="<?php echo e($editReservation['user_ID'] ?? ($editReservation['userId'] ?? ($editReservation['user_id'] ?? ($editReservation['owner_id'] ?? '')))); ?>">Edit User</button>
                            <button type="button" class="btn btn-outline" data-edit-action="edit_boat" data-edit-id="<?php echo e($editReservation['boat_ID'] ?? ($editReservation['boatId'] ?? ($editReservation['boat_id'] ?? ''))); ?>">Edit Boat</button>
                        </div>
                    </div>
                    <label>Start Date: <input type="date" name="start_date" value="<?php echo e($editReservation['start_date'] ?? ''); ?>"></label>
                    <label>End Date: <input type="date" name="end_date" value="<?php echo e($editReservation['end_date'] ?? ''); ?>"></label>
                    <?php
                        $curSlipSize = $editReservation['slip_size'] ?? ($editReservation['size'] ?? ($editReservation['length_ft'] ?? ''));
                        $curLoc = $editReservation['location_code'] ?? ($editReservation['location'] ?? '');
                        // If reservation references a slip_ID but doesn't include slip_size/location_code fields,
                        // try to fetch them from the slips table so the selects prefill correctly.
                        if ((empty($curSlipSize) || empty($curLoc)) && !empty($editReservation)) {
                            $maybeSlipId = $editReservation['slip_ID'] ?? $editReservation['slipId'] ?? $editReservation['slip_id'] ?? null;
                            if (!empty($maybeSlipId) && $pdo) {
                                try {
                                    $sq = $pdo->prepare("SELECT slip_size, location_code FROM slips WHERE slip_ID = ? LIMIT 1");
                                    $sq->execute([$maybeSlipId]);
                                    $sr = $sq->fetch(PDO::FETCH_ASSOC) ?: null;
                                    if ($sr) {
                                        if (empty($curSlipSize) && !empty($sr['slip_size'])) $curSlipSize = $sr['slip_size'];
                                        if (empty($curLoc) && !empty($sr['location_code'])) $curLoc = $sr['location_code'];
                                    }
                                } catch (Exception $_) { /* ignore */ }
                            }
                        }
                    ?>
                    <?php
                        // Ensure slip size and location lists are populated at render-time as a last resort.
                        // Use explicit column names first (most reliable for common schemas).
                        if (empty($slipSizes)) {
                            try {
                                $slipSizes = [];
                                $q = $pdo->query("SELECT DISTINCT slip_size FROM slips WHERE slip_size IS NOT NULL ORDER BY slip_size ASC");
                                if ($q) {
                                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                        $v = $r['slip_size'] ?? null;
                                        if ($v === null || $v === '') continue;
                                        $slipSizes[] = $v;
                                    }
                                }
                            } catch (Exception $_) { $slipSizes = []; }
                        }

                        if (empty($locationCodes)) {
                            try {
                                $locationCodes = [];
                                // Pull explicit location_code values; if a row lacks location_code, fall back to a Slip label
                                $q2 = $pdo->query("SELECT slip_ID, location_code FROM slips ORDER BY location_code ASC, slip_ID ASC");
                                if ($q2) {
                                    foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $r2) {
                                        $v2 = $r2['location_code'] ?? null;
                                        if ($v2 === null || $v2 === '') {
                                            $v2 = 'Slip ' . ($r2['slip_ID'] ?? '');
                                        }
                                        if ($v2 === null || $v2 === '') continue;
                                        $locationCodes[] = $v2;
                                    }
                                    // ensure unique and preserve order
                                    $locationCodes = array_values(array_unique($locationCodes));
                                }
                            } catch (Exception $_) { $locationCodes = []; }
                        }

                        // final fallback for slip sizes
                        if (empty($slipSizes)) $slipSizes = ['26','40','50'];
                    ?>
                    
                    <label>Slip Size:
                        <select name="slip_size">
                            <option value="">(select)</option>
                            <?php foreach (array_values($slipSizes) as $ss) { $sel = ((string)$ss === (string)$curSlipSize) ? 'selected' : ''; echo '<option value="'.htmlspecialchars($ss).'" '.$sel.'>'.htmlspecialchars($ss).' ft</option>'; } ?>
                        </select>
                    </label>
                    <label>Location Code:
                        <select name="location_code">
                            <option value="">(select)</option>
                            <?php foreach (array_values($locationCodes) as $lc) { $sel = ((string)$lc === (string)$curLoc) ? 'selected' : ''; echo '<option value="'.htmlspecialchars($lc).'" '.$sel.'>'.htmlspecialchars($lc).'</option>'; } ?>
                        </select>
                    </label>
                    <label>Months Duration: <input type="text" name="months_duration" id="reservationMonthsDuration" value="<?php echo e($editReservation['months_duration'] ?? ''); ?>" readonly style="width:80px"></label>
                    <label>Total Cost: <input type="text" name="total_cost" value="<?php echo e($editReservation['total_cost'] ?? ''); ?>" readonly id="reservationTotalCost"></label>
                    <label>Status:
                        <select name="reservation_status">
                            <?php
                                $opts = ['confirmed','checked_in','completed','canceled'];
                                $curst = $editReservation['reservation_status'] ?? '';
                                foreach ($opts as $o) {
                                    $sel = ($o === $curst) ? 'selected' : '';
                                    echo '<option value="'.htmlspecialchars($o).'" '.$sel.'>'.htmlspecialchars(ucwords(str_replace('_',' ',$o))).'</option>';
                                }
                            ?>
                        </select>
                    </label>
                    
                    <?php if (!empty($_SESSION['is_employee'])): ?>
                        <label style="display:flex;flex-direction:column;margin-top:8px"><span>Employee Password (confirm)</span><input type="password" name="confirm_password" required></label>
                    <?php endif; ?>
                    <div style="margin-top:8px;"><button type="submit" class="btn btn-ocean">Save</button> <a href="employee_dashboard.php" class="btn btn-ghost">Cancel</a></div>
                </form>

                <script>
                (function(){
                    try{
                        var PRICE_PER_FOOT = 10.50;
                        var HOOKUP_PER_MONTH = 10.50;
                        var start = document.querySelector('input[name="start_date"]');
                        var end = document.querySelector('input[name="end_date"]');
                        var slipSize = document.querySelector('[name="slip_size"]');
                        var totalField = document.getElementById('reservationTotalCost');
                        var boatLen = <?php echo json_encode(floatval($editBoat['boat_length'] ?? ($editReservation['boat_length'] ?? 0))); ?> || 0;

                        function computeMonths(s,e){
                            if(!s||!e) return 1;
                            var sd = new Date(s); var ed = new Date(e);
                            if(isNaN(sd) || isNaN(ed) || ed <= sd) return 1;
                            var msPerDay = 1000*60*60*24;
                            var days = Math.ceil((ed - sd)/msPerDay);
                            var months = Math.max(1, Math.ceil(days/30));
                            return months;
                        }
                        function updateCost(){
                            try{
                                var s = start && start.value ? start.value : null;
                                var e = end && end.value ? end.value : null;
                                var months = computeMonths(s,e);
                                var baseMonthly = (boatLen || 0) * PRICE_PER_FOOT;
                                var monthlySum = baseMonthly + HOOKUP_PER_MONTH;
                                var total = (monthlySum * months);
                                if(totalField) totalField.value = total.toFixed(2);
                                var monthsEl = document.getElementById('reservationMonthsDuration'); if(monthsEl) monthsEl.value = months;
                            }catch(_){ }
                        }
                        if(start) start.addEventListener('change', updateCost);
                        if(end) end.addEventListener('change', updateCost);
                        if(slipSize) slipSize.addEventListener('input', updateCost);
                        // initial
                        updateCost();
                    }catch(e){ console.warn('estimator failed',e); }
                })();
                </script>

                <?php if (!empty($requirePaymentFor) && intval($requirePaymentFor) === intval($editReservation['reservation_ID'] ?? $editReservation['reservation_id'] ?? $editReservation['reservationId'] ?? 0)): ?>
                    <div style="margin-top:12px;border-top:1px dashed #e6eef3;padding-top:12px">
                        <h4>Record Payment (required before check-in)</h4>
                        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="reservation_id" value="<?php echo e($editReservation['reservation_ID'] ?? $editReservation['reservation_id'] ?? $editReservation['reservationId'] ?? ''); ?>">
                            <label style="display:flex;flex-direction:column"><span>Amount</span><input type="number" step="0.01" name="amount" required></label>
                            <label style="display:flex;flex-direction:column"><span>Method</span>
                                <select name="method">
                                    <option>cash</option>
                                    <option>card</option>
                                    <option>check</option>
                                    <option>other</option>
                                </select>
                            </label>
                            <div style="margin-top:18px;">
                                <button type="submit" class="btn btn-gold">Record Payment</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                </form>
            </section>
                <?php if (!empty($showEditUserForm) && !empty($editUser)): ?>
                    <section class="inline-edit" style="margin-top:16px;background:#fff;padding:16px;border-radius:10px;">
                        <h3>Edit User #<?php echo e($editUser['user_ID'] ?? ($editUser['id'] ?? '')); ?></h3>
                        <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
                            <input type="hidden" name="action" value="save_user">
                            <input type="hidden" name="user_id" value="<?php echo e($editUser['user_ID'] ?? ($editUser['id'] ?? '')); ?>">
                            <label style="display:flex;flex-direction:column"><span>First Name</span><input type="text" name="first_name" value="<?php echo e($editUser['first_name'] ?? ''); ?>"></label>
                            <label style="display:flex;flex-direction:column"><span>Last Name</span><input type="text" name="last_name" value="<?php echo e($editUser['last_name'] ?? ''); ?>"></label>
                            <label style="display:flex;flex-direction:column"><span>Email</span><input type="email" name="email" value="<?php echo e($editUser['email'] ?? ''); ?>"></label>
                            <?php if (!empty($_SESSION['is_employee'])): ?>
                                <label style="display:flex;flex-direction:column"><span>Employee Password (confirm)</span><input type="password" name="confirm_password" required></label>
                            <?php endif; ?>
                            <div style="width:100%;margin-top:8px"><button type="submit" class="btn btn-ocean">Save User</button> <a href="employee_dashboard.php" class="btn btn-ghost">Cancel</a></div>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if (!empty($showEditBoatForm) && !empty($editBoat)): ?>
                    <section class="inline-edit" style="margin-top:16px;background:#fff;padding:16px;border-radius:10px;">
                        <h3>Edit Boat #<?php echo e($editBoat['boat_ID'] ?? ''); ?></h3>
                        <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
                            <input type="hidden" name="action" value="save_boat">
                            <input type="hidden" name="boat_id" value="<?php echo e($editBoat['boat_ID'] ?? ''); ?>">
                            <label style="display:flex;flex-direction:column"><span>Boat Name</span><input type="text" name="boat_name" value="<?php echo e($editBoat['boat_name'] ?? ''); ?>"></label>
                            <label style="display:flex;flex-direction:column"><span>Boat Length (ft)</span><input type="number" step="0.1" name="boat_length" value="<?php echo e($editBoat['boat_length'] ?? ''); ?>"></label>
                            <div style="width:100%;margin-top:8px"><button type="submit" class="btn btn-ocean">Save Boat</button> <a href="employee_dashboard.php" class="btn btn-ghost">Cancel</a></div>
                        </form>
                    </section>
                <?php endif; ?>
        <?php endif; ?>

        <?php
            // grouping support: group search results when requested
            $group_by = $_POST['group_by'] ?? '';
            // if not explicitly set, auto-select grouping based on variability in results
            if (empty($group_by) && !empty($searchResults)) {
                $statusVals = [];
                $slipVals = [];
                $dateVals = [];
                $scopeKey = $resultType ?? ($_POST['scope'] ?? 'reservations');
                foreach ($searchResults as $it) {
                    if ($scopeKey === 'reservations') {
                        $statusVals[] = $it['reservation_status'] ?? '';
                        $slipVals[] = $it['slip_size'] ?? '';
                        $dateVals[] = formatDate($it['start_date'] ?? '');
                    } else {
                        $statusVals[] = 'waitlist';
                        $slipVals[] = $it['preferred_slip_size'] ?? '';
                        $dateVals[] = $it['preferred_start_date'] ?? '';
                    }
                }
                $statusCount = count(array_unique(array_filter($statusVals)));
                $dateCount = count(array_unique(array_filter($dateVals)));
                $slipCount = count(array_unique(array_filter($slipVals)));
                // priority: status, date, slip size (preserve previous behavior)
                if ($statusCount > 1) $group_by = 'status';
                elseif ($dateCount > 1) $group_by = 'date';
                elseif ($slipCount > 1) $group_by = 'slip_size';
            }
            $groupedResults = [];
            if (!empty($searchResults) && !empty($group_by)) {
                $scopeKey = $resultType ?? ($_POST['scope'] ?? 'reservations');
                if ($scopeKey === 'reservations') {
                    foreach ($searchResults as $r) {
                        if ($group_by === 'date') $k = formatDate($r['start_date'] ?? '') ?: 'No Date';
                        elseif ($group_by === 'status') $k = $r['reservation_status'] ?? 'Unknown';
                        elseif ($group_by === 'dock') $k = $r['location_code'] ?? 'Unknown';
                        elseif ($group_by === 'slip_size') $k = (isset($r['slip_size']) && $r['slip_size'] !== '') ? $r['slip_size'] : 'Unknown';
                        else $k = 'Other';
                        $groupedResults[$k][] = $r;
                    }
                } elseif ($scopeKey === 'waitlist') {
                    foreach ($searchResults as $w) {
                        if ($group_by === 'date') $k = ($w['preferred_start_date'] ?? '') ?: 'No Date';
                        elseif ($group_by === 'status') $k = 'Waitlist';
                        elseif ($group_by === 'slip_size') $k = ($w['preferred_slip_size'] ?? 'Other');
                        else $k = ($w['preferred_slip_size'] ?? 'Other');
                        $groupedResults[$k][] = $w;
                    }
                }
                // normalize: sort rows within each group by start date
                foreach ($groupedResults as $gk => &$grows) {
                    usort($grows, function($a,$b){
                        $ta = !empty($a['start_date']) ? strtotime($a['start_date']) : PHP_INT_MAX;
                        $tb = !empty($b['start_date']) ? strtotime($b['start_date']) : PHP_INT_MAX;
                        return $ta <=> $tb;
                    });
                }
                unset($grows);
                // if grouping by slip_size, sort groups by numeric slip size (Unknown goes last)
                if ($group_by === 'slip_size' && !empty($groupedResults)) {
                    uksort($groupedResults, function($a,$b){
                        if ($a === 'Unknown') return 1;
                        if ($b === 'Unknown') return -1;
                        // compare numeric values when possible
                        $na = is_numeric($a) ? (float)$a : floatval(preg_replace('/[^0-9\.]/','',$a));
                        $nb = is_numeric($b) ? (float)$b : floatval(preg_replace('/[^0-9\.]/','',$b));
                        return $na <=> $nb;
                    });
                }
            }
        ?>
        <?php if (!empty($searchResults)): ?>
            <section class="results panel" style="margin-top:18px;">
                <h3>Search Results (<?php echo count($searchResults); ?>)</h3>
                <?php if (($resultType ?? ($_POST['scope'] ?? 'reservations')) === 'reservations'): ?>
                    <?php
                        // Preserve original ordering, group by status while keeping each group's internal order
                        $statusGroups = [];
                        $statusOrder = [];
                        foreach ($searchResults as $rr) {
                            $st = strtolower(trim((string)($rr['reservation_status'] ?? 'unknown')));
                            if ($st === '') $st = 'unknown';
                            if (!isset($statusGroups[$st])) { $statusGroups[$st] = []; $statusOrder[] = $st; }
                            $statusGroups[$st][] = $rr;
                        }
                        // Place 'checked_in' group first if present, otherwise preserve encounter order
                        $orderedStatuses = [];
                        if (isset($statusGroups['checked_in'])) $orderedStatuses[] = 'checked_in';
                        foreach ($statusOrder as $s) { if ($s === 'checked_in') continue; $orderedStatuses[] = $s; }
                    ?>
                    <?php foreach ($orderedStatuses as $stKey): ?>
                        <?php $rows = $statusGroups[$stKey] ?? []; if (empty($rows)) continue; ?>
                        <div class="status-card">
                            <div class="status-title"><?php echo e(ucwords(str_replace('_',' ',$stKey))); ?> &middot; <?php echo count($rows); ?> items</div>
                            <?php
                                // subgroup by slip_size inside this status card (preserve row ordering)
                                $subgroups = [];
                                foreach ($rows as $r) {
                                    $size = isset($r['slip_size']) && $r['slip_size'] !== '' ? $r['slip_size'] : 'Unknown';
                                    $subgroups[$size][] = $r;
                                }
                                uksort($subgroups, function($a,$b){ if ($a === 'Unknown') return 1; if ($b === 'Unknown') return -1; return floatval($a) <=> floatval($b); });
                            ?>
                            <?php foreach ($subgroups as $size => $grows): ?>
                                <?php $sizeClass = 'size-' . (preg_match('/^\d+$/', (string)$size) ? $size : 'unknown'); ?>
                                <div class="slip-subheader <?php echo e($sizeClass); ?>">Slip: <?php echo e($size); ?> &middot; <?php echo count($grows); ?> items</div>
                                <div class="res-cards">
                                    <?php foreach ($grows as $r): ?>
                                        <?php
                                            $now_ts = time();
                                            $isSoon = false;
                                            $start_ts = !empty($r['start_date']) ? strtotime($r['start_date']) : false;
                                            $end_ts = !empty($r['end_date']) ? strtotime($r['end_date']) : false;
                                            $day = 24 * 3600;
                                            if ($start_ts !== false && abs($start_ts - $now_ts) <= $day) { $isSoon = true; }
                                            if ($end_ts !== false && abs($end_ts - $now_ts) <= $day) { $isSoon = true; }
                                        ?>
                                        <?php $stClass = strtolower(trim((string)($r['reservation_status'] ?? ''))); ?>
                                        <div class="res-card<?php echo $isSoon ? ' ending-soon' : ''; ?><?php echo ($stClass === 'completed') ? ' completed' : ''; ?>">
                                            <div class="left">
                                                <div class="slip">Slip: <?php echo e($r['location_code'] ?? ''); ?></div>
                                                <div class="meta"><strong><?php echo e($r['slip_size'] ?? ''); ?> ft</strong><br><small>Res# <?php echo e($r['reservation_ID']); ?></small></div>
                                                <div class="status"><span class="badge"><?php echo e($r['reservation_status'] ?? ''); ?></span></div>
                                            </div>
                                            <div class="body">
                                                <div class="details">
                                                    <div>
                                                        <strong><?php echo e((trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['email'] ?? 'Guest'))); ?></strong>
                                                        <?php if(!empty($r['email'])): ?><br><small>Email: <?php echo e($r['email']); ?></small><?php endif; ?>
                                                        <?php if(!empty($r['user_phone'])): ?><br><small>Phone: <?php echo e($r['user_phone']); ?></small><?php endif; ?>
                                                        <br><small>Boat: <?php echo e($r['boat_name'] ?? 'No boat'); ?></small>
                                                        <?php if(!empty($r['boat_length'])): ?><br><small>Length: <?php echo e($r['boat_length']); ?> ft</small><?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="dates">
                                                    <div class="meta" style="margin-top:0"><strong>Dates:</strong><br><?php echo e(formatDate($r['start_date'] ?? '')); ?> → <?php echo e(formatDate($r['end_date'] ?? '')); ?><br><strong>Months:</strong> <?php echo e($r['months_duration'] ?? ''); ?></div>
                                                </div>
                                                <div class="actions">
                                                    <form method="post" style="display:inline-block"><input type="hidden" name="action" value="edit_reservation"><input type="hidden" name="reservation_id" value="<?php echo e($r['reservation_ID']); ?>"><button class="btn btn-outline btn-ocean">Edit</button></form>
                                                    <?php $st = strtolower(trim((string)($r['reservation_status'] ?? ''))); ?>
                                                    <?php if(!in_array($st, ['checked_in','completed','canceled'])): ?>
                                                        <form method="post" class="confirm-action" data-action="checkin" style="display:inline-block"><input type="hidden" name="action" value="checkin_reservation"><input type="hidden" name="reservation_id" value="<?php echo e($r['reservation_ID']); ?>"><button class="btn btn-ocean" data-start="<?php echo e($r['start_date'] ?? ''); ?>">Check In</button></form>
                                                    <?php else: ?>
                                                        <button class="btn btn-ocean btn-placeholder" aria-hidden="true" style="visibility:hidden">Check In</button>
                                                    <?php endif; ?>
                                                    <?php if(!in_array($st, ['completed','canceled'])): ?>
                                                        <form method="post" class="confirm-action" data-action="complete" style="display:inline-block"><input type="hidden" name="action" value="complete_reservation"><input type="hidden" name="reservation_id" value="<?php echo e($r['reservation_ID']); ?>"><button class="btn btn-gold">Complete</button></form>
                                                    <?php else: ?>
                                                        <button class="btn btn-gold btn-placeholder" aria-hidden="true" style="visibility:hidden">Complete</button>
                                                    <?php endif; ?>
                                                    <?php if(!in_array($st, ['canceled','completed'])): ?>
                                                        <form method="post" class="confirm-action" data-action="cancel" style="display:inline-block"><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?php echo e($r['reservation_ID']); ?>"><button class="btn btn-outline btn-cancel">Cancel</button></form>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline btn-cancel btn-placeholder" aria-hidden="true" style="visibility:hidden">Cancel</button>
                                                    <?php endif; ?>
                                                    <?php
                                                        $payLabel = '';
                                                        if (!empty($r['last_payment_amount']) || !empty($r['last_payment_last4'])) {
                                                            $amt = isset($r['last_payment_amount']) ? number_format((float)$r['last_payment_amount'],2) : '';
                                                            $last4 = $r['last_payment_last4'] ?? '';
                                                            $method = $r['last_payment_method'] ?? '';
                                                            $payLabel = trim(($amt !== '' ? "Paid \\\${$amt}" : '') . ($last4 ? " • ****{$last4}" : '') . ($method && !$last4 ? " ({$method})" : ''));
                                                        }
                                                    ?>
                                                    <?php if ((empty($r['payments_count']) || intval($r['payments_count']) === 0) && !in_array($st, ['canceled','cancelled'])): ?>
                                                        <button type="button" class="btn" data-take-payment="<?php echo e($r['reservation_ID']); ?>" data-default-amount="<?php echo e($r['total_cost'] ?? ''); ?>">Take Payment</button>
                                                    <?php else: ?>
                                                        <button class="btn btn-placeholder" aria-hidden="true" style="visibility:hidden">Take Payment</button>
                                                    <?php endif; ?>
                                                    <?php if(!empty($payLabel)): ?><span class="payment-summary"><?php echo e($payLabel); ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (($resultType ?? ($_POST['scope'] ?? 'waitlist')) === 'waitlist'): ?>
                    <?php if (!empty($group_by) && !empty($groupedResults)): ?>
                        <?php foreach ($groupedResults as $gkey => $items): ?>
                            <div class="group-header" style="margin:12px 0;padding:8px 12px;border-radius:8px;background:rgba(3,102,214,0.06);color:#043859;font-weight:700">
                                <?php echo e($gkey); ?> &middot; <?php echo count($items); ?> items
                            </div>
                            <table>
                                <thead><tr><th>Waitlist#</th><th>Name</th><th>Boat</th><th>Preferred Dates</th><th>Preferred Size</th><th>Position</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach($items as $w): ?>
                                    <tr>
                                        <td><?php echo e($w['waitlist_ID']); ?></td>
                                        <td><?php echo e(trim(($w['first_name'] ?? '') . ' ' . ($w['last_name'] ?? ''))); ?></td>
                                        <td><?php echo e($w['boat_name'] ?? ''); ?><?php if(!empty($w['boat_length'])): ?> <small>(<?php echo e($w['boat_length']); ?> ft)</small><?php endif; ?></td>
                                        <td><?php echo e(formatDateShort($w['preferred_start_date'] ?? '')); ?> → <?php echo e(formatDateShort($w['preferred_end_date'] ?? '')); ?></td>
                                        <td><?php echo e($w['preferred_slip_size'] ?? ''); ?></td>
                                        <td><?php echo e($w['position_in_queue'] ?? ''); ?></td>
                                        <td><span class="badge">Waitlist</span></td>
                                        <td>
                                            <form method="post" style="display:inline-block;"><input type="hidden" name="action" value="edit_waitlist"><input type="hidden" name="waitlist_id" value="<?php echo e($w['waitlist_ID']); ?>"><button class="btn btn-outline btn-ocean">Edit</button></form>
                                            <!-- details shown inline -->
                                        </td>
                                    </tr>
                                    <tr id="wl-<?php echo e($w['waitlist_ID']); ?>" class="details-row" style="display:table-row;background:rgba(15,30,60,0.02)">
                                        <td colspan="8" style="padding:10px">
                                            <strong>Owner:</strong> <?php echo e((trim(($w['first_name'] ?? '') . ' ' . ($w['last_name'] ?? '')) ?: ($w['email'] ?? ''))); ?><br>
                                            <strong>Email:</strong> <?php echo e($w['email'] ?? ''); ?><br>
                                            <strong>Phone:</strong> <?php echo e($w['user_phone'] ?? ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Waitlist#</th><th>Name</th><th>Boat</th><th>Preferred Dates</th><th>Preferred Size</th><th>Position</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach($searchResults as $w): ?>
                                <tr>
                                    <td><?php echo e($w['waitlist_ID']); ?></td>
                                    <td><?php echo e(trim(($w['first_name'] ?? '') . ' ' . ($w['last_name'] ?? ''))); ?></td>
                                    <td><?php echo e($w['boat_name'] ?? ''); ?><?php if(!empty($w['boat_length'])): ?> <small>(<?php echo e($w['boat_length']); ?> ft)</small><?php endif; ?></td>
                                    <td><?php echo e(formatDateShort($w['preferred_start_date'] ?? '')); ?> → <?php echo e(formatDateShort($w['preferred_end_date'] ?? '')); ?></td>
                                    <td><?php echo e($w['preferred_slip_size'] ?? ''); ?></td>
                                    <td><?php echo e($w['position_in_queue'] ?? ''); ?></td>
                                    <td><span class="badge">Waitlist</span></td>
                                    <td>
                                        <form method="post" style="display:inline-block;"><input type="hidden" name="action" value="edit_waitlist"><input type="hidden" name="waitlist_id" value="<?php echo e($w['waitlist_ID']); ?>"><button class="btn btn-outline btn-ocean">Edit</button></form>
                                        <!-- details shown inline -->
                                    </td>
                                </tr>
                                <tr id="wl-<?php echo e($w['waitlist_ID']); ?>" class="details-row" style="display:table-row;background:rgba(15,30,60,0.02)">
                                    <td colspan="8" style="padding:10px">
                                        <strong>Owner:</strong> <?php echo e((trim(($w['first_name'] ?? '') . ' ' . ($w['last_name'] ?? '')) ?: ($w['email'] ?? ''))); ?><br>
                                        <strong>Email:</strong> <?php echo e($w['email'] ?? ''); ?><br>
                                        <strong>Phone:</strong> <?php echo e($w['user_phone'] ?? ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php elseif (($resultType ?? ($_POST['scope'] ?? 'users')) === 'users'): ?>
                    <table>
                        <thead><tr><th>User ID</th><th>Email</th><th>Name</th><th>Date Created</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach($searchResults as $u): ?>
                            <tr>
                                <td><?php echo e($u['user_ID']); ?></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td><?php echo e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?></td>
                                <td><?php echo e(formatDateShort($u['date_created'] ?? '')); ?></td>
                                <td>
                                    <form method="post" style="display:inline-block"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" value="<?php echo e($u['user_ID']); ?>"><button class="btn btn-outline btn-ocean">Edit</button></form>
                                    <button type="button" class="btn btn-outline" data-show-boats="<?php echo e($u['user_ID']); ?>">Display Boats</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (($resultType ?? ($_POST['scope'] ?? 'boats')) === 'boats'): ?>
                    <table>
                        <thead><tr><th>Boat ID</th><th>Name</th><th>Length</th><th>Owner</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach($searchResults as $b): ?>
                            <tr>
                                <td><?php echo e($b['boat_ID']); ?></td>
                                <td><?php echo e($b['boat_name'] ?? ''); ?></td>
                                <td><?php echo e($b['boat_length'] ?? ''); ?></td>
                                <td><?php echo e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: ($b['owner_email'] ?? '')); ?></td>
                                <td><form method="post"><input type="hidden" name="action" value="edit_boat"><input type="hidden" name="boat_id" value="<?php echo e($b['boat_ID']); ?>"><button class="btn btn-outline btn-ocean">Edit</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (($resultType ?? ($_POST['scope'] ?? 'payments')) === 'payments'): ?>
                    <table>
                        <thead><tr><th>Payment ID</th><th>Reservation</th><th>Email</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach($searchResults as $p): ?>
                            <tr>
                                <td><?php echo e($p['payment_id']); ?></td>
                                <td><?php echo e($p['confirmation_number'] ?? $p['reservation_ID']); ?></td>
                                <td><?php echo e($p['email'] ?? ''); ?></td>
                                <td><?php echo e($p['amount']); ?></td>
                                <td><?php echo e($p['method']); ?></td>
                                <td><?php echo e(formatDateShort($p['date_created'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($editWaitlist)): ?>
            <section style="margin-top:16px;background:#fff;padding:16px;border-radius:10px;">
                <h3>Edit Waitlist #<?php echo e($editWaitlist['waitlist_ID']); ?></h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_waitlist">
                    <input type="hidden" name="waitlist_id" value="<?php echo e($editWaitlist['waitlist_ID']); ?>">
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>Preferred Start</span><input type="date" name="preferred_start_date" value="<?php echo e($editWaitlist['preferred_start_date'] ?? ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>Preferred End</span><input type="date" name="preferred_end_date" value="<?php echo e($editWaitlist['preferred_end_date'] ?? ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>Preferred Slip Size</span><input type="text" name="preferred_slip_size" value="<?php echo e($editWaitlist['preferred_slip_size'] ?? ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>Position In Queue</span><input type="number" name="position_in_queue" value="<?php echo e($editWaitlist['position_in_queue'] ?? ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>Boat ID</span><input type="text" name="boat_ID" value="<?php echo e($editWaitlist['boat_ID'] ?? ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;margin-top:6px"><span>User ID</span><input type="text" name="user_ID" value="<?php echo e($editWaitlist['user_ID'] ?? ''); ?>"></label>
                    <?php if (!empty($_SESSION['is_employee'])): ?>
                        <label style="display:flex;flex-direction:column;margin-top:8px"><span>Employee Password (confirm)</span><input type="password" name="confirm_password" required></label>
                    <?php endif; ?>
                    <div style="margin-top:8px;"><button type="submit" class="btn btn-ocean">Save</button> <a href="employee_dashboard.php" class="btn btn-ghost">Cancel</a></div>
                </form>
            </section>
        <?php endif; ?>

    </main>
    <script>
    (function(){
        var btn = document.getElementById('reset-search');
        if(btn){ btn.addEventListener('click', function(){ window.location.href = 'employee_dashboard.php'; }); }
        var form = document.getElementById('search-form');
        // Today's Reservations quick-tab handled below with other quick-tabs
        // support both naming conventions (hyphen or underscore) for robustness
        var taskCheckins = document.getElementById('task-checkins') || document.getElementById('task_checkins');
        var taskCompletions = document.getElementById('task-completions');
        var taskWl = document.getElementById('task-waitlist-delete');
        var taskPayment24 = document.getElementById('task-payment-24h');
        var taskTodayRes = document.getElementById('task-today-res');
        var taskField = document.getElementById('task_type');
        function activateQuickTab(el){
            var tabs = document.querySelectorAll('.search-panel .quick-tabs .btn');
            tabs.forEach(function(t){ t.setAttribute('aria-pressed','false'); });
            el.setAttribute('aria-pressed','true');
        }
        if(taskCheckins && form && taskField){ taskCheckins.addEventListener('click', function(ev){ ev.preventDefault(); activateQuickTab(taskCheckins); taskField.value = 'checkins_today'; setTimeout(function(){ form.submit(); }, 50); }); }
        if(taskCompletions && form && taskField){ taskCompletions.addEventListener('click', function(ev){ ev.preventDefault(); activateQuickTab(taskCompletions); taskField.value = 'completions_today'; setTimeout(function(){ form.submit(); }, 50); }); }
        if(taskWl && form && taskField){ taskWl.addEventListener('click', function(ev){ ev.preventDefault(); activateQuickTab(taskWl); taskField.value = 'waitlist_deletions'; setTimeout(function(){ form.submit(); }, 50); }); }
        if(taskPayment24 && form && taskField){ taskPayment24.addEventListener('click', function(ev){ ev.preventDefault(); activateQuickTab(taskPayment24); taskField.value = 'payments_due_24h'; setTimeout(function(){ form.submit(); }, 50); }); }
        if(taskTodayRes && form){ taskTodayRes.addEventListener('click', function(ev){ ev.preventDefault(); activateQuickTab(taskTodayRes); var d=new Date(); var s=d.toISOString().slice(0,10); var dateInput=form.querySelector('input[name="date"]'); if(dateInput) dateInput.value=s; var scope=form.querySelector('select[name="scope"]'); if(scope) scope.value='reservations'; if(taskField) taskField.value=''; setTimeout(function(){ form.submit(); },50); }); }
        // Search options dropdown toggle
        var searchToggle = document.getElementById('search-toggle');
        var searchOptionsPanel = document.getElementById('search-options-panel');
        if (searchToggle && searchOptionsPanel) {
            searchToggle.addEventListener('click', function(){
                var isOpen = searchToggle.getAttribute('aria-expanded') === 'true';
                searchToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                searchOptionsPanel.style.display = isOpen ? 'none' : 'block';
                searchOptionsPanel.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
                if (!isOpen) { searchOptionsPanel.scrollIntoView({behavior:'smooth', block:'center'}); }
            });
        }
        // (Confirmation UI handled earlier in the file; avoid duplicate handlers)

        // reservation details toggles removed — details are shown inline now

        // waitlist detail toggles removed — rows are visible inline now

        // Display Boats modal logic
        var boatsModal = document.getElementById('boats-modal');
        var boatsModalBody = document.getElementById('boats-modal-body');
        var boatsModalTitle = document.getElementById('boats-modal-title');
        var boatsModalClose = document.getElementById('boats-modal-close');
        function closeBoatsModal(){ if(boatsModal) boatsModal.style.display='none'; boatsModalBody && (boatsModalBody.innerHTML=''); }
        boatsModalClose && boatsModalClose.addEventListener('click', function(){ closeBoatsModal(); });

        function showBoatsForUser(userId, userLabel){
            if(!boatsModal || !boatsModalBody) return;
            boatsModalTitle.textContent = 'Boats for ' + userLabel;
            boatsModalBody.innerHTML = '<div style="padding:12px">Loading&hellip;</div>';
            boatsModal.style.display = 'flex';
            fetch(window.location.pathname + '?fetch_boats_for_user=' + encodeURIComponent(userId))
            .then(function(res){ return res.json(); })
            .then(function(data){
                if(!data || data.error){
                    boatsModalBody.innerHTML = '<div style="color:#7f1d1d">Error loading boats.</div>' + (data && data.error ? '<div style="font-family:monospace;">'+(data.error)+'</div>':'' );
                    return;
                }
                // build DOM grouped by length
                boatsModalBody.innerHTML = '';
                var keys = Object.keys(data);
                if(keys.length === 0){ boatsModalBody.innerHTML = '<div>No boats found for this user.</div>'; return; }
                keys.forEach(function(len){
                    var header = document.createElement('div'); header.style.marginTop='10px'; header.style.fontWeight='700'; header.textContent = 'Length: ' + len + ' ft';
                    boatsModalBody.appendChild(header);
                    var list = document.createElement('ul'); list.style.marginTop='6px'; list.style.marginBottom='8px';
                    (data[len] || []).forEach(function(b){
                        var li = document.createElement('li');
                        var txt = (b.boat_name || 'Unnamed');
                        li.textContent = txt + (b.boat_length ? (' — ' + b.boat_length + ' ft') : '');
                        list.appendChild(li);
                    });
                    boatsModalBody.appendChild(list);
                });
            }).catch(function(err){ boatsModalBody.innerHTML = '<div style="color:#7f1d1d">Error fetching boats.</div>'; });
        }

        var showBoatBtns = document.querySelectorAll('[data-show-boats]');
        showBoatBtns.forEach(function(b){
            b.addEventListener('click', function(){
                var uid = b.getAttribute('data-show-boats');
                var row = b.closest('tr');
                var nameCell = row ? row.querySelector('td:nth-child(3)') : null;
                var userLabel = nameCell ? nameCell.textContent.trim() : ('User ' + uid);
                showBoatsForUser(uid, userLabel);
            });
        });

        // Take Payment modal wiring for reservation-level buttons
        var paymentModal = document.getElementById('payment-modal');
        var paymentModalClose = document.getElementById('payment-modal-close');
        var paymentCancel = document.getElementById('payment-cancel');
        var paymentForm = document.getElementById('payment-form');
        var paymentResId = document.getElementById('payment-reservation-id');
        var paymentAmount = document.getElementById('payment-amount');
        var takeBtns = document.querySelectorAll('[data-take-payment]');
        function closePaymentModal(){ if(paymentModal) paymentModal.style.display='none'; }
        function openPaymentModal(resId, amt){ if(!paymentModal) return; paymentResId.value = resId; paymentAmount.value = (amt || ''); paymentModal.style.display='flex'; }
        takeBtns.forEach(function(tb){ tb.addEventListener('click', function(){ var id = tb.getAttribute('data-take-payment'); var a = tb.getAttribute('data-default-amount') || ''; openPaymentModal(id, a); }); });
        paymentModalClose && paymentModalClose.addEventListener('click', function(){ closePaymentModal(); });
        paymentCancel && paymentCancel.addEventListener('click', function(){ closePaymentModal(); });
        // Center action groups when only 1-2 visible buttons
        function centerSparseActionGroups(){
            var groups = document.querySelectorAll('.res-card .body > .actions');
            groups.forEach(function(g){
                var children = Array.from(g.children || []);
                var visible = children.filter(function(c){
                    var st = window.getComputedStyle(c);
                    return st.visibility !== 'hidden' && st.display !== 'none';
                });
                if (visible.length <= 2) g.classList.add('actions-centered'); else g.classList.remove('actions-centered');
            });
        }
        // run on load
        centerSparseActionGroups();
        // also re-run after DOM changes that might affect visibility (e.g., modals/records)
        document.addEventListener('click', function(){ setTimeout(centerSparseActionGroups, 40); });
    })();
    </script>
    <script>
    // Handler for Edit User / Edit Boat buttons inside the edit reservation panel
    (function(){
        function submitEdit(action, id){
            if(!action) { console.warn('submitEdit: missing action'); return; }
            if(!id) { alert('No id provided for ' + action); return; }
            var f = document.createElement('form'); f.method = 'POST'; f.style.display='none';
            var a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value = action; f.appendChild(a);
            var i = document.createElement('input'); i.type='hidden';
            if(action === 'edit_user'){ i.name='user_id'; i.value = id; } else { i.name='boat_id'; i.value = id; }
            f.appendChild(i);
            // try to capture the current reservation id (if present) so server can re-render the edit card in-place
            try {
                var currentResInput = document.querySelector('input[name="reservation_id"]');
                if (currentResInput && currentResInput.value) {
                    var ri = document.createElement('input'); ri.type='hidden'; ri.name='reservation_id'; ri.value = currentResInput.value; f.appendChild(ri);
                }
            } catch(e) { /* ignore */ }
            document.body.appendChild(f);
            try { if (typeof safeSubmit === 'function') { safeSubmit(f); } else { f.submit(); } } catch (ex) { console.error('submitEdit failed', ex); try { f.submit(); } catch(e){ alert('Unable to submit edit request'); } }
        }
        document.addEventListener('click', function(e){
            try{
                var b = e.target.closest && e.target.closest('[data-edit-action]');
                if(!b) return;
                e.preventDefault(); e.stopPropagation();
                var act = b.getAttribute('data-edit-action');
                var id = b.getAttribute('data-edit-id');
                submitEdit(act, id);
            } catch (err) { console.error('edit-button handler error', err); }
        });
    })();
    </script>
    <?php require_once __DIR__ . '/footer.php'; ?>
    <script>
    // toggle advanced search options
    (function(){
        var toggle = document.getElementById('search-toggle');
        var adv = document.getElementById('search-options-panel');
        if (!toggle || !adv) return;
        toggle.addEventListener('click', function(){
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            adv.style.display = open ? 'none' : 'flex';
            adv.setAttribute('aria-hidden', open ? 'true' : 'false');
        });
    })();
    </script>
</body>
<script>
(function(){
    function postComplete(resId){
        var fd = new FormData();
        fd.append('action','complete_reservation');
        fd.append('reservation_id',resId);
        fd.append('ajax','1');
        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(resp){ return resp.text(); })
        .then(function(text){
            try {
                var j = JSON.parse(text);
            } catch (e) {
                alert(text || 'No response from server');
                console.error('Non-JSON response for complete_reservation:', text);
                return;
            }
            if (j.status === 'ok' || j.status === 'success') {
                location.reload();
            } else if (j.status === 'no_update') {
                alert('No reservation updated. ' + (j.message || ''));
                console.log('complete_reservation debug:', j);
            } else {
                alert('Error completing reservation: ' + (j.message || 'Unknown error'));
                console.error('complete_reservation error:', j);
            }
        })
        .catch(function(err){
            console.error('Network error completing reservation:', err);
            alert('Network error: ' + (err && err.message ? err.message : err));
        });
    }

    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('[data-action="complete_reservation"], .btn-complete, button[data-complete-reservation]');
        if (!btn) return;
        e.preventDefault();
        var resId = btn.getAttribute('data-reservation-id') || btn.dataset.reservationId || (function(){
            var f = btn.closest && btn.closest('form');
            if (!f) return null;
            var i = f.querySelector('input[name="reservation_id"]');
            return i ? i.value : null;
        })();
        if (!resId) { alert('Reservation id not found'); return; }
        if (!confirm('Mark reservation #' + resId + ' as completed?')) return;
        postComplete(resId);
    }, false);
})();
</script>
</html>
