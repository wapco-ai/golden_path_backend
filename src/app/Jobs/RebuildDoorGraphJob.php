<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

            DB::select(
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
        });

        $this->setGraphStatus('ready');
    }

    public function failed(Throwable $e): void
    {
        $this->setGraphStatus('failed', $e->getMessage());
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