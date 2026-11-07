<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RebuildDoorGraphJob implements ShouldQueue, ShouldBeUnique
{
    use FoundationQueueable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 900;

    public function __construct(public int $doorId)
    {
        $this->onQueue('graph');
    }

    public function uniqueId(): string
    {
        return 'door-graph-' . $this->doorId;
    }

    public function handle(): void
    {
        $this->setGraphStatus('rebuilding');

        DB::transaction(function () {
            // جلوگیری از اجرای همزمان rebuild برای یک درب
            DB::select('SELECT pg_advisory_xact_lock(?)', [$this->doorId]);

            // برای عملیات سنگین PostGIS
            DB::statement("SET LOCAL statement_timeout = '15min'");

            $row = DB::selectOne(
                "SELECT public.fn_rebuild_graph_for_door(
                    :door_id,
                    false,
                    2.50,
                    0.20
                ) AS result",
                [
                    'door_id' => $this->doorId,
                ]
            );

            $this->validateSingleSidedStop($row->result ?? null);
        });

        $this->setGraphStatus('ready');
    }

    public function failed(Throwable $e): void
    {
        $this->setGraphStatus('failed', $e->getMessage());
    }

    private function validateSingleSidedStop(?string $result): bool
    {
        // A landing needs a local edge only when its area has other graph nodes.
        // In a single-node area the route can reach the landing directly.
        $stop = DB::selectOne(
            'SELECT n.node_id IS NOT NULL AND (NOT EXISTS (
                SELECT 1
                FROM public.routing_nodes peer
                WHERE peer.floor = n.floor AND peer.area_id = n.area_id AND peer.id <> n.node_id
            ) OR EXISTS (
                SELECT 1
                FROM public.routing_edges_static e
                JOIN public.routing_nodes peer ON peer.id = CASE WHEN e.src = n.node_id THEN e.dst ELSE e.src END
                WHERE n.node_id IN (e.src, e.dst)
                  AND e.floor = n.floor AND peer.floor = n.floor AND peer.area_id = n.area_id
                  AND peer.id <> n.node_id AND e.base_cost > 0
                  AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom)
            )) AS connected
            FROM public.routing_connector_stops s
            LEFT JOIN public.routing_connector_nodes n ON n.id = s.id
            LEFT JOIN public.routing_nodes rn ON rn.id = n.node_id
            LEFT JOIN public.door_access_points dap ON dap.id = rn.ref_id
            WHERE s.door_id = ? AND (dap.from_area IS NULL OR dap.to_area IS NULL)',
            [$this->doorId]
        );

        if ($stop === null) {
            return false;
        }

        // Some graph builders report ok=false solely because there is no door transition.
        $summary = json_decode($result ?? '', true);
        if (!$stop->connected || ($summary['inserted_nodes'] ?? 0) < 1
            || ($summary['areas_rebuilt_ok'] ?? 0) < 1 || ($summary['areas_rebuilt_failed'] ?? null) !== 0) {
            throw new RuntimeException('اتصال گره توقف به گراف همان طبقه کامل نشد. ' . $result);
        }

        return true;
    }

    private function setGraphStatus(string $status, ?string $error = null): void
    {
        $payload = [
            'status' => $status,
            'updated_at' => now()->toISOString(),
        ];

        if ($error !== null) {
            $payload['error'] = mb_substr($error, 0, 500);
        }

        DB::statement(
            "
            UPDATE public.doors
            SET attrs = jsonb_set(
                COALESCE(attrs, '{}'::jsonb),
                '{graph}',
                ?::jsonb,
                true
            )
            WHERE id = ?
            ",
            [
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $this->doorId,
            ]
        );
    }
}
