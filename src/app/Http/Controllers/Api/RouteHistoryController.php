<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteHistoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $limit = max(1, min(50, (int) $request->query('limit', 20)));

        $rows = DB::table('route_logs')
            ->where('ok', true)
            ->whereRaw("meta->>'user_id' = ?", [(string) $user->id])
            ->whereRaw("meta ? 'route_snapshot'")
            ->whereRaw("meta ? 'history'")
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($row) {
            $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
            if (!is_array($meta)) {
                $meta = [];
            }

            $history = is_array($meta['history'] ?? null) ? $meta['history'] : [];
            $snapshot = is_array($meta['route_snapshot'] ?? null) ? $meta['route_snapshot'] : null;
            if (!$snapshot) {
                return null;
            }

            return [
                'id' => (int) $row->id,
                'timestamp' => $row->ts,
                'mode' => $row->mode,
                'gender' => $row->gender,
                'floor' => $row->floor !== null ? (int) $row->floor : null,
                'distanceMeters' => $row->distance_m !== null ? (float) $row->distance_m : null,
                'durationSeconds' => $row->duration_s !== null ? (float) $row->duration_s : null,
                'origin' => $history['origin'] ?? null,
                'destination' => $history['destination'] ?? null,
                'route' => $snapshot,
            ];
        })->filter()->values();

        return response()->json(['data' => $data]);
    }
}
