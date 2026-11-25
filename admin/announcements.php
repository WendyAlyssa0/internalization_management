<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

/* =========================
   AUTHORIZATION
========================= */
// Uses the clean helper function from roles.php
if (!canManageAnnouncements()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to manage announcements.');
}

$pdo = db();

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* FETCH */
$announcements = $pdo->query("
    SELECT id, title, announcement, publish_date, expiry_date, attachment_path,
           CASE WHEN expiry_date < CURDATE() THEN 'completed' ELSE status END AS display_status,
           status
    FROM announcements
    ORDER BY publish_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ... YOUR HTML AND JAVASCRIPT REMAINS EXACTLY THE SAME ... -->
<!-- (Paste the HTML/JS from the previous step here) -->

<link rel="stylesheet" href="../asset/css/announcement.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Announcement Management</h2>
        <p>Create and manage announcements for students and partner institutions.</p>
    </div>

    <div class="toolbar">
        <div class="search-box">
            <i class="fa fa-search"></i>
            <input type="text" id="searchAnnouncement" placeholder="Search announcement...">
        </div>
        <button class="create-btn" onclick="openAddModal()">
            <i class="fa fa-plus"></i> New Announcement
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Announcement</th>
                    <th>Posting Date</th>
                    <th>Expiry Date</th>
                    <th>Attachments</th>
                    <th>Status</th>
                    <th width="90">Actions</th>
                </tr>
            </thead>

            <tbody id="announcementTable">
            <?php if (count($announcements)): ?>
                <?php foreach ($announcements as $row): ?>
                <tr data-search="<?= strtolower(htmlspecialchars($row['title'] . ' ' . $row['announcement'])) ?>">
                    <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($row['title']) ?></td>
                    <td style="color:#475569;">
                        <?= htmlspecialchars(substr($row['announcement'], 0, 100)) ?>
                        <?= strlen($row['announcement']) > 100 ? '...' : '' ?>
                    </td>
                    <td><?= date('M d, Y', strtotime($row['publish_date'])) ?></td>
                    <td><?= $row['expiry_date'] ? date('M d, Y', strtotime($row['expiry_date'])) : '—' ?></td>
                    <td>
                        <?php if ($row['attachment_path']): ?>
                            <a href="../<?= htmlspecialchars($row['attachment_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View attachment</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?= strtolower($row['display_status']) ?>">
                            <?= htmlspecialchars(ucfirst($row['display_status'])) ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <button class="action-btn edit-btn" title="Edit" onclick='openEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="empty-row">No announcements found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== ADD / EDIT MODAL ===================== -->
<div class="modal-overlay" id="announcementModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-icon"><i class="fa-solid fa-bullhorn" id="modalIcon"></i></div>
        <div>
          <h3 id="modalTitle">New Announcement</h3>
          <p class="modal-subtitle" id="modalSubtitle">Create a new announcement</p>
        </div>
      </div>
      <button type="button" class="modal-close" id="modalClose">&times;</button>
    </div>

    <form id="announcementForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" id="formAction" value="save">
      <input type="hidden" name="id" id="formId" value="">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="title">Title *</label>
          <input type="text" id="title" name="title" class="form-input" placeholder="e.g. Application Deadline Extended" required maxlength="255">
        </div>

        <div class="form-group">
          <label class="form-label" for="announcement">Announcement Content *</label>
          <textarea id="announcement" name="announcement" class="form-input" placeholder="Write your announcement here..." rows="4" required maxlength="5000"></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="publish_date">Posting Date *</label>
            <input type="date" id="publish_date" name="publish_date" class="form-input" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="expiry_date">Expiry Date *</label>
            <input type="date" id="expiry_date" name="expiry_date" class="form-input" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="status">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="ongoing">Ongoing</option>
                <option value="completed">Completed</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="attachment">Brochure or attachment</label>
          <input type="file" id="attachment" name="attachment" class="form-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
          <small>Leave empty to keep the current attachment when editing.</small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn-confirm" id="formSubmit">
          <i class="fa-solid fa-check"></i>
          <span id="formSubmitLabel">Save Announcement</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== TOAST ===================== -->
<div class="toast" id="toast">
  <i class="fa-solid fa-circle-check"></i>
  <span id="toastMessage">Saved successfully</span>
</div>

<script>
  window.ANNOUNCEMENT_CSRF = <?= json_encode($csrfToken) ?>;

  document.addEventListener("DOMContentLoaded", () => {
    const csrfToken = window.ANNOUNCEMENT_CSRF;

    const modal       = document.getElementById("announcementModal");

    const form         = document.getElementById("announcementForm");
    const formAction   = document.getElementById("formAction");
    const formId       = document.getElementById("formId");
    const submitLabel  = document.getElementById("formSubmitLabel");
    const modalTitle   = document.getElementById("modalTitle");
    const modalSubtitle = document.getElementById("modalSubtitle");
    const modalIcon    = document.getElementById("modalIcon");

    const titleInput        = document.getElementById("title");
    const announcementInput = document.getElementById("announcement");
    const dateInput         = document.getElementById("publish_date");
    const expiryInput       = document.getElementById("expiry_date");
    const statusInput       = document.getElementById("status");

    const toast        = document.getElementById("toast");
    const toastMessage = document.getElementById("toastMessage");
    let toastTimer = null;

    function showToast(message, type = "success") {
      toastMessage.textContent = message;
      toast.classList.remove("toast--success", "toast--error");
      toast.classList.add(type === "error" ? "toast--error" : "toast--success");
      toast.querySelector("i").className = type === "error" ? "fa-solid fa-circle-exclamation" : "fa-solid fa-circle-check";
      toast.classList.add("toast--show");
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.classList.remove("toast--show"), 3200);
    }

    const openModal        = () => modal.classList.add("open");
    const closeModal       = () => modal.classList.remove("open");

    function resetForm() {
      form.reset();
      formId.value = "";
      formAction.value = "save";
    }

    /* ---- ADD ---- */
    window.openAddModal = () => {
      resetForm();
      modalTitle.textContent    = "New Announcement";
      modalSubtitle.textContent = "Create a new announcement";
      modalIcon.className       = "fa-solid fa-bullhorn";
      submitLabel.textContent   = "Save Announcement";
      formAction.value          = "save";
      formId.value              = "";

      const today = new Date().toISOString().split('T')[0];
      dateInput.value = today;
      expiryInput.value = today;

      openModal();
      titleInput.focus();
    };

    /* ---- EDIT ---- */
    window.openEditModal = (data) => {
      resetForm();
      formAction.value          = "save";
      formId.value              = data.id;
      titleInput.value          = data.title || "";
      announcementInput.value   = data.announcement || "";
      dateInput.value           = data.publish_date || "";
      expiryInput.value         = data.expiry_date || "";
      statusInput.value         = data.status || "ongoing";

      modalTitle.textContent    = "Edit Announcement";
      modalSubtitle.textContent = "Update existing announcement";
      modalIcon.className       = "fa-solid fa-pen";
      submitLabel.textContent   = "Update Announcement";

      openModal();
      titleInput.focus();
    };

    document.getElementById("modalClose").onclick        = closeModal;
    document.getElementById("modalCancel").onclick       = closeModal;
    [modal].forEach((m) => {
      m.addEventListener("click", (e) => {
        if (e.target === m) m.classList.remove("open");
      });
    });

    /* ---- SUBMIT ---- */
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const submitBtn = document.getElementById("formSubmit");
      submitBtn.disabled = true;

      try {
        const res  = await fetch('announcement_save.php', { method: "POST", body: new FormData(form) });
        const data = await res.json();

        if (!data.ok) {
          showToast(data.message || "Something went wrong.", "error");
          return;
        }

        const isEdit = formId.value !== "";
        showToast(isEdit ? "Announcement updated." : "Announcement created.");
        setTimeout(() => location.reload(), 700);
      } catch (err) {
        showToast("Network error. Please try again.", "error");
      } finally {
        submitBtn.disabled = false;
      }
    });

  });

  /* ---- SEARCH ---- */
  document.getElementById("searchAnnouncement").addEventListener("keyup", function () {
      let value = this.value.toLowerCase();
      document.querySelectorAll("#announcementTable tr").forEach(row => {
          let text = row.dataset.search || "";
          row.style.display = text.includes(value) ? "" : "none";
      });
  });
</script>