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
| Node.js | **Not required on the server.** The compiled theme in `public/build` is committed, and CI fails if it is stale. Node is only needed on a developer machine that changes the theme. The eight IBM Plex Sans Arabic woff2 files are part of that build — the interface fetches no font from anybody else, so nothing outside your own server has to be reachable for Arabic text to render in the right face. |

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
APP_KEY=                       # left empty; scripts/deploy.sh generates it
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
ATTENDANCE_PING_INTERVAL_SECONDS=300
```

`SECURITY_HSTS_MAX_AGE` is optional and explained in section 8b. Leave it out unless you
are switching HSTS on for the first time and want to start with a short promise.

### Invitation email (optional)

Employees set their own password through an invitation link. The link always works and the
administrator can copy it and send it by hand, so mail is optional. Configure it only if
you want the invitation to arrive by email as well. On Hostinger, create a mailbox under
Emails, then add its SMTP details:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=attendance@your-domain
MAIL_PASSWORD=<mailbox password>
MAIL_FROM_ADDRESS=attendance@your-domain
MAIL_FROM_NAME="${APP_NAME}"
```

While `MAIL_MAILER` is `log`, the application does not pretend to send anything: it tells
the administrator that no email was sent and to use the link. That is deliberate, so
nobody waits for a message that was only ever written to a log file.

Leave `ADMIN_*` blank and create the administrator with the command in step 8. Never
commit `.env`.

## 4. Install

From the application root on the server, once `.env` exists:

```bash
bash scripts/deploy.sh
```

That is the whole install. Do **not** run `php artisan key:generate` first: a fresh clone
has no `vendor/`, so artisan cannot start until Composer has run. The script installs the
dependencies, then generates `APP_KEY` if and only if it is still empty, and never touches
an existing key.

`scripts/deploy.sh` does everything in sections 4, 6 and 7 in the right order and is safe
to re-run on every deployment: production dependencies, migrations, the settings row, and
all caches. It never calls npm, because the theme ships built. The individual commands are
spelled out below for the rare case where you need to run one on its own.

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

## 5. Storage and permissions

`php artisan storage:link` is **not** needed: nothing this application stores is public.
The web server user must be able to write to `storage/` and `bootstrap/cache/`:

```bash
chmod -R ug+rwx storage bootstrap/cache
```

### Leave attachments

An employee may attach one supporting document — a medical note, an examination timetable
— to a leave request, and that is the only file anybody uploads. Those files are written to

```
storage/app/private/leave-attachments/
```

which is **above the document root**: the web server is pointed at `public/`, and there is
no symlink, no `url` and no direct address into that directory. A file there is reached
only through `/leave-requests/{id}/attachment`, which signs the visitor in first and then
asks the same permission question the screens ask — an employee may read their own
document, an administrator may read any, and nobody else may read one. Because nothing in
that directory is ever served by the web server or passed to PHP, a file whose name ends
`.pdf` and whose bytes are a script sits there inert. The directory is created on the first
upload; it needs no special permissions beyond the `chmod` above.

**Nothing backs these files up.** The host's automatic backup, if the plan has one, covers
the database, and a `mysqldump` covers the database as well; neither of them has ever seen
`storage/`. A leave request whose document is gone still shows who asked for what and what
was decided, so this is a loss of evidence and not a loss of the record — but it is a real
loss, and the fix is one line in the same cron job that dumps the database:

```bash
tar -czf ~/backups/leave-attachments-$(date +%F).tar.gz -C /path/to/app storage/app/private
```

Keep those archives wherever the database dumps go, and keep them for as long: an attachment
is only useful beside the request it belongs to. If nobody is going to run that line, say so
out loud to whoever approves leave, because the alternative is a promise the server is not
keeping.

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

   **On a subdomain**, look at Domains → Subdomains and read the Directory column before
   changing anything. Hostinger usually shows a fixed path such as
   `/home/uXXXXXXXXX/domains/main.com/public_html/sub/public` — note that it already ends
   in `/public`. Rather than fight it, satisfy it: keep the application outside the web
   root and leave a directory containing a single link where the panel expects one.

   ```bash
   mkdir -p ~/domains/main.com/public_html/sub
   ln -s ~/domains/main.com/attendance/public ~/domains/main.com/public_html/sub/public
   ```

   Only that link is exposed, so `.env`, `vendor` and the source stay unreachable. If the
   document root does not exist, Hostinger silently serves the parent domain instead, so
   the symptom is the main website's 404 page rather than an error, which is confusing.
   Verify with `curl -sI https://sub.main.com/build/manifest.json`, expecting 200.
5. **Environment.** Copy `.env.example` to `.env` in File Manager and set `APP_ENV=production`,
   `APP_DEBUG=false`, `APP_URL=https://your-domain`, `SESSION_SECURE_COOKIE=true` and the
   `DB_*` values from step 1. Leave `APP_KEY` blank for now.
6. **Deploy.** hPanel → Advanced → SSH Access, then:

   ```bash
   cd ~/attendance
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

## 8b. Response security headers

The application sets its own headers on every response, from
`app/Http/Middleware/SecurityHeaders.php`. Nothing has to be configured on the web server,
and nothing should be: a second copy of these rules in nginx or `.htaccess` is a second
copy to keep in step.

After a deployment, read them back once:

```bash
curl -sI https://your-domain/login | grep -iE \
  'content-security-policy|permissions-policy|strict-transport|x-frame|x-content-type|referrer-policy|x-powered-by'
```

Four things are worth actually looking at in that output.

**`geolocation=(self)` must be in `Permissions-Policy`.** It is the one line the product
cannot lose. If it is missing or reads `geolocation=()`, no employee can check in, the
browser gives them an error they cannot act on, and nothing in the application will report
the fault. Nothing else in this list can break the product; this can.

**`x-powered-by` must not be there.** PHP adds it when `expose_php` is on, which it is on
Hostinger and which is a `php.ini` setting you cannot change from the application, so the
middleware removes it from the header list before the response is sent. If it comes back,
the web server is adding it after PHP, and that one has to be turned off at the server.

**`content-security-policy` may appear twice.** Hostinger's CDN injects a policy of its own
containing `upgrade-insecure-requests` and nothing else. Browsers apply two policies as an
intersection rather than merging them, and a policy with no fetch directives cannot narrow
anything, so two headers here are harmless — and the application's own policy keeps
`upgrade-insecure-requests` in it so the behaviour survives either way. What would be worth
knowing is the opposite case: if only the edge's short policy comes back and the
application's long one is gone, the CDN is replacing rather than appending, and the site is
running without the policy it thinks it has.

**`strict-transport-security` appears in production only.** It tells a browser to refuse
plain HTTP for this host for a year, and a browser that has been told cannot be untold
early — lowering the value only reaches people who come back and read the new one. That is
safe here, because this application cannot work over HTTP at all: a browser will not report
a position outside a secure context. If you would rather not take a year's promise on
faith, set `SECURITY_HSTS_MAX_AGE=300` in `.env` for the first deployment, confirm that
every way into the site is genuinely HTTPS, then remove the line and run
`php artisan config:cache` again. The header deliberately carries neither
`includeSubDomains`, which would speak for host names that are not this application's, nor
`preload`, which is a submission to a list shipped inside browsers and takes months to
leave.

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

## 10a. Deploying automatically on every push

Once the manual deployment above works, GitHub Actions can do it for you. The `deploy` job
in `.github/workflows/ci.yml` runs **only** after lint, static analysis and the whole test
suite have passed on both PHP 8.3 and 8.4, and only for a push to `master`. It signs in
over SSH, resets the checkout to `origin/master` and runs `scripts/deploy.sh` — the same
command a person would run. It stays switched off until you configure it.

**1. Make a key pair for the robot** (on your own machine, no passphrase, because a
robot cannot type one):

```bash
ssh-keygen -t ed25519 -C "github-actions" -f deploy_key -N ""
```

That writes `deploy_key` (private) and `deploy_key.pub` (public).

**2. Let the key into the server.** In hPanel go to Advanced, SSH Access, SSH keys, and
paste the contents of `deploy_key.pub`. Test it before going further:

```bash
ssh -i deploy_key -p <port> <user>@<host> "echo ok"
```

**3. Tell GitHub.** In the repository, Settings, Secrets and variables, Actions:

| Kind | Name | Value |
|---|---|---|
| Secret | `SSH_HOST` | the server address from hPanel |
| Secret | `SSH_USER` | your `uXXXXXXXXX` username |
| Secret | `SSH_PORT` | the port from hPanel, usually 65002 |
| Secret | `SSH_PRIVATE_KEY` | the whole contents of `deploy_key`, including the BEGIN and END lines |
| Secret | `DEPLOY_PATH` | e.g. `/home/uXXXXXXXXX/domains/main.com/app` |
| Variable | `DEPLOY_ENABLED` | `true` |
| Variable | `HEALTH_URL` | optional, e.g. `https://sub.main.com/up` |

Delete `deploy_key` from your machine afterwards; the server and GitHub both have what
they need.

**4. Push something.** The Actions tab shows the tests, then the deployment, then a check
that the site answers. If `HEALTH_URL` is set and the site does not respond, the run turns
red so you find out immediately rather than from an employee.

To switch it off again, set `DEPLOY_ENABLED` to anything other than `true`. Deployments
never overlap and are never cancelled part-way, because interrupting a migration is worse
than waiting.

**Know what you are turning on.** Every green push changes the live site, and that includes
running new migrations against real attendance data. The test suite is what stands between
a mistake and production, so keep it honest, and keep Hostinger's automatic database
backups switched on.

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

The state of the system is the MySQL database plus `.env` plus the leave attachments in
`storage/app/private/leave-attachments/`. A nightly `mysqldump` covers the first; nothing
covers the third unless somebody adds the `tar` line from section 5 beside it. See that
section for what is lost when nobody does.
