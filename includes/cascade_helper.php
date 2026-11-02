<?php

if (!function_exists('renderRelatedCascadeJS')) {
    
    function renderRelatedCascadeJS(): string {
        
        return "<!-- PUSAMS: cascading is unified in /assets/js/cascade.js -->";
    }
}

if (!function_exists('renderLegacyRelatedCascadeJS')) {
    function renderLegacyRelatedCascadeJS(): string {
        $apiBase = BASE_URL . '/api/cascading.php';
        return <<<HTML
<script>
(function(){
  if (window.__pusamsCascadeBound) return;
  window.__pusamsCascadeBound = true;
  var API_BASE = "{$apiBase}";

  // standard id-key + label-key per level
  var ORDER = ['faculty','department','class','subject','teacher'];
  var ENDPOINT = {
    faculty:    function(){ return API_BASE + '?type=faculties'; },
    department: function(p){ return API_BASE + '?type=departments&faculty_id=' + encodeURIComponent(p||0); },
    class:      function(p){ return API_BASE + '?type=classes&department_id=' + encodeURIComponent(p||0); },
    subject:    function(p){ return API_BASE + '?type=subjects&class_id=' + encodeURIComponent(p||0); },
    teacher:    function(p){ return API_BASE + '?type=teachers&subject_id=' + encodeURIComponent(p||0); }
  };
  var PLACEHOLDER = {
    faculty:    '— پوهنځۍ —',
    department: '— Department —',
    class:      '— Class —',
    subject:    '— مضمون —',
    teacher:    '— استاد —'
  };

  function findSelects(){
    var out = {};
    ORDER.forEach(function(k){
      var el = document.querySelector('select[data-cascade="' + k + '"]');
      if (el) out[k] = el;
    });
    return out;
  }

  function setOptions(sel, items, currentVal){
    var label = PLACEHOLDER[sel.getAttribute('data-cascade')] || '— Select —';
    var keep = sel.getAttribute('data-allow-empty') === '0' ? '' :
               '<option value="">' + (sel.getAttribute('data-placeholder') || label) + '</option>';
    var html = keep;
    items.forEach(function(it){
      var id   = (it.id !== undefined) ? it.id : it.value;
      var name = it.name || it.label || it.full_name || (it.code || '#'+id);
      if (it.section && (sel.getAttribute('data-cascade') === 'class')) name = name + ' ' + it.section;
      var sel_ = (String(id) === String(currentVal)) ? ' selected' : '';
      html += '<option value="' + id + '"' + sel_ + '>' + name + '</option>';
    });
    sel.innerHTML = html;
  }

  function loadChildren(level, parentVal, keepCurrent){
    var selects = findSelects();
    var sel = selects[level];
    if (!sel) return Promise.resolve();
    var endpoint = ENDPOINT[level](parentVal);
    if ((level !== 'faculty') && !parentVal) {
      // parent empty -> clear children too
      setOptions(sel, [], '');
      // also clear deeper levels
      var idx = ORDER.indexOf(level);
      for (var i = idx+1; i < ORDER.length; i++) {
        if (selects[ORDER[i]]) setOptions(selects[ORDER[i]], [], '');
      }
      return Promise.resolve();
    }
    return fetch(endpoint, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.ok) return;
        var keepVal = keepCurrent ? sel.value : '';
        setOptions(sel, j.items || [], keepVal);
      }).catch(function(){});
  }

  function bind(){
    var selects = findSelects();
    if (!Object.keys(selects).length) return;

    // on each level change, fetch the lower levels
    ORDER.forEach(function(level, i){
      var sel = selects[level];
      if (!sel) return;
      sel.addEventListener('change', function(){
        var v = sel.value;
        // auto-load lower levels
        var prev = Promise.resolve();
        for (var j = i+1; j < ORDER.length; j++) {
          (function(child, parentLevel){
            prev = prev.then(function(){
              var p = selects[parentLevel] ? selects[parentLevel].value : '';
              return loadChildren(child, p, false);
            });
          })(ORDER[j], ORDER[j-1]);
        }
        // if only one option exists, auto-select it
        prev.then(function(){
          for (var k = i+1; k < ORDER.length; k++) {
            var s = selects[ORDER[k]];
            if (!s) continue;
            var opts = Array.prototype.filter.call(s.options, function(o){ return o.value !== ''; });
            if (opts.length === 1) {
              s.value = opts[0].value;
              s.dispatchEvent(new Event('change'));
              break;
            }
          }
        });
      });
    });

    // on first load, fill lower levels from current value
    var prev = Promise.resolve();
    ORDER.forEach(function(level, i){
      var sel = selects[level];
      if (!sel) return;
      // initial load: keep current values, just load children
      if (i+1 < ORDER.length && selects[ORDER[i+1]]) {
        prev = prev.then(function(){
          return loadChildren(ORDER[i+1], sel.value, true);
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
</script>
<style>
.pusams-cascade-hint{font-size:.78rem;color:#0d6efd;}
</style>
HTML;
    }
}

if (!function_exists('addCascadeAttr')) {
    
    function addCascadeAttr(string $level, array $extra = []): string {
        $out = 'data-cascade="' . htmlspecialchars($level, ENT_QUOTES) . '"';
        foreach ($extra as $k => $v) {
            $out .= ' data-' . htmlspecialchars($k, ENT_QUOTES) . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }
        return $out;
    }
}
