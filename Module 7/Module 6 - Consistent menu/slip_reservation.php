<?php
/**
 * Jonah Aney 02/12/26
 * Moffat Bay: Slip Reservation Page
 *
 * PURPOSE:
 * Allows a logged-in user to:
 *   - Select an existing boat OR add a new boat
 *   - Check slip availability for selected dates & size
 *   - Proceed to reservation summary if available
 *
 * FLOW:
 *   1. Require login (session-based authentication)
 *   2. Load user boats from database
 *   3. Handle POST actions:
 *        - Add boat (optional)
 *        - Check availability
 *        - Confirm reservation (re-check availability for safety)
 *   4. Pass reservation data to reservation_summary.php via session
 *
 * NOTE:
 * Availability is intentionally checked TWICE:
 *   - Once for user feedback
 *   - Again before confirming (prevents double booking)
 */

session_start();
require_once "db.php";

//REQUIRE USER LOGIN: Redirects to login page if no active session exists.
if (!isset($_SESSION['user_id'])) {
  $current = $_SERVER['REQUEST_URI'] ?? '/slip_reservation.php';
  header("Location: BlueTeam_LoginPage.php?redirect=" . urlencode($current) . "&login_notice=1");
  exit();
}

$userID = $_SESSION['user_id'];

// UI FEEDBACK VARIABLES: Used to display success/error alerts above the form.
$error = "";
$boatSuccess = "";
$availabilitySuccess = "";
$availabilityCost = null;
$availableSlips = [];
$availabilityBase = null;
$availabilityHookup = null;
$availabilityMonths = null;

// DEFAULT FORM VALUES: These allow form fields to repopulate after submission.
$startDate = "";
$endDate   = "";
$slipSize  = "";
$boatID    = "";
$newBoatName   = "";
$newBoatLength = "";

// Flash message: show once after redirect, then clear
if (!empty($_SESSION['boat_success'])) {
    $boatSuccess = $_SESSION['boat_success'];
    unset($_SESSION['boat_success']);
}

// Keep newly added boat selected after redirect 
if (!empty($_SESSION['selected_boat_id'])) {
    $boatID = $_SESSION['selected_boat_id'];
    unset($_SESSION['selected_boat_id']);
}

// Restore date + slip size after Add Boat redirect (then clear)
if (!empty($_SESSION['reservation_form'])) {
    $startDate = $_SESSION['reservation_form']['start_date'] ?? '';
    $endDate   = $_SESSION['reservation_form']['end_date'] ?? '';
    $slipSize  = $_SESSION['reservation_form']['slip_size'] ?? '';
    unset($_SESSION['reservation_form']);
}

/*----------LOAD USER'S SAVED BOATS---------- 
Retrieves all boats associated with the logged-in user.
Used to populate the “Select Boat” dropdown. Only run when logged in.*/
$userBoats = [];
$userEmail = $_SESSION['username'] ?? $_SESSION['email'] ?? null;
if ($loggedIn) {
  // Attempt several queries to tolerate small schema/session differences
  $tryQueries = [
    ["sql" => "SELECT boat_ID, boat_name, boat_length FROM boats WHERE user_ID = :user_id ORDER BY boat_name", "params" => [':user_id' => $userID]],
    ["sql" => "SELECT boat_id AS boat_ID, boat_name, boat_length FROM boats WHERE user_id = :user_id ORDER BY boat_name", "params" => [':user_id' => $userID]],
    ["sql" => "SELECT id AS boat_ID, name AS boat_name, length_ft AS boat_length FROM boats WHERE user_id = :user_id ORDER BY name", "params" => [':user_id' => $userID]],
  ];

  foreach ($tryQueries as $q) {
    try {
      $stmt = $pdo->prepare($q['sql']);
      $stmt->execute($q['params']);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (!empty($rows)) { $userBoats = $rows; break; }
    } catch (Exception $e) {
      // ignore and try next variant
    }
  }

  // If still empty, try resolving by user email (join users table)
  if (empty($userBoats) && $userEmail) {
    try {
      $stmt = $pdo->prepare("SELECT b.boat_ID, b.boat_name, b.boat_length
                             FROM boats b
                             JOIN users u ON (b.user_ID = u.user_ID OR b.user_id = u.user_ID)
                             WHERE u.email = :email
                             ORDER BY b.boat_name");
      $stmt->execute([':email' => $userEmail]);
      $userBoats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
      // final fallback: leave $userBoats empty
      $userBoats = [];
    }
  }
} // end loggedIn guard


// HANDLE FORM SUBMISSION: All reservation logic lives inside POST block.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

// Capture submitted form values 
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';
    $slipSize  = $_POST['slip_size'] ?? '';
    $boatID    = $_POST['boat_id'] ?? '';

    $newBoatName   = trim($_POST['new_boat_name'] ?? '');
    $newBoatLength = trim($_POST['new_boat_length'] ?? '');

    // Normalize numeric values
    $slipSizeNum = is_numeric($slipSize) ? intval($slipSize) : 0;
    $newBoatLengthNum = is_numeric($newBoatLength) ? intval($newBoatLength) : 0;

/* ---------- OPTIONAL: ADD NEW BOAT (PRG pattern) ----------
   When user clicks "Add Boat":
   - Insert boat
   - Redirect back to this page (prevents double insert on refresh)
   - Reloads dropdown so new boat appears immediately */
if (isset($_POST['add_boat'])) {

    if ($newBoatName !== "" && $newBoatLength !== "") {

        $insertBoat = $pdo->prepare("
            INSERT INTO boats (user_ID, boat_name, boat_length)
            VALUES (:user_id, :boat_name, :boat_length)
        ");

        $insertBoat->execute([
            ':user_id'     => $userID,
            ':boat_name'   => $newBoatName,
            ':boat_length' => $newBoatLength
        ]);

// New boat becomes selected
        $boatID = $pdo->lastInsertId();

// Flash message + selected boat (survive redirect)
        $_SESSION['boat_success'] = "Boat added successfully.";
        $_SESSION['selected_boat_id'] = $boatID;

// Restore form values after Add Boat redirect (then clear)
    if (!empty($_SESSION['reservation_form'])) {
        $startDate = $_SESSION['reservation_form']['start_date'] ?? '';
        $endDate   = $_SESSION['reservation_form']['end_date'] ?? '';
        $slipSize  = $_SESSION['reservation_form']['slip_size'] ?? '';
        unset($_SESSION['reservation_form']);
    }
// Preserve form state across redirect
        $_SESSION['reservation_form'] = [
          'start_date' => $startDate,
          'end_date'   => $endDate,
          'slip_size'  => $slipSize
        ];

// Redirect to convert POST -> GET (prevents duplicates + refreshes dropdown)
        header("Location: slip_reservation.php");
        exit();

    } else {
        $error = "Please enter both boat name and length.";
    }
  }
}

// CHECK AVAILABILITY
if (isset($_POST['check_availability'])) {

// Require boat before checking availability
  if (empty($boatID)) {
    $error = "Please select or add a boat.";
  }

  // Server-side date validation: no past dates, minimum 1 month reservation
  if (!$error) {
    $today = new DateTime('today');
    $sd = DateTime::createFromFormat('Y-m-d', $startDate);
    $ed = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$sd || !$ed) {
      $error = "Please provide valid start and end dates.";
    } else {
      if ($sd < $today) {
        $error = "Start date cannot be in the past.";
      } else {
        $minEnd = (clone $sd)->modify('+1 month');
        if ($ed < $minEnd) {
          $error = "Reservations must be at least one month long.";
        }
      }
    }
  }

  // Ensure slip size is large enough for selected boat
  if (!$error && !empty($boatID)) {
    $boatLength = 0;
    // try find in loaded $userBoats
    if (!empty($userBoats)) {
      foreach ($userBoats as $b) {
        if ((string)($b['boat_ID'] ?? $b['boat_id'] ?? '') === (string)$boatID) {
          $boatLength = intval($b['boat_length'] ?? $b['length_ft'] ?? $b['boat_length']);
          break;
        }
      }
    }
    // fallback: query DB for the boat length
    if ($boatLength <= 0) {
      try {
        $q = $pdo->prepare("SELECT boat_length, length_ft FROM boats WHERE boat_ID = :id OR boat_id = :id LIMIT 1");
        $q->execute([':id' => $boatID]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) $boatLength = intval($r['boat_length'] ?? $r['length_ft'] ?? 0);
      } catch (Exception $e) { }
    }

    if ($boatLength > 0 && $slipSizeNum > 0 && $slipSizeNum < $boatLength) {
      $error = "Selected slip size ({$slipSizeNum} ft) is smaller than your boat ({$boatLength} ft).";
    }
    // If user is adding a new boat, compare against entered length as well
    if (!$error && $boatID === 'add_new' && $newBoatLengthNum > 0 && $slipSizeNum > 0 && $slipSizeNum < $newBoatLengthNum) {
      $error = "Selected slip size ({$slipSizeNum} ft) is smaller than your new boat ({$newBoatLengthNum} ft).";
    }
  }

// Only run query if no validation error
    if (!$error) {

        $sql = "
            SELECT COUNT(*)
            FROM slips s
            WHERE s.slip_size = :slip_size
            AND s.slip_ID NOT IN (
                SELECT r.slip_ID
                FROM reservations r
                WHERE r.reservation_status = 'confirmed'
                AND (:start_date <= r.end_date
                     AND :end_date >= r.start_date)
            )
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':slip_size'  => $slipSize,
            ':start_date' => $startDate,
            ':end_date'   => $endDate
        ]);

        $availableCount = (int)$stmt->fetchColumn();

        if ($availableCount > 0) {
            $availabilitySuccess = "Slip available! You may confirm your reservation.";

            // Server-side: compute defensive estimated cost for display
            $boatLength = 0;
            if (!empty($userBoats)) {
              foreach ($userBoats as $b) {
                if ((string)($b['boat_ID'] ?? $b['boat_id'] ?? '') === (string)$boatID) {
                  $boatLength = intval($b['boat_length'] ?? $b['length_ft'] ?? 0);
                  break;
                }
              }
            }
            if ($boatLength <= 0 && $boatID === 'add_new') {
              $boatLength = $newBoatLengthNum;
            }
            if ($boatLength <= 0) {
              try {
                $q = $pdo->prepare("SELECT boat_length, length_ft FROM boats WHERE boat_ID = :id OR boat_id = :id LIMIT 1");
                $q->execute([':id' => $boatID]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if ($r) $boatLength = intval($r['boat_length'] ?? $r['length_ft'] ?? 0);
              } catch (Exception $e) { }
            }

            $sd = DateTime::createFromFormat('Y-m-d', $startDate);
            $ed = DateTime::createFromFormat('Y-m-d', $endDate);
            $months = 1;
            if ($sd && $ed) {
              $interval = $sd->diff($ed);
              $months = ($interval->y * 12) + $interval->m + (($interval->d > 0) ? 1 : 0);
              if ($months < 1) $months = 1;
            }
            $pricePerFoot = 10.50;
            $hookupPerMonth = 10.50;
            $cost = ($boatLength * $pricePerFoot) + ($hookupPerMonth * $months);
            $availabilityCost = round($cost,2);
            $availabilityBase = round(($boatLength * $pricePerFoot),2);
            $availabilityHookup = round(($hookupPerMonth * $months),2);
            $availabilityMonths = intval($months);

            // Build a slip availability map: fetch slips matching the selected slip size (exact)
            try {
              $sizeFilter = intval($slipSizeNum) > 0 ? intval($slipSizeNum) : 0;
              $sstmt = $pdo->prepare("SELECT slip_ID, slip_name, slip_size, slip_status, location_code FROM slips WHERE slip_size = :sizeFilter ORDER BY slip_size, slip_ID");
              $sstmt->execute([':sizeFilter' => $sizeFilter]);
              $slips = $sstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              $rcheck = $pdo->prepare("SELECT COUNT(*) FROM reservations r WHERE r.slip_ID = :sid AND r.reservation_status = 'confirmed' AND (:start_date <= r.end_date AND :end_date >= r.start_date)");
              foreach ($slips as $s) {
                $sid = $s['slip_ID'] ?? $s['slipId'] ?? $s['id'] ?? null;
                $sname = $s['slip_name'] ?? $s['name'] ?? ('Slip ' . $sid);
                $ssize = intval($s['slip_size'] ?? $s['size'] ?? 0);
                $sstatus = strtolower($s['slip_status'] ?? $s['status'] ?? 'active');
                $loc = $s['location_code'] ?? $s['location'] ?? '';
                $rcheck->execute([':sid' => $sid, ':start_date' => $startDate, ':end_date' => $endDate]);
                $conflicts = (int)$rcheck->fetchColumn();
                $isAvailable = ($conflicts === 0) && ($sstatus !== 'out_of_service' && $sstatus !== 'offline');
                $availableSlips[] = [
                  'id' => $sid,
                  'name' => $sname,
                  'size' => $ssize,
                  'status' => $sstatus,
                  'location_code' => $loc,
                  'available' => $isAvailable
                ];
              }
              // persist the available slips in session so the next POST (confirm) can validate user's choice
              $_SESSION['available_slips'] = $availableSlips;
            } catch (Exception $e) {
              // ignore map errors; leave $availableSlips empty
            }
        } else {
            $error = "No slips available for those dates.";
        }
    }
}

// CONFIRM RESERVATION
if (isset($_POST['confirm_reservation'])) {

// Require boat before confirming
    if (empty($boatID)) {
        $error = "Please select or add a boat.";
    }

  // If the user chose to add a new boat at confirm time, require explicit save
  $saveNew = isset($_POST['save_new_boat']) && $_POST['save_new_boat'] === '1';
  if (!$error && $boatID === 'add_new') {
    if (!$saveNew) {
      $error = "Please save the new boat to your profile before confirming, or select an existing boat.";
    } else {
      // persist the new boat and replace boatID with the inserted id
      if ($newBoatName === '' || $newBoatLengthNum <= 0) {
        $error = "Provide a valid boat name and length to save the boat.";
      } else {
        try {
          $ins = $pdo->prepare("INSERT INTO boats (user_ID, boat_name, boat_length) VALUES (:user_id, :name, :len)");
          $ins->execute([':user_id' => $userID, ':name' => $newBoatName, ':len' => $newBoatLengthNum]);
          $boatID = $pdo->lastInsertId();
          $_SESSION['selected_boat_id'] = $boatID;
        } catch (Exception $e) {
          $error = "Unable to save new boat. Try again.";
        }
      }
    }
  }

// Only run query if no validation error
    if (!$error) {

        $sql = "
            SELECT s.slip_ID
            FROM slips s
            WHERE s.slip_size = :slip_size
            AND s.slip_ID NOT IN (
                SELECT r.slip_ID
                FROM reservations r
                WHERE r.reservation_status = 'confirmed'
                AND (:start_date <= r.end_date
                     AND :end_date >= r.start_date)
            )
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':slip_size'  => $slipSize,
            ':start_date' => $startDate,
            ':end_date'   => $endDate
        ]);

        $availableSlip = null;

        // If user selected a specific slip from the availability list, validate it
        $selectedSlipPost = $_POST['selected_slip_id'] ?? null;
        if ($selectedSlipPost) {
          // prefer validating against stored session availability
          $isValid = false;
          if (!empty($_SESSION['available_slips'])) {
            foreach ($_SESSION['available_slips'] as $s) {
              if ((string)($s['id'] ?? '') === (string)$selectedSlipPost && !empty($s['available'])) {
                $isValid = true; break;
              }
            }
          } else {
            // fallback: check DB for conflicts and status
            try {
              $q = $pdo->prepare("SELECT COUNT(*) FROM reservations r WHERE r.slip_ID = :sid AND r.reservation_status = 'confirmed' AND (:start_date <= r.end_date AND :end_date >= r.start_date)");
              $q->execute([':sid' => $selectedSlipPost, ':start_date' => $startDate, ':end_date' => $endDate]);
              $conflicts = (int)$q->fetchColumn();
              $sinfo = $pdo->prepare("SELECT slip_status, slip_size FROM slips WHERE slip_ID = :sid LIMIT 1");
              $sinfo->execute([':sid' => $selectedSlipPost]);
              $rrow = $sinfo->fetch(PDO::FETCH_ASSOC);
              $sstatus = strtolower($rrow['slip_status'] ?? '');
              $ssize = intval($rrow['slip_size'] ?? 0);
              if ($conflicts === 0 && $sstatus !== 'out_of_service' && $sstatus !== 'offline' && $ssize == $slipSizeNum) $isValid = true;
            } catch (Exception $e) { $isValid = false; }
          }
          if ($isValid) {
            $availableSlip = $selectedSlipPost;
          } else {
            $error = "Selected slip is not available. Please choose another slip.";
          }
        } else {
          // no explicit slip selected: fallback to picking first available slip matching size
          $availableSlip = $stmt->fetchColumn();
        }

        if ($availableSlip) {

            // Compute server-side estimated cost (defensive):
            $boatLength = 0;
            if (!empty($userBoats)) {
              foreach ($userBoats as $b) {
                if ((string)($b['boat_ID'] ?? $b['boat_id'] ?? '') === (string)$boatID) {
                  $boatLength = intval($b['boat_length'] ?? $b['length_ft'] ?? 0);
                  break;
                }
              }
            }
            if ($boatLength <= 0) {
              try {
                $q = $pdo->prepare("SELECT boat_length, length_ft FROM boats WHERE boat_ID = :id OR boat_id = :id LIMIT 1");
                $q->execute([':id' => $boatID]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if ($r) $boatLength = intval($r['boat_length'] ?? $r['length_ft'] ?? 0);
              } catch (Exception $e) { }
            }

            // months between start and end (count partial month as full)
            $sd = DateTime::createFromFormat('Y-m-d', $startDate);
            $ed = DateTime::createFromFormat('Y-m-d', $endDate);
            $months = 1;
            if ($sd && $ed) {
              $interval = $sd->diff($ed);
              $months = ($interval->y * 12) + $interval->m + (($interval->d > 0) ? 1 : 0);
              if ($months < 1) $months = 1;
            }

            $pricePerFoot = 10.50;
            $hookupPerMonth = 10.50;
            $cost = ($boatLength * $pricePerFoot) + ($hookupPerMonth * $months);

            $_SESSION['reservation_data'] = [
                'user_id'    => $userID,
                'boat_id'    => $boatID,
                'slip_id'    => $availableSlip,
                'start_date' => $startDate,
                'end_date'   => $endDate,
              'cost'       => round($cost,2),
              'cost_base'  => round($baseCost,2),
              'cost_hookup' => round($hookupCost,2),
              'cost_months' => intval($months)
            ];
            // clear availability session cache
            if (isset($_SESSION['available_slips'])) unset($_SESSION['available_slips']);

            header("Location: reservation_summary.php");
            exit();

        } else {
            $error = "That slip was just booked. Please try again.";
        }
    }
}

$loggedIn = isset($_SESSION['username']);
?>

<!DOCTYPE html>
<html>
<head>
  <title>Slip Reservation</title>
  <link rel="stylesheet" href="styles.css?v=1">
  <link rel="stylesheet" href="styles_slip_reservation.css?v=1">
  <style>
    .slip-map{margin:1rem 0;padding:0.5rem;border:1px solid #ddd;background:#fafafa}
    .slip-grid{display:flex;flex-wrap:wrap;gap:.5rem}
    .slip{width:120px;padding:.5rem;border-radius:4px;text-align:center;border:1px solid #ccc}
    .slip.available{background:#e7f9ee;border-color:#8fd3a6}
    .slip.unavailable{background:#fff2f2;border-color:#f2a6a6;opacity:0.9}
    .slip .meta{font-size:.85rem;color:#333}
    /* Use the same system font stack as MyAccount.php for visual consistency */
    body{font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;}
    /* Form layout improvements */
    .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;align-items:start}
    .form-group{display:flex;flex-direction:column;gap:.5rem}
    .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select{width:100%;box-sizing:border-box;padding:.45rem;border:1px solid #ccc;border-radius:4px}
    /* Add-boat panel should span full width and align fields neatly */
    .form-group.add-boat{grid-column:1/-1;display:grid;grid-template-columns:1fr 140px;gap:.5rem;align-items:center;margin-top:1.25rem}
    .form-group.add-boat > label{grid-column:1/-1;margin-bottom:.5rem;margin-top:1.5rem}
    .form-group.add-boat input[type="text"]{grid-column:1}
    .form-group.add-boat input[type="number"]{grid-column:2}
    .form-group.add-boat input[type="hidden"]{grid-column:1/-1}
    @media(max-width:600px){
      .form-group.add-boat{grid-template-columns:1fr}
      .form-group.add-boat input[type="number"]{grid-column:1}
    }
    .form-actions{display:flex;gap:.5rem;margin-top:3rem;clear:both}
    /* Reservation card visuals (match MyAccount card style) */
    .reservation-card{background:#fff;border-radius:10px;padding:18px;border:1px solid #e6e6e6;box-shadow:0 8px 30px rgba(31,47,69,0.06);max-width:980px;margin:16px auto}
    .reservation-card h3{margin-top:0;margin-bottom:8px}
    /* Cost area styling */
    .reservation-cost{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:8px;grid-column:1/-1;width:100%;box-sizing:border-box;position:relative;z-index:3;margin-bottom:4rem}
    .reservation-cost label{font-weight:700;margin-bottom:6px;display:block;text-align:center;width:100%}
    /* Hide separate electric-hookup line — hookup is shown in the breakdown below */
    .form-group.electric-hookup{display:none}
    /* Prevent global "note" styles from creating nested boxed panels inside the cost card */
    .reservation-cost .form-note-inline{background:transparent;border:none;padding:0;margin:0;width:100%;box-sizing:border-box}
    /* Tidy individual display elements inside the cost card */
    .reservation-cost #reservationCostDisplay{padding:0;margin:0}
    #reservationCostBreakdown{display:flex;flex-direction:column;gap:.4rem}
    #reservationCostBreakdown > div{display:flex;justify-content:space-between;align-items:center;gap:8px}
    #reservationTotalLine{font-weight:700;margin-top:.25rem;text-align:center}
    #reservationCostBreakdown small{color:#6b7280;white-space:nowrap;margin-left:8px}
    @media (max-width:520px){
      #reservationCostBreakdown > div{flex-direction:column;align-items:flex-start}
      #reservationCostBreakdown small{margin-left:0}
    }
  </style>
</head>

<body class="page-reservation">
<?php include 'nav.php'; ?>

<header class="page-header">
  <h1>Slip Reservation</h1>
</header>

<main class="reservation-page">
<section class="reservation-card">

<?php if ($error): ?>
  <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if ($boatSuccess): ?>
  <div class="alert alert-success"><?= $boatSuccess ?></div>
<?php endif; ?>

<?php if ($availabilitySuccess): ?>
  <div class="alert alert-success"><?= $availabilitySuccess ?></div>
<?php endif; ?>

<?php if (!is_null($availabilityCost)): ?>
  <div class="alert alert-info">
    <div>Estimated Total: <strong>$<?= number_format($availabilityCost,2) ?></strong></div>
    <div style="font-size:.95rem;margin-top:.25rem">Slip: <strong>$<?= number_format($availabilityBase ?? 0,2) ?></strong> &nbsp; | &nbsp; Electric hookup (<?= intval($availabilityMonths ?? 0) ?> mo): <strong>$<?= number_format($availabilityHookup ?? 0,2) ?></strong></div>
  </div>
<?php endif; ?>

<!-- Slip availability map will be rendered inside the form so selections submit correctly -->

<?php if ($requireLogin): ?>
  <div class="notice" style="background:#fff8e6;padding:12px;border:1px solid #f0dca8;margin-bottom:12px;border-radius:8px">
    Please <a href="BlueTeam_LoginPage.php?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? 'slip_reservation.php') ?>">log in</a> to make a reservation. If you don't have an account, use the same link to register.
  </div>
<?php endif; ?>

    <form method="POST" class="reservation-form">

      <div class="form-grid">

        <div class="form-group start-date">
          <label>Start Date</label>
          <input type="date" name="start_date"
            value="<?= htmlspecialchars($startDate) ?>" required <?= $formDisabledAttr ?>>
        </div>

        <div class="form-group end-date">
          <label>End Date</label>
          <input type="date" name="end_date"
            value="<?= htmlspecialchars($endDate) ?>" required>

        <div class="form-group slip-size">
          <label>Slip Size</label>
          <select name="slip_size" required>
            <option value="">Select size</option>
            <option value="26" <?= ($slipSize == "26") ? "selected" : "" ?>>26 ft</option>
            <option value="40" <?= ($slipSize == "40") ? "selected" : "" ?>>40 ft</option>
            <option value="50" <?= ($slipSize == "50") ? "selected" : "" ?>>50 ft</option>
          </select>
        </div>

        <div class="form-group select-boat">
          <label>Select Boat</label>
          <select name="boat_id" id="boatSelect">
            <option value="">-- Select Existing Boat --</option>
            <option value="add_new" <?= ($boatID === 'add_new') ? 'selected' : '' ?>>+ Add a new boat</option>
            <?php foreach ($userBoats as $boat):
              $bLen = intval($boat['boat_length'] ?? $boat['length_ft'] ?? 0);
            ?>
              <option value="<?= htmlspecialchars($boat['boat_ID']) ?>" data-length="<?= $bLen ?>"
                <?= ($boatID == $boat['boat_ID']) ? "selected" : "" ?>>
                <?= htmlspecialchars($boat['boat_name']) ?> (<?= $bLen ?> ft)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

      </div>

      <div class="form-group add-boat" id="addBoatPanel" style="<?= ($boatID === 'add_new') ? 'display:block;' : 'display:none;' ?>">
      <label>Add New Boat</label>
      <input type="text" name="new_boat_name" id="newBoatName" value="<?= htmlspecialchars($newBoatName) ?>" placeholder="Boat Name">
      <input type="number" name="new_boat_length" id="newBoatLength" value="<?= htmlspecialchars($newBoatLength) ?>" placeholder="Length (ft)" min="1">
        <input type="hidden" name="save_new_boat" id="saveNewBoat" value="0">
      </div>

      <div class="form-group electric-hookup">
        <label>&nbsp;</label>
        <div class="form-note-inline">
            Electric Hookup: <strong>$10.50 per month</strong>
        </div>
      </div>

      <div class="form-group reservation-cost">
        <label>Estimated Cost</label>
        <div id="reservationFormula" class="form-note-inline" style="margin-top:.25rem;font-size:.95rem;display:flex;flex-direction:column;gap:.5rem;text-align:center;">
          <div>Slip cost (per month): (<strong>$10.50</strong> × <span id="reservationLengthDisplay">0</span> ft) = <strong id="reservationBaseMonthlyDisplay">$0.00</strong></div>
          <div>Electric Hookup (per month): <strong id="reservationHookupMonthlyDisplay">$10.50</strong></div>
          <div>Months duration: <span id="reservationMonthsDisplay">0</span> mo</div>
          <div id="reservationTotalLine" style="font-weight:700;margin-top:.25rem">Total Cost: <span id="reservationCostTotalFinal">$0.00</span></div>
        </div>
        <input type="hidden" name="estimated_cost" id="estimatedCostInput" value="">
        <input type="hidden" name="estimated_base" id="estimatedBaseInput" value="">
        <input type="hidden" name="estimated_hookup" id="estimatedHookupInput" value="">
      </div>
        </div>

        <?php if (!empty($availableSlips)): ?>
          <?php
            $availOptions = array_filter($availableSlips, function($x){ return !empty($x['available']); });
            if (!empty($availOptions)):
          ?>
            <div style="margin-top:.75rem;">
              <label for="selected_slip_id">Choose an available slip</label>
              <select name="selected_slip_id" id="selected_slip_id" required>
                <option value="">-- Select a slip --</option>
                <?php foreach ($availOptions as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['id']) ?>"><?= htmlspecialchars($opt['name']) ?> (<?= htmlspecialchars($opt['location_code'] ?? '') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <div class="form-actions">

        <button type="submit" id="addBoatBtn" name="add_boat" class="btn-secondary" style="<?= ($boatID === 'add_new' && $newBoatName !== '' && $newBoatLength !== '') ? '' : 'display:none;' ?>">Add Boat</button>

        <button type="submit" name="check_availability" class="btn-primary">Check Availability</button>

        <?php if ($availabilitySuccess): ?>
          <button id="confirmBtn" type="submit" name="confirm_reservation" class="btn-secondary">Confirm & Continue</button>
        <?php endif; ?>

      </div>

    </form>

</section>
</main>

<script>
// Client-side helpers: prevent past dates, enforce 1-month minimum, and disable slip sizes smaller than selected boat
;(function(){
  const start = document.querySelector('input[name="start_date"]');
  const end = document.querySelector('input[name="end_date"]');
  const boatSelect = document.querySelector('select[name="boat_id"]');
  const slipSelect = document.querySelector('select[name="slip_size"]');
  if(!start || !end || !boatSelect || !slipSelect) return;

  const today = new Date();
  const isoToday = today.toISOString().slice(0,10);
  start.min = isoToday;
  // if start has no value, set to today for convenience
  if(!start.value) start.value = isoToday;

  // If the page was reloaded, reset the form state (dates, slip size, boat selection)
  try {
    let navType = null;
    if (performance.getEntriesByType) {
      const entries = performance.getEntriesByType('navigation');
      if (entries && entries[0] && entries[0].type) navType = entries[0].type;
    }
    // older API
    if (!navType && performance.navigation) navType = performance.navigation.type === 1 ? 'reload' : 'navigate';
    if (navType === 'reload') {
      start.value = isoToday;
      end.value = '';
      slipSelect.value = '';
      boatSelect.value = '';
      if(addBoatPanel) addBoatPanel.style.display = 'none';
      if(newBoatName) newBoatName.value = '';
      if(newBoatLength) newBoatLength.value = '';
      computeCost();
    }
  } catch(e) {}

  function setEndMinFromStart(){
    if(!start.value) return;
    const s = new Date(start.value);
    const minEnd = new Date(s);
    // add one month: preserve day where possible
    minEnd.setMonth(minEnd.getMonth()+1);
    end.min = minEnd.toISOString().slice(0,10);
    if(!end.value || new Date(end.value) < minEnd) end.value = end.min;
  }
  setEndMinFromStart();
  start.addEventListener('change', setEndMinFromStart);
  
  // references for add-boat controls (used by cost/enforcement functions)
  const addBoatPanel = document.getElementById('addBoatPanel');
  const newBoatName = document.getElementById('newBoatName');
  const newBoatLength = document.getElementById('newBoatLength');
  const saveNewBoat = document.getElementById('saveNewBoat');
  const addBoatBtn = document.getElementById('addBoatBtn');

  function updateAddBoatVisibility(){
    if(!addBoatBtn) return;
    const isAddNew = boatSelect && boatSelect.value === 'add_new';
    const nameFilled = newBoatName && newBoatName.value.trim() !== '';
    const lenFilled = newBoatLength && newBoatLength.value.trim() !== '';
    const show = isAddNew && nameFilled && lenFilled;
    addBoatBtn.style.display = show ? '' : 'none';
    addBoatBtn.disabled = !show;
  }
  if(boatSelect) boatSelect.addEventListener('change', updateAddBoatVisibility);
  if(newBoatName) newBoatName.addEventListener('input', updateAddBoatVisibility);
  if(newBoatLength) newBoatLength.addEventListener('input', updateAddBoatVisibility);
  // set initial visibility based on current form values
  updateAddBoatVisibility();
  // compute and display estimated cost
  const PRICE_PER_FOOT = 10.50;
  const HOOKUP_PER_MONTH = 10.50;

  function computeEstimatedMonths(sDate, eDate){
    if(!sDate || !eDate) return 1;
    const msPerDay = 1000*60*60*24;
    const days = Math.ceil((eDate - sDate) / msPerDay);
    const months = Math.max(1, Math.ceil(days / 30));
    return months;
  }

  function getSelectedBoatLength(){
    const opt = boatSelect.options[boatSelect.selectedIndex];
    if(!opt) return 0;
    if(opt.value === 'add_new'){
      const v = (newBoatLength || {}).value || '';
      return v && !isNaN(v) ? parseInt(v,10) : 0;
    }
    // prefer data-length attribute
    const dl = opt.getAttribute('data-length');
    if(dl) return parseInt(dl,10) || 0;
    // fallback: parse text like 'Name (34 ft)'
    const m = opt.textContent.match(/\((\d+)\s*ft\)/);
    if(m) return parseInt(m[1],10) || 0;
    return 0;
  }

  function computeCost(){
    const sVal = start.value;
    const eVal = end.value;
    if(!sVal || !eVal) {
      document.getElementById('estimatedCostInput').value = '';
      // clear breakdown displays
      if (document.getElementById('reservationBaseDisplay')) document.getElementById('reservationBaseDisplay').textContent = '$0.00';
      if (document.getElementById('reservationHookupDisplay')) document.getElementById('reservationHookupDisplay').textContent = '$0.00';
      if (document.getElementById('reservationMonthsDisplay')) document.getElementById('reservationMonthsDisplay').textContent = '0';
      if (document.getElementById('reservationLengthDisplay')) document.getElementById('reservationLengthDisplay').textContent = '0';
      if (document.getElementById('reservationCostTotal')) document.getElementById('reservationCostTotal').textContent = '$0.00';
      return;
    }
    const sDate = new Date(sVal);
    const eDate = new Date(eVal);
    const months = computeEstimatedMonths(sDate, eDate);
    const boatLen = getSelectedBoatLength() || 0;
    const baseMonthly = boatLen * PRICE_PER_FOOT;
    const hookupMonthly = HOOKUP_PER_MONTH;
    const monthlySum = baseMonthly + hookupMonthly;
    const monthsTotal = monthlySum * months;
    // update formula displays
    if (document.getElementById('reservationBaseMonthlyDisplay')) document.getElementById('reservationBaseMonthlyDisplay').textContent = '$' + baseMonthly.toFixed(2);
    if (document.getElementById('reservationHookupMonthlyDisplay')) document.getElementById('reservationHookupMonthlyDisplay').textContent = '$' + hookupMonthly.toFixed(2);
    if (document.getElementById('reservationMonthlySum')) document.getElementById('reservationMonthlySum').textContent = '$' + monthlySum.toFixed(2);
    if (document.getElementById('reservationMonthsDisplay')) document.getElementById('reservationMonthsDisplay').textContent = months;
    if (document.getElementById('reservationMonthsTotal')) document.getElementById('reservationMonthsTotal').textContent = '$' + monthsTotal.toFixed(2);
    if (document.getElementById('reservationLengthDisplay')) document.getElementById('reservationLengthDisplay').textContent = boatLen;
    if (document.getElementById('reservationCostTotalFinal')) document.getElementById('reservationCostTotalFinal').textContent = '$' + monthsTotal.toFixed(2);
    document.getElementById('estimatedCostInput').value = monthsTotal.toFixed(2);
    document.getElementById('estimatedBaseInput').value = (baseMonthly * months).toFixed(2);
    document.getElementById('estimatedHookupInput').value = (hookupMonthly * months).toFixed(2);
    document.getElementById('estimatedMonthsInput').value = months;
  }

  // update cost when relevant inputs change
  start.addEventListener('change', computeCost);
  end.addEventListener('change', computeCost);
  slipSelect.addEventListener('change', computeCost);
  if(newBoatLength) newBoatLength.addEventListener('input', function(){ enforceSlipForBoat(); computeCost(); });

  // Disable slip options smaller than selected boat length (if available)
  function enforceSlipForBoat(){
    if(!boatSelect || !slipSelect) return;
    const optBoat = boatSelect.options[boatSelect.selectedIndex];
    // parse length from option text (e.g. "Name (34 ft)") or data-length
    let boatLen = 0;
    if(optBoat && optBoat.textContent){
      const m = optBoat.textContent.match(/\((\d+)\s*ft\)/);
      if(m) boatLen = parseInt(m[1],10);
      // if user selected 'add_new' use the newBoatLength input value when present
      if(optBoat.value === 'add_new'){
        const v = (newBoatLength || {}).value || '';
        if(v && !isNaN(v)) boatLen = parseInt(v,10);
      }
    }
    // disable slip options smaller than boatLen
    Array.from(slipSelect.options).forEach(o=>{
      const val = parseInt(o.value,10) || 0;
      if(val>0 && boatLen>0 && val < boatLen) o.disabled = true; else o.disabled = false;
    });
    // auto-select the smallest slip option that fits the boat (next-largest)
    if(boatLen > 0){
      const candidates = Array.from(slipSelect.options)
        .map(o=>({opt:o, val: parseInt(o.value,10) || 0}))
        .filter(x => x.val > 0 && !x.opt.disabled)
        .sort((a,b)=>a.val - b.val);
      let chosen = null;
      for(const c of candidates){
        if(c.val >= boatLen){ chosen = c.opt; break; }
      }
      if(chosen){
        slipSelect.value = chosen.value;
      } else {
        // no fitting slip size available in options -> clear selection
        slipSelect.value = '';
      }
    }
    // update cost whenever slip/boat constraints change
    try{ computeCost(); }catch(e){}
  }
  if(boatSelect) {
    boatSelect.addEventListener('change', enforceSlipForBoat);
    // initial run
    enforceSlipForBoat();
  }
  // show/hide add-boat panel
  if(boatSelect && addBoatPanel){
    function toggleAddBoat(){
      if(boatSelect.value === 'add_new'){
        addBoatPanel.style.display = 'block';
      } else {
        addBoatPanel.style.display = 'none';
      }
      enforceSlipForBoat();
      computeCost();
    }
    boatSelect.addEventListener('change', toggleAddBoat);
    toggleAddBoat();
  }

  // intercept confirm to optionally save new boat
  const confirmBtn = document.getElementById('confirmBtn');
  if(confirmBtn){
    confirmBtn.addEventListener('click', function(e){
      if(boatSelect && boatSelect.value === 'add_new'){
        const name = newBoatName ? newBoatName.value.trim() : '';
        const len = newBoatLength ? newBoatLength.value.trim() : '';
        if(!name || !len){
          e.preventDefault();
          alert('Please provide new boat name and length before confirming.');
          return;
        }
        const save = confirm('Save this new boat to your profile? Click OK to save, Cancel to skip.');
        saveNewBoat.value = save ? '1' : '0';
        // allow form submit to proceed
      }
    });
  }
    // initial cost compute
    computeCost();
})();
</script>

</body>
</html>