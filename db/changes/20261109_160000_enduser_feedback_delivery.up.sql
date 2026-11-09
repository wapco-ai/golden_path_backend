-- Purpose: durable idempotent emergency receipts and independent guest feedback.
-- Dependencies: existing support_feedbacks and user_feedbacks tables.
-- Affects: user_feedbacks.user_id; support_feedbacks.request_id and its index.
-- Routing impact: none. Rollback refuses to reassign or delete guest feedback.
ALTER TABLE public.user_feedbacks ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE public.support_feedbacks ADD COLUMN IF NOT EXISTS request_id uuid;
CREATE UNIQUE INDEX IF NOT EXISTS support_feedbacks_request_id_uq ON public.support_feedbacks(request_id);
