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
    | Keep the safe default at 0 so existing image matching remains unchanged
    | until the shrine-wide local-north offset is surveyed and explicitly set.
    |
    */
    'local_north_offset_deg' => (float) env('GUIDANCE_LOCAL_NORTH_OFFSET_DEG', 0.0),
];
