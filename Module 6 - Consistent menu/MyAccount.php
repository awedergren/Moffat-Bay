<?php
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

$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);
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
$userEmail = $_SESSION['username'] ?? null;
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

  // When adding a boat while logged in we don't require the email field to be re-submitted
  if ($boat_action !== 'add' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
          if (!empty($colBoatId) && !empty($colUserId)) {
            $resolvedUid = $userId;
            if (empty($resolvedUid)) {
              $s = $pdo->prepare('SELECT user_ID FROM users WHERE email = :email LIMIT 1');
              $s->execute([':email' => $current['email'] ?? $userEmail]);
              $r = $s->fetch(PDO::FETCH_ASSOC);
              if ($r && !empty($r['user_ID'])) $resolvedUid = intval($r['user_ID']);
            }
            if (!empty($resolvedUid)) {
              $sql = "DELETE FROM boats WHERE {$colBoatId} = :bid AND {$colUserId} = :uid";
              $del = $pdo->prepare($sql);
              $del->execute([':bid'=>$boat_id, ':uid'=>$resolvedUid]);
            } else {
              $del = $pdo->prepare('DELETE FROM boats WHERE ' . ($colBoatId ?? 'boat_ID') . ' = :bid');
              $del->execute([':bid'=>$boat_id]);
            }
          } else {
            $del = $pdo->prepare('DELETE FROM boats WHERE ' . ($colBoatId ?? 'boat_ID') . ' = :bid');
            $del->execute([':bid'=>$boat_id]);
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
      try {
        // Try ordering by start_date first; fall back to created_at if needed
        try {
          $rq = $pdo->prepare('SELECT * FROM reservations WHERE user_email = :email OR email = :email ORDER BY start_date DESC');
          $rq->execute([':email' => $user['email']]);
          $reservations = $rq->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $inner) {
          $rq = $pdo->prepare('SELECT * FROM reservations WHERE user_email = :email OR email = :email ORDER BY created_at DESC');
          $rq->execute([':email' => $user['email']]);
          $reservations = $rq->fetchAll(PDO::FETCH_ASSOC);
        }
      } catch (Exception $e) {
        $reservations = [];
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
  <link rel="stylesheet" href="/styles.css?v=4">
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
    .account-card{background:#fff;border-radius:10px;padding:20px;border:1px solid var(--gray);box-shadow:0 8px 30px rgba(31,47,69,0.06)}
    /* stronger, more noticeable labels for account and boat fields */
    label{display:block;margin-top:12px;font-weight:700;font-size:15px;color:var(--navy)}
    input[type=text],input[type=email],input[type=tel],input[type=password],input[type=number]{width:100%;padding:10px;border-radius:8px;border:1px solid #d6dbe1;margin-top:6px}
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
    .actions{margin-top:18px;display:flex;gap:12px;justify-content:flex-end}
    .btn{font-weight:700;border-radius:8px;padding:10px 16px;cursor:pointer;border:1px solid transparent}
    .btn.save{background:var(--ocean);color:var(--boat-white);border-color:var(--ocean)}
    .btn.cancel{background:transparent;color:var(--ocean);border:1px solid var(--ocean)}
    /* Extra visual polish for My Account page */
    .account-card{border-left:4px solid rgba(63,135,166,0.18);box-shadow:0 14px 40px rgba(31,47,69,0.06)}
    .account-card h2, .account-card h3{color:var(--navy);font-weight:800}
    .section-title{font-size:18px;font-weight:800;margin:0 0 10px;color:var(--navy)}
    .boats-list{list-style:none;padding:0;margin:0}
    .boats-list li{background:linear-gradient(180deg,#ffffff,#fbfeff);border:1px solid rgba(31,47,69,0.04);padding:12px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center}
    .boats-list li .muted{margin-top:4px}
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
  </style>
  <style>
    /* Final overrides copied from login page to ensure exact spacing parity */
    .notice-wrap{margin-top:24px !important}
    .card-wrap{margin-top:12px !important}
  </style>
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="logo">
        <a href="index.php" class="logo-link" aria-label="Moffat Bay landing page">
          <img id="siteLogo" src="logo.png" alt="Moffat Bay logo">
        </a>
      </div>
      <nav>
        <div class="nav-left">
          <a href="#">About Us</a>
          <a href="#">Contact Us</a>
          <a href="#">Reservations</a>
          <a href="#">Reservation/Waitlist Lookup</a>
        </div>
        <div class="nav-right">
          <?php if ($loggedIn): ?>
            <a href="MyAccount.php">My Account</a>
            <a href="logout.php" class="btn ghost">Log out</a>
          <?php else: ?>
            <a href="BlueTeam_LoginPage.php">Login/Register</a>
          <?php endif; ?>
        </div>
      </nav>
    </div>
  </header>

  <section class="page">
    <h1 style="margin-top:0">Personal Information</h1>
    <?php if (!empty($dbError)): ?>
      <div class="notice" style="background:#fff0f0;color:#7a1f11;border:1px solid #ffdede"><?=htmlspecialchars($dbError)?></div>
    <?php endif; ?>
    <?php if (!empty($err) && empty($passwordError)): ?>
      <div class="notice" style="background:#fff0f0;color:#7a1f11;border:1px solid #ffdede"><?=htmlspecialchars($err)?></div>
    <?php elseif ($msg): ?>
      <div class="notice" style="background:#e8f9f1;color:var(--pine);border:1px solid rgba(47,93,74,0.1)"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>

    <div class="account-card" style="margin-top:16px">
      <?php if ($user): ?>
        <form id="accountForm" method="post" action="MyAccount.php">
          <div class="account-card-header">
            <div>
              <div class="muted" style="margin-bottom:6px">Manage your contact details and password</div>
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
    <?php if (!empty($reservations)): ?>
      <h2 class="section-title">Your Reservations</h2>
      <div class="account-card" style="margin-top:8px">
        <ul class="boats-list" style="list-style:none;padding:0;margin:0">
          <?php foreach ($reservations as $r): ?>
            <li>
              <div>
                <strong><?=htmlspecialchars($r['title'] ?? $r['reservation_name'] ?? 'Reservation')?></strong>
                <div class="muted">
                  <?php
                    $start = $r['start_date'] ?? $r['date'] ?? $r['reservation_date'] ?? $r['created_at'] ?? null;
                    $end = $r['end_date'] ?? null;
                    if ($start) echo htmlspecialchars(date('M j, Y', strtotime($start)));
                    if ($end) echo ' – ' . htmlspecialchars(date('M j, Y', strtotime($end)));
                  ?>
                </div>
                <div class="muted" style="margin-top:6px">Status: <?=htmlspecialchars($r['status'] ?? $r['state'] ?? 'Unknown')?></div>
              </div>
              <div class="muted" style="text-align:right;font-size:13px">
                <?=htmlspecialchars($r['location'] ?? $r['slip_number'] ?? $r['slot'] ?? '')?><br>
                <small class="muted"><?=htmlspecialchars($r['created_at'] ?? '')?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <h1 style="margin-top:18px">Your Boats</h1>
    <div class="account-card" style="margin-top:18px">
      <?php if (!empty($boats)): ?>
        <ul style="list-style:none;padding:0;margin:0">
          <?php foreach ($boats as $b): ?>
            <li style="border:1px solid #eee;padding:12px;border-radius:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
              <div>
                <strong><?=htmlspecialchars($b['name'] ?? '')?></strong>
                <div class="muted">Length: <?=htmlspecialchars($b['length_ft'] ?? '')?> ft</div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <button class="btn boat-edit-btn" data-boat-id="<?=intval($b['boat_id'])?>">Edit</button>
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
    <div class="account-card" style="margin-top:18px;border-left:4px solid rgba(232,137,107,0.18);">
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
            // hide all others
            document.querySelectorAll('.boat-edit-form').forEach(function(f){ f.style.display = 'none'; });
            if (!visible) {
              form.style.display = 'block';
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
              // hide other open delete forms
              document.querySelectorAll('.boat-delete-form').forEach(function(f){ f.style.display = 'none'; });
              form.style.display = 'block';
              form.scrollIntoView({behavior:'smooth',block:'center'});
            });
          });
          document.querySelectorAll('.boat-delete-cancel').forEach(function(btn){
            btn.addEventListener('click', function(){
              var wrapper = this.closest('.boat-delete-form');
              if (wrapper) wrapper.style.display = 'none';
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
          // hide any open edit forms or delete forms
          document.querySelectorAll('.boat-edit-form').forEach(function(f){ f.style.display = 'none'; });
          document.querySelectorAll('.boat-delete-form').forEach(function(f){ f.style.display = 'none'; });
          form.style.display = 'block';
          form.scrollIntoView({behavior:'smooth',block:'center'});
        });
      });
      document.querySelectorAll('.boat-delete-cancel').forEach(function(btn){
        btn.addEventListener('click', function(){
          var wrapper = this.closest('.boat-delete-form');
          if (wrapper) wrapper.style.display = 'none';
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
          // hide Edit/Delete buttons for the same list item to reduce confusion
          var li = form.closest('li');
          if (li) {
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
</body>
</html>
