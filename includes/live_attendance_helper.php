<?php

if (!function_exists('computeAttSessionLiveState')) {
    
    function computeAttSessionLiveState(array $session): array {

        

        

        

        $now      = time();
        $date     = $session['session_date'] ?? date('Y-m-d');
        $startTs  = strtotime($date.' '.($session['start_time'] ?? '00:00:00'));
        $endTs    = strtotime($date.' '.($session['end_time']   ?? '00:00:00'));
        $status   = function_exists('normalizeSessionStatus')
            ? normalizeSessionStatus((string)($session['status'] ?? 'open'))
            : strtolower(trim((string)($session['status'] ?? 'open')));

        $tLbl = function (string $key, string $fallback): string {
            return function_exists('t') ? t($key, [], $fallback) : $fallback;
        };

        if ($startTs > 0 && $now < $startTs) {
            return [
                'state' => 'pending',
                'label' => $tLbl('status_pending', 'Pending'),
                'icon'  => 'fa-hourglass-half',
                'remaining_seconds' => 0,
                'starts_in_seconds' => max(0, $startTs - $now),
            ];
        }

        if ($startTs > 0 && $endTs > 0 && $now >= $startTs && $now < $endTs) {
            return [
                'state' => 'live',
                'label' => $tLbl('status_open', 'Open'),
                'icon'  => 'fa-circle-play',
                'remaining_seconds' => max(0, $endTs - $now),
                'starts_in_seconds' => 0,
            ];
        }

        
        
        if ($status === 'completed' || $status === 'closed' || $status === 'cancelled') {
            return [
                'state' => 'closed',
                'label' => $tLbl('status_closed', 'Closed'),
                'icon'  => 'fa-check-double',
                'remaining_seconds' => 0,
                'starts_in_seconds' => 0,
            ];
        }

        return [
            'state' => 'missed',
            'label' => $tLbl('status_missed', 'Missed'),
            'icon'  => 'fa-circle-xmark',
            'remaining_seconds' => 0,
            'starts_in_seconds' => 0,
        ];
    }
}

if (!function_exists('effectiveSessionState')) {
    
    function effectiveSessionState(array $session): string {

        
        $state = (string)(computeAttSessionLiveState($session)['state'] ?? 'missed');
        return $state === 'live' ? 'open' : $state;
    }
}

if (!function_exists('effectiveSessionStateLabel')) {
    
    function effectiveSessionStateLabel(string $state): string {

        $map = [
            'pending' => function_exists('t') ? t('status_pending', [], 'Pending') : 'Pending',
            'open'    => function_exists('t') ? t('status_open',    [], 'Open')    : 'Open',
            'closed'  => function_exists('t') ? t('status_closed',  [], 'Closed')  : 'Closed',
            'missed'  => function_exists('t') ? t('status_missed',  [], 'Missed')  : 'Missed',
        ];
        return $map[$state] ?? ucfirst($state);
    }
}

if (!function_exists('renderEffectiveSessionBadge')) {
    
    function renderEffectiveSessionBadge(array $session): string {
        $state = effectiveSessionState($session);
        $label = effectiveSessionStateLabel($state);
        $iconMap = [
            'pending' => 'fa-hourglass-half',
            'open'    => 'fa-circle-play',
            'closed'  => 'fa-check-double',
            'missed'  => 'fa-circle-xmark',
        ];
        $icon = $iconMap[$state] ?? 'fa-clock';
        $extra = $state === 'open' ? '<span class="ses-dot"></span>' : '';

        
        
        $startTs  = (int)strtotime(($session['session_date'] ?? '').' '.($session['start_time'] ?? '00:00:00'));
        $endTs    = (int)strtotime(($session['session_date'] ?? '').' '.($session['end_time']   ?? '00:00:00'));
        $dbStatus = function_exists('normalizeSessionStatus')
            ? normalizeSessionStatus((string)($session['status'] ?? 'open'))
            : (string)($session['status'] ?? 'open');

        return '<span class="session-state-badge ses-'.h($state).' js-state-badge"'
             . ' data-state="'.h($state).'"'
             . ' data-start-ts="'.$startTs.'"'
             . ' data-end-ts="'.$endTs.'"'
             . ' data-db-status="'.h($dbStatus).'">'
             . $extra
             . '<i class="fas '.h($icon).' js-state-icon me-1"></i>'
             . '<span class="js-state-label">'.h($label).'</span>'
             . '</span>';
    }
}

if (!function_exists('applyEffectiveStateFilter')) {
    
    function applyEffectiveStateFilter(array &$where, array &$params, string $sCol, string $startCol, string $endCol, string $dateCol, ?string $state): void {

        
        $state = strtolower(trim((string)$state));
        if ($state === '' || $state === 'all') return;
        $startTs = 'TIMESTAMP('.$dateCol.','.$startCol.')';
        $endTs   = 'TIMESTAMP('.$dateCol.','.$endCol.')';
        switch ($state) {
            case 'pending':
                
                $where[] = $startTs.' > NOW()';
                break;
            case 'open':
            case 'live':
                
                $where[] = $startTs.' <= NOW() AND '.$endTs.' > NOW()';
                break;
            case 'closed':
            case 'completed':
                
                $where[] = $endTs.' <= NOW() AND '.$sCol." IN ('completed','closed','cancelled')";
                break;
            case 'missed':
                
                $where[] = $endTs.' <= NOW() AND '.$sCol." NOT IN ('completed','closed','cancelled')";
                break;
        }
    }
}

if (!function_exists('formatLiveDuration')) {
    
    function formatLiveDuration(int $seconds): string {
        if ($seconds <= 0) return '00:00';
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) return sprintf('%02d:%02d:%02d', $h, $m, $s);
        return sprintf('%02d:%02d', $m, $s);
    }
}

if (!function_exists('renderLiveAttendanceBanner')) {
    
    function renderLiveAttendanceBanner(array $session, array $opt = []): string {
        $info = computeAttSessionLiveState($session);
        $state = $info['state'];

        $className   = h($session['class_name']    ?? '');
        $section     = h($session['section']       ?? '');
        $subjectName = h($session['subject_name']  ?? '');
        $teacherName = h($opt['teacher_name']      ?? ($session['teacher_name'] ?? ''));
        $startTime   = h(substr((string)($session['start_time'] ?? ''), 0, 5));
        $endTime     = h(substr((string)($session['end_time']   ?? ''), 0, 5));
        $sessionDate = h($session['session_date']  ?? '');

        $present = (int)($opt['present_count'] ?? 0);
        $absent  = (int)($opt['absent_count']  ?? 0);
        $total   = (int)($opt['total_count']   ?? max($present + $absent, 0));
        $showStats = $opt['show_stats'] ?? true;

        $countdownId = 'liveAtt_' . (int)($session['id'] ?? mt_rand(1000,9999));

        $html = '<div class="live-att-banner state-'.h($state).'">';
        $html .= '<div class="lab-icon"><i class="fas '.h($info['icon']).'"></i></div>';
        $html .= '<div class="lab-body">';
        $html .= '<div class="lab-title">';
        if ($state === 'live')    $html .= '<span class="live-dot"></span>';
        $html .= h($info['label']).' · '.$subjectName;
        $html .= '</div>';
        $html .= '<div class="lab-meta">';
        $html .= '<i class="fas fa-chalkboard me-1"></i><strong>'.$className.' '.$section.'</strong>';
        if ($teacherName !== '') $html .= ' · <i class="fas fa-user-tie me-1"></i>'.$teacherName;
        $html .= ' · <i class="far fa-clock me-1"></i><strong>'.$startTime.' – '.$endTime.'</strong>';
        $html .= ' · <i class="far fa-calendar me-1"></i>'.$sessionDate;
        $html .= '</div>';
        $html .= '</div>';

        $tC = function(string $k, string $f): string { return function_exists('t') ? t($k, [], $f) : $f; };

        if ($showStats && $total > 0) {
            $html .= '<div class="lab-stats">';
            $html .= '<div class="lab-stat"><span class="lab-num">'.$present.'</span><span class="lab-lab">'.h($tC('lab_present','Present')).'</span></div>';
            $html .= '<div class="lab-stat"><span class="lab-num">'.$absent.'</span><span class="lab-lab">'.h($tC('lab_absent','Absent')).'</span></div>';
            $html .= '<div class="lab-stat"><span class="lab-num">'.$total.'</span><span class="lab-lab">'.h($tC('lab_total','Total')).'</span></div>';
            $html .= '</div>';
        }

        if ($state === 'live') {
            $endTs = (int)strtotime(($session['session_date'] ?? '').' '.($session['end_time'] ?? '00:00:00'));
            $html .= '<div class="lab-countdown" data-countdown="'.(int)$info['remaining_seconds'].'"'
                  .  ' data-end-ts="'.$endTs.'"'
                  .  ' id="'.h($countdownId).'">';
            $html .= '<div class="small opacity-75 mb-1"><i class="fas fa-stopwatch me-1"></i>'.h($tC('lab_time_left','Time left')).'</div>';
            $html .= '<div class="lab-time">'.formatLiveDuration((int)$info['remaining_seconds']).'</div>';
            $html .= '</div>';
        } elseif ($state === 'pending') {

            
            $endTs = (int)strtotime(($session['session_date'] ?? '').' '.($session['end_time'] ?? '00:00:00'));
            $html .= '<div class="lab-countdown" data-countup="'.(int)$info['starts_in_seconds'].'"'
                  .  ' data-end-ts="'.$endTs.'"'
                  .  ' id="'.h($countdownId).'">';
            $html .= '<div class="small opacity-75 mb-1"><i class="fas fa-hourglass-start me-1"></i>'.h($tC('lab_starts_in','Starts in')).'</div>';
            $html .= '<div class="lab-time">'.formatLiveDuration((int)$info['starts_in_seconds']).'</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('renderLiveAttendanceJS')) {
    
    function renderLiveAttendanceJS(): string {
        $csrfTokenJs = json_encode(generateCsrfToken(), JSON_UNESCAPED_SLASHES);
        $syncUrlJs   = json_encode(BASE_URL . '/api/auto_sync.php', JSON_UNESCAPED_SLASHES);
        $csrfFieldJs = json_encode(CSRF_TOKEN_NAME);
        
        $stateLabelsJs = json_encode([
            'pending' => function_exists('t') ? t('status_pending', [], 'Pending') : 'Pending',
            'open'    => function_exists('t') ? t('status_open',    [], 'Open')    : 'Open',
            'closed'  => function_exists('t') ? t('status_closed',  [], 'Closed')  : 'Closed',
            'missed'  => function_exists('t') ? t('status_missed',  [], 'Missed')  : 'Missed',
        ], JSON_UNESCAPED_UNICODE);
        return <<<JS
<script>
/* PUSAMS — Live attendance countdown ticker + auto-sync on state transition */
window.PUSAMS_STATE_LABELS = {$stateLabelsJs};
(function () {
  var __pusamsSyncing = false;
  function triggerServerSync(reason) {
    if (__pusamsSyncing) return;
    __pusamsSyncing = true;
    var payload = {}; payload[{$csrfFieldJs}] = {$csrfTokenJs}; payload.reason = reason || 'tick';
    fetch({$syncUrlJs}, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': {$csrfTokenJs} },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    }).catch(function(){}).finally(function(){
      setTimeout(function(){ __pusamsSyncing = false; }, 3000);
    });
  }
  /* Auto-sync once a minute as a safety net even if no banner is present. */
  /* REQ v23: heartbeat interval is now 10 seconds for prompt UI refresh. */
  setInterval(function(){ triggerServerSync('heartbeat'); }, 10000);
  function fmt(sec) {
    if (sec <= 0) return '00:00';
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return (h > 0 ? pad(h) + ':' : '') + pad(m) + ':' + pad(s);
  }
  function tick() {
    // د هر tick په هر widget کې لومړی هڅه کوو چې د data-end-ts (واقعي پای) پر
    // بنسټ پاتې وخت محاسبه کړو — په داسې طریقه چې د Users د کلاک drift
    // ته اړ نه یو او د کلاس واقعي duration (نه ثابت 60 دقیقې) معتبر وي.
    var nowSec = Math.floor(Date.now() / 1000);

    document.querySelectorAll('[data-countdown]').forEach(function (el) {
      var sec;
      var endTs = parseInt(el.dataset.endTs || '0', 10);
      if (endTs > 0) {
        sec = Math.max(0, endTs - nowSec);
      } else {
        sec = parseInt(el.dataset.countdown, 10);
        if (isNaN(sec) || sec < 0) return;
        sec = Math.max(0, sec - 1);
      }
      el.dataset.countdown = sec;
      var t = el.querySelector('.lab-time');
      if (t) t.textContent = fmt(sec);
      if (sec === 0) {
        var banner = el.closest('.live-att-banner');
        if (banner && banner.classList.contains('state-live')) {
          banner.classList.remove('state-live');
          banner.classList.add('state-closed');
          /* Live window ended without teacher action → ask the server to */
          /* reconcile the session status (Missed / Completed) immediately. */
          triggerServerSync('live-ended');
        }
      }
    });

    document.querySelectorAll('[data-countup]').forEach(function (el) {
      var sec = parseInt(el.dataset.countup, 10);
      if (isNaN(sec) || sec < 0) return;
      sec = Math.max(0, sec - 1);
      el.dataset.countup = sec;
      var t = el.querySelector('.lab-time');
      if (t) t.textContent = fmt(sec);
      if (sec === 0) {
        // محاسبه کیږي (د data-end-ts له مخې)، نه د ثابتو ۶۰ دقیقو.
        var banner = el.closest('.live-att-banner');
        if (banner) {
          banner.classList.remove('state-pending');
          banner.classList.add('state-live');
        }
        var endTs = parseInt(el.dataset.endTs || '0', 10);
        var liveRemaining = endTs > 0 ? Math.max(0, endTs - Math.floor(Date.now() / 1000)) : 0;
        el.removeAttribute('data-countup');
        el.dataset.countdown = liveRemaining;
        // د لومړۍ tick لپاره سمدلاسه ښوول
        var tNow = el.querySelector('.lab-time');
        if (tNow) tNow.textContent = fmt(liveRemaining);
        if (liveRemaining === 0 && banner) {
          banner.classList.remove('state-live');
          banner.classList.add('state-closed');
        }
        /* Pending → Live transition: tell the server so attendance buttons */
        /* can be flipped on for the teacher without a full page reload.    */
        triggerServerSync('pending-to-live');
      }
    });

    /* REQ v23: status badge auto-refresh — every tick we re-evaluate the
       canonical 4-state (pending|open|closed|missed) from data-start-ts /
       data-end-ts / data-db-status, and update the badge class + icon +
       label in place. No page reload needed. */
    document.querySelectorAll('.js-state-badge').forEach(function (b) {
      var dbStatus = (b.dataset.dbStatus || 'open').toLowerCase();
      var startTs  = parseInt(b.dataset.startTs || '0', 10);
      var endTs    = parseInt(b.dataset.endTs   || '0', 10);
      var prev     = (b.dataset.state || '').toLowerCase();
      var next;

      /* STATUS LOGIC — REPLACED v24.1 (mirrors PHP computeAttSessionLiveState).
         Time is authoritative: while we are inside [start, end] the badge is
         ALWAYS 'open' — never Missed, never Closed. DB status is consulted
         only AFTER the time window ends, to decide Closed vs Missed. */
      if (startTs > 0 && nowSec < startTs) {
        next = 'pending';
      } else if (startTs > 0 && endTs > 0 && nowSec >= startTs && nowSec < endTs) {
        next = 'open';
      } else if (dbStatus === 'completed' || dbStatus === 'closed' || dbStatus === 'cancelled') {
        next = 'closed';
      } else {
        next = 'missed';
      }

      if (next !== prev) {
        b.dataset.state = next;
        b.classList.remove('ses-pending','ses-open','ses-closed','ses-missed');
        b.classList.add('ses-' + next);

        var iconMap = {pending:'fa-hourglass-half', open:'fa-circle-play', closed:'fa-check-double', missed:'fa-circle-xmark'};
        var iconEl = b.querySelector('.js-state-icon');
        if (iconEl) {
          iconEl.classList.remove('fa-hourglass-half','fa-circle-play','fa-check-double','fa-circle-xmark');
          iconEl.classList.add(iconMap[next] || 'fa-clock');
        }
        var labelMap = window.PUSAMS_STATE_LABELS || {};
        var lab = b.querySelector('.js-state-label');
        if (lab && labelMap[next]) lab.textContent = labelMap[next];

        /* Toggle the live pulse dot */
        var dot = b.querySelector('.ses-dot');
        if (next === 'open' && !dot) {
          dot = document.createElement('span');
          dot.className = 'ses-dot';
          b.insertBefore(dot, b.firstChild);
        } else if (next !== 'open' && dot) {
          dot.remove();
        }

        /* Ask server to reconcile DB status at every UI transition */
        triggerServerSync('badge-' + prev + '-to-' + next);
      }
    });
  }
  setInterval(tick, 1000);
})();
</script>
JS;
    }
}

if (!function_exists('splitSessionsByLiveState')) {
    
    function splitSessionsByLiveState(array $sessions): array {
        $out = ['active' => [], 'pending' => [], 'history' => []];
        foreach ($sessions as $s) {
            $info = computeAttSessionLiveState($s);
            switch ($info['state']) {
                case 'live':
                    $out['active'][] = $s;
                    break;
                case 'pending':
                    $out['pending'][] = $s;
                    break;
                case 'closed':
                case 'missed':
                default:

                    
                    $out['history'][] = $s;
                    break;
            }
        }
        return $out;
    }
}

if (!function_exists('renderPendingAttList')) {
    
    function renderPendingAttList(array $sessions): string {
        if (!$sessions) {
            $noSessLbl = function_exists('t') ? t('lab_no_sessions', [], 'No sessions found.') : 'No sessions found.';
            return '<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>'.h($noSessLbl).'</div>';
        }
        $html = '<div class="pending-att-list">';
        foreach ($sessions as $s) {
            $info = computeAttSessionLiveState($s);
            $cls  = $info['state'] === 'live' ? 'is-live' : ($info['state'] === 'closed' ? 'is-closed' : '');
            $html .= '<div class="pending-att-row '.$cls.'">';
            $html .= '<div class="d-flex align-items-center gap-2 flex-grow-1">';
            $html .= '<span class="par-icon"><i class="fas '.h($info['icon']).'"></i></span>';
            $html .= '<div>';
            $html .= '<div class="par-title">'.h($s['subject_name'] ?? '').'</div>';
            $html .= '<div class="par-meta">'.h($s['class_name'] ?? '').' '.h($s['section'] ?? '').' · '
                  . h(substr((string)($s['start_time'] ?? ''),0,5)).'–'.h(substr((string)($s['end_time'] ?? ''),0,5)).'</div>';
            $html .= '</div></div>';
            $html .= '<span class="status-pill '.h($info['state']).'"><span class="dot"></span>'.h($info['label']).'</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('renderTodayScheduleList')) {
    
    function renderTodayScheduleList(array $sessions): string {
        $upcoming = [];
        foreach ($sessions as $s) {
            $info = computeAttSessionLiveState($s);
            if ($info['state'] === 'pending') $upcoming[] = $s;
        }
        if (!$upcoming) return '';
        $html = '<div class="today-schedule-list small">';
        foreach ($upcoming as $s) {
            $html .= '<div class="d-flex align-items-center gap-2 py-1 border-bottom">';
            $html .= '<i class="fas fa-calendar-day text-muted"></i>';
            $html .= '<span class="flex-grow-1">'.h($s['subject_name'] ?? '').' <span class="text-muted">· '.h($s['class_name'] ?? '').' '.h($s['section'] ?? '').'</span></span>';
            $html .= '<span class="badge bg-light text-dark border">'.h(substr((string)($s['start_time'] ?? ''),0,5)).'–'.h(substr((string)($s['end_time'] ?? ''),0,5)).'</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('fetchLiveSessions')) {
    
    function fetchLiveSessions(string $scope = 'all', int $scopeId = 0, int $limit = 10): array {
        try {
            $db = getDB();
            $today = date('Y-m-d');
            $where = ["s.session_date = ?", "s.status IN ('open','closed','missed')"];
            $params = [$today];
            switch ($scope) {
                case 'student':
                    $where[] = 's.class_id = ?';
                    $params[] = $scopeId;
                    break;
                case 'teacher':
                    $where[] = 's.teacher_id = ?';
                    $params[] = $scopeId;
                    break;
                case 'faculty':
                    $where[] = 't.faculty_id = ?';
                    $params[] = $scopeId;
                    break;
            }
            $sql = "SELECT s.id, s.session_date, s.start_time, s.end_time, s.status, s.slot_label,
                           s.class_id, s.subject_id, s.teacher_id,
                           c.name AS class_name, c.section,
                           sub.name AS subject_name, u.full_name AS teacher_name
                    FROM attendance_sessions s
                    JOIN timetable t ON t.id=s.timetable_id
                    JOIN classes c ON c.id=s.class_id
                    JOIN subjects sub ON sub.id=s.subject_id
                    JOIN users u ON u.id=s.teacher_id
                    WHERE ".implode(' AND ', $where)."
                    ORDER BY s.start_time
                    LIMIT ".(int)$limit;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            $rows = $stmt->fetchAll();
            $now = time();
            $filtered = [];
            foreach ($rows as $r) {
                $startTs = strtotime(($r['session_date'] ?? '').' '.($r['start_time'] ?? '00:00:00'));
                
                if ($startTs !== false && $now >= $startTs) {
                    $filtered[] = $r;
                }
            }
            return $filtered;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fetchUpcomingAndLiveSessions')) {
    function fetchUpcomingAndLiveSessions(string $scope = 'all', int $scopeId = 0, int $limit = 10): array {
        return fetchLiveSessions($scope, $scopeId, $limit);
    }
}
