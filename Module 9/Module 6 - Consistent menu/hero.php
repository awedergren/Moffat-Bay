<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: hero.php
Purpose: Reusable hero include (sets page hero title, subtitle, and icon).
Non-executing header only.
*/
// Reusable hero include
// Usage: set the following variables before including this file:
//   $hero_title (string) - main heading text
//   $hero_subtitle (string) - optional subtext (may contain limited HTML)
//   $hero_icon (string) - optional HTML/SVG fragment for an icon (not escaped)
//   $hero_classes (string) - optional extra classes for the hero wrapper
// Example:
//   $hero_title = 'Slip Reservation';
//   $hero_subtitle = 'Reserve your spot at Moffat Bay';
//   $hero_icon = '<svg ...>...</svg>';
//   include 'hero.php';

if (!isset($hero_classes)) $hero_classes = '';
if (!isset($hero_title)) $hero_title = '';
if (!isset($hero_subtitle)) $hero_subtitle = '';
if (!isset($hero_icon)) $hero_icon = '';

?>
<section class="site-hero hero <?= htmlspecialchars($hero_classes) ?>" aria-label="Page hero">
  <div class="site-hero-inner hero-inner">
    <?php if ($hero_icon): ?>
      <div class="site-hero-icon icon" aria-hidden="true"><?= $hero_icon ?></div>
    <?php endif; ?>
    <div class="site-hero-text">
      <?php if ($hero_title): ?>
        <h1><?= htmlspecialchars($hero_title) ?></h1>
      <?php endif; ?>
      <?php if ($hero_subtitle): ?>
        <div class="site-hero-subtitle"><?= $hero_subtitle ?></div>
      <?php endif; ?>
    </div>
  </div>
  <style>
    :root{
      --navy:#1F2F45;
      --ocean:#3F87A6;
      --boat-white:#F8F9FA;
      --max-width:1100px;
      --hero-height:300px; /* unified hero height for large screens (reduced by 20px) */
      --hero-inner-padding:12px;
      --hero-icon-size:96px; /* diameter of circular icon */
      --hero-icon-slot:140px; /* vertical slot reserved for icon (keeps icon position consistent)
                                should be <= --hero-height */
      --hero-title-size:2.2rem;
      --hero-subtitle-size:1rem;
      --hero-gap:12px;
      --hero-subtitle-line-height:1.25;
      --hero-subtitle-margin-top:6px;
    }

    /* unified hero: fixed height on desktop, vertically center icon+text */
    .site-hero{
      background:linear-gradient(135deg,var(--navy) 10%, rgba(47,93,74,0.85) 100%);
      color:var(--boat-white);
      position:relative;
      height:var(--hero-height);
      padding:0;
      border-bottom:0;
      display:flex;
      align-items:center;
      justify-content:center;
      box-sizing:border-box;
    }

    /* Grid layout: fixed top row for icon, variable row for text.
       This guarantees the icon appears in the same vertical slot across pages. */
    .site-hero .site-hero-inner{
      max-width:var(--max-width);
      margin:0 auto;
      text-align:center;
      /* add 20px vertical space above the header */
      padding:20px var(--hero-inner-padding) 0;
      display:grid;
      grid-template-rows: var(--hero-icon-slot) 1fr;
      align-items:start;
      justify-items:center;
      row-gap:var(--hero-gap);
      height:100%;
      box-sizing:border-box;
    }

    .site-hero .site-hero-icon{
      width:var(--hero-icon-size);
      height:var(--hero-icon-size);
      border-radius:50%;
      background:var(--ocean);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 6px 18px rgba(31,47,69,0.25);
      align-self:center; /* center within the icon slot */
      margin:0;
    }

    .site-hero .site-hero-icon svg{width:58%;height:58%;fill:none;stroke:currentColor;stroke-width:2;display:block}

    .site-hero h1{margin:0;font-size:var(--hero-title-size);font-weight:700;line-height:1.05}
    /* normalize subtitle whether it's plain text or already wrapped in a p tag */
    .site-hero .site-hero-subtitle,
    .site-hero .site-hero-subtitle p{
      margin:var(--hero-subtitle-margin-top) 0 0;
      color:rgba(248,249,250,0.95);
      font-size:var(--hero-subtitle-size);
      line-height:var(--hero-subtitle-line-height);
      max-width:820px;
    }

    /* reduce height and switch to natural flow on smaller screens */
    @media (max-width:900px){
      :root{--hero-height:auto}
      .site-hero{height:auto;padding:18px 0}
      /* reduce icon and title sizes on smaller screens and switch inner layout to flex */
      :root{--hero-icon-size:64px;--hero-icon-slot:88px;--hero-title-size:1.6rem;--hero-subtitle-size:0.98rem}
      .site-hero .site-hero-inner{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px}
      .site-hero .site-hero-icon{width:var(--hero-icon-size);height:var(--hero-icon-size)}
      .site-hero h1{font-size:var(--hero-title-size)}
    }

    /* Reduce gap between hero and the following main/content elements */
    .site-hero + .registration-layout, .site-hero + main, .site-hero + .content, .site-hero + section, .site-hero + .wrap {
      margin-top: 12px !important;
    }

    /* keep an explicit class available for pages that want to tweak placement
       (but it uses the same height now) */
    .site-hero.hero-registration{
      /* no height override — uses --hero-height */
      padding-top:0;
      padding-bottom:0;
    }
  </style>
</section>

