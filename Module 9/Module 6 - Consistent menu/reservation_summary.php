<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: reservation_summary.php
Purpose: Displays user's reservation summary and confirmation details.
Documentation-only header; safe and non-destructive.
*/
/**
 * Nardos Gebremedhin 
 * 02/12/26
 * Moffay Bay:Reservation Summary Page
 */
session_start();

// Check login state
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);

// Load database connection
$dbPaths = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php'
];
$dbIncluded = false;
foreach ($dbPaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        $dbIncluded = true;
        break;
    }
}

// Redirect if not logged in
if (!$loggedIn) {
    $current = $_SERVER['REQUEST_URI'] ?? '/reservation_summary.php';
    header("Location: BlueTeam_LoginPage.php?error=" . urlencode("Please log in to view your reservations.") . "&redirect=" . urlencode($current));
    exit;
}

// Fetch reservations for logged-in user
$userId = $_SESSION['user_id'] ?? null;

$reservations = [];
if ($userId && $dbIncluded) {
    // Join to slips and boats to obtain human-friendly slip location and boat details.
    // Slip size is retrieved separately to avoid referencing non-existent slip columns directly in SQL.
    $stmt = $pdo->prepare("SELECT r.*, s.location_code, b.boat_name, b.boat_length
                          FROM reservations r
                          LEFT JOIN slips s ON r.slip_ID = s.slip_ID
                          LEFT JOIN boats b ON r.boat_ID = b.boat_ID
                          WHERE r.user_id = ?
                          ORDER BY r.start_date DESC");
    $stmt->execute([$userId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich reservations with slip_size when possible by detecting slip table columns first
    try {
        $slipIds = [];
        foreach ($reservations as $rr) {
            $sid = $rr['slip_ID'] ?? $rr['slip_id'] ?? $rr['slip'] ?? 0;
            if (!empty($sid) && is_numeric($sid)) $slipIds[intval($sid)] = intval($sid);
        }
        if (!empty($slipIds)) {
            // detect slip size column name
            $sc = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slips'");
            $sc->execute();
            $slipCols = $sc->fetchAll(PDO::FETCH_COLUMN);
            $colSlipId = in_array('slip_ID',$slipCols) ? 'slip_ID' : (in_array('id',$slipCols) ? 'id' : null);
            $colSlipSize = in_array('slip_size',$slipCols) ? 'slip_size' : (in_array('size',$slipCols) ? 'size' : (in_array('length_ft',$slipCols) ? 'length_ft' : (in_array('size_ft',$slipCols) ? 'size_ft' : null)));
            if (!empty($colSlipId) && !empty($colSlipSize)) {
                $placeholders = implode(',', array_fill(0, count($slipIds), '?'));
                $vals = array_values($slipIds);
                $sq = $pdo->prepare("SELECT {$colSlipId} AS slip_id, {$colSlipSize} AS slip_size FROM slips WHERE {$colSlipId} IN ({$placeholders})");
                $sq->execute($vals);
                $slipMap = [];
                while ($srow = $sq->fetch(PDO::FETCH_ASSOC)) {
                    $slipMap[intval($srow['slip_id'])] = $srow['slip_size'] ?? null;
                }
                // attach slip_size to reservations where applicable
                foreach ($reservations as &$rr) {
                    $sid = $rr['slip_ID'] ?? $rr['slip_id'] ?? $rr['slip'] ?? 0;
                    if (!empty($sid) && isset($slipMap[intval($sid)])) $rr['slip_size'] = $slipMap[intval($sid)];
                }
                unset($rr);
            }
        }
    } catch (Exception $e) {
        // ignore enrichment errors — leave reservations as originally fetched
    }
}
$today = date('Y-m-d');
// Split into active and past reservations similar to MyAccount.php
$activeReservations = [];
$pastReservations = [];
foreach ($reservations as $r) {
    $start = $r['start_date'] ?? $r['date'] ?? $r['reservation_date'] ?? $r['created_at'] ?? null;
    $end = $r['end_date'] ?? null;
    $status = strtolower($r['reservation_status'] ?? $r['status'] ?? '');
    // Treat explicitly canceled reservations as past so they appear after active/future ones
    if ($status === 'canceled') { $pastReservations[] = $r; continue; }
    $isPast = false;
    if ($end) {
        if (date('Y-m-d', strtotime($end)) < $today) $isPast = true;
    } elseif ($start) {
        if (date('Y-m-d', strtotime($start)) < $today) $isPast = true;
    }
    if ($isPast) $pastReservations[] = $r; else $activeReservations[] = $r;
}

// Fetch user's boats for the inline edit form (best-effort)
$boats = [];
if ($dbIncluded) {
    try {
        $bstmt = $pdo->prepare("SELECT * FROM boats WHERE user_id = ?");
        $bstmt->execute([$userId]);
        $boats = $bstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) { $boats = []; }
}

// Prepare page-level messages
$successMsg = '';
if (isset($_GET['canceled'])) $successMsg = 'Reservation canceled successfully.';
if (isset($_GET['edited'])) $successMsg = 'Reservation updated successfully.';
$pageError = '';

// Handle inline cancel/edit actions posted from this page's modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbIncluded && isset($_POST['reservation_action'])) {
    $action = $_POST['reservation_action'];
    try {
        $currentpw = trim($_POST['current_password'] ?? '');
        if ($currentpw === '') throw new Exception('Enter your current password to confirm this action.');

        // resolve current user row
        $current = false;
        if (!empty($userId)) {
            foreach (['id','user_id','uid'] as $c) {
                try {
                    $s = $pdo->prepare("SELECT * FROM users WHERE $c = :id LIMIT 1");
                    $s->execute([':id' => $userId]);
                    $r = $s->fetch(PDO::FETCH_ASSOC);
                    if ($r) { $current = $r; break; }
                } catch (Exception $e) { }
            }
        }
        if (!$current) {
            $s = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $s->execute([':email' => $_SESSION['username'] ?? $_SESSION['email'] ?? null]);
            $current = $s->fetch(PDO::FETCH_ASSOC) ?: false;
        }
        if (!$current) throw new Exception('Unable to resolve current account.');

        $currentHash = $current['password_hash'] ?? $current['password'] ?? $current['passwd'] ?? null;
        if (!$currentHash || !password_verify($currentpw, $currentHash)) throw new Exception('Current password incorrect.');

        // determine reservation PK column
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations'");
        $colStmt->execute();
        $resCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        $pkResCol = in_array('reservation_ID',$resCols) ? 'reservation_ID' : (in_array('reservation_id',$resCols) ? 'reservation_id' : (in_array('id',$resCols) ? 'id' : null));
        if (empty($pkResCol)) throw new Exception('Reservations primary key not found.');

        $resId = intval($_POST['reservation_id'] ?? 0);
        if ($resId <= 0) throw new Exception('Invalid reservation selected.');

        $rq = $pdo->prepare("SELECT * FROM reservations WHERE {$pkResCol} = :id LIMIT 1");
        $rq->execute([':id' => $resId]);
        $resRow = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$resRow) throw new Exception('Reservation not found.');

        // verify ownership
        $owns = false;
        $currentUid = $current['user_ID'] ?? $current['id'] ?? $current['user_id'] ?? null;
        if (!empty($currentUid) && array_key_exists('user_ID',$resRow) && intval($resRow['user_ID']) === intval($currentUid)) $owns = true;
        $emailForLookup = $current['email'] ?? $_SESSION['username'] ?? $_SESSION['email'] ?? null;
        if (!$owns) {
            if ((!empty($resRow['user_email']) && $resRow['user_email'] === $emailForLookup) || (!empty($resRow['email']) && $resRow['email'] === $emailForLookup)) $owns = true;
        }
        if (!$owns) throw new Exception('You do not have permission to modify this reservation.');

        if ($action === 'cancel') {
            // perform cancel
            if (in_array('reservation_status',$resCols) || in_array('status',$resCols)) {
                $statusCol = in_array('reservation_status',$resCols) ? 'reservation_status' : 'status';
                $up = $pdo->prepare("UPDATE reservations SET {$statusCol} = :st WHERE {$pkResCol} = :id");
                $up->execute([':st' => 'canceled', ':id' => $resId]);
                header('Location: reservation_summary.php?canceled=1'); exit;
            } else {
                throw new Exception('Reservations table does not support status updates.');
            }
        }

        if ($action === 'edit') {
            // Only allow updating the boat. Dates must not be changed here.
            $boatId = intval($_POST['boat_id'] ?? 0);
            if ($boatId <= 0) throw new Exception('Please select a valid boat for this reservation.');

            // determine boats column name in reservations table and boats PK column
            $boatColInRes = in_array('boat_ID',$resCols) ? 'boat_ID' : (in_array('boat_id',$resCols) ? 'boat_id' : null);
            if (empty($boatColInRes)) throw new Exception('Reservations table does not have a boat reference column.');

            // Validate selected boat belongs to the current user and get its length
            try {
                // detect boats table columns
                $bcolsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boats'");
                $bcolsStmt->execute();
                $boatCols = $bcolsStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { $boatCols = []; }
            $colBoatId = in_array('boat_ID',$boatCols) ? 'boat_ID' : (in_array('boat_id',$boatCols) ? 'boat_id' : (in_array('id',$boatCols) ? 'id' : null));
            $colBoatUser = in_array('user_ID',$boatCols) ? 'user_ID' : (in_array('user_id',$boatCols) ? 'user_id' : (in_array('userid',$boatCols) ? 'userid' : null));
            $colBoatLength = in_array('boat_length',$boatCols) ? 'boat_length' : (in_array('length_ft',$boatCols) ? 'length_ft' : (in_array('length',$boatCols) ? 'length' : null));
            if (empty($colBoatId) || empty($colBoatLength)) throw new Exception('Boats table schema is not compatible for validation.');

            $bq = $pdo->prepare("SELECT {$colBoatId} AS bid, {$colBoatUser} AS owner_uid, {$colBoatLength} AS length_ft FROM boats WHERE {$colBoatId} = :bid LIMIT 1");
            $bq->execute([':bid' => $boatId]);
            $brow = $bq->fetch(PDO::FETCH_ASSOC);
            if (!$brow) throw new Exception('Selected boat not found.');

            // resolve numeric owner id for comparison
            $ownerUid = $brow['owner_uid'] ?? null;
            $currentUid = $current['user_ID'] ?? $current['user_id'] ?? $current['id'] ?? null;
            if (!empty($ownerUid) && !empty($currentUid) && intval($ownerUid) !== intval($currentUid)) {
                throw new Exception('The selected boat does not belong to your account.');
            }

            $boatLength = intval($brow['length_ft'] ?? 0);
            if ($boatLength <= 0) throw new Exception('Unable to determine selected boat length.');

            // determine slip size for this reservation
            $sid = intval($resRow['slip_ID'] ?? $resRow['slip_id'] ?? $resRow['slip'] ?? 0);
            if ($sid <= 0) throw new Exception('Reservation slip information is missing; cannot validate boat size.');

            try {
                $sc = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slips'");
                $sc->execute();
                $slipCols = $sc->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { $slipCols = []; }
            $colSlipId = in_array('slip_ID',$slipCols) ? 'slip_ID' : (in_array('id',$slipCols) ? 'id' : null);
            $colSlipSize = in_array('slip_size',$slipCols) ? 'slip_size' : (in_array('size',$slipCols) ? 'size' : (in_array('length_ft',$slipCols) ? 'length_ft' : (in_array('size_ft',$slipCols) ? 'size_ft' : null)));
            if (empty($colSlipId) || empty($colSlipSize)) throw new Exception('Slips table does not expose a size column for validation.');

            $sq = $pdo->prepare("SELECT {$colSlipSize} AS slip_size FROM slips WHERE {$colSlipId} = :sid LIMIT 1");
            $sq->execute([':sid' => $sid]);
            $srow = $sq->fetch(PDO::FETCH_ASSOC);
            if (!$srow) throw new Exception('Slip record not found for this reservation.');
            $slipSize = intval($srow['slip_size'] ?? 0);
            if ($slipSize <= 0) throw new Exception('Invalid slip size; cannot validate boat length.');

            if ($boatLength > $slipSize) {
                throw new Exception('The boat has to be shorter than the reserved slip.');
            }

            // perform update
            $up = $pdo->prepare("UPDATE reservations SET {$boatColInRes} = :boat WHERE {$pkResCol} = :id");
            $up->execute([':boat' => $boatId, ':id' => $resId]);
            header('Location: reservation_summary.php?edited=1'); exit;
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}
// If a server-side error occurred during POST handling, expose it to the page notice
$pageError = isset($err) && $err ? $err : $pageError;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservation Summary - Moffat Bay</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="reservation_style.css">
    <style>
        /* Highlight completed reservations with the project's cream color */
        .reservation-completed { background: #F2E6C9; border-radius: 8px; padding: 12px; }
          /* Ensure reservation cards have consistent internal layout
              Use a single-column layout (no multiple columns) and make cards wider */
          .card-grid { display:block; }
          .card-grid .card-left { box-sizing:border-box; display:flex; flex-direction:row; align-items:center; width:100%; max-width:980px; min-height:0; padding:16px; margin:0 auto 12px; }
        .card-grid .card-left h3 { margin:0 0 10px 0; font-size:1.05rem; }
        .card-grid .card-left p { margin:6px 0; color:#1F2F45; }
        .card-grid .card-left .card-left-body { flex:1; }
        .card-grid .card-left .reservation-actions-wrapper { margin-left:auto; display:flex; align-items:center; }
        .card-grid .card-left .reservation-actions{ display:flex; gap:8px; justify-content:flex-end; }

        /* Local button styles to match reference image */
        .reservation-actions .edit-reservation,
        .reservation-actions .btn.save {
            background: #3F87A6 !important; /* ocean blue */
            color: #ffffff !important;
            border: none !important;
            padding: 12px 22px !important;
            border-radius: 12px !important;
            font-weight: 400 !important;
            font-size: 16px !important;
            text-decoration: none !important;
            box-shadow: 0 8px 22px rgba(31,47,69,0.12) !important;
            display: inline-flex !important; align-items:center !important; justify-content:center !important;
            vertical-align: middle !important;
            line-height: 1 !important;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif !important;
            -webkit-font-smoothing:antialiased !important;
            -moz-osx-font-smoothing:grayscale !important;
            letter-spacing: 0.4px !important;
            min-width: 120px !important;
        }

        .reservation-actions .cancel-reservation,
        .reservation-actions .btn.cancel {
            background: transparent !important; /* coral outline like Delete Account */
            color: #E8896B !important;
            border: 1px solid #E8896B !important;
            padding: 12px 22px !important;
            border-radius: 12px !important;
            font-weight: 400 !important;
            font-size: 16px !important;
            text-decoration: none !important;
            display: inline-flex !important; align-items:center !important; justify-content:center !important;
            vertical-align: middle !important;
            line-height: 1 !important;
            box-shadow: 0 3px 10px rgba(31,47,69,0.06) !important;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif !important;
            -webkit-font-smoothing:antialiased !important;
            -moz-osx-font-smoothing:grayscale !important;
            letter-spacing: 0.4px !important;
            min-width:120px !important;
        }

        /* Ensure anchor elements look like buttons */
        .reservation-actions a.btn { line-height:1; }

        /* Modal and inline form buttons: match card action styles */
        #reservationModal .btn, .reservation-edit-form .btn.save, .reservation-cancel-form .btn {
            background: #3F87A6 !important;
            color: #ffffff !important;
            border: none !important;
            padding: 10px 18px !important;
            border-radius: 12px !important;
            font-weight: 400 !important;
            font-size: 16px !important;
            text-decoration: none !important;
            box-shadow: 0 8px 22px rgba(31,47,69,0.12) !important;
            display: inline-flex !important; align-items:center !important; justify-content:center !important;
            line-height:1 !important;
            letter-spacing:0.4px !important;
            -webkit-font-smoothing:antialiased !important;
            -moz-osx-font-smoothing:grayscale !important;
            min-width:120px !important;
        }

        #reservationModal .btn.ghost, .reservation-edit-form .btn.cancel, .reservation-cancel-form .btn.cancel {
            background: #f5f5f5 !important;
            color: #0b1220 !important;
            border: 1px solid rgba(31,47,69,0.06) !important;
            padding: 10px 18px !important;
            border-radius: 12px !important;
            font-weight: 400 !important;
            font-size: 16px !important;
            box-shadow: 0 3px 10px rgba(31,47,69,0.06) !important;
            display: inline-flex !important; align-items:center !important; justify-content:center !important;
            line-height:1 !important;
            letter-spacing:0.4px !important;
            min-width:120px !important;
        }

          /* Reservation page: Pine-colored notice that overlaps the hero (page-scoped strong rules)
              Force the hero inner, content, notice and card grid to the exact same fluid 1000px width
              Use !important so these overrides only affect this page and do not rely on global stylesheet order. */
          /* Scope sizing to this page only via a unique wrapper ID to avoid touching nav/header styles */
          /* remove the hero-introduced spacing for this page so overlap math is exact */
          #reservation-summary-content { box-sizing: border-box !important; max-width:980px !important; width:100% !important; margin:0 auto !important; padding:0 !important; margin-top: 0 !important; }
          #reservation-summary-content .notice-wrap, #reservation-summary-content .card-wrap { box-sizing: border-box !important; width:100% !important; max-width:980px !important; margin:0 auto !important; padding:0 !important; }
          #reservation-summary-content .notice, #reservation-summary-content .card-grid { box-sizing: border-box !important; width:100% !important; max-width:980px !important; margin:0 auto !important; }
          /* reduce hero overlap by 20px (less negative = less overlap) — scoped to this page only */
          #reservation-summary-content .notice-wrap { margin-top: -35px !important; margin-bottom:12px !important; position:relative; z-index:99999 }
          #reservation-summary-content .notice { display:block !important; background:#2F5D4A !important; color:#F8F9FA !important; padding:18px 28px; border-radius:12px; box-shadow:0 8px 28px rgba(31,47,69,0.12); text-align:center; position:relative; z-index:100000; }

        @media (max-width:900px){
            .notice-wrap{ margin-top:12px !important }
            .card-grid{ max-width:100%; gap:16px }
            .card-left{ flex:0 0 100% !important; width:100% !important }
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

<?php
    $hero_title = 'Your Reservations';
    $hero_subtitle = 'Review and manage your boat slip reservations at Moffat Bay Marina';
    $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-icon lucide-calendar"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>';
    $hero_classes = 'hero-reservations';
    include 'hero.php';
?>

<div id="reservation-summary-content" class="content">
    <div class="notice-wrap">
        <div class="notice">
            <?php if (empty($reservations)): ?>
                <p><strong>You currently have no reservations.</strong></p>
                <p>Browse availability and make a reservation from the Reservations page.</p>
            <?php else: ?>
                <p><strong>Reservations displayed in chronological order, oldest last.</strong></p>
                <p>Edit or cancel your reservations.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($successMsg) || !empty($pageError)): ?>
        <div style="max-width:980px;margin:8px auto 0;padding:0 16px;text-align:center;">
            <?php if (!empty($successMsg)): ?>
                <p style="background:#D1FAE5;color:#064E3B;padding:10px;border-radius:8px;margin:0 0 8px;text-align:center;"><strong><?= htmlspecialchars($successMsg) ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($pageError)): ?>
                <p style="background:#FEE2E2;color:#991B1B;padding:10px;border-radius:8px;margin:0;text-align:center;"><strong><?= htmlspecialchars($pageError) ?></strong></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card-wrap">
            <div class="card-grid">
                <?php if (!empty($activeReservations)): ?>
                    <h2 style="font-size:1.05rem;margin:8px 0 10px;"><strong>Current/Future Reservations</strong></h2>
                <?php endif; ?>
                <?php foreach($activeReservations as $res): ?>
                    <div class="card-left<?php if (!empty($res['reservation_status']) && (strtolower($res['reservation_status']) === 'completed' || strtolower($res['reservation_status']) === 'canceled')) echo ' reservation-completed'; ?>">
                        <div class="card-left-body">
                            <h3>Confirmation #<?= htmlspecialchars($res['confirmation_number']) ?></h3>
                            <p><strong>Boat Slip:</strong> <?= htmlspecialchars($res['location_code'] ?? $res['slip_ID']) ?><?php if (!empty($res['slip_size'])): ?> (<?= htmlspecialchars($res['slip_size']) ?> ft)<?php endif; ?></p>
                            <?php if (!empty($res['boat_name'])): ?>
                                <p><strong>Boat:</strong> <?= htmlspecialchars($res['boat_name']) ?><?php if (!empty($res['boat_length'])): ?> (<?= htmlspecialchars($res['boat_length']) ?> ft)<?php endif; ?></p>
                            <?php endif; ?>
                            <p><strong>Start Date:</strong> <?= htmlspecialchars($res['start_date']) ?></p>
                            <p><strong>End Date:</strong> <?= htmlspecialchars($res['end_date']) ?></p>
                            <p><strong>Status:</strong> <?= htmlspecialchars($res['reservation_status']) ?></p>
                        </div>
                        <?php
                            // determine reservation primary id field (try common variants)
                            $resId = $res['reservation_ID'] ?? $res['reservation_id'] ?? $res['id'] ?? null;
                            $status = strtolower($res['reservation_status'] ?? $res['status'] ?? '');
                            // show actions for active/confirmed reservations only
                            $showActions = !empty($resId) && $status === 'confirmed';
                            if ($showActions):
                        ?>
                            <div class="reservation-actions-wrapper">
                              <div class="reservation-actions">
                                <a href="#" data-res-id="<?= intval($resId) ?>" data-boat-id="<?= intval($res['boat_ID'] ?? $res['boat_id'] ?? 0) ?>" data-start="<?= htmlspecialchars($res['start_date'] ?? '') ?>" data-end="<?= htmlspecialchars($res['end_date'] ?? '') ?>" class="btn ghost edit-reservation">Edit</a>
                                <a href="#" data-res-id="<?= intval($resId) ?>" class="btn ghost cancel-reservation">Cancel</a>
                              </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($pastReservations)): ?>
                    <h2 style="font-size:1.05rem;margin:18px 0 10px;"><strong>Past/Canceled Reservations</strong></h2>
                    <?php foreach($pastReservations as $res): ?>
                        <div class="card-left<?php if (!empty($res['reservation_status']) && (strtolower($res['reservation_status']) === 'completed' || strtolower($res['reservation_status']) === 'canceled')) echo ' reservation-completed'; ?>">
                            <div class="card-left-body">
                                <h3>Confirmation #<?= htmlspecialchars($res['confirmation_number']) ?></h3>
                                <p><strong>Boat Slip:</strong> <?= htmlspecialchars($res['location_code'] ?? $res['slip_ID']) ?><?php if (!empty($res['slip_size'])): ?> (<?= htmlspecialchars($res['slip_size']) ?> ft)<?php endif; ?></p>
                                <?php if (!empty($res['boat_name'])): ?>
                                    <p><strong>Boat:</strong> <?= htmlspecialchars($res['boat_name']) ?><?php if (!empty($res['boat_length'])): ?> (<?= htmlspecialchars($res['boat_length']) ?> ft)<?php endif; ?></p>
                                <?php endif; ?>
                                <p><strong>Start Date:</strong> <?= htmlspecialchars($res['start_date']) ?></p>
                                <p><strong>End Date:</strong> <?= htmlspecialchars($res['end_date']) ?></p>
                                <p><strong>Status:</strong> <?= htmlspecialchars($res['reservation_status']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
            </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<!-- Edit / Cancel Modal (single shared modal) -->
<div id="reservationModal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);z-index:9999"> 
    <div style="background:#fff;border-radius:8px;max-width:520px;width:94%;padding:18px;box-shadow:0 12px 40px rgba(0,0,0,0.2)"> 
        <h3 id="modalTitle">Edit Reservation</h3>
        <form id="reservationModalForm" method="POST" action="reservation_summary.php">
            <input type="hidden" name="reservation_action" id="modalAction" value="">
            <input type="hidden" name="reservation_id" id="modalResId" value="">
            <div id="modalFields">
                <div id="modalBoatSelect" style="margin:8px 0; display:none;">
                    <label style="display:block;margin-bottom:6px">Select Boat</label>
                    <select name="boat_id" id="modalBoatId" style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px">
                        <option value="">-- Select a boat --</option>
                        <?php foreach ($boats as $b): ?>
                            <?php $bid = $b['boat_ID'] ?? $b['boat_id'] ?? $b['id'] ?? 0; ?>
                            <option value="<?= intval($bid) ?>"><?= htmlspecialchars($b['boat_name'] ?? ($b['name'] ?? 'Unnamed')) ?><?php if (!empty($b['boat_length'] ?? $b['length'] ?? null)): ?> (<?= htmlspecialchars($b['boat_length'] ?? $b['length']) ?> ft)<?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="margin-top:8px;color:#6b7280;font-size:0.95rem">To change reservation dates or slip size, cancel this reservation and create a new one with the desired details.</p>
                </div>
            </div>
            <div id="modalPassword" style="margin:8px 0">
                <label style="display:block;margin-bottom:6px">Enter current password to confirm</label>
                <input type="password" name="current_password" id="modalPasswordInput" style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px" required>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:12px">
                <button type="button" id="modalCancelBtn" class="btn ghost">Close</button>
                <button type="submit" id="modalSubmitBtn" class="btn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal & actions: handle Edit and Cancel using a shared modal that posts to MyAccount.php
(function(){
    const modal = document.getElementById('reservationModal');
    const modalForm = document.getElementById('reservationModalForm');
    const modalTitle = document.getElementById('modalTitle');
    const modalAction = document.getElementById('modalAction');
    const modalResId = document.getElementById('modalResId');
    const modalStart = document.getElementById('modalStart');
    const modalEnd = document.getElementById('modalEnd');
    const modalPasswordInput = document.getElementById('modalPasswordInput');
    const modalFields = document.getElementById('modalFields');
    const modalPassword = document.getElementById('modalPassword');
    const modalSubmitBtn = document.getElementById('modalSubmitBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');

    function openModal(type, id, start, end){
        modal.style.display = 'flex';
        modalResId.value = id;
        modalAction.value = type;
        modalPasswordInput.value = '';
        if(type === 'cancel'){
            modalTitle.textContent = 'Cancel Reservation';
            document.getElementById('modalBoatSelect').style.display = 'none';
            modalPasswordInput.required = true;
            modalSubmitBtn.textContent = 'Confirm Cancel';
        } else if(type === 'edit'){
            modalTitle.textContent = 'Edit Reservation';
            // show boat selection only
            document.getElementById('modalBoatSelect').style.display = 'block';
            modalPasswordInput.required = true;
            modalSubmitBtn.textContent = 'Save Changes';
            // pre-select boat if provided
            try{ const bid = this.getAttribute('data-boat-id'); if(bid) document.getElementById('modalBoatId').value = bid; }catch(e){}
        }
    }

    function closeModal(){ modal.style.display = 'none'; }

    document.querySelectorAll('.edit-reservation').forEach(a=>{
        a.addEventListener('click', function(e){
            e.preventDefault();
            const id = this.getAttribute('data-res-id');
            const start = this.getAttribute('data-start');
            const end = this.getAttribute('data-end');
            openModal('edit', id, start, end);
        });
    });

    document.querySelectorAll('.cancel-reservation').forEach(a=>{
        a.addEventListener('click', function(e){
            e.preventDefault();
            const id = this.getAttribute('data-res-id');
            openModal('cancel', id, '', '');
        });
    });

    modalCancelBtn.addEventListener('click', function(){ closeModal(); });

    // Close modal on outside click
    modal.addEventListener('click', function(e){ if(e.target === modal) closeModal(); });

    // Basic client validation: ensure dates are valid when editing
    modalForm.addEventListener('submit', function(e){
        const act = modalAction.value;
        if(act === 'edit'){
            if(modalStart.value === '' || modalEnd.value === ''){
                e.preventDefault(); alert('Please enter both start and end dates.'); return;
            }
            if(modalStart.value > modalEnd.value){ e.preventDefault(); alert('End date must be after start date.'); return; }
        }
        // allow submit to MyAccount.php which will perform server-side auth/validation
    });
})();
</script>
</body>
</html>
