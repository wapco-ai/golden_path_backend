<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // 1) اعتبارسنجی
        $data = $request->validate([
            'file'      => 'required|file|max:10240', // حداکثر 10MB - هرطور خواستی تغییر بده
            'context'   => 'nullable|string',         // مثلاً: landmark, content, ...
            'entity_id' => 'nullable|integer',        // مثلاً poi_id یا content_id
        ]);

        $file = $data['file'];

        // 2) ساخت مسیر ذخیره‌سازی
        $context   = $data['context']   ?? 'general';
        $entityId  = $data['entity_id'] ?? 'common';

        // مثال مسیر: uploads/landmarks/123/uuid.ext
        $folder = "uploads/{$context}/{$entityId}";

        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();

        // 3) ذخیره روی disk 'public'
        $path = $file->storeAs($folder, $filename, 'public');
        // مثال مقدار $path:
        // uploads/landmarks/123/550e8400-e29b-41d4-a716-446655440000.jpg

        // 4) ساخت URL عمومی
        $url = Storage::disk('public')->url($path);
        // مثال URL:
        // http://localhost:8080/storage/uploads/landmarks/123/...

        return response()->json([
            'path' => $path,
            'url'  => $url,
        ]);
    }
}
