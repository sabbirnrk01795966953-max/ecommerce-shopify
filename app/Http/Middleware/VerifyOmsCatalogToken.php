<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyOmsCatalogToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->secure()) {
            return response()->json([
                'success' => false,
                'message' => 'HTTPS is required.',
            ], 403);
        }

        $expected = trim((string) config('oms_catalog.token', ''));
        $provided = trim((string) $request->header('X-OMS-Token', ''));

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
