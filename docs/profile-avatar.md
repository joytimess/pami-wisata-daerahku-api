# Profile avatar V1

All endpoints use the existing bearer JWT and API envelope under `/api/v1`. Avatar operations are owner-only and enabled for role 0. No caller-supplied user identifier selects another account.

- `POST profile/avatar`: multipart `avatar` (JPG/PNG/WebP, at most 5 MiB) and integer `avatar_version`. Returns `data` containing the updated user.
- `PUT profile/avatar/preference`: JSON `source` (`default` or `google`) and integer `avatar_version`. Restore uses the existing Google snapshot without fetching Google.
- `GET profile/avatar?v=N`: authenticated active image bytes, or 404 for default/missing image. Private revalidation cache, user/version ETag and `nosniff`; no public storage URL.

User resources in auth and profile responses include `avatar_source`, nullable relative `avatar_url` (`profile/avatar?v=N`), `avatar_version`, and `google_avatar_available`. Version mismatches return 409 with `code: avatar_version_conflict` and `context.avatar_version`; refetch `auth/me` before retry. Missing Google snapshots return 409 `google_snapshot_unavailable`. Existing 401/403/422 contracts apply.

Migration `2026_11_03_033801_add_profile_avatar_state` adds defaulted avatar fields to users and the durable `avatar_file_cleanups` registry. Deployments must apply migrations before serving updated code. This change does not execute production migrations.

Images are decoded with GD after content/size/dimension validation (16 megapixels and 8192px maximum side), center-cropped and re-encoded as 512x512 PNG without source metadata. Files use UUID names on the private `local` disk, independent of the configured default disk. PHP GD with JPEG/PNG/WebP decoding is required; uploads must also fit PHP/web server request limits.

Google login imports only the verified Firebase token `picture` claim. Eligible accounts retry at their next Google login when acquisition fails. HTTPS photo hosts are restricted to `lh3.googleusercontent.com` through `lh6.googleusercontent.com`; DNS addresses must be public IPv4 and are pinned for the request. Redirects and proxies are disabled; connect/total timeouts are 2/5 seconds, with a 5 MiB download budget. Photo errors never reject a valid login. Manual choices and existing snapshots are rechecked under a database lock before activation.

Every generated file is registered before writing. Pointer updates use transactions, row locks and version checks. Old custom files are deleted after commit; snapshots remain available. `avatars:cleanup` retries failed deletions and unreferenced candidates after one hour; referenced records remain registered for recovery after a crash. The existing Laravel scheduler must run (`schedule:run` each minute). Failed deletion leaves a durable retry record and never reverses a committed preference. Cleanup logs omit file paths, tokens and origin URLs.
