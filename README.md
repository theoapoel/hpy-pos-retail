# Resto POS HPY

> Sistem kasir modern berbasis **Laravel 11**, database MySQL/MariaDB, UI Blade + Vite,
> dilengkapi sinkronisasi penuh ke **ERP HPY**.

---

## Daftar Isi

1. [Persyaratan Sistem](#persyaratan-sistem)
2. [Langkah Instalasi](#langkah-instalasi)
3. [Login Pertama Kali](#login-pertama-kali)
4. [Konfigurasi ERP HPY](#konfigurasi-erp-hpy)
5. [Fitur-Fitur Utama](#fitur-fitur-utama)
6. [Struktur Project](#struktur-project)
7. [Perintah Berguna](#perintah-berguna)
8. [Troubleshooting](#troubleshooting)
9. [Production Deployment](#production-deployment)

---

## Persyaratan Sistem

| Komponen      | Versi Minimum | Cek Perintah         |
|---------------|---------------|----------------------|
| PHP           | 8.2+          | `php -v`             |
| Composer      | 2.x           | `composer --version` |
| MySQL/MariaDB | 8.0+ / 10.3+  | `mysql --version`    |
| Node.js       | 18+           | `node -v`            |
| NPM           | 9+            | `npm -v`             |

Ekstensi PHP yang dibutuhkan:
```
php-mbstring  php-xml  php-curl  php-json
php-zip       php-pdo  php-mysql php-fileinfo
```

---

## Langkah Instalasi

### Langkah 1 — Clone Project

```bash
git clone https://github.com/theoapoel/resto-pos.git
cd resto-pos
```

---

### Langkah 2 — Install Dependensi

```bash
composer install
npm install
```

---

### Langkah 3 — Salin File Konfigurasi

```bash
cp .env.example .env
```

---

### Langkah 4 — Generate Application Key

```bash
php artisan key:generate
```

---

### Langkah 5 — Buat Database

```sql
-- Login ke MySQL/MariaDB, lalu jalankan:
CREATE DATABASE resto_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

### Langkah 6 — Konfigurasi `.env`

Buka file `.env`, sesuaikan bagian ini:

```env
APP_NAME="Resto POS HPY"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=resto_pos
DB_USERNAME=root
DB_PASSWORD=
```

> Jika menggunakan XAMPP: `DB_USERNAME=root` dan `DB_PASSWORD=` (kosong)

---

### Langkah 7 — Jalankan Migrasi Database

```bash
php artisan migrate
```

---

### Langkah 8 — Isi Data Demo (Opsional, Direkomendasikan)

```bash
php artisan db:seed
```

Seeder akan membuat:
- 3 akun user (admin, manager, kasir)
- 6 kategori produk
- 26 produk demo
- 8 customer demo
- ~300 transaksi demo selama 30 hari terakhir
- Pengaturan default toko

---

### Langkah 9 — Build Frontend Assets

```bash
npm run build
```

Atau untuk development (watch mode):
```bash
npm run dev
```

---

### Langkah 10 — Jalankan Aplikasi

```bash
# Jika menggunakan XAMPP: akses langsung lewat Apache
http://localhost/resto-pos/public

# Atau jalankan standalone:
php artisan serve
# Akses: http://localhost:8000
```

---

## Login Pertama Kali

| Role    | Email                  | Password   | PIN    |
|---------|------------------------|------------|--------|
| Admin   | admin@larapos.com      | `password` | 123456 |
| Manager | manager@larapos.com    | `password` | 111222 |
| Kasir   | kasir@larapos.com      | `password` | 654321 |

> Kasir langsung diarahkan ke halaman POS setelah login. Admin dan Manager diarahkan ke Dashboard.

> **PENTING:** Ganti password segera setelah login pertama di production!

---

## Konfigurasi ERP HPY

### Di Sisi ERP HPY (lakukan dulu):

**1. Buat API Key:**
```
ERP HPY → Settings → My Settings → API Access
→ Klik "Generate Keys"
→ Salin API Key dan API Secret
```

**2. Buat POS Profile:**
```
ERP HPY → Point of Sale → POS Profile → New
→ Isi nama, company, warehouse
→ Tambahkan payment methods (Cash, QRIS, dll.)
→ Tambahkan kasir di tab "Applicable for Users"
→ Simpan
```

**3. Pastikan Walk-in Customer tersedia** di ERP HPY untuk transaksi tanpa customer terdaftar.

**4. Pastikan item ada di ERP HPY** dengan `is_sales_item = Yes`

---

### Di Sisi Resto POS:

Buka menu **Sync HPY** → halaman dibagi 3 bagian:

**Konfigurasi HPY** (kolom kiri):
```
HPY URL      : http://your-hpy-domain.com
API Key      : (dari langkah di atas)
API Secret   : (dari langkah di atas)
Company      : Nama perusahaan di ERP HPY
POS Profile  : Nama POS Profile yang sudah dibuat
Naming Series: ACC-PSINV-.YYYY.- (sesuaikan jika perlu)
```

Klik **"Test"** → harus muncul status **"Terhubung"**, lalu klik **"Simpan"**.

**Sales Taxes & Charges** (kolom kanan):
- Pajak Produk: isi Account Head jika pajak diatur per item
- Service Charge & PB1: isi Account Head dan Charge Type sesuai Chart of Accounts ERP HPY
- Kosongkan jika pajak sudah diatur di POS Profile

**Aksi Sinkronisasi** (bawah):
- **Pull Payment Methods** → ambil metode bayar dari POS Profile, set walk-in customer otomatis
- **Pull Produk** → import semua item dari ERP HPY ke lokal
- **Pull User dari POS Profile** → import kasir dari tab "Applicable for Users"
- **Pull Harga Delivery** → ambil harga GoFood/GrabFood/ShopeeFood dari price list
- **Sync Pending** → kirim transaksi yang belum tersync ke ERP HPY sebagai POS Invoice

---

## Fitur-Fitur Utama

### Kasir (POS)
- Grid produk dengan pencarian real-time
- Filter per kategori
- Support barcode scanner (tekan **F3** untuk fokus input barcode)
- Manajemen keranjang (tambah/kurang/hapus item)
- Diskon nominal (Rp) dan persentase (%)
- Kalkulasi pajak per produk
- Metode pembayaran dinamis dari POS Profile ERP HPY
- Service Charge & PB1 otomatis untuk order Dine In
- Harga delivery khusus untuk GoFood / GrabFood / ShopeeFood
- Hitung kembalian otomatis, tombol nominal cepat
- Pilih / tambah customer
- Struk digital dan cetak struk thermal

### Dashboard
- Statistik penjualan hari ini
- Grafik penjualan 7 hari (Chart.js)
- 5 produk terlaris (30 hari)
- Transaksi terbaru
- Alert stok menipis

### Manajemen Produk
- CRUD produk lengkap
- Tracking stok dengan alert stok minimum
- Kategori dengan warna dan icon
- Barcode support
- Pajak per produk
- Sinkronisasi gambar produk dari ERP HPY

### Manajemen Customer
- CRUD customer
- Tracking total pembelian
- Push customer ke ERP HPY

### Riwayat Transaksi
- Filter tanggal, status, metode bayar
- Detail transaksi lengkap
- Cetak ulang struk
- Batalkan transaksi — stok otomatis dikembalikan (admin/manager)

### Multi-Warehouse & Stock Transfer
- Manajemen beberapa gudang (pemetaan 1:1 ke ERP HPY Warehouse)
- Transfer stok antar gudang dengan status lokal (`pending` / `submitted` / `cancelled`)
- Sinkronisasi ke ERP HPY Warehouse Transfer document
- Soft-delete untuk audit trail

### Stock Opname
- Buat sesi stock opname per gudang
- Input hitungan fisik per item
- Submit untuk memperbarui stok sistem
- Batalkan opname yang belum disubmit
- Riwayat opname lengkap

### Sinkronisasi ERP HPY
- Test koneksi real-time
- Pull produk, user kasir, payment methods, harga delivery dari ERP HPY
- Push transaksi sebagai POS Invoice (auto-submit)
- Retry transaksi gagal
- Log sinkronisasi lengkap
- Badge notifikasi pending sync

### Manajemen Pengguna & Hak Akses
- CRUD user dengan role: `admin`, `manager`, `kasir`
- Matriks permission per modul: `dashboard`, `pos`, `transactions`, `products`, `customers`, `stock_transfer`, `stock`, `sync`
- Permission di-cache per role (300 detik)
- Login dengan PIN untuk sesi kasir

---

## Struktur Project

```
resto-pos/
├── app/
│   ├── Http/Controllers/
│   │   ├── DashboardController.php       ← Dashboard & statistik
│   │   ├── PosController.php             ← Kasir & checkout
│   │   ├── ProductController.php         ← CRUD produk
│   │   ├── CustomerController.php        ← CRUD customer
│   │   ├── TransactionController.php     ← Riwayat transaksi
│   │   ├── StockTransferController.php   ← Transfer stok antar gudang
│   │   ├── StockController.php           ← Stok per gudang & sync Bin
│   │   ├── StockOpnameController.php     ← Stock opname
│   │   ├── ErpSyncController.php         ← Sinkronisasi ERP HPY
│   │   ├── UserController.php
│   │   ├── PermissionController.php
│   │   ├── RoleController.php
│   │   ├── WarehouseController.php
│   │   ├── SettingsController.php
│   │   ├── BackupController.php
│   │   └── FactoryResetController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Product.php
│   │   ├── ProductStock.php              ← Stok per produk per gudang
│   │   ├── Category.php
│   │   ├── Customer.php
│   │   ├── Transaction.php
│   │   ├── TransactionItem.php
│   │   ├── StockTransfer.php
│   │   ├── StockOpname.php
│   │   ├── StockOpnameItem.php
│   │   ├── Warehouse.php
│   │   ├── RolePermission.php
│   │   ├── ErpSyncLog.php
│   │   └── Setting.php
│   └── Services/
│       └── ErpNextService.php            ← Semua API call ke ERP HPY (Guzzle)
├── database/
│   ├── migrations/
│   └── seeders/
│       └── DatabaseSeeder.php
├── resources/views/
│   ├── layouts/app.blade.php
│   ├── pos/
│   ├── products/
│   ├── customers/
│   ├── transactions/
│   ├── stock-transfer/
│   ├── stock-opname/
│   ├── stock/
│   ├── sync/
│   ├── users/
│   ├── permissions/
│   ├── roles/
│   ├── warehouses/
│   └── settings/
└── routes/web.php
```

---

## Perintah Berguna

```bash
# Reset database & isi ulang data demo
php artisan migrate:fresh --seed

# Bersihkan semua cache
php artisan cache:clear && php artisan config:clear && php artisan route:clear

# Lihat semua route
php artisan route:list

# Linting PSR-12 (Laravel Pint)
./vendor/bin/pint --test   # cek saja
./vendor/bin/pint           # auto-fix

# Jalankan tests
./vendor/bin/phpunit

# Build production
npm run build
```

---

## Troubleshooting

### Error: `SQLSTATE[HY000] [2002] Connection refused`
Pastikan MySQL/MariaDB berjalan. Di XAMPP: Start MySQL dari Control Panel.

### Error: SSL certificate saat `git push`
```bash
git config --global http.sslBackend schannel
```

### Error: `storage/logs` tidak bisa ditulis
```bash
chmod -R 775 storage bootstrap/cache
# Windows (XAMPP): pastikan folder storage tidak read-only
```

### Gambar/CSS tidak muncul
```bash
php artisan storage:link
```

### ERP HPY: `401 Unauthorized`
- Pastikan API Key dan Secret benar di menu Sync HPY → Konfigurasi HPY
- User ERP HPY harus punya role System Manager atau Sales User
- Coba regenerate API keys di ERP HPY

### ERP HPY: `POS Profile not found`
- Nama POS Profile harus **sama persis** (case-sensitive) dengan di ERP HPY
- Pastikan POS Profile sudah di-enable

### ERP HPY: `Customer does not exist` / `NoneType error`
- Klik **Pull Payment Methods** di halaman Sync HPY — walk-in customer akan di-set otomatis dari POS Profile
- Pastikan customer yang dimaksud ada di ERP HPY

### Permission tidak berlaku setelah diubah
Cache permission di-reset otomatis saat menyimpan perubahan, tapi jika masih bermasalah:
```bash
php artisan cache:clear
```

---

## Production Deployment

```bash
# 1. Set environment di .env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pos.yourdomain.com

# 2. Install & optimasi
composer install --no-dev --optimize-autoloader
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Permission folder
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

**Nginx config:**
```nginx
server {
    listen 80;
    server_name pos.yourdomain.com;
    root /var/www/resto-pos/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

*Resto POS HPY — Laravel 11 + ERP HPY*
