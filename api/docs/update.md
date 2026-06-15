# Panduan Penggunaan Setup API

Panduan langkah demi langkah tentang **apa yang harus Anda lakukan** untuk
berinteraksi dengan `api/setup.php` — dari menyiapkan kredensial, menanda-tangani
request, sampai menjalankan migrasi schema lewat HTTP.

Endpoint ini **hanya untuk migrasi schema** pada aplikasi yang **sudah ter-install**.
Untuk instalasi pertama kali (membuat database, `_db_config.php`, user admin, dan
secret), lihat [`install.md`](install.md).

---

## Gambaran alur

Migrasi dilakukan dalam **2 fase**:

```
  1. PLAN   → tanyakan ke server: "apa saja perubahan schema yang dibutuhkan?"
                server membalas daftar SQL + sebuah plan_token
  2. APPLY  → kirim balik plan_token + id perubahan yang Anda setujui
                server mengeksekusi hanya yang disetujui
```

Server tidak menyimpan apa pun di antara dua request. Jadi setiap kali apply,
server menghitung ulang perubahannya dan mencocokkan `plan_token` — kalau schema
sudah berubah sejak plan, request ditolak (`409`) dan Anda harus plan ulang.

---

## Langkah 0 — Yang harus disiapkan dulu

Sebelum bisa memakai API ini, pastikan:

1. **Aplikasi sudah di-install minimal sekali.** Lewat CLI:

    ```bash
    php setup.php install <url-config>
    ```

    atau lewat HTTP dengan endpoint bootstrap [`api/install.php`](install.md).
    Langkah ini membuat database auth, user admin, dan **secret** untuk API
    (`$setup_hmac_secret`) di file `_db_config.php`. Endpoint `api/setup.php`
    **tidak** bisa melakukan bootstrap pertama kali ini.

2. **Anda punya nilai secret-nya.** Buka `_db_config.php` di server, cari baris:

    ```php
    $setup_hmac_secret = '....';
    ```

    Kalau belum ada (instalasi lama), tambahkan manual:

    ```bash
    php -r "echo bin2hex(random_bytes(32));"   # hasilkan 64 karakter hex
    ```

    lalu simpan ke `_db_config.php`.

3. **Anda tahu URL endpoint-nya**, contoh:

    ```
    https://server-anda/app-runner/api/setup.php
    ```

4. **Anda punya URL config** yang ingin diterapkan (artefak JSON yang sama yang
   dipakai CLI), contoh `https://contoh.com/config.json`.

> ⚠️ **Jaga kerahasiaan secret.** Siapa pun yang punya secret bisa mengubah
> schema database (termasuk `DROP TABLE`) lewat HTTP. Jangan pernah menaruh
> secret di kode front-end / browser. Jalankan pemanggilan API ini dari sisi
> server / mesin tepercaya saja (skrip deploy, backend internal, terminal admin).

---

## Langkah 1 — Pahami cara menanda-tangani request (WAJIB)

Setiap request **harus** menyertakan 2 header:

| Header              | Isi                                                               |
| ------------------- | ----------------------------------------------------------------- |
| `X-Setup-Timestamp` | Waktu Unix sekarang (detik). Maksimal beda 300 detik dari server. |
| `X-Setup-Signature` | `HMAC-SHA256( secret, timestamp + "\n" + isi-body )` dalam hex.   |

Tiga hal yang sering bikin gagal (`401`):

1. **Tanda-tangani body yang persis Anda kirim.** Susun JSON sekali, tanda-tangani
   string itu, lalu kirim string yang sama. Jangan biarkan library HTTP menyusun
   ulang JSON setelah ditanda-tangani.
2. **Pakai timestamp yang sama** untuk header `X-Setup-Timestamp` dan untuk
   string yang ditanda-tangani.
3. **Jam mesin Anda harus akurat** (sinkron NTP). Jam yang meleset > 300 detik
   akan ditolak.

---

## Langkah 2 — Kirim request PLAN

Tanyakan perubahan apa yang dibutuhkan. Belum ada yang ditulis ke database di
tahap ini.

**Body yang dikirim:**

```json
{
	"action": "plan",
	"config_url": "https://contoh.com/config.json"
}
```

**Response (contoh):**

```json
{
	"up_to_date": false,
	"plan_token": "9f2c…",
	"actions": [
		{ "id": 0, "sql": "CREATE TABLE `census_note` ( … );" },
		{
			"id": 1,
			"sql": "ALTER TABLE `census` ADD COLUMN `note_id` BIGINT NULL ;"
		},
		{ "id": 2, "sql": "ALTER TABLE `census` DROP COLUMN `legacy_field`;" }
	]
}
```

Yang harus Anda lakukan dengan response ini:

- Kalau `up_to_date` bernilai `true` → tidak ada yang perlu dimigrasi, **selesai**.
- Kalau tidak → **simpan `plan_token`** (harus dikirim kembali saat apply) dan
  **periksa daftar `actions`**.

---

## Langkah 3 — Periksa & putuskan perubahan

Inilah inti API ini: Anda meninjau dulu sebelum mengeksekusi. Perhatikan baik-baik
perubahan yang **destruktif**:

| SQL mengandung               | Sifat       | Catatan                         |
| ---------------------------- | ----------- | ------------------------------- |
| `DROP TABLE`, `DROP COLUMN`  | destruktif  | menghapus data — pastikan benar |
| `DROP FOREIGN KEY`           | berisiko    |                                 |
| `MODIFY COLUMN`              | berisiko    | bisa memotong/mengubah data     |
| `CREATE TABLE`, `ADD COLUMN` | aman/tambah | umumnya aman                    |

Tentukan **id mana saja** yang Anda setujui. Dua pilihan:

- **Setujui semua** (`approve: [0,1,2]`) → setelah sukses, kode aplikasi
  (`channels/` + `Library/`) ikut di-regenerasi agar selaras dengan schema. Ini
  jalur normal untuk deploy.
- **Setujui sebagian** (mis. `approve: [0,1]`) → hanya SQL terpilih yang
  dijalankan, dan **kode aplikasi TIDAK diregenerasi**. Berguna untuk migrasi
  bertahap, tapi Anda perlu menjalankan apply penuh nanti agar kode & schema
  kembali selaras.

---

## Langkah 4 — Kirim request APPLY

Kirim balik `plan_token` dari Langkah 2 dan daftar id yang disetujui.

**Body yang dikirim:**

```json
{
	"action": "apply",
	"config_url": "https://contoh.com/config.json",
	"plan_token": "9f2c…",
	"approve": [0, 1, 2]
}
```

**Response (contoh sukses):**

```json
{
	"applied": [
		{
			"id": 0,
			"sql": "CREATE TABLE `census_note` ( … );",
			"status": "executed"
		},
		{
			"id": 1,
			"sql": "ALTER TABLE `census` ADD COLUMN `note_id` …",
			"status": "executed"
		},
		{
			"id": 2,
			"sql": "ALTER TABLE `census` DROP COLUMN `legacy_field`;",
			"status": "executed"
		}
	],
	"code_regenerated": true,
	"note": null
}
```

---

## Langkah 5 — Tangani hasil / error

| Kode  | Artinya                                           | Yang harus Anda lakukan                                     |
| ----- | ------------------------------------------------- | ----------------------------------------------------------- |
| `200` | semua perubahan disetujui berhasil dijalankan     | selesai; cek `code_regenerated`                             |
| `207` | sebagian gagal                                    | lihat `status`/`message` tiap action; perbaiki & plan ulang |
| `409` | schema sudah berubah sejak plan                   | **ulangi dari Langkah 2 (plan lagi)**                       |
| `400` | request salah (JSON/field/URL config tidak valid) | perbaiki body request                                       |
| `401` | tanda tangan salah / timestamp di luar 300 detik  | cek secret, cara signing, dan jam mesin                     |
| `405` | bukan metode POST                                 | gunakan POST                                                |
| `500` | setup belum lengkap di server                     | pastikan `_db_config.php` & secret sudah ada                |

**Soal `409` (penting):** ini normal, bukan bug. Artinya database berubah di
antara plan dan apply Anda (mis. ada operator lain yang migrasi). Cukup jalankan
plan lagi untuk mendapat `plan_token` dan daftar `actions` yang baru, tinjau
ulang, lalu apply lagi.

---

## Contoh lengkap (bash + `openssl`)

Salin, ganti `SECRET` dan `URL`, lalu jalankan dari mesin tepercaya:

```bash
SECRET="…isi dari \$setup_hmac_secret…"
URL="https://server-anda/app-runner/api/setup.php"

# fungsi pembantu: kirim POST yang sudah ditanda-tangani. $1 = body JSON
post() {
  local body="$1" ts sig
  ts=$(date +%s)
  sig=$(printf '%s\n%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
  curl -s "$URL" \
    -H "Content-Type: application/json" \
    -H "X-Setup-Timestamp: $ts" \
    -H "X-Setup-Signature: $sig" \
    --data-binary "$body"
}

# 1) PLAN — lihat perubahan yang diusulkan
post '{"action":"plan","config_url":"https://contoh.com/config.json"}'

# 2) APPLY — kirim plan_token + id yang disetujui (ambil dari hasil PLAN di atas)
post '{"action":"apply","config_url":"https://contoh.com/config.json","plan_token":"9f2c…","approve":[0,1,2]}'
```

> Gunakan `--data-binary` agar body dikirim apa adanya — string yang
> ditanda-tangani harus sama persis dengan yang dikirim.

---

## Contoh lengkap (PHP)

Cocok bila Anda memanggil dari backend/skrip PHP sendiri:

```php
$secret = '…';
$url    = 'https://server-anda/app-runner/api/setup.php';

function setup_post($url, $secret, array $payload) {
    $body = json_encode($payload);                       // susun sekali
    $ts   = (string) time();
    $sig  = hash_hmac('sha256', $ts . "\n" . $body, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Setup-Timestamp: ' . $ts,
            'X-Setup-Signature: ' . $sig,
        ],
        CURLOPT_POSTFIELDS     => $body,                 // kirim string yang sama
    ]);
    $res = curl_exec($ch);
    return json_decode($res, true);
}

// 1) PLAN
$plan = setup_post($url, $secret, [
    'action'     => 'plan',
    'config_url' => 'https://contoh.com/config.json',
]);

if ($plan['up_to_date']) {
    echo "Tidak ada perubahan schema.\n";
    exit;
}

// 2) tinjau $plan['actions'] … di contoh ini kita setujui semua
$ids = array_column($plan['actions'], 'id');

// 3) APPLY
$apply = setup_post($url, $secret, [
    'action'     => 'apply',
    'config_url' => 'https://contoh.com/config.json',
    'plan_token' => $plan['plan_token'],
    'approve'    => $ids,
]);

print_r($apply);
```

---

## Ringkasan checklist

- [ ] Aplikasi sudah pernah di-install via CLI; secret `$setup_hmac_secret` ada di `_db_config.php`.
- [ ] Pemanggilan dilakukan dari mesin tepercaya, bukan dari browser/front-end.
- [ ] Setiap request menyertakan header `X-Setup-Timestamp` dan `X-Setup-Signature`.
- [ ] Body ditanda-tangani persis seperti yang dikirim, dengan timestamp yang sama.
- [ ] Jam mesin sinkron (dalam 300 detik dari server).
- [ ] PLAN dulu, tinjau `actions`, baru APPLY dengan `plan_token` + id yang disetujui.
- [ ] Tangani `409` dengan plan ulang; tangani `207` dengan memeriksa action yang gagal.

---

Untuk instalasi pertama kali (bootstrap), lihat [`install.md`](install.md).
