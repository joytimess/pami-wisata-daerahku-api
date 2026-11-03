# PRD — Wisata Daerahku: User Profile Picture V1

**Status:** Final — keputusan produk dan wireframe disetujui pengguna (3 November 2026)  
**Target:** Flutter Android (User, `role = 0`) + Laravel 13 REST API  
**Prioritas:** Implementasi fungsional end-to-end, tanpa perubahan bisnis booking  
**Lokasi dokumentasi ketika implementasi:** `docs/prd/PRD-User-Profile-Picture-V1.md`

## 1. Tujuan

Menambahkan profile picture yang persisten dan aman untuk pengunjung Wisata Daerahku. Register biasa memakai avatar inisial bawaan aplikasi; Google Sign-In mengimpor **snapshot foto Google satu kali**. User dapat melihat preview fullscreen, memilih foto dari galeri/kamera, melakukan crop 1:1, memakai avatar default, dan mengembalikan snapshot Google. Data disimpan dan dikelola Laravel, bukan Firebase Storage/Firestore.

Backend didesain reusable untuk `role = 0/1/2`, tetapi UI dan aktivasi fitur V1 **hanya User (`role = 0`)**. Admin dan Superadmin merupakan fase berikutnya. Tidak mengubah kebijakan Google login: **hanya role 0**.

## 2. Ruang lingkup yang disetujui

**In scope**
- Card **Profil** V4: avatar di kiri, ikon kamera kecil di kanan-bawah; ketuk avatar membuka preview fullscreen; ikon pensil membuka editor profil.
- **Edit Profil** fullscreen mengikuti wireframe interaktif approved: scenic header, typography biru/Plus Jakarta Sans, avatar 106–112dp, tombol Ganti Foto Profil, form nama/email/telepon existing, Simpan Perubahan.
- Bottom sheet avatar: **Pilih dari Galeri**, **Ambil dengan Kamera**, **Kembalikan Foto Google** (hanya bila snapshot tersedia dan bukan sedang aktif), **Gunakan Avatar Default**.
- Crop nyata 1:1 dengan geser/zoom, preview, konfirmasi; upload foto setelah konfirmasi crop; edit nama/telepon tetap disimpan terpisah.
- Foto default berupa inisial nama pada lingkaran biru; bila nama kosong fallback ikon person.
- Laravel: model/kolom avatar, file storage, API, foto Google otomatis, retry, kontrol preferensi, cleanup file, resource profil, otorisasi, keamanan upload.
- Backend QA/regression + security validation yang relevan.

**Out of scope**
- UI Admin/Superadmin; avatar di Riwayat, Notifikasi, Review, Detail Tiket, Beranda, dan tab lain.
- Sinkronisasi berulang ketika Google mengganti foto; histori/gallery avatar; edit foto berbasis AI.
- Firebase Storage/Firestore, migrasi bisnis lain, perubahan sistem JWT/OTP/booking, deployment produksi, full Flutter regression/QA.

## 3. Temuan checkout Flutter dan prinsip reuse

Repo: `https://github.com/koyotot/pami-wisata-daearahku`. Berdasarkan checkout `main` yang terlihat saat PRD disusun:
- `lib/screens/profil_user_screen.dart`: card identitas dengan `CircleAvatar` inisial; `_bukaEditProfil()` membuka `_EditProfilSheet` via `showModalBottomSheet`. **Refactor editor existing menjadi fullscreen sesuai approved wireframe; jangan membuat dua editor yang bersaing.** Jika working tree lokal sudah berbeda, inspeksi source terbaru dan pilih perubahan paling kecil.
- `lib/services/auth_session.dart`: JWT, `auth/google`, `auth/me`, refresh, `requestMultipart`, dan session generation. Reuse; jangan rewrite auth.
- `lib/data/app_data.dart`: `currentUser`/model identitas; `lib/data/business_repository.dart`: kontrak business API.
- `docs/DESIGN.md`: rujukan audit desain; gunakan bersama implementasi V4 terbaru dan screenshot/wireframe approved. Bila audit lama konflik dengan visual V4, hormati visual V4 terbaru.
- `image_picker` sudah ada di `pubspec.yaml`; periksa dependensi crop yang kompatibel sebelum menambah package.

## 4. Aturan business logic — final dan tidak boleh ditafsir ulang

1. **Register email/password**: `avatar_source = default`, tanpa file; tampilkan inisial.
2. **Google login pertama**: setelah Firebase ID token diverifikasi di Laravel dan role dipastikan 0, impor foto Google bila tersedia. Simpan **salinan foto**, bukan hanya URL eksternal. Login tidak boleh bergantung pada keberhasilan download gambar.
3. **Google user lama**: pada Google login berikutnya, impor snapshot bila belum memiliki snapshot dan **belum pernah memilih avatar secara manual**. Tidak perlu proses migrasi massal.
4. **Jika snapshot sudah tersimpan**: jangan sinkronisasi ulang hanya karena user login lagi atau URL Google berubah.
5. **Jika impor gagal/tidak ada foto**: avatar tetap default; retry pada **Google login berikutnya** selama belum ada pilihan manual. Login tetap sukses. Error foto tidak boleh muncul sebagai error autentikasi.
6. **User unggah custom**: avatar custom menjadi aktif, `manual_choice = true`. Google login berikutnya **tidak boleh overwrite**.
7. **User memilih default secara manual**: set `avatar_source = default`, `manual_choice = true`; tetap default setelah logout/login. Jangan aktifkan ulang foto Google secara otomatis.
8. **User pilih Kembalikan Foto Google**: aktifkan snapshot Google yang **sudah tersimpan**; jangan fetch ulang Google saat restore. Aksi hanya muncul jika snapshot tersedia. Jika tidak tersedia, backend mengembalikan conflict yang jelas.
9. **Custom lama**: setelah update database berhasil, hapus file custom yang sudah tidak dipakai. **Snapshot Google tetap disimpan** untuk restore, walaupun avatar aktif adalah custom/default.
10. **Upload gagal/crop dibatalkan**: avatar lama tetap aktif; tampilkan pesan non-destruktif; tidak ada perubahan state server.
11. **Perubahan nama dan nomor telepon**: diproses pada flow `PATCH profile` existing; tidak digabung dengan upload avatar.
12. **User berganti akun/login**: abaikan respons gambar/mutasi yang berasal dari session generation atau user GUID lama. Jangan tampilkan avatar akun sebelumnya saat akun baru login.

### State machine

| Kondisi | `avatar_source` | `avatar_manual_choice` | Snapshot Google | Aksi berikutnya |
|---|---|---:|---|---|
| Register biasa | `default` | `false` | kosong | Boleh upload |
| Google sebelum snapshot | `default` | `false` | kosong | Retry login Google berikutnya |
| Google snapshot berhasil | `google` | `false` | tersimpan | Jangan sync lagi |
| Upload custom | `custom` | `true` | tetap tersimpan jika ada | Custom aktif |
| Pilih default manual | `default` | `true` | tetap tersimpan jika ada | Jangan auto-restore |
| Restore Google manual | `google` | `true` | tersimpan | Snapshot aktif |

**Invarian:** jika `avatar_manual_choice = true`, proses auto-snapshot tidak boleh mengganti avatar aktif, dalam kondisi race/concurrent sekalipun. Jika snapshot sudah ada, tidak download lagi.

## 5. Data model Laravel (rekomendasi; sesuaikan dengan skema nyata)

Tambah data pada `users` atau tabel avatar one-to-one yang konsisten dengan arsitektur backend:
- `avatar_source` string enum: `default`, `google`, `custom`; default `default`.
- `avatar_manual_choice` boolean; default `false`.
- `avatar_custom_path` nullable string (internal, jangan expose melalui API).
- `google_avatar_snapshot_path` nullable string (internal, jangan expose).
- `avatar_version` integer monotonik; default 0 untuk cache invalidation dan optimistic concurrency.
- Field timestamps memanfaatkan `updated_at` existing atau `avatar_updated_at` bila diperlukan.

Semua perubahan skema harus **additive**, aman bagi user lama, default terisi tanpa migrasi file historis. Hindari menyimpan URL foto Google sebagai sumber avatar final. Jangan mengubah `users.role`, GUID, atau proses login existing. Gunakan `*_guid` saat menambah foreign key, bila ada tabel tambahan.

## 6. Kontrak API Laravel (proposal final; ikuti envelope/versioning existing)

Prefix `/api/v1`, semua endpoint avatar membutuhkan JWT dan hanya boleh memodifikasi avatar pemilik sesi yang aktif. Untuk V1 aktifkan operasi pada role 0; desain service reusable lintas role.

| Method | Endpoint | Request | Hasil |
|---|---|---|---|
| `GET` | `/auth/me` | JWT existing | Resource user memuat metadata avatar |
| `PATCH` | `/profile` | Nama/telepon existing | Resource user juga memuat metadata avatar |
| `POST` | `/profile/avatar` | multipart `avatar` (image) + `avatar_version` | Resource user terbaru; custom aktif |
| `PUT` | `/profile/avatar/preference` | JSON `source: default\|google`, `avatar_version` | Resource user terbaru |
| `GET` | `/profile/avatar` | JWT; `?v=avatar_version` opsional | Byte gambar avatar aktif, atau 404 bila default |
| `POST` | `/auth/google` | Firebase ID token existing; optional provider photo hint bila perlu | Login existing, **best-effort snapshot** |

Tambahkan properti resource user secara konsisten ke respons profil dan auth:

```json
{
  "avatar_source": "google",
  "avatar_url": "profile/avatar?v=3",
  "avatar_version": 3,
  "google_avatar_available": true
}
```

- `avatar_url = null` jika aktif default. Gunakan URL relatif ke API base/version sesuai standar proyek, **tidak mengungkap storage path**.
- `google_avatar_available` adalah boolean kemampuan restore; jangan expose path internal.
- API binary GET harus memverifikasi JWT, mengirim MIME tepat, cache aman per-user (misal `Cache-Control: private`, ETag/version), dan **tidak** menuntut URL publik permanen.
- `POST`/`PUT` harus menegakkan pemilik sesi, validasi versi; bila stale kembalikan **409** dengan kode stabil misalnya `avatar_version_conflict` dan metadata versi terbaru jika aman.
- Format error ikuti `ApiException`/envelope existing: 401 unauthenticated, 403 akses/akun tidak diizinkan, 422 invalid image, 409 `google_snapshot_unavailable`/version conflict, 5xx fault aman. Jangan bocorkan path/server stack.

### Google photo acquisition

Pertahankan Flutter Firebase ID token exchange yang sudah berjalan. Sumber foto diprioritaskan dari informasi profil yang **dihubungkan ke identitas terverifikasi** pada sisi server (misal foto user/provider Firebase Admin atau claim token terverifikasi bila tersedia). Jika perlu Flutter mengirim **optional** `photo_url` hint dari profil Google/Firebase, **jangan menganggap hint sebagai identitas, jangan mempercayai URL arbitrer**. Laravel tetap wajib memverifikasi token/UID dan hanya mengizinkan domain HTTPS Google yang ditentukan secara eksplisit. Blok redirect ke domain tak dikenal, IP privat/loopback, fetch non-HTTPS, payload besar, dan respons non-gambar. Tetapkan timeout pendek; retry hanya saat login berikutnya. Foto tidak tersedia/fetch gagal bukan kegagalan login.

## 7. Validasi gambar, upload, dan lifecycle storage

- Input JPG, PNG, WebP; maksimal **5 MiB** per file. Validasi ukuran, MIME dari konten, decode gambar valid, dimensi yang aman; jangan percaya nama atau MIME dari client.
- Flutter memilih gambar via galeri/kamera; crop interaktif **1:1**, zoom/drag dan preview. Upload hasil crop, bukan file mentah tanpa konfirmasi.
- Backend re-encode hasil yang masuk menjadi gambar avatar persegi optimal (contoh WebP 512×512), hapus metadata EXIF/geo, gunakan nama random/UUID, folder per GUID dan private disk. Proses gambar berukuran besar dibatasi untuk menghindari decompression bomb.
- File sementara dibersihkan jika validasi/persistensi gagal. Setelah penyimpanan file baru berhasil, update pointer atomik memakai transaction + row lock dan validasi `avatar_version`; hapus file custom lama **setelah commit** dengan mekanisme retryable cleanup. Jangan hapus snapshot Google saat pergantian avatar.
- Untuk operasi default/restore: pointer custom lama dilepas secara atomik, kemudian file custom lama dihapus setelah commit. Jika cleanup gagal, catat untuk retry; jangan rollback preferensi yang sudah sukses.
- Mencegah race: pengambilan Google yang selesai terlambat harus re-check user di DB dalam transaction. Jika snapshot sudah tersimpan/manual choice berubah, jangan overwrite. Tidak ada orphan file yang dibiarkan permanen.
- Foto bukan data rahasia tinggi, tetapi akses file dan log tetap mengikuti kebijakan privasi: tidak expose origin URL, token, storage path, atau detail internal.

## 8. Flutter UI/UX — sesuai wireframe approved

**Profil (`lib/screens/profil_user_screen.dart`)**
- Header scenic time-based V4 dan layout edge-to-edge/status bar tetap. Pertahankan statistik dan Wisata Tersimpan tanpa redesign.
- Identitas: avatar lingkaran ~48–52dp kiri; foto remote bila ada, fallback inisial. Badge kamera kecil menumpang sisi kanan bawah avatar; tap kamera => bottom sheet.
- Tap bagian avatar => preview fullscreen dengan latar gelap, foto besar di tengah, tombol close dan **Ubah Foto Profil**.
- Tap ikon pensil => **Edit Profil fullscreen** yang berbasis editor existing, bukan editor kedua. Preview harus bisa dipanggil dari editor juga.

**Edit Profil**
- Header scenic/biru yang konsisten, tombol back, area foto besar (~106–112dp), tombol `Ganti Foto Profil`, status singkat gambar.
- Field `Nama Lengkap`, `Email` read-only, `Nomor Telepon` menggunakan selector negara dan format internasional yang sudah ada dalam V4. Jangan regresi phone formatting.
- Tombol `Simpan Perubahan` khusus nama/telepon. Aksi `Gunakan Foto` dari crop langsung upload foto secara independen dan menyegarkan avatar di layar.
- Keyboard-safe, scrollable 320/390/430dp, safe area/edge-to-edge, reduced motion, loading/error/empty states. Hindari layout overflow.

**Bottom sheet**
- `Pilih dari Galeri`, `Ambil dengan Kamera`, `Kembalikan Foto Google` hanya bila tersedia, dan `Gunakan Avatar Default`.
- Opsi restore disembunyikan ketika source sudah `google`; `Gunakan Avatar Default` boleh disembunyikan saat sudah default.
- Minta permission seperlunya via picker/kamera; bila denied atau batal, tidak ada perubahan avatar.

**Crop & upload**
- Crop nyata rasio 1:1; support pan/zoom; cancel => no-op; gunakan hasil => upload dengan progress/loading, cegah double submit.
- Upload sukses => perbarui currentUser/session model dan cache/version, kembali ke editor/profil, beri snackbar sukses.
- Upload/network/409 gagal => avatar lama tetap; tampilkan error yang bisa ditindaklanjuti; untuk 409 refetch profil dan minta user mencoba kembali.
- Loading avatar remote => placeholder inisial; error pemuatan => placeholder inisial, bukan broken image.

## 9. Integrasi dan state safety

- Ubah model user di `lib/data/app_data.dart` serta hydrasi dari `AuthSession`/API agar `avatar_source`, `avatar_url`, `avatar_version`, `google_avatar_available` tersedia di Profil/Edit Profil.
- Reuse `AuthSession.requestMultipart` dan generational session guard untuk upload, restore, default, serta image loading; saat refresh JWT tetap bekerja.
- Gunakan authenticated image bytes/endpoint bila gambar private. Jangan membuat `Image.network()` tanpa auth header untuk endpoint protected; hindari kebocoran token lewat URL.
- Saat login Google, informasi avatar berasal dari response Laravel; bila proses snapshot menyusul, refresh hanya profil pemilik sesi sesuai mekanisme proyek. Jangan rewrite Google Sign-In maupun auth flow OTP/JWT.
- Cache image keyed by `(user_guid, avatar_version)`; clear atau invalidate ketika avatar berubah/logout/ganti akun.

## 10. Kandidat file implementasi (inspect first)

**Flutter:** `lib/screens/profil_user_screen.dart`; kemungkinan membuat `lib/screens/edit_profil_screen.dart` untuk refactor editor; komponen avatar/reusable widget baru bila masuk akal; `lib/data/app_data.dart`; `lib/services/auth_session.dart`; `lib/data/business_repository.dart` atau `lib/services/api_client.dart`; file Google Sign-In yang **benar-benar aktif** (inspeksi, jangan asumsi); `pubspec.yaml` hanya bila butuh crop package; `docs/DESIGN.md` sebagai rujukan, tidak perlu ditulis ulang.

**Laravel:** `routes/api.php`, model `User`, migration additive, auth Google handler/service, profile controller/request/resource, avatar service + image normalization/storage cleanup, API auth middleware, feature tests. **Repo backend terpisah dan belum diverifikasi dalam PRD ini:** Codex harus memeriksa path/struktur aktual, tidak menebak nama file dan tidak membuat backend palsu di repo Flutter.

## 11. Acceptance criteria

1. Email registration menampilkan inisial tanpa file dummy.
2. Google user baru mendapat snapshot jika ada; user lama mendapat snapshot pada login berikutnya.
3. Foto tidak ditimpa pada login berikutnya; no continuous sync.
4. Jika foto Google gagal, login tetap berhasil; retry hanya jika belum ada manual choice/snapshot.
5. Pilih default manual persisten; login Google tidak mengembalikan foto.
6. Upload custom, preview, crop, restore Google, kembali ke default bekerja; foto lama custom dibersihkan post-commit, snapshot Google tetap ada.
7. Foto custom dan preferensi avatar persisten lintas logout/login dan restart aplikasi.
8. Upload invalid, cancel, 401, 403, 409, offline, timeout aman dan tidak menghapus foto aktif.
9. Tidak bisa membaca/mengubah avatar user lain; upload/Google picture tidak membuka celah SSRF/unsafe file.
10. UI sesuai wireframe approved, tanpa perubahan layout statistik, scenic header, bottom nav, booking, atau modul lain.

## 12. Testing & agent policy — WAJIB

**Laravel:** QA dan regresi **DIPERBOLEHKAN**, fokus pada auth Google dan role restriction, state machine/avatar preference, user lama, retry, upload validation, akses owner, version conflict, file cleanup dan security. Gunakan `qa_engineer` hanya untuk backend. Tes unit/feature/regresi backend boleh berjalan sesuai kebutuhan. Security reviewer hanya jika risiko upload/integrasi Google membutuhkan pemeriksaan terarah.

**Flutter:** **TIDAK ADA QA AGENT, TIDAK ADA REGRESSION TESTING, TIDAK ADA FULL `flutter test`, TIDAK ADA FULL `flutter analyze`**. Engineer mengimplementasikan langsung berdasarkan PRD dan memeriksa fungsional/perangkat secara ringan hanya bila diperlukan untuk memastikan perubahan berfungsi; jangan buat tugas QA khusus atau loop tes berkali-kali.

**Orkestrasi hemat:** maksimal **2 subagent aktif bersamaan**; gunakan `backend_engineer`, `flutter_engineer`, `qa_engineer` (backend saja) secara sekuensial bila memungkinkan. Jangan memanggil `master_orchestrator`, tujuh agent sekaligus, atau security_auditor tanpa alasan jelas. Gunakan skill TasteSkill/design-taste-frontend yang benar-benar tersedia untuk mobile Flutter bila kompatibel; jangan mengarang skill.

## 13. Delivery

- Implementasi backend dan Flutter lokal, additive migration, dokumentasi API dan catatan file diubah.
- Ringkasan hasil: endpoint + state model, perubahan UI, hasil **tes Laravel saja**, blocker dan catatan manual singkat.
- **Jangan commit, push, deploy, reset working tree, atau menyentuh perubahan tak terkait** tanpa instruksi eksplisit. Jangan meminta klarifikasi untuk detail kecil; ikuti keputusan PRD dan inspeksi repo.
