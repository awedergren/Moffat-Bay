<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: nav.php
Purpose: Shared navigation partial included on all pages.
Header is a non-executing comment only.
*/
// Shared navigation partial — include this at top of pages to keep header consistent
if (session_status() === PHP_SESSION_NONE) session_start();
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);
?>
<header class="topbar">
  <style>
    /* Authoritative nav styles to ensure identical menu across all pages */
    :root{box-sizing:border-box}
    header.topbar{background:var(--boat-white) !important;border-bottom:1px solid var(--gray) !important;padding:0 !important;min-height:68px;display:block;box-sizing:border-box;position:relative !important;top:0 !important;left:0 !important;width:100% !important;z-index:1000}
    header.topbar{margin:0 !important}
    header.topbar .container{max-width:var(--max-width);margin:0 auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:68px}
    .logo-link{display:inline-flex;align-items:center;height:68px}
    .logo-link img{height:56px;width:56px;object-fit:cover;border-radius:50% !important;display:block}
    /* layout: logo (left) - links (center) - actions (right) */
    /* make the link group truly centered and actions pinned right */
    nav{display:block;position:relative;height:100%;width:100%}
    /* position link group to the right, close to action buttons */
    nav .nav-left{position:absolute;right:120px;top:50%;transform:translateY(-50%);display:flex;gap:8px;align-items:center;height:auto;white-space:nowrap}
    nav .nav-right{position:absolute;right:20px;top:50%;transform:translateY(-50%);display:flex;gap:6px;align-items:center;height:auto}
    nav .nav-left a, nav .nav-right a{color:#1F2F45;text-decoration:none;padding:4px 6px;border-radius:8px;font-weight:400;font-size:15px;line-height:1;display:inline-flex;align-items:center;height:100% !important}
    nav .nav-left a:hover, nav .nav-right a:hover{background:rgba(31,47,69,0.03)}
    /* Match BlueTeam .btn and .btn.ghost appearance exactly */
    nav .nav-right a.btn, nav .nav-left a.btn{font-weight:700;border-radius:8px;padding:10px 16px;cursor:pointer;border:1px solid transparent}
    /* logout button: white fill with ocean outline, rounded and prominent */
    nav .nav-right a.btn.ghost, nav .nav-left a.btn.ghost{background:#ffffff;color:var(--ocean);border:1px solid var(--ocean);padding:10px 16px}
    nav .nav-right a.btn.ghost:hover{background:rgba(63,135,166,0.03)}
    nav .nav-right a.btn.ghost:focus{outline:none;box-shadow:0 4px 14px rgba(63,135,166,0.08);border-color:var(--ocean) !important}
    /* Keep layout stable across pages by preventing per-page overrides from shifting spacing */
    header.topbar *{box-sizing:border-box !important}
  </style>
  <div class="container">
    <div class="logo">
      <a href="index.php" class="logo-link" aria-label="Moffat Bay landing page">
        <img id="siteLogo" src="logo.png" alt="Moffat Bay logo">
      </a>
    </div>
    <nav>
      <div class="nav-left">
        <a href="about.php">About Us</a>
        <?php if ($loggedIn): ?>
          <a href="slip_reservation.php">Reservations</a>
        <?php else: ?>
          <a href="reservation_info.php">Reservations</a>
        <?php endif; ?>
        <a href="reservation_summary.php">Reservation/Waitlist Lookup</a>
        <?php if ($loggedIn): ?>
          <a href="MyAccount.php">My Account</a>
        <?php endif; ?>
      </div>
      <div class="nav-right">
        <?php if ($loggedIn): ?>
          <a href="logout.php" class="btn ghost">Log out</a>
        <?php else: ?>
          <a href="BlueTeam_LoginPage.php">Login/Register</a>
        <?php endif; ?>
      </div>
    </nav>
  </div>
</header>
