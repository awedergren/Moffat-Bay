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
        ':phone'      => $phone
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
            <a href="account.php">My Account</a>
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
        <input type="text" name="phone">
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

</body>
