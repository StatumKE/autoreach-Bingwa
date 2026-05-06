import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const themeColorMeta = document.querySelector('meta[name="theme-color"]');
const lightThemeColor = '#bedbff';
const darkThemeColor = '#02130d';

window.Pusher = Pusher;

function initializeBroadcasting() {
    const runtimeConfig = window.__autoreachBroadcasting;
    const broadcaster = import.meta.env.VITE_AUTOREACH_BROADCAST_BROADCASTER;
    const key = import.meta.env.VITE_AUTOREACH_BROADCAST_KEY;
    const host = import.meta.env.VITE_AUTOREACH_BROADCAST_HOST;

    if (
        ! runtimeConfig
        || typeof runtimeConfig.authEndpoint !== 'string'
        || runtimeConfig.authEndpoint === ''
        || typeof runtimeConfig.deviceToken !== 'string'
        || runtimeConfig.deviceToken === ''
        || typeof broadcaster !== 'string'
        || broadcaster === ''
        || typeof key !== 'string'
        || key === ''
        || typeof host !== 'string'
        || host === ''
    ) {
        return;
    }

    window.Echo = new Echo({
        broadcaster,
        key,
        wsHost: host,
        wsPort: Number(import.meta.env.VITE_AUTOREACH_BROADCAST_WS_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_AUTOREACH_BROADCAST_WSS_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_AUTOREACH_BROADCAST_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: runtimeConfig.authEndpoint,
        auth: {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${runtimeConfig.deviceToken}`,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });
}

function syncThemeColor() {
    if (!themeColorMeta) {
        return;
    }

    themeColorMeta.setAttribute(
        'content',
        document.documentElement.classList.contains('dark') ? darkThemeColor : lightThemeColor,
    );
}

syncThemeColor();
initializeBroadcasting();

document.addEventListener('DOMContentLoaded', syncThemeColor);
window.addEventListener('pageshow', syncThemeColor);

new MutationObserver(syncThemeColor).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
});
