<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserProfileUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ProfileService;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsersController extends Controller
{
    use ApiError;

    public function __construct(protected ProfileService $profiles) {}

    /**
     * POST /users (after OTP)
     */
    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();

        // phone uniqueness
        if (User::where('mobile', $data['phone'])->exists()) {
            return $this->error('PHONE_EXISTS', 'شماره موبایل قبلاً ثبت شده است.', 409);
        }

        // email uniqueness فقط اگر email آمده
        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return $this->error('EMAIL_EXISTS', 'ایمیل قبلاً ثبت شده است.', 409);
        }

        $user = new User();
        $user->is_admin = false;
        $user->status = 'active';
        $user->mobile = $data['phone'];

        // fullName اگر نبود، مقدار پیشفرض
        $user->name = $data['fullName'] ?? 'کاربر';

        // email اگر نبود null
        $user->email = $data['email'] ?? null;
        $user->national_id = $data['nationalId'] ?? null;
        $user->referral_code = $data['referralCode'] ?? null;

        // password optional
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        } else {
            $user->password = null;
        }

        $user->save();

        $completed = $this->profiles->isProfileCompleted($user);

        return response()->json((new UserResource($user, $completed))->toArray($request), 201);
    }

    /**
     * GET /users/me
     */
    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) return $this->error('UNAUTHORIZED', 'UNAUTHORIZED', 401);

        $completed = $this->profiles->isProfileCompleted($user);
        return response()->json((new UserResource($user, $completed))->toArray($request));
    }

    /**
     * PUT/PATCH /users/me/profile  OR /users/me
     */
    public function updateProfile(UserProfileUpdateRequest $request)
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user) return $this->error('UNAUTHORIZED', 'UNAUTHORIZED', 401);

        $data = $request->validated();

        // email uniqueness (exclude self)
        if (array_key_exists('email', $data) && $data['email']) {
            $exists = User::where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($exists) return $this->error('EMAIL_EXISTS', 'ایمیل قبلاً ثبت شده است.', 409);
        }

        if (array_key_exists('fullName', $data)) $user->name = $data['fullName'];
        if (array_key_exists('email', $data)) $user->email = $data['email'] ?? $user->email;
        if (array_key_exists('gender', $data)) $user->gender = $data['gender'];
        if (array_key_exists('birthDate', $data)) $user->birth_date = $data['birthDate'];
        if (array_key_exists('nationalId', $data)) $user->national_id = $data['nationalId'];

        if (array_key_exists('address', $data)) $user->address = $data['address'] ?? [];
        if (array_key_exists('preferences', $data)) $user->preferences = $data['preferences'] ?? [];
        if (array_key_exists('avatarUrl', $data)) $user->avatar_url = $data['avatarUrl'];

        $user->save();

        $completed = $this->profiles->isProfileCompleted($user);

        // اگر کامل نبود => طبق نیازمندی code PROFILE_INCOMPLETE هم می‌تونید برگردونید
        // ولی شما گفتید پاسخ user + profileCompleted true/false؛ همین را رعایت می‌کنیم.
        return response()->json((new UserResource($user, $completed))->toArray($request));
    }

    // ---------------- Optional avatar (اگر FileController موجودتان را ترجیح می‌دهید می‌تونیم این را منتقل کنیم)
    public function uploadAvatar(Request $request)
    {
        // اینجا اگر سرویس فایل پروژه (FileController) استاندارد دارد، بهتر است از همان استفاده شود.
        // فعلاً یک پیاده‌سازی ساده:
        $request->validate(['file' => 'required|file|image|max:2048']);

        $path = $request->file('file')->store('public/avatars');
        $url = str_replace('public/', '/storage/', $path);

        $user = $request->user();
        $user->avatar_url = $url;
        $user->save();

        return response()->json(['url' => $url]);
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();
        $user->avatar_url = null;
        $user->save();

        return response()->json(['message' => 'AVATAR_DELETED']);
    }
}
