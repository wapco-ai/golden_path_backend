# RNG guidance-only images

`GET /api/v1/landmark-view-image` accepts `source=guidance_points`.
The query must include a smallint `floor`, WGS84 `geo[lat]` / `geo[lng]`, and `heading`.

A match returns `status=OK`, `source=guidance_points`, `guidance_point_id`, and `image`.
No match returns HTTP 200, `status=NO_MATCH`, `source=guidance_points`, `image=null`,
and `reason=NO_GUIDANCE_IMAGE_MATCH`. It never calls the POI fallback in this mode.
The default `source=auto` keeps the previous guidance-first / POI-fallback behavior.

## Two-stage guidance selection

Guidance point selection and guidance image selection are intentionally separate.

### 1. Select the guidance point

The backend first considers active points on the requested floor. A point must satisfy:

- distance <= `min(max_distance, coverage_radius_m)`
- the bearing from the user to the point must be inside the request FOV around `heading`

Candidate points are ranked by distance, then angular distance from the center of the
request FOV, then point `sort_order`.

If the best visible point has no images, it is skipped and the next visible point is tried.
The same happens when a point has image rows but none has usable direction metadata.

The shrine local-north offset is NOT used in this stage.

### 2. Select the image for that point

After a point has passed floor/distance/FOV selection, the backend calculates the bearing
from the selected point back to the user. This represents the side from which the user is
approaching/viewing the point.

Only now is `GUIDANCE_LOCAL_NORTH_OFFSET_DEG` applied:

`local_view_bearing = normalize(point_to_user_bearing - local_north_offset)`

The image whose `azimuth_deg` (or orientation fallback) has the smallest circular angular
difference from `local_view_bearing` is returned.

The request `fov` is not reused for image selection. `guidance_point_images.fov_deg` is kept
as image metadata and produces the response flag `imageMatched`; it does not suppress the
best available image. This ensures a point with at least one usable directional image can
still return its closest available view from any approach angle.

Guidance image orientations/azimuths are authored against the shrine-wide local north.
The default offset remains `30` degrees clockwise. Stored image azimuths and the route
`heading` returned by the API are not rewritten.

The response includes diagnostics such as `bearing_to_guidance_deg`,
`point_angle_diff_deg`, `bearing_guidance_to_user_deg`, `local_view_bearing_deg`,
`image_angle_diff_deg`, and `imageMatched`.

This is an application-code-only update. No new migration, SQL, graph rebuild,
Composer dependency update or historical baseline change is required.

## Verification

`cd src && vendor/bin/phpunit tests/Feature --filter='Guidance(ImageSource|Coverage)Test'`

Database calls are mocked; no database is created, migrated or contacted.
Spatial behavior still requires local acceptance testing with the real PostGIS dataset.

## Geometry policy

Guidance points are visual aids only. No routing geometry or steps are generated here.
`doors.geom` remains display-only; route and step geometries remain the responsibility
of `routing_edges_static` / `door_access_points`. No graph-generation code is changed.

## Rollback

Revert this application-code change through a PR. No database rollback is needed.
