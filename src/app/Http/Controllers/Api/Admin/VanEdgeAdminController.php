<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VanEdgeAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = DB::table('van_edges as e')
            ->selectRaw("
                e.id,
                e.src,
                e.dst,
                e.length_m,
                e.one_way,
                e.is_open,
                e.attrs,
                ST_AsGeoJSON(e.geom)::json as geom_geojson
            ");

        foreach (['src','dst'] as $k) {
            if ($request->filled($k)) $q->where("e.$k", (int)$request->query($k));
        }
        if ($request->filled('is_open')) {
            $q->where('e.is_open', (bool)((int)$request->query('is_open')));
        }

        // فیلتر floor از طریق join به nodes (چون خود edges floor ندارد) - nodes floor در DDL هست :contentReference[oaicite:8]{index=8}
        if ($request->filled('floor')) {
            $floor = (int)$request->query('floor');
            $q->join('van_nodes as ns', 'ns.id', '=', 'e.src')
              ->join('van_nodes as nd', 'nd.id', '=', 'e.dst')
              ->where('ns.floor', $floor)
              ->where('nd.floor', $floor);
        }

        return response()->json(
            $q->orderBy('e.id', 'desc')->paginate((int)$request->query('limit', 50))
        );
    }

    public function show(int $id)
    {
        $row = DB::table('van_edges as e')
            ->selectRaw("
                e.id,
                e.src,
                e.dst,
                e.length_m,
                e.one_way,
                e.is_open,
                e.attrs,
                ST_AsGeoJSON(e.geom)::json as geom_geojson
            ")
            ->where('e.id', $id)
            ->first();

        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json($row);
    }

    public function store(Request $request)
    {
        $data = $this->validateEdge($request);

        return DB::transaction(function () use ($data) {
            // اگر geom نیامده، از src/dst بساز
            $geomSql = $data['geom_sql'] ?? $this->geomFromNodesSql($data['src'], $data['dst']);

            $id = DB::table('van_edges')->insertGetId([
                'src' => $data['src'],
                'dst' => $data['dst'],
                'one_way' => $data['one_way'] ?? true,
                'is_open' => $data['is_open'] ?? true,
                'attrs' => json_encode($data['attrs'] ?? (object)[]),
                'geom' => DB::raw($geomSql),
                'length_m' => DB::raw("ST_Length(($geomSql))::numeric"),
            ]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validateEdge($request, true);

        return DB::transaction(function () use ($id, $data) {
            $exists = DB::table('van_edges')->where('id', $id)->exists();
            if (!$exists) return response()->json(['message' => 'Not found'], 404);

            $upd = [];

            if (isset($data['src'])) $upd['src'] = $data['src'];
            if (isset($data['dst'])) $upd['dst'] = $data['dst'];
            if (array_key_exists('one_way', $data)) $upd['one_way'] = (bool)$data['one_way'];
            if (array_key_exists('is_open', $data)) $upd['is_open'] = (bool)$data['is_open'];
            if (array_key_exists('attrs', $data)) $upd['attrs'] = json_encode($data['attrs'] ?? (object)[]);

            // geom اگر آمده:
            if (isset($data['geom_sql'])) {
                $upd['geom'] = DB::raw($data['geom_sql']);
                $upd['length_m'] = DB::raw("ST_Length((".$data['geom_sql']."))::numeric");
            } else {
                // اگر src/dst عوض شده ولی geom نیامده: geom را از nodes بازسازی کن
                if (isset($data['src']) || isset($data['dst'])) {
                    $edge = DB::table('van_edges')->where('id', $id)->first(['src','dst']);
                    $src = $data['src'] ?? $edge->src;
                    $dst = $data['dst'] ?? $edge->dst;

                    $geomSql = $this->geomFromNodesSql($src, $dst);
                    $upd['geom'] = DB::raw($geomSql);
                    $upd['length_m'] = DB::raw("ST_Length(($geomSql))::numeric");
                }
            }

            DB::table('van_edges')->where('id', $id)->update($upd);

            return response()->json(['id' => $id]);
        });
    }

    public function destroy(int $id)
    {
        $deleted = DB::table('van_edges')->where('id', $id)->delete();
        if (!$deleted) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['ok' => true]);
    }

    private function validateEdge(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'src' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:van_nodes,id'],
            'dst' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:van_nodes,id'],
            'one_way' => [$isUpdate ? 'sometimes' : 'nullable', 'boolean'],
            'is_open' => [$isUpdate ? 'sometimes' : 'nullable', 'boolean'],
            'attrs' => [$isUpdate ? 'sometimes' : 'nullable', 'array'],
            'geom' => [$isUpdate ? 'sometimes' : 'nullable', 'array'],
            'geom.type' => ['nullable', 'in:LineString'],
            'geom.srid' => ['nullable', 'integer'],
            'geom.coordinates' => ['nullable', 'array', 'min:2'],
        ];

        $v = $request->validate($rules);

        $out = $v;

        // تبدیل geom به SQL
        if (isset($v['geom']['coordinates']) && is_array($v['geom']['coordinates'])) {
            $srid = (int)($v['geom']['srid'] ?? 32640);
            $coords = $v['geom']['coordinates'];

            $pairs = [];
            foreach ($coords as $c) {
                if (!is_array($c) || count($c) < 2) continue;
                $pairs[] = sprintf("%F %F", (float)$c[0], (float)$c[1]);
            }
            if (count($pairs) >= 2) {
                $wkt = "LINESTRING(" . implode(",", $pairs) . ")";
                $out['geom_sql'] = "ST_SetSRID(ST_GeomFromText('{$wkt}'), {$srid})::geometry(LineString,{$srid})";
            }
        }

        return $out;
    }

    private function geomFromNodesSql(int $src, int $dst): string
    {
        // LineString از دو Point
        return "
            ST_MakeLine(
              (SELECT geom FROM van_nodes WHERE id = {$src}),
              (SELECT geom FROM van_nodes WHERE id = {$dst})
            )::geometry(LineString,32640)
        ";
    }
}
