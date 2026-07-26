<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$programs = [];
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
        c.country_name,
        pr.institution_name
    FROM programs p
    LEFT JOIN countries c
        ON p.country_id = c.id
    LEFT JOIN partners pr
        ON p.partner_id = pr.id
    WHERE p.status = 'Active'
    ORDER BY p.id DESC
    ");

    $stmt->execute();

    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $ex) {
    // Don't let a query failure blank out the whole page — show a
    // friendly message instead and log the real error for debugging.
    error_log('browse_program.php query failed: ' . $ex->getMessage());
    $loadError = 'We ran into a problem loading programs. Please try again shortly.';
}

// Counts for the sidebar / hero stats (computed from real data instead of hardcoded numbers)
$typeCounts = ['exchange' => 0, 'internship' => 0, 'research' => 0, 'training' => 0];
$countrySet = [];
foreach ($programs as $p) {
    $t = strtolower($p['program_type'] ?? '');
    if (isset($typeCounts[$t])) {
        $typeCounts[$t]++;
    }
    if (!empty($p['country_name'])) {
        $countrySet[$p['country_name']] = true;
    }
}
$totalPrograms = count($programs);
$totalCountries = count($countrySet);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>PSU Internalization — Student Programs Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../asset/css/website.css">

</head>
<body>

<!-- NAV -->
<nav>
  <div class="nav-logo">PSU <span>Internalization</span></div>
  <ul class="nav-links">
    <li><a href="browse_program.php">Browse Programs</a></li>
    <li><a href="partner_ins.php">Partner Institutions</a></li>
    <li><a href="how_it_works.php">How It Works</a></li>
    <li><a href="register.php" class="nav-cta">Log in Now</a></li>
  </ul>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <div class="hero-eyebrow">Student Mobility &amp; Internship Portal</div>
    <h1>Find your next program, <em>abroad</em>.</h1>
    <p>Discover internships, exchange programs, and research opportunities from partner institutions around the world — open to PSU students.</p>
    <div class="search-bar">
      <input type="text" placeholder="Search by program name, country, or field…" id="searchInput"/>
      <select id="typeFilter">
        <option value="">All Types</option>
        <option value="exchange">Exchange</option>
        <option value="internship">Internship</option>
        <option value="research">Research</option>
        <option value="training">Training</option>
      </select>
      <button onclick="applySearch()">Search Programs</button>
    </div>
    <div class="stats-strip">
      <div class="stat"><strong><?= $totalPrograms ?></strong> Active Programs</div>
      <div class="stat"><strong><?= $totalCountries ?></strong> Partner Countries</div>
      <div class="stat"><strong>130+</strong> Students Placed</div>
    </div>
  </div>
</section>

<!-- MAIN -->
<div class="page-body">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="filter-card">
      <h3>Program Type</h3>
      <div class="filter-group">
        <label><input type="checkbox" data-filter="type" value="exchange" onchange="filterCards()"> Student Exchange <span class="badge-count"><?= $typeCounts['exchange'] ?></span></label>
        <label><input type="checkbox" data-filter="type" value="internship" onchange="filterCards()"> Internship <span class="badge-count"><?= $typeCounts['internship'] ?></span></label>
        <label><input type="checkbox" data-filter="type" value="research" onchange="filterCards()"> Research <span class="badge-count"><?= $typeCounts['research'] ?></span></label>
        <label><input type="checkbox" data-filter="type" value="training" onchange="filterCards()"> Training <span class="badge-count"><?= $typeCounts['training'] ?></span></label>
      </div>
    </div>

    <div class="filter-card">
      <h3>Country / Region</h3>
      <select id="countryFilter" onchange="filterCards()">
        <option value="">All Countries</option>
        <?php foreach (array_keys($countrySet) as $countryName): ?>
          <option><?= htmlspecialchars($countryName) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-card">
      <h3>Duration</h3>
      <div class="filter-group">
        <label><input type="checkbox" onchange="filterCards()"> 1 – 3 months</label>
        <label><input type="checkbox" onchange="filterCards()"> 4 – 6 months</label>
        <label><input type="checkbox" onchange="filterCards()"> 1 semester</label>
        <label><input type="checkbox" onchange="filterCards()"> 1 academic year</label>
      </div>
    </div>

    <div class="filter-card">
      <h3>Funding</h3>
      <div class="filter-group">
        <label><input type="checkbox" data-filter="funding" value="full scholarship" onchange="filterCards()"> With Scholarship</label>
        <label><input type="checkbox" data-filter="funding" value="partial" onchange="filterCards()"> Partially Funded</label>
        <label><input type="checkbox" data-filter="funding" value="self-funded" onchange="filterCards()"> Self-funded</label>
      </div>
    </div>

    <button class="clear-btn" onclick="clearFilters()">Clear all filters</button>
  </aside>

  <!-- LISTINGS -->
  <main>
    <div class="listings-header">
      <div>
        <h2>Available Programs</h2>
        <span id="resultCount">Showing <?= $totalPrograms ?> of <?= $totalPrograms ?> programs</span>
      </div>
      <select class="sort-select" id="sortSelect">
        <option value="deadline">Sort: Deadline (Soonest)</option>
        <option value="slots">Sort: Most Slots</option>
        <option value="az">Sort: A → Z</option>
      </select>
    </div>

    <?php if ($loadError): ?>

      <div class="programs-empty">
        <p><?= htmlspecialchars($loadError) ?></p>
      </div>

    <?php elseif ($totalPrograms === 0): ?>

      <div class="programs-empty">
        <p>No programs are available right now. Please check back soon.</p>
      </div>

    <?php else: ?>

      <!-- Cards are rendered client-side by renderPrograms() below -->
      <div id="programList"></div>

      <div class="pagination" id="paginationBar"></div>

    <?php endif; ?>
  </main>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModal(event)">
  <div class="modal" id="modalBox">
    <div class="modal-header">
      <div class="modal-logo" id="modalLogo"></div>
      <div>
        <div class="modal-title" id="modalTitle"></div>
        <div class="modal-institution" id="modalInstitution"></div>
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
      <a href="#" id="applyLink" class="apply-btn">Apply for this Program</a>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <strong>PSU Internalization Management System</strong><br/>
  Pangasinan State University · Office of International Affairs · <a href="#" style="color:rgba(255,255,255,.55)">Contact Us</a>
</footer>

<?php if (!$loadError && $totalPrograms > 0): ?>
<script>

  // ---- Raw data from the database ----
  const rawPrograms = <?= json_encode($programs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const TYPE_EMOJI = {
    exchange: '🔄',
    internship: '💼',
    research: '🔬'
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

  // ---- Normalize DB rows into the shape the UI expects ----
const programs = rawPrograms.map(p => {

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
        urgent: isUrgent(p.end_date)
    };

});

  const PER_PAGE = 8;
  let currentPage = 1;
  let filteredPrograms = [...programs];

  function renderPrograms(list, page) {
    currentPage = page || 1;
    filteredPrograms = list;
    const total = list.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    const start = (currentPage - 1) * PER_PAGE;
    const pageItems = list.slice(start, start + PER_PAGE);

    const container = document.getElementById('programList');
    container.innerHTML = '';

    if (total === 0) {
      container.innerHTML = '<div style="text-align:center;padding:60px 0;color:var(--gray-500);">No programs match your filters. Try adjusting your search.</div>';
      renderPagination(0, 1);
      document.getElementById('resultCount').textContent = 'No programs found';
      return;
    }

    pageItems.forEach(p => {

        const desc =
            p.desc.length > 120
                ? p.desc.substring(0, 120) + "..."
                : p.desc;

        const card = document.createElement('div');
        card.className = 'program-card' + (p.featured ? ' featured' : '');
        card.onclick = () => openModal(p);

        card.innerHTML = `
            <div class="card-logo">${p.emoji}</div>

            <div class="card-body">

                <div class="card-top">
                    <div>
                        <div class="card-title">${p.title}</div>
                        <div class="card-institution">${p.institution} · ${p.country}</div>
                    </div>

                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                        ${p.featured ? '<span class="featured-badge">FEATURED</span>' : ''}
                        <span class="card-deadline${p.urgent ? ' urgent' : ''}">
                            Deadline: ${p.deadline}
                        </span>
                    </div>
                </div>

                <div class="card-desc">${desc}</div>

                    <div class="card-tags">
                        <span class="tag type-${p.type}">
                            ${capitalize(p.type)}
                        </span>

                        <span class="tag">
                            ⏱ ${p.duration}
                        </span>
                    </div>

            </div>
        `;

        container.appendChild(card);
    });

    document.getElementById('resultCount').textContent =
      `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} of ${total} programs`;

    renderPagination(totalPages, currentPage);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function renderPagination(totalPages, active) {
    const pg = document.getElementById('paginationBar');
    pg.innerHTML = '';
    if (totalPages <= 1) return;

    const btn = (label, page, disabled, isActive) => {
      const b = document.createElement('button');
      b.className = 'page-btn' + (isActive ? ' active' : '');
      b.textContent = label;
      b.disabled = disabled;
      if (!disabled && !isActive) b.onclick = () => renderPrograms(filteredPrograms, page);
      return b;
    };

    pg.appendChild(btn('‹', active - 1, active === 1, false));

    let pages = [];
    if (totalPages <= 7) {
      pages = Array.from({length: totalPages}, (_, i) => i + 1);
    } else {
      pages = [1];
      if (active > 3) pages.push('…');
      for (let i = Math.max(2, active - 1); i <= Math.min(totalPages - 1, active + 1); i++) pages.push(i);
      if (active < totalPages - 2) pages.push('…');
      pages.push(totalPages);
    }

    pages.forEach(p => {
      if (p === '…') {
        const s = document.createElement('span');
        s.textContent = '…';
        s.style.cssText = 'padding:0 6px;color:var(--gray-500);line-height:36px;';
        pg.appendChild(s);
      } else {
        pg.appendChild(btn(p, p, false, p === active));
      }
    });

    pg.appendChild(btn('›', active + 1, active === totalPages, false));
  }

  function openModal(p) {
    document.getElementById('modalLogo').textContent = p.emoji;
    document.getElementById('modalTitle').textContent = p.title;
    document.getElementById('modalInstitution').textContent = p.institution + ' · ' + p.country;
    document.getElementById('modalTags').innerHTML = `
      <span class="tag type-${p.type}">${capitalize(p.type)}</span>
      <span class="tag">⏱ ${p.duration}</span>`;
    document.getElementById('modalDesc').textContent = p.desc;
    document.getElementById('modalMeta').innerHTML = `
      <div class="meta-item"><div class="label">Duration</div><div class="value">${p.duration}</div></div>
      <div class="meta-item"><div class="label">Application Deadline</div><div class="value">${p.deadline}</div></div>
      <div class="meta-item"><div class="label">Country</div><div class="value">${p.country}</div></div>`;
    // Wire the Apply button to the real application flow for this program
    document.getElementById('applyLink').href = '?page=apply_program&program_id=' + p.id;
    document.getElementById('modalOverlay').classList.add('open');
  }

  function closeModal(e) {
    if (e.target === document.getElementById('modalOverlay')) closeModalDirect();
  }
  function closeModalDirect() {
    document.getElementById('modalOverlay').classList.remove('open');
  }

  function applySearch() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const type = document.getElementById('typeFilter').value;
    const checkedTypes = [...document.querySelectorAll('.filter-group input[type=checkbox][data-filter=type]:checked')].map(c => c.value);
    const countryVal = document.getElementById('countryFilter').value;

    const filtered = programs.filter(p => {
      const matchQ = !q || p.title.toLowerCase().includes(q) || p.country.toLowerCase().includes(q) || p.institution.toLowerCase().includes(q);
      const matchType = (!type || p.type === type) && (checkedTypes.length === 0 || checkedTypes.includes(p.type));
      const matchCountry = !countryVal || p.country === countryVal;
      return matchQ && matchType && matchCountry;
    });
    renderPrograms(filtered, 1);
  }

  function filterCards() { applySearch(); }

  function clearFilters() {
    document.querySelectorAll('.filter-group input[type=checkbox]').forEach(cb => cb.checked = false);
    document.getElementById('searchInput').value = '';
    document.getElementById('typeFilter').value = '';
    document.getElementById('countryFilter').value = '';
    renderPrograms(programs, 1);
  }

  function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  document.getElementById('searchInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') applySearch();
  });

  // Sort
  document.getElementById('sortSelect').addEventListener('change', function() {
    const val = this.value;
    const sorted = [...filteredPrograms];
    sorted.sort((a, b) => {
      const da = a.endDate ? new Date(a.endDate) : new Date(8640000000000000);
      const db = b.endDate ? new Date(b.endDate) : new Date(8640000000000000);
      return da - db;
    });
    if (val === 'az') sorted.sort((a, b) => a.title.localeCompare(b.title));
    renderPrograms(sorted, 1);
  });

  renderPrograms(programs, 1);
</script>
<?php endif; ?>
</body>
</html>