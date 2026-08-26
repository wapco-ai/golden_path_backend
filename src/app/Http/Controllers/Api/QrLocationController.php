<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QrLocationController extends Controller
{
    public function show(Request $request, string $code)
    {
        $language        = $request->query('language', 'fa');
        $includeContents = filter_var($request->query('includeContents', 'true'), FILTER_VALIDATE_BOOLEAN);
        $includeComments = filter_var($request->query('includeComments', 'false'), FILTER_VALIDATE_BOOLEAN);

        // زبان‌های فعال
        $langs = DB::table('languages')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('code')
            ->toArray();

        if (!in_array($language, $langs, true)) {
            $language = 'fa';
        }

        // پیدا کردن QR
        $qr = DB::table('qrcodes')
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (!$qr) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $attrs = $this->decodeJson($qr->attrs);

        // نقشه‌ی چندزبانه از i18n_texts
        $titleMap     = $this->buildI18nMap('qrcodes', $qr->id, 'name', $langs);
        $aboutFullMap = $this->buildI18nMap('qrcodes', $qr->id, 'desc', $langs);

        // تک‌زبانه کردن:
        $title     = $this->resolveLang($titleMap, $language);
        $aboutFull = $this->resolveLang($aboutFullMap, $language);

        // about.short از attrs یا از full کوتاه‌شده
        $aboutShortMap = $attrs['about']['short'] ?? [];
        $aboutShort    = $this->resolveLangWithFallbackShort($aboutShortMap, $aboutFull, $language);

        // location / openingHours / images
        $locationMap  = $attrs['location']     ?? [];
        $openingMap   = $attrs['openingHours'] ?? [];
        $location     = $this->resolveLang($locationMap, $language);
        $openingHours = $this->resolveLang($openingMap, $language);

        $images = $attrs['images'] ?? [];

        // contents: هر content خودش title/description چندزبانه دارد
        $contents = [];
        if ($includeContents && isset($attrs['contents']) && is_array($attrs['contents'])) {
            foreach ($attrs['contents'] as $item) {
                $contents[] = [
                    'id'          => $item['id']   ?? null,
                    'type'        => $item['type'] ?? null,
                    'title'       => isset($item['title'])       ? $this->resolveLang($item['title'], $language) : null,
                    'description' => isset($item['description']) ? $this->resolveLang($item['description'], $language) : null,
                    'fileKey'     => $item['fileKey']   ?? null,
                    'thumbnail'   => $item['thumbnail'] ?? null,
                ];
            }
        }

        // comments: معمولاً تک‌زبانه هستند
        $comments = $includeComments
            ? ($attrs['comments'] ?? [])
            : [];

        $views         = $attrs['views']         ?? 0;
        $averageRating = $attrs['averageRating'] ?? null;

        $response = [
            'id'   => $qr->code,
            'lang' => $language,

            'title'        => $title,
            'location'     => $location,
            'images'       => $images,
            'openingHours' => $openingHours,

            'about' => [
                'short' => $aboutShort,
                'full'  => $aboutFull,
            ],

            'contents'       => $contents,
            'comments'       => $comments,
            'views'          => $views,
            'averageRating'  => $averageRating,
        ];

        return response()->json($response);
    }

    protected function buildI18nMap(string $entityTable, int $entityId, string $field, array $langs): array
    {
        $rows = DB::table('i18n_texts')
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->where('field', $field)
            ->whereIn('lang', $langs)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->lang] = $row->txt;
        }
        return $map;
    }

    protected function resolveLang(array $map, string $language): ?string
    {
        if (isset($map[$language]) && $map[$language] !== null && $map[$language] !== '') {
            return $map[$language];
        }
        // fallback: اولین مقدار غیر خالی
        foreach ($map as $txt) {
            if ($txt !== null && $txt !== '') {
                return $txt;
            }
        }
        return null;
    }

    protected function resolveLangWithFallbackShort(array $shortMap, ?string $full, string $language): ?string
    {
        $short = $this->resolveLang($shortMap, $language);
        if ($short !== null && $short !== '') {
            return $short;
        }
        if ($full) {
            return mb_substr($full, 0, 140) . '…';
        }
        return null;
    }

    protected function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
