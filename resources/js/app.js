import './bootstrap';
import './native-forms';

const themeColorMeta = document.querySelector('meta[name="theme-color"]');
const lightThemeColor = '#bedbff';
const darkThemeColor = '#02130d';

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
