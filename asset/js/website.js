/* =========================================
   PSU INTERNATIONALIZATION — MAIN JS
   ========================================= */

// ── Scroll effect for header ──
const header = document.getElementById('siteHeader');
if (header) {
  window.addEventListener('scroll', () => {
    header.classList.toggle('scrolled', window.scrollY > 20);
  }, { passive: true });
}

// ── Mobile menu toggle ──
document.querySelector('.menu-button')?.addEventListener('click', function () {
  const isOpen = document.body.classList.toggle('menu-open');
  this.setAttribute('aria-expanded', isOpen);
});

// Close mobile menu when a nav link is clicked
document.querySelectorAll('.main-nav a').forEach(link => {
  link.addEventListener('click', () => {
    document.body.classList.remove('menu-open');
  });
});


document.addEventListener("DOMContentLoaded", () => {
  /* ── NAV ACTIVE STATE ── */
  const navLinks = document.querySelectorAll(".main-nav a");
  navLinks.forEach((link) => {
    link.addEventListener("click", () => {
      navLinks.forEach((navLink) => navLink.classList.remove("active"));
      link.classList.add("active");
    });
  });

  /* ── CONFIG ── */
  const PER_PAGE = 8;
  const allPrograms = Array.isArray(window.PROGRAMS) ? window.PROGRAMS : [];

  let filtered = [...allPrograms];
  let currentPage = 1;
  let currentSort = "deadline";

  /* ── DOM REFERENCES ── */
  const programList = document.getElementById("programList");
  const paginationBar = document.getElementById("pagination");
  const resultSummary = document.getElementById("resultSummary");
  const programModal = document.getElementById("programModal");
  const modalClose = document.getElementById("modalClose");
  const modalCancel = document.getElementById("modalCancel");

  const modalProgramType   = document.getElementById("modalProgramType");
  const modalProgramTitle  = document.getElementById("modalProgramTitle");
  const modalInstitution   = document.getElementById("modalInstitution");
  const modalDescription   = document.getElementById("modalDescription");
  const modalCountry       = document.getElementById("modalCountry");
  const modalDuration      = document.getElementById("modalDuration");
  const modalStartDate     = document.getElementById("modalStartDate");
  const modalSlots         = document.getElementById("modalSlots");

  if (!programList || !paginationBar || !resultSummary) {
    console.error("Required elements missing:", { programList, paginationBar, resultSummary });
    return;
  }

  /* ── TYPE META ── */
  const typeIcons = {
    Exchange: "fa-earth-asia",
    "Student Exchange": "fa-earth-asia",
    "Faculty Exchange": "fa-earth-asia",
    Internship: "fa-building",
    Research: "fa-wand-magic-sparkles",
    "Short-term Program": "fa-landmark",
    "Summer Program": "fa-sun",
    Volunteering: "fa-hand-holding-heart",
  };

  const typeColors = {
    Exchange: "blue",
    "Student Exchange": "blue",
    "Faculty Exchange": "blue",
    Internship: "gold",
    Research: "green",
    "Short-term Program": "orange",
    "Summer Program": "orange",
    Volunteering: "rose",
  };

  /* ── HELPERS ── */
  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
  }

  function normalize(value) {
    return String(value ?? "").trim().toLowerCase();
  }

  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function formatDate(isoDate) {
    if (!isoDate) return "TBA";
    const date = new Date(`${isoDate}T00:00:00`);
    if (Number.isNaN(date.getTime())) return "TBA";
    return date.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
  }

  function getIcon(type) {
    const t = normalize(type);
    if (t.includes("exchange")) return "fa-earth-asia";
    if (t.includes("intern"))   return "fa-building";
    if (t.includes("research")) return "fa-wand-magic-sparkles";
    if (t.includes("short") || t.includes("summer")) return "fa-landmark";
    if (t.includes("volunteer")) return "fa-hand-holding-heart";
    return typeIcons[type] || "fa-circle";
  }

  function getColor(type) {
    const t = normalize(type);
    if (t.includes("exchange")) return "blue";
    if (t.includes("intern"))   return "gold";
    if (t.includes("research")) return "green";
    if (t.includes("short") || t.includes("summer")) return "orange";
    if (t.includes("volunteer")) return "rose";
    return typeColors[type] || "blue";
  }

  function inDurationBucket(months, bucket) {
    const m = Number(months);
    if (!Number.isFinite(m)) return false;
    if (bucket === "1-3")  return m >= 1 && m <= 3;
    if (bucket === "3-6")  return m > 3 && m <= 6;
    if (bucket === "6-12") return m > 6 && m <= 12;
    if (bucket === "12+")  return m > 12;
    return false;
  }

  function selectedFilterValues(name) {
    return Array.from(document.querySelectorAll(`input[data-filter="${name}"]:checked`))
      .map(cb => normalize(cb.value));
  }

  function programMatchesType(programType, filters) {
    if (filters.length === 0) return true;
    const type = normalize(programType);
    return filters.some(f => {
      if (type === f) return true;
      if (f === "exchange" && type.includes("exchange")) return true;
      if (f === "short-term program" && (type.includes("short") || type.includes("summer"))) return true;
      return type.includes(f);
    });
  }

  /* ── FILTER + SORT ── */
  function applyFilters() {
    const searchInput    = document.getElementById("searchInput");
    const typeSelect     = document.getElementById("typeSelect");
    const countrySelect  = document.getElementById("countrySelect");

    const searchText      = normalize(searchInput?.value);
    const selectedType    = normalize(typeSelect?.value);
    const selectedCountry = normalize(countrySelect?.value);

    const checkedTypes     = selectedFilterValues("type");
    const checkedCountries = selectedFilterValues("country");
    const checkedDurations = selectedFilterValues("duration");

    filtered = allPrograms.filter(program => {
      // Short-circuit text search
      if (searchText) {
        const searchable = [
          normalize(program.title),
          normalize(program.type),
          normalize(program.country),
          normalize(program.institution),
          normalize(program.description),
        ].join(" ");
        if (!searchable.includes(searchText)) return false;
      }

      // Short-circuit type filter
      if (selectedType && !programMatchesType(program.type, [selectedType])) return false;
      if (checkedTypes.length > 0 && !programMatchesType(program.type, checkedTypes)) return false;

      // Short-circuit country filter
      const country = normalize(program.country);
      if (selectedCountry && country !== selectedCountry) return false;
      if (checkedCountries.length > 0 && !checkedCountries.includes(country)) return false;

      // Short-circuit duration filter
      if (checkedDurations.length > 0 &&
          !checkedDurations.some(b => inDurationBucket(program.durationMonths, b))) return false;

      return true;
    });

    currentPage = 1;
    sortPrograms(false);
    renderPrograms();
  }

  function sortPrograms(render = true) {
    filtered.sort((a, b) => {
      if (currentSort === "title") {
        return String(a.title ?? "").localeCompare(String(b.title ?? ""));
      }
      const aTime = a.deadline ? new Date(`${a.deadline}T00:00:00`).getTime() : Number.MAX_SAFE_INTEGER;
      const bTime = b.deadline ? new Date(`${b.deadline}T00:00:00`).getTime() : Number.MAX_SAFE_INTEGER;
      return aTime - bTime;
    });
    if (render) renderPrograms();
  }

  /* ── RENDER ── */
  function renderPrograms() {
    const total      = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (currentPage > totalPages) currentPage = totalPages;

    const start     = (currentPage - 1) * PER_PAGE;
    const pageItems = filtered.slice(start, start + PER_PAGE);

    programList.innerHTML = "";
    paginationBar.innerHTML = "";

    if (total === 0) {
      resultSummary.textContent = "No programs found";
      programList.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
          <strong>No programs found</strong>
          <span>Try changing your filters or searching for another program.</span>
          <button type="button" id="emptyClear">Clear filters</button>
        </div>`;
      document.getElementById("emptyClear")?.addEventListener("click", clearFilters);
      return;
    }

    const fragment = document.createDocumentFragment();

    pageItems.forEach(program => {
      const card = document.createElement("article");
      card.className = "program-card";

      const safeTitle       = escapeHtml(program.title || "Untitled Program");
      const safeType        = escapeHtml(program.type || "Program");
      const safeCountry     = escapeHtml(program.country || "Country TBA");
      const safeInstitution = escapeHtml(program.institution || "Partner institution");
      const safeDescription = escapeHtml(program.description || "No description available.");
      const safeDuration    = escapeHtml(program.duration || "Duration TBA");
      const safeDate        = escapeHtml(formatDate(program.deadline));

      const icon  = getIcon(program.type);
      const color = getColor(program.type);

      card.innerHTML = `
        <div class="card-content">
          <div class="card-top">
            <span class="card-type-badge ${color}">
              <i class="fa-solid ${icon}" aria-hidden="true"></i>
              ${safeType}
            </span>
            <h3 class="card-title">${safeTitle}</h3>
            <p class="card-institution">
              <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
              ${safeInstitution}
            </p>
          </div>
          <p class="card-desc">${safeDescription}</p>
          <div class="card-tags">
            <span class="tag country">
              <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
              ${safeCountry}
            </span>
            <span class="tag duration">
              <i class="fa-regular fa-clock" aria-hidden="true"></i>
              ${safeDuration}
            </span>
          </div>
          <div class="card-meta">
            <span class="card-meta-item">
              <i class="fa-regular fa-calendar" aria-hidden="true"></i>
              <strong>${safeDate}</strong>
            </span>
            <button
              type="button"
              class="card-deadline details-button"
              data-program-id="${Number(program.id) || 0}"
            >
              Details <i class="fa-solid fa-arrow-right"></i>
            </button>
          </div>
        </div>`;
      fragment.appendChild(card);
    });

    programList.appendChild(fragment);

    resultSummary.textContent =
      `Showing ${start + 1}–${Math.min(start + PER_PAGE, total)} ` +
      `of ${total} program${total === 1 ? "" : "s"}`;

    renderPagination(totalPages);
  }

  function renderPagination(totalPages) {
    paginationBar.innerHTML = "";
    if (totalPages <= 1) return;

    function createPageButton(label, page, disabled = false, active = false) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = `page-btn${active ? " active" : ""}`;
      btn.innerHTML = label;
      btn.disabled = disabled;
      if (!disabled && !active) {
        btn.addEventListener("click", () => {
          currentPage = page;
          renderPrograms();
          document.getElementById("programs")?.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }
      return btn;
    }

    paginationBar.appendChild(createPageButton('<i class="fa-solid fa-chevron-left"></i>', currentPage - 1, currentPage === 1));
    for (let p = 1; p <= totalPages; p++) {
      paginationBar.appendChild(createPageButton(p, p, false, p === currentPage));
    }
    paginationBar.appendChild(createPageButton('<i class="fa-solid fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages));
  }

  /* ── CLEAR FILTERS ── */
  function clearFilters() {
    document.querySelectorAll("input[type=checkbox][data-filter]").forEach(cb => cb.checked = false);

    const ids = ["searchInput", "typeSelect", "countrySelect", "countrySearch"];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = "";
    });

    const sortSelect = document.getElementById("sortSelect");
    if (sortSelect) sortSelect.value = "deadline";
    currentSort = "deadline";

    document.querySelectorAll("[data-country]").forEach(label => label.style.display = "");

    applyFilters();
  }

  /* ── MODAL ── */
  function openProgramModal(programId) {
    const program = allPrograms.find(p => Number(p.id) === Number(programId));
    if (!program || !programModal) return;

    const badgeColor = getColor(program.type);
    modalProgramType.className = `card-type-badge ${badgeColor}`;
    modalProgramType.innerHTML = `<i class="fa-solid ${getIcon(program.type)}"></i> ${escapeHtml(program.type || "Program")}`;

    modalProgramTitle.textContent = program.title || "Untitled Program";
    modalInstitution.innerHTML = `<i class="fa-solid fa-building-columns"></i> ${escapeHtml(program.institution || "Partner institution")}`;
    modalDescription.textContent = program.description || "No description is available for this program.";
    modalCountry.textContent = program.country || "Country TBA";
    modalDuration.textContent = program.duration || "Duration TBA";
    modalStartDate.textContent = formatDate(program.startDate || program.deadline);

    const slotCount = Number(program.slots || 0);
    modalSlots.textContent = `${slotCount} slot${slotCount === 1 ? "" : "s"}`;

    // Prevent body scroll when modal is open
    document.body.style.overflow = "hidden";
    programModal.showModal();
  }

  function closeProgramModal() {
    if (programModal?.open) programModal.close();
    document.body.style.overflow = "";
  }

  /* ── EVENT LISTENERS ── */
  programList.addEventListener("click", (event) => {
    const detailsButton = event.target.closest(".details-button");
    if (!detailsButton) return;
    openProgramModal(detailsButton.dataset.programId);
  });

  modalClose?.addEventListener("click", closeProgramModal);
  modalCancel?.addEventListener("click", closeProgramModal);

  programModal?.addEventListener("click", (event) => {
    if (event.target === programModal) closeProgramModal();
  });

  // Also handle the modal being closed via ESC key
  programModal?.addEventListener("close", () => {
    document.body.style.overflow = "";
  });

  document.getElementById("searchForm")?.addEventListener("submit", (event) => {
    event.preventDefault();
    applyFilters();
    document.getElementById("programs")?.scrollIntoView({ behavior: "smooth", block: "start" });
  });

  // Debounced search input
  const debouncedFilter = debounce(applyFilters, 300);
  document.getElementById("searchInput")?.addEventListener("input", debouncedFilter);

  document.getElementById("typeSelect")?.addEventListener("change", applyFilters);
  document.getElementById("countrySelect")?.addEventListener("change", applyFilters);

  document.getElementById("sortSelect")?.addEventListener("change", function () {
    currentSort = this.value;
    currentPage = 1;
    sortPrograms();
  });

  document.getElementById("clearButton")?.addEventListener("click", clearFilters);

  document.getElementById("countrySearch")?.addEventListener("input", function () {
    const q = normalize(this.value);
    document.querySelectorAll("[data-country]").forEach(label => {
      const country = normalize(label.dataset.country);
      label.style.display = !q || country.includes(q) ? "" : "none";
    });
  });

  document.querySelectorAll(".trending button").forEach(button => {
    button.addEventListener("click", () => {
      const searchInput = document.getElementById("searchInput");
      if (searchInput) searchInput.value = button.dataset.search || "";
      applyFilters();
      document.getElementById("programs")?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });

  // Debounced filter checkbox change (handles multi-select)
  const debouncedCheckboxFilter = debounce(applyFilters, 150);
  document.body.addEventListener("change", (event) => {
    if (event.target.matches("input[data-filter]")) debouncedCheckboxFilter();
  });

  /* ── INITIAL RENDER ── */
  applyFilters();
});