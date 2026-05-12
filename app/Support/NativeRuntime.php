<?php

namespace App\Support;

use Illuminate\Http\Request;

class NativeRuntime
{
    public function isNativeRequest(?Request $request = null): bool
    {
        $request ??= request();

        $userAgent = $request->userAgent() ?? '';

        return str_contains($userAgent, 'NativePHP')
            || str_contains($userAgent, 'wv');
    }

    public function isAndroidWebView(?Request $request = null): bool
    {
        $request ??= request();

        $userAgent = $request->userAgent() ?? '';

        return str_contains($userAgent, 'Android')
            && str_contains($userAgent, 'wv');
    }
}
