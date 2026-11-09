function openUploadModal(programId, requirementName) {
        document.getElementById('modal_program_id').value = programId;
        document.getElementById('modal_requirement_name').value = requirementName;
        document.getElementById('modal_req_display').textContent = requirementName;
        document.getElementById('uploadModal').classList.add('open');
    }

    function closeUploadModal() {
        document.getElementById('uploadModal').classList.remove('open');
    }

    document.getElementById('uploadModal').addEventListener('click', function(e) {
        if (e.target === this) closeUploadModal();
    });
