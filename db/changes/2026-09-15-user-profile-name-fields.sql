BEGIN;

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS first_name varchar(100),
    ADD COLUMN IF NOT EXISTS last_name varchar(100);

COMMENT ON COLUMN public.users.first_name IS
    'Canonical given/first name for public user profile. May contain spaces; never derive by splitting users.name.';

COMMENT ON COLUMN public.users.last_name IS
    'Canonical family/last name for public user profile. May contain spaces; never derive by splitting users.name.';

-- Intentionally no automatic backfill from users.name.
-- Existing full names can be ambiguous (for example: "محمد رضا حسینی").
-- Legacy rows continue to use users.name until the user explicitly saves
-- firstName and lastName through the profile API.

COMMIT;
