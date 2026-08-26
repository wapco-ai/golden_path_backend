<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteDoorGraphJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 900;

    public function __construct(public int $doorId)
    {
        $this->onQueue('graph');
    }

    public function uniqueId(): string
    {
        return 'delete-door-graph-' . $this->doorId;
    }

    public function handle(): void
    {
        $doorId = $this->doorId;

        DB::transaction(function () use ($doorId) {
            DB::select('SELECT pg_advisory_xact_lock(?::bigint)', [$doorId]);

            DB::statement("SET LOCAL statement_timeout = '15min'");

            $this->setGraphStatus('rebuilding');

            /*
             * خیلی مهم:
             * این تابع باید قبل از حذف رکورد doors و door_access_points اجرا شود،
             * چون برای تشخیص floor و areaهای متاثر از همین رکوردها استفاده می‌کند.
             */
            DB::select(
                "SELECT public.fn_remove_door_from_graph(
                    ?::bigint,
                    false,
                    2.50,
                    0.20
                )",
                [$doorId]
            );

            // DB::table('door_access_points')
            //     ->where('door_id', $doorId)
            //     ->delete();

            DB::table('access_time_restrictions')
                ->where('entity_table', 'doors')
                ->where('entity_id', $doorId)
                ->delete();

            DB::table('access_prayer_restrictions')
                ->where('entity_table', 'doors')
                ->where('entity_id', $doorId)
                ->delete();

            DB::table('door_status_live')
                ->where('door_id', $doorId)
                ->delete();

            DB::table('door_schedules')
                ->where('door_id', $doorId)
                ->delete();

            DB::table('i18n_texts')
                ->where('entity_table', 'doors')
                ->where('entity_id', $doorId)
                ->delete();

            DB::table('doors')
                ->where('id', $doorId)
                ->delete();

            /*
             * چون mv_area_door_stats روی تعداد درب‌های هر area اثر دارد،
             * بعد از حذف فیزیکی درب refresh می‌شود.
             * CONCURRENTLY داخل transaction مجاز نیست؛ پس همین REFRESH عادی مناسب است.
             */
            DB::statement('REFRESH MATERIALIZED VIEW public.mv_area_door_stats');
        });
    }

    public function failed(Throwable $e): void
    {
        $this->setGraphStatus('failed', $e->getMessage());
    }

    private function setGraphStatus(string $status, ?string $error = null): void
    {
        $exists = DB::table('doors')
            ->where('id', $this->doorId)
            ->exists();

        if (!$exists) {
            return;
        }

        $payload = [
            'status'     => $status,
            'action'     => 'delete',
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