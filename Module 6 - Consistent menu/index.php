<!DOCTYPE html>
<?php
session_start();
require_once __DIR__ . '/db.php';
$loggedIn = isset($_SESSION['username']);
?>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Moffat Bay Island Marina</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- IMPORTANT: root-relative stylesheet -->
    <link rel="stylesheet" href="/styles.css?v=1">
</head>
<body>

<!-- ================= HEADER ================= -->
<header class="topbar">
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

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-overlay"></div>

  <div class="hero-content">
    <h1>Welcome to Moffat Bay</h1>
    <p>Your Pacific Northwest Island Retreat Awaits</p>

    <div class="hero-actions">
      <a class="btn btn-primary" href="#">Reserve Your Slip</a>
      <a class="btn btn-outline" href="#">Check Reservation</a>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section class="features">
  <div class="container feature-grid">

    <div class="feature-card">
      <div class="feature-icon">⚓</div>
      <h3>Boat Slips</h3>
      <p>26ft, 40ft &amp; 50ft slips available</p>
    </div>

    <div class="feature-card">
      <div class="feature-icon">🍴</div>
      <h3>Restaurant</h3>
      <p>Waterfront dining experience</p>
    </div>

    <div class="feature-card">
      <div class="feature-icon">⛽</div>
      <h3>Fuel Dock</h3>
      <p>Convenient refueling services</p>
    </div>

    <div class="feature-card">
      <div class="feature-icon">📅</div>
      <h3>Easy Booking</h3>
      <p>Monthly reservations online</p>
    </div>

  </div>
</section>

<!-- ================= CTA ================= -->
<section class="cta">
  <h2>Experience the San Juan Islands</h2>
  <p>Discover why boaters choose Moffat Bay for their island adventures</p>
  <a class="btn btn-secondary" href="#">Learn More About Us</a>
</section>

<!-- ================= HELP ================= -->
<section class="help">
  <h3>Questions? We're Here to Help</h3>
  <p>Our team is ready to assist with your reservation needs</p>
  <a class="btn btn-outline-dark contact-outline-index" href="#">Contact Us</a>
</section>

<!-- ================= FOOTER ================= -->
<footer class="site-footer">
  <p>&copy; 2026 Moffat Bay Island Marina</p>
</footer>

</body>
</html>
</body>
</html>
