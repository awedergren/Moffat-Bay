<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 18, 2026
Project: Moffat Bay Marina Project
File: waitlist_lookup.php
Purpose: Lookup page for waitlist entries. This page ONLY queries the waitlist (and users/boats as needed).
*/
session_start();

// login helper (not used for reservations on this page)
$loggedIn = isset($_SESSION['username']) || isset($_SESSION['user_id']);

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$searchErr = '';
$waitlistEntries = [];

// load DB (try a few common paths)
$pdo = null;
$dbPaths = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php'
];
foreach ($dbPaths as $dbPath) {
    if (file_exists($dbPath)) {
        require_once $dbPath;
        if (isset($pdo)) break;
        if (isset($conn)) { $pdo = $conn; break; }
    }
}
$pdo = $pdo ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX cancel requests first
    if (isset($_POST['do']) && $_POST['do'] === 'cancel_waitlist') {
        header('Content-Type: application/json');
        $wlId = intval($_POST['waitlist_id'] ?? 0);
        if ($wlId <= 0 || !$pdo) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            exit;
        }
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        try {
            $s = $pdo->prepare('SELECT * FROM waitlist WHERE waitlist_ID = ? LIMIT 1');
            $s->execute([$wlId]);
            $entry = $s->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                echo json_encode(['success' => false, 'error' => 'Entry not found']);
                exit;
            }
            if (intval($entry['user_ID'] ?? 0) !== intval($userId)) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            // Recalculate positions transactionally:
            // - remember the current position
            // - set the canceled entry position to 0
            // - decrement positions greater than the canceled position
            $oldPos = intval($entry['position_in_queue'] ?? 0);
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare('UPDATE waitlist SET position_in_queue = 0 WHERE waitlist_ID = ?');
                $u->execute([$wlId]);
                if ($oldPos > 0) {
                    $shift = $pdo->prepare('UPDATE waitlist SET position_in_queue = position_in_queue - 1 WHERE position_in_queue > ?');
                    $shift->execute([$oldPos]);
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'waitlist_id' => $wlId]);
                exit;
                } catch (Exception $ex2) {
                try { $pdo->rollBack(); } catch (Exception $_) {}
                echo json_encode(['success' => false, 'error' => 'Database error during cancel']);
                exit;
            }
        } catch (Exception $ex) {
            echo json_encode(['success' => false, 'error' => 'Database error']);
            exit;
        }
    }

    $searchBy = $_POST['search_by'] ?? 'email';
    $query = trim($_POST['query'] ?? '');

    if ($query === '') {
        $searchErr = 'Please enter an email address.';
    } elseif (!$pdo) {
        $searchErr = 'Database connection not available. Please contact the administrator.';
    } else {
        try {
            if ($searchBy === 'waitlist') {
                $wlId = intval($query);
                if ($wlId <= 0) {
                    $searchErr = 'Please enter a valid waitlist ID.';
                } else {
                    $wlSql = 'SELECT w.*, u.email, b.boat_name, b.boat_length
                              FROM waitlist w
                              LEFT JOIN users u ON w.user_ID = u.user_ID
                              LEFT JOIN boats b ON w.boat_ID = b.boat_ID
                              WHERE w.waitlist_ID = ? LIMIT 1';
                    $wlStmt = $pdo->prepare($wlSql);
                    $wlStmt->execute([$wlId]);
                    $wlRow = $wlStmt->fetch(PDO::FETCH_ASSOC);
                    if ($wlRow) {
                        $userId = $wlRow['user_ID'] ?? null;
                        if ($userId) {
                            $allStmt = $pdo->prepare('SELECT w.*, b.boat_name, b.boat_length FROM waitlist w LEFT JOIN boats b ON w.boat_ID = b.boat_ID WHERE w.user_ID = ? AND w.position_in_queue > 0 ORDER BY w.position_in_queue ASC');
                            $allStmt->execute([$userId]);
                            $waitlistEntries = $allStmt->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            $waitlistEntries = [$wlRow];
                        }
                    } else {
                        $searchErr = 'No waitlist entry found matching that ID.';
                    }
                }
            } else {
                // email lookup -> get user then waitlist entries
                $uStmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
                $uStmt->execute([$query]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $userId = $uRow['user_ID'];
                    $waitlistStmt = $pdo->prepare('SELECT w.*, b.boat_name, b.boat_length FROM waitlist w LEFT JOIN boats b ON w.boat_ID = b.boat_ID WHERE w.user_ID = ? AND w.position_in_queue > 0 ORDER BY w.position_in_queue ASC');
                    $waitlistStmt->execute([$userId]);
                    $waitlistEntries = $waitlistStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($waitlistEntries)) $searchErr = 'No waitlist entries found for that email.';
                } else {
                    $searchErr = 'No account found for that email address.';
                }
            }
        } catch (Exception $ex) {
            $searchErr = 'Database error during search: ' . e($ex->getMessage());
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Waitlist Lookup</title>

    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="reservation_style.css">
    <link rel="stylesheet" href="waitlist_lookup.css">

    <style>
    /* page-scoped styles kept minimal */
    /* Ensure footer stays at bottom on short pages */
    html, body { height: 100%; }
    body.reservation-info { display: flex; flex-direction: column; min-height: 100vh; }
    main { flex: 1 0 auto; }

    :root{ --page-max-width:1000px; }
    /* Form buttons */
    .form-lookup .cta, .form-lookup .btn{ background:var(--ocean); color:#fff; border-color:var(--ocean); }
    .form-lookup .cta:hover, .form-lookup .btn:hover{ background:#35738c; }

    /* Cards layout: stack vertically and center cards, limit width and make responsive */
    .cards{ display:flex; flex-direction:column; gap:28px; justify-content:center; align-items:center; margin:0 auto; }
    .cards .card{ box-sizing:border-box; width:100%; max-width:var(--page-max-width); padding:0 18px; margin:0 auto; }
    @media(min-width:900px){ .cards{ gap:32px; } .cards .card{ max-width:var(--page-max-width); } }

    /* Top lookup card styling to match reference */
    /* (removed ordering so DOM order controls stacking; form-card handles overlap) */
    .cards .card.lookup-card{ display:flex; gap:28px; align-items:center; border:3px solid rgba(63,135,166,0.95); padding:32px 36px; border-radius:18px; background:#fff; box-shadow:0 14px 30px rgba(31,47,69,0.08); }
    /* Main form card (overlapping hero) */
    .cards .card.form-card{ width:100%; max-width:var(--page-max-width); margin-top:-40px; position:relative; z-index:60; }
    .cards .card.form-card{ display:block; border-radius:14px; background:#fff; padding:32px 36px; box-shadow:0 14px 30px rgba(31,47,69,0.08); border:1px solid rgba(15,37,64,0.06); }
    .cards .card.form-card .input-text{ width:100%; padding:14px 16px; border-radius:8px; border:1px solid #e6eef0; box-shadow:none }
    .cards .card.form-card .cta{ display:inline-flex; align-items:center; justify-content:center; padding:12px 22px; border-radius:8px; max-width:360px; margin:18px auto 0; }

    /* Lookup card (smaller) appearance */
    .cards .card.lookup-card, .cards .lookup-card{ display:flex; gap:18px; align-items:center; border:3px solid rgba(63,135,166,0.9); padding:20px; border-radius:14px; background:#fff; box-shadow:0 10px 28px rgba(31,47,69,0.06); }
    .cards .card.lookup-card .icon, .cards .lookup-card .icon{ flex:0 0 56px; height:56px; border-radius:50%; background:var(--ocean); display:flex; align-items:center; justify-content:center; color:#fff }

    .btn-gold{ background:var(--gold); color:#0f2540; border-radius:12px; padding:12px 26px; font-weight:400; border:none; display:inline-block; text-decoration:none }

    /* Contact band: constrain and center within page */
    .contact-section{ padding:36px 0; margin-top:-4px }
    .container{ max-width:var(--page-max-width); margin:0 auto; padding:0; }
    .contact-band{ background:#f3e0bd; border-radius:12px; padding:28px; box-shadow:0 6px 18px rgba(31,47,69,0.06); display:flex; gap:20px; align-items:center; box-sizing:border-box; width:100%; max-width:var(--page-max-width); margin:0 auto; }
    .contact-cta{ display:flex; gap:14px; margin-top:18px; align-items:center }
    .btn-primary{ background:var(--ocean); color:#fff; padding:12px 22px; border-radius:10px; text-decoration:none }
    .btn-pill{ background:#fff; color:var(--ocean); padding:10px 18px; border-radius:10px; text-decoration:none; border:2px solid var(--ocean); box-shadow:none; font-weight:600 }
    @media (max-width:700px){ .contact-band{ flex-direction:column; align-items:flex-start } }

    /* Table */
    .waitlist-table{ width:100%; border-collapse:collapse; margin-top:12px }
    .waitlist-table th, .waitlist-table td{ text-align:left; padding:10px; border-bottom:1px solid #eee }
    /* Make the waitlist position more prominent */
    .waitlist-table .pos-cell{ width:140px; text-align:center; vertical-align:middle }
    /* Ensure the header for the position column is also centered so badge aligns under it */
    .waitlist-table th.pos-header{ text-align:center; }
    .waitlist-table .pos-badge{ display:inline-flex; align-items:center; justify-content:center; background:var(--ocean); color:#fff; font-weight:800; font-size:1.25rem; padding:10px 14px; border-radius:999px; min-width:48px; box-shadow:0 8px 22px rgba(15,30,60,0.12); }
    @media (max-width:700px){ .waitlist-table .pos-badge{ font-size:1rem; padding:8px 10px; min-width:40px } }
    /* Centered positions block shown below the table */
    .waitlist-positions{ display:flex; gap:18px; justify-content:center; margin-top:18px; align-items:center; flex-wrap:wrap }
    .waitlist-positions .pos-item{ display:flex; flex-direction:column; align-items:center; }
    .waitlist-positions .pos-label{ font-size:0.9rem; color:#0f2540; margin-bottom:8px; font-weight:700; text-transform:none }
    .waitlist-positions .pos-badge{ width:56px; height:56px; display:inline-flex; align-items:center; justify-content:center; background:var(--ocean); color:#fff; font-weight:800; font-size:1.25rem; border-radius:50%; box-shadow:0 8px 22px rgba(15,30,60,0.12); }
    @media (max-width:700px){ .waitlist-positions .pos-badge{ width:44px; height:44px; font-size:1.05rem } .waitlist-positions{ gap:12px } }
        /* Login prompt shown below the position badge (subtle, italic, gray) */
        .waitlist-login-msg{ text-align:center; font-style:italic; color:#7a7a7a; font-weight:300; margin-top:36px; margin-bottom:0px; font-size:1rem; line-height:1.35 }
    /* Contact modal styles (page-scoped) */
    .cb-overlay{ position:fixed; inset:0; background:rgba(0,0,0,0.45); display:none; align-items:center; justify-content:center; z-index:2147483646; }
    .cb-modal{ background:#fff; border-radius:10px; max-width:720px; width:94%; padding:20px; box-shadow:0 20px 50px rgba(15,30,60,0.25); }
    .cb-modal h3{ margin-top:0 }
    /* Generic coral-outline cancel button (inverted on hover) */
    .btn.btn-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    .btn.btn-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    /* Cancel modal button styles */
    .cb-modal .btn.btn-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    .cb-modal .btn.btn-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    .cb-modal .btn.btn-leave { background: var(--ocean); color: #fff; padding: 8px 14px; border-radius:8px; border: none; }
    .cb-modal .btn.btn-leave:hover { background: #1e6a82; }
    .cb-row{ display:flex; gap:12px; margin-bottom:12px; }
    .cb-row .cb-col{ flex:1 }
    .cb-row .cb-col.small{ flex:0 0 160px }
    .cb-input, .cb-textarea, .cb-select{ width:100%; padding:10px; border:1px solid #d0d7d9; border-radius:6px; box-sizing:border-box }
    .cb-textarea{ min-height:120px; resize:vertical }
    .cb-actions{ display:flex; gap:10px; justify-content:flex-end; margin-top:12px }
    .cb-success{ background:#d4edda; color:#155724; padding:10px; border-radius:6px; margin-bottom:10px }
    /* Remove default focus/outline on contact buttons and modal send button */
    .contact-cta .btn-primary, #contact-open, .cb-modal .btn-primary { outline: none; box-shadow: none; border: none; }
    .contact-cta .btn-primary:focus, #contact-open:focus, .cb-modal .btn-primary:focus { outline: none; box-shadow: none; }
    /* Make Contact Us button match phone button vertical size (no width change) */
    .contact-cta .btn-primary, .cb-modal .btn-primary { padding:16px 18px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; line-height:1; }
    /* Coral outline for modal cancel button */
    #contact-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    #contact-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    </style>
</head>
<body class="reservation-info">
    <?php include __DIR__ . '/nav.php'; ?>
    <main>
        <?php
        $hero_title = 'Waitlist Lookup';
        $hero_subtitle = 'Find your waitlist position using confirmation number or email.';
        $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock4-icon lucide-clock-4"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
        $hero_classes = 'hero-reservations';
        include __DIR__ . '/hero.php';
        ?>

        <div class="cards">
            <div class="card form-card">
                <form method="post" class="form-lookup" novalidate>
                    <div class="search-type">
                        <label><input type="radio" name="search_by" value="email" <?php if(!isset($_POST['search_by']) || $_POST['search_by']==='email') echo 'checked'; ?>> Email Address</label>
                    </div>

                    <div class="form-row">
                        <input type="text" name="query" placeholder="Enter email address" value="<?php echo e($_POST['query'] ?? ''); ?>" class="input-text">
                    </div>

                    <div class="form-row">
                        <button type="submit" class="cta">Check waitlist position</button>
                    </div>
                </form>

                <?php if ($searchErr): ?>
                    <div class="error-message"><?php echo e($searchErr); ?></div>
                <?php endif; ?>

                <?php if (!empty($waitlistEntries)): ?>
                    <div class="detail-section" id="waitlist-section">
                        <h3>Your Waitlist Entries</h3>
                        <table class="waitlist-table">
                            <thead>
                                <tr>
                                    <th>Boat</th>
                                    <th>Preferred Size</th>
                                    <th>Preferred Dates</th>
                                    <th>Duration</th>
                                        <th>Date Added</th>
                                        <th class="pos-header">Position</th>
                                        <th></th>
                                    </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waitlistEntries as $entry): ?>
                                <tr>
                                    <td><?php echo e($entry['boat_name'] ?? 'Any'); ?></td>
                                    <td><?php echo e($entry['preferred_slip_size'] ?? 'Any'); ?> ft</td>
                                    <td>
                                        <?php if (!empty($entry['preferred_start_date'])): ?>
                                            <?php
                                                $start = date('M d, Y', strtotime($entry['preferred_start_date']));
                                                $end = !empty($entry['preferred_end_date']) ? date('M d, Y', strtotime($entry['preferred_end_date'])) : 'TBD';
                                            ?>
                                            <?php echo e($start); ?> to <?php echo e($end); ?>
                                        <?php else: ?>
                                            Flexible
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e($entry['months_duration'] ?? 'N/A'); ?> months</td>
                                    <td><?php echo e(date('M d, Y', strtotime($entry['date_created']))); ?></td>
                                    <td class="pos-cell"><span class="pos-badge"><?php echo e($entry['position_in_queue']); ?></span></td>
                                    <td>
                                        <?php if (!empty($loggedIn) && intval($_SESSION['user_id'] ?? 0) === intval($entry['user_ID'] ?? 0)): ?>
                                            <a href="#" class="cancel-waitlist-link" data-id="<?php echo e($entry['waitlist_ID']); ?>" style="color:#d9534f;font-weight:700;">Cancel</a>
                                        <?php elseif (!empty($loggedIn)): ?>
                                            <span style="color:#6c757d;font-size:0.95rem;">Not your entry</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!empty($waitlistEntries) && empty($loggedIn)): ?>
                            <div class="waitlist-login-msg">Log in to modify or cancel this waitlist entry.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        
            <div class="card lookup-card">
                <div class="icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-search-icon lucide-calendar-search"><path d="M16 2v4"/><path d="M21 11.75V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7.25"/><path d="m22 22-1.875-1.875"/><path d="M3 10h18"/><path d="M8 2v4"/><circle cx="18" cy="18" r="3"/></svg>
                    </div>
                <div>
                    <h3>Looking for a reservation?</h3>
                    <p>Check your existing reservation status and details.</p>
                    <div style="margin-top:12px;"><a href="look_up.php" class="btn-gold">Search for Reservation</a></div>
                </div>
            </div>
        
        </div>

        <!-- Contact Us band (styled to match the provided screenshot) -->
        <section class="contact-section" aria-labelledby="contact-heading">
            <div class="container">
                <div class="contact-band" role="region" aria-labelledby="contact-heading">
                    <div class="contact-left">
                        <h2 id="contact-heading">Questions About the Waitlist?</h2>
                        <p>Our team can provide more information about estimated wait times, slip availability, and help you understand your position on the waitlist.</p>
                        <div class="contact-cta">
                            <button type="button" id="contact-open" class="btn-primary">Contact Us</button>
                            <a href="#" id="phone-open" class="btn-pill">Call (555) 987-2345</a>
                        </div>
                    </div>
                    <!-- right column intentionally removed to match design (email link omitted) -->
                </div>
            </div>
        </section>

        <!-- Contact modal markup -->
        <div class="cb-overlay" id="contact-overlay" aria-hidden="true">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="cb-title">
                <button type="button" id="contact-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="cb-title">Contact Moffat Bay Marina</h3>
                <div id="cb-msg" style="display:none"></div>
                <form id="contact-form">
                    <div class="cb-row">
                        <div class="cb-col"><input name="name" class="cb-input" placeholder="Your name" required></div>
                        <div class="cb-col small"><input name="phone" class="cb-input" placeholder="Phone (optional)"></div>
                    </div>
                    <div class="cb-row">
                        <div class="cb-col"><input name="email" type="email" class="cb-input" placeholder="Email address" required></div>
                        <div class="cb-col small">
                            <select name="reason" class="cb-select">
                                <option>General Inquiry</option>
                                <option>Waitlist Question</option>
                                <option>Reservation Help</option>
                                <option>Billing</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:8px;"><textarea name="message" class="cb-textarea" placeholder="Your message" required></textarea></div>
                    <div class="cb-actions">
                        <button type="button" id="contact-cancel" class="btn">Cancel</button>
                        <button type="submit" class="btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>

        </div>
        </div>

        <!-- Phone modal markup (page-scoped) -->
        <div class="cb-overlay" id="phone-overlay" aria-hidden="true">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="phone-title">
                <button type="button" id="phone-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="phone-title">Call Moffat Bay Marina</h3>
                <div style="text-align:center;padding:20px 0;font-size:28px;font-weight:700;">(555) 987-2345</div>
                <div style="display:flex;justify-content:center;margin-top:8px;"><button type="button" id="phone-great" class="btn-primary">Great</button></div>
            </div>
        </div>

        <!-- Cancel confirmation modal (page-scoped) -->
        <div class="cb-overlay" id="cancel-overlay" aria-hidden="true" style="display:none">
            <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
                <button type="button" id="cancel-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
                <h3 id="cancel-title">Confirm Cancel Waitlist Entry</h3>
                <p id="cancel-msg">Are you sure you want to cancel this waitlist entry? This will remove your position from the queue.</p>
                <div style="display:flex;gap:12px;justify-content:center;margin-top:12px;">
                    <button type="button" id="cancel-confirm" class="btn btn-cancel">Confirm Cancel</button>
                    <button type="button" id="cancel-keep" class="btn btn-leave">Leave</button>
                </div>
            </div>
        </div>

    </main>

    <script>
    (function(){
        function hideResults(){
            try{
                var w = document.getElementById('waitlist-section');
                if(w) w.style.display = 'none';
                var err = document.querySelector('.error-message');
                if(err) err.style.display = 'none';
            }catch(e){}
        }

        function clearOnReload(){
            try{
                var navEntries = (performance.getEntriesByType) ? performance.getEntriesByType('navigation') : [];
                var navType = (navEntries && navEntries[0] && navEntries[0].type) || (performance.navigation && performance.navigation.type);
                var isReload = (navType === 'reload' || navType === 1);
                if(isReload){
                    var q = document.querySelector('input[name="query"]');
                    if(q) q.value = '';
                    var email = document.querySelector('input[name="search_by"][value="email"]');
                    if(email) email.checked = true;
                    hideResults();
                }
            }catch(e){}
        }

        window.addEventListener('load', clearOnReload);
        window.addEventListener('pageshow', function(e){
            try{
                if(e && e.persisted){
                    var q = document.querySelector('input[name="query"]');
                    if(q) q.value = '';
                    var email = document.querySelector('input[name="search_by"][value="email"]');
                    if(email) email.checked = true;
                    hideResults();
                }
            }catch(er){}
        });
        // Contact modal logic
        (function(){
            var open = document.getElementById('contact-open');
            var overlay = document.getElementById('contact-overlay');
            var close = document.getElementById('contact-close');
            var cancel = document.getElementById('contact-cancel');
            var form = document.getElementById('contact-form');
            var msg = document.getElementById('cb-msg');

            function show(){ overlay.style.display = 'flex'; overlay.removeAttribute('aria-hidden'); }
            function hide(){ overlay.style.display = 'none'; overlay.setAttribute('aria-hidden','true'); msg.style.display='none'; form.style.display='block'; form.reset(); }

            if(open) open.addEventListener('click', function(){ show(); document.querySelector('#contact-form [name="name"]').focus(); });
            if(close) close.addEventListener('click', hide);
            if(cancel) cancel.addEventListener('click', hide);
            if(overlay) overlay.addEventListener('click', function(e){ if(e.target === overlay) hide(); });
            document.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });

            if(form) form.addEventListener('submit', function(e){
                e.preventDefault();
                var fd = new FormData(form);
                msg.style.display = 'none';
                fetch('contact_send.php', { method:'POST', body: fd }).then(function(r){ return r.json(); }).then(function(js){
                    if(js && js.success){
                        form.style.display='none';
                        msg.innerHTML = '<div class="cb-success">Thanks! Your message was sent.</div>';
                        msg.style.display='block';
                        setTimeout(hide, 2200);
                    } else {
                        var err = (js && js.errors) ? js.errors.join('<br>') : 'Failed to send message.';
                        msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">'+err+'</div>';
                        msg.style.display='block';
                    }
                }).catch(function(){
                    msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">Network error sending message.</div>';
                    msg.style.display='block';
                });
            });

            // Phone modal logic (page-scoped)
            (function(){
                var phoneOpen = document.getElementById('phone-open');
                var phoneOverlay = document.getElementById('phone-overlay');
                var phoneClose = document.getElementById('phone-close');
                var phoneGreat = document.getElementById('phone-great');
                function showPhone(){ if(phoneOverlay){ phoneOverlay.style.display='flex'; phoneOverlay.removeAttribute('aria-hidden'); } }
                function hidePhone(){ if(phoneOverlay){ phoneOverlay.style.display='none'; phoneOverlay.setAttribute('aria-hidden','true'); } }
                if(phoneOpen) phoneOpen.addEventListener('click', function(e){ e.preventDefault(); showPhone(); });
                if(phoneClose) phoneClose.addEventListener('click', hidePhone);
                if(phoneGreat) phoneGreat.addEventListener('click', hidePhone);
                if(phoneOverlay) phoneOverlay.addEventListener('click', function(e){ if(e.target === phoneOverlay) hidePhone(); });
                document.addEventListener('keydown', function(e){ if(e.key==='Escape') hidePhone(); });
            })();
        })();
    })();
        // Waitlist cancel modal logic
        (function(){
            var overlay = document.getElementById('cancel-overlay');
            var closeBtn = document.getElementById('cancel-close');
            var keepBtn = document.getElementById('cancel-keep');
            var confirmBtn = document.getElementById('cancel-confirm');
            var currentId = null;

            function showCancel(id){
                currentId = id;
                if(overlay){ overlay.style.display = 'flex'; overlay.removeAttribute('aria-hidden'); }
                if(confirmBtn) confirmBtn.focus();
            }
            function hideCancel(){
                currentId = null;
                if(overlay){ overlay.style.display = 'none'; overlay.setAttribute('aria-hidden','true'); }
            }

            // Delegate clicks from cancel links
            document.addEventListener('click', function(e){
                var a = e.target.closest && e.target.closest('.cancel-waitlist-link');
                if(a){
                    e.preventDefault();
                    var id = a.getAttribute('data-id');
                    if(id) showCancel(id);
                }
            });

            if(closeBtn) closeBtn.addEventListener('click', hideCancel);
            if(keepBtn) keepBtn.addEventListener('click', hideCancel);

            if(confirmBtn){
                confirmBtn.addEventListener('click', function(){
                    if(!currentId) return;
                    var fd = new FormData();
                    fd.append('do','cancel_waitlist');
                    fd.append('waitlist_id', currentId);
                    fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){
                        return r.json();
                    }).then(function(js){
                        if(js && js.success){
                            // Refresh the lookup results so positions update server-side
                            hideCancel();
                            var form = document.querySelector('form.form-lookup');
                            if(form) {
                                // submit the form to re-run the search and re-render the table
                                form.submit();
                            } else {
                                // fallback full reload
                                window.location.reload();
                            }
                        } else {
                            var err = (js && js.error) ? js.error : 'Failed to cancel entry.';
                            alert(err);
                        }
                    }).catch(function(){ alert('Network error attempting to cancel.'); });
                });
            }
        })();
    </script>
    <?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>
