<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: slip_reservation.php
Purpose: Slip reservation flow — select dates and boat, check availability, and choose a slip.
This documentation is non-executing and safe; it does not change behavior or output.
*/
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

// Lightweight DB error logger (appends to db_errors.txt in project dir)
if (!function_exists('log_db_error')) {
  function log_db_error($msg){
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'db_errors.txt';
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
  }
}

// Determine login state and form attributes early to avoid undefined variable warnings
$loggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['username']);
$requireLogin = !$loggedIn;
$formDisabledAttr = $requireLogin ? 'disabled' : '';

//REQUIRE USER LOGIN: Redirects to login page if no active session exists.
if (!isset($_SESSION['user_id'])) {
  $current = $_SERVER['REQUEST_URI'] ?? '/slip_reservation.php';
  header("Location: BlueTeam_LoginPage.php?redirect=" . urlencode($current) . "&login_notice=1");
  exit();
}

$userID = $_SESSION['user_id'] ?? null;

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

    // If end date was not provided, default to start + 30 days to reduce user errors
    if (empty($endDate) && !empty($startDate)) {
      $sdTmp = DateTime::createFromFormat('Y-m-d', $startDate);
      if ($sdTmp) {
        $sdTmp->modify('+30 days');
        $endDate = $sdTmp->format('Y-m-d');
      }
    }

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
        // one "month" is defined as 30 days
        $minEnd = (clone $sd)->modify('+30 days');
        if ($ed < $minEnd) {
          $error = "Reservations must be at least one month (30 days) long.";
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
        try {
            // Determine the boat length to ensure we only return slips that fit the boat
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

            // The required slip size must fit both the requested slip size and the boat length
            $requiredSize = max(intval($slipSizeNum), intval($boatLength));

            $sql = "
                SELECT COUNT(*)
                FROM slips s
                WHERE s.slip_size >= :required_size
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
                ':required_size' => $requiredSize,
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
                  // calculate days difference and convert to 30-day months
                  $days = max(0, (int)ceil(($ed->getTimestamp() - $sd->getTimestamp()) / 86400));
                  $months = max(1, (int)ceil($days / 30));
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
                  $sizeFilter = $requiredSize;
                  $sstmt = $pdo->prepare("SELECT slip_ID, slip_name, slip_size, slip_status, location_code FROM slips WHERE slip_size >= :sizeFilter ORDER BY slip_size, slip_ID");
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
                  // log map errors
                  log_db_error('Slip-map error: ' . $e->getMessage() . ' POST: ' . json_encode($_POST));
                }
            } else {
                $error = "No slips available for those dates.";
            }
        } catch (Exception $e) {
            // log the exception and provide a generic error message
            log_db_error('Availability error: ' . $e->getMessage() . ' POST: ' . json_encode($_POST));
            $error = "Availability check failed. Please try again.";
        }
    }
}

          // If this was an AJAX request, return JSON (used by the client-side availability check)
          if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
              'error' => $error ?: null,
              'availableCount' => $availableCount ?? 0,
              'availableSlips' => $availableSlips,
              'estimated' => [
                'cost' => $availabilityCost,
                'base' => $availabilityBase,
                'hookup' => $availabilityHookup,
                'months' => $availabilityMonths
              ]
            ]);
            exit();
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
    /* Use an explicit two-column grid so we can place controls precisely */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
    /* Force specific placement so Select Boat and Slip Size share the same row */
    .form-group.start-date{grid-column:1;grid-row:1}
    .form-group.end-date{grid-column:2;grid-row:1}
    .form-group.select-boat{grid-column:1;grid-row:2}
    .form-group.slip-size{grid-column:2;grid-row:2}
    /* Responsive: collapse to single column on small screens */
    @media (max-width:600px){
      .form-grid{grid-template-columns:1fr}
      .form-group.start-date,.form-group.end-date,.form-group.select-boat,.form-group.slip-size{grid-column:auto}
    }
    .form-group{display:flex;flex-direction:column;gap:.5rem;align-self:start}
    /* Ensure select controls align exactly across columns */
    .form-grid > .form-group { align-self: start; }
    .form-grid > .form-group select { margin-top: 0; }

    /* Force top-alignment and identical control heights for the two dropdowns */
    .form-grid > .form-group.select-boat,
    .form-grid > .form-group.slip-size {
      align-self: start !important;
      display: flex !important;
      flex-direction: column !important;
      justify-content: flex-start !important;
    }
    .form-grid > .form-group.select-boat label,
    .form-grid > .form-group.slip-size label {
      margin: 0 0 6px 0 !important;
      line-height: 1.15 !important;
      display: block !important;
    }
    .form-grid > .form-group.select-boat select,
    .form-grid > .form-group.slip-size select,
    .form-grid > .form-group.start-date input[type="date"],
    .form-grid > .form-group.end-date input[type="date"] {
      margin-top: 0 !important;
      height: 38px !important;
      box-sizing: border-box !important;
      padding-top: 6px !important;
      padding-bottom: 6px !important;
      vertical-align: middle !important;
    }

    /* Enlarge and style the primary action button (gold) and the secondary (ocean blue) */
    .form-actions .btn-primary {
      padding: 12px 22px !important;
      font-size: 1.05rem !important;
      min-width: 200px !important;
      border-radius: 8px !important;
      font-weight: 700 !important;
      background: #F4C26B !important; /* Sunset Gold (exact provided) */
      color: #1F2F45 !important; /* Navy text for contrast (exact provided) */
      border: none !important;
      box-shadow: 0 6px 18px rgba(244,194,107,0.12) !important;
      cursor: pointer !important;
      display: inline-block !important;
      text-align: center !important;
      line-height: 1 !important;
    }
    .form-actions .btn-primary:hover { filter: brightness(0.95); }
    .form-actions .btn-primary:active { transform: translateY(1px); }

    /* Add Boat (and other secondary) buttons stay ocean-blue but share sizing and radius */
    .form-actions .btn-secondary {
      padding: 12px 20px !important;
      font-size: 1.02rem !important;
      min-width: 160px !important;
      border-radius: 8px !important;
      font-weight: 700 !important;
      background: #3F87A6 !important; /* Ocean Blue (exact provided) */
      color: #fff !important;
      border: none !important;
      box-shadow: 0 6px 18px rgba(63,135,166,0.12) !important;
      cursor: pointer !important;
      display: inline-block !important;
      text-align: center !important;
      line-height: 1 !important;
    }
    .form-actions .btn-secondary:hover { filter: brightness(0.95); }
    .form-actions .btn-secondary:active { transform: translateY(1px); }
    /* Utility: hide buttons until explicitly shown by JS */
    .form-actions .btn-secondary.hidden { display: none !important; }
    .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select{width:100%;box-sizing:border-box;padding:.45rem;border:1px solid #ccc;border-radius:4px}
    /* Add-boat panel should span full width and align fields neatly
       Increase vertical spacing below the 'Add New Boat' label and
       make the spacing between the name and length fields consistent. */
    .form-group.add-boat{
      grid-column:1/-1;
      display:grid;
      grid-template-columns:1fr 140px;
      gap:20px; /* horizontal + vertical gap between grid items */
      align-items:center;
      margin-top:1.25rem;
    }
    .form-group.add-boat > label{
      grid-column:1/-1;
      margin-bottom:12px; /* increased spacing below the label */
      margin-top:1.5rem;
    }
    .form-group.add-boat input[type="text"]{grid-column:1}
    .form-group.add-boat input[type="number"]{grid-column:2}
    .form-group.add-boat input[type="hidden"]{grid-column:1/-1}
    @media(max-width:600px){
      .form-group.add-boat{grid-template-columns:1fr;gap:12px}
      .form-group.add-boat input[type="number"]{grid-column:1}
    }
    .form-actions{display:flex;gap:.5rem;margin-top:3rem;clear:both}
    /* Reservation card visuals (match MyAccount card style) */
    /* Reservation card: fluid up to 1000px, centered, subtle shadow to match project cards
       (keep styles simple and avoid aggressive overrides) */
    .reservation-card{
      /* fixed 1000px width on desktop to match request */
      width:1000px;
      max-width:100%;
      margin:28px auto;
      box-sizing:border-box;
      background:#fff;
      border-radius:12px;
      padding:28px 36px 9px; /* reduced bottom padding to remove extra space */
      border:1px solid rgba(0,0,0,0.04);
      box-shadow:0 6px 18px rgba(31,47,69,0.06);
      position:relative; /* ensure absolute children are positioned to the card */
    }
    /* Place the action button inside the reservation card bottom-center */
    .reservation-card{position:relative}
    /* force the action button to sit pinned to the bottom-center of the card */
    /* ensure the nearest positioned ancestor is the form inside the card
       in case other page CSS resets the section positioning */
    .reservation-card .reservation-form{position:relative}
    .reservation-card .reservation-card-actions{
      position:absolute;
      left:50%;
      transform:translateX(-50%);
      bottom:20px;
      display:flex;
      justify-content:center;
      gap:8px;
      z-index:9999;
      width:auto;
    }
    .reservation-card .reservation-card-actions .btn-primary{margin:0}
    .reservation-card h3{margin-top:0;margin-bottom:8px}
    /* Cost area styling */
    .reservation-cost{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:8px;grid-column:1/-1;width:100%;box-sizing:border-box;position:relative;z-index:3;margin-bottom:0 !important}
    .reservation-cost label{font-weight:700;margin-top:15px;margin-bottom:6px;display:block;text-align:center;width:100%}
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
    /* Place the inline action directly under the Total Cost in normal flow
       so it cannot cover text; keep centered and use project button styles */
    .reservation-card > .reservation-card-actions-inline{
      position:static !important;
      display:flex;
      flex-direction:column; /* stack buttons vertically so Add Boat sits below Availability */
      justify-content:center;
      gap:0; /* no vertical gap when only one button present */
      margin-top:80px !important; /* reduced by 4px */
      margin-bottom:15px !important; /* reduced vertical space below actions */
      z-index:2;
      pointer-events:auto;
      align-items:center;
    }
    /* Prevent action buttons from stretching to full card width on this page
       Cover button element and primary/secondary classes explicitly. */
    .reservation-card > .reservation-card-actions-inline button,
    .reservation-card > .reservation-card-actions-inline .btn-primary,
    .reservation-card > .reservation-card-actions-inline .btn-secondary,
    .reservation-card .reservation-card-actions button,
    .reservation-card .reservation-card-actions .btn-primary,
    .reservation-card .reservation-card-actions .btn-secondary {
      width: auto !important;
      display: inline-block !important;
      min-width: 0 !important;
      max-width: none !important; /* allow button to size to content */
      box-sizing: border-box !important;
      white-space: nowrap !important;
      padding: 12px 25px !important; /* horizontal padding fixed to 25px each side */
    }
    @media (max-width:480px){
      .reservation-card > .reservation-card-actions-inline button,
      .reservation-card > .reservation-card-actions-inline .btn-primary,
      .reservation-card > .reservation-card-actions-inline .btn-secondary { min-width:72px !important; padding:10px 18px !important; max-width: none !important; }
    }
    /* When the Add Boat button is visible, apply vertical spacing (15px requested) */
    .reservation-card > .reservation-card-actions-inline.has-add { gap:15px; }
    /* Pull error/notice messages up slightly to reduce space above them (page-scoped) */
    .reservation-card .alert.alert-error { margin-top: -20px !important; }
    /* Remove focus outlines from buttons on this page only (keeps global focus behavior elsewhere) */
    .reservation-frame .btn, .reservation-frame .form-actions .btn {
      outline: none !important;
      box-shadow: none !important;
    }
    .reservation-frame .btn:focus, .reservation-frame .btn:active, .reservation-frame .btn:focus-visible {
      outline: none !important;
      box-shadow: none !important;
    }
    /* Remove outlines for primary/secondary action buttons on this page specifically */
    .reservation-card .btn-primary:focus,
    .reservation-card .btn-primary:active,
    .reservation-card .btn-primary:focus-visible,
    .reservation-card .btn-secondary:focus,
    .reservation-card .btn-secondary:active,
    .reservation-card .btn-secondary:focus-visible {
      outline: none !important;
      box-shadow: none !important;
    }
    /* Ensure the Add Boat button stays hidden until JS removes the 'hidden' class */
    .reservation-card > .reservation-card-actions-inline .hidden{display:none !important}
  </style>
  <style>
    /* Reservation step tracker used inside the notice bar (page-local) */
    .reservation-steps{display:flex;flex-direction:column;align-items:center}
    .reservation-steps .steps-row{display:flex;align-items:center;width:100%;max-width:980px;margin:0 auto;padding:0 12px;box-sizing:border-box}
    .reservation-steps .step{display:flex;flex-direction:column;align-items:center;flex:0 0 auto}
    .reservation-steps .step .step-circle{width:40px;height:40px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:var(--navy);background:#e6eef4}
    /* Active step: inverted / cream palette to stand out against ocean background */
    .reservation-steps .step.active .step-circle{
      background:#F2E6C9 !important; /* cream */
      color:var(--navy) !important;
      border:2px solid rgba(31,47,69,0.06) !important;
      box-shadow:0 8px 22px rgba(31,47,69,0.12) !important;
    }
    .reservation-steps .step .step-label{display:block;font-size:13px;color:var(--boat-white);margin-top:8px;text-align:center}
    /* Connector line between steps is a flexible item so circles spread evenly */
    .reservation-steps .connector{flex:1;height:2px;background:#dbe5ea;margin:0 12px}
    @media(max-width:520px){
      .reservation-steps .steps-row{padding:0 8px}
      .reservation-steps .step .step-label{font-size:12px}
      .reservation-steps .step .step-circle{width:34px;height:34px}
      .reservation-steps .connector{margin:0 8px}
    }

    /* Notice wrapper and notice bar — match BlueTeam_LoginPage.php behavior */
    /* force overlap hero by 40px and center to match reservation-card width */
    .notice-wrap{display:flex;justify-content:center;margin-top:-40px !important;position:relative;z-index:9999 !important;padding:0 26px;margin-bottom:-54px}
    /* inside the reservation-frame the notice should be fluid (100% of frame) up to 1000px */
    /* reservation-frame: align with hero inner padding so notice and card edges match */
    /* Force the reservation frame to a fixed centered column so the notice
       and reservation card always share the same visible width. This is
       intentionally page-scoped and uses !important to override globals. */
    .reservation-frame{
      max-width:1000px !important;
      width:100% !important;
      margin:0 auto !important;
      box-sizing:border-box !important;
      padding-left:0 !important;
      padding-right:0 !important;
      padding-top:0 !important;
      padding-bottom:0 !important;
    }
    /* Ensure the inner wrapper does not add horizontal padding which would
       make the visible elements exceed 1000px. */
    .reservation-frame > .notice-wrap { padding-left:0 !important; padding-right:0 !important; box-sizing:border-box !important; }
    .notice{background:var(--ocean) !important;color:var(--boat-white) !important;padding:18px 28px !important;border-radius:12px !important;width:100% !important;box-sizing:border-box !important;text-align:center !important;box-shadow:0 8px 28px rgba(31,47,69,0.12) !important;margin:0 auto !important;position:relative !important;z-index:10000 !important}

    /* Force exact matching outer widths and centering for the notice.
       Use page-specific selector to override any global rules. */
    /* Constrain the notice and main content inside a centered 1000px inner column
       while the outer frame matches the hero's horizontal padding. This keeps
       the notice visually aligned with the reservation card edges. */
    /* Constrain inner elements to the same 1000px centered column */
    .reservation-frame > .notice-wrap,
    .reservation-frame > main,
    .reservation-frame > .reservation-card {
      max-width:1000px !important;
      width:100% !important;
      margin-left:auto !important;
      margin-right:auto !important;
      box-sizing:border-box !important;
      padding-left:0 !important;
      padding-right:0 !important;
    }
    .reservation-frame > .notice-wrap > .notice {
      /* make notice fill the 1000px frame exactly */
      max-width:1000px !important;
      width:1000px !important;
      margin-left:auto !important;
      margin-right:auto !important;
      box-sizing:border-box !important;
    }
    /* force the reservation card to exactly 1000px on wide viewports */
    .reservation-frame > main > .reservation-card,
    .reservation-frame > .reservation-card,
    .reservation-card {
      max-width:1000px !important;
      width:1000px !important;
      margin-left:auto !important;
      margin-right:auto !important;
      box-sizing:border-box !important;
    }

     /* keep the reservation frame's outer padding matched to hero; inner
       content is centered to 1000px so edges align exactly */

    /* page-reservation uses default page spacing and no forced framing */
    /* ensure hero sits behind the notice (avoid stacking context surprises) */
    .hero{position:relative;z-index:1}
     /* Page-local override: match hero inner width to reservation content so
       the hero, notice, and reservation card align exactly on this page. */
     .site-hero .site-hero-inner { max-width: 1000px !important; padding-left: var(--hero-inner-padding) !important; padding-right: var(--hero-inner-padding) !important; }
    @media (max-width:900px){
      .notice-wrap{margin-top:18px !important;padding:0 16px}
      .notice{max-width:100% !important;padding:12px 16px}
    }
    @media (max-width:900px){
      .notice-wrap{margin-top:18px !important;padding:0 16px}
      .notice{max-width:100%;padding:12px 16px}
    }
    /* On small screens the reservation card should be fluid */
    @media (max-width:1024px){
      .reservation-card{ width:100% !important; padding:20px !important; }
      .reservation-frame{ padding:0 12px !important; }
    }
    /* Override global page container so no extra framed box appears behind the card */
    .page-reservation .reservation-page {
      background: transparent !important;
      box-shadow: none !important;
      border-radius: 0 !important;
    }
    /* Additional page-scoped safety: ensure no parent/sibling creates a framed panel
       that visually sits behind the reservation card. Only affects this page. */
    .page-reservation > *:not(.site-hero):not(.topbar) {
      background: transparent !important;
      box-shadow: none !important;
      border-radius: 0 !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
    }
    /* Make sure the frame that contains the notice/card is transparent
       while keeping the card itself styled as intended. */
    .page-reservation .reservation-frame {
      background: transparent !important;
      box-shadow: none !important;
      border-radius: 0 !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
    }
  </style>
</head>

<body class="page-reservation">
<?php include 'nav.php'; ?>

<?php
  $hero_title = 'Slip Reservation';
  $hero_subtitle = 'Reserve your slip at Moffat Bay';
  $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 10.189V14"/><path d="M12 2v3"/><path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/><path d="M19.38 20A11.6 11.6 0 0 0 21 14l-8.188-3.639a2 2 0 0 0-1.624 0L3 14a11.6 11.6 0 0 0 2.81 7.76"/><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1s1.2 1 2.5 1c2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/></svg>';
  $hero_classes = 'hero-reservation';
  include 'hero.php';
?>

  <!-- Page-level overrides removed to preserve consistent nav/footer sizing. -->

<!-- Frame to align notice and reservation card exactly (fluid up to 1000px) -->
<div class="reservation-frame">
  <div class="notice-wrap">
    <div class="notice">
      <div class="reservation-steps">
        <div class="steps-row">
          <div class="step active">
            <span class="step-circle">1</span>
            <div class="step-label">Dates &amp; Boat</div>
          </div>
          <div class="connector" aria-hidden="true"></div>
          <div class="step">
            <span class="step-circle">2</span>
            <div class="step-label">Select Slip</div>
          </div>
          <div class="connector" aria-hidden="true"></div>
          <div class="step">
            <span class="step-circle">3</span>
            <div class="step-label">Review &amp; Confirm</div>
          </div>
        </div>
      </div>
    </div>
  </div>
<main class="reservation-page">
<section class="reservation-card" style="position:relative;padding-bottom:9px;">

<?php if ($error): ?>
  <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if ($boatSuccess): ?>
  <div class="alert alert-success"><?= $boatSuccess ?></div>
<?php endif; ?>

<?php if ($availabilitySuccess): ?>
  <div class="alert alert-success"><?= $availabilitySuccess ?></div>
<?php endif; ?>

<?php /* Removed duplicate Estimated Total alert — use the reservation-cost card only */ ?>

<!-- Slip availability map will be rendered inside the form so selections submit correctly -->

    <!-- notice moved above the card to overlap hero (see top of file) -->

    <form id="reservationForm" method="POST" class="reservation-form">

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

        <div class="form-group slip-size">
          <label>Slip Size</label>
          <select name="slip_size" required>
            <option value="">Select size</option>
            <option value="26" <?= ($slipSize == "26") ? "selected" : "" ?>>26 ft</option>
            <option value="40" <?= ($slipSize == "40") ? "selected" : "" ?>>40 ft</option>
            <option value="50" <?= ($slipSize == "50") ? "selected" : "" ?>>50 ft</option>
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
        <label>Reservation Cost</label>
        <div id="reservationFormula" class="form-note-inline" style="margin-top:.25rem;font-size:.95rem;display:flex;flex-direction:column;gap:.5rem;text-align:center;">
          <div>Slip cost (per month): (<strong>$10.50</strong> × <span id="reservationLengthDisplay">0</span> ft) = <strong id="reservationBaseMonthlyDisplay">$0.00</strong></div>
          <div>Electric Hookup (per month): <strong id="reservationHookupMonthlyDisplay">$10.50</strong></div>
          <div>Months duration: <span id="reservationMonthsDisplay">0</span> mo</div>
          <div id="reservationTotalLine" style="font-weight:700;margin-top:.25rem">Total Cost: <span id="reservationCostTotalFinal">$0.00</span></div>
        </div>
        <input type="hidden" name="estimated_cost" id="estimatedCostInput" value="">
        <input type="hidden" name="estimated_base" id="estimatedBaseInput" value="">
        <input type="hidden" name="estimated_hookup" id="estimatedHookupInput" value="">
        <!-- Check Availability button placed directly under the Total Cost inside the card -->
        <div class="reservation-card-actions-inline" style="display:flex;flex-direction:column;justify-content:center;align-items:center;margin-top:82px;margin-bottom:15px;gap:0;">
          <button type="submit" name="check_availability" class="btn-primary" style="background:#F2C36A;color:#0f2540;border:1px solid rgba(31,47,69,0.9);padding:12px 37.5px;border-radius:8px;box-shadow:0 4px 12px rgba(31,47,69,0.08);font-weight:800;letter-spacing:0.3px;line-height:1.1;font-size:16px;width:auto !important;max-width:none !important;display:inline-block !important;">Check Availability</button>
          <button type="submit" id="addBoatBtn" name="add_boat" class="btn-secondary hidden" style="display:none;padding:12px 25px;border-radius:8px;font-weight:700;margin-top:15px;width:auto !important;max-width:160px !important;display:inline-block !important;">Add Boat</button>
        </div>
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
              <!-- Marina slip map (visual reference) -->
              <div class="slip-map" id="availableSlipMap" style="margin-top:.75rem;">
                <div class="slip-grid" id="slipGrid">
                  <?php foreach ($availableSlips as $s):
                    $isAvail = !empty($s['available']);
                    $sid = htmlspecialchars($s['id']);
                    $loc = htmlspecialchars($s['location_code'] ?? ($s['name'] ?? ''));
                  ?>
                    <div class="slip <?= $isAvail ? 'available' : 'unavailable' ?>" data-slip-id="<?= $sid ?>" role="button" tabindex="0" aria-pressed="false">
                      <div class="meta"><?= $loc ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
      <?php endif; ?>

      <div class="form-actions">

        <!-- Confirm stays in form-actions when available -->
        <?php if ($availabilitySuccess): ?>
          <button id="confirmBtn" type="submit" name="confirm_reservation" class="btn-secondary">Confirm & Continue</button>
        <?php endif; ?>

      </div>

    <!-- Move actions outside the form so it's absolutely positioned relative to the card -->
</form>

    <!-- external actions removed: button now lives inside the reservation-cost block -->

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
  
  // (Page reload clearing is handled later after all controls are bound)

  function setEndMinFromStart(){
    if(!start.value) return;
    const s = new Date(start.value);
    const minEnd = new Date(s);
    // add 30 days (one month defined as 30 days)
    minEnd.setDate(minEnd.getDate() + 30);
    end.min = minEnd.toISOString().slice(0,10);
    // Automatically select end date one month (30 days) from the chosen start date.
    end.value = end.min;
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
    if (addBoatBtn) {
      addBoatBtn.classList.toggle('hidden', !show);
      addBoatBtn.disabled = !show;
      // also control inline style to ensure hidden state in case of CSS conflicts
      try { addBoatBtn.style.display = show ? '' : 'none'; } catch(e){}
    }
    // toggle container class so spacing is applied only when Add Boat is visible
    try {
      const actionsInline = document.querySelector('.reservation-card > .reservation-card-actions-inline');
      if (actionsInline) actionsInline.classList.toggle('has-add', !!show);
    } catch(e){}
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

// Ensure add-boat inputs clear on full page reload: run after DOM is ready and controls exist
document.addEventListener('DOMContentLoaded', function(){
  try{
    var navType = null;
    if (performance.getEntriesByType){
      var entries = performance.getEntriesByType('navigation');
      if (entries && entries[0] && entries[0].type) navType = entries[0].type;
    }
    if (!navType && performance.navigation) navType = performance.navigation.type === 1 ? 'reload' : 'navigate';
    if (navType === 'reload' || navType === 'back_forward'){
      // clear form fields to ensure a fresh reservation on reload
      var start = document.querySelector('input[name="start_date"]');
      var end = document.querySelector('input[name="end_date"]');
      var boatSelect = document.querySelector('select[name="boat_id"]');
      var slipSelect = document.querySelector('select[name="slip_size"]');
      var addBoatPanel = document.getElementById('addBoatPanel');
      var newBoatName = document.getElementById('newBoatName');
      var newBoatLength = document.getElementById('newBoatLength');
      var selectedSlip = document.getElementById('selected_slip_id');
      var availableWrapper = document.getElementById('availableSlipWrapper');
      var availableMap = document.getElementById('availableSlipMap');
      var slipGrid = document.getElementById('slipGrid');
      var confirmBtn = document.getElementById('confirmBtn');
      // inputs
      if(start) start.value = '';
      if(end) end.value = '';
      // selects: clear selection and remove any selected attributes
      if(boatSelect){ boatSelect.value = ''; Array.from(boatSelect.options||[]).forEach(function(o){ o.selected = false; }); }
      if(slipSelect){ slipSelect.value = ''; Array.from(slipSelect.options||[]).forEach(function(o){ o.selected = false; }); }
      if(selectedSlip){ selectedSlip.value = ''; Array.from(selectedSlip.options||[]).forEach(function(o){ o.selected = false; }); }
      // add-boat panel and inputs
      if(addBoatPanel) addBoatPanel.style.display = 'none';
      if(newBoatName) newBoatName.value = '';
      if(newBoatLength) newBoatLength.value = '';
      // remove any availability UI rendered by previous checks
      if(availableWrapper) availableWrapper.remove();
      if(availableMap) availableMap.remove();
      if(slipGrid) slipGrid.innerHTML = '';
      // hide confirm button until availability is checked again
      if(confirmBtn) confirmBtn.style.display = 'none';
      // clear estimated inputs and displays
      try{ var estCost = document.getElementById('estimatedCostInput'); if(estCost) estCost.value = ''; }catch(e){}
      try{ var estBase = document.getElementById('estimatedBaseInput'); if(estBase) estBase.value = ''; }catch(e){}
      try{ var estHook = document.getElementById('estimatedHookupInput'); if(estHook) estHook.value = ''; }catch(e){}
      try{ var estMonths = document.getElementById('estimatedMonthsInput'); if(estMonths) estMonths.value = ''; }catch(e){}
      try{ var disp = document.getElementById('reservationCostTotalFinal'); if(disp) disp.textContent = '$0.00'; }catch(e){}
      try{ var lenDisp = document.getElementById('reservationLengthDisplay'); if(lenDisp) lenDisp.textContent = '0'; }catch(e){}
      try{ var monthsDisp = document.getElementById('reservationMonthsDisplay'); if(monthsDisp) monthsDisp.textContent = '0'; }catch(e){}
    }
  }catch(e){ /* ignore */ }
});

// When the page is restored from the back/forward cache (bfcache), trigger the same clearing logic
window.addEventListener('pageshow', function(e){
  if (e && e.persisted) {
    try { document.dispatchEvent(new Event('DOMContentLoaded')); } catch(err) { /* ignore */ }
  }
});

// AJAX availability check: intercept Check Availability and request JSON
(function(){
  const availBtn = document.querySelector('button[name="check_availability"]');
  const form = document.getElementById('reservationForm');
  if(!availBtn || !form) return;

  // Helper: set the active reservation step (1-based)
  function setFlowStep(n){
    try{
      const steps = document.querySelectorAll('.reservation-steps .step');
      const NAVY = '#1F2F45';
      const CREAM = '#F2E6C9';
      const BOAT_WHITE = '#F8F9FA';
      steps.forEach((el, idx) => {
        const isActive = (idx === (n-1));
        el.classList.toggle('active', isActive);
        // ensure visual fallback: update circle and label inline in case CSS specificity
        const circle = el.querySelector('.step-circle');
        const label = el.querySelector('.step-label');
        if(circle){
          if(isActive){
            circle.style.background = CREAM;
            circle.style.color = NAVY;
            circle.style.border = '2px solid rgba(31,47,69,0.06)';
            circle.style.boxShadow = '0 8px 22px rgba(31,47,69,0.12)';
          } else {
            circle.style.background = '';
            circle.style.color = '';
            circle.style.border = '';
            circle.style.boxShadow = '';
          }
        }
        if(label){
          label.style.color = isActive ? NAVY : BOAT_WHITE;
        }
      });
    }catch(e){/* ignore */}
  }

  availBtn.addEventListener('click', function(e){
    e.preventDefault();
    // update visual flow tracker to step 2 (Select Slip) as availability begins
    setFlowStep(2);
    // Ensure end date is set to start + 30 days if missing so server can search
    try{
      const startInput = form.querySelector('input[name="start_date"]');
      const endInput = form.querySelector('input[name="end_date"]');
      if(startInput && startInput.value && endInput && !endInput.value){
        const s = new Date(startInput.value);
        const minEnd = new Date(s);
        minEnd.setDate(minEnd.getDate() + 30);
        endInput.value = minEnd.toISOString().slice(0,10);
        // update min as well
        endInput.min = endInput.value;
        try{ computeCost(); }catch(e){}
      }
    }catch(err){}
    const fd = new FormData(form);
    // include the button value so server knows action
    fd.set('check_availability', '1');
    fetch(window.location.href, {method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'}})
      .then(r => r.json())
      .then(json => {
        // create or update message container
        let msg = document.getElementById('clientAvailabilityMsg');
        if(!msg){ msg = document.createElement('div'); msg.id = 'clientAvailabilityMsg'; form.parentNode.insertBefore(msg, form); }
        msg.innerHTML = '';
        if(json.error){
          msg.className = 'alert alert-error'; msg.textContent = json.error; return;
        }
        const cnt = json.availableCount || 0;
        if(cnt > 0){
          msg.className = 'alert alert-success';
          msg.textContent = 'Slips available: ' + cnt + '. Choose one from the list below.';
          // ensure tracker visually indicates step 2 (Select Slip)
          try{ setFlowStep(2); }catch(e){}
          // populate slip select
          let sel = document.getElementById('selected_slip_id');
          if(!sel){
            const wrapper = document.createElement('div'); wrapper.style.marginTop = '.75rem';
            wrapper.id = 'availableSlipWrapper';
            const label = document.createElement('label'); label.setAttribute('for','selected_slip_id'); label.textContent = 'Choose an available slip';
            sel = document.createElement('select'); sel.name = 'selected_slip_id'; sel.id = 'selected_slip_id'; sel.required = true;
            const opt0 = document.createElement('option'); opt0.value = ''; opt0.textContent = '-- Select a slip --'; sel.appendChild(opt0);
            wrapper.appendChild(label); wrapper.appendChild(sel);
            // insert before the form actions
            const actions = form.querySelector('.form-actions');
            if(actions) actions.parentNode.insertBefore(wrapper, actions);
          } else {
            // clear existing options
            sel.innerHTML = '<option value="">-- Select a slip --</option>';
          }
          // add options from json.availableSlips
          (json.availableSlips || []).forEach(s => {
            if(!s.available) return;
            const o = document.createElement('option'); o.value = s.id; o.textContent = (s.name || ('Slip ' + s.id)) + ' (' + (s.location_code || '') + ')';
            sel.appendChild(o);
          });
          // render slip map
          let grid = document.getElementById('slipGrid');
          if(!grid){
            const wrapper = document.createElement('div'); wrapper.className = 'slip-map'; wrapper.id = 'availableSlipMap';
            grid = document.createElement('div'); grid.className = 'slip-grid'; grid.id = 'slipGrid'; wrapper.appendChild(grid);
            const actions = form.querySelector('.form-actions');
            if(actions) actions.parentNode.insertBefore(wrapper, actions);
            else form.appendChild(wrapper);
          } else {
            grid.innerHTML = '';
          }
          (json.availableSlips || []).forEach(s => {
            const div = document.createElement('div');
            div.className = 'slip ' + (s.available ? 'available' : 'unavailable');
            div.setAttribute('data-slip-id', s.id);
            div.setAttribute('tabindex','0');
            div.innerHTML = '<div class="meta">'+(s.location_code||s.name||('Slip '+s.id))+'</div>';
            grid.appendChild(div);
          });
          // clicking a slip tile selects the option
          grid.querySelectorAll('.slip.available').forEach(el=>{
            el.addEventListener('click', function(){
              const id = this.getAttribute('data-slip-id');
              sel.value = id;
              sel.dispatchEvent(new Event('change'));
              // highlight
              grid.querySelectorAll('.slip').forEach(x=>x.classList.remove('selected'));
              this.classList.add('selected');
            });
            el.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); } });
          });
          // show confirm button if present
          const confirmBtn = document.getElementById('confirmBtn');
          if(confirmBtn) confirmBtn.style.display = '';
        } else {
          msg.className = 'alert alert-error'; msg.textContent = 'No slips available for those dates.';
          // revert flow tracker to step 1 when nothing found
          setFlowStep(1);
          // remove any previous slip select
          const wrapper = document.getElementById('availableSlipWrapper'); if(wrapper) wrapper.remove();
          const confirmBtn = document.getElementById('confirmBtn'); if(confirmBtn) confirmBtn.style.display = 'none';
        }
        // set estimated cost fields when provided
        if(json.estimated){
          try{
            document.getElementById('estimatedCostInput').value = json.estimated.cost || '';
            document.getElementById('estimatedBaseInput').value = json.estimated.base || '';
            document.getElementById('estimatedHookupInput').value = json.estimated.hookup || '';
            // update UI breakdown
            if(document.getElementById('reservationCostTotalFinal')) document.getElementById('reservationCostTotalFinal').textContent = '$' + (json.estimated.cost || 0).toFixed(2);
          }catch(e){}
        }
      })
      .catch(err => {
        alert('Availability check failed. Try again.');
        console.error(err);
      });
  });
  // Ensure end date is set when the form submits (covers non-AJAX submits too)
  form.addEventListener('submit', function(evt){
    try{
      const startInput = form.querySelector('input[name="start_date"]');
      const endInput = form.querySelector('input[name="end_date"]');
      if(startInput && startInput.value && endInput && !endInput.value){
        const s = new Date(startInput.value);
        const minEnd = new Date(s);
        minEnd.setDate(minEnd.getDate() + 30);
        endInput.value = minEnd.toISOString().slice(0,10);
        endInput.min = endInput.value;
        try{ computeCost(); }catch(e){}
      }
    }catch(e){}
  });
  // Enable Check Availability only when required inputs populated
  function validateAvailabilityInputs(){
    try{
      const start = form.querySelector('input[name="start_date"]');
      const end = form.querySelector('input[name="end_date"]');
      const slip = form.querySelector('select[name="slip_size"]');
      const boat = form.querySelector('select[name="boat_id"]');
      const newBoatName = document.getElementById('newBoatName');
      const newBoatLength = document.getElementById('newBoatLength');
      let ok = true;
      if(!start || !start.value) ok = false;
      // end may be auto-filled, but ensure at least min set
      if(!end || !end.value) ok = false;
      if(!slip || !slip.value) ok = false;
      if(!boat || !boat.value) ok = false;
      // if user selected add_new require new boat fields filled for availability
      if(boat && boat.value === 'add_new'){
        if(!newBoatName || !newBoatName.value.trim()) ok = false;
        if(!newBoatLength || !newBoatLength.value.trim()) ok = false;
      }
      availBtn.disabled = !ok;
      // reflect disabled state visually
      if(availBtn.disabled) availBtn.classList.add('disabled'); else availBtn.classList.remove('disabled');
      return ok;
    }catch(e){ return true; }
  }
  // attach listeners
  ['input','change'].forEach(evt => {
    form.querySelectorAll('input[name="start_date"], input[name="end_date"], select[name="slip_size"], select[name="boat_id"], #newBoatName, #newBoatLength').forEach(el=>{
      try{ el.addEventListener(evt, validateAvailabilityInputs); }catch(e){}
    });
  });
  // initial validation
  validateAvailabilityInputs();
})();
</script>
</script>

</main>

<?php include 'footer.php'; ?>

</body>
</html>