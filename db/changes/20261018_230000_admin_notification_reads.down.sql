-- Revert notification-read API code first. Drop only read receipts, not source data.
BEGIN;

DROP TABLE IF EXISTS public.admin_notification_reads;

COMMIT;
