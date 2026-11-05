<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post('website') !== '') {                       // honeypot: bots fill this
        redirect(app_url('/#contact'));
    }
    if (rate_limited('enquiry', 3, 600)) {
        $error = 'Too many messages sent. Please try again in a few minutes.';
    } else {
        $name = mb_substr(post('name'), 0, 120);
        $phone = mb_substr(post('phone'), 0, 30);
        $email = mb_substr(post('email'), 0, 160);
        $msg = mb_substr(post('message'), 0, 2000);
        if ($name === '' || $msg === '' || !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
            $error = 'Please enter your name, a valid phone number and a message.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } else {
            db()->prepare('INSERT INTO enquiries (name, phone, email, message, ip_address) VALUES (?,?,?,?,?)')
                ->execute([$name, $phone, $email ?: null, $msg, client_ip()]);
            $sent = true;
        }
    }
}

$pageTitle = APP_NAME . ' | Compassionate, Patient-Focused Healthcare';
$pageDesc  = APP_NAME . ' provides compassionate, patient-focused healthcare in Zimbabwe, including consultations, laboratory testing, reproductive health, antenatal care, and other clinic services.';
$canonical = clinic_public_site_url();
$socialImage = $canonical !== '' ? $canonical . 'assets/img/social-card.png' : '';
$clinicWhatsappUrl = 'https://wa.me/' . preg_replace('/\D+/', '', CLINIC_PHONE)
    . '?text=' . rawurlencode('Goodday, welcome to Good Samaritan. How can we help you?');
$clinicSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'MedicalClinic',
    'name' => APP_NAME,
    'telephone' => CLINIC_PHONE,
    'areaServed' => ['@type' => 'Country', 'name' => 'Zimbabwe'],
];
if ($canonical !== '') {
    $clinicSchema['url'] = $canonical;
    $clinicSchema['logo'] = $canonical . 'assets/img/logo.png';
    $clinicSchema['image'] = $socialImage;
}
if (CLINIC_EMAIL !== '') {
    $clinicSchema['email'] = CLINIC_EMAIL;
}
if (CLINIC_ADDRESS !== '') {
    $clinicSchema['address'] = [
        '@type' => 'PostalAddress',
        'streetAddress' => CLINIC_ADDRESS,
        'addressCountry' => 'ZW',
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta name="robots" content="index, follow">
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<meta name="theme-color" content="#062b83">
<link rel="icon" type="image/png" href="<?= e(app_asset('/assets/img/logo.png')) ?>">

<meta property="og:type" content="website">
<meta property="og:locale" content="en_ZW">
<meta property="og:site_name" content="<?= e(APP_NAME) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<?php if ($canonical !== ''): ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($socialImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e($pageDesc) ?>">
<?php if ($canonical !== ''): ?><meta name="twitter:image" content="<?= e($socialImage) ?>"><?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(app_asset('/assets/css/style.css')) ?>">

<script type="application/ld+json">
<?= json_encode($clinicSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>
</head>
<body id="top">
<a class="skip" href="#main">Skip to content</a>

<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= e(app_url('/#top')) ?>" aria-label="<?= e(APP_NAME) ?> home">
      <img src="<?= e(app_asset('/assets/img/logo.png')) ?>" alt="" width="44" height="44">
      <span><?= e(APP_NAME) ?></span>
    </a>
    <button class="menu-toggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="site-nav">&#9776;</button>
    <nav class="nav" id="site-nav" aria-label="Primary">
      <a href="#services">Services</a>
      <a href="#journey">Your Visit</a>
      <a href="#faq">FAQs</a>
      <a href="#about">About</a>
      <a href="#contact">Contact</a>
      <a class="btn btn-sm" href="<?= e(app_url('/login.php')) ?>">Staff Login</a>
    </nav>
  </div>
</header>

<main id="main">
  <section class="hero">
    <img class="hero-logo" src="<?= e(app_asset('/assets/img/logo.png')) ?>" alt="" aria-hidden="true">
    <div class="container">
      <span class="eyebrow">CARING &bull; PROFESSIONAL &bull; TRUSTED</span>
      <h1>Healing begins with care.</h1>
      <p class="lead"><?= e(APP_NAME) ?> is committed to a welcoming healthcare experience where patients and families receive attentive, respectful and quality-focused care.</p>
      <div class="actions">
        <a class="btn" href="#contact">Contact the centre</a>
        <a class="btn btn-ghost" href="#services">Explore services</a>
      </div>
      <div class="hero-stats">
        <div><b>Patient-first care</b><span>Respectful interactions</span></div>
        <div><b>Professional service</b><span>Quality-focused care</span></div>
        <div><b>Convenient support</b><span>Call or WhatsApp</span></div>
      </div>
    </div>
  </section>

  <section class="block alt" id="services" aria-labelledby="services-h">
    <div class="container">
      <div class="section-title reveal">
        <h2 id="services-h">Our services</h2>
        <p>Service availability can vary. Contact the centre to confirm details before visiting.</p>
      </div>
      <div class="grid grid-3">
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129658;</div><h3>Medical consultation</h3><p>Professional consultations in a supportive environment with clear communication.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129514;</div><h3>Laboratory &amp; diagnostics</h3><p>Diagnostic support as part of a coordinated patient-care experience.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#128138;</div><h3>Pharmacy support</h3><p>Medication support focused on guidance, convenience and responsible service.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#128100;</div><h3>Patient services</h3><p>Reception and patient guidance to help you navigate your visit.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#127793;</div><h3>Reproductive health</h3><p>Family-planning and reproductive-health support with privacy and respect.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129328;</div><h3>ANC &amp; postnatal care</h3><p>Support for mothers through antenatal and postnatal stages.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129300;</div><h3>Pregnancy &amp; lab tests</h3><p>Pregnancy testing and selected laboratory services.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129440;</div><h3>TB, HIV &amp; STI testing</h3><p>Confidential testing and guidance for infectious and sexual-health needs.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129463;</div><h3>Dental services</h3><p>Dental care and guidance to support oral health and wellbeing.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#129461;</div><h3>Rehabilitation</h3><p>Support focused on recovery, function and quality of life.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#128172;</div><h3>Counselling</h3><p>A respectful setting for counselling and supportive guidance.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#128065;</div><h3>Eye care &amp; optometry</h3><p>Eye-care support and optometry services for vision and eye health.</p></article>
        <article class="card reveal"><div class="icon" aria-hidden="true">&#128567;</div><h3>COVID-19 screening</h3><p>Antigen screening and PCR sample collection, subject to availability.</p></article>
      </div>
      <p class="service-enquiry"><a class="btn btn-dark" href="<?= e($clinicWhatsappUrl) ?>" target="_blank" rel="noopener noreferrer">Ask about service availability on WhatsApp</a></p>
    </div>
  </section>

  <section class="block" id="journey" aria-labelledby="journey-h">
    <div class="container">
      <div class="section-title reveal">
        <h2 id="journey-h">Your visit, step by step</h2>
        <p>Contact the centre first to confirm the service and guidance for your visit.</p>
      </div>
      <ol class="steps">
        <li class="step reveal"><h3>Reach out</h3><p>Call or WhatsApp with your question or service enquiry.</p></li>
        <li class="step reveal"><h3>Discuss your need</h3><p>Tell the centre which service or information you need.</p></li>
        <li class="step reveal"><h3>Visit the centre</h3><p>Follow the guidance provided and arrive ready for your visit.</p></li>
        <li class="step reveal"><h3>Receive support</h3><p>Get respectful, patient-focused care from the healthcare team.</p></li>
      </ol>
    </div>
  </section>

  <section class="block alt" id="faq" aria-labelledby="faq-h">
    <div class="container">
      <div class="section-title reveal">
        <h2 id="faq-h">Helpful information</h2>
        <p>Contact the centre directly to confirm current service availability and requirements.</p>
      </div>
      <div class="faq-list">
        <details class="faq-item"><summary>How can I contact Good Samaritan Medical Center?</summary><p>Call <a href="tel:<?= e(preg_replace('/\s+/', '', CLINIC_PHONE)) ?>"><?= e(CLINIC_PHONE) ?></a> or use WhatsApp to send an enquiry.</p></details>
        <details class="faq-item"><summary>What services are available?</summary><p>The centre lists medical consultation, laboratory and diagnostic support, reproductive health, antenatal and postnatal care, pregnancy and lab tests, TB/HIV/STI testing, dental services, rehabilitation, counselling, eye care and COVID-19 screening. Availability may vary.</p></details>
        <details class="faq-item"><summary>Should I confirm a service before visiting?</summary><p>Yes. Contact the centre first if you need to confirm availability, testing requirements or visit guidance.</p></details>
      </div>
    </div>
  </section>

  <section class="block alt" id="about" aria-labelledby="about-h">
    <div class="container container-narrow">
      <div class="section-title reveal">
        <h2 id="about-h">About <?= e(APP_NAME) ?></h2>
      </div>
      <p class="reveal about-copy">Our approach is centered on listening to patients, communicating clearly and providing a respectful environment for healthcare needs. We aim to make every visit feel organised, caring and professional.</p>
      <div class="grid grid-2">
        <article class="card reveal"><span class="eyebrow-label">Our mission</span><h3>Serve with compassion</h3><p>To provide accessible, respectful and patient-centered healthcare while treating every person with dignity, empathy and professionalism.</p></article>
        <article class="card reveal"><span class="eyebrow-label">Our vision</span><h3>A healthier community</h3><p>To be a trusted healthcare center known for compassionate service, responsible practice and a positive experience for patients and families.</p></article>
      </div>
      <h3 class="values-heading">Principles that guide our care</h3>
      <div class="values-list" aria-label="Our values">
        <span>Compassion</span><span>Integrity</span><span>Excellence</span><span>Respect</span><span>Teamwork</span><span>Community</span>
      </div>
    </div>
  </section>

  <section class="block" id="contact" aria-labelledby="contact-h">
    <div class="container container-medium">
      <div class="section-title reveal">
        <h2 id="contact-h">Contact &amp; enquiries</h2>
        <p>Call or WhatsApp to ask about services, requirements and availability.</p>
      </div>
      <div class="grid services-grid">
        <div class="card reveal">
          <h3>Contact the centre</h3>
          <p><strong>Phone</strong><br><a href="tel:<?= e(preg_replace('/\s+/', '', CLINIC_PHONE)) ?>"><?= e(CLINIC_PHONE) ?></a></p>
          <p><a class="btn btn-dark" href="<?= e($clinicWhatsappUrl) ?>" target="_blank" rel="noopener noreferrer">Start WhatsApp chat</a></p>
          <?php if (CLINIC_ADDRESS !== ''): ?><p><strong>Address</strong><br><?= e(CLINIC_ADDRESS) ?></p><?php endif; ?>
          <?php if (CLINIC_EMAIL !== ''): ?><p><strong>Email</strong><br><a href="mailto:<?= e(CLINIC_EMAIL) ?>"><?= e(CLINIC_EMAIL) ?></a></p><?php endif; ?>
        </div>
        <form class="card reveal" method="post" action="<?= e(app_url('/#contact')) ?>" novalidate>
          <?php if ($sent): ?><div class="alert alert-success" role="status">Thank you. We will contact you shortly.</div><?php endif; ?>
          <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
          <?= csrf_field() ?>
          <div class="hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
          <p>For a quicker response, call or use the WhatsApp link.</p>
          <div class="form-grid">
            <div class="full"><label class="req" for="name">Full name</label><input id="name" name="name" maxlength="120" required autocomplete="name"></div>
            <div><label class="req" for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="30" required autocomplete="tel"></div>
            <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="160" autocomplete="email"></div>
            <div class="full"><label class="req" for="message">How can we help?</label><textarea id="message" name="message" maxlength="2000" required></textarea></div>
            <div class="full"><button class="btn btn-dark" type="submit">Send message</button></div>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div><h3><?= e(APP_NAME) ?></h3><p>Compassionate, professional healthcare for the whole family.</p></div>
      <div><h3>Quick links</h3><p><a href="#services">Services</a><br><a href="#journey">Your visit</a><br><a href="#contact">Contact</a><br><a href="<?= e(app_url('/login.php')) ?>">Staff login</a></p></div>
      <div><h3>Reach us</h3><p><a href="tel:<?= e(preg_replace('/\s+/', '', CLINIC_PHONE)) ?>"><?= e(CLINIC_PHONE) ?></a><br><a href="<?= e($clinicWhatsappUrl) ?>" target="_blank" rel="noopener noreferrer">WhatsApp us</a><?php if (CLINIC_EMAIL !== ''): ?><br><a href="mailto:<?= e(CLINIC_EMAIL) ?>"><?= e(CLINIC_EMAIL) ?></a><?php endif; ?></p></div>
    </div>
    <p class="copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Patient information is kept confidential.</p>
    <p class="copy"><a href="https://wa.me/263782822150" target="_blank" rel="noopener noreferrer">Developed by Raymond Chingururu</a></p>
  </div>
</footer>
<nav class="public-mobile-actions" aria-label="Contact the centre">
  <a href="tel:<?= e(preg_replace('/\s+/', '', CLINIC_PHONE)) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/></svg>
    <span>Call</span>
  </a>
  <a class="public-mobile-whatsapp" href="<?= e($clinicWhatsappUrl) ?>" target="_blank" rel="noopener noreferrer">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-4.7A8.5 8.5 0 1 1 20.5 11.5Z"/><path d="M8.5 8.2c.2-.5.4-.5.7-.5h.5c.2 0 .4.1.5.4l.7 1.6c.1.2.1.4-.1.6l-.5.6c-.2.2-.2.4 0 .6.5.9 1.3 1.6 2.2 2 .2.1.4.1.6-.1l.7-.8c.2-.2.4-.2.6-.1l1.5.7c.3.1.4.3.4.5 0 .4-.2 1.2-.7 1.6-.5.4-1.1.6-1.8.4-1-.2-2.3-.8-3.6-2-1.1-1-2-2.4-2.2-3.4-.2-.9.1-1.7.5-2.1Z"/></svg>
    <span>WhatsApp</span>
  </a>
  <a href="#contact">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="m4 7 8 6 8-6"/></svg>
    <span>Enquire</span>
  </a>
</nav>
<a class="to-top" href="#top" aria-label="Back to top">&uarr;</a>
<script src="<?= e(app_asset('/assets/js/main.js')) ?>" defer></script>
</body>
</html>
