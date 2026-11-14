<?php

namespace App\Services;

final class PublicMediaUrl
{
    public static function from(?string $url, ?string $key = null): ?string
    {
        if ($key !== null) {
            $path = preg_replace('#^(?:\./|/)?(?:storage/)?(?=uploads/)#', '', trim($key));
            if (str_starts_with($path, 'uploads/') && !preg_match('#[?\x00-\x1F]|\#|(?:^|/)\.{1,2}(?:/|$)#', $path) && !str_contains($path, '\\')) {
                return '/storage/'.$path;
            }
        }

        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host !== null && (strcasecmp($host, (string) $appHost) === 0 || in_array($host, ['localhost', '127.0.0.1'], true))
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && str_starts_with($path, '/storage/')) {
            return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        }

        return $url;
    }

    public static function attachments(array $media): array
    {
        foreach ($media as $index => $item) {
            if (is_string($item)) {
                $media[$index] = self::from($item);
            } elseif (is_array($item)) {
                $url = $item['url'] ?? null;
                $key = $item['path'] ?? null;
                $resolved = self::from(is_string($url) ? $url : null, is_string($key) ? $key : null);
                if ($resolved !== null) {
                    $media[$index]['url'] = $resolved;
                }
            }
        }

        return $media;
    }
}
