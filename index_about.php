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
    <link rel="stylesheet" href="home.css?v=16">
    <link rel="stylesheet" href="frontend_polish.css?v=16">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="index.css?v=22">
<script src="frontend_polish.js?v=15" defer></script>
</head>
<body class="public-index-page public-about-page">
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

    <main>
        <section class="search-hero welcome-area public-about-hero">
            <div class="welcome-inner centered-hero">
                <span class="welcome-badge stagger-1"><span class="badge-dot"></span>Public service, clearly connected</span>
                <h1 class="welcome-title stagger-2">About <span class="welcome-highlight">BENEPESO</span></h1>
                <p class="centered-text stagger-3">The official digital service path of PESO Vinzons for discovering programs, submitting accurate applications, and following recorded beneficiary updates.</p>
                <div class="public-hero-actions stagger-4">
                    <a class="btn-main" href="index.php#available-programs">Explore Open Programs</a>
                </div>
            </div>
        </section>

        <section class="public-about-overview content-wrap reveal" aria-labelledby="publicAboutTitle">
            <div class="public-about-heading">
                <span>Built around resident needs</span>
                <h2 id="publicAboutTitle">One reliable path to PESO services</h2>
                <p>BENEPESO supports the Public Employment Service Office of Vinzons by organizing program information, beneficiary applications, validation, and official next steps in one secure platform.</p>
            </div>

            <div class="public-about-grid">
                <article><span>01</span><h3>Discover</h3><p>Review approved programs, schedules, venues, available slots, eligibility rules, and documentary requirements.</p></article>
                <article><span>02</span><h3>Apply accurately</h3><p>Use one registered profile to submit complete information for the exact program batch you select.</p></article>
                <article><span>03</span><h3>Follow official updates</h3><p>Track PESO validation, requirements received, program participation, and the next recorded action.</p></article>
            </div>
        </section>

        <section class="public-about-standards reveal" aria-labelledby="serviceStandardsTitle">
            <div class="content-wrap public-about-standards-inner">
                <div>
                    <span class="section-kicker">Public service standards</span>
                    <h2 id="serviceStandardsTitle">What residents can expect</h2>
                </div>
                <ul>
                    <li><strong>Clear information</strong><span>Program details are published from approved PESO records.</span></li>
                    <li><strong>Privacy-aware access</strong><span>Personal records remain protected behind authenticated services.</span></li>
                    <li><strong>Human validation</strong><span>Automated checks remain preliminary until authorized PESO review.</span></li>
                    <li><strong>Actionable guidance</strong><span>Recorded statuses explain what happened and what to do next.</span></li>
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
                <a href="mailto:lguvinzonspeso@gmail.com">Email PESO</a>
                <a href="tel:+639479971186">Call +63 947 997 1186</a>
                <a href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer">PESO Vinzons on Facebook</a>
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
                <a class="footer-contact-link" href="mailto:lguvinzonspeso@gmail.com"><span>lguvinzonspeso@gmail.com</span></a>
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
    }

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
