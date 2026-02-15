<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: registration.php
Purpose: User registration page and account creation flow.
Non-executing header only; does not affect page behavior or layout.
*/

/**
 * Jonah Aney 02/04/26 
 * Moffay Bay: Registration Page
 */

session_start();
// Load DB from sensible locations (works both in root and older exports)
$dbPaths = [
  __DIR__ . '/db.php',
  __DIR__ . '/config/db.php',
  __DIR__ . '/../db.php'
];
foreach ($dbPaths as $dbPath) {
  if (file_exists($dbPath)) {
    require_once $dbPath;
    break;
  }
}

$loggedIn = isset($_SESSION['username']);

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $firstName = trim($_POST['first_name']);
    $lastName  = trim($_POST['last_name']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone']);
    $password  = $_POST['password'];
    $confirm   = $_POST['confirm_password'];

    // normalize phone for storage as XXX-XXX-XXXX when possible
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if (strlen($digits) === 10) {
      $phone_db = sprintf('%s-%s-%s', substr($digits,0,3), substr($digits,3,3), substr($digits,6,4));
    } else {
      $phone_db = $phone; // store as-entered when we can't normalize
    }

  // Server-side password validations
  if ($password !== $confirm) {
    $error = "Passwords do not match.";
  } else {
    $pw = $password;
    $pwLower = strtolower($pw);
    $emailLower = strtolower(trim($email));
    $valid = true;
    if (strlen($pw) < 8) { $valid = false; $error = "Password must be at least 8 characters."; }
    if ($valid && !preg_match('/[A-Z]/', $pw)) { $valid = false; $error = "Password must contain at least one uppercase letter."; }
    if ($valid && !preg_match('/[a-z]/', $pw)) { $valid = false; $error = "Password must contain at least one lowercase letter."; }
    if ($valid && !preg_match('/[0-9]/', $pw)) { $valid = false; $error = "Password must contain at least one number."; }
    if ($valid && !preg_match('/[^A-Za-z0-9]/', $pw)) { $valid = false; $error = "Password must contain at least one special character."; }
    if ($valid && $emailLower !== '' && $pwLower === $emailLower) { $valid = false; $error = "Password cannot be your email address."; }

    if ($valid) {
      // 🔐 Hash password and create user
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
      try {
        $sql = "INSERT INTO users 
            (email, password, first_name, last_name, phone)
            VALUES (:email, :password, :first_name, :last_name, :phone)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':email'      => $email,
          ':password'   => $hashedPassword,
          ':first_name' => $firstName,
          ':last_name'  => $lastName,
          ':phone'      => $phone_db
        ]);

        // Auto-login the new user
        $_SESSION['user_id'] = $pdo->lastInsertId() ?: null;
        $_SESSION['username'] = $email;

        // Redirect to reservation page
        header('Location: slip_reservation.php');
        exit;

      } catch (PDOException $e) {
        // duplicate entry or other DB error
        $error = "That email is already registered.";
      }
    }
  }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Registration</title>
    <link rel="stylesheet" href="styles_registration.css?v=1">
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
      nav .nav-left{display:flex;gap:18px}
      nav .nav-right{margin-left:auto;display:flex;gap:18px;align-items:center}

      /* Page wrapper and hero-like spacing */
      .card-wrap{width:100%;margin:12px 0 60px;padding:26px;background:transparent;border-radius:8px;position:relative;z-index:1}
      .card-grid{display:flex;gap:24px;align-items:flex-start}
      .form-card{flex:1;background:#fff;border-radius:10px;padding:22px;border:1px solid var(--gray);box-shadow:0 8px 30px rgba(31,47,69,0.06)}
      .info-column{width:360px}

      /* Register card and benefits (right column) */
      .register-panel{background:#fff;border-radius:10px;padding:18px;border:2px solid rgba(63,135,166,0.15);box-shadow:0 10px 30px rgba(31,47,69,0.06)}
      .register-panel .top{display:flex;align-items:center;justify-content:center;padding:18px 0;border-bottom:1px solid var(--gray);}
      .register-panel h3{margin:0;font-size:18px}
      .benefits{
        background:var(--cream);
        padding:18px 20px;
        border-radius:10px;
        margin-top:18px;
        border:1px solid rgba(0,0,0,0.06);
        font-size:14px;
        line-height:1.5;
        box-shadow:0 6px 18px rgba(31,47,69,0.04);
      }
      .benefits ul{margin:6px 0 0 16px;padding:0;list-style-position:outside}
      .benefits li{margin-bottom:6px;font-size:13px}

      /* Form elements (use shared classes) */
      label.small{display:block;font-size:13px;color:#556170;margin-bottom:6px}
      .input{display:block;width:100%;padding:12px 14px;border-radius:8px;border:1px solid var(--gray);margin-bottom:12px;background:#fff}
      .small{font-size:14px;color:#556170}
      .muted{font-size:13px;color:#6b7280}

      /* Buttons */
      .btn{font-weight:700;border-radius:8px;padding:10px 16px;cursor:pointer;border:1px solid transparent}
      .btn.create{background:var(--gold);color:var(--navy);border-color:var(--gold);padding:12px 18px}
      .btn.ghost{background:transparent;color:var(--ocean);border:1px solid var(--ocean)}
      .btn.login{background:var(--ocean);color:var(--boat-white);border-color:var(--ocean);padding:12px 18px}

      /* Checkbox / consent row */
      .form-group.checkbox label{display:flex;align-items:center;gap:12px;color:#556170}
      .form-group.checkbox input[type="checkbox"]{width:18px;height:18px;accent-color:var(--ocean);border-radius:4px;box-shadow:0 1px 2px rgba(31,47,69,0.04);margin:0}
      .form-group.checkbox .checkbox-label{font-size:14px;color:#556170}
      .form-group.checkbox a{color:var(--ocean);text-decoration:underline}

      /* Password requirements help box */
      .pw-help{display:none;background:#fff;border-radius:8px;padding:12px;border:1px solid var(--gray);box-shadow:0 6px 18px rgba(31,47,69,0.04);margin-bottom:12px}
      .pw-help .pw-req{font-size:13px;color:#6b7280;margin:6px 0}
      .pw-help .pw-req[data-valid="true"]{color:var(--pine);font-weight:700}
      .pw-help strong{display:block;margin-bottom:8px}

      @media (max-width:900px){
        .card-grid{flex-direction:column}
        .info-column{width:100%}
        .logo-link img{height:44px;width:44px}
      }
    </style>
</head>
<body>

<?php
  // Use the shared navigation to keep the top menu identical across pages
  include 'nav.php';
?>

  <?php
  // Page-specific hero values — placed directly under the menu per request
  $hero_title = 'Create Your Account';
  $hero_subtitle = 'Create an account to start making reservations.';
  $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round-plus-icon lucide-user-round-plus"><path d="M2 21a8 8 0 0 1 13.292-6"/><circle cx="10" cy="8" r="5"/><path d="M19 16v6"/><path d="M22 19h-6"/></svg>';
  $hero_classes = 'hero-registration';
  include 'hero.php';

  ?>
  <style>
    /* Page-local: align notice and card grid to BlueTeam_LoginPage spacing */
    /* Container that centers both notice and grid identically */
    .content { width:100%; max-width:1000px; margin:0 auto; padding:0; box-sizing:border-box; }

    /* Make wrapper elements full-width but center their children using the .content container */
    .notice-wrap, .card-wrap { width:100%; padding:0; box-sizing:border-box; display:flex; justify-content:center }

    /* Ensure the visible boxes use the same outer width and centering */
    .notice, .card-grid { width:100%; max-width:1000px; margin:0; box-sizing:border-box }

    /* Keep explicit column widths so their sum + gap == 1000px */
    .card-grid { display:flex; gap:24px }
    .form-card { width:566px !important; flex:0 0 566px !important }
    .info-column { width:410px !important; flex:0 0 410px !important }

    /* Slight gap between notice and cards */
     /* Ensure notice sits above hero and is visible */
     .notice-wrap { margin-top: -36px !important; margin-bottom:12px !important; position:relative; z-index:99999 }
    .notice { display:block !important; visibility:visible !important; opacity:1 !important; position:relative; z-index:100000; background:var(--pine); color:var(--boat-white); padding:18px 28px; border-radius:12px; box-shadow:0 8px 28px rgba(31,47,69,0.12); text-align:center; }
     /* Remove page-level registration layout padding so the inner .card-grid centers
       inside the .content container (overrides styles_registration.css) */
     .registration-layout { padding: 0 !important; max-width: 100% !important; box-sizing: border-box !important; }
     .card-wrap { margin-top:12px !important }
    /* Force-notice visible if other CSS creates stacking contexts */
    .notice { display:block !important; z-index:100000; position:relative }
    @media (max-width:900px){ .notice-wrap{margin-top:18px !important} .card-grid{max-width:100%;gap:16px} .form-card,.info-column{flex:0 0 100% !important;width:100% !important} }
  </style>

  <div class="content">
  <div class="notice-wrap">
    <div class="notice">
    <p>Registration is required to reserve boat slips, manage your bookings, and join waitlists at Moffat Bay.</p></div>
  </div>


  <main class="registration-layout">

    <div class="card-wrap">
    <div class="card-grid">

<!-- LEFT COLUMN -->
  <section class="form-card">
    <h3>Register for an Account</h3>

    <form method="POST" class="registration-form">

      <div class="form-group">
        <label class="small">First Name</label>
        <input class="input" type="text" name="first_name" required placeholder="Jane">
      </div>

      <div class="form-group">
        <label class="small">Last Name</label>
        <input class="input" type="text" name="last_name" required placeholder="Doe">
      </div>

      <div class="form-group">
        <label class="small">Email Address</label>
        <input class="input" type="email" name="email" placeholder="jane.doe@example.com">
      </div>

      <div class="form-group">
        <label class="small">Phone Number</label>
        <input class="input" type="tel" id="reg_phone" name="phone" inputmode="tel" placeholder="e.g. 555-555-5555">
      </div>

      <div class="form-group">
        <label class="small">Password</label>
        <input id="newPassword" class="input" type="password" name="password" placeholder="Enter a password">
        <div id="pwHelp" class="pw-help" aria-live="polite">
          <strong>Password requirements</strong>
          <div class="pw-req" data-valid="false">Minimum of 8 characters</div>
          <div class="pw-req" data-valid="false">1 upper case</div>
          <div class="pw-req" data-valid="false">1 lower case</div>
          <div class="pw-req" data-valid="false">1 number</div>
          <div class="pw-req" data-valid="false">1 special character</div>
          <div class="pw-req" data-valid="false">Cannot be your email address</div>
        </div>
      </div>

      <div class="form-group">
        <label class="small">Confirm Password</label>
        <input id="confirmPassword" class="input" type="password" name="confirm_password" placeholder="Confirm your password">
      </div>

      <div class="form-group checkbox small">
        <label>
          <input type="checkbox" required>
          <span class="checkbox-label">I agree to the <a href="#">Terms & Conditions</a></span>
        </label>
      </div>

      <button type="submit" class="btn create">
        Create Account
      </button>

    </form>
  </section>

<!-- RIGHT COLUMN -->
  <aside class="info-column">

    <div class="register-panel">
      <div class="top">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--cream);display:flex;align-items:center;justify-content:center;color:var(--navy);font-weight:700;margin-right:10px">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-user-round-icon lucide-user-round">
            <circle cx="12" cy="8" r="5"/>
            <path d="M20 21a8 8 0 0 0-16 0"/>
          </svg>
        </div>
      </div>
      <div style="padding:18px;text-align:center">
        <h3 style="margin-top:0;color:var(--navy)">Already have an account?</h3>
        <div class="muted" style="margin-top:8px">Login to your account to manage reservations and boats.</div>
        <div style="margin-top:16px">
          <a href="BlueTeam_LoginPage.php" class="btn login" style="width:100%;display:inline-block;text-align:center">Login to your account</a>
        </div>
      </div>
    </div>

    <div class="benefits" style="margin-top:-2px !important">
      <strong>Account Benefits</strong>
      <ul class="small" style="margin-top:8px">
        <li>Make reservations online quick and easy!</li>
        <li>View reservation history to reference past bookings.</li>
        <li>Join the waitlist to be notified of available spots as soon as they become available.</li>
      </ul>
    </div>

  </aside>
    </div>
    </div>

  </main>
  </div>

<script>
// Auto-format phone input as XXX-XXX-XXXX while typing
(function(){
  var el = document.getElementById('reg_phone');
  if (!el) return;
  el.addEventListener('input', function(e){
    var v = this.value.replace(/\D/g,'').slice(0,10);
    if (v.length > 6) this.value = v.slice(0,3) + '-' + v.slice(3,6) + '-' + v.slice(6);
    else if (v.length > 3) this.value = v.slice(0,3) + '-' + v.slice(3);
    else this.value = v;
  });
  // optional: format on blur to ensure stored format
  el.addEventListener('blur', function(){
    var v = this.value.replace(/\D/g,'');
    if (v.length === 11 && v[0] === '1') v = v.slice(1);
    if (v.length === 10) this.value = v.slice(0,3) + '-' + v.slice(3,6) + '-' + v.slice(6);
  });
})();
// Password requirements UI and client-side validation
(function(){
  var pw = document.getElementById('newPassword');
  var confirm = document.getElementById('confirmPassword');
  var email = document.querySelector('input[name="email"]');
  var help = document.getElementById('pwHelp');
  if (!pw || !help) return;
  var reqEls = help.querySelectorAll('.pw-req');

  function setReq(el, ok){
    el.dataset.valid = ok ? 'true' : 'false';
    el.style.color = ok ? 'var(--pine)' : '#6b7280';
    el.style.fontWeight = ok ? '700' : '400';
  }

  function validatePassword(value){
    const results = {
      length: value.length >= 8,
      upper: /[A-Z]/.test(value),
      lower: /[a-z]/.test(value),
      number: /[0-9]/.test(value),
      special: /[!@#\$%\^&\*\(\)\-_=+\[\]{};:'"\\|,.<>\/\?`~]/.test(value),
      notEmail: true
    };
    const emailVal = (email && email.value) ? email.value.trim().toLowerCase() : '';
    if (emailVal && value.trim().toLowerCase() === emailVal) results.notEmail = false;
    return results;
  }

  function updateReqs(){
    const val = pw.value || '';
    const r = validatePassword(val);
    setReq(reqEls[0], r.length);
    setReq(reqEls[1], r.upper);
    setReq(reqEls[2], r.lower);
    setReq(reqEls[3], r.number);
    setReq(reqEls[4], r.special);
    setReq(reqEls[5], r.notEmail);
  }

  pw.addEventListener('focus', function(){
    help.style.display = 'block';
    updateReqs();
  });
  pw.addEventListener('input', updateReqs);
  pw.addEventListener('blur', function(){
    // slight delay so clicking confirm doesn't immediately hide
    setTimeout(function(){ if (document.activeElement !== confirm) help.style.display = 'none'; }, 150);
  });
  confirm && confirm.addEventListener('focus', function(){ help.style.display = 'block'; updateReqs(); });

  // form submit validation
  var form = document.querySelector('.registration-form');
  form && form.addEventListener('submit', function(e){
    const val = pw.value || '';
    const r = validatePassword(val);
    const allValid = r.length && r.upper && r.lower && r.number && r.special && r.notEmail;
    if (!allValid){
      e.preventDefault();
      help.style.display = 'block';
      updateReqs();
      pw.focus();
      alert('Password does not meet the requirements.');
    }
    else if (val !== (confirm && confirm.value || '')){
      e.preventDefault();
      alert('Passwords do not match.');
      confirm && confirm.focus();
    }
  });
})();
</script>

<?php include 'footer.php'; ?>

</body>
