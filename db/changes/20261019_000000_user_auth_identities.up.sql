-- Purpose: map employer authentication identities to existing public user IDs.
-- Dependencies: public.users from the immutable application baseline.
-- Objects: public.user_auth_identities and users_mobile_auth_lookup_idx.
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

-- Nonunique: historical equivalent phone records must still be detected as ambiguous.
-- Keep this fixed expression identical to IranianMobile::postgresStorageExpression().
CREATE INDEX IF NOT EXISTS users_mobile_auth_lookup_idx ON public.users ((
    regexp_replace(translate(mobile, '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'), E'[\\s()\\-\u0085\u00A0\u1680\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u2028\u2029\u202F\u205F\u3000]+', '', 'g')
));

COMMIT;
