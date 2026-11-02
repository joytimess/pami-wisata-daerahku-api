# PRD — Multiple Destination Images & Interactive Gallery

**Produk:** Wisata Daerahku  
**Cakupan:** Laravel Backend (API + MySQL + storage) dan Flutter Mobile (User, Admin, Superadmin)  
**Tanggal:** 2 November 2026  
**Status:** Final — siap dijadikan instruksi implementasi; belum merupakan laporan bahwa fitur telah dikerjakan  
**Prioritas:** High  
**Repository Flutter:** https://github.com/koyotot/pami-wisata-daearahku  
**Repository backend:** terpisah; path aktual wajib diaudit di workspace backend sebelum coding

## 1. Tujuan dan masalah

Saat ini satu destinasi wisata hanya menerima satu gambar. Fitur ini memperluas setiap destinasi menjadi galeri **1–10 gambar** yang **memiliki urutan eksplisit dan konsisten**. Admin/Superadmin dapat mengunggah, menambah, menghapus, dan menyusun ulang gambar melalui drag & drop. Gambar urutan pertama otomatis menjadi cover. Pengunjung melihat hero carousel pada Detail Destinasi, lalu dapat membuka fullscreen gallery dengan zoom, swipe, serta thumbnail navigation.

**Tidak termasuk scope:** mengubah logika booking, quota, fasilitas, kategori, autentikasi, review, check-in, FCM, Firebase, atau melakukan redesign luas di luar komponen galeri. Tidak ada commit, push, atau deploy otomatis tanpa instruksi terpisah.

## 2. Keputusan final dari product owner

| Topik | Keputusan mengikat |
|---|---|
| Jumlah foto | Minimal 1 dan maksimal 10 untuk destinasi baru/galeri yang diubah |
| Format upload | JPEG/JPG, PNG, WebP; maksimal 5 MiB per file (5 × 1.024 × 1.024 byte) |
| Optimasi | Otomatis di Laravel; simpan varian thumbnail, display, fullscreen |
| Urutan | Urutan awal sesuai pilihan/upload; Admin/Superadmin dapat long-press drag & drop |
| Cover | Selalu foto dengan `sequence = 1`; tidak ada cover independen |
| Editor admin | Grid 3 kolom, nomor urutan, badge Cover, hapus, tambah gambar, indikator `N/10` |
| Penyimpanan | Satu aksi **Simpan Perubahan**; tidak ada autosave galeri |
| Kegagalan upload | Manual retry; draft yang masih aktif dipertahankan di Flutter |
| Penghapusan | Cleanup file fisik **setelah** perubahan database berhasil commit |
| Migrasi | Foto lama menjadi `sequence = 1` tanpa menghapus file lama |
| Detail user | Hero Image Carousel, swipe horizontal, dot/page indicator dan `1 / N`, tap ke fullscreen |
| Fullscreen | Background hitam, pinch zoom, double-tap zoom, pan saat zoom, swipe foto, tombol tutup, indikator, thumbnail navigation |
| Performa | Kualitas adaptif + image caching + prefetch gambar berikutnya |

## 3. Roles dan akses

- **User (`role = 0`)**: hanya membaca gambar destinasi aktif melalui API katalog/detail. Tidak boleh menambah, menghapus, mengurutkan, atau mengganti gambar.
- **Admin (`role = 1`)**: dapat mengelola galeri **hanya untuk destinasi yang memang boleh dikelolanya**, mengikuti assignment/scope backend terkini. Jangan mengasumsikan semua Admin berhak mengubah semua destinasi.
- **Superadmin (`role = 2`)**: dapat mengelola galeri semua destinasi sesuai kebijakan backend saat ini.
- Validasi role, ownership/assignment, status akun, dan ID destinasi **wajib di Laravel**; UI guard Flutter bukan otorisasi.

## 4. Data model Laravel dan migrasi

### 4.1 Tabel baru `destination_images`

Desain logis yang disarankan (selaraskan tipe GUID dengan schema existing):

| Kolom | Makna |
|---|---|
| `guid` | GUID gambar, primary/unique sesuai konvensi proyek |
| `destination_guid` | FK → GUID destinasi; indexed |
| `sequence` | Integer 1..10, berurutan tanpa gap dalam galeri final |
| `storage_disk` | Disk Laravel yang digunakan |
| `thumbnail_path` | Path file varian thumbnail |
| `display_path` | Path file varian display |
| `fullscreen_path` | Path file varian high quality |
| `width`, `height` | Ukuran master/varian fullscreen sesuai kebutuhan |
| `mime_type`, `bytes` | Metadata hasil optimasi, bukan hanya input klien |
| `created_at`, `updated_at` | Timestamp |

- Index `(destination_guid, sequence)` dengan unique constraint jika penataan ulang menggunakan **temporary sequence** yang tidak melanggar unique index; hindari tabrakan saat swap posisi.
- Tambahkan `gallery_version` integer default `0` pada tabel destinasi untuk optimistic concurrency control.
- Relasi `Destination hasMany DestinationImage ordered by sequence`; cover diturunkan dari urutan pertama, **tanpa boolean `is_cover`** yang berpotensi inkonsisten.
- Jangan menghapus kolom atau API image lama dalam rollout ini. Migrasi bersifat **additive dan backward-compatible**.
- Destinasi existing yang belum memiliki foto tetap boleh bertahan di database sebagai legacy exception; validasi 1–10 diterapkan pada **create baru dan update galeri**, tidak sebagai constraint yang menggagalkan migrasi global.

### 4.2 Backfill legacy (aman dan idempotent)

1. Audit sumber single-image aktual di backend (nama kolom, disk, URL/path, storage visibility).
2. Buat migration tabel baru, kolom `gallery_version`, dan relasi model tanpa menghapus existing data.
3. Jalankan **backfill command** yang dapat diulang: untuk tiap destinasi yang punya gambar lama tetapi belum mempunyai galeri, insert satu record `sequence = 1` yang menunjuk file/derivative hasil migrasi; catat status dan error per destinasi.
4. **Jangan menghapus, memindahkan secara destruktif, atau menimpa file lama** selama periode kompatibilitas. Optimasi boleh membuat turunan baru; catat file mana yang shared/referenced.
5. Destinasi tanpa foto: tetap dapat di-read dengan `images: []`, `cover_image: null`, dan placeholder Flutter; saat galeri diedit/destinasi dibuat, minimal satu wajib dipenuhi.
6. Pastikan backfill bisa dijalankan ulang tanpa menghasilkan duplikasi. Verifikasi jumlah, sequence, URL, dan checksum/eksistensi file; siapkan rollback aplikasi yang tidak menghancurkan data yang baru dibuat.

## 5. Kontrak API dan kompatibilitas

**Prefix existing:** `/api/v1`. Backend harus mengaudit route, middleware, resource dan request classes yang aktif terlebih dahulu. Gunakan gaya respons Laravel saat ini (`data` wrapper untuk object).

### 5.1 GET katalog/detail

- `GET /api/v1/destinations`
- `GET /api/v1/destinations/{destination_guid}`
- Endpoint admin list/detail terkait harus mengembalikan representasi galeri bila dibutuhkan editor.

Contoh shape **usulan** (nama existing single-image field tidak boleh diputus sebelum audit):

```json
{
  "data": {
    "guid": "destination-guid",
    "gallery_version": 4,
    "cover_image_url": "https://api.example.com/media/cover-display",
    "images": [
      {
        "guid": "image-guid-1",
        "sequence": 1,
        "is_cover": true,
        "urls": {
          "thumbnail": "https://api.example.com/media/thumb-1",
          "display": "https://api.example.com/media/display-1",
          "fullscreen": "https://api.example.com/media/full-1"
        }
      },
      {
        "guid": "image-guid-2",
        "sequence": 2,
        "is_cover": false,
        "urls": {
          "thumbnail": "https://api.example.com/media/thumb-2",
          "display": "https://api.example.com/media/display-2",
          "fullscreen": "https://api.example.com/media/full-2"
        }
      }
    ]
  }
}
```

- `images` selalu array berurutan ascending `sequence`; `is_cover` **derived** (`sequence === 1`), bukan stored state terpisah.
- `cover_image_url` dan field gambar tunggal legacy tetap mengarah ke foto pertama demi kompatibilitas dengan kartu katalog, favorit, booking, dan versi Flutter lama. Gunakan **nama field yang sudah ada** untuk legacy; contoh di atas bukan instruksi menghapus/mengganti nama existing API.
- Saat 0 gambar legacy: `images: []`, cover nullable, tampil placeholder. Jangan menampilkan URL palsu.
- URL/identitas gambar mesti memiliki mekanisme cache-busting saat isi file berubah (`image GUID` immutable/path baru atau revision); jangan gantikan isi path lama diam-diam.
- Jika media existing diambil melalui autentikasi `ApiImage`, pertahankan pola header yang aman atau sediakan endpoint/signed URL yang terotorisasi. **Jangan taruh JWT dalam query string URL**.

### 5.2 Create destinasi

- Pertahankan route create existing (`POST /api/v1/destinations` menurut README), gunakan `multipart/form-data` dan field lain yang sudah ada.
- Terima `images[]` (1..10), urutan sesuai manifest/urutan file yang dikirim; urutan server harus deterministik.
- Create destinasi + metadata galeri harus atomik secara database. Jangan buat destinasi aktif tanpa galeri karena sebagian image gagal.

### 5.3 Edit galeri bersama form destinasi — satu tombol simpan

**Preferensi kontrak:** perluas endpoint update existing agar metadata destinasi dan perubahan galeri disimpan sebagai **satu logical save**. Hindari dua request terpisah (update destinasi, lalu edit galeri) yang bisa menghasilkan form tersimpan setengah. Jika kompleksitas existing route menuntut endpoint galeri tersendiri, pastikan editor UI menyatakan scope penyimpanannya dengan jujur dan tidak menjanjikan atomicity lintas endpoint.

Kirim multipart dengan:

- Semua field destinasi yang berubah sesuai existing request/validation.
- `gallery_version` dari GET terakhir (required untuk mutation).
- `gallery_manifest`: JSON array **berurutan**; tiap item `{ "image_guid": "..." }` (pertahankan existing) atau `{ "upload_key": "local-uuid" }` (gambar baru). Setiap existing ID harus milik destinasi ini. Tidak boleh duplikat ID atau key.
- `new_images[local-uuid]`: file untuk setiap `upload_key` baru, tepat satu file per key; tidak boleh orphan upload key. Gambar yang tidak muncul lagi di manifest berarti dihapus setelah save berhasil.
- `Idempotency-Key`: UUID unik untuk satu aksi simpan; **gunakan key dan payload yang sama** saat retry yang hasilnya tidak pasti.

Contoh manifest:

```json
[
  { "image_guid": "old-image-2" },
  { "upload_key": "new-photo-a" },
  { "image_guid": "old-image-1" }
]
```

Hasilnya: posisi 1 = `old-image-2` menjadi cover; posisi 2 = foto baru; posisi 3 = `old-image-1`. Semua gambar lain yang sebelumnya ada dianggap dihapus. Backend menormalisasi `sequence = 1,2,3`.

**Catatan PHP/Laravel:** beberapa setup PHP tidak mem-parsing multipart `PATCH` secara native. Implementasi boleh memakai `POST` plus `_method=PATCH` ke URL update existing, sesuai pola Laravel dan `AuthSession` yang sudah ada; verifikasi perilaku nyata sebelum finalisasi client.

### 5.4 Respons/error

| HTTP | Perilaku wajib |
|---|---|
| `200/201` | Return data destinasi terbaru termasuk urutan dan gallery_version baru |
| `401` | Belum login / sesi tidak valid |
| `403` | Role/assignment tidak berhak |
| `404` | Destinasi / gambar tidak ditemukan di scope yang diizinkan |
| `409` | `gallery_version` stale, conflicting update, atau idempotency key digunakan untuk payload berbeda; data remote tidak ditimpa |
| `413` | Total payload melewati batas webserver/PHP; tampilkan pesan actionable |
| `422` | Format/ukuran/file rusak, manifest salah, foto <1 atau >10, ID asing, duplikat, dst. |
| `5xx` / timeout | Jangan klaim gagal total apabila hasil commit belum pasti; lakukan rekonsiliasi via GET + key dan tawarkan manual retry aman |

Pastikan error response mengikuti struktur error standar backend. Header key idempotency + status tersimpan dapat digunakan untuk membedakan request replay yang sudah berhasil dari error jaringan. Jangan retry dengan membuat key baru untuk operasi yang sama saat status sebelumnya belum pasti.

## 6. Transaksi, konsistensi dan cleanup file

**Alur server yang diharapkan:**

1. Autentikasi + otorisasi; validasi manifest/bytes/MIME nyata dan batas foto, serta batas total request. Terapkan rate limit dan file dimension/decompression safeguards.
2. Tangani idempotency record secara aman (unique key scoped ke actor+action/resource dan payload hash) agar submission ganda tidak membuat image ganda.
3. Simpan file baru terlebih dahulu ke **temporary/staging storage**, lalu buat versi optimal sebelum mengubah galeri aktif. Jika tahap ini gagal, delete temp dan kembalikan error; jangan menyentuh galeri aktif.
4. DB transaction: lock row destinasi (`lockForUpdate`), cek `gallery_version`, assignment, dan keberadaan/ownership image GUID; lakukan update metadata form + galeri secara atomik; normalisasi sequence 1..N; increment gallery_version; commit. Jika unique `(destination_guid, sequence)`, reorder menggunakan temporary non-colliding sequence dalam transaksi.
5. **Setelah commit sukses**, jadwalkan cleanup file gambar lama yang sudah tidak direferensikan. Pembersihan harus retryable/idempotent; tidak boleh menghapus file yang dipakai foto lainnya atau file legacy yang masih dibutuhkan rollback.
6. Saat DB transaction rollback/gagal: hapus semua file staging/optimasi dari request tersebut; gambar aktif tetap utuh. Sediakan scheduled orphan cleanup untuk file temp yang tertinggal setelah process crash.
7. Ketika HTTP response hilang padahal commit sukses: retry dengan `Idempotency-Key` yang sama menghasilkan hasil yang sama, bukan mutasi baru. Konflik versi dari admin lain harus mengembalikan `409`, bukan last-write-wins.

**Keterbatasan teknis:** operasi filesystem tidak ikut transaction MySQL. Klaim atomicity berlaku untuk **state galeri yang terlihat** di database; proses finalisasi/cleanup file harus memakai staging, after-commit/outbox/retry agar konsisten secara eventual. Jangan mengklaim satu transaksi ACID mencakup storage fisik.

**Upload limit:** 10 × 5 MiB = maksimal 50 MiB hanya untuk file, belum overhead multipart. Verifikasi dan sesuaikan `upload_max_filesize`, `post_max_size`, memory/time limit, reverse proxy/webserver, disk quota, dan PHP image library berdasarkan hosting. Jangan meminta user mengupload 10 sekaligus kalau server saat ini tidak bisa menerimanya; beri error jelas dan strategi kompatibel tanpa mengurangi batas produk.

## 7. Pengolahan dan distribusi gambar

- Validasi ekstensi **dan** signature/MIME konten, ukuran <=5 MiB/file, dimensi wajar (misalnya batas 24 MP configurable), serta reject corrupt/decompression bombs; strip metadata sensitif (mis. GPS EXIF) dan hormati orientation.
- Buat tiga varian dengan aspek rasio asli (tanpa crop paksa): rekomendasi **thumbnail max 480 px**, **display max 1280 px**, **fullscreen max 2048 px** pada sisi terpanjang, **tanpa upscale**. Kualitas encoder dikalibrasi agar visual tetap tajam saat zoom; full screen bukan jaminan resolusi asli tak terbatas.
- Format output WebP/JPEG dengan fallback jika codec tidak didukung; transparansi PNG dijaga bila dibutuhkan. Pilih Imagick/GD berdasarkan modul yang benar-benar tersedia di hosting. Jangan menambah library berat tanpa audit kompatibilitas deployment.
- Simpan metadata dan path relatif, jangan menerima arbitrary `storage_path` dari klien. Hindari nama file user sebagai path langsung.
- Cover katalog memakai thumbnail/display yang sesuai; detail memakai display; fullscreen memakai fullscreen.
- Tetapkan Cache-Control/ETag dengan key URL immutable atau versi konten. Tidak boleh men-cache media privat secara publik tanpa pemeriksaan auth.

## 8. Flutter — domain/model, state, API

Repo Flutter yang sudah ada menggunakan `BusinessRepository`, `ApiClient`, `AuthSession` dan state berbasis Flutter; **ikuti arsitektur ini**, jangan memperkenalkan data bisnis Firebase/Firestore baru.

- Tambahkan model `DestinationImage` (`guid`, `sequence`, `urls`); upgrade parser model `Destination` di `lib/data/business_repository.dart` atau file model existing.
- Pastikan model tetap membaca field single-image legacy apabila `images` belum tersedia (masa rolling upgrade); setelah server baru tersedia, `images` adalah source of truth untuk galeri.
- Tambahkan layer admin untuk draft gallery: item existing dengan `image_guid`, item baru dengan `upload_key` + bytes/file/XFile, urutan lokal, status hapus/validasi, `gallery_version`, dan `Idempotency-Key` per save. Tetap gunakan transport authenticated saat mengirim multipart; jangan membuat HTTP client tanpa refresh JWT/error handling.
- Simpan draft **in-memory selama screen/editor masih hidup**. Saat request gagal, draft + selection + urutan tetap ada; disable double-submit. Jangan berjanji bahwa draft bertahan setelah app force close.
- Jika request timeout: reconcile dengan GET terbaru/idempotency status. Jika sudah sukses, sinkronkan editor dan jangan re-upload; jika belum, manual retry memakai operation/key yang sama. Ketika user mengubah draft setelah failure, operasi baru harus mendapat key baru.
- Konflik `409`: beri dialog bahwa galeri berubah di perangkat/admin lain, tawarkan refresh dengan penjelasan bahwa perubahan lokal perlu direkonsiliasi; **jangan silent overwrite**.

## 9. Flutter Admin/Superadmin — editor galeri

**Lokasi UI:** form tambah/edit destinasi (sekarang `lib/screens/tambah_wisata_screen.dart` dan alur list/edit `lib/screens/daftar_wisata_screen.dart`, audit actual code sebelum patch).

- Tampilkan **grid 3 kolom** dengan thumbnail crop `cover` hanya untuk tile editor; long press drag/drop reorder (jangan trigger scroll/klik secara tak sengaja).
- Tampilkan nomor `1..N`, badge **Cover** khusus posisi 1, tombol hapus di setiap item, tombol `+ Tambah Foto`, serta penghitung `N/10`.
- Pilih banyak foto (`image_picker` existing bila mendukung), validasi format/5 MiB/10 item **sebelum** kirim; preview lokal untuk item baru; urutan awal sesuai urutan yang telah dipilih dan tampil, kemudian boleh dipindahkan.
- Jika `N = 10`, nonaktifkan tambah dan beri penjelasan; jika `N = 1`, penghapusan terakhir yang menghasilkan 0 harus ditolak di client **dan server**.
- Tombol **Simpan Perubahan** hanya aktif ketika form valid; menampilkan progress loading dan disabled double submission. Jika gagal, tampil error + tombol **Coba Lagi**, draft tetap hidup. `Batal` membuang draft dengan konfirmasi jika terdapat perubahan.
- Foto remote yang dihapus tidak hilang permanen hingga save berhasil; pada keberhasilan refresh dari respons server, cover dan sequence diperbarui di semua layar.
- Ikuti warna, font, spacing, border radius, komponen, status bar/edge-to-edge yang berlaku menurut `docs/DESIGN.md` **dan source tema paling baru**. `docs/DESIGN.md` adalah audit snapshot yang mungkin memuat implementasi lama; jangan menghidupkan kembali tema/konvensi yang sudah diganti di V4.

## 10. Flutter User — hero carousel pada Detail Destinasi

**Target utama:** `lib/screens/detail_wisata_screen.dart`, dengan komponen galeri reusable bila sesuai.

- Area gambar utama di bagian atas menjadi **PageView horizontal** yang menampilkan foto **berurutan dari API**.
- Di atas gambar tampil **page counter** `1 / N` dan dot/page indicator. Sembunyikan affordance multiple-page yang tidak berguna ketika `N = 1` (counter opsional tetap boleh), jangan tampil dot palsu.
- Tap pada foto `index = i` membuka fullscreen **pada index `i`**, bukan kembali ke gambar pertama. Kembali dari fullscreen mempertahankan posisi carousel.
- Carousel image menggunakan varian **display** dan `BoxFit.cover` sesuai hero existing; fullscreen menggunakan `BoxFit.contain` supaya tidak crop paksa.
- Tetap edge-to-edge mengikuti UI V4; status bar, tombol navigasi aplikasi, teks dan area aman tidak boleh tertutup gambar/interaksi.
- Bila `images=[]` pada destinasi legacy atau semua gambar gagal dimuat, sediakan placeholder/error/retry tanpa crash. Pada foto tertentu gagal, foto lain tetap bisa di-swipe.

## 11. Flutter User — fullscreen gallery

- Buka viewer modal/fullscreen immersive berlatar hitam, tombol **X** yang dapat ditekan, indikator `i / N`, serta **horizontal thumbnail strip** di bawah. Thumbnail aktif diberi border primary yang sesuai desain Flutter aktual.
- Gesture: pinch-to-zoom, double-tap untuk toggle zoom, pan ketika diperbesar, horizontal swipe antar gambar ketika belum zoom/gesture memungkinkan. **Utamakan pan saat zoom** agar pergantian foto tidak terjadi tak sengaja; pindah foto via thumbnail tetap tersedia.
- Memilih thumbnail mengubah foto utama dan indikator secara sinkron; scroll thumbnail menjaga item aktif terlihat. Kembali ke detail mempertahankan index foto terakhir (tetapkan sinkronisasi kembali ke hero).
- Saat membuka viewer, pakai display image sebagai placeholder instan bila cached, lalu naikkan ke versi **fullscreen** dengan transisi yang tidak berkedip. Gunakan loading/error state per image.
- `Escape`/Android system back dan tombol X menutup viewer; kontrol berada di safe-area; gesture tidak mengganggu edge-to-edge. Matikan interaksi drag carousel saat transform zoom aktif bila perlu.
- Pertimbangkan hero transition hanya bila aman terhadap shared image state dan tidak menimbulkan layout jump/black frame. Hormati reduced motion.

## 12. Caching, prefetch, dan kegagalan jaringan

- Thumbnail untuk katalog/admin strip; display untuk hero; fullscreen high quality hanya saat viewer diakses.
- Cache menggunakan URL/identitas yang **versioned dan immutable**; perubahan gambar tidak boleh menampilkan konten lama. Jangan gunakan token rahasia sebagai cache key yang tercetak di log.
- Prefetch foto sebelum/sesudah index aktif secara **terbatas** (mis. ±1) untuk mengurangi memory pressure; cancel/ignore respons stale setelah berpindah destinasi.
- Cache authenticated media harus mempertahankan headers/session boundaries; cek keterbatasan widget `ApiImage` existing. Jangan langsung mengganti dengan `Image.network` jika endpoint membutuhkan bearer token.
- Decode thumbnail/display ke resolusi device yang masuk akal; jangan decode semua 10 fullscreen images sekaligus. Fallback saat offline memakai cache jika sudah tersedia; error/retry terarah jika belum.
- Setelah admin save sukses, invalidasi/refetch detail, katalog, favorit, dan layar admin terkait supaya cover terbaru tampil konsisten.

## 13. Lokasi file kandidat — diverifikasi terhadap README Flutter publik

**Flutter:**

- `lib/screens/detail_wisata_screen.dart` — hero carousel, route fullscreen.
- `lib/screens/tambah_wisata_screen.dart` — create/edit upload + editor grid.
- `lib/screens/daftar_wisata_screen.dart` — tampilan cover + titik masuk edit.
- `lib/screens/beranda_user_screen.dart` dan `lib/screens/cari_wisata_screen.dart` — cover katalog/search tetap pakai image pertama.
- `lib/data/business_repository.dart` — parser model + API contract galeri.
- `lib/data/admin_state.dart`, `lib/data/catalog_state.dart` — draft/mutation/refetch sesuai state architecture yang ada.
- `lib/services/api_client.dart`, `lib/services/auth_session.dart` — multipart, idempotency, auth dan error mapping; modifikasi hanya jika dibutuhkan.
- `lib/data/app_data.dart`, `lib/models/wisata.dart`, `lib/widgets/` — compat mapping/ApiImage; lokasi widget final wajib dicari di source.
- `lib/widgets/destination_hero_gallery.dart`, `lib/widgets/fullscreen_destination_gallery.dart`, `lib/widgets/admin_destination_gallery_editor.dart` — **file baru yang disarankan**, bukan klaim sudah ada.
- `docs/DESIGN.md`, `docs/prd/`, `test/` — acuan UI, dokumen, dan pengujian.

**Laravel (nama file kandidat, bukan hasil audit backend):**

- `database/migrations/*_create_destination_images_table.php`, `*_add_gallery_version_to_destinations_table.php`.
- `app/Models/DestinationImage.php`, relasi existing `Destination`.
- `app/Http/Requests/*Destination*Request.php` — validasi multipart/manifest.
- `app/Services/*DestinationGallery*Service.php` — orchestration transaksional & image processing.
- `app/Http/Resources/*Destination*Resource.php` — ordered images + legacy cover.
- `app/Jobs/*GalleryCleanup*.php`, backfill `app/Console/Commands/*` — retryable cleanup/migrasi.
- `routes/api.php` dan existing controller — thin controller, route auth/authorization tetap.
- `tests/Feature/`, `tests/Unit/` — contract, concurrency, security, migration tests.

## 14. Delegasi agent dan skills (wajib disesuaikan dengan file agent yang benar-benar ada)

1. **Lead / Master Orchestrator** (`.codex/agents/master_orchestrator.toml`, jika ada): audit backend + Flutter, breakdown task, urutan implementasi, integrasi; jangan mengambil keputusan produk baru yang bertentangan dengan PRD.
2. **System Architect** (`.codex/agents/system_architect.toml`): review relasi, migrasi zero-loss, schema/versioning, kontrak API, idempotency, konsistensi filesystem vs DB, rencana rollout.
3. **Backend Engineer** (`.codex/agents/backend_engineer.toml`): implement Laravel migration, service, API Resource, optimasi gambar, cleanup, legacy fallback; controller tipis.
4. **Flutter Engineer** (`.codex/agents/flutter_engineer.toml` jika ada; jika nama berbeda, gunakan agent Flutter yang terpasang): editor grid, carousel, fullscreen viewer, model/state/API dan rendering performa.
5. **QA Engineer** (`.codex/agents/qa_engineer.toml`): kontrak API, integration/widget/gesture tests, regresi, retry, actual emulator.
6. **Security Auditor** (`.codex/agents/security_auditor.toml`): IDOR, role/assignment, upload payload, path traversal, JWT handling, concurrency, race conditions, file cleanup.
7. **Mobile UI skill**: wajib rujuk **taste-skills / design-taste-frontend untuk mobile** yang tersedia di workspace, `docs/DESIGN.md`, dan source design/theme terbaru. Jika skill atau agent path tidak ditemukan, laporkan dengan jelas, bukan mengklaim telah digunakan.

## 15. Rencana implementasi bertahap

**Phase 0 — Audit dan contract freeze**: temukan source single image backend, schema, routes, API Resource, file writer/storage, image renderer Flutter, nilai tema aktual, assignment admin, dan batas hosting. Buat kontrak JSON/multipart final mengikuti existing naming.

**Phase 1 — Backend**: migration additive + backfill idempotent + image processing + database versioning + atomic edit + idempotency + cleanup + legacy response. Bukti test backend dan contoh response harus ada sebelum Flutter mutation diaktifkan.

**Phase 2 — Flutter Admin/Superadmin**: model, draft in-memory, multi-picker, grid 3 kolom, long-press reorder, cover dynamic, validation, single-save, manual retry dan konflik 409.

**Phase 3 — Flutter User**: cover mapping, hero carousel, fullscreen viewer, zoom/pan/swipe, thumbnail selector, cache/prefetch, placeholder, sinkron index.

**Phase 4 — QA/hardening**: integrasi real Laravel/MySQL, image storage, emulator Android, foto lama, stress test/network loss, media performance, security & regression.

Tidak mengubah fitur booking dan flow bisnis lainnya selama fase ini.

## 16. Acceptance criteria & test matrix wajib

### Backend

- [ ] Buat destinasi dengan 0 foto → `422`; 1 dan 10 → sukses; 11 → `422`.
- [ ] Format invalid, file >5 MiB, corrupted image, spoofed MIME, overlarge decoded dimensions → `422` (atau limit webserver `413` bila payload terlalu besar).
- [ ] Reorder 3 foto → `sequence=1,2,3` stabil; cover berubah otomatis; read API selalu return ascending sequence.
- [ ] Edit campuran add/delete/reorder → satu commit state; DB rollback tidak mengubah galeri aktif dan menghapus staging files.
- [ ] Gambar yang dihapus hilang dari API sesudah commit; file fisik dibersihkan via retryable cleanup **setelah** commit.
- [ ] Legacy image backfill menjadi urutan 1; rerun command tidak duplikasi; original image file tetap ada.
- [ ] Legacy dest tanpa gambar tidak membuat backfill gagal; create/update baru tetap memerlukan >=1 gambar.
- [ ] User role 0 tidak dapat write; Admin tidak dapat ubah destinasi di luar scope; Superadmin sesuai policy; cross-destination image ID ditolak.
- [ ] Dua admin edit versi sama: satu commit, satu `409`, tidak ada lost update atau sequence duplicate.
- [ ] Double request/timeout + retry key sama: satu hasil tersimpan, tidak ada file/image duplikat.
- [ ] CRUD/response existing dan field legacy masih berjalan; media URL benar dan cache invalidated.
- [ ] Simulasi job cleanup gagal, temp upload orphan, restart proses, full disk → recovery aman dan terukur.

### Flutter

- [ ] Grid 3 kolom; long-press reorder, badge Cover mengikuti urutan pertama; counter `N/10` benar.
- [ ] Multi-pick 1..10; file invalid ditolak sebelum save; delete foto terakhir dicegah; Batal membuang draft dengan konfirmasi.
- [ ] Simpan sekali, tombol disabled saat loading; gagal/timeout mempertahankan draft; retry manual tidak menggandakan foto.
- [ ] Conflict 409 memunculkan penanganan yang jelas; tidak overwrite perubahan admin lain.
- [ ] User swipe hero dari foto 1 ke N, indikator sinkron; tap index i membuka fullscreen index i; back mengembalikan index yang benar.
- [ ] Fullscreen pinch/double-tap/pan/thumbnail/swipe; pan zoom tidak keliru menjadi page swipe; tombol close, Android back dan safe area benar.
- [ ] 1 foto: UI tidak menyesatkan; 0 foto legacy: placeholder aman; gagal muat satu foto: foto lain tetap bisa dilihat.
- [ ] Thumbnail/display/fullscreen sesuai konteks; prefetch terbatas; scrolling/zoom tidak jank pada perangkat/emulator target.
- [ ] Setelah admin edit, cover di beranda, cari, daftar wisata, dan detail ikut berubah setelah refetch/cache refresh.
- [ ] `flutter test`, `flutter analyze` (bedakan lint existing dan regresi baru), backend unit/feature, real MySQL concurrency, dan E2E API → Flutter dilakukan dan hasilnya dilaporkan apa adanya.
- [ ] Android 320/390/430dp serta font scaling, landscape bila didukung, long label, loading/empty/error, edge-to-edge/status bar/navigation gesture diverifikasi.

## 17. Definition of Done dan format handoff

Selesai **hanya bila**: migration aman, backfill terverifikasi, kontrak lama kompatibel, API gallery 1..10 berjalan, upload optimasi aman, editor dan viewer Flutter teruji, otorisasi dan idempotency terbukti, serta regresi business flow existing tidak berubah.

Laporan implementasi harus memuat:

1. Ringkasan perubahan per Laravel/Flutter dan daftar **file aktual** yang berubah.
2. Migration dan backfill command + verifikasi before/after + backup/rollback plan.
3. Kontrak request/response final dan contoh curl multipart yang benar-benar dipakai.
4. Hasil test (angka pass/fail/skip), lint baru vs existing, emulator dan concurrency real MySQL, keterbatasan yang belum diuji.
5. Risiko/perhatian deploy (PHP upload caps, storage links, GD/Imagick, queue/cron cleanup, disk usage), plus tindakan follow-up.
6. Pernyataan bahwa **tidak ada commit/push/deploy** kecuali user memberikan instruksi eksplisit.

## 18. Catatan sumber dan batas audit

Repo Flutter publik dan README terverifikasi pada 2 November 2026. README menuliskan struktur `lib/screens/detail_wisata_screen.dart`, `lib/screens/tambah_wisata_screen.dart`, `lib/data/business_repository.dart`, `lib/data/admin_state.dart`, alur `ApiImage`, serta backend Laravel di repo terpisah. `docs/DESIGN.md` terverifikasi tersedia, tetapi berisi **audit snapshot** dan menyebut keterbatasan/sisa arsitektur lama; source code terbaru V4 tetap harus menjadi acuan implementasi. Path dan route **backend** di PRD ini masih desain kandidat, bukan klaim telah dibaca.

Referensi:
- https://github.com/koyotot/pami-wisata-daearahku
- https://raw.githubusercontent.com/koyotot/pami-wisata-daearahku/main/docs/DESIGN.md
