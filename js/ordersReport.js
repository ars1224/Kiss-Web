document.addEventListener('DOMContentLoaded', () => {
    const reportFromDate = document.getElementById('reportFromDate');
    const reportToDate = document.getElementById('reportToDate');
    const generateBtn = document.getElementById('generateOrdersReportBtn');

    const exportPdfBtn = document.getElementById('exportOrdersReportPdfBtn');

    if (exportPdfBtn) {
        exportPdfBtn.addEventListener('click', exportOrdersReportPdf);
    }

    const today = new Date().toISOString().split('T')[0];

    reportFromDate.value = today;
    reportToDate.value = today;

    generateBtn.addEventListener('click', loadOrdersReport);

    loadOrdersReport();
});

async function loadOrdersReport() {
    const fromDate = document.getElementById('reportFromDate').value;
    const toDate = document.getElementById('reportToDate').value;

    if (!fromDate || !toDate) {
        alert('Please select from date and to date.');
        return;
    }

    try {
        const response = await fetch(`php/functions/orders_report.php?from_date=${encodeURIComponent(fromDate)}&to_date=${encodeURIComponent(toDate)}`);
        const data = await response.json();

        if (!data.success) {
            alert(data.message || 'Failed to load report.');
            return;
        }

        renderOrdersReportSummary(data.summary);
        renderProductsNotSupplied(data.not_supplied);
        renderOrdersNotSent(data.not_sent);

    } catch (error) {
        console.error(error);
        alert('Error loading orders report.');
    }
}

function renderOrdersReportSummary(summary) {
    document.getElementById('reportTotalOrders').textContent = summary.total_orders ?? 0;
    document.getElementById('reportSentOrders').textContent = summary.sent_orders ?? 0;
    document.getElementById('reportNotSentOrders').textContent = summary.not_sent_orders ?? 0;
    document.getElementById('reportQtyOrdered').textContent = summary.total_qty_ordered ?? 0;
    document.getElementById('reportQtySupplied').textContent = summary.total_qty_supplied ?? 0;
    document.getElementById('reportQtyNotSupplied').textContent = summary.total_qty_not_supplied ?? 0;
}

function renderProductsNotSupplied(rows) {
    const tbody = document.getElementById('reportNotSuppliedBody');

    if (!rows || rows.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="report-empty">No products not supplied for this date range.</td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr>
            <td data-label="Invoice">${escapeHtml(row.invoice_no)}</td>
            <td data-label="Order Date">${escapeHtml(row.completed_at)}</td>
            <td data-label="Customer">${escapeHtml(row.customer_name)}</td>
            <td data-label="SKU">${escapeHtml(row.sku_code)}</td>
            <td data-label="Description">${escapeHtml(row.description)}</td>
            <td data-label="Qty Ordered">${escapeHtml(row.qty_ordered)}</td>
            <td data-label="Qty Supplied">${escapeHtml(row.qty_supplied)}</td>
            <td data-label="Not Supplied" class="report-danger-text">${escapeHtml(row.qty_not_supplied)}</td>
            <td data-label="Reason">${escapeHtml(row.not_supplied_reason || 'No reason added')}</td>
        </tr>
    `).join('');
}

function renderOrdersNotSent(rows) {
    const tbody = document.getElementById('reportNotSentBody');

    if (!rows || rows.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="report-empty">No orders still not sent for this date range.</td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr>
            <td data-label="Invoice">
    <a href="order_view.php?id=${encodeURIComponent(row.id)}" class="report-link">
        ${escapeHtml(row.invoice_no)}
    </a>
</td>
            <td data-label="Customer">${escapeHtml(row.customer_name)}</td>
            <td data-label="Order Date">${escapeHtml(row.order_date)}</td>
            <td data-label="Delivery Date">${escapeHtml(row.delivery_date || '')}</td>
            <td data-label="Status">${renderReportStatus(row.status)}</td>
            <td data-label="Reason">${escapeHtml(row.status_reason || 'No reason added')}</td>
        </tr>
    `).join('');
}

function renderReportStatus(status) {
    const value = status || '';
    let className = 'report-status-badge';

    if (value === 'Sent') className += ' report-status-sent';
    else if (value === 'Pending') className += ' report-status-pending';
    else if (value === 'Ongoing') className += ' report-status-ongoing';
    else if (value === 'Booking') className += ' report-status-booking';
    else if (value === 'Waiting Slip') className += ' report-status-waiting';
    else if (value === 'Not Sent') className += ' report-status-not-sent';

    return `<span class="${className}">${escapeHtml(value)}</span>`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

async function exportOrdersReportPdf() {
    const fromDate = document.getElementById('reportFromDate').value;
    const toDate = document.getElementById('reportToDate').value;
    const exportPdfBtn = document.getElementById('exportOrdersReportPdfBtn');

    if (!fromDate || !toDate) {
        alert('Please select from date and to date first.');
        return;
    }

    const url = `php/functions/orders_report_pdf.php?from_date=${encodeURIComponent(fromDate)}&to_date=${encodeURIComponent(toDate)}`;
    const filename = `orders-report-${fromDate}-to-${toDate}.pdf`;

    if (exportPdfBtn) {
        exportPdfBtn.disabled = true;
        exportPdfBtn.textContent = 'Preparing PDF...';
    }

    try {
        await downloadGeneratedFile(url, filename);
    } catch (error) {
        console.error(error);
        alert(error.message || 'PDF download failed.');
    } finally {
        if (exportPdfBtn) {
            exportPdfBtn.disabled = false;
            exportPdfBtn.textContent = 'Download PDF';
        }
    }
}

async function downloadGeneratedFile(url, fallbackFilename) {
    const response = await fetch(url, { cache: 'no-store' });

    if (!response.ok) {
        const message = await response.text();
        throw new Error(message || 'Download failed.');
    }

    const blob = await response.blob();
    const filename = getDownloadFilename(response, fallbackFilename);
    saveBlob(blob, filename);
}

function getDownloadFilename(response, fallbackFilename) {
    const disposition = response.headers.get('Content-Disposition') || '';
    const filenameMatch = disposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
    const filename = filenameMatch ? (filenameMatch[1] || filenameMatch[2]) : '';

    if (!filename) {
        return fallbackFilename;
    }

    try {
        return decodeURIComponent(filename);
    } catch (error) {
        return filename;
    }
}

function saveBlob(blob, filename) {
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = objectUrl;
    link.download = filename;
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}
