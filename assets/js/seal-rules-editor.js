/* Row-based editor for ILL → Org Rules. Each rule set is stored as "a|b|c" lines in a textarea;
   this turns that into a table with labelled columns and keeps the textarea in sync. */
(function () {
  'use strict';

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; }
      else if (k === 'class') { n.className = attrs[k]; }
      else { n.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  function init(root) {
    var schema = JSON.parse(root.getAttribute('data-schema'));
    var ta = root.querySelector('textarea');
    var cols = schema.cols;
    var splits = schema.split || [];
    var uid = root.id || ('seal-rules-' + Math.random().toString(36).slice(2));
    var live = el('div', { 'class': 'seal-rules-live screen-reader-text', 'aria-live': 'polite' });
    var table, tbody, filter, count;

    function clean(v) { return String(v == null ? '' : v).replace(/\|/g, '/').trim(); }

    function displayIndex(split) {
      var idx = split.at;
      splits.forEach(function (s) { if (s.at < split.at) { idx += 1; } });
      return idx;
    }

    function parse(text) {
      var rows = [];
      text.split(/\r?\n/).forEach(function (line) {
        line = line.trim();
        if (!line || line.charAt(0) === '#') { return; }
        var cells = line.split('|').map(function (c) { return c.trim(); });
        splits.forEach(function (s) {
          while (cells.length <= s.at) { cells.push(''); }
          var v = cells[s.at], p = v.indexOf(s.sep);
          var pair = p < 0 ? [v, ''] : [v.slice(0, p), v.slice(p + s.sep.length)];
          cells.splice(s.at, 1, pair[0], pair[1]);
        });
        cols.forEach(function (c, i) {
          var v = cells[i] === undefined ? '' : cells[i];
          if (c.prefix && v.toLowerCase().indexOf(c.prefix) === 0) { v = v.slice(c.prefix.length); }
          if (c.type === 'yesno') { v = /^y/i.test(v) ? 'yes' : 'no'; }
          if (c.empty_as && v === c.empty_as) { v = ''; }
          cells[i] = v;
        });
        rows.push(cells.slice(0, cols.length));
      });
      return rows;
    }

    function serializeRow(vals) {
      var cells = vals.map(function (v, i) {
        var c = cols[i];
        v = clean(v);
        if (c.type === 'yesno') { return v === 'yes' ? 'yes' : 'no'; }
        if (v === '' && c.empty_as) { return c.empty_as; }
        if (v !== '' && c.prefix) { return c.prefix + v; }
        return v;
      });
      splits.slice().sort(function (a, b) { return displayIndex(b) - displayIndex(a); }).forEach(function (s) {
        var d = displayIndex(s);
        var a = cells[d], b = cells[d + 1];
        cells.splice(d, 2, (a === '' && b === '') ? '' : (a + s.sep + b));
      });
      while (cells.length > 1 && cells[cells.length - 1] === '') { cells.pop(); }
      return cells.join('|');
    }

    function rowValues(tr) {
      return Array.prototype.map.call(tr.querySelectorAll('[data-col]'), function (inp) {
        return inp.type === 'checkbox' ? (inp.checked ? 'yes' : 'no') : inp.value;
      });
    }

    function patternFails(i, v) {
      var c = cols[i];
      return !!c.pattern && clean(v) !== '' && !new RegExp(c.pattern).test(clean(v));
    }

    var required = cols.map(function (c) { return !!c.required; });
    var hasRequired = required.some(Boolean);

    /** Row state: complete rows are saved; started-but-incomplete rows are flagged and not saved. */
    function rowState(vals) {
      var started, complete;
      if (hasRequired) {
        started  = vals.some(function (v, i) { return (required[i] || cols[i].type === 'text') && clean(v) !== ''; });
        complete = vals.every(function (v, i) { return (!required[i] || clean(v) !== '') && !patternFails(i, v); });
      } else {
        started = complete = vals.some(function (v, i) { return cols[i].type === 'text' && clean(v) !== ''; });
      }
      return { started: started, complete: started && complete };
    }

    function sync() {
      var lines = [], incomplete = 0;
      Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr) {
        var st = rowState(rowValues(tr));
        var bad = st.started && !st.complete;
        tr.classList.toggle('is-incomplete', bad);
        Array.prototype.forEach.call(tr.querySelectorAll('[data-col]'), function (inp) {
          var i = +inp.getAttribute('data-col');
          var empty = (required[i] && clean(inp.type === 'checkbox' ? 'x' : inp.value) === '') || patternFails(i, inp.value);
          if (bad && empty) { inp.setAttribute('aria-invalid', 'true'); } else { inp.removeAttribute('aria-invalid'); }
        });
        if (bad) { incomplete++; }
        if (st.complete) { lines.push(serializeRow(rowValues(tr))); }
      });
      ta.value = lines.join('\n');
      ta.dispatchEvent(new CustomEvent('seal-rules-sync', { bubbles: true }));
      count.textContent = lines.length + (lines.length === 1 ? ' entry' : ' entries')
        + (incomplete ? ' - ' + incomplete + ' incomplete (not saved)' : '');
    }

    function rowLabel(tr, colIndex) {
      var n = Array.prototype.indexOf.call(tbody.children, tr) + 1;
      return cols[colIndex].label + ', row ' + n;
    }

    function buildRow(vals) {
      var tr = el('tr');
      cols.forEach(function (c, i) {
        var id = uid + '-r' + (buildRow.n = (buildRow.n || 0) + 1) + 'c' + i;
        var inp, v = vals ? vals[i] : (c.type === 'yesno' ? 'no' : '');
        if (c.type === 'select') {
          inp = el('select', { id: id });
          Object.keys(c.options).forEach(function (k) {
            var o = el('option', { value: k, text: c.options[k] });
            if (k === v) { o.selected = true; }
            inp.appendChild(o);
          });
          if (v && !c.options[v]) { var extra = el('option', { value: v, text: v }); extra.selected = true; inp.appendChild(extra); }
        } else if (c.type === 'yesno') {
          inp = el('input', { id: id, type: 'checkbox' });
          inp.checked = v === 'yes';
        } else {
          inp = el('input', { id: id, type: 'text', autocomplete: 'off', spellcheck: 'false' });
          inp.value = v || '';
          if (c.placeholder) { inp.placeholder = c.placeholder; }
          if (c.list) { inp.setAttribute('list', uid + '-dl'); }
        }
        inp.setAttribute('data-col', i);
        inp.className = 'seal-rules-input';
        tr.appendChild(el('td', { 'class': 'seal-rules-cell' + (c.type === 'yesno' ? ' seal-rules-center' : '') }, [inp]));
      });
      var rm = el('button', { type: 'button', 'class': 'button-link seal-rules-remove', text: 'Remove' });
      tr.appendChild(el('td', { 'class': 'seal-rules-cell seal-rules-actions' }, [rm]));
      return tr;
    }

    function relabel() {
      Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr, r) {
        Array.prototype.forEach.call(tr.querySelectorAll('[data-col]'), function (inp) {
          inp.setAttribute('aria-label', cols[inp.getAttribute('data-col')].label + ', row ' + (r + 1));
        });
        tr.querySelector('.seal-rules-remove').setAttribute('aria-label', 'Remove row ' + (r + 1));
      });
    }

    function addRow(vals, focus) {
      var tr = buildRow(vals);
      tbody.appendChild(tr);
      relabel(); sync();
      if (focus) { var first = tr.querySelector('input,select'); if (first) { first.focus(); } live.textContent = 'Row added. ' + tbody.children.length + ' rows.'; }
      return tr;
    }

    function load(text) {
      tbody.innerHTML = '';
      parse(text).forEach(function (r) { tbody.appendChild(buildRow(r)); });
      relabel(); sync(); applyFilter();
    }

    function applyFilter() {
      var q = filter.value.trim().toLowerCase();
      Array.prototype.forEach.call(tbody.children, function (tr) {
        var hay = rowValues(tr).join(' ').toLowerCase();
        tr.hidden = q !== '' && hay.indexOf(q) < 0;
      });
    }

    // ── Build the UI ──
    var head = el('tr');
    cols.forEach(function (c) {
      var th = el('th', { scope: 'col', text: c.label });
      if (c.width) { th.style.width = c.width; }
      head.appendChild(th);
    });
    var actionsTh = el('th', { scope: 'col', 'class': 'seal-rules-actions' }, [el('span', { 'class': 'screen-reader-text', text: 'Actions' })]);
    actionsTh.style.width = '74px';
    head.appendChild(actionsTh);
    table = el('table', { 'class': 'widefat striped seal-rules-table' }, [el('caption', { 'class': 'screen-reader-text', text: schema.title }), el('thead', {}, [head])]);
    tbody = el('tbody'); table.appendChild(tbody);

    var addBtn  = el('button', { type: 'button', 'class': 'button', text: 'Add row' });
    var sortBtn = el('button', { type: 'button', 'class': 'button', text: 'Sort A–Z' });
    var textChk = el('input', { type: 'checkbox', id: uid + '-astext' });
    var textLbl = el('label', { 'for': uid + '-astext', 'class': 'seal-rules-astext' }, [textChk, document.createTextNode(' Edit as text')]);
    filter = el('input', { type: 'search', 'class': 'seal-rules-filter', placeholder: 'Filter…', 'aria-label': 'Filter ' + schema.title.toLowerCase() });
    count = el('span', { 'class': 'seal-rules-count' });
    var bar = el('div', { 'class': 'seal-rules-bar' }, [addBtn, sortBtn, filter, count, textLbl]);

    var listId = uid + '-dl';
    var dl = null;
    cols.forEach(function (c) {
      if (c.list && !dl) {
        dl = el('datalist', { id: listId });
        c.list.forEach(function (t) { dl.appendChild(el('option', { value: t })); });
      }
    });

    var wrap = el('div', { 'class': 'seal-rules-tablewrap' }, [table]);
    root.appendChild(bar); root.appendChild(wrap); if (dl) { root.appendChild(dl); } root.appendChild(live);

    root.classList.add('is-ready');
    ta.classList.add('seal-rules-text');
    ta.hidden = true;
    load(ta.value);

    // ── Events ──
    tbody.addEventListener('input', function () { sync(); applyFilter(); });
    tbody.addEventListener('change', sync);
    tbody.addEventListener('click', function (e) {
      var b = e.target.closest('.seal-rules-remove');
      if (!b) { return; }
      var tr = b.closest('tr'), next = tr.nextElementSibling || tr.previousElementSibling;
      tr.parentNode.removeChild(tr);
      relabel(); sync();
      live.textContent = 'Row removed. ' + tbody.children.length + ' rows.';
      var f = next ? next.querySelector('input,select') : addBtn;
      if (f) { f.focus(); }
    });
    tbody.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName === 'BUTTON') { return; }
      e.preventDefault();                       // Enter must not submit the whole settings form
      var tr = e.target.closest('tr'), i = e.target.getAttribute('data-col');
      var next = tr.nextElementSibling;
      while (next && next.hidden) { next = next.nextElementSibling; }
      if (!next) { next = addRow(null, false); }
      var f = next.querySelector('[data-col="' + i + '"]'); if (f) { f.focus(); }
    });
    addBtn.addEventListener('click', function () { filter.value = ''; applyFilter(); addRow(null, true); });
    sortBtn.addEventListener('click', function () {
      var rows = Array.prototype.slice.call(tbody.children);
      var key = cols.findIndex(function (c) { return c.type === 'text'; });
      rows.sort(function (a, b) {
        return rowValues(a)[key].toLowerCase().localeCompare(rowValues(b)[key].toLowerCase());
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
      relabel(); sync();
      live.textContent = 'Sorted A to Z.';
    });
    filter.addEventListener('input', applyFilter);
    filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    textChk.addEventListener('change', function () {
      if (textChk.checked) { sync(); ta.hidden = false; wrap.hidden = true; addBtn.disabled = sortBtn.disabled = filter.disabled = true; }
      else { load(ta.value); ta.hidden = true; wrap.hidden = false; addBtn.disabled = sortBtn.disabled = filter.disabled = false; }
    });
    ta.addEventListener('input', function () { ta.dispatchEvent(new CustomEvent('seal-rules-sync', { bubbles: true })); });
    var form = ta.form;
    if (form) {
      form.addEventListener('submit', function () { if (textChk.checked) { load(ta.value); } else { sync(); } });
    }
  }

  // ── Copy-and-paste crontab, built from the Scheduled Jobs list and the settings above it ──
  var CRON_RE = /^(manual|@(reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)|([0-9*\/,A-Za-z-]+\s+){4}[0-9*\/,A-Za-z-]+)$/;
  var SAFE = /^[A-Za-z0-9._\/-]+$/;

  function initCron(box) {
    var d = JSON.parse(box.getAttribute('data-defaults'));
    var listId = box.getAttribute('data-list');
    var out = box.querySelector('.seal-cron-text');
    var status = box.querySelector('.seal-cron-status');
    function val(id, def) {
      var e = document.getElementById(id);
      var v = e ? String(e.value || '').trim() : '';
      return v === '' ? def : v;
    }
    function build() {
      var list = document.getElementById(listId);
      // Short code: same reduction as SEAL_Org_Rules::code_from() (lower-case letters and digits).
      var code = val(box.getAttribute('data-code'), d.code).toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 20) || d.code;
      var withCode = function (s) { return s.split('{code}').join(code); };
      var php = val(box.getAttribute('data-php'), d.php);
      var dir = withCode(val(box.getAttribute('data-dir'), d.dir)).replace(/\/+$/, '');
      var mode = val(box.getAttribute('data-output'), d.output) === 'log' ? 'log' : 'discard';
      var log = withCode(val(box.getAttribute('data-log'), d.log));
      var redirect = (mode === 'log' && SAFE.test(log)) ? '>> ' + log + ' 2>&1' : '>/dev/null 2>&1';
      var lines = [
        '# Scheduled jobs for ' + d.title,
        '# Install: run "crontab -e" as the user that owns the script files, paste the lines below, save.',
        '# Check:   crontab -l',
        mode === 'log' ? '# Output is appended to the log file so a failing job leaves a trace.'
                       : '# Output is discarded (a failing job is silent). Choose "Append to a log file" above to keep it.',
        ''
      ];
      var manual = [];
      String(list ? list.value : '').split(/\r?\n/).forEach(function (line) {
        line = line.trim();
        if (!line || line.charAt(0) === '#') { return; }
        var c = line.split('|').map(function (x) { return x.trim(); });
        var when = c[0] || '', script = c[1] || '', desc = c[2] || '';
        if (!script) { return; }
        if (!SAFE.test(script) || !SAFE.test(php) || !SAFE.test(dir)) { lines.push('# SKIPPED (unsafe characters in a path or script name): ' + script); return; }
        if (when.toLowerCase() === 'manual') { manual.push([script, desc]); return; }
        if (!CRON_RE.test(when)) { lines.push('# SKIPPED (not a valid schedule "' + when + '"): ' + script); return; }
        if (desc) { lines.push('# ' + desc); }
        lines.push(when + ' ' + php + ' ' + dir + '/' + script + ' ' + redirect);
        lines.push('');
      });
      if (manual.length) {
        lines.push('# Run by hand when needed (not scheduled):');
        manual.forEach(function (m) { lines.push('#   ' + php + ' ' + dir + '/' + m[0] + (m[1] ? '   # ' + m[1] : '')); });
      }
      out.value = lines.join('\n').replace(/\s+$/, '') + '\n';
    }
    ['data-code', 'data-php', 'data-dir', 'data-output', 'data-log'].forEach(function (a) {
      var e = document.getElementById(box.getAttribute(a));
      if (e) { e.addEventListener('input', build); e.addEventListener('change', build); }
    });
    document.addEventListener('seal-rules-sync', function (e) { if (e.target && e.target.id === listId) { build(); } });
    box.querySelector('.seal-cron-copy').addEventListener('click', function () {
      out.focus(); out.select();
      var done = function (ok) { status.textContent = ok ? 'Copied. Now paste it into "crontab -e" on the server.' : 'Press Ctrl+C (Cmd+C on a Mac) to copy the selected text.'; };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(out.value).then(function () { done(true); }, function () { done(document.execCommand && document.execCommand('copy')); });
      } else {
        var ok = false; try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        done(ok);
      }
    });
    build();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.seal-rules'), init);
    Array.prototype.forEach.call(document.querySelectorAll('.seal-cron-box'), initCron);
  });
})();
