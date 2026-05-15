const confirmationModalId = 'app-confirmation-modal';
const pendingNativeScrollKey = 'bingwa:native-scroll-position';

function normalizedPageUrl(url) {
    try {
        const resolved = new URL(url, window.location.href);

        if (resolved.origin !== window.location.origin) {
            return null;
        }

        return `${resolved.pathname}${resolved.search}`;
    } catch {
        return null;
    }
}

function queuePendingScrollRestore(url) {
    if (!window.sessionStorage) {
        return;
    }

    const normalizedUrl = normalizedPageUrl(url);

    if (!normalizedUrl) {
        return;
    }

    try {
        window.sessionStorage.setItem(
            pendingNativeScrollKey,
            JSON.stringify({
                url: normalizedUrl,
                x: window.scrollX,
                y: window.scrollY,
            }),
        );
    } catch {
        // Ignore storage write failures on constrained web views.
    }
}

function restorePendingScrollPosition() {
    if (!window.sessionStorage) {
        return;
    }

    let pendingState = null;

    try {
        pendingState = window.sessionStorage.getItem(pendingNativeScrollKey);
    } catch {
        return;
    }

    if (!pendingState) {
        return;
    }

    let payload = null;

    try {
        payload = JSON.parse(pendingState);
    } catch {
        window.sessionStorage.removeItem(pendingNativeScrollKey);

        return;
    }

    if (!payload || payload.url !== normalizedPageUrl(window.location.href)) {
        return;
    }

    window.sessionStorage.removeItem(pendingNativeScrollKey);

    if (typeof payload.x !== 'number' || typeof payload.y !== 'number') {
        return;
    }

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            window.scrollTo(payload.x, payload.y);
        });
    });
}

function triggerNativeFeedback() {
    if (!isNativeAndroid()) {
        return;
    }

    const feedbackKind = document.body?.dataset.bingwaNativeFeedbackKind;

    if (!feedbackKind || typeof navigator.vibrate !== 'function') {
        return;
    }

    if (feedbackKind === 'error') {
        navigator.vibrate([18, 40, 18]);

        return;
    }

    navigator.vibrate(12);
}

function buildNativePayload(form, declaredMethod, effectiveMethod) {
    const formData = new FormData(form);

    if (effectiveMethod === declaredMethod) {
        formData.delete('_method');
    }

    return new URLSearchParams(formData);
}

async function syncActiveFieldValue(form) {
    const activeElement = document.activeElement;

    if (!(activeElement instanceof HTMLElement) || !form.contains(activeElement)) {
        return;
    }

    if (typeof activeElement.blur === 'function') {
        activeElement.blur();
    }

    await new Promise((resolve) => {
        requestAnimationFrame(() => resolve());
    });
}

function setPendingState(form, submitButton) {
    if (!(submitButton instanceof HTMLButtonElement)) {
        return;
    }

    if (!submitButton.dataset.originalLabel) {
        submitButton.dataset.originalLabel = submitButton.innerHTML;
    }

    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
    submitButton.textContent = submitButton.dataset.loadingText || 'Working...';

    if (!form.querySelector('[data-submit-status]')) {
        const status = document.createElement('p');
        status.dataset.submitStatus = 'true';
        status.className = 'mt-2 text-xs text-slate-500';
        status.textContent = 'Please wait. We are processing your request.';
        form.appendChild(status);
    }
}

function clearPendingState(form, submitButton) {
    if (!(submitButton instanceof HTMLButtonElement)) {
        return;
    }

    submitButton.disabled = false;
    submitButton.removeAttribute('aria-busy');
    submitButton.classList.remove('opacity-70', 'cursor-not-allowed');

    if (submitButton.dataset.originalLabel) {
        submitButton.innerHTML = submitButton.dataset.originalLabel;
    }

    form.querySelector('[data-submit-status]')?.remove();
}

function getConfirmationElements() {
    let modal = document.getElementById(confirmationModalId);

    if (!modal) {
        modal = document.createElement('div');
        modal.id = confirmationModalId;
        modal.className = 'fixed inset-0 z-[120] hidden items-end justify-center bg-slate-950/50 px-4 pb-4 pt-10 backdrop-blur-sm sm:items-center';
        modal.innerHTML = `
            <div class="w-full max-w-sm overflow-hidden rounded-[28px] bg-white shadow-2xl shadow-slate-950/20">
                <div class="space-y-2 px-5 pt-5">
                    <p id="app-confirmation-title" class="text-lg font-bold tracking-tight text-slate-900">Confirm</p>
                    <p id="app-confirmation-message" class="text-sm leading-relaxed text-slate-500"></p>
                </div>
                <div class="mt-5 flex gap-3 px-5 pb-5">
                    <button id="app-confirmation-cancel" type="button" class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                    <button id="app-confirmation-confirm" type="button" class="flex-1 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Confirm</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
    }

    return {
        modal,
        title: document.getElementById('app-confirmation-title'),
        message: document.getElementById('app-confirmation-message'),
        cancel: document.getElementById('app-confirmation-cancel'),
        confirm: document.getElementById('app-confirmation-confirm'),
    };
}

function applyConfirmationVariant(confirmButton, variant) {
    confirmButton.classList.remove('bg-slate-950', 'hover:bg-slate-800', 'bg-rose-600', 'hover:bg-rose-700');

    if (variant === 'danger') {
        confirmButton.classList.add('bg-rose-600', 'hover:bg-rose-700');

        return;
    }

    confirmButton.classList.add('bg-slate-950', 'hover:bg-slate-800');
}

function showConfirmationDialog(options = {}) {
    const elements = getConfirmationElements();
    const modal = elements.modal;
    const title = elements.title;
    const message = elements.message;
    const cancelButton = elements.cancel;
    const confirmButton = elements.confirm;

    if (!title || !message || !cancelButton || !confirmButton) {
        return Promise.resolve(window.confirm(options.message || 'Are you sure you want to continue?'));
    }

    title.textContent = options.title || 'Confirm';
    message.textContent = options.message || 'Are you sure you want to continue?';
    cancelButton.textContent = options.cancelText || 'Cancel';
    confirmButton.textContent = options.confirmText || 'Confirm';
    applyConfirmationVariant(confirmButton, options.variant || 'default');

    modal.classList.remove('hidden');
    modal.classList.add('flex');

    return new Promise((resolve) => {
        let settled = false;

        const close = (result) => {
            if (settled) {
                return;
            }

            settled = true;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            cancelButton.removeEventListener('click', onCancel);
            confirmButton.removeEventListener('click', onConfirm);
            modal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKeyDown);
            resolve(result);
        };

        const onCancel = () => close(false);
        const onConfirm = () => close(true);
        const onBackdrop = (event) => {
            if (event.target === modal) {
                close(false);
            }
        };
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                close(false);
            }
        };

        cancelButton.addEventListener('click', onCancel);
        confirmButton.addEventListener('click', onConfirm);
        modal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKeyDown);
        confirmButton.focus();
    });
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function isNativeAndroid() {
    const userAgent = navigator.userAgent || '';

    return /Android/i.test(userAgent) && /(wv|NativePHP)/i.test(userAgent);
}

function resolveNativeFormMethods(form) {
    const declaredMethod = (
        form.dataset.nativeMethod
        || form.getAttribute('method')
        || form.method
        || 'GET'
    ).toUpperCase();
    const methodOverrideField = form.querySelector('input[name="_method"]');
    const effectiveMethod = methodOverrideField instanceof HTMLInputElement
        ? methodOverrideField.value.toUpperCase()
        : declaredMethod;

    return { declaredMethod, effectiveMethod };
}

function shouldBridgeNativeFormSubmission(form, effectiveMethod) {
    if (!isNativeAndroid()) {
        return false;
    }

    if (form.enctype === 'multipart/form-data' || form.querySelector('input[type="file"]')) {
        return false;
    }

    if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(effectiveMethod)) {
        return false;
    }

    try {
        const actionUrl = new URL(form.action || window.location.href, window.location.href);

        if (actionUrl.pathname === '/logout') {
            return false;
        }

        return actionUrl.origin === window.location.origin;
    } catch {
        return false;
    }
}

async function submitNativeForm(form, submitButton = null) {
    const { declaredMethod, effectiveMethod } = resolveNativeFormMethods(form);

    if (!shouldBridgeNativeFormSubmission(form, effectiveMethod)) {
        return false;
    }

    await syncActiveFieldValue(form);

    const payload = buildNativePayload(form, declaredMethod, effectiveMethod);

    try {
        const response = await window.fetch(form.action || window.location.href, {
            method: declaredMethod,
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html,application/xhtml+xml',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                'X-Connect-Native-Form': '1',
            },
            body: payload.toString(),
        });

        const html = await response.text();
        const responseUrl = response.url || window.location.href;
        const currentUrl = normalizedPageUrl(window.location.href);
        const nextUrl = normalizedPageUrl(responseUrl);

        if (nextUrl && nextUrl !== currentUrl) {
            window.location.replace(responseUrl);

            return true;
        }

        queuePendingScrollRestore(responseUrl);

        if (responseUrl) {
            try {
                window.history.replaceState(window.history.state, '', responseUrl);
            } catch {
                // Ignore history updates that are blocked by the web view.
            }
        }

        document.open();
        document.write(html);
        document.close();

        return true;
    } catch (error) {
        if (submitButton) {
            clearPendingState(form, submitButton);
        }

        console.error('Native form submission failed', error);

        return false;
    }
}

function installProgrammaticNativeFormBridge() {
    if (typeof HTMLFormElement === 'undefined') {
        return;
    }

    if (HTMLFormElement.prototype.__bingwaNativeSubmitBridgeInstalled === true) {
        return;
    }

    const originalSubmit = HTMLFormElement.prototype.submit;

    HTMLFormElement.prototype.submit = function bingwaNativeSubmitBridge() {
        if (!(this instanceof HTMLFormElement)) {
            return originalSubmit.call(this);
        }

        const { effectiveMethod } = resolveNativeFormMethods(this);

        if (!shouldBridgeNativeFormSubmission(this, effectiveMethod)) {
            return originalSubmit.call(this);
        }

        void submitNativeForm(this);
    };

    Object.defineProperty(HTMLFormElement.prototype, '__bingwaNativeSubmitBridgeInstalled', {
        configurable: true,
        enumerable: false,
        value: true,
        writable: false,
    });
}

function initializeNativePageBehaviors() {
    installProgrammaticNativeFormBridge();
    restorePendingScrollPosition();
    triggerNativeFeedback();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeNativePageBehaviors, { once: true });
} else {
    initializeNativePageBehaviors();
}

document.addEventListener(
    'submit',
    async (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitButton = event.submitter instanceof HTMLButtonElement ? event.submitter : null;

        if (form.dataset.confirmMessage && form.dataset.confirmed !== 'true') {
            event.preventDefault();

            const confirmed = await showConfirmationDialog({
                title: form.dataset.confirmTitle || 'Confirm',
                message: form.dataset.confirmMessage,
                confirmText: form.dataset.confirmButton || 'Confirm',
                cancelText: form.dataset.confirmCancel || 'Cancel',
                variant: form.dataset.confirmVariant || 'default',
            });

            if (!confirmed) {
                if (submitButton) {
                    clearPendingState(form, submitButton);
                }

                return;
            }

            form.dataset.confirmed = 'true';

            if (typeof form.requestSubmit === 'function') {
                if (submitButton) {
                    form.requestSubmit(submitButton);
                } else {
                    form.requestSubmit();
                }
            } else {
                form.submit();
            }

            return;
        }

        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
        }

        if (submitButton) {
            setPendingState(form, submitButton);
        }

        const { effectiveMethod } = resolveNativeFormMethods(form);

        if (!shouldBridgeNativeFormSubmission(form, effectiveMethod)) {
            return;
        }

        event.preventDefault();
        await submitNativeForm(form, submitButton);
    },
    true,
);
