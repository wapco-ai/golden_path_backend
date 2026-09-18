-- Purpose: endpoint text search includes every matching POI, on every floor.
-- Dependencies: 20260917_180000_public_place_floors.up.sql.
-- Objects: public.fn_landmark_places_json (same seven-argument signature).
-- Empty-search discovery and featured landmarks retain their content requirement.
-- Routing impact: search/endpoint metadata only; no graph or route geometry changes.
-- Rollback: the matching down file restores the preceding floor-aware function.

BEGIN;

CREATE OR REPLACE FUNCTION public.fn_landmark_places_json(
    p_lang public.lang_enum,
    p_limit integer,
    p_lat double precision DEFAULT NULL::double precision,
    p_lon double precision DEFAULT NULL::double precision,
    p_poi_id bigint DEFAULT NULL::bigint,
    p_search text DEFAULT NULL::text,
    p_featured boolean DEFAULT false
) RETURNS jsonb
LANGUAGE plpgsql
AS $$
DECLARE
    v_user_geom_32640 geometry;
BEGIN
    IF p_lat IS NOT NULL AND p_lon IS NOT NULL THEN
        v_user_geom_32640 := ST_Transform(
            ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326), 32640
        );
    ELSE
        v_user_geom_32640 := NULL;
    END IF;

    RETURN (
        WITH landmarks AS (
            SELECT
                p.id, p.geom, p.floor, p.attrs, p.updated_at,
                fn_i18n_label('poi_points', p.id, 'name', p_lang, 'fa'::lang_enum) AS title,
                fn_i18n_label('areas', cour.id, 'name', p_lang, 'fa'::lang_enum) AS address,
                ST_Y(ST_Transform(p.geom, 4326)) AS lat,
                ST_X(ST_Transform(p.geom, 4326)) AS lon,
                CASE WHEN v_user_geom_32640 IS NOT NULL
                    THEN round(ST_Distance(v_user_geom_32640, p.geom))::integer
                    ELSE NULL END AS distance_m,
                CASE WHEN v_user_geom_32640 IS NOT NULL
                    THEN GREATEST(1, round(ST_Distance(v_user_geom_32640, p.geom)::numeric / 70.0)::integer)
                    ELSE NULL END AS time_min,
                COALESCE((p.attrs->>'rating')::numeric, 0) AS rating,
                COALESCE((p.attrs->>'views')::integer, 0) AS views,
                COALESCE(
                    NULLIF(p.attrs->>'image_url', ''),
                    NULLIF(ct.media::jsonb->0->>'path', ''),
                    NULLIF(ct.media::jsonb->0->>'src', ''),
                    CASE WHEN ct.media IS NOT NULL
                        AND (ct.media::jsonb->0 ? 'data')
                        AND (ct.media::jsonb->0->>'data') <> ''
                    THEN 'data:' || COALESCE(ct.media::jsonb->0->>'mime', 'application/octet-stream')
                        || ';base64,' || (ct.media::jsonb->0->>'data')
                    ELSE NULL END
                ) AS image,
                COALESCE(p.attrs->>'group', cat.level1_code, m.default_group, 'poi') AS group_code,
                COALESCE(p.attrs->>'sub_group', cat.level2_code, m.default_subgroup, p.poi_type::text) AS sub_group,
                COALESCE(p.attrs->>'sub_group_value', cat.leaf_code, 'poi-' || p.id::text) AS sub_group_value,
                ct.title AS content_title, ct.body AS content_body, ct.media AS content_media
            FROM poi_points p
            LEFT JOIN feature_group_mappings m
                ON m.entity_table = 'poi_points' AND m.feature_key = p.poi_type::text
            LEFT JOIN categories c_leaf ON c_leaf.id = m.category_leaf_id
            LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat ON TRUE
            LEFT JOIN LATERAL (
                SELECT a.id FROM areas a
                WHERE a.floor = p.floor AND ST_Contains(a.geom, p.geom)
                ORDER BY a.id LIMIT 1
            ) cour ON TRUE
            LEFT JOIN LATERAL (
                SELECT c.* FROM contents c
                WHERE c.poi_id = p.id AND c.lang = p_lang
                ORDER BY c.id LIMIT 1
            ) ct ON TRUE
            WHERE (p_poi_id IS NULL OR p.id = p_poi_id)
                AND (
                    -- MPR/MPB text search is NOT limited to cultural landmarks.
                    -- Do not filter by floor, category, has_content or image here.
                    (NULLIF(btrim(p_search), '') IS NOT NULL AND NOT COALESCE(p_featured, false))
                    OR p.has_content = TRUE
                    OR ct.id IS NOT NULL
                )
        ),
        featured AS (
            SELECT l.* FROM public.featured_landmark_places f
            JOIN landmarks l ON l.id = f.poi_id
            WHERE f.is_active = true
            ORDER BY f.sort_order, f.id
            LIMIT COALESCE(p_limit, 20)
        ),
        filtered AS (
            SELECT * FROM landmarks
            WHERE p_search IS NULL OR btrim(p_search) = ''
                OR title ILIKE ('%' || p_search || '%')
        ),
        limited AS (
            SELECT * FROM filtered
            ORDER BY (distance_m IS NULL), distance_m NULLS LAST, id
            LIMIT COALESCE(CASE WHEN p_poi_id IS NOT NULL THEN 1 ELSE p_limit END, 20)
        ),
        picked AS (
            SELECT * FROM featured WHERE p_featured = true
            UNION ALL
            SELECT * FROM limited WHERE p_featured = false
        )
        SELECT jsonb_build_object(
            'places', jsonb_build_object('landmarkPlaces', COALESCE(jsonb_agg(
                jsonb_build_object(
                    'id', id::text, 'floor', floor, 'title', title, 'address', address,
                    'distance', distance_m, 'time', time_min, 'rating', rating, 'views', views,
                    'image', image, 'group', group_code, 'subGroup', sub_group,
                    'coordinates', jsonb_build_array(lat, lon),
                    'subGroupValue', sub_group_value, 'lastUpdated', updated_at,
                    'content', CASE WHEN content_title IS NOT NULL OR content_body IS NOT NULL OR content_media IS NOT NULL
                        THEN jsonb_build_object('title', content_title, 'body', content_body, 'media', content_media)
                        ELSE NULL END
                )
            ), '[]'::jsonb)),
            'language', p_lang::text, 'generatedAt', now()
        ) FROM picked
    );
END;
$$;

COMMIT;
