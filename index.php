<?php
declare(strict_types=1);

// ICE v1.0
// Licensed under the GNU General Public License v3.0 (GPL-3.0).
// https://github.com/MichelleFindlay/ice

/*
  ---------------------------------------------------------------------------
  UNLOCK WORD (server-side)
  ---------------------------------------------------------------------------
  This gate runs entirely in PHP, not in the browser. That matters: the
  word itself, and the values of any locked field, are never sent to the
  browser until the correct word is submitted — unlike a client-side JS
  check, viewing page source or the Network tab reveals nothing extra
  while the page is locked. There is no rate-limiting on guesses, so treat
  this as a privacy screen against a casual glance (e.g. a stranger who
  scans your wristband QR code), not as protection against someone
  deliberately trying to brute-force it.

  The word itself lives in config.php, not in this file — see the comment
  below for why. To change it, edit config.php directly.
*/

/*
  ---------------------------------------------------------------------------
  CONTENT AND CONFIG LIVE IN SEPARATE, GITIGNORED FILES — NOT IN THIS FILE
  ---------------------------------------------------------------------------
  This file (index.php) is just the template: layout, styling, and the
  unlock gate's logic. The data behind it lives in three files loaded
  below, none of which are committed to git:

  - profile-data.php   — everything shown openly on the page (name, DOB,
                          medical conditions, medications, care team,
                          history, etc.). Loaded unconditionally.
  - sensitive-data.php  — home address, phone numbers, NHS/policy number.
                          Only read from disk after the correct unlock word
                          has been submitted — while the page is locked,
                          $sensitive stays an empty array, so there is
                          nothing for the rest of this script to
                          accidentally leak into the page.
  - config.php          — the unlock word itself, plus the URL this page
                           is deployed at. Unlike the two data files above,
                           this one isn't page *content* — it's the secret
                           that gates them, so it gets the same gitignore
                           treatment for a different reason: if it were
                           committed, the unlock word would be readable by
                           anyone with access to the repo, which defeats
                           the point of having one.

  Copy each *.example.php file to the name without ".example" and fill in
  real values. Keeping all of this out of index.php means a future upgrade
  to the template (a new index.php) never overwrites your filled-in
  content or config.
*/

function resolve_data_path(string $realName, string $exampleName): string {
    $real = __DIR__ . '/' . $realName;
    return file_exists($real) ? $real : __DIR__ . '/' . $exampleName;
}

function load_data_file(string $path): array {
    $data = include $path;
    return is_array($data) ? $data : [];
}

$configPath = resolve_data_path('config.php', 'config.example.php');
$config = load_data_file($configPath);
$unlockWord = strtolower((string) ($config['unlockWord'] ?? ''));
$siteUrl = (string) ($config['siteUrl'] ?? '');

ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS'])) {
    ini_set('session.cookie_secure', '1');
}
session_start();

// This page must never be cached — not by the browser's disk cache, its
// back-forward cache, or any proxy in between. That matters most once
// unlocked: the rendered HTML then contains the real address, phone
// numbers, and NHS/policy number, and a cached copy could resurface them
// to whoever uses the browser/device next.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Belt-and-braces alongside robots.txt and the <meta name="robots"> tag
// below: this header is honoured by crawlers even before they fetch or
// parse the HTML, and by ones that ignore robots.txt but still respect
// X-Robots-Tag.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');

$selfUrl = strtok($_SERVER['REQUEST_URI'], '?');

// Post/Redirect/Get: handle the unlock form submission, then redirect so a
// page refresh never resubmits the word.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlockInput'])) {
    $submitted = strtolower(trim((string) $_POST['unlockInput']));
    if ($unlockWord !== '' && hash_equals($unlockWord, $submitted)) {
        $_SESSION['ice_unlocked'] = true;
    } else {
        $_SESSION['ice_unlock_error'] = true;
    }
    header('Location: ' . $selfUrl);
    exit;
}

if (isset($_GET['lock'])) {
    unset($_SESSION['ice_unlocked']);
    header('Location: ' . $selfUrl);
    exit;
}

$unlocked = !empty($_SESSION['ice_unlocked']);
$showUnlockError = !empty($_SESSION['ice_unlock_error']);
unset($_SESSION['ice_unlock_error']);

$profilePath = resolve_data_path('profile-data.php', 'profile-data.example.php');
$sensitivePath = resolve_data_path('sensitive-data.php', 'sensitive-data.example.php');
$photoPath = resolve_data_path('photo.png', 'photo.example.png');

$profile = load_data_file($profilePath);

$sensitive = [];
if ($unlocked) {
    $sensitive = load_data_file($sensitivePath);
}

// "Last updated" is whichever of profile-data.php, sensitive-data.php, or
// photo.png was edited most recently. This only reads filesystem metadata
// (mtime), not file content, so it's safe to check even while the page is
// locked.
$profileMtime = @filemtime($profilePath) ?: 0;
$sensitiveMtime = @filemtime($sensitivePath) ?: 0;
$photoMtime = @filemtime($photoPath) ?: 0;
$lastUpdatedTimestamp = max($profileMtime, $sensitiveMtime, $photoMtime);
$lastUpdatedText = $lastUpdatedTimestamp > 0 ? date('j F Y', $lastUpdatedTimestamp) : 'Unknown';

function ice_esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Looks up a profile field and escapes it. Missing keys render as empty
// rather than erroring, so an older profile-data.php still works against a
// newer template that has added fields.
function pf(array $data, string $key): string {
    return isset($data[$key]) ? ice_esc((string) $data[$key]) : '';
}

// Renders a masked field, or the real value once unlocked. Real values only
// ever reach this function's return value (and thus the response body)
// after $unlocked is true, so a locked page cannot leak them.
function sensitive_span(array $data, string $key, bool $unlocked): string {
    if ($unlocked && !empty($data[$key])) {
        return '<span class="sensitive">' . ice_esc((string) $data[$key]) . '</span>';
    }
    return '<span class="sensitive">•••• unlock to view</span>';
}

function sensitive_tel(array $data, string $key, bool $unlocked): string {
    if ($unlocked && !empty($data[$key])) {
        $val = (string) $data[$key];
        $href = 'tel:' . preg_replace('/\s+/', '', $val);
        return '<a class="phone-link sensitive-link" href="' . ice_esc($href) . '">' . ice_esc($val) . '</a>';
    }
    return '<a class="phone-link sensitive-link" href="#" aria-disabled="true">•••• unlock to call</a>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
<meta name="theme-color" content="#b3261e">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<?php if ($siteUrl !== ''): ?>
<link rel="canonical" href="<?= ice_esc($siteUrl) ?>">
<?php endif; ?>
<title>ICE — Emergency Medical Information</title>
<script>
  // Applied before the CSS paints, so there's no flash of the wrong theme.
  // Purely a display preference — not sensitive, safe to run before the
  // unlock check below.
  (function(){
    try {
      var t = localStorage.getItem('iceTheme');
      if (t === 'light' || t === 'dark') {
        document.documentElement.setAttribute('data-theme', t);
      }
    } catch (e) {}
  })();
</script>
<style>
  /*
    HOW TO EDIT THIS PAGE
    ----------------------
    This file is just the template — don't edit content directly in here.
    All content, including [ ] placeholders and "last updated", lives in
    profile-data.php (non-sensitive) and sensitive-data.php (address, phone
    numbers, NHS/policy number). See the PHP block at the top of this file
    for how those are loaded and how the unlock word gating works.
  */

  :root{
    --ink:#111315;
    --bg:#ffffff;
    --muted:#5b6167;
    --line:#d8dbde;
    --critical-bg:#fdeceb;
    --critical-border:#b3261e;
    --critical-ink:#7a1712;
    --accent:#0b5fff;
    --panel:#f6f7f8;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --ink:#f1f2f3;
      --bg:#121416;
      --muted:#b6bcc2;
      --line:#33383d;
      --critical-bg:#3a1512;
      --critical-border:#ff6b5e;
      --critical-ink:#ffd6d1;
      --accent:#6ea8ff;
      --panel:#1c1f22;
    }
  }
  :root[data-theme="dark"]{
    --ink:#f1f2f3;
    --bg:#121416;
    --muted:#b6bcc2;
    --line:#33383d;
    --critical-bg:#3a1512;
    --critical-border:#ff6b5e;
    --critical-ink:#ffd6d1;
    --accent:#6ea8ff;
    --panel:#1c1f22;
  }

  #themeToggle{
    position:fixed; z-index:60;
    top:.6rem; right:.6rem;
    top:calc(.6rem + env(safe-area-inset-top));
    right:calc(.6rem + env(safe-area-inset-right));
    width:2.75rem; height:2.75rem; border-radius:50%;
    border:1px solid var(--line); background:var(--panel); color:var(--ink);
    font-size:1.2rem; line-height:1; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    padding:0;
  }

  *{box-sizing:border-box;}
  html{-webkit-text-size-adjust:100%; overflow-x:hidden;}
  body{
    margin:0;
    max-width:100vw;
    overflow-x:hidden;
    background:var(--bg);
    color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    font-size:18px;
    line-height:1.45;
    overflow-wrap:anywhere; /* long addresses / NHS numbers never force horizontal scroll */
    padding-bottom:5.5rem; /* room for the fixed unlock bar */
    padding-bottom:calc(5.5rem + env(safe-area-inset-bottom)); /* iPhone home-indicator area */
  }
  h1,h2,h3{line-height:1.2; margin:0 0 .5rem; font-weight:700;}
  a{color:var(--accent);}
  .wrap{max-width:640px; margin:0 auto; padding:0 1rem;}

  /* ---------- Unlock bar (fixed, always reachable) ---------- */
  #unlockBar{
    position:fixed; left:0; right:0; bottom:0; z-index:50;
    background:var(--panel);
    border-top:1px solid var(--line);
    padding:.6rem .75rem;
    padding-bottom:calc(.6rem + env(safe-area-inset-bottom)); /* iPhone home-indicator area */
    display:flex; gap:.5rem; align-items:center;
    flex-wrap:wrap;
  }
  #unlockBar form{display:flex; gap:.5rem; flex:1; min-width:220px;}
  #unlockBar input[type=text]{
    flex:1; font-size:1rem; padding:.7rem .6rem; /* >=44px tall touch target */
    border:1px solid var(--line); border-radius:8px;
    background:var(--bg); color:var(--ink);
  }
  #unlockBar button{
    font-size:1rem; padding:.7rem 1rem; border-radius:8px; /* >=44px tall touch target */
    border:1px solid var(--accent); background:var(--accent); color:#fff;
    cursor:pointer;
  }
  #unlockStatus{font-size:.85rem; color:var(--muted); width:100%;}
  #unlockStatus[data-state="ok"]{color:#1a7a3c;}
  #unlockStatus[data-state="err"]{color:var(--critical-border);}
  #lockAgainBtn{
    display:inline-block; font-size:1rem; padding:.7rem 1rem; border-radius:8px; /* >=44px tall touch target */
    border:1px solid var(--line); background:transparent; color:var(--ink);
    text-decoration:none; cursor:pointer;
    margin-left:auto;
  }

  /* ---------- QR code dialog ---------- */
  #qrDialog{
    border:1px solid var(--line); border-radius:12px; padding:0;
    background:var(--bg); color:var(--ink);
    max-width:min(90vw, 380px); width:100%;
  }
  #qrDialog::backdrop{background:rgba(0,0,0,.5);}
  .qr-dialog-inner{padding:1.1rem 1.1rem 1.3rem; position:relative;}
  .qr-dialog-inner h2{font-size:1.1rem; padding-right:2rem;}
  #qrCloseBtn{
    position:absolute; top:.6rem; right:.6rem;
    width:2.5rem; height:2.5rem; border-radius:50%; /* close to a 44px touch target */
    border:1px solid var(--line); background:var(--panel); color:var(--ink);
    font-size:1.1rem; line-height:1; cursor:pointer;
    display:flex; align-items:center; justify-content:center; padding:0;
  }
  #qrCodeHolder{
    margin:.75rem auto 0; max-width:260px;
    border-radius:8px; overflow:hidden; border:1px solid var(--line);
  }
  #qrCodeHolder svg{display:block; width:100%; height:auto;}
  .qr-url{
    margin-top:.75rem; font-size:.85rem; color:var(--muted);
    word-break:break-all; text-align:center;
  }

  /* ---------- Print card dialog ---------- */
  #printCardDialog{
    border:1px solid var(--line); border-radius:12px; padding:0;
    background:var(--bg); color:var(--ink);
    max-width:min(92vw, 420px); width:100%;
    overflow-x:hidden; /* the card below must never cause sideways scrolling */
  }
  #printCardDialog::backdrop{background:rgba(0,0,0,.5);}
  .print-dialog-inner{padding:1.1rem 1.1rem 1.3rem; position:relative;}
  .print-dialog-inner h2{font-size:1.1rem; padding-right:2rem;}
  #printCardCloseBtn{
    position:absolute; top:.6rem; right:.6rem;
    width:2.5rem; height:2.5rem; border-radius:50%; /* close to a 44px touch target */
    border:1px solid var(--line); background:var(--panel); color:var(--ink);
    font-size:1.1rem; line-height:1; cursor:pointer;
    display:flex; align-items:center; justify-content:center; padding:0;
  }
  .card-unlock-field{margin:.75rem 0 0;}
  .card-unlock-field label{display:block; font-weight:700; font-size:.9rem; margin-bottom:.3rem;}
  .card-unlock-field input{
    width:100%; font-size:1rem; padding:.6rem; /* >=44px tall touch target */
    border:1px solid var(--line); border-radius:8px;
    background:var(--bg); color:var(--ink);
  }
  .card-unlock-warning{font-size:.8rem; color:var(--muted); margin-top:.4rem;}

  /* The card preview is sized in real physical units (a credit card is
     85.6mm x 53.98mm) so what prints matches what's on screen — CSS mm are
     a physical unit, so this is the true size on both paper and screen.
     max-width:100% is just a safety net for phones narrower than the card
     itself; there's deliberately no scale-up transform here, since scaling
     a box visually without resizing the space reserved for it is exactly
     what caused this to overflow into scrollbars before. */
  .card-preview-wrap{
    margin:1.1rem 0 1.5rem;
    display:flex; justify-content:center;
  }
  .ice-card{
    width:85.6mm; max-width:100%; height:53.98mm; box-sizing:border-box;
    border:1px solid #ccc; border-radius:3mm;
    background:#fff; color:#111;
    padding:3mm 3.5mm;
    display:flex; flex-direction:column; gap:.8mm;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  .ice-card-header{display:flex; align-items:baseline; justify-content:space-between; gap:2mm;}
  .ice-logo{display:flex; align-items:center; gap:1mm; font-weight:800; font-size:5mm; color:#b3261e; letter-spacing:.02em;}
  .ice-logo svg{width:5mm; height:5mm; flex-shrink:0;}
  .ice-card-sub{font-size:2.4mm; color:#555; text-align:right;}
  .ice-card-caption{margin:0; font-size:1.8mm; line-height:1.25; color:#333;}
  .ice-card-body{display:flex; align-items:center; gap:3mm;}
  .ice-card-qr{width:17mm; height:17mm; flex-shrink:0;}
  .ice-card-qr svg{display:block; width:100%; height:100%;}
  .ice-card-url{font-size:2.8mm; word-break:break-all; color:#111;}
  .ice-card-unlock{
    border-top:.3mm dashed #ccc; padding-top:1.3mm;
    font-size:2.6mm; color:#111; display:flex; gap:1.5mm; align-items:baseline;
  }
  .ice-card-unlock-label{font-weight:700; flex-shrink:0;}
  .ice-card-unlock-value{flex:1; border-bottom:.3mm solid #999; min-height:3.6mm; word-break:break-all;}
  .ice-card-unlock-value:empty::before{content:"(write in by hand)"; color:#999; font-style:italic;}

  #printCardGoBtn{
    display:block; width:100%; font-size:1rem; padding:.75rem 1rem; border-radius:8px; /* >=44px tall touch target */
    border:1px solid var(--accent); background:var(--accent); color:#fff; cursor:pointer;
  }

  @media print{
    body > *:not(#printCardDialog){display:none !important;}
    #printCardDialog::backdrop{display:none;}
    #printCardDialog{
      position:static; margin:0; padding:0; border:none; max-width:none; width:auto;
      background:none; color:#000;
    }
    .no-print{display:none !important;}
    .card-preview-wrap{margin:0; display:block;}
  }

  /* ---------- Identity header ---------- */
  header.identity{
    padding:.85rem 3.6rem .6rem 1rem; /* right padding clears the fixed theme toggle */
    display:flex; gap:.85rem; align-items:center;
    border-bottom:1px solid var(--line);
  }
  .photo{
    width:64px; height:64px; border-radius:50%;
    object-fit:cover; flex-shrink:0; border:1px solid var(--line);
  }
  .photo-fallback{
    width:64px; height:64px; border-radius:50%;
    background:var(--panel); border:1px solid var(--line);
    display:none; align-items:center; justify-content:center;
    font-weight:700; font-size:1.3rem; color:var(--muted); flex-shrink:0;
  }
  .identity-text{min-width:0;}
  .identity-text h1{font-size:1.25rem;}
  .identity-text .preferred{color:var(--muted); font-size:.95rem;}
  .identity-meta{font-size:.9rem; color:var(--muted); margin-top:.15rem;}

  /* ---------- Critical block ---------- */
  section.critical{
    background:var(--critical-bg);
    border-top:4px solid var(--critical-border);
    border-bottom:4px solid var(--critical-border);
    padding:.9rem 1rem 1.1rem;
    color:var(--critical-ink);
  }
  section.critical h2{
    font-size:1.05rem; text-transform:uppercase; letter-spacing:.03em;
    margin-bottom:.6rem;
  }
  .crit-item{margin-bottom:.7rem;}
  .crit-item:last-child{margin-bottom:0;}
  .crit-label{font-weight:800; display:block; font-size:.85rem; text-transform:uppercase; letter-spacing:.02em; opacity:.85;}
  .crit-value{font-size:1.15rem; font-weight:600;}

  /* ---------- Emergency contacts ---------- */
  section.contacts{padding:1rem;}
  section.contacts h2{font-size:1.05rem; color:var(--muted); text-transform:uppercase; letter-spacing:.03em;}
  .contact-card{
    border:1px solid var(--line); border-radius:10px;
    padding:.75rem .85rem; margin-bottom:.6rem;
  }
  .contact-card .role{font-weight:700; font-size:.8rem; color:var(--muted); text-transform:uppercase; letter-spacing:.02em;}
  .contact-card .name{font-size:1.15rem; font-weight:700;}
  .contact-card .rel{color:var(--muted); font-size:.95rem;}
  .contact-card .phone-link{
    display:inline-block; margin-top:.35rem; font-size:1.1rem; font-weight:700;
    text-decoration:none; border:1px solid var(--accent); border-radius:8px;
    padding:.35rem .7rem;
  }
  a.phone-link[aria-disabled="true"]{
    pointer-events:none; opacity:.6; border-color:var(--line); color:var(--muted);
  }

  /* ---------- Generic sections / details ---------- */
  section.block{padding:1rem; border-top:1px solid var(--line);}
  section.block h2{font-size:1.05rem;}
  details{border-top:1px solid var(--line); padding:.9rem 1rem;}
  details:last-of-type{border-bottom:1px solid var(--line);}
  summary{font-weight:700; cursor:pointer; font-size:1.05rem; padding:.4rem 0;}
  summary:focus-visible{outline:2px solid var(--accent);}
  dl{margin:.6rem 0 0;}
  dt{font-weight:800; margin-top:.6rem; font-size:.9rem; color:var(--muted); text-transform:uppercase; letter-spacing:.02em;}
  dt:first-child{margin-top:0;}
  dd{margin:.1rem 0 0;}
  .table-scroll{overflow-x:auto; -webkit-overflow-scrolling:touch; margin-top:.6rem;}
  table{width:100%; min-width:420px; border-collapse:collapse; font-size:.95rem;}
  th,td{text-align:left; padding:.4rem .3rem; border-bottom:1px solid var(--line); vertical-align:top;}
  th{color:var(--muted); font-size:.8rem; text-transform:uppercase; letter-spacing:.02em;}

  .sensitive, .sensitive-link{
    background:var(--panel); border:1px dashed var(--line); border-radius:6px;
    padding:.05rem .4rem; cursor:default; font-weight:600;
  }
  .sensitive::before, .sensitive-link::before{content:"🔒 ";}
  body.unlocked .sensitive, body.unlocked .sensitive-link{
    background:transparent; border-style:solid; font-weight:700;
  }
  body.unlocked .sensitive::before, body.unlocked .sensitive-link::before{content:"";}

  footer{padding:1.2rem 1rem 1.5rem; color:var(--muted); font-size:.85rem;}
  footer strong{color:var(--ink);}
  .footerActions{display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.9rem;}
  .footerActions button{
    font-size:1rem; padding:.7rem 1rem; border-radius:8px; /* >=44px tall touch target */
    border:1px solid var(--line); background:transparent; color:var(--ink);
    cursor:pointer;
  }

  .visually-hidden{
    position:absolute; width:1px; height:1px; padding:0; margin:-1px;
    overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0;
  }

  @media print{
    #unlockBar{display:none;}
    body{padding-bottom:0;}
  }
</style>
</head>
<body class="<?= $unlocked ? 'unlocked' : '' ?>">

  <button type="button" id="themeToggle" aria-label="Switch between light and dark theme">🌙</button>
  <script>
  (function(){
    var root = document.documentElement;
    var btn = document.getElementById('themeToggle');

    function isDark(){
      var explicit = root.getAttribute('data-theme');
      if (explicit === 'dark') { return true; }
      if (explicit === 'light') { return false; }
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    function updateIcon(){
      btn.textContent = isDark() ? '☀' : '🌙';
    }

    updateIcon();
    btn.addEventListener('click', function(){
      var next = isDark() ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('iceTheme', next); } catch (e) {}
      updateIcon();
    });
  })();
  </script>

  <header class="identity">
    <img class="photo" src="photo.png?v=<?= $photoMtime ?>" alt="Photo of <?= pf($profile, 'fullName') ?>"
         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
    <div class="photo-fallback" aria-hidden="true"><?= pf($profile, 'initials') ?></div>
    <div class="identity-text">
      <h1><?= pf($profile, 'fullName') ?></h1>
      <?php
        $preferredBits = [];
        if (!empty($profile['preferredName'])) { $preferredBits[] = '<strong>Preferred name:</strong> ' . pf($profile, 'preferredName'); }
        if (!empty($profile['pronouns'])) { $preferredBits[] = '<strong>Pronouns:</strong> ' . pf($profile, 'pronouns'); }
      ?>
      <?php if ($preferredBits): ?>
      <div class="preferred"><?= implode(' · ', $preferredBits) ?></div>
      <?php endif; ?>
      <?php
        $dobLangBits = [];
        if (!empty($profile['dob'])) { $dobLangBits[] = '<strong>DOB:</strong> ' . pf($profile, 'dob'); }
        if (!empty($profile['language'])) { $dobLangBits[] = '<strong>Speaks:</strong> ' . pf($profile, 'language'); }
      ?>
      <?php if ($dobLangBits): ?>
      <div class="identity-meta"><?= implode(' · ', $dobLangBits) ?></div>
      <?php endif; ?>
      <div class="identity-meta">
        <strong>Home address:</strong>
        <?= sensitive_span($sensitive, 'address', $unlocked) ?>
      </div>
    </div>
  </header>

  <section class="critical" aria-label="Critical medical information">
    <h2>⚠ Critical medical information</h2>

    <?php if (!empty($profile['majorConditions'])): ?>
    <div class="crit-item">
      <span class="crit-label">Major conditions</span>
      <span class="crit-value"><?= pf($profile, 'majorConditions') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['allergies'])): ?>
    <div class="crit-item">
      <span class="crit-label">Severe allergies</span>
      <span class="crit-value"><?= pf($profile, 'allergies') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['currentMedsSummary'])): ?>
    <div class="crit-item">
      <span class="crit-label">Current medications (key ones)</span>
      <span class="crit-value"><?= pf($profile, 'currentMedsSummary') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['bloodType'])): ?>
    <div class="crit-item">
      <span class="crit-label">Blood type</span>
      <span class="crit-value"><?= pf($profile, 'bloodType') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['implantedDevices'])): ?>
    <div class="crit-item">
      <span class="crit-label">Implanted / assistive devices</span>
      <span class="crit-value"><?= pf($profile, 'implantedDevices') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['mriFlags'])): ?>
    <div class="crit-item">
      <span class="crit-label">MRI safety flags</span>
      <span class="crit-value"><?= pf($profile, 'mriFlags') ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['resuscitationStatus'])): ?>
    <div class="crit-item">
      <span class="crit-label">Resuscitation status</span>
      <span class="crit-value"><?= pf($profile, 'resuscitationStatus') ?></span>
    </div>
    <?php endif; ?>
  </section>

  <section class="contacts" aria-label="Emergency contacts">
    <h2>Emergency contacts</h2>

    <?php if (!empty($profile['primaryContactName'])): ?>
    <div class="contact-card">
      <div class="role">Primary ICE contact</div>
      <div class="name"><?= pf($profile, 'primaryContactName') ?></div>
      <?php if (!empty($profile['primaryContactRel'])): ?>
      <div class="rel"><?= pf($profile, 'primaryContactRel') ?></div>
      <?php endif; ?>
      <?= sensitive_tel($sensitive, 'primaryPhone', $unlocked) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['secondaryContactName'])): ?>
    <div class="contact-card">
      <div class="role">Secondary contact</div>
      <div class="name"><?= pf($profile, 'secondaryContactName') ?></div>
      <?php if (!empty($profile['secondaryContactRel'])): ?>
      <div class="rel"><?= pf($profile, 'secondaryContactRel') ?></div>
      <?php endif; ?>
      <?= sensitive_tel($sensitive, 'secondaryPhone', $unlocked) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($profile['nokName'])): ?>
    <div class="contact-card">
      <div class="role">Next of kin / legal proxy</div>
      <div class="name"><?= pf($profile, 'nokName') ?></div>
      <?php if (!empty($profile['nokRel'])): ?>
      <div class="rel"><?= pf($profile, 'nokRel') ?></div>
      <?php endif; ?>
      <?= sensitive_tel($sensitive, 'nokPhone', $unlocked) ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="block" aria-label="Medical care team">
    <h2>Medical care team</h2>
    <dl>
      <?php
        $gpBits = [];
        if (!empty($profile['gpName'])) { $gpBits[] = pf($profile, 'gpName'); }
        if (!empty($profile['gpPractice'])) { $gpBits[] = pf($profile, 'gpPractice'); }
        $gpBits[] = sensitive_span($sensitive, 'gpPhone', $unlocked);
      ?>
      <?php if (!empty($profile['gpName']) || !empty($profile['gpPractice'])): ?>
      <dt>GP / primary doctor</dt>
      <dd><?= implode(' · ', $gpBits) ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['specialists'])): ?>
      <dt>Key specialists</dt>
      <dd><?= pf($profile, 'specialists') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['hospital'])): ?>
      <dt>Preferred / usual hospital</dt>
      <dd><?= pf($profile, 'hospital') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['pharmacy'])): ?>
      <dt>Pharmacy</dt>
      <dd><?= pf($profile, 'pharmacy') ?></dd>
      <?php endif; ?>
    </dl>
  </section>

  <details>
    <summary>Medical history</summary>
    <dl>
      <?php if (!empty($profile['surgeries'])): ?>
      <dt>Past major surgeries</dt>
      <dd><?= pf($profile, 'surgeries') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['chronicConditions'])): ?>
      <dt>Chronic conditions & diagnoses</dt>
      <dd><?= pf($profile, 'chronicConditions') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['hospitalisations'])): ?>
      <dt>Recent hospitalisations</dt>
      <dd><?= pf($profile, 'hospitalisations') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['immunisationNotes'])): ?>
      <dt>Immunisation notes</dt>
      <dd><?= pf($profile, 'immunisationNotes') ?></dd>
      <?php endif; ?>
    </dl>
  </details>

  <details open>
    <summary>Medication detail</summary>
    <?php if (!empty($profile['medications'])): ?>
    <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Medication</th><th>Dose</th><th>Frequency</th></tr>
        </thead>
        <tbody>
          <?php foreach ($profile['medications'] as $med): ?>
          <tr>
            <td><?= ice_esc((string) ($med['name'] ?? '')) ?></td>
            <td><?= ice_esc((string) ($med['dose'] ?? '')) ?></td>
            <td><?= ice_esc((string) ($med['frequency'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <dl>
      <?php if (!empty($profile['stoppedMeds'])): ?>
      <dt>Recently stopped medications</dt>
      <dd><?= pf($profile, 'stoppedMeds') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['drugAllergies'])): ?>
      <dt>Drug allergies (do not administer)</dt>
      <dd><?= pf($profile, 'drugAllergies') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['drugIntolerances'])): ?>
      <dt>Drug intolerances (not a true allergy)</dt>
      <dd><?= pf($profile, 'drugIntolerances') ?></dd>
      <?php endif; ?>
    </dl>
  </details>

  <details>
    <summary>Practical / logistical</summary>
    <dl>
      <dt>Health insurance / NHS number</dt>
      <dd><?= sensitive_span($sensitive, 'nhsNumber', $unlocked) ?></dd>

      <?php if (!empty($profile['organDonorStatus'])): ?>
      <dt>Organ donor status</dt>
      <dd><?= pf($profile, 'organDonorStatus') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['advanceDirectiveLocation'])): ?>
      <dt>Advance directive / living will</dt>
      <dd><?= pf($profile, 'advanceDirectiveLocation') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['communicationNeeds'])): ?>
      <dt>Communication / care needs</dt>
      <dd><?= pf($profile, 'communicationNeeds') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['serviceAnimal'])): ?>
      <dt>Service animal / assistance dog</dt>
      <dd><?= pf($profile, 'serviceAnimal') ?></dd>
      <?php endif; ?>
    </dl>
  </details>

  <details>
    <summary>Situational</summary>
    <dl>
      <?php if (!empty($profile['pregnancyStatus'])): ?>
      <dt>Pregnancy status</dt>
      <dd><?= pf($profile, 'pregnancyStatus') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['dietaryReligious'])): ?>
      <dt>Dietary / religious considerations affecting treatment</dt>
      <dd><?= pf($profile, 'dietaryReligious') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['recentTravel'])): ?>
      <dt>Recent travel</dt>
      <dd><?= pf($profile, 'recentTravel') ?></dd>
      <?php endif; ?>

      <?php if (!empty($profile['dependents'])): ?>
      <dt>Dependents at home needing care if hospitalised</dt>
      <dd><?= pf($profile, 'dependents') ?></dd>
      <?php endif; ?>
    </dl>
  </details>

  <footer>
    <div><strong>Last updated:</strong> <?= ice_esc($lastUpdatedText) ?>.</div>
    <div style="margin-top:.4rem;">This page shows critical medical information openly, with no login, so first responders can act on it immediately. Only your address, phone numbers and ID numbers are gated behind the unlock word below.</div>
    <div class="footerActions">
      <button type="button" id="qrBtn">QR code generator</button>
      <button type="button" id="printCardBtn">Print card</button>
    </div>
    <div style="margin-top:.6rem;">
      ICE v1.0 — <a href="https://github.com/MichelleFindlay/ice">source on GitHub</a>, licensed under GPL-3.0.
    </div>
  </footer>

  <div id="unlockBar" role="group" aria-label="Unlock sensitive information">
    <?php if ($unlocked): ?>
      <span id="unlockStatus" data-state="ok">Unlocked for this session.</span>
      <a id="lockAgainBtn" href="?lock=1">Lock again</a>
    <?php else: ?>
      <form method="post" action="<?= ice_esc($selfUrl) ?>">
        <label for="unlockInput" class="visually-hidden">Unlock word</label>
        <input type="text" id="unlockInput" name="unlockInput" placeholder="Unlock word" autocomplete="off" autocapitalize="off" spellcheck="false">
        <button type="submit">Unlock</button>
      </form>
      <?php if ($showUnlockError): ?>
        <span id="unlockStatus" data-state="err">Incorrect word — try again.</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <dialog id="qrDialog" aria-labelledby="qrDialogTitle">
    <div class="qr-dialog-inner">
      <button type="button" id="qrCloseBtn" aria-label="Close QR code window">✕</button>
      <h2 id="qrDialogTitle">QR code for this page</h2>
      <div id="qrCodeHolder"></div>
      <p class="qr-url" id="qrUrlText"></p>
    </div>
  </dialog>

  <dialog id="printCardDialog" aria-labelledby="printCardDialogTitle">
    <div class="print-dialog-inner">
      <button type="button" id="printCardCloseBtn" class="no-print" aria-label="Close print card window">✕</button>
      <h2 id="printCardDialogTitle" class="no-print">Printable wallet card</h2>

      <!--
        The unlock word itself never reaches this page from the server (see
        the "UNLOCK WORD" comment at the top of this file) — so the only way
        to put it on the card is for the person to type it in themselves,
        here. That value never leaves this field: it isn't submitted to the
        server or stored anywhere, it just feeds straight into the card
        preview below for printing. Left blank, the card prints with a
        blank line to fill in by hand instead.
      -->
      <div class="card-unlock-field no-print">
        <label for="cardUnlockWord">Unlock word (optional)</label>
        <input type="text" id="cardUnlockWord" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Leave blank to keep it private">
        <div class="card-unlock-warning">Anyone holding this card — or a saved PDF of it — could then unlock your full details. Only fill this in if you're fine with that trade-off.</div>
      </div>

      <div class="card-preview-wrap">
        <div class="ice-card" id="printCardArea">
          <div class="ice-card-header">
            <span class="ice-logo">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 2h4v8h8v4h-8v8h-4v-8H2v-4h8z"/></svg>
              ICE
            </span>
            <span class="ice-card-sub">In case of emergency</span>
          </div>
          <p class="ice-card-caption">Scan the QR code or visit the URL below for medical and contact details. Critical info shows immediately.</p>
          <div class="ice-card-body">
            <div class="ice-card-qr" id="printCardQr"></div>
            <div class="ice-card-url" id="printCardUrl"></div>
          </div>
          <p class="ice-card-caption">Address and phone numbers unlock with the word below — for use by emergency responders.</p>
          <div class="ice-card-unlock">
            <span class="ice-card-unlock-label">Unlock word:</span>
            <span class="ice-card-unlock-value" id="printCardUnlockValue"></span>
          </div>
        </div>
      </div>

      <button type="button" id="printCardGoBtn" class="no-print">Print</button>
    </div>
  </dialog>

  <script src="qrcode.lib.js"></script>
  <script>
  (function(){
    // Shared open/close/backdrop-click wiring for the QR and print-card
    // dialogs — both are plain <dialog> popups with the same interaction
    // pattern, just different content.
    function wireDialog(openBtn, dialog, closeBtn, onOpen){
      if (!openBtn || !dialog) { return; }
      openBtn.addEventListener('click', function(){
        if (onOpen) { onOpen(); }
        dialog.showModal();
      });
      if (closeBtn) {
        closeBtn.addEventListener('click', function(){ dialog.close(); });
      }
      dialog.addEventListener('click', function(e){
        if (e.target === dialog) { dialog.close(); }
      });
    }

    var qrCodeHolder = document.getElementById('qrCodeHolder');
    var qrUrlText = document.getElementById('qrUrlText');
    var qrRendered = false;
    wireDialog(document.getElementById('qrBtn'), document.getElementById('qrDialog'), document.getElementById('qrCloseBtn'), function(){
      if (qrRendered) { return; }
      var url = window.location.href;
      var qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      qrCodeHolder.innerHTML = qr.createSvgTag({ scalable: true });
      qrUrlText.textContent = url;
      qrRendered = true;
    });

    var printCardQr = document.getElementById('printCardQr');
    var printCardUrl = document.getElementById('printCardUrl');
    var cardRendered = false;
    wireDialog(document.getElementById('printCardBtn'), document.getElementById('printCardDialog'), document.getElementById('printCardCloseBtn'), function(){
      if (cardRendered) { return; }
      // Drop any query string / hash so a stray "?lock=1" never ends up
      // printed on a card meant to be reused indefinitely.
      var url = window.location.origin + window.location.pathname;
      var qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      printCardQr.innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
      printCardUrl.textContent = url;
      cardRendered = true;
    });

    var cardUnlockWord = document.getElementById('cardUnlockWord');
    var printCardUnlockValue = document.getElementById('printCardUnlockValue');
    if (cardUnlockWord && printCardUnlockValue) {
      cardUnlockWord.addEventListener('input', function(){
        printCardUnlockValue.textContent = cardUnlockWord.value;
      });
    }

    var printCardGoBtn = document.getElementById('printCardGoBtn');
    if (printCardGoBtn) {
      printCardGoBtn.addEventListener('click', function(){ window.print(); });
    }
  })();
  </script>

</body>
</html>
