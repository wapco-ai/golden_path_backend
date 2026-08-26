<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SignedUserIndexRequest;
use App\Http\Requests\Admin\SignedUserStatusRequest;
use App\Http\Resources\Admin\SignedUserCollection;
use App\Http\Resources\Admin\SignedUserResource;
use App\Services\Admin\SignedUserService;

class SignedUsersController extends Controller
{
    public function __construct(private readonly SignedUserService $svc) {}

    /**
     * GET /api/v1/admin/users
     */
    public function index(SignedUserIndexRequest $request)
    {
        $page = (int)($request->query('page', 1));
        $pageSize = (int)($request->query('pageSize', 20));
        $search = $request->query('search');

        $paginator = $this->svc->paginate($page, $pageSize, is_string($search) ? $search : null);

        return response()->json((new SignedUserCollection($paginator))->toArray($request));
    }

    /**
     * GET /api/v1/admin/users/{id}
     */
    public function show(int $id)
    {
        $user = $this->svc->getOne($id);
        return response()->json(new SignedUserResource($user));
    }

    /**
     * PATCH /api/v1/admin/users/{id}/status
     * Body: {"status": "active"|"inactive"}
     */
    public function updateStatus(int $id, SignedUserStatusRequest $request)
    {
        $status = (string)($request->validated()['status'] ?? '');
        $user = $this->svc->updateStatus($id, $status);

        return response()->json(new SignedUserResource($user));
    }
}
