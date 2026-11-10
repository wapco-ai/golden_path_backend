<?php

return [
    'search_aliases' => [
        'fa' => [1000998 => ['روضه منوره']],
        'ar' => [1000998 => ['روضة منورة', 'الروضة المنورة']],
    ],
    'groups' => [
        'imam-khomeini-riwaq' => [
            'floor' => 0,
            'area_type' => 'riwaq',
            'area_ids' => [1001065, 1001061, 1001472, 1001473],
            // Auxiliary areas can share the group title without belonging to the group geometry.
            'search_excluded_area_ids' => [1001105, 1001112],
        ],
    ],
];
