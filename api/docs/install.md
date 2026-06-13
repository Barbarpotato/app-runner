# Panduan Penggunaan Install API

Panduan untuk **instalasi pertama kali** aplikasi lewat HTTP menggunakan
`api/install.php` — endpoint bootstrap yang membangun seluruh aplikasi dari nol
hanya dari sebuah URL config.

Endpoint ini adalah padanan HTTP dari CLI `php setup.php install <url>`. Setelah
aplikasi ter-install, untuk perubahan schema berikutnya gunakan
[`api/setup.php`](setup.md).

---

## Apa yang dilakukan endpoint ini

Dari satu URL config, `api/install.php` melakukan berurutan:

1. **Generate kode aplikasi** (`channels/` + `Library/config.json`).
2. **Membuat database bisnis** dan **database auth** (beserta tabel auth + user admin).
3. **Menulis `_db_config.php`** dengan `$setup_hmac_secret` yang **digenerate baru**.
4. **Membuat tabel bisnis** beserta foreign key-nya, sesuai config.

Di akhir, ia mengembalikan **`setup_hmac_secret`** — secret inilah yang Anda pakai
untuk memanggil [`api/setup.php`](setup.md) selanjutnya.

---

## Model akses — TANPA autentikasi, sekali pakai

> ⚠️ **Penting dipahami.** Endpoint ini **tidak punya autentikasi sama sekali**.
> Tidak ada handshake HMAC, tidak ada token. Itu memang disengaja: secret HMAC
> belum ada sampai endpoint ini sendiri yang membuatnya.

Satu-satunya pengaman adalah **keberadaan `_db_config.php`**:

| Kondisi server                | Perilaku `api/install.php`                          |
| ----------------------------- | --------------------------------------------------- |
| `_db_config.php` **belum ada** | berjalan penuh (install)                            |
| `_db_config.php` **sudah ada** | langsung `403 Access denied`, tidak melakukan apa pun |

Artinya endpoint ini **sekali pakai**: begitu instalasi sukses (file `_db_config.php`
terbentuk), pemanggilan berikutnya otomatis ditolak.

> 🔒 **Selama `_db_config.php` belum ada, siapa pun yang bisa menjangkau URL ini
> dapat meng-install aplikasi, membuat database, dan mencetak user admin + secret.**
> Karena itu:
>
> - Deploy hanya pada instance yang **belum** ter-install, di balik kontrol jaringan/firewall.
> - **Hapus `api/install.php`** segera setelah instalasi sukses.
> - Simpan `setup_hmac_secret` dari response — **hanya ditampilkan sekali**.

---

## Request

`POST /app-runner/api/install.php` dengan body JSON. **Semua field wajib** dan
harus berupa string non-kosong.

| Field            | Keterangan                                     |
| ---------------- | ---------------------------------------------- |
| `config_url`     | URL sumber `config.json` (artefak JSON config) |
| `db_host`        | host MySQL                                      |
| `db_name`        | nama database bisnis                            |
| `db_user`        | username MySQL                                  |
| `db_pass`        | password MySQL                                  |
| `auth_db_name`   | nama database auth                             |
| `admin_username` | username login admin app-runner                |
| `admin_password` | password login admin app-runner                |

Tidak ada header khusus yang dibutuhkan (selain `Content-Type: application/json`).

**Body (contoh):**

```json
{
	"config_url": "https://contoh.com/config.json",
	"db_host": "mysql",
	"db_name": "census",
	"db_user": "root",
	"db_pass": "root",
	"auth_db_name": "authes",
	"admin_username": "teeks",
	"admin_password": "rahasia123"
}
```

---

## Response

**Sukses (`200`):**

```json
{
	"ok": true,
	"message": "Installation complete. SAVE the secret below — it is shown only once. Delete api/install.php now.",
	"databases": { "business": "census", "auth": "authes" },
	"tables_created": ["census", "census_note"],
	"foreign_key_errors": [],
	"admin_username": "teeks",
	"setup_hmac_secret": "9f2c…(64 hex)"
}
```

Yang harus Anda lakukan dengan response sukses:

1. **Simpan `setup_hmac_secret`.** Ini satu-satunya kesempatan; nilainya tidak
   ditampilkan lagi. Dipakai untuk semua pemanggilan [`api/setup.php`](setup.md).
2. **Periksa `foreign_key_errors`.** Jika tidak kosong, sebagian FK gagal dibuat
   (tabel tetap terbentuk) — tinjau pesannya.
3. **Hapus `api/install.php`** dari server.

**Tabel kode error:**

| Kode  | Artinya                                              | Yang harus dilakukan                                  |
| ----- | ---------------------------------------------------- | ----------------------------------------------------- |
| `200` | instalasi sukses                                     | simpan secret; hapus `install.php`                    |
| `400` | field wajib hilang/kosong, atau config URL tidak valid | lengkapi body; cek `config_url`                       |
| `403` | aplikasi **sudah** ter-install (`_db_config.php` ada) | gunakan [`api/setup.php`](setup.md) untuk migrasi     |
| `405` | bukan metode POST                                    | gunakan POST                                          |
| `500` | gagal konek/menyiapkan DB, template hilang, dll.     | cek kredensial DB, host, dan template di server       |

---

## Contoh lengkap (bash + `curl`)

Tidak perlu menanda-tangani apa pun — cukup kirim JSON:

```bash
URL="https://server-anda/app-runner/api/install.php"

curl -s "$URL" \
  -H "Content-Type: application/json" \
  --data '{
    "config_url":"https://contoh.com/config.json",
    "db_host":"mysql",
    "db_name":"census",
    "db_user":"root",
    "db_pass":"root",
    "auth_db_name":"authes",
    "admin_username":"teeks",
    "admin_password":"rahasia123"
  }'
```

Ambil `setup_hmac_secret` dari response, lalu lanjut ke
[`setup.md`](setup.md) untuk migrasi schema.

---

## Contoh lengkap (PHP)

```php
$url = 'https://server-anda/app-runner/api/install.php';

$body = json_encode([
    'config_url'     => 'https://contoh.com/config.json',
    'db_host'        => 'mysql',
    'db_name'        => 'census',
    'db_user'        => 'root',
    'db_pass'        => 'root',
    'auth_db_name'   => 'authes',
    'admin_username' => 'teeks',
    'admin_password' => 'rahasia123',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $body,
]);
$res = json_decode(curl_exec($ch), true);

if (!empty($res['ok'])) {
    // SIMPAN ini — hanya muncul sekali
    file_put_contents('setup_secret.txt', $res['setup_hmac_secret']);
    echo "Install sukses. Tabel: " . implode(', ', $res['tables_created']) . "\n";
} else {
    echo "Gagal: " . ($res['error'] ?? 'unknown') . "\n";
}
```

---

## Ringkasan checklist

- [ ] Server **belum** ter-install (`_db_config.php` belum ada).
- [ ] Endpoint berada di balik kontrol jaringan (karena tanpa autentikasi).
- [ ] Body berisi **semua** field wajib (config_url, db_*, auth_db_name, admin_*).
- [ ] Setelah `200`: **simpan `setup_hmac_secret`** (hanya sekali).
- [ ] **Hapus `api/install.php`** dari server setelah sukses.
- [ ] Lanjutkan migrasi schema berikutnya lewat [`api/setup.php`](setup.md).

---

Untuk migrasi schema setelah ter-install, lihat [`setup.md`](setup.md).
