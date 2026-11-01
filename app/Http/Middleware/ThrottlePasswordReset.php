<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ThrottlePasswordReset
{
    public function handle(Request $request, Closure $next): mixed
    {
        $email = is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : '';
        $timestamp = now()->timestamp;
        $limits = [
            ['key' => hash('sha256', 'ip|'.$request->ip()), 'window' => intdiv($timestamp, 60), 'max' => 10, 'duration' => 60],
            ['key' => hash('sha256', 'email|'.$email), 'window' => intdiv($timestamp, 3600), 'max' => 20, 'duration' => 3600],
        ];
        usort($limits, fn ($a, $b) => strcmp($a['key'], $b['key']));
        if (random_int(1, 100) === 1) {
            DB::table('password_reset_rate_limits')->where('expires_at', '<', $timestamp - 60)->delete();
        }
        $allowed = DB::transaction(function () use ($limits) {
            foreach ($limits as $limit) {
                DB::table('password_reset_rate_limits')->upsert(
                    ['key' => $limit['key'], 'window' => $limit['window'], 'attempts' => 0, 'expires_at' => ($limit['window'] + 1) * $limit['duration']],
                    ['key'],
                    ['key'],
                );
                $row = DB::table('password_reset_rate_limits')->where('key', $limit['key'])->lockForUpdate()->first();
                if ((int) $row->window === $limit['window'] && $row->attempts >= $limit['max']) {
                    return false;
                }
                DB::table('password_reset_rate_limits')->where('key', $limit['key'])->update([
                    'window' => $limit['window'],
                    'expires_at' => ($limit['window'] + 1) * $limit['duration'],
                    'attempts' => (int) $row->window === $limit['window'] ? $row->attempts + 1 : 1,
                ]);
            }

            return true;
        }, 3);
        if (! $allowed) {
            throw new TooManyRequestsHttpException;
        }

        return $next($request);
    }
}
