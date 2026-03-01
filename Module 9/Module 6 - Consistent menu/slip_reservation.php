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

// If requested, clear reservation-related session flags and redirect to clean state
if (!empty($_GET['reset_reservation'])) {
  unset($_SESSION['auto_check_availability']);
  unset($_SESSION['selected_boat_id']);
  unset($_SESSION['reservation_form']);
  unset($_SESSION['boat_success']);
  // clear any cached availability data so a fresh page shows
  if (isset($_SESSION['available_slips'])) unset($_SESSION['available_slips']);
  if (isset($_SESSION['reservation_data'])) unset($_SESSION['reservation_data']);
  // Ensure browser does not serve cached form state, then redirect to fresh page
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: 0');
  // Redirect to remove query param and render fresh page
  header('Location: slip_reservation.php');
  exit();
}

// Lightweight DB error logger (appends to db_errors.txt in project dir)
if (!function_exists('log_db_error')) {
  function log_db_error($msg){
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'db_errors.txt';
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
  }
}

// Determine login state and form attributes early to avoid undefined variable warnings
$loggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['user_ID']) || !empty($_SESSION['username']);
$requireLogin = !$loggedIn;
$formDisabledAttr = $requireLogin ? 'disabled' : '';

//REQUIRE USER LOGIN: Redirects to login page if no active session exists.
if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_ID'])) {
  $current = $_SERVER['REQUEST_URI'] ?? '/slip_reservation.php';
  header("Location: BlueTeam_LoginPage.php?redirect=" . urlencode($current) . "&login_notice=1");
  exit();
}

$userID = $_SESSION['user_id'] ?? $_SESSION['user_ID'] ?? (is_array($_SESSION['user']) ? ($_SESSION['user']['user_id'] ?? $_SESSION['user']['user_ID'] ?? null) : null);

// JSON endpoint: return DB-backed current user info for client-side review
if (isset($_GET['fetch_current_user'])) {
  header('Content-Type: application/json');
  $out = [
    'first' => '', 'last' => '', 'full' => '', 'email' => '', 'phone' => '',
    'company' => '', 'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'display' => '', 'username' => ''
  ];
  try {
    if (!empty($userID)) {
      $s = $pdo->prepare("SELECT first_name,last_name,display_name,name,username,email,phone,phone_number,mobile,cell,company,address,address1,address_line1,city,town,state,region,postal_code,zip,postcode FROM users WHERE user_ID = :id OR user_id = :id OR id = :id LIMIT 1");
      $s->execute([':id' => $userID]);
      $row = $s->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $out['first'] = trim((string)($row['first_name'] ?? $row['firstname'] ?? ''));
        $out['last']  = trim((string)($row['last_name'] ?? $row['lastname'] ?? ''));
        $out['email'] = $row['email'] ?? '';
        $out['phone'] = $row['phone'] ?? $row['phone_number'] ?? $row['mobile'] ?? $row['cell'] ?? '';
        $out['company'] = $row['company'] ?? '';
        $out['address'] = $row['address'] ?? $row['address1'] ?? $row['address_line1'] ?? '';
        $out['city'] = $row['city'] ?? $row['town'] ?? '';
        $out['state'] = $row['state'] ?? $row['region'] ?? '';
        $out['zip'] = $row['postal_code'] ?? $row['zip'] ?? $row['postcode'] ?? '';
        $out['display'] = $row['display_name'] ?? $row['name'] ?? '';
        $out['username'] = $row['username'] ?? '';
        $full = trim(($out['first'] ? $out['first'] : '') . ' ' . ($out['last'] ? $out['last'] : ''));
        $out['full'] = $full ?: ($out['display'] ?: $out['username']);
      }
    }
    // If still empty, try session fallbacks
    if (empty($out['first']) && !empty($_SESSION)){
      $out['first'] = $_SESSION['first_name'] ?? $_SESSION['firstname'] ?? $_SESSION['firstName'] ?? ($_SESSION['user']['first_name'] ?? '');
      $out['last'] = $_SESSION['last_name'] ?? $_SESSION['lastname'] ?? $_SESSION['lastName'] ?? ($_SESSION['user']['last_name'] ?? '');
      $out['email'] = $out['email'] ?: ($_SESSION['email'] ?? $_SESSION['user_email'] ?? $_SESSION['username'] ?? '');
      $out['phone'] = $out['phone'] ?: ($_SESSION['phone'] ?? $_SESSION['phone_number'] ?? $_SESSION['phoneNumber'] ?? '');
      $out['company'] = $out['company'] ?: ($_SESSION['company'] ?? '');
      $out['address'] = $out['address'] ?: ($_SESSION['address'] ?? $_SESSION['address1'] ?? '');
      $out['city'] = $out['city'] ?: ($_SESSION['city'] ?? $_SESSION['town'] ?? '');
      $out['state'] = $out['state'] ?: ($_SESSION['state'] ?? $_SESSION['region'] ?? '');
      $out['zip'] = $out['zip'] ?: ($_SESSION['postal_code'] ?? $_SESSION['zip'] ?? '');
      $out['display'] = $out['display'] ?: ($_SESSION['display_name'] ?? $_SESSION['name'] ?? '');
      $out['username'] = $out['username'] ?: ($_SESSION['username'] ?? '');
      $out['full'] = $out['full'] ?: trim(($out['first'] ? $out['first'] : '') . ' ' . ($out['last'] ? $out['last'] : '')) ?: ($out['display'] ?: $out['username']);
    }
  } catch (Exception $e) {
    // ignore, return whatever we have
  }
  echo json_encode($out);
  exit();
}

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

// Reservation confirmation flags: when a reservation is successfully created
// we set `$reservationConfirmed` and provide `$reservationSummary` for rendering
$reservationConfirmed = false;
$reservationSummary = null;

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

        $insertBoat = $pdo->prepare("\n            INSERT INTO boats (user_ID, boat_name, boat_length)\n            VALUES (:user_id, :boat_name, :boat_length)\n        ");

        // Server-side validation for numeric length (1-50)
        if ($newBoatLengthNum < 1 || $newBoatLengthNum > 50) {
          $error = "Boat length must be a number between 1 and 50.";
        }

        if (empty($error)) {
          $insertBoat->execute([
            ':user_id'     => $userID,
            ':boat_name'   => $newBoatName,
            ':boat_length' => $newBoatLengthNum
          ]);

          // New boat becomes selected
          $boatID = $pdo->lastInsertId();

          // Flash message + selected boat (survive redirect)
          $_SESSION['boat_success'] = "Boat added successfully.";
          $_SESSION['selected_boat_id'] = $boatID;

            // after redirect, auto-run availability check so the user sees available slips
            $_SESSION['auto_check_availability'] = 1;

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
        }

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

            // Use known slip columns from this DB schema: slip_ID, slip_size,
            // is_available, location_code. Select slips that are large enough
            // and have no overlapping confirmed/active reservations.
            $sql = "
                SELECT s.slip_ID AS id,
                       CONCAT('Slip ', s.slip_ID) AS name,
                       s.slip_size AS size,
                       s.is_available AS is_available,
                       s.location_code AS location_code
                FROM slips s
                WHERE COALESCE(s.slip_size,0) >= :required_size
                  AND s.is_available = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM reservations r
                       WHERE (r.slip_ID = s.slip_ID)
                        AND (r.reservation_status = 'confirmed' OR r.reservation_status = 'checked_in')
                        AND NOT (r.end_date < :start_date OR r.start_date > :end_date)
                  )
                ORDER BY s.slip_size DESC, s.slip_ID
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':required_size' => $requiredSize,
                ':start_date' => $startDate,
                ':end_date'   => $endDate
            ]);

            $slips = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Build availability list from slips result
            $availableSlips = [];
            foreach ($slips as $s) {
              $sid = $s['id'] ?? null;
              $sname = $s['name'] ?? ('Slip ' . $sid);
              $ssize = intval($s['size'] ?? 0);
              $isAvailable = !empty($s['is_available']);
              $sstatus = $isAvailable ? 'available' : 'unavailable';
              $loc = $s['location_code'] ?? '';
              $availableSlips[] = [
                'id' => $sid,
                'name' => $sname,
                'size' => $ssize,
                'status' => $sstatus,
                'location_code' => $loc,
                'available' => $isAvailable
              ];
            }

            $availableCount = count(array_filter($availableSlips, function($x){ return !empty($x['available']); }));

            // persist the available slips in session so the next POST (confirm) can validate user's choice
            $_SESSION['available_slips'] = $availableSlips;

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

                // availability map already built above and persisted to session
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
  // allow explicit confirmation to proceed without saving when user opts in
  $confirmWithoutSave = isset($_POST['confirm_without_save']) && $_POST['confirm_without_save'] === '1';
  if (!$error && $boatID === 'add_new') {
    if (!$saveNew) {
      if (!$confirmWithoutSave) {
        $error = "Please save the new boat to your profile before confirming, or select an existing boat.";
      }
      // if $confirmWithoutSave is true, allow proceeding without persisting the boat
    } else {
      // persist the new boat and replace boatID with the inserted id
      if ($newBoatName === '' || $newBoatLengthNum <= 0 || $newBoatLengthNum > 50) {
        $error = "Provide a valid boat name and length (1-50 ft) to save the boat.";
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
              AND s.is_available = 1
              AND s.slip_ID NOT IN (
                SELECT r.slip_ID
                FROM reservations r
                WHERE r.reservation_status IN ('confirmed','checked_in')
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
              $q = $pdo->prepare("SELECT COUNT(*) FROM reservations r WHERE r.slip_ID = :sid AND r.reservation_status IN ('confirmed','checked_in') AND (:start_date <= r.end_date AND :end_date >= r.start_date)");
              $q->execute([':sid' => $selectedSlipPost, ':start_date' => $startDate, ':end_date' => $endDate]);
              $conflicts = (int)$q->fetchColumn();
              $sinfo = $pdo->prepare("SELECT is_available, slip_size FROM slips WHERE slip_ID = :sid LIMIT 1");
              $sinfo->execute([':sid' => $selectedSlipPost]);
              $rrow = $sinfo->fetch(PDO::FETCH_ASSOC);
              $isAvail = !empty($rrow['is_available']);
              $ssize = intval($rrow['slip_size'] ?? 0);
              if ($conflicts === 0 && $isAvail && $ssize == $slipSizeNum) $isValid = true;
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

            $baseCost = ($boatLength * $pricePerFoot);
            $hookupCost = ($hookupPerMonth * $months);

            // Persist reservation to DB and keep a session pointer for the summary page.
            // Attempt a common schema first (user_ID / boat_ID / slip_ID). If that fails,
            // fall back to a lowercase variant (user_id / boat_id / slip_id).
            $reservationId = null;
            // Generate a human-friendly confirmation number for the reservation
            $confirmationNumber = '';
            try {
              if (function_exists('random_bytes')) {
                $confirmationNumber = strtoupper(bin2hex(random_bytes(4)));
              } else {
                $confirmationNumber = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
              }
            } catch (Exception $ex) {
              $confirmationNumber = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
            }

            try {
              // Use schema column names as shown in your DB: months_duration, total_cost, reservation_status, confirmation_number
              $insSql = "INSERT INTO reservations (confirmation_number, user_ID, boat_ID, slip_ID, start_date, end_date, months_duration, total_cost, reservation_status, date_created) VALUES (:confirmation, :user_id, :boat_id, :slip_id, :start_date, :end_date, :months_duration, :total_cost, :status, NOW())";
              $ins = $pdo->prepare($insSql);
              $ins->execute([
                ':confirmation' => $confirmationNumber,
                ':user_id' => $userID,
                ':boat_id' => $boatID,
                ':slip_id' => $availableSlip,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':months_duration' => intval($months),
                ':total_cost' => round($cost,2),
                ':status' => 'confirmed'
              ]);
              $reservationId = $pdo->lastInsertId();
            } catch (Exception $e) {
              // try a lowercase variant just in case schema uses different naming
              try {
                $insSql2 = "INSERT INTO reservations (confirmation_number, user_id, boat_id, slip_id, start_date, end_date, months_duration, total_cost, reservation_status, date_created) VALUES (:confirmation, :user_id, :boat_id, :slip_id, :start_date, :end_date, :months_duration, :total_cost, :status, NOW())";
                $ins2 = $pdo->prepare($insSql2);
                $ins2->execute([
                  ':confirmation' => $confirmationNumber,
                  ':user_id' => $userID,
                  ':boat_id' => $boatID,
                  ':slip_id' => $availableSlip,
                  ':start_date' => $startDate,
                  ':end_date' => $endDate,
                  ':months_duration' => intval($months),
                  ':total_cost' => round($cost,2),
                  ':status' => 'confirmed'
                ]);
                $reservationId = $pdo->lastInsertId();
              } catch (Exception $e2) {
                log_db_error('Reservation insert failed (primary): ' . $e->getMessage() . ' / fallback: ' . $e2->getMessage());
                $error = "Unable to create reservation at this time. Please try again.";
              }
            }

            if ($reservationId) {
              // store reservation summary in session for later access
              // capture a snapshot of the chosen slip so the confirmation can show
              $snapName = '';
              $snapLocation = '';
              $snapSize = '';
              if (!empty($_SESSION['available_slips'])) {
                foreach ($_SESSION['available_slips'] as $as) {
                  if ((string)($as['id'] ?? '') === (string)$availableSlip) {
                    $snapName = $as['name'] ?? '';
                    $snapLocation = $as['location_code'] ?? '';
                    $snapSize = $as['size'] ?? $as['slip_size'] ?? '';
                    break;
                  }
                }
              }
              // fallback DB lookup if session snapshot not available
              if (empty($snapName) && !empty($availableSlip) && isset($pdo)) {
                try {
                  $sstmt = $pdo->prepare("SELECT name, location_code, slip_size, size FROM slips WHERE slip_ID = :id OR id = :id OR slip_id = :id LIMIT 1");
                  $sstmt->execute([':id' => $availableSlip]);
                  $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
                  if ($srow) {
                    $snapName = $srow['name'] ?? '';
                    $snapLocation = $srow['location_code'] ?? '';
                    $snapSize = $srow['slip_size'] ?? $srow['size'] ?? '';
                  }
                } catch (Exception $e) { }
              }

              $_SESSION['reservation_data'] = [
                'reservation_id' => $reservationId,
                'confirmation_number' => $confirmationNumber,
                'user_ID'    => $userID,
                'boat_ID'    => $boatID,
                'slip_ID'    => $availableSlip,
                'slip_name'  => $snapName,
                'slip_location_code' => $snapLocation,
                'slip_size'  => $snapSize,
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'months_duration' => intval($months),
                'total_cost' => round($cost,2)
              ];
              // clear availability session cache
              if (isset($_SESSION['available_slips'])) unset($_SESSION['available_slips']);
              // store last reservation id for convenience
              $_SESSION['last_reservation_id'] = $reservationId;

              // Instead of redirecting to reservation_summary.php, render an inline
              // confirmation panel on this page that replicates Step 3 information.
              $reservationConfirmed = true;
              $reservationSummary = $_SESSION['reservation_data'];
            }

        } else {
            $error = "That slip was just booked. Please try again.";
        }
    }
}

// fetch current user contact details for client-side review display
try {
  $user_first = '';
  $user_last = '';
  $user_email_display = '';
  $user_phone = '';
  $user_address = '';
  $user_city = '';
  $user_state = '';
  $user_zip = '';
  $user_company = '';
  $user_display_name = '';
  $user_username = '';
    if (!empty($userID)) {
    // Attempt to read common name/display fields so the client review shows
    // the same user info as MyAccount. Some schemas use 'display_name' or
    // 'name' instead of separate first/last columns; tolerate those.
    $uStmt = $pdo->prepare("SELECT first_name, last_name, display_name, name, username, email, phone, phone_number, address, address1, address_line1, city, town, state, region, postal_code, zip, postcode, company FROM users WHERE user_ID = :id OR user_id = :id OR id = :id LIMIT 1");
    $uStmt->execute([':id' => $userID]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
      // Prefer explicit first/last when available
      $raw_first = trim((string)($uRow['first_name'] ?? $uRow['firstname'] ?? ''));
      $raw_last  = trim((string)($uRow['last_name'] ?? $uRow['lastname'] ?? ''));
      if ($raw_first || $raw_last) {
        $user_first = $raw_first;
        $user_last  = $raw_last;
      } else {
        // Try display_name or name and split into first/last parts
        $display = trim((string)($uRow['display_name'] ?? $uRow['name'] ?? ''));
        if ($display !== '') {
          $parts = preg_split('/\s+/', $display, -1, PREG_SPLIT_NO_EMPTY);
          $user_first = $parts[0] ?? '';
          $user_last = count($parts) > 1 ? implode(' ', array_slice($parts,1)) : '';
        } else {
          // fallback to username if present
          $user_first = trim((string)($uRow['username'] ?? ''));
          $user_last = '';
        }
      }
      $user_email_display = $uRow['email'] ?? '';
      // prefer 'phone' column name, fall back to 'phone_number'
      $user_phone = $uRow['phone'] ?? $uRow['phone_number'] ?? '';
      // address fields (try several common column names)
      $user_address = $uRow['address'] ?? $uRow['address1'] ?? $uRow['address_line1'] ?? '';
      $user_city = $uRow['city'] ?? $uRow['town'] ?? '';
      $user_state = $uRow['state'] ?? $uRow['region'] ?? '';
      $user_zip = $uRow['postal_code'] ?? $uRow['zip'] ?? $uRow['postcode'] ?? '';
      $user_company = $uRow['company'] ?? '';
      $user_display_name = $uRow['display_name'] ?? $uRow['name'] ?? '';
      $user_username = $uRow['username'] ?? '';
    } else {
      // attempt common session fallbacks when DB row missing
      $user_first = '';
      $user_last = '';
      // try many common session key variants for first/last name
      $user_first = $_SESSION['first_name'] ?? $_SESSION['firstname'] ?? $_SESSION['firstName'] ?? $_SESSION['user_first'] ?? $_SESSION['userFirst'] ?? ($_SESSION['user']['first_name'] ?? null) ?? ($user_first ?: '');
      $user_last  = $_SESSION['last_name'] ?? $_SESSION['lastname'] ?? $_SESSION['lastName'] ?? $_SESSION['user_last'] ?? $_SESSION['userLast'] ?? ($_SESSION['user']['last_name'] ?? null) ?? ($user_last ?: '');
      $user_email_display = $_SESSION['email'] ?? $_SESSION['user_email'] ?? ($_SESSION['user']['email'] ?? ($_SESSION['username'] ?? ''));
      // many possible phone keys
      $user_phone = $_SESSION['phone'] ?? $_SESSION['phone_number'] ?? $_SESSION['phoneNumber'] ?? $_SESSION['user_phone'] ?? ($_SESSION['user']['phone'] ?? $_SESSION['user']['phone_number'] ?? '') ;
      // session fallbacks for address-like fields
      $user_address = $_SESSION['address'] ?? $_SESSION['address1'] ?? $_SESSION['address_line1'] ?? ($_SESSION['user']['address'] ?? '');
      $user_city = $_SESSION['city'] ?? $_SESSION['town'] ?? ($_SESSION['user']['city'] ?? '');
      $user_state = $_SESSION['state'] ?? $_SESSION['region'] ?? ($_SESSION['user']['state'] ?? '');
      $user_zip = $_SESSION['postal_code'] ?? $_SESSION['zip'] ?? $_SESSION['postcode'] ?? ($_SESSION['user']['postal_code'] ?? '');
      $user_company = $_SESSION['company'] ?? ($_SESSION['user']['company'] ?? '');
      $user_display_name = $_SESSION['display_name'] ?? $_SESSION['name'] ?? ($_SESSION['user']['display_name'] ?? '');
      $user_username = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '');
    }
  }
  } catch (Exception $e) {
  // silently ignore DB issues for display — try multiple session fallbacks
  $user_first = $_SESSION['first_name'] ?? $_SESSION['firstname'] ?? $_SESSION['firstName'] ?? $_SESSION['user_first'] ?? $_SESSION['userFirst'] ?? ($_SESSION['user']['first_name'] ?? '');
  $user_last = $_SESSION['last_name'] ?? $_SESSION['lastname'] ?? $_SESSION['lastName'] ?? $_SESSION['user_last'] ?? $_SESSION['userLast'] ?? ($_SESSION['user']['last_name'] ?? '');
  $user_email_display = $_SESSION['email'] ?? $_SESSION['user_email'] ?? ($_SESSION['user']['email'] ?? ($_SESSION['username'] ?? ''));
  $user_phone = $_SESSION['phone'] ?? $_SESSION['phone_number'] ?? $_SESSION['phoneNumber'] ?? $_SESSION['user_phone'] ?? ($_SESSION['user']['phone_number'] ?? '');
  $user_address = $_SESSION['address'] ?? $_SESSION['address1'] ?? $_SESSION['address_line1'] ?? ($_SESSION['user']['address'] ?? '');
  $user_city = $_SESSION['city'] ?? $_SESSION['town'] ?? ($_SESSION['user']['city'] ?? '');
  $user_state = $_SESSION['state'] ?? $_SESSION['region'] ?? ($_SESSION['user']['state'] ?? '');
  $user_zip = $_SESSION['postal_code'] ?? $_SESSION['zip'] ?? $_SESSION['postcode'] ?? ($_SESSION['user']['postal_code'] ?? '');
  $user_company = $_SESSION['company'] ?? ($_SESSION['user']['company'] ?? '');
  $user_display_name = $_SESSION['display_name'] ?? $_SESSION['name'] ?? ($_SESSION['user']['display_name'] ?? '');
  $user_username = $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '');
}

// Defensive re-checks: if we still don't have a first/last name, try querying
// again using available session email/username or explicit user_ID variants.
try{
  if (empty($user_first) && !empty($userID)){
    $q = $pdo->prepare("SELECT first_name, last_name, display_name, name, username, email, phone, phone_number, mobile, cell, telephone FROM users WHERE user_ID = :id OR user_id = :id OR id = :id LIMIT 1");
    $q->execute([':id' => $userID]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r){
      $user_first = $r['first_name'] ?? $r['firstname'] ?? $user_first;
      $user_last  = $r['last_name'] ?? $r['lastname'] ?? $user_last;
      if (empty($user_last)) {
        $disp = trim((string)($r['display_name'] ?? $r['name'] ?? ''));
        if ($disp !== '') {
          $parts = preg_split('/\s+/', $disp, -1, PREG_SPLIT_NO_EMPTY);
          $user_first = $user_first ?: ($parts[0] ?? $user_first);
          $user_last = $user_last ?: (count($parts) > 1 ? implode(' ', array_slice($parts,1)) : $user_last);
        }
      }
      $user_phone = $r['phone'] ?? $r['phone_number'] ?? $r['mobile'] ?? $r['cell'] ?? $r['telephone'] ?? $user_phone;
    }
  }
  if (empty($user_first) && !empty($_SESSION['email'])){
    $q = $pdo->prepare("SELECT first_name, last_name, display_name, name, username, phone, phone_number, mobile, cell, telephone FROM users WHERE email = :email LIMIT 1");
    $q->execute([':email' => $_SESSION['email']]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r){
      $user_first = $r['first_name'] ?? $r['firstname'] ?? $user_first;
      $user_last  = $r['last_name'] ?? $r['lastname'] ?? $user_last;
      if (empty($user_last)) {
        $disp = trim((string)($r['display_name'] ?? $r['name'] ?? ''));
        if ($disp !== '') {
          $parts = preg_split('/\s+/', $disp, -1, PREG_SPLIT_NO_EMPTY);
          $user_first = $user_first ?: ($parts[0] ?? $user_first);
          $user_last = $user_last ?: (count($parts) > 1 ? implode(' ', array_slice($parts,1)) : $user_last);
        }
      }
      $user_phone = $r['phone'] ?? $r['phone_number'] ?? $r['mobile'] ?? $r['cell'] ?? $r['telephone'] ?? $user_phone;
    }
  }

  // Try a username-based lookup as a last effort (session may carry username instead of email)
  if ((empty($user_first) || empty($user_phone)) && !empty($_SESSION['username'])){
    $q = $pdo->prepare("SELECT first_name, last_name, display_name, name, username, phone, phone_number, mobile, cell, telephone FROM users WHERE username = :username LIMIT 1");
    $q->execute([':username' => $_SESSION['username']]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r){
      $user_first = $r['first_name'] ?? $r['firstname'] ?? $user_first;
      $user_last  = $r['last_name'] ?? $r['lastname'] ?? $user_last;
      if (empty($user_last)) {
        $disp = trim((string)($r['display_name'] ?? $r['name'] ?? ''));
        if ($disp !== '') {
          $parts = preg_split('/\s+/', $disp, -1, PREG_SPLIT_NO_EMPTY);
          $user_first = $user_first ?: ($parts[0] ?? $user_first);
          $user_last = $user_last ?: (count($parts) > 1 ? implode(' ', array_slice($parts,1)) : $user_last);
        }
      }
      $user_phone = $r['phone'] ?? $r['phone_number'] ?? $r['mobile'] ?? $r['cell'] ?? $r['telephone'] ?? $user_phone;
    }
  }
}catch(Exception $e){ /* ignore fallback failures */ }

// Provide a combined full-name hidden input so client JS has a single reliable
// source if first/last are present in the DB.
$user_full = trim(($user_first ? $user_first : '') . ' ' . ($user_last ? $user_last : ''));

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
    .slip{width:120px;padding:.5rem;border-radius:6px;text-align:center;border:1px solid rgba(31,47,69,0.08);box-sizing:border-box}
    /* Available slips: Ocean-blue tint with clear border to match site palette */
    .slip.available{background:linear-gradient(180deg,#f0fbff,#e6f7fb);border-color:#3F87A6;color:#1F2F45}
    /* Unavailable slips: soft muted red/rose to indicate unavailable but keep subdued */
    .slip.unavailable{background:#fff7f6;border-color:#f2a6a6;color:#9b1f1f;opacity:0.95}
    .slip .meta{font-size:.85rem;color:#1F2F45}
    /* Selected slip: stronger, high-contrast highlight matching project gold/navy palette */
    .slip.selected{
      background: linear-gradient(180deg,#fff7e6,#fff1d9) !important;
      border-color: #F4C26B !important;
      color: #1F2F45 !important;
      box-shadow: 0 8px 24px rgba(31,47,69,0.14), 0 0 0 4px rgba(244,194,107,0.18) !important;
      transform: translateY(-2px);
    }
    .slip.selected .meta{ color: #102535 !important; font-weight:700 }
    /* Marina map card for the full-size map image */
    .marina-card{margin-top:14px;padding:12px;border-radius:12px;background:#fff;border:1px solid rgba(0,0,0,0.04);box-shadow:0 6px 18px rgba(31,47,69,0.04);}
    .marina-card img{width:100%;height:auto;border-radius:8px;display:block}
    .select-slip-btn{display:none;margin-top:10px;padding:12px 22px;border-radius:8px;background:#3F87A6;color:#ffffff;font-weight:700;border:none;cursor:pointer;box-shadow:0 6px 18px rgba(63,135,166,0.12);font-size:1.05rem;min-width:200px}
    /* Remove default focus outline for reservation-related buttons except the Cancel button */
    .reservation-card button:focus,
    .reservation-card button:focus-visible,
    .reservation-card .reservation-card-actions-inline button:focus,
    .reservation-card .reservation-card-actions-inline button:focus-visible,
    .reservation-card .reservation-card-actions button:focus,
    .reservation-card .reservation-card-actions button:focus-visible {
      outline: none !important;
      box-shadow: none !important;
      border-color: inherit !important;
    }
    /* Keep a visible outline for the Cancel button for accessibility */
    .reservation-card button.btn-cancel:focus,
    .reservation-card button.btn-cancel:focus-visible {
      outline: 2px solid #FF6B6B !important;
      outline-offset: 2px !important;
      box-shadow: none !important;
    }
    /* Final aggressive cleanup: remove any residual visual rings, shadows, or
       pseudo-element outlines applied to the top action buttons. This targets
       pseudo-elements and any focus/focus-visible states with !important so
       third-party CSS cannot reintroduce the ring on this page. */
    .reservation-card .reservation-card-actions-inline button[name="check_availability"],
    .reservation-card #addBoatBtn,
    .reservation-card .reservation-card-actions button[name="check_availability"],
    .reservation-card .reservation-card-actions #addBoatBtn,
    .reservation-card button[name="check_availability"],
    .reservation-card #addBoatBtn,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]::before,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]::after,
    .reservation-card #addBoatBtn::before,
    .reservation-card #addBoatBtn::after {
      outline: none !important;
      box-shadow: none !important;
      -webkit-box-shadow: none !important;
      -moz-box-shadow: none !important;
      border: none !important;
      background-clip: padding-box !important;
      -webkit-appearance: none !important;
      transform: none !important;
    }
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]:focus,
    .reservation-card #addBoatBtn:focus,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]:focus-visible,
    .reservation-card #addBoatBtn:focus-visible,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]:active,
    .reservation-card #addBoatBtn:active {
      outline: none !important;
      box-shadow: none !important;
    }
    /* Extra aggressive specificity to remove any browser/third-party focus rings
       or shadow outlines that may be applied to the top action buttons. This
       keeps the button visuals identical to other project buttons. */
    .reservation-card .reservation-card-actions-inline button[name="check_availability"],
    .reservation-card .reservation-card-actions-inline #addBoatBtn,
    .reservation-card .reservation-card-actions button[name="check_availability"],
    .reservation-card .reservation-card-actions #addBoatBtn,
    .reservation-card button[name="check_availability"],
    .reservation-card #addBoatBtn {
      outline: none !important;
      box-shadow: none !important;
      -webkit-box-shadow: none !important;
      -moz-box-shadow: none !important;
      border-color: inherit !important;
      background-clip: padding-box !important;
      -webkit-appearance: none !important;
      -webkit-tap-highlight-color: transparent !important;
    }
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]:focus,
    .reservation-card .reservation-card-actions-inline #addBoatBtn:focus,
    .reservation-card .reservation-card-actions button[name="check_availability"]:focus,
    .reservation-card .reservation-card-actions #addBoatBtn:focus,
    .reservation-card button[name="check_availability"]:focus,
    .reservation-card #addBoatBtn:focus,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]:focus-visible,
    .reservation-card .reservation-card-actions-inline #addBoatBtn:focus-visible,
    .reservation-card .reservation-card-actions button[name="check_availability"]:focus-visible,
    .reservation-card .reservation-card-actions #addBoatBtn:focus-visible,
    .reservation-card button[name="check_availability"]:focus-visible,
    .reservation-card #addBoatBtn:focus-visible {
      outline: none !important;
      box-shadow: none !important;
      -webkit-box-shadow: none !important;
    }
    /* Specifically ensure top action buttons (Check Availability, Add Boat) match site buttons
       and do not show an extra focus ring in our reservation card. This targets both
       the inline top actions and the pinned card actions to override other styles. */
    .reservation-card button[name="check_availability"],
    .reservation-card #addBoatBtn,
    .reservation-card .reservation-card-actions-inline button[name="check_availability"],
    .reservation-card .reservation-card-actions-inline #addBoatBtn,
    .reservation-card .reservation-card-actions button[name="check_availability"],
    .reservation-card .reservation-card-actions #addBoatBtn {
      outline: none !important;
      box-shadow: none !important;
      -webkit-appearance: none !important;
      border-color: inherit !important;
    }
    .reservation-card button[name="check_availability"]:focus,
    .reservation-card #addBoatBtn:focus,
    .reservation-card button[name="check_availability"]:focus-visible,
    .reservation-card #addBoatBtn:focus-visible {
      outline: none !important;
      box-shadow: none !important;
    }
    /* Review panel card-style */
     /* Simplified review panel: remove heavy secondary-card visuals and use
       a friendlier stacked layout (label above value). Kept page-scoped.
       This avoids the 'table' look and removes the secondary card feel. */
      /* Make the review take full card width so it doesn't act like a secondary nested card
        that constrains content. Center it inside the reservation card and keep a simple
        inner surface without an extra boxed panel to maximize available width. */
      .reservation-review{background:transparent;border:none;box-shadow:none;padding:0;margin:12px auto;width:100%;max-width:920px;box-sizing:border-box}
      .reservation-review > .panel-inner{background:transparent;border:none;padding:0;margin:0;border-radius:0;box-shadow:none;width:100%;box-sizing:border-box}
      /* Layout the review into three columns: User | Boat | Slip */
      .reservation-review > .panel-inner { display: grid; grid-template-columns: 1fr 1fr 1fr; gap:18px; align-items:start; }
      .reservation-review .user-section { grid-column: 1; }
      .reservation-review .boat-section  { grid-column: 2; }
      .reservation-review .slip-section  { grid-column: 3; }
      /* Dates and costs should span the full width below the three columns */
      .reservation-review .dates, .reservation-review .costs-section { grid-column: 1 / -1; }
      /* Compact cost rows and emphasize total */
      .reservation-review .costs-section .label{font-weight:700}
      .reservation-review .total { font-size:1.35rem; font-weight:900; color:#102535; text-align:center; }
      /* Center Confirm/Cancel horizontally */
      .reservation-review .confirm-wrapper{display:flex;justify-content:center;gap:12px;margin-top:18px}
      @media (max-width:720px){
        .reservation-review > .panel-inner { grid-template-columns: 1fr; }
        .reservation-review .user-section, .reservation-review .boat-section, .reservation-review .slip-section { grid-column: auto; }
        .reservation-review .dates, .reservation-review .costs-section { grid-column: auto; }
      }
     .reservation-review h3{margin:0 0 12px 0}
     .reservation-review .item{padding:10px 0;border-bottom:1px dashed rgba(0,0,0,0.04)}
     .reservation-review .item:last-child{border-bottom:none}
    /* Labels act as section headers (bold) and values are regular weight */
    .reservation-review .label{display:block;color:#1F2F45;margin-bottom:6px;font-weight:700;font-size:1rem}
    .reservation-review .value{font-weight:400;color:#102535;font-size:0.98rem}
    .reservation-review .total{font-size:1.25rem;font-weight:900;color:#1F2F45;margin-top:12px;text-align:right}
    .reservation-review .amount{font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;}
    .reservation-review .confirm-wrapper{display:flex;flex-direction:column;align-items:center;gap:12px;margin-top:16px}
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
    /* Hide Add Boat panel by default; only show when reservation-card has .show-add-boat
       This prevents accidental visibility from inline styles or flow transitions. */
    .reservation-card .form-group.add-boat{ display: none !important; }
    .reservation-card.show-add-boat .form-group.add-boat{ display: grid !important; }
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
    .form-actions{display:flex;gap:.5rem;margin-top:3rem;clear:both;justify-content:center}
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
    /* Force the actions wrapper to be a centered column (override inline styles where needed) */
    .reservation-card .reservation-card-actions-inline { display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important }
    /* Force the top Check Availability button to center regardless of inline styles */
    .reservation-card .reservation-card-actions-inline button[name="check_availability"]{ display:block !important; margin:0 auto !important; text-align:center !important; width:auto !important }
    /* Ensure dynamically inserted select button centers */
    .reservation-card .reservation-card-actions-inline #selectSlipBtn{ display:block !important; margin:12px auto !important }
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
    /* Force action buttons inside the card to center and stack vertically */
    .reservation-card > .reservation-card-actions-inline button,
    .reservation-card > .reservation-card-actions-inline .btn-primary,
    .reservation-card > .reservation-card-actions-inline .btn-secondary {
      display: block !important;
      margin: 0 auto !important;
      text-align: center !important;
      /* Force the top Check Availability button to center even if it has inline styles */
      .reservation-card .reservation-card-actions-inline button[name="check_availability"] {
        display: block !important;
        margin: 0 auto !important;
        text-align: center !important;
      }
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

    /* Step 3: hide the reservation input controls and center the Confirm button
       when the card has the page-local class `flow-step-3`. This is stronger
       than inline style manipulation and guarantees the UI the user asked for. */
    .reservation-card.flow-step-3 .form-group.start-date,
    .reservation-card.flow-step-3 .form-group.end-date,
    .reservation-card.flow-step-3 .form-group.select-boat,
    .reservation-card.flow-step-3 .form-group.slip-size,
    .reservation-card.flow-step-3 .form-group.add-boat,
    .reservation-card.flow-step-3 .reservation-cost,
    .reservation-card.flow-step-3 .reservation-card-actions-inline,
    .reservation-card.flow-step-3 #availableSlipWrapper,
    .reservation-card.flow-step-3 #availableSlipMap,
    .reservation-card.flow-step-3 #marinaCard {
      display: none !important;
    }

    /* Center and style the confirm button to match Ocean Blue primary/secondary
       buttons in the flow. This targets the bottom confirm whether server- or
       client-inserted and enforces width/margins so it sits centered under the card. */
    .reservation-card .form-actions #confirmBtn,
    .reservation-card.flow-step-3 .form-actions #confirmBtn {
      display: block !important;
      margin: 16px auto !important;
      background: #3F87A6 !important; /* Ocean Blue */
      color: #ffffff !important;
      border: none !important;
      padding: 12px 25px !important;
      border-radius: 8px !important;
      font-weight: 700 !important;
      min-width: 200px !important;
      box-shadow: 0 6px 18px rgba(63,135,166,0.12) !important;
      text-align: center !important;
    }

    /* Ensure the confirm button label is visually consistent */
    .reservation-card .form-actions #confirmBtn:hover{ filter: brightness(0.95); }
  </style>
  <style>
    /* Reservation step tracker used inside the notice bar (page-local) */
    .reservation-steps{display:flex;flex-direction:column;align-items:center}
    .reservation-steps .steps-row{display:flex;align-items:center;justify-content:center;width:100%;max-width:980px;margin:0 auto;padding:0 12px;box-sizing:border-box}
    .reservation-steps .step{display:flex;flex-direction:column;align-items:center;flex:0 0 auto}
    .reservation-steps .step .step-circle{width:40px;height:40px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:var(--navy);background:#e6eef4}
    /* Active step: inverted / cream palette to stand out against ocean background */
    /* Active and completed steps: use gold for better contrast/visibility */
    .reservation-steps .step.active .step-circle,
    .reservation-steps .step.completed .step-circle{
      background:var(--gold, #F4C26B) !important; /* gold */
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
    .site-hero .site-hero-inner { max-width: 1000px !important; padding-left: var(--hero-inner-padding,20px) !important; padding-right: var(--hero-inner-padding,20px) !important; }
     /* Prevent layout shift on this page by forcing a stable vertical scrollbar
       so the viewport width doesn't change when content height varies. This
       rule is page-scoped via body.page-reservation and will not affect other pages. */
     /* Keep a stable vertical scrollbar gutter on this page without forcing
       a second scrollbar; rely on the site-wide `html` setting for the
       authoritative scrollbar. */
     body.page-reservation { scrollbar-gutter: stable; }
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
    /* Page-scoped sticky/footer spacing: make this page a column flex container
       and allow the page body to grow so the footer is pushed to the bottom. */
    body.page-reservation {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    body.page-reservation .page-body { flex: 1 0 auto; display:flex; flex-direction:column; }
    body.page-reservation .page-body main.reservation-page { flex: 1 0 auto; }
    body.page-reservation .site-footer { flex-shrink: 0; }
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
<div class="page-body">
  <style>
    /* Page-scoped: ensure footer sits at bottom on short pages */
    .page-body { min-height: 100vh; display: flex; flex-direction: column; }
    .page-body > main.reservation-page { flex: 1 1 auto; }
  </style>
<div class="reservation-frame">
  <div class="notice-wrap">
    <div class="notice">
      <div class="reservation-steps">
        <div class="steps-row">
          <?php if (!empty($reservationConfirmed)): ?>
            <div class="step active">
              <span class="step-circle">✓</span>
              <div class="step-label">Confirmation</div>
            </div>
          <?php else: ?>
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
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<!-- page-body opened above to contain reservation-frame + main -->
<main class="reservation-page">
<section class="reservation-card" style="position:relative;padding-bottom:9px;">

<?php if ($error): ?>
  <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<?php if ($boatSuccess): ?>
  <div class="alert alert-success"><?= $boatSuccess ?></div>
<?php endif; ?>

<?php if (!empty($reservationConfirmed) && !empty($reservationSummary)): ?>
  <div class="alert alert-success">Reservation successful — confirmation #: <strong><?= htmlspecialchars($reservationSummary['confirmation_number'] ?? '') ?></strong></div>
  <div class="reservation-confirmation" style="border:1px solid #e6f2ef;padding:16px;border-radius:8px;margin-bottom:12px;background:#f7fffb">
    <h3 style="margin-top:0;margin-bottom:6px;text-align:center;">Reservation Confirmed</h3>
    <?php
      // Format dates for display
      $fmtStart = $reservationSummary['start_date'] ?? '';
      $fmtEnd = $reservationSummary['end_date'] ?? '';
      try { $d1 = DateTime::createFromFormat('Y-m-d', $fmtStart); if ($d1) $fmtStart = $d1->format('F j, Y'); } catch (Exception $e){}
      try { $d2 = DateTime::createFromFormat('Y-m-d', $fmtEnd); if ($d2) $fmtEnd = $d2->format('F j, Y'); } catch (Exception $e){}

      // Boat label
      $boatLabel = '';
      foreach ($userBoats as $b) {
        if ((string)($b['boat_ID'] ?? $b['boat_id'] ?? '') === (string)($reservationSummary['boat_ID'] ?? '')) {
          $boatLabel = htmlspecialchars($b['boat_name'] ?? $b['name'] ?? '') . ' (' . intval($b['boat_length'] ?? $b['length_ft'] ?? 0) . ' ft)';
          break;
        }
      }

      // Slip label: prefer snapshot stored with reservation, then session cache, then DB
      $slipLabel = '';
      $slipLength = '';
      if (!empty($reservationSummary['slip_location_code'])) {
        $slipLabel = htmlspecialchars($reservationSummary['slip_location_code']);
        $slipLength = intval($reservationSummary['slip_size'] ?? 0);
      }
      // fallback to stored slip name
      if (empty($slipLabel) && !empty($reservationSummary['slip_name'])) {
        $slipLabel = htmlspecialchars($reservationSummary['slip_name']);
        if (empty($slipLength)) $slipLength = intval($reservationSummary['slip_size'] ?? 0);
      }
      // fallback to session available_slips
      $sid = $reservationSummary['slip_ID'] ?? null;
      if (empty($slipLabel) && !empty($_SESSION['available_slips']) && $sid) {
        foreach ($_SESSION['available_slips'] as $as) {
          if ((string)($as['id'] ?? '') === (string)$sid) {
            if (!empty($as['location_code'])) $slipLabel = htmlspecialchars($as['location_code']);
            $slipLength = intval($as['size'] ?? $as['slip_size'] ?? 0);
            if (empty($slipLabel) && !empty($as['name'])) $slipLabel = htmlspecialchars($as['name']);
            break;
          }
        }
      }
      // final fallback: DB lookup
      if (empty($slipLabel) && !empty($sid) && isset($pdo)) {
        try {
          $sstmt = $pdo->prepare("SELECT name, location_code, slip_size, size FROM slips WHERE slip_ID = :id OR id = :id OR slip_id = :id LIMIT 1");
          $sstmt->execute([':id' => $sid]);
          $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
          if ($srow) {
            $slipLabel = htmlspecialchars($srow['location_code'] ?? $srow['name'] ?? ('Slip ' . $sid));
            $slipLength = intval($srow['slip_size'] ?? $srow['size'] ?? 0);
          }
        } catch (Exception $e) { /* ignore */ }
      }
    ?>
    <div style="display:flex;gap:18px;flex-wrap:wrap;justify-content:space-between;align-items:flex-start">
      <div style="display:flex;align-items:center;gap:18px;flex-wrap:nowrap;justify-content:center;width:100%">
        <div style="flex:0 0 220px;text-align:center">
          <div style="font-weight:700">Boat</div>
          <div><?= $boatLabel ?: htmlspecialchars($reservationSummary['boat_ID'] ?? '') ?></div>
        </div>

        <div style="flex:0 0 auto;min-width:220px;display:flex;justify-content:center;align-items:center;gap:28px">
          <div style="text-align:center;white-space:nowrap">
            <div style="font-weight:700">Slip</div>
            <div><?= $slipLabel ?><?php if (!empty($slipLength)) echo ' (' . intval($slipLength) . ' ft)'; ?></div>
          </div>
          <div style="text-align:center;white-space:nowrap">
            <div style="font-weight:700">Dates</div>
            <div><?= htmlspecialchars($fmtStart) ?> — <?= htmlspecialchars($fmtEnd) ?></div>
          </div>
        </div>

        <div style="flex:0 0 180px;min-width:160px;text-align:center">
          <div style="font-weight:700">Total</div>
          <div>$<?= number_format($reservationSummary['total_cost'] ?? 0, 2) ?></div>
        </div>
      </div>
    </div>
    <div style="margin-top:12px;display:flex;justify-content:center;gap:8px">
      <a href="reservation_summary.php" class="btn" style="background:#3F87A6;color:#fff;padding:10px 14px;border-radius:6px;text-decoration:none;display:inline-block">Your Reservations</a>
      <a href="slip_reservation.php?reset_reservation=1" class="btn" style="background:transparent;border:1px solid #3F87A6;color:#3F87A6;padding:10px 14px;border-radius:6px;text-decoration:none;display:inline-block">Make another reservation</a>
    </div>
  </div>
<?php endif; ?>

<?php // availability messages are handled client-side; suppress server-side duplicate rendering to avoid stale notices on reload ?>

<?php /* Removed duplicate Estimated Total alert — use the reservation-cost card only */ ?>

<!-- Slip availability map will be rendered inside the form so selections submit correctly -->

    <!-- notice moved above the card to overlap hero (see top of file) -->

  <?php if (empty($reservationConfirmed)): ?>
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
      <input type="number" name="new_boat_length" id="newBoatLength" value="<?= htmlspecialchars($newBoatLength) ?>" placeholder="Length (ft)" min="1" max="50" step="1" inputmode="numeric" pattern="[0-9]*" oninput="if(this.value.length>2) this.value=this.value.slice(0,2); if(Number(this.value)>50) this.value='50';">
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
        <input type="hidden" name="estimated_months" id="estimatedMonthsInput" value="">
        <?php
        // Ensure we have first/last name from DB when possible using session email as a reliable key.
        if (empty($user_first)) {
          $tryEmail = $_SESSION['email'] ?? $_SESSION['username'] ?? null;
          if (!empty($tryEmail)) {
            try {
              $stmtName = $pdo->prepare("SELECT first_name, last_name FROM users WHERE email = :email LIMIT 1");
              $stmtName->execute([':email' => $tryEmail]);
              $rname = $stmtName->fetch(PDO::FETCH_ASSOC);
              if ($rname) {
                $user_first = trim((string)($rname['first_name'] ?? $rname['firstname'] ?? '')) ?: $user_first;
                $user_last  = trim((string)($rname['last_name'] ?? $rname['lastname'] ?? '')) ?: $user_last;
              }
            } catch (Exception $e) { /* ignore lookup failures */ }
          }
        }
        $user_full = trim(($user_first ? $user_first : '') . ' ' . ($user_last ? $user_last : ''));
        ?>
        <!-- expose current user contact fields to the page JS so the review can show user info -->
        <input type="hidden" id="currentUserFirst" value="<?= htmlspecialchars($user_first ?? '') ?>">
        <input type="hidden" id="currentUserLast" value="<?= htmlspecialchars($user_last ?? '') ?>">
        <input type="hidden" id="currentUserFull" value="<?= htmlspecialchars($user_full ?? ($user_first . ' ' . $user_last)) ?>">
        <input type="hidden" id="currentUserEmail" value="<?= htmlspecialchars($user_email_display ?? $_SESSION['email'] ?? $_SESSION['username'] ?? '') ?>">
        <input type="hidden" id="currentUserPhone" value="<?= htmlspecialchars($user_phone ?? $_SESSION['phone_number'] ?? '') ?>">
        <input type="hidden" id="currentUserAddress" value="<?= htmlspecialchars($user_address ?? '') ?>">
        <input type="hidden" id="currentUserCity" value="<?= htmlspecialchars($user_city ?? '') ?>">
        <input type="hidden" id="currentUserState" value="<?= htmlspecialchars($user_state ?? '') ?>">
        <input type="hidden" id="currentUserZip" value="<?= htmlspecialchars($user_zip ?? '') ?>">
        <input type="hidden" id="currentUserCompany" value="<?= htmlspecialchars($user_company ?? '') ?>">
        <input type="hidden" id="currentUserDisplay" value="<?= htmlspecialchars($user_display_name ?? '') ?>">
        <input type="hidden" id="currentUserNameOnly" value="<?= htmlspecialchars($user_username ?? '') ?>">
        <!-- Check Availability button placed directly under the Total Cost inside the card -->
        <div class="reservation-card-actions-inline" style="display:flex;flex-direction:column;justify-content:center;align-items:center;margin-top:82px;margin-bottom:15px;gap:0;">
          <button type="submit" name="check_availability" class="btn-primary" style="background:#F2C36A;color:#0f2540;padding:12px 37.5px;border-radius:8px;font-weight:800;letter-spacing:0.3px;line-height:1.1;font-size:16px;width:auto !important;max-width:none !important;display:inline-block !important;">Check Availability</button>
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
                  <option value="<?= htmlspecialchars($opt['id']) ?>"><?= htmlspecialchars($opt['location_code'] ?? ('Slip ' . $opt['id'])) ?></option>
                <?php endforeach; ?>
              </select>
              <!-- Marina slip map (visual reference) -->
              <style>
                .slip-size-group{margin-bottom:14px}
                .slip-size-group h4{margin:6px 0 8px;font-size:0.98rem;color:#102535}
                .slip-columns{display:flex;gap:12px}
                .slip-column{flex:1;min-width:0}
                .slip-column .col-label{font-weight:800;text-align:center;margin-bottom:6px;color:#1F2F45}
                .slip-column .slip{margin-bottom:8px}
              </style>
              <div class="slip-map" id="availableSlipMap" style="margin-top:.75rem;">
                <div class="slip-grid" id="slipGrid">
                  <?php
                    // Group available slips by slip_size and render three columns (A/B/C) per size
                    $groups = [];
                    foreach ($availableSlips as $s) {
                      if (empty($s['available'])) continue;
                      $sizeKey = intval($s['size'] ?? $s['slip_size'] ?? 0);
                      if (!isset($groups[$sizeKey])) $groups[$sizeKey] = [];
                      $groups[$sizeKey][] = $s;
                    }
                    if (!empty($groups)) {
                      // sort sizes descending (largest first) for better UX
                      krsort($groups, SORT_NUMERIC);
                      foreach ($groups as $size => $slipsBySize):
                  ?>
                    <div class="slip-size-group" data-size="<?= intval($size) ?>">
                      <h4>Size: <?= intval($size) ?> ft</h4>
                      <div class="slip-columns">
                        <?php
                          // distribute slips round-robin into three columns
                          $cols = [[],[],[]]; $ci = 0;
                          foreach ($slipsBySize as $ss) { $cols[$ci % 3][] = $ss; $ci++; }
                          $labels = ['A','B','C'];
                          for ($c = 0; $c < 3; $c++):
                        ?>
                          <div class="slip-column">
                            <div class="col-label"><?= $labels[$c] ?></div>
                            <?php foreach ($cols[$c] as $tile):
                              $isAvail = !empty($tile['available']);
                              $sid = htmlspecialchars($tile['id']);
                              $loc = htmlspecialchars($tile['location_code'] ?? ('Slip ' . $tile['id']));
                            ?>
                              <div class="slip <?= $isAvail ? 'available' : 'unavailable' ?>" data-slip-id="<?= $sid ?>" data-slip-size="<?= htmlspecialchars($tile['slip_size'] ?? $tile['size'] ?? '') ?>" role="button" tabindex="0" aria-pressed="false" data-slip-location="<?= $loc ?>">
                                <div class="meta"><?= $loc ?></div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endfor; ?>
                      </div>
                    </div>
                  <?php
                      endforeach;
                    }
                  ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
      <?php endif; ?>

      <div class="form-actions">

        <!-- Confirm stays in form-actions when available -->
        <?php /* Confirm button is rendered by client-side JS when needed; suppress server-side output to avoid stale UI on reload */ ?>

      </div>

    <!-- Move actions outside the form so it's absolutely positioned relative to the card -->
  </form>
<?php endif; ?>

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
  window.formatDateYMD = function(d){
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }
  window.parseDateYMD = function(ymd){
    if(!ymd) return null;
    const parts = String(ymd).split('-').map(p=>parseInt(p,10));
    if(parts.length < 3 || parts.some(isNaN)) return null;
    return new Date(parts[0], parts[1]-1, parts[2]);
  }
  const isoToday = formatDateYMD(today);
  start.min = isoToday;
  // Do not auto-fill the start date on page load; user must choose it.
  
  // (Page reload clearing is handled later after all controls are bound)

  function setEndMinFromStart(){
    if(!start.value) return;
    const s = parseDateYMD(start.value);
    const minEnd = new Date(s);
    // add 30 days (one month defined as 30 days)
    minEnd.setDate(minEnd.getDate() + 30);
    const minYmd = formatDateYMD(minEnd);
    end.min = minYmd;
    // Automatically select end date one month (30 days) from the chosen start date.
    end.value = minYmd;
  }
  setEndMinFromStart();
  start.addEventListener('change', setEndMinFromStart);
  
  // references for add-boat controls (used by cost/enforcement functions)
  const addBoatPanel = document.getElementById('addBoatPanel');
  const newBoatName = document.getElementById('newBoatName');
  const newBoatLength = document.getElementById('newBoatLength');
  const saveNewBoat = document.getElementById('saveNewBoat');
  const addBoatBtn = document.getElementById('addBoatBtn');

  // Centralized Add-Boat visibility control: hide by default via CSS
  (function(){
    try{
      if(!document.querySelector('style[data-addboat-style]')){
        const css = '\n.reservation-card .form-group.add-boat{display:none !important;}\n.reservation-card.show-add-boat .form-group.add-boat{display:block !important;}\n#addBoatBtn.hidden{display:none !important;}\n';
        const s = document.createElement('style'); s.setAttribute('data-addboat-style','1'); s.appendChild(document.createTextNode(css));
        (document.head || document.documentElement).appendChild(s);
      }
    }catch(e){}
  })();

  function setShowAddBoat(show){
    try{ const card = document.querySelector('.reservation-card'); if(card) card.classList.toggle('show-add-boat', !!show); }
    catch(e){}
  }

  function updateAddBoatVisibility(){
    if(!addBoatBtn) return;
    const isAddNew = boatSelect && boatSelect.value === 'add_new';
    const nameFilled = newBoatName && newBoatName.value.trim() !== '';
    const lenFilled = newBoatLength && newBoatLength.value.trim() !== '';
    // Show the Add Boat panel when user selects "Add a new boat".
    // Only show the Add Boat submit button once both fields are filled.
    const panelVisible = !!isAddNew;
    const buttonVisible = isAddNew && nameFilled && lenFilled;
    if (addBoatBtn) {
      addBoatBtn.classList.toggle('hidden', !buttonVisible);
      addBoatBtn.disabled = !buttonVisible;
      // control inline style to ensure hidden state in case of CSS conflicts
      try { addBoatBtn.style.display = buttonVisible ? '' : 'none'; } catch(e){}
    }
    // ensure the panel itself is shown when add_new selected
    if (addBoatPanel) {
      try { setShowAddBoat(panelVisible); } catch(e){}
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
        setShowAddBoat(true);
      } else {
        setShowAddBoat(false);
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
      if(addBoatPanel) setShowAddBoat(false);
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
      // ensure add-boat panel behavior still works even if earlier script returned early
      try{
        var boatSelectEl = document.getElementById('boatSelect');
        var addBoatPanelEl = document.getElementById('addBoatPanel');
        var newBoatNameEl = document.getElementById('newBoatName');
        if(boatSelectEl){
          boatSelectEl.addEventListener('change', function(){
            if(this.value === 'add_new'){
              if(addBoatPanelEl) addBoatPanelEl.style.display = 'block';
              if(newBoatNameEl) newBoatNameEl.focus();
            } else {
              if(addBoatPanelEl) addBoatPanelEl.style.display = 'none';
            }
          });
        }
      }catch(e){}
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

// Fallback binding: ensure Add Boat panel and button behavior is always attached
(function(){
  function bindAddBoatToggle(){
    try{
      var sel = document.getElementById('boatSelect');
      var panel = document.getElementById('addBoatPanel');
      var name = document.getElementById('newBoatName');
      var len = document.getElementById('newBoatLength');
      var addBtn = document.getElementById('addBoatBtn');
      if(!sel) return;
      function update(){
        var isAdd = sel.value === 'add_new';
        if(isAdd){ if(panel) panel.style.display = 'block'; }
        else { if(panel) panel.style.display = 'none'; }
        var showBtn = isAdd && name && name.value.trim() !== '' && len && len.value.trim() !== '';
        if(addBtn){ addBtn.style.display = showBtn ? '' : 'none'; addBtn.disabled = !showBtn; }
      }
      // ensure change handler focuses the name field only when selection changes to add_new
      sel.removeEventListener('change', update);
      sel.addEventListener('change', function(e){
        update();
        try{ if(sel.value === 'add_new' && name) name.focus(); }catch(err){}
      });
      if(name){ name.removeEventListener('input', update); name.addEventListener('input', update); }
      if(len){ len.removeEventListener('input', update); len.addEventListener('input', update); }
      update();
    }catch(e){ /* ignore */ }
  }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindAddBoatToggle);
  else bindAddBoatToggle();
})();

// AJAX availability check: intercept Check Availability and request JSON
(function(){
  const availBtn = document.querySelector('button[name="check_availability"]');
  const form = document.getElementById('reservationForm');
  // ensure bottom confirm is hidden until step 3
  try{ const confirmInit = document.getElementById('confirmBtn'); if(confirmInit) confirmInit.style.display = 'none'; }catch(e){}
  if(!availBtn || !form) return;

  // track current flow step (1-based)
  let currentFlowStep = 1;
  // helper: format a Y-M-D string to 'Month Day, Year'
  function formatFriendlyDate(ymd){
    if(!ymd) return '';
    try{
      const d = window.parseDateYMD ? window.parseDateYMD(ymd) : new Date(ymd);
      if(!d) return ymd;
      const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }catch(e){ return ymd; }
  }

  // helper: clear the reservation form and any availability/review UI, then focus start date
  function clearReservationForm(){
    try{
      const form = document.getElementById('reservationForm');
      if(!form) return;
      const start = form.querySelector('input[name="start_date"]');
      const end = form.querySelector('input[name="end_date"]');
      const boat = form.querySelector('select[name="boat_id"]');
      const slip = form.querySelector('select[name="slip_size"]');
      const addBoatPanel = document.getElementById('addBoatPanel');
      const newBoatName = document.getElementById('newBoatName');
      const newBoatLength = document.getElementById('newBoatLength');
      // clear values
      if(start) start.value = '';
      if(end) end.value = '';
      if(boat) { try{ boat.selectedIndex = 0; Array.from(boat.options||[]).forEach(o=>o.selected=false); }catch(e){ boat.value = ''; } }
      if(slip) { try{ slip.selectedIndex = 0; Array.from(slip.options||[]).forEach(o=>o.selected=false); }catch(e){ slip.value = ''; } }
      if(addBoatPanel) setShowAddBoat(false);
      if(newBoatName) newBoatName.value = '';
      if(newBoatLength) newBoatLength.value = '';
      // remove availability UI
      const wrapper = document.getElementById('availableSlipWrapper'); if(wrapper) wrapper.remove();
      const map = document.getElementById('availableSlipMap'); if(map) map.remove();
      const marina = document.getElementById('marinaCard'); if(marina) marina.remove();
      const clientMsg = document.getElementById('clientAvailabilityMsg'); if(clientMsg) clientMsg.remove();
      const slipGrid = document.getElementById('slipGrid'); if(slipGrid) slipGrid.remove();
      const selectBtn = document.getElementById('selectSlipBtn'); if(selectBtn) selectBtn.remove();
      // also remove any stray select buttons by class
      try{ document.querySelectorAll('.select-slip-btn').forEach(x=>x.remove()); }catch(e){}
      // remove any alert or header that mentions available slips (inserted before the form)
      try{
        const prev = form.previousElementSibling;
        if(prev && prev.textContent && prev.textContent.indexOf('Slips available') !== -1) prev.remove();
        document.querySelectorAll('.alert').forEach(function(a){ if(a.textContent && a.textContent.indexOf('Slips available')!==-1) a.remove(); });
      }catch(e){}
      // aggressively remove any remaining nodes under the reservation card that mention availability
      try{
        const card = document.querySelector('.reservation-card');
        if(card){
          Array.from(card.querySelectorAll('*')).forEach(function(el){
            try{
              const t = (el.textContent||'').trim();
              if(!t) return;
              if(t.indexOf('Slips available') !== -1) el.remove();
              if(t.indexOf('Select this slip') !== -1) el.remove();
            }catch(e){}
          });
        }
      }catch(e){}
      // global cleanup for any remaining availability nodes (targeted phrases only)
      try{
        const phrases = ['Slips available', 'Choose one from the list', 'Select this slip'];
        document.querySelectorAll('body *').forEach(function(n){
          try{
            const txt = (n.textContent||'').trim();
            if(!txt) return;
            for(const p of phrases){ if(txt.indexOf(p) !== -1){ n.remove(); break; } }
          }catch(e){}
        });
      }catch(e){}
      // clear slip grid selections
      try{ document.querySelectorAll('#slipGrid .slip.selected').forEach(x=>x.classList.remove('selected')); }catch(e){}
      // remove review and hide confirm
      const review = document.getElementById('reservationReviewPanel'); if(review) review.remove();
      const confirmBtn = document.getElementById('confirmBtn'); if(confirmBtn) confirmBtn.style.display = 'none';
      // restore any inline/action displays to the Step 1 baseline
      try{ const costCard = form.querySelector('.reservation-cost'); if(costCard) costCard.style.display = ''; }catch(e){}
      try{ const inlineActions = form.querySelector('.reservation-card-actions-inline'); if(inlineActions) inlineActions.style.display = ''; }catch(e){}
      try{ const clientMsg2 = document.getElementById('clientAvailabilityMsg'); if(clientMsg2) clientMsg2.style.display = ''; }catch(e){}
      // clear estimated fields and displays
      try{ document.getElementById('estimatedCostInput').value = ''; }catch(e){}
      try{ document.getElementById('estimatedBaseInput').value = ''; }catch(e){}
      try{ document.getElementById('estimatedHookupInput').value = ''; }catch(e){}
      try{ document.getElementById('estimatedMonthsInput').value = ''; }catch(e){}
      try{ document.getElementById('reservationCostTotalFinal').textContent = '$0.00'; }catch(e){}
      try{ document.getElementById('reservationMonthsDisplay').textContent = '0'; }catch(e){}
      try{ document.getElementById('reservationLengthDisplay').textContent = '0'; }catch(e){}
      // set flow back to step 1 visuals
      try{ setFlowStep(1); }catch(e){}
      if(start) try{ start.focus(); }catch(e){}
    }catch(e){ /* ignore */ }
  }
  // Helper: set the active reservation step (1-based)
  function setFlowStep(n){
    try{
      currentFlowStep = n;
      // Toggle a page-scoped class on the reservation card so CSS can reliably
      // hide/show input groups and style the confirm button for Step 3.
      try{ const card = document.querySelector('.reservation-card'); if(card) card.classList.toggle('flow-step-3', n === 3); }catch(e){}
      // Inline fallback: ensure the confirm button is styled/centered even when
      // other page CSS wins on specificity. We only set inline styles for step 3
      // and remove them when leaving step 3 to avoid affecting other pages.
      try{
        const cb = document.getElementById('confirmBtn');
        if(cb){
          if(n === 3){
            cb.classList.add('btn-secondary');
            cb.style.display = 'block';
            cb.style.margin = '16px auto';
            cb.style.background = '#3F87A6';
            cb.style.color = '#ffffff';
            cb.style.border = 'none';
            cb.style.padding = '12px 25px';
            cb.style.borderRadius = '8px';
            cb.style.fontWeight = '700';
            cb.style.minWidth = '200px';
            cb.style.boxShadow = '0 6px 18px rgba(63,135,166,0.12)';
            cb.style.textAlign = 'center';
          } else {
            // remove inline styling we set earlier so normal styles resume
            cb.style.display = '';
            cb.style.margin = '';
            cb.style.background = '';
            cb.style.color = '';
            cb.style.border = '';
            cb.style.padding = '';
            cb.style.borderRadius = '';
            cb.style.fontWeight = '';
            cb.style.minWidth = '';
            cb.style.boxShadow = '';
            cb.style.textAlign = '';
          }
        }
      }catch(e){}
      // Enforce Add Boat panel visibility: only show when the user explicitly selected 'add_new'
      try{
        const boatSel = document.querySelector('select[name="boat_id"]');
        if(boatSel) setShowAddBoat(boatSel.value === 'add_new');
      }catch(e){}
      const steps = document.querySelectorAll('.reservation-steps .step');
      const NAVY = '#1F2F45';
      const GOLD = '#F4C26B';
      const BOAT_WHITE = '#F8F9FA';
      steps.forEach((el, idx) => {
        const isActive = (idx === (n-1));
        const isCompleted = idx < (n-1);
        el.classList.toggle('active', isActive);
        el.classList.toggle('completed', isCompleted);
        // ensure visual fallback: update circle and label inline in case CSS specificity
        const circle = el.querySelector('.step-circle');
        const label = el.querySelector('.step-label');
        if(circle){
          if(isActive || isCompleted){
            circle.style.background = GOLD;
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
        // Keep step label color unchanged; only the circle indicates state.
      });

      // page-specific behavior: hide inputs and swap buttons when on step 3
      try{
        const hide = (n === 3);
        const groups = [form.querySelector('.form-group.start-date'), form.querySelector('.form-group.end-date'), form.querySelector('.form-group.select-boat'), form.querySelector('.form-group.slip-size'), form.querySelector('.form-group.add-boat')];
        groups.forEach(g => { if(g) g.style.display = hide ? 'none' : ''; });
        // hide reservation cost card and top inline actions and availability message on step 3
        try{ const costCard = form.querySelector('.reservation-cost'); if(costCard) costCard.style.display = hide ? 'none' : ''; }catch(e){}
        try{ const inlineActions = form.querySelector('.reservation-card-actions-inline'); if(inlineActions) inlineActions.style.display = hide ? 'none' : ''; }catch(e){}
        try{ const clientMsg = document.getElementById('clientAvailabilityMsg'); if(clientMsg) clientMsg.style.display = hide ? 'none' : ''; }catch(e){}

        // show the bottom confirm button on step 3 (keep the top Check Availability unchanged)
        try{
          let confirmBtn = document.getElementById('confirmBtn');
          if(!confirmBtn){
            // create bottom confirm button if server did not render it
            const actionsWrap = form.querySelector('.form-actions') || form;
            confirmBtn = document.createElement('button');
            confirmBtn.id = 'confirmBtn'; confirmBtn.type = 'submit'; confirmBtn.name = 'confirm_reservation'; confirmBtn.className = 'btn-secondary'; confirmBtn.style.display = 'none'; confirmBtn.textContent = 'Confirm & Continue';
            actionsWrap.appendChild(confirmBtn);
          }
          if(hide){
            confirmBtn.style.display = '';
            confirmBtn.textContent = 'Confirm Reservation';
            confirmBtn.name = 'confirm_reservation';
            confirmBtn.type = 'submit';
          } else {
            confirmBtn.style.display = 'none';
            confirmBtn.textContent = 'Confirm & Continue';
            confirmBtn.name = 'confirm_reservation';
            confirmBtn.type = 'submit';
          }
        }catch(e){}

        if(n === 3){
          let review = document.getElementById('reservationReviewPanel');
          if(!review){
            review = document.createElement('div'); review.id = 'reservationReviewPanel'; review.className = 'reservation-review';
            const panel = document.createElement('div'); panel.className = 'panel-inner';
            const start = (form.querySelector('input[name="start_date"]')||{}).value || '';
            const end = (form.querySelector('input[name="end_date"]')||{}).value || '';
            const selVal = (document.getElementById('selected_slip_id')||{}).value || '';
            const chosenEl = selVal ? document.querySelector('#slipGrid .slip[data-slip-id="'+selVal+'"]') : null;
            const loc = chosenEl ? (chosenEl.getAttribute('data-slip-location')||'') : '';
            const sizeVal = chosenEl ? (chosenEl.getAttribute('data-slip-size')||'') : '';
            const total = (document.getElementById('estimatedCostInput')||{}).value || '';
            const months = (document.getElementById('estimatedMonthsInput')||{}).value || '';
            // build grouped review DOM structure: user, boat, slip & dates, costs
            panel.innerHTML = '';
                    const hdr = document.createElement('h3'); hdr.textContent = 'Review Reservation'; hdr.style.textAlign = 'center'; hdr.style.marginTop = '6px'; hdr.style.marginBottom = '14px'; hdr.style.fontSize = '24px'; hdr.style.fontWeight = '800'; hdr.style.gridColumn = '1 / -1'; panel.appendChild(hdr);
            // User | Boat | Slip (three-column layout) — append sections directly so CSS grid applies
            const userSection = document.createElement('div'); userSection.className = 'section user-section'; userSection.style.flex = '1'; userSection.style.textAlign = 'center';
                                  const userLabel = document.createElement('div'); userLabel.className = 'label'; userLabel.textContent = 'Your Information'; userLabel.style.marginTop = '12px';
                          // Prefer a combined DB-provided full name when present
                          const full = (document.getElementById('currentUserFull')||{}).value || '';
                          const first = (document.getElementById('currentUserFirst')||{}).value || '';
                          const last = (document.getElementById('currentUserLast')||{}).value || '';
                          const email = (document.getElementById('currentUserEmail')||{}).value || '';
                          const phone = (document.getElementById('currentUserPhone')||{}).value || '';
                          const addr = (document.getElementById('currentUserAddress')||{}).value || '';
                          const city = (document.getElementById('currentUserCity')||{}).value || '';
                          const state = (document.getElementById('currentUserState')||{}).value || '';
                          const zip = (document.getElementById('currentUserZip')||{}).value || '';
                          const company = (document.getElementById('currentUserCompany')||{}).value || '';
                          const displayHidden = (document.getElementById('currentUserDisplay')||{}).value || '';
                          const uname = (document.getElementById('currentUserNameOnly')||{}).value || '';
                          const userVal = document.createElement('div'); userVal.className = 'value';
                          // Build a robust display name: prefer DB combined, then first+last, then explicit display/username, then try email local-part
                          let displayName = full || [first, last].map(s=> (s||'').trim()).filter(Boolean).join(' ') || displayHidden || uname || '';
                          if(!displayName){
                            if(window.currentUserName) displayName = window.currentUserName;
                            else if(email){
                              try{
                                const local = String(email).split('@')[0]||'';
                                const parts = local.replace(/[._]+/g,' ').split(' ').filter(Boolean);
                                if(parts.length) displayName = parts.map(p=>p.charAt(0).toUpperCase()+p.slice(1)).join(' ');
                                else displayName = email;
                              }catch(e){ displayName = email; }
                            }
                          }
                          // Build address block if present
                          let addrBlock = '';
                          const locality = [city, state, zip].filter(Boolean).join(', ');
                          if(addr) addrBlock = addr + (locality ? '<br>' + locality : '');
                          else if(locality) addrBlock = locality;
                          // Company on its own line if present
                          const companyLine = company ? (company + '<br>') : '';
                          // Build contact lines with full-size email/phone and muted address
                          const contactParts = [];
                          if(email && email !== displayName) contactParts.push('<div class="value" style="margin-top:6px">' + email + '</div>');
                          if(phone) contactParts.push('<div class="value">' + phone + '</div>');
                          if(addrBlock) contactParts.push('<div class="value" style="color:#6b7280;font-size:0.95rem">' + addrBlock + '</div>');
                          // Compose final HTML: display name (and company), then contact parts (email/phone same size as values)
                          const nameHtml = (displayName || '') + (company ? '<br><small style="color:#6b7280;">' + company + '</small>' : '');
                          const contactHtml = contactParts.length ? ('<br>' + contactParts.join('')) : '';
                          const setInner = (n, c) => { userVal.innerHTML = n + c; };
                          if(displayName && (phone || email || contactLines.length)){
                            setInner(nameHtml, contactHtml);
                          } else {
                            // attempt to fetch fresh DB-backed user info from this page
                            try{
                              fetch(window.location.pathname + '?fetch_current_user=1', { credentials: 'same-origin' })
                                .then(resp => resp.json())
                                .then(data => {
                                  const fetchedName = data.full || (data.first && data.last ? (data.first + ' ' + data.last) : (data.display || data.username || '')) || '';
                                  const fetchedEmail = data.email || email || '';
                                  const fetchedPhone = data.phone || phone || '';
                                  const fetchedCompany = data.company || company || '';
                                  const fetchedAddr = data.address || '';
                                  const fetchedLocality = [data.city||'', data.state||'', data.zip||''].filter(Boolean).join(', ');
                                  let fetchedAddrBlock = '';
                                  if(fetchedAddr) fetchedAddrBlock = fetchedAddr + (fetchedLocality ? '<br>' + fetchedLocality : '');
                                  else if(fetchedLocality) fetchedAddrBlock = fetchedLocality;
                                  const fetchedParts = [];
                                  if(fetchedEmail && fetchedEmail !== fetchedName) fetchedParts.push('<div class="value" style="margin-top:6px">' + fetchedEmail + '</div>');
                                  if(fetchedPhone) fetchedParts.push('<div class="value">' + fetchedPhone + '</div>');
                                  if(fetchedAddrBlock) fetchedParts.push('<div class="value" style="color:#6b7280;font-size:0.95rem">' + fetchedAddrBlock + '</div>');
                                  const fetchedNameHtml = (fetchedName || nameHtml) + (fetchedCompany ? '<br><small style="color:#6b7280;">' + fetchedCompany + '</small>' : '');
                                  const fetchedContactHtml = fetchedParts.length ? ('<br>' + fetchedParts.join('')) : contactHtml;
                                  setInner(fetchedNameHtml, fetchedContactHtml);
                                }).catch(()=> setInner(nameHtml, contactHtml));
                            }catch(e){ setInner(nameHtml, contactHtml); }
                          }
            userSection.appendChild(userLabel); userSection.appendChild(userVal);
                // Boat details section (middle)
                const boatOpt = form.querySelector('select[name="boat_id"] option:checked');
                const boatLabel = boatOpt ? boatOpt.textContent.trim() : '';
                const boatLen = (boatOpt && boatOpt.getAttribute) ? (boatOpt.getAttribute('data-length') || '') : '';
                const boatSection = document.createElement('div'); boatSection.className = 'section boat-section'; boatSection.style.flex = '1';
                boatSection.style.textAlign = 'center';
                  const boatLab = document.createElement('div'); boatLab.className='label'; boatLab.textContent = 'Boat'; boatLab.style.marginTop = '12px';
                const boatVal = document.createElement('div'); boatVal.className='value';
                const boatMatch = boatLabel ? boatLabel.match(/^(.*)\s*\(\s*\d+\s*ft\s*\)$/) : null;
                const boatNameOnly = boatMatch ? boatMatch[1].trim() : boatLabel;
                boatVal.textContent = boatNameOnly || '';
                boatSection.appendChild(boatLab); boatSection.appendChild(boatVal);
                if(boatLen){ const boatLenEl = document.createElement('div'); boatLenEl.className='value'; boatLenEl.style.marginTop='6px'; boatLenEl.textContent = boatLen + ' ft'; boatSection.appendChild(boatLenEl); }

                // If user chose to add a new boat, display the entered boat info (no inputs) and offer a Save button
                try{
                  const boatSelVal = (form.querySelector('select[name="boat_id"]')||{}).value || '';
                  if(boatSelVal === 'add_new'){
                    const newName = (form.querySelector('#newBoatName')||form.querySelector('input[name="new_boat_name"]')||{}).value || '';
                    const newLen = (form.querySelector('#newBoatLength')||form.querySelector('input[name="new_boat_length"]')||{}).value || '';
                    if(newName){
                      // show entered boat name and length in the review (no inputs)
                      boatVal.textContent = newName;
                      if(newLen){ const boatLenEl2 = document.createElement('div'); boatLenEl2.className='value'; boatLenEl2.style.marginTop='6px'; boatLenEl2.textContent = newLen + ' ft'; boatSection.appendChild(boatLenEl2); }
                      // add Save button under the boat info (Ocean Blue style)
                      const saveBtn = document.createElement('button'); saveBtn.type = 'button'; saveBtn.id = 'saveNewBoatBtn'; saveBtn.className = 'btn-secondary'; saveBtn.textContent = 'Save boat to profile';
                      saveBtn.style.display = 'block'; saveBtn.style.margin = '12px auto 0 auto'; saveBtn.style.background = '#3F87A6'; saveBtn.style.color = '#ffffff'; saveBtn.style.border = 'none'; saveBtn.style.padding = '10px 18px'; saveBtn.style.borderRadius = '8px'; saveBtn.style.fontWeight = '700';
                      boatSection.appendChild(saveBtn);
                      saveBtn.addEventListener('click', function(){
                        try{
                          const tmp = document.createElement('form'); tmp.method = 'POST'; tmp.action = window.location.pathname; tmp.style.display = 'none';
                          const addHidden = function(n,v){ const i = document.createElement('input'); i.type='hidden'; i.name = n; i.value = v; tmp.appendChild(i); };
                          addHidden('add_boat','1');
                          addHidden('start_date', (form.querySelector('input[name="start_date"]')||{}).value || '');
                          addHidden('end_date', (form.querySelector('input[name="end_date"]')||{}).value || '');
                          addHidden('slip_size', (form.querySelector('select[name="slip_size"]')||{}).value || '');
                          addHidden('boat_id','add_new');
                          addHidden('new_boat_name', newName || '');
                          addHidden('new_boat_length', newLen || '');
                          document.body.appendChild(tmp);
                          tmp.submit();
                        }catch(err){ }
                      });
                    }
                  }
                }catch(e){}

                // Slip details (right column)
                const slipSection = document.createElement('div'); slipSection.className = 'section slip-section'; slipSection.style.textAlign = 'center'; slipSection.style.flex = '1';
                  const slipLab = document.createElement('div'); slipLab.className='label'; slipLab.textContent = 'Slip'; slipLab.style.marginTop = '12px';
                slipSection.appendChild(slipLab);
                const slipInfo = document.createElement('div'); slipInfo.style.marginBottom = '6px';
                const slipLoc = document.createElement('div'); slipLoc.className='value'; slipLoc.textContent = loc || 'n/a'; slipInfo.appendChild(slipLoc);
                if (sizeVal && String(sizeVal).trim() !== '') {
                  const slipSizeLine = document.createElement('div'); slipSizeLine.className = 'value'; slipSizeLine.style.marginTop = '6px'; slipSizeLine.textContent = String(sizeVal) + ' ft'; slipInfo.appendChild(slipSizeLine);
                }
                slipSection.appendChild(slipInfo);
                // dates (placed under slip info inside the right column)
                const startFriendly = formatFriendlyDate(start);
                const endFriendly = formatFriendlyDate(end);
                const dateLabel = document.createElement('div'); dateLabel.className = 'label'; dateLabel.textContent = 'Dates'; dateLabel.style.marginTop = '12px'; dateLabel.style.textAlign = 'center';
                // reduce vertical gap below the Dates header so it aligns with surrounding content
                dateLabel.style.marginBottom = '2px';
                const dateWrap = document.createElement('div'); dateWrap.className = 'dates'; dateWrap.style.marginTop = '0px'; dateWrap.style.marginBottom = '0px'; dateWrap.style.fontWeight = '400';
                // center dates horizontally
                dateWrap.style.display = 'flex'; dateWrap.style.justifyContent = 'center'; dateWrap.style.width = '100%';
                const dateEl = document.createElement('div'); dateEl.className='value'; dateEl.textContent = startFriendly + ' — ' + endFriendly; dateEl.style.textAlign = 'center'; dateEl.style.marginTop = '0px'; dateWrap.appendChild(dateEl);
                // move Dates out of the right column and place into the center column of the panel grid
                dateLabel.style.gridColumn = '2 / 3'; dateLabel.style.textAlign = 'center';
                dateWrap.style.gridColumn = '2 / 3'; dateWrap.style.justifySelf = 'center';
                panel.appendChild(dateLabel); panel.appendChild(dateWrap);

                panel.appendChild(userSection); panel.appendChild(boatSection); panel.appendChild(slipSection);
            // Costs section
            const costsSection = document.createElement('div'); costsSection.className = 'section costs-section'; costsSection.style.textAlign = 'center';
                    const costsLab = document.createElement('div'); costsLab.className='label'; costsLab.textContent = 'Costs'; costsLab.style.marginTop = '20px';
            costsSection.appendChild(costsLab);
            function addCostRow(labelText, amountText){ const row = document.createElement('div'); row.style.display = 'flex'; row.style.justifyContent = 'center'; row.style.alignItems = 'center'; row.style.padding = '4px 0'; row.style.gap = '6px'; const l = document.createElement('div'); l.className='label'; l.textContent = labelText; l.style.fontWeight = '700'; l.style.display = 'inline-block'; l.style.marginBottom = '0'; const a = document.createElement('div'); a.className='amount'; a.textContent = amountText || ''; a.style.fontWeight = '400'; a.style.display = 'inline-block'; a.style.marginBottom = '0'; row.appendChild(l); row.appendChild(a); costsSection.appendChild(row); }
            addCostRow('Base', '$' + ((document.getElementById('estimatedBaseInput')||{}).value || '0.00'));
            addCostRow('Hookup', '$' + ((document.getElementById('estimatedHookupInput')||{}).value || '0.00'));
            addCostRow('Duration', (months || '1') + ' month(s)');
            const tot = document.createElement('div'); tot.className = 'total'; tot.textContent = 'Total: $' + ((document.getElementById('estimatedCostInput')||{}).value ? parseFloat((document.getElementById('estimatedCostInput')||{}).value).toFixed(2) : '0.00'); tot.style.textAlign = 'center'; tot.style.fontWeight = '800'; tot.style.fontSize = '18px'; tot.style.marginTop = '12px'; costsSection.appendChild(tot);
            panel.appendChild(costsSection);
            // actions: Cancel + Confirm horizontally centered
            const cwrap = document.createElement('div'); cwrap.className = 'confirm-wrapper';
            cwrap.style.display = 'flex'; cwrap.style.justifyContent = 'center'; cwrap.style.gap = '12px'; cwrap.style.marginTop = '12px'; cwrap.style.width = '100%'; cwrap.style.alignItems = 'center';
            let confirmBtn = document.getElementById('confirmBtn');
            if(confirmBtn){ try{ confirmBtn.parentNode && confirmBtn.parentNode.removeChild(confirmBtn); }catch(e){} } else { confirmBtn = document.createElement('button'); confirmBtn.id='confirmBtn'; confirmBtn.type='submit'; confirmBtn.name='confirm_reservation'; confirmBtn.className='btn-secondary'; confirmBtn.textContent='Confirm Reservation'; }
            // style confirm (add bottom spacing so cancel sits comfortably underneath)
            confirmBtn.style.display = 'inline-block'; confirmBtn.style.background = '#3F87A6'; confirmBtn.style.color = '#fff'; confirmBtn.style.border = 'none'; confirmBtn.style.padding = '12px 25px'; confirmBtn.style.borderRadius = '8px'; confirmBtn.style.fontWeight = '700'; confirmBtn.style.minWidth = '200px'; confirmBtn.style.boxShadow = '0 6px 18px rgba(63,135,166,0.12)'; confirmBtn.style.margin = '0';
            // intercept confirm to prompt when user is reserving with an unsaved new boat
            try{
              confirmBtn.addEventListener('click', function(ev){
                try{
                  const boatSel = form.querySelector('select[name="boat_id"]');
                  if(boatSel && boatSel.value === 'add_new'){
                    // only during add-new-boat flow: prompt user to confirm reserving without saving
                    const ok = window.confirm('Reserve slip without saving boat?');
                    if(!ok){ ev.preventDefault(); return false; }
                    // ensure server receives explicit confirmation to proceed without saving
                    let hidden = document.getElementById('confirmWithoutSaveInput');
                    if(!hidden){ hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'confirm_without_save'; hidden.id = 'confirmWithoutSaveInput'; hidden.value = '1'; form.appendChild(hidden); }
                    else { hidden.value = '1'; }
                  }
                }catch(e){}
              });
            }catch(e){}
            // create cancel button styled with Coral outline/text (project cancel style)
            const cancelBtn = document.createElement('button'); cancelBtn.type='button'; cancelBtn.id='cancelBtn'; cancelBtn.className='btn-cancel'; cancelBtn.textContent = 'Cancel';
            const CORAL = '#FF6B6B';
            cancelBtn.style.background = 'transparent'; cancelBtn.style.color = CORAL; cancelBtn.style.border = '1px solid ' + CORAL; cancelBtn.style.padding = '12px 25px'; cancelBtn.style.borderRadius = '8px'; cancelBtn.style.minWidth = '140px';
            cancelBtn.addEventListener('click', function(){
              try{
                // Clear form and UI elements
                clearReservationForm();
                // Ensure step resets to 1
                try{ setFlowStep(1); }catch(e){}
                // Hide add-boat panel
                try{ setShowAddBoat(false); }catch(e){}
                // Remove review panel if present
                try{ const rev = document.getElementById('reservationReviewPanel'); if(rev) rev.remove(); }catch(e){}
                // Hide bottom confirm button
                try{ const cb = document.getElementById('confirmBtn'); if(cb) cb.style.display = 'none'; }catch(e){}
                // Hide any selectSlipBtn left behind
                try{ const sb = document.getElementById('selectSlipBtn'); if(sb) sb.style.display = 'none'; }catch(e){}
                // Scroll to top of reservation card
                // avoid auto-scrolling here so the header/menu remains visible
                try{ /* no-op: preserving user scroll position */ }catch(e){}
              }catch(err){ /* ignore */ }
            });
            // append confirm first so cancel appears under it (stacked column)
            cwrap.appendChild(confirmBtn); cwrap.appendChild(cancelBtn);
            panel.appendChild(cwrap);
            review.appendChild(panel);
            const actions = form.querySelector('.form-actions');
            // remove the add-boat input panel so the review appears cleanly without the inputs above it
            try{ const addPanel = document.getElementById('addBoatPanel'); if(addPanel) addPanel.remove(); }catch(e){}
            if(actions) actions.parentNode.insertBefore(review, actions);
            else form.appendChild(review);
          } else { review.style.display = ''; }
        } else {
          const review = document.getElementById('reservationReviewPanel'); if(review) review.remove();
        }
      }catch(e){}

    }catch(e){/* ignore */}
  }

  // ensure review CSS rules present to force horizontal centering for dates and action buttons
  (function(){
    try{
      if(!document.querySelector('style[data-sr-style]')){
        const css = '\n.reservation-review .dates{display:flex;justify-content:center;width:100% !important;}\n.reservation-review .dates .value{text-align:center !important;}\n.reservation-review h3{ text-align:center !important; margin-left:auto !important; margin-right:auto !important; }\n.reservation-review .panel-inner{ /* ensure predictable layout inside review */ display:grid; grid-template-columns:repeat(3,1fr); gap:24px; align-items:start; }\n.reservation-review .confirm-wrapper{display:flex;justify-content:center;gap:12px;margin-top:12px;width:100% !important;align-items:center; grid-column:1 / -1;}\n.reservation-review .confirm-wrapper button{margin:0 !important;}\n';
        const s = document.createElement('style'); s.setAttribute('data-sr-style','1'); s.appendChild(document.createTextNode(css));
        (document.head || document.documentElement).appendChild(s);
      }
    }catch(e){}
  })();

  availBtn.addEventListener('click', function(e){
    e.preventDefault();
    // update visual flow tracker to step 2 (Select Slip) as availability begins
    setFlowStep(2);
    // Ensure Add Boat panel only visible when user explicitly selected add_new
    try{
      const boatSel = document.querySelector('select[name="boat_id"]');
      if(boatSel) setShowAddBoat(boatSel.value === 'add_new');
    }catch(e){}
    // Ensure end date is set to start + 30 days if missing so server can search
    try{
      const startInput = form.querySelector('input[name="start_date"]');
      const endInput = form.querySelector('input[name="end_date"]');
      if(startInput && startInput.value && endInput && !endInput.value){
        const s = new Date(startInput.value);
        const minEnd = new Date(s);
        minEnd.setDate(minEnd.getDate() + 30);
        const ymd = formatDateYMD(minEnd);
        endInput.value = ymd;
        // update min as well
        endInput.min = ymd;
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
        if(!msg){
          msg = document.createElement('div'); msg.id = 'clientAvailabilityMsg';
          // Prefer inserting the availability message immediately after the action buttons
          // and just above the available slips wrapper so it's very visible to users.
          try{
            const actionsInline = form.querySelector('.reservation-card-actions-inline');
            const availWrapper = document.getElementById('availableSlipWrapper');
            if(actionsInline && actionsInline.parentNode){
              // place message after the buttons; if wrapper exists place before wrapper instead
              if(availWrapper && availWrapper.parentNode){
                availWrapper.parentNode.insertBefore(msg, availWrapper);
              } else {
                actionsInline.parentNode.insertBefore(msg, actionsInline.nextSibling);
              }
            } else if(availWrapper && availWrapper.parentNode){
              availWrapper.parentNode.insertBefore(msg, availWrapper);
            } else {
              form.parentNode.insertBefore(msg, form);
            }
          }catch(e){ try{ form.parentNode.insertBefore(msg, form); }catch(err){} }
        }
        msg.innerHTML = '';
        if(json.error){
          msg.className = 'alert alert-error'; msg.textContent = json.error; return;
        }
        const cnt = json.availableCount || 0;
        if(cnt > 0){
          msg.className = 'alert alert-success';
          msg.innerHTML = 'Slips available: ' + cnt + '.<br>Choose one from the list below. Check map for slip location.';
          // ensure tracker visually indicates step 2 (Select Slip)
          try{ setFlowStep(2); }catch(e){}
          // populate slip select grouped by size and render map + grid grouped by size
          let sel = document.getElementById('selected_slip_id');
          if(!sel){
            const wrapper = document.createElement('div'); wrapper.style.marginTop = '.75rem';
            wrapper.id = 'availableSlipWrapper';
            const label = document.createElement('label'); label.setAttribute('for','selected_slip_id'); label.textContent = 'Choose an available slip';
            sel = document.createElement('select'); sel.name = 'selected_slip_id'; sel.id = 'selected_slip_id'; sel.required = true;
            const opt0 = document.createElement('option'); opt0.value = ''; opt0.textContent = '-- Select a slip --'; sel.appendChild(opt0);
            wrapper.appendChild(label); wrapper.appendChild(sel);
            const actions = form.querySelector('.form-actions');
            if(actions) actions.parentNode.insertBefore(wrapper, actions);
          } else {
            sel.innerHTML = '<option value="">-- Select a slip --</option>';
          }

          // group slips by size
          const groups = {};
          (json.availableSlips || []).forEach(s => { if(!s.available) return; const key = String(s.size || '0'); (groups[key] = groups[key]||[]).push(s); });
          const sizes = Object.keys(groups).map(k=>parseInt(k,10)).sort((a,b)=>a-b);
          sizes.forEach(size => {
            const grp = groups[String(size)];
            if(!grp || !grp.length) return;
            // add optgroup to select
            const og = document.createElement('optgroup'); og.label = size + ' ft';
            grp.forEach(s => { const o = document.createElement('option'); o.value = s.id; o.textContent = (s.location_code || ('Slip ' + s.id)); og.appendChild(o); });
            sel.appendChild(og);
          });

          // render slip map and grid
          let wrapper = document.getElementById('availableSlipMap');
          if(!wrapper){
            wrapper = document.createElement('div'); wrapper.className = 'slip-map'; wrapper.id = 'availableSlipMap';
            // grid container
            const grid = document.createElement('div'); grid.className = 'slip-grid'; grid.id = 'slipGrid'; wrapper.appendChild(grid);
            const actions = form.querySelector('.form-actions');
            if(actions) actions.parentNode.insertBefore(wrapper, actions);
            else form.appendChild(wrapper);
            // create marina card (image only) and place after the slip map
            let marinaCard = document.getElementById('marinaCard');
            if(!marinaCard){
              marinaCard = document.createElement('div'); marinaCard.className = 'marina-card'; marinaCard.id = 'marinaCard';
              const img = document.createElement('img'); img.id = 'marinaMapImg'; img.src = 'marina_map.png'; img.alt = 'Marina Map';
              marinaCard.appendChild(img);
              wrapper.parentNode.insertBefore(marinaCard, wrapper.nextSibling);
            }
          } else {
            // clear existing grid; ensure marina card exists
            const grid = wrapper.querySelector('#slipGrid'); if(grid) grid.innerHTML = '';
            let marinaCard = wrapper.parentNode ? wrapper.parentNode.querySelector('#marinaCard') : null;
            if(!marinaCard){
              marinaCard = document.createElement('div'); marinaCard.className = 'marina-card'; marinaCard.id = 'marinaCard';
              const img = document.createElement('img'); img.id = 'marinaMapImg'; img.src = 'marina_map.png'; img.alt = 'Marina Map';
              marinaCard.appendChild(img);
              wrapper.parentNode.insertBefore(marinaCard, wrapper.nextSibling);
            }
          }

          const gridRoot = document.getElementById('slipGrid');
          // create per-size sections
          sizes.forEach(size => {
            const grp = groups[String(size)];
            if(!grp) return;
            const header = document.createElement('div'); header.style.fontWeight='700'; header.style.margin='8px 0'; header.textContent = size + ' ft slips';
            gridRoot.appendChild(header);
            const sub = document.createElement('div'); sub.className = 'slip-grid';
            grp.forEach(s => {
              const div = document.createElement('div');
              div.className = 'slip ' + (s.available ? 'available' : 'unavailable');
              div.setAttribute('data-slip-id', s.id);
              div.setAttribute('data-slip-location', s.location_code || ('Slip ' + s.id));
              div.setAttribute('data-slip-size', s.size || '');
              div.setAttribute('tabindex','0');
              div.innerHTML = '<div class="meta">'+(s.location_code || ('Slip '+s.id))+'</div>';
              sub.appendChild(div);
              // no inline legend needed here; the full marina map image is shown below
            });
            gridRoot.appendChild(sub);
          });

          // clicking a slip tile selects the option and shows Select button
          const grid = document.getElementById('slipGrid');
          grid.querySelectorAll('.slip.available').forEach(el=>{
            el.addEventListener('click', function(){
              const id = this.getAttribute('data-slip-id');
              sel.value = id; sel.dispatchEvent(new Event('change'));
              grid.querySelectorAll('.slip').forEach(x=>x.classList.remove('selected'));
              this.classList.add('selected');
              // reveal the select button (insert below the availability message when possible)
              let selectBtn = document.getElementById('selectSlipBtn');
              if(!selectBtn){
                selectBtn = document.createElement('button'); selectBtn.id = 'selectSlipBtn'; selectBtn.type = 'button'; selectBtn.className = 'select-slip-btn'; selectBtn.textContent = 'Select this slip';
                const clientMsg = document.getElementById('clientAvailabilityMsg');
                if(clientMsg && clientMsg.parentNode){ clientMsg.parentNode.insertBefore(selectBtn, clientMsg.nextSibling); }
                else {
                  const availBtn = form.querySelector('button[name="check_availability"]');
                  if(availBtn && availBtn.parentNode){ availBtn.parentNode.insertBefore(selectBtn, availBtn.nextSibling); }
                  else { const actions = form.querySelector('.form-actions'); if(actions) actions.parentNode.insertBefore(selectBtn, actions); else form.appendChild(selectBtn); }
                }
              }
              selectBtn.style.display = 'block';
              selectBtn.style.margin = '12px auto';
            });
            el.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); } });
          });

          // handle Select this slip button click
          document.addEventListener('click', function(ev){
            const btn = ev.target && ev.target.id === 'selectSlipBtn' ? ev.target : null;
            if(!btn) return;
            (function(){
              // Robustly determine the chosen slip id: prefer the select value, fall back to the selected tile
              const selEl = document.getElementById('selected_slip_id');
              let chosenId = (selEl && selEl.value) ? selEl.value : null;
              if(!chosenId){
                const chosenTile = document.querySelector('#slipGrid .slip.selected');
                if(chosenTile) chosenId = chosenTile.getAttribute('data-slip-id');
              }
              if(!chosenId){
                alert('Please choose a slip first.');
                return;
              }
              // Ensure a select input exists and reflects the chosen id so server receives it on submit
              if(selEl){ selEl.value = chosenId; selEl.dispatchEvent(new Event('change')); }
              else {
                const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'selected_slip_id'; hidden.id = 'selected_slip_id'; hidden.value = chosenId; form.appendChild(hidden);
              }
              // move flow to step 3 (Review & Confirm)
              try{ setFlowStep(3); }catch(e){}
              // ensure confirm button visible and focused (do not auto-scroll so menu stays visible)
              const confirmBtn = document.getElementById('confirmBtn'); if(confirmBtn){ confirmBtn.style.display = ''; try{ confirmBtn.focus(); }catch(e){} }
              // hide availability UI and show a concise review panel
              try{
                const availWrapper = document.getElementById('availableSlipWrapper'); if(availWrapper) availWrapper.style.display = 'none';
                const availMap = document.getElementById('availableSlipMap'); if(availMap) availMap.style.display = 'none';
                const marinaCard = document.getElementById('marinaCard'); if(marinaCard) marinaCard.style.display = 'none';
                // build review panel
                let review = document.getElementById('reservationReviewPanel');
                if(!review){
                    review = document.createElement('div'); review.id = 'reservationReviewPanel'; review.className = 'reservation-review';
                    const panel = document.createElement('div'); panel.className = 'panel-inner';
                    const start = (form.querySelector('input[name="start_date"]')||{}).value || '';
                    const end = (form.querySelector('input[name="end_date"]')||{}).value || '';
                    const chosenEl = document.querySelector('#slipGrid .slip[data-slip-id="'+chosenId+'"]');
                    const loc = chosenEl ? (chosenEl.getAttribute('data-slip-location')||'') : '';
                    const sizeVal = chosenEl ? (chosenEl.getAttribute('data-slip-size')||'') : '';
                    const total = (document.getElementById('estimatedCostInput')||{}).value || '';
                    const months = (document.getElementById('estimatedMonthsInput')||{}).value || '';
                    panel.innerHTML = '';
                    const hdr = document.createElement('h3'); hdr.textContent = 'Review Reservation'; hdr.style.textAlign = 'center'; hdr.style.marginBottom = '12px'; hdr.style.gridColumn = '1 / -1'; panel.appendChild(hdr);
                    // Top row: User | Boat | Slip (three-column layout)
                    const topRow = document.createElement('div'); topRow.style.display = 'flex'; topRow.style.justifyContent = 'space-between'; topRow.style.gap = '24px';
                    // User details section (left)
                    const userSection = document.createElement('div'); userSection.className = 'section user-section'; userSection.style.flex = '1'; userSection.style.textAlign = 'center';
                    const userLabel = document.createElement('div'); userLabel.className = 'label'; userLabel.textContent = 'Your Information'; userLabel.style.marginTop = '12px';
                    const full = (document.getElementById('currentUserFull')||{}).value || '';
                    const first = (document.getElementById('currentUserFirst')||{}).value || '';
                    const last = (document.getElementById('currentUserLast')||{}).value || '';
                    const email = (document.getElementById('currentUserEmail')||{}).value || '';
                    const phone = (document.getElementById('currentUserPhone')||{}).value || '';
                    const addr = (document.getElementById('currentUserAddress')||{}).value || '';
                    const city = (document.getElementById('currentUserCity')||{}).value || '';
                    const state = (document.getElementById('currentUserState')||{}).value || '';
                    const zip = (document.getElementById('currentUserZip')||{}).value || '';
                    const company = (document.getElementById('currentUserCompany')||{}).value || '';
                    const displayHidden = (document.getElementById('currentUserDisplay')||{}).value || '';
                    const uname = (document.getElementById('currentUserNameOnly')||{}).value || '';
                    const userVal = document.createElement('div'); userVal.className = 'value';
                    // Use server-provided combined full name when available (guaranteed to come from DB)
                            let displayName = full || [first, last].map(s=> (s||'').trim()).filter(Boolean).join(' ') || displayHidden || uname || '';
                            if(!displayName){
                              if(window.currentUserName) displayName = window.currentUserName;
                              else if(email){
                                try{
                                  const local = String(email).split('@')[0] || '';
                                  const parts = local.replace(/[._]+/g,' ').split(' ').filter(Boolean);
                                  if(parts.length) displayName = parts.map(p=>p.charAt(0).toUpperCase()+p.slice(1)).join(' ');
                                  else displayName = email;
                                }catch(e){ displayName = email; }
                              }
                            }
                    let addrBlock = '';
                    const locality = [city, state, zip].filter(Boolean).join(', ');
                    if(addr) addrBlock = addr + (locality ? '<br>' + locality : '');
                    else if(locality) addrBlock = locality;
                    const companyLine = company ? (company + '<br>') : '';
                    const contactParts = [];
                    if(email && email !== displayName) contactParts.push('<div class="value" style="margin-top:6px">' + email + '</div>');
                    if(phone) contactParts.push('<div class="value">' + phone + '</div>');
                    if(addrBlock) contactParts.push('<div class="value" style="color:#6b7280;font-size:0.95rem">' + addrBlock + '</div>');
                    const nameHtml = (displayName || '') + (company ? '<br><small style="color:#6b7280;">' + company + '</small>' : '');
                    const contactHtml = contactParts.length ? ('<br>' + contactParts.join('')) : '';
                    const setInner = (n, c) => { userVal.innerHTML = n + c; };
                    if(displayName && (phone || email || contactLines.length)){
                      setInner(nameHtml, contactHtml);
                    } else {
                      try{
                        fetch(window.location.pathname + '?fetch_current_user=1', { credentials: 'same-origin' })
                          .then(resp => resp.json())
                          .then(data => {
                            const fetchedName = data.full || (data.first && data.last ? (data.first + ' ' + data.last) : (data.display || data.username || '')) || '';
                            const fetchedEmail = data.email || email || '';
                            const fetchedPhone = data.phone || phone || '';
                            const fetchedCompany = data.company || company || '';
                            const fetchedAddr = data.address || '';
                            const fetchedLocality = [data.city||'', data.state||'', data.zip||''].filter(Boolean).join(', ');
                            let fetchedAddrBlock = '';
                            if(fetchedAddr) fetchedAddrBlock = fetchedAddr + (fetchedLocality ? '<br>' + fetchedLocality : '');
                            else if(fetchedLocality) fetchedAddrBlock = fetchedLocality;
                            const fetchedContactParts = [];
                            if(fetchedEmail && fetchedEmail !== fetchedName) fetchedContactParts.push('<div class="value" style="margin-top:6px">' + fetchedEmail + '</div>');
                            if(fetchedPhone) fetchedContactParts.push('<div class="value">' + fetchedPhone + '</div>');
                            if(fetchedAddrBlock) fetchedContactParts.push('<div class="value" style="color:#6b7280;font-size:0.95rem">' + fetchedAddrBlock + '</div>');
                            const fetchedNameHtml = (fetchedName || nameHtml) + (fetchedCompany ? '<br><small style="color:#6b7280;">' + fetchedCompany + '</small>' : '');
                            const fetchedContactHtml = fetchedContactParts.length ? ('<br>' + fetchedContactParts.join('')) : contactHtml;
                            setInner(fetchedNameHtml, fetchedContactHtml);
                          }).catch(()=> setInner(nameHtml, contactHtml));
                      }catch(e){ setInner(nameHtml, contactHtml); }
                    }
                    userSection.appendChild(userLabel); userSection.appendChild(userVal);
                    // Boat details section (right)
                    const boatOpt = form.querySelector('select[name="boat_id"] option:checked');
                    const boatLabel = boatOpt ? boatOpt.textContent.trim() : '';
                    const boatLen = (boatOpt && boatOpt.getAttribute) ? (boatOpt.getAttribute('data-length') || '') : '';
                    const boatSection = document.createElement('div'); boatSection.className = 'section boat-section'; boatSection.style.flex = '1';
                    boatSection.style.textAlign = 'center';
                    const boatLab = document.createElement('div'); boatLab.className='label'; boatLab.textContent = 'Boat';
                    const boatVal = document.createElement('div'); boatVal.className='value';
                    const boatMatch = boatLabel ? boatLabel.match(/^(.*)\s*\(\s*\d+\s*ft\s*\)$/) : null;
                    const boatNameOnly = boatMatch ? boatMatch[1].trim() : boatLabel;
                    boatVal.textContent = boatNameOnly || '';
                    boatSection.appendChild(boatLab); boatSection.appendChild(boatVal);
                    if(boatLen){ const boatLenEl = document.createElement('div'); boatLenEl.className='value'; boatLenEl.style.marginTop='6px'; boatLenEl.textContent = boatLen + ' ft'; boatSection.appendChild(boatLenEl); }

                    // Slip details (right column)
                    const slipSection = document.createElement('div'); slipSection.className = 'section slip-section'; slipSection.style.textAlign = 'center'; slipSection.style.flex = '1';
                    const slipLab = document.createElement('div'); slipLab.className='label'; slipLab.textContent = 'Slip'; slipLab.style.marginTop = '12px';
                    slipSection.appendChild(slipLab);
                    const slipInfo = document.createElement('div'); slipInfo.style.marginBottom = '6px';
                    const slipLoc = document.createElement('div'); slipLoc.className='value'; slipLoc.textContent = loc || 'n/a'; slipInfo.appendChild(slipLoc);
                    if (sizeVal && String(sizeVal).trim() !== '') {
                      const slipSizeLine = document.createElement('div'); slipSizeLine.className = 'value'; slipSizeLine.style.marginTop = '6px'; slipSizeLine.textContent = String(sizeVal) + ' ft'; slipInfo.appendChild(slipSizeLine);
                    }
                    slipSection.appendChild(slipInfo);
                    // dates (placed under slip info inside the right column)
                    const startFriendly = formatFriendlyDate(start);
                    const endFriendly = formatFriendlyDate(end);
                    const dateLabel = document.createElement('div'); dateLabel.className = 'label'; dateLabel.textContent = 'Dates'; dateLabel.style.marginTop = '12px'; dateLabel.style.textAlign = 'center';
                    // reduce vertical gap below the Dates header so it aligns with surrounding content
                    dateLabel.style.marginBottom = '2px';
                    const dateWrap = document.createElement('div'); dateWrap.className = 'dates'; dateWrap.style.marginTop = '0px'; dateWrap.style.marginBottom = '0px'; dateWrap.style.fontWeight = '400';
                    // center dates horizontally
                    dateWrap.style.display = 'flex'; dateWrap.style.justifyContent = 'center'; dateWrap.style.width = '100%';
                    const dateEl = document.createElement('div'); dateEl.className='value'; dateEl.textContent = startFriendly + ' — ' + endFriendly; dateEl.style.textAlign = 'center'; dateEl.style.marginTop = '0px'; dateWrap.appendChild(dateEl);
                    // move Dates out of the right column and place into the center column of the panel grid
                    dateLabel.style.gridColumn = '2 / 3'; dateLabel.style.textAlign = 'center';
                    dateWrap.style.gridColumn = '2 / 3'; dateWrap.style.justifySelf = 'center';
                    panel.appendChild(dateLabel); panel.appendChild(dateWrap);

                    topRow.appendChild(userSection); topRow.appendChild(boatSection); topRow.appendChild(slipSection); panel.appendChild(topRow);
                    // Costs
                    const costsSection = document.createElement('div'); costsSection.className = 'section costs-section'; costsSection.style.textAlign = 'center';
                    const costsLab = document.createElement('div'); costsLab.className='label'; costsLab.textContent = 'Costs'; costsLab.style.marginTop = '20px';
                    costsSection.appendChild(costsLab);
                    function addCostRow(labelText, amountText){ const row = document.createElement('div'); row.style.display = 'flex'; row.style.justifyContent = 'center'; row.style.alignItems = 'center'; row.style.padding = '4px 0'; row.style.gap = '6px'; const l = document.createElement('div'); l.className='label'; l.textContent = labelText; l.style.fontWeight = '700'; l.style.display = 'inline-block'; l.style.marginBottom = '0'; const a = document.createElement('div'); a.className='amount'; a.textContent = amountText || ''; a.style.fontWeight = '400'; a.style.display = 'inline-block'; a.style.marginBottom = '0'; row.appendChild(l); row.appendChild(a); costsSection.appendChild(row); }
                    addCostRow('Base', '$' + ((document.getElementById('estimatedBaseInput')||{}).value || '0.00'));
                    addCostRow('Hookup', '$' + ((document.getElementById('estimatedHookupInput')||{}).value || '0.00'));
                    addCostRow('Duration', (months || '1') + ' month(s)');
                    const tot = document.createElement('div'); tot.className = 'total'; tot.textContent = 'Total: $' + ((document.getElementById('estimatedCostInput')||{}).value ? parseFloat((document.getElementById('estimatedCostInput')||{}).value).toFixed(2) : '0.00'); tot.style.textAlign = 'center'; tot.style.fontWeight = '800'; tot.style.fontSize = '18px'; tot.style.marginTop = '12px'; costsSection.appendChild(tot);
                    panel.appendChild(costsSection);
                    // actions: Cancel + Confirm centered
                    const cwrap = document.createElement('div'); cwrap.className = 'confirm-wrapper';
                    cwrap.style.display = 'flex'; cwrap.style.justifyContent = 'center'; cwrap.style.gap = '12px'; cwrap.style.marginTop = '12px'; cwrap.style.width = '100%'; cwrap.style.alignItems = 'center';
                    let confirmBtn = document.getElementById('confirmBtn');
                    if(confirmBtn){ try{ confirmBtn.parentNode && confirmBtn.parentNode.removeChild(confirmBtn); }catch(e){} } else { confirmBtn = document.createElement('button'); confirmBtn.id='confirmBtn'; confirmBtn.type='submit'; confirmBtn.name='confirm_reservation'; confirmBtn.className='btn-secondary'; confirmBtn.textContent='Confirm Reservation'; }
                    confirmBtn.style.display = 'inline-block'; confirmBtn.style.background = '#3F87A6'; confirmBtn.style.color = '#fff'; confirmBtn.style.border = 'none'; confirmBtn.style.padding = '12px 25px'; confirmBtn.style.borderRadius = '8px'; confirmBtn.style.fontWeight = '700'; confirmBtn.style.minWidth = '200px'; confirmBtn.style.boxShadow = '0 6px 18px rgba(63,135,166,0.12)'; confirmBtn.style.margin = '0';
                    const cancelBtn = document.createElement('button'); cancelBtn.type='button'; cancelBtn.id='cancelBtn'; cancelBtn.className='btn-cancel'; cancelBtn.textContent = 'Cancel';
                    const CORAL = '#FF6B6B';
                    cancelBtn.style.background = 'transparent'; cancelBtn.style.color = CORAL; cancelBtn.style.border = '1px solid ' + CORAL; cancelBtn.style.padding = '12px 25px'; cancelBtn.style.borderRadius = '8px'; cancelBtn.style.minWidth = '140px';
                    cancelBtn.addEventListener('click', function(){
                      try{
                        clearReservationForm();
                        try{ setFlowStep(1); }catch(e){}
                        try{ setShowAddBoat(false); }catch(e){}
                        try{ const rev = document.getElementById('reservationReviewPanel'); if(rev) rev.remove(); }catch(e){}
                        try{ const cb = document.getElementById('confirmBtn'); if(cb) cb.style.display = 'none'; }catch(e){}
                        try{ const sb = document.getElementById('selectSlipBtn'); if(sb) sb.style.display = 'none'; }catch(e){}
                        // do not auto-scroll when cancelling or resetting - keep site header/menu visible
                        try{ /* intentionally left blank to avoid auto-scroll */ }catch(e){}
                      }catch(err){ }
                          // perform a clean GET reload that also clears server-side reservation state
                          try{ location.replace(window.location.pathname + '?reset_reservation=1'); }catch(e){}
                        try{ location.replace(window.location.pathname + '?reset_reservation=1'); }catch(e){}
                    });
                    cwrap.appendChild(confirmBtn); cwrap.appendChild(cancelBtn); panel.appendChild(cwrap); review.appendChild(panel);
                    const actions = form.querySelector('.form-actions');
                    // remove the add-boat input panel so the review appears cleanly without the inputs above it
                    try{ const addPanel = document.getElementById('addBoatPanel'); if(addPanel) addPanel.remove(); }catch(e){}
                    if(actions) actions.parentNode.insertBefore(review, actions); else form.appendChild(review);
                }
                // hide the select button to avoid re-opening
                const selectBtn = document.getElementById('selectSlipBtn'); if(selectBtn) selectBtn.style.display = 'none';
              }catch(e){}
            })();
          });

          // when select changes, reflect on grid and show Select button
          sel.addEventListener('change', function(){
            const v = this.value;
            const gridAll = document.querySelectorAll('#slipGrid .slip');
            gridAll.forEach(x=>x.classList.remove('selected'));
            if(!v) return;
            const chosen = document.querySelector('#slipGrid .slip[data-slip-id="'+v+'"]');
            if(chosen) chosen.classList.add('selected');
            let selectBtn = document.getElementById('selectSlipBtn');
            if(!selectBtn){
              selectBtn = document.createElement('button'); selectBtn.id = 'selectSlipBtn'; selectBtn.type = 'button'; selectBtn.className = 'select-slip-btn'; selectBtn.textContent = 'Select this slip';
              const clientMsg = document.getElementById('clientAvailabilityMsg');
              if(clientMsg && clientMsg.parentNode){ clientMsg.parentNode.insertBefore(selectBtn, clientMsg.nextSibling); }
              else {
                const availBtn = form.querySelector('button[name="check_availability"]');
                if(availBtn && availBtn.parentNode){ availBtn.parentNode.insertBefore(selectBtn, availBtn.nextSibling); }
                else { const actions = form.querySelector('.form-actions'); if(actions) actions.parentNode.insertBefore(selectBtn, actions); else form.appendChild(selectBtn); }
              }
            }
            selectBtn.style.display = 'block';
            selectBtn.style.margin = '12px auto';
          });

          // show confirm button if present
          const confirmBtn = document.getElementById('confirmBtn');
          if(confirmBtn) confirmBtn.style.display = 'none';
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
        const ymd = formatDateYMD(minEnd);
        endInput.value = ymd;
        endInput.min = ymd;
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
      // Ensure Add Boat panel is only visible when user explicitly chose "add_new"
      try{
        if(boat) setShowAddBoat(boat.value === 'add_new');
      }catch(e){}
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
  // Run initial validation once so auto-filled end date enables the availability button
  try{ validateAvailabilityInputs(); }catch(e){}
  // Auto-trigger availability if all required inputs already present after autofill
  try{
    let autoChecked = false;
    function tryAutoCheck(){
      try{
        if(autoChecked) return;
        const ok = validateAvailabilityInputs();
        if(ok){
          // soft delay to allow UI updates to settle
          setTimeout(()=>{
            try{ 
              // use safe parsing: ensure start date parsed as local before click auto-run
              const sInput = form.querySelector('input[name="start_date"]');
              if(sInput && sInput.value){
                // ensure end min/value were set using local parse
                const sDate = parseDateYMD(sInput.value);
                if(sDate){
                  const me = new Date(sDate); me.setDate(me.getDate()+30);
                  const y = formatDateYMD(me);
                  const eInput = form.querySelector('input[name="end_date"]');
                  if(eInput && !eInput.value){ eInput.value = y; eInput.min = y; }
                }
              }
              availBtn.click(); autoChecked = true; 
            }catch(e){}
          }, 150);
        }
      }catch(e){}
    }
    // attempt once now
    tryAutoCheck();
    // also try after user changes start date (auto-fill end) so it can trigger availability
    const startInput = form.querySelector('input[name="start_date"]');
    if(startInput){ startInput.addEventListener('change', function(){ autoChecked = false; setTimeout(tryAutoCheck, 120); }); }
  }catch(e){}
  // initial validation
  validateAvailabilityInputs();
})();
</script>

<?php include 'footer.php'; ?>
</div>
<script>
// Make marina map slips interactive: delegated click + keyboard support and dynamic enhancement
(function(){
  // helper: enhance a slip tile for keyboard accessibility
  function enhanceTile(tile){
    try{
      if(!tile) return;
      if(!tile.hasAttribute('tabindex')) tile.setAttribute('tabindex','0');
      if(!tile.hasAttribute('role')) tile.setAttribute('role','button');
      if(!tile.hasAttribute('aria-pressed')) tile.setAttribute('aria-pressed','false');
    }catch(e){}
  }

  // run once on existing tiles
  document.querySelectorAll('.slip').forEach(enhanceTile);

  // delegated click handler so it works for dynamically-inserted tiles
  document.addEventListener('click', function(evt){
    try{
      var tile = evt.target.closest && evt.target.closest('.slip');
      if(!tile) return;
      // ignore unavailable
      if(tile.classList.contains('unavailable')) return;
      // ensure tile is enhanced
      enhanceTile(tile);
      var sid = tile.getAttribute('data-slip-id');
      if(!sid) return;
      var slipSelect = document.getElementById('selected_slip_id');
      if(slipSelect){ slipSelect.value = sid; slipSelect.dispatchEvent(new Event('change',{bubbles:true})); }
      // visual selection: clear any existing selected tiles within grid or document
      var grid = document.getElementById('slipGrid');
      var existing = grid ? grid.querySelectorAll('.slip.selected') : document.querySelectorAll('.slip.selected');
      existing.forEach(function(s){ s.classList.remove('selected'); try{s.setAttribute('aria-pressed','false');}catch(e){} });
      tile.classList.add('selected'); tile.setAttribute('aria-pressed','true');
    }catch(e){ /* ignore */ }
  }, false);

  // keyboard: if a .slip has focus, Enter or Space triggers click
  document.addEventListener('keydown', function(e){
    try{
      if(e.key !== 'Enter' && e.key !== ' ') return;
      var ae = document.activeElement;
      if(ae && ae.classList && ae.classList.contains('slip')){
        e.preventDefault(); ae.click();
      }
    }catch(err){}
  }, false);

  // If user changes the select, reflect selection visually
  document.addEventListener('change', function(e){
    try{
      var target = e.target || e.srcElement;
      if(!target) return;
      if(target.id === 'selected_slip_id' || target.name === 'selected_slip_id'){
        var v = target.value;
        var grid = document.getElementById('slipGrid');
        var existing = grid ? grid.querySelectorAll('.slip.selected') : document.querySelectorAll('.slip.selected');
        existing.forEach(function(s){ s.classList.remove('selected'); try{s.setAttribute('aria-pressed','false');}catch(e){} });
        if(!v) return;
        var match = document.querySelector('.slip[data-slip-id="' + CSS.escape ? CSS.escape(v) : v + '"]');
        if(match){ enhanceTile(match); match.classList.add('selected'); match.setAttribute('aria-pressed','true'); }
      }
    }catch(err){}
  }, false);

  // Observe for dynamically-inserted slip tiles and enhance them
  var observerRoot = document.getElementById('slipGrid') || document.getElementById('availableSlipMap') || document.body;
  try{
    var mo = new MutationObserver(function(muts){
      muts.forEach(function(m){
        (m.addedNodes||[]).forEach(function(node){
          try{
            if(!node) return;
            if(node.nodeType === 1){
              if(node.classList && node.classList.contains('slip')) enhanceTile(node);
              // also enhance any descendant slips
              node.querySelectorAll && node.querySelectorAll('.slip').forEach(enhanceTile);
            }
          }catch(e){}
        });
      });
    });
    mo.observe(observerRoot, { childList:true, subtree:true });
  }catch(e){}

})();
</script>
<script>
// Defensive cleanup on page load: if the user has no start date selected, remove any availability UI
document.addEventListener('DOMContentLoaded', function(){
  try{
    var start = document.querySelector("input[name='start_date']");
    if(!start || !start.value){
      ['clientAvailabilityMsg','availableSlipWrapper','availableSlipMap','slipGrid','selectSlipBtn','reservationReviewPanel','marinaCard'].forEach(function(id){ try{ var e = document.getElementById(id); if(e) e.remove(); }catch(err){} });
      try{ document.querySelectorAll('.select-slip-btn').forEach(function(x){ try{x.remove();}catch(e){} }); }catch(e){}
      try{ document.querySelectorAll('.alert').forEach(function(a){ try{ if(a.textContent && a.textContent.indexOf('Slips available')!==-1) a.remove(); }catch(e){} }); }catch(e){}
    }
    // delegate Cancel clicks to ensure a full reset happens even if individual handlers fail
    try{
      document.addEventListener('click', function(ev){
        try{
          var btn = ev.target.closest && ev.target.closest('#cancelBtn, .btn-cancel');
          if(btn){
            try{ ev.preventDefault(); }catch(e){}
            try{ location.replace(window.location.pathname + '?reset_reservation=1'); }catch(e){}
          }
        }catch(e){}
      }, true);
    }catch(e){}
  }catch(e){}
});
</script>

</body>
</html>

<?php
// If we've set auto_check_availability in session, emit a tiny script to trigger availability
if (!empty($_SESSION['auto_check_availability'])) {
  echo "<script>document.addEventListener('DOMContentLoaded', function(){ setTimeout(function(){ try{ var b = document.querySelector('button[name=\'check_availability\']'); if(b) b.click(); }catch(e){} }, 250); });</script>";
  unset($_SESSION['auto_check_availability']);
}

// Page-scoped alignment helper: align the reservation frame's left edge to the
// header container so header variations (logged-in vs guest) do not shift the
// centered content. This runs only on this page and does not modify other files.
// alignment script removed — using page-scoped CSS centering instead
