<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class I18nTextService
{
    /**
     * Fetch texts for an entity as {lang: txt} for a given field.
     */
    public function getLangMap(string $entityTable, int $entityId, string $field): array
    {
        $rows = DB::table('i18n_texts')
            ->select('lang', 'txt')
            ->where('entity_table', $entityTable)
            ->where('entity_id', $entityId)
            ->where('field', $field)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->lang] = $r->txt ?? '';
        }
        return $out;
    }

    /**
     * Upsert texts for an entity field. $texts = ['fa'=>'...', 'en'=>'...']
     */
    public function upsertLangMap(string $entityTable, int $entityId, string $field, array $texts): void
    {
        foreach (['fa','en','ar','ur'] as $lang) {
            if (!array_key_exists($lang, $texts)) continue;

            DB::table('i18n_texts')->updateOrInsert(
                [
                    'entity_table' => $entityTable,
                    'entity_id'    => $entityId,
                    'field'        => $field,
                    'lang'         => $lang,
                ],
                [
                    'txt'          => $texts[$lang] ?? '',
                    // 'updated_at'   => now(),
                    // 'created_at'   => now(),
                ]
            );
        }
    }

    public function empty4(): array
    {
        return ['fa'=>'', 'en'=>'', 'ar'=>'', 'ur'=>''];
    }
}
