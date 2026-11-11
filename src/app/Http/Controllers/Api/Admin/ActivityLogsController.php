<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ActivityLogsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ActivityLogsController extends Controller
{
    public function index(Request $request, ActivityLogsService $service): JsonResponse
    {
        $filters = $request->validate([
            'actorId' => ['nullable', 'regex:/\A[0-9]{1,19}\z/'],
            'entityId' => ['nullable', 'regex:/\A[0-9]{1,19}\z/'],
            'operation' => ['nullable', Rule::in(ActivityLogsService::OPERATIONS)],
            'result' => ['nullable', Rule::in(['success', 'failure'])],
            'from' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i:sP', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'cursor' => ['nullable', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}:[0-9]{1,18}\z/'],
        ]);

        return response()->json($service->list($filters), headers: ['Cache-Control' => 'private, no-store']);
    }
}
