<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectorRequest;
use App\Services\ConnectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectorController extends Controller
{
    public function __construct(private ConnectorService $connectors) {}

    public function index()
    {
        return response()->json(DB::table('routing_connectors')->select('id', 'kind', 'version', 'is_active')
            ->selectRaw("info #>> '{basic_info,title,fa}' AS title")->orderBy('id')->get());
    }

    public function show(int $id) { return response()->json($this->connectors->show($id)); }
    public function store(ConnectorRequest $request) { return response()->json($this->connectors->save($request->validated()), 201); }
    public function update(ConnectorRequest $request, int $id) { return response()->json($this->connectors->save($request->validated(), $id)); }

    public function candidates(Request $request)
    {
        $data = $request->validate([
            'floor' => 'required|integer|exists:routing_floors,floor',
            'access_id' => 'nullable|integer', 'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);
        $areas = $this->connectors->candidates($data);
        return response()->json(['areas' => $areas, 'area_id' => count($areas) === 1 ? $areas[0]->id : null]);
    }

    public function floors() { return response()->json(DB::table('routing_floors')->orderBy('sort_order')->get()); }
}
