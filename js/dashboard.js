document.addEventListener('DOMContentLoaded', () => {

    const lowStockCard = document.getElementById('lowStockCard');
    const lowStockModal = document.getElementById('lowStockModal');
    const closeBtn = document.getElementById('closeLowStockModal');

    lowStockCard?.addEventListener('click', () => {
        lowStockModal.classList.remove('d-none');
    });

    closeBtn?.addEventListener('click', () => {
        lowStockModal.classList.add('d-none');
    });

    lowStockModal?.addEventListener('click', (e) => {
        if (e.target === lowStockModal) {
            lowStockModal.classList.add('d-none');
        }
    });

});

document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('#lowStockModal table');
    if (!table) return;

    const headers = table.querySelectorAll('thead th');
    const tbody = table.querySelector('tbody');

    headers.forEach((th, index) => {
        th.style.cursor = 'pointer';
        th.title = 'Click to sort';

        let asc = true;

        th.addEventListener('click', () => {
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                const A = a.children[index]?.innerText.trim() || '';
                const B = b.children[index]?.innerText.trim() || '';

                const numA = parseFloat(A.replace(/,/g, ''));
                const numB = parseFloat(B.replace(/,/g, ''));

                if (!isNaN(numA) && !isNaN(numB)) {
                    return asc ? numA - numB : numB - numA;
                }

                return asc
                    ? A.localeCompare(B)
                    : B.localeCompare(A);
            });

            rows.forEach(row => tbody.appendChild(row));
            asc = !asc;
        });
    });
});