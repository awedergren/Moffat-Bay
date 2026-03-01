<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 18, 2026
Project: Moffat Bay Marina Project
File: look_up.php
Purpose: Lookup reservation page and wait list status.
Non-executing header only; does not affect page behavior or layout.
*/
// Reservation Lookup Page
session_start();
// Check login state (allow either username or user_id session keys)
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);

// Load database connection from several common locations
$dbConn = null;
$dbPaths = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php'
];
foreach ($dbPaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        if (isset($conn)) $dbConn = $conn;
        if (isset($pdo)) $dbConn = $pdo;
        break;
    }
}

// Page is public: do not require login to view lookup results.
// $loggedIn remains available to conditionally show modify/cancel actions.

// NOTE: navigation will be included in the document body below so markup
// and styles match other pages (do not output nav before the DOCTYPE).

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$searchErr = '';
$result = null;
$waitlistEntries = [];
$dbIncluded = false;

// Check if database was successfully included
if (!isset($pdo) && !isset($conn)) {
    $dbPaths = [
        __DIR__ . '/config/db.php',
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php'
    ];
    foreach ($dbPaths as $dbPath) {
        if (file_exists($dbPath)) {
            require_once $dbPath;
            $dbIncluded = (isset($pdo) || isset($conn));
            break;
        }
    }
} else {
    $dbIncluded = true;
}

$pdo = $pdo ?? null;

// Inline modify/cancel state
$showCancelConfirm = false;
$showModifyForm = false;
$userBoats = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    // Action flows: request_cancel, confirm_cancel, request_modify, confirm_modify
    if ($do === 'request_cancel') {
        $pendingId = intval($_POST['id'] ?? 0);
        if ($pendingId > 0 && $pdo) {
            try {
                $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
                $s->execute([$pendingId]);
                $result = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($result) $showCancelConfirm = true;
                else $searchErr = 'Reservation not found.';
            } catch (Exception $e) { $searchErr = 'Database error.'; }
        } else {
            $searchErr = 'Invalid reservation selected.';
        }
    } elseif ($do === 'confirm_cancel') {
        $resId = intval($_POST['reservation_id'] ?? 0);
        $pwd = trim($_POST['current_password'] ?? '');
        $confirm = isset($_POST['confirm_delete']);
        if ($resId <= 0) $searchErr = 'Invalid reservation.';
        elseif (!$confirm) $searchErr = 'Please confirm cancellation.';
        elseif ($pwd === '') $searchErr = 'Enter your password to confirm.';
        else {
            try {
                $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
                $s->execute([$resId]);
                $res = $s->fetch(PDO::FETCH_ASSOC);
                if (!$res) { $searchErr = 'Reservation not found.'; }
                else {
                    $userId = $_SESSION['user_id'] ?? null;
                    if (!$userId || intval($res['user_ID'] ?? 0) !== intval($userId)) {
                        $searchErr = 'You do not have permission to cancel this reservation.';
                    } else {
                        $u = $pdo->prepare('SELECT * FROM users WHERE user_ID = ? LIMIT 1');
                        $u->execute([$userId]);
                        $usr = $u->fetch(PDO::FETCH_ASSOC);
                        $hash = $usr['password'] ?? $usr['password_hash'] ?? null;
                        if (!$hash || !password_verify($pwd, $hash)) { $searchErr = 'Password incorrect.'; }
                        else {
                            $up = $pdo->prepare('UPDATE reservations SET reservation_status = ? WHERE reservation_ID = ?');
                            $up->execute(['canceled', $resId]);
                            // refresh result
                            $s2 = $pdo->prepare('SELECT r.*, u.email FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID WHERE r.reservation_ID = ? LIMIT 1');
                            $s2->execute([$resId]);
                            $result = $s2->fetch(PDO::FETCH_ASSOC);
                        }
                    }
                }
            } catch (Exception $e) { $searchErr = 'Database error.'; }
        }
    } elseif ($do === 'request_modify') {
        $pendingId = intval($_POST['id'] ?? 0);
        if ($pendingId > 0 && $pdo) {
            try {
                $s = $pdo->prepare('SELECT r.*, u.email FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID WHERE r.reservation_ID = ? LIMIT 1');
                $s->execute([$pendingId]);
                $result = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($result) {
                    $userId = $_SESSION['user_id'] ?? null;
                    if (!$userId || intval($result['user_ID'] ?? 0) !== intval($userId)) {
                        $searchErr = 'You do not have permission to modify this reservation.';
                    } else {
                        // fetch user's boats and slip info
                        $bstmt = $pdo->prepare('SELECT * FROM boats WHERE user_ID = ? ORDER BY boat_name ASC');
                        $bstmt->execute([$userId]);
                        $userBoats = $bstmt->fetchAll(PDO::FETCH_ASSOC);
                        $slipId = $result['slip_ID'] ?? null;
                        if ($slipId) {
                            $ss = $pdo->prepare('SELECT slip_ID, slip_size, location_code FROM slips WHERE slip_ID = ? LIMIT 1');
                            $ss->execute([$slipId]);
                            $result['slip_info'] = $ss->fetch(PDO::FETCH_ASSOC) ?: null;
                        }
                        $showModifyForm = true;
                    }
                } else $searchErr = 'Reservation not found.';
            } catch (Exception $e) { $searchErr = 'Database error.'; }
        } else $searchErr = 'Invalid reservation selected.';
    } elseif ($do === 'confirm_modify') {
        $resId = intval($_POST['reservation_id'] ?? 0);
        $newBoat = intval($_POST['boat_id'] ?? 0);
        $pwd = trim($_POST['current_password'] ?? '');
        if ($resId <= 0) $searchErr = 'Invalid reservation.';
        elseif ($newBoat <= 0) $searchErr = 'Please select a valid boat.';
        elseif ($pwd === '') $searchErr = 'Enter your password to confirm.';
        else {
            try {
                $s = $pdo->prepare('SELECT * FROM reservations WHERE reservation_ID = ? LIMIT 1');
                $s->execute([$resId]);
                $res = $s->fetch(PDO::FETCH_ASSOC);
                if (!$res) { $searchErr = 'Reservation not found.'; }
                else {
                    $userId = $_SESSION['user_id'] ?? null;
                    if (!$userId || intval($res['user_ID'] ?? 0) !== intval($userId)) {
                        $searchErr = 'You do not have permission to modify this reservation.';
                    } else {
                        $u = $pdo->prepare('SELECT * FROM users WHERE user_ID = ? LIMIT 1');
                        $u->execute([$userId]);
                        $usr = $u->fetch(PDO::FETCH_ASSOC);
                        $hash = $usr['password'] ?? $usr['password_hash'] ?? null;
                        if (!$hash || !password_verify($pwd, $hash)) { $searchErr = 'Password incorrect.'; }
                        else {
                            // validate boat ownership and length
                            $bq = $pdo->prepare('SELECT * FROM boats WHERE boat_ID = ? AND user_ID = ? LIMIT 1');
                            $bq->execute([$newBoat, $userId]);
                            $brow = $bq->fetch(PDO::FETCH_ASSOC);
                            if (!$brow) { $searchErr = 'Selected boat not found or not owned by you.'; }
                            else {
                                $boatLen = intval($brow['boat_length'] ?? 0);
                                $slipId = intval($res['slip_ID'] ?? 0);
                                $slipSize = 0;
                                if ($slipId) {
                                    $ss = $pdo->prepare('SELECT slip_size FROM slips WHERE slip_ID = ? LIMIT 1');
                                    $ss->execute([$slipId]);
                                    $srow = $ss->fetch(PDO::FETCH_ASSOC);
                                    $slipSize = intval($srow['slip_size'] ?? 0);
                                }
                                if ($slipSize > 0 && $boatLen > $slipSize) { $searchErr = 'Boat is too long for the reserved slip.'; }
                                else {
                                    $up = $pdo->prepare('UPDATE reservations SET boat_ID = ? WHERE reservation_ID = ?');
                                    $up->execute([$newBoat, $resId]);
                                    // refresh result
                                    $s2 = $pdo->prepare('SELECT r.*, u.email FROM reservations r LEFT JOIN users u ON r.user_ID = u.user_ID WHERE r.reservation_ID = ? LIMIT 1');
                                    $s2->execute([$resId]);
                                    $result = $s2->fetch(PDO::FETCH_ASSOC);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) { $searchErr = 'Database error.'; }
        }
    } else {
        // treat as a search submit when no action specified
        $searchBy = $_POST['search_by'] ?? '';
        $query = trim($_POST['query'] ?? '');
        if ($query === '') {
            $searchErr = 'Please enter a confirmation number or email address.';
        } elseif (!$pdo) {
            $searchErr = 'Database connection not available. Please contact the administrator.';
        } else {
            try {
                if ($searchBy === 'confirmation') {
                    // Only select reservation table fields by confirmation number
                    $sql = 'SELECT r.* FROM reservations r WHERE r.confirmation_number = ? LIMIT 1';
                } else {
                    // For email searches, select the next upcoming reservation for the user (exclude canceled/checked_in/completed)
                    $sql = 'SELECT r.* FROM reservations r WHERE r.user_ID = (SELECT user_ID FROM users WHERE email = ? LIMIT 1) AND (r.reservation_status IS NULL OR LOWER(r.reservation_status) NOT IN ("canceled","cancelled","checked_in","completed")) AND COALESCE(r.start_date, r.end_date) >= CURDATE() ORDER BY COALESCE(r.start_date, r.end_date) ASC LIMIT 1';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$query]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $result = $row;
                    // If the matched reservation has a status that should not be shown, try to find the next upcoming reservation for the same user
                    $bad = array_map('strtolower', array('canceled','cancelled','checked_in','completed'));
                    $status = strtolower($result['reservation_status'] ?? '');
                    if (in_array($status, $bad)) {
                        $userId = $result['user_ID'] ?? null;
                        if (!empty($userId)) {
                            try {
                                $nextSql = 'SELECT r.* FROM reservations r WHERE r.user_ID = ? AND (r.reservation_status IS NULL OR LOWER(r.reservation_status) NOT IN ("canceled","cancelled","checked_in","completed")) AND COALESCE(r.start_date, r.end_date) >= CURDATE() ORDER BY COALESCE(r.start_date, r.end_date) ASC LIMIT 1';
                                $nextStmt = $pdo->prepare($nextSql);
                                $nextStmt->execute([$userId]);
                                $nextRow = $nextStmt->fetch(PDO::FETCH_ASSOC);
                                if ($nextRow) {
                                    $result = $nextRow;
                                } else {
                                    $result = null;
                                    $searchErr = 'No upcoming reservation found matching your search.';
                                }
                            } catch (Exception $e) {
                                $result = null;
                                $searchErr = 'Database error during search.';
                            }
                        } else {
                            $result = null;
                            $searchErr = 'No upcoming reservation found matching your search.';
                        }
                    }
                    // Fetch related display info (email, slip, boat) but do not change
                    // the fact that the primary data comes from the reservations table.
                    try {
                        $userId = $result['user_ID'] ?? null;
                        if ($userId) {
                            $u = $pdo->prepare('SELECT email FROM users WHERE user_ID = ? LIMIT 1');
                            $u->execute([$userId]);
                            $usr = $u->fetch(PDO::FETCH_ASSOC);
                            if ($usr) $result['email'] = $usr['email'];
                        }
                    } catch (Exception $e) { }

                                        // Fetch upcoming reservations (next 5, fetch 6 to detect 'more')
                                        try {
                                                if (!empty($userId) && $pdo) {
                                                        $upSql = 'SELECT reservation_ID, confirmation_number, start_date, end_date, reservation_status, total_cost
                                                                            FROM reservations
                                                                            WHERE user_ID = ?
                                                                                AND (reservation_status IS NULL OR reservation_status NOT IN ("canceled","cancelled"))
                                                                                AND COALESCE(start_date, end_date) >= CURDATE()
                                                                                                    ORDER BY COALESCE(start_date, end_date) ASC
                                                                            LIMIT 6';
                                                        $upStmt = $pdo->prepare($upSql);
                                                        $upStmt->execute([$userId, 'canceled']);
                                                        $upRows = $upStmt->fetchAll(PDO::FETCH_ASSOC);
                                                        $result['upcoming_reservations'] = $upRows ?: [];
                                                }
                                        } catch (Exception $e) { /* ignore */ }
                                        /* No fallback fetch for all reservations — only upcoming reservations are retrieved */

                    try {
                        $slipId = $result['slip_ID'] ?? null;
                        if ($slipId) {
                            $ss = $pdo->prepare('SELECT slip_ID, slip_size, location_code FROM slips WHERE slip_ID = ? LIMIT 1');
                            $ss->execute([$slipId]);
                            $srow = $ss->fetch(PDO::FETCH_ASSOC);
                            if ($srow) $result['slip_info'] = $srow;
                        }
                    } catch (Exception $e) { }

                    try {
                        $boatId = $result['boat_ID'] ?? null;
                        if ($boatId) {
                            $bs = $pdo->prepare('SELECT boat_ID, boat_name, boat_length FROM boats WHERE boat_ID = ? LIMIT 1');
                            $bs->execute([$boatId]);
                            $brow = $bs->fetch(PDO::FETCH_ASSOC);
                            if ($brow) $result['boat_info'] = $brow;
                        }
                        // Fetch user's boats for modify modal (if available)
                        if (!empty($userId) && $pdo) {
                            try {
                                $bstmt = $pdo->prepare('SELECT * FROM boats WHERE user_ID = ? ORDER BY boat_name ASC');
                                $bstmt->execute([$userId]);
                                $userBoats = $bstmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Exception $_) { $userBoats = []; }
                        }
                    } catch (Exception $e) { }
                } else {
                    $searchErr = 'No reservation found matching your search.';
                }
            } catch (Exception $ex) {
                $searchErr = 'Database error during search: ' . e($ex->getMessage());
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reservation Lookup</title>

 <link rel="stylesheet" href="styles.css">  
 <link rel="stylesheet" href="reservation_style.css">
<link rel="stylesheet" href="look_up.css">  
    <style>
    /* Page-scoped: set primary buttons to Ocean color only for the lookup form (do NOT affect nav) */
    .form-lookup .cta,
    .form-lookup .btn {
        background: var(--ocean);
        color: #fff;
        border-color: var(--ocean);
    }
    .form-lookup .cta:hover,
    .form-lookup .btn:hover {
        background: #35738c;
    }
    /* Reservation details action buttons (page-scoped; do NOT affect nav/other pages) */
    .reservation-details .actions .btn {
        background: var(--ocean);
        color: #fff;
        border-color: var(--ocean);
    }
    .reservation-details .actions .btn:hover {
        background: #35738c;
    }
    /* Cancel: coral outline + coral text, filled coral on hover */
    .reservation-details .actions .btn.btn-cancel,
    .detail-section .btn.btn-cancel {
        background: transparent;
        color: var(--coral);
        border: 2px solid var(--coral);
        padding: 8px 14px;
    }
    .reservation-details .actions .btn.btn-cancel:hover,
    .detail-section .btn.btn-cancel:hover {
        background: var(--coral);
        color: #fff;
        border-color: var(--coral);
    }
    :root{ --page-max-width:1000px; }
    body.reservation-info .cards {
        display:flex;
        flex-direction:column;
        gap:28px;
        justify-content:center;
        align-items:center;
        margin:0 auto;
        position: relative;
        z-index: 1000;
        padding: 0;
        box-sizing: border-box;
        width:100%;
        max-width: var(--page-max-width);
    }
    body.reservation-info .cards .card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(31,47,69,0.12);
        padding: 20px;
        overflow: visible;
        box-sizing: border-box;
        width:100%;
        max-width: var(--page-max-width);
    }
    @media (max-width:900px){
        body.reservation-info .cards { margin-top: 12px; }
        body.reservation-info .cards .card { padding: 14px; border-radius:8px; }
    }
    /* Move the overlap to the form-card so spacing matches waitlist_lookup.php */
        .cards .card.form-card { width:100%; max-width:var(--page-max-width); margin-top:-40px; position:relative; z-index:2147483647 !important; display:block; overflow:visible; border-radius:14px; background:#fff; padding:32px 36px; box-shadow:0 14px 30px rgba(31,47,69,0.08); border:1px solid rgba(15,37,64,0.06); transform: translateZ(0); }
        /* Ensure the hero does not stack above the form card on this page */
        .site-hero { z-index: 0; }
    .cards .card.form-card .input-text{ width:100%; padding:14px 16px; border-radius:8px; border:1px solid #e6eef0; box-shadow:none }
    /* Increase vertical rhythm between direct children of the form card */
    .cards .card.form-card > * + * { margin-top:18px; }
    /* Add extra spacing above the radio options inside the form card */
    .cards .card.form-card .search-type { margin-top:32px; margin-bottom:12px; display:flex; gap:18px; justify-content:center; }
    .cards .card.form-card .cta{ display:inline-flex; align-items:center; justify-content:center; padding:12px 22px; border-radius:8px; max-width:360px; margin:18px auto 0; }
    /* Keep footer at bottom: make this page a column-flex container so main grows */
    body.reservation-info {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    body.reservation-info main {
        flex: 1 1 auto; /* allow main content to grow and push footer down */
    }
    /* Additional small card and contact band styles (page-scoped) */
    .cards .lookup-card{ display:flex; gap:18px; align-items:center; border:3px solid rgba(63,135,166,0.9); padding:24px; border-radius:18px; background:#fff; box-shadow:0 10px 28px rgba(31,47,69,0.06); box-sizing:border-box; width:100%; max-width:var(--page-max-width); }
    .cards .lookup-card .icon{ flex:0 0 56px; height:56px; border-radius:50%; background:var(--ocean); display:flex; align-items:center; justify-content:center; color:#fff }
    .btn-gold{ background:var(--gold); color:#0f2540; border-radius:12px; padding:12px 26px; font-weight:400; border:none; display:inline-block; text-decoration:none }
    .contact-section{ padding:32px 0; margin-top:8px }
    .container{ max-width:var(--page-max-width); margin:0 auto; padding:0 }
    .contact-band{ background:#f3e0bd; border-radius:12px; padding:28px; box-shadow:0 6px 18px rgba(31,47,69,0.06); display:flex; gap:20px; align-items:center; box-sizing:border-box; width:100%; max-width:var(--page-max-width); margin:0 auto; }
    .contact-cta{ display:flex; gap:14px; margin-top:18px; align-items:center }
    /* Contact modal styles (page-scoped) */
    .cb-overlay{ position:fixed; inset:0; background:rgba(0,0,0,0.45); display:none; align-items:center; justify-content:center; z-index:2147483646; }
    .cb-modal{ background:#fff; border-radius:10px; max-width:720px; width:94%; padding:20px; box-shadow:0 20px 50px rgba(15,30,60,0.25); }
    .cb-modal h3{ margin-top:0 }
    .cb-row{ display:flex; gap:12px; margin-bottom:12px; }
    .cb-row .cb-col{ flex:1 }
    .cb-row .cb-col.small{ flex:0 0 160px }
    .cb-input, .cb-textarea, .cb-select{ width:100%; padding:10px; border:1px solid #d0d7d9; border-radius:6px; box-sizing:border-box }
    .cb-textarea{ min-height:120px; resize:vertical }
    .cb-actions{ display:flex; gap:10px; justify-content:flex-end; margin-top:12px }
    .cb-success{ background:#d4edda; color:#155724; padding:10px; border-radius:6px; margin-bottom:10px }
    /* Remove default focus/outline on contact buttons and modal send button */
    .contact-cta .btn-primary, #contact-open, .cb-modal .btn-primary { outline: none; box-shadow: none; border: none; }
    .contact-cta .btn-primary:focus, #contact-open:focus, .cb-modal .btn-primary:focus { outline: none; box-shadow: none; }
    /* Make Contact Us button match phone button vertical size (no width change) */
    .contact-cta .btn-primary, .cb-modal .btn-primary { padding:16px 18px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; line-height:1; }
    /* Coral outline for modal cancel button */
    #contact-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    #contact-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    .btn-primary{ background:var(--ocean); color:#fff; padding:12px 22px; border-radius:10px; text-decoration:none }

    /* Reservation cancel modal button styles (page-scoped) */
    .cb-modal .btn.btn-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    .cb-modal .btn.btn-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    .cb-modal .btn.btn-leave { background: var(--ocean); color: #fff; border: none; padding: 8px 14px; border-radius:8px; }
    .cb-modal .btn.btn-leave:hover { background: #1e6a82; }
    /* Phone button: Ocean Blue outline and Ocean text to match site accent */
    .contact-band .btn-pill{ background:#fff; color:var(--ocean); padding:10px 18px; border-radius:10px; text-decoration:none; border:2px solid var(--ocean); box-shadow:none; font-weight:600 }
    </style>
</head>
<body class="reservation-info">
    <?php include __DIR__ . '/nav.php'; ?>
    <main>
        <?php
        // Use the shared hero include so this page matches site-wide hero styling
        $hero_title = 'Reservation Lookup';
        $hero_subtitle = 'Find your reservation using confirmation number or email.';
        $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-search-icon lucide-calendar-search"><path d="M16 2v4"/><path d="M21 11.75V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7.25"/><path d="m22 22-1.875-1.875"/><path d="M3 10h18"/><path d="M8 2v4"/><circle cx="18" cy="18" r="3"/></svg>';
        $hero_classes = 'hero-reservations';
        include __DIR__ . '/hero.php';
        ?>

        <div class="cards">
            <div class="card form-card">
                <form method="post" class="form-lookup" novalidate>
                    <div class="search-type">
                        <label><input type="radio" name="search_by" value="email" <?php if(!isset($_POST['search_by']) || $_POST['search_by']==='email') echo 'checked'; ?>> Email Address</label>
                        <label><input type="radio" name="search_by" value="confirmation" <?php if(isset($_POST['search_by']) && $_POST['search_by']==='confirmation') echo 'checked'; ?>> Confirmation Number</label>
                    </div>

                    <div class="form-row">
                        <input type="text" name="query" placeholder="Enter confirmation number or email" value="<?php echo e($_POST['query'] ?? ''); ?>" class="input-text">
                    </div>

                    <div class="form-row">
                        <button type="submit" class="cta">Search Reservation</button>
                    </div>
                </form>

                <?php if ($searchErr): ?>
                    <div class="error-message"><?php echo e($searchErr); ?></div>
                <?php endif; ?>

                <?php if ($result): ?>
                    <section class="reservation-details">
                        <h2>Reservation Details</h2>
                        <div class="detail-section">
                            <p><strong>Confirmation Number:</strong> <?php echo e($result['confirmation_number'] ?? 'N/A'); ?></p>
                            <p><strong>Email:</strong> <?php echo e($result['email'] ?? 'N/A'); ?></p>
                        </div>

                        <?php if (isset($result['slip_info'])): ?>
                        <div class="detail-section">
                            <h3>Slip Information</h3>
                            <p><strong>Location Code:</strong> <?php echo e($result['slip_info']['location_code'] ?? 'N/A'); ?></p>
                            <p><strong>Slip Size:</strong> <?php echo e($result['slip_info']['slip_size'] ?? 'N/A'); ?> ft</p>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($result['boat_info'])): ?>
                        <div class="detail-section">
                            <h3>Boat Information</h3>
                            <p><strong>Boat Name:</strong> <?php echo e($result['boat_info']['boat_name'] ?? 'N/A'); ?></p>
                            <p><strong>Boat Length:</strong> <?php echo e($result['boat_info']['boat_length'] ?? 'N/A'); ?> ft</p>
                        </div>
                        <?php endif; ?>

                        <div class="detail-section">
                            <h3>Reservation Dates & Status</h3>
                            <?php
                                $sdate_raw = $result['start_date'] ?? $result['check_in'] ?? null;
                                $edate_raw = $result['end_date'] ?? $result['check_out'] ?? null;
                                $sdate = $sdate_raw ? date('M d, Y', strtotime($sdate_raw)) : 'N/A';
                                $edate = $edate_raw ? date('M d, Y', strtotime($edate_raw)) : 'N/A';
                            ?>
                            <p><strong>Start Date:</strong> <?php echo e($sdate); ?></p>
                            <p><strong>End Date:</strong> <?php echo e($edate); ?></p>
                            <p><strong>Duration:</strong> <?php echo e($result['months_duration'] ?? 'N/A'); ?> months</p>
                            <p><strong>Total Cost:</strong> $<?php echo e($result['total_cost'] ?? '0.00'); ?></p>
                            <p><strong>Status:</strong> <span class="badge badge-<?php echo strtolower($result['reservation_status'] ?? 'unknown'); ?>"><?php echo e($result['reservation_status'] ?? 'unknown'); ?></span></p>
                        </div>

                        <!-- Waitlist entries intentionally omitted; only reservation table data is shown on this page. -->

                        <?php if (!empty($result['upcoming_reservations'])): ?>
                        <div class="detail-section" id="upcoming-reservations">
                            <h3>Next Upcoming Reservation</h3>
                            <?php
                                $up = $result['upcoming_reservations'];
                                $first = $up[0];
                                $count = count($up);
                            ?>
                            <div style="padding:12px;border:1px solid #eee;border-radius:8px;max-width:640px;background:#fff;">
                                <p style="margin:0 0 8px;"><strong>Confirmation:</strong> <?php echo e($first['confirmation_number'] ?? ''); ?></p>
                                <p style="margin:0 0 8px;"><strong>Start:</strong> <?php echo e(!empty($first['start_date']) ? date('M d, Y', strtotime($first['start_date'])) : 'TBD'); ?></p>
                                <p style="margin:0 0 8px;"><strong>End:</strong> <?php echo e(!empty($first['end_date']) ? date('M d, Y', strtotime($first['end_date'])) : 'TBD'); ?></p>
                                <p style="margin:0 0 8px;"><strong>Status:</strong> <?php echo e($first['reservation_status'] ?? ''); ?></p>
                                <p style="margin:0;"><strong>Cost:</strong> $<?php echo e(number_format($first['total_cost'] ?? 0, 2)); ?></p>
                            </div>

                            
                        </div>
                        <?php endif; ?>
                        <!-- Other reservations hidden by default (removed per requirement) -->

                        <?php if (!empty($showCancelConfirm)): ?>
                        <div class="detail-section">
                            <h3>Confirm Cancellation</h3>
                            <div style="background:#fff3cd;padding:12px;border-radius:6px;margin-bottom:12px;border-left:4px solid #ffc107;">
                                <strong>Warning:</strong> Cancelling will mark this reservation as canceled. This action can be undone by staff only.
                            </div>
                            <form method="post">
                                <input type="hidden" name="do" value="confirm_cancel">
                                <input type="hidden" name="reservation_id" value="<?php echo e($result['reservation_ID'] ?? '0'); ?>">
                                <div style="margin-bottom:10px;">
                                    <label style="display:block;font-weight:700;margin-bottom:6px;">Current Password:</label>
                                    <input type="password" name="current_password" required style="width:100%;max-width:360px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                                </div>
                                <div style="margin-bottom:10px;">
                                    <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="confirm_delete" required> Yes, I want to cancel this reservation</label>
                                </div>
                                <div style="display:flex;gap:8px;justify-content:center;">
                                    <button type="submit" class="btn btn-cancel">Confirm Cancel</button>
                                    <a href="look_up.php" class="btn" style="background:#6c757d;">Back</a>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($showModifyForm)): ?>
                        <div class="detail-section">
                            <h3>Modify Reservation</h3>
                            <form method="post">
                                <input type="hidden" name="do" value="confirm_modify">
                                <input type="hidden" name="reservation_id" value="<?php echo e($result['reservation_ID'] ?? '0'); ?>">
                                <div style="margin-bottom:12px;">
                                    <label style="display:block;font-weight:700;margin-bottom:6px;">Select Boat:</label>
                                    <select name="boat_id" required style="width:100%;max-width:420px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                                        <option value="">-- choose a boat --</option>
                                        <?php foreach ($userBoats as $b): ?>
                                            <option value="<?php echo e($b['boat_ID']); ?>"><?php echo e($b['boat_name'] . ' (' . ($b['boat_length'] ?? 'N/A') . ' ft)'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label style="display:block;font-weight:700;margin-bottom:6px;">Current Password:</label>
                                    <input type="password" name="current_password" required style="width:100%;max-width:360px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                                </div>
                                <div style="display:flex;gap:8px;justify-content:center;">
                                    <button type="submit" class="btn">Update Reservation</button>
                                    <a href="look_up.php" class="btn" style="background:#6c757d;">Back</a>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="actions">
                            <?php if (!empty($loggedIn) && intval($_SESSION['user_id'] ?? 0) === intval($result['user_ID'] ?? 0)): ?>
                                <button type="button" class="btn open-modify-modal" data-id="<?php echo e($result['reservation_ID'] ?? 0); ?>">Modify Reservation</button>

                                <button type="button" class="btn btn-cancel open-cancel-modal" data-id="<?php echo e($result['reservation_ID'] ?? 0); ?>">Cancel Reservation</button>
                                <!-- Waitlist-related actions removed from this page. -->
                            <?php elseif (!empty($loggedIn)): ?>
                                <p><em>You do not have permission to modify or cancel this reservation.</em></p>
                            <?php else: ?>
                                <p><em>Log in to modify or cancel this reservation.</em></p>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:center;margin-top:12px;">
                            <a href="reservation_summary.php" class="btn-gold" style="color:#000;border:none;padding:10px 20px;display:inline-block;">View Additional Reservations</a>
                        </div>
                        <div style="font-size:0.95rem;color:#666;margin-top:12px;text-align:center;max-width:720px;margin-left:auto;margin-right:auto;">
                            This displays your soonest upcoming reservation. Go to View Additional Reservations to view more reservations.
                        </div>
                    </section>
                <?php endif; ?>
            </div>

            <div class="card lookup-card">
                <div class="icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" stroke="currentColor"></circle><path d="M12 7v5l3 2" stroke="currentColor"></path></svg>
                </div>
                <div>
                    <h3>On the Waitlist?</h3>
                    <p>Check your position on the waitlist for available boat slips.</p>
                    <div style="margin-top:12px;"><a href="waitlist_lookup.php" class="btn-gold">Check Waitlist Position</a></div>
                </div>
            </div>
        </div>

        <!-- Contact Us band (styled to match reference) -->
        <section class="contact-section" aria-labelledby="contact-heading">
            <div class="container">
                <div class="contact-band" role="region" aria-labelledby="contact-heading">
                    <div class="contact-left">
                        <h2 id="contact-heading">Need Help?</h2>
                        <p>If you're having trouble finding your reservation or have questions about your booking, our team is here to help.</p>
                        <div class="contact-cta">
                            <button type="button" id="contact-open" class="btn-primary">Contact Us</button>
                            <a href="#" id="phone-open" class="btn-pill">Call (555) 987-2345</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

        <!-- Contact modal markup -->
        <div class="cb-overlay" id="contact-overlay" aria-hidden="true">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="cb-title">
                <button type="button" id="contact-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="cb-title">Contact Moffat Bay Marina</h3>
                <div id="cb-msg" style="display:none"></div>
                <form id="contact-form">
                    <div class="cb-row">
                        <div class="cb-col"><input name="name" class="cb-input" placeholder="Your name" required></div>
                        <div class="cb-col small"><input name="phone" class="cb-input" placeholder="Phone (optional)"></div>
                    </div>
                    <div class="cb-row">
                        <div class="cb-col"><input name="email" type="email" class="cb-input" placeholder="Email address" required></div>
                        <div class="cb-col small">
                            <select name="reason" class="cb-select">
                                <option>General Inquiry</option>
                                <option>Waitlist Question</option>
                                <option>Reservation Help</option>
                                <option>Billing</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:8px;"><textarea name="message" class="cb-textarea" placeholder="Your message" required></textarea></div>
                    <div class="cb-actions">
                        <button type="button" id="contact-cancel" class="btn">Cancel</button>
                        <button type="submit" class="btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reservation Cancel modal (page-scoped) -->
        <div class="cb-overlay" id="reservation-cancel-overlay" aria-hidden="true" style="display:none">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="reservation-cancel-title">
                <button type="button" id="reservation-cancel-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="reservation-cancel-title">Confirm Cancel Reservation</h3>
                <div id="reservation-cancel-error" style="display:none;margin-bottom:12px;color:#721c24;background:#f8d7da;padding:8px;border-radius:6px;"></div>
                <p>Please enter your account password and check the confirmation box to cancel this reservation.</p>
                <div style="margin-top:8px;margin-bottom:8px;">
                    <label style="display:block;font-weight:700;margin-bottom:6px;">Current Password:</label>
                    <input id="reservation-current-password" type="password" name="current_password" placeholder="Enter your password" style="width:100%;max-width:360px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;"><input id="reservation-confirm-checkbox" type="checkbox"> Yes, I want to cancel this reservation</label>
                </div>
                <div style="display:flex;gap:12px;justify-content:center;margin-top:12px;">
                    <button type="button" id="reservation-cancel-keep" class="btn btn-leave">Keep Reservation</button>
                    <button type="button" id="reservation-cancel-confirm" class="btn btn-cancel">Confirm Cancel</button>
                </div>
            </div>
        </div>

        <!-- Reservation Modify modal (page-scoped) -->
        <div class="cb-overlay" id="reservation-modify-overlay" aria-hidden="true" style="display:none">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="reservation-modify-title">
                <button type="button" id="reservation-modify-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="reservation-modify-title">Modify Reservation - Change Boat</h3>
                <div id="reservation-modify-error" style="display:none;margin-bottom:12px;color:#721c24;background:#f8d7da;padding:8px;border-radius:6px;"></div>
                <p style="margin-top:0;margin-bottom:12px;">If you need different dates or a different slip size, cancel this reservation and make a new one.</p>
                <div style="margin-top:8px;margin-bottom:8px;">
                    <label style="display:block;font-weight:700;margin-bottom:6px;">Select Boat:</label>
                    <select id="reservation-boat-select" style="width:100%;max-width:420px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                        <option value="">-- choose a boat --</option>
                        <?php foreach ($userBoats as $b): ?>
                            <option value="<?php echo e($b['boat_ID']); ?>" data-length="<?php echo e(intval($b['boat_length'] ?? 0)); ?>"><?php echo e($b['boat_name'] . ' (' . ($b['boat_length'] ?? 'N/A') . ' ft)'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="reservation-boat-length-warning" style="display:none;color:#6c757d;margin-top:8px;font-size:0.95rem;"></div>
                </div>
                <div style="margin-top:8px;margin-bottom:8px;">
                    <label style="display:block;font-weight:700;margin-bottom:6px;">Current Password:</label>
                    <input id="reservation-modify-password" type="password" name="current_password" placeholder="Enter your password" style="width:100%;max-width:360px;padding:8px;border:1px solid #ccc;border-radius:4px;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;"><input id="reservation-modify-confirm" type="checkbox"> Yes, confirm changes to this reservation</label>
                </div>
                <div style="display:flex;gap:12px;justify-content:center;margin-top:12px;">
                    <button type="button" id="reservation-modify-save" class="btn btn-primary">Save Changes</button>
                    <button type="button" id="reservation-modify-keep" class="btn-gold" style="color:#000;border:none;padding:8px 14px;">Keep Reservation</button>
                </div>
            </div>
        </div>

        <!-- Phone modal markup (page-scoped) -->
        <div class="cb-overlay" id="phone-overlay" aria-hidden="true">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="phone-title">
                <button type="button" id="phone-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="phone-title">Call Moffat Bay Marina</h3>
                <div style="text-align:center;padding:20px 0;font-size:28px;font-weight:700;">(555) 987-2345</div>
                <div style="display:flex;justify-content:center;margin-top:8px;"><button type="button" id="phone-great" class="btn-primary">Great</button></div>
            </div>
        </div>

        <?php require_once __DIR__ . '/footer.php'; ?>

    <script>
    // Contact modal logic for look_up.php
    (function(){
        var open = document.getElementById('contact-open');
        var overlay = document.getElementById('contact-overlay');
        var close = document.getElementById('contact-close');
        var cancel = document.getElementById('contact-cancel');
        var form = document.getElementById('contact-form');
        var msg = document.getElementById('cb-msg');
        function show(){ overlay.style.display = 'flex'; overlay.removeAttribute('aria-hidden'); }
        function hide(){ overlay.style.display = 'none'; overlay.setAttribute('aria-hidden','true'); msg.style.display='none'; form.style.display='block'; form.reset(); }
        if(open) open.addEventListener('click', function(){ show(); document.querySelector('#contact-form [name="name"]').focus(); });
        if(close) close.addEventListener('click', hide);
        if(cancel) cancel.addEventListener('click', hide);
        if(overlay) overlay.addEventListener('click', function(e){ if(e.target === overlay) hide(); });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });
        if(form) form.addEventListener('submit', function(e){
            e.preventDefault();
            var fd = new FormData(form);
            msg.style.display = 'none';
            fetch('contact_send.php', { method:'POST', body: fd }).then(function(r){ return r.json(); }).then(function(js){
                if(js && js.success){ form.style.display='none'; msg.innerHTML = '<div class="cb-success">Thanks! Your message was sent.</div>'; msg.style.display='block'; setTimeout(hide, 2200);
                } else { var err = (js && js.errors) ? js.errors.join('<br>') : 'Failed to send message.'; msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">'+err+'</div>'; msg.style.display='block'; }
            }).catch(function(){ msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">Network error sending message.</div>'; msg.style.display='block'; });
        });

        // Phone modal logic (page-scoped)
        (function(){
            var phoneOpen = document.getElementById('phone-open');
            var phoneOverlay = document.getElementById('phone-overlay');
            var phoneClose = document.getElementById('phone-close');
            var phoneGreat = document.getElementById('phone-great');
            function showPhone(){ if(phoneOverlay){ phoneOverlay.style.display='flex'; phoneOverlay.removeAttribute('aria-hidden'); } }
            function hidePhone(){ if(phoneOverlay){ phoneOverlay.style.display='none'; phoneOverlay.setAttribute('aria-hidden','true'); } }
            if(phoneOpen) phoneOpen.addEventListener('click', function(e){ e.preventDefault(); showPhone(); });
            if(phoneClose) phoneClose.addEventListener('click', hidePhone);
            if(phoneGreat) phoneGreat.addEventListener('click', hidePhone);
            if(phoneOverlay) phoneOverlay.addEventListener('click', function(e){ if(e.target === phoneOverlay) hidePhone(); });
            document.addEventListener('keydown', function(e){ if(e.key==='Escape') hidePhone(); });
        })();
        // Show more reservations toggle
        (function(){
            var btn = document.getElementById('show-more-reservations');
            if(!btn) return;
            btn.addEventListener('click', function(){
                var hiddenRows = document.querySelectorAll('.more-reservation');
                var showing = hiddenRows.length && hiddenRows[0].style.display !== 'none';
                if(showing){
                    hiddenRows.forEach(function(r){ r.style.display = 'none'; });
                    btn.textContent = 'Show ' + hiddenRows.length + ' more';
                } else {
                    hiddenRows.forEach(function(r){ r.style.display = ''; });
                    btn.textContent = 'Hide';
                }
            });
        })();
        // No 'show all' toggle needed; only the next upcoming reservation is displayed.
    })();
    </script>
    <script>
    // Reservation modify modal logic: open modal, client-side validate boat length vs slip, then submit POST to do=confirm_modify
    (function(){
        var overlay = document.getElementById('reservation-modify-overlay');
        var closeBtn = document.getElementById('reservation-modify-close');
        var keepBtn = document.getElementById('reservation-modify-keep');
        var saveBtn = document.getElementById('reservation-modify-save');
        var select = document.getElementById('reservation-boat-select');
        var pwd = document.getElementById('reservation-modify-password');
        var chk = document.getElementById('reservation-modify-confirm');
        var errBox = document.getElementById('reservation-modify-error');
        var lengthWarn = document.getElementById('reservation-boat-length-warning');
        var currentId = null;
        var slipSize = <?php echo intval($result['slip_info']['slip_size'] ?? 0); ?>;

        function show(id){
            currentId = id;
            if(overlay){ overlay.style.display='flex'; overlay.removeAttribute('aria-hidden'); }
            if(pwd) pwd.value='';
            if(chk) chk.checked=false;
            if(errBox) { errBox.style.display='none'; errBox.textContent=''; }
            if(lengthWarn) { lengthWarn.style.display='none'; lengthWarn.textContent=''; }
            if(select) select.focus();
        }
        function hide(){
            currentId = null;
            if(overlay){ overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); }
        }

        document.addEventListener('click', function(e){
            var a = e.target.closest && e.target.closest('.open-modify-modal');
            if(a){ e.preventDefault(); var id = a.getAttribute('data-id'); show(id); }
        });

        if(closeBtn) closeBtn.addEventListener('click', hide);
        if(keepBtn) keepBtn.addEventListener('click', hide);

        if(select){
            select.addEventListener('change', function(){
                var opt = select.options[select.selectedIndex];
                var len = parseInt(opt.getAttribute('data-length')||0,10);
                if(slipSize > 0 && len > slipSize){
                    lengthWarn.style.display='block';
                    lengthWarn.textContent = 'Selected boat is too long for the reserved slip ('+slipSize+' ft). Choose a different boat or cancel and rebook.';
                } else {
                    lengthWarn.style.display='none'; lengthWarn.textContent='';
                }
            });
        }

        if(saveBtn){
            saveBtn.addEventListener('click', function(){
                if(!select) return;
                var boatId = select.value;
                var opt = select.options[select.selectedIndex];
                var len = parseInt(opt ? (opt.getAttribute('data-length')||0) : 0,10);
                if(boatId === ''){ errBox.style.display='block'; errBox.textContent = 'Please select a boat.'; select.focus(); return; }
                if(slipSize > 0 && len > slipSize){ errBox.style.display='block'; errBox.textContent = 'Selected boat is too long for the reserved slip.'; return; }
                if(!pwd || pwd.value.trim()===''){ errBox.style.display='block'; errBox.textContent = 'Please enter your password to confirm.'; pwd.focus(); return; }
                if(!chk || !chk.checked){ errBox.style.display='block'; errBox.textContent = 'Please check the confirmation box to proceed.'; return; }
                // submit POST to trigger server-side confirm_modify
                var form = document.createElement('form'); form.method='POST'; form.style.display='none';
                var i1=document.createElement('input'); i1.type='hidden'; i1.name='do'; i1.value='confirm_modify'; form.appendChild(i1);
                var i2=document.createElement('input'); i2.type='hidden'; i2.name='reservation_id'; i2.value=currentId||''; form.appendChild(i2);
                var i3=document.createElement('input'); i3.type='hidden'; i3.name='boat_id'; i3.value=boatId; form.appendChild(i3);
                var i4=document.createElement('input'); i4.type='hidden'; i4.name='current_password'; i4.value=pwd.value; form.appendChild(i4);
                document.body.appendChild(form); form.submit();
            });
        }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });
    })();
    </script>
    <script>
    // Reservation cancel modal logic: opens modal, validates, then posts form to trigger server-side confirm_cancel
    (function(){
        var overlay = document.getElementById('reservation-cancel-overlay');
        var closeBtn = document.getElementById('reservation-cancel-close');
        var keepBtn = document.getElementById('reservation-cancel-keep');
        var confirmBtn = document.getElementById('reservation-cancel-confirm');
        var pwdInput = document.getElementById('reservation-current-password');
        var chk = document.getElementById('reservation-confirm-checkbox');
        var errBox = document.getElementById('reservation-cancel-error');
        var currentId = null;

        function show(id){
            currentId = id;
            if(overlay){ overlay.style.display='flex'; overlay.removeAttribute('aria-hidden'); }
            if(pwdInput) { pwdInput.value = ''; pwdInput.focus(); }
            if(chk) chk.checked = false;
            if(errBox) { errBox.style.display='none'; errBox.textContent = ''; }
        }
        function hide(){
            currentId = null;
            if(overlay){ overlay.style.display='none'; overlay.setAttribute('aria-hidden','true'); }
        }

        document.addEventListener('click', function(e){
            var a = e.target.closest && e.target.closest('.open-cancel-modal');
            if(a){
                e.preventDefault();
                var id = a.getAttribute('data-id');
                show(id);
            }
        });

        if(closeBtn) closeBtn.addEventListener('click', hide);
        if(keepBtn) keepBtn.addEventListener('click', hide);

        if(confirmBtn){
            confirmBtn.addEventListener('click', function(){
                if(!pwdInput || !chk) return;
                var pwd = pwdInput.value.trim();
                if(pwd === ''){
                    errBox.style.display='block'; errBox.textContent = 'Please enter your password.'; pwdInput.focus(); return;
                }
                if(!chk.checked){
                    errBox.style.display='block'; errBox.textContent = 'Please check the confirmation box to proceed.'; return;
                }
                // Build a form and submit via POST so server-side confirm_cancel flow handles it and page reloads
                var form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                var i1 = document.createElement('input'); i1.type='hidden'; i1.name='do'; i1.value='confirm_cancel'; form.appendChild(i1);
                var i2 = document.createElement('input'); i2.type='hidden'; i2.name='reservation_id'; i2.value=currentId || '' ; form.appendChild(i2);
                var i3 = document.createElement('input'); i3.type='hidden'; i3.name='current_password'; i3.value=pwd; form.appendChild(i3);
                var i4 = document.createElement('input'); i4.type='hidden'; i4.name='confirm_delete'; i4.value='1'; form.appendChild(i4);
                document.body.appendChild(form);
                form.submit();
            });
        }

        document.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });
    })();
    </script>
</body>
</html>