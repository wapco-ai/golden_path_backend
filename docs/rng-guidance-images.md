# RNG guidance-only images

`GET /api/v1/landmark-view-image` accepts `source=guidance_points`.
The query must include a smallint `floor`, WGS84 `geo[lat]` / `geo[lng]`, and `heading`.

A match returns `status=OK`, `source=guidance_points`, `guidance_point_id`, and `image`.
No match returns HTTP 200, `status=NO_MATCH`, `source=guidance_points`, `image=null`,
and `reason=NO_GUIDANCE_IMAGE_MATCH`. It never calls the POI fallback in this mode.
The default `source=auto` keeps the previous guidance-first / POI-fallback behavior.
Coverage radius, image azimuth and FOV restrictions are unchanged.

## Local north for guidance images

Guidance image orientations/azimuths are authored against one shrine-wide local north.
Route `heading` remains in the normal map/route north frame. Before comparing the route
heading with a guidance image, the backend rotates only the comparison heading into the
local frame using `GUIDANCE_LOCAL_NORTH_OFFSET_DEG`.

The default is `30` degrees clockwise: local north is 30 degrees east of route north.
With that default, a local-west image (`azimuth_deg=270`) matches a route heading of
`300` degrees. Stored image azimuths and the `heading` returned by the API are not rewritten.
Set the environment value to the surveyed shrine offset if it changes; negative values and
values outside 0..360 are normalized by the matching calculation.

This setting affects guidance-image selection only. It does not rotate coordinates, alter
route geometry, generate route steps, or modify any graph object.

This is an application-code-only update. No new migration, SQL, graph rebuild,
Composer dependency update or change to historical db/baseline is required.
Deploy backend before the matching frontend. The frontend rejects a source-unaware
backend response rather than displaying unrelated POI imagery.

## Verification

`cd src && vendor/bin/phpunit tests/Feature/GuidanceImageSourceTest.php`

Database calls are mocked; no database is created, migrated or contacted.
Existing PHPUnit production-database safeguards remain enabled.
Spatial SQL and actual on-device navigation still require local acceptance testing.

## Geometry policy

Guidance points are visual aids only. No routing geometry or steps are generated here.
`doors.geom` remains display-only; route and step geometries remain the responsibility
of `routing_edges_static` / `door_access_points`. No graph-generation code is changed.

## Rollback

Revert the frontend change first, then this application-code change through a PR.
No database rollback is needed.
