<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SameFloorStairRequest;
use App\Services\SameFloorStairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SameFloorStairController extends Controller
{
    public function __construct(private SameFloorStairService $stairs) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['floor' => 'required|integer|exists:routing_floors,floor']);

        return response()->json($this->stairs->index((int) $input['floor']));
    }

    public function candidates(Request $request): JsonResponse
    {
        $input = $request->validate([
            'floor' => 'required|integer|exists:routing_floors,floor',
            'start_access_id' => 'required|integer|min:1',
            'end_access_id' => 'required|integer|min:1|different:start_access_id',
            'start_area_id' => 'required|integer|min:1',
            'end_area_id' => 'required|integer|min:1',
        ]);

        return response()->json(['areas' => $this->stairs->candidates($input)]);
    }

    public function store(SameFloorStairRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->stairs->save($request->validated())], 201);
    }

    public function update(SameFloorStairRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->stairs->save($request->validated(), $id)]);
    }
}
