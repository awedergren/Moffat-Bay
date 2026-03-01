<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 18, 2026
Project: Moffat Bay Marina Project
File: lookup_reservation.php
Purpose: Lookup reservation page and wait list status.
Non-executing header only; does not affect page behavior or layout.
*/
// Lookup Reservation Page
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/db.php';

$error = '';
$result = null;

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $resid = trim($_POST['reservation_id'] ?? '');

    if ($email === '' && $resid === '') {
        $error = 'Please enter an email address or reservation ID to search.';
    } else {
        if (!empty($resid)) {
            // Try lookup by reservation ID first
            if (isset($conn) && $stmt = $conn->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1')) {
                $stmt->bind_param('i', $resid);
                $stmt->execute();
                $res = $stmt->get_result();
                $result = $res->fetch_assoc();
                $stmt->close();
            }
        }

        if (empty($result) && !empty($email)) {
            // Lookup by email
            if (isset($conn) && $stmt = $conn->prepare('SELECT * FROM reservations WHERE email = ? LIMIT 1')) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $result = $res->fetch_assoc();
                $stmt->close();
            }
        }

        if ($result === null || $result === false) {
            $error = "We're sorry, no reservation was found.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Wait List Look Up</title>
    <link rel="stylesheet" href="lookup_reservation.css">
</head>
<body>
<main class="container">
    <h2 class="center">Wait List Look Up</h2>

    <section class="lookup">
        <form method="post" novalidate>
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" value="<?= h($_POST['email'] ?? '') ?>">

            <div class="or">OR</div>

            <label for="reservation_id">Reservation ID</label>
            <input id="reservation_id" name="reservation_id" type="text" value="<?= h($_POST['reservation_id'] ?? '') ?>">

            <button type="submit" class="btn">Search</button>
        </form>
    </section>

    <?php if ($error): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($result) && is_array($result)): ?>
        <section class="results">
            <h3>Search Results and Wait List Status</h3>
            <p><strong>Name:</strong> <?= h($result['name'] ?? $result['customer_name'] ?? '') ?></p>
            <p><strong>Boat Length:</strong> <?= h($result['boat_length'] ?? '') ?></p>
            <p><strong>Requested Date:</strong> <?= h($result['requested_date'] ?? $result['date'] ?? '') ?></p>
            <p><strong>Wait List Position:</strong> <?= h($result['waitlist_position'] ?? $result['position'] ?? '') ?></p>
            <p><strong>Status:</strong> <?= h($result['status'] ?? '') ?></p>
            <p><strong>Reservation ID:</strong> <?= h($result['id'] ?? $result['reservation_id'] ?? '') ?></p>
            <p><strong>Email:</strong> <?= h($result['email'] ?? '') ?></p>
        </section>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
