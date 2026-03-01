<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: about.php
Purpose: About page content and team information.
This header is informational only and does not change behavior or output.
*/
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
  <style>
    /* Footer logo: centered above the footer and sized slightly larger with minimal vertical spacing */
    .footer-logo { display:block; margin:1px auto; max-width:320px; height:auto; }
    /* Reduce footer vertical padding and reset footer paragraph margins locally */
    .site-footer { padding-top:1px; padding-bottom:1px; }
    .site-footer p { margin:0; }
    /* Reduce vertical spacing below the main content card */
    .paper { padding-bottom: 1px !important; }
    .paper-inner { margin-bottom: 1px !important; }
    /* Page-local override to ensure the paper slightly overlaps the hero */
    .hero-about + .paper { margin-top: -10px !important; }
  </style>
</head>
<body>

<?php include 'nav.php'; ?>

<?php
  $hero_title = 'About Us';
  $hero_subtitle = '<p>Welcome to Moffat Bay Island Marina — a premier destination for boaters seeking a peaceful island retreat.</p>';
  $hero_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-info-icon lucide-info"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
  $hero_classes = 'hero-about';
  include 'hero.php';
?>
<style>
  /* page-local: slight overlap so the paper sits partially over the hero */
  .site-hero.hero-about + .paper { margin-top: -10px !important; }
</style>

  <style>
    /* Contact modal (page-scoped) copied from look_up.php to keep behavior local to this page */
    .cb-overlay{ position:fixed; inset:0; background:rgba(0,0,0,0.45); display:none; align-items:center; justify-content:center; z-index:2147483646; }
    .cb-modal{ background:#fff; border-radius:10px; max-width:720px; width:94%; padding:20px; box-shadow:0 20px 50px rgba(15,30,60,0.25); }
    .cb-modal h3{ margin-top:0 }
    .cb-row{ display:flex; gap:12px; margin-bottom:12px; }
    .cb-row .cb-col{ flex:1 }
    .cb-row .cb-col.small{ flex:0 0 160px }
    .cb-input, .cb-textarea, .cb-select{ width:100%; padding:10px; border:1px solid #d0d7d9; border-radius:6px; box-sizing:border-box }
    .cb-textarea{ min-height:120px; resize:vertical }
    .cb-actions{ display:flex; gap:10px; justify-content:flex-end; margin-top:12px }
    .cb-success{ background:#d4edda; color:#155724; padding:10px; border-radius:6px; margin-bottom:10px }
    /* Remove default focus/outline on modal send button and set Ocean color */
    .cb-modal .btn-primary { outline: none; box-shadow: none; border: none; background: var(--ocean); color: #fff; }
    .cb-modal .btn-primary:focus { outline: none; box-shadow: none; }
    /* Make Contact Us send button consistent */
    .cb-modal .btn-primary { padding:16px 18px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; line-height:1; }
    /* Coral outline for modal cancel button */
    #contact-cancel { background: transparent; color: var(--coral); border: 2px solid var(--coral); padding: 8px 14px; border-radius:8px; }
    #contact-cancel:hover { background: var(--coral); color: #fff; border-color: var(--coral); }
    /* Inline marina map styles */
    .map-link a { text-decoration:none; display:inline-block; padding:10px 16px; border-radius:8px; border:1px solid rgba(15,37,64,0.06); background:#fff; color:var(--ocean); font-weight:600 }
    .map-card { display:none; margin:18px 0; text-align:center; max-width:var(--page-max-width); margin-left:auto; margin-right:auto; padding:12px; box-sizing:border-box }
    .map-card img { max-width:100%; height:auto; border-radius:8px; border:1px solid #e6eef0; box-shadow:0 8px 20px rgba(15,30,60,0.08); }
  </style>

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

        <div class="map-link" style="text-align:center;margin-bottom:12px;">
          <a href="#" id="map-open">View Marina Map</a>
        </div>
        <div class="card map-card" id="map-card" aria-hidden="true">
          <img src="marina_map.png" alt="Marina Map">
        </div>

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
            <p class="body"><a href="#" id="phone-open">(555) 987-2345</a></p>
          </div>
          <div class="contact-item">
            <h3 class="h3">Email</h3>
            <p class="body"><a href="#" id="contact-open">info@moffatbaymarina.com</a></p>
          </div>
        </div>
      </section>

    </div>
  </div>
</section>

<img src="logo.png" alt="Moffat Bay Marina logo" class="footer-logo">
<!-- Contact modal markup (page-scoped) -->
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
<!-- Phone modal markup (page-scoped) -->
<div class="cb-overlay" id="phone-overlay" aria-hidden="true">
  <div class="cb-modal" role="dialog" aria-modal="true" aria-labelledby="phone-title">
    <button type="button" id="phone-close" style="float:right;background:none;border:none;font-size:18px;line-height:1;">&times;</button>
    <h3 id="phone-title">Call Moffat Bay Marina</h3>
    <div style="text-align:center;padding:20px 0;font-size:28px;font-weight:700;">(555) 987-2345</div>
    <div style="display:flex;justify-content:center;margin-top:8px;"><button type="button" id="phone-great" class="btn-primary">Great</button></div>
  </div>
</div>
<?php include 'footer.php'; ?>

<script>
// Contact modal logic for about.php (page-scoped)
(function(){
  var open = document.getElementById('contact-open');
  var overlay = document.getElementById('contact-overlay');
  var close = document.getElementById('contact-close');
  var cancel = document.getElementById('contact-cancel');
  var form = document.getElementById('contact-form');
  var msg = document.getElementById('cb-msg');
  function show(){ overlay.style.display = 'flex'; overlay.removeAttribute('aria-hidden'); }
  function hide(){ overlay.style.display = 'none'; overlay.setAttribute('aria-hidden','true'); msg.style.display='none'; form.style.display='block'; form.reset(); }
  if(open) open.addEventListener('click', function(e){ e.preventDefault(); show(); document.querySelector('#contact-form [name="name"]').focus(); });
  if(close) close.addEventListener('click', hide);
  if(cancel) cancel.addEventListener('click', hide);
  if(overlay) overlay.addEventListener('click', function(e){ if(e.target === overlay) hide(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });
  if(form) form.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(form);
    msg.style.display = 'none';
    fetch('contact_send.php', { method:'POST', body: fd }).then(function(r){ return r.json(); }).then(function(js){
      if(js && js.success){ form.style.display='none'; msg.innerHTML = '<div class="cb-success">Thanks! Your message was sent.</div>'; msg.style.display='block'; setTimeout(hide, 2200);
      } else { var err = (js && js.errors) ? js.errors.join('<br>') : 'Failed to send message.'; msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">'+err+'</div>'; msg.style.display='block'; }
    }).catch(function(){ msg.innerHTML = '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:6px;">Network error sending message.</div>'; msg.style.display='block'; });
  });

  // Phone modal logic (page-scoped)
  (function(){
    var phoneOpen = document.getElementById('phone-open');
    var phoneOverlay = document.getElementById('phone-overlay');
    var phoneClose = document.getElementById('phone-close');
    var phoneGreat = document.getElementById('phone-great');
    function showPhone(){ if(phoneOverlay){ phoneOverlay.style.display = 'flex'; phoneOverlay.removeAttribute('aria-hidden'); } }
    function hidePhone(){ if(phoneOverlay){ phoneOverlay.style.display = 'none'; phoneOverlay.setAttribute('aria-hidden','true'); } }
    if(phoneOpen) phoneOpen.addEventListener('click', function(e){ e.preventDefault(); showPhone(); });
    if(phoneClose) phoneClose.addEventListener('click', hidePhone);
    if(phoneGreat) phoneGreat.addEventListener('click', hidePhone);
    if(phoneOverlay) phoneOverlay.addEventListener('click', function(e){ if(e.target === phoneOverlay) hidePhone(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') hidePhone(); });
  })();

})();
</script>

<script>
// Inline marina map show/hide (page-scoped)
(function(){
  var open = document.getElementById('map-open');
  var card = document.getElementById('map-card');
  if(!open || !card) return;
  open.addEventListener('click', function(e){
    e.preventDefault();
    var isShown = card.style.display === 'block' || card.getAttribute('data-open') === '1';
    if(isShown){
      card.style.display = 'none';
      card.setAttribute('data-open','0');
      card.setAttribute('aria-hidden','true');
      open.textContent = 'View Marina Map';
    } else {
      card.style.display = 'block';
      card.setAttribute('data-open','1');
      card.setAttribute('aria-hidden','false');
      open.textContent = 'Hide Marina Map';
      card.scrollIntoView({behavior:'smooth', block:'center'});
    }
  });
})();
</script>

<!-- no runtime overrides required; using page-local CSS to control overlap -->

</body>
</html>
