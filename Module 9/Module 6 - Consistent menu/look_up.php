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
                    $sql = 'SELECT r.*, u.email 
                        FROM reservations r
                        LEFT JOIN users u ON r.user_ID = u.user_ID
                        LEFT JOIN slips s ON r.slip_ID = s.slip_ID
                        LEFT JOIN boats b ON r.boat_ID = b.boat_ID
                        WHERE r.confirmation_number = ? LIMIT 1';
                } else {
                    $sql = 'SELECT r.*, u.email 
                        FROM reservations r
                        LEFT JOIN users u ON r.user_ID = u.user_ID
                        LEFT JOIN slips s ON r.slip_ID = s.slip_ID
                        LEFT JOIN boats b ON r.boat_ID = b.boat_ID
                        WHERE u.email = ? LIMIT 1';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$query]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $result = $row;
                    $userId = $result['user_ID'] ?? null;
                    if ($userId) {
                        // slip
                        try {
                            $slipId = $result['slip_ID'] ?? null;
                            if ($slipId) {
                                $slipStmt = $pdo->prepare('SELECT slip_ID, slip_size, location_code FROM slips WHERE slip_ID = ? LIMIT 1');
                                $slipStmt->execute([$slipId]);
                                $slipRow = $slipStmt->fetch(PDO::FETCH_ASSOC);
                                if ($slipRow) $result['slip_info'] = $slipRow;
                            }
                        } catch (Exception $e) {}
                        // boat
                        try {
                            $boatId = $result['boat_ID'] ?? null;
                            if ($boatId) {
                                $boatStmt = $pdo->prepare('SELECT boat_ID, boat_name, boat_length FROM boats WHERE boat_ID = ? LIMIT 1');
                                $boatStmt->execute([$boatId]);
                                $boatRow = $boatStmt->fetch(PDO::FETCH_ASSOC);
                                if ($boatRow) $result['boat_info'] = $boatRow;
                            }
                        } catch (Exception $e) {}
                        // waitlist
                        try {
                            $waitlistStmt = $pdo->prepare('SELECT w.*, b.boat_name, b.boat_length 
                                                       FROM waitlist w
                                                       LEFT JOIN boats b ON w.boat_ID = b.boat_ID
                                                       WHERE w.user_ID = ?
                                                       ORDER BY w.position_in_queue ASC');
                            $waitlistStmt->execute([$userId]);
                            $waitlistEntries = $waitlistStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) { $waitlistEntries = []; }
                    }
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
    /* Card overlap: place the lookup cards in a raised card that overlaps the hero by 40px */
    body.reservation-info .cards {
        max-width: 980px;
        margin: 0 auto;
        margin-top: -40px; /* overlap hero by 40px */
        position: relative;
        z-index: 1000; /* sit above the hero */
        padding: 0 16px;
        box-sizing: border-box;
    }
    body.reservation-info .cards .card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(31,47,69,0.12);
        padding: 20px;
        overflow: visible;
    }
    @media (max-width:900px){
        body.reservation-info .cards { margin-top: 12px; }
        body.reservation-info .cards .card { padding: 14px; border-radius:8px; }
    }
    /* Keep footer at bottom: make this page a column-flex container so main grows */
    body.reservation-info {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    body.reservation-info main {
        flex: 1 1 auto; /* allow main content to grow and push footer down */
    }
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
            <div class="card">
                <form method="post" class="form-lookup" novalidate>
                    <div class="search-type">
                        <label><input type="radio" name="search_by" value="confirmation" <?php if(!isset($_POST['search_by']) || $_POST['search_by']==='confirmation') echo 'checked'; ?>> Confirmation Number</label>
                        <label><input type="radio" name="search_by" value="email" <?php if(isset($_POST['search_by']) && $_POST['search_by']==='email') echo 'checked'; ?>> Email Address</label>
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
                            <p><strong>User ID:</strong> <?php echo e($result['user_ID'] ?? 'N/A'); ?></p>
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
                            <p><strong>Start Date:</strong> <?php echo e($result['start_date'] ?? $result['check_in'] ?? 'N/A'); ?></p>
                            <p><strong>End Date:</strong> <?php echo e($result['end_date'] ?? $result['check_out'] ?? 'N/A'); ?></p>
                            <p><strong>Duration:</strong> <?php echo e($result['months_duration'] ?? 'N/A'); ?> months</p>
                            <p><strong>Total Cost:</strong> $<?php echo e($result['total_cost'] ?? '0.00'); ?></p>
                            <p><strong>Status:</strong> <span class="badge badge-<?php echo strtolower($result['reservation_status'] ?? 'unknown'); ?>"><?php echo e($result['reservation_status'] ?? 'unknown'); ?></span></p>
                        </div>

                        <?php if (!empty($waitlistEntries)): ?>
                        <div class="detail-section" id="waitlist-section">
                            <h3>Your Waitlist Entries</h3>
                            <table class="waitlist-table">
                                <thead>
                                    <tr>
                                        <th>Position</th>
                                        <th>Boat</th>
                                        <th>Preferred Size</th>
                                        <th>Preferred Dates</th>
                                        <th>Duration</th>
                                        <th>Date Added</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($waitlistEntries as $entry): ?>
                                    <tr>
                                        <td><?php echo e($entry['position_in_queue']); ?></td>
                                        <td><?php echo e($entry['boat_name'] ?? 'Any'); ?></td>
                                        <td><?php echo e($entry['preferred_slip_size'] ?? 'Any'); ?> ft</td>
                                        <td>
                                            <?php if ($entry['preferred_start_date']): ?>
                                                <?php echo e($entry['preferred_start_date']); ?> to <?php echo e($entry['preferred_end_date'] ?? 'TBD'); ?>
                                            <?php else: ?>
                                                Flexible
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($entry['months_duration'] ?? 'N/A'); ?> months</td>
                                        <td><?php echo e(date('M d, Y', strtotime($entry['date_created']))); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

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
                            <?php if (!empty($loggedIn)): ?>
                                <form method="post" style="display:inline-block;margin-right:8px;">
                                    <input type="hidden" name="do" value="request_modify">
                                    <input type="hidden" name="id" value="<?php echo e($result['reservation_ID'] ?? 0); ?>">
                                    <button type="submit" class="btn">Modify Reservation</button>
                                </form>

                                <form method="post" style="display:inline-block;">
                                    <input type="hidden" name="do" value="request_cancel">
                                    <input type="hidden" name="id" value="<?php echo e($result['reservation_ID'] ?? 0); ?>">
                                    <button type="submit" class="btn btn-cancel">Cancel Reservation</button>
                                </form>
                                <?php if (!empty($waitlistEntries)): ?>
                                <div class="waitlist-check-btn" style="display:inline-block;margin-left:10px;">
                                    <button type="button" class="btn btn-info" onclick="document.querySelector('.detail-section#waitlist-section, .detail-section').scrollIntoView({behavior: 'smooth'});">Check on waitlist position</button>
                                    <span class="waitlist-badge">on the waitlist?</span>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p><em>Log in to modify or cancel this reservation.</em></p>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>