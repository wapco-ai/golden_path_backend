BEGIN;

CREATE INDEX IF NOT EXISTS route_logs_user_history_ts_idx
    ON public.route_logs ((meta->>'user_id'), ts DESC, id DESC)
    WHERE ok = true
      AND jsonb_exists(meta, 'route_snapshot')
      AND jsonb_exists(meta, 'history');

COMMIT;
