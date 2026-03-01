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
    // Join to slips to obtain the human-friendly location_code for each slip
    $stmt = $pdo->prepare("SELECT r.*, s.location_code FROM reservations r LEFT JOIN slips s ON r.slip_ID = s.slip_ID WHERE r.user_id = ? ORDER BY r.start_date DESC");
    $stmt->execute([$userId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$today = date('Y-m-d');
// Split into active and past reservations similar to MyAccount.php
$activeReservations = [];
$pastReservations = [];
foreach ($reservations as $r) {
    $start = $r['start_date'] ?? $r['date'] ?? $r['reservation_date'] ?? $r['created_at'] ?? null;
    $end = $r['end_date'] ?? null;
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

    <div class="card-wrap">
            <div class="card-grid">
                <?php foreach($reservations as $res): ?>
                    <div class="card-left<?php if (!empty($res['reservation_status']) && strtolower($res['reservation_status']) === 'completed') echo ' reservation-completed'; ?>">
                        <div class="card-left-body">
                            <h3>Confirmation #<?= htmlspecialchars($res['confirmation_number']) ?></h3>
                            <p><strong>Boat Slip:</strong> <?= htmlspecialchars($res['location_code'] ?? $res['slip_ID']) ?></p>
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
                                <a href="#" data-res-id="<?= intval($resId) ?>" data-start="<?= htmlspecialchars($res['start_date'] ?? '') ?>" data-end="<?= htmlspecialchars($res['end_date'] ?? '') ?>" class="btn ghost edit-reservation">Edit</a>
                                <a href="#" data-res-id="<?= intval($resId) ?>" class="btn ghost cancel-reservation">Cancel</a>
                              </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<!-- Edit / Cancel Modal (single shared modal) -->
<div id="reservationModal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);z-index:9999"> 
    <div style="background:#fff;border-radius:8px;max-width:520px;width:94%;padding:18px;box-shadow:0 12px 40px rgba(0,0,0,0.2)"> 
        <h3 id="modalTitle">Edit Reservation</h3>
        <form id="reservationModalForm" method="POST" action="MyAccount.php">
            <input type="hidden" name="reservation_action" id="modalAction" value="">
            <input type="hidden" name="reservation_id" id="modalResId" value="">
            <div id="modalFields">
                <div style="margin:8px 0">
                    <label style="display:block;margin-bottom:6px">Start Date</label>
                    <input type="date" name="start_date" id="modalStart" style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px">
                </div>
                <div style="margin:8px 0">
                    <label style="display:block;margin-bottom:6px">End Date</label>
                    <input type="date" name="end_date" id="modalEnd" style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px">
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
            modalFields.style.display = 'none';
            modalPasswordInput.required = true;
            modalSubmitBtn.textContent = 'Confirm Cancel';
        } else if(type === 'edit'){
            modalTitle.textContent = 'Edit Reservation';
            modalFields.style.display = 'block';
            modalStart.value = start || '';
            modalEnd.value = end || '';
            modalPasswordInput.required = true;
            modalSubmitBtn.textContent = 'Save Changes';
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
