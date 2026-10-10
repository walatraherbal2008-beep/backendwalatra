# Panduan Sysadmin: First Run Server

Panduan ini digunakan saat Backend Walatra pertama kali dipasang pada server Linux production.

## 1. Prasyarat

Project membutuhkan PHP 8.4, Composer 2, Redis, database production (PostgreSQL/MySQL), Nginx, dan PHP-FPM. Node.js/npm dibutuhkan jika asset harus dibuild di server.

Contoh Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y nginx redis-server supervisor unzip git curl \
  php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-gd php8.4-bcmath php8.4-intl php8.4-redis \
  php8.4-pgsql
```

Verifikasi:

```bash
php -v
composer --version
node -v
npm -v
redis-cli ping
```

Response Redis harus `PONG`.

## 2. Deploy source code

```bash
sudo mkdir -p /var/www/walatra
sudo chown -R "$USER":"$USER" /var/www/walatra
cd /var/www/walatra
git clone <URL_REPOSITORY> current
cd /var/www/walatra/current
```

Untuk deployment berikutnya:

```bash
git fetch --all --prune
git checkout <BRANCH_PRODUCTION>
git pull --ff-only origin <BRANCH_PRODUCTION>
```

Install dependency:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

Jangan menjalankan `npm run dev` di production.

## 3. Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate --force
chmod 600 .env
nano .env
```

Nilai minimum production:

```dotenv
APP_NAME="Walatra Marketplace"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=walatra
DB_USERNAME=walatra_app
DB_PASSWORD=<PASSWORD_DATABASE>

SESSION_DRIVER=database
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
FILESYSTEM_DISK=public
OTP_EXPOSE_CODE=false

MAXCHAT_ENABLED=true
MAXCHAT_BASE_URL=https://core.maxchat.id/walatra-herbal
MAXCHAT_API_TOKEN=<TOKEN_MAXCHAT>

SUPERADMIN_NAME="Walatra Super Admin"
SUPERADMIN_EMAIL=<EMAIL_SUPERADMIN>
SUPERADMIN_PASSWORD=<PASSWORD_SUPERADMIN>
SUPERADMIN_FORCE_PASSWORD=false
```

Integrasi berikut tetap `false` sampai credential production siap dan sudah diuji:

```dotenv
MIDTRANS_IS_PRODUCTION=false
WAREHOUSE_STOCK_ENABLED=false
SHIPPING_RATE_ENABLED=false
EVERPRO_ENABLED=false
```

Jangan mengaktifkan `OTP_EXPOSE_CODE` di production dan jangan menyimpan token pihak ketiga di repository.

## 4. Database dan Superadmin

```bash
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=SuperAdminSeeder --force
```

Jika master katalog belum tersedia, jalankan setelah mendapat persetujuan owner data:

```bash
php artisan db:seed --class=WalatraCatalogSeeder --force
php artisan db:seed --class=CategorySeeder --force
```

`WalatraCatalogSeeder` memperbarui data master katalog. Jangan menjalankannya sembarangan pada database production yang sudah berisi perubahan katalog.

## 5. Storage dan cache

```bash
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
php artisan optimize:clear
php artisan filament:optimize
php artisan optimize
```

Folder `storage` dan `bootstrap/cache` wajib dapat ditulis oleh PHP-FPM.

## 6. Nginx dan PHP-FPM

Root virtual host harus menunjuk ke folder `public/`:

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/walatra/current/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Aktifkan dan cek:

```bash
sudo ln -s /etc/nginx/sites-available/walatra /etc/nginx/sites-enabled/walatra
sudo nginx -t
sudo systemctl enable --now php8.4-fpm redis-server
sudo systemctl reload nginx
```

Gunakan HTTPS sebelum menerima token OTP atau data customer.

## 7. Queue OTP dan media

Project menggunakan Laravel Horizon pada queue `otp` dan `default`. Uji manual:

```bash
php artisan horizon
```

Untuk production, jalankan melalui Supervisor. Contoh file `/etc/supervisor/conf.d/walatra-horizon.conf`:

```ini
[program:walatra-horizon]
process_name=%(program_name)s
command=php /var/www/walatra/current/artisan horizon
directory=/var/www/walatra/current
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/walatra/current/storage/logs/horizon.log
```

Aktifkan:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart walatra-horizon
php artisan horizon:status
```

Queue harus aktif agar OTP WhatsApp dan image conversion diproses.

## 8. Scheduler

```bash
sudo crontab -e -u www-data
```

Tambahkan:

```cron
* * * * * cd /var/www/walatra/current && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Health check first run

```bash
cd /var/www/walatra/current
php artisan about
php artisan migrate:status
php artisan route:list --path=api/v1
curl -f https://api.example.com/up
curl -f -H 'Accept: application/json' https://api.example.com/api/v1/catalog/products
```

Uji request OTP setelah MaxChat dan Horizon aktif:

```bash
curl -i -X POST https://api.example.com/api/v1/auth/customer/request-otp \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"phone":"08XXXXXXXXXX"}'
```

Jangan mencetak atau menyimpan OTP production di command history maupun log.

## 10. Checklist sebelum go-live

- [ ] `APP_DEBUG=false` dan `APP_URL` memakai HTTPS.
- [ ] `OTP_EXPOSE_CODE=false`.
- [ ] Database production dan credential sudah benar.
- [ ] Redis aktif dan dapat diakses aplikasi.
- [ ] Horizon aktif pada queue `otp` dan `default`.
- [ ] MaxChat production sudah diuji.
- [ ] Storage link dan permission sudah benar.
- [ ] Superadmin berhasil login ke `/superadmin`.
- [ ] Endpoint `/up` dan katalog publik merespons `200`.
- [ ] Backup database dan storage sudah dikonfigurasi.
- [ ] API gudang, stok, ongkir, dan Everpro hanya diaktifkan setelah credential production diverifikasi.

## 11. Deployment berikutnya

```bash
cd /var/www/walatra/current
git pull --ff-only origin <BRANCH_PRODUCTION>
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
sudo supervisorctl restart walatra-horizon
sudo systemctl reload php8.4-fpm
sudo systemctl reload nginx
```

Jalankan deployment setelah backup dan review migration.
