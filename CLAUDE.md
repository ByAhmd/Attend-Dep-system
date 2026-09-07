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
                           AttendanceRejectionReason, NavigationGroup — label() + options();
                           Locale (ar/en, nativeLabel(), other(), current())
app/Support/Geo/           Coordinates, LocationReading (validated value objects), Meters,
                           GoogleMapsLink (map URL for a stored position)
app/Support/Filament/      PanelAccess — which panel a user may enter;
                           LanguageMenuItems — the language entry of the user menu
app/Support/Attendance/    SessionDuration — hours and minutes, as Meters is for distance
app/Data/Attendance/       LocationVerification — the backend verdict on one reading
app/Services/Geolocation/  DistanceCalculator (haversine), LocationReadingValidator
app/Services/Attendance/   AttendanceCalendar, LocationVerifier, AttendanceWorkflow,
                           AttendanceDashboardMetrics, AttendanceDaySummary (one
                           employee's day as its sessions), PresencePingRecorder
app/Services/Users/        EmployeeInvitationService + Invitation (url, emailed)
app/Notifications/         EmployeeInvitationNotification
app/Listeners/             ActivateInvitedEmployee — Pending becomes Active on first
                           password set; discovered automatically from app/Listeners
app/Exceptions/Attendance/ AttendanceRejectedException (reason enum + verification)
app/Models/                User, Attendance (one SESSION), AttendanceRejection,
                           AttendanceSetting, PresencePing
app/Policies/              one per model; employees never write attendance
app/Http/Middleware/       EnsureAccountIsActive (signs out deactivated accounts),
                           SetLocale (applies the language cookie; persistent for Livewire)
app/Http/Controllers/      SwitchLocaleController — GET /locale/{ar|en}, the only web route
app/Console/Commands/      CreateAdminCommand (app:create-admin — the first administrator)
app/Filament/Auth/         ResetPassword — the stock page admits a Pending account so an
                           invitation can be accepted; Inactive is still refused
app/Filament/Resources/    admin resources: <Name>Resource + Schemas/ + Tables/ + Pages/
                           (+ Actions/ for actions shared by a table and an edit page)
app/Filament/Pages|Widgets admin dashboard
app/Filament/Employee/     employee panel page + widgets
app/Providers/Filament/    AdminPanelProvider, EmployeePanelProvider, Concerns/ConfiguresPanel
lang/ar, lang/en           every user-facing string; Arabic is the default locale
                           (APP_LOCALE=ar), English the fallback; parity is tested
```

**Business logic lives in services, never in Filament pages, resources or Blade.**
The Filament layer validates input (`LocationReadingValidator`), calls
`AttendanceWorkflow`, and turns `AttendanceRejectedException` into a message.

---

## 3. Business rules (immutable)

- Attendance period = one calendar day in Asia/Riyadh. One `attendances` row is one
  **session**, and a day may hold several: an employee who leaves checks out and checks
  in again on return, so time inside and outside is visible. At most one **open** session
  per employee per day, enforced by `unique(user_id, open_attendance_date)` on a virtual
  column carrying `attendance_date` only while `check_out_at` is NULL. The application
  never reads or writes that column; it exists to carry the index.
- **Check-in** requires: active account, no open session today (sessions already closed
  today do not block it, and neither does a session left open on an earlier day), reading
  accuracy ≤ `attendance.max_accuracy_meters`, distance ≤ configured radius (inclusive,
  compared at centimetre precision).
- **Check-out** requires: an open session today, then the same location test.
- A session left open on an earlier day is shown as *Missing check-out* and is never
  closed automatically.
- **Presence pings** are recorded only while a session is open and only while the page is
  open and the phone awake. They are supporting evidence, never proof of absence, and no
  interface may imply otherwise. A gap means nothing on its own.
- Employees set their own password through an invitation. A new account is **Pending**
  with a NULL password until its owner follows the link; Pending cannot sign in.
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
  `__()` key present in **both** `lang/ar` and `lang/en`. The suite runs in Arabic (the
  shipped default); a test that asserts an English sentence sets `App::setLocale('en')`.
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
