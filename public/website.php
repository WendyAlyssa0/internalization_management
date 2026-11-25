<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$studentPrograms = [];
$facultyPrograms = [];
$loadError = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.program_name,
            p.program_type,
            p.description,
            p.start_date,
            p.end_date,
            p.status,
            p.partner_id,
            p.country_id,
            p.eligible_account_type,
            c.country_name,
            pr.institution_name
        FROM programs p
        LEFT JOIN countries c
            ON p.country_id = c.id
        LEFT JOIN partners pr
            ON p.partner_id = pr.id
        WHERE p.status IN ('Active', 'Upcoming')
        ORDER BY p.id DESC
    ");

    $stmt->execute();
    $allPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Split into two buckets
    foreach ($allPrograms as $p) {
        $type = strtolower(trim($p['eligible_account_type'] ?? ''));
        if ($type === 'faculty') {
            $facultyPrograms[] = $p;
        } else {
            // 'student' or anything else defaults to student
            $studentPrograms[] = $p;
        }
    }

} catch (PDOException $ex) {
    error_log('browse_program.php query failed: ' . $ex->getMessage());
    $loadError = 'We ran into a problem loading programs. Please try again shortly.';
}

// ---- Helper to build counts for a program list ----
function buildStats(array $programs): array {
    $typeCounts = ['exchange' => 0, 'internship' => 0, 'research' => 0, 'training' => 0];
    $countrySet = [];
    $institutionSet = [];
    foreach ($programs as $p) {
        $t = strtolower($p['program_type'] ?? '');
        if (isset($typeCounts[$t])) $typeCounts[$t]++;
        if (!empty($p['country_name'])) $countrySet[$p['country_name']] = true;
        if (!empty($p['institution_name'])) $institutionSet[$p['institution_name']] = true;
    }
    return [
        'typeCounts'    => $typeCounts,
        'countries'     => array_keys($countrySet),
        'totalPrograms' => count($programs),
        'totalCountries' => count($countrySet),
        'totalInstitutions' => count($institutionSet),
    ];
}

$studentStats = buildStats($studentPrograms);
$facultyStats = buildStats($facultyPrograms);

// Overall totals for the hero
$totalAll = count($studentPrograms) + count($facultyPrograms);
$allCountries = array_unique(array_merge($studentStats['countries'], $facultyStats['countries']));
$totalCountries = count($allCountries);

$allInstitutions = [];
foreach (array_merge($studentPrograms, $facultyPrograms) as $p) {
    if (!empty($p['institution_name'])) $allInstitutions[$p['institution_name']] = true;
}
$totalInstitutions = count($allInstitutions);

// Requirements per audience
$studentRequirements = [
    'Must be a currently enrolled PSU student',
    'Minimum GPA of 2.5 (or equivalent)',
    'Completed application form',
    'Recommendation letter from a faculty member',
];
$facultyRequirements = [
    'Must be a full-time PSU faculty member',
    'Minimum of 2 years teaching experience',
    'Completed application form with department endorsement',
    'Research proposal or teaching plan (for research/exchange programs)',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>PSU Internationalization — Programs Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    /* ===== DESIGN TOKENS ===== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      /* Brand Colors */
      --psu-navy: #0b1a30;
      --psu-navy-light: #162c4d;
      --psu-blue: #1c3d6e;
      --psu-gold: #e4b134;
      --psu-gold-light: #f4c856;
      
      /* Neutrals */
      --bg-main: #f7f9fc;
      --bg-alt: #edf2f8;
      --white: #ffffff;
      --text-main: #0f172a;
      --text-muted: #475569;
      --text-light: #64748b;
      
      /* UI Elements */
      --border-light: #e2e8f0;
      --border-focus: #94a3b8;
      
      /* Radii & Shadows */
      --radius-sm: 8px;
      --radius: 12px;
      --radius-lg: 20px;
      --radius-xl: 30px;
      --radius-full: 9999px;
      
      --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
      --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
      --shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01);
      --shadow-xl: 0 20px 40px -10px rgba(0,0,0,0.08);
      --shadow-search: 0 12px 35px rgba(11, 26, 48, 0.08);
      
      /* Typography */
      --font-heading: 'Plus Jakarta Sans', sans-serif;
      --font-body: 'Inter', sans-serif;
      --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
      font-family: var(--font-body);
      background-color: var(--bg-main);
      color: var(--text-main);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      line-height: 1.5;
    }

    /* ===== CUSTOM SCROLLBAR ===== */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: var(--bg-main); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* ===== NAVIGATION (Refined) ===== */
.nav-wrapper {
  position: fixed;
  top: 20px;
  left: 0;
  width: 100%;
  display: flex;
  justify-content: center;
  z-index: 100;
  padding: 0 24px;
  pointer-events: none;
  transition: top 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Slight lift on scroll */
.nav-wrapper.scrolled { top: 12px; }

nav {
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(16px) saturate(180%);
  -webkit-backdrop-filter: blur(16px) saturate(180%);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px 0 28px;
  height: 68px;
  width: 100%;
  max-width: 1200px;
  border-radius: var(--radius-full);
  box-shadow:
    0 1px 2px rgba(11, 26, 48, 0.04),
    0 8px 24px -4px rgba(11, 26, 48, 0.08),
    inset 0 0 0 1px rgba(11, 26, 48, 0.04);
  pointer-events: auto;
  transition: height 0.3s cubic-bezier(0.4, 0, 0.2, 1),
              box-shadow 0.3s ease;
  position: relative;
}

/* Gold accent line at bottom of nav */
nav::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: 0;
  transform: translateX(-50%);
  width: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--psu-gold), transparent);
  transition: width 0.4s ease;
  border-radius: 2px;
}
nav:hover::after { width: 60%; }

/* Shrink on scroll */
.nav-wrapper.scrolled nav {
  height: 60px;
  box-shadow:
    0 1px 2px rgba(11, 26, 48, 0.06),
    0 12px 32px -6px rgba(11, 26, 48, 0.12),
    inset 0 0 0 1px rgba(11, 26, 48, 0.05);
}

/* Brand Section */
.nav-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  text-decoration: none;
  flex-shrink: 0;
  padding: 4px 0;
}

.nav-logo-badge {
  background: linear-gradient(135deg, var(--psu-navy) 0%, var(--psu-blue) 100%);
  color: var(--white);
  font-family: var(--font-heading);
  font-size: 0.8rem;
  font-weight: 800;
  padding: 9px 13px;
  border-radius: 10px;
  letter-spacing: 0.5px;
  box-shadow: 0 4px 12px -2px rgba(11, 26, 48, 0.3),
              inset 0 1px 0 rgba(255, 255, 255, 0.15);
  position: relative;
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Gold dot accent on badge */
.nav-logo-badge::after {
  content: '';
  position: absolute;
  top: -3px;
  right: -3px;
  width: 9px;
  height: 9px;
  background: var(--psu-gold);
  border-radius: 50%;
  border: 2px solid var(--white);
}

.nav-brand:hover .nav-logo-badge { transform: rotate(-3deg) scale(1.05); }

.nav-divider {
  width: 1px;
  height: 22px;
  background: linear-gradient(to bottom, transparent, var(--border-light), transparent);
}

.nav-title {
  font-family: var(--font-heading);
  color: var(--psu-navy);
  font-size: 1.05rem;
  font-weight: 700;
  letter-spacing: -0.3px;
  white-space: nowrap;
}

/* Links Section */
.nav-links {
  display: flex;
  align-items: center;
  gap: 6px;
  list-style: none;
  margin: 0;
  padding: 0;
  position: relative;
}

.nav-links a.nav-link {
  color: var(--text-muted);
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 600;
  padding: 8px 14px;
  border-radius: var(--radius-full);
  transition: color 0.2s ease, background 0.2s ease;
  white-space: nowrap;
  position: relative;
}

.nav-links a.nav-link::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: 2px;
  transform: translateX(-50%);
  width: 0;
  height: 2px;
  background: var(--psu-gold);
  border-radius: 2px;
  transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-links a.nav-link:hover {
  color: var(--psu-navy);
  background: rgba(11, 26, 48, 0.04);
}

.nav-links a.nav-link:hover::after { width: 16px; }

/* Actions Section */
.nav-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.btn-outline {
  background: transparent;
  color: var(--psu-navy);
  border: 1.5px solid var(--border-light);
  padding: 9px 20px;
  border-radius: var(--radius-full);
  font-family: var(--font-body);
  font-size: 0.86rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.btn-outline:hover {
  border-color: var(--psu-navy);
  background: var(--bg-main);
  transform: translateY(-1px);
}

.btn-solid {
  background: linear-gradient(135deg, var(--psu-gold) 0%, var(--psu-gold-light) 100%);
  color: var(--psu-navy);
  border: none;
  padding: 10px 20px;
  border-radius: var(--radius-full);
  font-family: var(--font-body);
  font-size: 0.86rem;
  font-weight: 700;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  white-space: nowrap;
  box-shadow: 0 4px 14px -2px rgba(228, 177, 52, 0.5);
}

.btn-solid:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 20px -2px rgba(228, 177, 52, 0.65);
  filter: brightness(1.05);
}

.btn-solid svg {
  width: 15px;
  height: 15px;
  transition: transform 0.2s ease;
}

.btn-solid:hover svg { transform: translateX(2px); }

/* Mobile hamburger (hidden by default) */
.nav-hamburger {
  display: none;
  background: var(--bg-main);
  border: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-full);
  cursor: pointer;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 4px;
  transition: background 0.2s ease;
}

.nav-hamburger:hover { background: var(--border-light); }

.nav-hamburger span {
  display: block;
  width: 16px;
  height: 2px;
  background: var(--psu-navy);
  border-radius: 2px;
  transition: all 0.2s ease;
}

/* ===== RESPONSIVE UPDATES FOR NAV ===== */
@media (max-width: 1024px) {
  .nav-links { display: none; }
  .nav-hamburger { display: flex; }
  .btn-outline { display: none; }
}

@media (max-width: 640px) {
  .nav-wrapper { padding: 0 16px; top: 12px; }
  nav { padding: 0 8px 0 18px; height: 60px; }
  .nav-title { font-size: 0.95rem; }
  .btn-solid { padding: 9px 16px; font-size: 0.8rem; }
  .btn-solid svg { display: none; }
}

    /* ===== HERO SECTION ===== */
    .hero {
      position: relative;
      padding: 180px 24px 100px;
      background-color: #f4f7fb;
      background-image: 
        radial-gradient(circle at 15% 50%, rgba(228, 177, 52, 0.05) 0%, transparent 40%),
        radial-gradient(circle at 85% 30%, rgba(28, 61, 110, 0.05) 0%, transparent 40%);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    /* Decorative Background Elements */
    .hero-bg-dots {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background-image: radial-gradient(var(--border-light) 1px, transparent 1px);
      background-size: 24px 24px;
      opacity: 0.4;
      z-index: 0;
      mask-image: linear-gradient(to bottom, black 40%, transparent 100%);
      -webkit-mask-image: linear-gradient(to bottom, black 40%, transparent 100%);
    }
    .hero-globe {
      position: absolute;
      bottom: -150px;
      left: -150px;
      width: 600px;
      height: 600px;
      border-radius: 50%;
      background: radial-gradient(circle at 70% 30%, var(--psu-blue) 0%, var(--psu-navy) 70%);
      box-shadow: inset -20px -20px 60px rgba(0,0,0,0.5), 0 0 80px rgba(28, 61, 110, 0.2);
      z-index: 0;
      opacity: 0.9;
    }
    .hero-globe::after {
      content: '';
      position: absolute;
      inset: -20px;
      border-radius: 50%;
      border: 1px solid rgba(228, 177, 52, 0.3);
    }
    .hero-contour {
      position: absolute;
      bottom: -50px;
      left: -50px;
      width: 800px;
      height: 800px;
      border-radius: 50%;
      border: 1px solid rgba(228, 177, 52, 0.2);
      z-index: 0;
      pointer-events: none;
    }
    .hero-contour-2 {
      position: absolute;
      bottom: -100px;
      left: -100px;
      width: 900px;
      height: 900px;
      border-radius: 50%;
      border: 1px solid rgba(228, 177, 52, 0.1);
      z-index: 0;
      pointer-events: none;
    }
    .hero-flight-path {
      position: absolute;
      top: 20%;
      right: 10%;
      width: 300px;
      height: 300px;
      border: 1px dashed rgba(11, 26, 48, 0.2);
      border-radius: 50%;
      z-index: 0;
      pointer-events: none;
    }
    .hero-plane {
      position: absolute;
      top: 18%;
      right: 12%;
      color: var(--psu-navy);
      transform: rotate(-15deg);
      z-index: 1;
    }

    .hero-content {
      position: relative;
      z-index: 2;
      max-width: 900px;
    }

    .hero-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
    }
    .hero-eyebrow::before, .hero-eyebrow::after {
      content: '';
      width: 40px;
      height: 1px;
      background: rgba(228, 177, 52, 0.5);
    }
    .hero-eyebrow span {
      background: var(--psu-gold);
      color: var(--psu-navy);
      font-family: var(--font-heading);
      font-size: 0.75rem;
      font-weight: 800;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      padding: 8px 24px;
      border-radius: var(--radius-full);
    }

    .hero h1 {
      font-family: var(--font-heading);
      font-size: clamp(2.5rem, 5vw, 4rem);
      color: var(--psu-navy);
      line-height: 1.1;
      font-weight: 800;
      letter-spacing: -1px;
      margin-bottom: 20px;
    }
    .hero h1 em {
      display: block;
      color: var(--psu-gold);
      font-style: normal;
      font-size: 1.15em;
      line-height: 1;
      margin-top: 4px;
    }

    .hero-desc {
      font-size: 1.1rem;
      color: var(--text-muted);
      line-height: 1.6;
      max-width: 680px;
      margin: 0 auto 48px;
      font-weight: 400;
    }

    /* ===== SEARCH BAR ===== */
    .search-container {
      position: relative;
      z-index: 2;
      width: 100%;
      max-width: 760px;
      margin: 0 auto;
    }
    .search-bar {
      display: flex;
      align-items: stretch;
      background: var(--white);
      border-radius: var(--radius-xl);
      padding: 8px;
      box-shadow: var(--shadow-search);
      border: 1px solid rgba(0,0,0,0.03);
    }
    .search-input-wrapper {
      flex: 1;
      display: flex;
      align-items: center;
      padding-left: 20px;
      gap: 12px;
    }
    .search-icon {
      color: var(--text-light);
      flex-shrink: 0;
    }
    .search-input-wrapper input {
      width: 100%;
      border: none;
      outline: none;
      background: transparent;
      font-family: var(--font-body);
      font-size: 1rem;
      color: var(--psu-navy);
      padding: 12px 0;
    }
    .search-input-wrapper input::placeholder {
      color: var(--text-light);
    }
    .search-divider {
      width: 1px;
      background: var(--border-light);
      margin: 12px 0;
    }
    .search-select-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      padding: 0 16px;
    }
    .search-select-wrapper select {
      appearance: none;
      border: none;
      outline: none;
      background: transparent;
      font-family: var(--font-body);
      font-size: 0.95rem;
      font-weight: 500;
      color: var(--psu-navy);
      cursor: pointer;
      padding: 12px 24px 12px 0;
    }
    .search-select-wrapper::after {
      content: '';
      position: absolute;
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      width: 10px;
      height: 10px;
      border-right: 2px solid var(--psu-navy);
      border-bottom: 2px solid var(--psu-navy);
      transform: translateY(-70%) rotate(45deg);
      pointer-events: none;
    }
    .search-btn {
      background: var(--psu-navy);
      color: var(--white);
      border: none;
      border-radius: var(--radius-lg);
      padding: 0 32px;
      font-family: var(--font-body);
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
      transition: var(--transition);
    }
    .search-btn:hover {
      background: var(--psu-blue);
    }
    .search-btn svg {
      width: 18px;
      height: 18px;
    }

    /* ===== STATS STRIP ===== */
    .stats-strip {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 60px;
      margin-top: 56px;
      position: relative;
      z-index: 2;
    }
    .stat-item {
      text-align: center;
      position: relative;
    }
    .stat-item:not(:last-child)::after {
      content: '';
      position: absolute;
      right: -30px;
      top: 20%;
      height: 60%;
      width: 1px;
      background: var(--border-light);
    }
    .stat-value {
      font-family: var(--font-heading);
      font-size: 2.2rem;
      font-weight: 800;
      color: var(--psu-navy);
      line-height: 1;
      margin-bottom: 8px;
    }
    .stat-label {
      font-size: 0.9rem;
      color: var(--text-muted);
      font-weight: 500;
    }
    .stat-underline {
      width: 32px;
      height: 3px;
      background: var(--psu-gold);
      border-radius: 2px;
      margin: 10px auto 0;
    }

    /* ===== SECTION HEADERS ===== */
    .section-header {
      text-align: center;
      max-width: 680px;
      margin: 0 auto 48px;
    }
    .section-eyebrow {
      display: inline-block;
      background: rgba(28, 61, 110, 0.05);
      color: var(--psu-blue);
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      padding: 6px 16px;
      border-radius: var(--radius-full);
      margin-bottom: 16px;
    }
    .section-header h2 {
      font-family: var(--font-heading);
      font-size: clamp(1.8rem, 3vw, 2.4rem);
      font-weight: 800;
      color: var(--psu-navy);
      line-height: 1.2;
      letter-spacing: -0.5px;
      margin-bottom: 16px;
    }
    .section-header h2 em { color: var(--psu-blue); font-style: normal; }
    .section-header p {
      color: var(--text-muted);
      font-size: 1.05rem;
      line-height: 1.6;
    }

    /* ===== PARTNER INSTITUTIONS ===== */
    .institutions-section {
      max-width: 1200px;
      margin: 0 auto;
      padding: 80px 24px 40px;
      scroll-margin-top: 100px;
    }
    .institutions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 20px;
    }
    .institution-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border-light);
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .institution-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md);
      border-color: var(--psu-gold);
    }
    .institution-logo {
      width: 52px;
      height: 52px;
      border-radius: var(--radius);
      background: var(--bg-main);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: var(--font-heading);
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--psu-navy);
      flex-shrink: 0;
    }
    .institution-info { flex: 1; min-width: 0; }
    .institution-name {
      font-family: var(--font-heading);
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--psu-navy);
      line-height: 1.3;
      margin-bottom: 4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .institution-meta {
      font-size: 0.8rem;
      color: var(--text-light);
      font-weight: 500;
    }
    .institution-count {
      background: rgba(28, 61, 110, 0.05);
      color: var(--psu-blue);
      font-size: 0.75rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: var(--radius-full);
      flex-shrink: 0;
    }

    /* ===== HOW IT WORKS ===== */
    .how-section {
      max-width: 1200px;
      margin: 0 auto;
      padding: 60px 24px 80px;
      scroll-margin-top: 100px;
    }
    .how-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 24px;
      counter-reset: step;
    }
    .how-step {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 40px 32px 32px;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border-light);
      position: relative;
      counter-increment: step;
      transition: var(--transition);
    }
    .how-step:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md);
    }
    .how-step::before {
      content: counter(step, decimal-leading-zero);
      position: absolute;
      top: -20px;
      left: 32px;
      background: var(--psu-gold);
      color: var(--psu-navy);
      font-family: var(--font-heading);
      font-size: 1rem;
      font-weight: 800;
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(228, 177, 52, 0.3);
      border: 4px solid var(--bg-main);
    }
    .how-step h3 {
      font-family: var(--font-heading);
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--psu-navy);
      margin-bottom: 12px;
    }
    .how-step p {
      font-size: 0.95rem;
      color: var(--text-muted);
      line-height: 1.6;
    }

    /* ===== AUDIENCE SECTIONS ===== */
    .audience-section {
      max-width: 1200px;
      margin: 0 auto;
      padding: 60px 24px 40px;
      scroll-margin-top: 100px;
    }
    .audience-section.faculty-section {
      background: var(--bg-alt);
      border-top: 1px solid var(--border-light);
      border-bottom: 1px solid var(--border-light);
      max-width: 100%;
      padding-left: calc(max(24px, (100vw - 1200px) / 2 + 24px));
      padding-right: calc(max(24px, (100vw - 1200px) / 2 + 24px));
    }

    .audience-banner {
      display: flex;
      align-items: center;
      gap: 24px;
      padding: 32px 40px;
      border-radius: var(--radius-lg);
      margin-bottom: 40px;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
    }
    .audience-banner.students {
      background: linear-gradient(135deg, var(--psu-navy) 0%, var(--psu-blue) 100%);
      color: var(--white);
    }
    .audience-banner.faculty {
      background: linear-gradient(135deg, #d19a20 0%, var(--psu-gold) 100%);
      color: var(--psu-navy);
    }
    .audience-banner-icon {
      width: 64px;
      height: 64px;
      border-radius: var(--radius);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      flex-shrink: 0;
    }
    .audience-banner.students .audience-banner-icon {
      background: rgba(255,255,255,0.1);
    }
    .audience-banner.faculty .audience-banner-icon {
      background: rgba(255,255,255,0.3);
    }
    .audience-banner-text { flex: 1; min-width: 0; }
    .audience-banner-text h2 {
      font-family: var(--font-heading);
      font-size: 1.5rem;
      font-weight: 800;
      margin-bottom: 6px;
    }
    .audience-banner-text p {
      font-size: 0.95rem;
      opacity: 0.9;
    }
    .audience-banner-count {
      font-family: var(--font-heading);
      font-size: 2.2rem;
      font-weight: 800;
      flex-shrink: 0;
      text-align: center;
      line-height: 1;
    }
    .audience-banner-count small {
      display: block;
      font-family: var(--font-body);
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      opacity: 0.8;
      margin-top: 6px;
    }

    /* ===== MAIN LAYOUT ===== */
    .page-body {
      display: grid;
      grid-template-columns: 260px 1fr;
      gap: 40px;
      align-items: start;
    }

    /* ===== SIDEBAR FILTERS ===== */
    .sidebar {
      display: flex;
      flex-direction: column;
      gap: 20px;
      position: sticky;
      top: 100px;
    }
    .filter-card {
      background: var(--white);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border-light);
    }
    .filter-card h3 {
      font-family: var(--font-heading);
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--psu-navy);
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .filter-card h3::before {
      content: '';
      width: 4px;
      height: 16px;
      background: var(--psu-gold);
      border-radius: 2px;
    }
    .filter-group { display: flex; flex-direction: column; gap: 12px; }
    .filter-group label {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.9rem;
      cursor: pointer;
      color: var(--text-muted);
      transition: var(--transition);
      font-weight: 500;
    }
    .filter-group label:hover { color: var(--psu-navy); }
    .filter-group input[type=checkbox] {
      accent-color: var(--psu-navy);
      width: 16px;
      height: 16px;
      cursor: pointer;
    }
    .badge-count {
      margin-left: auto;
      background: var(--bg-main);
      color: var(--text-light);
      font-size: 0.75rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: var(--radius-full);
    }
    .filter-card select {
      width: 100%;
      border: 1px solid var(--border-light);
      border-radius: var(--radius-sm);
      padding: 10px 14px;
      font-family: var(--font-body);
      font-size: 0.9rem;
      color: var(--psu-navy);
      outline: none;
      cursor: pointer;
      background: var(--white);
      transition: var(--transition);
      font-weight: 500;
    }
    .filter-card select:focus {
      border-color: var(--psu-navy);
      box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.05);
    }
    .clear-btn {
      width: 100%;
      background: none;
      border: 1px solid var(--border-light);
      border-radius: var(--radius-sm);
      padding: 10px;
      font-family: var(--font-body);
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-muted);
      cursor: pointer;
      transition: var(--transition);
    }
    .clear-btn:hover {
      border-color: var(--psu-navy);
      color: var(--psu-navy);
    }

    /* ===== LISTINGS HEADER ===== */
    .listings-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }
    .listings-header h2 {
      font-family: var(--font-heading);
      font-size: 1.3rem;
      font-weight: 800;
      color: var(--psu-navy);
    }
    .listings-header span {
      color: var(--text-light);
      font-size: 0.9rem;
      font-weight: 500;
      display: block;
      margin-top: 4px;
    }
    .sort-select {
      border: 1px solid var(--border-light);
      border-radius: var(--radius-sm);
      padding: 10px 16px;
      font-family: var(--font-body);
      font-size: 0.85rem;
      color: var(--psu-navy);
      outline: none;
      cursor: pointer;
      background: var(--white);
      transition: var(--transition);
      font-weight: 600;
    }
    .sort-select:focus {
      border-color: var(--psu-navy);
    }

    /* ===== PROGRAM CARD ===== */
    .program-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 28px 32px;
      box-shadow: var(--shadow-sm);
      margin-bottom: 16px;
      display: flex;
      gap: 24px;
      cursor: pointer;
      border: 1px solid var(--border-light);
      transition: var(--transition);
      text-decoration: none;
      color: inherit;
      position: relative;
    }
    .program-card:hover {
      border-color: var(--psu-gold);
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
    }
    .card-logo {
      width: 60px;
      height: 60px;
      border-radius: var(--radius);
      background: var(--bg-main);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      flex-shrink: 0;
      color: var(--psu-navy);
    }
    .card-logo.faculty {
      background: #fef8e8;
      color: #b58a1a;
    }
    .card-body { flex: 1; min-width: 0; }
    .card-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 8px;
    }
    .card-title {
      font-family: var(--font-heading);
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--psu-navy);
      line-height: 1.3;
    }
    .card-institution {
      font-size: 0.9rem;
      color: var(--psu-blue);
      margin-bottom: 12px;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .card-institution:hover {
      text-decoration: underline;
    }
    .card-desc {
      font-size: 0.9rem;
      color: var(--text-muted);
      line-height: 1.6;
      margin-bottom: 16px;
    }
    .card-tags { display: flex; flex-wrap: wrap; gap: 8px; }
    .tag {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 4px 12px;
      border-radius: var(--radius-full);
      background: var(--bg-main);
      color: var(--text-muted);
    }
    .tag.type-exchange { background: #ecfdf5; color: #047857; }
    .tag.type-internship { background: #fff7ed; color: #c2410c; }
    .tag.type-research { background: #faf5ff; color: #7e22ce; }
    .tag.type-training { background: #eff6ff; color: #1d4ed8; }
    .card-deadline {
      font-size: 0.8rem;
      color: var(--text-light);
      white-space: nowrap;
      font-weight: 500;
    }
    .card-deadline.urgent { color: #e11d48; font-weight: 600; }

    /* ===== MODAL & PROFILE PANELS (Retained functional styles) ===== */
    .modal-overlay, .profile-overlay {
      display: none; position: fixed; inset: 0; background: rgba(11,26,48,0.6);
      backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; padding: 24px;
    }
    .modal-overlay.open, .profile-overlay.open { display: flex; }
    .modal, .profile-panel {
      background: var(--white); border-radius: var(--radius-lg); width: 100%;
      box-shadow: var(--shadow-xl); animation: modalIn 0.3s ease-out; overflow: hidden;
    }
    .modal { max-width: 700px; max-height: 90vh; overflow-y: auto; }
    .profile-panel { max-width: 840px; max-height: 90vh; overflow-y: auto; }
    @keyframes modalIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    .modal-header { padding: 24px 30px; border-bottom: 1px solid var(--border-light); display: flex; gap: 20px; align-items: flex-start; }
    .modal-logo { width: 64px; height: 64px; border-radius: var(--radius); background: var(--bg-main); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; }
    .modal-logo.faculty { background: #fef8e8; color: #b58a1a; }
    .modal-title { font-family: var(--font-heading); font-size: 1.3rem; font-weight: 800; color: var(--psu-navy); margin-bottom: 6px; }
    .modal-institution { color: var(--psu-blue); font-weight: 600; font-size: 0.9rem; cursor: pointer; display: inline-block; margin-bottom: 12px; }
    .modal-institution:hover { text-decoration: underline; }
    .modal-close, .profile-close { margin-left: auto; background: var(--bg-main); border: none; font-size: 1.1rem; cursor: pointer; color: var(--text-muted); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: var(--transition); }
    .modal-close:hover, .profile-close:hover { background: var(--border-light); color: var(--psu-navy); }
    
    .modal-body { padding: 30px; }
    .modal-section { margin-bottom: 24px; }
    .modal-section h4 { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-light); margin-bottom: 12px; }
    .modal-section p { font-size: 0.95rem; line-height: 1.6; color: var(--text-muted); }
    .modal-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .meta-item { background: var(--bg-main); padding: 16px; border-radius: var(--radius-sm); }
    .meta-item .label { font-size: 0.75rem; color: var(--text-light); margin-bottom: 4px; }
    .meta-item .value { font-size: 0.95rem; font-weight: 700; color: var(--psu-navy); }
    .requirements-list { list-style: none; padding-left: 0; }
    .requirements-list li { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 8px; padding-left: 24px; position: relative; }
    .requirements-list li::before { content: '✓'; position: absolute; left: 0; color: var(--psu-gold); font-weight: bold; }
    .apply-btn { display: block; width: 100%; background: var(--psu-navy); color: var(--white); text-align: center; padding: 16px; border-radius: var(--radius); text-decoration: none; font-weight: 700; margin-top: 20px; transition: var(--transition); }
    .apply-btn:hover { background: var(--psu-blue); }

    .profile-banner { background: var(--psu-navy); padding: 40px; color: var(--white); position: relative; }
    .profile-banner-inner { display: flex; gap: 24px; align-items: center; }
    .profile-big-logo { width: 88px; height: 88px; border-radius: var(--radius); background: var(--white); color: var(--psu-navy); display: flex; align-items: center; justify-content: center; font-family: var(--font-heading); font-size: 2rem; font-weight: 800; }
    .profile-name { font-family: var(--font-heading); font-size: 1.8rem; font-weight: 800; margin-bottom: 6px; }
    .profile-location { font-size: 1rem; opacity: 0.8; }
    .profile-stats { display: flex; background: var(--white); border-bottom: 1px solid var(--border-light); }
    .profile-stat { flex: 1; padding: 24px; text-align: center; border-right: 1px solid var(--border-light); }
    .profile-stat:last-child { border-right: none; }
    .profile-stat strong { display: block; font-family: var(--font-heading); font-size: 1.6rem; font-weight: 800; color: var(--psu-navy); margin-bottom: 4px; }
    .profile-stat span { font-size: 0.8rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.5px; }
    .profile-body { padding: 40px; }
    .profile-about { margin-bottom: 32px; }
    .profile-about h4 { font-family: var(--font-heading); font-size: 1.1rem; margin-bottom: 12px; color: var(--psu-navy); }
    .profile-about p { font-size: 0.95rem; line-height: 1.6; color: var(--text-muted); }
    .profile-programs-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .profile-programs-header h4 { font-family: var(--font-heading); font-size: 1.1rem; color: var(--psu-navy); }
    .profile-program-item { display: flex; gap: 16px; padding: 16px; border: 1px solid var(--border-light); border-radius: var(--radius); margin-bottom: 12px; cursor: pointer; transition: var(--transition); align-items: center; }
    .profile-program-item:hover { border-color: var(--psu-gold); background: var(--bg-main); }
    .profile-program-emoji { width: 40px; height: 40px; border-radius: var(--radius-sm); background: var(--white); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .profile-program-title { font-weight: 700; font-size: 0.95rem; color: var(--psu-navy); margin-bottom: 4px; }
    .profile-program-meta { font-size: 0.8rem; color: var(--text-light); }

    /* ===== PAGINATION ===== */
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 32px; }
    .page-btn { width: 40px; height: 40px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--white); cursor: pointer; font-weight: 600; color: var(--text-muted); display: flex; align-items: center; justify-content: center; transition: var(--transition); }
    .page-btn.active { background: var(--psu-navy); color: var(--white); border-color: var(--psu-navy); }
    .page-btn:hover:not(.active) { border-color: var(--psu-navy); color: var(--psu-navy); }

    /* ===== EMPTY STATE ===== */
    .programs-empty { background: var(--white); border-radius: var(--radius-lg); padding: 60px 20px; text-align: center; border: 1px dashed var(--border-focus); }
    .programs-empty p { color: var(--text-muted); font-size: 1rem; }

    /* ===== FOOTER ===== */
    footer {
      background: var(--psu-navy);
      color: var(--white);
      padding: 80px 24px 40px;
      margin-top: 80px;
    }
    .footer-inner { max-width: 1200px; margin: 0 auto; }
    .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; padding-bottom: 48px; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .footer-brand h4 { font-family: var(--font-heading); font-size: 1.2rem; margin-bottom: 16px; }
    .footer-brand p { color: rgba(255,255,255,0.6); font-size: 0.9rem; line-height: 1.6; max-width: 300px; }
    .footer-col h5 { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; color: var(--psu-gold); }
    .footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 12px; }
    .footer-col a { color: rgba(255,255,255,0.7); text-decoration: none; font-size: 0.9rem; transition: var(--transition); }
    .footer-col a:hover { color: var(--white); }
    .footer-bottom { display: flex; justify-content: space-between; align-items: center; padding-top: 32px; font-size: 0.85rem; color: rgba(255,255,255,0.5); }
    .footer-socials { display: flex; gap: 16px; }
    .footer-socials a { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: var(--white); text-decoration: none; transition: var(--transition); }
    .footer-socials a:hover { background: var(--psu-gold); color: var(--psu-navy); }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 1024px) {
      .page-body { grid-template-columns: 1fr; }
      .sidebar { display: none; }
      .footer-grid { grid-template-columns: 1fr 1fr; }
      .nav-wrapper { padding: 0 16px; top: 16px; }
      nav { padding: 0 24px; }
      .nav-links { display: none; }
    }
    @media (max-width: 768px) {
      .hero h1 { font-size: 2.5rem; }
      .search-bar { flex-direction: column; border-radius: var(--radius-lg); padding: 16px; gap: 16px; }
      .search-input-wrapper { padding-left: 0; }
      .search-divider { display: none; }
      .search-select-wrapper { padding: 0; }
      .search-btn { width: 100%; justify-content: center; padding: 16px; border-radius: var(--radius); }
      .stats-strip { flex-direction: column; gap: 24px; }
      .stat-item:not(:last-child)::after { display: none; }
      .audience-banner { flex-direction: column; text-align: center; padding: 24px; }
      .program-card { flex-direction: column; padding: 24px; }
      .card-top { flex-direction: column; gap: 8px; }
      .modal-meta { grid-template-columns: 1fr; }
      .footer-grid { grid-template-columns: 1fr; gap: 32px; }
      .footer-bottom { flex-direction: column; gap: 16px; text-align: center; }
      .profile-banner-inner { flex-direction: column; text-align: center; }
      .profile-stats { flex-wrap: wrap; }
      .profile-stat { flex: 1 1 50%; border-bottom: 1px solid var(--border-light); }
    }
  </style>
</head>
<body>

<!-- NAV (Refined) -->
<div class="nav-wrapper" id="navWrapper">
  <nav>
    <!-- Brand Section -->
    <a href="#" class="nav-brand">
      <div class="nav-logo-badge">PSU</div>
      <div class="nav-divider"></div>
      <div class="nav-title">Internationalization</div>
    </a>

    <!-- Navigation Links (Centered) -->
    <ul class="nav-links">
      <li><a href="#studentPrograms" class="nav-link">Student Programs</a></li>
      <li><a href="#facultyPrograms" class="nav-link">Faculty Programs</a></li>
      <li><a href="#institutionsGrid" class="nav-link">Partner Institutions</a></li>
      <li><a href="#how-section" class="nav-link">How It Works</a></li>
    </ul>

    <!-- Action Buttons (Right Aligned) -->
    <div class="nav-actions">
      <a href="register.php" class="btn-outline">Create Account</a>
      <a href="user_login.php" class="btn-solid">
        Log In Now
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
        </svg>
      </a>
      <!-- Mobile hamburger -->
      <button class="nav-hamburger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>
</div>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg-dots"></div>
  <div class="hero-globe"></div>
  <div class="hero-contour"></div>
  <div class="hero-contour-2"></div>
  <div class="hero-flight-path"></div>
  <svg class="hero-plane" width="32" height="32" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
    <path d="M21.989 2.011C21.989 2.011 17.959 1.441 14.109 1.611C10.259 1.781 3.12 6.44 2.989 7.33C2.859 8.22 8.239 8.33 8.239 8.33C8.239 8.33 8.869 11.86 10.349 13.85C11.829 15.84 15.369 16.42 15.369 16.42C15.369 16.42 15.499 21.69 16.369 21.55C17.239 21.41 21.839 14.29 22.009 10.49C22.179 6.69 21.989 2.011 21.989 2.011Z" />
  </svg>

  <div class="hero-content">
    <div class="hero-eyebrow">
      <span>Student &amp; Faculty Mobility Portal</span>
    </div>
    <h1>Find your next program, <em>abroad.</em></h1>
    <p class="hero-desc">Discover opportunities for both PSU students and faculty members — from internships and exchanges to research collaborations and teaching programs with our partner institutions worldwide.</p>
    
    <div class="search-container">
      <div class="search-bar">
        <div class="search-input-wrapper">
          <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          <input type="text" placeholder="Search by program name, country, or type" id="searchInput"/>
        </div>
        <div class="search-divider"></div>
        <div class="search-select-wrapper">
          <select id="typeFilter">
            <option value="">All Types</option>
            <option value="exchange">Exchange</option>
            <option value="internship">Internship</option>
            <option value="research">Research</option>
            <option value="training">Training</option>
          </select>
        </div>
        <button class="search-btn" onclick="applySearch()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          Search Programs
        </button>
      </div>
    </div>

    <div class="stats-strip">
      <div class="stat-item">
        <div class="stat-value"><?= $totalAll ?></div>
        <div class="stat-label">Active Programs</div>
        <div class="stat-underline"></div>
      </div>
      <div class="stat-item">
        <div class="stat-value"><?= $totalCountries ?></div>
        <div class="stat-label">Partner Countries</div>
        <div class="stat-underline"></div>
      </div>
      <div class="stat-item">
        <div class="stat-value"><?= $totalInstitutions ?></div>
        <div class="stat-label">Partner Institutions</div>
        <div class="stat-underline"></div>
      </div>
    </div>
  </div>
</section>

<!-- PARTNER INSTITUTIONS SHOWCASE -->
<section class="institutions-section" id="institutionsGrid">
  <div class="section-header">
    <div class="section-eyebrow">Our Partners</div>
    <h2>Explore partner <em>institutions</em></h2>
    <p>Click any institution to view their profile and all programs they offer.</p>
  </div>
  <div class="institutions-grid" id="institutionsGridContainer"></div>
</section>

<!-- HOW IT WORKS -->
<section class="how-section" id="how-section">
  <div class="section-header">
    <div class="section-eyebrow">Simple Process</div>
    <h2>How it <em>works</em></h2>
    <p>Four straightforward steps from discovery to departure.</p>
  </div>
  <div class="how-grid">
    <div class="how-step">
      <h3>Browse Programs</h3>
      <p>Explore active programs filtered by type, country, duration, and funding options.</p>
    </div>
    <div class="how-step">
      <h3>Submit Application</h3>
      <p>Complete a single online application with all required documents and recommendations.</p>
    </div>
    <div class="how-step">
      <h3>Get Approved</h3>
      <p>Receive confirmation from PSU International Affairs and your host institution.</p>
    </div>
    <div class="how-step">
      <h3>Travel &amp; Study</h3>
      <p>Attend pre-departure orientation, then begin your adventure abroad with full support.</p>
    </div>
  </div>
</section>

<?php if ($loadError): ?>
  <div class="audience-section">
    <div class="programs-empty">
      <p><?= htmlspecialchars($loadError) ?></p>
    </div>
  </div>
<?php else: ?>

<!-- SECTION A: PROGRAMS FOR STUDENTS -->
<section class="audience-section" id="studentPrograms">
  <div class="audience-banner students">
    <div class="audience-banner-icon">🎓</div>
    <div class="audience-banner-text">
      <h2>Programs for Students</h2>
      <p>Internships, exchanges, research, and training opportunities open to currently enrolled PSU students.</p>
    </div>
    <div class="audience-banner-count">
      <?= $studentStats['totalPrograms'] ?>
      <small>Programs</small>
    </div>
  </div>

  <div class="page-body">
    <aside class="sidebar">
      <div class="filter-card">
        <h3>Program Type</h3>
        <div class="filter-group">
          <label><input type="checkbox" data-filter="type" data-audience="student" value="exchange" onchange="filterCards('student')"> Student Exchange <span class="badge-count"><?= $studentStats['typeCounts']['exchange'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="student" value="internship" onchange="filterCards('student')"> Internship <span class="badge-count"><?= $studentStats['typeCounts']['internship'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="student" value="research" onchange="filterCards('student')"> Research <span class="badge-count"><?= $studentStats['typeCounts']['research'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="student" value="training" onchange="filterCards('student')"> Training <span class="badge-count"><?= $studentStats['typeCounts']['training'] ?></span></label>
        </div>
      </div>
      <div class="filter-card">
        <h3>Country / Region</h3>
        <select id="countryFilterStudent" onchange="filterCards('student')">
          <option value="">All Countries</option>
          <?php foreach ($studentStats['countries'] as $countryName): ?>
            <option><?= htmlspecialchars($countryName) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-card">
        <h3>Duration</h3>
        <div class="filter-group">
          <label><input type="checkbox" onchange="filterCards('student')"> 1 – 3 months</label>
          <label><input type="checkbox" onchange="filterCards('student')"> 4 – 6 months</label>
          <label><input type="checkbox" onchange="filterCards('student')"> 1 semester</label>
          <label><input type="checkbox" onchange="filterCards('student')"> 1 academic year</label>
        </div>
      </div>
      <button class="clear-btn" onclick="clearFilters('student')">Clear all filters</button>
    </aside>

    <main>
      <div class="listings-header">
        <div>
          <h2>Available Student Programs</h2>
          <span id="resultCountStudent">Showing <?= $studentStats['totalPrograms'] ?> of <?= $studentStats['totalPrograms'] ?> programs</span>
        </div>
        <select class="sort-select" id="sortSelectStudent">
          <option value="deadline">Sort: Deadline (Soonest)</option>
          <option value="slots">Sort: Most Slots</option>
          <option value="az">Sort: A → Z</option>
        </select>
      </div>
      <div id="studentProgramListContainer"></div>
      <div class="pagination" id="studentPaginationBar"></div>
    </main>
  </div>
</section>

<!-- SECTION B: PROGRAMS FOR FACULTY -->
<section class="audience-section faculty-section" id="facultyPrograms">
  <div class="audience-banner faculty">
    <div class="audience-banner-icon">👩‍🏫</div>
    <div class="audience-banner-text">
      <h2>Programs for Faculty</h2>
      <p>Teaching exchanges, research collaborations, and faculty development opportunities with our partner institutions.</p>
    </div>
    <div class="audience-banner-count">
      <?= $facultyStats['totalPrograms'] ?>
      <small>Programs</small>
    </div>
  </div>

  <div class="page-body">
    <aside class="sidebar">
      <div class="filter-card">
        <h3>Program Type</h3>
        <div class="filter-group">
          <label><input type="checkbox" data-filter="type" data-audience="faculty" value="exchange" onchange="filterCards('faculty')"> Faculty Exchange <span class="badge-count"><?= $facultyStats['typeCounts']['exchange'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="faculty" value="internship" onchange="filterCards('faculty')"> Internship <span class="badge-count"><?= $facultyStats['typeCounts']['internship'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="faculty" value="research" onchange="filterCards('faculty')"> Research <span class="badge-count"><?= $facultyStats['typeCounts']['research'] ?></span></label>
          <label><input type="checkbox" data-filter="type" data-audience="faculty" value="training" onchange="filterCards('faculty')"> Training <span class="badge-count"><?= $facultyStats['typeCounts']['training'] ?></span></label>
        </div>
      </div>
      <div class="filter-card">
        <h3>Country / Region</h3>
        <select id="countryFilterFaculty" onchange="filterCards('faculty')">
          <option value="">All Countries</option>
          <?php foreach ($facultyStats['countries'] as $countryName): ?>
            <option><?= htmlspecialchars($countryName) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-card">
        <h3>Duration</h3>
        <div class="filter-group">
          <label><input type="checkbox" onchange="filterCards('faculty')"> 1 – 3 months</label>
          <label><input type="checkbox" onchange="filterCards('faculty')"> 4 – 6 months</label>
          <label><input type="checkbox" onchange="filterCards('faculty')"> 1 semester</label>
          <label><input type="checkbox" onchange="filterCards('faculty')"> 1 academic year</label>
        </div>
      </div>
      <button class="clear-btn" onclick="clearFilters('faculty')">Clear all filters</button>
    </aside>

    <main>
      <div class="listings-header">
        <div>
          <h2>Available Faculty Programs</h2>
          <span id="resultCountFaculty">Showing <?= $facultyStats['totalPrograms'] ?> of <?= $facultyStats['totalPrograms'] ?> programs</span>
        </div>
        <select class="sort-select" id="sortSelectFaculty">
          <option value="deadline">Sort: Deadline (Soonest)</option>
          <option value="slots">Sort: Most Slots</option>
          <option value="az">Sort: A → Z</option>
        </select>
      </div>
      <div id="facultyProgramListContainer"></div>
      <div class="pagination" id="facultyPaginationBar"></div>
    </main>
  </div>
</section>

<?php endif; ?>

<!-- PROGRAM DETAIL MODAL -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModal(event)">
  <div class="modal" id="modalBox">
    <div class="modal-header">
      <div class="modal-logo" id="modalLogo"></div>
      <div>
        <div class="modal-title" id="modalTitle"></div>
        <a class="modal-institution" id="modalInstitution" onclick="openInstitutionFromModal(event)"></a>
        <div class="card-tags" id="modalTags"></div>
      </div>
      <button class="modal-close" onclick="closeModalDirect()">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-section">
        <h4>About this Program</h4>
        <p id="modalDesc"></p>
      </div>
      <div class="modal-section">
        <h4>Program Details</h4>
        <div class="modal-meta" id="modalMeta"></div>
      </div>
      <div class="modal-section">
        <h4>Requirements</h4>
        <ul class="requirements-list" id="modalReqs"></ul>
      </div>
      <a href="register.php" id="applyLink" class="apply-btn">Apply for this Program</a>
    </div>
  </div>
</div>

<!-- PARTNER INSTITUTION PROFILE PANEL -->
<div class="profile-overlay" id="profileOverlay" onclick="closeProfile(event)">
  <div class="profile-panel" id="profilePanel">
    <div class="profile-banner">
      <button class="profile-close" onclick="closeProfileDirect()">✕</button>
      <div class="profile-banner-inner">
        <div class="profile-big-logo" id="profileLogo"></div>
        <div class="profile-banner-text">
          <div class="profile-name" id="profileName"></div>
          <div class="profile-location" id="profileLocation"></div>
        </div>
      </div>
    </div>
    <div class="profile-stats">
      <div class="profile-stat">
        <strong id="profileStatPrograms">0</strong>
        <span>Active Programs</span>
      </div>
      <div class="profile-stat">
        <strong id="profileStatTypes">0</strong>
        <span>Program Types</span>
      </div>
      <div class="profile-stat">
        <strong id="profileStatCountry">—</strong>
        <span>Location</span>
      </div>
    </div>
    <div class="profile-body">
      <div class="profile-about">
        <h4>About this Institution</h4>
        <p id="profileAbout"></p>
      </div>
      <div class="profile-programs-header">
        <h4>Programs at this Institution</h4>
        <span id="profileProgramCount"></span>
      </div>
      <div id="profileProgramsList"></div>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <h4>PSU Internationalization</h4>
        <p>The official student and faculty mobility portal of Pangasinan State University's Office of International Affairs.</p>
      </div>
      <div class="footer-col">
        <h5>Explore</h5>
        <ul>
          <li><a href="#studentPrograms">Student Programs</a></li>
          <li><a href="#facultyPrograms">Faculty Programs</a></li>
          <li><a href="#institutionsGrid">Partner Institutions</a></li>
          <li><a href="#how-section">How It Works</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h5>Support</h5>
        <ul>
          <li><a href="#">Contact Us</a></li>
          <li><a href="#">FAQ</a></li>
          <li><a href="#">Student Handbook</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h5>Connect</h5>
        <ul>
          <li><a href="#">Facebook</a></li>
          <li><a href="#">Instagram</a></li>
          <li><a href="#">LinkedIn</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?= date('Y') ?> Pangasinan State University · Office of International Affairs. All rights reserved.</p>
      <div class="footer-socials">
        <a href="#" title="Facebook">f</a>
        <a href="#" title="Instagram">ig</a>
        <a href="#" title="LinkedIn">in</a>
      </div>
    </div>
  </div>
</footer>

<?php if (!$loadError && ($studentStats['totalPrograms'] > 0 || $facultyStats['totalPrograms'] > 0)): ?>
<script>
  // Inject data from PHP
  const rawStudentPrograms = <?= json_encode($studentPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawFacultyPrograms = <?= json_encode($facultyPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const STUDENT_REQS = <?= json_encode($studentRequirements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const FACULTY_REQS = <?= json_encode($facultyRequirements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const TYPE_EMOJI = {
    exchange: '🔄',
    internship: '💼',
    research: '🔬',
    training: '📚'
  };

  function formatDate(dateStr) {
    if (!dateStr) return 'TBA';
    const d = new Date(dateStr);
    if (isNaN(d)) return 'TBA';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function computeDuration(start, end) {
    if (!start || !end) return 'Flexible';
    const s = new Date(start), e = new Date(end);
    if (isNaN(s) || isNaN(e)) return 'Flexible';
    const months = Math.max(1, Math.round((e - s) / (1000 * 60 * 60 * 24 * 30)));
    if (months >= 10) return '1 academic year';
    if (months >= 5) return '1 semester';
    if (months >= 4) return '4 – 6 months';
    return '1 – 3 months';
  }

  function isUrgent(endDate) {
    if (!endDate) return false;
    const days = (new Date(endDate) - new Date()) / (1000 * 60 * 60 * 24);
    return days >= 0 && days <= 14;
  }

  function normalizeProgram(p, audience) {
    const type = (p.program_type || '').toLowerCase();
    return {
      id: p.id,
      title: p.program_name,
      type: type,
      desc: p.description || 'No description available.',
      institution: p.institution_name || 'Partner Institution',
      country: p.country_name || 'Unknown',
      startDate: p.start_date,
      endDate: p.end_date,
      deadline: formatDate(p.end_date),
      duration: computeDuration(p.start_date, p.end_date),
      emoji: TYPE_EMOJI[type] || '🎓',
      featured: false,
      urgent: isUrgent(p.end_date),
      audience: audience
    };
  }

  // Nav scroll shrink effect
(function () {
  const navWrapper = document.getElementById('navWrapper');
  if (!navWrapper) return;
  let lastScroll = 0;

  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    navWrapper.classList.toggle('scrolled', y > 40);
    lastScroll = y;
  }, { passive: true });
})();

  const studentPrograms = rawStudentPrograms.map(p => normalizeProgram(p, 'student'));
  const facultyPrograms = rawFacultyPrograms.map(p => normalizeProgram(p, 'faculty'));

  // Build institution groups
  const institutions = {};
  [...studentPrograms, ...facultyPrograms].forEach(p => {
    if (!institutions[p.institution]) {
      institutions[p.institution] = {
        name: p.institution,
        country: p.country,
        programs: [],
        types: new Set()
      };
    }
    institutions[p.institution].programs.push(p);
    institutions[p.institution].types.add(p.type);
  });
  const institutionList = Object.values(institutions);

  function initials(name) {
    return name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
  }
  function capitalize(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
  }

  const PER_PAGE = 4;
  const state = {
    student: {
      all: studentPrograms,
      filtered: [...studentPrograms],
      page: 1,
      listContainer: 'studentProgramListContainer',
      paginationBar: 'studentPaginationBar',
      resultCount: 'resultCountStudent',
      sortSelect: 'sortSelectStudent',
      reqs: STUDENT_REQS
    },
    faculty: {
      all: facultyPrograms,
      filtered: [...facultyPrograms],
      page: 1,
      listContainer: 'facultyProgramListContainer',
      paginationBar: 'facultyPaginationBar',
      resultCount: 'resultCountFaculty',
      sortSelect: 'sortSelectFaculty',
      reqs: FACULTY_REQS
    }
  };

  function renderInstitutions() {
    const grid = document.getElementById('institutionsGridContainer');
    if (!grid) return;
    grid.innerHTML = '';
    institutionList.forEach(inst => {
      const card = document.createElement('div');
      card.className = 'institution-card';
      card.onclick = () => openProfile(inst.name);
      card.innerHTML = `
        <div class="institution-logo">${initials(inst.name)}</div>
        <div class="institution-info">
          <div class="institution-name">${inst.name}</div>
          <div class="institution-meta">${inst.country} · ${inst.types.size} type${inst.types.size > 1 ? 's' : ''}</div>
        </div>
        <span class="institution-count">${inst.programs.length}</span>
      `;
      grid.appendChild(card);
    });
  }

  function openProfile(name) {
    const inst = institutions[name];
    if (!inst) return;

    document.getElementById('profileLogo').textContent = initials(inst.name);
    document.getElementById('profileName').textContent = inst.name;
    document.getElementById('profileLocation').innerHTML = `📍 ${inst.country}`;
    document.getElementById('profileStatPrograms').textContent = inst.programs.length;
    document.getElementById('profileStatTypes').textContent = inst.types.size;
    document.getElementById('profileStatCountry').textContent = inst.country;

    const studentCount = inst.programs.filter(p => p.audience === 'student').length;
    const facultyCount = inst.programs.filter(p => p.audience === 'faculty').length;
    document.getElementById('profileAbout').textContent =
      `${inst.name} is a partner institution of Pangasinan State University located in ${inst.country}. ` +
      `Through this partnership, PSU offers ${studentCount} student program${studentCount !== 1 ? 's' : ''} ` +
      `and ${facultyCount} faculty program${facultyCount !== 1 ? 's' : ''} with this institution.`;

    document.getElementById('profileProgramCount').textContent = `${inst.programs.length} program${inst.programs.length > 1 ? 's' : ''} available`;

    const list = document.getElementById('profileProgramsList');
    list.innerHTML = '';
    inst.programs.forEach(p => {
      const item = document.createElement('div');
      item.className = 'profile-program-item';
      item.onclick = () => { closeProfileDirect(); openModal(p); };
      const audienceLabel = p.audience === 'faculty' ? '👩‍🏫 Faculty' : '🎓 Student';
      item.innerHTML = `
        <div class="profile-program-emoji">${p.emoji}</div>
        <div class="profile-program-info">
          <div class="profile-program-title">${p.title}</div>
          <div class="profile-program-meta">${audienceLabel} · ${capitalize(p.type)} · ⏱ ${p.duration} · Deadline: ${p.deadline}</div>
        </div>
      `;
      list.appendChild(item);
    });

    document.getElementById('profileOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeProfile(e) {
    if (e.target === document.getElementById('profileOverlay')) closeProfileDirect();
  }
  function closeProfileDirect() {
    document.getElementById('profileOverlay').classList.remove('open');
    if (!document.getElementById('modalOverlay').classList.contains('open')) {
      document.body.style.overflow = '';
    }
  }

  function renderPrograms(audience, page) {
    const s = state[audience];
    s.page = page || 1;
    const total = s.filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    const start = (s.page - 1) * PER_PAGE;
    const pageItems = s.filtered.slice(start, start + PER_PAGE);

    const container = document.getElementById(s.listContainer);
    if (!container) return;
    container.innerHTML = '';

    if (total === 0) {
      container.innerHTML = '<div class="programs-empty"><p>No programs match your filters. Try adjusting your search or clearing filters.</p></div>';
      renderPagination(audience, 0, 1);
      document.getElementById(s.resultCount).textContent = 'No programs found';
      return;
    }

    pageItems.forEach(p => {
      const desc = p.desc.length > 130 ? p.desc.substring(0, 130) + '...' : p.desc;
      const isFaculty = p.audience === 'faculty';
      const card = document.createElement('div');
      card.className = 'program-card';
      card.onclick = () => openModal(p);

      card.innerHTML = `
        <div class="card-logo${isFaculty ? ' faculty' : ''}">${p.emoji}</div>
        <div class="card-body">
          <div class="card-top">
            <div>
              <div class="card-title">${p.title}</div>
              <a class="card-institution" onclick="event.stopPropagation(); openProfile('${p.institution.replace(/'/g, "\\'")}')">
                🏛 ${p.institution} · ${p.country}
              </a>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;">
              <span class="card-deadline${p.urgent ? ' urgent' : ''}">Deadline: ${p.deadline}</span>
            </div>
          </div>
          <div class="card-desc">${desc}</div>
          <div class="card-tags">
            <span class="tag type-${p.type}">${capitalize(p.type)}</span>
            <span class="tag">⏱ ${p.duration}</span>
            <span class="tag">${isFaculty ? '👩‍🏫 Faculty' : '🎓 Student'}</span>
          </div>
        </div>
      `;
      container.appendChild(card);
    });

    document.getElementById(s.resultCount).textContent = `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} of ${total} programs`;
    renderPagination(audience, totalPages, s.page);
  }

  function renderPagination(audience, totalPages, active) {
    const s = state[audience];
    const pg = document.getElementById(s.paginationBar);
    if (!pg) return;
    pg.innerHTML = '';
    if (totalPages <= 1) return;

    const btn = (label, page, disabled, isActive) => {
      const b = document.createElement('button');
      b.className = 'page-btn' + (isActive ? ' active' : '');
      b.textContent = label;
      b.disabled = disabled;
      if (!disabled && !isActive) b.onclick = () => renderPrograms(audience, page);
      return b;
    };

    pg.appendChild(btn('‹', active - 1, active === 1, false));

    let pages = [];
    if (totalPages <= 7) {
      pages = Array.from({ length: totalPages }, (_, i) => i + 1);
    } else {
      pages = [1];
      if (active > 3) pages.push('…');
      for (let i = Math.max(2, active - 1); i <= Math.min(totalPages - 1, active + 1); i++) pages.push(i);
      if (active < totalPages - 2) pages.push('…');
      pages.push(totalPages);
    }

    pages.forEach(p => {
      if (p === '…') {
        const sp = document.createElement('span');
        sp.textContent = '…';
        sp.style.cssText = 'padding:0 8px;color:var(--text-light);line-height:40px;';
        pg.appendChild(sp);
      } else {
        pg.appendChild(btn(p, p, false, p === active));
      }
    });

    pg.appendChild(btn('›', active + 1, active === totalPages, false));
  }

  let activeProgramForModal = null;

  function openModal(p) {
    activeProgramForModal = p;
    const isFaculty = p.audience === 'faculty';

    const logoEl = document.getElementById('modalLogo');
    logoEl.textContent = p.emoji;
    logoEl.className = 'modal-logo' + (isFaculty ? ' faculty' : '');

    document.getElementById('modalTitle').textContent = p.title;
    document.getElementById('modalInstitution').innerHTML = `🏛 ${p.institution} · ${p.country} ↗`;
    document.getElementById('modalTags').innerHTML = `
      <span class="tag type-${p.type}">${capitalize(p.type)}</span>
      <span class="tag">⏱ ${p.duration}</span>
      <span class="tag">${isFaculty ? '👩‍🏫 Faculty' : '🎓 Student'}</span>`;
    document.getElementById('modalDesc').textContent = p.desc;
    document.getElementById('modalMeta').innerHTML = `
      <div class="meta-item"><div class="label">Duration</div><div class="value">${p.duration}</div></div>
      <div class="meta-item"><div class="label">Application Deadline</div><div class="value">${p.deadline}</div></div>
      <div class="meta-item"><div class="label">Country</div><div class="value">${p.country}</div></div>
      <div class="meta-item"><div class="label">Program Type</div><div class="value">${capitalize(p.type)}</div></div>`;

    const reqs = isFaculty ? FACULTY_REQS : STUDENT_REQS;
    document.getElementById('modalReqs').innerHTML = reqs.map(r => `<li>${r}</li>`).join('');
    document.getElementById('applyLink').href = 'register.php?program_id=' + p.id + '&account_type=' + p.audience;

    document.getElementById('modalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function openInstitutionFromModal(e) {
    e.preventDefault();
    if (!activeProgramForModal) return;
    const instName = activeProgramForModal.institution;
    closeModalDirect();
    openProfile(instName);
  }

  function closeModal(e) {
    if (e.target === document.getElementById('modalOverlay')) closeModalDirect();
  }
  function closeModalDirect() {
    document.getElementById('modalOverlay').classList.remove('open');
    if (!document.getElementById('profileOverlay').classList.contains('open')) {
      document.body.style.overflow = '';
    }
  }

  function applySearchToAudience(audience) {
    const s = state[audience];
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const globalType = document.getElementById('typeFilter').value;

    const checkedTypes = [...document.querySelectorAll(
      `.filter-group input[type=checkbox][data-filter=type][data-audience="${audience}"]:checked`
    )].map(c => c.value);

    const countryEl = document.getElementById(audience === 'student' ? 'countryFilterStudent' : 'countryFilterFaculty');
    const countryVal = countryEl ? countryEl.value : '';

    s.filtered = s.all.filter(p => {
      const matchQ = !q || p.title.toLowerCase().includes(q) || p.country.toLowerCase().includes(q) || p.institution.toLowerCase().includes(q);
      const matchGlobalType = !globalType || p.type === globalType;
      const matchChecked = checkedTypes.length === 0 || checkedTypes.includes(p.type);
      const matchCountry = !countryVal || p.country === countryVal;
      return matchQ && matchGlobalType && matchChecked && matchCountry;
    });
    renderPrograms(audience, 1);
  }

  function applySearch() {
    applySearchToAudience('student');
    applySearchToAudience('faculty');
  }

  function filterCards(audience) { applySearchToAudience(audience); }

  function clearFilters(audience) {
    document.querySelectorAll(`.filter-group input[type=checkbox][data-audience="${audience}"]`).forEach(cb => cb.checked = false);
    const countryEl = document.getElementById(audience === 'student' ? 'countryFilterStudent' : 'countryFilterFaculty');
    if (countryEl) countryEl.value = '';
    document.getElementById('searchInput').value = '';
    document.getElementById('typeFilter').value = '';

    state[audience].filtered = [...state[audience].all];
    renderPrograms(audience, 1);
    const other = audience === 'student' ? 'faculty' : 'student';
    applySearchToAudience(other);
  }

  function bindSort(audience) {
    const s = state[audience];
    const el = document.getElementById(s.sortSelect);
    if (!el) return;
    el.addEventListener('change', function () {
      const val = this.value;
      const sorted = [...s.filtered];
      sorted.sort((a, b) => {
        const da = a.endDate ? new Date(a.endDate) : new Date(8640000000000000);
        const db = b.endDate ? new Date(b.endDate) : new Date(8640000000000000);
        return da - db;
      });
      if (val === 'az') sorted.sort((a, b) => a.title.localeCompare(b.title));
      s.filtered = sorted;
      renderPrograms(audience, 1);
    });
  }

  document.getElementById('searchInput').addEventListener('keydown', e => { if (e.key === 'Enter') applySearch(); });
  document.getElementById('typeFilter').addEventListener('change', () => { applySearch(); });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeModalDirect(); closeProfileDirect(); }
  });

  // Init
  renderInstitutions();
  renderPrograms('student', 1);
  renderPrograms('faculty', 1);
  bindSort('student');
  bindSort('faculty');
</script>
<?php endif; ?>
</body>
</html>