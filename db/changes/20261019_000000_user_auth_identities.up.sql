-- Purpose: map employer authentication identities to existing public user IDs.
-- Dependencies: public.users from the immutable application baseline.
-- Objects: public.user_auth_identities and its ordinary relational indexes.
-- Routing impact: none; no geometry, routing functions or admin auth changes.
-- Rollback: apply matching down file; users and their application data remain.

BEGIN;

CREATE TABLE IF NOT EXISTS public.user_auth_identities (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    provider varchar(32) NOT NULL,
    issuer varchar(255) NOT NULL,
    subject varchar(64) NOT NULL,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    CONSTRAINT user_auth_identity_subject_unique UNIQUE (provider, issuer, subject),
    CONSTRAINT user_auth_identity_user_unique UNIQUE (provider, issuer, user_id)
);

COMMIT;
