-- Rollback: export connector configuration first; refuse to remove populated configuration.
-- Apply the routing down.sql first. No historical baseline is modified.
BEGIN;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM routing_connectors) THEN
    RAISE EXCEPTION 'Export and explicitly remove connector configuration before schema rollback';
  END IF;
END $$;
CREATE OR REPLACE FUNCTION public.fn_allowed_areas(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, geom public.geometry, area_type public.area_type_enum, floor smallint, allowed_gender public.gender_enum, is_closed boolean, weight_open_space numeric, attrs jsonb, is_allowed boolean, admin_penalty_w numeric)
    LANGUAGE sql STABLE
    AS $$

WITH blocked_raw AS (

    SELECT
        ba.area_id,
        ba.restrict_type,
        ba.penalty_w

    FROM public.fn_blocked_areas_from_admin(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) ba
),

blocked AS (

    SELECT
        area_id,

        CASE
            WHEN bool_or(restrict_type = 'close')
                THEN 'close'

            ELSE MIN(restrict_type)
        END AS restrict_type,

        MAX(
            COALESCE(penalty_w, 0.0)
        ) AS penalty_w

    FROM blocked_raw

    GROUP BY area_id
)

SELECT
    a.id,
    a.geom,
    a.area_type,
    a.floor,
    a.allowed_gender,
    a.is_closed,
    a.weight_open_space,
    a.attrs,

    CASE

        --------------------------------------------------------------
        -- area اصولاً routable نیست
        --------------------------------------------------------------
        WHEN public.fn_area_routing_role(
                 a.area_type,
                 a.attrs
             ) <> 'routable'
            THEN FALSE


        --------------------------------------------------------------
        -- ویلچر از پله عبور نکند
        --------------------------------------------------------------
        WHEN p_mode = 'wheelchair'
             AND a.area_type::text = 'stair_area'
            THEN FALSE


        --------------------------------------------------------------
        -- محدوده بدون DAP قابل استفاده در گراف نیست
        --------------------------------------------------------------
        WHEN COALESCE(ds.door_cnt, 0) = 0
            THEN FALSE


        --------------------------------------------------------------
        -- محدودیت زمان / نماز / admin از fn_entity_access
        --------------------------------------------------------------
        WHEN COALESCE(acc.allowed, TRUE) = FALSE
            THEN FALSE


        --------------------------------------------------------------
        -- بسته بودن دائمی area
        --------------------------------------------------------------
        WHEN a.is_closed = TRUE
            THEN FALSE


        --------------------------------------------------------------
        -- Admin close
        --------------------------------------------------------------
        WHEN b.restrict_type = 'close'
            THEN FALSE


        ELSE TRUE

    END AS is_allowed,

    COALESCE(
        b.penalty_w,
        0.0
    ) AS admin_penalty_w

FROM public.areas a

LEFT JOIN blocked b
       ON b.area_id = a.id

LEFT JOIN public.mv_area_door_stats ds
       ON ds.area_id = a.id

LEFT JOIN LATERAL (

    SELECT
        ea.allowed

    FROM public.fn_entity_access(
        'areas',
        a.id,
        p_now,
        p_gender,
        p_mode,
        p_floor,

        -- area.geom مجاز است
        ST_PointOnSurface(a.geom)
    ) ea

    LIMIT 1

) acc ON TRUE

WHERE
    a.floor = p_floor

    AND (

        --------------------------------------------------------------
        -- family
        --------------------------------------------------------------
        (
            p_gender = 'both'::gender_enum
            AND a.allowed_gender = 'both'::gender_enum
        )

        OR

        --------------------------------------------------------------
        -- male / female
        --------------------------------------------------------------
        (
            p_gender IN (
                'male'::gender_enum,
                'female'::gender_enum
            )

            AND (
                a.allowed_gender = 'both'::gender_enum
                OR a.allowed_gender = p_gender
            )
        )
    );

$$;
DROP VIEW public.routing_connector_nodes;
DROP TABLE public.routing_connector_stops;
DROP FUNCTION public.fn_connector_stop_removed();
DROP TABLE public.routing_connectors;
DO $$ DECLARE t text; invalid boolean; BEGIN
  FOREACH t IN ARRAY ARRAY['areas','doors','door_access_points','mesh_triangles','poi_points','route_logs','admin_restrictions','van_nodes'] LOOP
    EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I WHERE floor NOT IN (0,-1))',t) INTO invalid;
    IF invalid THEN RAISE EXCEPTION 'Floor data in % requires the new floor catalog',t; END IF;
    EXECUTE format('ALTER TABLE public.%I DROP CONSTRAINT %I',t,t || '_routing_floor_fk');
    EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I CHECK (floor IN (0,-1))',t,t || '_floor_check');
  END LOOP;
END $$;
DROP TABLE public.routing_floors;
COMMIT;
