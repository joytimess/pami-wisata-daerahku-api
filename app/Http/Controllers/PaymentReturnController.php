<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentReturnController extends Controller
{
    private const HINTS = ['success', 'cancel', 'error'];

    /**
     * Landing page for customers returning from an e-wallet. It is navigation only: it reads no payment state,
     * writes nothing and calls no provider. Payment is settled solely by the authenticated DOKU status inquiry,
     * so nothing here (including the optional `result` hint) may be treated as proof of payment.
     */
    public function __invoke(Request $request): Response
    {
        $hint = $request->query('result');
        $hint = is_string($hint) && in_array($hint, self::HINTS, true) ? $hint : null;

        return response()->view('payment-return', ['hint' => $hint, 'appUrl' => $this->appUrl($hint)])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Explicit-package Android intent for the same HTTPS URL; works even before App Links verification completes. */
    private function appUrl(?string $hint): string
    {
        $host = parse_url((string) config('doku.ewallet.return_url'), PHP_URL_HOST) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $package = (string) config('mobile.android.package');
        if (! is_string($host) || $host === '' || $package === '') {
            return '';
        }

        return 'intent://'.$host.route('payment.return', [], false).($hint ? '?result='.$hint : '')
            .'#Intent;scheme=https;package='.rawurlencode($package).';end';
    }
}
