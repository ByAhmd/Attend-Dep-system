# Deployment

The application targets the simplest possible hosting: one PHP + MySQL server (shared
hosting with SSH access, a small VPS, or a managed Laravel host). No Redis, no queue worker,
no Docker, no scheduler is required.

## 1. Server requirements

| Requirement | Value |
|---|---|
| PHP | **8.3 or newer** (8.4 supported) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `dom`, `xmlreader`, `libxml`, `iconv`, `ctype`, `fileinfo`, `intl`, `curl`, `zip`, `bcmath`, `gd` — confirm on the server with `composer check-platform-reqs --no-dev` |
| Database | MySQL **8.0.19+** (MariaDB 10.6+ also works — the migrations use standard `CHECK` constraints) |
| Web server | nginx or Apache with the document root at **`public/`** |
| HTTPS | **Required** — browsers refuse geolocation on plain HTTP |
| Composer | 2.x on the server, or upload `vendor/` built elsewhere |
| Node.js | **Not required on the server.** The compiled theme in `public/build` is committed, and CI fails if it is stale. Node is only needed on a developer machine that changes the theme. |

`composer.lock` is resolved for PHP 8.3 (`config.platform.php` in `composer.json`), so the
same lock installs on 8.3 and 8.4 hosts alike.

## 2. Database setup

```sql
CREATE DATABASE attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'attendance'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON attendance.* TO 'attendance'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Environment variables

Copy `.env.example` to `.env` on the server and set at least:

```dotenv
APP_NAME=Attendance
APP_ENV=production
APP_DEBUG=false
APP_URL=https://attendance.your-company.example
APP_KEY=                       # generated below
APP_LOCALE=ar                  # Arabic interface (default); en for English
APP_TIMEZONE=Asia/Riyadh

LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance
DB_USERNAME=attendance
DB_PASSWORD=<strong password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

ATTENDANCE_MAX_ACCURACY_METERS=100
```

Leave `ADMIN_*` blank and create the administrator with the command in step 8. Never
commit `.env`.

## 4. Install

From the application root on the server:

```bash
php artisan key:generate --force          # first deployment only
bash scripts/deploy.sh
```

`scripts/deploy.sh` does everything in sections 4, 6 and 7 in the right order and is safe
to re-run on every deployment: production dependencies, migrations, the settings row, and
all caches. It never calls npm, because the theme ships built. The individual commands are
spelled out below for the rare case where you need to run one on its own.

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

## 5. Storage and permissions

No file uploads exist, so `php artisan storage:link` is **not** needed. The web server
user must be able to write to `storage/` and `bootstrap/cache/`:

```bash
chmod -R ug+rwx storage bootstrap/cache
```

## 6. Migrations

```bash
php artisan migrate --force
php artisan db:seed --force               # creates the settings row (radius 150 m)
```

`db:seed` is safe to repeat. Never run `DemoDataSeeder` in production; it refuses to run
there anyway.

**There is no `.sql` file in this repository, and there should not be.** The schema is
defined by the seven files in `database/migrations/`, and the two commands above build
it. A checked-in dump would be a second, silently drifting copy of the same schema.

## 7. Cache commands

Run after every deployment:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:optimize
```

To undo before a config change: `php artisan optimize:clear`.

## 8. Web root and HTTPS

Point the virtual host document root at `public/`. Example nginx server block:

```nginx
server {
    listen 443 ssl http2;
    server_name attendance.your-company.example;
    root /var/www/attendance/public;

    ssl_certificate     /etc/letsencrypt/live/attendance.your-company.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/attendance.your-company.example/privkey.pem;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

server {
    listen 80;
    server_name attendance.your-company.example;
    return 301 https://$host$request_uri;
}
```

On Apache or LiteSpeed shared hosting, point the domain at `public/`; the shipped
`public/.htaccess` handles the rewrite. If the host cannot change the document root, ask
them to — do not copy `index.php` to the parent directory.

With `APP_ENV=production` the application generates https URLs itself. If the server sits
behind a proxy or load balancer, configure the trusted proxy in `bootstrap/app.php`
(`$middleware->trustProxies(at: '*')`) so HTTPS is detected correctly.

## 8a. Hostinger, step by step

Hostinger shared hosting runs PHP and MariaDB and has Composer, but **no Node.js**. That
is already handled: the compiled theme in `public/build` is committed, and
`scripts/deploy.sh` never calls npm. Nothing has to be imported by hand, and there is no
`.sql` file to upload.

1. **Database.** hPanel → Databases → Management. Create a database and a user, and note
   that Hostinger prefixes both (`u123456789_attendance`). Leave the database empty.
2. **PHP version.** hPanel → Advanced → PHP Configuration. Choose **8.3** or newer, and on
   the extensions tab make sure `pdo_mysql`, `mbstring`, `intl`, `dom`, `xmlreader`,
   `iconv`, `curl`, `zip` and `fileinfo` are ticked.
3. **Get the code onto the server.** Either hPanel → Advanced → GIT (repository
   `https://github.com/ByAhmd/Attend-Dep-system.git`, branch `master`), or upload a ZIP of
   the repository through File Manager. Put it **outside** `public_html`, for example
   `/home/uXXXXXXXXX/attendance`.
4. **Document root.** hPanel → Websites → your domain → Advanced → change the website root
   to `/home/uXXXXXXXXX/attendance/public`. This is the one step people skip; without it
   the whole source tree is served over the web.
5. **Environment.** Copy `.env.example` to `.env` in File Manager and set `APP_ENV=production`,
   `APP_DEBUG=false`, `APP_URL=https://your-domain`, `SESSION_SECURE_COOKIE=true` and the
   `DB_*` values from step 1. Leave `APP_KEY` blank for now.
6. **Deploy.** hPanel → Advanced → SSH Access, then:

   ```bash
   cd ~/attendance
   php artisan key:generate --force
   bash scripts/deploy.sh
   php artisan app:create-admin --name="Company Admin" --email=admin@your-company.example
   ```

   `scripts/deploy.sh` installs the production dependencies, migrates, seeds the settings
   row and rebuilds every cache. It is safe to run again on each future deployment.
7. **SSL.** hPanel → Security → SSL. Install the free certificate and force HTTPS.
   Browsers refuse to share location over plain HTTP, so check-in cannot work without it.
8. **Configure the company location** in the admin panel (section 10 below).

### If your plan has no SSH

Every command above is an artisan command, and hPanel can run those without SSH:
Advanced → Cron Jobs → create a cron job, set it to run once (or every 5 minutes and
delete it afterwards), with the command:

```
/usr/bin/php /home/uXXXXXXXXX/attendance/artisan migrate --force
```

Run `key:generate --force`, then `migrate --force`, then `db:seed --force`, then
`app:create-admin --name="Admin" --email=you@example.com --password="a-strong-password"`
the same way, one at a time. `vendor/` is the only piece Composer would normally install,
so on a plan without SSH upload your local `vendor/` folder with File Manager as well.

Do **not** build a database dump for this. The migrations are the schema; a dump is a
second copy of it that silently goes stale.

## 9. Initial administrator

```bash
php artisan app:create-admin --name="Company Admin" --email=admin@your-company.example
```

The password is prompted (or pass `--password=`). Sign in at `/admin/login`.

## 10. Company location configuration

In the admin panel open **Attendance settings**, enter the company latitude and longitude
(Google Maps: press and hold on the entrance and copy the coordinates), keep the radius at
150 m unless the site needs otherwise, and save. Attendance is refused until this is done.

Then create employees under **Employees**, give each their password, and send them the
site URL. On first use the phone asks for location permission; employees should allow it
and enable precise location (GPS).

## 11. Updating

```bash
php artisan down
git pull                                   # or upload the new release
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build                    # or upload public/build/
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan filament:optimize
php artisan up
```

## 12. Backups

The whole state of the system is the MySQL database (`users`, `attendances`,
`attendance_rejections`, `attendance_settings`) plus `.env`. A nightly `mysqldump` is a
complete backup.
