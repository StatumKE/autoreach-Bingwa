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

test('the native php android shell uses classic request runtime mode', function () {
    expect(config('nativephp.runtime.mode'))->toBe('classic');
});

test('the native php android shell keeps database queue dispatching in app config', function () {
    $queueConfig = File::get(base_path('config/queue.php'));

    expect($queueConfig)
        ->toContain("env('QUEUE_CONNECTION', 'database')");
});

test('the native php android shell keeps database queue dispatching in env', function () {
    $env = File::get(base_path('.env'));

    expect($env)
        ->toContain('QUEUE_CONNECTION=database');
});

test('the native php android release env is production ready and compact', function () {
    $env = File::get(base_path('.env'));

    expect($env)
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('NATIVEPHP_ANDROID_MINIFY_ENABLED=true')
        ->toContain('NATIVEPHP_ANDROID_SHRINK_RESOURCES=true')
        ->toContain('NATIVEPHP_ANDROID_DEBUG_SYMBOLS=NONE');
});

test('the native php android bundle excludes the source sqlite database', function () {
    expect(config('nativephp.cleanup_exclude_files'))
        ->toContain('database/database.sqlite')
        ->toContain('database/database.sqlite-shm')
        ->toContain('database/database.sqlite-wal');
});

test('the native php android shell does not require the unused native contacts package', function () {
    $composerJson = File::get(base_path('composer.json'));
    $pluginRegistration = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt'));
    $phpstanConfig = File::get(base_path('phpstan.neon'));

    expect($composerJson)
        ->not->toContain('"statum/native-contacts": "1.0.0"')
        ->not->toContain('"Statum\\\\NativeContacts\\\\": "packages/statum/native-contacts/src/"')
        ->not->toContain('"url": "packages/statum/native-contacts"');

    expect($pluginRegistration)
        ->not->toContain('ContactsFunctions')
        ->not->toContain('Contacts.CheckPermission')
        ->not->toContain('Contacts.RequestPermission')
        ->not->toContain('Contacts.Search');

    expect($phpstanConfig)
        ->not->toContain('packages/statum/native-contacts/src');
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

test('the android queue worker initializes the background environment before using the ephemeral runtime', function () {
    $queueWorkerSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt'));
    $schedulerWorkerSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/background/PHPSchedulerWorker.kt'));
    $pushNotificationSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/firebase/PushNotificationService.kt'));

    expect($queueWorkerSource)
        ->toContain('initializeForBackground()')
        ->toContain('registerContextOnlyBridgeFunctions(context)')
        ->toContain('synchronized(PHPBridge.phpLock)')
        ->toContain('nativeEphemeralBoot')
        ->toContain('nativeEphemeralArtisan')
        ->toContain('queue:work --once --timeout=360')
        ->toContain('nativeEphemeralShutdown')
        ->toContain('IDLE_POLL_TIMEOUT_MS')
        ->toContain('AFTER_JOB_COOLDOWN_MS')
        ->toContain('wakeSignal')
        ->toContain('wakeSignal.poll')
        ->not->toContain('nativeWorkerBoot')
        ->not->toContain('nativeWorkerArtisan')
        ->not->toContain('nativeWorkerShutdown');

    expect($schedulerWorkerSource)
        ->toContain('PHPQueueService::class.java')
        ->toContain('startForegroundService')
        ->not->toContain('ReentrantLock')
        ->not->toContain('ephemeralLock.lock()');

    expect($pushNotificationSource)
        ->toContain('initializeForBackground()')
        ->toContain('synchronized(PHPBridge.phpLock)')
        ->toContain('nativeEphemeralBoot')
        ->toContain('nativeEphemeralShutdown');
});

test('the android shell keeps the queue worker available while the app is foregrounded', function () {
    $mainActivitySource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'));

    expect($mainActivitySource)
        ->toContain('queueWorker = PHPQueueWorker(applicationContext).also { it.start() }')
        ->not->toContain('syncQueueWorkerState()')
        ->not->toContain('override fun onStart()')
        ->not->toContain('override fun onStop()')
        ->not->toContain('Queue worker starting because the app moved to the background')
        ->not->toContain('Queue worker stopping because the app is in the foreground')
        ->toContain('queueWorker = PHPQueueWorker(applicationContext).also { it.start() }');
});

test('the mobile list pages render immediately without wire:init polling', function () {
    $pageFiles = [
        base_path('resources/views/components/⚡offers.blade.php'),
        base_path('resources/views/components/⚡transactions.blade.php'),
        base_path('resources/views/components/⚡auto-replies.blade.php'),
        base_path('resources/views/components/⚡sms.blade.php'),
        base_path('resources/views/components/⚡quick-dials.blade.php'),
        base_path('resources/views/components/⚡auto-renewals.blade.php'),
    ];

    foreach ($pageFiles as $pageFile) {
        $pageSource = File::get($pageFile);

        expect($pageSource)
            ->toContain('public bool $loaded = true;')
            ->not->toContain('wire:init="loadPage"')
            ->not->toContain('wire:init="loadTransactions"');
    }
});

test('the android native ephemeral runtime cannot be reused across background threads', function () {
    $bridgeSource = File::get(base_path('nativephp/android/app/src/main/cpp/php_bridge.c'));

    expect($bridgeSource)
        ->toContain('pthread_mutex_lock(&g_php_request_mutex);')
        ->toContain('pthread_mutex_unlock(&g_php_request_mutex);')
        ->toContain('native_ephemeral_boot')
        ->toContain('native_ephemeral_shutdown');
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
        ->toContain('patch_mobile_background_tasks_scheduler_lock')
        ->toContain('patch_mobile_firebase_ephemeral_lock')
        ->toContain('patch_mobile_native_mutexes')
        ->toContain('patch_mobile_security_csrf_header')
        ->toContain('patch_mobile_webview_csrf_bridge')
        ->toContain('commitNowAllowingStateLoss()')
        ->toContain('Looper.myLooper() != Looper.getMainLooper()');
});

test('the native form bridge uses a real navigation for redirected responses', function () {
    $nativeFormsScript = File::get(base_path('resources/js/native-forms.js'));

    expect($nativeFormsScript)
        ->toContain('const formData = new FormData(form)')
        ->toContain('window.history.pushState(null, \'\', responseUrl)')
        ->toContain('window.history.replaceState(window.history.state, \'\', responseUrl)')
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
        ->toContain('Device settings')
        ->not->toContain('<x-app-logo')
        ->not->toContain('app-logo-icon')
        ->not->toContain('flux:sidebar.toggle')
        ->not->toContain('data-flux-sidebar-toggle')
        ->not->toContain('data-flux-sidebar-collapsed-mobile');

    expect($appJs)
        ->not->toContain('data-flux-sidebar-toggle')
        ->not->toContain('data-flux-sidebar-backdrop')
        ->not->toContain('data-flux-sidebar-collapsed-mobile')
        ->not->toContain('window.__bingwaMobileSidebarFallbackInstalled');
});

test('the setup screen uses inline alpine state and visible permission button labels', function () {
    $setupComponent = File::get(base_path('resources/views/components/⚡setup.blade.php'));

    expect($setupComponent)
        ->toContain('x-data="{')
        ->toContain("nativeCall('RequestSetupPermissions', { force: true })")
        ->toContain('Grant Access')
        ->toContain('Restricted settings')
        ->toContain('Open App Info')
        ->toContain('Open Accessibility')
        ->not->toContain('x-data="bingwaSetup()"')
        ->not->toContain('function bingwaSetup()')
        ->not->toContain('RequestRuntimePermissions')
        ->not->toContain("x-text=\"requesting ? 'Requesting");
});
