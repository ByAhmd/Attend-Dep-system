# Attendance — Project Context

A very small internal attendance application: an employee opens the site on their
phone, signs in, allows location access, presses **Check In** (later **Check Out**),
and the server accepts it only when the device is within the configured radius
(default **150 m**) of the company's registered location. An administrator manages
employees, reads attendance, and configures the company location and radius.

**That is the whole business scope.** No payroll, leave, shifts, departments,
notifications, messaging, reports beyond the attendance list, API, or multi-company.
Technical work needed to make the above reliable and secure is in scope; new business
features are not.

---

## 1. Frozen stack

- **PHP ^8.3** (Herd PHP 8.4 locally; `composer.lock` is resolved with `platform.php`
  8.3.0 so it installs on 8.3 hosts), **Laravel ^13**, **Filament ^5**, **MySQL 8**
- Tests: **PHPUnit 12** (not Pest), MySQL-backed (never SQLite)
- Static analysis: **Larastan level 5**, formatting: **Pint** (default Laravel preset)
- Frontend: Vite + Tailwind 4 only for the Filament theme; no JS framework
- **No extra packages.** Ask "can Laravel or Filament already do this?" first. The only
  third-party runtime dependency is Filament itself.
- Timezone **Asia/Riyadh** — every attendance timestamp and "today" comes from the server
  (`AttendanceCalendar`), never from the device.

Local commands (Bash on this machine must use Herd's PHP explicitly):

```bash
export PATH="/c/Users/ahmed/.config/herd/bin/php84:$PATH"   # Bash only; PowerShell already resolves php to Herd
php artisan migrate
php artisan test                # MySQL database attendance_testing (see phpunit.xml)
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run build                   # builds the Filament theme
```

`DB_DATABASE=attendance_testing_b vendor/bin/phpunit` runs the suite against a second
database — use a private one when several test runs may overlap.

---

## 2. Architecture

Two Filament panels, explicit registration (no directory discovery):

| Panel | Path | Who | Contents |
|---|---|---|---|
| `employee` (default) | `/` (`/login`, `/`) | every active account | `App\Filament\Employee\Pages\Attendance` + history widget |
| `admin` | `/admin` | administrators | Employees, Attendance records, Rejected attempts, Attendance settings, Dashboard |

Layers, exactly as in the ZonKSA/StockFlow projects:

```
app/Enums/                 UserRole, UserStatus, AttendanceStatus, AttendanceAction,
                           AttendanceRejectionReason, NavigationGroup — label() + options()
app/Support/Geo/           Coordinates, LocationReading (validated value objects), Meters,
                           GoogleMapsLink (map URL for a stored position)
app/Support/Filament/      PanelAccess — which panel a user may enter
app/Data/Attendance/       LocationVerification — the backend verdict on one reading
app/Services/Geolocation/  DistanceCalculator (haversine), LocationReadingValidator
app/Services/Attendance/   AttendanceCalendar, LocationVerifier, AttendanceWorkflow,
                           AttendanceDashboardMetrics
app/Exceptions/Attendance/ AttendanceRejectedException (reason enum + verification)
app/Models/                User, Attendance, AttendanceRejection, AttendanceSetting
app/Policies/              one per model; employees never write attendance
app/Http/Middleware/       EnsureAccountIsActive (signs out deactivated accounts)
app/Console/Commands/      CreateAdminCommand (app:create-admin — the first administrator)
app/Filament/Resources/    admin resources: <Name>Resource + Schemas/ + Tables/ + Pages/
                           (+ Actions/ for actions shared by a table and an edit page)
app/Filament/Pages|Widgets admin dashboard
app/Filament/Employee/     employee panel page + widgets
app/Providers/Filament/    AdminPanelProvider, EmployeePanelProvider, Concerns/ConfiguresPanel
lang/en, lang/ar           every user-facing string; parity is tested
```

**Business logic lives in services, never in Filament pages, resources or Blade.**
The Filament layer validates input (`LocationReadingValidator`), calls
`AttendanceWorkflow`, and turns `AttendanceRejectedException` into a message.

---

## 3. Business rules (immutable)

- Attendance period = one calendar day in Asia/Riyadh. One `attendances` row per
  employee per day, enforced by `unique(user_id, attendance_date)`.
- **Check-in** requires: active account, no record today, reading accuracy ≤
  `attendance.max_accuracy_meters`, distance ≤ configured radius (inclusive, compared at
  centimetre precision).
- **Check-out** requires: today's record exists and is open, then the same location test.
- A record left open on an earlier day is shown as *Missing check-out* and is never
  closed automatically.
- The browser sends **only latitude, longitude, accuracy**. The server computes the
  distance, stamps the time, and stores what it saw. Client distance is feedback only.
- Rejections for `insufficient_accuracy` / `outside_allowed_area` are recorded in
  `attendance_rejections` (audit); state-rule rejections are not.
- Accounts are deactivated, never deleted; attendance rows are never edited or deleted
  from any interface. An administrator cannot change their own role or status.
- Company coordinates and radius live only in `attendance_settings` (single row,
  `AttendanceSetting::current()`); the default radius 150 is in `config/attendance.php`.

---

## 4. Conventions

- `declare(strict_types=1);`, `final` classes, `readonly` services and value objects.
- Docblocks explain *why*; no TODOs, no placeholders, no dead code.
- Enums: `label()` resolves through `lang/*/enums.php`; `options()` for selects.
- Models: `#[Fillable]`/`#[Hidden]` attributes, `casts()` method, `@property` docblocks
  for enum/decimal casts (PHPStan `checkModelProperties`).
- Filament resources follow the split: `Resource`, `Schemas/<X>Form`, `Tables/<X>sTable`,
  `Pages/*`, and `Actions/*` when a table and an edit page share an action; labels via
  `getNavigationLabel()` etc. returning `__()` strings; navigation groups via the
  `NavigationGroup` enum.
- Every label, placeholder, helper, validation message, empty state and notification is a
  `__()` key present in **both** `lang/en` and `lang/ar`.
- Tests: `final class …Test extends Tests\TestCase`, `use RefreshDatabase`, `#[Test]`
  attribute, snake_case method names that read as sentences, fixtures from
  `Tests\Concerns\CreatesAttendanceFixtures` (deterministic Riyadh coordinates; points
  "N metres from the company" are placed due north so the haversine distance is exact).
- Filament page tests: `Filament::setCurrentPanel('admin'|'employee')` in `setUp()`, then
  `Livewire::test(Page::class)`.
- Migrations: anonymous class, `declare(strict_types=1)`, explanatory docblock, foreign
  keys, indexes, CHECK constraints mirroring enums.

---

## 5. Security notes

- HTTPS in production (`URL::forceHttps()` when `APP_ENV=production`;
  `SESSION_SECURE_COOKIE=true`). Browser geolocation requires a secure context.
- Filament login is rate limited (5/min); check-in/out Livewire calls are rate limited too.
- Mass assignment: `User` exposes role/status to the admin form only; employees never
  reach a form that writes to users or attendances.
- Never trust the device clock, timezone, or any distance it computes.
