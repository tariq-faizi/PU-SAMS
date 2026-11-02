# PU-SAMS — Paktia University Student Attendance Management System

A complete, role-based attendance management system built for Paktia University, with QR-code attendance, timetable management, approvals, audit logging and reporting.

Developed as a final-year Computer Science project at Paktia University (Gardez, Afghanistan).
**Project Developer & Team Leader:** [Tariq Faizi](https://github.com/tariq-faizi)

---

## Features

- **Role-based access** — separate panels for **Admin, Dean, Manager, Teacher** and **Student**, with a permissions layer (`config/permissions.php`)
- **QR-code attendance (v2)** — teachers open an attendance session with a live QR code; students scan to mark attendance; includes a live scan feed for the teacher and QR usage statistics for administration
- **Academic structure** — universities, faculties, departments, classes, subjects and semesters
- **Timetable management** — weekly timetables with a visual timetable view per class
- **Approvals workflow** — attendance edits and requests pass through approval queues
- **Missed-classes tracking** — automatic detection and follow-up of missed sessions
- **Audit log** — every sensitive action is recorded
- **Announcements & notifications** — system-wide and per-role
- **Backups** — manual and scheduled database backups with history
- **Reports & charts** — attendance statistics and dashboards (Chart.js)
- **Multilingual-ready** — full UTF-8 (utf8mb4) support for Pashto / Dari / Arabic text
- **PWA manifest** included for installable web-app behaviour

## Tech Stack

| Layer    | Technology |
|----------|------------|
| Backend  | PHP (PDO, prepared statements) |
| Database | MySQL / MariaDB (26 tables) |
| Frontend | Bootstrap 5, custom CSS themes, vanilla JavaScript |
| Charts   | Chart.js |
| QR       | qrcode.js (generation) + jsQR (scanning) |
| Icons    | Font Awesome |

## Project Structure

```
admin/          Admin panel (users, faculties, departments, classes, subjects, semesters, timetable, approvals, backups, settings, audit log)
dean/           Dean panel (faculty-level oversight)
manager/        Manager panel
teacher/        Teacher panel (sessions, QR attendance)
student/        Student panel (scan attendance, view records)
api/            JSON endpoints (charts, cascading selects, QR session state, live scans, QR stats, auto-sync)
config/         Configuration, database layer (PDO), permissions
includes/       Shared includes and language files
reports/        Reporting pages
notifications/  Notification handling
cron/           Scheduled auto tasks
sql/            Database schema, seed data and the QR-attendance migration
assets/         CSS, JS, images and webfonts
```

## Installation (XAMPP / local)

1. Copy the project folder into your web root (e.g. `htdocs/PU_SAMS`).
2. Create a MySQL/MariaDB database named `PU_SAMS`.
3. Import, in order:
   - `sql/PU_SAMS.sql` — full schema (26 tables)
   - `sql/PU_Data.sql` — settings & policy seed
   - `sql/migration_qr_attendance.sql` — QR attendance tables
4. Configure the database connection. Out of the box the system uses XAMPP-friendly defaults (`127.0.0.1`, user `root`, empty password, database `PU_SAMS`). On shared hosting, set environment variables instead of editing code:

   `PUSAMS_DB_HOST`, `PUSAMS_DB_PORT`, `PUSAMS_DB_NAME`, `PUSAMS_DB_USER`, `PUSAMS_DB_PASS`, `PUSAMS_DB_CHARSET`

5. Open `http://localhost/PU_SAMS/` — you are redirected to the login page.

See `QR_INSTALL_README.md` for the QR feature install notes and `QR_v2_ENHANCEMENTS.md` for the v2 QR changelog.

## Database

26 tables covering users & roles, academic structure, timetable, attendance sessions/records/history, missed classes, edit requests, approvals, audit log, session tokens, backups, announcements, notifications and settings — see `sql/PU_SAMS.sql`.

## Author

**Tariq Faizi** — Full Stack Developer | IT Officer | Database Administrator
- GitHub: [github.com/tariq-faizi](https://github.com/tariq-faizi)
- LinkedIn: [linkedin.com/in/tariq-faizi-af](https://www.linkedin.com/in/tariq-faizi-af)
- Gardez, Paktia, Afghanistan — open to remote work

## License

Released under the MIT License — see [LICENSE](LICENSE).
