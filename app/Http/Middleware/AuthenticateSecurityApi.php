<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi token untuk API monitoring keamanan (F3-3).
 *
 * Token dibaca dari environment (SECURITY_API_TOKEN) dan dibandingkan dengan
 * hash_equals() agar tidak rentan timing attack. Tidak ada jalur tanpa token:
 * bila token kosong/API dinonaktifkan → 503; token salah/tidak ada → 401.
 *
 * Catatan keamanan: API ini TIDAK menerima kredensial server dalam bentuk
 * apa pun — payload hanya metadata temuan (lihat SecurityEventController).
 */
class AuthenticateSecurityApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('crm.security.api_enabled', true)) {
            return response()->json(['message' => 'API keamanan dinonaktifkan.'], 503);
        }

        $expected = (string) config('crm.security.api_token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'API keamanan belum dikonfigurasi (SECURITY_API_TOKEN kosong).',
            ], 503);
        }

        $provided = $request->bearerToken() ?: $request->header('X-Api-Token');

        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Token API tidak valid.'], 401);
        }

        return $next($request);
    }
}
