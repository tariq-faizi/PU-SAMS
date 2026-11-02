
(function () {
  if (typeof window === 'undefined') return;
  if (window.__pusamsCascadeJSBound) return;
  window.__pusamsCascadeJSBound = true;

  var DEBUG = !!window.PUSAMS_CASCADE_DEBUG;
  
  
  
  
  
  function getApiUrl() {
    return (window.BASE_URL || '') + '/api/cascading.php';
  }

  function log() {
    if (DEBUG) try { console.log.apply(console, ['[PUSAMS-Cascade]'].concat([].slice.call(arguments))); } catch(e){}
  }

  
  var TYPE_MAP = {
    faculty:    { endpoint: 'faculties',   parent: null,           label: function(r){ return r.name; } },
    department: { endpoint: 'departments', parent: 'faculty_id',   label: function(r){ return r.name; } },
    'class':    { endpoint: 'classes',     parent: 'department_id',label: function(r){ return r.name + (r.section ? ' ' + r.section : ''); } },
    subject:    { endpoint: 'subjects',    parent: 'class_id',     label: function(r){ return r.name + (r.code ? ' [' + r.code + ']' : ''); } },
    teacher:    { endpoint: 'teachers',    parent: 'subject_id',   label: function(r){ return r.full_name || r.name; } },
    semester:   { endpoint: 'semesters',   parent: 'class_id',     label: function(r){
        // (v24) د سمیستر option داسې ځلوو: «نوم — Semester N»
        // دا دیوزر ته دومره واضح کوي چې کوم سمیستر توټوي.
        if (r.semester_no) {
            return r.name + ' — Semester ' + r.semester_no;
        }
        return r.name;
    } }
  };

  
  var SIMPLE_ORDER = ['faculty', 'department', 'class', 'semester', 'subject', 'teacher'];

  function parentParamFor(level, parentLevel) {
    if (level === 'department') return 'faculty_id';
    if (level === 'class') return parentLevel === 'faculty' ? 'faculty_id' : 'department_id';
    if (level === 'subject') {
      if (parentLevel === 'department') return 'department_id';
      if (parentLevel === 'faculty') return 'faculty_id';
      
      
      
      
      
      if (parentLevel === 'semester') return 'semester_id';
      if (parentLevel === 'class')    return 'class_id';
      return 'class_id';
    }
    if (level === 'teacher') return 'subject_id';
    if (level === 'semester') return parentLevel === 'class' ? 'class_id' : 'faculty_id';
    var cfg = TYPE_MAP[level];
    return cfg ? cfg.parent : null;
  }

  
  function placeholderText(sel, level) {
    return sel.dataset.placeholder ||
           sel.getAttribute('data-placeholder') ||
           ('— ' + (level || 'Select') + ' —');
  }

  function htmlSafe(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[c];
    });
  }

  function clearOptions(sel, level) {
    var ph = placeholderText(sel, level);
    sel.innerHTML = '<option value="">' + htmlSafe(ph) + '</option>';
  }

  function setOptions(sel, items, level, currentVal) {
    var cfg = TYPE_MAP[level];
    var ph = placeholderText(sel, level);
    var html = '<option value="">' + htmlSafe(ph) + '</option>';
    (items || []).forEach(function (row) {
      var id   = row.id !== undefined ? row.id : row.value;
      var name = cfg && typeof cfg.label === 'function'
                  ? cfg.label(row)
                  : (row.name || row.full_name || ('#' + id));
      var isSel = (String(id) === String(currentVal || '')) ? ' selected' : '';
      html += '<option value="' + id + '"' + isSel + '>' + htmlSafe(name) + '</option>';
    });
    sel.innerHTML = html;
  }

  
  
  
  
  var LEVEL_PARAM = {
    faculty:    'faculty_id',
    department: 'department_id',
    'class':    'class_id',
    semester:   'semester_id',
    subject:    'subject_id',
    teacher:    'teacher_id'
  };

  function fetchItems(level, parentVal, parentLevel, ctx) {
    var cfg = TYPE_MAP[level];
    if (!cfg) return Promise.resolve([]);
    var params = new URLSearchParams({ type: cfg.endpoint });
    var parentParam = parentParamFor(level, parentLevel);
    if (parentParam && parentVal) params.set(parentParam, parentVal);
    
    
    
    
    
    
    
    
    
    
    
    if (ctx && typeof ctx === 'object') {
      Object.keys(ctx).forEach(function (k) {
        var v = ctx[k];
        if (!v || v === '0') return;
        var p = LEVEL_PARAM[k];
        if (!p) return;
        
        params.set(p, v);
      });
    }
    var url = getApiUrl() + '?' + params.toString();
    log('fetch →', level, 'parent=', parentVal, 'parentLevel=', parentLevel, 'ctx=', ctx, 'url=', url);
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) { log('fetch http error', level, r.status); return { ok:false, items:[] }; }
        return r.json();
      })
      .then(function (j) {
        log('fetch ←', level, 'items=', (j && j.items) ? j.items.length : 0);
        return (j && j.ok) ? (j.items || []) : [];
      })
      .catch(function (e) { log('fetch err', level, e); return []; });
  }

  
  
  function collectChainContext(chain, uptoIdx) {
    var ctx = {};
    for (var i = 0; i < uptoIdx && i < chain.length; i++) {
      var s = chain[i];
      if (!s) continue;
      var lv = s.dataset.cascade;
      if (lv && s.value) ctx[lv] = s.value;
    }
    return ctx;
  }

  
  
  function collectSimpleContext(found, uptoLevel) {
    var ctx = {};
    var stop = SIMPLE_ORDER.indexOf(uptoLevel);
    if (stop < 0) stop = SIMPLE_ORDER.length;
    for (var i = 0; i < stop; i++) {
      var lv = SIMPLE_ORDER[i];
      var s = found[lv];
      if (s && s.value) ctx[lv] = s.value;
    }
    return ctx;
  }

  
  
  
  function collectSourceContext(sel) {
    var ctx = {};
    var cur = sel;
    var safety = 10;
    while (cur && safety-- > 0) {
      var srcId = cur.dataset && cur.dataset.cascadeSource;
      if (!srcId) break;
      var src = document.getElementById(srcId);
      if (!src) break;
      var lv = src.dataset.cascade;
      if (lv && src.value && !ctx[lv]) ctx[lv] = src.value;
      cur = src;
    }
    return ctx;
  }

  
  function hasRealPreselect(sel) {
    if (!sel || !sel.dataset) return false;
    var p = sel.dataset.preselect;
    return !!(p && p !== '0' && p !== '');
  }

  function isAutoSelectAllowed(sel) {
    return !(sel && sel.dataset && (sel.dataset.noAutoselectSingle === '1' || sel.dataset.noAutoselectSingle === 'true'));
  }

  
  function loadInto(sel, level, parentVal, keepCurrent, parentLevel, ctx) {
    if (!sel) return Promise.resolve();
    var keepVal = keepCurrent ? sel.value : '';
    
    if (level !== 'faculty' && (!parentVal || parentVal === '' || parentVal === '0')) {
      clearOptions(sel, level);
      sel.disabled = false;
      log('loadInto: parent empty, cleared', level);
      return Promise.resolve();
    }
    var preselectVal = hasRealPreselect(sel) ? sel.dataset.preselect : '';

    
    sel.classList.add('cascade-loading');
    sel.disabled = true;

    return fetchItems(level, parentVal, parentLevel, ctx).then(function (items) {
      setOptions(sel, items, level, keepVal || preselectVal);
      sel.disabled = false;
      sel.classList.remove('cascade-loading');

      
      
      var opts = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; });
      var alreadyHasValue = !!keepVal || !!preselectVal;
      if (opts.length === 1 && !alreadyHasValue && isAutoSelectAllowed(sel)) {
        sel.value = opts[0].value;
        log('auto-selected single option for', level, '=', opts[0].value);
        
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      return items;
    });
  }

  
  
  
  function buildExplicitChain(rootSel) {
    var chain = [rootSel];
    var cur = rootSel;
    var safety = 10;
    while (cur && safety-- > 0) {
      var tid = cur.dataset.cascadeTarget;
      if (!tid) break;
      var nxt = document.getElementById(tid);
      if (!nxt || chain.indexOf(nxt) !== -1) break;
      chain.push(nxt);
      cur = nxt;
    }
    return chain;
  }

  function bindExplicitRoot(rootSel) {
    var chain = buildExplicitChain(rootSel);
    log('explicit chain built', chain.map(function(s){return s.id||s.name;}));

    if (chain.length < 2) {
      maybeLoadRoot(rootSel);
      return;
    }

    
    chain.forEach(function (sel, idx) {
      sel.addEventListener('change', function (evt) {
        
        log('change fired on', sel.id || sel.name, 'value=', sel.value, 'level=', idx);
        cascadeDownward(chain, idx);
      });
    });

    
    initialFillChain(chain);
  }

  
  function cascadeDownward(chain, startIdx) {
    var p = Promise.resolve();
    for (var k = startIdx + 1; k < chain.length; k++) {
      (function (child, parent, childIdx) {
        p = p.then(function () {
          
          var childLevel = child.dataset.cascade;
          var parentVal = parent.value;
          
          var ctx = collectChainContext(chain, childIdx);
          return loadInto(child, childLevel, parentVal, false, parent ? parent.dataset.cascade : '', ctx);
        });
      })(chain[k], chain[k - 1], k);
    }
    
    
    
    p.then(function () {
      autoSelectSinglesChain(chain.slice(startIdx + 1));
    });
    return p;
  }

  function initialFillChain(chain) {
    var root = chain[0];
    var initialPromise;
    if (root.options.length > 1 && !root.dataset.cascadeForceReload) {
      
      initialPromise = Promise.resolve();
    } else {
      initialPromise = loadInto(root, root.dataset.cascade, null,
        hasRealPreselect(root) ? false : true);
    }
    initialPromise.then(function () {
      var p = Promise.resolve();
      for (var i = 1; i < chain.length; i++) {
        (function (child, parent, idx) {
          p = p.then(function () {
            
            
            if (child.options.length > 1 && hasRealPreselect(child) && child.value === child.dataset.preselect) {
              return Promise.resolve();
            }
            
            var ctx = collectChainContext(chain, idx);
            return loadInto(child, child.dataset.cascade, parent.value, true, parent ? parent.dataset.cascade : '', ctx);
          });
        })(chain[i], chain[i - 1], i);
      }
      return p.then(function () {
        autoSelectSinglesChain(chain.slice(1));
        
        
        
        if (root && !root.value) {
          var opts = Array.prototype.filter.call(root.options, function(o){ return o.value !== ''; });
          if (opts.length === 1 && isAutoSelectAllowed(root)) {
            root.value = opts[0].value;
            log('auto-selected root single', root.id, '=', root.value);
            root.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
      });
    });
  }

  function maybeLoadRoot(sel) {
    if (sel.options.length <= 1) {
      loadInto(sel, sel.dataset.cascade, null, false);
    }
  }

  function autoSelectSinglesChain(rest) {
    
    for (var i = 0; i < rest.length; i++) {
      var s = rest[i];
      if (!isAutoSelectAllowed(s)) continue;
      if (s.value) continue; 
      var opts = Array.prototype.filter.call(s.options, function (o) { return o.value !== ''; });
      if (opts.length === 1) {
        s.value = opts[0].value;
        log('auto-selected (chain) single', s.id || s.name, '=', s.value);
        s.dispatchEvent(new Event('change', { bubbles: true }));
        return; 
      }
    }
  }

  
  
  
  function bindSimpleSet(scope) {
    var found = {};
    SIMPLE_ORDER.forEach(function (lv) {
      var s = scope.querySelector('select[data-cascade="' + lv + '"]');
      if (s) found[lv] = s;
    });
    var levels = Object.keys(found);
    if (levels.length < 2) return;

    var present = SIMPLE_ORDER.filter(function (lv) { return !!found[lv]; });

    
    
    
    
    
    
    
    
    present.forEach(function (lv) {
      var sel = found[lv];
      if (!sel) return;
      var isExplicit = !!(sel.dataset && (sel.dataset.cascadeSource || sel.dataset.cascadeTarget));
      if (!isExplicit) return;
      sel.addEventListener('change', function () {
        var idx = present.indexOf(lv);
        if (idx < 0) return;
        
        
        var p = Promise.resolve();
        for (var j = idx + 1; j < present.length; j++) {
          (function (childLv) {
            var child = found[childLv];
            if (!child) return;
            var childIsExplicit = !!(child.dataset && (child.dataset.cascadeSource || child.dataset.cascadeTarget));
            if (childIsExplicit) return; 
            p = p.then(function () {
              child.value = ''; 
              var parentLv = present[j - 1];
              var parent = found[parentLv];
              var ctx = collectSimpleContext(found, childLv);
              return loadInto(child, childLv, parent ? parent.value : '', false, parent ? parent.dataset.cascade : '', ctx);
            });
          })(present[j]);
        }
        p.then(function () {
          var rest = [];
          for (var k = idx + 1; k < present.length; k++) {
            var c = found[present[k]];
            if (c && !(c.dataset.cascadeSource || c.dataset.cascadeTarget)) rest.push(c);
          }
          autoSelectSinglesChain(rest);
        });
      });
    });

    present.forEach(function (lv, i) {
      var sel = found[lv];
      sel.addEventListener('change', function () {
        log('simple change', lv, '=', sel.value);
        
        
        for (var c = i + 1; c < present.length; c++) {
          var deep = found[present[c]];
          if (deep) deep.value = '';
        }
        var p = Promise.resolve();
        for (var j = i + 1; j < present.length; j++) {
          (function (childLv, parentLv) {
            p = p.then(function () {
              var parent = found[parentLv];
              
              var ctx = collectSimpleContext(found, childLv);
              return loadInto(found[childLv], childLv, parent ? parent.value : '', false, parent ? parent.dataset.cascade : '', ctx);
            });
          })(present[j], present[j - 1]);
        }
        p.then(function () {
          var rest = present.slice(i + 1).map(function (lv2) { return found[lv2]; });
          autoSelectSinglesChain(rest);
        });
      });
    });

    
    var p = Promise.resolve();
    for (var i = 1; i < present.length; i++) {
      (function (childLv, parentLv) {
        p = p.then(function () {
          var parent = found[parentLv];
          var child = found[childLv];
          if (child.options.length > 1 && child.value && child.value !== '0') return Promise.resolve();
          
          var ctx = collectSimpleContext(found, childLv);
          return loadInto(child, childLv, parent ? parent.value : '', true, parent ? parent.dataset.cascade : '', ctx);
        });
      })(present[i], present[i - 1]);
    }
    p.then(function () {
      autoSelectSinglesChain(present.map(function (lv) { return found[lv]; }).slice(1));
      
      var first = found[present[0]];
      if (first && !first.value) {
        var opts = Array.prototype.filter.call(first.options, function(o){ return o.value !== ''; });
        if (opts.length === 1 && isAutoSelectAllowed(first)) {
          first.value = opts[0].value;
          first.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    });
  }

  
  
  
  function init() {
    log('init', 'API=', getApiUrl(), 'BASE_URL=', window.BASE_URL);

    
    if (!document.getElementById('pusams-cascade-style')) {
      var st = document.createElement('style');
      st.id = 'pusams-cascade-style';
      st.textContent =
        'select.cascade-loading{background-image:url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%231d4ed8\' stroke-width=\'3\' stroke-linecap=\'round\'><circle cx=\'12\' cy=\'12\' r=\'9\' opacity=\'0.25\'/><path d=\'M21 12a9 9 0 0 0-9-9\'><animateTransform attributeName=\'transform\' type=\'rotate\' from=\'0 12 12\' to=\'360 12 12\' dur=\'0.9s\' repeatCount=\'indefinite\'/></path></svg>") !important;background-repeat:no-repeat !important;background-position:left .55rem center !important;background-size:1rem 1rem !important;padding-inline-start:2rem !important;opacity:.85}';
      document.head.appendChild(st);
    }

    
    var explicitRoots = document.querySelectorAll(
      'select[data-cascade][data-cascade-target]:not([data-cascade-source])'
    );
    var handled = new Set();
    explicitRoots.forEach(function (r) {
      bindExplicitRoot(r);
      var c = buildExplicitChain(r);
      c.forEach(function (n) { handled.add(n); });
    });

    
    
    document.querySelectorAll('select[data-cascade][data-cascade-source]').forEach(function (sel) {
      if (handled.has(sel)) return;
      var parent = document.getElementById(sel.dataset.cascadeSource);
      if (!parent) return;
      parent.addEventListener('change', function () {
        
        
        
        sel.value = '';
        var ctx = collectSourceContext(sel);
        loadInto(sel, sel.dataset.cascade, parent.value, false, parent ? parent.dataset.cascade : '', ctx).then(function () {
          autoSelectSinglesChain([sel]);
          
          
          
          if (sel.dataset && sel.dataset.cascadeTarget) {
            sel.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });
      if (parent.value && parent.value !== '0' &&
          (sel.options.length <= 1 || !sel.value || sel.value === '0')) {
        var ctx0 = collectSourceContext(sel);
        loadInto(sel, sel.dataset.cascade, parent.value, true, parent ? parent.dataset.cascade : '', ctx0);
      }
      handled.add(sel);
    });

    
    
    var forms = document.querySelectorAll('form, .v6-filter-card, .card');
    forms.forEach(function (form) {
      var selects = form.querySelectorAll('select[data-cascade]:not([data-cascade-target]):not([data-cascade-source])');
      if (selects.length >= 2) {
        var anyHandled = Array.prototype.some.call(selects, function (s) { return handled.has(s); });
        if (!anyHandled) {
          bindSimpleSet(form);
          selects.forEach(function (s) { handled.add(s); });
        }
      }
    });

    
    document.querySelectorAll('select[data-cascade]').forEach(function (sel) {
      if (handled.has(sel)) return;
      if (!sel.dataset.cascadeSource) {
        
        if (sel.options.length <= 1) {
          loadInto(sel, sel.dataset.cascade, null, false).then(function(){
            
            if (!sel.value && isAutoSelectAllowed(sel)) {
              var opts = Array.prototype.filter.call(sel.options, function(o){ return o.value !== ''; });
              if (opts.length === 1) {
                sel.value = opts[0].value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
              }
            }
          });
        } else if (!sel.value && isAutoSelectAllowed(sel)) {
          var opts = Array.prototype.filter.call(sel.options, function(o){ return o.value !== ''; });
          if (opts.length === 1) {
            sel.value = opts[0].value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
      }
      handled.add(sel);
    });

  
  
  
  
  document.querySelectorAll('select[data-cascade]').forEach(function(sel) {
    var form = sel.closest('form[data-cascade-autosubmit]');
    if (!form) return;
    
    if (!sel.dataset.cascadeSource && !sel.dataset.cascadeTarget) {
      var origType = sel.dataset.cascade;
      if (origType === 'faculty' || origType === 'department') {
        sel.addEventListener('change', function() {
          
          setTimeout(function() { form.submit(); }, 350);
        });
      }
    }
  });

  log('init done. handled=', handled.size);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
