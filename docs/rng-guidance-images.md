# RNG guidance-only images

`GET /api/v1/landmark-view-image` accepts `source=guidance_points`.
The query must include a smallint `floor`, WGS84 `geo[lat]` / `geo[lng]`, and `heading`.

A match returns `status=OK`, `source=guidance_points`, `guidance_point_id`, and `image`.
No match returns HTTP 200, `status=NO_MATCH`, `source=guidance_points`, `image=null`,
and `reason=NO_GUIDANCE_IMAGE_MATCH`. It never calls the POI fallback in this mode.
The default `source=auto` keeps the previous guidance-first / POI-fallback behavior.
Coverage radius, image azimuth and FOV restrictions are unchanged.

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
