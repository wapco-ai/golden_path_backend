<?php

namespace App\Services;

class PageI18nMapper
{
    public function __construct(private I18nTextService $i18n)
    {
    }

    /**
     * Return lang map for a field in i18n_texts and ensure 4 langs exist.
     */
    public function get4(string $entityTable, int $entityId, string $field): array
    {
        $m = $this->i18n->empty4();
        $rows = $this->i18n->getLangMap($entityTable, $entityId, $field);
        foreach (['fa','en','ar','ur'] as $lang) {
            if (array_key_exists($lang, $rows)) {
                $m[$lang] = $rows[$lang] ?? '';
            }
        }
        return $m;
    }

    /**
     * Upsert i18n_texts with keys: fa/en/ar/ur.
     */
    public function upsert4(string $entityTable, int $entityId, string $field, array $texts): void
    {
        $payload = [
            'fa' => $texts['fa'] ?? '',
            'en' => $texts['en'] ?? '',
            'ar' => $texts['ar'] ?? '',
            'ur' => $texts['ur'] ?? '',
        ];

        $this->i18n->upsertLangMap($entityTable, $entityId, $field, $payload);
    }

    /**
     * Resolve one language (for public endpoints).
     */
    public function resolveOne(string $entityTable, int $entityId, string $field, string $lang, string $fallbackFa = ''): string
    {
        $lang = in_array($lang, ['fa','en','ar','ur'], true) ? $lang : 'fa';

        if ($lang === 'fa') {
            return $fallbackFa;
        }

        $map = $this->i18n->getLangMap($entityTable, $entityId, $field);
        $v = $map[$lang] ?? '';
        return $v !== '' ? $v : $fallbackFa;
    }
}
