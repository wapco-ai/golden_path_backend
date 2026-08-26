<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserFeedback;
use Illuminate\Http\Request;

class CommentAdminController extends Controller
{
    private function errorResponse(string $error, string $message, int $status = 400)
    {
        return response()->json([
            'error'   => $error,
            'message' => $message,
        ], $status);
    }

    /**
     * GET /api/v1/admin/comments
     * Query:
     * - page, perPage
     * - status: pending|approved|rejected
     * - postId/entityId  (maps to target_id)
     * - authorName / authorEmail (search)
     * - dateFrom / dateTo (ISO or YYYY-MM-DD)
     * - sortBy: createdAt|updatedAt
     * - sortOrder: asc|desc
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'page'       => 'nullable|integer|min:1',
            'perPage'    => 'nullable|integer|min:1|max:200',

            'status'     => 'nullable|string|in:pending,approved,rejected',
            'postId'     => 'nullable|integer|min:1',
            'entityId'   => 'nullable|integer|min:1',

            'authorName'  => 'nullable|string|max:200',
            'authorEmail' => 'nullable|string|max:200',

            'dateFrom'   => 'nullable|date',
            'dateTo'     => 'nullable|date',

            'sortBy'     => 'nullable|string|in:createdAt,updatedAt',
            'sortOrder'  => 'nullable|string|in:asc,desc',
        ]);

        $perPage   = $data['perPage'] ?? 20;
        $status    = $data['status'] ?? null;
        $targetId  = $data['postId'] ?? ($data['entityId'] ?? null);

        $sortBy    = $data['sortBy'] ?? 'createdAt';
        $sortCol   = $sortBy === 'updatedAt' ? 'updated_at' : 'created_at';
        $sortOrder = $data['sortOrder'] ?? 'desc';

        $q = UserFeedback::query()
            ->with(['user:id,name,email'])
            ->when($status, fn($qq) => $qq->where('status', $status))
            ->when($targetId, fn($qq) => $qq->where('target_id', $targetId))
            ->when(!empty($data['dateFrom']), fn($qq) => $qq->whereDate('created_at', '>=', $data['dateFrom']))
            ->when(!empty($data['dateTo']), fn($qq) => $qq->whereDate('created_at', '<=', $data['dateTo']))
            ->when(!empty($data['authorName']), function ($qq) use ($data) {
                $term = trim($data['authorName']);
                $qq->whereHas('user', fn($u) => $u->where('name', 'ILIKE', "%{$term}%"));
            })
            ->when(!empty($data['authorEmail']), function ($qq) use ($data) {
                $term = trim($data['authorEmail']);
                $qq->whereHas('user', fn($u) => $u->where('email', 'ILIKE', "%{$term}%"));
            })
            ->orderBy($sortCol, $sortOrder);

        $p = $q->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => $p->getCollection()->map(function (UserFeedback $f) {
                return [
                    'id'          => (string)$f->id,
                    'postId'      => $f->target_id, // قرارداد فرانت
                    'authorName'  => $f->user?->name,
                    'authorEmail' => $f->user?->email,
                    'content'     => $f->body ?? $f->title ?? '',
                    'status'      => $f->status,
                    'createdAt'   => $f->created_at?->toIso8601String(),
                    'updatedAt'   => $f->updated_at?->toIso8601String(),
                ];
            })->values(),
            'pagination' => [
                'page'       => $p->currentPage(),
                'perPage'    => $p->perPage(),
                'total'      => $p->total(),
                'totalPages' => $p->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/comments/{id}
     */
    public function show(Request $request, $id)
    {
        $f = UserFeedback::with(['user:id,name,email'])->find($id);
        if (!$f) {
            return $this->errorResponse('NotFound', 'Comment not found.', 404);
        }

        return response()->json([
            'id'          => (string)$f->id,
            'postId'      => $f->target_id,
            'authorName'  => $f->user?->name,
            'authorEmail' => $f->user?->email,
            'content'     => $f->body ?? $f->title ?? '',
            'status'      => $f->status,
            'createdAt'   => $f->created_at?->toIso8601String(),
            'updatedAt'   => $f->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * PATCH /api/v1/admin/comments/{id}/status
     * Body: { "status": "approved" }
     */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,rejected,pending',
        ]);

        $f = UserFeedback::find($id);
        if (!$f) {
            return $this->errorResponse('NotFound', 'Comment not found.', 404);
        }

        $f->status = $data['status'];

        // اگر approved شد، اطلاعات تاییدکننده را پر کن
        if ($data['status'] === 'approved') {
            $f->approved_at = now();
            $f->approved_by = $request->user()?->id; // توسط AdminAuth باید set شده باشد
        }

        $f->save();

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/v1/admin/comments/{id}
     */
    public function destroy(Request $request, $id)
    {
        $f = UserFeedback::find($id);
        if (!$f) {
            return $this->errorResponse('NotFound', 'Comment not found.', 404);
        }

        $f->delete();

        return response()->json(['success' => true]);
    }
}
