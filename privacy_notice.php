<?php
session_start();
$backLink = isset($_SESSION['user_id']) ? 'home.php' : 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Privacy Notice | BenePeso</title>
  <link rel="icon" href="img/pesologo.png">
  <style>
    :root { --green:#1f6a49; --green-light:#eaf5ef; --dark:#123d2b; --ink:#22332a; --line:#dce8e1; --gold:#c8a64b; }
    * { box-sizing:border-box; }
    html { scroll-behavior:smooth; }
    body { margin:0; font-family:Arial,Helvetica,sans-serif; color:var(--ink); background:linear-gradient(180deg,#eef6f1 0,#f8fbf9 420px); line-height:1.65; }
    header { position:relative; overflow:hidden; padding:18px 20px 74px; background:linear-gradient(135deg,#103c2a 0%,#1f6a49 72%,#267b58 100%); color:#fff; }
    header::after { content:""; position:absolute; width:420px; height:420px; right:-120px; bottom:-290px; border:1px solid rgba(255,255,255,.12); border-radius:50%; box-shadow:0 0 0 55px rgba(255,255,255,.035),0 0 0 110px rgba(255,255,255,.025); }
    .wrap { width:min(1040px,calc(100% - 32px)); margin:auto; }
    .topbar { position:relative; z-index:1; display:flex; align-items:center; justify-content:space-between; gap:20px; padding:10px 14px; border:1px solid rgba(255,255,255,.18); border-radius:16px; background:rgba(255,255,255,.08); backdrop-filter:blur(8px); }
    .brand { display:flex; align-items:center; gap:12px; color:#fff; text-decoration:none; font-weight:800; letter-spacing:.02em; }
    .brand img { width:42px; height:42px; object-fit:contain; padding:3px; border-radius:50%; background:#fff; }
    .nav-notice { display:flex; align-items:center; gap:9px; padding:9px 14px; border:1px solid rgba(255,255,255,.2); border-radius:999px; background:rgba(7,42,28,.22); color:#fff; font-size:13px; font-weight:700; }
    .nav-notice svg { color:#bfe8d2; }
    .hero { position:relative; z-index:1; display:grid; grid-template-columns:1fr auto; align-items:center; gap:36px; padding:52px 4px 0; }
    .eyebrow { display:flex; align-items:center; gap:8px; margin-bottom:12px; color:#ccebd9; font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
    .eyebrow::before { content:""; width:28px; height:2px; background:var(--gold); }
    h1 { margin:0 0 12px; font-size:clamp(34px,5vw,52px); line-height:1.08; letter-spacing:-.025em; }
    header p { margin:0; max-width:720px; color:#e6f2eb; font-size:17px; line-height:1.6; }
    .hero-icon { display:grid; width:118px; height:118px; place-items:center; border:1px solid rgba(255,255,255,.24); border-radius:30px; background:rgba(255,255,255,.11); color:#fff; box-shadow:0 22px 45px rgba(5,32,21,.18); transform:rotate(3deg); }
    .meta { display:inline-flex; align-items:center; gap:8px; margin-top:20px; padding:7px 11px; border-radius:999px; background:rgba(255,255,255,.1); color:#e1f1e8; font-size:12px; }
    .meta::before { content:""; width:7px; height:7px; border-radius:50%; background:#8ad4aa; }
    main { position:relative; margin:-38px auto 48px; padding:clamp(24px,5vw,52px); border:1px solid var(--line); border-radius:20px; background:#fff; box-shadow:0 18px 50px rgba(20,64,43,.11); }
    h2 { display:flex; align-items:center; gap:11px; margin:34px 0 10px; color:var(--dark); font-size:21px; line-height:1.35; }
    h2::before { content:""; flex:0 0 auto; width:9px; height:9px; border:5px solid var(--green-light); border-radius:50%; background:var(--green); box-shadow:0 0 0 1px #cbe2d5; }
    h2:first-of-type { margin-top:0; }
    main p,main li { color:#46574e; font-size:16px; }
    ul { margin:12px 0 4px; padding:18px 22px 18px 42px; border:1px solid var(--line); border-radius:14px; background:#fbfdfc; }
    li { padding:4px 0 4px 4px; }
    li::marker { color:var(--green); }
    .summary { display:grid; grid-template-columns:auto 1fr; gap:16px; align-items:start; margin:0 0 30px; padding:20px 22px; border:1px solid #d5e8dd; border-radius:14px; background:linear-gradient(135deg,#edf7f1,#f7fbf8); }
    .summary-icon { display:grid; width:44px; height:44px; place-items:center; border-radius:12px; background:var(--green); color:#fff; box-shadow:0 8px 18px rgba(31,106,73,.18); }
    .summary p { margin:0; color:#344b3f; }
    a { color:var(--green); }
    .actions { display:flex; justify-content:center; margin-top:38px; padding-top:26px; border-top:1px solid var(--line); }
    .back { display:inline-flex; align-items:center; gap:9px; padding:12px 20px; border-radius:10px; background:var(--green); color:#fff; text-decoration:none; font-weight:700; box-shadow:0 9px 20px rgba(31,106,73,.2); transition:.2s ease; }
    .back:hover { background:var(--dark); transform:translateY(-1px); }
    @media (max-width:640px) { header{padding-inline:12px}.wrap{width:min(100% - 20px,1040px)}.nav-notice span{display:none}.hero{grid-template-columns:1fr;padding-top:38px}.hero-icon{display:none}header p{font-size:15px}main{padding:25px 20px}.summary{grid-template-columns:1fr} }
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <nav class="topbar" aria-label="Privacy notice navigation">
      <a class="brand" href="<?= htmlspecialchars($backLink, ENT_QUOTES, 'UTF-8') ?>"><img src="img/pesologo.png" alt="PESO Vinzons logo"><span>BENEPESO</span></a>
      <div class="nav-notice"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg><span>Privacy &amp; Data Protection</span></div>
    </nav>
    <div class="hero">
      <div>
        <div class="eyebrow">Official Data Protection Notice</div>
        <h1>Privacy Notice</h1>
        <p>How the Public Employment Service Office (PESO) Vinzons collects, uses, stores, and protects personal data in BenePeso.</p>
        <div class="meta">Notice version: 13 August 2026</div>
      </div>
      <div class="hero-icon" aria-hidden="true"><svg width="62" height="62" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><rect x="8.5" y="10" width="7" height="6" rx="1"/><path d="M10 10V8.5a2 2 0 0 1 4 0V10"/></svg></div>
    </div>
  </div>
</header>

<main class="wrap">
  <div class="summary"><span class="summary-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></span><p>PESO Vinzons processes personal data to register beneficiaries, determine program eligibility, manage applications and assistance, communicate application results, verify records, and prepare required government reports.</p></div>

  <h2>Who is responsible for your information</h2>
  <p>The Municipality of Vinzons, through PESO Vinzons, is the personal information controller for BenePeso. Privacy questions and requests may be submitted in person to PESO Vinzons at the Municipality of Vinzons, Camarines Norte.</p>

  <h2>Information we collect</h2>
  <ul>
    <li>Account and identity details, including name, birth date, sex, civil status, photograph, email address, contact number, and password in secured hashed form.</li>
    <li>Residence details, including street, purok or zone, barangay, municipality, and district.</li>
    <li>Program and eligibility details, which may include government ID information, employment, occupation, income, education, skills, household and dependent information.</li>
    <li>Program-specific information, which may include family circumstances, disability or sector classification, business registration, assets, capital, earnings, and previous government assistance.</li>
    <li>Technical and accountability records, including acknowledgment date, IP address, browser information, login activity, and staff audit logs.</li>
  </ul>

  <h2>Why and how we use it</h2>
  <p>We use information only for declared public-service purposes: account administration, identity and residency verification, assessment of eligibility, application processing, queue and availment management, fraud and duplicate-record prevention, communications, audits, statistics, and reports required for authorized government programs.</p>

  <h2>Lawful basis</h2>
  <p>Processing is based on the functions and legal obligations of the Municipality and PESO, applicable program rules, and—where required—your specific consent. The checkbox shown during registration and application records that this notice was presented and understood; it does not waive any right under the Data Privacy Act of 2012.</p>

  <h2>Who may receive or access it</h2>
  <p>Access is limited to authorized PESO and municipal personnel whose duties require it. Information may be disclosed to DOLE, other competent government agencies, auditors, or authorized service providers when required for the relevant program, reporting obligation, public function, or by law. BenePeso does not sell personal data.</p>

  <h2>Retention and deletion</h2>
  <p>Records are kept only for the period necessary to administer the program, meet government records-retention and audit requirements, resolve claims, and comply with law. When retention is no longer required, records must be securely deleted, anonymized, or disposed of under the Municipality's approved records policy.</p>

  <h2>Protection of your information</h2>
  <p>BenePeso uses role-based access, authenticated accounts, password hashing, activity records, and controlled administrative access. PESO Vinzons also applies appropriate organizational, physical, and technical safeguards according to the sensitivity and risk of the information.</p>

  <h2>Your rights</h2>
  <p>Subject to applicable law, you may ask to be informed, access your personal data, correct inaccurate or incomplete data, object to certain processing, request erasure or blocking, obtain data portability where applicable, withdraw consent when processing depends on consent, and seek damages. You may also lodge a complaint with the National Privacy Commission.</p>

  <h2>Accuracy and other people's information</h2>
  <p>Please provide accurate and current information. If an application asks for information about a parent, dependent, employee, or another person, provide it only when you are authorized to do so and have informed that person of the purpose.</p>

  <h2>Changes to this notice</h2>
  <p>Material changes will be identified by a new notice version and presented at the appropriate collection point. Information will not be used for an incompatible new purpose without the notice or authorization required by law.</p>

  <div class="actions"><a class="back" href="<?= htmlspecialchars($backLink, ENT_QUOTES, 'UTF-8') ?>"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>Return to BenePeso</a></div>
</main>
</body>
</html>
