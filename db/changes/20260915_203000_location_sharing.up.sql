BEGIN;

CREATE TABLE IF NOT EXISTS public.user_location_shares (
    id bigserial PRIMARY KEY,
    sender_user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    recipient_user_id bigint NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
    geom geometry(Point, 32640) NOT NULL,
    floor smallint NOT NULL CHECK (floor IN (-1, 0)),
    accuracy_m numeric(8,2) NULL CHECK (accuracy_m IS NULL OR accuracy_m >= 0),
    source text NOT NULL DEFAULT 'gps' CHECK (source IN ('gps')),
    expires_at timestamp with time zone NOT NULL,
    viewed_at timestamp with time zone NULL,
    revoked_at timestamp with time zone NULL,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT user_location_shares_not_self CHECK (sender_user_id <> recipient_user_id)
);

CREATE INDEX IF NOT EXISTS user_location_shares_recipient_active_idx
    ON public.user_location_shares (recipient_user_id, expires_at DESC)
    WHERE revoked_at IS NULL;

CREATE INDEX IF NOT EXISTS user_location_shares_sender_active_idx
    ON public.user_location_shares (sender_user_id, expires_at DESC)
    WHERE revoked_at IS NULL;

CREATE INDEX IF NOT EXISTS user_location_shares_geom_gix
    ON public.user_location_shares USING gist (geom);

COMMIT;
