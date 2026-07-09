# Rekap Perbaikan Performance — 9 Juli 2026

Rekap audit performance dan perbaikan yang sudah diterapkan pada aplikasi census-dev.

## Ringkasan

| # | Masalah | Dampak | Status |
|---|---|---|---|
| 1 | `config.json` (183KB) di-parse ulang tiap request | High | Fixed |
| 2 | Semua model di-instantiate tiap request walau gak kepake | Medium-High | Fixed |
| 3 | Transaksi DB dibuka buat semua request, termasuk GET/read | Medium | Fixed |
| 4 | Session file di-write tiap request API, padahal gak kepake | Low-Medium | Fixed |
| 5 | `SELECT *` default di fungsi `get()` non-QUERYBUILDER | Medium (belum urgent) | Belum difix |
| 6 | Gak ada index buat kolom ownership non-FK | Low sekarang, Medium kalau dipakai | Belum difix |

## Detail Fix

### 1. Cache `config.json`
- **Sebelum**: `Bootloader.php` baca + `json_decode` file 183KB tiap ada request masuk, padahal isinya cuma berubah kalau ada yang edit skema.
- **Fix**: file baru `lindsey_engine.php` (fungsi `lindsey_load_config()`) — convert `config.json` jadi file PHP array (`Library/config.cache.php`), auto ke-generate ulang cuma kalau `config.json` berubah (dicek lewat `filemtime`). PHP array kena opcache, jauh lebih cepat dari parsing JSON ulang.

### 2. Lazy model instantiation
- **Sebelum**: `Bootloader.php` bikin object buat SEMUA model yang terdaftar di config tiap request, walau endpoint yang kena cuma butuh 1 model.
- **Fix**: class baru `LindseyLazyContainer` (di `lindsey_engine.php`) — model beneran di-construct pas pertama kali diakses, bukan di awal request. `Bootloader.php` sekarang cuma bikin peta nama→class (murah), bukan langsung instantiate semua.

### 3. Transaksi cuma buat endpoint yang nulis data
- **Sebelum**: `Bootloader.php` buka transaksi DB buat semua request, termasuk GET/read yang gak nulis apa-apa.
- **Fix**: `beginTransaction()` sekarang cuma jalan kalau endpoint tipe write (`$is_action_endpoint`).

### 4. Hapus session di entrypoint API
- **Sebelum**: `index.php` (entrypoint API) jalanin `session_start()` + nulis `$_SESSION['last_activity']` tiap request, padahal auth API pakai `X-Api-Key` header, bukan session. Ini bikin PHP nulis file session ke disk tiap panggilan API, sia-sia.
- **Fix**: `session_start()` dan logic session dihapus total dari `index.php` (dicek dulu: gak ada satupun endpoint `channels/` atau `Bootloader.php` yang pakai `$_SESSION`).

## File yang Berubah

- `Bootloader.php` — pakai config cache, model lazy container, transaksi kondisional
- `index.php` — hapus session_start
- `lindsey_engine.php` (baru) — helper cache config + class `LindseyLazyContainer`

Catatan: `_LindseyEngine.php` (file engine asli) sengaja gak disentuh, semua logic baru ditaruh terpisah di `lindsey_engine.php`.

## Verifikasi

- Endpoint API dites langsung pakai token sementara (dibuat dan dihapus lagi setelah tes) — data ke-return normal, gak ada error.
- Cache file `Library/config.cache.php` kebentuk otomatis dan ke-generate ulang kalau `config.json` diubah.
- Response `X-API-Key header missing` masih muncul normal buat request tanpa token — gerbang auth gak keganggu.

## Belum Dikerjakan

- **`SELECT *` di fungsi `get()`**: belum ada opsi buat milih kolom tertentu di fungsi ini (beda sama `_get()`/QUERYBUILDER yang udah bisa). Belum urgent karena skema masih kecil, tapi jadi masalah kalau tabel nambah kolom besar (text/gambar).
- **Index buat kolom ownership**: `setup.php` cuma bikin index buat PRIMARY KEY dan foreign key, gak buat kolom ownership biasa. Sekarang belum masalah karena semua model `ownership` masih kosong — begitu ada yang diisi, query ke kolom itu bisa lambat (full table scan) kalau belum diindex.

## Rekomendasi

`Library/config.cache.php` itu file auto-generated, hasil convert `config.json` — sebaiknya ditambahkan ke `.gitignore` biar gak ke-commit ke repo.
