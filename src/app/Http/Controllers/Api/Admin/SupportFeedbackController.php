<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportFeedback;
use Illuminate\Http\Request;

class SupportFeedbackController extends Controller
{
    /**
     * GET /api/v1/admin/support-feedbacks
     * Query: status (optional), q (optional), page/limit
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:new,seen,in_progress,closed',
            'q'      => 'nullable|string|max:200',
            'limit'  => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $data['limit'] ?? 20;

        $q = SupportFeedback::with('user:id,name,mobile')
            ->when(!empty($data['status']), fn($qq) => $qq->where('status', $data['status']))
            ->when(!empty($data['q']), function ($qq) use ($data) {
                $term = $data['q'];
                $qq->where(function ($w) use ($term) {
                    $w->where('subject', 'ilike', "%{$term}%")
                      ->orWhere('message', 'ilike', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($limit);

        return response()->json([
            'data' => $q->getCollection()->map(fn($x) => [
                'id' => $x->id,
                'user' => $x->user ? [
                    'id' => $x->user->id,
                    'name' => $x->user->name,
                    'phone' => $x->user->mobile ?? null,
                ] : null,
                'subject' => $x->subject,
                'message' => $x->message,
                'status' => $x->status,
                'createdAt' => $x->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'currentPage' => $q->currentPage(),
                'lastPage' => $q->lastPage(),
                'perPage' => $q->perPage(),
                'total' => $q->total(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/support-feedbacks/{id}
     * Body: { status: new|seen|in_progress|closed }
     */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|string|in:new,seen,in_progress,closed',
        ]);

        $row = SupportFeedback::findOrFail($id);
        $row->status = $data['status'];
        $row->updated_at = now();
        $row->save();

        return response()->json([
            'message' => 'وضعیت به‌روزرسانی شد.',
            'id' => $row->id,
            'status' => $row->status,
        ]);
    }

    /** DELETE /api/v1/admin/support-feedbacks/{id} */
    public function destroy(string $id)
    {
        // Do not cast an untrusted route ID to an integer or send it to
        // PostgreSQL before checking its positive signed-bigint range.
        $id = ltrim($id, '0');
        if ($id === '' || !ctype_digit($id) || strlen($id) > 19
            || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            return response()->json(['success' => false, 'message' => 'بازخورد یافت نشد.'], 404);
        }

        $deleted = SupportFeedback::query()->whereKey($id)->delete();
        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'بازخورد یافت نشد.'], 404);
        }

        return response()->json([
            'success' => true,
            'id' => (int) $id,
            'message' => 'بازخورد حذف شد.',
        ]);
    }
}
