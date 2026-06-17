document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("productsSearch");
    const clearButton = document.getElementById("clearProductsSearch");
    const table = document.getElementById("productsTable");

    const overlay = document.getElementById("productPopupOverlay");
    const closeBtn = document.getElementById("closeProductPopup");

    const popupSku = document.getElementById("popupSku");
    const popupDesc = document.getElementById("popupDesc");
    const popupTotal = document.getElementById("popupTotal");
    const popupQtyCtn = document.getElementById("popupQtyCtn");
    const popupLocationsBody = document.getElementById("popupLocationsBody");

    const urlParams = new URLSearchParams(window.location.search);

    makeTableSortable("#productsTable");
    makeTableSortable(".product-popup-table");

    const popupDescriptionInput = document.getElementById("popupDescriptionInput");
const popupStatusInput = document.getElementById("popupStatusInput");
const saveProductInfoBtn = document.getElementById("saveProductInfoBtn");

let currentPopupSku = "";

    function updateUrl(searchValue) {
        const url = new URL(window.location);

        if (searchValue.trim() !== "") {
            url.searchParams.set("search", searchValue.trim());
        } else {
            url.searchParams.delete("search");
        }

        window.history.replaceState({}, "", url);
    }

    function filterProducts() {
        if (!searchInput || !table) return;

        const searchValue = searchInput.value.toLowerCase().trim();
        const rows = table.querySelectorAll("tbody tr");

        rows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(searchValue) ? "" : "none";
        });

        updateUrl(searchInput.value);
    }

    const initialSearch = urlParams.get("search") || "";

    if (initialSearch && searchInput) {
        searchInput.value = initialSearch;
    }

    filterProducts();

    if (searchInput) {
        searchInput.addEventListener("keydown", event => {
            if (event.key === "Enter") {
                event.preventDefault();
                filterProducts();
            }
        });
    }

    if (clearButton) {
        clearButton.addEventListener("click", () => {
            searchInput.value = "";
            filterProducts();
            searchInput.focus();
        });
    }

    document.querySelectorAll(".product-row").forEach(row => {
        row.addEventListener("click", async () => {
            const sku = row.dataset.sku;
            currentPopupSku = sku;
            if (!sku || !overlay) return;

            overlay.classList.add("show");
            popupSku.textContent = sku;
            popupDesc.textContent = "Loading...";
            popupTotal.textContent = "0";
            popupQtyCtn.textContent = "0";
            popupLocationsBody.innerHTML = `<tr><td colspan="6">Loading...</td></tr>`;

            try {

                const inventoryType = row.dataset.inventoryType || '';

                const response = await fetch(`/kiss-web/php/functions/get_product_details.php?sku=${encodeURIComponent(sku)}&inventory=${encodeURIComponent(inventoryType)}`)
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || "Failed to load product.");
                }

                popupSku.textContent = data.summary.SKU_Code || sku;
                popupDesc.textContent = data.summary.ProductDescription || "";
                popupTotal.textContent = Number(data.summary.TotalQty || 0).toLocaleString();
                popupQtyCtn.textContent = Number(data.summary.QtyPerCtn || 0).toLocaleString();

                if (!data.locations.length) {
                    popupLocationsBody.innerHTML = `<tr><td colspan="6">No locations found.</td></tr>`;
                    return;
                }

                if (popupDescriptionInput) {
    popupDescriptionInput.value = data.summary.ProductDescription || "";
}

if (popupStatusInput) {
    popupStatusInput.value = data.summary.Status || "Continue";
}

                popupLocationsBody.innerHTML = data.locations.map(item => `
                    <tr>
                        <td>${escapeHtml(item.Location || "")}</td>
                        <td>${escapeHtml(item.BatchNo || "")}</td>
                        <td>${escapeHtml(item.ExpiryDate || "")}</td>
                        <td>${Number(item.TotalQty || 0).toLocaleString()}</td>
                        <td>${Number(item.QtyPerCtn || 0).toLocaleString()}</td>
                        <td>${escapeHtml(item.Comments || "")}</td>
                    </tr>
                `).join("");

            } catch (error) {
                popupDesc.textContent = "Error loading product.";
                popupLocationsBody.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`;
            }
        });
    });

    if (closeBtn && overlay) {
        closeBtn.addEventListener("click", () => {
            overlay.classList.remove("show");
        });
    }

    if (overlay) {
        overlay.addEventListener("click", e => {
            if (e.target === overlay) {
                overlay.classList.remove("show");
            }
        });
    }

    if (saveProductInfoBtn) {
    saveProductInfoBtn.addEventListener("click", async () => {
        if (!currentPopupSku) return;

        saveProductInfoBtn.disabled = true;
        saveProductInfoBtn.textContent = "Saving...";

        try {
            const response = await fetch("/kiss-web/php/functions/update_product_info.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    sku: currentPopupSku,
                    description: popupDescriptionInput.value,
                    status: popupStatusInput.value
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || "Failed to save product.");
            }

            popupDesc.textContent = popupDescriptionInput.value;

            const row = document.querySelector(`.product-row[data-sku="${CSS.escape(currentPopupSku)}"]`);

            if (row) {
                const descriptionCell = row.children[1];
                const statusBadge = row.querySelector(".status-badge");

                descriptionCell.textContent = popupDescriptionInput.value;

                if (statusBadge) {
                    statusBadge.textContent = popupStatusInput.value;
                    statusBadge.className = "status-badge status-" + statusToClass(popupStatusInput.value);
                }
            }

            saveProductInfoBtn.textContent = "Saved!";

            setTimeout(() => {
                saveProductInfoBtn.textContent = "Save Changes";
                saveProductInfoBtn.disabled = false;
            }, 900);

        } catch (error) {
            alert(error.message);
            saveProductInfoBtn.textContent = "Save Changes";
            saveProductInfoBtn.disabled = false;
        }
    });
}

function statusToClass(status) {
    return String(status)
        .toLowerCase()
        .replaceAll("/", "-")
        .replaceAll(" ", "-");
}

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function makeTableSortable(tableSelector) {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    const headers = table.querySelectorAll("thead th");

    headers.forEach((header, index) => {
        header.style.cursor = "pointer";
        header.title = "Click to sort";

        header.addEventListener("click", () => {
            const tbody = table.querySelector("tbody");
            const rows = Array.from(tbody.querySelectorAll("tr"));

            const currentDirection = header.dataset.sortDirection || "asc";
            const newDirection = currentDirection === "asc" ? "desc" : "asc";

            headers.forEach(th => {
                th.dataset.sortDirection = "";
                th.classList.remove("sort-asc", "sort-desc");
            });

            header.dataset.sortDirection = newDirection;
            header.classList.add(newDirection === "asc" ? "sort-asc" : "sort-desc");

            rows.sort((a, b) => {
                const aText = a.children[index]?.textContent.trim() || "";
                const bText = b.children[index]?.textContent.trim() || "";

                const aNum = Number(aText.replace(/,/g, ""));
                const bNum = Number(bText.replace(/,/g, ""));

                const bothAreNumbers = !isNaN(aNum) && !isNaN(bNum) && aText !== "" && bText !== "";

                if (bothAreNumbers) {
                    return newDirection === "asc" ? aNum - bNum : bNum - aNum;
                }

                return newDirection === "asc"
                    ? aText.localeCompare(bText, undefined, { numeric: true })
                    : bText.localeCompare(aText, undefined, { numeric: true });
            });

            rows.forEach(row => tbody.appendChild(row));
        });
    });
}
});