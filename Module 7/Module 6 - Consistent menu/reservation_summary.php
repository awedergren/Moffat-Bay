<?php
/**
 * Nardos Gebremedhin 
 * 02/12/26
 * Moffay Bay:Reservation Summary Page
 */
session_start();

// Check login state
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);

// Load database connection
$dbPaths = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php'
];
$dbIncluded = false;
foreach ($dbPaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        $dbIncluded = true;
        break;
    }
}

// Redirect if not logged in
if (!$loggedIn) {
    $current = $_SERVER['REQUEST_URI'] ?? '/reservation_summary.php';
    header("Location: BlueTeam_LoginPage.php?error=" . urlencode("Please log in to view your reservations.") . "&redirect=" . urlencode($current));
    exit;
}

// Fetch reservations for logged-in user
$userId = $_SESSION['user_id'] ?? null;

$reservations = [];
if ($userId && $dbIncluded) {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE user_id = ? ORDER BY start_date DESC");
    $stmt->execute([$userId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservation Summary - Moffat Bay</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="reservation_style.css">
</head>
<body>
<?php include 'nav.php'; ?>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-inner">
        <h1>Your Reservations</h1>
        <p>Review and manage your boat slip bookings at Moffat Bay Marina</p>
    </div>
</section>

<div class="content">
    <div class="notice-wrap">
        <?php if (empty($reservations)): ?>
            <div class="notice">You currently have no reservations.</div>
        <?php endif; ?>
    </div>

    <div class="card-wrap">
        <div class="card-grid">
            <?php foreach($reservations as $res): ?>
                <div class="card-left">
                    <h3>Confirmation #<?= htmlspecialchars($res['confirmation_number']) ?></h3>
                    <p><strong>Boat Slip:</strong> <?= htmlspecialchars($res['slip_ID']) ?></p>
                    <p><strong>Start Date:</strong> <?= htmlspecialchars($res['start_date']) ?></p>
                    <p><strong>End Date:</strong> <?= htmlspecialchars($res['end_date']) ?></p>
                    <p><strong>Status:</strong> <?= htmlspecialchars($res['reservation_status']) ?></p>
                    <?php if (!empty($res['id'])): ?>
                        <a href="edit_reservation.php?id=<?= intval($res['id']) ?>" class="btn ghost">Edit</a>
                        <a href="cancel_reservation.php?id=<?= intval($res['id']) ?>" class="btn ghost">Cancel</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<footer>
    <div class="container" style="max-width:var(--max-width);">
        <small>&copy; 2026 Moffat Bay Island Marina — All rights reserved.</small>
    </div>
</footer>
</body>
</html>
