<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\UserLogsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserLogsController extends Controller
{
    public function __construct(private readonly UserLogsService $service) {}

    /**
     * GET /api/v1/admin/user-logs
     * Query: page, pageSize, search, sortBy, sortOrder, fromDate, toDate
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'page'      => ['nullable', 'integer', 'min:1'],
            'pageSize'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'search'    => ['nullable', 'string', 'max:200'],
            'sortBy'    => ['nullable', Rule::in(['lastLogin', 'lastRoutingDate', 'successfulRoutes', 'totalRoutes', 'fullName'])],
            'sortOrder' => ['nullable', Rule::in(['asc', 'desc'])],
            'fromDate'  => ['nullable', 'date_format:Y-m-d'],
            'toDate'    => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->service->list(
                search: $data['search'] ?? null,
                page: (int)($data['page'] ?? 1),
                pageSize: (int)($data['pageSize'] ?? 6),
                sortBy: (string)($data['sortBy'] ?? 'lastLogin'),
                sortOrder: (string)($data['sortOrder'] ?? 'desc'),
                fromDate: $data['fromDate'] ?? null,
                toDate: $data['toDate'] ?? null,
            )
        );
    }

    /**
     * GET /api/v1/admin/user-logs/export
     * خروجی CSV (قابل باز شدن با Excel)
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'search'    => ['nullable', 'string', 'max:200'],
            'sortBy'    => ['nullable', Rule::in(['lastLogin', 'lastRoutingDate', 'successfulRoutes', 'totalRoutes', 'fullName'])],
            'sortOrder' => ['nullable', Rule::in(['asc', 'desc'])],
            'fromDate'  => ['nullable', 'date_format:Y-m-d'],
            'toDate'    => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filename = 'user-logs-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            // Header row
            fputcsv($out, [
                'ID',
                'Full Name',
                'Last Login (UTC)',
                'Successful Routes',
                'Total Routes',
                'Last Routing Date',
                'Last Routing',
            ]);

            $this->service->streamExportCsv(
                out: $out,
                search: $data['search'] ?? null,
                sortBy: (string)($data['sortBy'] ?? 'lastLogin'),
                sortOrder: (string)($data['sortOrder'] ?? 'desc'),
                fromDate: $data['fromDate'] ?? null,
                toDate: $data['toDate'] ?? null,
            );

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}