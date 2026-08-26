<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserFeedback;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * GET /api/v1/admin/feedbacks
     *
     * Query:
     *  - status: pending | approved | rejected | hidden (default: pending)
     *  - targetType: poi | content | route (optional)
     *  - targetId: int (optional)
     *  - lang: fa | en | ar | ur (optional)
     *  - userId: int (optional)
     *  - page, limit
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status'     => 'nullable|string|in:pending,approved,rejected,hidden',
            'targetType' => 'nullable|string|in:poi,content,route',
            'targetId'   => 'nullable|integer|min:1',
            'lang'       => 'nullable|string|in:fa,en,ar,ur',
            'userId'     => 'nullable|integer|min:1',
            'page'       => 'nullable|integer|min:1',
            'limit'      => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $data['limit'] ?? 20;
        $status = $data['status'] ?? 'pending';

        $query = UserFeedback::with(['user:id,name', 'approver:id,name'])
            ->where('status', $status);

        if (!empty($data['targetType'])) {
            $query->where('target_type', $data['targetType']);
        }
        if (!empty($data['targetId'])) {
            $query->where('target_id', $data['targetId']);
        }
        if (!empty($data['lang'])) {
            $query->where('lang', $data['lang']);
        }
        if (!empty($data['userId'])) {
            $query->where('user_id', $data['userId']);
        }

        $feedbacks = $query
            ->orderByDesc('created_at')
            ->paginate($limit);

        return response()->json([
            'data' => $feedbacks->getCollection()->map(function (UserFeedback $f) {
                return [
                    'id'          => $f->id,
                    'user'        => [
                        'id'   => $f->user?->id,
                        'name' => $f->user?->name,
                    ],
                    'targetType'  => $f->target_type,
                    'targetId'    => $f->target_id,
                    'lang'        => $f->lang,
                    'rating'      => $f->rating,
                    'title'       => $f->title,
                    'body'        => $f->body,
                    'status'      => $f->status,
                    'adminNote'   => $f->admin_note,
                    'approvedAt'  => $f->approved_at?->toIso8601String(),
                    'approvedBy'  => $f->approver ? [
                        'id'   => $f->approver->id,
                        'name' => $f->approver->name,
                    ] : null,
                    'createdAt'   => $f->created_at?->toIso8601String(),
                ];
            }),
            'pagination' => [
                'currentPage' => $feedbacks->currentPage(),
                'lastPage'    => $feedbacks->lastPage(),
                'perPage'     => $feedbacks->perPage(),
                'total'       => $feedbacks->total(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/feedbacks/{id}
     *
     * Body:
     *  - status: approved | rejected | hidden
     *  - adminNote: string (optional)
     */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status'    => 'required|string|in:approved,rejected,hidden',
            'adminNote' => 'nullable|string|max:2000',
        ]);

        /** @var UserFeedback $feedback */
        $feedback = UserFeedback::findOrFail($id);

        $feedback->status     = $data['status'];
        $feedback->admin_note = $data['adminNote'] ?? null;

        if ($data['status'] === 'approved') {
            $feedback->approved_at = now();
            $feedback->approved_by = $request->user()->id;
        } else {
            // برای rejected/hidden اگر می‌خواهی زمان و کاربر را نگه داری می‌توانی همین را بگذاری
            $feedback->approved_at = $feedback->approved_at ?? now();
            $feedback->approved_by = $feedback->approved_by ?? $request->user()->id;
        }

        $feedback->save();

        return response()->json([
            'message'  => 'وضعیت نظر به‌روزرسانی شد.',
            'feedback' => [
                'id'        => $feedback->id,
                'status'    => $feedback->status,
                'adminNote' => $feedback->admin_note,
                'approvedAt'=> $feedback->approved_at?->toIso8601String(),
            ],
        ]);
    }
}
