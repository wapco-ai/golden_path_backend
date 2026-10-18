-- Purpose: persist only actual per-admin acknowledgements of the derived feed.
-- Dependencies: immutable baseline public.users; source entities remain unchanged.
-- No event, read receipt or timestamp is backfilled for existing source rows.
BEGIN;

CREATE TABLE IF NOT EXISTS public.admin_notification_reads (
    admin_user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    notification_type text NOT NULL CHECK (notification_type IN ('new_user', 'new_comment', 'support_message')),
    entity_id bigint NOT NULL CHECK (entity_id > 0),
    read_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (admin_user_id, notification_type, entity_id)
);

COMMIT;
