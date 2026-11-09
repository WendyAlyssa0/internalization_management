document.addEventListener('DOMContentLoaded', function() {
    /* --- Avatar Live Preview --- */
    const fileInput = document.getElementById('profile_picture_input');
    const avatarPreview = document.getElementById('avatarPreview');

    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = function(ev) {
            // Replace the element with an image
            const newImg = document.createElement('img');
            newImg.src = ev.target.result;
            newImg.alt = 'Profile';
            newImg.className = 'avatar-image';
            newImg.id = 'avatarPreview';
            avatarPreview.replaceWith(newImg);
        };
        reader.readAsDataURL(file);
    });

    /* --- Form Submission --- */
    const form = document.getElementById('profileForm');
    const saveBtn = document.getElementById('saveBtn');
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');

    function showToast(type, message) {
        toast.className = `toast ${type} show`;
        toastMessage.textContent = message;
        const icon = toast.querySelector('i');
        icon.className = type === 'success' ? 'ti ti-circle-check' : 'ti ti-alert-circle';
        
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const originalBtn = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="ti ti-loader" style="animation: spin 1s linear infinite;"></i> Saving...';

        const formData = new FormData(form);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast('success', data.message);
                // Refresh the page after 1.2s so the identity card updates
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('error', data.message);
            }
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Network error. Please try again.');
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtn;
        });
    });
});
