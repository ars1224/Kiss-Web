(function () {
    'use strict';

    let codeReader = null;
    let scannerControls = null;
    let scannerRun = 0;
    let isProcessing = false;
    let lastScannedCode = '';
    let lastScannedAt = 0;
    let scannerLibraryPromise = null;
    let fallbackQrLibraryPromise = null;
    let focusedScanTimer = null;
    let focusedScanCanvas = null;

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('scanPalletBtn')?.addEventListener('click', openPalletScanner);
        document.getElementById('palletScannerClose')?.addEventListener('click', closePalletScanner);
        document.getElementById('palletScannerCancel')?.addEventListener('click', closePalletScanner);
        document.getElementById('palletScannerTorch')?.addEventListener('click', toggleScannerTorch);
        document.getElementById('palletScanManualForm')?.addEventListener('submit', submitManualPalletCode);
        document.getElementById('palletScannerModal')?.addEventListener('click', event => {
            if (event.target.id === 'palletScannerModal') closePalletScanner();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isScannerOpen()) closePalletScanner();
        });
        if (scannerContext() === 'location') {
            restoreLocationScanSelection();
        }
        preloadScannerLibrary();
    });

    function scannerContext() {
        return document.getElementById('scanPalletBtn')?.dataset.scanContext || 'order';
    }

    function isScannerOpen() {
        return document.getElementById('palletScannerModal')?.classList.contains('is-open');
    }

    async function openPalletScanner() {
        const modal = document.getElementById('palletScannerModal');
        if (!modal) return;
        const runId = ++scannerRun;

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('scanner-modal-open');
        setScannerResult('neutral', 'Starting camera…', 'Point the camera at the QR code on the pallet label.');

        // Paint the modal before camera or scanner-library startup begins.
        await waitForScannerPaint();
        if (!isScannerOpen() || runId !== scannerRun) return;

        if (!window.isSecureContext) {
            setScannerResult('error', 'Camera unavailable', 'Open KISS-Web using its HTTPS address.');
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setScannerResult('error', 'Camera unavailable', 'This browser does not support camera scanning. Use the manual Pallet ID field below.');
            return;
        }

        try {
            await loadScannerLibrary();
        } catch (error) {
            console.error('Pallet scanner library error:', error);
            setScannerResult('error', 'Scanner unavailable', 'The barcode scanner library did not load. Check the network and try again.');
            return;
        }

        if (!isScannerOpen() || runId !== scannerRun) return;

        try {
            codeReader = new window.ZXingBrowser.BrowserMultiFormatReader(undefined, {
                delayBetweenScanAttempts: 150,
                delayBetweenScanSuccess: 1200
            });

            const video = document.getElementById('palletScannerVideo');
            const controls = await codeReader.decodeFromConstraints(
                {
                    audio: false,
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                },
                video,
                result => {
                    if (!result) return;
                    const barcodeFormat = result.getBarcodeFormat?.();
                    const qrFormat = window.ZXingBrowser.BarcodeFormat?.QR_CODE;
                    if (
                        qrFormat !== undefined
                        && barcodeFormat !== undefined
                        && barcodeFormat !== qrFormat
                    ) {
                        setScannerResult('neutral', 'Non-QR barcode ignored', 'Keep the pallet QR code centered inside the frame.');
                        return;
                    }
                    processPalletCode(result.getText());
                }
            );

            if (!isScannerOpen() || runId !== scannerRun) {
                await controls.stop?.();
                return;
            }
            scannerControls = controls;
            void startFocusedQrFallback(video, runId);

            const torchButton = document.getElementById('palletScannerTorch');
            if (torchButton && typeof scannerControls?.switchTorch === 'function') {
                torchButton.hidden = false;
            }
            setScannerResult('neutral', 'Camera ready', 'Hold the label steady inside the frame.');
        } catch (error) {
            console.error('Pallet scanner camera error:', error);
            const denied = error?.name === 'NotAllowedError' || error?.name === 'PermissionDeniedError';
            setScannerResult(
                'error',
                denied ? 'Camera permission denied' : 'Could not start camera',
                denied
                    ? 'Allow camera access in the browser, then close and reopen the scanner.'
                    : 'Check that no other app is using the camera, or enter the Pallet ID manually.'
            );
        }
    }

    function waitForScannerPaint() {
        return new Promise(resolve => {
            window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
        });
    }

    function loadScannerLibrary() {
        if (window.ZXingBrowser?.BrowserMultiFormatReader) {
            return Promise.resolve();
        }
        if (scannerLibraryPromise) return scannerLibraryPromise;

        scannerLibraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'js/vendor/zxing-browser-0.2.1.min.js';
            script.async = true;
            script.dataset.palletScannerLibrary = 'true';
            script.addEventListener('load', () => {
                if (window.ZXingBrowser?.BrowserMultiFormatReader) {
                    resolve();
                    return;
                }
                scannerLibraryPromise = null;
                reject(new Error('ZXing loaded without BrowserMultiFormatReader.'));
            }, { once: true });
            script.addEventListener('error', () => {
                scannerLibraryPromise = null;
                script.remove();
                reject(new Error('Could not load ZXing.'));
            }, { once: true });
            document.head.appendChild(script);
        });

        return scannerLibraryPromise;
    }

    function loadFallbackQrLibrary() {
        if (typeof window.jsQR === 'function') {
            return Promise.resolve();
        }
        if (fallbackQrLibraryPromise) return fallbackQrLibraryPromise;

        fallbackQrLibraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'js/vendor/jsqr-1.4.0.js?v=1.4.0';
            script.async = true;
            script.dataset.palletQrFallbackLibrary = 'true';
            script.addEventListener('load', () => {
                if (typeof window.jsQR === 'function') {
                    resolve();
                    return;
                }
                fallbackQrLibraryPromise = null;
                reject(new Error('jsQR loaded without its decoder.'));
            }, { once: true });
            script.addEventListener('error', () => {
                fallbackQrLibraryPromise = null;
                script.remove();
                reject(new Error('Could not load the QR fallback.'));
            }, { once: true });
            document.head.appendChild(script);
        });

        return fallbackQrLibraryPromise;
    }

    function preloadScannerLibrary() {
        const preload = () => {
            loadScannerLibrary().catch(() => {});
            loadFallbackQrLibrary().catch(() => {});
        };
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(preload, { timeout: 2000 });
            return;
        }
        window.setTimeout(preload, 500);
    }

    async function startFocusedQrFallback(video, runId) {
        try {
            await loadFallbackQrLibrary();
        } catch (error) {
            console.warn('Focused QR fallback unavailable:', error);
            return;
        }

        if (!isScannerOpen() || runId !== scannerRun) return;

        const frame = document.querySelector('.pallet-scanner-frame');
        const canvas = document.createElement('canvas');
        let context = null;
        try {
            context = canvas.getContext('2d', { willReadFrequently: true });
        } catch (error) {
            context = canvas.getContext('2d');
        }
        if (!context) return;

        const targetSize = 400;
        canvas.width = targetSize;
        canvas.height = targetSize;
        focusedScanCanvas = canvas;

        const scan = () => {
            if (!isScannerOpen() || runId !== scannerRun || focusedScanCanvas !== canvas) return;

            if (!isProcessing && video.readyState >= 2 && video.videoWidth && video.videoHeight) {
                try {
                    const videoRect = video.getBoundingClientRect();
                    const frameRect = frame?.getBoundingClientRect();
                    if (!videoRect.width || !videoRect.height) {
                        focusedScanTimer = window.setTimeout(scan, 180);
                        return;
                    }

                    const scale = Math.max(
                        videoRect.width / video.videoWidth,
                        videoRect.height / video.videoHeight
                    );
                    const displayedWidth = video.videoWidth * scale;
                    const displayedHeight = video.videoHeight * scale;
                    const croppedLeft = (displayedWidth - videoRect.width) / 2;
                    const croppedTop = (displayedHeight - videoRect.height) / 2;
                    const visibleFrameSize = frameRect?.width || Math.min(videoRect.width * 0.68, 300);
                    const frameLeft = frameRect
                        ? frameRect.left - videoRect.left
                        : (videoRect.width - visibleFrameSize) / 2;
                    const frameTop = frameRect
                        ? frameRect.top - videoRect.top
                        : (videoRect.height - visibleFrameSize) / 2;
                    const padding = visibleFrameSize * 0.08;
                    const sourceX = Math.max(0, (frameLeft - padding + croppedLeft) / scale);
                    const sourceY = Math.max(0, (frameTop - padding + croppedTop) / scale);
                    const sourceSize = Math.min(
                        (visibleFrameSize + padding * 2) / scale,
                        video.videoWidth - sourceX,
                        video.videoHeight - sourceY
                    );

                    if (sourceSize > 0) {
                        context.drawImage(
                            video,
                            sourceX,
                            sourceY,
                            sourceSize,
                            sourceSize,
                            0,
                            0,
                            targetSize,
                            targetSize
                        );
                        const image = context.getImageData(0, 0, targetSize, targetSize);
                        const decoded = window.jsQR(image.data, targetSize, targetSize, {
                            inversionAttempts: 'dontInvert'
                        });
                        if (decoded?.data) processPalletCode(decoded.data);
                    }
                } catch (error) {
                    console.warn('Focused QR fallback frame failed:', error);
                }
            }

            focusedScanTimer = window.setTimeout(scan, 180);
        };

        scan();
    }

    function closePalletScanner() {
        scannerRun++;
        if (focusedScanTimer !== null) {
            window.clearTimeout(focusedScanTimer);
            focusedScanTimer = null;
        }
        focusedScanCanvas = null;
        try {
            scannerControls?.stop?.();
        } catch (error) {
            console.warn('Scanner stop warning:', error);
        }

        scannerControls = null;
        codeReader = null;
        isProcessing = false;

        const video = document.getElementById('palletScannerVideo');
        if (video?.srcObject) {
            video.srcObject.getTracks().forEach(track => track.stop());
            video.srcObject = null;
        }

        const modal = document.getElementById('palletScannerModal');
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('scanner-modal-open');
    }

    async function toggleScannerTorch() {
        if (typeof scannerControls?.switchTorch !== 'function') return;
        try {
            await scannerControls.switchTorch();
        } catch (error) {
            setScannerResult('error', 'Torch unavailable', 'This phone does not allow the browser to control its torch.');
        }
    }

    function submitManualPalletCode(event) {
        event.preventDefault();
        const input = document.getElementById('palletScanManualInput');
        const value = input?.value?.trim() || '';
        if (!value) {
            setScannerResult('error', 'Enter a Pallet ID', 'Example: PLT-P-0000000157');
            input?.focus();
            return;
        }
        processPalletCode(value);
    }

    async function processPalletCode(rawCode) {
        const code = String(rawCode || '').trim();
        const now = Date.now();
        if (!code || isProcessing) return;
        if (code === lastScannedCode && now - lastScannedAt < 3000) return;

        lastScannedCode = code;
        lastScannedAt = now;
        isProcessing = true;
        setScannerResult('neutral', 'Checking pallet…', code);

        try {
            if (scannerContext() === 'location') {
                setScannerResult(
                    'neutral',
                    'QR code read',
                    `Finding pallet ${code}`
                );
                await processLocationPalletCode(code);
                return;
            }
            const response = await fetch('php/functions/validate_pallet_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: Number(orderId),
                    pallet_id: code
                })
            });
            const result = await response.json();

            if (response.status === 401) {
                setScannerResult('error', 'Session expired', 'Sign in again, then reopen this order.');
                return;
            }

            if (!result.success) {
                const detail = result.pallet
                    ? palletSummary(result.pallet)
                    : result.code === 'invalid_code'
                        ? `QR contained: ${scannedCodePreview(code)}`
                        : 'Check the label and try again.';
                setScannerResult('error', result.message || 'Wrong pallet', detail);
                navigator.vibrate?.([160, 80, 160]);
                return;
            }

            const pallet = result.pallet || {};
            const expectedQty = result.match?.expected_qty || '-';
            setScannerResult(
                'success',
                'Correct pallet',
                `${pallet.PalletID} · ${pallet.SKU_Code} · ${pallet.Location} · Pick ${expectedQty}`
            );
            navigator.vibrate?.(100);
            highlightOrderItem(result.match?.order_item_id);

            const manualInput = document.getElementById('palletScanManualInput');
            if (manualInput) manualInput.value = '';
        } catch (error) {
            const isLocationScan = scannerContext() === 'location';
            console.error('Pallet validation error:', error);
            setScannerResult(
                'error',
                isLocationScan ? 'Could not find pallet' : 'Could not validate pallet',
                'Check the network connection and try again.'
            );
        } finally {
            window.setTimeout(() => { isProcessing = false; }, 1200);
        }
    }

    async function processLocationPalletCode(code) {
        const response = await fetch('php/functions/find_pallet_location.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ pallet_id: code })
        });
        const result = await response.json();

        if (response.status === 401) {
            setScannerResult('error', 'Session expired', 'Sign in again, then reopen Product Location.');
            return;
        }

        if (!result.success) {
            const detail = result.code === 'invalid_code'
                ? `QR contained: ${scannedCodePreview(code)}`
                : 'Check the label and try again.';
            setScannerResult('error', result.message || 'Pallet not found', detail);
            navigator.vibrate?.([160, 80, 160]);
            return;
        }

        const pallet = result.pallet || {};
        const palletId = String(pallet.PalletID || '').trim();
        if (!selectLocationRowByPalletId(palletId)) {
            const url = new URL(window.location.href);
            url.searchParams.set('q', palletId);
            url.searchParams.set('scanned_pallet', palletId);
            window.location.assign(url.toString());
            return;
        }

        setScannerResult(
            'success',
            'Pallet selected',
            `${pallet.PalletID} · ${pallet.SKU_Code} · ${pallet.Location} · Choose an action below`
        );
        navigator.vibrate?.(100);

        const manualInput = document.getElementById('palletScanManualInput');
        if (manualInput) manualInput.value = '';
        window.setTimeout(closePalletScanner, 800);
    }

    function selectLocationRowByPalletId(palletId, shouldScroll = true) {
        const normalizedId = String(palletId || '').trim().toUpperCase();
        if (!normalizedId) return false;

        const rows = Array.from(document.querySelectorAll('.product-table tbody tr[data-pallet-id]'));
        const row = rows.find(candidate => String(candidate.dataset.palletId || '').trim().toUpperCase() === normalizedId);
        if (!row) return false;

        rows.forEach(currentRow => {
            const checkbox = currentRow.querySelector('.row-check');
            if (checkbox) checkbox.checked = false;
            currentRow.classList.remove('selected', 'pallet-scan-match');
        });

        const checkbox = row.querySelector('.row-check');
        if (!checkbox) return false;
        checkbox.checked = true;
        row.classList.add('selected', 'pallet-scan-match');
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));

        if (typeof updateActionVisibility === 'function') updateActionVisibility();
        if (typeof updateFooterTotals === 'function') updateFooterTotals();

        if (shouldScroll) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => row.classList.remove('pallet-scan-match'), 5000);
        return true;
    }

    function restoreLocationScanSelection() {
        const params = new URLSearchParams(window.location.search);
        const palletId = params.get('scanned_pallet');
        if (!palletId) return;

        if (selectLocationRowByPalletId(palletId)) {
            params.delete('scanned_pallet');
            const query = params.toString();
            window.history.replaceState({}, document.title, `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
        }
    }

    function scannedCodePreview(code) {
        const value = String(code || '').trim();
        if (!value) return '(empty)';
        return value.length > 160
            ? `${value.slice(0, 160)}...`
            : value;
    }

    function palletSummary(pallet) {
        return [pallet.PalletID, pallet.SKU_Code, pallet.Location]
            .map(value => String(value || '').trim())
            .filter(Boolean)
            .join(' · ');
    }

    function setScannerResult(type, title, detail) {
        const result = document.getElementById('palletScannerResult');
        if (!result) return;

        result.className = `pallet-scanner-result is-${type}`;
        result.innerHTML = `
            <strong>${escapeScannerHtml(title)}</strong>
            <span>${escapeScannerHtml(detail)}</span>
        `;
    }

    function highlightOrderItem(itemId) {
        if (!itemId) return;
        const selector = `[data-item-id="${CSS.escape(String(itemId))}"]`;
        const rows = Array.from(document.querySelectorAll(selector));
        rows.forEach(row => row.classList.add('pallet-scan-match'));
        rows[0]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => rows.forEach(row => row.classList.remove('pallet-scan-match')), 5000);
    }

    function escapeScannerHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
})();
