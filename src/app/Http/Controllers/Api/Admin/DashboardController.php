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
        foreach (['unreadOnly', 'includeMeta'] as $flag) {
            if ($request->exists($flag)) {
                $value = $request->input($flag);
                if ($value === 'true' || $value === 'false') {
                    $request->merge([$flag => $value === 'true']);
                }
            }
        }
        $data = $request->validate([
            'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
            'unreadOnly' => ['sometimes', 'required', 'boolean'],
            'includeMeta' => ['sometimes', 'required', 'boolean'],
        ]);

        return response()->json(
            $this->service->notifications(
                (int) $request->user()->id,
                (int) ($data['limit'] ?? 10),
                (bool) ($data['unreadOnly'] ?? false),
                (bool) ($data['includeMeta'] ?? false),
            )
        );
    }

    public function markNotificationsRead(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => [
                'required', 'string', 'distinct:strict',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) || !preg_match('/\A(new_user|new_comment|support_message):([1-9][0-9]{0,18})\z/', $value, $matches)
                        || (strlen($matches[2]) === 19 && strcmp($matches[2], '9223372036854775807') > 0)) {
                        $fail('The '.$attribute.' must be a valid notification ID.');
                    }
                },
            ],
        ]);

        return response()->json($this->service->markNotificationsRead((int) $request->user()->id, $data['ids']));
    }

    public function markAllNotificationsRead(Request $request)
    {
        return response()->json($this->service->markAllNotificationsRead((int) $request->user()->id));
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
