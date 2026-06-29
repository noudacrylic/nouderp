<?php

namespace App\Http\Middleware;

use App\Models\StorefrontSetting;
use Closure;
use Illuminate\Http\Request;

/**
 * Gerbang endpoint baca etalase (/api/storefront/*). Memeriksa kunci rahasia di
 * header `Authorization: Bearer <key>` atau `X-Api-Key` terhadap StorefrontSetting.
 * Hanya VPS/etalase (server-side) yang memegang kunci — frontend publik tidak.
 */
class StorefrontApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $setting = StorefrontSetting::current();

        if (!$setting || !$setting->isConfigured()) {
            return response()->json(['message' => 'Storefront API belum diaktifkan.'], 503);
        }

        $bearer = $request->bearerToken();
        $provided = $bearer ?: $request->header('X-Api-Key');

        if (!$provided || !hash_equals($setting->api_key, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
