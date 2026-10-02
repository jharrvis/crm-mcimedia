<?php

namespace App\Domains\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi akses route berdasarkan hak akses modul pada role user (F4-1).
 *
 * Pemakaian: ->middleware('permission:clients') pada route/group modul.
 *
 * Action diturunkan otomatis dari request:
 *  - GET biasa (index/show)          → "view"
 *  - GET create/edit                 → "manage"
 *  - POST/PUT/PATCH/DELETE           → "manage"
 *
 * User tanpa role (akun warisan) dianggap akses penuh; lihat User::isAdmin().
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        // Route publik / tanpa auth diteruskan; middleware 'auth' yang menangani.
        if ($user === null) {
            return $next($request);
        }

        if (! $user->hasPermission($module, $this->resolveAction($request))) {
            abort(403, 'Anda tidak memiliki akses ke modul ini.');
        }

        return $next($request);
    }

    protected function resolveAction(Request $request): string
    {
        if (! $request->isMethod('GET')) {
            return 'manage';
        }

        $name = (string) ($request->route()?->getName() ?? '');

        return str_ends_with($name, '.create') || str_ends_with($name, '.edit')
            ? 'manage'
            : 'view';
    }
}
