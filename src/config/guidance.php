<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guidance image local north offset
    |--------------------------------------------------------------------------
    |
    | Guidance-image azimuths/orientations are recorded in the shrine's local
    | north frame, while route headings are supplied in the map/true-north
    | frame. Positive values mean local north is rotated clockwise (eastward)
    | from route north. This offset is used only while matching guidance images;
    | it must not affect routing geometry, route steps, or graph generation.
    |
    */
    'local_north_offset_deg' => (float) env('GUIDANCE_LOCAL_NORTH_OFFSET_DEG', 30.0),
];
