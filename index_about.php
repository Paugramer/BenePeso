<?php
require_once __DIR__ . '/auth_session.php';

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
        exit();
    }
    if ($_SESSION['role'] === 'peso_staff') {
        header('Location: peso_staff_dashboard.php');
        exit();
    }
    if ($_SESSION['role'] === 'user') {
        header('Location: about.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Learn how BENEPESO supports transparent and accessible PESO Vinzons program discovery, applications, and beneficiary services.">
    <meta name="theme-color" content="#176b49">
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <title>About BENEPESO | PESO Vinzons</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="home.css?v=17">
    <link rel="stylesheet" href="frontend_polish.css?v=20260921">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="index.css?v=28">
    <link rel="stylesheet" href="beneficiary_mobile.css?v=10">
<script src="frontend_polish.js?v=20260921" defer></script>
</head>
<body class="public-index-page public-about-page">
<a class="public-skip-link" href="#mainContent">Skip to main content</a>
<div class="page-wrap">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand-area" href="index.php">
                <img class="brand-logo" src="img/pesologo.png" alt="PESO Vinzons logo" onerror="this.style.display='none'">
                <div class="brand-name">
                    <div class="brand-title">BENEPESO</div>
                    <div class="brand-subtitle">PESO Vinzons</div>
                </div>
            </a>

            <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu" aria-controls="menuArea" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>

            <nav class="menu-area" id="menuArea" aria-label="Public navigation">
                <a class="menu-item" href="index.php">Home</a>
                <a class="menu-item" href="index.php#available-programs">Programs</a>
                <a class="menu-item active" href="index_about.php" aria-current="page">About</a>
                <a class="btn-login" href="login.php"><svg class="public-login-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path></svg><span>Login / Register</span></a>
            </nav>
        </div>
    </header>

    <main id="mainContent">
        <section class="search-hero welcome-area public-about-hero" aria-labelledby="aboutHeroTitle">
            <div class="public-hero-bubbles" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
            <div class="welcome-inner public-about-hero-inner">
                <div class="public-about-hero-copy">
                    <span class="welcome-badge stagger-1"><span class="badge-dot"></span>Public service, clearly connected</span>
                    <h1 class="welcome-title stagger-2" id="aboutHeroTitle">A clearer path to <span class="welcome-highlight">PESO services.</span></h1>
                    <p class="centered-text stagger-3">BENEPESO connects residents to official program information, secure applications, and understandable updates from PESO Vinzons.</p>
                    <div class="public-hero-actions stagger-4">
                        <a class="btn-main" href="index.php#available-programs">Explore Open Programs</a>
                        <a class="btn-quiet" href="#how-benepeso-helps">How It Helps</a>
                    </div>
                </div>

                <aside class="public-about-identity stagger-3" aria-label="BENEPESO service overview">
                    <div class="public-about-seal"><img src="img/pesologo.png" alt="PESO Vinzons official seal"></div>
                    <span>BENEPESO</span>
                    <strong>Beneficiary Profiling, Eligibility, and Verification System</strong>
                    <div>
                        <span><b>3</b><small>Core programs</small></span>
                        <span><b>1</b><small>Resident profile</small></span>
                        <span><b>Official</b><small>PESO updates</small></span>
                    </div>
                </aside>
            </div>
        </section>

        <section class="public-trust-strip" aria-label="BENEPESO service assurances">
            <div class="content-wrap">
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg><b>Official information</b><small>Approved PESO program records</small></span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><b>Privacy-aware</b><small>Authenticated resident services</small></span>
                <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 3-6.2"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg><b>Human-validated</b><small>Reviewed by authorized PESO staff</small></span>
            </div>
        </section>

        <section class="public-about-overview content-wrap reveal" id="how-benepeso-helps" aria-labelledby="publicAboutTitle">
            <div class="public-about-heading">
                <span>Built around resident needs</span>
                <h2 id="publicAboutTitle">One reliable path to PESO services</h2>
                <p>BENEPESO supports the Public Employment Service Office of Vinzons by organizing program information, beneficiary applications, validation, and official next steps in one secure platform.</p>
            </div>

            <div class="public-about-grid" aria-label="BENEPESO resident service journey">
                <article><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8 11h6M11 8v6"/></svg></span><div><small>EXPLORE</small><h3>Discover</h3><p>Review approved programs, schedules, venues, available slots, eligibility rules, and documentary requirements.</p></div></article>
                <article><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4M9 12h6M9 16h4"/></svg></span><div><small>PREPARE</small><h3>Apply accurately</h3><p>Use one registered profile to submit complete information for the exact program batch you select.</p></div></article>
                <article><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 3-6.2"/><path d="M4 4v5h5M9 12l2 2 4-5"/></svg></span><div><small>TRACK</small><h3>Follow official updates</h3><p>Track PESO validation, requirements received, program participation, and the next recorded action.</p></div></article>
            </div>
        </section>

        <section class="public-about-purpose reveal" aria-labelledby="purposeTitle">
            <div class="content-wrap public-about-purpose-inner">
                <div class="public-purpose-visual" aria-hidden="true">
                    <span class="purpose-ring purpose-ring-one"></span>
                    <span class="purpose-ring purpose-ring-two"></span>
                    <span class="purpose-center"><img src="img/pesologo.png" alt=""><b>BENEPESO</b></span>
                    <span class="purpose-program purpose-program-tupad"><i class="program-symbol program-symbol--tupad"><svg viewBox="0 0 24 24"><path d="M5 18h14M7 15v-2a5 5 0 0 1 10 0v2M9 8.5V7a3 3 0 0 1 6 0v1.5M6 9h12"/></svg></i><b>TUPAD</b></span>
                    <span class="purpose-program purpose-program-spes"><i class="program-symbol program-symbol--spes"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"/></svg></i><b>SPES</b></span>
                    <span class="purpose-program purpose-program-msme"><i class="program-symbol program-symbol--msme"><svg viewBox="0 0 24 24"><path d="M5 10v9h14v-9M4 5h16l1 5a3 3 0 0 1-4 0 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-4 0l1-5Z"/><path d="M9 19v-5h6v5"/></svg></i><b>MSME</b></span>
                    <svg class="purpose-connections" viewBox="0 0 320 320"><path d="M160 160 77 75M160 160l92-70M160 160l76 100"/></svg>
                </div>
                <div class="public-purpose-copy">
                    <span class="section-kicker">Why BENEPESO exists</span>
                    <h2 id="purposeTitle">Less uncertainty. Better-prepared applications.</h2>
                    <p>Residents should not need to guess which information is official, what documents to prepare, or what happens after submitting an application. BENEPESO brings those steps together while keeping final decisions with authorized PESO personnel.</p>
                    <ul>
                        <li>One profile for supported PESO services</li>
                        <li>Requirements shown before applying</li>
                        <li>Recorded status and next-action guidance</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="public-about-standards reveal" aria-labelledby="serviceStandardsTitle">
            <div class="content-wrap public-about-standards-inner">
                <div>
                    <span class="section-kicker">Public service standards</span>
                    <h2 id="serviceStandardsTitle">What residents can expect</h2>
                </div>
                <ul>
                    <li><i aria-hidden="true">01</i><strong>Clear information</strong><span>Program details are published from approved PESO records.</span></li>
                    <li><i aria-hidden="true">02</i><strong>Privacy-aware access</strong><span>Personal records remain protected behind authenticated services.</span></li>
                    <li><i aria-hidden="true">03</i><strong>Human validation</strong><span>Automated checks remain preliminary until authorized PESO review.</span></li>
                    <li><i aria-hidden="true">04</i><strong>Actionable guidance</strong><span>Recorded statuses explain what happened and what to do next.</span></li>
                </ul>
            </div>
        </section>

        <section class="public-about-contact content-wrap reveal" aria-labelledby="publicContactTitle">
            <div>
                <span>Official assistance</span>
                <h2 id="publicContactTitle">Questions about a program?</h2>
                <p>Contact PESO Vinzons for requirement clarification, record corrections, and official schedule confirmation.</p>
            </div>
            <div class="public-about-contact-actions">
                <div class="public-about-next-actions">
                    <a class="is-primary" href="index.php#available-programs">Explore Programs</a>
                    <a href="signup.php">Create an Account</a>
                </div>
                <div class="public-about-help-links">
                    <a href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com" target="_blank" rel="noopener noreferrer">Email PESO</a>
                    <a href="tel:+639479971186">Call +63 947 997 1186</a>
                    <a href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer">Facebook</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="content-wrap footer-grid">
            <div class="footer-brand">
                <img class="footer-logo" src="img/pesologo.png" alt="PESO Vinzons logo" onerror="this.style.display='none'">
                <div class="brand-text-footer">
                    <div class="footer-title">BENEPESO</div>
                    <div class="footer-sub">PESO Vinzons &bull; Beneficiary Profiling &amp; Verification</div>
                </div>
            </div>
            <div class="footer-col">
                <div class="footer-head">Links</div>
                <a href="index.php">Home</a>
                <a href="index.php#available-programs">Programs</a>
                <a href="index_about.php">About</a>
                <a href="login.php">Login / Register</a>
                <a href="privacy_notice.php">Privacy Notice</a>
            </div>
            <div class="footer-col">
                <div class="footer-head">Office</div>
                <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
                <div class="footer-text">Public Employment Service Office (PESO)</div>
                <a class="footer-contact-link" href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com" target="_blank" rel="noopener noreferrer"><span>lguvinzonspeso@gmail.com</span></a>
                <a class="footer-contact-link" href="tel:+639479971186"><span>+63 947 997 1186</span></a>
                <a class="footer-contact-link" href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer"><span>PESO Vinzons on Facebook</span></a>
            </div>
        </div>
        <div class="content-wrap footer-bottom">
            <div class="footer-copy">&copy; <?= date('Y') ?> BENEPESO &bull; PESO Vinzons</div>
            <div class="footer-mini">Republic of the Philippines &bull; Province of Camarines Norte</div>
        </div>
    </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const menuButton = document.getElementById('menuButton');
    const menuArea = document.getElementById('menuArea');
    if (menuButton && menuArea) {
        menuButton.addEventListener('click', function () {
            const isOpen = menuArea.classList.toggle('open');
            menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        menuArea.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menuArea.classList.remove('open');
                menuButton.setAttribute('aria-expanded', 'false');
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menuArea.classList.contains('open')) {
                menuArea.classList.remove('open');
                menuButton.setAttribute('aria-expanded', 'false');
                menuButton.focus();
            }
        });
    }

    const topbar = document.querySelector('.topbar');
    const updateTopbar = function () { topbar?.classList.toggle('is-scrolled', window.scrollY > 12); };
    updateTopbar();
    window.addEventListener('scroll', updateTopbar, { passive: true });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries, activeObserver) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    activeObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        document.querySelectorAll('.reveal').forEach(function (element) { observer.observe(element); });
    } else {
        document.querySelectorAll('.reveal').forEach(function (element) { element.classList.add('active'); });
    }
});
</script>
</body>
</html>
