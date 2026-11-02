# PUSAMS — QR Attendance Feature (Install Guide)

**Version:** additive upgrade, safe on top of PU_SAMS current production build
**Files changed:** 5 (edits) &nbsp;|&nbsp; **Files added:** 6

---

## 0. ⚠ FIX NOTE (this release) — resolves the site-wide **HTTP 500** on the main page

**Symptom reported:** every page after login returned "HTTP 500 — Internal Server Error";
the main page could not open.

**Root cause:** `config/database.php` in the previous drop was accidentally **shipped empty (0 bytes)**. Every page in the codebase calls `getDB()` — which lives inside `config/database.php` — so with the file empty PHP threw:

```
Fatal error: Uncaught Error: Call to undefined function getDB()
```

on every request past `require_once __DIR__.'/config/database.php'`. That is what the browser reported as HTTP 500.

**Fix applied in this release:** `config/database.php` has been rebuilt as a full, self-contained connection layer that:

1. Defines the standard `DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / DB_CHARSET` constants, with **XAMPP-friendly defaults** (`127.0.0.1` / `root` / no-password / `PU_SAMS`) so the file works out-of-the-box on a fresh XAMPP install.
2. Also honours environment variables `PUSAMS_DB_HOST / _PORT / _NAME / _USER / _PASS / _CHARSET`, so shared hosting deployments do not need to edit the file — set the vars in `.htaccess` / hosting panel and you are done.
3. Exposes a singleton `getDB(): PDO` that:
    * uses `PDO::ERRMODE_EXCEPTION`
    * uses `PDO::FETCH_ASSOC` by default (matches every `fetch()` / `fetchAll()` call in the codebase)
    * disables emulated prepares (real prepared statements)
    * forces `utf8mb4` on the session (safe for Pashto / Dari / Arabic + emoji)
    * best-effort syncs `SET time_zone` to `PUSAMS_ACTIVE_TZ`
4. On DB failure, renders a **friendly branded HTML page** for browsers and a proper `{"success":false,"message":…}` JSON for `/api/*` — it **never** leaks the DSN, username, or stack trace to the user.

**End-to-end verification performed on a clean MariaDB after applying the SQL migration:**

| URL | HTTP | Behaviour |
|---|---|---|
| `/index.php` | 302 | Redirects to `/login.php` (as before) |
| `/login.php` | 200 | Renders the PUSAMS login page |
| `/student/scan.php` (anon) | 302 | Redirects to login (auth guard) |
| `/api/session_qr_state.php` (anon POST) | 401 | Returns `{"success":false,"message":"Unauthorized"}` (JSON) |

No 500 anywhere. No other file needed to change to restore service.

---

## 1. What was added

### New files
| File | Purpose |
|---|---|
| `sql/migration_qr_attendance.sql` | Idempotent DB migration (columns + permissions) |
| `api/session_qr_state.php` | Teacher endpoint: generate / refresh / cancel a session QR |
| `student/scan.php` | Student page: camera scanner + backup-code entry |
| `assets/js/qrcode.min.js` | Client-side QR **generator** (renders the teacher's QR on canvas) |
| `assets/js/jsQR.min.js` | Client-side QR **decoder** (reads camera frames for the student) |
| `QR_INSTALL_README.md` | This file |

### Existing files edited (additively only)
| File | What was added |
|---|---|
| `config/database.php` | **Rebuilt from empty** — see §0 above |
| `includes/helpers.php` | 4 new helpers: `claimAttendanceMethod`, `releaseAttendanceMethodIfEmpty`, `generateUniqueSessionBackupCode`, `isStudentEnrolledInClass`; 1 new student menu entry |
| `includes/lang/en.php` | 24 new QR keys (existing keys untouched) |
| `includes/lang/fa.php` | 24 new QR keys (Dari translations) |
| `includes/lang/ps.php` | 24 QR keys in Pashto (file was empty; falls back to English for other keys) |
| `teacher/sessions.php` | QR switcher bar above the roster + live-QR modal + `claimAttendanceMethod('manual')` guard on the existing `save_group` handler |

**Nothing was renamed, dropped, or repurposed.** Dashboards, reports, and manager/admin views all continue reading `attendance_records` and `attendance_sessions` the exact same way — QR records write with `mark_method='qr'` and appear automatically.

---

## 2. Install steps (production)

### Step A — Apply the DB migration
```bash
mysql -u <user> -p <database_name> < sql/migration_qr_attendance.sql
```

The script is **idempotent** — running it twice is safe. It:
- adds 5 columns to `attendance_sessions` (defaults set so historical rows behave like the pre-QR flow)
- widens the `attendance_records.mark_method` enum by adding `'qr'`
- inserts 2 permission rows (`start_qr_session` for teachers, `scan_qr` for students)

### Step B — Deploy the files
Simply overlay the extracted archive over your existing `PU_SAMS/` directory. **In particular, make sure `config/database.php` is copied over** — the file rebuilt in this release fixes the site-wide HTTP 500. If your production instance already has a working `config/database.php` with custom credentials, keep your version and just verify that `getDB()` is defined inside it.

### Step C — Configure DB credentials (only if XAMPP defaults do not match)

Two options — pick whichever fits your host:

**Option 1 — Edit the constants directly** in `config/database.php`:

```php
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'PU_SAMS');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
```

**Option 2 — Environment variables** (no file edit needed, ideal for shared hosting):

```apache
# .htaccess
SetEnv PUSAMS_DB_HOST  db.example.com
SetEnv PUSAMS_DB_NAME  pu_sams_prod
SetEnv PUSAMS_DB_USER  pu_user
SetEnv PUSAMS_DB_PASS  s3cret
```

### Step D — Verify
Run the sanity checks from `Section 4` below.

---

## 3. Backing out (rollback)

If you ever need to remove the feature completely:
1. **Disable at the permission layer** (soft rollback — recommended):
   ```sql
   UPDATE permissions SET granted=0 WHERE permission IN ('start_qr_session','scan_qr');
   ```
   Teachers will no longer see the **Generate QR** buttons; students will no longer see **Scan Attendance** in the menu. Manual attendance keeps working unchanged.

2. **Full rollback of code** — restore the original files. The DB columns can stay: they are unused when the feature is disabled and cost nothing (all default to `pending`/`NULL`).

> ⚠ **Do not** roll back `config/database.php` to the earlier empty file — that is what caused the 500 in the first place. Keep the rebuilt version.

---

## 4. Sanity-check testing

| # | Test | Expected result |
|---|---|---|
| 1 | Open the site root (`/index.php`) | Redirects to `/login.php` (302). **No 500.** |
| 2 | Load `/login.php` in a browser | Login page renders in 200 |
| 3 | Log in as teacher | Redirects to `/teacher/dashboard.php` |
| 4 | Open an existing day of manual attendance | Works exactly as before; no visible change until you scroll to the credit-slot cards |
| 5 | Save manual attendance on a fresh session | Saves normally; `attendance_method` on that session row becomes `'manual'` |
| 6 | Click **Generate QR** on a fresh session | Modal appears with rotating QR + 6-digit backup code; `attendance_method` becomes `'qr'` |
| 7 | Try to save manual attendance on a QR-locked session | Warning banner: *"This credit is set up for QR attendance."* Save is blocked |
| 8 | Student opens `/student/scan.php`, enters the 6-digit backup code | Success message, one row inserted into `attendance_records` with `mark_method='qr'` |
| 9 | Same student scans again | *"You have already been marked for this class credit today."* — no duplicate |
| 10 | Student from a different class scans the same code | *"You are not enrolled in this class."* |
| 11 | Teacher clicks **Cancel QR** before any scan | Session returns to `attendance_method='pending'`; both buttons re-enable |
| 12 | Teacher clicks **Cancel QR** after at least one scan | Rejected: *"Cannot cancel: attendance records already exist for this credit."* |
| 13 | Attendance history / reports / dashboards | QR-marked students appear identically to manual entries (they read from the same tables) |
| 14 | Missed / expired session | Both buttons hidden — matches existing behavior |

---

## 5. Configuration knobs

- **QR rotation interval** (default 30 s): stored per-session in `attendance_sessions.qr_refresh_seconds`. Change the default by updating the `SMALLINT UNSIGNED DEFAULT 30` in the migration, or per-session with an admin SQL update. Clamped server-side to 5–300 s.
- **Enable/disable per role**:
  ```sql
  UPDATE permissions SET granted=0 WHERE role_id=3 AND permission='start_qr_session'; -- Teachers off
  UPDATE permissions SET granted=0 WHERE role_id=4 AND permission='scan_qr';          -- Students off
  ```
- **Backup-code length** is fixed at 6 digits (see `generateUniqueSessionBackupCode()` in `includes/helpers.php`).

---

## 6. Security notes

- **CSRF-protected** on every POST endpoint (teacher & student). Existing `CSRF_TOKEN_NAME` and `$_SESSION['csrf_token']` are reused.
- **Ownership check**: teachers can only refresh/cancel QRs for their own sessions (`teacher_id = current user`).
- **Locking**: `claimAttendanceMethod()` uses `SELECT … FOR UPDATE` inside a transaction to prevent race conditions between simultaneous "save manual" and "generate QR" clicks.
- **Duplicate scans**: guarded both by the unique `(session_id, student_id)` index and an explicit `INSERT IGNORE` check.
- **Expired codes**: server-side rejection independent of client clock (compares `NOW()` to `qr_expires_at`).
- **DB error messages**: `config/database.php` never renders the raw exception, DSN, or credentials to the browser — full detail only goes to `error_log`.

---

## 7. Files summary (unchanged everywhere else)

| Untouched | Why safe |
|---|---|
| `syncSessionsForDate()`, `markMissedSessionsForDate()` | QR reuses the session rows they already create |
| Manager / admin / dean views on `attendance_records` | QR writes to the same table, same shape |
| Dashboard counters (`Today's Operations`, live/pending session counts) | They read `status` / `is_missed`, never `attendance_method` |
| `reports/attendance_history.php`, `missed/index.php` | Read the same tables; no schema-shape changes |
| `saveGroupedAttendance()` | Called unchanged after the lock guard passes |
