<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PublicJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CaptureRouteHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/routing/route')) {
            return $next($request);
        }

        $user = $this->resolveOptionalUser($request);
        $response = $next($request);

        if (!$user || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload) || !($payload['ok'] ?? false)) {
            return $response;
        }

        $this->attachSnapshotToMatchingLog($request, $user, $payload);

        return $response;
    }

    private function resolveOptionalUser(Request $request): ?User
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        /** @var PublicJwtService $jwt */
        $jwt = app(PublicJwtService::class);
        $payload = $jwt->decode(substr($authHeader, 7));
        if (!$payload || ($payload['type'] ?? null) !== 'access') {
            return null;
        }

        $userId = $payload['sub'] ?? null;
        /** @var User|null $user */
        $user = $userId ? User::find($userId) : null;

        if (!$user || $user->is_admin || $user->status !== 'active') {
            return null;
        }

        if ($user->locked_until && now()->lessThan($user->locked_until)) {
            return null;
        }

        $request->setUserResolver(fn () => $user);

        return $user;
    }

    private function attachSnapshotToMatchingLog(Request $request, User $user, array $payload): void
    {
        try {
            $input = $request->all();
            $floor = isset($input['floor']) ? (int) $input['floor'] : 0;

            $origin = [
                'type' => data_get($input, 'origin.type'),
                'id' => data_get($input, 'origin.id'),
                'code' => data_get($input, 'origin.code'),
                'lat' => data_get($input, 'origin.lat'),
                'lon' => data_get($input, 'origin.lon'),
            ];
            $destination = [
                'type' => data_get($input, 'destination.type'),
                'id' => data_get($input, 'destination.id'),
                'code' => data_get($input, 'destination.code'),
                'lat' => data_get($input, 'destination.lat'),
                'lon' => data_get($input, 'destination.lon'),
            ];

            $fingerprint = sha1(json_encode([
                'mode' => $input['mode'] ?? null,
                'gender' => $input['gender'] ?? null,
                'floor' => $floor,
                'o' => $origin,
                'd' => $destination,
                'user_id' => $user->id,
            ], JSON_UNESCAPED_UNICODE));

            $row = DB::table('route_logs')
                ->where('ok', true)
                ->whereRaw("meta->>'fingerprint' = ?", [$fingerprint])
                ->orderByDesc('ts')
                ->orderByDesc('id')
                ->first();

            if (!$row) {
                return;
            }

            $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
            if (!is_array($meta)) {
                $meta = [];
            }

            $originFloor = (int) (data_get($input, 'origin.floor') ?? $floor);
            $destinationFloor = (int) (data_get($input, 'destination.floor') ?? $floor);

            $meta['history'] = [
                'origin' => [
                    'name' => data_get($input, 'origin.name'),
                    'coordinates' => [
                        (float) data_get($input, 'origin.lat'),
                        (float) data_get($input, 'origin.lon'),
                    ],
                    'floor' => $originFloor,
                ],
                'destination' => [
                    'name' => data_get($input, 'destination.name'),
                    'coordinates' => [
                        (float) data_get($input, 'destination.lat'),
                        (float) data_get($input, 'destination.lon'),
                    ],
                    'floor' => $destinationFloor,
                ],
                'capture_version' => 1,
            ];
            $meta['route_snapshot'] = $payload;

            DB::table('route_logs')
                ->where('id', $row->id)
                ->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            // Route history capture must never break routing.
        }
    }
}
