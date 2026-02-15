<?php
session_start();
?>
<?php
// Pricing constants
$pricePerFoot = 10.50;
$hookupPerMonth = 10.50;
function fmt($n){return number_format($n,2);} 
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reserve Your Boat Slip — Moffat Bay</title>
  <link rel="stylesheet" href="styles.css?v=1">
  <link rel="stylesheet" href="styles_reservation_info.css?v=23">
  <!-- Using inline SVG icons for consistent rendering (no external CDN required) -->
  <style id="critical-reservation-cards">
    /* Critical overrides to match reference visuals exactly */
    /* Applied hero background & height from BlueTeam_LoginPage (only background + height copied). */
    .hero{position:relative;z-index:1;height:320px;background:linear-gradient(135deg,var(--navy) 10%, rgba(47,93,74,0.85) 100%);padding:14px 20px 16px}
    .cards{max-width:1100px;margin:-40px auto 60px;display:flex;gap:32px;padding:0 24px;justify-content:center;z-index:5;position:relative}
    .card{background:#fff;border-radius:12px;padding:26px 26px;flex:0 0 300px;box-shadow:0 14px 28px rgba(31,47,69,0.08);border:1px solid rgba(31,47,69,0.06);text-align:left;z-index:6}
    .card .icon{width:56px;height:56px;border-radius:50%;background:#2f7f92;color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;font-size:20px}
    .card h3{font-size:16px;margin:0 0 8px}
    .card p{margin:0;color:#6b7280;font-size:13px;line-height:1.5}
    /* Price styles are defined in styles_reservation_info.css to allow full visual control */
    /* Slight responsive tweaks */
    @media(max-width:900px){.cards{flex-direction:column;margin-top:-40px}.price-grid{flex-direction:column}.card{width:100%;max-width:660px}}
  </style>
</head>
<body class="reservation-info">
  <?php include 'nav.php'; ?>

  <section class="hero">
    <div class="inner">
      <h1>Reserve Your Boat Slip</h1>
      <p>Secure your spot at Moffat Bay Marina in the beautiful San Juan Islands. We offer flexible monthly reservations with competitive rates and premium amenities.</p>
      <p>Please login or create an account to make a reservation.</p>
      <a class="cta" href="slip_reservation.php">Make a Reservation</a>
    </div>
  </section>

  <div class="cards">
    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ship-icon lucide-ship">
          <path d="M12 10.189V14"/>
          <path d="M12 2v3"/>
          <path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/>
          <path d="M19.38 20A11.6 11.6 0 0 0 21 14l-8.188-3.639a2 2 0 0 0-1.624 0L3 14a11.6 11.6 0 0 0 2.81 7.76"/>
          <path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1s1.2 1 2.5 1c2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/>
        </svg>
      </div>
      <h3>3 Slip Sizes</h3>
      <p>26ft, 40ft, and 50ft slips available across three linear docks</p>
    </div>
    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <line x1="12" y1="1" x2="12" y2="23" />
          <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H7" />
        </svg>
      </div>
      <h3>Simple Pricing</h3>
      <p>$10.50 per foot plus $10.50 for electric hookup per month</p>
    </div>
    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
      </div>
      <h3>Flexible Terms</h3>
      <p>Monthly reservations with a 1-month minimum stay</p>
    </div>
  </div>

  <section class="pricing">
    <h2>Slip Sizes & Pricing</h2>
    <p class="lead">Choose the perfect slip size for your boat. Pricing is calculated at $<?php echo fmt($pricePerFoot); ?> per foot of boat length, plus $<?php echo fmt($hookupPerMonth); ?> for electric hookup, per month.</p>

    <h3 class="available-heading">Available Slip Sizes</h3>
    <div class="price-grid">
      <div class="price-tile">
        <div class="price-icon" aria-hidden="true">
          <!-- anchor icon (keeps look consistent) -->
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-anchor-icon lucide-anchor">
            <path d="M12 6v16"/>
            <path d="m19 13 2-1a9 9 0 0 1-18 0l2 1"/>
            <path d="M9 11h6"/>
            <circle cx="12" cy="4" r="2"/>
          </svg>
        </div>
        <div class="label">26ft Slips</div>
        <p class="tile-lead">Perfect for smaller vessels</p>
        <div class="features">
          <ul>
            <li>30 slips available</li>
            <li>Accommodates boats up to 26 feet</li>
            <li>Electric hookup included</li>
            <li>Located on all three docks</li>
          </ul>
        </div>
      </div>
      <div class="price-tile popular">
        <div class="badge-pop">Most Popular</div>
        <div class="price-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-anchor-icon lucide-anchor">
            <path d="M12 6v16"/>
            <path d="m19 13 2-1a9 9 0 0 1-18 0l2 1"/>
            <path d="M9 11h6"/>
            <circle cx="12" cy="4" r="2"/>
          </svg>
        </div>
        <div class="label">40ft Slips</div>
        <p class="tile-lead">Ideal for mid-size boats</p>
        <div class="features">
          <ul>
            <li>24 slips available</li>
            <li>Accommodates boats up to 40 feet</li>
            <li>Electric hookup included</li>
            <li>Prime dock locations</li>
          </ul>
        </div>
      </div>
      <div class="price-tile">
        <div class="price-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-anchor-icon lucide-anchor">
            <path d="M12 6v16"/>
            <path d="m19 13 2-1a9 9 0 0 1-18 0l2 1"/>
            <path d="M9 11h6"/>
            <circle cx="12" cy="4" r="2"/>
          </svg>
        </div>
        <div class="label">50ft Slips</div>
        <p class="tile-lead">For larger vessels &amp; yachts</p>
        <div class="features">
          <ul>
            <li>18 premium slips</li>
            <li>Accommodates boats up to 50 feet</li>
            <li>Electric hookup included</li>
            <li>Easy access to facilities</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Monthly cost by boat length -->
    <?php
      $m20 = 20 * $pricePerFoot + $hookupPerMonth;
      $m26 = 26 * $pricePerFoot + $hookupPerMonth;
      $m32 = 32 * $pricePerFoot + $hookupPerMonth;
      $m40 = 40 * $pricePerFoot + $hookupPerMonth;
      $m50 = 50 * $pricePerFoot + $hookupPerMonth;
    ?>
    <h3 class="monthly-heading">Monthly Cost by Boat Length</h3>
    <p class="lead">Your monthly rate is based on your boat's length at $<?php echo fmt($pricePerFoot); ?> per foot, plus $<?php echo fmt($hookupPerMonth); ?> for electric hookup</p>
    <div class="monthly-grid">
      <div class="monthly-tile"><div class="size">20ft</div><div class="monthly-price">$<?php echo fmt($m20); ?></div><div class="monthly-note">per month</div></div>
      <div class="monthly-tile"><div class="size">26ft</div><div class="monthly-price">$<?php echo fmt($m26); ?></div><div class="monthly-note">per month</div></div>
      <div class="monthly-tile"><div class="size">32ft</div><div class="monthly-price">$<?php echo fmt($m32); ?></div><div class="monthly-note">per month</div></div>
      <div class="monthly-tile"><div class="size">40ft</div><div class="monthly-price">$<?php echo fmt($m40); ?></div><div class="monthly-note">per month</div></div>
      <div class="monthly-tile"><div class="size">50ft</div><div class="monthly-price">$<?php echo fmt($m50); ?></div><div class="monthly-note">per month</div></div>
    </div>

    <!-- Pricing formula band -->
    <div class="formula-band">
      <div class="formula-inner">
        <div class="formula-icon">$
        </div>
        <div class="formula-text">
          <strong>Pricing Formula:</strong> (Boat Length × $<?php echo fmt($pricePerFoot); ?>) + $<?php echo fmt($hookupPerMonth); ?> electric hookup = Monthly Rate
          <div class="formula-example">Example: A 35ft boat would be (35 × $<?php echo fmt($pricePerFoot); ?>) + $<?php echo fmt($hookupPerMonth); ?> = $<?php echo fmt(35 * $pricePerFoot + $hookupPerMonth); ?>/month</div>
        </div>
      </div>
    </div>
  </section>

  <div class="map-wrap">
    <h3 style="margin-top:0;text-align:center">Marina Layout</h3>
    <div class="map-inner">
      <?php if (file_exists(__DIR__ . '/marina_map.png')): ?>
        <img src="marina_map.png" alt="Marina layout map">
      <?php else: ?>
        <!-- Inline placeholder SVG when map image is not available -->
        <svg width="100%" height="360" viewBox="0 0 1000 360" xmlns="http://www.w3.org/2000/svg" style="border-radius:8px;display:block">
          <rect width="100%" height="100%" fill="#eef6fb" rx="8"/>
          <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6b7280" font-size="20">Marina map placeholder (add marina_map.png to project)</text>
        </svg>
      <?php endif; ?>
    
      <!-- legend moved inside the map-inner to display over the map footer -->
      <div class="map-legend">
        <div class="legend-item"><span class="chip chip-26"></span><span class="legend-text">26ft Slips (30 total)</span></div>
        <div class="legend-item"><span class="chip chip-40"></span><span class="legend-text">40ft Slips (24 total)</span></div>
        <div class="legend-item"><span class="chip chip-50"></span><span class="legend-text">50ft Slips (18 total)</span></div>
      </div>
    </div>
  </div>

  <div class="dock-buttons">
    <div class="dock-card">Linear Dock A<br><small>24 slips (mixed sizes)</small></div>
    <div class="dock-card">Linear Dock B<br><small>24 slips (mixed sizes)</small></div>
    <div class="dock-card">Linear Dock C<br><small>24 slips (mixed sizes)</small></div>
  </div>

  
  <div class="info-heading">
    <h2>What You Need to Know</h2>
    <p class="lead">Before making your reservation, here's what information you'll need to provide</p>
  </div>

  <div class="info-grid">
    
    <div class="info-box">
      <div class="info-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ship-icon lucide-ship">
          <path d="M12 10.189V14"/>
          <path d="M12 2v3"/>
          <path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/>
          <path d="M19.38 20A11.6 11.6 0 0 0 21 14l-8.188-3.639a2 2 0 0 0-1.624 0L3 14a11.6 11.6 0 0 0 2.81 7.76"/>
          <path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1s1.2 1 2.5 1c2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/>
        </svg>
      </div>
      <h4>Boat Information</h4>
      <ul>
        <li>Boat length (in feet)</li>
        <li>Boat name</li>
      </ul>
    </div>
    <div class="info-box">
      <div class="info-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
      </div>
      <h4>Reservation Dates</h4>
      <ul>
        <li>Start date (check-in)</li>
        <li>End date (check-out)</li>
        <li>Minimum 1-month reservation required</li>
      </ul>
    </div>
    <div class="info-box">
      <div class="info-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
          <circle cx="12" cy="7" r="4" />
        </svg>
      </div>
      <h4>Contact Details</h4>
      <ul>
        <li>Full name</li>
        <li>Email address</li>
        <li>Phone number</li>
      </ul>
    </div>
    <div class="info-box">
      <div class="info-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
          <path d="M21 10c0 6-9 13-9 13S3 16 3 10a9 9 0 1 1 18 0z" />
          <circle cx="12" cy="10" r="3" />
        </svg>
      </div>
      <h4>Slip Preference</h4>
      <ul>
        <li>Choose preferred slip size</li>
        <li>Select specific slip from available options</li>
      </ul>
    </div>
  </div>

  <!-- Simple Reservation Process (beige band) -->
  <section class="process-band">
    <div class="process-inner">
      <h2>Simple Reservation Process</h2>
      <p class="lead">Making a reservation is easy. Just follow these four simple steps.</p>
      <div class="process-steps">
        <div class="step">
          <div class="step-circle">1</div>
          <h4>Select Dates &amp; Size</h4>
          <p>Choose your dates and the slip size that fits your boat</p>
        </div>
        <div class="step">
          <div class="step-circle">2</div>
          <h4>Pick Your Slip</h4>
          <p>View available slips on our interactive map and select your favorite</p>
        </div>
        <div class="step">
          <div class="step-circle">3</div>
          <h4>Enter Details</h4>
          <p>Provide your boat information and contact details</p>
        </div>
        <div class="step">
          <div class="step-circle">4</div>
          <h4>Confirm &amp; Pay</h4>
          <p>Review your reservation and choose to pay now or later</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Marina Amenities -->
  <section class="amenities">
    <div class="amenities-inner">
      <h2>Marina Amenities</h2>
      <p class="lead">Every reservation includes access to our full range of facilities and services</p>
      <div class="amenity-cards">
        <div class="amenity fuel">
          <div class="amenity-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
              <path d="M13 2L3 14h7l-1 8L21 10h-7l1-8z" />
            </svg>
          </div>
          <h4>Electric Hookup</h4>
          <p>All slips include electric power hookup at just $<?php echo fmt($hookupPerMonth); ?>/month</p>
        </div>
        <div class="amenity">
          <div class="amenity-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ship-icon lucide-ship">
              <path d="M12 10.189V14"/>
              <path d="M12 2v3"/>
              <path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/>
              <path d="M19.38 20A11.6 11.6 0 0 0 21 14l-8.188-3.639a2 2 0 0 0-1.624 0L3 14a11.6 11.6 0 0 0 2.81 7.76"/>
              <path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1s1.2 1 2.5 1c2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/>
            </svg>
          </div>
          <h4>Fuel Dock</h4>
          <p>Convenient on-site fuel dock for easy refueling</p>
        </div>
        <div class="amenity">
          <div class="amenity-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
              <path d="M21 10c0 6-9 13-9 13S3 16 3 10a9 9 0 1 1 18 0z" />
              <circle cx="12" cy="10" r="2" />
            </svg>
          </div>
          <h4>Marina Store &amp; Restaurant</h4>
          <p>Well-stocked store for all your boating needs, featuring our waterfront restaurant with fresh Pacific Northwest cuisine</p>
        </div>
      </div>
    </div>
  </section>

  <section class="cta-footer">
    <div class="cta-inner">
      <h3>Ready to Reserve Your Slip?</h3>
      <p>Secure your spot at Moffat Bay Marina today. Our team is here to help every step of the way.</p>
      <a class="cta" href="slip_reservation.php">Make a Reservation</a>
      <a class="cta secondary" href="about.php#contact">Contact Us</a>
    </div>
  </section>

</body>
</html>
