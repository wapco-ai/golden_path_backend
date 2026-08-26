<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategoryAdminService
{
    public function __construct(
        private readonly I18nTextService $i18n,
    ) {}

    /**
     * List top-level categories with pagination and optional search (fa title/desc).
     *
     * @return array{items: array<int, array>, page:int, pageSize:int, total:int}
     */
    public function list(int $page, int $pageSize, ?string $search, bool $includeSubcategories = true): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $search = $search !== null ? trim($search) : null;

        // Base top-level categories
        $base = DB::table('categories as c')
            ->whereNull('c.parent_id');

        if ($search !== null && $search !== '') {
            // Search on fa name/desc
            $base->whereExists(function ($q) use ($search) {
                $q->select(DB::raw(1))
                    ->from('i18n_texts as t')
                    ->whereColumn('t.entity_id', 'c.id')
                    ->where('t.entity_table', 'categories')
                    ->where('t.lang', 'fa')
                    ->whereIn('t.field', ['name', 'desc'])
                    ->where('t.txt', 'ilike', '%' . $search . '%');
            });
        }

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->orderBy('c.sort_order')
            ->orderBy('c.id')
            ->forPage($page, $pageSize)
            ->get([
                'c.id', 'c.code', 'c.property_target', 'c.icon',
                'c.parent_id', 'c.level', 'c.sort_order', 'c.is_active',
            ]);

        $ids = $rows->pluck('id')->all();
        $i18n = $this->bulkI18nForCategories($ids);
        $children = $includeSubcategories ? $this->bulkChildren($ids) : [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->mapCategoryRow($r, $i18n, $children[$r->id] ?? []);
        }

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => (int)$total,
        ];
    }

    public function getSubcategories(int $categoryId): array
    {
        $parent = DB::table('categories')->where('id', $categoryId)->first();
        if (!$parent) {
            abort(404, 'Category not found');
        }

        $rows = DB::table('categories as c')
            ->where('c.parent_id', $categoryId)
            ->orderBy('c.sort_order')
            ->orderBy('c.id')
            ->get(['c.id', 'c.icon', 'c.parent_id', 'c.is_active']);

        $ids = $rows->pluck('id')->all();
        $i18n = $this->bulkI18nForCategories($ids);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->mapSubcategoryRow($r, $i18n);
        }
        return $out;
    }

    /**
     * Create a category (and optional subcategories) with i18n texts.
     */
    public function create(array $payload, ?UploadedFile $image = null): array
    {
        return DB::transaction(function () use ($payload, $image) {
            $code = $payload['code'] ?? null;
            $code = is_string($code) ? trim($code) : '';
            if ($code === '') {
                $code = 'cat_' . bin2hex(random_bytes(4));
            }

            $propertyTarget = $payload['property_target'] ?? null;
            $propertyTarget = is_string($propertyTarget) ? trim($propertyTarget) : '';
            if ($propertyTarget === '') {
                $propertyTarget = 'group';
            }

            $isActive = ($payload['status'] ?? 'active') === 'inactive' ? false : true;

            $id = DB::table('categories')->insertGetId([
                'code' => $code,
                'label_key' => $code, // legacy field; not used by admin UI
                'property_target' => $propertyTarget,
                'icon' => null,
                'parent_id' => null,
                'level' => 1,
                'sort_order' => (int)($payload['sortOrder'] ?? 0),
                'is_active' => $isActive,
            ]);

            // Image upload (store into same public disk convention)
            if ($image) {
                $path = $this->storeCategoryImage($id, $image);
                DB::table('categories')->where('id', $id)->update(['icon' => $path]);
            } elseif (!empty($payload['icon'])) {
                DB::table('categories')->where('id', $id)->update(['icon' => (string)$payload['icon']]);
            } elseif (!empty($payload['image']) && is_string($payload['image'])) {
                // Frontend sometimes sends selected icon filename in "image" (e.g. "Pictogram34.png")
                // Treat it as icon key/path (NOT an uploaded file).
                DB::table('categories')->where('id', $id)->update(['icon' => trim((string)$payload['image'])]);
            }

            // i18n upserts
            $titleFa = (string)($payload['title'] ?? '');
            $descFa = (string)($payload['description'] ?? '');
            $langs = $payload['languageTitles'] ?? [];

            $this->i18n->upsertLangMap('categories', $id, 'name', [
                'fa' => $titleFa,
                'en' => (string)($langs['english'] ?? ''),
                'ar' => (string)($langs['arabic'] ?? ''),
                'ur' => (string)($langs['urdu'] ?? ''),
            ]);
            $this->i18n->upsertLangMap('categories', $id, 'desc', [
                'fa' => $descFa,
            ]);

            // Subcategories
            $subcats = $payload['subcategories'] ?? [];
            if (is_array($subcats) && count($subcats) > 0) {
                $i = 0;
                foreach ($subcats as $sc) {
                    if (!is_array($sc)) continue;
                    $scTitleFa = trim((string)($sc['title'] ?? ''));
                    if ($scTitleFa === '') continue;

                    $scCode = $sc['code'] ?? null;
                    $scCode = is_string($scCode) ? trim($scCode) : '';
                    if ($scCode === '') {
                        $scCode = $code . '_sub_' . ($i + 1) . '_' . bin2hex(random_bytes(2));
                    }

                    $scId = DB::table('categories')->insertGetId([
                        'code' => $scCode,
                        'label_key' => $scCode,
                        'property_target' => $propertyTarget,
                        'icon' => null,
                        'parent_id' => $id,
                        'level' => 2,
                        'sort_order' => (int)($sc['sortOrder'] ?? ($i + 1)),
                        'is_active' => true,
                    ]);

                    $this->i18n->upsertLangMap('categories', $scId, 'name', [
                        'fa' => $scTitleFa,
                    ]);
                    $i++;
                }
            }

            return $this->getOne($id, true);
        });
    }

    public function update(int $id, array $payload, ?UploadedFile $image = null): array
    {
        return DB::transaction(function () use ($id, $payload, $image) {
            $row = DB::table('categories')->where('id', $id)->first();
            if (!$row) abort(404, 'Category not found');
            if ($row->parent_id !== null) abort(400, 'Not a top-level category');

            $upd = [];
            if (array_key_exists('status', $payload)) {
                $upd['is_active'] = ($payload['status'] ?? 'active') === 'inactive' ? false : true;
            }
            if (array_key_exists('sortOrder', $payload)) {
                $upd['sort_order'] = (int)($payload['sortOrder'] ?? 0);
            }
            if (array_key_exists('code', $payload) && is_string($payload['code']) && trim($payload['code']) !== '') {
                $upd['code'] = trim($payload['code']);
                $upd['label_key'] = trim($payload['code']);
            }
            // if (array_key_exists('propertyTarget', $payload) && is_string($payload['propertyTarget']) && trim($payload['propertyTarget']) !== '') {
            // FIX: broken condition in uploaded file (had "..." which breaks PHP)
            if (array_key_exists('property_target', $payload)
                && is_string($payload['property_target'])
                && trim($payload['property_target']) !== '') {
                $upd['property_target'] = trim($payload['property_target']);
            }

            if ($image) {
                $path = $this->storeCategoryImage($id, $image);
                $upd['icon'] = $path;
            } elseif (array_key_exists('icon', $payload)) {
                // allow clearing icon by sending null
                $upd['icon'] = $payload['icon'] === null ? null : (string)$payload['icon'];
            } elseif (array_key_exists('image', $payload) && $payload['image'] !== null) {
                // Frontend icon picker sends filename in "image"
                $val = is_string($payload['image']) ? trim($payload['image']) : '';
                if ($val !== '') $upd['icon'] = $val;
            }
            if (!empty($upd)) {
                DB::table('categories')->where('id', $id)->update($upd);
            }

            // i18n updates
            if (array_key_exists('title', $payload) || array_key_exists('languageTitles', $payload)) {
                $langs = is_array($payload['languageTitles'] ?? null) ? $payload['languageTitles'] : [];
                $this->i18n->upsertLangMap('categories', $id, 'name', array_filter([
                    'fa' => array_key_exists('title', $payload) ? (string)$payload['title'] : null,
                    'en' => array_key_exists('english', $langs) ? (string)($langs['english'] ?? '') : null,
                    'ar' => array_key_exists('arabic', $langs) ? (string)($langs['arabic'] ?? '') : null,
                    'ur' => array_key_exists('urdu', $langs) ? (string)($langs['urdu'] ?? '') : null,
                ], fn($v) => $v !== null));
            }
            if (array_key_exists('description', $payload)) {
                $this->i18n->upsertLangMap('categories', $id, 'desc', ['fa' => (string)$payload['description']]);
            }

            return $this->getOne($id, true);
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('categories')->where('id', $id)->first();
            if (!$row) abort(404, 'Category not found');
            if ($row->parent_id !== null) abort(400, 'Not a top-level category');

            $hasChildren = DB::table('categories')->where('parent_id', $id)->exists();
            if ($hasChildren) {
                abort(409, 'Category has subcategories');
            }

            if (DB::getSchemaBuilder()->hasTable('feature_group_mappings')) {
                $inUse = DB::table('feature_group_mappings')->where('category_leaf_id', $id)->exists();
                if ($inUse) {
                    abort(409, 'Category is used in feature mappings');
                }
            }

            DB::table('i18n_texts')->where('entity_table', 'categories')->where('entity_id', $id)->delete();
            DB::table('categories')->where('id', $id)->delete();
        });
    }

    public function createSubcategory(int $parentId, array $payload): array
    {
        return DB::transaction(function () use ($parentId, $payload) {
            $parent = DB::table('categories')->where('id', $parentId)->first();
            if (!$parent) abort(404, 'Category not found');
            if ($parent->parent_id !== null) abort(400, 'Parent must be a top-level category');

            $titleFa = trim((string)($payload['title'] ?? ''));
            if ($titleFa === '') abort(422, 'Subcategory title is required');

            $code = $payload['code'] ?? null;
            $code = is_string($code) ? trim($code) : '';
            if ($code === '') {
                $code = $parent->code . '_sub_' . bin2hex(random_bytes(3));
            }

            $isActive = ($payload['status'] ?? 'active') === 'inactive' ? false : true;

            $id = DB::table('categories')->insertGetId([
                'code' => $code,
                'label_key' => $code,
                'property_target' => 'subGroup',
                'icon' => null,
                'parent_id' => $parentId,
                'level' => (int)($parent->level ?? 1) + 1,
                'sort_order' => (int)($payload['sortOrder'] ?? 0),
                'is_active' => $isActive,
            ]);

            $this->i18n->upsertLangMap('categories', $id, 'name', ['fa' => $titleFa]);

            $i18n = $this->bulkI18nForCategories([$id]);
            $row = DB::table('categories as c')->where('c.id', $id)->first(['c.id','c.icon','c.parent_id','c.is_active']);
            return $this->mapSubcategoryRow($row, $i18n);
        });
    }

    public function updateSubcategory(int $id, array $payload): array
    {
        return DB::transaction(function () use ($id, $payload) {
            $row = DB::table('categories')->where('id', $id)->first();
            if (!$row) abort(404, 'Subcategory not found');
            if ($row->parent_id === null) abort(400, 'Not a subcategory');

            $upd = [];
            if (array_key_exists('status', $payload)) {
                $upd['is_active'] = ($payload['status'] ?? 'active') === 'inactive' ? false : true;
            }
            if (array_key_exists('sortOrder', $payload)) {
                $upd['sort_order'] = (int)($payload['sortOrder'] ?? 0);
            }
            if (array_key_exists('code', $payload) && is_string($payload['code']) && trim($payload['code']) !== '') {
                $upd['code'] = trim($payload['code']);
                $upd['label_key'] = trim($payload['code']);
            }
            if (!empty($upd)) {
                DB::table('categories')->where('id', $id)->update($upd);
            }

            if (array_key_exists('title', $payload)) {
                $titleFa = trim((string)($payload['title'] ?? ''));
                if ($titleFa === '') abort(422, 'Subcategory title is required');
                $this->i18n->upsertLangMap('categories', $id, 'name', ['fa' => $titleFa]);
            }

            $i18n = $this->bulkI18nForCategories([$id]);
            $row2 = DB::table('categories as c')->where('c.id', $id)->first(['c.id','c.icon','c.parent_id','c.is_active']);
            return $this->mapSubcategoryRow($row2, $i18n);
        });
    }

    public function deleteSubcategory(int $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('categories')->where('id', $id)->first();
            if (!$row) abort(404, 'Subcategory not found');
            if ($row->parent_id === null) abort(400, 'Not a subcategory');

            if (DB::getSchemaBuilder()->hasTable('feature_group_mappings')) {
                $inUse = DB::table('feature_group_mappings')->where('category_leaf_id', $id)->exists();
                if ($inUse) abort(409, 'Subcategory is used in feature mappings');
            }

            DB::table('i18n_texts')->where('entity_table', 'categories')->where('entity_id', $id)->delete();
            DB::table('categories')->where('id', $id)->delete();
        });
    }

    // -------------------------
    // Internal helpers
    // -------------------------

    private function getOne(int $id, bool $includeSubcategories): array
    {
        $row = DB::table('categories as c')->where('c.id', $id)->first([
            'c.id', 'c.code', 'c.property_target', 'c.icon',
            'c.parent_id', 'c.level', 'c.sort_order', 'c.is_active',
        ]);
        if (!$row) abort(404, 'Category not found');
        $i18n = $this->bulkI18nForCategories([$id]);
        $children = $includeSubcategories ? $this->bulkChildren([$id])[$id] ?? [] : [];
        return $this->mapCategoryRow($row, $i18n, $children);
    }

    private function storeCategoryImage(int $categoryId, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $folder = "uploads/categories/{$categoryId}/images";
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid()->toString() . '.' . $ext;
        return $file->storeAs($folder, $filename, 'public');
    }

    private function bulkI18nForCategories(array $ids): array
    {
        if (empty($ids)) return [];
        $rows = DB::table('i18n_texts')
            ->where('entity_table', 'categories')
            ->whereIn('entity_id', $ids)
            ->whereIn('field', ['name', 'desc'])
            ->get(['entity_id', 'field', 'lang', 'txt']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->entity_id][$r->field][$r->lang] = $r->txt ?? '';
        }
        return $out;
    }

    /**
     * @return array<int, array<int, array>> map parent_id => subcategory[]
     */
    private function bulkChildren(array $parentIds): array
    {
        if (empty($parentIds)) return [];
        $rows = DB::table('categories as c')
            ->whereIn('c.parent_id', $parentIds)
            ->orderBy('c.sort_order')
            ->orderBy('c.id')
            ->get(['c.id', 'c.icon', 'c.parent_id', 'c.is_active']);

        $ids = $rows->pluck('id')->all();
        $i18n = $this->bulkI18nForCategories($ids);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->parent_id][] = $this->mapSubcategoryRow($r, $i18n);
        }
        return $out;
    }

    private function mapCategoryRow(object $r, array $i18n, array $subcategories): array
    {
        $name = $i18n[$r->id]['name'] ?? [];
        $desc = $i18n[$r->id]['desc']['fa'] ?? '';

        $imageUrl = $this->toPublicUrl($r->icon);

        return [
            'id' => (int)$r->id,
            'title' => (string)($name['fa'] ?? ''),
            'description' => (string)$desc,
            'image' => $imageUrl,
            'createdAt' => null, // categories table currently has no timestamps
            'status' => ($r->is_active ? 'active' : 'inactive'),
            'numSubcategories' => count($subcategories),
            'languageTitles' => [
                'english' => (string)($name['en'] ?? ''),
                'arabic' => (string)($name['ar'] ?? ''),
                'urdu' => (string)($name['ur'] ?? ''),
            ],
            'subcategories' => $subcategories,
            // (optional) helpful for debugging/admin UI
            'meta' => [
                'code' => (string)$r->code,
                'propertyTarget' => (string)$r->property_target,
                'sortOrder' => (int)($r->sort_order ?? 0),
                'iconRaw' => $r->icon,
            ],
        ];
    }

    private function mapSubcategoryRow(object $r, array $i18n): array
    {
        $name = $i18n[$r->id]['name'] ?? [];
        return [
            'id' => (int)$r->id,
            'title' => (string)($name['fa'] ?? ''),
            'parentId' => (int)$r->parent_id,
            'createdAt' => null,
            'status' => ($r->is_active ? 'active' : 'inactive'),
        ];
    }

    private function toPublicUrl($icon): ?string
    {
        if ($icon === null) return null;
        $icon = trim((string)$icon);
        if ($icon === '') return null;

        // If it's already a URL, return as-is
        if (preg_match('#^https?://#i', $icon)) return $icon;

        // If it's a stored public path (uploads/...), return disk url
        if (str_starts_with($icon, 'uploads/')) {
            return Storage::disk('public')->url($icon);
        }

        // Otherwise treat it as an icon key (UI may choose to render internally)
        return null;
    }
}
