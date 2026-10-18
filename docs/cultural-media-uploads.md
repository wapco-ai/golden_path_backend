# Cultural media uploads

`POST /api/v1/files` applies these rules only to `entity_table=contents`:

| Media | New upload formats | Maximum per file |
| --- | --- | --- |
| Photo | JPG/JPEG, PNG, WebP, GIF | 5 MiB (5,242,880 bytes) |
| Video | MP4 | 50 MiB (52,428,800 bytes) |
| Audio/documents | Existing accepted formats | 50 MiB |

Image and video content is checked using the server-detected MIME type and
matching filename extension. Images must also have readable image headers. The
response `mime` for cultural uploads is the detected value, not the multipart
MIME supplied by the browser. Both the current `images` bucket (historically
used for videos too) and the `videos` bucket accept valid MP4 uploads. Other
entity upload contracts are unchanged. Stored `contents.media` JSON is not
rewritten or rejected by the new-upload policy, preserving existing media and
its titles, descriptions and language metadata.

MP4 is stored and served directly. No conversion, codec normalization or video
thumbnail generation is introduced; use a browser-compatible MP4 encoding.
The cultural list chooses a photo for `primaryImage` and preserves complete
media arrays, so a movie or document cannot become its image source.

## Deployment

After pulling the backend change, apply the public cultural-thumbnail function
migration and the new read-only PHP INI mount. No image build is needed:

```bash
git pull --ff-only
docker compose up -d --no-deps --no-build --pull never --force-recreate app
docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_18_180000_select_cultural_landmark_images.php
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php -r 'echo "upload_max_filesize=".ini_get("upload_max_filesize").PHP_EOL."post_max_size=".ini_get("post_max_size").PHP_EOL;'
```

The last command should report `50M` and `64M`. The queue service has the same
mount and receives it on its next recreation. Nginx already permits 200 MiB
requests. If deployment uses another PHP configuration or an external reverse
proxy, ensure it permits a 50 MiB file plus multipart overhead as well. Deploy
the matching frontend build so its upload hints and checks use the same limits.
