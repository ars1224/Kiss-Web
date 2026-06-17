// Split from original designScript.js

// ---- Helpers ----
function parseDate(s) {
    if (!s) return NaN;

    const t = String(s).trim();
    const m = t.match(/^(\d{1,2})[\/\-](\d{4})$/);

    if (m) {
        const mm = +m[1];
        const yy = +m[2];

        return new Date(yy, mm - 1, 1).getTime();
    }

    const ts = Date.parse(t);

    return Number.isNaN(ts) ? NaN : ts;
}

function parseNum(s) {
    const n = parseFloat(String(s || '').replace(/,/g, ''));

    return Number.isNaN(n) ? 0 : n;
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/

const BASE_URL = '/kiss-web';

let notifSoundAllowed = false;
let notifAudioContext = null;
let notifInitialLoadDone = false;
let knownNotifIds = new Set();

function allowNotifSound() {
    notifSoundAllowed = true;

    const AudioCtx = window.AudioContext || window.webkitAudioContext;

    if (!AudioCtx) return;

    if (!notifAudioContext) {
        notifAudioContext = new AudioCtx();
    }

    if (notifAudioContext.state === 'suspended') {
        notifAudioContext.resume().catch(() => {});
    }
}

function playNotifSound() {
    if (!notifSoundAllowed) return;

    const AudioCtx = window.AudioContext || window.webkitAudioContext;

    if (!AudioCtx) return;

    if (!notifAudioContext) {
        notifAudioContext = new AudioCtx();
    }

    const playChime = () => {
        const now = notifAudioContext.currentTime;
        const gain = notifAudioContext.createGain();
        const firstTone = notifAudioContext.createOscillator();
        const secondTone = notifAudioContext.createOscillator();

        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.45);

        firstTone.type = 'sine';
        firstTone.frequency.setValueAtTime(880, now);
        firstTone.frequency.exponentialRampToValueAtTime(1174.66, now + 0.18);

        secondTone.type = 'sine';
        secondTone.frequency.setValueAtTime(1320, now + 0.08);

        firstTone.connect(gain);
        secondTone.connect(gain);
        gain.connect(notifAudioContext.destination);

        firstTone.start(now);
        firstTone.stop(now + 0.28);

        secondTone.start(now + 0.08);
        secondTone.stop(now + 0.45);

        secondTone.addEventListener('ended', () => {
            firstTone.disconnect();
            secondTone.disconnect();
            gain.disconnect();
        }, { once: true });
    };

    if (notifAudioContext.state === 'suspended') {
        notifAudioContext.resume().then(playChime).catch(() => {});
        return;
    }

    playChime();
}

document.addEventListener('click', allowNotifSound, { once: true, passive: true });
document.addEventListener('touchstart', allowNotifSound, { once: true, passive: true });

const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');

if (notifBtn && notifDropdown) {
    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notifDropdown.classList.toggle('active');
    });
    
notifDropdown.addEventListener('click', async (e) => {
    e.stopPropagation();

    const readAllBtn = e.target.closest('#readAllNotifBtn');

    if (readAllBtn) {
        e.preventDefault();

        await fetch(`${BASE_URL}/php/functions/mark_all_notifications_read.php`);
        await loadNotifications();

        return;
    }

    const item = e.target.closest('.notif-item');

    if (!item) return;

    e.preventDefault();

    const notifId = item.dataset.notifId;
    const notifLink = item.getAttribute('href') || '#';

    if (!notifId) {
        window.location.href = notifLink;
        return;
    }

    try {
        const response = await fetch(
            `${BASE_URL}/php/functions/mark_notification_read.php?id=${encodeURIComponent(notifId)}`
        );

        const result = await response.json();

        console.log('Marked single notification:', result);

    } catch (error) {
        console.error(error);
    }

    window.location.href = notifLink;
});

    document.addEventListener('click', () => {
        notifDropdown.classList.remove('active');
    });
}

async function loadNotifications() {
    try {
        const response = await fetch(`${BASE_URL}/php/functions/get_notifications.php`);
        const result = await response.json();

        if (!result.success) return;

        const dropdown = document.getElementById('notifDropdown');
        const countBadge = document.getElementById('notifCount');

        if (!dropdown) return;

        const newCount = Number(result.count || 0);
        const list = result.notifications || [];
        const nextNotifIds = new Set(list.map((notif) => String(notif.id)));
        const newNotifications = list.filter((notif) => !knownNotifIds.has(String(notif.id)));
        const shouldAlert = notifInitialLoadDone && newNotifications.length > 0;

        knownNotifIds = nextNotifIds;
        notifInitialLoadDone = true;

        if (countBadge) {
            countBadge.textContent = newCount;
            countBadge.style.display = newCount > 0 ? 'flex' : 'none';
        }

        dropdown.innerHTML = `
            <div class="notif-header">
                <h4>Notifications</h4>

                ${list.length ? `
                    <button type="button" id="readAllNotifBtn" class="read-all-btn">
                        Read All
                    </button>
                ` : ''}
            </div>
        `;

        if (!list.length) {
            dropdown.innerHTML += `<div class="notif-empty">No notifications</div>`;
            return;
        }

        list.forEach((notif) => {
            dropdown.innerHTML += `
                <a
                    href="${escapeAttr(notif.link || '#')}"
                    class="notif-item ${escapeAttr(notif.type || '')}"
                    data-notif-id="${Number(notif.id)}"
                >
                    <strong>${escapeHtml(notif.title || '')}</strong>
                    <span>${escapeHtml(notif.message || '')}</span>
                    <small>${timeAgo(notif.created_at)}</small>
                </a>
            `;
        });

        if (shouldAlert) {
            playNotifSound();
            showNotifToast(newNotifications[0].title, newNotifications[0].message);
        }

    } catch (error) {
        console.error(error);
    }
}

function timeAgo(dateString) {
    const date = new Date(dateString);
    const seconds = Math.floor((new Date() - date) / 1000);

    if (seconds < 60) return 'Just now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    }

    const days = Math.floor(hours / 24);

    return `${days} day${days > 1 ? 's' : ''} ago`;
}

function showNotifToast(title, message) {
    const toast = document.createElement('div');

    toast.className = 'notif-toast';

    toast.innerHTML = `
        <strong>${escapeHtml(title || 'Notification')}</strong>
        <span>${escapeHtml(message || '')}</span>
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('show');
    }, 50);

    setTimeout(() => {
        toast.classList.remove('show');

        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 4000);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeAttr(value) {
    return escapeHtml(value);
}

setInterval(loadNotifications, 10000);
loadNotifications();
