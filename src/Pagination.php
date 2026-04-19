<?php

namespace App;

class Pagination {
    public static function render(int $currentPage, int $totalPages, string $basePath, array $queryParams, array $labels = [], int $neighborCount = 1): string {
        if ($totalPages <= 1) {
            return '';
        }

        $currentPage = max(1, min($currentPage, $totalPages));
        $neighborCount = max(0, $neighborCount);

        $defaultLabels = [
            'first' => 'First page',
            'last' => 'Last page',
            'previous' => 'Previous',
            'next' => 'Next',
            'navigation' => 'Pagination',
            'go_to_page' => 'Go to page %d',
            'current_page' => 'Current page, %d',
        ];

        $labels = array_merge($defaultLabels, $labels);
        $visiblePages = self::getVisiblePages($currentPage, $totalPages, $neighborCount);
        $items = [];

        if ($currentPage > 1) {
            $items[] = self::buildControlItem(
                self::buildUrl($basePath, 1, $queryParams),
                '&laquo;',
                $labels['first'],
                'pagination-control'
            );
            $items[] = self::buildControlItem(
                self::buildUrl($basePath, $currentPage - 1, $queryParams),
                htmlspecialchars($labels['previous'], ENT_QUOTES, 'UTF-8'),
                $labels['previous'],
                'pagination-control pagination-control-label'
            );
        }

        $lastRenderedPage = null;
        foreach ($visiblePages as $pageNumber) {
            if ($lastRenderedPage !== null && $pageNumber - $lastRenderedPage > 1) {
                $items[] = '<li class="pagination-item pagination-ellipsis" aria-hidden="true"><span>...</span></li>';
            }

            $items[] = self::buildPageItem($pageNumber, $currentPage, $basePath, $queryParams, $labels);
            $lastRenderedPage = $pageNumber;
        }

        if ($currentPage < $totalPages) {
            $items[] = self::buildControlItem(
                self::buildUrl($basePath, $currentPage + 1, $queryParams),
                htmlspecialchars($labels['next'], ENT_QUOTES, 'UTF-8'),
                $labels['next'],
                'pagination-control pagination-control-label'
            );
            $items[] = self::buildControlItem(
                self::buildUrl($basePath, $totalPages, $queryParams),
                '&raquo;',
                $labels['last'],
                'pagination-control'
            );
        }

        return sprintf(
            '<nav class="pagination-nav" aria-label="%s"><ul class="pagination-list">%s</ul></nav>',
            htmlspecialchars($labels['navigation'], ENT_QUOTES, 'UTF-8'),
            implode('', $items)
        );
    }

    private static function getVisiblePages(int $currentPage, int $totalPages, int $neighborCount): array {
        $pages = [1, $totalPages];

        for ($pageNumber = $currentPage - $neighborCount; $pageNumber <= $currentPage + $neighborCount; $pageNumber++) {
            if ($pageNumber >= 1 && $pageNumber <= $totalPages) {
                $pages[] = $pageNumber;
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    private static function buildPageItem(int $pageNumber, int $currentPage, string $basePath, array $queryParams, array $labels): string {
        $isCurrent = $pageNumber === $currentPage;
        $pageText = (string) $pageNumber;

        if ($isCurrent) {
            return sprintf(
                '<li class="pagination-item"><span class="pagination-link active" aria-current="page" aria-label="%s">%s</span></li>',
                htmlspecialchars(sprintf($labels['current_page'], $pageNumber), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($pageText, ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<li class="pagination-item"><a class="pagination-link" href="%s" aria-label="%s">%s</a></li>',
            htmlspecialchars(self::buildUrl($basePath, $pageNumber, $queryParams), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(sprintf($labels['go_to_page'], $pageNumber), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($pageText, ENT_QUOTES, 'UTF-8')
        );
    }

    private static function buildControlItem(string $url, string $text, string $label, string $extraClass = ''): string {
        $classes = trim('pagination-link ' . $extraClass);

        return sprintf(
            '<li class="pagination-item"><a class="%s" href="%s" aria-label="%s">%s</a></li>',
            htmlspecialchars($classes, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $text
        );
    }

    private static function buildUrl(string $basePath, int $pageNumber, array $queryParams): string {
        $filteredParams = array_filter($queryParams, static function ($value): bool {
            return $value !== null && $value !== '';
        });
        $filteredParams['page'] = $pageNumber;

        return $basePath . '?' . http_build_query($filteredParams);
    }
}