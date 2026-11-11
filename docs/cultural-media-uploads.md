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

## Landmark card photos

`GET /api/v1/landmark-places` returns same-origin paths for local uploads and
adds `thumbnail` when a smaller WebP is available. The map home cards prefer
that path and fall back to `image`. The original photo is used in place details.

Deploy the backend before the frontend. For the IP-hosted stack, retain the
persistent uploads mount, the live Nginx configuration and the enabled
thumbnail timer during code and container releases. The GitHub Pages build is
independent of the IP-hosted stack.

On first provisioning or when rebuilding the production host, install Ubuntu's
`python3-pil` package and run the following from the backend checkout.

```bash
root=/opt/golden_path_stack
sudo install -d -m 0755 "$root/scripts"
sudo install -m 0644 scripts/generate_landmark_card_thumbnails.py "$root/scripts/"
sudo install -d -m 0755 -o www-data -g www-data \
  "$root/src/docker-data/uploads/card-thumbnails-v1"
sudo install -m 0644 scripts/systemd/goldenpath-landmark-card-thumbnails.{service,timer} \
  /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start goldenpath-landmark-card-thumbnails.service
sudo systemctl enable --now goldenpath-landmark-card-thumbnails.timer
```

If the live Nginx config is replaced, apply
`docker/nginx/production/landmark-card-thumbnails.patch` to the production
`default.conf`. Check the patch against the current config, back it up, and
prepare the patched file separately. Write the candidate into the existing
bind-mounted file to preserve its inode. Run `docker exec gp_nginx nginx -t`
before `docker exec gp_nginx nginx -s reload`. Restore the backup in place if
validation fails.

After rollout, check `systemctl is-active goldenpath-landmark-card-thumbnails.timer`
and search for `چایخانه` on the production IP over HTTP and HTTPS. The four
cards should use the same thumbnail paths. Responses should be `image/webp`
with `Cache-Control: public, max-age=2592000, immutable`.
