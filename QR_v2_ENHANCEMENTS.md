# PUSAMS — QR Attendance v2 Enhancements

**نسخه:** ۲.۰ (Additive enhancements to the existing QR Attendance feature)
**پیل شوی نسخه څخه:** `PU_SAMS_with_QR_FIXED.zip` (v1 backend + phase-2 UI already integrated)

---

## 🎯 لنډیز

دا نسخه د پخوانۍ QR-attendance ماډل باندې **پرمختللي فیچرونه** او **د کارونې تجربې لوړوالي** اضافه کوي. **هیڅ موجود منطق نه بدل شوی** — ټول اضافات په شرطي (guarded) او additive ډول دي. که چیرې دا فایلونه بېرته پرېښودل شي، سیستم دقیقاً د پخوا په ډول کار کوي.

### د بدلونونو غږ (Non-breaking guarantee)
- ✅ هیڅ ډیټابیس migration نه ده اضافه شوې (v1 migration کافي ده)
- ✅ هیڅ موجود table، column، column-type، یا function نه ده بدله شوې
- ✅ هیڅ موجود endpoint route نه ده بدله شوې
- ✅ ټول موجود CSS classes، JS globals، او templates لا هم د پخوا په ډول کار کوي
- ✅ که پرمختللي CSS/JS فایلونه لرې شي، سیستم بېرته v1 UX ته راګرځي

---

## 🆕 نوي فایلونه (۳ زیاتشوي)

| فایل | موخه |
|---|---|
| `api/session_qr_scans.php` | **Live scan feed endpoint.** د استاد modal ته د پولنګ له لارې د اوسنیو سکن شویو زده‌کوونکو لیست ورکوي |
| `api/qr_usage_stats.php` | **د ادارې لپاره احصایې endpoint.** د QR کارونې راپور د admin/manager/dean لپاره (read-only) |
| `assets/css/pusams-qr.css` | **د QR ماډل جلا CSS.** ټول selectors د `.qr-*` او `#qr*` په namespace کې دي — د بل چا CSS سره ټکر نلري |

---

## ✏️ اصلاح شوي فایلونه (۷، additive-only)

| فایل | څه اضافه شوی |
|---|---|
| `teacher/sessions.php` | Modal کې د live-scans لیست، fullscreen toggle، print sheet، copy-code button، countdown progress bar |
| `student/scan.php` | Haptic vibration + بریالي سکن beep، CDN fallback د jsQR لپاره، per-student rate-limit (۶/۳۰ ثانیې)، submitting spinner |
| `student/dashboard.php` | نوی **QR Quick Action tile** (کله چې زده‌کوونکي `scan_qr` اجازه ولري ښکاره کیږي، لایو سیشن ته highlight) |
| `includes/header.php` | د `pusams-qr.css` لپاره یو `<link>` سطر اضافه شوی |
| `includes/lang/en.php` | ۱۰ نوي کیلي (v2 UX strings) |
| `includes/lang/fa.php` | ۱۰ نوي کیلي (دري ژباړې) |
| `includes/lang/ps.php` | ۱۰ نوي کیلي (پښتو ژباړې) |

---

## 🚀 د فیچرونو تفصیلي لست

### ۱. 📊 د استاد لپاره ژوندی سکن feed
د QR modal ښي خوا کې یو card ښکاره کیږي چې هر ۴ ثانیې تازه کیږي او دا څرګندوي:
- **د اوسني سکن شویو زده‌کوونکو نومونه** (وروستي ۲۰)
- **د زده‌کوونکي ID، بشپړ نوم، د سکن دقیق وخت**
- **د لومړي کس لپاره غزیدلی سبز flash animation** (تازه سکن)
- **د پرمختګ سلنه** (marked / total)

### ۲. 🖥️ Fullscreen QR display
د modal header کې د `⛶` تڼۍ فشارول د QR canvas بشپړ صفحې ته وړي — ډیر ښه د لوی ټولګي یا پروجکټر لپاره. QR canvas یې تر ۴۲۰×۴۲۰ لوی کیږي، backup code تر ۵rem.

### ۳. 🖨️ QR + backup code چاپول
د **Print** تڼۍ یو پاک print sheet جوړوي (QR + backup code + سیشن نوم) او `window.print()` غوښتنه ورکوي — د هغه استادانو لپاره ډیر ښه چې پروجکټر نلري، مثلاً د تخته نصبولو لپاره.

### ۴. 📋 د کوډ کاپي کول
د backup code لاندې **Copy** تڼۍ چې ښه د خبرو یا SMS شریکولو لپاره کار ورکوي. Clipboard API + legacy fallback.

### ۵. ⏳ د تازه‌کیدو progress bar
د countdown سره یو تنکی progress bar سلاید کیږي چې دیداري ډول ښه چار ښیي چې QR کله تازه کیږي.

### ۶. 📱 Haptic vibration + beep (زده‌کوونکي)
د بریالي سکن څخه وروسته:
- **د کیبل چڼل** موبایل کې (کم مدت، غیر‌رخنه‌کوونکی)
- **ساده کم صدا beep** (که د browser اجازه ورکړي)
- **د "Submitting…" spinner** پیغام

### ۷. 🌐 CDN fallback د jsQR لپاره
که local `jsQR.min.js` load ونشي (د deployment ستونزه)، په اتوماتیک ډول د jsDelivr CDN څخه یې راوباسي — د زده‌کوونکو د سکن پاڼې بشپړ توقف نه کیږي.

### ۸. 🛡️ Rate limiting د زده‌کوونکو پر سکن
یو معمولي per-session in-memory rate-limiter:
- تر ۶ هڅو پورې برست منل کیږي
- بیا ۳ ثانیې cooldown د هغه ۳۰ ثانیو د اوسپه لپاره
- تل تر ۱ ثانیې پورې مطلق قدم
- **د DB ټکاو نه دی، د معمول کارونې څخه هیڅ اثر نلري**

### ۹. 🎨 د زده‌کوونکي ډیشبورډ Quick Action tile
د زده‌کوونکو په ډیشبورډ کې یو خورا څرګند **"Scan Attendance"** carte چې:
- کله چې د زده‌کوونکي `scan_qr` اجازه فعاله وي، ښکاره کیږي
- که لایو سیشن روان وي، د "Scan now" لپاره emphasis lets
- یو زړه پورې gradient لري چې د پام وړ کیږي

### ۱۰. 📈 د ادارې احصایې endpoint
د `/api/qr_usage_stats.php` نوی endpoint چې:
- د QR/manual/pending سیشنونو ټول شمیر
- د QR/manual attendance ریکاردونو ټول شمیر
- د تیرو ۳۰ Dayو ورځنی ماشور (breakdown)
- تر ۱۰ استادانو پورې چې د QR ډیر کاروي
- **د admin/manager/dean رول لپاره یوازې** — read-only

### ۱۱. 🌍 د RTL سره ملاتړ
د پښتو/دري لپاره، backup code او manual token همیشه LTR ښکاري (نمبرونه یو ډول کیدی شي) — د CSS کې د `unicode-bidi` اصلاح شوی.

---

## 🔧 نصبولو ګامونه

**که تاسو د v1 (`PU_SAMS_with_QR_FIXED.zip`) قبلاً نصب کړی وي:**

1. د دې zip فایل مینځپانګه د موجوده `PU_SAMS/` فولډر پر اورله واچوئ (overlay)
2. که موجود دی، `PU_SAMS.sql` او `migration_qr_attendance.sql` **بیا نه چلوئ** — دې ته اړتیا نشته
3. تازه شوي CSS/JS assets ته د browser cache-busting query string (`?v=2`) لا دمخه اضافه شوی
4. لاندې ازمایښتونه پرمخ یوسئ:

### د صحت‌سنجۍ ازمایښتونه

| # | ازمایښت | تمه شوی پایله |
|---|---|---|
| 1 | د زده‌کوونکي ډیشبورډ بارول | نوی **"Scan Attendance"** gradient tile ښکاري |
| 2 | د استاد **Generate QR** پرانیستل | نوی modal د live-scans feed سره ښکاري، fullscreen/print/copy تڼۍ کار کوي |
| 3 | زده‌کوونکی د backup code په لیکلو سکن کوي | د استاد modal کې د زده‌کوونکي نوم په ۴ ثانیو کې ښکاره کیږي (سبز flash سره) |
| 4 | د زده‌کوونکو د سکن تڼۍ ۱۰ ځله چټک ماشول | ۶ هڅو څخه وروسته warning: "Please slow down..." |
| 5 | استاد د **Print** تڼۍ فشاروي | یو پاک print dialog QR + code سره پرانیستل کیږي |
| 6 | استاد د **Fullscreen** تڼۍ فشاروي | QR modal بشپړ صفحه لوی کیږي |
| 7 | زده‌کوونکی له موبایل څخه سکن کوي | ښکته وایبرشن + کوچنی beep د بریالي سکن پر وخت |
| 8 | admin دې URL ته لاسرسی وموند: `/api/qr_usage_stats.php` (GET) | JSON د QR usage statistics سره بیرته ورکوي |
| 9 | زده‌کوونکی چې **`scan_qr` اجازه یې نلري** | Quick Action tile ورته نه ښکاري |
| 10 | د لاسي ثبت شویو سیشنونو ثبتول | د پخوا په ډول کار کوي (هیڅ تاثیر نه لري) |

---

## 🧹 د بیرته‌ګرځولو (rollback) ټکي

که چیرې دا v2 اصلاحات ستونزه ولري:

1. **جزوي rollback (سفت):** د assets/css/pusams-qr.css فایل لرې کول یوازې د بصري بدلونونو مخنیوی کوي. سیستم په بشپړ ډول کار کوي.
2. **بشپړ rollback:** د دې فایلونو د v1 نسخه بیرته راوستل کافي دي:
   - `teacher/sessions.php`
   - `student/scan.php`
   - `student/dashboard.php`
   - `includes/header.php`
   - `includes/lang/en.php`, `fa.php`, `ps.php`
3. نوي endpoint فایلونه (`session_qr_scans.php`, `qr_usage_stats.php`) کولی شي ساده ډول لرې شي — هیڅ چا نه دي راجع.
4. د DB migration بدلون **اړتیا نه لري** — v2 هیڅ نوی DB بدلون نلري.

---

## 📋 د فایلونو لست (شکل شوی)

```
PU_SAMS/
├── api/
│   ├── session_qr_state.php         (پخوانی، بې تغیره)
│   ├── session_qr_scans.php         🆕 NEW
│   └── qr_usage_stats.php           🆕 NEW
├── assets/
│   ├── css/
│   │   └── pusams-qr.css            🆕 NEW
│   └── js/
│       ├── jsQR.min.js              (پخوانی، بې تغیره)
│       └── qrcode.min.js            (پخوانی، بې تغیره)
├── includes/
│   ├── header.php                   ✏️ (یو <link> تازه‌شوی)
│   └── lang/
│       ├── en.php                   ✏️ (۱۰ نوي کیلي)
│       ├── fa.php                   ✏️ (۱۰ نوي کیلي)
│       └── ps.php                   ✏️ (۱۰ نوي کیلي)
├── student/
│   ├── dashboard.php                ✏️ (Quick Action tile اضافه شوی)
│   └── scan.php                     ✏️ (Haptic + CDN fallback + rate-limit)
├── teacher/
│   └── sessions.php                 ✏️ (Modal پراخول: live feed + fullscreen + print + copy)
└── QR_v2_ENHANCEMENTS.md            🆕 NEW (دا فایل)
```

---

## 🔒 د امنیت پام

- ټول نوي endpoints **CSRF-محفوظ دي** (د `session_qr_state.php` سره ورته ډول)
- د استاد ملکیت چک په هر endpoint کې تکرار کیږي (`teacher_id = current user`)
- د احصایو endpoint یوازې د admin/manager/dean لپاره او **read-only** دی
- Rate limiter د user's session کې ذخیره کیږي — د خدمت‌ اجباري ډول برنامیده نه دی، بلکې د UX aid دی
- هیڅ نوي permissions اضافه شوي نه دي — v1 پرمېټ رول کارول کیږي

---

## 💬 د API نمونې (JSON)

### `POST /api/session_qr_scans.php`
```json
{
  "success": true,
  "session_id": 42,
  "marked_count": 18,
  "qr_count": 18,
  "total_students": 25,
  "scans": [
    {
      "student_id": 101,
      "full_name": "Ahmad Karimi",
      "student_code": "STU-0001",
      "marked_at": "2025-11-14 10:23:45",
      "time_short": "10:23:45"
    }
  ],
  "server_time": "2025-11-14T10:24:02+04:30"
}
```

### `GET /api/qr_usage_stats.php`
```json
{
  "success": true,
  "migration_applied": true,
  "totals": {
    "qr_sessions": 143,
    "manual_sessions": 892,
    "pending_sessions": 21,
    "qr_records": 3247,
    "manual_records": 18456
  },
  "by_day": [
    { "day": "2025-10-15", "qr": 5, "manual": 22 }
  ],
  "top_teachers": [
    { "teacher_id": 12, "teacher_name": "Dr. Fatima Noor", "qr_sessions": 34 }
  ]
}
```

---

**غزیدلی مسیر:** د دې پاڼې کیفیت هرلوری ازمول شوی. که کومې نوې پوښتنې راولاړې شي، د `api/session_qr_scans.php` او `assets/css/pusams-qr.css` د غزیدل لپاره سولوول سرچینه.
