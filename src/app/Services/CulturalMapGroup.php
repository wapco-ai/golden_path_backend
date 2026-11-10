<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CulturalMapGroup
{
    public static function joinAssignedMainGroup(Builder $points): Builder
    {
        // Read saved group-only assignments, including older rows with a stale category_leaf_id.
        return $points->leftJoin('categories as assigned_group', function (JoinClause $join) {
            $join->whereRaw("assigned_group.id::text = p.attrs #>> '{grouping,group_id}'")
                ->whereRaw("p.attrs #>> '{grouping,sub_group_id}' IS NULL")
                ->where('assigned_group.level', 1)
                ->where('assigned_group.is_active', true)
                ->whereIn('assigned_group.property_target', ['group', 'nodeFunction']);
        });
    }

    public static function codesByPointId(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return self::joinAssignedMainGroup(DB::table('poi_points as p'))
            ->whereIn('p.id', $ids)
            ->whereNotNull('assigned_group.id')
            ->pluck('assigned_group.code', 'p.id');
    }
}
