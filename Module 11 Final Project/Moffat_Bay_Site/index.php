<!DOCTYPE html>
<!--
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: index.php
Purpose: Landing page for Moffat Bay Island Marina.
Documentation-only comment; does not affect layout or behavior.
-->
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

  <!-- IMPORTANT: use project-relative stylesheet (avoid leading slash) -->
    <link rel="stylesheet" href="styles.css?v=1">
</head>
<body>

<?php include 'nav.php'; ?>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-overlay"></div>

  <div class="hero-content">
    <h1>Welcome to Moffat Bay</h1>
    <p>Your Pacific Northwest Island Retreat Awaits</p>

    <div class="hero-actions">
      <a class="btn btn-primary" href="reservation_info.php">Reserve Your Slip</a>
      <a class="btn btn-outline" href="look_up.php">Check Reservation</a>
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
  <a class="btn btn-secondary" href="about.php">Learn More About Us</a>
</section>

<!-- ================= HELP ================= -->
<section class="help">
  <h3>Questions? We're Here to Help</h3>
  <p>Our team is ready to assist with your reservation needs</p>
  <a class="btn btn-outline-dark contact-outline-index" href="about.php#contact">Contact Us</a>
</section>

<!-- ================= FOOTER ACCENT ================= -->
<div class="footer-accent-wrap" aria-hidden="true">
  <img src="SalishSalmonCoralAll.png" alt="Salish Salmon Coral seal" class="footer-accent-img" width="120" height="60" loading="lazy">
</div>

<!-- ================= FOOTER ================= -->
<footer class="site-footer">
  <p>&copy; 2026 Moffat Bay Island Marina</p>
</footer>

<style>
  /* Page-scoped footer image styling to keep the layout cohesive */
  .footer-accent-wrap { display:flex; justify-content:center; align-items:center; padding:12px 0 28px; }
  .site-footer { display:flex;flex-direction:column;align-items:center;gap:8px;padding:18px 0 }
  .footer-accent-img{height:140px;width:auto;opacity:0.95;display:block}
  @media(max-width:900px){ .footer-accent-img{height:110px} }
  @media(max-width:420px){ .footer-accent-img{height:80px} }
</style>

</body>
</html>
