const NATIVE_ENDPOINT = '/_native/api/call';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export async function callNative(method, params = {}) {
    const response = await fetch(NATIVE_ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            method,
            params,
        }),
    });

    const payload = await response.json();

    return {
        ok: response.ok,
        status: payload.status ?? null,
        payload,
        data: payload.data?.data ?? payload.data ?? {},
    };
}

if (! window.AutoreachNative) {
    window.AutoreachNative = {
        call: callNative,
    };
}
