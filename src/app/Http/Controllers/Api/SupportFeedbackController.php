<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportFeedback;
use Illuminate\Http\Request;

class SupportFeedbackController extends Controller
{
    /**
     * POST /api/v1/support/feedback
     * Body: { subject: string(required), message: string(required) }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:8000',
        ]);

        $user = $request->user();

        $row = SupportFeedback::create([
            'user_id' => $user?->id, // اگر لاگین نباشد NULL
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status'  => 'new',
            'meta'    => [
                'ip'        => $request->ip(),
                'userAgent' => $request->userAgent(),
                // اگر فرانت خواست بعداً اضافه کند:
                // 'appVersion' => $request->header('X-App-Version'),
                // 'platform'   => $request->header('X-Platform'),
            ],
        ]);

        return response()->json([
            'id' => $row->id,
            'message' => 'بازخورد شما ثبت شد.',
        ], 201);
    }

    /**
     * GET /api/v1/support/feedbacks/me
     * نیازمند لاگین (اگر می‌خواید)
     */
    public function myList(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $limit = (int) min(max((int) $request->query('limit', 20), 1), 100);

        $items = SupportFeedback::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($limit);

        return response()->json([
            'data' => $items->getCollection()->map(fn($x) => [
                'id' => $x->id,
                'subject' => $x->subject,
                'message' => $x->message,
                'status' => $x->status,
                'createdAt' => $x->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'currentPage' => $items->currentPage(),
                'lastPage' => $items->lastPage(),
                'perPage' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }
}
