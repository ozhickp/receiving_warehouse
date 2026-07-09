# Receiving Warehouse Management System

Sistem manajemen gudang penerimaan barang berbasis web — dibangun dengan PHP native (PDO), MySQL/MariaDB, dan Bootstrap 5.3. Mencakup penerimaan barang dari supplier, pengeluaran stok, penimbangan, mapping lokasi gudang, hingga penyesuaian stok (opname).

## Fitur

- **Autentikasi & Role** — 4 role: `admin`, `ppic`, `receiving`, `manager`, masing-masing dengan hak akses berbeda.
- **Stock In** — penerimaan barang dari supplier (multi-item per transaksi), tercatat dengan No. PO & No. DO, lokasi penyimpanan wajib dipilih dari Master Lokasi.
- **Stock Out** — pengeluaran material, breakdown stok per lokasi.
- **Stock Adjustment (Opname)** — koreksi stok sistem agar sesuai hasil hitung fisik, dengan jejak audit (catatan wajib).
- **Weighing** — pencatatan penimbangan barang.
- **Schedule Supplier** — jadwal kedatangan barang dari supplier.
- **Master Data** — Material, Supplier, Gudang (Warehouse), Lokasi (Zone/Rak per gudang) dengan editor peta lokasi drag-and-drop.
- **History & Laporan** — riwayat seluruh transaksi (IN/OUT/ADJUST) dengan filter.
- **Kelola User** (admin) — tambah/nonaktifkan user, reset password, lihat siapa yang sedang online, buka kunci akun yang terkunci.
- **Keamanan** — proteksi CSRF di semua form, rate limiting login (kunci akun sementara setelah beberapa kali gagal), kebijakan password minimum, session cookie hardening (HttpOnly/Secure/SameSite).

## Tech Stack

| Layer      | Teknologi                          |
|------------|-------------------------------------|
| Backend    | PHP 8+ (native, PDO, tanpa framework) |
| Database   | MySQL / MariaDB                     |
| Frontend   | Bootstrap 5.3, Bootstrap Icons, vanilla JS |
| Dependency | Composer (lihat `composer.json`)    |

## Struktur Folder

```
receiving_warehouse/
├── auth/            # login, register, kelola user
├── config/          # konfigurasi app & database
├── dashboard/        # ringkasan stok & aktivitas
├── includes/          # auth helper, header/footer, fungsi bersama
├── inventory/          # material, supplier gudang, lokasi, peta lokasi
├── schedule/            # jadwal kedatangan supplier
├── suppliers/             # master supplier
├── transactions/           # stock in / out / adjustment / history
├── weighing/                # penimbangan
├── vendor/                   # dependency composer (JANGAN diedit manual)
├── schema.sql                 # skema database awal
├── migration_*.sql             # migrasi tambahan (jalankan berurutan sesuai tanggal)
├── .htaccess                    # proteksi folder sensitif (lihat bagian Keamanan)
└── .gitignore
```

## Instalasi (Local — XAMPP)

1. Clone/copy project ini ke `htdocs/receiving_warehouse`.
2. Install dependency:
   ```bash
   composer install
   ```
3. Buat database baru bernama `db_receiving` di MySQL/MariaDB (via phpMyAdmin atau CLI).
4. Jalankan file SQL **berurutan**:
   ```
   schema.sql
   migration_stock_in_po.sql
   migration_fix_missing_schema.sql
   migration_login_security.sql
   ```
5. Salin file konfigurasi database dari template, lalu isi kredensial:
   ```bash
   cp config/database.example.php config/database.php
   ```
   Edit `config/database.php` dan sesuaikan `DB_USER` / `DB_PASS` dengan kredensial database kamu.
6. Sesuaikan `BASE_URL` di `config/app.php` kalau path project berbeda dari `/receiving_warehouse`.
7. Buat folder log (kalau belum otomatis terbuat):
   ```bash
   mkdir -p storage/logs
   ```
8. Akses lewat browser: `http://localhost/receiving_warehouse`.

## Deployment ke Server Production

Sebelum go-live, pastikan:

- [ ] `config/database.php` pakai user database khusus (**bukan** `root`) dengan password kuat, dan **tidak ikut ter-commit ke Git** (sudah di-`.gitignore`).
- [ ] `APP_ENV` di `config/app.php` di-set ke `'production'` (default-nya memang sudah begitu — pastikan tidak sengaja diubah jadi `'development'` saat upload).
- [ ] HTTPS aktif di server (SSL certificate terpasang).
- [ ] File `.sql` (`schema.sql`, `migration_*.sql`) **tidak diupload** ke server production — cukup disimpan di repo/lokal untuk keperluan setup ulang.
- [ ] `.htaccess` ikut terupload dan `mod_rewrite` aktif di server (cek dengan tim infra/hosting).
- [ ] `BASE_URL` disesuaikan dengan path/domain production.
- [ ] Folder `storage/logs` writable oleh web server.
- [ ] Backup database terjadwal (belum ada mekanisme otomatis di aplikasi ini — atur di level server/hosting).

## Role & Hak Akses

| Role        | Akses                                                      |
|-------------|-------------------------------------------------------------|
| `admin`     | Akses penuh, termasuk Kelola User, Stock Adjustment, Master Gudang/Lokasi |
| `receiving` | Stock In/Out, konfirmasi schedule, weighing                |
| `ppic`      | Input schedule supplier                                     |
| `manager`   | Lihat dashboard & history (read-only)                       |

Registrasi mandiri lewat `auth/register.php` **tidak** menyediakan opsi role `admin` — akun admin baru hanya bisa dibuat oleh admin lain lewat menu Kelola User.

## Lisensi

Internal project — belum ditentukan lisensinya.
