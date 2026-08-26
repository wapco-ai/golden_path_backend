<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryStoreRequest;
use App\Http\Requests\Admin\CategoryUpdateRequest;
use App\Services\CategoryAdminService;
use Illuminate\Http\Request;

class CategoryAdminController extends Controller
{
    public function __construct(private readonly CategoryAdminService $svc) {}

    /**
     * GET /api/v1/admin/categories
     */
    public function index(Request $request)
    {
        $page = (int)($request->query('page', 1));
        $pageSize = (int)($request->query('pageSize', 7));
        $search = $request->query('search');
        $includeSubcategories = filter_var($request->query('includeSubcategories', '1'), FILTER_VALIDATE_BOOLEAN);

        return response()->json(
            $this->svc->list($page, $pageSize, is_string($search) ? $search : null, $includeSubcategories)
        );
    }

    /**
     * POST /api/v1/admin/categories
     * Supports:
     *  - application/json
     *  - multipart/form-data (image + payload JSON)
     */
    public function store(CategoryStoreRequest $request)
    {
        $payload = $this->extractPayload($request);
        $image = $request->file('image');
        $created = $this->svc->create($payload, $image);
        return response()->json($created, 201);
    }

    /**
     * PUT /api/v1/admin/categories/{id}
     */
    public function update(int $id, CategoryUpdateRequest $request)
    {
        $payload = $this->extractPayload($request);
        $image = $request->file('image');
        return response()->json($this->svc->update($id, $payload, $image));
    }

    /**
     * DELETE /api/v1/admin/categories/{id}
     */
    public function destroy(int $id)
    {
        $this->svc->delete($id);
        return response()->json(null, 204);
    }

    /**
     * GET /api/v1/admin/categories/{id}/subcategories
     */
    public function subcategories(int $id)
    {
        return response()->json([
            'items' => $this->svc->getSubcategories($id),
        ]);
    }

    /**
     * POST /api/v1/admin/categories/{id}/subcategories
     */
    public function storeSubcategory(int $id, Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'status' => 'sometimes|in:active,inactive',
            'code' => 'sometimes|nullable|string|max:64',
            'sortOrder' => 'sometimes|integer|min:0|max:1000000',
        ]);
        return response()->json($this->svc->createSubcategory($id, $data), 201);
    }

    /**
     * PUT /api/v1/admin/subcategories/{id}
     */
    public function updateSubcategory(int $id, Request $request)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|in:active,inactive',
            'code' => 'sometimes|nullable|string|max:64',
            'sortOrder' => 'sometimes|integer|min:0|max:1000000',
        ]);
        return response()->json($this->svc->updateSubcategory($id, $data));
    }

    /**
     * DELETE /api/v1/admin/subcategories/{id}
     */
    public function destroySubcategory(int $id)
    {
        $this->svc->deleteSubcategory($id);
        return response()->json(null, 204);
    }

    // ---------------------
    // Helpers
    // ---------------------

    private function extractPayload(Request $request): array
    {
        // When multipart is used, UI sends payload as JSON in `payload`
        $payloadStr = $request->input('payload');
        if (is_string($payloadStr) && trim($payloadStr) !== '') {
            $decoded = json_decode($payloadStr, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Otherwise take JSON body as-is
        return $request->all();
    }
}
