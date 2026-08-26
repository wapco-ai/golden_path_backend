<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GuidancePointImageService
{
    public const MAX_IMAGES = 4;

    public function diskName(): string
    {
        // دقیقاً مثل مدیریت فرهنگی: همیشه روی public disk
        return 'public';
    }

    public function storeMany(array $files, ?int $pointId = null, array $meta = [], int $sortOffset = 0): array
    {
        if (count($files) > self::MAX_IMAGES) {
            throw new RuntimeException('A guidance point can have at most 4 images.');
        }

        $stored = [];
        $disk = Storage::disk('public');

        // ساختار نهایی:
        // uploads/guidance_points/{point_id}/images/{filename}
        $baseDir = 'uploads/guidance_points';

        foreach (array_values($files) as $idx => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $this->deleteKeys(array_column($stored, 'image_key'));
                throw new RuntimeException('Invalid uploaded image.');
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');

            $key = sprintf(
                '%s/%s/images/%s.%s',
                $baseDir,
                $pointId ?: 'tmp',
                (string) Str::uuid(),
                $extension
            );

            try {
                // مثل الگوی فرهنگی، مستقیم روی disk public ذخیره می‌کنیم
                $path = $file->storeAs(
                    dirname($key),
                    basename($key),
                    'public'
                );

                if (!$path || !$disk->exists($path)) {
                    throw new RuntimeException('Storage put failed or file does not exist after upload: ' . $key);
                }

                $m = $meta[$idx] ?? [];

                $stored[] = [
                    'image_url' => $this->urlFor($path),
                    'image_key' => $path,
                    'sort_order' => $sortOffset + $idx + 1,
                    'view_orientation' => $m['view_orientation'] ?? null,
                    'azimuth_deg' => $m['azimuth_deg'] ?? null,
                    'fov_deg' => $m['fov_deg'] ?? 60,
                    'caption' => $m['caption'] ?? null,
                    'attrs' => json_encode([], JSON_UNESCAPED_UNICODE),
                ];
            } catch (\Throwable $e) {
                $this->deleteKeys(array_column($stored, 'image_key'));
                throw new RuntimeException('Image upload failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return $stored;
    }

    public function deleteKeys(array $keys): void
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if (!$keys) {
            return;
        }

        try {
            Storage::disk('public')->delete($keys);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function urlFor(string $key): string
    {
        return Storage::disk('public')->url($key);
    }
}