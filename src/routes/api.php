<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LanguageController;
use App\Http\Controllers\Api\MapGeojsonController;
use App\Http\Controllers\Api\QrLocationController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\KoutharProxyController;
use App\Http\Controllers\Api\LandmarkController;
use App\Http\Controllers\Api\CategoryMetadataController;
use App\Http\Controllers\Api\AreaDoorsController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AreaAdminController;
use App\Http\Middleware\AdminAuth;
use App\Http\Controllers\Api\CulturalItemsController;
use App\Http\Controllers\Api\DoorCrudController;
use App\Http\Controllers\Api\Admin\DoorBulkController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\Admin\TempBlockAreaController;
use App\Http\Controllers\Api\Admin\VanNodeAdminController;
use App\Http\Controllers\Api\Admin\VanEdgeAdminController;
use App\Http\Controllers\Api\Admin\CategoryAdminController;
use App\Http\Controllers\Api\Admin\SignedUsersController;
use App\Http\Controllers\Api\SupportFeedbackController;
use App\Http\Controllers\Api\Admin\SupportFeedbackController as AdminSupportFeedbackController;
use App\Http\Controllers\Api\Admin\CommentAdminController;
use App\Http\Controllers\Api\Admin\AdminsController;
use App\Http\Controllers\Api\Admin\GuidancePointController;
use App\Http\Controllers\Api\GuidancePointsPublicController;

use App\Http\Controllers\Api\Admin\PagesAdminController;
use App\Http\Controllers\Api\Admin\FaqAdminController;
use App\Http\Controllers\Api\PagesController;

use App\Http\Controllers\Api\DestinationsController;


use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Middleware\UserAuth;

use App\Http\Controllers\Api\Admin\DashboardController;

use App\Http\Controllers\Api\Admin\UserLogsController;







Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    Route::prefix('admin/auth')->group(function () {

        Route::post('login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:5,1'); // محدودیت تلاش لاگین

        Route::post('refresh', [AdminAuthController::class, 'refresh']);

        // مسیرهایی که نیاز به لاگین ادمین دارند
        Route::middleware(AdminAuth::class)->group(function () {
            Route::get('me', [AdminAuthController::class, 'me']);
            Route::post('logout', [AdminAuthController::class, 'logout']);
        });
    });



    // Public Auth (after OTP)
    Route::prefix('auth')->group(function () {
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('logout',  [AuthController::class, 'logout'])->middleware(UserAuth::class);
        Route::get('me',       [AuthController::class, 'me'])->middleware(UserAuth::class);
    });

    Route::prefix('auth/otp')->group(function () {
        Route::post('request', [\App\Http\Controllers\Api\OtpAuthController::class, 'requestOtp']);
        Route::post('verify',  [\App\Http\Controllers\Api\OtpAuthController::class, 'verifyOtp']);
    });

    // Users
    Route::post('users', [UsersController::class, 'store']); // create user after OTP

    Route::middleware(UserAuth::class)->group(function () {
        Route::get('users/me',        [UsersController::class, 'me']);
        Route::match(['put', 'patch'], 'users/me/profile', [UsersController::class, 'updateProfile']);
        Route::match(['put', 'patch'], 'users/me',         [UsersController::class, 'updateProfile']);

        // optional avatar
        Route::post('files/avatar',   [UsersController::class, 'uploadAvatar']);
        Route::delete('files/avatar', [UsersController::class, 'deleteAvatar']);

        // Saved destinations (FS)
        Route::get('destinations/suggestions', [DestinationsController::class, 'suggestions']);
        Route::get('destinations',             [DestinationsController::class, 'index']);
        Route::post('destinations',            [DestinationsController::class, 'store']);
        Route::get('destinations/{id}',        [DestinationsController::class, 'show']);
        Route::put('destinations/{id}',        [DestinationsController::class, 'update']);
        Route::delete('destinations/{id}',     [DestinationsController::class, 'destroy']);
    });

    // ------------------------------------------------------------------
    // Admin-only APIs (must be protected)
    // ------------------------------------------------------------------
    Route::middleware(AdminAuth::class)->group(function () {
        // Van CRUD
        Route::prefix('admin/van')->group(function () {
            Route::get('/nodes',        [VanNodeAdminController::class, 'index']);
            Route::get('/nodes/{id}',   [VanNodeAdminController::class, 'show']);
            Route::post('/nodes',       [VanNodeAdminController::class, 'store']);
            Route::put('/nodes/{id}',   [VanNodeAdminController::class, 'update']);
            Route::delete('/nodes/{id}', [VanNodeAdminController::class, 'destroy']);

            Route::get('/edges',        [VanEdgeAdminController::class, 'index']);
            Route::get('/edges/{id}',   [VanEdgeAdminController::class, 'show']);
            Route::post('/edges',       [VanEdgeAdminController::class, 'store']);
            Route::put('/edges/{id}',   [VanEdgeAdminController::class, 'update']);
            Route::delete('/edges/{id}', [VanEdgeAdminController::class, 'destroy']);
        });

        // Temp-block areas CRUD
        Route::prefix('admin')->group(function () {
            Route::get('/temp-block-areas',            [TempBlockAreaController::class, 'index']);
            Route::get('/temp-block-areas/active',     [TempBlockAreaController::class, 'active']);
            Route::get('/temp-block-areas/{id}',       [TempBlockAreaController::class, 'show']);
            Route::post('/temp-block-areas',           [TempBlockAreaController::class, 'store']);
            Route::put('/temp-block-areas/{id}',       [TempBlockAreaController::class, 'update']);
            Route::delete('/temp-block-areas/{id}',    [TempBlockAreaController::class, 'destroy']);

            Route::patch('/temp-block-areas/{id}/stop',   [TempBlockAreaController::class, 'stop']);
            Route::patch('/temp-block-areas/{id}/extend', [TempBlockAreaController::class, 'extend']);

            Route::get('/support-feedbacks', [AdminSupportFeedbackController::class, 'index']);
            Route::patch('/support-feedbacks/{id}', [AdminSupportFeedbackController::class, 'updateStatus']);


            Route::get('comments', [CommentAdminController::class, 'index']);
            Route::get('comments/{id}', [CommentAdminController::class, 'show']);
            Route::patch('comments/{id}/status', [CommentAdminController::class, 'updateStatus']);
            Route::delete('comments/{id}', [CommentAdminController::class, 'destroy']);
        });


        Route::prefix('admin/pages')->group(function () {
            Route::get('{type}', [PagesAdminController::class, 'show'])
                ->where('type', 'support|rules|about|contact');

            Route::put('{type}', [PagesAdminController::class, 'update'])
                ->where('type', 'support|rules|about|contact');

            Route::get('faq', [FaqAdminController::class, 'index']);
            Route::post('faq', [FaqAdminController::class, 'store']);
            Route::put('faq/{id}', [FaqAdminController::class, 'update']);
            Route::delete('faq/{id}', [FaqAdminController::class, 'destroy']);
        });
        // Signed-up users (admin)
        // Admins CRUD (admin)
        Route::get('admin/admins',              [AdminsController::class, 'index']);
        Route::get('admin/admins/{id}',         [AdminsController::class, 'show']);
        Route::post('admin/admins',             [AdminsController::class, 'store']);
        Route::patch('admin/admins/{id}',       [AdminsController::class, 'update']);
        Route::delete('admin/admins/{id}',      [AdminsController::class, 'destroy']);

        Route::get('admin/users',                 [SignedUsersController::class, 'index']);
        Route::get('admin/users/{id}',            [SignedUsersController::class, 'show']);
        Route::patch('admin/users/{id}/status',   [SignedUsersController::class, 'updateStatus']);


        // Areas CRUD (admin)
        Route::get('/areas',        [AreaAdminController::class, 'index']);
        Route::get('/areas/{id}',   [AreaAdminController::class, 'show']);
        Route::post('/areas',       [AreaAdminController::class, 'store']);
        Route::put('/areas/{id}',   [AreaAdminController::class, 'update']);
        Route::delete('/areas/{id}', [AreaAdminController::class, 'destroy']);

        // Doors CRUD (admin)
        Route::post('/doors',        [DoorCrudController::class, 'store']);
        Route::get('/doors/{id}',    [DoorCrudController::class, 'show']);
        Route::put('/doors/{id}',    [DoorCrudController::class, 'update']);
        Route::delete('/doors/{id}', [DoorCrudController::class, 'destroy']);
        Route::put('/doors/{id}/move', [DoorCrudController::class, 'move']);
        Route::get('/doors/{id}/info', [DoorCrudController::class, 'getInfo']);
        Route::put('/doors/{id}/info', [DoorCrudController::class, 'saveInfo']);
        Route::get('/doors/{id}/graph-status', [DoorCrudController::class, 'graphStatus']);

        // Doors bulk open/close (admin)
        Route::patch('/doors/bulk-open', [DoorBulkController::class, 'bulkSetOpen']);

        // Cultural items (admin / CMS)
        Route::get('cultural-items',          [CulturalItemsController::class, 'index']);
        Route::get('cultural-items/{id}',     [CulturalItemsController::class, 'show']);
        Route::post('cultural-items',         [CulturalItemsController::class, 'store']);
        Route::put('cultural-items/{id}',     [CulturalItemsController::class, 'update']);
        Route::delete('cultural-items/{id}',  [CulturalItemsController::class, 'destroy']);
        Route::get('cultural-items/export',   [CulturalItemsController::class, 'export']);

        // Categories & subcategories (admin)
        Route::get('admin/categories',                      [CategoryAdminController::class, 'index']);
        Route::post('admin/categories',                     [CategoryAdminController::class, 'store']);
        Route::put('admin/categories/{id}',                 [CategoryAdminController::class, 'update']);
        Route::delete('admin/categories/{id}',              [CategoryAdminController::class, 'destroy']);
        Route::get('admin/categories/{id}/subcategories',   [CategoryAdminController::class, 'subcategories']);
        Route::post('admin/categories/{id}/subcategories',  [CategoryAdminController::class, 'storeSubcategory']);
        Route::put('admin/subcategories/{id}',              [CategoryAdminController::class, 'updateSubcategory']);
        Route::delete('admin/subcategories/{id}',           [CategoryAdminController::class, 'destroySubcategory']);


        Route::prefix('admin/dashboard')->group(function () {
            Route::get('summary',        [DashboardController::class, 'summary']);
            Route::get('user-visits',    [DashboardController::class, 'userVisits']);
            Route::get('comment-stats',  [DashboardController::class, 'commentStats']);
            Route::get('notifications',  [DashboardController::class, 'notifications']);
            Route::get('recent-users',   [DashboardController::class, 'recentUsers']);
        });

        // User Logs (admin)
        Route::get('admin/user-logs',        [UserLogsController::class, 'index']);
        Route::get('admin/user-logs/export', [UserLogsController::class, 'export']);

        // Guidance points CRUD (admin) - independent from routes
        Route::get('admin/guidance-points', [GuidancePointController::class, 'index']);
        Route::get('admin/guidance-points/{id}', [GuidancePointController::class, 'show'])->whereNumber('id');
        Route::post('admin/guidance-points', [GuidancePointController::class, 'store']);
        Route::match(['put', 'patch'], 'admin/guidance-points/{id}', [GuidancePointController::class, 'update'])->whereNumber('id');
        // Multipart FormData compatibility: clients may POST with _method=PATCH when PHP/SAPI does not parse multipart PATCH bodies.
        Route::post('admin/guidance-points/{id}', [GuidancePointController::class, 'update'])->whereNumber('id');
        Route::delete('admin/guidance-points/{id}', [GuidancePointController::class, 'destroy'])->whereNumber('id');
    });
    // NOTE: admin/van and admin/* routes were moved under AdminAuth middleware above.

    Route::get('/languages', [LanguageController::class, 'index']);
    Route::get('/maps/geojson', [MapGeojsonController::class, 'index']);
    Route::get('/qrcodes/{code}', [QrLocationController::class, 'show']);
    Route::get('/kouthar/{date}', [KoutharProxyController::class, 'fetch'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}'); // مثلا 1404-08-08

    Route::get('/landmark-places', [LandmarkController::class, 'landmarkPlaces']);
    Route::get('/landmark-view-image', [LandmarkController::class, 'viewImage']);

    Route::get('/groups/metadata', [CategoryMetadataController::class, 'groupsMetadata']);
    Route::get('/groups/subgroups', [CategoryMetadataController::class, 'subGroups']);
    Route::get('area-doors', [AreaDoorsController::class, 'byPoint']);
    // لیست نظرات تأیید شده
    Route::get('/feedbacks', [FeedbackController::class, 'index']);
    // خلاصه‌ی امتیاز (ratingAvg, ratingCount, ...)
    Route::get('/feedbacks/summary', [FeedbackController::class, 'summary']);
    Route::post('/feedbacks', [FeedbackController::class, 'store']);

    // Public guidance points for route instructions; no route FK by design
    Route::get('/guidance-points', [GuidancePointsPublicController::class, 'index']);


    // User endpoints
    Route::post('/support/feedback', [SupportFeedbackController::class, 'store']);
    Route::get('/support/feedbacks/me', [SupportFeedbackController::class, 'myList']); // اگر می‌خوای فقط لاگین: زیر middleware auth


    // NOTE: CulturalItems CRUD and Doors/Areas CRUD are admin-only and moved under AdminAuth middleware above.

    // Route::post('/uploads', [UploadController::class, 'store']);


    Route::post('/files',            [FileController::class, 'upload']);   // Upload
    Route::get('/files',             [FileController::class, 'download']); // Download by path (query)
    Route::delete('/files',          [FileController::class, 'delete']);   // Delete by path (query)

    // اختیاری: اطلاعات فایل
    Route::get('/files/meta',        [FileController::class, 'meta']);     // Meta by path (query)


    Route::prefix('pages')->group(function () {
        Route::get('{type}', [PagesController::class, 'show'])
            ->where('type', 'support|rules|about|contact');

        Route::get('faq', [PagesController::class, 'faq']);
    });
});

Route::prefix('v1/routing')->group(function () {
    Route::post('/route', [RoutingController::class, 'route']);
});

// ثبت/ویرایش نظر توسط کاربر لاگین کرده
// Route::middleware('auth:sanctum')->group(function () {
// Route::post('/v1/feedbacks', [FeedbackController::class, 'store']);
// });

// ----------------------
// Admin feedback routes
// ----------------------

// می‌تونی به جای can:manage-feedbacks از middleware خودت استفاده کنی
// Admin feedback routes (use Admin JWT session)
Route::middleware([AdminAuth::class])->prefix('/v1/admin')->group(function () {
    Route::get('/feedbacks', [AdminFeedbackController::class, 'index']);
    Route::patch('/feedbacks/{id}', [AdminFeedbackController::class, 'updateStatus']);
});

// // ALIAS: برای سازگاری با فرانت که از /user-feedback استفاده می‌کند
// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/v1/user-feedback', [FeedbackController::class, 'store']);
// });


// routes/api.php
