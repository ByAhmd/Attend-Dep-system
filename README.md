# Attendance

A very small internal attendance system for one company. An employee opens the site on
their phone, signs in, allows location access and presses **Check In** — later
**Check Out**. The server accepts the action only when the device is within the
configured radius (default **150 metres**) of the company's registered location. An
administrator adds employees, reads attendance and configures the company location.

That is the entire business scope. It is not an HR, payroll or leave system and is not
meant to become one.

---

## Technology stack

| Component | Choice |
|---|---|
| Language / framework | PHP ^8.3, Laravel ^13 |
| Admin panel and employee screen | Filament ^5 (two panels) |
| Database | MySQL 8 (MariaDB 10.6+ also works) |
| Frontend build | Vite + Tailwind 4, only for the Filament theme |
| Tests | PHPUnit 12 against MySQL |
| Static analysis / formatting | Larastan (level 5), Pint |
| Local development | Laravel Herd + local MySQL |

The only third-party runtime dependency is Filament. There is no Redis, queue worker,
Docker, external geolocation API or paid service; the application runs on ordinary
PHP + MySQL hosting.

---

## Architecture overview

Two Filament panels share one Laravel application and one `users` table:

| Panel | URL | Who | What |
|---|---|---|---|
| Employee | `/` (`/login`) | every active account | today's status, Check In, Check Out, own history |
| Admin | `/admin` | administrators | Dashboard, Employees, Attendance records, Rejected attempts, Attendance settings |

Business logic lives in services, never in the panels:

```
app/Enums/                  UserRole, UserStatus, AttendanceStatus, AttendanceAction, AttendanceRejectionReason
app/Support/Geo/            Coordinates, LocationReading (validated value objects), Meters, GoogleMapsLink
app/Services/Geolocation/   DistanceCalculator (haversine), LocationReadingValidator
app/Services/Attendance/    AttendanceCalendar, LocationVerifier, AttendanceWorkflow, AttendanceDashboardMetrics
app/Models/                 User, Attendance, AttendanceRejection, AttendanceSetting
app/Policies/               one per model
app/Filament/Resources/     admin resources (Resource + Schemas/ + Tables/ + Pages/)
app/Filament/Employee/      the employee page and its history widget
lang/ar, lang/en            every user-facing string; Arabic is the default, APP_LOCALE=en switches to English
```

**How a check-in is decided.** The browser sends only latitude, longitude and the
accuracy reported by the Geolocation API. The server validates the numbers, computes the
haversine distance to the company coordinates, refuses readings whose accuracy is worse
than `ATTENDANCE_MAX_ACCURACY_METERS` (default 100 m), refuses distances beyond the
configured radius, and only then writes the record with its own timestamp. Refusals caused
by location or accuracy are stored in `attendance_rejections` for the administrator.

**Attendance rules.** One record per employee per calendar day in `Asia/Riyadh`, enforced
by a unique index. Check-out requires today's open record. A record left open on an
earlier day is shown as *Missing check-out* and is never closed automatically. Records are
never edited or deleted from any interface; accounts are deactivated, never deleted.

---

## Local setup (Laravel Herd)

Requirements: Laravel Herd (PHP 8.3+), a local MySQL 8 server (Herd Pro services, DBngin
or any MySQL 8), Composer, Node 22 LTS (or 20.19+) and npm, Git.

```bash
git clone <repository-url> attendance
cd attendance
composer install
copy .env.example .env          # cp on macOS/Linux
php artisan key:generate
```

Create the databases and a user (MySQL root shell, adjust credentials to taste):

```sql
CREATE DATABASE attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE attendance_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'attendance'@'localhost' IDENTIFIED BY 'attendance';
GRANT ALL PRIVILEGES ON attendance.* TO 'attendance'@'localhost';
GRANT ALL PRIVILEGES ON attendance_testing.* TO 'attendance'@'localhost';
```

`.env.example` already points at `attendance` / `attendance` / `attendance` on
`127.0.0.1:3306`; change `DB_*` if you used other values. Then:

```bash
php artisan migrate
npm install
npm run build
herd link attendance && herd secure attendance   # serves https://attendance.test
```

Optional demonstration data (never in production — it plants known passwords):

```bash
php artisan db:seed --class=DemoDataSeeder
```

This creates `admin@attendance.test` / `password` (administrator),
`sara@attendance.test` / `password` (employee), a week of attendance and two rejected
attempts, and sets the company location to Riyadh (24.7136, 46.6753).

### HTTPS locally

Browsers only expose geolocation in a **secure context**. `http://attendance.test` is not
one, so the Check In button will report that location services need HTTPS. Either:

- run `herd secure attendance` once and use `https://attendance.test`, or
- use `php artisan serve` and open `http://localhost:8000` — `localhost` counts as secure.

To try the employee screen from a phone on the same network, expose the HTTPS site
(for example with `herd share`) rather than using a plain IP address.

---

## Running the application

- Employee screen: `https://attendance.test/` (sign in at `/login`)
- Admin panel: `https://attendance.test/admin`
- Language: Arabic by default. Both login pages and the user menu of both panels offer
  the other language (`English` / `العربية`); the choice is kept in a cookie for a year.
  `APP_LOCALE` only sets the default for visitors who have not chosen.

Filament rate-limits sign-in to five attempts per minute; check-in and check-out calls are
rate-limited per user as well.

---

## Creating the first administrator

There is no public registration. On any environment:

```bash
php artisan app:create-admin --name="Company Admin" --email=admin@company.com
```

The command prompts for the password when `--password` is omitted. Alternatively set
`ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` and run `php artisan db:seed`;
the seeder creates the account once and never overwrites an existing one.

Every further account is created by an administrator under **Admin → Employees**, which
also resets passwords and activates or deactivates accounts.

---

## Configuring the company location and the 150 m radius

Sign in to `/admin` and open **Attendance settings**:

1. Open Google Maps, press and hold on the company entrance, and copy the two numbers
   shown (for example `24.7136, 46.6753`).
2. Enter them as latitude and longitude.
3. Leave the radius at **150** metres, or change it (20–5000 m).

Until the location is set, every check-in is refused with a clear message. The values live
in the single row of `attendance_settings`; nothing is hard-coded in the source.

`ATTENDANCE_MAX_ACCURACY_METERS` in `.env` (default 100) is the only other tuning knob:
readings whose reported accuracy is worse than this are refused with a request to enable
precise location. Raise it only if employees are consistently refused indoors.

---

## Production deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for the step-by-step server checklist. In short:
PHP 8.3+ with the listed extensions, MySQL 8, the web root pointed at `public/`, HTTPS,
`composer install --no-dev`, `npm run build` (or upload `public/build`),
`php artisan migrate --force`, the cache commands, then `app:create-admin` and the
settings page.

---

## Testing

```bash
php artisan test
```

**Tests run against MySQL, not SQLite** — `phpunit.xml` targets `attendance_testing` on
the local server. The CHECK constraints, DATETIME handling and the unique index that stops
a duplicate check-in are exactly what the tests exist to prove, and SQLite would leave them
unexercised. A shell variable overrides the database name when several runs may overlap:

```bash
DB_DATABASE=attendance_testing_b vendor/bin/phpunit
```

Code style and static analysis:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
composer check                  # lint + analyse + test
```

The suite covers authentication and panel access, check-in and check-out rules (inside,
outside, boundary, duplicates, accuracy, inactive accounts, day roll-over), authorization
policies, configuration defaults, the haversine distance calculation, the admin resources,
the employee screen, and English/Arabic translation parity. GitHub Actions runs the same
checks on PHP 8.3 and 8.4 for every push to `master` and every pull request
(`.github/workflows/ci.yml`).

---

## Security considerations

- Passwords are hashed by Laravel; sessions, CSRF and authorization are Laravel's and
  Filament's standard mechanisms. Policies deny every write to attendance rows from any
  interface, deny deleting accounts, and stop an administrator from changing their own
  role or status.
- The server never trusts the device: no client timestamp or client-computed distance is
  read, coordinates are validated, and every accepted or refused attempt stores what the
  server saw (coordinates, accuracy, computed distance).
- A deactivated account is signed out on its next request and can no longer sign in.
- Production must run over HTTPS. `APP_ENV=production` forces https URLs; set
  `SESSION_SECURE_COOKIE=true` as well.
- This is practical protection for a small company, not anti-spoofing: a device with a
  fake-GPS app can lie about its position. The audit table and the accuracy ceiling make
  that visible, not impossible.

---

## Geolocation requirements

- The employee's browser must support the Geolocation API and be in a secure context
  (HTTPS, or `localhost` in development). Otherwise the screen explains what is missing.
- Location permission must be granted; with it denied, the screen says so and nothing is
  sent to the server.
- The page asks for a high-accuracy fix, watches the position for up to ten seconds and
  uses the best reading. Readings worse than the accuracy ceiling are refused, so
  employees should enable precise location (GPS) on their phone.
- Distance is the great-circle (haversine) distance in metres; 150 m is inclusive.
