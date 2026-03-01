<?php
// Shared navigation partial — include this at top of pages to keep header consistent
if (session_status() === PHP_SESSION_NONE) session_start();
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);
?>
<header class="topbar">
  <div class="container">
    <div class="logo">
      <a href="index.php" class="logo-link" aria-label="Moffat Bay landing page">
        <img id="siteLogo" src="logo.png" alt="Moffat Bay logo">
      </a>
    </div>
    <nav>
      <div class="nav-left">
        <a href="about.php">About Us</a>
        <a href="slip_reservation.php">Reservations</a>
        <a href="reservation_summary.php">Reservation/Waitlist Lookup</a>
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
