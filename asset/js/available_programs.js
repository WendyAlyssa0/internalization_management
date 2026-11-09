(function () {

    const overlay = document.getElementById('programModal');
    let lastFocused = null;

    // OPEN PROGRAM DETAILS
    window.viewProgram = function (button) {

        let program = {};
        let requirements = [];

        try {
            program = JSON.parse(button.getAttribute('data-program') || '{}');
            requirements = JSON.parse(button.getAttribute('data-requirements') || '[]');
        } catch (error) {
            console.error('Unable to load program information:', error);
            return;
        }

        const set = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        const total = Number(program.total_slots) || 0;
        const left = Number(program.slots_available) || 0;
        const isFull = total > 0 && left <= 0;
        const isLow = total > 0 && left > 0 && left <= 3;

        // Hero header
        set('modalProgramName', program.program_name || 'Program');
        set('modalHeroType', program.program_type || 'Program');
        set('modalHeroCountry', ''); // clear then rebuild below
        set('modalHeroTerm', '');

        const heroCountry = document.getElementById('modalHeroCountry');
        heroCountry.innerHTML = '<i class="fa-solid fa-location-dot"></i> ' + escapeHtml(program.country || 'TBD');

        const heroTerm = document.getElementById('modalHeroTerm');
        heroTerm.innerHTML = '<i class="fa-solid fa-layer-group"></i> ' + escapeHtml(program.term_label || 'TBD');

        // Details grid
        set('modalPartner', program.partner_institution || 'TBD');
        set('modalCountry', program.country || 'TBD');
        set('modalType', program.program_type || 'TBD');
        set('modalTerm', program.term_label || 'TBD');
        set('modalDuration', formatDate(program.start_date) + ' – ' + formatDate(program.end_date));
        set('modalSlots', total > 0 ? left + ' of ' + total + ' available' : 'Not specified');
        set('modalDescription', program.description || 'No description available.');

        // Slot summary in footer
        const summary = document.getElementById('modalSlotSummary');
        if (total > 0) {
            if (isFull) {
                summary.innerHTML = '<i class="fa-solid fa-ban" style="color:#dc2626;"></i> <strong>All slots filled</strong>';
            } else if (isLow) {
                summary.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#b45309;"></i> Only <strong>' + left + '</strong> slot' + (left === 1 ? '' : 's') + ' left';
            } else {
                summary.innerHTML = '<i class="fa-solid fa-users" style="color:#3730a3;"></i> <strong>' + left + '</strong> of ' + total + ' slots available';
            }
        } else {
            summary.textContent = '';
        }

        // PROGRAM REQUIREMENTS
        const requirementsList = document.getElementById('modalRequirements');
        requirementsList.replaceChildren();

        if (requirements.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'requirements-empty';
            empty.textContent = 'No requirements have been assigned to this program yet.';
            requirementsList.appendChild(empty);
        } else {
            requirements.forEach(requirement => {
                const item = document.createElement('div');
                item.className = 'requirement-item';

                const iconContainer = document.createElement('div');
                iconContainer.className = 'requirement-icon-box';

                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-file-lines';
                iconContainer.appendChild(icon);

                const info = document.createElement('div');
                info.className = 'requirement-info';

                const name = document.createElement('strong');
                name.textContent = requirement.requirement_name || 'Unnamed Requirement';

                const status = document.createElement('small');
                status.textContent = Number(requirement.is_required) === 1 ? 'Required' : 'Optional';

                info.append(name, status);

                if (requirement.requirement_file) {
                    const download = document.createElement('a');
                    download.className = 'requirement-download';
                    download.href = requirement.requirement_file;
                    download.innerHTML = '<i class="fa-solid fa-download"></i> Download Requirement';
                    info.appendChild(download);
                }

                item.append(iconContainer, info);
                requirementsList.appendChild(item);
            });
        }

        // APPLY BUTTON
        const applyButton = document.getElementById('applyLink');

        if (isFull) {
            applyButton.removeAttribute('href');
            applyButton.textContent = 'Slots Full';
            applyButton.classList.add('disabled');
        } else {
            // Note: apply_program.php should redirect to ?page=available_programs&applied=1 on success
            applyButton.href = '?page=apply_program&program_id=' + encodeURIComponent(program.id);
            applyButton.innerHTML = 'Apply Now <i class="fa-solid fa-arrow-right"></i>';
            applyButton.classList.remove('disabled');
        }

        lastFocused = button;
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        document.getElementById('modalProgramName').focus();
    };

    // CLOSE MODAL
    window.closeModal = function () {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        if (lastFocused) lastFocused.focus();
    };

    // FORMAT DATES
    function formatDate(date) {
        if (!date) return 'TBD';
        return new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric'
        });
    }

    // ESCAPE HTML for safe innerHTML usage
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && overlay.classList.contains('active')) closeModal();
    });

    // SAVED PROGRAMS
    const STORE = 'psu_saved_programs';
    let saved = new Set();
    try { saved = new Set(JSON.parse(localStorage.getItem(STORE) || '[]')); }
    catch (error) { saved = new Set(); }

    document.querySelectorAll('[data-save-id]').forEach(btn => {
        const id = btn.dataset.saveId;
        paint(btn, saved.has(id));
        btn.addEventListener('click', function () {
            if (saved.has(id)) saved.delete(id);
            else saved.add(id);
            localStorage.setItem(STORE, JSON.stringify([...saved]));
            paint(btn, saved.has(id));
        });
    });

    function paint(btn, on) {
        btn.classList.toggle('saved', on);
        btn.setAttribute('aria-pressed', String(on));
        btn.querySelector('i').className = on ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark';
    }

    // AUTO-HIDE SUCCESS BANNER
    const banner = document.getElementById('successBanner');
    if (banner) {
        setTimeout(() => {
            banner.style.transition = 'opacity 0.4s, transform 0.4s';
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(-10px)';
            setTimeout(() => banner.remove(), 400);
        }, 10000);
    }

})();
