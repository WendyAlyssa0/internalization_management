<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$studentPrograms = [];
$facultyPrograms = [];
$nonTeachingPrograms = [];
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
        // Each participant group has its own category; a program open to several groups appears in each
        if (in_array('faculty', $eligibleTypes, true)) $facultyPrograms[] = $p;
        if (in_array('non-teaching', $eligibleTypes, true)) $nonTeachingPrograms[] = $p;
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
$nonTeachingStats = buildStats($nonTeachingPrograms);

// Distinct programs: one open to both students and faculty must count once
$distinctIds = [];
foreach (array_merge($studentPrograms, $facultyPrograms, $nonTeachingPrograms) as $p) $distinctIds[$p['id']] = true;
$totalAll = count($distinctIds);
$allCountries = array_unique(array_merge($studentStats['countries'], $facultyStats['countries'], $nonTeachingStats['countries']));
$totalCountries = count($allCountries);

$allInstitutions = [];
foreach (array_merge($studentPrograms, $facultyPrograms, $nonTeachingPrograms) as $p) {
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
$nonTeachingRequirements = [
    'Must be a PSU non-teaching personnel',
    'Completed application form with office/unit endorsement',
    'Other requirements as specified by the International Affairs office',
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
<link rel="stylesheet" href="../asset/css/website.css">
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
      <li><a href="#announcements" class="nav-link" data-spy="announcements">Announcements</a></li>
      <li class="nav-dropdown">
        <a href="#studentPrograms" class="nav-link" data-spy="programs">Programs ▾</a>
        <ul class="dropdown-menu">
          <li><a href="#studentPrograms" data-spy="studentPrograms">Student Programs</a></li>
          <li><a href="#facultyPrograms" data-spy="facultyPrograms">Faculty Programs</a></li>
          <li><a href="#nonTeachingPrograms" data-spy="nonTeachingPrograms">Non-teaching Programs</a></li>
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
      <p>Teaching exchanges, research collaborations, and faculty development opportunities with our partner institutions.</p>
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

<!-- NON-TEACHING PERSONNEL PROGRAMS -->
<section class="audience-section" id="nonTeachingPrograms">
  <div class="audience-banner nonteaching">
    <div class="audience-banner-icon">🧑‍💼</div>
    <div class="audience-banner-text">
      <h2>Programs for Non-teaching Personnel</h2>
      <p>Training, exchanges, internships, and development opportunities open to PSU non-teaching personnel and staff.</p>
    </div>
    <div class="audience-banner-count"><?= (int)$nonTeachingStats['totalPrograms'] ?><small>Programs</small></div>
  </div>

  <div class="page-body">
    <aside class="sidebar">
      <div class="filter-card">
        <h3>Program Type</h3>
        <div class="filter-group">
          <?php foreach (['exchange' => 'Exchange', 'internship' => 'Internship', 'research' => 'Research', 'training' => 'Training'] as $v => $label): ?>
            <label><input type="checkbox" data-filter="type" data-audience="non-teaching" value="<?= $v ?>" onchange="filterCards('non-teaching')"> <?= $label ?> <span class="badge-count"><?= (int)$nonTeachingStats['typeCounts'][$v] ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="filter-card">
        <h3>Country / Region</h3>
        <select id="countryFilterNonTeaching" onchange="filterCards('non-teaching')">
          <option value="">All Countries</option>
          <?php foreach ($nonTeachingStats['countries'] as $countryName): ?>
            <option><?= htmlspecialchars($countryName) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-card">
        <h3>Duration</h3>
        <div class="filter-group">
          <?php foreach (['1 – 3 months', '4 – 6 months', '1 semester', '1 academic year'] as $d): ?>
            <label><input type="checkbox" data-filter="duration" data-audience="non-teaching" value="<?= $d ?>" onchange="filterCards('non-teaching')"> <?= $d ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="clear-btn" onclick="clearFilters('non-teaching')">Clear all filters</button>
    </aside>

    <main>
      <div class="listings-header">
        <div>
          <h2>Available Non-teaching Programs</h2>
          <span id="resultCountNonTeaching">Showing <?= (int)$nonTeachingStats['totalPrograms'] ?> of <?= (int)$nonTeachingStats['totalPrograms'] ?> programs</span>
        </div>
        <select class="sort-select" id="sortSelectNonTeaching">
          <option value="deadline">Sort: Deadline (Soonest)</option>
          <option value="az">Sort: A → Z</option>
        </select>
      </div>
      <div id="nonTeachingProgramListContainer"></div>
      <div class="pagination" id="nonTeachingPaginationBar"></div>
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
          <li><a href="#nonTeachingPrograms">Non-teaching Programs</a></li>
          <li><a href="#institutionsGrid">Partner Institutions</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h5>Support</h5>
        <ul><li><a href="#">Contact Us</a></li><li><a href="#">FAQ</a></li><li><a href="#">Student Handbook</a></li></ul>
      </div>
      <div class="footer-col">
        <h5>Connect</h5>
        <ul><li><a href="#">Facebook</a></li><li><a href="#">Instagram</a></li></ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?= date('Y') ?> Pangasinan State University · Office of International Affairs. All rights reserved.</p>
      <div class="footer-socials">
        <a href="#" title="Facebook">f</a><a href="#" title="Instagram">ig</a>
      </div>
    </div>
  </div>
</footer>

<script>
  const rawStudentPrograms = <?= json_encode($studentPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawFacultyPrograms = <?= json_encode($facultyPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawNonTeachingPrograms = <?= json_encode($nonTeachingPrograms, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const rawPartners = <?= json_encode($partnersForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const NONTEACHING_REQS = <?= json_encode($nonTeachingRequirements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
      eligibleList: p.eligible_list || [],
      availability: availabilityLabel(p),
      emoji: TYPE_EMOJI[type] || '🎓',
      urgent: isUrgent(deadlineRaw),
      audience: audience
    };
  }

  const studentPrograms = rawStudentPrograms.map(p => normalizeProgram(p, 'student'));
  const facultyPrograms = rawFacultyPrograms.map(p => normalizeProgram(p, 'faculty'));
  const nonTeachingPrograms = rawNonTeachingPrograms.map(p => normalizeProgram(p, 'non-teaching'));
  const COUNTRY_FILTER = { student: 'countryFilterStudent', faculty: 'countryFilterFaculty', 'non-teaching': 'countryFilterNonTeaching' };
  const AUD_LABEL = { student: '🎓 Student', faculty: '👩‍🏫 Faculty', 'non-teaching': '🧑‍💼 Non-teaching' };

  // Institutions: every partner from the database, with their programs attached
  const institutions = {};
  rawPartners.forEach(pt => {
    institutions[pt.name] = { ...pt, country: pt.country || '—', programs: [], types: new Set() };
  });
  [...studentPrograms, ...facultyPrograms, ...nonTeachingPrograms].forEach(p => {
    if (!institutions[p.institution]) {
      institutions[p.institution] = { name: p.institution, country: p.country, logo: '', website: '', programs: [], types: new Set() };
    }
    const inst = institutions[p.institution];
    if (inst.country === '—') inst.country = p.country;
    const dup = inst.programs.find(x => x.id === p.id);
    if (dup) { dup.audiences.add(p.audience); return; }
    p.audiences = new Set([p.audience]);
    inst.programs.push(p);
    inst.types.add(p.type);
  });
  const institutionList = Object.values(institutions);

  const PER_PAGE = 4;
  const state = {
    student: { all: studentPrograms, filtered: [...studentPrograms], page: 1, listContainer: 'studentProgramListContainer', paginationBar: 'studentPaginationBar', resultCount: 'resultCountStudent', sortSelect: 'sortSelectStudent' },
    faculty: { all: facultyPrograms, filtered: [...facultyPrograms], page: 1, listContainer: 'facultyProgramListContainer', paginationBar: 'facultyPaginationBar', resultCount: 'resultCountFaculty', sortSelect: 'sortSelectFaculty' },
    'non-teaching': { all: nonTeachingPrograms, filtered: [...nonTeachingPrograms], page: 1, listContainer: 'nonTeachingProgramListContainer', paginationBar: 'nonTeachingPaginationBar', resultCount: 'resultCountNonTeaching', sortSelect: 'sortSelectNonTeaching' }
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

    const sc = inst.programs.filter(p => p.audiences.has('student')).length;
    const fc = inst.programs.filter(p => p.audiences.has('faculty')).length;
    const nc = inst.programs.filter(p => p.audiences.has('non-teaching')).length;
    document.getElementById('profileAbout').textContent =
      `${inst.name} is a partner institution of Pangasinan State University` + (inst.country !== '—' ? ` located in ${inst.country}` : '') + `. ` +
      `Through this partnership, PSU offers ${sc} student program${sc !== 1 ? 's' : ''}, ${fc} faculty program${fc !== 1 ? 's' : ''} and ${nc} non-teaching program${nc !== 1 ? 's' : ''} with this institution.`;
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
      const aud = [...p.audiences].map(a => AUD_LABEL[a]).join(' + ');
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
            <span class="tag">📅 ${esc(p.availability)}</span>
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
    const reqs = p.audience === 'student' ? STUDENT_REQS : p.audience === 'non-teaching' ? NONTEACHING_REQS : FACULTY_REQS;
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
    const countryEl = document.getElementById(COUNTRY_FILTER[audience]);
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
  function applySearch() { applySearchToAudience('student'); applySearchToAudience('faculty'); applySearchToAudience('non-teaching'); }
  function filterCards(audience) { applySearchToAudience(audience); }
  function clearFilters(audience) {
    document.querySelectorAll(`input[type=checkbox][data-audience="${audience}"]`).forEach(cb => cb.checked = false);
    const countryEl = document.getElementById(COUNTRY_FILTER[audience]);
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

    const ids = ['overview', 'about', 'announcements', 'studentPrograms', 'facultyPrograms', 'nonTeachingPrograms', 'institutionsGrid'];
    const sections = ids.map(id => document.getElementById(id)).filter(Boolean);
    const groupOf = id => (id === 'studentPrograms' || id === 'facultyPrograms' || id === 'nonTeachingPrograms') ? 'programs'
                        : id;
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
  renderPrograms('non-teaching', 1);
  bindSort('student');
  bindSort('faculty');
  bindSort('non-teaching');
</script>
</body>
</html>