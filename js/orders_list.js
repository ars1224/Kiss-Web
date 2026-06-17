let allOrders = [];
let ordersRefreshTimer = null;
let isLoadingOrders = false;
const ORDERS_REFRESH_INTERVAL = 10000;

document.addEventListener('DOMContentLoaded', () => {
    loadOrders();
    startOrdersAutoRefresh();

    document.getElementById('statusFilter').addEventListener('change', () => {
    updateOrdersUrl();
    renderFilteredOrders();
});

document.getElementById('searchOrder').addEventListener('input', () => {
    updateOrdersUrl();
    renderFilteredOrders();
})
});

const urlParams = new URLSearchParams(window.location.search);

const statusParam = urlParams.get('status') || '';
const searchParam = urlParams.get('search') || '';

document.getElementById('statusFilter').value = statusParam;
document.getElementById('searchOrder').value = searchParam;

function startOrdersAutoRefresh() {
    if (ordersRefreshTimer) {
        clearInterval(ordersRefreshTimer);
    }

    ordersRefreshTimer = setInterval(() => {
        loadOrders({ silent: true });
    }, ORDERS_REFRESH_INTERVAL);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            loadOrders({ silent: true });
        }
    });
}

async function loadOrders(options = {}) {
    if (isLoadingOrders) {
        return;
    }

    isLoadingOrders = true;
    const silent = Boolean(options.silent);

    try {
        const response = await fetch(`php/functions/orders_list.php?t=${Date.now()}`, {
            cache: 'no-store'
        });
        const result = await response.json();

        if (!result.success) {
            if (!silent) {
                alert(result.message || 'Failed to load orders.');
            }
            return;
        }

        allOrders = result.orders || [];
        updateStatusCounts();
        renderFilteredOrders();
    } catch (error) {
        console.error(error);
        if (!silent) {
            alert('Error loading orders.');
        }
    } finally {
        isLoadingOrders = false;
    }
}

function renderFilteredOrders() {
    const status = document.getElementById('statusFilter').value;
    const search = document.getElementById('searchOrder').value.toLowerCase().trim();

    let filtered = allOrders;

    if (status) {
        filtered = filtered.filter(order => order.status === status);
    }

    if (search) {
        filtered = filtered.filter(order =>
            String(order.invoice_no || '').toLowerCase().includes(search) ||
            String(order.customer_name || '').toLowerCase().includes(search) ||
            String(order.order_number || '').toLowerCase().includes(search)
        );
    }

    renderOrders(filtered);
}

function renderOrders(orders) {
    const body = document.getElementById('ordersListBody');

    if (!orders.length) {
        body.innerHTML = `
            <tr class="empty-row">
                <td colspan="9">No orders found.</td>
            </tr>
        `;
        return;
    }

    body.innerHTML = orders.map(order => `
        <tr>
            <td data-label="Invoice">
                <a class="order-link" href="order_view.php?id=${order.id}">
                    <strong>${escapeHtml(order.invoice_no || '')}</strong>
                </a>
            </td>
            <td data-label="Date">${escapeHtml(order.order_date || '')}</td>
            <td data-label="Customer">
                <a class="order-link" href="order_view.php?id=${order.id}">
                    ${escapeHtml(order.customer_name || '')}
                </a>
            </td>
            <td data-label="Order No">${escapeHtml(order.order_number || '')}</td>
            <td data-label="Status">
                <span class="status-badge status-${escapeHtml(order.status || 'pending')}">
                    ${formatStatus(order.status || 'pending')}
                </span>
            </td>

            <td data-label="Packed By">${escapeHtml(order.picker_name || '')}</td>

            <td data-label="Checked By">${escapeHtml(order.checker_name || '')}</td>

            <td data-label="Courier">${escapeHtml(order.courier_name || '')}</td>
            <td data-label="Actions">
    <div class="action-group-row">

        ${['pending', 'ongoing'].includes(order.status || 'pending')
            ? `
                <button class="btn-mini btn-edit"
                    onclick="editOrder(${order.id})">
                    Edit
                </button>
            `
            : ''
        }

        ${!['booking', 'waiting_packing_slip', 'sent'].includes(order.status || 'pending')
            ? `
                <button class="btn-mini btn-delete"
                    onclick="deleteOrder(${order.id})">
                    Delete
                </button>
            `
            : ''
        }

        ${!['sent', 'not_sent'].includes(order.status || '')
            ? `
                <button class="btn-mini btn-print"
                    onclick="printCartonLabels(${order.id})">
                    Print Labels
                </button>
            `
            : ''
        }

        <a class="btn-mini btn-download"
           href="php/functions/export_pick_slip.php?id=${order.id}">
            Pick Slip
        </a>

        ${(order.status || '') === 'sent' && order.packing_slip_file
    ? `
        <a class="btn-mini btn-packing"
           href="${escapeHtml(order.packing_slip_file)}"
           target="_blank"
           download>
            Packing Slip
        </a>
    `
    : ''
}

    </div>
</td>
        </tr>
    `).join('');
}

async function quickStatus(id, status) {
    await updateStatus(id, status);
}

async function updateStatus(id, status) {
    try {
        const response = await fetch('php/functions/update_order_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, status })
        });

        const result = await response.json();

        if (!result.success) {
            alert(result.message || 'Status update failed.');
            return;
        }

        const order = allOrders.find(o => Number(o.id) === Number(id));
        if (order) order.status = status;
        updateStatusCounts();

        renderFilteredOrders();
    } catch (error) {
        console.error(error);
        alert('Status update request failed.');
    }
}

function editOrder(id) {
    window.location.href = `orders.php?edit=${id}`;
}

async function deleteOrder(id) {
    if (!confirm('Delete this order?')) return;

    try {
        const response = await fetch('php/functions/delete_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });

        const result = await response.json();

        if (!result.success) {
            alert(result.message || 'Delete failed.');
            return;
        }

        allOrders = allOrders.filter(o => Number(o.id) !== Number(id));
        updateStatusCounts();
        renderFilteredOrders();
    } catch (error) {
        console.error(error);
        alert('Delete request failed.');
    }
}

function formatStatus(status) {
    switch (status) {
        case 'pending': return 'Pending';
        case 'ongoing': return 'Ongoing';
        case 'booking': return 'Booking';
        case 'waiting_packing_slip': return 'Waiting Slip';
        case 'sent': return 'Sent';
        case 'not_sent': return 'Not Sent';
        default: return status;
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;') 
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function updateStatusCounts() {
    const pending = allOrders.filter(o => (o.status || 'pending') === 'pending').length;
    const ongoing = allOrders.filter(o => (o.status || 'pending') === 'ongoing').length;
    const booking = allOrders.filter(o => (o.status || 'pending') === 'booking').length;
    const waiting = allOrders.filter(o => (o.status || 'pending') === 'waiting_packing_slip').length;
    const sent = allOrders.filter(o => (o.status || 'pending') === 'sent').length;
    const notSent = allOrders.filter(o => (o.status || 'pending') === 'not_sent').length;

    document.getElementById('countPending').textContent = pending;
    document.getElementById('countOngoing').textContent = ongoing;
    document.getElementById('countBooking').textContent = booking;
    document.getElementById('countWaiting').textContent = waiting;
    document.getElementById('countSent').textContent = sent;
    document.getElementById('countNotSent').textContent = notSent;
}

async function printCartonLabels(id) {
    try {
        const response = await fetch(
            `php/functions/print_carton_labels.php?id=${encodeURIComponent(id)}`
        );

        const rawText = await response.text();
        console.log('Print labels response:', rawText);

        let result;

        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert('Print failed. Server returned invalid JSON. Check console.');
            return;
        }

        if (!result.ok) {
            alert(result.message || 'Print labels failed.');
            return;
        }

        alert('Carton labels sent to printer.');
    } catch (error) {
        console.error(error);
        alert('Print request failed.');
    }
}

function updateOrdersUrl() {
    const status = document.getElementById('statusFilter').value;
    const search = document.getElementById('searchOrder').value.trim();

    const params = new URLSearchParams();

    if (status) params.set('status', status);
    if (search) params.set('search', search);

    const newUrl = params.toString()
        ? `${window.location.pathname}?${params.toString()}`
        : window.location.pathname;

    window.history.replaceState({}, '', newUrl);
}
