<?php

if (!function_exists('pusamsEnglishPhraseMap')) {
function pusamsEnglishPhraseMap(): array {

    static $map = null;
    if ($map !== null) return $map;
    $map = [

        // ====================================================================
        // FIX 19c — single-word entries that collided with real DB content
        // (student / class / subject / department / faculty names) were
        // removed from this map. They previously caused user names like
        // "تاج‌محمد محصل" to render as "تاج‌محمد Student" in tables and
        // dropdowns because strtr() rewrites every occurrence in the page
        // buffer. Multi-word UI phrases above are still translated safely.
        // ====================================================================
        
        'لاسرسی بلاک دی — د درجه‌بندي بشپړ لید یوازې د معاون د Studentsو، د پوهنځۍ رئیس، او د پوهنځۍ مدیر لپاره دی.' =>
            'Access denied — the full hierarchy view is restricted to the Vice-Chancellor, the Faculty Dean, and the Faculty Manager.',
        'لاسرسی بلاک شو — تاسو صرف خپل حضور کتلای شئ.' => 'Access denied — you can only view your own attendance.',
        'لاسرسی بلاک دی — تاسو صرف خپل حضور کتلای شئ.' => 'Access denied — you can only view your own attendance.',
        'د بل ټولګي لید بلاک دی.' => 'Viewing other classes is blocked.',
        'لاسرسی نشته — یوازې VC او د پوهنځۍ مدیر کولی شي حاضري ایډیټ کړي' => 'Access denied — only the Vice-Chancellor and Faculty Manager may edit attendance.',
        'status باید present یا absent وي' => 'Status must be either present or absent.',
        'Session ونه موندل شو' => 'Session not found.',
        'محصل ونه موندل شو' => 'Student not found.',
        'محصل د دې ټولګي نه دی' => 'The student does not belong to this class.',
        'د بل پوهنځي محصل ته لاسرسی بلاک دی' => 'Access denied — student belongs to a different faculty.',
        'د یوزر نوم او Full Name اړین دي.' => 'Username and full name are required.',
        'د یوزر نوم باید 3 تر 60 توري وي او یوازې انګلیسي توري، شمیرې،' => 'Username must be 3-60 characters and may contain only English letters, digits,',
        '. - ولري.' => '. - characters.',
        'دا د یوزر نوم مخکې له مخکې شته. بل وټاکئ.' => 'This username already exists. Please choose another one.',
        'مخکې له مخکې شته. بل وټاکئ.' => 'already exists. Please choose another one.',
        'مخکې له مخکې شته. مهرباني وکړئ بل وټاکئ.' => 'already exists. Please choose another one.',
        'مخکې له مخکې راجستر شوې ده.' => 'is already registered.',
        'دا د یوزر نوم' => 'This username',
        'د پټنوم ستونزه:' => 'Password issue:',
        'کاروونکی په بریالۍ توګه نوی شو.' => 'User updated successfully.',
        'کاروونکی په بریالۍ توګه جوړ شو.' => 'User created successfully.',
        'کاروونکی په بشپړ Type وحذف شو (د ټولګي، پوهنځۍ او څانګو څخه).' => 'User completely removed (from classes, faculties and departments).',
        'کاروونکی آرشیو شو.' => 'User archived.',
        'تکراري ارزښت:' => 'Duplicate value:',
        'د دیتابیس د تړاو ستونزه — د پوهنځۍ/څانګې/ټولګي ID ناسم دی.' => 'Database relation error — the faculty/department/class ID is invalid.',
        'د Users په خوندي کولو کې خطا:' => 'Error while saving the user:',
        'د حذف وخت خطا:' => 'Delete error:',
        'مهرباني وکړئ د Users رول وټاکئ.' => 'Please select a user role.',
        'Policies په بریالیتوب سره وساتل شوې.' => 'Policies saved successfully.',
        'Policies وساتل شوې مګر د backfill پر مهال ستونزه پېښه شوه:' => 'Policies saved, but a problem occurred during backfill:',
        'د Policies د ساتنې پر مهال د ډیټابیس ستونزه پېښه شوه:' => 'A database error occurred while saving policies:',
        'د موجوده پالیسیو لوستل ډیر و نه شول:' => 'Failed to read the existing policies:',
        'د سمیستر د پیل Date اړینه ده.' => 'Semester start date is required.',
        'د سمیستر د پیل Date باید په YYYY-MM-DD بڼه وي.' => 'Semester start date must be in YYYY-MM-DD format.',
        'د سمیستر Week باید د ۱ او ۵۲ ترمنځ وي.' => 'Semester weeks must be between 1 and 52.',
        'د سمیستر Week (1-52).' => 'Semester weeks (1-52).',
        'د روان سمیستر د پیل Date (YYYY-MM-DD).' => 'Current semester start date (YYYY-MM-DD).',
        'د settings ټیبل ته لاسرسی ونه شو:' => 'Cannot access the settings table:',
        'لومړی د سمیستر د پیل Date وټاکئ. کله چې وساتل شي، سیستم به Automatic د هغې نېټې څخه تر اوسني پورې ټول د حضور سیشنونه جوړ کړي.' => 'First set the semester start date. Once saved, the system will automatically build every attendance session from that date up to today.',
        'د هغو سیشنونو لپاره چې حضور یې نه دی نیول شوی، ټول Students به' => 'For sessions whose attendance was not taken, all students will be',
        'حساب شي (د میس کلاس fixed rule).' => 'counted (missed-class fixed rule).',
        'Semester Start نیټه نه ده ټاکل —' => 'Semester start date is not set —',
        'د timezone ناسم و (' => 'Timezone was invalid (',
        ') — ثبت ونه شو.' => ') — not saved.',
        '— Automatic (Auto-detect from OS) —' => '— Auto (detect from OS) —',
        'If the system time differs from your computer, set the university\'s local timezone here.' => 'Does the system time differ from the computer time? Pick the university\'s local timezone here.',
        '— Current Active:' => '— Currently active:',
        'PHP time:' => 'PHP time:',
        'د ډیټابیس وصل تم شو' => 'Database connection failed',
        'مهرباني وکړئ ډاډ ترلاسه کړئ چې MySQL روان دی' => 'Please make sure MySQL is running',
        'او ډیټابیس' => 'and the database',
        'موجود دی.' => 'exists.',
        'د حاضرۍ پر cell کلیک وکړئ ترڅو' => 'Click each cell to',
        'کلیک وکړئ ترڅو حالت بدل کړئ' => 'Click to change the status',
        'راتلونکې نیټه — لاک شوې' => 'Future date — locked',
        'راتلونکې نیټې' => 'Future dates',
        'راتلونکې' => 'Upcoming',
        'لاک دي.' => 'are locked.',
        'بدل کړئ.' => 'change.',
        'غلطي: HTTP' => 'Error: HTTP',
        'د شبکې غلطي:' => 'Network error:',
        'خوندي شو' => 'Saved',
        'Attendance is locked — upcoming session.' => 'Attendance entry is locked — upcoming session.',
        'Audit Log — د نن Dayې' => 'Audit log — today',
        'Audit Log' => 'Audit log',
        'د نن Dayې لپاره هېڅ Activeیت نشته.' => 'No activity for today.',
        'بیک اپ بریالی شو:' => 'Backup successful:',
        'د بیک اپ پر مهال خطا:' => 'Backup error:',
        'د Automatic Backup Schedule وساتل شو.' => 'Automatic backup schedule saved.',
        'بیک اپ فایل وحذف شو.' => 'Backup file deleted.',
        'Backup History (Last 30)' => 'Backup history (last 30 backups)',
        'د بیک اپ تاریخچه' => 'Backup history',
        'No backups taken yet.' => 'No backups have been taken yet.',
        'Are you sure you want to delete this backup?' => 'Are you sure you want to delete this backup?',
        'No subjects found for this semester.' => 'No subjects exist for this semester.',
        'No classes found for this semester.' => 'No classes exist for this semester.',
        'No active students found for this semester.' => 'No active students exist for this semester.',
        'د پوهنځۍ رئیس یوازې د خپلې پوهنځۍ لپاره سمیستر اضافه کولی شي.' => 'A faculty dean can only add a semester for their own faculty.',
        'Policies وساتل شوې. د سمیستر له پیل نېټې (%s) څخه backfill بشپړ شو: %d Dayې، %d سیشنونه میس شول، %d Students حاضر ثبت شول.' =>
            'Policies saved. Backfill from semester start (%s) complete: %d days, %d sessions missed, %d students marked present.',
        'هېڅ اعلان شتون نلري.' => 'No announcements available.',
        'Are you sure you want to delete?' => 'Are you sure you want to delete it?',
        'Previous Announcements (' => 'Previous announcements (',
        'هېڅ ډاټا و نه موندل شوه.' => 'No data found.',
        'د خطر په حالت کې' => 'At risk',
        'د خطر حالت' => 'Risk state',
        'د خطر سره مخامخ محصلین' => 'Students at risk',
        'د خبرتیا حد' => 'Warning threshold',
        'د محرومیت حد' => 'Disqualification threshold',
        'غیر حاضري %' => 'Absence %',
        'غیر حاضر:' => 'Absent:',
        'حاضر:' => 'Present:',
        'General Attendance Summary' => 'Attendance summary',
        'Student Full Attendance' => 'Student full attendance',
        'Teacher All Classes' => 'All teacher classes',
        'Student Attendance Details' => 'Full student attendance detail',
        'Teacher Recent Sessions' => 'Teacher recent sessions',
        'Teacher Subjects' => 'Teacher subjects',
        'Attendance Summary' => 'Attendance summary',
        'Per-Subject Attendance' => 'Per-subject attendance',
        'د غیاب %' => 'Absence %',
        'Attendance History' => 'Attendance history',
        'Quick Links' => 'Quick links',
        'Basic Information' => 'Basic information',
        'د ښوونکي د غیر حاضرۍ راپورونه' => 'Teacher absence reports',
        'Teacher Subjects' => 'Teacher subjects',
        'System Backup' => 'System backup',
        'د ډیټابیس بیک اپ' => 'Database backup',
        'Take Backup Now' => 'Back up now',
        'د Automatic Backup Schedule' => 'Automatic backup schedule',
        'Automatic Backup Schedule' => 'Automatic backup schedule',
        'Backup Frequency' => 'Backup frequency',
        'Daily' => 'Daily (every day)',
        'Weekly' => 'Weekly (every week)',
        'Monthly' => 'Monthly (every month)',
        'Per Semester' => 'Semester (at the end of each semester)',
        'Time' => 'Time',
        'Day of Week' => 'Day of week',
        'Enable Automatic Backup' => 'Enable automatic backup',
        'Save Schedule' => 'Save schedule',
        'Last run:' => 'Last run:',
        'File Name' => 'File name',
        'Created By' => 'Created by',
        'Restored' => 'Restore',
        'Manual Backup' => 'Manual backup',
        'د سمیستر اوسنی حالت' => 'Current semester status',
        'د سمیستر د پیل Date' => 'Semester start date',
        'د سمیستر Week' => 'Semester weeks',
        'Semester Start' => 'Semester start',
        'Semester End' => 'Semester end',
        'د سمیستر Policies' => 'Semester policies',
        'اکاډمیک Policies' => 'Academic policies',
        'Policies خوندي کړه' => 'Save policies',
        'End (calculated):' => 'End (calculated):',
        'Weeks passed:' => 'Past weeks:',
        'completed' => 'Completed',
        'Current Semester Progress' => 'Current semester progress',
        'Total Weeks:' => 'Total weeks:',
        'Start:' => 'Start:',
        'End:' => 'End:',
        'Active Students / Active Students' => 'Active Students',
        'Full Name' => 'Full name',
        'Username' => 'Username',
        'Student ID:' => 'Student ID:',
        'Employee ID:' => 'Staff ID:',
        'Student ID' => 'Student ID',
        'د زده‌کوونکي شمېره' => 'Student number',
        'د کارمند شمېره' => 'Staff number',
        'تېر ننوتل:' => 'Last login:',
        'کارن:' => 'User:',
        'Username:' => 'Username:',
        'ایمیل:' => 'Email:',
        'ټلیفون:' => 'Phone:',
        'پوهنځی:' => 'Faculty:',
        'ډیپارټمنټ:' => 'Department:',
        'Class:' => 'Class:',
        'New Announcement' => 'New announcement',
        'Previous Announcements' => 'Previous announcements',
        'Expiry Date (Optional)' => 'End date (optional)',
        'Publish' => 'Publish',
        'پوهنځۍ ریسان' => 'Faculty deans',
        'مضامین / Subjects' => 'Subjects',
        'ټولګي / Classes' => 'Classes',
        'سمیستر / Semesters' => 'Semesters',
        'Users / Users' => 'Users',
        'څانګې / Departments' => 'Departments',
        'پالیسیې / Policies' => 'Policies',
        'Faculty' => 'Faculty',
        'امارات' => 'UAE',
        'عربستان' => 'Saudi Arabia',
        'ترکيه' => 'Türkiye',
        'برتانيه' => 'United Kingdom',
        'جرمني' => 'Germany',
        'چاپڗېريایي' => 'Global',
        'د ټولګي رئیس یوازې' => 'The class head only',
        'د خاضرۍ ثبتول قفل دي' => 'Attendance entry is locked',
        'upcoming سیشن.' => 'upcoming session.',
        '% غیاب' => '% absence',
        'id لازمي دی' => 'id is required',
        'د یوزر نوم' => 'Username',
        'یوزر نوم' => 'username',
        'خوندي کړئ' => 'Save',
        'خوندي کول' => 'Save',
        'حاضري راجستر' => 'Attendance entry',
        'میس شوي کلاسونه' => 'Missed classes',
        'میس شوی کلاس' => 'Missed class',
        'د سمولو ګامونه' => 'Audit log',
        'څخه د' => 'from',
        'محرومیت: ' => 'Disqualified: ',
        'د محرومیت اعلان: ' => 'Disqualification notice: ',
        'د حضور احتار: ' => 'Attendance warning: ',
        'د PHP وخت' => 'PHP time',
        'Semester Start نیټه نه ده ټاکل' => 'Semester start date is not set',

        
        'د درجه‌بندي بشپړ لید یوازې د معاون د Studentsو، د پوهنځۍ رئیس، او د پوهنځۍ مدیر لپاره دی.' => 'The full hierarchy view is restricted to the Vice-Chancellor, the Faculty Dean, and the Faculty Manager.',
        'د یوزر نوم باید 3 تر 60 توري وي او یوازې انګلیسي توري، شمیرې، _ . - ولري.' => 'Username must be 3-60 characters and may contain only English letters, digits, _ . - characters.',
        'دخاضرۍ ثبتول به پرانیستل کېڊي کله چې د کلاس دقیق پیل وخت به راورسیڊي.' => 'Attendance entry will open when the exact class start time is reached.',
        'د هر قطار څنګ ته د Removeو تڼۍ ښکاري. د حذف څخه مخکې ډاډ ترلاسه کړئ.' => 'A delete button appears next to each row. Confirm before deleting.',
        'د اوسط، ټول کریډیټ، او د حضور سلنه کارتونه له دې صفحې پاک شول' => 'The average, total-credit and attendance-percentage cards have been removed from this page',
        'د هر قطار څنګ ته د سمولو تڼۍ ښکاري. کله چې پای ته ورسئ، د' => 'An edit button appears next to each row. When finished,',
        'ستاسو په یوه مضمون کې د حاضرۍ ریکارډ سم شو. اوسنی فیصدي:' => 'Your attendance record in one subject has been updated. Current percentage:',
        'سیستم اوس په Live حالت کې دی - هر ۶۰ ثانیې اپډیټ کېږي' => 'The system is now in Live mode — refreshing every 60 seconds',
        'یوازې VC او د پوهنځۍ مدیر کولی شي حاضري ایډیټ کړي' => 'Only the Vice-Chancellor and Faculty Manager may edit attendance',
        'د راتلونکې نېټې حاضري نه شي ایډیټ کیدای (Locked)' => 'Future-date attendance cannot be edited (Locked)',
        'د پوهنځي رئیس (Dean) د خاضرۍ د ایډیټ حق نه لري' => 'The Faculty Dean does not have permission to edit attendance',
        'د پروفایل پاک کارت - یوازې اساسي شخصي معلومات' => 'Clean profile card — only basic personal information',
        'د حاضرۍ ریکارډ په بریالیتوب سره خوندي شو' => 'Attendance record saved successfully',
        '% ته رسیده. مهرباني وکړئ خپل حضور وڅارئ.' => '%. Please monitor your attendance.',
        'د راپور جدول - یوازې د غیر حاضرۍ تمرکز' => 'Report table — absence focus only',
        'Attendance History له سیسټم څخه لرې شوې ده' => 'Attendance history has been removed from the system',
        'ټولې خبرتیاوې لوستل شوي په نښه شوې.' => 'All notifications have been marked as read.',
        '% غیر حاضرۍ له امله محروم شوي یاست.' => '% — you have been disqualified due to absences.',
        'د هر محصل خاضري وڅارئ او ایډیټ کړئ' => 'Monitor and edit attendance for each student',
        'د راپور Title او د لید بدلولو تڼۍ' => 'Report title and view-toggle button',
        'د مضمون پر بنسټ د غیر حاضرۍ راپور' => 'Absence report by subject',
        'Recipients (Faculty Users)' => 'Recipients (users of this faculty)',
        'د محصل پر بنسټ د غیر حاضرۍ راپور' => 'Absence report by student',
        'اوس مستقیم د حضور پاڼې ته لاړل.' => 'Now redirected directly to the attendance page.',
        'ستاسو د تدریس د غیر حاضرۍ راپور' => 'Your teaching absence report',
        ') لا نه دی رارسېدلی (Pending).' => ') has not yet arrived (Pending).',
        'اوس مهال هېڅ اعلان شتون نلري.' => 'There are currently no announcements.',
        '% غیر حاضرۍ له امله محروم شو.' => '% — disqualified due to absences.',
        'Open full hierarchical view' => 'Open full hierarchy view',
        'Automatic (Auto-detect from OS)' => 'Auto (detect from OS)',
        'د خوندي کولو پر مهال ستونزه:' => 'A problem occurred while saving:',
        'ستاسو شخصي د غیر حاضرۍ راپور' => 'Your personal absence report',
        'تاسو صرف خپل حضور کتلای شئ.' => 'You can only view your own attendance.',
        'یوازې لیدلو (view-only).' => 'view-only.',
        'د خطر سره مخامخ محصلین (' => 'Students at risk (',
        'د دیتابیس د تړاو ستونزه' => 'Database relation error',
        'د فلترونو پټه (Filters)' => 'Filters bar',
        'My Announcements' => 'My published announcements',
        'Class start time (' => 'Exact class start time (',
        'د حاضرۍ حالت تازه شو' => 'Attendance status updated',
        'Are you sure you want to delete?' => 'Are you sure you want to delete?',
        'بېرته د کتنې حالت ته' => 'Back to view mode',
        'د سمولو حالت Active دی' => 'Edit mode is active',
        'د سمولو حالت / Edit mode' => 'Edit mode',
        'session_id لازمي دی' => 'session_id is required',
        'student_id لازمي دی' => 'student_id is required',
        'All Faculty Members' => 'All faculty users',
        'د کلاسونو غیر حاضري' => 'Class absences',
        'د حذف حالت Active دی' => 'Delete mode is active',
        'د حذف حالت / Delete mode' => 'Delete mode',
        'ټولګي ID ناسم دی.' => 'Class ID is invalid.',
        'Faculty Announcements' => 'Faculty announcements',
        'د غیر حاضرۍ فیصدي' => 'Absence percentage',
        'د اطلاع‌ورکونې وړ' => 'Notifiable',
        'UTC (چاپڗېريایي)' => 'UTC (Global)',
        'د محرومیت اعلان:' => 'Disqualification notice:',
        'پوهنتون راپورونه' => 'University reports',
        'No announcements yet.' => 'No announcements.',
        'د مضمون پر بنسټ' => 'By subject',
        'لاسرسی بلاک دی' => 'Access denied',
        'لاسرسی بلاک شو' => 'Access denied',
        'له شفټ څخه بهر' => 'Outside shift',
        'د محصل پر بنسټ' => 'By student',
        'د فیصدۍ حد (%)' => 'Percentage threshold (%)',
        'د جزئیاتو لیدل' => 'View details',
        'راتلونکې نیټه' => 'Future date',
        '8 (سمیسترونه)' => '8 (semesters)',
        'Teachers Only' => 'Teachers only',
        'د حضور احتار:' => 'Attendance warning:',
        'د خبرتیا حالت' => 'Notice state',
        'د نومونو پټول' => 'Hide names',
        'Students Only' => 'Students only',
        'Managers Only' => 'Managers only',
        'ماښام (13:00' => 'Evening (13:00',
        'د سمولو حالت' => 'Edit mode',
        'د حالت بندول' => 'Close mode',
        'تڼۍ کېک کړئ.' => 'click the button.',
        'د فلتر پاکول' => 'Clear filter',
        'د فلټر فورمه' => 'Filter form',
        'ثبت ونه شو.' => 'Not saved.',
        'اوسنی Active:' => 'Currently active:',
        'Active Students' => 'Active students',
        'لاسرسی نشته' => 'Access denied',
        'سهار (08:00' => 'Morning (08:00',
        'د حذف حالت' => 'Delete mode',
        'حالت بندول' => 'Close mode',
        'ټول Subjects' => 'All subjects',
        'ټول محصلین' => 'All students',
        'د نن Dayې' => 'Today',
        '4 (Class)' => '4 (classes)',
        'ټول ټولګي' => 'All classes',
        'د پوهنځۍ' => 'Faculty',
        'ټول مارک' => 'Mark all',
        'محرومیت:' => 'Disqualified:',
        'لاک شوې' => 'Locked',
        'تاسو له' => 'You from',
        'ستاسو د' => 'Your',

        // =====================================================================
        // FIX V21.1 — Multi-word UI labels for teacher / student progress and
        // common dashboard widgets. We deliberately keep ONLY multi-word phrases
        // here (per FIX 19c). Short single tokens like "حالت" or "پاتې" are
        // intentionally NOT added because they collide with DB content (subject
        // names, notes, etc.) and would corrupt user-facing text.
        // =====================================================================
        'د تدریس پرمختګ'    => 'Teaching progress',
        'د تدریس پرمختگ'    => 'Teaching progress',
        'تدریس شوي کریډیټ'  => 'Taught credits',
        'تدریس شوی کریډیټ'  => 'Taught credits',
        'پاتې کریډیټ'        => 'Remaining credits',
        'ټول کریډیټ'         => 'Total credits',
        'د سمسټر کریډیټ'    => 'Semester credits',
        'د پاتې کریډیټ'     => 'Remaining credits',
        'دخاضرۍ ثبتول به پرانیستل کېڊي کله چې د کلاس دقیق پیل وخت به راورسیڊي.' =>
            'Attendance can only be recorded once the exact scheduled start time has been reached.',
    ];
    
    uksort($map, static function ($a, $b) { return strlen($b) <=> strlen($a); });
    return $map;
}
}

// =============================================================================
// FIX 19c — HTML translator now shields database content from accidental rewrite.
// -----------------------------------------------------------------------------
// Previously this function ran strtr() over the ENTIRE page buffer, so any
// Pashto word that also appeared inside a real user/class/subject/department
// name from the database would get translated mid-name. The most visible
// symptom was that names like "تاجمحمد محصل" turned into "تاجمحمد Student"
// inside tables, dropdowns and edit forms.
//
// We now additionally protect:
//   • Form-input value/data-*/title/alt/placeholder/aria-label attributes
//     (where DB values are almost always rendered).
//   • <code>...</code> blocks (filenames, IDs).
//   • Any element opted-out via class="no-translate" or data-no-translate.
// =============================================================================
if (!function_exists('pusamsEnglishHtmlTranslate')) {
function pusamsEnglishHtmlTranslate(string $html): string {

    if ($html === '' || ($html[0] !== '<' && stripos($html, '<html') === false && stripos($html, '<!doctype') === false)) {
        if ($html === '' || $html[0] !== '<') return $html;
    }
    $map = pusamsEnglishPhraseMap();
    if (!$map) return $html;

    $placeholders = [];
    $protect = static function ($pattern) use (&$html, &$placeholders) {
        $html = preg_replace_callback($pattern, static function ($m) use (&$placeholders) {
            $key = "\x01PUSAMS_PROTECT_".count($placeholders)."\x01";
            $placeholders[$key] = $m[0];
            return $key;
        }, $html);
    };

    // --- Existing protections: scripts, styles, HTML comments ---
    $protect('#<script\b[^>]*>.*?</script>#is');
    $protect('#<style\b[^>]*>.*?</style>#is');
    $protect('#<!--.*?-->#s');

    // FIX 19c — Explicit opt-out: any element carrying class="no-translate"
    // OR a data-no-translate attribute is preserved verbatim (including inner
    // content). This gives templates a clean way to mark DB-rendered cells.
    $protect('#<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\b(?:class\s*=\s*"[^"]*\bno-translate\b[^"]*"|class\s*=\s*\'[^\']*\bno-translate\b[^\']*\'|data-no-translate(?:[\s=>]))[^>]*>.*?</\1>#is');

    // FIX 19c — <code>...</code> blocks are filenames / IDs / hashes; never translate.
    $protect('#<code\b[^>]*>.*?</code>#is');

    // FIX 19c — Protect attribute values that almost always carry DB data:
    //   value="..."        → form inputs (user name, code, etc.)
    //   data-*="..."       → JS-side carriers (data-name, data-id, ...)
    //   alt / title        → image alts and tooltips on names
    //   placeholder        → hint text that often mirrors DB-style values
    //   aria-label         → accessibility labels often quote user data
    // Note: this protects the whole attr+value token, including the surrounding
    // quotes, so strtr() cannot reach inside the quotes.
    $protect('#\b(?:value|data-[a-zA-Z0-9_\-]+|alt|title|placeholder|aria-label)\s*=\s*(?:"[^"]*"|\'[^\']*\')#i');

    // --- Apply the phrase map to whatever survives the protection pass ---
    $html = strtr($html, $map);

    if ($placeholders) {
        $html = strtr($html, $placeholders);
    }
    return $html;
}
}

if (!isset($GLOBALS['__pusams_en_filter_attached'])) {
    $GLOBALS['__pusams_en_filter_attached'] = true;
    $activeLang = $GLOBALS['currentLang'] ?? ($_SESSION['lang'] ?? 'en');
    if ($activeLang === 'en' && PHP_SAPI !== 'cli') {
        ob_start(static function ($buffer) {

            $hdrs = function_exists('headers_list') ? headers_list() : [];
            foreach ($hdrs as $h) {
                $hl = strtolower($h);
                if (strpos($hl, 'content-type:') === 0) {
                    if (strpos($hl, 'text/html') === false && strpos($hl, 'application/xhtml') === false) {
                        return $buffer;
                    }
                }
            }
            try {
                return pusamsEnglishHtmlTranslate($buffer);
            } catch (\Throwable $e) {
                
                return $buffer;
            }
        });
    }
}
