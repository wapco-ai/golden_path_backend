<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\PageI18nService;
use Illuminate\Http\Request;

class PagesAdminController extends Controller
{
    public function __construct(private readonly PageI18nService $pageI18n)
    {
    }

    private function errorResponse(string $error, string $message, int $status = 400)
    {
        return response()->json([
            'error'   => $error,
            'message' => $message,
        ], $status);
    }

    /**
     * GET /api/v1/admin/pages/{type}
     * type: support|rules|about|contact
     */
    public function show(Request $request, string $type)
    {
        $page = Page::query()->where('type', $type)->first();
        if (!$page) {
            return $this->errorResponse('NotFound', 'Page not found.', 404);
        }

        $out = [
            'type'  => $page->type,
            'title' => $page->title,
            'phones' => array_values($page->phones ?? []),
            'emails' => array_values($page->emails ?? []),
            'description' => $page->description ?? '',
            'translations' => $this->pageI18n->pageDescriptionTranslations($page),
        ];

        if ($page->type === 'contact') {
            $out['address'] = $page->address ?? '';
            $out['addressTranslations'] = $this->pageI18n->pageAddressTranslations($page);
        }

        return response()->json($out);
    }

    /**
     * PUT /api/v1/admin/pages/{type}
     * type: support|rules|about|contact
     */
    public function update(Request $request, string $type)
    {
        $page = Page::query()->where('type', $type)->first();
        if (!$page) {
            return $this->errorResponse('NotFound', 'Page not found.', 404);
        }

        $data = $request->validate([
            // shared fields
            'description'        => 'nullable|string',
            'englishDescription' => 'nullable|string',
            'arabicDescription'  => 'nullable|string',
            'urduDescription'    => 'nullable|string',

            // lists (support/contact)
            'phones'   => 'nullable|array|max:3',
            'phones.*' => 'nullable|string|max:100',
            'emails'   => 'nullable|array|max:3',
            'emails.*' => 'nullable|email:rfc,dns|max:200',

            // address (contact only)
            'address'        => 'nullable|string',
            'englishAddress' => 'nullable|string',
            'arabicAddress'  => 'nullable|string',
            'urduAddress'    => 'nullable|string',
        ]);

        // normalize arrays to max 3 and trim
        $phones = isset($data['phones']) ? array_slice(array_values(array_filter($data['phones'], fn($v) => trim((string)$v) !== '')), 0, 3) : null;
        $emails = isset($data['emails']) ? array_slice(array_values(array_filter($data['emails'], fn($v) => trim((string)$v) !== '')), 0, 3) : null;

        if ($phones !== null) {
            $page->phones = array_map(fn($v) => trim((string)$v), $phones);
        }
        if ($emails !== null) {
            $page->emails = array_map(fn($v) => trim((string)$v), $emails);
        }

        if (array_key_exists('description', $data)) {
            $page->description = $data['description'] ?? '';
        }

        if ($page->type === 'contact' && array_key_exists('address', $data)) {
            $page->address = $data['address'] ?? '';
        }

        $page->save();

        // i18n upserts
        $this->pageI18n->upsertPageDescription($page, $data);
        if ($page->type === 'contact') {
            $this->pageI18n->upsertPageAddress($page, $data);
        }

        return $this->show($request, $type);
    }
}
