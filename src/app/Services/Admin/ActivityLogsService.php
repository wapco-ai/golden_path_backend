<?php

namespace App\Services\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Exception;
use Generator;
use Illuminate\Support\Str;
use RuntimeException;

final class ActivityLogsService
{
    public const OPERATIONS = ['login', 'logout', 'refresh', 'read', 'post', 'update', 'delete', 'other'];

    private const PAGE_SIZE = 50;

    public function list(array $filters): array
    {
        $timezone = config('app.timezone', 'UTC');
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->setTimezone($timezone) : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->setTimezone($timezone)->endOfSecond() : null;
        [$cursorDay, $cursorOffset] = explode(':', $filters['cursor'] ?? ':');
        $path = config('logging.channels.activity.path');
        $prefix = pathinfo($path, PATHINFO_FILENAME).'-';
        $files = glob(dirname($path).'/'.$prefix.'????-??-??.log') ?: [];
        rsort($files, SORT_STRING);

        $items = [];
        $nextCursor = null;
        foreach ($files as $file) {
            $day = substr(basename($file), strlen($prefix), 10);
            if (is_link($file) || ($cursorDay !== '' && $day > $cursorDay)
                || ($to && $day > $to->toDateString()) || ($from && $day < $from->toDateString())) {
                continue;
            }

            $end = $day === $cursorDay ? (int) $cursorOffset : null;
            foreach ($this->reverseLines($file, $end) as $offset => $line) {
                $row = $this->row($line);
                if ($row === null || ($from && $row['time'] < $from) || ($to && $row['time'] > $to)
                    || (isset($filters['actorId']) && $row['actorId'] !== (string) $filters['actorId'])
                    || (isset($filters['entityId']) && $row['entityId'] !== (string) $filters['entityId'])
                    || (isset($filters['operation']) && $row['operation'] !== $filters['operation'])
                    || (isset($filters['result']) && $row['result'] !== $filters['result'])) {
                    continue;
                }

                if (count($items) === self::PAGE_SIZE) {
                    return $this->response($items, $nextCursor);
                }
                $items[] = $row;
                $nextCursor = $day.':'.$offset;
            }
        }

        return $this->response($items, null);
    }

    private function reverseLines(string $path, ?int $end): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            if (is_file($path)) {
                throw new RuntimeException('Activity log could not be opened.');
            }
            return;
        }

        try {
            $size = fstat($handle)['size'];
            $position = min($end ?? $size, $size);
            $buffer = '';
            while ($position > 0) {
                $length = min($position, 8192);
                $position -= $length;
                if (fseek($handle, $position) !== 0 || ($chunk = fread($handle, $length)) === false) {
                    throw new RuntimeException('Activity log could not be read.');
                }
                $buffer = $chunk.$buffer;
                while (($newline = strrpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, $newline + 1);
                    $buffer = substr($buffer, 0, $newline);
                    if ($line !== '') {
                        yield $position + $newline + 1 => $line;
                    }
                }
            }
            if ($buffer !== '') {
                yield 0 => $buffer;
            }
        } finally {
            fclose($handle);
        }
    }

    private function row(string $line): ?array
    {
        $entry = json_decode($line, true);
        $context = $entry['context'] ?? [];
        if (!is_array($context) || !is_string($entry['datetime'] ?? null)
            || !is_string($context['request_id'] ?? null) || !Str::isUuid($context['request_id'])
            || !is_string($context['method'] ?? null) || !is_string($context['route'] ?? null)
            || !is_int($context['status'] ?? null)) {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($entry['datetime']);
        } catch (Exception) {
            return null;
        }

        $method = $context['method'];
        $operation = match ($entry['message'] ?? null) {
            'auth.login' => 'login',
            'auth.logout' => 'logout',
            'auth.refresh' => 'refresh',
            'api.request' => match ($method) {
                'GET', 'HEAD' => 'read',
                'POST' => 'post',
                'PUT', 'PATCH' => 'update',
                'DELETE' => 'delete',
                default => 'other',
            },
            default => null,
        };
        if ($operation === null) {
            return null;
        }

        $status = $context['status'];
        $logoutTokenMatched = $context['logout_token_matched'] ?? null;
        $result = match (true) {
            $status >= 400, $logoutTokenMatched === false => 'failure',
            $status >= 200 && $status < 300 => 'success',
            default => 'other',
        };

        return [
            'requestId' => $context['request_id'],
            'time' => $time,
            'actorId' => isset($context['actor_id']) ? (string) $context['actor_id'] : null,
            'actorType' => $context['actor_type'] ?? null,
            'operation' => $operation,
            'method' => $method,
            'route' => $context['route'],
            'entityId' => isset($context['entity_id']) ? (string) $context['entity_id'] : null,
            'status' => $status,
            'result' => $result,
            'deletedRows' => $context['deleted_rows'] ?? null,
            'logoutTokenMatched' => $logoutTokenMatched,
        ];
    }

    private function response(array $items, ?string $nextCursor): array
    {
        $ids = array_unique(array_filter(array_column($items, 'actorId')));
        $accounts = $ids === [] ? collect() : User::query()->whereIn('id', $ids)
            ->get(['id', 'name', 'username'])->keyBy('id');

        foreach ($items as &$item) {
            $account = $accounts->get($item['actorId']);
            $item['actorName'] = $account?->name ?: $account?->username;
            $item['time'] = $item['time']->utc()->toISOString();
        }

        return ['items' => $items, 'nextCursor' => $nextCursor];
    }
}
