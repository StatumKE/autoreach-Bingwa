import './bootstrap';
import './native-forms';

const themeColorMeta = document.querySelector('meta[name="theme-color"]');
const lightThemeColor = '#bedbff';
const darkThemeColor = '#02130d';
const mobileSidebarBreakpoint = window.matchMedia('(max-width: 1023px)');
const mobileSidebarSelector = 'ui-sidebar[data-flux-sidebar][collapsible="mobile"]';
const mobileSidebarToggleSelector = '[data-flux-sidebar-toggle]';
const mobileSidebarBackdropSelector = '[data-flux-sidebar-backdrop]';

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

document.addEventListener('DOMContentLoaded', syncThemeColor);
window.addEventListener('pageshow', syncThemeColor);

new MutationObserver(syncThemeColor).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
});

function getMobileSidebar() {
    return document.querySelector(mobileSidebarSelector);
}

function setMobileSidebarCollapsed(collapsed) {
    const sidebar = getMobileSidebar();

    if (!sidebar) {
        return false;
    }

    if (collapsed) {
        sidebar.setAttribute('data-flux-sidebar-collapsed-mobile', '');
    } else {
        sidebar.removeAttribute('data-flux-sidebar-collapsed-mobile');
    }

    return true;
}

function toggleMobileSidebar(forceCollapsed = null) {
    if (!mobileSidebarBreakpoint.matches) {
        return false;
    }

    const sidebar = getMobileSidebar();

    if (!sidebar) {
        return false;
    }

    const isCollapsed = sidebar.hasAttribute('data-flux-sidebar-collapsed-mobile');
    const shouldCollapse = forceCollapsed === null ? !isCollapsed : forceCollapsed;

    if (shouldCollapse === isCollapsed) {
        return true;
    }

    return setMobileSidebarCollapsed(shouldCollapse);
}

function installMobileSidebarFallback() {
    if (window.__bingwaMobileSidebarFallbackInstalled === true) {
        return;
    }

    window.__bingwaMobileSidebarFallbackInstalled = true;

    document.addEventListener('click', (event) => {
        if (!mobileSidebarBreakpoint.matches) {
            return;
        }

        const toggle = event.target instanceof Element
            ? event.target.closest(mobileSidebarToggleSelector)
            : null;

        if (toggle) {
            event.preventDefault();
            event.stopImmediatePropagation();
            toggleMobileSidebar();

            return;
        }

        const backdrop = event.target instanceof Element
            ? event.target.closest(mobileSidebarBackdropSelector)
            : null;

        if (backdrop) {
            event.preventDefault();
            event.stopImmediatePropagation();
            toggleMobileSidebar(true);
        }
    }, true);
}

installMobileSidebarFallback();
