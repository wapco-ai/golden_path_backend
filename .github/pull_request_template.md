## Summary

Describe what this pull request changes and why.

## Verification

Describe the commands/tests used to verify the change.

## Database

- [ ] No database change
- [ ] Laravel migration included
- [ ] Versioned SQL included under `db/changes/`
- [ ] Rollback strategy documented
- [ ] Historical `db/baseline/` files were not modified

## Routing geometry

For routing-related changes:

- [ ] `doors.geom` is used only for raw drawing/display geometry
- [ ] door routing geometry comes from `door_access_points.geom`
- [ ] `routing_nodes.geom` comes from `door_access_points.geom`
- [ ] `routing_edges_static.geom` comes from `routing_nodes.geom`
- [ ] route output/steps do not use `doors.geom`

## Risk / rollback

Describe operational risk and rollback steps.
