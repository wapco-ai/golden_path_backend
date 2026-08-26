<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoorBulkController extends Controller
{
    /**
     * PATCH /api/v1/doors/bulk-open
     *
     * Body:
     * {
     *   "door_ids": [1,2,3],
     *   "is_open": true
     * }
     */
    public function bulkSetOpen(Request $request)
    {
        $data = $request->validate([
            'door_ids'   => ['required', 'array', 'min:1'],
            'door_ids.*' => ['integer', 'min:1'],
            'is_open'    => ['required', 'boolean'],
        ]);

        $doorIds = array_values(array_unique($data['door_ids']));
        $isOpen  = (bool) $data['is_open'];

        return DB::transaction(function () use ($doorIds, $isOpen) {
            // 1) find existing doors
            $existingIds = DB::table('doors')
                ->whereIn('id', $doorIds)
                ->pluck('id')
                ->all();

            $notFound = array_values(array_diff($doorIds, $existingIds));

            // 2) bulk update
            $updated = 0;
            if (!empty($existingIds)) {
                $updated = DB::table('doors')
                    ->whereIn('id', $existingIds)
                    ->update([
                        'is_open'    => $isOpen,
                        'updated_at' => DB::raw('now()'),
                    ]);
            }

            return response()->json([
                'ok'        => true,
                'requested' => count($doorIds),
                'updated'   => $updated,
                'not_found' => $notFound,
                'is_open'   => $isOpen,
            ]);
        });
    }
}
