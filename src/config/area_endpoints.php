<?php

return [
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
