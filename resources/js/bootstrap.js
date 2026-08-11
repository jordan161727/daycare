import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/* ---- CSRF freshness -------------------------------------------------------
 * Blade stamps the CSRF token into the HTML once, at render time. The
 * attendance board and the import screen stay open far longer than the session
 * lifetime, so by the time someone submits, that stamped token is dead and
 * Laravel answers with the bare "Page Expired" screen.
 *
 * So: keep one token in a meta tag, top it up while somebody is actually at the
 * screen, and re-stamp every form when it changes.
 */

const tokenMeta = () => document.head.querySelector('meta[name="csrf-token"]');

window.csrfToken = () => (tokenMeta()?.content ?? '');

const applyToken = (token) => {
    if (! token) return;

    const meta = tokenMeta();
    if (meta) meta.content = token;

    document.querySelectorAll('input[name="_token"]').forEach(input => {
        input.value = token;
    });

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
};

const refreshCsrfToken = async () => {
    try {
        const response = await fetch('/csrf-token', {
            headers: {'Accept': 'application/json'},
            credentials: 'same-origin',
        });

        if (! response.ok) return '';

        const {token} = await response.json();
        applyToken(token);

        return token;
    } catch {
        // Offline or the server is down — the next heartbeat tries again.
        return '';
    }
};

window.refreshCsrfToken = refreshCsrfToken;

/**
 * POST JSON with the live token, retrying once on a 419 so a tab that went
 * stale recovers by itself instead of showing the teacher a failure.
 */
window.postJson = async (url, body) => {
    const send = () => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': window.csrfToken(),
        },
        body: JSON.stringify(body),
    });

    let response = await send();

    if (response.status === 419 && await refreshCsrfToken()) {
        response = await send();
    }

    // A fresh token but no login behind it means the session really is over.
    // Reloading lands on the sign-in page with this URL remembered.
    if (response.status === 401 || response.status === 419) {
        window.location.reload();
    }

    return response;
};

applyToken(window.csrfToken());

// Refresh often enough that the token never ages out, but only for a screen
// someone is in front of — an abandoned browser still times out on schedule.
const HEARTBEAT_MS = 5 * 60 * 1000;
const IDLE_MS = 20 * 60 * 1000;

let lastActivity = performance.now();

['click', 'keydown', 'pointerdown', 'scroll'].forEach(event => {
    document.addEventListener(event, () => { lastActivity = performance.now(); }, {passive: true});
});

if (tokenMeta()) {
    setInterval(() => {
        if (document.hidden) return;
        if (performance.now() - lastActivity > IDLE_MS) return;

        refreshCsrfToken();
    }, HEARTBEAT_MS);

    // Coming back to a tab left in the background is the classic way to hit
    // "Page Expired" — get a live token before anything is clicked.
    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) refreshCsrfToken();
    });
}
