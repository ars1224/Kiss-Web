document.addEventListener('DOMContentLoaded', () => {
    const reportFromDate = document.getElementById('reportFromDate');
    const reportToDate = document.getElementById('reportToDate');
    const generateBtn = document.getElementById('generateOrdersReportBtn');
    const reportPage = document.querySelector('.orders-report-page');

    const exportPdfBtn = document.getElementById('exportOrdersReportPdfBtn');

    if (exportPdfBtn) {
        exportPdfBtn.addEventListener('click', exportOrdersReportPdf);
    }

    if (reportPage) {
        reportPage.addEventListener('click', event => {
            const saveButton = event.target.closest('.report-reason-save');

            if (saveButton) saveOrderStatusReason(saveButton);
        });
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
        renderFinalNotSent(data.final_not_sent);
        renderProductsNotSupplied(data.not_supplied);
        renderOrdersNotSent(data.not_sent);
        renderReportCharts(data.charts || {});

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

function renderReportCharts(charts) {
    renderSkuBarChart(
        'reportBestSellerSkuChart',
        charts.top_sellers,
        'No supplied SKU units for this date range.'
    );

    renderSkuBarChart(
        'reportMostOrderedSkuChart',
        charts.most_ordered,
        'No ordered SKU units for this date range.'
    );

    renderMonthlyUnitsChart(
        'reportMonthlyUnitsOutChart',
        charts.monthly_units_out,
        'No units out for this date range.'
    );
}

function renderSkuBarChart(containerId, rows, emptyMessage) {
    const container = document.getElementById(containerId);

    if (!container) return;

    const chartRows = getChartRows(rows);

    if (chartRows.length === 0) {
        container.innerHTML = `<div class="report-chart-empty">${escapeHtml(emptyMessage)}</div>`;
        return;
    }

    const maxValue = getMaxChartValue(chartRows);

    container.innerHTML = chartRows.map((row, index) => {
        const value = getChartValue(row);
        const width = value > 0 ? Math.max((value / maxValue) * 100, 3) : 0;
        const sku = row.sku_code || 'Unknown SKU';
        const description = row.description || '';

        return `
            <div class="report-chart-row">
                <div class="report-chart-rank">${index + 1}</div>
                <div class="report-chart-label">
                    <strong>${escapeHtml(sku)}</strong>
                    <span>${escapeHtml(description)}</span>
                </div>
                <div class="report-chart-track" aria-hidden="true">
                    <span class="report-chart-fill" style="width: ${width}%;"></span>
                </div>
                <div class="report-chart-value">${formatQty(value)}</div>
            </div>
        `;
    }).join('');
}

function renderMonthlyUnitsChart(containerId, rows, emptyMessage) {
    const container = document.getElementById(containerId);

    if (!container) return;

    const chartRows = getChartRows(rows);

    if (chartRows.length === 0) {
        container.innerHTML = `<div class="report-chart-empty">${escapeHtml(emptyMessage)}</div>`;
        return;
    }

    const maxValue = getMaxChartValue(chartRows);

    container.innerHTML = chartRows.map(row => {
        const value = getChartValue(row);
        const height = value > 0 ? Math.max((value / maxValue) * 100, 4) : 0;
        const label = row.month_label || row.month_key || 'Month';

        return `
            <div class="report-month-bar" title="${escapeHtml(label)}: ${formatQty(value)}">
                <div class="report-month-value">${formatQty(value)}</div>
                <div class="report-month-track" aria-hidden="true">
                    <span class="report-month-fill" style="height: ${height}%;"></span>
                </div>
                <div class="report-month-label">${escapeHtml(label)}</div>
            </div>
        `;
    }).join('');
}

function getChartRows(rows) {
    if (!Array.isArray(rows)) return [];

    return rows.filter(row => getChartValue(row) > 0);
}

function getMaxChartValue(rows) {
    return Math.max(...rows.map(row => getChartValue(row)), 1);
}

function getChartValue(row) {
    const value = Number(row?.total_units ?? 0);
    return Number.isFinite(value) ? value : 0;
}

function formatQty(value) {
    const number = Number(value || 0);

    if (!Number.isFinite(number)) return '0';

    return number.toLocaleString(undefined, {
        maximumFractionDigits: Number.isInteger(number) ? 0 : 2
    });
}

function renderFinalNotSent(rows) {
    const section = document.getElementById('finalNotSentSection');
    const tbody = document.getElementById('reportFinalNotSentBody');
    const hasRows = Array.isArray(rows) && rows.length > 0;

    if (section) {
        section.hidden = !hasRows;
    }

    if (!tbody) return;

    if (!hasRows) {
        tbody.innerHTML = '';
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr>
            <td data-label="Invoice">
                <a href="order_view.php?id=${encodeURIComponent(row.id)}" class="report-link">
                    ${escapeHtml(row.invoice_no)}
                </a>
            </td>
            <td data-label="Report Date">${escapeHtml(row.report_date || '')}</td>
            <td data-label="Customer">${escapeHtml(row.customer_name || '')}</td>
            <td data-label="Order Date">${escapeHtml(row.order_date || '')}</td>
            <td data-label="Delivery Date">${escapeHtml(row.delivery_date || '')}</td>
            <td data-label="Reason / Comment">${renderOrderReasonEditor(row)}</td>
        </tr>
    `).join('');
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
    const section = document.getElementById('ordersStillNotSentSection');
    const hasRows = Array.isArray(rows) && rows.length > 0;

    if (section) {
        section.hidden = !hasRows;
    }

    if (!tbody) return;

    if (!hasRows) {
        tbody.innerHTML = '';
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
            <td data-label="Reason / Comment">${renderOrderReasonEditor(row)}</td>
        </tr>
    `).join('');
}

function renderOrderReasonEditor(row) {
    const orderId = Number.parseInt(row?.id, 10);
    const reason = row?.status_reason || '';

    if (!Number.isInteger(orderId) || orderId <= 0) {
        return escapeHtml(reason || 'No reason added');
    }

    return `
        <div class="report-reason-editor">
            <textarea
                class="report-reason-input"
                rows="2"
                maxlength="255"
                placeholder="Why has this order not been sent?"
                aria-label="Reason this order has not been sent"
            >${escapeHtml(reason)}</textarea>
            <div class="report-reason-actions">
                <button
                    type="button"
                    class="report-reason-save"
                    data-order-id="${orderId}"
                >Save</button>
                <span class="report-reason-status" role="status" aria-live="polite"></span>
            </div>
        </div>
    `;
}

async function saveOrderStatusReason(saveButton) {
    const editor = saveButton.closest('.report-reason-editor');
    const input = editor?.querySelector('.report-reason-input');
    const status = editor?.querySelector('.report-reason-status');
    const orderId = Number.parseInt(saveButton.dataset.orderId, 10);

    if (!input || !status || !Number.isInteger(orderId) || orderId <= 0) return;

    const reason = input.value.trim();

    if (reason.length > 255) {
        showReasonSaveStatus(status, 'Reason is too long.', true);
        return;
    }

    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';
    showReasonSaveStatus(status, 'Saving...', false);

    try {
        const response = await fetch('php/functions/update_order_status_reason.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: orderId, reason })
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Could not save the reason.');
        }

        input.value = data.status_reason || '';
        showReasonSaveStatus(status, 'Saved', false, true);
    } catch (error) {
        console.error(error);
        showReasonSaveStatus(status, error.message || 'Could not save.', true);
    } finally {
        saveButton.disabled = false;
        saveButton.textContent = 'Save';
    }
}

function showReasonSaveStatus(status, message, isError = false, isSuccess = false) {
    status.textContent = message;
    status.classList.toggle('is-error', isError);
    status.classList.toggle('is-success', isSuccess);
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
