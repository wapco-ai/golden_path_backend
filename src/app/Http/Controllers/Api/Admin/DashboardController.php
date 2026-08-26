<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    public function summary()
    {
        return response()->json($this->service->summary());
    }

    public function userVisits(Request $request)
    {
        $data = $request->validate([
            'range' => ['nullable', Rule::in(['week', 'month', 'quarter', 'year'])],
        ]);

        return response()->json(
            $this->service->userVisits($data['range'] ?? 'week')
        );
    }

    public function commentStats(Request $request)
    {
        $data = $request->validate([
            'range' => ['nullable', Rule::in(['week', 'month', 'quarter', 'year'])],
        ]);

        return response()->json(
            $this->service->commentStats($data['range'] ?? 'week')
        );
    }

    public function notifications(Request $request)
    {
        // ⬅️ CAST قبل از validate
        if ($request->has('unreadOnly')) {
            $request->merge([
                'unreadOnly' => filter_var(
                    $request->query('unreadOnly'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                )
            ]);
        }
        $data = $request->validate([
            'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
            'unreadOnly' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->service->notifications(
                $data['limit'] ?? 10,
                (bool)($data['unreadOnly'] ?? false)
            )
        );
    }

    public function recentUsers(Request $request)
    {
        $data = $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            $this->service->recentUsers(
                $data['search'] ?? null,
                (int)($data['page'] ?? 1),
                (int)($data['pageSize'] ?? 10),
            )
        );
    }
}
