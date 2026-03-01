<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: MyAccount.php
Purpose: User account dashboard and related actions.
This header is informational and non-destructive.
*/

/**
 * Amanda Wedergren 
 * 02/12/26
 * Moffay Bay: My Account Page
 */

session_start();

// Load DB (try several common locations so this page uses the same db.php)
$tried = [];
$candidates = [
  __DIR__ . '/config/db.php',
  __DIR__ . '/db.php',
  __DIR__ . '/../db.php',
  __DIR__ . '/../../db.php',
  // fallback to common project location (no absolute drive paths)
  __DIR__ . '/../db.php'
];
foreach ($candidates as $path) {
  $tried[] = $path;
  if (file_exists($path)) {
    require_once $path;
    break;
  }
}

// Phone helpers: normalize for storage (XXX-XXX-XXXX) and format for display ((XXX)XXX-XXXX)
function _mb_digits_only($s) { return preg_replace('/\D+/', '', (string)$s); }
function format_phone_storage($phone) {
  $d = _mb_digits_only($phone);
  if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
  if (strlen($d) === 10) return sprintf('%s-%s-%s', substr($d,0,3), substr($d,3,3), substr($d,6,4));
  return $phone;
}
function format_phone_display($phone) {
  $d = _mb_digits_only($phone);
  if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
  if (strlen($d) === 10) return sprintf('(%s)%s-%s', substr($d,0,3), substr($d,3,3), substr($d,6,4));
  return $phone;
}

// Helper: return associative map of canonical boat/user columns that exist in DB.
function get_boat_column_map(PDO $pdo) {
  $map = [
    'boat_id' => null,
    'user_id' => null,
    'boat_name' => null,
    'boat_length' => null,
    'date_created' => null
  ];
  try {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boats'");
    $stmt->execute();
    $cols = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['COLUMN_NAME'];
    // candidates for each canonical name
    $map['boat_id'] = (in_array('boat_ID',$cols) ? 'boat_ID' : (in_array('boat_id',$cols) ? 'boat_id' : (in_array('id',$cols) ? 'id' : null)));
    $map['user_id'] = (in_array('user_ID',$cols) ? 'user_ID' : (in_array('user_id',$cols) ? 'user_id' : (in_array('userid',$cols) ? 'userid' : null)));
    $map['boat_name'] = (in_array('boat_name',$cols) ? 'boat_name' : (in_array('name',$cols) ? 'name' : null));
    $map['boat_length'] = (in_array('boat_length',$cols) ? 'boat_length' : (in_array('length_ft',$cols) ? 'length_ft' : (in_array('length',$cols) ? 'length' : null)));
    $map['date_created'] = (in_array('date_created',$cols) ? 'date_created' : (in_array('created_at',$cols) ? 'created_at' : null));
  } catch (Exception $e) { }
  return $map;
}

$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']) || isset($_SESSION['email']);
if (!$loggedIn) {
  header('Location: BlueTeam_LoginPage.php');
  exit;
}

$pdoExists = (isset($pdo) && $pdo instanceof PDO);
if (!$pdoExists) {
  // graceful fallback: show an error message in-page
  $dbError = 'Database connection not available.';
}

// Determine current user identifier (prefer user_id if present)
// Normalize session identifiers: prefer numeric user_ID; session may contain email by mistake
$userId = $_SESSION['user_id'] ?? null;
// Accept common session key names for the logged-in user's email
$userEmail = $_SESSION['username'] ?? $_SESSION['email'] ?? null;
if ($pdoExists && !empty($userId) && !is_numeric($userId)) {
  // session `user_id` appears non-numeric (likely an email). Try to resolve actual numeric `user_ID`.
  $lookupEmail = filter_var($userId, FILTER_VALIDATE_EMAIL) ? $userId : ($userEmail ?: null);
  if ($lookupEmail) {
    try {
      $s = $pdo->prepare('SELECT user_ID FROM users WHERE email = :email LIMIT 1');
      $s->execute([':email' => $lookupEmail]);
      $r = $s->fetch(PDO::FETCH_ASSOC);
      if ($r && !empty($r['user_ID'])) {
        $userId = intval($r['user_ID']);
        $_SESSION['user_id'] = $userId; // normalize session for future requests
      } else {
        $userId = null;
      }
    } catch (Exception $e) {
      $userId = null;
    }
  } else {
    $userId = null;
  }
}

$msg = '';
$err = '';
// flag to indicate current-password specific error so UI stays editable
$passwordError = false;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdoExists) {
  // Common POST values
  $name = trim($_POST['name'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  // normalize phone for storage (store as XXX-XXX-XXXX when possible)
  $phone_storage = format_phone_storage($phone);
  $email = trim($_POST['email'] ?? '');
  $newpw = trim($_POST['new_password'] ?? '');
  $newpw_confirm = trim($_POST['new_password_confirm'] ?? '');
  $currentpw = trim($_POST['current_password'] ?? '');
  $changePw = isset($_POST['change_password']) && $_POST['change_password'] === '1';
  $boat_action = $_POST['boat_action'] ?? '';

  // When adding/editing/deleting a boat while logged in we don't require the email field to be re-submitted
  if (!in_array($boat_action, ['add','edit','delete']) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Please provide a valid email address.';
  } else {
    try {
      // Short-circuit: adding a boat does NOT require current password.
      if ($boat_action === 'add') {
        // Use detected boats schema columns (do not alter schema)
        $bmap = get_boat_column_map($pdo);
        $colBoatId = $bmap['boat_id'];
        $colUserId = $bmap['user_id'];
        $colBoatName = $bmap['boat_name'];
        $colBoatLength = $bmap['boat_length'];
        $colDateCreated = $bmap['date_created'];

        // require minimal compatible schema
        if (empty($colUserId) || empty($colBoatName) || empty($colBoatLength)) {
          throw new Exception('Boats table schema not compatible: missing required columns.');
        }

        $boat_name = trim($_POST['boat_name'] ?? '');
        $boat_length = intval($_POST['boat_length'] ?? 0);
        if ($boat_name === '' || $boat_length <= 0) throw new Exception('Provide a boat name and length in whole feet.');

        // Resolve numeric user id (prefer session userId, else lookup users.user_ID)
        $resolvedUid = $userId;
        if (empty($resolvedUid)) {
          $s = $pdo->prepare('SELECT user_ID FROM users WHERE email = :email LIMIT 1');
          $s->execute([':email' => $userEmail]);
          $r = $s->fetch(PDO::FETCH_ASSOC);
          if ($r && !empty($r['user_ID'])) $resolvedUid = intval($r['user_ID']);
        }
        if (empty($resolvedUid)) throw new Exception('Unable to resolve numeric user ID for this account.');

        // Prevent exact duplicate rows for this user
        $dupSql = "SELECT COUNT(*) FROM boats WHERE {$colUserId} = :uid AND {$colBoatName} = :name AND {$colBoatLength} = :len";
        $dup = $pdo->prepare($dupSql);
        $dup->execute([':uid'=>$resolvedUid, ':name'=>$boat_name, ':len'=>$boat_length]);
        if ($dup->fetchColumn() > 0) throw new Exception('This boat already exists in your account.');

        // Insert boat associated with user's numeric id
        $insSql = "INSERT INTO boats ({$colUserId}, {$colBoatName}, {$colBoatLength}) VALUES (:uid, :name, :len)";
        $ins = $pdo->prepare($insSql);
        $ins->execute([':uid'=>$resolvedUid, ':name'=>$boat_name, ':len'=>$boat_length]);

        header('Location: MyAccount.php?saved=1'); exit;
      }

      // Handle reservation actions (cancel or edit) which require current password confirmation
      if (!empty($_POST['reservation_action'])) {
        $resAction = $_POST['reservation_action'];
        if ($resAction === 'cancel' || $resAction === 'edit') {
          if ($currentpw === '') throw new Exception('Enter your current password to save changes.');

          // load current user row to verify password
          $current = false;
          $pkCol = null;
          if ($userId) {
            $idCandidates = ['id', 'user_id', 'uid'];
            foreach ($idCandidates as $c) {
              try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE $c = :id LIMIT 1");
                $stmt->execute([':id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) { $current = $row; $pkCol = $c; break; }
              } catch (Exception $e) { }
            }
          }
          if (!$current) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $userEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
              $current = $row;
              foreach (['id','user_id','uid'] as $c) { if (array_key_exists($c,$row)) { $pkCol = $c; break; } }
            }
          }
          if (!$current) throw new Exception('Current account row not found.');

          $currentHash = $current['password_hash'] ?? $current['password'] ?? $current['passwd'] ?? null;
          if (!$currentHash || !password_verify($currentpw, $currentHash)) throw new Exception('Current password incorrect.');

          // detect reservation primary key column
          $resCols = [];
          try {
            $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations'");
            $colStmt->execute();
            $resCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
          } catch (Exception $ex) { $resCols = []; }
          $pkResCol = in_array('reservation_ID',$resCols) ? 'reservation_ID' : (in_array('reservation_id',$resCols) ? 'reservation_id' : (in_array('id',$resCols) ? 'id' : null));
          if (empty($pkResCol)) throw new Exception('Reservations table primary id not found.');

          $resId = intval($_POST['reservation_id'] ?? 0);
          if ($resId <= 0) throw new Exception('Invalid reservation selected.');

          // fetch reservation and ensure ownership
          $rq = $pdo->prepare("SELECT * FROM reservations WHERE {$pkResCol} = :id LIMIT 1");
          $rq->execute([':id' => $resId]);
          $resRow = $rq->fetch(PDO::FETCH_ASSOC);
          if (!$resRow) throw new Exception('Reservation not found.');

          // ownership check: prefer numeric user_ID when available
          $owns = false;
          $currentUid = $current['user_ID'] ?? $current['id'] ?? $current['user_id'] ?? null;
          if (!empty($currentUid) && array_key_exists('user_ID',$resRow) && intval($resRow['user_ID']) === intval($currentUid)) $owns = true;
          // fallback: match email fields on reservations
          $emailForLookup = $current['email'] ?? $userEmail;
          if (!$owns) {
            if ((!empty($resRow['user_email']) && $resRow['user_email'] === $emailForLookup) || (!empty($resRow['email']) && $resRow['email'] === $emailForLookup)) $owns = true;
          }
          if (!$owns) throw new Exception('You do not have permission to modify this reservation.');

          if ($resAction === 'cancel') {
            // update reservation_status to canceled when column exists
            if (in_array('reservation_status',$resCols) || in_array('status',$resCols)) {
              $statusCol = in_array('reservation_status',$resCols) ? 'reservation_status' : 'status';
              $up = $pdo->prepare("UPDATE reservations SET {$statusCol} = :st WHERE {$pkResCol} = :id");
              $up->execute([':st' => 'canceled', ':id' => $resId]);
            } else {
              // best-effort: no status column
              throw new Exception('Reservations table does not support status updates.');
            }
            header('Location: MyAccount.php?saved=1'); exit;
          }

          if ($resAction === 'edit') {
            // Build update list from submitted fields and existing reservation columns
            $updates = [];
            $params = [':id' => $resId];
            // title/reservation_name
            if (!empty($_POST['reservation_name'])) {
              if (in_array('reservation_name',$resCols)) { $updates[] = 'reservation_name = :name'; $params[':name'] = trim($_POST['reservation_name']); }
              elseif (in_array('title',$resCols)) { $updates[] = 'title = :name'; $params[':name'] = trim($_POST['reservation_name']); }
            }
            if (!empty($_POST['start_date']) && in_array('start_date',$resCols)) { $updates[] = 'start_date = :start'; $params[':start'] = $_POST['start_date']; }
            if (!empty($_POST['end_date']) && in_array('end_date',$resCols)) { $updates[] = 'end_date = :end'; $params[':end'] = $_POST['end_date']; }
            // boat id
            if (!empty($_POST['boat_id']) && in_array('boat_ID',$resCols)) { $updates[] = 'boat_ID = :boat'; $params[':boat'] = intval($_POST['boat_id']); }
            if (!empty($_POST['boat_id']) && in_array('boat_id',$resCols) && !in_array('boat_ID',$resCols)) { $updates[] = 'boat_id = :boat'; $params[':boat'] = intval($_POST['boat_id']); }
            // slip id or slip_location
            if (!empty($_POST['slip_id']) && in_array('slip_ID',$resCols)) { $updates[] = 'slip_ID = :slip'; $params[':slip'] = intval($_POST['slip_id']); }
            if (!empty($_POST['slip_location']) && in_array('slip_location',$resCols)) { $updates[] = 'slip_location = :sliploc'; $params[':sliploc'] = trim($_POST['slip_location']); }

            if (empty($updates)) throw new Exception('No editable fields submitted.');

            $sql = 'UPDATE reservations SET ' . implode(', ', $updates) . " WHERE {$pkResCol} = :id";
            $up = $pdo->prepare($sql);
            $up->execute($params);
            header('Location: MyAccount.php?saved=1'); exit;
          }
        }
      }

      // For edits, deletes and account updates we require current password
      if ($currentpw === '') {
        throw new Exception('Enter your current password to save changes.');
      }

      // load current user row to verify password and detect columns
      $current = false;
      $pkCol = null;
      if ($userId) {
        $idCandidates = ['id', 'user_id', 'uid'];
        foreach ($idCandidates as $c) {
          try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE $c = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) { $current = $row; $pkCol = $c; break; }
          } catch (Exception $e) {
            // try next candidate
          }
        }
      }
      if (!$current) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $userEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $current = $row;
          // discover primary key column if present
          foreach (['id','user_id','uid'] as $c) {
            if (array_key_exists($c, $row)) { $pkCol = $c; break; }
          }
        }
      }
      if (!$current) throw new Exception('Current account row not found.');

      $currentHash = $current['password_hash'] ?? $current['password'] ?? $current['passwd'] ?? null;
      if (!$currentHash || !password_verify($currentpw, $currentHash)) {
        throw new Exception('Current password incorrect.');
      }

      // Account deletion flow: if requested, require current password (already verified above),
      // mark related reservations to indicate the account owner was deleted, then remove the user row.
      if (isset($_POST['account_action']) && $_POST['account_action'] === 'delete') {
        // Compose a deleted-note for reservations to preserve records while indicating deletion
        $origEmail = $current['email'] ?? $userEmail;
        $deletedNote = '[deleted account] ' . ($origEmail ?: 'unknown');
        try {
          // Update reservations that reference this user by email (or user_email) to note deletion.
          // Use two attempts: update rows where user_email or email match the user's email.
          $u1 = $pdo->prepare('UPDATE reservations SET user_email = :note WHERE user_email = :email');
          $u1->execute([':note' => $deletedNote, ':email' => $origEmail]);
        } catch (Exception $e) {
          // ignore if reservations table/column does not exist or update fails; we still proceed to delete account
        }

        try {
          $u2 = $pdo->prepare('UPDATE reservations SET email = :note WHERE email = :email');
          $u2->execute([':note' => $deletedNote, ':email' => $origEmail]);
        } catch (Exception $e) {
          // ignore
        }

        // Delete the user row (boats should cascade if FK exists). Prefer detected PK column when available.
        if (!empty($pkCol) && isset($current[$pkCol])) {
          $del = $pdo->prepare("DELETE FROM users WHERE $pkCol = :pk");
          $del->execute([':pk' => $current[$pkCol]]);
        } else {
          $del = $pdo->prepare('DELETE FROM users WHERE email = :email');
          $del->execute([':email' => $origEmail]);
        }

        // Destroy session, clear session cookie, and redirect to landing with a friendly message
        // Clear all session variables
        $_SESSION = [];
        // If session uses cookies, remove the session cookie
        if (ini_get('session.use_cookies')) {
          $params = session_get_cookie_params();
          setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
          );
        }
        // Finally destroy the session data on the server
        session_unset();
        session_destroy();
        header('Location: index.php?account_deleted=1');
        exit;
      }

      // For boat edits/deletes use boats table columns as detected (no schema changes)
      if ($boat_action) {
        $bmap = get_boat_column_map($pdo);
        $colBoatId = $bmap['boat_id'];
        $colUserId = $bmap['user_id'];
        $colBoatName = $bmap['boat_name'];
        $colBoatLength = $bmap['boat_length'];

        if ($boat_action === 'edit') {
          $boat_id = intval($_POST['boat_id'] ?? 0);
          $boat_name = trim($_POST['boat_name'] ?? '');
          $boat_length = intval($_POST['boat_length'] ?? 0);
          if ($boat_id <= 0) throw new Exception('Invalid boat selected.');
          if ($boat_name === '' || $boat_length <= 0) throw new Exception('Provide a boat name and length in whole feet.');

          // If we have both boat id and user_id columns, restrict by both for safety
          if (!empty($colBoatId) && !empty($colUserId)) {
            $resolvedUid = $userId;
            if (empty($resolvedUid)) {
              $s = $pdo->prepare('SELECT user_ID FROM users WHERE email = :email LIMIT 1');
              $s->execute([':email' => $current['email'] ?? $userEmail]);
              $r = $s->fetch(PDO::FETCH_ASSOC);
              if ($r && !empty($r['user_ID'])) $resolvedUid = intval($r['user_ID']);
            }
            if (!empty($resolvedUid)) {
              $sql = "UPDATE boats SET {$colBoatName} = :name, {$colBoatLength} = :len WHERE {$colBoatId} = :bid AND {$colUserId} = :uid";
              $up = $pdo->prepare($sql);
              $up->execute([':name'=>$boat_name, ':len'=>$boat_length, ':bid'=>$boat_id, ':uid'=>$resolvedUid]);
            } else {
              $up = $pdo->prepare("UPDATE boats SET {$colBoatName} = :name, {$colBoatLength} = :len WHERE {$colBoatId} = :bid");
              $up->execute([':name'=>$boat_name, ':len'=>$boat_length, ':bid'=>$boat_id]);
            }
          } else {
            // best-effort update by boat id only
            $up = $pdo->prepare('UPDATE boats SET ' . ($colBoatName ?? 'boat_name') . ' = :name, ' . ($colBoatLength ?? 'boat_length') . ' = :len WHERE ' . ($colBoatId ?? 'boat_ID') . ' = :bid');
            $up->execute([':name'=>$boat_name, ':len'=>$boat_length, ':bid'=>$boat_id]);
          }
          header('Location: MyAccount.php?saved=1'); exit;
        }

        

        if ($boat_action === 'delete') {
          $boat_id = intval($_POST['boat_id'] ?? 0);
          if ($boat_id <= 0) throw new Exception('Invalid boat selected.');

          // Ensure the boat exists and belongs to the current user before deleting
          $detectedBoatIdCol = $colBoatId ?? 'boat_ID';
          try {
            $br = $pdo->prepare("SELECT * FROM boats WHERE {$detectedBoatIdCol} = :bid LIMIT 1");
            $br->execute([':bid' => $boat_id]);
            $boatRow = $br->fetch(PDO::FETCH_ASSOC);
          } catch (Exception $e) {
            $boatRow = false;
          }
          if (!$boatRow) throw new Exception('Boat not found.');

          // Ownership check: prefer numeric user id column when available
          $owns = false;
          if (!empty($colUserId) && array_key_exists($colUserId, $boatRow)) {
            $boatOwner = $boatRow[$colUserId];
            $currentUid = $current['user_ID'] ?? $current['id'] ?? $current['user_id'] ?? null;
            if (!empty($currentUid) && intval($boatOwner) === intval($currentUid)) $owns = true;
            // If boat owner is stored as email, compare with current email as fallback
            if (!$owns && filter_var($boatOwner, FILTER_VALIDATE_EMAIL) && !empty($current['email']) && $boatOwner === $current['email']) $owns = true;
          } else {
            // Fallback: try matching by any email-like column on the boats row
            foreach (['owner_email','user_email','email'] as $ec) {
              if (array_key_exists($ec, $boatRow) && !empty($boatRow[$ec]) && !empty($current['email']) && $boatRow[$ec] === $current['email']) { $owns = true; break; }
            }
          }
          if (!$owns) throw new Exception('You do not have permission to delete this boat.');

          // Perform the delete (prefer restricting by owner when possible)
          $logParts = [];
          $logParts[] = '['.date('c').'] boat_delete attempt boat_id=' . $boat_id . ' user_id=' . ($current['user_ID'] ?? $current['id'] ?? $userId ?? 'null');
          try {
            if (!empty($colBoatId) && !empty($colUserId) && !empty($currentUid)) {
              $sql = "DELETE FROM boats WHERE {$colBoatId} = :bid AND {$colUserId} = :uid";
              $del = $pdo->prepare($sql);
              $del->execute([':bid'=>$boat_id, ':uid'=>$currentUid]);
              $logParts[] = 'sql=' . $sql;
            } else {
              $sql = 'DELETE FROM boats WHERE ' . ($colBoatId ?? 'boat_ID') . ' = :bid';
              $del = $pdo->prepare($sql);
              $del->execute([':bid'=>$boat_id]);
              $logParts[] = 'sql=' . $sql;
            }
            $affected = $del->rowCount();
            $logParts[] = 'affected=' . intval($affected);
            // write debug log (do not log passwords)
            @file_put_contents(__DIR__ . '/MyAccount_debug.log', implode(' | ', $logParts) . "\n", FILE_APPEND | LOCK_EX);
            if ($affected === 0) throw new Exception('Boat deletion failed.');
          } catch (Exception $ex) {
            @file_put_contents(__DIR__ . '/MyAccount_debug.log', '['.date('c').'] boat_delete error: ' . $ex->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            throw $ex;
          }
          header('Location: MyAccount.php?saved=1'); exit;
        }
      }

      // If password change not requested, ignore newpw
      if (!$changePw) {
        $newpw = '';
        $newpw_confirm = '';
      } else {
        if ($newpw === '') throw new Exception('Enter a new password to change it.');
        if ($newpw !== $newpw_confirm) throw new Exception('New password and confirmation do not match.');
      }

      // Build update dynamically based on columns present in the users table
      $cols = array_keys($current);
      $fields = [];
      $params = [];

      // name/first_name+last_name handling
      if (in_array('first_name', $cols) && in_array('last_name', $cols)) {
        $parts = preg_split('/\s+/', $name, 2, PREG_SPLIT_NO_EMPTY);
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? '';
        $fields[] = 'first_name = :first_name';
        $fields[] = 'last_name = :last_name';
        $params[':first_name'] = $first;
        $params[':last_name'] = $last;
      } elseif (in_array('name', $cols)) {
        $fields[] = 'name = :name';
        $params[':name'] = $name;
      } elseif (in_array('username', $cols)) {
        $fields[] = 'username = :username';
        $params[':username'] = $name;
      }
      if (in_array('phone', $cols)) {
        $fields[] = 'phone = :phone';
        $params[':phone'] = $phone_storage;
      }
      if (in_array('email', $cols)) {
        $fields[] = 'email = :email';
        $params[':email'] = $email;
      }
      if ($newpw !== '') {
        $hash = password_hash($newpw, PASSWORD_DEFAULT);
        if (in_array('password_hash', $cols)) {
          $fields[] = 'password_hash = :pw';
          $params[':pw'] = $hash;
        } elseif (in_array('password', $cols)) {
          $fields[] = 'password = :pw';
          $params[':pw'] = $hash;
        } elseif (in_array('passwd', $cols)) {
          $fields[] = 'passwd = :pw';
          $params[':pw'] = $hash;
        }
      }

      if (empty($fields)) throw new Exception('No writable user columns detected.');

      // Determine WHERE clause based on detected PK column or fallback to email
      if (!empty($pkCol) && $pkCol !== 'email') {
        $where = "$pkCol = :pk";
        // prefer actual PK value from $current to be safe
        $params[':pk'] = $current[$pkCol] ?? $userId;
      } else {
        $where = 'email = :whereEmail';
        $params[':whereEmail'] = $userEmail;
      }

      $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE ' . $where;
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);

      if (isset($_SESSION['username']) && $_SESSION['username'] !== $email) $_SESSION['username'] = $email;
      // Use Post/Redirect/Get to reload fresh data and avoid resubmission
      header('Location: MyAccount.php?saved=1');
      exit;
    } catch (Exception $e) {
      $m = $e->getMessage();
      // If the error is specifically about the current password, store it in session and redirect (PRG)
      if (stripos($m, 'current password') !== false || stripos($m, 'Current password incorrect') !== false) {
        $_SESSION['MyAccount_password_error_msg'] = $m;
        header('Location: MyAccount.php?pwerr=1');
        exit;
      } else {
        $err = 'Error updating account: ' . $m;
      }
    }
  }
}

$user = null;
  if ($pdoExists) {
  try {
    $user = false;
    if ($userId) {
      foreach (['id','user_id','uid'] as $c) {
        try {
          $s = $pdo->prepare("SELECT * FROM users WHERE $c = :id LIMIT 1");
          $s->execute([':id' => $userId]);
          $row = $s->fetch(PDO::FETCH_ASSOC);
          if ($row) { $user = $row; break; }
        } catch (Exception $e) {
          // try next candidate
        }
      }
    }
    if (!$user) {
      $s = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
      $s->execute([':email'=>$userEmail]);
      $user = $s->fetch(PDO::FETCH_ASSOC);
    }

    // Normalize display fields to handle different schemas
    if ($user) {
      // prefer first_name + last_name when available
      if (isset($user['first_name']) || isset($user['last_name'])) {
        $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
      } else {
        $displayName = $user['name'] ?? $user['username'] ?? '';
      }
      $displayPhone = format_phone_display($user['phone'] ?? '');
      $displayEmail = $user['email'] ?? '';
      $displayPasswordHash = $user['password_hash'] ?? $user['password'] ?? $user['passwd'] ?? '';
      // overwrite $user keys used in the template for compatibility
      $user['name'] = $displayName;
      $user['phone'] = $displayPhone;
      $user['email'] = $displayEmail;
      $user['password'] = $displayPasswordHash;
      // load boats for this user using the existing boats schema (no schema changes)
      $boats = [];
      try {
        $bmap = get_boat_column_map($pdo);
        $colBoatId = $bmap['boat_id'];
        $colUserId = $bmap['user_id'];
        $colBoatName = $bmap['boat_name'];
        $colBoatLength = $bmap['boat_length'];
        $colDateCreated = $bmap['date_created'] ?? 'date_created';

        if (!empty($colUserId) && !empty($colBoatId) && !empty($colBoatName) && !empty($colBoatLength)) {
          // resolve user's numeric id from loaded $user row or session
          $ownerIdForQuery = null;
          if (!empty($user['user_ID'])) $ownerIdForQuery = intval($user['user_ID']);
          if (empty($ownerIdForQuery) && !empty($user['id'])) $ownerIdForQuery = intval($user['id']);
          if (empty($ownerIdForQuery) && !empty($userId)) $ownerIdForQuery = intval($userId);

          if (!empty($ownerIdForQuery)) {
            $orderBy = in_array($colDateCreated, ['date_created','created_at']) ? $colDateCreated : $colDateCreated;
            $sql = "SELECT {$colBoatId} AS boat_id, {$colBoatName} AS name, {$colBoatLength} AS length_ft FROM boats WHERE {$colUserId} = :uid ORDER BY {$orderBy} DESC";
            $bq = $pdo->prepare($sql);
            $bq->execute([':uid' => $ownerIdForQuery]);
            $boats = $bq->fetchAll(PDO::FETCH_ASSOC);
          } else {
            $boats = [];
          }
        } else {
          // schema doesn't match expected boats layout — return empty list
          $boats = [];
        }
      
      // Load reservations for this user (if the reservations table exists).
      $reservations = [];
      $activeReservations = [];
      $pastReservations = [];
      try {
        // Detect whether reservations table exposes a numeric user-id column we can use
        $resUserCol = null;
        try {
          $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations'");
          $colStmt->execute();
          $resCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $ce) {
          $resCols = [];
        }
        $candidates = ['user_id','user_ID','userid','userID','owner_id','account_id'];
        foreach ($candidates as $c) { if (in_array($c, $resCols)) { $resUserCol = $c; break; } }

        // Resolve numeric user id from session or loaded user row when possible
        $resolvedUid = null;
        foreach (['user_ID','id','user_id','uid'] as $c) { if (!empty($user[$c])) { $resolvedUid = intval($user[$c]); break; } }
        if (empty($resolvedUid) && !empty($userId) && is_numeric($userId)) $resolvedUid = intval($userId);

        // Build a list of candidate queries and try them until one returns results.
        $candidateQueries = [];
        // Prepare an email fallback value (covers legacy schema variants)
        $emailForLookup = $user['email'] ?? $userEmail ?? '';

        // If we detected a numeric user id and reservations table has id-like columns, prefer those queries first.
        if ($resolvedUid) {
          // try every candidate numeric column that exists in the table
          foreach ($candidates as $c) {
            if (in_array($c, $resCols)) {
              $candidateQueries[] = [
                'sql' => "SELECT * FROM reservations WHERE `{$c}` = :uid ORDER BY COALESCE(start_date, created_at) DESC",
                'params' => [':uid' => $resolvedUid]
              ];
            }
          }
        }

        // Also attempt to match by email on those same reservation columns, to support schemas
        // where the `user_id`/`user_ID` column actually stores the user's email address.
        if (!empty($emailForLookup)) {
          foreach ($candidates as $c) {
            if (in_array($c, $resCols)) {
              $candidateQueries[] = [
                'sql' => "SELECT * FROM reservations WHERE `{$c}` = :email ORDER BY COALESCE(start_date, created_at) DESC",
                'params' => [':email' => $emailForLookup]
              ];
            }
          }
        }

        // If we don't yet have a numeric user id but we do have an email, try to resolve user_ID from users table
        if (empty($resolvedUid) && !empty($emailForLookup)) {
          try {
            $uq = $pdo->prepare('SELECT user_ID FROM users WHERE email = :email LIMIT 1');
            $uq->execute([':email' => $emailForLookup]);
            $urow = $uq->fetch(PDO::FETCH_ASSOC);
            if ($urow && !empty($urow['user_ID'])) {
              $resolvedUid = intval($urow['user_ID']);
            }
          } catch (Exception $ux) {
            // ignore lookup failures and proceed with email fallback
          }
        }

        // Always include a general email-based fallback that checks common email columns
        $candidateQueries[] = [
          'sql' => 'SELECT * FROM reservations WHERE user_email = :email OR email = :email ORDER BY COALESCE(start_date, created_at) DESC',
          'params' => [':email' => $emailForLookup]
        ];

        // Prioritized reservation lookup:
        // 1) If we have a numeric user id, query reservations.user_ID directly.
        // 2) If that returns nothing and we have an email, join users -> reservations by users.user_ID (reliable for FK schema).
        // 3) Legacy fallback: look for email values stored directly on reservations (user_email/email columns).
        $reservations = [];
        try {
          if (!empty($resolvedUid)) {
            $rq = $pdo->prepare("SELECT * FROM reservations WHERE user_ID = :uid ORDER BY COALESCE(start_date, date_created) DESC");
            $rq->execute([':uid' => $resolvedUid]);
            $reservations = $rq->fetchAll(PDO::FETCH_ASSOC);
          }

          if (empty($reservations) && !empty($emailForLookup)) {
            // Join against users to ensure we match the account that owns the reservation via FK
            $rq = $pdo->prepare("SELECT r.* FROM reservations r JOIN users u ON u.user_ID = r.user_ID WHERE u.email = :email ORDER BY COALESCE(r.start_date, r.date_created) DESC");
            $rq->execute([':email' => $emailForLookup]);
            $reservations = $rq->fetchAll(PDO::FETCH_ASSOC);
          }

          if (empty($reservations) && !empty($emailForLookup)) {
            // Legacy schema fallback: check reservations table email columns
            $rq = $pdo->prepare('SELECT * FROM reservations WHERE user_email = :email OR email = :email ORDER BY COALESCE(start_date, date_created) DESC');
            $rq->execute([':email' => $emailForLookup]);
            $reservations = $rq->fetchAll(PDO::FETCH_ASSOC);
          }
        } catch (Exception $qe) {
          // leave $reservations empty on error
          $reservations = [];
        }

        // Enrich reservations with boat and slip details (batch lookups)
        try {
          $boatMap = [];
          $slipMap = [];
          $boatIds = [];
          $slipIds = [];
          // Candidate keys for slip id in reservations rows (handle schema variations)
          $slipKeyCandidates = ['slip_ID','slip_id','slip','slot','slip_number','slot_id','slipID','id'];
          foreach ($reservations as $rr) {
            if (!empty($rr['boat_ID'])) $boatIds[] = intval($rr['boat_ID']);
            // detect slip id value from multiple possible column names
            foreach ($slipKeyCandidates as $sk) {
              if (!empty($rr[$sk]) && is_numeric($rr[$sk])) { $slipIds[] = intval($rr[$sk]); break; }
            }
          }
          $boatIds = array_values(array_unique($boatIds));
          $slipIds = array_values(array_unique($slipIds));

          if (!empty($boatIds)) {
            $bmap = get_boat_column_map($pdo);
            $colBoatId = $bmap['boat_id'] ?? 'boat_ID';
            $colBoatName = $bmap['boat_name'] ?? 'boat_name';
            $colBoatLength = $bmap['boat_length'] ?? 'boat_length';
            $placeholders = implode(',', array_fill(0, count($boatIds), '?'));
            $btSql = "SELECT {$colBoatId} AS boat_id, {$colBoatName} AS boat_name, {$colBoatLength} AS boat_length FROM boats WHERE {$colBoatId} IN ({$placeholders})";
            $btStmt = $pdo->prepare($btSql);
            $btStmt->execute($boatIds);
            while ($brow = $btStmt->fetch(PDO::FETCH_ASSOC)) {
              $boatMap[intval($brow['boat_id'])] = $brow;
            }
          }

          if (!empty($slipIds)) {
            // detect slip location column
            try {
              $sc = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slips'");
              $sc->execute();
              $slipCols = $sc->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $ex) { $slipCols = []; }
            $colSlipId = in_array('slip_ID', $slipCols) ? 'slip_ID' : (in_array('id', $slipCols) ? 'id' : 'slip_ID');
            $colLoc = in_array('location_code', $slipCols) ? 'location_code' : (in_array('code', $slipCols) ? 'code' : (in_array('location', $slipCols) ? 'location' : (in_array('slip_number', $slipCols) ? 'slip_number' : 'location_code')));
            $placeholders = implode(',', array_fill(0, count($slipIds), '?'));
            $slSql = "SELECT {$colSlipId} AS slip_id, {$colLoc} AS location_code FROM slips WHERE {$colSlipId} IN ({$placeholders})";
            $slStmt = $pdo->prepare($slSql);
            $slStmt->execute($slipIds);
            while ($srow = $slStmt->fetch(PDO::FETCH_ASSOC)) {
              $slipMap[intval($srow['slip_id'])] = $srow['location_code'] ?? '';
            }
          }

          // Apply maps to reservations
          foreach ($reservations as &$rr) {
            $bid = intval($rr['boat_ID'] ?? 0);
            if ($bid && isset($boatMap[$bid])) {
              $rr['boat_name'] = $boatMap[$bid]['boat_name'] ?? ($rr['boat_name'] ?? '');
              $rr['boat_length'] = $boatMap[$bid]['boat_length'] ?? ($rr['boat_length'] ?? '');
            } else {
              $rr['boat_name'] = $rr['boat_name'] ?? '';
              $rr['boat_length'] = $rr['boat_length'] ?? '';
            }
            // resolve slip id from reservation row using detected candidate keys
            $sid = 0;
            foreach ($slipKeyCandidates as $sk) {
              if (!empty($rr[$sk]) && is_numeric($rr[$sk])) { $sid = intval($rr[$sk]); break; }
            }
            $rr['slip_location'] = $sid && isset($slipMap[$sid]) ? $slipMap[$sid] : ($rr['slip_location'] ?? ($rr['location'] ?? ''));
          }
          unset($rr);
        } catch (Exception $en) {
          // ignore enrichment errors
        }

        // Split into active (upcoming/ongoing) and past reservations
        $today = date('Y-m-d');
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
      } catch (Exception $e) {
        $reservations = [];
        $activeReservations = [];
        $pastReservations = [];
      }
      } catch (Exception $e) {
        $boats = [];
      }
    }
  } catch (Exception $e) {
    $err = 'Unable to load account: ' . $e->getMessage();
  }
}

// If we were redirected after a successful save, show a friendly message
if (empty($err) && isset($_GET['saved']) && $_GET['saved'] == '1') {
  $msg = 'Account updated successfully.';
}

// If we were redirected after a password error, show it once and clear session so refresh won't repeat
if (empty($err) && isset($_GET['pwerr']) && !empty($_SESSION['MyAccount_password_error_msg'])) {
  $err = $_SESSION['MyAccount_password_error_msg'];
  $passwordError = true;
  unset($_SESSION['MyAccount_password_error_msg']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Personal Information — Moffat Bay</title>
  <link rel="stylesheet" href="styles.css?v=4">
  <style>
    :root{
      --navy: #1F2F45;
      --cream: #F2E6C9;
      --boat-white: #F8F9FA;
      --ocean: #3F87A6;
      --pine: #2F5D4A;
      --gold: #F4C26B;
      --coral: #E8896B;
      --gray: #D8DEE4;
      --max-width: 1100px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; background:var(--boat-white); color:var(--navy);font-size:16px}

    /* Top navigation */
    .topbar{background:var(--boat-white);border-bottom:1px solid var(--gray)}
    .container{max-width:var(--max-width);margin:0 auto;padding:12px 20px;display:flex;align-items:center;justify-content:space-between}
    .logo{display:flex;align-items:center}
    .logo-link img{height:56px;width:56px;object-fit:cover;border-radius:50%}
    nav{display:flex;align-items:center}
    nav a{color:var(--navy);text-decoration:none;font-size:14px}
    /* left and right groups inside nav; right group pushed using margin-left */
    nav .nav-left{display:flex;gap:18px}
    nav .nav-right{margin-left:auto;display:flex;gap:18px;align-items:center}
    /* ensure spacing between last left link and the account links */
    nav .nav-left a:last-child{margin-right:18px}

    /* Content wrapper */
    .page{max-width:980px;margin:0 auto;padding:20px}
    .account-card{background:#fff;border-radius:10px;padding:20px;border:1px solid var(--gray);box-shadow:0 8px 30px rgba(31,47,69,0.06);position:relative}
    /* stronger, more noticeable labels for account and boat fields */
    label{display:block;margin-top:12px;font-weight:700;font-size:15px;color:var(--navy)}
    input[type=text],input[type=email],input[type=tel],input[type=password],input[type=number]{width:100%;padding:10px;border-radius:8px;border:1px solid #d6dbe1;margin-top:6px}
    /* Narrow the current-password confirm box when shown during Edit Information */
    #currentPwRow input[type="password"]{width:320px;max-width:100%;display:inline-block}
    /* Make disabled account inputs look like plain text until user clicks Edit Information */
    #accountForm input[disabled]{
      background:transparent;
      border:none;
      padding:0;
      width:auto;
      margin-top:6px;
      color:var(--navy);
      font-size:16px;
    }
    /* ensure layout doesn't collapse for labels/values */
    #accountForm label{margin-bottom:4px}
    .muted{color:#6b7280;font-size:13px}
    .actions{margin-top:0;display:flex;gap:12px;justify-content:flex-end;position:absolute;right:20px;bottom:20px;top:auto;transform:none}
    .btn{font-weight:700;border-radius:8px;padding:10px 16px;cursor:pointer;border:1px solid transparent}
    .btn.save{background:var(--ocean);color:var(--boat-white);border-color:var(--ocean)}
    .btn.cancel{background:transparent;color:var(--ocean);border:1px solid var(--ocean)}
    /* Ensure buttons inside account cards share consistent sizing */
    .account-card .btn, .account-card a.btn {
      padding: 12px 22px; 
      border-radius: 12px;
      font-weight: 400;
      font-size: 16px;
      min-width: 120px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
    }
    /* Match Delete Account outline for specific controls */
    #changePwBtn, .boat-delete-btn {
      background: transparent !important;
      border: 1px solid var(--coral) !important;
      color: var(--coral) !important;
    }
    /* Extra visual polish for My Account page */
    .account-card{border-left:4px solid rgba(63,135,166,0.18);box-shadow:0 14px 40px rgba(31,47,69,0.06)}
    .account-card h2, .account-card h3{color:var(--navy);font-weight:800}
    .section-title{font-size:18px;font-weight:800;margin:0 0 10px;color:var(--navy)}
    .boats-list{list-style:none;padding:0;margin:0}
    .boats-list li{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
    .boats-list li .muted{margin-top:4px}

    /* Make reservation lists visually identical to the boats list */
    .reservations-list{list-style:none;padding:0;margin:0}
    .reservations-list li{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
    .reservations-list li .muted{margin-top:4px}
    /* Reservation item specifics matching Boats visuals */
    .reservation-item{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
    .reservation-main{flex:1}
    .reservation-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;font-size:13px}
    .reservation-meta{color:#6b7280;font-size:13px;margin-top:6px}
    .reservation-label{font-weight:700;margin-right:6px;color:var(--navy);font-size:13px}
    .boat-edit-form{background:#f7fcfd;border:1px solid rgba(63,135,166,0.06);padding:12px;border-radius:8px;margin-top:8px}
    .account-meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
    .account-meta > div{background:linear-gradient(180deg,rgba(63,135,166,0.03),transparent);padding:8px 10px;border-radius:8px;border:1px solid rgba(31,47,69,0.03);font-size:14px}
    /* button hover states */
    .btn.save:hover{filter:brightness(.97);transform:translateY(-1px)}
    .btn.cancel:hover{background:rgba(63,135,166,0.04)}
    /* subtle spacing for section headers inside page */
    .page h1, .page h2, .page h3{margin-top:0;margin-bottom:8px}
    /* Personal info form visuals */
    .account-card-header{display:flex;align-items:center;gap:12px;margin-bottom:12px}
    .account-card-header .icon{font-size:28px;background:linear-gradient(180deg,var(--ocean),rgba(63,135,166,0.9));color:var(--boat-white);width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;box-shadow:0 6px 18px rgba(31,47,69,0.12)}
    .info-row{display:flex;gap:12px;align-items:flex-start;padding:8px 0;border-bottom:1px dashed rgba(0,0,0,0.04)}
    .info-icon{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(63,135,166,0.06);color:var(--ocean);font-size:18px;flex:0 0 36px}
    .info-body{flex:1}
    .info-body .info-label{font-weight:700;color:var(--navy);margin-bottom:6px}
    .info-value{color:var(--navy);font-size:16px}
    @media (max-width:520px){.info-row{flex-direction:column}.info-icon{width:32px;height:32px}}
    .hash{word-break:break-all;background:#f7f9fb;padding:10px;border-radius:8px;border:1px solid rgba(0,0,0,0.04);margin-top:8px}
    .notice{padding:12px;border-radius:8px}
    .field-error{font-size:14px}
    @media (max-width:900px){.actions{flex-direction:column;align-items:stretch}}

    /* Responsive: restore flow on narrow screens so actions stack below content */
    @media (max-width:900px){
      .actions{position:static;transform:none;margin-top:18px;justify-content:flex-end}
    }
    /* Modal for reservation cancellation confirmation */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.45);display:none;align-items:center;justify-content:center;z-index:1200}
    .modal{background:#fff;border-radius:10px;padding:18px;max-width:480px;width:100%;box-shadow:0 12px 40px rgba(31,47,69,0.15)}
    .modal h4{margin:0 0 8px;font-size:18px}
    .modal .muted{margin-bottom:12px}
    .modal input[type=password]{width:100%;padding:10px;border-radius:8px;border:1px solid #d6dbe1}
    .modal .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
  </style>
  <style>
    /* Final overrides copied from login page to ensure exact spacing parity */
    .notice-wrap{margin-top:24px !important}
    .card-wrap{margin-top:12px !important}
  </style>
  <style>
    /* Section typography overrides for better visibility */
    .muted{color:#374151 !important;font-size:14px;line-height:1.35}
    .info-value{color:var(--navy) !important;font-size:16px;font-weight:600}
    .reservation-title{font-size:16px;color:var(--navy);font-weight:800;margin-bottom:6px}
    .reservation-meta{color:#334155;font-size:14px;margin-top:6px}
    .reservation-label{font-weight:700;color:var(--navy);font-size:14px}
    .boats-list li, .reservation-item{padding:14px;border-color:rgba(31,47,69,0.06);margin-bottom:12px}
    .boats-list li .muted{color:#374151;font-size:14px}
  </style>
</head>
<body>
  <?php include 'nav.php'; ?>
  <?php
    $hero_title = 'Your Account';
    $hero_subtitle = 'Manage your personal information and boats';
    $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round-icon lucide-user-round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>';
    $hero_classes = 'hero-account';
    include 'hero.php';
  ?>

  <!-- Page-level :root override removed to preserve consistent nav/footer sizing across pages. -->

  <script>
    // Force layout client-side in case server or cache prevents CSS updates
    document.addEventListener('DOMContentLoaded', function(){
      try {
        var cards = document.querySelectorAll('.page .account-card');
        cards.forEach(function(c,i){
          c.style.maxWidth = '1000px';
          c.style.width = '100%';
          c.style.marginLeft = 'auto';
          c.style.marginRight = 'auto';
          c.style.boxSizing = 'border-box';
          c.style.marginTop = '16px';
        });
        if(cards.length){
          cards[0].style.marginTop = '-60px';
          cards[0].style.position = 'relative';
          cards[0].style.zIndex = '5';
        }
        console.log('MYACCOUNT_FORCE_APPLIED');
      } catch(e) { console.log('MYACCOUNT_FORCE_ERROR', e); }
    });
  </script>

  <section class="page">
  
    <?php if (!empty($dbError)): ?>
      <div class="notice" style="background:#fff0f0;color:#7a1f11;border:1px solid #ffdede"><?=htmlspecialchars($dbError)?></div>
    <?php endif; ?>
    <?php if (!empty($err) && empty($passwordError)): ?>
      <div class="notice" style="background:#fff0f0;color:#7a1f11;border:1px solid #ffdede"><?=htmlspecialchars($err)?></div>
    <?php elseif ($msg): ?>
      <div class="notice" style="background:#e8f9f1;color:var(--pine);border:1px solid rgba(47,93,74,0.1)"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>

    <div class="account-card">
      <?php if ($user): ?>
        <form id="accountForm" method="post" action="MyAccount.php">
          <div class="account-card-header">
            <div>
              <h3 class="section-title" style="margin-bottom:6px">Personal Information</h3>
              <div class="muted" style="margin-top:6px">Manage your contact details and password</div>
            </div>
          </div>

          <div class="info-row">
            <div class="info-body">
              <div class="info-label">Name</div>
              <div class="info-value"><input type="text" id="name" name="name" value="<?=htmlspecialchars($user['name'] ?? '')?>" disabled></div>
            </div>
          </div>

          <div class="info-row">
            <div class="info-body">
              <div class="info-label">Phone Number</div>
              <div class="info-value"><input type="tel" id="phone" name="phone" value="<?=htmlspecialchars($user['phone'] ?? '')?>" disabled></div>
            </div>
          </div>

          <div class="info-row">
            <div class="info-body">
              <div class="info-label">Email Address</div>
              <div class="info-value"><input type="email" id="email" name="email" value="<?=htmlspecialchars($user['email'] ?? '')?>" disabled></div>
            </div>
          </div>

          <!-- Password hash removed from display for security -->

          <input type="hidden" id="change_password" name="change_password" value="0">

          <div id="newPwRow" style="display:none;margin-top:12px">
            <label for="new_password">New Password (leave blank to keep current)</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" disabled>

            <div id="pwConfirmRow" style="display:none;margin-top:8px">
              <label for="new_password_confirm">Confirm New Password</label>
              <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" disabled>
            </div>
          </div>

          <div id="currentPwRow" style="display:none;margin-top:12px">
            <label for="current_password">Confirm Current Password</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password">
            <div id="currentPwError" class="field-error" style="display:none;margin-top:8px;padding:8px;border-radius:6px;background:#fff6f6;color:#7a1f11;border:1px solid #ffdede;font-size:14px"></div>
          </div>

          <div class="actions">
            <button class="btn save" type="button" id="editBtn">Edit Information</button>
            <button class="btn" type="button" id="changePwBtn">Change Password</button>
            <button class="btn cancel" type="button" id="cancelBtn" style="display:none">Cancel</button>
            <button class="btn save" type="submit" id="saveBtn" style="display:none">Save Changes</button>
          </div>
        </form>
      <?php else: ?>
        <div class="muted">Account data not available.</div>
      <?php endif; ?>
    </div>
    <?php if (true): ?>
      <div class="account-card">
        <h3 class="section-title">Reservations</h3>
        <div class="muted" style="margin-top:6px">Manage your reservations.</div>
        <div style="margin-top:12px">
          <a href="reservation_summary.php" class="btn save">View Reservations</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="account-card">
      <h3 class="section-title">Boats</h3>
      <div class="muted" style="margin-top:6px">Manage your boats.</div>
      <div style="margin-top:12px">
        <button id="showBoatsBtn" class="btn save" aria-expanded="false" type="button">Your Boats</button>
      </div>
    </div>
    <div id="boatsSection" class="account-card" style="margin-top:18px;display:none">
      <?php if (!empty($boats)): ?>
        <ul style="list-style:none;padding:0;margin:0">
          <?php foreach ($boats as $b): ?>
            <li style="border:1px solid #eee;padding:12px;border-radius:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
              <div class="boat-main">
                <strong><?=htmlspecialchars($b['name'] ?? '')?></strong>
                <div class="muted">Length: <?=htmlspecialchars($b['length_ft'] ?? '')?> ft</div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <button class="btn save boat-edit-btn" data-boat-id="<?=intval($b['boat_id'])?>">Edit</button>
                <button class="btn boat-delete-btn" data-boat-id="<?=intval($b['boat_id'])?>">Delete</button>
                <div class="boat-delete-form" data-boat-id="<?=intval($b['boat_id'])?>" style="display:none;margin-left:8px">
                  <form method="post" onsubmit="return confirm('Delete this boat?');" style="display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="boat_action" value="delete">
                    <input type="hidden" name="boat_id" value="<?=intval($b['boat_id'])?>">
                    <input type="password" name="current_password" placeholder="Current password" required style="margin-right:6px">
                    <button class="btn" type="submit">Confirm</button>
                    <button type="button" class="btn cancel boat-delete-cancel">Cancel</button>
                  </form>
                </div>
              </div>
              <div class="boat-edit-form" data-boat-id="<?=intval($b['boat_id'])?>" style="display:none;margin-top:8px;width:100%">
                <form method="post">
                  <input type="hidden" name="boat_action" value="edit">
                  <input type="hidden" name="boat_id" value="<?=intval($b['boat_id'])?>">
                  <label style="display:block">Boat Name</label>
                  <input type="text" name="boat_name" value="<?=htmlspecialchars($b['name'] ?? '')?>" required>
                  <label style="display:block;margin-top:8px">Boat Length (ft)</label>
                  <input type="number" name="boat_length" value="<?=intval($b['length_ft'] ?? 0)?>" min="1" required>
                  <div class="muted" style="font-size:13px;margin-top:6px">Boat length should be rounded to the next whole foot.</div>
                  <label style="display:block;margin-top:8px">Confirm Current Password</label>
                  <input type="password" name="current_password" required>
                  <div style="margin-top:8px">
                    <button class="btn save" type="submit">Save</button>
                    <button type="button" class="btn cancel boat-edit-cancel">Cancel</button>
                  </div>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="muted">No boats on file.</div>
      <?php endif; ?>

      <div style="margin-top:12px;border-top:1px dashed #e6e6e6;padding-top:12px">
        <h3 class="section-title">Add a Boat</h3>
        <form method="post" id="addBoatForm">
          <input type="hidden" name="boat_action" value="add">
          <label style="display:block">Boat Name</label>
          <input type="text" name="boat_name" required>
          <label style="display:block;margin-top:8px">Boat Length (ft)</label>
          <input type="number" name="boat_length" min="1" required>
          <div class="muted" style="font-size:13px;margin-top:6px">Boat length should be rounded to the next whole foot.</div>
          <div style="margin-top:10px">
            <button class="btn save" type="submit">Add Boat</button>
          </div>
        </form>
      </div>

      
    </div>
  </section>

  <!-- Delete Account card placed separately to emphasize its importance -->
  <section class="page">
    <div class="account-card" style="border-left:4px solid rgba(232,137,107,0.18);">
      <h3 class="section-title" style="color:var(--coral)">Delete Account</h3>
      <div class="muted" style="margin-top:6px">Deleting your account will remove your user record and associated boats. Reservations will be retained for records but will note the account was deleted.</div>
      <div style="margin-top:12px">
        <button id="showDeleteAccount" class="btn" type="button" style="background:transparent;border:1px solid var(--coral);color:var(--coral)">Delete Account</button>

        <div id="deleteAccountForm" style="display:none;margin-top:12px">
          <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete your account? This action cannot be undone.');">
            <input type="hidden" name="account_action" value="delete">
            <label style="display:block;margin-top:6px">Enter Current Password to Confirm</label>
            <input type="password" name="current_password" id="delete_current_password" required style="margin-top:6px">
            <div style="margin-top:10px">
              <button class="btn" type="submit" style="background:var(--coral);color:var(--boat-white);border-color:var(--coral)">Confirm Delete</button>
              <button type="button" class="btn cancel" id="cancelDeleteAccount" style="margin-left:8px">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
  <script>
    // Toggle edit mode for account form and change-password flow
    (function(){
      const editBtn = document.getElementById('editBtn');
      const changePwBtn = document.getElementById('changePwBtn');
      const saveBtn = document.getElementById('saveBtn');
      const cancelBtn = document.getElementById('cancelBtn');
      const form = document.getElementById('accountForm');
      const inputs = form ? form.querySelectorAll('input[type="text"],input[type="email"],input[type="tel"]') : [];
      const pwdInputs = form ? form.querySelectorAll('input[type="password"]') : [];
      const currentRow = document.getElementById('currentPwRow');
      const pwConfirmRow = document.getElementById('pwConfirmRow');
      const newPw = document.getElementById('new_password');
      const newPwConfirm = document.getElementById('new_password_confirm');
      const changeFlag = document.getElementById('change_password');
      // Boats section toggler
      const showBoatsBtn = document.getElementById('showBoatsBtn');
      const boatsSection = document.getElementById('boatsSection');
      if(showBoatsBtn && boatsSection){
        showBoatsBtn.addEventListener('click', function(){
          const expanded = this.getAttribute('aria-expanded') === 'true';
          if(expanded){
            boatsSection.style.display = 'none';
            this.setAttribute('aria-expanded','false');
            this.textContent = 'Your Boats';
          } else {
            boatsSection.style.display = '';
            this.setAttribute('aria-expanded','true');
            this.textContent = 'Hide Boats';
          }
        });
      }

      let pwChanging = false;
      let editOn = false;

      function setEdit(on){
        editOn = !!on;
        // If user opens Edit Information, clear any active password-change UI
        if (on && pwChanging) {
          pwChanging = false;
          const newPwRow = document.getElementById('newPwRow');
          if (newPwRow) newPwRow.style.display = 'none';
          if (pwConfirmRow) pwConfirmRow.style.display = 'none';
          newPw.value = '';
          if (newPwConfirm) newPwConfirm.value = '';
          newPw.disabled = true;
          if (newPwConfirm) newPwConfirm.disabled = true;
          if (changeFlag) changeFlag.value = '0';
          if (changePwBtn) changePwBtn.textContent = 'Change Password';
        }

        // Boats: edit form toggles
        document.querySelectorAll('.boat-edit-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var id = this.getAttribute('data-boat-id');
            var form = document.querySelector('.boat-edit-form[data-boat-id="'+id+'"]');
            if (!form) return;
            var visible = form.style.display !== 'none';
            // hide all others and restore their left info
            document.querySelectorAll('.boat-edit-form').forEach(function(f){ f.style.display = 'none'; });
            document.querySelectorAll('.boat-main').forEach(function(m){ m.style.display = ''; });
            document.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display = ''; });
            if (!visible) {
              form.style.display = 'block';
              // hide this item's left info and its action buttons
              var li = form.closest('li');
              if (li) {
                var main = li.querySelector('.boat-main'); if (main) main.style.display = 'none';
                li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(el){ el.style.display = 'none'; });
              }
              form.scrollIntoView({behavior:'smooth',block:'center'});
            }
          });
        });

        document.querySelectorAll('.boat-edit-cancel').forEach(function(btn){
          btn.addEventListener('click', function(){
            var wrapper = this.closest('.boat-edit-form');
            if (wrapper) wrapper.style.display = 'none';
          });
        });
        
          // Delete button: reveal a small password-confirm form only when user requests delete
          document.querySelectorAll('.boat-delete-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
              var id = this.getAttribute('data-boat-id');
              var form = document.querySelector('.boat-delete-form[data-boat-id="'+id+'"]');
              if (!form) return;
              // hide other open delete forms and restore their controls
              document.querySelectorAll('.boat-delete-form').forEach(function(f){ f.style.display = 'none'; var li = f.closest('li'); if (li) { li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display=''; }); } });
              // hide edit/delete buttons for this item only
              var li = form.closest('li');
              if (li) {
                li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display='none'; });
              }
              form.style.display = 'block';
              form.scrollIntoView({behavior:'smooth',block:'center'});
            });
          });
          document.querySelectorAll('.boat-delete-cancel').forEach(function(btn){
            btn.addEventListener('click', function(){
              var wrapper = this.closest('.boat-delete-form');
              if (wrapper) {
                wrapper.style.display = 'none';
                var li = wrapper.closest('li');
                if (li) {
                  // restore edit/delete buttons
                  li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display=''; });
                }
              }
            });
          });


        inputs.forEach(i => i.disabled = !on);

        if (on) {
          // When editing information, hide the Edit button and the Change Password option
          editBtn.style.display = 'none';
          saveBtn.style.display = '';
          cancelBtn.style.display = '';
          if (changePwBtn) changePwBtn.style.display = 'none';
          currentRow.style.display = '';
          document.getElementById('current_password').required = true;
          document.getElementById('name').focus();
        } else {
          // Not editing: restore Edit button and show Change Password on main page
          editBtn.style.display = '';
          saveBtn.style.display = pwChanging ? '' : 'none';
          cancelBtn.style.display = pwChanging ? '' : 'none';
          if (changePwBtn) changePwBtn.style.display = pwChanging ? '' : '';
          currentRow.style.display = pwChanging ? '' : 'none';
          document.getElementById('current_password').required = pwChanging;
        }
      }

      if (changePwBtn) {
        changePwBtn.addEventListener('click', () => {
          pwChanging = !pwChanging;
          const newPwRow = document.getElementById('newPwRow');
          if (pwChanging) {
            // show new-password UI and enable inputs
            if (newPwRow) newPwRow.style.display = '';
            if (pwConfirmRow) pwConfirmRow.style.display = '';
            newPw.disabled = false;
            if (newPwConfirm) newPwConfirm.disabled = false;
            newPw.required = true;
            if (newPwConfirm) newPwConfirm.required = true;
            if (changeFlag) changeFlag.value = '1';
            // hide the Change Password control while the standalone password-change UI is active
            if (changePwBtn) changePwBtn.style.display = 'none';
            // show Save/Cancel and require current password
            saveBtn.style.display = '';
            // show Cancel for password changes (user expects Cancel + Save)
            cancelBtn.style.display = '';
            // hide any other cancel buttons on the page so only the main Cancel shows
            document.querySelectorAll('.btn.cancel').forEach(function(el){ if (el.id !== 'cancelBtn') el.style.display = 'none'; });
            // while changing password, hide the Edit Information button
            if (editBtn) editBtn.style.display = 'none';
            currentRow.style.display = '';
            document.getElementById('current_password').required = true;
            newPw.focus();
          } else {
            // hide new-password UI and clear values
            if (newPwRow) newPwRow.style.display = 'none';
            if (pwConfirmRow) pwConfirmRow.style.display = 'none';
            newPw.value = '';
            if (newPwConfirm) newPwConfirm.value = '';
            newPw.disabled = true;
            if (newPwConfirm) newPwConfirm.disabled = true;
            newPw.required = false;
            if (newPwConfirm) newPwConfirm.required = false;
            if (changeFlag) changeFlag.value = '0';
            if (changePwBtn) { changePwBtn.style.display = ''; changePwBtn.textContent = 'Change Password'; }
            // restore buttons when password change cancelled
            if (!editOn) {
              saveBtn.style.display = 'none';
              cancelBtn.style.display = 'none';
              // restore any other cancel buttons that were hidden earlier
              document.querySelectorAll('.btn.cancel').forEach(function(el){ if (el.id !== 'cancelBtn') el.style.display = ''; });
              currentRow.style.display = 'none';
              document.getElementById('current_password').required = false;
              if (editBtn) editBtn.style.display = '';
            } else {
              // if editing info, ensure cancel is visible and Edit remains hidden
              cancelBtn.style.display = '';
              if (editBtn) editBtn.style.display = 'none';
            }
          }
        });
      }

      if (editBtn) editBtn.addEventListener('click', () => setEdit(true));
      if (cancelBtn) cancelBtn.addEventListener('click', function(){
        // If server flagged a password error, perform an in-page cancel to clear error
        var pwErr = <?php echo json_encode((bool)$passwordError); ?>;
        if (pwErr) {
          // hide current password row and its inline error, reset fields, and return to read-only
          try {
            var curRow = document.getElementById('currentPwRow');
            var curErr = document.getElementById('currentPwError');
            if (curRow) curRow.style.display = 'none';
            if (curErr) { curErr.textContent = ''; curErr.style.display = 'none'; }
            // Reset password input
            var cur = document.getElementById('current_password'); if (cur) cur.value = '';
            // restore buttons
            if (editBtn) editBtn.style.display = '';
            if (changePwBtn) changePwBtn.style.display = '';
            if (saveBtn) saveBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
            // disable inputs again (ensure read-only state)
            inputs.forEach(i => i.disabled = true);
          } catch (e) { location.reload(); }
        } else {
          location.reload();
        }
      });
    })();
    // Prompt user to round up boat length to next whole foot when needed
    (function(){
      function roundPrompt(e){
        const input = e.target.querySelector('input[name="boat_length"]');
        if (!input) return;
        const raw = input.value;
        if (!raw) return;
        const val = parseFloat(raw);
        if (isNaN(val) || val <= 0) return;
        if (Math.floor(val) !== val) {
          const rounded = Math.ceil(val);
          // Auto-round fractional feet up to the next whole foot for consistency
          input.value = rounded;
          // allow submit to continue with rounded value
          return;
        }
      }

      const add = document.getElementById('addBoatForm');
      if (add) add.addEventListener('submit', roundPrompt);
      document.querySelectorAll('.boat-edit-form form').forEach(function(f){ f.addEventListener('submit', roundPrompt); });
    })();
  </script>
  <script>
    // Ensure delete and edit handlers are attached even when account-edit UI hasn't been toggled
    (function(){
      document.querySelectorAll('.boat-delete-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.getAttribute('data-boat-id');
          var form = document.querySelector('.boat-delete-form[data-boat-id="'+id+'"]');
          if (!form) return;
          // hide any open edit forms and restore their controls
          document.querySelectorAll('.boat-edit-form').forEach(function(f){ f.style.display = 'none'; var li = f.closest('li'); if (li) { li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display=''; }); } });
          document.querySelectorAll('.boat-delete-form').forEach(function(f){ f.style.display = 'none'; var li = f.closest('li'); if (li) { li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display=''; }); } });
          // hide edit/delete buttons for this item only
          var li = form.closest('li');
          if (li) {
            li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display='none'; });
          }
          form.style.display = 'block';
          form.scrollIntoView({behavior:'smooth',block:'center'});
        });
      });
      document.querySelectorAll('.boat-delete-cancel').forEach(function(btn){
        btn.addEventListener('click', function(){
          var wrapper = this.closest('.boat-delete-form');
          if (wrapper) {
            wrapper.style.display = 'none';
            var li = wrapper.closest('li');
            if (li) {
              li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(b){ b.style.display=''; });
            }
          }
        });
      });

      // Edit button: show the inline edit form and hide other controls; show only Save/Cancel and password input
      document.querySelectorAll('.boat-edit-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = this.getAttribute('data-boat-id');
          var form = document.querySelector('.boat-edit-form[data-boat-id="'+id+'"]');
          if (!form) return;
          // hide other open edit/delete forms
          document.querySelectorAll('.boat-edit-form').forEach(function(f){ f.style.display = 'none'; });
          document.querySelectorAll('.boat-delete-form').forEach(function(f){ f.style.display = 'none'; });
          // show the edit form
          form.style.display = 'block';
          // hide Edit/Delete buttons and left info for the same list item to reduce confusion
          var li = form.closest('li');
          if (li) {
            var main = li.querySelector('.boat-main'); if (main) main.style.display = 'none';
            li.querySelectorAll('.boat-edit-btn, .boat-delete-btn').forEach(function(el){ el.style.display = 'none'; });
          }
          form.scrollIntoView({behavior:'smooth',block:'center'});
        });
      });

      // Cancel inside the boat-edit form should return the user to MyAccount.php (reload clean state)
      document.querySelectorAll('.boat-edit-cancel').forEach(function(btn){
        btn.addEventListener('click', function(){
          // Navigate back to the account page to clear any transient UI
          window.location.href = 'MyAccount.php';
        });
      });

      // Delete account UI toggle
      var showDel = document.getElementById('showDeleteAccount');
      var delForm = document.getElementById('deleteAccountForm');
      var cancelDel = document.getElementById('cancelDeleteAccount');
      if (showDel && delForm) {
        showDel.addEventListener('click', function(){
          delForm.style.display = delForm.style.display === 'none' ? 'block' : 'none';
          if (delForm.style.display === 'block') {
            var inp = document.getElementById('delete_current_password'); if (inp) inp.focus();
          }
        });
      }
      if (cancelDel && delForm) {
        cancelDel.addEventListener('click', function(){ delForm.style.display = 'none'; });
      }
    })();
  </script>
  <script>
    // If server detected a current-password error, reveal the current password row
    (function(){
      var pwErr = <?php echo json_encode((bool)$passwordError); ?>;
      if (!pwErr) return;
      document.addEventListener('DOMContentLoaded', function(){
        try {
          var curRow = document.getElementById('currentPwRow');
          var curErr = document.getElementById('currentPwError');
          var editBtn = document.getElementById('editBtn');
          var changeBtn = document.getElementById('changePwBtn');
          var saveBtn = document.getElementById('saveBtn');
          var cancelBtn = document.getElementById('cancelBtn');
          if (curRow) curRow.style.display = '';
          if (curErr) { curErr.textContent = <?php echo json_encode($err); ?>; curErr.style.display = 'block'; }
          if (saveBtn) saveBtn.style.display = '';
          if (cancelBtn) cancelBtn.style.display = '';
          if (editBtn) editBtn.style.display = 'none';
          if (changeBtn) changeBtn.style.display = 'none';
          var cur = document.getElementById('current_password');
          if (cur) { cur.focus(); }
        } catch(e) { /* ignore JS errors */ }
      });
    })();
  </script>
    <script>
      // Reservation action handlers: show inline edit form and handle cancel POST
      (function(){
        document.addEventListener('click', function(e){
          var t = e.target;
          if (!t) return;
          // Show edit form
          if (t.classList && t.classList.contains('reservation-edit-btn')) {
            var li = t.closest('li');
            if (!li) return;
            // close any other open edit forms and restore their action buttons and left view
            document.querySelectorAll('.reservation-edit-form').forEach(function(el){ el.style.display = 'none'; });
            document.querySelectorAll('.reservation-main').forEach(function(m){ m.style.display = ''; });
            document.querySelectorAll('.reservation-edit-btn, .reservation-cancel-btn').forEach(function(btn){ btn.style.display = ''; });
            // hide action buttons on this item while editing and hide its left details
            li.querySelectorAll('.reservation-edit-btn, .reservation-cancel-btn').forEach(function(btn){ btn.style.display = 'none'; });
            var main = li.querySelector('.reservation-main'); if (main) main.style.display = 'none';
            var formDiv = li.querySelector('.reservation-edit-form');
            if (formDiv) {
              formDiv.style.display = 'block';
              formDiv.scrollIntoView({behavior:'smooth', block:'center'});
            }
            return;
          }

          // Cancel reservation: open confirmation modal (requires password)
          if (t.classList && t.classList.contains('reservation-cancel-btn')) {
            var id = t.getAttribute('data-reservation-id') || '0';
            var modal = document.getElementById('reservationCancelModal');
            if (!modal) return;
            modal.style.display = 'flex';
            modal.querySelector('input[name="cancel_reservation_id"]').value = id;
            var pwInput = modal.querySelector('input[name="cancel_password"]');
            if (pwInput) pwInput.value = '';
            var confirmBtn = modal.querySelector('.cancel-confirm-btn');
            if (confirmBtn) confirmBtn.focus();
            return;
          }

          // Inline edit cancel button: hide form and restore action buttons and left view
          if (t.classList && t.classList.contains('reservation-edit-cancel')) {
            var li = t.closest('li');
            if (!li) return;
            var formDiv = li.querySelector('.reservation-edit-form');
            if (formDiv) formDiv.style.display = 'none';
            var main = li.querySelector('.reservation-main'); if (main) main.style.display = '';
            li.querySelectorAll('.reservation-edit-btn, .reservation-cancel-btn').forEach(function(btn){ btn.style.display = ''; });
          }
        });
      })();
    </script>
    <div id="reservationCancelModal" class="modal-backdrop" role="dialog" aria-modal="true" style="display:none">
      <div class="modal" role="document">
        <h4>Confirm Cancellation</h4>
        <div class="muted">Are you sure you want to cancel this reservation? This action cannot be undone. Please re-enter your current password to confirm.</div>
        <input type="hidden" name="cancel_reservation_id" value="">
        <label style="display:block;font-weight:700;margin-top:8px">Current Password</label>
        <input type="password" name="cancel_password" class="cancel-password" autocomplete="current-password">
        <div class="modal-actions">
          <button class="btn cancel cancel-modal-btn" type="button">Back</button>
          <button class="btn save cancel-confirm-btn" type="button">Confirm Cancel</button>
        </div>
      </div>
    </div>
    <script>
      // Modal button handlers
      (function(){
        var modal = document.getElementById('reservationCancelModal');
        if (!modal) return;
        modal.addEventListener('click', function(e){
          if (e.target === modal) modal.style.display = 'none';
        });
        document.querySelector('.cancel-modal-btn').addEventListener('click', function(){ modal.style.display = 'none'; });
        document.querySelector('.cancel-confirm-btn').addEventListener('click', function(){
          var id = modal.querySelector('input[name="cancel_reservation_id"]').value || '0';
          var pw = modal.querySelector('input[name="cancel_password"]').value || '';
          if (!pw) { alert('Enter your current password to confirm cancellation.'); modal.querySelector('input[name="cancel_password"]').focus(); return; }
          var f = document.createElement('form'); f.method = 'POST'; f.action = 'MyAccount.php';
          var a = document.createElement('input'); a.type='hidden'; a.name='reservation_action'; a.value='cancel'; f.appendChild(a);
          var b = document.createElement('input'); b.type='hidden'; b.name='reservation_id'; b.value=id; f.appendChild(b);
          var c = document.createElement('input'); c.type='hidden'; c.name='current_password'; c.value=pw; f.appendChild(c);
          document.body.appendChild(f);
          f.submit();
        });
      })();
    </script>
<?php include 'footer.php'; ?>
</body>
</html>
