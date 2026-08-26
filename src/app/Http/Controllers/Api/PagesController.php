<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageFaq;
use App\Services\I18nTextService;
use App\Services\PageI18nService;
use Illuminate\Http\Request;

class PagesController extends Controller
{
    public function __construct(
        private readonly I18nTextService $i18n,
        private readonly PageI18nService $pageI18n,
    ) {
    }

    private function errorResponse(string $error, string $message, int $status = 400)
    {
        return response()->json([
            'error'   => $error,
            'message' => $message,
        ], $status);
    }

    /**
     * GET /api/v1/pages/{type}?lang=fa|en|ar|ur
     * type: support|rules|about|contact
     */
    public function show(Request $request, string $type)
    {
        $lang = (string)($request->query('lang', 'fa'));

        $page = Page::query()->where('type', $type)->first();
        if (!$page) {
            return $this->errorResponse('NotFound', 'Page not found.', 404);
        }

        $descMap = array_merge(
            ['fa' => $page->description ?? ''],
            $this->i18n->getLangMap('pages', (int)$page->id, 'description')
        );

        $out = [
            'type'  => $page->type,
            'title' => $page->title,
            'description' => $this->pageI18n->pickByLang($descMap, $lang),
            'phones' => array_values($page->phones ?? []),
            'emails' => array_values($page->emails ?? []),
        ];

        if ($page->type === 'contact') {
            $addrMap = array_merge(
                ['fa' => $page->address ?? ''],
                $this->i18n->getLangMap('pages', (int)$page->id, 'address')
            );
            $out['address'] = $this->pageI18n->pickByLang($addrMap, $lang);
        }

        return response()->json($out);
    }

    /**
     * GET /api/v1/pages/faq?lang=fa|en|ar|ur
     */
    public function faq(Request $request)
    {
        $lang = (string)($request->query('lang', 'fa'));

        $items = PageFaq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (PageFaq $f) use ($lang) {
                $qMap = array_merge(
                    ['fa' => $f->question],
                    $this->i18n->getLangMap('page_faqs', (int)$f->id, 'question')
                );
                $aMap = array_merge(
                    ['fa' => $f->answer],
                    $this->i18n->getLangMap('page_faqs', (int)$f->id, 'answer')
                );

                return [
                    'id' => (int)$f->id,
                    'question' => $this->pageI18n->pickByLang($qMap, $lang),
                    'answer'   => $this->pageI18n->pickByLang($aMap, $lang),
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }
}
