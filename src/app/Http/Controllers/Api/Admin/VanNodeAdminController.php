<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VanNodeAdminController extends Controller
{
    public function index(Request $request)
    {
        $lang = $request->query('language', 'fa');
        $q = DB::table('van_nodes as v')
            ->selectRaw("
                v.id,
                v.floor,
                v.node_type::text as node_type,
                ST_X(v.geom) as x,
                ST_Y(v.geom) as y,
                v.updated_at,
                fn_i18n_label('van_nodes', v.id, 'name',  ?::lang_enum, 'fa'::lang_enum) as name,
                fn_i18n_label('van_nodes', v.id, 'desc',  ?::lang_enum, 'fa'::lang_enum) as description
            ", [$lang, $lang]);

        if ($request->filled('floor')) {
            $q->where('v.floor', (int)$request->query('floor'));
        }
        if ($request->filled('node_type')) {
            $q->where('v.node_type', $request->query('node_type'));
        }

        $rows = $q->orderBy('v.id', 'desc')->paginate((int)$request->query('limit', 50));

        return response()->json($rows);
    }

    public function show(Request $request, int $id)
    {
        $lang = $request->query('language', 'fa');

        $row = DB::table('van_nodes as v')
            ->selectRaw("
                v.id,
                v.floor,
                v.node_type::text as node_type,
                ST_X(v.geom) as x,
                ST_Y(v.geom) as y,
                v.updated_at,
                fn_i18n_label('van_nodes', v.id, 'name',  ?::lang_enum, 'fa'::lang_enum) as name,
                fn_i18n_label('van_nodes', v.id, 'desc',  ?::lang_enum, 'fa'::lang_enum) as description
            ", [$lang, $lang])
            ->where('v.id', $id)
            ->first();

        if (!$row) return response()->json(['message' => 'Not found'], 404);

        // همه زبان‌ها را هم برگردان (برای فرم ادمین)
        $i18n = DB::table('i18n_texts')
            ->select('field', 'lang', 'txt')
            ->where('entity_table', 'van_nodes')
            ->where('entity_id', $id)
            ->whereIn('field', ['name', 'desc'])
            ->get()
            ->groupBy('field')
            ->map(function ($items) {
                return $items->pluck('txt', 'lang');
            });

        return response()->json([
            'id' => $row->id,
            'floor' => $row->floor,
            'node_type' => $row->node_type,
            'geom' => ['x' => (float)$row->x, 'y' => (float)$row->y],
            'basic_info' => [
                'title' => [
                    'fa' => $i18n['name']['fa'] ?? ($row->name ?? ''),
                    'en' => $i18n['name']['en'] ?? '',
                    'ar' => $i18n['name']['ar'] ?? '',
                    'ur' => $i18n['name']['ur'] ?? '',
                ],
                'description' => [
                    'fa' => $i18n['desc']['fa'] ?? ($row->description ?? ''),
                    'en' => $i18n['desc']['en'] ?? '',
                    'ar' => $i18n['desc']['ar'] ?? '',
                    'ur' => $i18n['desc']['ur'] ?? '',
                ],
            ],
            'updated_at' => $row->updated_at,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateNode($request);

        return DB::transaction(function () use ($data) {
            $id = DB::table('van_nodes')->insertGetId([
                'geom' => DB::raw(sprintf("ST_SetSRID(ST_MakePoint(%F,%F),32640)", $data['x'], $data['y'])),
                'node_type' => $data['node_type'],
                'floor' => $data['floor'],
                'updated_at' => now(),
            ]);

            $this->upsertNodeI18n($id, $data['basic_info'] ?? null);

            return response()->json(['id' => $id], 201);
        });
    }

    public function update(Request $request, int $id)
    {
        $data = $this->validateNode($request, true);

        return DB::transaction(function () use ($id, $data) {
            $exists = DB::table('van_nodes')->where('id', $id)->exists();
            if (!$exists) return response()->json(['message' => 'Not found'], 404);

            $upd = ['updated_at' => now()];

            if (isset($data['x'], $data['y'])) {
                $upd['geom'] = DB::raw(sprintf("ST_SetSRID(ST_MakePoint(%F,%F),32640)", $data['x'], $data['y']));
            }
            if (isset($data['node_type'])) $upd['node_type'] = $data['node_type'];
            if (isset($data['floor'])) $upd['floor'] = $data['floor'];

            DB::table('van_nodes')->where('id', $id)->update($upd);

            if (array_key_exists('basic_info', $data)) {
                $this->upsertNodeI18n($id, $data['basic_info']);
            }

            return response()->json(['id' => $id]);
        });
    }

    public function destroy(int $id)
    {
        return DB::transaction(function () use ($id) {
            // اول edges را حذف کن چون FK دارد :contentReference[oaicite:7]{index=7}
            DB::table('van_edges')->where('src', $id)->orWhere('dst', $id)->delete();

            DB::table('i18n_texts')
                ->where('entity_table', 'van_nodes')
                ->where('entity_id', $id)
                ->delete();

            $deleted = DB::table('van_nodes')->where('id', $id)->delete();

            if (!$deleted) return response()->json(['message' => 'Not found'], 404);

            return response()->json(['ok' => true]);
        });
    }

    private function validateNode(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'floor' => [$isUpdate ? 'sometimes' : 'required', 'integer'],
            'node_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['stop', 'junction'])],
            'geom' => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'geom.x' => [$isUpdate ? 'sometimes' : 'required', 'numeric'],
            'geom.y' => [$isUpdate ? 'sometimes' : 'required', 'numeric'],

            'basic_info' => [$isUpdate ? 'sometimes' : 'nullable', 'array'],
            'basic_info.title' => ['nullable', 'array'],
            'basic_info.description' => ['nullable', 'array'],
        ];

        $v = $request->validate($rules);

        $out = [];
        if (isset($v['floor'])) $out['floor'] = (int)$v['floor'];
        if (isset($v['node_type'])) $out['node_type'] = $v['node_type'];

        if (isset($v['geom']['x'], $v['geom']['y'])) {
            $out['x'] = (float)$v['geom']['x'];
            $out['y'] = (float)$v['geom']['y'];
        }

        if (array_key_exists('basic_info', $v)) {
            $out['basic_info'] = $v['basic_info'];
        }

        return $out;
    }

    private function upsertNodeI18n(int $nodeId, ?array $basicInfo): void
    {
        if (!$basicInfo) return;

        $title = $basicInfo['title'] ?? [];
        $desc  = $basicInfo['description'] ?? [];

        $langs = ['fa', 'en', 'ar', 'ur'];

        foreach ($langs as $lang) {
            if (array_key_exists($lang, $title)) {
                $this->upsertI18nRow('van_nodes', $nodeId, 'name', $lang, (string)$title[$lang]);
            }
            if (array_key_exists($lang, $desc)) {
                $this->upsertI18nRow('van_nodes', $nodeId, 'desc', $lang, (string)$desc[$lang]);
            }
        }
    }

    private function upsertI18nRow(string $table, int $id, string $field, string $lang, string $txt): void
    {
        // اگر txt خالی است: حذف کن (فرم‌ها معمولاً خالی می‌فرستند)
        if (trim($txt) === '') {
            DB::table('i18n_texts')
                ->where('entity_table', $table)
                ->where('entity_id', $id)
                ->where('field', $field)
                ->where('lang', $lang)
                ->delete();
            return;
        }

        DB::table('i18n_texts')->updateOrInsert(
            [
                'entity_table' => $table,
                'entity_id'    => $id,
                'field'        => $field,
                'lang'         => $lang,
            ],
            [
                'txt'          => $txt,
            ]
        );
    }
}
