-- Purpose: expose the authoritative POI floor in public landmark/search results.
-- Dependencies: 2026-08-26 baseline.
-- Objects: public.fn_landmark_places_json (existing signature).
-- Routing impact: endpoint metadata only; no geometry or graph changes.
-- Rollback: the down file restores the previous JSON contract without deleting data.

BEGIN;

CREATE OR REPLACE FUNCTION public.fn_landmark_places_json(p_lang public.lang_enum, p_limit integer, p_lat double precision DEFAULT NULL::double precision, p_lon double precision DEFAULT NULL::double precision, p_poi_id bigint DEFAULT NULL::bigint, p_search text DEFAULT NULL::text, p_featured boolean DEFAULT false) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_user_geom_32640 geometry;
BEGIN
    -- تبدیل موقعیت کاربر
    IF p_lat IS NOT NULL AND p_lon IS NOT NULL THEN
        v_user_geom_32640 := ST_Transform(
            ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326),
            32640
        );
    ELSE
        v_user_geom_32640 := NULL;
    END IF;

    RETURN (
        WITH landmarks AS (
            SELECT
                p.id,
                p.geom,
                p.floor,
                p.attrs,
                p.updated_at,

                -- عنوان
                fn_i18n_label('poi_points', p.id, 'name', p_lang, 'fa'::lang_enum) AS title,

                -- آدرس (مثلاً نام صحن)
                fn_i18n_label('areas', cour.id, 'name', p_lang, 'fa'::lang_enum) AS address,

                -- مختصات WGS84
                ST_Y(ST_Transform(p.geom, 4326)) AS lat,
                ST_X(ST_Transform(p.geom, 4326)) AS lon,

                -- فاصله از کاربر (متر)
                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN
                        round(ST_Distance(v_user_geom_32640, p.geom))::integer
                    ELSE
                        NULL
                END AS distance_m,

                -- زمان تخمینی پیاده‌روی (دقیقه)
                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN
                        GREATEST(
                            1,
                            round(
                                (ST_Distance(v_user_geom_32640, p.geom))::numeric
                                / 70.0
                            )::integer
                        )
                    ELSE
                        NULL
                END AS time_min,

                -- rating / views از attrs
                COALESCE((p.attrs->>'rating')::numeric, 0)  AS rating,
                COALESCE((p.attrs->>'views')::integer, 0)   AS views,

                -- تصویر
                -- تصویر (پشتیبانی همزمان از URL و Base64)
COALESCE(
    NULLIF(p.attrs->>'image_url',''),

    -- اگر media به صورت url/src ذخیره شده باشد (آپلودی)
    NULLIF(ct.media::jsonb->0->>'path',''),
    NULLIF(ct.media::jsonb->0->>'src',''),

    -- اگر media به صورت base64 ذخیره شده باشد
    CASE
        WHEN ct.media IS NOT NULL
         AND (ct.media::jsonb->0 ? 'data')
         AND (ct.media::jsonb->0->>'data') <> ''
        THEN
            'data:' ||
            COALESCE(ct.media::jsonb->0->>'mime','application/octet-stream') ||
            ';base64,' ||
            (ct.media::jsonb->0->>'data')
        ELSE
            NULL
    END
) AS image,


                -- گروه اصلی (مثلاً sahn, riwaq, elevator, ...)
COALESCE(
    p.attrs->>'group',
    cat.level1_code,
    m.default_group,
    'poi'
) AS group_code,

-- زیرگروه (مثلاً sahn, eyvan, ... یا نوع POI)
COALESCE(
    p.attrs->>'sub_group',
    cat.level2_code,
    m.default_subgroup,
    p.poi_type::text
) AS sub_group,

-- subGroupValue (برچسب یکتای زیرگروه / برگ)
COALESCE(
    p.attrs->>'sub_group_value',
    cat.leaf_code,
    'poi-' || p.id::text
) AS sub_group_value,

                -- 👇 فیلدهای محتوای فرهنگی از جدول contents
                ct.title AS content_title,
                ct.body  AS content_body,
                ct.media AS content_media

            FROM poi_points p
						
LEFT JOIN feature_group_mappings m
  ON m.entity_table = 'poi_points'
 AND m.feature_key  = p.poi_type::text
LEFT JOIN categories c_leaf
  ON c_leaf.id = m.category_leaf_id
LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
  ON TRUE


            -- پیدا کردن area شامل این POI
            LEFT JOIN LATERAL (
                SELECT a.id
                FROM areas a
                WHERE a.floor = p.floor
                  AND ST_Contains(a.geom, p.geom)
                ORDER BY a.id
                LIMIT 1
            ) cour ON TRUE

            -- آوردن content مرتبط با این POI و زبان کاربر
            LEFT JOIN LATERAL (
                SELECT c.*
                FROM contents c
                WHERE c.poi_id = p.id
                  AND c.lang   = p_lang
                ORDER BY c.id
                LIMIT 1
            ) ct ON TRUE

            WHERE
                -- فیلتر اختیاری بر اساس poi_id
                (p_poi_id IS NULL OR p.id = p_poi_id)
                -- تعریف Landmark: هر چیزی که محتوای فرهنگی دارد
                AND (
                    p.has_content = TRUE
                    OR ct.id IS NOT NULL      -- اگر ردیف contents دارد
                )
        ),
				featured AS (
  SELECT l.*
  FROM public.featured_landmark_places f
  JOIN landmarks l ON l.id = f.poi_id
  WHERE f.is_active = true
  ORDER BY f.sort_order, f.id
  LIMIT COALESCE(p_limit, 20)
),

filtered AS (
  SELECT *
  FROM landmarks
  WHERE
    p_search IS NULL
    OR btrim(p_search) = ''
    OR title ILIKE ('%' || p_search || '%')
--     OR COALESCE(address,'') ILIKE ('%' || p_search || '%')
--     OR COALESCE(content_title,'') ILIKE ('%' || p_search || '%')
--     OR COALESCE(content_body,'') ILIKE ('%' || p_search || '%')
),

limited AS (
  SELECT *
  FROM filtered
  ORDER BY
    (distance_m IS NULL),
    distance_m NULLS LAST,
    id
  LIMIT COALESCE(
    CASE WHEN p_poi_id IS NOT NULL THEN 1 ELSE p_limit END,
    20
  )
),

picked AS (
  SELECT * FROM featured WHERE p_featured = true
  UNION ALL
  SELECT * FROM limited  WHERE p_featured = false
)

        SELECT jsonb_build_object(
            'places', jsonb_build_object(
                'landmarkPlaces',
                COALESCE(
                    jsonb_agg(
                        jsonb_build_object(
                            'id',           id::text,
                            'title',        title,
                            'address',      address,
                            'distance',     distance_m,
                            'time',         time_min,
                            'rating',       rating,
                            'views',        views,
                            'image',        image,
														-- 👇 فیلدهای دسته‌بندی برای فیلتر نقشه
                    'group',        group_code,
                    'subGroup',     sub_group,
                            'coordinates',  jsonb_build_array(lat, lon),
                            'subGroupValue', sub_group_value,
                            'lastUpdated',  updated_at,

                            -- 👇 بلوک محتوای فرهنگی
                            'content', CASE
                                WHEN content_title IS NOT NULL
                                  OR content_body  IS NOT NULL
                                  OR content_media IS NOT NULL
                                THEN jsonb_build_object(
                                    'title', content_title,
                                    'body',  content_body,
                                    'media', content_media
                                )
                                ELSE NULL
                            END
                        )
                    ),
                    '[]'::jsonb
                )
            ),
            'language',   p_lang::text,
            'generatedAt', now()
        )
        FROM picked
    );
END;
$$;

COMMIT;
