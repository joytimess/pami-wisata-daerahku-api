<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AppLinkController extends Controller
{
    private const FINGERPRINT = '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/D';

    /** Digital Asset Links statement that lets Android verify the app for HTTPS links on this host. */
    public function __invoke(): JsonResponse
    {
        $package = (string) config('mobile.android.package');
        $fingerprints = array_values(array_unique(array_filter((array) config('mobile.android.sha256_fingerprints'),
            fn (mixed $value): bool => is_string($value) && preg_match(self::FINGERPRINT, $value) === 1)));
        abort_if($package === '' || $fingerprints === [], 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $fingerprints],
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
