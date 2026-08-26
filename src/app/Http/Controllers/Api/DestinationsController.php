<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DestinationsController extends Controller
{
    /**
     * GET /api/v1/destinations
     * Query:
     *  - page (default 1)
     *  - page_size|perPage (default 20, max 100)
     *  - source=poi|area|manual (optional)
     *  - tag=<string> (optional)
     *  - q=<search> (optional; title/address)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $page = max((int) $request->query('page', 1), 1);
        $pageSize = (int) $request->query('page_size', $request->query('perPage', 20));
        $pageSize = (int) min(max($pageSize, 1), 100);

        $q = trim((string) $request->query('q', ''));
        $source = $request->query('source');
        $tag = $request->query('tag');

        $query = Destination::query()->where('user_id', $user->id);

        if ($source) $query->where('source', $source);
        if ($tag) $query->whereJsonContains('tags', $tag);

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('title', 'ilike', "%{$q}%")
                   ->orWhere('address', 'ilike', "%{$q}%");
            });
        }

        $p = $query->orderByDesc('updated_at')
            ->paginate($pageSize, ['*'], 'page', $page);

        return response()->json([
            'items' => $p->getCollection()->map(fn (Destination $d) => $this->toDto($d)),
            'pagination' => [
                'page' => $p->currentPage(),
                'page_size' => $p->perPage(),
                'total' => $p->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/destinations
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'x'           => 'required|numeric',
            'y'           => 'required|numeric',
            'floor'       => 'nullable|integer',
            'source'      => 'required|in:poi,area,manual',
            'source_id'   => 'nullable|string|max:64',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:32',
            'address'     => 'nullable|string|max:512',
            'metadata'    => 'nullable|array',
        ]);

        $d = Destination::create([
            'user_id'     => $user->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'x'           => (float) $data['x'],
            'y'           => (float) $data['y'],
            'floor'       => $data['floor'] ?? null,
            'source'      => $data['source'],
            'source_id'   => $data['source_id'] ?? null,
            'tags'        => $data['tags'] ?? [],
            'address'     => $data['address'] ?? null,
            'metadata'    => $data['metadata'] ?? [],
        ]);

        // Optional: store geom if column exists
        $this->tryUpdateGeom($d);

        return response()->json([
            'destination' => $this->toDto($d->fresh()),
        ], 201);
    }

    /**
     * GET /api/v1/destinations/{id}
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();

        $d = Destination::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$d) return response()->json(['message' => 'Not Found'], 404);

        return response()->json(['destination' => $this->toDto($d)]);
    }

    /**
     * PUT /api/v1/destinations/{id}
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();

        $d = Destination::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$d) return response()->json(['message' => 'Not Found'], 404);

        $data = $request->validate([
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:32',
            'address'     => 'nullable|string|max:512',
            'metadata'    => 'nullable|array',
        ]);

        $d->fill($data);
        $d->save();

        return response()->json(['destination' => $this->toDto($d->fresh())]);
    }

    /**
     * DELETE /api/v1/destinations/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();

        $d = Destination::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$d) return response()->json(['message' => 'Not Found'], 404);

        $d->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /api/v1/destinations/suggestions
     * Returns: { recent: [...], popular: [...] }
     */
    public function suggestions(Request $request)
    {
        $user = $request->user();

        $recent = Destination::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Destination $d) => [
                'id'        => $d->id,
                'title'     => $d->title,
                'address'   => $d->address,
                'floor'     => $d->floor,
                'source'    => $d->source,
                'source_id' => $d->source_id,
                'x'         => $d->x,
                'y'         => $d->y,
            ]);

        // فعلاً بهترین-تلاش: اگر منبع محبوبیت ندارید، خالی برگردانید و فرانت از recent استفاده کند.
        $popular = [];

        return response()->json([
            'recent'  => $recent,
            'popular' => $popular,
        ]);
    }

    private function toDto(Destination $d): array
    {
        return [
            'id'          => $d->id,
            'title'       => $d->title,
            'description' => $d->description,
            'x'           => $d->x,
            'y'           => $d->y,
            'floor'       => $d->floor,
            'source'      => $d->source,
            'source_id'   => $d->source_id,
            'tags'        => $d->tags ?? [],
            'address'     => $d->address,
            'metadata'    => $d->metadata ?? [],
            'created_at'  => $d->created_at?->toIso8601String(),
            'updated_at'  => $d->updated_at?->toIso8601String(),
        ];
    }

    private function tryUpdateGeom(Destination $d): void
    {
        try {
            DB::table('destinations')
                ->where('id', $d->id)
                ->update([
                    'geom' => DB::raw("ST_SetSRID(ST_MakePoint({$d->x}, {$d->y}), 32640)"),
                ]);
        } catch (\Throwable $e) {
            // geom/postgis not available -> ignore
        }
    }
}
