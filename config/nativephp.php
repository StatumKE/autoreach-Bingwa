<?php

return [
    'app_version' => env('NATIVEPHP_APP_VERSION', 'DEBUG'),
    'app_version_code' => env('NATIVEPHP_APP_VERSION_CODE', 1),

    'android' => [
        'status_bar_style' => env('NATIVEPHP_ANDROID_STATUS_BAR_STYLE', 'light'),
        'min_sdk' => env('NATIVEPHP_ANDROID_MIN_SDK', 33),
    ],
];
