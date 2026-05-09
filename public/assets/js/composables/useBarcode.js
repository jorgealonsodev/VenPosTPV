/**
 * Barcode composable — USB HID detection with debounce + ZXing camera support.
 */
const useBarcode = (function () {
    let buffer = '';
    let lastKeyTime = 0;
    let callback = null;
    let cameraScanner = null;
    let scanning = Vue.ref(false);

    const THRESHOLD = 50; // ms between chars

    function onKeyDown(e) {
        const now = Date.now();
        const delta = now - lastKeyTime;
        lastKeyTime = now;

        if (delta > THRESHOLD && buffer.length > 0 && e.key !== 'Enter') {
            buffer = '';
        }

        if (e.key === 'Enter') {
            if (buffer.length >= 4) {
                e.preventDefault();
                if (callback) callback(buffer);
            }
            buffer = '';
            return;
        }

        if (e.key.length === 1) {
            buffer += e.key;
        }
    }

    function onScan(cb) {
        callback = cb;
        document.addEventListener('keydown', onKeyDown);
    }

    function offScan() {
        callback = null;
        document.removeEventListener('keydown', onKeyDown);
    }

    async function startCamera() {
        if (typeof ZXing === 'undefined') {
            await loadZxing();
        }
        if (!ZXing) return;

        scanning.value = true;
        // Placeholder: actual camera implementation would use ZXing.BrowserMultiFormatReader
        // For MVP, we just set scanning state; full camera integration deferred.
    }

    function stopCamera() {
        scanning.value = false;
    }

    function loadZxing() {
        return new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/@zxing/browser@0.1.3/umd/index.min.js';
            script.onload = () => resolve(true);
            script.onerror = () => resolve(false);
            document.head.appendChild(script);
        });
    }

    // F6 toggle
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F6') {
            e.preventDefault();
            if (scanning.value) {
                stopCamera();
            } else {
                startCamera();
            }
        }
    });

    return {
        onScan,
        offScan,
        startCamera,
        stopCamera,
        scanning,
    };
})();
