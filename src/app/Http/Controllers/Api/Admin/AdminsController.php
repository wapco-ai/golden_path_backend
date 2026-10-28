<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminIndexRequest;
use App\Http\Requests\Admin\AdminStoreRequest;
use App\Http\Requests\Admin\AdminUpdateRequest;
use App\Http\Resources\Admin\AdminCollection;
use App\Http\Resources\Admin\AdminResource;
use App\Services\Admin\AdminService;
use App\Services\Admin\AdminAccess;

class AdminsController extends Controller
{
    public function __construct(private readonly AdminService $service) {}

    /**
     * GET /api/v1/admin/admins
     */
    public function index(AdminIndexRequest $request)
    {
        $page = (int)($request->query('page', 1));
        $pageSize = (int)($request->query('pageSize', 5));
        $search = $request->query('search');

        $paginator = $this->service->paginate($page, $pageSize, is_string($search) ? $search : null);
        return (new AdminCollection($paginator))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/v1/admin/admins/{id}
     */
    public function show(int $id)
    {
        $admin = $this->service->getOne($id);
        return response()->json((new AdminResource($admin))->toArray(request()));
    }

    /**
     * POST /api/v1/admin/admins
     */
    public function store(AdminStoreRequest $request)
    {
        $data = $request->validated();
        abort_unless(AdminAccess::mayManage($request->user(), null, $data['roles']), 403, 'Cannot grant roles outside your own access');

        $admin = $this->service->create(
            $data['firstName'],
            $data['lastName'],
            $data['username'],
            $data['roles'],
            $data['email'] ?? null,
            $data['password'] ?? null
        );

        return response()->json((new AdminResource($admin))->toArray($request), 201);
    }

    /**
     * PATCH /api/v1/admin/admins/{id}
     */
    public function update(AdminUpdateRequest $request, int $id)
    {
        $data = $request->validated();
        $target = $this->service->getOne($id);
        abort_unless(AdminAccess::mayManage($request->user(), $target, $data['roles'] ?? []), 403, 'Cannot manage this account');

        $admin = $this->service->updateAdmin($id, $data);

        // ✅ برگرداندن خروجی کامل برای sync شدن فرانت
        return response()->json((new AdminResource($admin))->toArray($request), 200);
    }


    /**
     * DELETE /api/v1/admin/admins/{id}
     */
    public function destroy(\Illuminate\Http\Request $request, int $id)
    {
        $target = $this->service->getOne($id);
        abort_unless(AdminAccess::mayManage($request->user(), $target), 403, 'Cannot manage this account');
        $this->service->deleteAdmin($id);

        return response()->json(['success' => true], 200);
    }
}
