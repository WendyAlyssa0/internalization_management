/* Debounced search */
    let searchTimer;
    document.querySelector('.audit-toolbar input[name="search"]').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => this.form.submit(), 500);
    });
