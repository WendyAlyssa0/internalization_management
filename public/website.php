<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$studentPrograms = [];
$facultyPrograms = [];
$loadError = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.program_name, p.program_type, p.program_types,
            p.eligible_participant_types, p.description,
            p.start_date, p.end_date, p.application_start_date, p.application_end_date,
            p.status, p.partner_id, p.country_id, p.eligible_account_type,
            c.country_name, pr.institution_name
        FROM programs p
        LEFT JOIN countries c ON p.country_id = c.id
        LEFT JOIN partners pr ON p.partner_id = pr.id
        WHERE p.status IN ('Active', 'Upcoming')
          AND COALESCE(p.mobility_direction, 'outbound') = 'outbound'
          AND (p.application_start_date IS NULL OR p.application_start_date <= CURDATE())
          AND (p.application_end_date IS NULL OR p.application_end_date >= CURDATE())
        ORDER BY p.id DESC
    ");
    $stmt->execute();
    $allPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPrograms as $p) {
        $eligibleTypes = json_decode((string)($p['eligible_participant_types'] ?? ''), true);
        if (!is_array($eligibleTypes)) {
            $legacyType = strtolower(trim((string)($p['eligible_account_type'] ?? '')));
            $eligibleTypes = $legacyType === 'both' ? ['student', 'faculty'] : [$legacyType];
        }
        $eligibleTypes = array_map(fn($t) => str_replace(['nonteaching', 'non_teaching', 'staff'], 'non-teaching', strtolower(trim((string)$t))), $eligibleTypes);
        $p['eligible_list'] = array_values(array_unique(array_filter($eligibleTypes)));
        // Non-teaching personnel programs are listed with faculty programs and labelled as such
        if (in_array('faculty', $eligibleTypes, true) || in_array('non-teaching', $eligibleTypes, true)) $facultyPrograms[] = $p;
        if (in_array('student', $eligibleTypes, true)) $studentPrograms[] = $p;
    }
} catch (PDOException $ex) {
    error_log('browse_program.php query failed: ' . $ex->getMessage());
    $loadError = 'We ran into a problem loading programs. Please try again shortly.';
}

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
        'typeCounts' => $typeCounts,
        'countries' => array_keys($countrySet),
        'totalPrograms' => count($programs),
        'totalCountries' => count($countrySet),
        'totalInstitutions' => count($institutionSet),
    ];
}

$studentStats = buildStats($studentPrograms);
$facultyStats = buildStats($facultyPrograms);

$totalAll = count($studentPrograms) + count($facultyPrograms);
$allCountries = array_unique(array_merge($studentStats['countries'], $facultyStats['countries']));
$totalCountries = count($allCountries);

$allInstitutions = [];
foreach (array_merge($studentPrograms, $facultyPrograms) as $p) {
    if (!empty($p['institution_name'])) $allInstitutions[$p['institution_name']] = true;
}
$totalInstitutions = count($allInstitutions);

// ---- Partner institutions (auto-updating: every partner added in the system appears) ----
// SELECT * so this works whether or not website/logo columns exist yet.
// Expected optional columns: website_url (or website), logo_path (or logo).
$partnersForJs = [];
$countryMap = [];
try {
    foreach ($pdo->query("SELECT id, country_name FROM countries")->fetchAll(PDO::FETCH_ASSOC) as $cr) $countryMap[$cr['id']] = $cr['country_name'];
} catch (PDOException $ex) { error_log('countries query failed: ' . $ex->getMessage()); }
$partnerCountrySet = [];
try {
    $partnerRows = $pdo->query("SELECT pr.* FROM partners pr ORDER BY pr.institution_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($partnerRows as $r) {
        $name = trim((string)($r['institution_name'] ?? ''));
        if ($name === '') continue;
        $url = trim((string)($r['website_url'] ?? $r['website'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $url = '';
        $pick = function (array $keys) use ($r) { foreach ($keys as $k) { if (isset($r[$k]) && trim((string)$r[$k]) !== '') return trim((string)$r[$k]); } return ''; };
        $safePath = fn($x) => (is_string($x) && $x !== '' && strpos($x, '..') === false) ? ltrim($x, '/\\') : '';
        $photoRaw = $pick(['photos', 'photo_paths', 'photo_path', 'photo']);
        $photoList = json_decode($photoRaw, true);
        if (!is_array($photoList)) $photoList = preg_split('/[\r\n,;]+/', $photoRaw, -1, PREG_SPLIT_NO_EMPTY);
        $photoList = array_values(array_filter(array_map(fn($x) => $safePath(trim((string)$x)), $photoList)));
        $partnersForJs[] = [
            'id' => $r['id'] ?? null,
            'name' => $name,
            'logo' => (string)($r['logo_path'] ?? $r['logo'] ?? ''),
            'website' => $url,
            'country' => (string)($countryMap[$r['country_id'] ?? null] ?? ''),
            'description' => $pick(['description', 'about', 'background', 'institution_description']),
            'address' => $pick(['full_address', 'address', 'institution_address']),
            'contact' => $pick(['contact_person', 'contact_name', 'contact']),
            'areas' => $pick(['areas_of_collaboration', 'collaboration_areas', 'areas']),
            'brochure' => $safePath($pick(['brochure_path', 'brochure'])),
            'photos' => array_slice($photoList, 0, 6),
        ];
        if (!empty($countryMap[$r['country_id'] ?? null])) $partnerCountrySet[$countryMap[$r['country_id']]] = true;
    }
    if ($partnersForJs) $totalInstitutions = count($partnersForJs);
    if ($partnerCountrySet) $totalCountries = count(array_unique(array_merge(array_keys($partnerCountrySet), $allCountries)));
} catch (PDOException $ex) {
    error_log('partners query failed: ' . $ex->getMessage());
}

$announcements = [];
$announcementLoadError = null;
try {
    $announcements = $pdo->query("
        SELECT title, announcement, publish_date, expiry_date, status, attachment_path
        FROM announcements
        WHERE status IN ('ongoing', 'Published')
          AND publish_date <= CURDATE()
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ORDER BY publish_date DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log('public announcements query failed: ' . $ex->getMessage());
    $announcementLoadError = 'Announcements are temporarily unavailable.';
}

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
<link rel="icon" type="image/png" href="../assets/psu-logo.png">
<title>PSU Internationalization — Programs Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
:root{
  /* Sampled from the PSU logo: blue #000fda, yellow #fff74e. Navy is a darker shade of the logo blue for text and dark surfaces. */
  --psu-navy:#00094f;--psu-navy-light:#000f7a;--psu-blue:#000fda;--psu-gold:#fff74e;--psu-gold-light:#fffb9a;--psu-gold-deep:#c9b800;
  --bg-main:#f7f9fc;--bg-alt:#edf2f8;--white:#ffffff;--text-main:#0f172a;--text-muted:#475569;--text-light:#64748b;
  --border-light:#e2e8f0;--border-focus:#94a3b8;
  --radius-sm:8px;--radius:12px;--radius-lg:20px;--radius-xl:30px;--radius-full:9999px;
  --shadow-sm:0 1px 2px rgba(0,0,0,.05);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03);
  --shadow-lg:0 10px 25px -5px rgba(0,0,0,.08);
  --shadow-xl:0 20px 40px -10px rgba(0,0,0,.08);
  --shadow-search:0 12px 35px rgba(11,26,48,.08);
  /* Hierarchy: headings bold (700/800), details and subtitles regular (400/500) */
  --font-heading:'Plus Jakarta Sans',sans-serif;--font-body:'Inter',sans-serif;
  --transition:all .25s cubic-bezier(.4,0,.2,1);
}
body{font-family:var(--font-body);font-weight:400;background:var(--bg-main);color:var(--text-main);min-height:100vh;-webkit-font-smoothing:antialiased;line-height:1.5}
::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-track{background:var(--bg-main)}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}

/* NAV */
.nav-wrapper{position:fixed;top:20px;left:0;width:100%;display:flex;justify-content:center;z-index:100;padding:0 24px;pointer-events:none;transition:top .3s}
.nav-wrapper.scrolled{top:12px}
nav{background:rgba(255,255,255,.92);backdrop-filter:blur(16px) saturate(180%);-webkit-backdrop-filter:blur(16px) saturate(180%);display:flex;align-items:center;justify-content:space-between;padding:0 12px 0 20px;height:68px;width:100%;max-width:1200px;border-radius:var(--radius-full);box-shadow:0 1px 2px rgba(11,26,48,.04),0 8px 24px -4px rgba(11,26,48,.08),inset 0 0 0 1px rgba(11,26,48,.04);pointer-events:auto;transition:height .3s,box-shadow .3s;position:relative}
.nav-wrapper.scrolled nav{height:60px}
.nav-brand{display:flex;align-items:center;gap:14px;text-decoration:none;flex-shrink:0}
.nav-logo-frame{position:relative;display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;background:var(--white);margin:0 4px}
.nav-logo-img{width:44px;height:44px;object-fit:contain}
.nav-logo-fallback{display:none;font:800 .8rem var(--font-heading);color:var(--psu-navy)}
.logo-globe{position:absolute;inset:-5px;border-radius:50%;border:1.5px dashed var(--psu-blue);animation:spin 14s linear infinite;pointer-events:none}
.logo-globe::after{content:'';position:absolute;top:-3px;left:50%;width:6px;height:6px;border-radius:50%;background:var(--psu-blue)}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.logo-globe{animation:none}html{scroll-behavior:auto}}
.nav-divider{width:1px;height:22px;background:linear-gradient(to bottom,transparent,var(--border-light),transparent)}
.nav-title{font-family:var(--font-heading);color:var(--psu-navy);font-size:1.05rem;font-weight:700;letter-spacing:-.3px;white-space:nowrap}
.nav-links{display:flex;align-items:center;gap:6px;list-style:none}
.nav-links a.nav-link{color:var(--text-muted);text-decoration:none;font-size:.88rem;font-weight:500;padding:8px 14px;border-radius:var(--radius-full);transition:color .2s,background .2s;white-space:nowrap;position:relative;display:inline-block}
.nav-links a.nav-link::after{content:'';position:absolute;left:50%;bottom:2px;transform:translateX(-50%);width:0;height:2px;background:var(--psu-gold);border-radius:2px;transition:width .25s}
.nav-links a.nav-link:hover{color:var(--psu-navy);background:rgba(11,26,48,.04)}
.nav-links a.nav-link:hover::after,.nav-links a.nav-link.active::after{width:16px}
.nav-links a.nav-link.active{color:var(--psu-navy);font-weight:700;background:rgba(11,26,48,.06)}
.nav-dropdown{position:relative}
.dropdown-menu{display:none;position:absolute;top:100%;left:0;min-width:210px;background:#fff;border-radius:var(--radius);padding:8px;list-style:none;box-shadow:var(--shadow-lg);border:1px solid var(--border-light)}
.nav-dropdown:hover .dropdown-menu,.nav-dropdown:focus-within .dropdown-menu{display:block}
.dropdown-menu a{display:block;padding:10px 14px;border-radius:var(--radius-sm);color:var(--text-muted);text-decoration:none;font-weight:500;font-size:.88rem}
.dropdown-menu a:hover,.dropdown-menu a.active{background:var(--bg-main);color:var(--psu-navy);font-weight:700}
.nav-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}
.btn-outline{background:transparent;color:var(--psu-navy);border:1.5px solid var(--border-light);padding:9px 20px;border-radius:var(--radius-full);font-size:.86rem;font-weight:600;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-outline:hover{border-color:var(--psu-navy);background:var(--bg-main)}
.btn-solid{background:linear-gradient(135deg,var(--psu-gold),var(--psu-gold-light));color:var(--psu-navy);padding:10px 20px;border-radius:var(--radius-full);font-size:.86rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:8px;white-space:nowrap;box-shadow:0 4px 14px -2px rgba(200,190,0,.45);transition:all .2s}
.btn-solid:hover{transform:translateY(-1px);filter:brightness(1.05)}
.btn-solid svg{width:15px;height:15px}
.nav-hamburger{display:none;background:var(--bg-main);border:none;width:40px;height:40px;border-radius:50%;cursor:pointer;align-items:center;justify-content:center;flex-direction:column;gap:4px}
.nav-hamburger span{display:block;width:16px;height:2px;background:var(--psu-navy);border-radius:2px}
@media (max-width:1024px){
  .nav-links{display:none;position:absolute;top:calc(100% + 8px);left:0;right:0;flex-direction:column;align-items:stretch;background:#fff;border-radius:var(--radius-lg);padding:12px;box-shadow:var(--shadow-lg)}
  .nav-links.open{display:flex}
  .nav-dropdown .dropdown-menu{display:block;position:static;box-shadow:none;border:none;padding:0 0 0 14px}
  .nav-hamburger{display:flex}.btn-outline{display:none}
}
@media (max-width:640px){.nav-wrapper{padding:0 16px;top:12px}nav{height:60px}.nav-title{font-size:.95rem}.btn-solid{padding:9px 16px;font-size:.8rem}.btn-solid svg{display:none}}

/* HERO */
.hero{position:relative;padding:180px 24px 100px;background:#f4f7fb radial-gradient(circle at 15% 50%,rgba(255,247,78,.12),transparent 40%);overflow:hidden;display:flex;flex-direction:column;align-items:center;text-align:center;scroll-margin-top:0}
.hero-bg-dots{position:absolute;inset:0;background-image:radial-gradient(var(--border-light) 1px,transparent 1px);background-size:24px 24px;opacity:.4;mask-image:linear-gradient(to bottom,#000 40%,transparent);-webkit-mask-image:linear-gradient(to bottom,#000 40%,transparent)}
.hero-globe{position:absolute;bottom:-150px;left:-150px;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle at 70% 30%,var(--psu-blue),var(--psu-navy) 70%);box-shadow:inset -20px -20px 60px rgba(0,0,0,.5);opacity:.9}
.hero-globe::after{content:'';position:absolute;inset:-20px;border-radius:50%;border:1px solid rgba(255,247,78,.5)}
.hero-content{position:relative;z-index:2;max-width:900px}
.hero-eyebrow{display:inline-flex;align-items:center;gap:16px;margin-bottom:24px}
.hero-eyebrow span{background:var(--psu-gold);color:var(--psu-navy);font:800 .75rem var(--font-heading);letter-spacing:1.5px;text-transform:uppercase;padding:8px 24px;border-radius:var(--radius-full)}
.hero h1{font-family:var(--font-heading);font-size:clamp(2.5rem,5vw,4rem);color:var(--psu-navy);line-height:1.1;font-weight:800;letter-spacing:-1px;margin-bottom:20px}
.hero h1 em{display:block;color:var(--psu-blue);font-style:normal;font-size:1.15em;line-height:1;margin-top:4px}
.hero-desc{font-size:1.1rem;color:var(--text-muted);line-height:1.6;max-width:680px;margin:0 auto 48px}
.search-container{position:relative;z-index:2;width:100%;max-width:760px;margin:0 auto}
.search-bar{display:flex;align-items:stretch;background:var(--white);border-radius:var(--radius-xl);padding:8px;box-shadow:var(--shadow-search)}
.search-input-wrapper{flex:1;display:flex;align-items:center;padding-left:20px;gap:12px}
.search-icon{color:var(--text-light);flex-shrink:0}
.search-input-wrapper input{width:100%;border:none;outline:none;background:transparent;font:400 1rem var(--font-body);color:var(--psu-navy);padding:12px 0}
.search-divider{width:1px;background:var(--border-light);margin:12px 0}
.search-select-wrapper{position:relative;display:flex;align-items:center;padding:0 16px}
.search-select-wrapper select{appearance:none;border:none;outline:none;background:transparent;font:500 .95rem var(--font-body);color:var(--psu-navy);cursor:pointer;padding:12px 24px 12px 0}
.search-select-wrapper::after{content:'';position:absolute;right:16px;top:50%;width:8px;height:8px;border-right:2px solid var(--psu-navy);border-bottom:2px solid var(--psu-navy);transform:translateY(-70%) rotate(45deg);pointer-events:none}
.search-btn{background:var(--psu-navy);color:#fff;border:none;border-radius:var(--radius-lg);padding:0 32px;font:600 .95rem var(--font-body);cursor:pointer;display:flex;align-items:center;gap:10px;transition:var(--transition)}
.search-btn:hover{background:var(--psu-blue)}.search-btn svg{width:18px;height:18px}
.stats-strip{display:flex;justify-content:center;align-items:center;gap:60px;margin-top:56px;position:relative;z-index:2}
.stat-item{text-align:center;position:relative}
.stat-item:not(:last-child)::after{content:'';position:absolute;right:-30px;top:20%;height:60%;width:1px;background:var(--border-light)}
.stat-value{font:800 2.2rem var(--font-heading);color:var(--psu-navy);line-height:1;margin-bottom:8px}
.stat-label{font-size:.9rem;color:var(--text-muted);font-weight:400}
.stat-underline{width:32px;height:3px;background:var(--psu-gold);border-radius:2px;margin:10px auto 0}

/* SECTIONS */
.section-header{text-align:center;max-width:680px;margin:0 auto 48px}
.section-eyebrow{display:inline-block;background:rgba(28,61,110,.05);color:var(--psu-blue);font-size:.75rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;padding:6px 16px;border-radius:var(--radius-full);margin-bottom:16px}
.section-header h2{font-family:var(--font-heading);font-size:clamp(1.8rem,3vw,2.4rem);font-weight:800;color:var(--psu-navy);line-height:1.2;letter-spacing:-.5px;margin-bottom:16px}
.section-header h2 em{color:var(--psu-blue);font-style:normal}
.section-header p{color:var(--text-muted);font-size:1.05rem;line-height:1.6}
.about-section,.institutions-section,.announce-section{max-width:1200px;margin:0 auto;padding:80px 24px 40px;scroll-margin-top:100px}
.announce-section{padding:48px 24px}
.about-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}
.about-card{background:#fff;border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:28px;box-shadow:var(--shadow-sm)}
.about-card h3{font:700 1.1rem var(--font-heading);color:var(--psu-navy);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.about-card h3::before{content:'';width:4px;height:16px;background:var(--psu-gold);border-radius:2px}
.about-card p{color:var(--text-muted);font-size:.95rem;line-height:1.6}
.about-card.wide{grid-column:1/-1}

/* PARTNERS (logo-only) */
.institutions-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px}
.institution-card{background:#fff;border-radius:var(--radius-lg);padding:20px;box-shadow:var(--shadow-sm);border:1px solid var(--border-light);transition:var(--transition);display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center}
.institution-logo-link{display:block;width:100%;text-decoration:none}
.institution-name{font:700 .85rem var(--font-heading);color:var(--psu-navy);line-height:1.3}
.institution-profile-btn{background:none;border:1px solid var(--border-light);border-radius:var(--radius-full);padding:6px 14px;font:600 .75rem var(--font-body);color:var(--psu-blue);cursor:pointer;transition:var(--transition)}
.institution-profile-btn:hover{border-color:var(--psu-blue);background:var(--bg-main)}
.institution-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);border-color:var(--psu-gold)}
.institution-logo-lg{width:100%;height:80px;display:flex;align-items:center;justify-content:center;font:800 1.4rem var(--font-heading);color:var(--psu-navy);text-align:center}
.institution-logo-lg img{max-width:100%;max-height:80px;object-fit:contain}

/* AUDIENCE */
.audience-section{max-width:1200px;margin:0 auto;padding:60px 24px 40px;scroll-margin-top:100px}
.audience-section.faculty-section{background:var(--bg-alt);border-top:1px solid var(--border-light);border-bottom:1px solid var(--border-light);max-width:100%;padding-left:max(24px,calc((100vw - 1200px)/2 + 24px));padding-right:max(24px,calc((100vw - 1200px)/2 + 24px))}
.audience-banner{display:flex;align-items:center;gap:24px;padding:32px 40px;border-radius:var(--radius-lg);margin-bottom:40px;box-shadow:var(--shadow-sm)}
.audience-banner.students{background:linear-gradient(135deg,var(--psu-navy),var(--psu-blue));color:#fff}
.audience-banner.faculty{background:linear-gradient(135deg,#e6d800,var(--psu-gold));color:var(--psu-navy)}
.audience-banner-icon{width:64px;height:64px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:2rem;flex-shrink:0;background:rgba(255,255,255,.2)}
.audience-banner-text{flex:1;min-width:0}
.audience-banner-text h2{font:800 1.5rem var(--font-heading);margin-bottom:6px}
.audience-banner-text p{font-size:.95rem;opacity:.9}
.audience-banner-count{font:800 2.2rem var(--font-heading);flex-shrink:0;text-align:center;line-height:1}
.audience-banner-count small{display:block;font:600 .75rem var(--font-body);text-transform:uppercase;letter-spacing:1px;opacity:.8;margin-top:6px}
.page-body{display:grid;grid-template-columns:260px 1fr;gap:40px;align-items:start}
.sidebar{display:flex;flex-direction:column;gap:20px;position:sticky;top:100px}
.filter-card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:var(--shadow-sm);border:1px solid var(--border-light)}
.filter-card h3{font:700 .9rem var(--font-heading);color:var(--psu-navy);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.filter-card h3::before{content:'';width:4px;height:16px;background:var(--psu-gold);border-radius:2px}
.filter-group{display:flex;flex-direction:column;gap:12px}
.filter-group label{display:flex;align-items:center;gap:10px;font-size:.9rem;cursor:pointer;color:var(--text-muted)}
.filter-group label:hover{color:var(--psu-navy)}
.filter-group input[type=checkbox]{accent-color:var(--psu-navy);width:16px;height:16px}
.badge-count{margin-left:auto;background:var(--bg-main);color:var(--text-light);font-size:.75rem;font-weight:700;padding:2px 8px;border-radius:var(--radius-full)}
.filter-card select,.sort-select{width:100%;border:1px solid var(--border-light);border-radius:var(--radius-sm);padding:10px 14px;font:500 .9rem var(--font-body);color:var(--psu-navy);outline:none;cursor:pointer;background:#fff}
.sort-select{width:auto}
.clear-btn{width:100%;background:none;border:1px solid var(--border-light);border-radius:var(--radius-sm);padding:10px;font:600 .85rem var(--font-body);color:var(--text-muted);cursor:pointer;transition:var(--transition)}
.clear-btn:hover{border-color:var(--psu-navy);color:var(--psu-navy)}
.listings-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:16px}
.listings-header h2{font:800 1.3rem var(--font-heading);color:var(--psu-navy)}
.listings-header span{color:var(--text-light);font-size:.9rem;display:block;margin-top:4px}

/* PROGRAM CARD */
.program-card{background:#fff;border-radius:var(--radius-lg);padding:28px 32px;box-shadow:var(--shadow-sm);margin-bottom:16px;display:flex;gap:24px;cursor:pointer;border:1px solid var(--border-light);transition:var(--transition)}
.program-card:hover{border-color:var(--psu-gold);box-shadow:var(--shadow-md);transform:translateY(-2px)}
.card-logo,.modal-logo{width:60px;height:60px;border-radius:var(--radius);background:var(--bg-main);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
.card-logo.faculty,.modal-logo.faculty{background:#fef8e8;color:#b58a1a}
.card-body{flex:1;min-width:0}
.card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:8px}
.card-title{font:700 1.15rem var(--font-heading);color:var(--psu-navy);line-height:1.3}
.card-institution{font-size:.9rem;color:var(--psu-blue);margin-bottom:12px;font-weight:500;display:inline-flex;gap:4px}
.card-institution:hover{text-decoration:underline}
.card-desc{font-size:.9rem;color:var(--text-muted);line-height:1.6;margin-bottom:16px}
.card-tags{display:flex;flex-wrap:wrap;gap:8px}
.tag{font-size:.75rem;font-weight:600;padding:4px 12px;border-radius:var(--radius-full);background:var(--bg-main);color:var(--text-muted)}
.tag.type-exchange{background:#ecfdf5;color:#047857}.tag.type-internship{background:#fff7ed;color:#c2410c}
.tag.type-research{background:#faf5ff;color:#7e22ce}.tag.type-training{background:#eff6ff;color:#1d4ed8}
.card-deadline{font-size:.8rem;color:var(--text-light);white-space:nowrap}
.card-deadline.urgent{color:#e11d48;font-weight:600}

/* MODALS */
.modal-overlay,.profile-overlay{display:none;position:fixed;inset:0;background:rgba(11,26,48,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:24px}
.modal-overlay.open,.profile-overlay.open{display:flex}
.modal,.profile-panel{background:#fff;border-radius:var(--radius-lg);width:100%;box-shadow:var(--shadow-xl);animation:modalIn .3s ease-out;max-height:90vh;overflow-y:auto}
.modal{max-width:700px}.profile-panel{max-width:840px}
@keyframes modalIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.modal-header{padding:24px 30px;border-bottom:1px solid var(--border-light);display:flex;gap:20px;align-items:flex-start}
.modal-logo{width:64px;height:64px}
.modal-title{font:800 1.3rem var(--font-heading);color:var(--psu-navy);margin-bottom:6px}
.modal-institution{color:var(--psu-blue);font-weight:600;font-size:.9rem;cursor:pointer;display:inline-block;margin-bottom:12px}
.modal-institution:hover{text-decoration:underline}
.modal-close,.profile-close{margin-left:auto;background:var(--bg-main);border:none;font-size:1.1rem;cursor:pointer;color:var(--text-muted);width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.modal-body{padding:30px}.modal-section{margin-bottom:24px}
.modal-section h4{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-light);margin-bottom:12px}
.modal-section p{font-size:.95rem;line-height:1.6;color:var(--text-muted)}
.modal-meta{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.meta-item{background:var(--bg-main);padding:16px;border-radius:var(--radius-sm)}
.meta-item .label{font-size:.75rem;color:var(--text-light);margin-bottom:4px}
.meta-item .value{font-size:.95rem;font-weight:700;color:var(--psu-navy)}
.requirements-list{list-style:none}
.requirements-list li{font-size:.9rem;color:var(--text-muted);margin-bottom:8px;padding-left:24px;position:relative}
.requirements-list li::before{content:'✓';position:absolute;left:0;color:var(--psu-blue);font-weight:700}
.apply-btn{display:block;width:100%;background:var(--psu-navy);color:#fff;text-align:center;padding:16px;border-radius:var(--radius);text-decoration:none;font-weight:700;margin-top:20px;transition:var(--transition)}
.apply-btn:hover{background:var(--psu-blue)}
.profile-banner{background:var(--psu-navy);padding:40px;color:#fff;position:relative}
.profile-banner-inner{display:flex;gap:24px;align-items:center}
.profile-big-logo{width:88px;height:88px;border-radius:var(--radius);background:#fff;color:var(--psu-navy);display:flex;align-items:center;justify-content:center;font:800 2rem var(--font-heading);overflow:hidden}
.profile-big-logo img{max-width:100%;max-height:100%;object-fit:contain}
.profile-name{font:800 1.8rem var(--font-heading);margin-bottom:6px}
.profile-location{font-size:1rem;opacity:.8}
.profile-stats{display:flex;background:#fff;border-bottom:1px solid var(--border-light)}
.profile-stat{flex:1;padding:24px;text-align:center;border-right:1px solid var(--border-light)}
.profile-stat:last-child{border-right:none}
.profile-stat strong{display:block;font:800 1.6rem var(--font-heading);color:var(--psu-navy);margin-bottom:4px}
.profile-stat span{font-size:.8rem;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.profile-body{padding:40px}.profile-about{margin-bottom:32px}
.profile-about h4,.profile-programs-header h4{font:700 1.1rem var(--font-heading);color:var(--psu-navy);margin-bottom:12px}
.profile-about p{font-size:.95rem;line-height:1.6;color:var(--text-muted)}
.profile-programs-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.profile-program-item{display:flex;gap:16px;padding:16px;border:1px solid var(--border-light);border-radius:var(--radius);margin-bottom:12px;cursor:pointer;align-items:center;transition:var(--transition)}
.profile-program-item:hover{border-color:var(--psu-gold);background:var(--bg-main)}
.profile-program-emoji{width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.profile-program-title{font-weight:700;font-size:.95rem;color:var(--psu-navy);margin-bottom:4px}
.profile-program-meta{font-size:.8rem;color:var(--text-light)}
.pagination{display:flex;justify-content:center;gap:8px;margin-top:32px}
.page-btn{width:40px;height:40px;border:1px solid var(--border-light);border-radius:var(--radius-sm);background:#fff;cursor:pointer;font-weight:600;color:var(--text-muted)}
.page-btn.active{background:var(--psu-navy);color:#fff;border-color:var(--psu-navy)}
.programs-empty{background:#fff;border-radius:var(--radius-lg);padding:60px 20px;text-align:center;border:1px dashed var(--border-focus);color:var(--text-muted)}

/* FOOTER */
footer{background:var(--psu-navy);color:#fff;padding:80px 24px 40px;margin-top:80px}
.footer-inner{max-width:1200px;margin:0 auto}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:40px;padding-bottom:48px;border-bottom:1px solid rgba(255,255,255,.1)}
.footer-brand h4{font:700 1.2rem var(--font-heading);margin-bottom:16px}
.footer-brand p{color:rgba(255,255,255,.6);font-size:.9rem;line-height:1.6;max-width:300px}
.footer-col h5{font-size:.85rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:20px;color:var(--psu-gold)}
.footer-col ul{list-style:none;display:flex;flex-direction:column;gap:12px}
.footer-col a{color:rgba(255,255,255,.7);text-decoration:none;font-size:.9rem}
.footer-col a:hover{color:#fff}
.footer-bottom{display:flex;justify-content:space-between;align-items:center;padding-top:32px;font-size:.85rem;color:rgba(255,255,255,.5)}
.footer-socials{display:flex;gap:16px}
.footer-socials a{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none}
.footer-socials a:hover{background:var(--psu-gold);color:var(--psu-navy)}

@media (max-width:1024px){.page-body{grid-template-columns:1fr}.sidebar{display:none}.footer-grid{grid-template-columns:1fr 1fr}}
@media (max-width:768px){
  .hero h1{font-size:2.5rem}
  .search-bar{flex-direction:column;border-radius:var(--radius-lg);padding:16px;gap:16px}
  .search-input-wrapper{padding-left:0}.search-divider{display:none}.search-select-wrapper{padding:0}
  .search-btn{width:100%;justify-content:center;padding:16px;border-radius:var(--radius)}
  .stats-strip{flex-direction:column;gap:24px}.stat-item:not(:last-child)::after{display:none}
  .audience-banner{flex-direction:column;text-align:center;padding:24px}
  .program-card{flex-direction:column;padding:24px}.card-top{flex-direction:column;gap:8px}
  .modal-meta{grid-template-columns:1fr}
  .footer-grid{grid-template-columns:1fr;gap:32px}.footer-bottom{flex-direction:column;gap:16px;text-align:center}
  .profile-banner-inner{flex-direction:column;text-align:center}
  .profile-stats{flex-wrap:wrap}.profile-stat{flex:1 1 50%;border-bottom:1px solid var(--border-light)}
}
</style>
</head>
<body>

<!-- NAV: Overview | Programs (Student / Faculty) | Partner Institutions -->
<div class="nav-wrapper" id="navWrapper">
  <nav>
    <a href="#overview" class="nav-brand">
      <div class="nav-logo-frame">
        <!-- Place the official PSU logo at ../assets/psu-logo.png -->
        <img src="../assets/psu-logo.png" alt="PSU logo" class="nav-logo-img"
             onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
        <span class="nav-logo-fallback">PSU</span>
        <span class="logo-globe" aria-hidden="true"></span>
      </div>
      <div class="nav-divider"></div>
      <div class="nav-title">Internationalization</div>
    </a>

    <ul class="nav-links" id="navLinks">
      <li><a href="#overview" class="nav-link" data-spy="overview">Overview</a></li>
      <li><a href="#about" class="nav-link" data-spy="about">About Us</a></li>
      <li class="nav-dropdown">
        <a href="#studentPrograms" class="nav-link" data-spy="programs">Programs ▾</a>
        <ul class="dropdown-menu">
          <li><a href="#studentPrograms" data-spy="studentPrograms">Student Programs</a></li>
          <li><a href="#facultyPrograms" data-spy="facultyPrograms">Faculty Programs</a></li>
        </ul>
      </li>
      <li><a href="#institutionsGrid" class="nav-link" data-spy="institutionsGrid">Partner Institutions</a></li>
    </ul>

    <div class="nav-actions">
      <a href="register.php" class="btn-outline">Create Account</a>
      <a href="user_login.php" class="btn-solid">
        Log In Now
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
      </a>
      <button class="nav-hamburger" id="navToggle" aria-label="Menu"><span></span><span></span><span></span></button>
    </div>
  </nav>
</div>

<!-- HERO / OVERVIEW -->
<section class="hero" id="overview">
  <div class="hero-bg-dots"></div>
  <div class="hero-globe"></div>
  <div class="hero-content">
    <div class="hero-eyebrow"><span>Student &amp; Faculty Mobility Portal</span></div>
    <h1>Find your next program, <em>abroad.</em></h1>
    <p class="hero-desc">Discover opportunities for PSU students, faculty, and non-teaching personnel — from internships and exchanges to research collaborations and teaching programs with our partner institutions worldwide.</p>

    <div class="search-container">
      <div class="search-bar">
        <div class="search-input-wrapper">
          <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
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
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          Search Programs
        </button>
      </div>
    </div>

    <!-- Live counts -->
    <div class="stats-strip">
      <div class="stat-item"><div class="stat-value"><?= (int)$totalAll ?></div><div class="stat-label">Active Programs</div><div class="stat-underline"></div></div>
      <div class="stat-item"><div class="stat-value"><?= (int)$totalCountries ?></div><div class="stat-label">Partner Countries</div><div class="stat-underline"></div></div>
      <div class="stat-item"><div class="stat-value"><?= (int)$totalInstitutions ?></div><div class="stat-label">Partner Institutions</div><div class="stat-underline"></div></div>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="about-section" id="about">
  <div class="section-header">
    <div class="section-eyebrow">About Us</div>
    <h2>PSU <em>Internationalization</em></h2>
    <p>Building global opportunities for Pangasinan State University students and faculty.</p>
  </div>
  <div class="about-grid">
    <article class="about-card wide">
      <h3>Our History</h3>
      <p>[Replace with the official history of PSU Internationalization.]</p>
    </article>
    <article class="about-card wide">
      <h3>Role of the Internationalization Office</h3>
      <p>[Replace with the role and purpose of the International Affairs office.]</p>
    </article>
    <article class="about-card">
      <h3>Vision</h3>
      <p>[Replace with the official vision statement.]</p>
    </article>
    <article class="about-card">
      <h3>Mission</h3>
      <p>[Replace with the official mission statement.]</p>
    </article>
  </div>
</section>

<!-- ANNOUNCEMENTS -->
<section id="announcements" class="announce-section">
  <div style="margin-bottom:20px;">
    <p style="color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Latest updates</p>
    <h2 style="font-size:2rem;color:#0b1a30;font-family:var(--font-heading);">Announcements</h2>
  </div>
  <?php if ($announcementLoadError): ?>
    <p role="status"><?= htmlspecialchars($announcementLoadError, ENT_QUOTES, 'UTF-8') ?></p>
  <?php elseif (!$announcements): ?>
    <p style="color:#64748b;">There are no current announcements.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
      <?php foreach ($announcements as $announcement): ?>
        <article style="padding:20px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 4px 12px rgba(15,23,42,.04);">
          <h3 style="margin:0 0 8px;color:#0b1a30;"><?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?></h3>
          <time style="display:block;margin-bottom:12px;color:#64748b;font-size:.875rem;" datetime="<?= htmlspecialchars($announcement['publish_date'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M d, Y', strtotime($announcement['publish_date'])), ENT_QUOTES, 'UTF-8') ?></time>
          <?php if (!empty($announcement['expiry_date'])): ?><p style="margin:-6px 0 10px;color:#64748b;font-size:.8rem;">Displayed until <?= htmlspecialchars(date('M d, Y', strtotime($announcement['expiry_date'])), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <p style="white-space:pre-line;color:#334155;"><?= htmlspecialchars($announcement['announcement'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php
            $attachmentPath = ltrim((string)($announcement['attachment_path'] ?? ''), '/\\');
            if (strpos($attachmentPath, 'uploads/announcements/') === 0 && strpos($attachmentPath, '..') === false):
          ?>
            <a href="../<?= htmlspecialchars($attachmentPath, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-top:12px;color:#1c3d6e;font-weight:600;">View attachment</a>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($loadError): ?>
  <div class="audience-section" id="studentPrograms"><div class="programs-empty"><p><?= htmlspecialchars($loadError) ?></p></div></div>
<?php else: ?>

<!-- STUDENT PROGRAMS -->
<section class="audience-section" id="studentPrograms">
  <div class="audience-banner students">
    <div class="audience-banner-icon">🎓</div>
    <div class="audience-banner-text">
      <h2>Programs for Students</h2>
      <p>Internships, exchanges, research, and training opportunities open to currently enrolled PSU students.</p>
    </div>
    <div class="audience-banner-count"><?= (int)$studentStats['totalPrograms'] ?><small>Programs</small></div>
  </div>

  <div class="page-body">
    <aside class="sidebar">
      <div class="filter-card">
        <h3>Program Type</h3>
        <div class="filter-group">
          <?php foreach (['exchange' => 'Student Exchange', 'internship' => 'Internship', 'research' => 'Research', 'training' => 'Training'] as $v => $label): ?>
            <label><input type="checkbox" data-filter="type" data-audience="student" value="<?= $v ?>" onchange="filterCards('student')"> <?= $label ?> <span class="badge-count"><?= (int)$studentStats['typeCounts'][$v] ?></span></label>
          <?php endforeach; ?>
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
          <?php foreach (['1 – 3 months', '4 – 6 months', '1 semester', '1 academic year'] as $d): ?>
            <label><input type="checkbox" data-filter="duration" data-audience="student" value="<?= $d ?>" onchange="filterCards('student')"> <?= $d ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="clear-btn" onclick="clearFilters('student')">Clear all filters</button>
    </aside>

    <main>
      <div class="listings-header">
        <div>
          <h2>Available Student Programs</h2>
          <span id="resultCountStudent">Showing <?= (int)$studentStats['totalPrograms'] ?> of <?= (int)$studentStats['totalPrograms'] ?> programs</span>
        </div>
        <select class="sort-select" id="sortSelectStudent">
          <option value="deadline">Sort: Deadline (Soonest)</option>
          <option value="az">Sort: A → Z</option>
        </select>
      </div>
      <div id="studentProgramListContainer"></div>
      <div class="pagination" id="studentPaginationBar"></div>
    </main>
  </div>
</section>

<!-- FACULTY PROGRAMS -->
<section class="audience-section faculty-section" id="facultyPrograms">
  <div class="audience-banner faculty">
    <div class="audience-banner-icon">👩‍🏫</div>
    <div class="audience-banner-text">
      <h2>Programs for Faculty</h2>
      <p>Teaching exchanges, research collaborations, and faculty development opportunities with our partner institutions. Some programs are also open to non-teaching personnel.</p>
    </div>
    <div class="audience-banner-count"><?= (int)$facultyStats['totalPrograms'] ?><small>Programs</small></div>
  </div>

  <div class="page-body">
    <aside class="sidebar">
      <div class="filter-card">
        <h3>Program Type</h3>
        <div class="filter-group">
          <?php foreach (['exchange' => 'Faculty Exchange', 'internship' => 'Internship', 'research' => 'Research', 'training' => 'Training'] as $v => $label): ?>
            <label><input type="checkbox" data-filter="type" data-audience="faculty" value="<?= $v ?>" onchange="filterCards('faculty')"> <?= $label ?> <span class="badge-count"><?= (int)$facultyStats['typeCounts'][$v] ?></span></label>
          <?php endforeach; ?>
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
          <?php foreach (['1 – 3 months', '4 – 6 months', '1 semester', '1 academic year'] as $d): ?>
            <label><input type="checkbox" data-filter="duration" data-audience="faculty" value="<?= $d ?>" onchange="filterCards('faculty')"> <?= $d ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="clear-btn" onclick="clearFilters('faculty')">Clear all filters</button>
    </aside>

    <main>
      <div class="listings-header">
        <div>
          <h2>Available Faculty Programs</h2>
          <span id="resultCountFaculty">Showing <?= (int)$facultyStats['totalPrograms'] ?> of <?= (int)$facultyStats['totalPrograms'] ?> programs</span>
        </div>
        <select class="sort-select" id="sortSelectFaculty">
          <option value="deadline">Sort: Deadline (Soonest)</option>
          <option value="az">Sort: A → Z</option>
        </select>
      </div>
      <div id="facultyProgramListContainer"></div>
      <div class="pagination" id="facultyPaginationBar"></div>
    </main>
  </div>
</section>

<?php endif; ?>

<!-- PARTNER INSTITUTIONS (auto-updates from the partners table; logos link to each partner's site) -->
<section class="institutions-section" id="institutionsGrid">
  <div class="section-header">
    <div class="section-eyebrow">Our Partners</div>
    <h2>Partner <em>Institutions</em></h2>
    <p>Click a logo to visit the partner's website, or open a profile to see the programs we run together.</p>
  </div>
  <div class="institutions-grid" id="institutionsGridContainer"></div>
</section>

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
      <div class="modal-section"><h4>About this Program</h4><p id="modalDesc"></p></div>
      <div class="modal-section"><h4>Program Details</h4><div class="modal-meta" id="modalMeta"></div></div>
      <div class="modal-section"><h4>Requirements</h4><ul class="requirements-list" id="modalReqs"></ul></div>
      <div class="modal-section">
        <h4>How to apply</h4>
        <ol class="requirements-list" style="counter-reset:s">
          <li style="counter-increment:s">Create an account with your PSU email (pre-registration).</li>
          <li style="counter-increment:s">The International Affairs office reviews and approves your application.</li>
          <li style="counter-increment:s">Once approved, upload your requirements in your portal.</li>
        </ol>
      </div>
      <a href="register.php" id="applyLink" class="apply-btn">Pre-register for this Program</a>
      <p style="margin-top:12px;font-size:.8rem;color:var(--text-light);text-align:center">Creating an account is pre-registration only. The International Affairs office reviews and approves your application before you can upload requirements. Use your PSU email address.</p>
    </div>
  </div>
</div>

<!-- PARTNER PROFILE PANEL -->
<div class="profile-overlay" id="profileOverlay" onclick="closeProfile(event)">
  <div class="profile-panel" id="profilePanel">
    <div class="profile-banner">
      <button class="profile-close" onclick="closeProfileDirect()" style="position:absolute;top:20px;right:20px">✕</button>
      <div class="profile-banner-inner">
        <div class="profile-big-logo" id="profileLogo"></div>
        <div>
          <div class="profile-name" id="profileName"></div>
          <div class="profile-location" id="profileLocation"></div>
        </div>
      </div>
    </div>
    <div class="profile-stats">
      <div class="profile-stat"><strong id="profileStatPrograms">0</strong><span>Active Programs</span></div>
      <div class="profile-stat"><strong id="profileStatTypes">0</strong><span>Program Types</span></div>
      <div class="profile-stat"><strong id="profileStatCountry">—</strong><span>Location</span></div>
    </div>
    <div class="profile-body">
      <div class="profile-about"><h4>About this Institution</h4><p id="profileAbout"></p></div>
      <div id="profileDetails" style="margin-bottom:32px"></div>
      <div class="profile-programs-header"><h4>Programs at this Institution</h4><span id="profileProgramCount"></span></div>
      <div id="profileProgramsList"></div>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <img src="../assets/psu-logo.png" alt="PSU logo" width="64" height="64" style="margin-bottom:14px">
        <h4>PSU Internationalization</h4>
        <p>The official student and faculty mobility portal of Pangasinan State University's Office of International Affairs.</p>
      </div>
      <div class="footer-col">
        <h5>Explore</h5>
        <ul>
          <li><a href="#overview">Overview</a></li>
          <li><a href="#about">About Us</a></li>
          <li><a href="#studentPrograms">Student Programs</a></li>
          <li><a href="#facultyPrograms">Faculty Programs</a></li>
          <li><a href="#institutionsGrid">Partner Institutions</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h5>Support</h5>
        <ul><li><a href="#">Contact Us</a></li><li><a href="#">FAQ</a></li><li><a href="#">Student Handbook</a></li></ul>
      </div>
      <div class="footer-col">
        <h5>Connect</h5>
        <ul><li><a href="#">Facebook</a></li><li><a href="#">Instagram</a></li><li><a href="#">LinkedIn</a></li></ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?= date('Y') ?> Pangasinan State University · Office of International Affairs. All rights reserved.</p>
      <div class="footer-socials">
        <a href="#" title="Facebook">f</a><a href="#" title="Instagram">ig</a><a href="#" title="LinkedIn">in</a>
      </div>
    </div>
  </div>
</footer>

<script>
  const rawStudentPrograms = <?= json_encode($studentPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawFacultyPrograms = <?= json_encode($facultyPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawPartners = <?= json_encode($partnersForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const STUDENT_REQS = <?= json_encode($studentRequirements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const FACULTY_REQS = <?= json_encode($facultyRequirements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const TYPE_EMOJI = { exchange: '🔄', internship: '💼', research: '🔬', training: '📚' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function formatDate(dateStr) {
    if (!dateStr) return 'TBA';
    const d = new Date(dateStr);
    return isNaN(d) ? 'TBA' : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
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
  function initials(name) {
    return name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
  }
  function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  const GROUP_LABEL = { student: 'Students', faculty: 'Faculty', 'non-teaching': 'Non-teaching personnel' };
  function eligibleLabel(p, audience) {
    const list = (p.eligible_list && p.eligible_list.length) ? p.eligible_list : [audience];
    return list.map(g => GROUP_LABEL[g] || capitalize(g)).join(', ');
  }
  function availabilityLabel(p) {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const st = p.application_start_date ? new Date(p.application_start_date) : null;
    const en = p.application_end_date ? new Date(p.application_end_date) : null;
    if (st && st > today) return 'Opens ' + formatDate(p.application_start_date);
    if (en) return 'Open until ' + formatDate(p.application_end_date);
    return 'Open';
  }

  function normalizeProgram(p, audience) {
    const type = (p.program_type || '').toLowerCase();
    // Deadline = application deadline when set, otherwise program end date
    const deadlineRaw = p.application_end_date || p.end_date;
    return {
      id: p.id,
      title: p.program_name,
      type: type,
      desc: p.description || 'No description available.',
      institution: p.institution_name || 'Partner Institution',
      country: p.country_name || 'Unknown',
      startDate: p.start_date,
      endDate: p.end_date,
      deadlineRaw: deadlineRaw,
      deadline: formatDate(deadlineRaw),
      applyStart: formatDate(p.application_start_date),
      applyEnd: formatDate(p.application_end_date),
      duration: computeDuration(p.start_date, p.end_date),
      eligible: eligibleLabel(p, audience),
      availability: availabilityLabel(p),
      emoji: TYPE_EMOJI[type] || '🎓',
      urgent: isUrgent(deadlineRaw),
      audience: audience
    };
  }

  const studentPrograms = rawStudentPrograms.map(p => normalizeProgram(p, 'student'));
  const facultyPrograms = rawFacultyPrograms.map(p => normalizeProgram(p, 'faculty'));

  // Institutions: every partner from the database, with their programs attached
  const institutions = {};
  rawPartners.forEach(pt => {
    institutions[pt.name] = { ...pt, country: pt.country || '—', programs: [], types: new Set() };
  });
  [...studentPrograms, ...facultyPrograms].forEach(p => {
    if (!institutions[p.institution]) {
      institutions[p.institution] = { name: p.institution, country: p.country, logo: '', website: '', programs: [], types: new Set() };
    }
    const inst = institutions[p.institution];
    if (inst.country === '—') inst.country = p.country;
    inst.programs.push(p);
    inst.types.add(p.type);
  });
  const institutionList = Object.values(institutions);

  const PER_PAGE = 4;
  const state = {
    student: { all: studentPrograms, filtered: [...studentPrograms], page: 1, listContainer: 'studentProgramListContainer', paginationBar: 'studentPaginationBar', resultCount: 'resultCountStudent', sortSelect: 'sortSelectStudent' },
    faculty: { all: facultyPrograms, filtered: [...facultyPrograms], page: 1, listContainer: 'facultyProgramListContainer', paginationBar: 'facultyPaginationBar', resultCount: 'resultCountFaculty', sortSelect: 'sortSelectFaculty' }
  };

  /* ---------- Partner institutions (logo-only, link out) ---------- */
  function renderInstitutions() {
    const grid = document.getElementById('institutionsGridContainer');
    if (!grid) return;
    grid.innerHTML = '';
    if (!institutionList.length) {
      grid.innerHTML = '<div class="programs-empty" style="grid-column:1/-1"><p>Partner institutions will appear here.</p></div>';
      return;
    }
    institutionList.forEach(inst => {
      const card = document.createElement('div');
      card.className = 'institution-card';
      const logoLink = document.createElement(inst.website ? 'a' : 'div');
      logoLink.className = 'institution-logo-link';
      logoLink.title = inst.name;
      if (inst.website) { logoLink.href = inst.website; logoLink.target = '_blank'; logoLink.rel = 'noopener noreferrer'; }
      const logo = document.createElement('div');
      logo.className = 'institution-logo-lg';
      if (inst.logo) {
        const img = document.createElement('img');
        img.src = '../' + inst.logo.replace(/^\/+/, '');
        img.alt = inst.name;
        img.onerror = () => { logo.textContent = initials(inst.name); };
        logo.appendChild(img);
      } else logo.textContent = initials(inst.name);
      logoLink.appendChild(logo);
      const meta = document.createElement('div');
      meta.className = 'institution-name';
      meta.textContent = inst.name;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'institution-profile-btn';
      btn.textContent = inst.programs.length ? `View profile · ${inst.programs.length} program${inst.programs.length !== 1 ? 's' : ''}` : 'View profile';
      btn.onclick = () => openProfile(inst.name);
      card.append(logoLink, meta, btn);
      grid.appendChild(card);
    });
  }

  function openProfile(name) {
    const inst = institutions[name];
    if (!inst) return;
    const logoEl = document.getElementById('profileLogo');
    logoEl.textContent = '';
    if (inst.logo) {
      const img = document.createElement('img');
      img.src = '../' + inst.logo.replace(/^\/+/, '');
      img.alt = inst.name;
      img.onerror = () => { logoEl.textContent = initials(inst.name); };
      logoEl.appendChild(img);
    } else logoEl.textContent = initials(inst.name);

    document.getElementById('profileName').textContent = inst.name;
    document.getElementById('profileLocation').textContent = '📍 ' + inst.country;
    document.getElementById('profileStatPrograms').textContent = inst.programs.length;
    document.getElementById('profileStatTypes').textContent = inst.types.size;
    document.getElementById('profileStatCountry').textContent = inst.country;

    const sc = inst.programs.filter(p => p.audience === 'student').length;
    const fc = inst.programs.filter(p => p.audience === 'faculty').length;
    document.getElementById('profileAbout').textContent =
      `${inst.name} is a partner institution of Pangasinan State University` + (inst.country !== '—' ? ` located in ${inst.country}` : '') + `. ` +
      `Through this partnership, PSU offers ${sc} student program${sc !== 1 ? 's' : ''} and ${fc} faculty program${fc !== 1 ? 's' : ''} with this institution.`;
    document.getElementById('profileProgramCount').textContent = `${inst.programs.length} program${inst.programs.length !== 1 ? 's' : ''} available`;

    if (inst.description) document.getElementById('profileAbout').textContent = inst.description;
    const det = document.getElementById('profileDetails');
    const rows = [['Country', inst.country !== '—' ? inst.country : ''], ['Full address', inst.address], ['Contact person', inst.contact], ['Areas of collaboration', inst.areas]].filter(x => x[1]);
    let html = rows.length ? '<div class="modal-meta">' + rows.map(([k, v]) => `<div class="meta-item"><div class="label">${esc(k)}</div><div class="value" style="font-weight:500">${esc(v)}</div></div>`).join('') + '</div>' : '';
    const links = [];
    if (inst.website) links.push(`<a href="${esc(inst.website)}" target="_blank" rel="noopener noreferrer" class="institution-profile-btn" style="text-decoration:none;display:inline-block">Visit website ↗</a>`);
    if (inst.brochure) links.push(`<a href="../${esc(inst.brochure)}" target="_blank" rel="noopener noreferrer" class="institution-profile-btn" style="text-decoration:none;display:inline-block">Download brochure</a>`);
    if (links.length) html += `<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">${links.join('')}</div>`;
    if (inst.photos && inst.photos.length) html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:16px">' + inst.photos.map(p => `<img src="../${esc(p)}" alt="${esc(inst.name)} photo" loading="lazy" style="width:100%;height:110px;object-fit:cover;border-radius:var(--radius-sm)" onerror="this.remove()">`).join('') + '</div>';
    det.innerHTML = html;

    const list = document.getElementById('profileProgramsList');
    list.innerHTML = '';
    inst.programs.forEach(p => {
      const item = document.createElement('div');
      item.className = 'profile-program-item';
      item.onclick = () => { closeProfileDirect(); openModal(p); };
      const aud = p.audience === 'faculty' ? '👩‍🏫 Faculty' : '🎓 Student';
      item.innerHTML = `
        <div class="profile-program-emoji">${p.emoji}</div>
        <div>
          <div class="profile-program-title">${esc(p.title)}</div>
          <div class="profile-program-meta">${aud} · ${esc(capitalize(p.type))} · ⏱ ${esc(p.duration)} · Deadline: ${esc(p.deadline)}</div>
        </div>`;
      list.appendChild(item);
    });
    document.getElementById('profileOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeProfile(e) { if (e.target === document.getElementById('profileOverlay')) closeProfileDirect(); }
  function closeProfileDirect() {
    document.getElementById('profileOverlay').classList.remove('open');
    if (!document.getElementById('modalOverlay').classList.contains('open')) document.body.style.overflow = '';
  }

  /* ---------- Program listings ---------- */
  function renderPrograms(audience, page) {
    const s = state[audience];
    s.page = page || 1;
    const total = s.filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    const start = (s.page - 1) * PER_PAGE;
    const container = document.getElementById(s.listContainer);
    if (!container) return;
    container.innerHTML = '';

    if (total === 0) {
      container.innerHTML = '<div class="programs-empty"><p>No programs match your filters. Try adjusting your search or clearing filters.</p></div>';
      renderPagination(audience, 0, 1);
      document.getElementById(s.resultCount).textContent = 'No programs found';
      return;
    }

    s.filtered.slice(start, start + PER_PAGE).forEach(p => {
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
              <div class="card-title">${esc(p.title)}</div>
              <a class="card-institution" data-inst="${esc(p.institution)}">🏛 ${esc(p.institution)} · ${esc(p.country)}</a>
            </div>
            <span class="card-deadline${p.urgent ? ' urgent' : ''}">Deadline: ${esc(p.deadline)}</span>
          </div>
          <div class="card-desc">${esc(desc)}</div>
          <div class="card-tags">
            <span class="tag type-${esc(p.type)}">${esc(capitalize(p.type))}</span>
            <span class="tag">⏱ ${esc(p.duration)}</span>
            <span class="tag">👥 ${esc(p.eligible)}</span>
          </div>
        </div>`;
      card.querySelector('.card-institution').onclick = ev => { ev.stopPropagation(); openProfile(p.institution); };
      container.appendChild(card);
    });

    document.getElementById(s.resultCount).textContent = `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} of ${total} programs`;
    renderPagination(audience, totalPages, s.page);
  }

  function renderPagination(audience, totalPages, active) {
    const pg = document.getElementById(state[audience].paginationBar);
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
    if (totalPages <= 7) pages = Array.from({ length: totalPages }, (_, i) => i + 1);
    else {
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
      } else pg.appendChild(btn(p, p, false, p === active));
    });
    pg.appendChild(btn('›', active + 1, active === totalPages, false));
  }

  /* ---------- Program modal: who it's for, dates, nature ---------- */
  let activeProgramForModal = null;
  function openModal(p) {
    activeProgramForModal = p;
    const isFaculty = p.audience === 'faculty';
    const logoEl = document.getElementById('modalLogo');
    logoEl.textContent = p.emoji;
    logoEl.className = 'modal-logo' + (isFaculty ? ' faculty' : '');
    document.getElementById('modalTitle').textContent = p.title;
    document.getElementById('modalInstitution').textContent = `🏛 ${p.institution} · ${p.country} ↗`;
    document.getElementById('modalTags').innerHTML = `
      <span class="tag type-${esc(p.type)}">${esc(capitalize(p.type))}</span>
      <span class="tag">⏱ ${esc(p.duration)}</span>
      <span class="tag">👥 ${esc(p.eligible)}</span>`;
    document.getElementById('modalDesc').textContent = p.desc;
    document.getElementById('modalMeta').innerHTML = `
      <div class="meta-item"><div class="label">Eligible participants</div><div class="value">${esc(p.eligible)}</div></div>
      <div class="meta-item"><div class="label">Partner institution</div><div class="value">${esc(p.institution)}</div></div>
      <div class="meta-item"><div class="label">Application availability</div><div class="value">${esc(p.availability)}</div></div>
      <div class="meta-item"><div class="label">Nature of program</div><div class="value">${esc(capitalize(p.type))}</div></div>
      <div class="meta-item"><div class="label">Program dates</div><div class="value">${esc(formatDate(p.startDate))} – ${esc(formatDate(p.endDate))}</div></div>
      <div class="meta-item"><div class="label">Application period</div><div class="value">${esc(p.applyStart)} – ${esc(p.applyEnd)}</div></div>
      <div class="meta-item"><div class="label">Duration</div><div class="value">${esc(p.duration)}</div></div>
      <div class="meta-item"><div class="label">Country</div><div class="value">${esc(p.country)}</div></div>`;
    const reqs = isFaculty ? FACULTY_REQS : STUDENT_REQS;
    document.getElementById('modalReqs').innerHTML = reqs.map(r => `<li>${esc(r)}</li>`).join('');
    document.getElementById('applyLink').href = 'register.php?program_id=' + encodeURIComponent(p.id) + '&account_type=' + p.audience;
    document.getElementById('modalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function openInstitutionFromModal(e) {
    e.preventDefault();
    if (!activeProgramForModal) return;
    const n = activeProgramForModal.institution;
    closeModalDirect();
    openProfile(n);
  }
  function closeModal(e) { if (e.target === document.getElementById('modalOverlay')) closeModalDirect(); }
  function closeModalDirect() {
    document.getElementById('modalOverlay').classList.remove('open');
    if (!document.getElementById('profileOverlay').classList.contains('open')) document.body.style.overflow = '';
  }

  /* ---------- Search, filters, sort ---------- */
  function applySearchToAudience(audience) {
    const s = state[audience];
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const globalType = document.getElementById('typeFilter').value;
    const checked = f => [...document.querySelectorAll(`input[type=checkbox][data-filter="${f}"][data-audience="${audience}"]:checked`)].map(c => c.value);
    const checkedTypes = checked('type');
    const checkedDurations = checked('duration');
    const countryEl = document.getElementById(audience === 'student' ? 'countryFilterStudent' : 'countryFilterFaculty');
    const countryVal = countryEl ? countryEl.value : '';

    s.filtered = s.all.filter(p =>
      (!q || p.title.toLowerCase().includes(q) || p.country.toLowerCase().includes(q) || p.institution.toLowerCase().includes(q) || p.type.includes(q)) &&
      (!globalType || p.type === globalType) &&
      (!checkedTypes.length || checkedTypes.includes(p.type)) &&
      (!checkedDurations.length || checkedDurations.includes(p.duration)) &&
      (!countryVal || p.country === countryVal)
    );
    renderPrograms(audience, 1);
  }
  function applySearch() { applySearchToAudience('student'); applySearchToAudience('faculty'); }
  function filterCards(audience) { applySearchToAudience(audience); }
  function clearFilters(audience) {
    document.querySelectorAll(`input[type=checkbox][data-audience="${audience}"]`).forEach(cb => cb.checked = false);
    const countryEl = document.getElementById(audience === 'student' ? 'countryFilterStudent' : 'countryFilterFaculty');
    if (countryEl) countryEl.value = '';
    document.getElementById('searchInput').value = '';
    document.getElementById('typeFilter').value = '';
    applySearch();
  }
  function bindSort(audience) {
    const s = state[audience];
    const el = document.getElementById(s.sortSelect);
    if (!el) return;
    el.addEventListener('change', function () {
      const far = new Date(8640000000000000);
      const sorted = [...s.filtered];
      if (this.value === 'az') sorted.sort((a, b) => a.title.localeCompare(b.title));
      else sorted.sort((a, b) => (a.deadlineRaw ? new Date(a.deadlineRaw) : far) - (b.deadlineRaw ? new Date(b.deadlineRaw) : far));
      s.filtered = sorted;
      renderPrograms(audience, 1);
    });
  }

  /* ---------- Nav: shrink on scroll, mobile toggle, scroll-spy highlight ---------- */
  (function () {
    const wrap = document.getElementById('navWrapper');
    window.addEventListener('scroll', () => wrap.classList.toggle('scrolled', window.scrollY > 40), { passive: true });

    const links = document.getElementById('navLinks');
    document.getElementById('navToggle').addEventListener('click', () => links.classList.toggle('open'));
    links.addEventListener('click', e => { if (e.target.closest('a')) links.classList.remove('open'); });

    const ids = ['overview', 'about', 'announcements', 'studentPrograms', 'facultyPrograms', 'institutionsGrid'];
    const sections = ids.map(id => document.getElementById(id)).filter(Boolean);
    const groupOf = id => (id === 'studentPrograms' || id === 'facultyPrograms') ? 'programs'
                        : id === 'announcements' ? 'overview' : id;
    function setActive(id) {
      document.querySelectorAll('[data-spy]').forEach(a => a.classList.remove('active'));
      document.querySelectorAll(`[data-spy="${id}"], [data-spy="${groupOf(id)}"]`).forEach(a => a.classList.add('active'));
    }
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) setActive(e.target.id); });
      }, { rootMargin: '-40% 0px -55% 0px' });
      sections.forEach(s => io.observe(s));
    }
    setActive('overview');
  })();

  document.getElementById('searchInput').addEventListener('keydown', e => { if (e.key === 'Enter') applySearch(); });
  document.getElementById('typeFilter').addEventListener('change', applySearch);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModalDirect(); closeProfileDirect(); } });

  renderInstitutions();
  renderPrograms('student', 1);
  renderPrograms('faculty', 1);
  bindSort('student');
  bindSort('faculty');
</script>
</body>
</html>