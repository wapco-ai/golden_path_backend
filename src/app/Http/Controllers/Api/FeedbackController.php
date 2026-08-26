<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    /**
     * GET /api/v1/feedbacks
     *
     * Query:
     *  - targetType: poi | content | route (required)
     *  - targetId: int (required)
     *  - lang: fa | en | ar | ur (optional)
     *  - page, limit (optional)
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'targetType' => 'required|string|in:poi,content,route',
            'targetId'   => 'required|integer|min:1',
            'lang'       => 'nullable|string|in:fa,en,ar,ur',
            'page'       => 'nullable|integer|min:1',
            'limit'      => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $data['limit'] ?? 20;

        $query = UserFeedback::with('user:id,name')
            ->where('target_type', $data['targetType'])
            ->where('target_id', $data['targetId'])
            ->where('status', 'approved');

        if (!empty($data['lang'])) {
            $query->where('lang', $data['lang']);
        }

        $feedbacks = $query
            ->orderByDesc('created_at')
            ->paginate($limit);

        // خلاصه‌ی امتیاز از View یا attrs
        $summary = $this->buildSummary($data['targetType'], $data['targetId']);

        return response()->json([
            'target' => [
                'type' => $data['targetType'],
                'id'   => (int) $data['targetId'],
            ],
            'summary' => $summary,
            'feedbacks' => [
                'data' => $feedbacks->getCollection()->map(function (UserFeedback $f) {
                    return [
                        'id'        => $f->id,
                        'userName'  => optional($f->user)->name,
                        'lang'      => $f->lang,
                        'rating'    => $f->rating,
                        'title'     => $f->title,
                        'body'      => $f->body,
                        'createdAt' => $f->created_at?->toIso8601String(),
                    ];
                }),
                'pagination' => [
                    'currentPage' => $feedbacks->currentPage(),
                    'lastPage'    => $feedbacks->lastPage(),
                    'perPage'     => $feedbacks->perPage(),
                    'total'       => $feedbacks->total(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/feedbacks
     *
     * Body:
     *  - targetType: poi | content | route
     *  - targetId: int
     *  - lang: fa | en | ar | ur
     *  - rating: int (1..5) (optional)
     *  - title: string (optional)
     *  - body: string (optional)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'targetType' => 'required|string|in:poi,content,route',
            'targetId'   => 'required|integer|min:1',
            'lang'       => 'required|string|in:fa,en,ar,ur',
            'rating'     => 'nullable|integer|min:1|max:5',
            'title'      => 'nullable|string|max:255',
            'body'       => 'nullable|string|max:5000',
        ]);

        $user = $request->user();
        // موقتاً:
        $userId = $user?->id ?? 1; // مثلا کاربر 1 را guest در نظر بگیر
        // if (!$user) {
        //     return response()->json([
        //         'message' => 'Unauthorized',
        //     ], 401);
        // }

        // یا می‌تونی اگر رکورد از قبل بود خطا بدهی؛ اینجا اجازه ویرایش می‌دهیم
        $feedback = UserFeedback::updateOrCreate(
            [
                'user_id'     => $userId,
                // user_id'     => $user->id,
                'target_type' => $data['targetType'],
                'target_id'   => $data['targetId'],
                'lang'        => $data['lang'],
            ],
            [
                'rating'   => $data['rating'] ?? null,
                'title'    => $data['title'] ?? null,
                'body'     => $data['body'] ?? null,
                // هر بار که کاربر ادیت می‌کند، دوباره pending می‌شود
                'status'   => 'pending',
                'admin_note' => null,
            ]
        );

        return response()->json([
            'id'      => $feedback->id,
            'status'  => $feedback->status,
            'message' => 'نظر شما ثبت شد و پس از تأیید ادمین نمایش داده می‌شود.',
        ], 201);
    }

    /**
     * GET /api/v1/feedbacks/summary
     *
     * Query:
     *  - targetType: poi | content | route
     *  - targetId: int
     */
    public function summary(Request $request)
    {
        $data = $request->validate([
            'targetType' => 'required|string|in:poi,content,route',
            'targetId'   => 'required|integer|min:1',
        ]);

        $summary = $this->buildSummary($data['targetType'], $data['targetId']);

        return response()->json([
            'targetType' => $data['targetType'],
            'targetId'   => (int) $data['targetId'],
            'ratingAvg'  => $summary['ratingAvg'],
            'ratingCount' => $summary['ratingCount'],
            'lastReviewAt' => $summary['lastReviewAt'],
        ]);
    }

    /**
     * خلاصه‌ی امتیاز را از View (برای poi) یا مستقیماً از جدول حساب می‌کند.
     */
    protected function buildSummary(string $targetType, int $targetId): array
    {
        if ($targetType === 'poi') {
            $row = DB::table('poi_ratings')
                ->where('poi_id', $targetId)
                ->first();

            return [
                'ratingAvg'    => $row?->rating_avg ? (float) $row->rating_avg : 0.0,
                'ratingCount'  => $row?->rating_count ? (int) $row->rating_count : 0,
                'lastReviewAt' => $row?->last_review_at
                    ? (new \Carbon\Carbon($row->last_review_at))->toIso8601String()
                    : null,
            ];
        }

        // برای content / route فعلاً مستقیم از user_feedbacks حساب می‌کنیم
        $row = DB::table('user_feedbacks')
            ->selectRaw('
                COUNT(*) FILTER (WHERE rating IS NOT NULL) AS rating_count,
                AVG(rating) FILTER (WHERE rating IS NOT NULL) AS rating_avg,
                MAX(created_at) AS last_review_at
            ')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', 'approved')
            ->first();

        return [
            'ratingAvg'    => $row?->rating_avg ? (float) $row->rating_avg : 0.0,
            'ratingCount'  => $row?->rating_count ? (int) $row->rating_count : 0,
            'lastReviewAt' => $row?->last_review_at
                ? (new \Carbon\Carbon($row->last_review_at))->toIso8601String()
                : null,
        ];
    }
}
