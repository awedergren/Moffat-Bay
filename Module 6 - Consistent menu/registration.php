<?php

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

// Password match check
    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {

// 🔐 Hash password
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

    $success = "Account created successfully! Redirecting to login...";
    header("Refresh: 2; URL=BlueTeam_LoginPage.php");

} catch (PDOException $e) {
    $error = "That email is already registered.";
  }
}
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Registration</title>
    <link rel="stylesheet" href="styles_registration.css?v=1">
</head>
<body>

<header class="topbar site-header">
    <div class="container">
      <div class="logo">
        <!-- Logo only in top-left; link to landing page to be set later -->
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

<header class="page-header">
  <h1>Create Your Account</h1>
  <p class="subtitle">
    Create an account to start making reservations.
  </p>
</header>

<main class="registration-layout">

<!-- LEFT COLUMN -->
  <section class="form-card">
    <h3>Register for an Account</h3>

    <form method="POST" class="registration-form">

      <div class="form-group">
        <label>First Name</label>
        <input type="text" name="first_name" required>
      </div>

      <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="last_name" required>
      </div>

      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email">
      </div>

      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" id="reg_phone" name="phone" inputmode="tel" placeholder="e.g. 555-555-5555">
      </div>

      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password">
      </div>

      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="confirm_password">
      </div>

      <div class="form-group checkbox">
        <input type="checkbox" required>
        <span>I agree to the <a href="#">Terms & Conditions</a></span>
      </div>

      <button type="submit" class="btn-primary">
        Create Account
      </button>

    </form>
  </section>

<!-- RIGHT COLUMN -->
  <aside class="info-column">

    <div class="info-box">
      <h3>Already have an account?</h3>
      <a href="BlueTeam_LoginPage.php" class="btn-secondary">Login to your account</a>
    </div>

    <div class="info-box">
      <h3>Account Benefits</h3>
      <ul>
        <li>Make reservations online quick and easy!</li>
        <li>View reservation history to refernce past bookings.</li>
        <li>Join the waitlist to be notified of available spots as soon as they become available.</li>
      </ul>
    </div>

  </aside>

</main>

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
</script>

</body>
