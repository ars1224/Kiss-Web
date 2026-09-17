const params = new URLSearchParams(window.location.search);
const orderId = params.get('id');

let currentOrder = null;
let currentItems = [];
let orderRefreshTimer = null;
let isLoadingOrder = false;
let pendingOrderRefresh = false;
let hasUnsavedPickingChanges = false;
let hasUnsavedLineChanges = false;
let hideFinishedRows = false;
let isLineEditMode = false;
let lineEditSnapshot = [];
let deletedOrderItemIds = new Set();
const ORDER_REFRESH_INTERVAL = 10000;

document.addEventListener('DOMContentLoaded', () => {
    if (!orderId) {
        document.getElementById('orderViewBody').innerHTML = 'Missing order ID.';
        return;
    }

    const msg = params.get('msg');
    if (msg === 'packing_uploaded') {
        showSuccessMessage('Packing slip uploaded. Order marked as sent.');
        window.history.replaceState({}, document.title, `order_view.php?id=${encodeURIComponent(orderId)}`);
    }

    document.getElementById('startPickingBtn')?.addEventListener('click', startPicking);
    document.getElementById('checkedBtn')?.addEventListener('click', checkOrder);
    document.getElementById('bookCourierBtn')?.addEventListener('click', bookCourier);
    setupPackingSlipDropZone();
    document.getElementById('downloadPickSlipBtn')?.addEventListener('click', downloadCurrentPickSlip);
    document.getElementById('reopenOrderBtn')?.addEventListener('click', reopenOrder);

    const orderViewBody = document.getElementById('orderViewBody');
    orderViewBody?.addEventListener('input', handleOrderViewInput);
    orderViewBody?.addEventListener('change', handleOrderViewChange);
    orderViewBody?.addEventListener('click', handleOrderViewClick);

    document.getElementById('courierName')?.addEventListener('change', () => {
        const courier = document.getElementById('courierName').value;
        document.getElementById('customCourierName').style.display =
            courier === 'Other' ? 'inline-block' : 'none';
    });

    loadOrder();
    startOrderAutoRefresh();
});

function startOrderAutoRefresh() {
    if (orderRefreshTimer) {
        clearInterval(orderRefreshTimer);
    }

    orderRefreshTimer = setInterval(() => {
        loadOrder({ silent: true });
    }, ORDER_REFRESH_INTERVAL);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            loadOrder({ silent: true });
        }
    });
}

function markUnsavedPickingChanges(event) {
    if (event.target?.matches?.(
        '.pick-ctn-input, .pick-done-input, .pick-done-checkbox'
    )) {
        hasUnsavedPickingChanges = true;
    }
}

function markUnsavedLineChanges(event) {
    const control = event.target;

    if (!control?.matches?.('[data-order-line-field]')) {
        return;
    }

    hasUnsavedLineChanges = true;

    const editor = control.closest(
        '[data-order-line-editor], [data-mobile-order-line-editor]'
    );
    const itemId = Number(editor?.dataset.itemId || 0);
    const item = currentItems.find(entry => Number(entry.id) === itemId);
    const field = control.dataset.orderLineField;

    if (!item || !field) return;

    const value = field === 'total_qty_supplied'
        ? String(control.value ?? '').trim()
        : normalizeAlignedEditorValue(control.value);

    item[field] = value;

    if (field === 'total_qty_supplied') {
        item.qty_supplied = value;
    }

    if (field === 'ctn_no' && String(item.picked_ctn_no || '').trim() !== '') {
        item.picked_ctn_no = value;
    }

    document.querySelectorAll('[data-order-line-field]').forEach(otherControl => {
        const otherEditor = otherControl.closest(
            '[data-order-line-editor], [data-mobile-order-line-editor]'
        );

        if (
            otherControl !== control &&
            Number(otherEditor?.dataset.itemId || 0) === itemId &&
            otherControl.dataset.orderLineField === field
        ) {
            otherControl.value = control.value;
        }
    });
}

function handleOrderViewInput(event) {
    markUnsavedPickingChanges(event);
    markUnsavedLineChanges(event);
}

function handleOrderViewChange(event) {
    markUnsavedPickingChanges(event);
    markUnsavedLineChanges(event);

    if (event.target?.matches?.('#hideFinishedRows')) {
        hideFinishedRows = Boolean(event.target.checked);
        applyFinishedRowsVisibility();
    }
}

function handleOrderViewClick(event) {
    const button = event.target?.closest?.('[data-order-view-action]');
    if (!button) return;

    const handlers = {
        change: startLineEditing,
        cancelLineChanges: cancelLineEditing,
        saveLineChanges: saveLineChanges,
        deleteLine: stageDeleteOrderLine,
        delete: deleteCurrentOrder,
        print: printCurrentOrderLabels,
        save: savePicking
    };
    const handler = handlers[button.dataset.orderViewAction];

    if (handler) {
        handler(button);
    }
}

function renderOrderItemControls(status) {
    const canEdit = ['pending', 'ongoing'].includes(status);
    const canDelete = !['booking', 'waiting_packing_slip', 'sent'].includes(status);
    const canPrint = !['sent', 'not_sent'].includes(status);
    const canSavePicking = status === 'ongoing';

    return `
        <div class="order-item-controls no-print">
            ${renderHideFinishedRowsToggle()}
            <div class="order-item-action-buttons">
                ${canEdit && !isLineEditMode ? '<button type="button" class="btn btn-edit" id="changeOrderLinesBtn" data-order-view-action="change">Change</button>' : ''}
                ${canEdit && isLineEditMode ? '<button type="button" class="btn btn-success" id="saveOrderLineChangesBtn" data-order-view-action="saveLineChanges">Save Changes</button>' : ''}
                ${canEdit && isLineEditMode ? '<button type="button" class="btn btn-secondary" id="cancelOrderLineChangesBtn" data-order-view-action="cancelLineChanges">Cancel</button>' : ''}
                ${canDelete && !isLineEditMode ? '<button type="button" class="btn btn-delete" id="deleteOrderBtn" data-order-view-action="delete">Delete</button>' : ''}
                ${canPrint && !isLineEditMode ? '<button type="button" class="btn btn-print" id="printLabelsBtn" data-order-view-action="print">Print Labels</button>' : ''}
                ${canSavePicking && !isLineEditMode ? '<button type="button" class="btn btn-success" id="savePickingBtn" data-order-view-action="save">Save Picking</button>' : ''}
            </div>
        </div>
    `;
}

function applyFinishedRowsVisibility() {
    const toggle = document.getElementById('hideFinishedRows');
    const summary = document.getElementById('finishedRowsSummary');

    if (!toggle) return;

    const finishedItemIds = new Set();

    document.querySelectorAll(
        '[data-order-item-row], [data-mobile-order-item-row]'
    ).forEach(row => {
        const isFinished = row.classList.contains('picked-row-done');

        if (isFinished) {
            finishedItemIds.add(String(row.dataset.itemId || ''));
        }

        row.hidden = toggle.checked && isFinished;
    });

    if (!summary) return;

    const finishedCount = finishedItemIds.size;

    if (finishedCount === 0) {
        summary.textContent = 'No finished rows';
    } else if (toggle.checked) {
        summary.textContent = `${finishedCount} hidden`;
    } else {
        summary.textContent = `${finishedCount} finished`;
    }
}

function renderHideFinishedRowsToggle() {
    return `
        <div class="order-finished-toggle-row no-print">
            <label class="order-finished-toggle" for="hideFinishedRows">
                <input type="checkbox" id="hideFinishedRows" role="switch" aria-controls="orderItemsSection" ${hideFinishedRows ? 'checked' : ''}>
                <span class="order-finished-toggle-track" aria-hidden="true"></span>
                <span class="order-finished-toggle-label">Hide finished rows</span>
                <small id="finishedRowsSummary" aria-live="polite">No finished rows</small>
            </label>
        </div>
    `;
}

function isOrderEntryActive() {
    const active = document.activeElement;

    return Boolean(
        active &&
        active.closest &&
        active.closest('#orderViewBody') &&
        active.matches('input, select, textarea')
    );
}

async function loadOrder(options = {}) {
    if (isLoadingOrder) {
        return;
    }

    const silent = Boolean(options.silent);

    if (silent && (
        hasUnsavedPickingChanges ||
        hasUnsavedLineChanges ||
        isLineEditMode ||
        isOrderEntryActive()
    )) {
        pendingOrderRefresh = true;
        return;
    }

    isLoadingOrder = true;

    try {
        const response = await fetch(
            `php/functions/get_order.php?id=${encodeURIComponent(orderId)}&t=${Date.now()}`,
            { cache: 'no-store' }
        );
        const result = await response.json();

        if (!result.success) {
            if (!silent) {
                document.getElementById('orderViewBody').innerHTML = escapeHtml(result.message || 'Failed to load order.');
            }
            return;
        }

        if (silent && (
            hasUnsavedPickingChanges ||
            hasUnsavedLineChanges ||
            isLineEditMode ||
            isOrderEntryActive()
        )) {
            pendingOrderRefresh = true;
            return;
        }

        currentOrder = result.order;
        currentItems = result.items || [];
        renderOrder(currentOrder, currentItems);
        pendingOrderRefresh = false;
    } catch (error) {
        console.error(error);
        if (!silent) {
            document.getElementById('orderViewBody').innerHTML = 'Error loading order.';
        }
    } finally {
        isLoadingOrder = false;
    }
}

document.addEventListener('focusout', () => {
    if (pendingOrderRefresh) {
        window.setTimeout(() => {
            if (
                !hasUnsavedPickingChanges &&
                !hasUnsavedLineChanges &&
                !isLineEditMode &&
                !isOrderEntryActive()
            ) {
                loadOrder({ silent: true });
            }
        }, 150);
    }
});

async function startPicking() {
    const response = await fetch('php/functions/start_picking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: orderId })
    });

    const result = await response.json();
    if (!result.success) {
        alert(result.message || 'Failed to start picking.');
        return;
    }

    await loadOrder();
}

async function savePicking() {
    const mobileView = window.matchMedia('(max-width: 768px)').matches;
    const rowSelector = mobileView
        ? '[data-mobile-order-item-row]'
        : '[data-order-item-row]';
    const rows = document.querySelectorAll(rowSelector);
    const grouped = new Map();

    rows.forEach(row => {
        const itemId = Number(row.dataset.itemId);
        if (!itemId) return;

        if (!grouped.has(itemId)) {
            grouped.set(itemId, {
                id: itemId,
                ctnValues: [],
                doneChecked: false,
                hasDoneCheckbox: false,
                isAlreadyDone: false
            });
        }

        const group = grouped.get(itemId);

        if (row.classList.contains('picked-row-done')) {
            group.isAlreadyDone = true;
        }

        row.querySelectorAll('.pick-ctn-input').forEach(input => {
            group.ctnValues.push(input.value.trim());
        });

        const doneInput =
            row.querySelector('.pick-done-input') ||
            row.querySelector('.pick-done-checkbox');

        if (doneInput) {
            group.hasDoneCheckbox = true;
            if (doneInput.checked) {
                group.doneChecked = true;
            }
        }
    });

    const items = [];

grouped.forEach(group => {
    if (!group.hasDoneCheckbox && !group.ctnValues.length) {
        return;
    }

    items.push({
        id: group.id,
        picked_ctn_no: group.ctnValues.join(' | '),
        picked_done: group.doneChecked || group.isAlreadyDone ? '1' : '0'
    });
});

    try {
        const response = await fetch('php/functions/save_picking_lines.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: Number(orderId),
                items: items
            })
        });

        const rawText = await response.text();
        console.log('Save picking raw response:', rawText);

        let result;

        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert('Save picking failed. Server returned invalid JSON. Check console.');
            return;
        }

        if (!result.success) {
            alert(result.message || 'Failed to save picking.');
            return;
        }

        hasUnsavedPickingChanges = false;
        await loadOrder();

    } catch (error) {
        console.error(error);
        alert('Save picking request failed.');
    }
}

async function checkOrder() {
    const checkerName = document.getElementById('checkerName').value.trim();

    if (!checkerName) {
        alert('Please enter checker name.');
        return;
    }

    try {
        const response = await fetch('php/functions/check_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: Number(orderId),
                checker_name: checkerName
            })
        });

        const rawText = await response.text();
        console.log('Check order raw response:', rawText);

        let result;

        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert('Check failed. Server returned invalid JSON. Check console.');
            return;
        }

        if (!result.success) {
            alert(result.message || 'Check failed.');
            return;
        }

        await loadOrder();

    } catch (error) {
        console.error(error);
        alert('Check request failed.');
    }
}

async function bookCourier() {
    let courierName = document.getElementById('courierName').value;
    const customCourier = document.getElementById('customCourierName').value.trim();
    const courierReference = document.getElementById('courierReference').value.trim();

    if (courierName === 'Other') courierName = customCourier;

    if (!courierName || !courierReference) {
        alert('Please enter courier and reference.');
        return;
    }

    const response = await fetch('php/functions/book_courier.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: orderId,
            courier_name: courierName,
            courier_reference: courierReference
        })
    });

    const result = await response.json();
    if (!result.success) {
        alert(result.message || 'Booking failed.');
        return;
    }

    await loadOrder();
}

function setupPackingSlipDropZone() {
    const fileInput = document.getElementById('packingSlipFile');
    const dropZone = document.getElementById('packingSlipDropZone');

    if (!fileInput || !dropZone) return;

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, event => {
            event.preventDefault();

            if (!dropZone.classList.contains('is-uploading')) {
                dropZone.classList.add('is-dragover');
            }
        });
    });

    dropZone.addEventListener('dragleave', event => {
        if (event.relatedTarget && dropZone.contains(event.relatedTarget)) return;
        dropZone.classList.remove('is-dragover');
    });

    dropZone.addEventListener('drop', event => {
        event.preventDefault();
        dropZone.classList.remove('is-dragover');

        if (dropZone.classList.contains('is-uploading')) return;

        const files = Array.from(event.dataTransfer?.files || []);

        if (files.length !== 1) {
            setPackingSlipDropState('error', 'Drop one packing slip at a time.');
            return;
        }

        uploadPackingSlip(files[0]);
    });

    dropZone.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();

        if (!dropZone.classList.contains('is-uploading')) {
            fileInput.click();
        }
    });

    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file) uploadPackingSlip(file);
    });
}

function setPackingSlipDropState(state, message) {
    const dropZone = document.getElementById('packingSlipDropZone');
    const fileInput = document.getElementById('packingSlipFile');
    const status = document.getElementById('packingSlipStatus');
    const uploading = state === 'uploading';

    if (!dropZone || !fileInput || !status) return;

    dropZone.classList.toggle('is-uploading', uploading);
    dropZone.classList.toggle('is-error', state === 'error');
    dropZone.setAttribute('aria-busy', uploading ? 'true' : 'false');
    dropZone.setAttribute('aria-disabled', uploading ? 'true' : 'false');
    fileInput.disabled = uploading;
    status.textContent = message;
}

async function uploadPackingSlip(file) {
    const fileInput = document.getElementById('packingSlipFile');
    const extension = String(file?.name || '').split('.').pop().toLowerCase();
    const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];

    if (!file || !allowedExtensions.includes(extension)) {
        setPackingSlipDropState('error', 'Use a PDF, JPG, PNG, XLS or XLSX file.');
        if (fileInput) fileInput.value = '';
        return;
    }

    setPackingSlipDropState('uploading', `Uploading ${file.name}...`);

    try {
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('packing_slip', file);

        const response = await fetch('php/functions/upload_packing_slip.php', {
            method: 'POST',
            body: formData,
            cache: 'no-store'
        });

        const rawText = await response.text();
        let result;

        try {
            result = JSON.parse(rawText);
        } catch (error) {
            console.error('Upload response:', rawText);
            throw new Error('Server returned an invalid upload response.');
        }

        if (!result.success) {
            throw new Error(result.message || 'Upload failed.');
        }

        window.location.assign(
            `order_view.php?id=${encodeURIComponent(orderId)}&msg=packing_uploaded&t=${Date.now()}`
        );
    } catch (error) {
        console.error(error);
        setPackingSlipDropState('error', error.message || 'Upload failed. Drop the file again to retry.');
        if (fileInput) fileInput.value = '';
    }
}

async function reopenOrder() {
    const message = [
        'Reopen this order to add more items?',
        '',
        'Existing completed items and stock deductions will stay unchanged.',
        'Checking and courier details will be cleared. Amend or cancel the courier booking separately if required.'
    ].join('\n');

    if (!confirm(message)) {
        return;
    }

    const reopenButton = document.getElementById('reopenOrderBtn');
    reopenButton.disabled = true;
    reopenButton.textContent = 'Reopening...';

    try {
        const response = await fetch('php/functions/reopen_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: orderId })
        });
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Order could not be reopened.');
        }

        window.location.href = result.edit_url
            || `orders.php?edit=${encodeURIComponent(orderId)}&reopened=1`;
    } catch (error) {
        console.error(error);
        alert(error.message || 'Reopen request failed.');
        reopenButton.disabled = false;
        reopenButton.textContent = 'Reopen & Add Items';
    }
}

function cloneOrderItems(items) {
    return items.map(item => ({ ...item }));
}

function isCompletedOrderLine(item) {
    return String(item?.picked_done || '') === '1' || Boolean(item?.stock_deducted_at);
}

function startLineEditing() {
    if (!['pending', 'ongoing'].includes(currentOrder?.status || '')) {
        alert('This order cannot be changed in its current status.');
        return;
    }

    if (hasUnsavedPickingChanges) {
        alert('Save Picking before changing the order lines.');
        return;
    }

    lineEditSnapshot = cloneOrderItems(currentItems);
    deletedOrderItemIds = new Set();
    hasUnsavedLineChanges = false;
    isLineEditMode = true;
    renderOrder(currentOrder, currentItems);
}

function cancelLineEditing() {
    if (hasUnsavedLineChanges && !confirm('Discard the unsaved line changes?')) {
        return;
    }

    currentItems = cloneOrderItems(lineEditSnapshot);
    lineEditSnapshot = [];
    deletedOrderItemIds = new Set();
    hasUnsavedLineChanges = false;
    isLineEditMode = false;
    pendingOrderRefresh = false;
    renderOrder(currentOrder, currentItems);
}

function stageDeleteOrderLine(button) {
    const itemId = Number(button.dataset.itemId || 0);
    const item = currentItems.find(entry => Number(entry.id) === itemId);

    if (!item) return;

    if (isCompletedOrderLine(item)) {
        alert('Completed lines cannot be deleted because their stock has already been processed.');
        return;
    }

    collectLineEditorItems();

    if (currentItems.length <= 1) {
        alert('An order must keep at least one line.');
        return;
    }

    currentItems = currentItems.filter(entry => Number(entry.id) !== itemId);
    deletedOrderItemIds.add(itemId);
    hasUnsavedLineChanges = true;
    renderOrder(currentOrder, currentItems);
}

function collectLineEditorItems() {
    const mobileView = window.matchMedia('(max-width: 768px)').matches;
    const selector = mobileView
        ? '[data-mobile-order-line-editor]'
        : '[data-order-line-editor]';
    const rows = document.querySelectorAll(selector);
    const itemsById = new Map(
        currentItems.map(item => [Number(item.id), { ...item }])
    );

    rows.forEach(row => {
        const itemId = Number(row.dataset.itemId || 0);
        const item = itemsById.get(itemId);

        if (!item) return;

        row.querySelectorAll('[data-order-line-field]').forEach(control => {
            const field = control.dataset.orderLineField;
            const value = field === 'total_qty_supplied'
                ? String(control.value ?? '').trim()
                : normalizeAlignedEditorValue(control.value);

            item[field] = value;

            if (field === 'total_qty_supplied') {
                item.qty_supplied = value;
            }

            if (field === 'ctn_no' && String(item.picked_ctn_no || '').trim() !== '') {
                item.picked_ctn_no = value;
            }
        });
    });

    currentItems = currentItems.map(item => itemsById.get(Number(item.id)) || item);
    return currentItems;
}

function normalizeAlignedEditorValue(value) {
    return String(value ?? '')
        .split(/\n|\|/)
        .map(entry => entry.trim())
        .join(' | ');
}

function lineEditorValue(value) {
    return String(value ?? '')
        .split('|')
        .map(entry => entry.trim())
        .join('\n');
}

function getDisplayedCtnNumber(item) {
    return String(item.picked_ctn_no || '').trim() !== ''
        ? item.picked_ctn_no
        : item.ctn_no;
}

async function saveLineChanges() {
    const items = collectLineEditorItems().map(item => ({
            id: Number(item.id),
            batch_no: item.batch_no || '',
            total_qty_supplied: item.total_qty_supplied || '',
            qty_supplied_per_batch: item.qty_supplied_per_batch || '',
            units_per_ctn: item.units_per_ctn || '',
            full_ctn: item.full_ctn || '',
            ctn_no: getDisplayedCtnNumber(item) || '',
            location: item.location || '',
            comment: item.comment || ''
        }));
    const saveButton = document.getElementById('saveOrderLineChangesBtn');

    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    }

    try {
        const response = await fetch('php/functions/save_order_line_changes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: Number(orderId),
                items,
                deleted_item_ids: Array.from(deletedOrderItemIds)
            })
        });
        const rawText = await response.text();
        let result;

        try {
            result = JSON.parse(rawText);
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'The line changes could not be saved.');
        }

        isLineEditMode = false;
        lineEditSnapshot = [];
        deletedOrderItemIds = new Set();
        hasUnsavedLineChanges = false;
        pendingOrderRefresh = false;
        await loadOrder();
        showSuccessMessage('Order lines updated.');
    } catch (error) {
        console.error(error);
        alert(error.message || 'The line changes could not be saved.');

        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Changes';
        }
    }
}

async function deleteCurrentOrder() {
    if (!confirm('Delete this order?')) return;

    try {
        const response = await fetch('php/functions/delete_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: orderId })
        });

        const result = await response.json();

        if (!result.success) {
            alert(result.message || 'Delete failed.');
            return;
        }

        window.location.href = 'orders_list.php';
    } catch (error) {
        console.error(error);
        alert('Delete request failed.');
    }
}

async function printCurrentOrderLabels() {
    try {
        const response = await fetch(
            `php/functions/print_carton_labels.php?id=${encodeURIComponent(orderId)}`
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

function renderOrder(order, items) {
    const isPicking = order.status === 'ongoing';
    const status = order.status || 'pending';

    document.getElementById('startPickingBtn').style.display =
        order.status === 'pending' ? 'inline-block' : 'none';

    const scanPalletBtn = document.getElementById('scanPalletBtn');
    if (scanPalletBtn) {
        scanPalletBtn.style.display = ['pending', 'ongoing'].includes(status) ? 'inline-block' : 'none';
        scanPalletBtn.disabled = status !== 'ongoing';
        scanPalletBtn.title = status === 'pending' ? 'Start Picking before scanning pallets.' : '';
    }


    document.getElementById('reopenOrderBtn').style.display =
        ['booking', 'waiting_packing_slip'].includes(order.status) ? 'inline-block' : 'none';

    const downloadBtn = document.getElementById('downloadPickSlipBtn');
    if (downloadBtn) {
        const downloadUrl = `php/functions/export_pick_slip.php?id=${encodeURIComponent(orderId)}`;
        downloadBtn.href = downloadUrl;
        downloadBtn.dataset.downloadUrl = downloadUrl;
        downloadBtn.style.display =
            ['waiting_packing_slip', 'sent', 'not_sent'].includes(order.status) ? 'inline-block' : 'none';
    }

    document.getElementById('checkingPanel').style.display =
        order.status === 'ongoing' && isPickingComplete(items) ? 'block' : 'none';

    document.getElementById('bookingPanel').style.display =
        order.status === 'booking' ? 'block' : 'none';

    document.getElementById('packingSlipPanel').style.display =
        order.status === 'waiting_packing_slip' ? 'block' : 'none';

    document.getElementById('orderViewBody').innerHTML = `
        <div class="print-area order-view-shell">
            <section class="order-summary-card">
                <div class="order-summary-header">
                    <div>
                        <span class="order-summary-kicker">Picking List</span>
                        <h2>Invoice ${formatEmpty(order.invoice_no)}</h2>
                        <p>${formatEmpty(order.customer_name)}${order.customer_code ? ` - ${escapeHtml(order.customer_code)}` : ''}</p>
                    </div>
                    <span class="status-badge status-${escapeHtml(status)}">${escapeHtml(formatStatus(status))}</span>
                </div>

                <div class="order-meta-grid">
                    ${renderMetaItem('Order No', order.order_number)}
                    ${renderMetaItem('Order Date', order.order_date)}
                    ${renderMetaItem('Delivery Date', order.completed_at ? formatDateTime(order.completed_at) : order.delivery_date)}
                    ${renderMetaItem('Sales Person', order.sales_person)}
                    ${renderMetaItem('Packed By', order.picker_name)}
                    ${renderMetaItem('Checked By', order.checker_name)}
                    ${renderMetaItem('Checked At', order.checked_at ? formatDateTime(order.checked_at) : '')}
                    ${renderMetaItem('Courier', order.courier_name)}
                    ${renderMetaItem('Courier Ref', order.courier_reference)}
                    ${order.packing_slip_file ? `
                        <div class="order-meta-item">
                            <span>Packing Slip</span>
                            <strong><a href="${escapeHtml(order.packing_slip_file)}" target="_blank">View File</a></strong>
                        </div>
                    ` : ''}
                </div>

                <div class="order-address-block">
                    <span>Delivery Address</span>
                    <strong>${formatEmpty(order.customer_address)}</strong>
                </div>

                ${renderOrderComments(order.order_comments)}
            </section>

            <div class="order-print-header print-only-summary">
                <div>
                    <h2>Picking List</h2>
                    <p><strong>Invoice:</strong> ${escapeHtml(order.invoice_no || '')}</p>
                    <p><strong>Order No:</strong> ${escapeHtml(order.order_number || '')}</p>
                    <p><strong>Status:</strong> ${escapeHtml(formatStatus(order.status || 'pending'))}</p>
                </div>
                <div>
                    <p><strong>Date:</strong> ${escapeHtml(order.order_date || '')}</p>
                    ${order.checked_at ? `<p><strong>Checked At:</strong> ${formatDateTime(order.checked_at)}</p>` : ''}
                    <p><strong>Delivery Date:</strong> ${order.completed_at? formatDateTime(order.completed_at): escapeHtml(order.delivery_date || '')}</p>
                </div>
            </div>

            <div class="order-customer-box print-only-block">
                <p><strong>Customer:</strong> ${escapeHtml(order.customer_name || '')}</p>
                <p><strong>Address:</strong> ${escapeHtml(order.customer_address || '')}</p>
                <p><strong>Customer Code:</strong> ${escapeHtml(order.customer_code || '')}</p>
                <p><strong>Sales Person:</strong> ${escapeHtml(order.sales_person || '')}</p>
                ${renderPrintOrderComments(order.order_comments)}

                ${order.picker_name ? `<p><strong>Packed By:</strong> ${escapeHtml(order.picker_name)}</p>` : ''}

                ${order.checker_name ? `<p><strong>Checked By:</strong> ${escapeHtml(order.checker_name)}</p>` : ''}

                ${order.courier_name ? `<p><strong>Courier:</strong> ${escapeHtml(order.courier_name)}</p>` : ''}
                
                ${order.packing_slip_file ? `<p><strong>Packing Slip:</strong> <a href="${escapeHtml(order.packing_slip_file)}" target="_blank">View File</a></p>` : ''}
            </div>

            ${renderOrderItemControls(status)}

            <section class="order-lines-card" id="orderItemsSection">
                <div class="order-lines-header">
                    <div>
                        <h3>Items</h3>
                        <p>
                            ${items.length} ${items.length === 1 ? 'line' : 'lines'}
                            ${isLineEditMode ? ' - SKU, description and ordered quantities are read-only' : ''}
                        </p>
                    </div>
                </div>

            <div class="table-wrap order-items-table-wrap">
                <table class="orders-table preview-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>BATCH EXPIRY</th>
                            <th>Description</th>
                            <th>Qty Ordered</th>
                            <th>TOTAL Qty Ordered</th>
                            <th>TOTAL QTY SUPPLIED</th>
                            <th>QTY SUPPLIED</th>
                            <th>UNITS/CTN</th>
                            <th class="full-ctn-cell">NO. FULL CTN</th>
                            <th class="ctn-number-cell">CTN #</th>
                            <th>LOCATION</th>
                            <th>COMMENT</th>
                            ${['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '')
                                ? '<th>Print</th>'
                                : ''
                            }
                            <th class="no-print">Done</th>
                            ${isLineEditMode ? '<th class="no-print order-line-delete-heading"><span class="visually-hidden">Delete line</span></th>' : ''}
                        </tr>
                    </thead>
                    <tbody>${isLineEditMode ? renderEditableItems(items) : renderItems(items, isPicking)}</tbody>
                </table>
            </div>
            <div class="mobile-order-items" aria-label="Order items">
                ${renderMobileItems(items, isPicking)}
            </div>
            </section>
        </div>
    `;

    applyFinishedRowsVisibility();
}


function renderOrderComments(value) {
    const text = String(value ?? '').trim();
    if (!text) return '';

    return [
        '                <div class="order-comments-block">',
        '                    <span>Order Comments</span>',
        '                    <strong>' + formatMultiline(text) + '</strong>',
        '                </div>'
    ].join('');
}

function renderPrintOrderComments(value) {
    const text = String(value ?? '').trim();
    if (!text) return '';

    return '<p><strong>Comments:</strong> ' + formatMultiline(text) + '</p>';
}

function formatMultiline(value) {
    return escapeHtml(value).replace(/\r?\n/g, '<br>');
}

function renderEditableItems(items) {
    if (!items.length) {
        const canPrintColumn = ['pending', 'ongoing', 'booking', 'waiting_packing_slip']
            .includes(currentOrder.status || '');
        return `<tr class="empty-row"><td colspan="${canPrintColumn ? 15 : 14}">No items found.</td></tr>`;
    }

    const canPrint = ['pending', 'ongoing', 'booking', 'waiting_packing_slip']
        .includes(currentOrder.status || '');

    return items.map(item => {
        const completed = isCompletedOrderLine(item);

        return `
            <tr class="order-line-editor-row ${completed ? 'picked-row-done' : ''}"
                data-order-line-editor data-item-id="${Number(item.id)}">
                <td class="order-line-protected">${formatEmpty(item.sku_code)}</td>
                <td>${renderLineEditorControl(item, 'batch_no', 'Batch / expiry')}</td>
                <td class="order-line-protected">${formatEmpty(item.description)}</td>
                <td class="order-line-protected center-cell">${renderLineEditorReadOnly(item.order_qty)}</td>
                <td class="order-line-protected center-cell">${renderLineEditorReadOnly(item.total_qty)}</td>
                <td>${renderLineEditorControl(
                    item,
                    'total_qty_supplied',
                    'Total quantity supplied',
                    false,
                    item.total_qty_supplied || item.qty_supplied || ''
                )}</td>
                <td>${renderLineEditorControl(
                    item,
                    'qty_supplied_per_batch',
                    'Quantity supplied',
                    true,
                    item.qty_supplied_per_batch || item.qty_supplied || ''
                )}</td>
                <td>${renderLineEditorControl(item, 'units_per_ctn', 'Units per carton')}</td>
                <td>${renderLineEditorControl(item, 'full_ctn', 'Number of full cartons')}</td>
                <td>${renderLineEditorControl(item, 'ctn_no', 'Carton number', true, getDisplayedCtnNumber(item))}</td>
                <td>${renderLineEditorControl(item, 'location', 'Location')}</td>
                <td>${renderLineEditorControl(item, 'comment', 'Comment')}</td>
                ${canPrint ? `
                    <td class="center-cell no-print">
                        <button type="button" class="btn-mini btn-print"
                            onclick="printSkuLabels(${Number(item.id)})">Print</button>
                    </td>
                ` : ''}
                <td class="center-cell no-print">
                    ${completed ? '<span class="done-text">Done</span>' : ''}
                </td>
                <td class="center-cell no-print order-line-delete-cell">
                    ${renderLineDeleteButton(item)}
                </td>
            </tr>
        `;
    }).join('');
}

function renderLineEditorControl(item, field, label, multiline = true, valueOverride) {
    const value = typeof valueOverride === 'undefined' ? item[field] : valueOverride;

    if (!multiline) {
        return `
            <input
                type="text"
                class="order-line-edit-input"
                data-order-line-field="${escapeHtml(field)}"
                value="${escapeHtml(value || '')}"
                aria-label="${escapeHtml(label)}"
                autocomplete="off"
            >
        `;
    }

    const editorValue = lineEditorValue(value || '');
    const rows = Math.min(Math.max(editorValue.split('\n').length, 2), 4);

    return `
        <textarea
            class="order-line-edit-input order-line-edit-textarea"
            data-order-line-field="${escapeHtml(field)}"
            rows="${rows}"
            aria-label="${escapeHtml(label)}"
            autocomplete="off"
        >${escapeHtml(editorValue)}</textarea>
    `;
}

function renderLineEditorReadOnly(value) {
    const lines = splitAlignedLines(value || '');

    if (!lines.length || lines.every(line => line === '')) {
        return '<span class="muted-dash">-</span>';
    }

    return lines
        .map(line => `<span class="order-line-readonly-value">${escapeHtml(line || '')}</span>`)
        .join('');
}

function renderLineDeleteButton(item) {
    const completed = isCompletedOrderLine(item);
    const sku = String(item.sku_code || 'order line');
    const title = completed
        ? 'Completed lines cannot be deleted'
        : `Delete ${sku}`;

    return `
        <button
            type="button"
            class="order-line-delete-btn"
            data-order-view-action="deleteLine"
            data-item-id="${Number(item.id)}"
            aria-label="${escapeHtml(title)}"
            title="${escapeHtml(title)}"
            ${completed ? 'disabled' : ''}
        >&times;</button>
    `;
}

function renderEditableMobileItems(items) {
    if (!items.length) return '<p class="mobile-order-empty">No items found.</p>';

    return items.map(item => {
        const completed = isCompletedOrderLine(item);

        return `
            <article class="mobile-order-card mobile-order-line-editor ${completed ? 'picked-row-done' : ''}"
                data-mobile-order-line-editor data-item-id="${Number(item.id)}">
                <header class="mobile-order-card-header">
                    <h4>${formatEmpty(item.sku_code)}</h4>
                    ${renderLineDeleteButton(item)}
                </header>
                <div class="mobile-order-description">
                    <span>Description</span>
                    <strong>${formatEmpty(item.description)}</strong>
                </div>
                <dl class="mobile-order-details">
                    ${renderMobileLineEditorReadOnly('Order Qty', item.order_qty)}
                    ${renderMobileLineEditorReadOnly('Total Order Qty', item.total_qty)}
                    ${renderMobileLineEditorDetail(item, 'batch_no', 'Batch / Expiry')}
                    ${renderMobileLineEditorDetail(
                        item,
                        'total_qty_supplied',
                        'Total Qty Supplied',
                        false,
                        item.total_qty_supplied || item.qty_supplied || ''
                    )}
                    ${renderMobileLineEditorDetail(
                        item,
                        'qty_supplied_per_batch',
                        'Qty Supplied',
                        true,
                        item.qty_supplied_per_batch || item.qty_supplied || ''
                    )}
                    ${renderMobileLineEditorDetail(item, 'units_per_ctn', 'Units / CTN')}
                    ${renderMobileLineEditorDetail(item, 'full_ctn', 'No. Full CTN')}
                    ${renderMobileLineEditorDetail(item, 'ctn_no', 'CTN #', true, getDisplayedCtnNumber(item))}
                    ${renderMobileLineEditorDetail(item, 'location', 'Location', true, undefined, 'mobile-location-value')}
                    ${renderMobileLineEditorDetail(item, 'comment', 'Comment', true, undefined, 'mobile-comment-value')}
                </dl>
                ${completed ? '<div class="mobile-order-line-complete">Completed line - fields can be changed; deletion is locked</div>' : ''}
            </article>
        `;
    }).join('');
}

function renderMobileLineEditorReadOnly(label, value) {
    return `
        <div class="mobile-order-detail order-line-protected">
            <dt>${escapeHtml(label)}</dt>
            <dd>${renderLineEditorReadOnly(value)}</dd>
        </div>
    `;
}

function renderMobileLineEditorDetail(
    item,
    field,
    label,
    multiline = true,
    valueOverride,
    extraClass = ''
) {
    return `
        <div class="mobile-order-detail mobile-order-edit-detail ${escapeHtml(extraClass)}">
            <dt>${escapeHtml(label)}</dt>
            <dd>${renderLineEditorControl(item, field, label, multiline, valueOverride)}</dd>
        </div>
    `;
}

function renderMobileItems(items, isPicking) {
    if (isLineEditMode) {
        return renderEditableMobileItems(items);
    }

    if (!items.length) return '<p class="mobile-order-empty">No items found.</p>';

    const canPrint = ['pending', 'ongoing', 'booking', 'waiting_packing_slip']
        .includes(currentOrder.status || '');

    return items.map(item => {
        const batch = splitAlignedLines(item.batch_no || '');
        const ordered = splitAlignedLines(item.order_qty || '');
        const supplied = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
        const units = splitAlignedLines(item.units_per_ctn || '');
        const fullCtn = splitAlignedLines(item.full_ctn || '');
        const locations = splitAlignedLines(item.location || '');
        const ctn = splitAlignedLines(item.picked_ctn_no || item.ctn_no || '');
        const comments = splitAlignedLines(item.comment || '');
        const isDone = String(item.picked_done || '') === '1';
        const canEdit = isPicking && !isDone;
        const ctnInputs = Array.from(
            { length: ctn.length > 1 ? ctn.length : 1 },
            (_, index) => renderSingleCtnInput(ctn[index] || '', isPicking, item, index)
        );
        const showActions = canPrint || isDone || canEdit;

        return `
            <article class="mobile-order-card ${isDone ? 'picked-row-done' : ''}"
                data-mobile-order-item-row data-item-id="${item.id}">
                <header class="mobile-order-card-header">
                    <h4>${formatEmpty(item.sku_code)}</h4>
                    ${isDone ? '<span class="mobile-order-done-badge">Done</span>' : ''}
                </header>
                <div class="mobile-order-description">
                    <span>Description</span>
                    <strong>${formatEmpty(item.description)}</strong>
                </div>
                <dl class="mobile-order-details">
                    ${renderMobileDetail('Qty Ordered', item.total_qty || ordered)}
                    ${renderMobileDetail('Qty Supplied', item.total_qty_supplied || item.qty_supplied || supplied)}
                    ${renderMobileDetail('Units/CTN', units)}
                    ${renderMobileDetail('Location', locations, 'mobile-location-value')}
                    ${renderMobileDetail('Batch / Expiry', batch)}
                    ${renderMobileDetail('No. Full CTN', fullCtn)}
                    ${renderMobileDetail('CTN #', ctnInputs, 'mobile-ctn-value', true)}
                    ${renderMobileDetail('Comment', comments, 'mobile-comment-value')}
                </dl>
                ${showActions ? `
                    <footer class="mobile-order-card-actions no-print">
                        ${canPrint ? `<button type="button" class="btn-mini btn-print"
                            onclick="printSkuLabels(${item.id})">Print</button>` : ''}
                        ${(isDone || canEdit) ? `<label class="mobile-done-control">
                            <span>Done</span>${renderLineDone(item, canEdit, 0)}
                        </label>` : ''}
                    </footer>
                ` : ''}
            </article>
        `;
    }).join('');
}

function renderMobileDetail(label, value, extraClass = '', isHtml = false) {
    const values = (Array.isArray(value) ? value : [value])
        .map(entry => String(entry ?? '').trim()).filter(Boolean);
    if (!values.length && label !== 'CTN #') return '';

    const content = values.length
        ? values.map(entry => `<span>${isHtml ? entry : escapeHtml(entry)}</span>`).join('')
        : '<span class="muted-dash">-</span>';

    return `<div class="mobile-order-detail ${extraClass}">
        <dt>${escapeHtml(label)}</dt><dd>${content}</dd>
    </div>`;
}

function renderItems(items, isPicking) {
    if (!items.length) {
        const canPrintColumn = ['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '');
        return `<tr class="empty-row"><td colspan="${canPrintColumn ? 14 : 13}">No items found.</td></tr>`;
    }

    let html = '';

    items.forEach(item => {
        const batchLines = splitAlignedLines(item.batch_no || '');
        const qtyOrderedLines = splitAlignedLines(item.order_qty || '');
        const qtySuppliedLines = splitAlignedLines(
            item.qty_supplied_per_batch || item.qty_supplied || ''
        );
        const unitsLines = splitAlignedLines(item.units_per_ctn || '');
        const fullCtnLines = splitAlignedLines(item.full_ctn || '');
        const locationLines = splitAlignedLines(item.location || '');
        const ctnLines = splitAlignedLines(item.picked_ctn_no || item.ctn_no || '');
        const commentLines = splitAlignedLines(item.comment || '');

        const itemFullyNoStock = isItemFullyNoStock(
            item,
            batchLines,
            locationLines,
            qtySuppliedLines
        );

        const hasManyLocations = locationLines.filter(Boolean).length > 1;
        const hasPartBoxLine = qtySuppliedLines.filter(Boolean).length > 1;

        const qtyOrderedShouldSpan = qtyOrderedLines.filter(Boolean).length <= 1;
        const qtySuppliedShouldSpan = !hasManyLocations && !hasPartBoxLine;
        const roundingMode = String(currentOrder?.rounding_mode ?? item.rounding_mode ?? '0');

        const unitsShouldSpan = roundingMode === '1' || unitsLines.filter(Boolean).length <= 1;
        const fullCtnShouldSpan =
            !String(item.full_ctn || '').includes('|')
            && fullCtnLines.filter(Boolean).length <= 1;
        const ctnShouldSpan = ctnLines.length <= 1;
        const locationShouldSpan = !hasManyLocations;

        const commentHasAnyValue = commentLines.some(v => String(v || '').trim() !== '');
        const commentShouldSpan = itemFullyNoStock || !commentHasAnyValue;

        const maxLines = Math.max(
            batchLines.length,
            qtyOrderedLines.length,
            qtySuppliedLines.length,
            unitsLines.length,
            fullCtnLines.length,
            locationLines.length,
            ctnLines.length,
            commentLines.length,
            1
        );
        const batchRowSpans = getConsecutiveRowSpans(batchLines, maxLines);

        const isDoneRow = String(item.picked_done || '') === '1';
        const canEditRow = isPicking && !isDoneRow;

        for (let i = 0; i < maxLines; i++) {
            const isDoneRow = String(item.picked_done || '') === '1';

            html += `<tr data-order-item-row data-item-id="${item.id}" class="${isDoneRow ? 'picked-row-done' : ''}">`;

            if (i === 0) {
                html += `
                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(item.sku_code || '')}</td>

                    ${renderBatchCell(batchLines, batchRowSpans, i)}

                    <td rowspan="${maxLines}" class="merged-cell">${escapeHtml(item.description || '')}</td>

                    ${qtyOrderedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtyOrderedLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] || '')}</td>`
                    }

                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(item.total_qty || '')}</td>
                    <td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(item.total_qty_supplied || item.qty_supplied || '')}</td>

                    ${qtySuppliedShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(qtySuppliedLines[0] || item.qty_supplied || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] || '')}</td>`
                    }

                    ${unitsShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(unitsLines[0] || item.units_per_ctn || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(unitsLines[i] || '')}</td>`
                    }
                    
                    ${fullCtnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(item.full_ctn || '')}</div></td>`
                        : `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] || '')}</div></td>`
                    }

                    ${ctnShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[0] || '', isPicking, item, 0)}</div></td>`
                        : `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[i] || '', isPicking, item, i)}</div></td>`
                    }

                    ${locationShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(locationLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(locationLines[i] || '')}</td>`
                    }

                    ${commentShouldSpan
                        ? `<td rowspan="${maxLines}" class="merged-cell center-cell">${escapeHtml(commentLines[0] || '')}</td>`
                        : `<td class="center-cell">${escapeHtml(commentLines[i] || '')}</td>`
                    }

                    ${['pending', 'ongoing', 'booking', 'waiting_packing_slip'].includes(currentOrder.status || '')
                        ? `
                            <td rowspan="${maxLines}" class="merged-cell center-cell no-print">
                                <button type="button"
                                    class="btn-mini btn-print"
                                    onclick="printSkuLabels(${item.id})">
                                    Print
                                </button>
                            </td>
                        `
                        : ''
                    }

                    <td rowspan="${maxLines}" class="merged-cell center-cell no-print">
                        ${renderLineDone(item, canEditRow, 0)}
                    </td>
                `;
            } else {
                html += `
                    ${renderBatchCell(batchLines, batchRowSpans, i)}
                    ${!qtyOrderedShouldSpan ? `<td class="center-cell">${escapeHtml(qtyOrderedLines[i] || '')}</td>` : ''}
                    ${!qtySuppliedShouldSpan ? `<td class="center-cell">${escapeHtml(qtySuppliedLines[i] || '')}</td>` : ''}
                    ${!unitsShouldSpan ? `<td class="center-cell">${escapeHtml(unitsLines[i] || '')}</td>` : ''}
                    ${!fullCtnShouldSpan ? `<td class="center-cell full-ctn-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="full-ctn-value">${escapeHtml(fullCtnLines[i] || '')}</div></td>` : ''}

                    ${!ctnShouldSpan
                        ? `<td class="center-cell ctn-number-cell" style="text-align:center !important; vertical-align:middle !important;"><div class="ctn-number-value">${renderSingleCtnInput(ctnLines[i] || '', isPicking, item, i)}</div></td>`
                        : ''
                    }

                    ${!locationShouldSpan ? `<td class="center-cell">${escapeHtml(locationLines[i] || '')}</td>` : ''}
                    ${!commentShouldSpan ? `<td class="center-cell">${escapeHtml(commentLines[i] || '')}</td>` : ''}
                `;
            }

            html += `</tr>`;
        }
    });

    return html;
}

function getConsecutiveRowSpans(lines, totalRows) {
    const values = Array.from(
        { length: totalRows },
        (_, index) => String(lines[index] ?? '').trim()
    );
    const nonEmptyValues = values.filter(Boolean);
    const spans = Array(totalRows).fill(1);

    if (new Set(nonEmptyValues).size <= 1) {
        spans.fill(0);
        spans[0] = totalRows;
        return spans;
    }

    for (let start = 0; start < totalRows;) {
        const value = values[start];

        if (value === '') {
            start++;
            continue;
        }

        let end = start + 1;
        while (end < totalRows && values[end] === value) {
            end++;
        }

        spans[start] = end - start;
        for (let index = start + 1; index < end; index++) {
            spans[index] = 0;
        }

        start = end;
    }

    return spans;
}

function renderBatchCell(lines, spans, index) {
    const rowSpan = spans[index] ?? 1;

    if (rowSpan === 0) {
        return '';
    }

    const rowspanAttribute = rowSpan > 1 ? ` rowspan="${rowSpan}"` : '';
    const mergedClass = rowSpan > 1 ? ' merged-cell' : '';

    return `<td${rowspanAttribute} class="${mergedClass} center-cell">${escapeHtml(lines[index] ?? '')}</td>`;
}

function renderMultiLine(lines) {
    if (!Array.isArray(lines) || !lines.length) {
        return '';
    }

    return lines
        .map(line => `<div class="stack-line">${escapeHtml(line ?? '')}</div>`)
        .join('');
}


function renderCtnInputs(lines, maxLines, isPicking, item) {
    const batchLines = splitAlignedLines(item.batch_no || '');
    const locationLines = splitAlignedLines(item.location || '');
    const qtySuppliedLines = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
    let html = '';

    for (let i = 0; i < maxLines; i++) {
        const isNoStock = isNoStockLine(batchLines, locationLines, qtySuppliedLines, i);

        if (isPicking && !isNoStock) {
            html += `<div class="stack-line"><input class="pick-ctn-input" type="text" value="${escapeHtml(lines[i] || '')}" placeholder="CTN #"></div>`;
        } else {
            html += `<div class="stack-line">${isNoStock ? 'NO STOCK' : escapeHtml(lines[i] || '')}</div>`;
        }
    }

    return html;
}

function renderDoneInputs(lines, maxLines, isPicking, item) {
    const batchLines = splitAlignedLines(item.batch_no || '');
    const locationLines = splitAlignedLines(item.location || '');
    const qtySuppliedLines = splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || '');
    let html = '';

    for (let i = 0; i < maxLines; i++) {
        const isNoStock = isNoStockLine(batchLines, locationLines, qtySuppliedLines, i);

        const checked = lines[i] === '1';

        if (isPicking && !isNoStock) {
            html += `<div class="stack-line"><input class="pick-done-input" type="checkbox" ${checked ? 'checked' : ''}></div>`;
        } else {
            html += `<div class="stack-line">${isNoStock ? 'N/A' : (checked ? 'Done' : '')}</div>`;
        }
    }

    return html;
}

function isPickingComplete(items) {
    return items.every(item => {
        if (isItemFullyNoStock(item)) {
            return true;
        }

        const ctnLines = splitLines(item.picked_ctn_no || '');
        const doneValue = (item.picked_done || '').includes('1');

        const hasCtn = ctnLines.some(v => v.trim() !== '');

        return hasCtn && doneValue;
    });
}

function splitLines(value) {

    if (!value) {
        return [];
    }

    return String(value)
        .split(/[|,]/) // split by | OR comma
        .map(v => v.trim())
        .filter(v => v !== '');
}

function splitAlignedLines(value) {
    if (value === null || typeof value === 'undefined' || value === '') {
        return [];
    }

    return String(value)
        .split(/[|,]/)
        .map(v => v.trim());
}

function isNoStockValue(value) {
    return String(value ?? '').trim().toUpperCase().includes('NO STOCK');
}

function isNoStockLine(batchLines, locationLines, qtySuppliedLines, index) {
    return isNoStockValue(batchLines[index]) ||
        isNoStockValue(locationLines[index]) ||
        isNoStockValue(qtySuppliedLines[index]);
}

function hasStockedLine(batchLines, locationLines, qtySuppliedLines, index) {
    const batch = String(batchLines[index] ?? '').trim();
    const location = String(locationLines[index] ?? '').trim();
    const qtySupplied = String(qtySuppliedLines[index] ?? '').trim();

    if (isNoStockLine(batchLines, locationLines, qtySuppliedLines, index)) {
        return false;
    }

    return batch !== '' || location !== '' || qtySupplied !== '';
}

function isItemFullyNoStock(item, batchLinesArg, locationLinesArg, qtySuppliedLinesArg) {
    const totalSupplied = String(item.total_qty_supplied ?? item.qty_supplied ?? '').trim();

    if (totalSupplied !== '' && isNoStockValue(totalSupplied)) {
        return true;
    }

    const batchLines = batchLinesArg || splitAlignedLines(item.batch_no || '');
    const locationLines = locationLinesArg || splitAlignedLines(item.location || '');
    const qtySuppliedLines = qtySuppliedLinesArg || splitAlignedLines(
        item.qty_supplied_per_batch || item.qty_supplied || ''
    );
    const lineCount = Math.max(batchLines.length, locationLines.length, qtySuppliedLines.length, 1);

    let hasNoStock = false;
    let hasStock = false;

    for (let index = 0; index < lineCount; index++) {
        if (isNoStockLine(batchLines, locationLines, qtySuppliedLines, index)) {
            hasNoStock = true;
        }

        if (hasStockedLine(batchLines, locationLines, qtySuppliedLines, index)) {
            hasStock = true;
        }
    }

    return hasNoStock && !hasStock;
}

function isNoStockLineFromItem(item, lineIndex) {
    return isNoStockLine(
        splitAlignedLines(item.batch_no || ''),
        splitAlignedLines(item.location || ''),
        splitAlignedLines(item.qty_supplied_per_batch || item.qty_supplied || ''),
        lineIndex
    );
}

function renderMultiLine(value) {
    const lines = splitLines(value);
    const clean = lines.filter(v => v !== '');
    const unique = [...new Set(clean)];

    if (unique.length === 1) {
        return `<div class="stack-line">${escapeHtml(unique[0])}</div>`;
    }

    return lines.map(v => `<div class="stack-line">${escapeHtml(v)}</div>`).join('');
}

function renderMergedColumn(value, maxLines) {
    const safe = escapeHtml(value ?? '');

    let html = '';

    for (let i = 0; i < maxLines; i++) {
        if (i === 0) {
            html += `<div class="stack-line merged">${safe}</div>`;
        } else {
            html += `<div class="stack-line empty"></div>`;
        }
    }

    return html;
}

function showSuccessMessage(message) {
    const existing = document.querySelector('.success-message');
    if (existing) existing.remove();

    const box = document.createElement('div');
    box.className = 'success-message';
    box.innerHTML = `
        <span>${escapeHtml(message)}</span>
        <button type="button" onclick="this.parentElement.remove()">×</button>
    `;

    const page = document.querySelector('.orders-page');
    const actions = document.querySelector('.order-top-actions');

    if (actions) {
        actions.insertAdjacentElement('afterend', box);
    } else if (page) {
        page.prepend(box);
    } else {
        document.body.prepend(box);
    }
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return isNaN(date) ? value : date.toLocaleString();
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

function formatEmpty(value) {
    const text = String(value ?? '').trim();
    return text ? escapeHtml(text) : '<span class="muted-dash">-</span>';
}

function renderMetaItem(label, value) {
    return `
        <div class="order-meta-item">
            <span>${escapeHtml(label)}</span>
            <strong>${formatEmpty(value)}</strong>
        </div>
    `;
}

function shouldMergeCtnNo(fullCtnValue) {
    const value = String(fullCtnValue || '').trim();

    if (value === '' || value.toUpperCase().includes('NO STOCK')) {
        return false;
    }

    const num = Number(value);

    return Number.isFinite(num) && Number.isInteger(num);
}

function renderMergedCtnInput(lines, maxLines, isPicking, item) {
    const isNoStock = isItemFullyNoStock(item);

    const value = String(lines || '');

    let html = '';

    for (let i = 0; i < maxLines; i++) {
        if (i === 0) {
            if (isPicking && !isNoStock) {
                html += `
                    <div class="stack-line merged">
                        <input class="pick-ctn-input" type="text" value="${escapeHtml(value)}" placeholder="CTN #">
                    </div>
                `;
            } else {
                html += `<div class="stack-line merged">${isNoStock ? 'NO STOCK' : escapeHtml(value)}</div>`;
            }
        } else {
            html += `<div class="stack-line empty"></div>`;
        }
    }

    return html;
}

function renderLineDone(item, canEditRow, lineIndex) {
    if (isItemFullyNoStock(item)) {
        return `<span class="na-text">N/A</span>`;
    }

    if (String(item.picked_done || '') === '1') {
        return `<span class="done-text">Done</span>`;
    }

    if (!canEditRow) {
        return '';
    }

    return `
        <input
            type="checkbox"
            class="pick-done-checkbox"
            data-line-index="${lineIndex}"
        >
    `;
}



function renderSingleDone(item, isPicking) {

    const isDone =
        String(item.picked_done || '') === '1';

    if (isDone) {
        return `<span class="done-text">Done</span>`;
    }

    if (!isPicking) {
        return '';
    }

    return `
        <input
            type="checkbox"
            class="pick-done-checkbox"
        >
    `;
}

function formatQtySupplied(item) {
    const qty = Number(item.qty_supplied);

    if (isItemFullyNoStock(item) || qty === 0) {
        return 'NO STOCK';
    }

    return item.qty_supplied;
}

function renderSingleCtnInput(value, isPicking, item, lineIndex = 0) {
    if (isNoStockLineFromItem(item, lineIndex)) {
        return 'NO STOCK';
    }

    if (isPicking) {

        return `
            <input
                class="pick-ctn-input"
                type="text"
                value="${escapeHtml(value || '')}"
                placeholder="CTN #"
            >
        `;
    }

    return escapeHtml(value || '');
}

async function printSkuLabels(itemId) {
    try {
        const response = await fetch(
            `php/functions/print_carton_labels.php?item_id=${encodeURIComponent(itemId)}`
        );

        const result = await response.json();

        if (!result.ok) {
            alert(result.message || 'Print failed.');
            return;
        }

        alert('SKU labels sent to printer.');
    } catch (error) {
        console.error(error);
        alert('Print request failed.');
    }
}

async function downloadCurrentPickSlip(event) {
    event.preventDefault();

    const button = event.currentTarget;
    const url = button.dataset.downloadUrl || button.getAttribute('href');

    if (!url) {
        return;
    }

    const originalText = button.textContent;
    const invoice = currentOrder?.invoice_no || orderId || 'order';

    button.style.pointerEvents = 'none';
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Preparing...';

    try {
        await downloadGeneratedOrderFile(
            url,
            `pick_slip_${sanitizeDownloadFilename(invoice)}.xlsx`
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'Pick slip download failed.');
    } finally {
        button.style.pointerEvents = '';
        button.removeAttribute('aria-busy');
        button.textContent = originalText;
    }
}

async function downloadGeneratedOrderFile(url, fallbackFilename) {
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

function sanitizeDownloadFilename(value) {
    return String(value || 'file').replace(/[^A-Za-z0-9_-]+/g, '_');
}

