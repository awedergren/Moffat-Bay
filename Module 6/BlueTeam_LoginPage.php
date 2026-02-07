<?php
session_start();

// Load database connection from project config so `$pdo` is available.
// Try several sensible locations so the page works when served from htdocs
// or run from the project root. After attempting includes we set
// `$dbAvailable` to true when `$pdo` is a valid PDO instance.
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
// If the included file set $pdo in a different scope, try the global.
if (!isset($pdo) && isset($GLOBALS['pdo'])) {
  $pdo = $GLOBALS['pdo'];
}
$dbAvailable = (isset($pdo) && ($pdo instanceof PDO));
$loggedIn = isset($_SESSION['username']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Moffat Bay — Login</title>
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
    /* left and right groups inside nav; right group pushed using margin-left */
    nav .nav-left{display:flex;gap:18px}
    nav .nav-right{margin-left:auto;display:flex;gap:18px;align-items:center}
    /* ensure spacing between last left link and the account links */
    nav .nav-left a:last-child{margin-right:18px}

    /* Hero and notice bar */
    .hero{height:220px;background:linear-gradient(135deg,var(--navy) 10%, rgba(47,93,74,0.85) 100%);display:flex;align-items:center;justify-content:center;color:var(--boat-white);position:relative}
    .hero .hero-inner{max-width:var(--max-width);text-align:center}
    .hero .icon{width:64px;height:64px;border-radius:50%;background:var(--ocean);display:inline-flex;align-items:center;justify-content:center;margin:0 auto 14px;box-shadow:0 6px 18px rgba(31,47,69,0.25)}
    .hero h1{margin:0;font-size:28px;font-weight:700}
    .hero p{margin:8px 0 0;color:rgba(248,249,250,0.9)}

    .notice-wrap{display:flex;justify-content:center;margin-top:18px;position:relative;z-index:2;padding:0 26px}
    /* match notice width to cards below */
    .notice{background:var(--ocean);color:var(--boat-white);padding:14px 26px;border-radius:8px;width:100%;box-sizing:border-box;text-align:center;font-size:15px;box-shadow:0 6px 18px rgba(31,47,69,0.08);position:relative}

    /* Content wrapper to align notice and cards */
    .content{max-width:980px;margin:0 auto;padding:0;box-sizing:border-box}

    /* Card area */
    /* keep cards below hero/notice so no overlap */
    .card-wrap{width:100%;margin:24px 0 60px;padding:26px;background:transparent;border-radius:8px;position:relative;z-index:1}
    .card-grid{display:flex;gap:24px;align-items:flex-start}
    .card-left{flex:1;background:#fff;border-radius:10px;padding:22px;border:1px solid var(--gray);box-shadow:0 8px 30px rgba(31,47,69,0.06)}
    .card-right{width:360px}

    /* Register card styling to match reference */
    .register-panel{background:#fff;border-radius:10px;padding:18px;border:2px solid rgba(63,135,166,0.15);box-shadow:0 10px 30px rgba(31,47,69,0.06)}
    .register-panel .top{display:flex;align-items:center;justify-content:center;padding:18px 0;border-bottom:1px solid var(--gray);}
    .register-panel h3{margin:0;font-size:18px}
    .benefits{background:var(--cream);padding:14px;border-radius:8px;margin-top:14px;border:1px solid rgba(0,0,0,0.04)}

    /* Form elements */
    label.small{display:block;font-size:13px;color:#556170;margin-bottom:6px}
    .input{display:block;width:100%;padding:12px 14px;border-radius:8px;border:1px solid var(--gray);margin-bottom:12px;background:#fff}
    .small{font-size:14px;color:#556170}
    .muted{font-size:13px;color:#6b7280}

    /* Buttons */
    .btn{font-weight:700;border-radius:8px;padding:10px 16px;cursor:pointer;border:1px solid transparent}
    .btn.login{background:var(--ocean);color:var(--boat-white);border-color:var(--ocean);padding:12px 18px}
    .btn.create{background:var(--gold);color:var(--navy);border-color:var(--gold);padding:12px 18px}
    .btn.ghost{background:transparent;color:var(--ocean);border:1px solid var(--ocean)}

    .demo-box{margin-top:14px;padding:12px;border-radius:8px;background:var(--cream);border:1px solid rgba(0,0,0,0.04);font-size:13px}

    .error{display:none;background:#fff0f0;color:#7a1f11;padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid #ffdede}
    .success{display:none;background:#e8f9f1;color:var(--pine);padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid rgba(47,93,74,0.1)}

    @media (max-width:900px){
      .card-grid{flex-direction:column}
      .card-right{width:100%}
      .logo-link img{height:44px;width:44px}
    }
  </style>
</head>
<body>
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
            <a href="account.php">My Account</a>
            <a href="logout.php" class="btn ghost">Log out</a>
          <?php else: ?>
            <a href="BlueTeam_LoginPage.php">Login/Register</a>
          <?php endif; ?>
        </div>
      </nav>
    </div>
  </header>

  <!-- Hero -->
  <section class="hero">
    <div class="hero-inner">
      <div class="icon">👤</div>
      <h1>Welcome Back</h1>
      <p>Log in to manage your reservations at Moffat Bay Marina</p>
    </div>
  </section>

  <div class="content">
    <div class="notice-wrap">
      <div class="notice">Please log in to make a reservation. You must be logged into your account to reserve boat slips, join waitlists, or manage your bookings.</div>
    </div>

    <main>
      <div class="card-wrap">
      <div class="card-grid">
        <div class="card-left">
          <h2 style="margin-top:0;color:var(--navy)">Login to Your Account</h2>
          <p class="small">Access your reservations and account settings</p>

          <div id="error" class="error"></div>
          <div id="success" class="success"></div>

          <form id="loginForm" method="post" action="BlueTeam_LoginHandler.php">
            <label class="small">Email Address *</label>
            <input class="input" name="email" type="email" placeholder="your.email@example.com" required>

            <label class="small">Password</label>
            <input class="input" name="password" type="password" placeholder="Enter your password" required>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px">
              <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="remember"> <span class="small">Remember me</span></label>
              <a href="#" class="small">Forgot password?</a>
            </div>

            <button class="btn login" type="submit" style="margin-top:14px;">Login</button>
          </form>

          

        </div>

        <div class="card-right">
          <div class="register-panel">
            <div class="top">
              <div style="width:56px;height:56px;border-radius:50%;background:var(--cream);display:flex;align-items:center;justify-content:center;color:var(--navy);font-weight:700;margin-right:10px">👤</div>
            </div>
            <div style="padding:18px;text-align:center">
              <h3 style="margin-top:0;color:var(--navy)">New to Moffat Bay?</h3>
              <div class="muted" style="margin-top:8px">Create an account to start making reservations and manage your boat slips at our marina.</div>
                <div style="margin-top:16px">
                <button id="openRegister" class="btn create" style="width:100%" onclick="location.href='registration.php'">Create Your Account →</button>
              </div>
            </div>
          </div>

          <div class="benefits">
            <strong>Benefits of Creating an Account</strong>
            <ul class="small" style="margin-top:8px">
              <li>Easy online reservation management</li>
              <li>View and modify your reservations anytime</li>
              <li>Check waitlist positions in real-time</li>
              <li>Receive updates about your reservations</li>
              <li>Save your boat and contact information</li>
            </ul>
          </div>

          
        </div>
      </div>
      </div>
    </main>
  </div>

  <footer>
    <div class="container" style="max-width:var(--max-width);">
      <small>&copy; 2026 Moffat Bay Island Marina — All rights reserved.</small>
    </div>
  </footer>
  <script>
    // Logo fallback sequence: try project-relative, then absolute file URI, then inline SVG.
    (function(){
      const img = document.getElementById('siteLogo');
      if(!img) return;
      const absolute = 'file:///C:/Users/awede/Desktop/BCS%20Degree%20-%202025/Winter%202025/Capstone/Moffat%20Bay%20Marina%20Logo.png';
      img.addEventListener('error', function handler(){
        // first fallback: if we haven't tried the absolute path yet, try it
        if(!this.dataset.triedAbsolute){
          this.dataset.triedAbsolute = '1';
          this.src = absolute;
          return;
        }
        // final fallback: inline SVG
        this.removeEventListener('error', handler);
        this.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="52" height="52" viewBox="0 0 52 52"><rect rx="10" width="52" height="52" fill="%231F2F45"/><text x="50%" y="58%" font-size="18" font-family="Segoe UI,Arial" fill="%23F2E6C9" text-anchor="middle" dominant-baseline="middle">MB</text></svg>';
      });
    })();
    // show error/success from query params
    (function(){
      const params = new URLSearchParams(window.location.search);
      const err = params.get('error');
      const logged = params.get('logged');
      if(err){
        const el = document.getElementById('error');
        el.textContent = decodeURIComponent(err);
        el.style.display = 'block';
      }
      if(logged){
        const s = document.getElementById('success');
        s.textContent = 'You are now logged in.';
        s.style.display = 'block';
      }
    })();
    // Toggle register form — only attach handlers if the related elements exist
    const openRegister = document.getElementById('openRegister');
    const registerForm = document.getElementById('registerForm');
    const cancelCreate = document.getElementById('cancelCreate');
    if (openRegister && registerForm) {
      openRegister.addEventListener('click', () => {
        registerForm.style.display = 'block';
        openRegister.style.display = 'none';
        const np = document.getElementById('newPassword');
        if (np) np.focus();
      });
    }
    if (cancelCreate && registerForm && openRegister) {
      cancelCreate.addEventListener('click', () => {
        registerForm.style.display = 'none';
        openRegister.style.display = 'block';
      });
    }

    // Password requirement checks
    const pw = document.getElementById('newPassword');
    const confirm = document.getElementById('confirmPassword');
    const pwReqEls = document.querySelectorAll('#pwReqs .pw-req');
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
        special: /[!@#\$%\^&\*\(\)\-_=+\[\]{};:'"\\|,.<>\/?`~]/.test(value)
      };
      return results;
    }

    function updateReqs(){
      const val = pw.value || '';
      const r = validatePassword(val);
      setReq(pwReqEls[0], r.length);
      setReq(pwReqEls[1], r.upper);
      setReq(pwReqEls[2], r.lower);
      setReq(pwReqEls[3], r.number);
      setReq(pwReqEls[4], r.special);
    }

    pw && pw.addEventListener('input', updateReqs);
    pw && pw.addEventListener('focus', () => { document.getElementById('pwHelp').style.display = 'block'; updateReqs(); });

    // Form submission: prevent if password invalid or mismatch
    const createForm = document.getElementById('createAccountForm');
    createForm && createForm.addEventListener('submit', function(e){
      e.preventDefault();
      const val = pw.value || '';
      const r = validatePassword(val);
      const allValid = r.length && r.upper && r.lower && r.number && r.special;
      if(!allValid){
        alert('Password does not meet the requirements.');
        pw.focus();
        return;
      }
      if(val !== confirm.value){
        alert('Passwords do not match.');
        confirm.focus();
        return;
      }
      // For now, we only validate on the client. Implement server-side registration separately.
      alert('Registration validated (client-side). Implement server endpoint to complete registration.');
    });
  </script>
</body>
</html>