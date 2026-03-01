<?php
session_start();
require_once __DIR__ . '/db.php';
$loggedIn = isset($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>About Us | Moffat Bay Island Marina</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css?v=1">
  <link rel="stylesheet" href="about.css?v=3">
</head>
<body>

<?php include 'nav.php'; ?>

<section class="hero">
  <img src="about-hero.jpg" alt="Moffat Bay Marina View" class="hero-img">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,0.45);z-index:1;"></div>
  <div class="hero-title-wrap">
    <h1 style="margin:0;color:#fff;font-size:52px;font-weight:700;letter-spacing:.2px;position:relative;z-index:10;">About Us</h1>
  </div>
</section>

<section class="paper">
  <div class="wrap">
    <div class="paper-inner">

      <h2 class="h2-center">A Marina Built for Relaxation & Adventure</h2>

      <div class="about-block">
        <div>
          <p class="body">
            Welcome to Moffat Bay Island Marina, a premier destination for boaters
            seeking a peaceful island retreat with first-class amenities. Whether
            you're a seasoned sailor or a weekend explorer, our marina offers
            the perfect balance of comfort, security, and natural beauty.
          </p>

          <ul class="checklist">
            <li>Secure, well-maintained dock facilities</li>
            <li>Friendly, knowledgeable marina staff</li>
            <li>Modern utilities and shore power access</li>
            <li>Easy access to island attractions</li>
          </ul>
        </div>

        <div class="photo-frame">
          <img src="about-main.jpg" alt="Marina Docks and Boats">
        </div>
      </div>

      <section class="why">
        <h2 class="h2">Why Choose Moffat Bay?</h2>

        <div class="why-grid">
          <div class="why-card">
            <h3 class="h3">Prime Location</h3>
            <p class="small">Nestled in a scenic island setting with calm waters.</p>
          </div>

          <div class="why-card">
            <h3 class="h3">Reliable Security</h3>
            <p class="small">24/7 monitored docks and controlled access.</p>
          </div>

          <div class="why-card">
            <h3 class="h3">Modern Amenities</h3>
            <p class="small">Electric hookups, fresh water, and clean facilities.</p>
          </div>

          <div class="why-card">
            <h3 class="h3">Customer Focused</h3>
            <p class="small">We treat every boater like a long-term guest.</p>
          </div>
        </div>
      </section>

      <div class="pricing-banner">
        <img src="pricing-banner.jpg" alt="Marina at Sunset">
        <div class="pricing-banner-overlay"></div>
        <div class="pricing-banner-text">Slip Pricing & Seasonal Rates</div>
      </div>

      <section class="pricing">
        <p class="pricing-sub">
          Flexible pricing options to accommodate boats of all sizes.
        </p>

        <div class="pricing-formula">
          <h3 class="h3" style="text-align:center;margin-bottom:16px;">Our Simple Pricing Formula</h3>
          <div class="formula-box">
            <div class="formula-text">Monthly Rate = (Boat Length × $10.50) + $10.50 electric power</div>
            <p class="small" style="text-align:center;margin-top:12px;color:#fff;font-weight:700;">
              For example, a 34 ft boat would cost <strong>$367.50/month</strong> (34 × $10.50 + $10.50 = $367.50)
            </p>
          </div>
        </div>

        <h3 class="h3" style="text-align:center;margin:40px 0 20px;">Common Slip Sizes & Rates</h3>

        <div class="table">
          <div class="tr th">
            <div class="td">Boat Length</div>
            <div class="td-right">Monthly Rate</div>
          </div>

          <div class="tr">
            <div class="td">20 ft</div>
            <div class="td-right">$220.50</div>
          </div>

          <div class="tr">
            <div class="td">25 ft</div>
            <div class="td-right">$273</div>
          </div>

          <div class="tr">
            <div class="td">30 ft</div>
            <div class="td-right">$325.50</div>
          </div>

          <div class="tr">
            <div class="td">35 ft</div>
            <div class="td-right">$378</div>
          </div>

          <div class="tr">
            <div class="td">40 ft</div>
            <div class="td-right">$430.50</div>
          </div>

          <div class="tr">
            <div class="td">50 ft</div>
            <div class="td-right">$535.50</div>
          </div>
        </div>

        <p style="text-align:center;margin-top:20px;color:rgba(31,47,69,0.75);font-size:14px;">
          Seasonal discounts available. Contact us for long-term reservations.
        </p>
      </section>

      <section id="contact" class="contact-info">
        <h2 class="h2-center">Contact Us</h2>
        <div class="contact-details">
          <div class="contact-item">
            <h3 class="h3">Phone</h3>
            <p class="body"><a href="tel:+15559872345">(555) 987-2345</a></p>
          </div>
          <div class="contact-item">
            <h3 class="h3">Email</h3>
            <p class="body"><a href="mailto:info@moffatbaymarina.com">info@moffatbaymarina.com</a></p>
          </div>
        </div>
      </section>

    </div>
  </div>
</section>

<footer class="site-footer">
  <p>&copy; <?php echo date("Y"); ?> Moffat Bay Island Marina</p>
</footer>

</body>
</html>
