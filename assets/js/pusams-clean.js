
(function () {
  'use strict';

  var MAX_DESC = 110; 

  function cleanAlert(el) {
    if (!el || el.dataset.pcCleaned === '1') return;
    el.dataset.pcCleaned = '1';

    
    var inner = el.querySelector('.flex-grow-1, > div:not(.btn-close):not(.btn):not(.badge)') || el;

    
    
    var walker = document.createTreeWalker(inner, NodeFilter.SHOW_TEXT, null);
    var node;
    var collected = [];
    while ((node = walker.nextNode())) collected.push(node);

    collected.forEach(function (tn) {
      var txt = tn.nodeValue || '';
      
      var idx = txt.search(/[\u2014\u2013\u2012]|--/);
      if (idx === -1) return;
      var before = txt.slice(0, idx).trim();
      var after  = txt.slice(idx + 1).replace(/^[\s\u2014\u2013\u2012-]+/, '').trim();
      if (after.length > 0) {
        
        if (after.length > MAX_DESC) {
          after = after.slice(0, MAX_DESC).trim() + '…';
        }
        
        var span = document.createElement('span');
        span.className = 'alert-subtle';
        span.textContent = ' — ' + after;
        var beforeNode = document.createTextNode(before ? before + ' ' : '');
        var parent = tn.parentNode;
        if (parent) {
          parent.insertBefore(beforeNode, tn);
          parent.insertBefore(span, tn);
          parent.removeChild(tn);
        }
      }
    });
  }

  function tidy() {
    
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(cleanAlert);

    
    var toasts = document.querySelectorAll('.toast-body');
    toasts.forEach(function (el) {
      if (el.dataset.pcCleaned === '1') return;
      el.dataset.pcCleaned = '1';
      var t = (el.textContent || '').trim();
      if (t.length > MAX_DESC + 40) {
        el.textContent = t.slice(0, MAX_DESC + 40).trim() + '…';
      }
    });

    
    var subs = document.querySelectorAll('.hub-hero-sub, .dashboard-hero p, .welcome-hero p');
    subs.forEach(function (el) {
      var t = (el.textContent || '').trim();
      if (t.length > 140) el.textContent = t.slice(0, 140).trim() + '…';
    });

    
    
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tidy);
  } else {
    tidy();
  }

  
  document.addEventListener('shown.bs.modal', tidy);
})();
