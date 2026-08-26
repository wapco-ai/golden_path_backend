<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * POST /api/v1/files
     * multipart/form-data:
     *  - file (required)
     *  - entity_table (required)  e.g. poi_points | contents | areas | doors
     *  - entity_id (required)     int
     *  - bucket (optional)        e.g. images | files | audio | cover | gallery
     *  - keep_original_name (optional boolean)
     *
     * Response:
     *  {
     *    "path": "uploads/poi_points/798/images/uuid.jpg",
     *    "url": "http://host/storage/uploads/poi_points/798/images/uuid.jpg",
     *    "original_name": "...",
     *    "mime": "image/jpeg",
     *    "size": 12345
     *  }
     */
    public function upload(Request $request)
    {
        $request->merge([
            'keep_original_name' => filter_var($request->input('keep_original_name', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $data = $request->validate([
            'file'               => 'required|file|max:51200', // 50MB
            'entity_table'       => 'required|string|max:64',
            'entity_id'          => 'required|integer|min:1',
            'bucket'             => 'nullable|string|max:64',
            'keep_original_name' => 'nullable|boolean',
        ]);

        $disk = Storage::disk('public');

        $entityTable = $this->sanitizeSegment($data['entity_table']);
        $entityId    = (string)$data['entity_id'];
        $bucket      = $this->sanitizeSegment($data['bucket'] ?? 'files');

        // ✅ Whitelist امنیتی (حتماً نگه دار)
        $allowedTables = ['poi_points', 'contents', 'areas', 'doors', 'categories', 'guidance_points'];
        if (!in_array($entityTable, $allowedTables, true)) {
            return response()->json([
                'message' => 'Invalid entity_table',
                'errors'  => ['entity_table' => ['entity_table is not allowed.']],
            ], 422);
        }

        // مسیر استاندارد
        // uploads/{entity_table}/{entity_id}/{bucket}/...
        $folder = "uploads/{$entityTable}/{$entityId}/{$bucket}";

        $file = $data['file'];
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'bin');

        $keepOriginal = (bool)($data['keep_original_name'] ?? false);

        if ($keepOriginal) {
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $base = $this->sanitizeFilename($base);
            $filename = $base . '.' . $ext;

            $i = 1;
            while ($disk->exists("{$folder}/{$filename}")) {
                $filename = $base . '-' . $i . '.' . $ext;
                $i++;
            }
        } else {
            $filename = Str::uuid()->toString() . '.' . $ext;
        }

        $path = $file->storeAs($folder, $filename, 'public');
        $url = $disk->url($path);

        $attached = $this->attachPoiPointImageIfNeeded(
            $entityTable,
            (int) $entityId,
            $bucket,
            $url,
            $path
        );

        return response()->json([
            'success'       => true,
            'data'          => [
                'id'            => $attached['id'],
                'path'          => $path,
                'url'           => $url,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
                'entity_table'  => $entityTable,
                'entity_id'     => (int)$entityId,
                'bucket'        => $bucket,
                'attached'      => $attached['attached'],
            ],
            // legacy flat response: keep old frontend/backend contracts working
            'id'            => $attached['id'],
            'path'          => $path,
            'url'           => $url,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'entity_table'  => $entityTable,
            'entity_id'     => (int)$entityId,
            'bucket'        => $bucket,
            'attached'      => $attached['attached'],
        ], 201);
    }

    /**
     * GET /api/v1/files?path=uploads/...
     * Query:
     *  - path (required) e.g. uploads/poi_points/798/images/xxx.jpg
     *  - as (optional) download filename
     *  - inline (optional boolean) true => inline
     */
    public function download(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'path'   => 'required|string',
            'as'     => 'nullable|string|max:200',
            'inline' => 'nullable|boolean',
        ]);

        $disk = Storage::disk('public');
        $path = $this->normalizePath($data['path']);

        if (!$disk->exists($path)) {
            abort(404, 'File not found');
        }

        $downloadName = $data['as'] ?? basename($path);
        $downloadName = $this->sanitizeDownloadName($downloadName);

        $headers = [];
        $mime = $disk->mimeType($path);
        if ($mime) $headers['Content-Type'] = $mime;

        $inline = (bool)($data['inline'] ?? false);
        $headers['Content-Disposition'] =
            ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"';

        return response()->streamDownload(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream === false) {
                abort(500, 'Unable to read file stream');
            }
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $downloadName, $headers);
    }

    /**
     * DELETE /api/v1/files?path=uploads/...
     */
    public function delete(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string',
        ]);

        $disk = Storage::disk('public');
        $path = $this->normalizePath($data['path']);

        $fileDeleted = false;
        $dbDeleted = false;
        $pointId = null;

        DB::transaction(function () use ($disk, $path, &$fileDeleted, &$dbDeleted, &$pointId) {
            $image = DB::table('guidance_point_images')
                ->where('image_key', $path)
                ->first();

            if ($image) {
                $pointId = (int) $image->point_id;

                DB::table('guidance_point_images')
                    ->where('id', $image->id)
                    ->delete();

                $dbDeleted = true;
            }

            if ($disk->exists($path)) {
                $fileDeleted = (bool) $disk->delete($path);
            }

            if ($pointId) {
                $nextImage = DB::table('guidance_point_images')
                    ->where('point_id', $pointId)
                    ->orderBy('sort_order')
                    ->first();

                DB::table('guidance_points')
                    ->where('id', $pointId)
                    ->update([
                        'primary_image_url' => $nextImage?->image_url,
                        'updated_at' => now(),
                    ]);

                $remaining = DB::table('guidance_point_images')
                    ->where('point_id', $pointId)
                    ->orderBy('sort_order')
                    ->get(['id']);

                $sort = 1;
                foreach ($remaining as $row) {
                    DB::table('guidance_point_images')
                        ->where('id', $row->id)
                        ->update([
                            'sort_order' => $sort++,
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        if (!$fileDeleted && !$dbDeleted) {
            return response()->json([
                'success' => false,
                'deleted' => false,
                'file_deleted' => false,
                'db_deleted' => false,
                'path' => $path,
                'message' => 'File and database image row not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'deleted' => true,
            'file_deleted' => $fileDeleted,
            'db_deleted' => $dbDeleted,
            'path' => $path,
            'point_id' => $pointId,
        ]);
    }

    /**
     * GET /api/v1/files/meta?path=uploads/...
     */
    public function meta(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string',
        ]);

        $disk = Storage::disk('public');
        $path = $this->normalizePath($data['path']);

        if (!$disk->exists($path)) {
            abort(404, 'File not found');
        }

        return response()->json([
            'path'          => $path,
            'url'           => $disk->url($path),
            'size'          => $disk->size($path),
            'mime'          => $disk->mimeType($path),
            'last_modified' => $disk->lastModified($path),
        ]);
    }

    // -----------------------
    // Helpers (security)
    // -----------------------


    /**
     * The admin guidance-points frontend uploads point images through the generic
     * /files endpoint using entity_table=poi_points, entity_id=<guidance_points.id>, bucket=images.
     * Store that upload in guidance_point_images as well, otherwise the guidance-points
     * list/show responses cannot display the attached files.
     *
     * @return array{id:?int, attached:bool}
     */
    private function attachPoiPointImageIfNeeded(string $entityTable, int $entityId, string $bucket, string $url, string $path): array
    {
        if ($entityTable !== 'poi_points' || $bucket !== 'images') {
            return ['id' => null, 'attached' => false];
        }

        $exists = DB::table('guidance_points')
            ->where('id', $entityId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            return ['id' => null, 'attached' => false];
        }

        $already = DB::table('guidance_point_images')
            ->where('point_id', $entityId)
            ->where('image_key', $path)
            ->value('id');

        if ($already) {
            return ['id' => (int) $already, 'attached' => true];
        }

        $nextSort = (int) DB::table('guidance_point_images')
            ->where('point_id', $entityId)
            ->max('sort_order') + 1;

        if ($nextSort > 4) {
            // The file itself was uploaded successfully, but this entity already has 4 gallery images.
            return ['id' => null, 'attached' => false];
        }

        $id = (int) DB::table('guidance_point_images')->insertGetId([
            'point_id' => $entityId,
            'image_url' => $url,
            'image_key' => $path,
            'sort_order' => $nextSort,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guidance_points')
            ->where('id', $entityId)
            ->whereNull('primary_image_url')
            ->update([
                'primary_image_url' => $url,
                'updated_at' => now(),
            ]);

        return ['id' => $id, 'attached' => true];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        // اگر URL کامل بود، فقط قسمت بعد از /storage/ را بردار
        if (preg_match('#^https?://#i', $path)) {
            $pos = stripos($path, '/storage/');
            if ($pos !== false) {
                $path = substr($path, $pos + strlen('/storage/'));
            }
        }
        $path = str_replace(['\\'], '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim($path, '/');

        if (str_contains($path, '..')) {
            abort(400, 'Invalid path');
        }

        // فقط داخل uploads مجاز است
        if (!str_starts_with($path, 'uploads/')) {
            abort(400, 'Invalid path scope');
        }

        // حداقل باید این فرم را داشته باشد:
        // uploads/{entity_table}/{entity_id}/...
        $parts = explode('/', $path);
        if (count($parts) < 4) {
            abort(400, 'Invalid path format');
        }

        // entity_table فقط کاراکترهای امن
        $entityTable = $parts[1] ?? '';
        if (!preg_match('/^[a-z0-9_\-]+$/i', $entityTable)) {
            abort(400, 'Invalid entity_table in path');
        }

        // entity_id عددی
        $entityId = $parts[2] ?? '';
        if (!preg_match('/^\d+$/', $entityId)) {
            abort(400, 'Invalid entity_id in path');
        }

        // (اختیاری) whitelist اینجا هم می‌تونی enforce کنی
        $allowedTables = ['poi_points', 'contents', 'areas', 'doors', 'categories', 'guidance_points'];
        if (!in_array(strtolower($entityTable), $allowedTables, true)) {
            abort(400, 'Invalid entity_table scope');
        }

        return $path;
    }

    private function sanitizeSegment(string $s): string
    {
        $s = trim($s);
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9_\-]+/i', '_', $s);
        $s = trim($s, '_');
        return $s ?: 'general';
    }

    private function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^\pL\pN\-_ ]/u', '', $name);
        $name = preg_replace('/\s+/', '-', $name);
        $name = mb_substr($name, 0, 120);
        return $name ?: 'file';
    }

    private function sanitizeDownloadName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['"', "\r", "\n"], '', $name);
        $name = mb_substr($name, 0, 180);
        return $name ?: 'download.bin';
    }
}
