<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;

class KoutharProxyController extends Controller
{
    /**
     * دریافت دیتا از سرویس کوثر و برگرداندن آن به فرانت.
     *
     * GET /api/kouthar/{date}
     */
    public function fetch(Request $request, string $date)
    {
        // اگر خواستی ولیدیشن دقیق‌تر کنی، می‌تونی از Validator استفاده کنی
        // فعلا یک چک ساده:
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date)) {
            return response()->json([
                'ok'      => false,
                'message' => 'فرمت تاریخ نامعتبر است. فرمت صحیح: 1404-08-08',
            ], 422);
        }

        $baseUrl = config('services.kouthar.base_url');

        // آدرس نهایی مقصد
        $targetUrl = rtrim($baseUrl, '/') . '/' . $date;

        try {
            // درخواست سمت سرور → CORS اینجا مطرح نیست
            $response = Http::timeout(10)
                // اگر نیاز به هدر خاص یا توکن بود اینجا اضافه کن
                // ->withHeaders(['Authorization' => 'Bearer ...'])
                ->get($targetUrl);

            // اگر وضعیت غیر 2xx بود، همونو پاس بده
            if ($response->failed()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'خطا در ارتباط با سرویس کوثر',
                    'status'  => $response->status(),
                    'error'   => $response->json() ?? $response->body(),
                ], 502); // Bad Gateway از دید ما
            }

            // محتوای پاسخِ سرویس
            $body = $response->body();
            $contentType = $response->header('Content-Type', 'application/json; charset=utf-8');

            // اگر می‌دونی همیشه JSON هست، می‌تونی مستقیماً json() رو برگردونی:
            // return response()->json($response->json(), 200);

            // در غیر این صورت، همون بدنه و content-type اصلی رو پاس می‌دیم:
            return response($body, 200)->header('Content-Type', $contentType);

        } catch (\Throwable $e) {
            // خطای شبکه، timeout، DNS و ...
            return response()->json([
                'ok'      => false,
                'message' => 'سرویس کوثر در دسترس نیست یا خطای شبکه رخ داده است.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
