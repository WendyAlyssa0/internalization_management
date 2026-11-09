const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function(e) {
        const val = e.target.value.toLowerCase().trim();
        const cards = document.querySelectorAll('.partner-card');
        const noResults = document.getElementById('noResults');
        let visibleCount = 0;

        cards.forEach(card => {
            const matches = card.dataset.search.includes(val);
            
            if (val === "") {
                card.style.display = "flex";
                visibleCount++;
            } else {
                card.style.display = matches ? "flex" : "none";
                if (matches) visibleCount++;
            }
        });

        document.getElementById('countBadge').innerText = `${visibleCount} Partner${visibleCount !== 1 ? 's' : ''}`;
        
        if (noResults) {
            noResults.style.display = (visibleCount === 0 && val !== "") ? 'block' : 'none';
        }
    });
}
