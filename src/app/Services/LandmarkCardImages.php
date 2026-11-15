<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class LandmarkCardImages
{
    private const UPLOAD_URL = '/storage/uploads/';
    private const THUMBNAIL_DIRECTORY = 'card-thumbnails-v1';

    public function decorate(array $places, string $requestHost): array
    {
        $hosts = [strtolower($requestHost), strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))];
        $uploads = Storage::disk('public')->path('uploads');

        foreach ($places as &$place) {
            $image = $place['image'] ?? null;
            if (!is_string($image) || ($url = $this->localUploadUrl($image, $hosts)) === null) {
                continue;
            }

            $place['image'] = $url;
            $thumbnail = $this->findThumbnail($url, $uploads);
            if ($thumbnail !== null) {
                $place['thumbnail'] = $thumbnail;
            }
        }
        unset($place);

        return $places;
    }

    private function localUploadUrl(string $image, array $hosts): ?string
    {
        $parts = parse_url($image);
        if ($parts === false || isset($parts['user'])
            || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))
            || (isset($parts['host']) && !in_array(strtolower($parts['host']), $hosts, true))) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if (str_starts_with(ltrim($path, '/'), 'uploads/')) {
            $path = '/storage/' . ltrim($path, '/');
        }

        if (!str_starts_with($path, self::UPLOAD_URL)) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private function findThumbnail(string $url, string $uploads): ?string
    {
        $path = substr(parse_url($url, PHP_URL_PATH), strlen(self::UPLOAD_URL));
        $path = rawurldecode($path);
        if (!preg_match('~\Acontents/[0-9]+/images/[^/\\\\\x00-\x1F]+\.(?:jpe?g|png|webp|gif)\z~i', $path)) {
            return null;
        }

        $original = @stat($uploads . '/' . $path);
        if ($original === false || ($original['mode'] & 0170000) !== 0100000) {
            return null;
        }

        // Keep this key in sync with scripts/generate_landmark_card_thumbnails.py.
        $fingerprint = implode("\n", [$path, $original['ino'], $original['mtime'], $original['size']]);
        $key = hash('sha256', $fingerprint);
        $thumbnail = self::THUMBNAIL_DIRECTORY . '/' . $key . '.webp';
        return is_file($uploads . '/' . $thumbnail) ? self::UPLOAD_URL . $thumbnail : null;
    }
}
