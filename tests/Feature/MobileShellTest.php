<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

test('guest users are redirected to the login screen from the mobile home route', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('authenticated users are redirected to the dashboard from the mobile home route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('the native php android shell is configured for light status bar icons', function () {
    expect(config('nativephp.android.status_bar_style'))->toBe('light');
});

test('the native php android shell uses persistent runtime mode', function () {
    expect(config('nativephp.runtime.mode'))->toBe('persistent');
});

test('the native php android shell runs migrations during filesystem prep', function () {
    $environmentSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'));

    expect($environmentSource)
        ->toContain('phpBridge.runArtisanCommand("migrate --force")');
});

test('the android shell starts at the home router instead of login directly', function () {
    $env = File::get(base_path('.env'));

    expect($env)->toContain('NATIVEPHP_START_URL=/');
});

test('the android shell keeps the splash visible until the first committed web frame', function () {
    $mainActivitySource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'));
    $webViewManagerSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'));

    expect($mainActivitySource)
        ->toContain('fun hideSplash(reason: String)')
        ->toContain('hideSplash("Laravel environment initialization failed")');

    expect($webViewManagerSource)
        ->toContain('cacheMode = WebSettings.LOAD_DEFAULT')
        ->toContain('onPageCommitVisible')
        ->toContain('startupMainFrameStatusCode')
        ->toContain('hideSplash("main frame committed: $url")')
        ->not->toContain('LOAD_CACHE_ELSE_NETWORK');
});

test('the android csrf helper only sends the cookie-backed xsrf header', function () {
    $securitySource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/security/LaravelSecurity.kt'));
    $webViewManagerSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'));

    expect($securitySource)
        ->not->toContain('headers["X-CSRF-TOKEN"] = it')
        ->toContain('headers["X-XSRF-TOKEN"] = Uri.decode(xsrfCookie)');

    expect($webViewManagerSource)
        ->not->toContain('LaravelSecurity.set(token)');
});

test('the native action coordinator uses the main thread and allows state loss when needed', function () {
    $coordinatorSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt'));

    expect($coordinatorSource)
        ->toContain('Handler(Looper.getMainLooper()).post')
        ->toContain('commitNowAllowingStateLoss()')
        ->toContain('fragmentManager.isStateSaved');
});

test('the native php hotfix script preserves the coordinator crash fix', function () {
    $hotfixScript = File::get(base_path('scripts/apply-nativephp-hotfixes.php'));

    expect($hotfixScript)
        ->toContain('patch_mobile_action_coordinator')
        ->toContain('patch_mobile_security_csrf_header')
        ->toContain('patch_mobile_webview_csrf_bridge')
        ->toContain('commitNowAllowingStateLoss()')
        ->toContain('Looper.myLooper() != Looper.getMainLooper()');
});

test('the native form bridge uses a real navigation for redirected responses', function () {
    $nativeFormsScript = File::get(base_path('resources/js/native-forms.js'));

    expect($nativeFormsScript)
        ->toContain('window.location.replace(responseUrl)')
        ->toContain('queuePendingScrollRestore(responseUrl)')
        ->toContain('document.write(html)');
});

test('the native form bridge leaves logout forms on the standard webview submit path', function () {
    $nativeFormsScript = File::get(base_path('resources/js/native-forms.js'));

    expect($nativeFormsScript)
        ->toContain("actionUrl.pathname === '/logout'")
        ->toContain('return false;');
});

test('the dashboard mobile navigation uses a plain alpine drawer instead of flux mobile sidebar controls', function () {
    $sidebarLayout = File::get(base_path('resources/views/layouts/app/sidebar.blade.php'));
    $appJs = File::get(base_path('resources/js/app.js'));

    expect($sidebarLayout)
        ->toContain("@persist('mobile-nav')")
        ->toContain('x-data="{ open: false }"')
        ->toContain('id="mobile-navigation-drawer"')
        ->toContain('Open navigation menu')
        ->toContain('Close navigation menu')
        ->not->toContain('flux:sidebar.toggle')
        ->not->toContain('data-flux-sidebar-toggle')
        ->not->toContain('data-flux-sidebar-collapsed-mobile');

    expect($appJs)
        ->not->toContain('data-flux-sidebar-toggle')
        ->not->toContain('data-flux-sidebar-backdrop')
        ->not->toContain('data-flux-sidebar-collapsed-mobile')
        ->not->toContain('window.__bingwaMobileSidebarFallbackInstalled');
});
