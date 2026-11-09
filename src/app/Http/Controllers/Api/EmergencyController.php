<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportFeedback;
use Illuminate\Http\Request;

class EmergencyController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'requestId' => 'required|uuid',
            'type' => 'required|in:fire,theft,medical,missing',
            'description' => 'required|string|max:6000',
            'location' => 'nullable|array:lat,lng,source,accuracy,timestamp,floor',
            'location.lat' => 'required_with:location|numeric|between:-90,90',
            'location.lng' => 'required_with:location|numeric|between:-180,180',
            'location.source' => 'required_with:location|in:gps,dead_reckoning',
            'location.accuracy' => 'nullable|numeric|min:0|max:25',
            'location.timestamp' => 'required_with:location|numeric',
            'location.floor' => 'nullable|integer|between:-20,200',
        ]);
        $userId = $request->user()?->id;
        $fingerprint = hash('sha256', json_encode([$userId, $data]));
        $existing = SupportFeedback::where('request_id', $data['requestId'])->first();
        if ($existing) {
            if (($existing->meta['fingerprint'] ?? null) !== $fingerprint) {
                return response()->json(['message' => 'Request ID already used'], 409);
            }
            return response()->json(['id' => $existing->id, 'requestId' => $data['requestId'], 'status' => 'received']);
        }
        $location = $data['location'] ?? null;
        // Never present an old or fabricated map centre as a current location.
        if ($location && (abs(now()->getTimestampMs() - $location['timestamp']) > 30000)) {
            return response()->json(['message' => 'Location has expired'], 422);
        }
        $types = ['fire' => 'آتش‌سوزی', 'theft' => 'سرقت', 'medical' => 'پزشکی', 'missing' => 'گمشده'];
        $positionText = $location
            ? "\nموقعیت ({$location['source']}): {$location['lat']}, {$location['lng']}\nطبقه: ".($location['floor'] ?? 'نامشخص')
            : "\nموقعیت فعلی در دسترس نیست؛ محل حادثه را از توضیحات بررسی کنید.";
        // The existing staff support inbox is the durable delivery destination.
        // The unique request_id makes retries after a lost response safe.
        $row = SupportFeedback::firstOrCreate(['request_id' => $data['requestId']], [
            'user_id' => $userId,
            'subject' => 'اضطراری: '.$types[$data['type']],
            'message' => trim($data['description']).$positionText,
            'status' => 'new',
            'meta' => ['kind' => 'emergency', 'type' => $data['type'], 'location' => $location,
                'fingerprint' => $fingerprint],
        ]);
        if (($row->meta['fingerprint'] ?? null) !== $fingerprint) {
            return response()->json(['message' => 'Request ID already used'], 409);
        }
        return response()->json(['id' => $row->id, 'requestId' => $data['requestId'],
            'status' => 'received'], $row->wasRecentlyCreated ? 201 : 200);
    }
}
