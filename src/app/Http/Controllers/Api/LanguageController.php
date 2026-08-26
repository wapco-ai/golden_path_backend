<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LanguageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $languages = Language::orderBy('id')->get();

        $default = $languages->firstWhere('is_default', true);
        $latestUpdate = $languages->max('updated_at');

        return response()->json([
            'data' => $languages->map(fn ($language) => [
                'id' => $language->id,
                'name' => $language->name,
                'english_name' => $language->english_name,
                'locale' => $language->locale,
                'code' => $language->code,
                'direction' => $language->direction,
                'is_default' => (bool) $language->is_default,
                'is_active' => (bool) $language->is_active,
                'flag_icon_url' => $language->flag_icon_url,
            ]),
            'meta' => [
                'defaultLanguageId' => $default?->id,
                'updatedAt' => optional($latestUpdate)->toISOString(),
            ],
        ]);
    }
}
