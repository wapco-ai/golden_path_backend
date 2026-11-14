<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CulturalMapGroup
{
    public static function joinAssignedCategories(Builder $points): Builder
    {
        return $points
            ->leftJoin('categories as assigned_group', function (JoinClause $join) {
                $join->whereRaw("assigned_group.id::text = p.attrs #>> '{grouping,group_id}'")
                    ->where('assigned_group.level', 1)
                    ->where('assigned_group.is_active', true)
                    ->whereIn('assigned_group.property_target', ['group', 'nodeFunction']);
            })
            ->leftJoin('categories as assigned_sub_group', function (JoinClause $join) {
                $join->on('assigned_sub_group.parent_id', '=', 'assigned_group.id')
                    ->whereRaw("assigned_sub_group.id::text = p.attrs #>> '{grouping,sub_group_id}'")
                    ->where('assigned_sub_group.level', 2)
                    ->where('assigned_sub_group.is_active', true);
            });
    }

    public static function assignedLeafExpression(): string
    {
        return "CASE WHEN p.attrs #>> '{grouping,sub_group_id}' IS NULL
            THEN assigned_group.id ELSE assigned_sub_group.id END";
    }

    public static function propertiesByPointId(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return self::joinAssignedCategories(DB::table('poi_points as p'))
            ->whereIn('p.id', $ids)
            ->whereNotNull(DB::raw(self::assignedLeafExpression()))
            ->select('p.id', 'assigned_group.code as group_code', 'assigned_sub_group.code as sub_group_code')
            ->get()
            ->mapWithKeys(fn ($point) => [
                $point->id => [
                    'group' => $point->group_code,
                    'subGroup' => $point->sub_group_code,
                    'subGroupValue' => 'poi-'.$point->id,
                ],
            ]);
    }
}
