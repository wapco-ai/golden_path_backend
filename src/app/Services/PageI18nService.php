<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageFaq;

class PageI18nService
{
    public function __construct(private readonly I18nTextService $i18n)
    {
    }

    /**
     * Page description translations keyed for UI.
     * Keys: englishDescription, arabicDescription, urduDescription
     */
    public function pageDescriptionTranslations(Page $page): array
    {
        $m = $this->i18n->getLangMap('pages', (int)$page->id, 'description');

        return [
            'englishDescription' => $m['en'] ?? '',
            'arabicDescription'  => $m['ar'] ?? '',
            'urduDescription'    => $m['ur'] ?? '',
        ];
    }

    /**
     * Contact address translations keyed for UI.
     * Keys: englishAddress, arabicAddress, urduAddress
     */
    public function pageAddressTranslations(Page $page): array
    {
        $m = $this->i18n->getLangMap('pages', (int)$page->id, 'address');

        return [
            'englishAddress' => $m['en'] ?? '',
            'arabicAddress'  => $m['ar'] ?? '',
            'urduAddress'    => $m['ur'] ?? '',
        ];
    }

    public function upsertPageDescription(Page $page, array $payload): void
    {
        $this->i18n->upsertLangMap('pages', (int)$page->id, 'description', [
            'fa' => (string)($payload['description'] ?? ''),
            'en' => (string)($payload['englishDescription'] ?? ''),
            'ar' => (string)($payload['arabicDescription'] ?? ''),
            'ur' => (string)($payload['urduDescription'] ?? ''),
        ]);
    }

    public function upsertPageAddress(Page $page, array $payload): void
    {
        $this->i18n->upsertLangMap('pages', (int)$page->id, 'address', [
            'fa' => (string)($payload['address'] ?? ''),
            'en' => (string)($payload['englishAddress'] ?? ''),
            'ar' => (string)($payload['arabicAddress'] ?? ''),
            'ur' => (string)($payload['urduAddress'] ?? ''),
        ]);
    }

    public function faqTranslations(PageFaq $faq): array
    {
        $q = $this->i18n->getLangMap('page_faqs', (int)$faq->id, 'question');
        $a = $this->i18n->getLangMap('page_faqs', (int)$faq->id, 'answer');

        return [
            'englishQuestion' => $q['en'] ?? '',
            'arabicQuestion'  => $q['ar'] ?? '',
            'urduQuestion'    => $q['ur'] ?? '',
            'englishAnswer'   => $a['en'] ?? '',
            'arabicAnswer'    => $a['ar'] ?? '',
            'urduAnswer'      => $a['ur'] ?? '',
        ];
    }

    public function upsertFaqTranslations(PageFaq $faq, array $payload): void
    {
        $this->i18n->upsertLangMap('page_faqs', (int)$faq->id, 'question', [
            'fa' => (string)($payload['question'] ?? ''),
            'en' => (string)($payload['englishQuestion'] ?? ''),
            'ar' => (string)($payload['arabicQuestion'] ?? ''),
            'ur' => (string)($payload['urduQuestion'] ?? ''),
        ]);

        $this->i18n->upsertLangMap('page_faqs', (int)$faq->id, 'answer', [
            'fa' => (string)($payload['answer'] ?? ''),
            'en' => (string)($payload['englishAnswer'] ?? ''),
            'ar' => (string)($payload['arabicAnswer'] ?? ''),
            'ur' => (string)($payload['urduAnswer'] ?? ''),
        ]);
    }

    public function pickByLang(array $langMap, string $lang): string
    {
        $lang = strtolower(trim($lang ?: 'fa'));
        if (!in_array($lang, ['fa','en','ar','ur'], true)) $lang = 'fa';
        return (string)($langMap[$lang] ?? ($langMap['fa'] ?? ''));
    }
}
