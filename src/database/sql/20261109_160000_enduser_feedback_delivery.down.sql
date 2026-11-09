-- Purpose: durable idempotent emergency receipts and independent guest feedback.
-- Dependencies: existing support_feedbacks and user_feedbacks tables.
-- Affects: user_feedbacks.user_id; support_feedbacks.request_id and its index.
-- Routing impact: none. Rollback refuses to reassign or delete guest feedback.
-- Do not delete anonymous feedback or assign it to another user on rollback.
-- Restoring NOT NULL deliberately fails if anonymous records still exist.
ALTER TABLE public.user_feedbacks ALTER COLUMN user_id SET NOT NULL;
DROP INDEX IF EXISTS public.support_feedbacks_request_id_uq;
ALTER TABLE public.support_feedbacks DROP COLUMN IF EXISTS request_id;
