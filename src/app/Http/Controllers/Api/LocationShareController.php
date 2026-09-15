<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationShareRequest;
use App\Models\User;
use App\Models\UserLocationShare;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationShareController extends Controller
{
    private const SHARE_TTL_MINUTES = 30;

    public function store(StoreLocationShareRequest $request)
    {
        /** @var User $sender */
        $sender = $request->user();
        $data = $request->validated();

        $normalizedPhone = PhoneNormalizer::iranMobile($data['recipientPhone']);
        if ($normalizedPhone === null) {
            return response()->json([
                'code' => 'RECIPIENT_UNAVAILABLE',
                'message' => 'Recipient is unavailable.',
            ], 422);
        }

        // Existing accounts may contain legacy/raw mobile formatting. Resolve by the
        // canonical identity and fail closed if more than one active account maps to
        // the same number, so a share is never delivered to an arbitrary recipient.
        $recipient = $this->resolveUniqueRecipient($normalizedPhone);

        if (!$recipient || $recipient->id === $sender->id) {
            return response()->json([
                'code' => $recipient?->id === $sender->id ? 'CANNOT_SHARE_WITH_SELF' : 'RECIPIENT_UNAVAILABLE',
                'message' => $recipient?->id === $sender->id
                    ? 'You cannot share your location with yourself.'
                    : 'Recipient is unavailable.',
            ], 422);
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $floor = (int) $data['floor'];
        $accuracy = array_key_exists('accuracyM', $data) && $data['accuracyM'] !== null
            ? (float) $data['accuracyM']
            : null;

        $share = DB::transaction(function () use ($sender, $recipient, $lat, $lng, $floor, $accuracy) {
            // Serialize concurrent create/retry requests for the same sender-recipient
            // pair before replacing the active share.
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$sender->id.':'.$recipient->id]
            );

            UserLocationShare::query()
                ->where('sender_user_id', $sender->id)
                ->where('recipient_user_id', $recipient->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $expiresAt = now()->addMinutes(self::SHARE_TTL_MINUTES);
            $row = DB::selectOne(
                <<<'SQL'
INSERT INTO public.user_location_shares
    (sender_user_id, recipient_user_id, geom, floor, accuracy_m, source, expires_at, created_at, updated_at)
VALUES
    (?, ?, ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), 32640), ?, ?, ?, ?, now(), now())
RETURNING id
SQL,
                [
                    $sender->id,
                    $recipient->id,
                    $lng,
                    $lat,
                    $floor,
                    $accuracy,
                    'gps',
                    $expiresAt,
                ]
            );

            return UserLocationShare::query()
                ->with(['sender', 'recipient'])
                ->findOrFail((int) $row->id);
        });

        return response()->json([
            'share' => $this->toDto($share, includeRecipient: true),
        ], 201);
    }

    public function incoming(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $shares = UserLocationShare::query()
            ->with('sender')
            ->where('recipient_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $unviewedIds = $shares->whereNull('viewed_at')->pluck('id');
        if ($unviewedIds->isNotEmpty()) {
            UserLocationShare::query()
                ->where('recipient_user_id', $user->id)
                ->whereIn('id', $unviewedIds)
                ->whereNull('viewed_at')
                ->update(['viewed_at' => now(), 'updated_at' => now()]);
        }

        return response()->json([
            'items' => $shares->map(fn (UserLocationShare $share) => $this->toDto($share)),
        ]);
    }

    public function outgoing(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $shares = UserLocationShare::query()
            ->with('recipient')
            ->where('sender_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'items' => $shares->map(fn (UserLocationShare $share) => $this->toDto($share, includeRecipient: true)),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        /** @var User $user */
        $user = $request->user();

        $share = UserLocationShare::query()
            ->where('id', $id)
            ->where('sender_user_id', $user->id)
            ->first();

        if (!$share) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        if ($share->revoked_at === null) {
            $share->revoked_at = now();
            $share->save();
        }

        return response()->json(['status' => 'ok']);
    }

    private function toDto(UserLocationShare $share, bool $includeRecipient = false): array
    {
        $point = DB::table('user_location_shares')
            ->where('id', $share->id)
            ->selectRaw('ST_Y(ST_Transform(geom, 4326)) AS lat, ST_X(ST_Transform(geom, 4326)) AS lng')
            ->first();

        $dto = [
            'id' => $share->id,
            'sender' => [
                'displayName' => $this->displayName($share->sender),
            ],
            'location' => [
                'lat' => $point ? (float) $point->lat : null,
                'lng' => $point ? (float) $point->lng : null,
                'floor' => $share->floor,
                'accuracyM' => $share->accuracy_m,
            ],
            'createdAt' => $share->created_at?->toIso8601String(),
            'expiresAt' => $share->expires_at?->toIso8601String(),
            'viewedAt' => $share->viewed_at?->toIso8601String(),
        ];

        if ($includeRecipient) {
            $dto['recipient'] = [
                'displayName' => $this->displayName($share->recipient),
                'mobileMasked' => $this->maskMobile($share->recipient?->mobile),
            ];
        }

        return $dto;
    }

    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'User';
        }

        $canonical = trim(trim((string) ($user->first_name ?? '')).' '.trim((string) ($user->last_name ?? '')));
        return $canonical !== '' ? $canonical : (trim((string) $user->name) ?: 'User');
    }

    private function resolveUniqueRecipient(string $normalizedPhone): ?User
    {
        $matches = [];

        User::query()
            ->select(['id', 'mobile', 'name', 'first_name', 'last_name'])
            ->where('is_admin', false)
            ->where('status', 'active')
            ->whereNotNull('mobile')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($normalizedPhone, &$matches) {
                foreach ($users as $user) {
                    if (PhoneNormalizer::iranMobile($user->mobile) === $normalizedPhone) {
                        $matches[] = $user;
                        if (count($matches) > 1) {
                            return false;
                        }
                    }
                }

                return true;
            });

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function maskMobile(?string $mobile): ?string
    {
        $normalized = PhoneNormalizer::iranMobile($mobile);
        if (!$normalized) {
            return null;
        }

        return substr($normalized, 0, 4).'***'.substr($normalized, -4);
    }
}
