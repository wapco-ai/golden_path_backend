<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageFaq;
use App\Services\PageI18nService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FaqAdminController extends Controller
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
     * GET /api/v1/admin/pages/faq
     */
    public function index(Request $request)
    {
        $items = PageFaq::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (PageFaq $f) {
                return array_merge([
                    'id' => (int)$f->id,
                    'question' => $f->question,
                    'answer' => $f->answer,
                ], $this->pageI18n->faqTranslations($f));
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v1/admin/pages/faq
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'question' => 'required|string',
            'answer'   => 'required|string',

            'englishQuestion' => 'nullable|string',
            'arabicQuestion'  => 'nullable|string',
            'urduQuestion'    => 'nullable|string',
            'englishAnswer'   => 'nullable|string',
            'arabicAnswer'    => 'nullable|string',
            'urduAnswer'      => 'nullable|string',

            'sortOrder' => 'nullable|integer|min:0|max:100000',
            'isActive'  => 'nullable|boolean',
        ]);

        $faq = new PageFaq();
        $faq->question = $data['question'];
        $faq->answer   = $data['answer'];
        $faq->sort_order = $data['sortOrder'] ?? 0;
        $faq->is_active  = $data['isActive'] ?? true;
        $faq->save();

        $this->pageI18n->upsertFaqTranslations($faq, $data);

        return response()->json(array_merge([
            'id' => (int)$faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
        ], $this->pageI18n->faqTranslations($faq)));
    }

    /**
     * PUT /api/v1/admin/pages/faq/{id}
     */
    public function update(Request $request, int $id)
    {
        $faq = PageFaq::query()->find($id);
        if (!$faq) {
            return $this->errorResponse('NotFound', 'FAQ not found.', 404);
        }

        $data = $request->validate([
            'question' => 'nullable|string',
            'answer'   => 'nullable|string',

            'englishQuestion' => 'nullable|string',
            'arabicQuestion'  => 'nullable|string',
            'urduQuestion'    => 'nullable|string',
            'englishAnswer'   => 'nullable|string',
            'arabicAnswer'    => 'nullable|string',
            'urduAnswer'      => 'nullable|string',

            'sortOrder' => 'nullable|integer|min:0|max:100000',
            'isActive'  => 'nullable|boolean',
        ]);

        if (array_key_exists('question', $data)) $faq->question = $data['question'] ?? '';
        if (array_key_exists('answer', $data))   $faq->answer   = $data['answer'] ?? '';
        if (array_key_exists('sortOrder', $data)) $faq->sort_order = $data['sortOrder'] ?? 0;
        if (array_key_exists('isActive', $data))  $faq->is_active  = (bool)$data['isActive'];

        $faq->save();

        // i18n upserts (also writes fa based on updated question/answer)
        $this->pageI18n->upsertFaqTranslations($faq, array_merge([
            'question' => $faq->question,
            'answer'   => $faq->answer,
        ], $data));

        return response()->json(array_merge([
            'id' => (int)$faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
        ], $this->pageI18n->faqTranslations($faq)));
    }

    /**
     * DELETE /api/v1/admin/pages/faq/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $faq = PageFaq::query()->find($id);
        if (!$faq) {
            return $this->errorResponse('NotFound', 'FAQ not found.', 404);
        }

        DB::table('i18n_texts')
            ->where('entity_table', 'page_faqs')
            ->where('entity_id', $id)
            ->delete();

        $faq->delete();

        return response()->json(['ok' => true]);
    }
}
