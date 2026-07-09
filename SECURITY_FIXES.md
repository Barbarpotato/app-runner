# Rekap Perbaikan Keamanan Aplikasi — 9 Juli 2026

Dokumen ini merangkum celah keamanan yang ditemukan pada aplikasi census-dev, beserta perbaikan yang sudah dilakukan. Ditulis dengan bahasa sederhana, tidak untuk audiens teknis.

## Ringkasan Singkat

| # | Masalah | Tingkat Bahaya | Status |
|---|---|---|---|
| 1 | Ada halaman rahasia yang bisa dipakai siapa saja buat "mengintip" seluruh isi database | Sangat Berbahaya | Sudah diperbaiki |
| 2 | Halaman yang sama juga bisa disusupi buat ambil data tabel manapun | Sangat Berbahaya | Sudah diperbaiki |
| 3 | File konfigurasi rahasia aplikasi bisa dibuka siapa saja lewat internet | Sangat Berbahaya | Sudah diperbaiki |
| 4 | Ada trik yang memungkinkan orang luar "meloncat" ke file lain di server | Sangat Berbahaya | Sudah diperbaiki |
| 5 | Sesi login pengguna berpotensi dibajak orang lain | Sedang | Sudah diperbaiki |
| 6 | Beberapa fitur bisa diakses langsung tanpa melalui pengecekan keamanan | Sedang | Sudah diperbaiki |
| 7 | Pesan error aplikasi membocorkan detail teknis yang harusnya rahasia | Rendah-Sedang | Sudah diperbaiki |
| 8 | Sebagian dokumen teknis aplikasi bisa diunduh tanpa izin | Rendah | Sudah diperbaiki |
| 9 | Tombol hapus/tambah/ubah token API bisa dipicu diam-diam oleh situs jahat (CSRF) | Sedang | Sudah diperbaiki |

## Penjelasan Tiap Masalah

### 1 & 2. Halaman rahasia "database.php" bisa diakses dan disalahgunakan siapa saja
Ternyata ada satu halaman khusus di aplikasi yang awalnya dibuat untuk kebutuhan development (semacam alat bantu lihat isi database), tapi lupa dikunci. Siapapun yang tahu alamatnya bisa membuka halaman ini tanpa perlu login, lalu mengintip bahkan menyedot seluruh isi database — termasuk data-data sensitif di tabel lain.

**Sudah dicoba langsung dan terbukti bisa** — bukan cuma dugaan.

**Perbaikan**: sekarang halaman ini hanya bisa dibuka kalau sudah login, dan sistem juga membatasi supaya orang tidak bisa mengetik nama tabel sembarangan untuk mengambil data di luar yang seharusnya.

### 3. File konfigurasi rahasia bisa dibuka bebas
Ada satu file konfigurasi aplikasi (berisi struktur data, aturan bisnis, dan sedikit source code) yang seharusnya tersembunyi, tapi karena satu baris pengaturan sengaja dinonaktifkan sebelumnya, file ini jadi bisa dibuka langsung lewat browser oleh siapa saja, tanpa login.

**Perbaikan**: akses langsung ke folder ini sekarang diblokir total.

### 4. Trik "meloncat" ke file lain di server (path traversal)
Cara sistem membaca alamat/link yang diketik pengguna punya celah — orang yang punya akses API (walau terbatas) bisa "menipu" sistem supaya membuka file lain yang seharusnya tidak boleh diakses dari jalur itu.

**Perbaikan**: sistem sekarang menolak alamat/link yang mengandung pola mencurigakan seperti ini sebelum diproses.

### 5. Sesi login bisa dibajak (session fixation)
Saat pengguna login, sistem seharusnya mengganti "kunci sesi" (session ID) dengan yang baru. Sebelumnya ini tidak dilakukan, sehingga secara teori ada celah orang lain bisa membajak sesi login orang lain kalau berhasil menyusupkan kunci sesi lama sebelum korban login.

**Perbaikan**: sekarang kunci sesi otomatis diganti baru setiap kali ada yang berhasil login.

### 6. Beberapa fitur internal bisa dipanggil langsung, melewati pengecekan keamanan
Normalnya setiap permintaan ke fitur-fitur tertentu harus lewat "penjaga pintu" yang mengecek izin akses (API key). Tapi ternyata ada cara memanggil fitur itu secara langsung yang berhasil melewati penjaga pintu ini.

**Perbaikan**: jalur akses langsung ini sekarang diblokir, semua permintaan wajib lewat penjaga pintu seperti seharusnya. Fitur yang dipakai pengguna sehari-hari tidak terpengaruh, sudah dicek tetap berjalan normal.

### 7. Pesan error membocorkan detail teknis
Kalau terjadi error di sistem, pesan error yang ditampilkan ke pengguna/luar berisi detail teknis (misalnya nama kolom database) yang sebenarnya bisa dimanfaatkan orang buat menyusun serangan lebih lanjut.

**Perbaikan**: sekarang pesan yang ditampilkan ke luar dibuat umum ("terjadi kesalahan pada server"), detail teknisnya tetap dicatat rapi di catatan log internal untuk keperluan debugging tim.

### 8. Dokumen teknis bocor tanpa izin
Ada satu pengaturan lama yang secara tidak sengaja membuat sebagian file dokumentasi teknis bisa diunduh orang luar tanpa harus login.

**Perbaikan**: pengaturan ini sudah dihapus.

### 9. Tombol hapus/tambah token API bisa dipicu diam-diam dari situs lain (CSRF)
Kalau admin sedang login, lalu tanpa sadar membuka halaman/link dari situs lain yang jahat, ada kemungkinan aksi seperti "hapus token API" bisa terpicu otomatis tanpa admin sadar atau klik apapun secara sengaja.

**Perbaikan**: sekarang setiap aksi tambah/ubah/hapus token API wajib menyertakan "kode rahasia sekali pakai" yang cuma diketahui oleh halaman resmi aplikasi. Kalau kode ini tidak ada atau salah, aksinya otomatis ditolak.

## Sudah Dicek dan Dipastikan Aman

- Fitur-fitur lain (tampilan halaman, pengambilan data lewat API) sudah dicoba manual setelah perbaikan, dan semuanya tetap berjalan normal seperti sebelumnya.
- Perbaikan ini tidak mengubah cara kerja aplikasi untuk pengguna biasa — hanya menutup celah yang bisa dimanfaatkan pihak luar yang tidak berwenang.

## Yang Belum Dicek (di luar cakupan pemeriksaan kali ini)

- Fitur upload file (aplikasi saat ini belum punya fitur ini, tapi perlu dicek ulang kalau nanti ditambahkan)
- Perlindungan dari percobaan login bertubi-tubi (brute force)
- Pengaturan keamanan tambahan pada cookie sesi
- Alur lupa password / reset password
