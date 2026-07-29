<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';
require_once __DIR__ . '/lib/AuditExport.php';

$user = Auth::requireRole(AuditExport::EXPORT_ROLES);
$db   = getDB();

[$fyStart, $fyEnd]   = AuditExport::currentFY();
[$pfyStart, $pfyEnd] = AuditExport::currentFY(-1);
$groups = AuditExport::groups();

Layout::shell($user, 'irs', 0, 'Auditor Export');
?>
<style>
.ax-page { max-width:1000px; }
.ax-card { background:#fff; border:1px solid #e2e8f0; border-radius:.5rem; margin-bottom:1.1rem; }
.ax-hd { padding:.75rem 1.1rem; border-bottom:1px solid #f1f5f9; font-size:.78rem; font-weight:700;
         text-transform:uppercase; letter-spacing:.06em; color:#64748b; }
.ax-body { padding:1rem 1.1rem; }

.ax-presets { display:flex; gap:.45rem; flex-wrap:wrap; margin-bottom:.8rem; }
.ax-preset { font-family:inherit; font-size:.8rem; font-weight:600; padding:.4rem .75rem; border-radius:.35rem;
             border:1px solid #cbd5e1; background:#fff; color:#475569; cursor:pointer; }
.ax-preset:hover { border-color:#002850; color:#002850; }
.ax-preset.on { background:#002850; border-color:#002850; color:#fff; }

.ax-range { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; max-width:430px; }
@media (max-width:520px) { .ax-range { grid-template-columns:1fr; } }

.ax-groups { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:.5rem; }
.ax-grp { display:flex; gap:.55rem; align-items:flex-start; padding:.55rem .65rem; border:1px solid #e2e8f0;
          border-radius:.4rem; cursor:pointer; }
.ax-grp:hover { border-color:#94a3b8; }
.ax-grp.on { border-color:#64A014; background:#f6fbef; }
.ax-grp.locked { background:#f8fafc; cursor:default; border-color:#cbd5e1; }
.ax-grp input { margin-top:.2rem; accent-color:#64A014; width:15px; height:15px; flex-shrink:0; }
.ax-gname { font-size:.83rem; font-weight:700; color:#0f172a; }
.ax-gtag { font-size:.6rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
           padding:.05rem .35rem; border-radius:999px; margin-left:.3rem; background:#002850; color:#fff; }
.ax-gdesc { font-size:.75rem; color:#64748b; margin-top:.1rem; }
.ax-gcols { font-family:monospace; font-size:.67rem; color:#94a3b8; margin-top:.18rem; word-break:break-word; }

.ax-switch { display:flex; gap:.55rem; align-items:flex-start; padding:.5rem 0; }
.ax-switch input { margin-top:.2rem; accent-color:#002850; width:15px; height:15px; flex-shrink:0; }
.ax-sname { font-size:.85rem; font-weight:600; color:#0f172a; }
.ax-sdesc { font-size:.76rem; color:#64748b; }

.ax-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.7rem; }
.ax-stat { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.4rem; padding:.6rem .7rem; }
.ax-slabel { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
.ax-sval { font-size:1.15rem; font-weight:700; color:#002850; font-variant-numeric:tabular-nums; margin-top:.1rem; }
.ax-sval.warn { color:#b45309; }
.ax-sval.bad  { color:#dc2626; }
.ax-sval.ok   { color:#059669; }

.ax-flags { margin-top:.8rem; font-size:.82rem; }
.ax-flag { padding:.45rem .7rem; border-radius:.35rem; margin-bottom:.35rem; }
.ax-flag.warn { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; }
.ax-flag.ok   { background:#f0fdf4; border:1px solid #86efac; color:#166534; }

/* Live data grid — the actual rows the CSV will contain */
.ax-grid-wrap { border:1px solid #e2e8f0; border-radius:.4rem; overflow:hidden; margin-top:.9rem; }
.ax-grid-hd { padding:.5rem .75rem; background:#f8fafc; border-bottom:1px solid #e2e8f0;
              font-size:.75rem; color:#64748b; display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; }
.ax-grid-scroll { overflow:auto; max-height:460px; }
.ax-grid { border-collapse:collapse; font-size:.74rem; white-space:nowrap; }
.ax-grid th { background:#f1f5f9; text-align:left; font-size:.63rem; letter-spacing:.04em; text-transform:uppercase;
              color:#64748b; font-weight:700; padding:.4rem .55rem; border-bottom:1px solid #cbd5e1;
              border-right:1px solid #e2e8f0; position:sticky; top:0; z-index:2; }
.ax-grid td { padding:.3rem .55rem; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9;
              font-family:monospace; font-size:.71rem; color:#334155; }
.ax-grid td.t { font-family:inherit; font-size:.74rem; white-space:normal; min-width:150px; max-width:260px; }
.ax-grid td.n { text-align:right; font-variant-numeric:tabular-nums; }
.ax-grid tr.first td { border-top:2px solid #cbd5e1; }
.ax-grid tr.first td.ref { font-weight:700; color:#002850; }
.ax-grid tr:hover td { background:#f8fafc; }
.ax-pill { display:inline-block; font-size:.62rem; font-weight:700; padding:.04rem .36rem; border-radius:999px; }
.ax-pill.bank { background:#dcfce7; color:#059669; }
.ax-pill.exp  { background:#f1f5f9; color:#64748b; }
.ax-pill.liab { background:#fef3c7; color:#b45309; }
.ax-miss { color:#dc2626; font-style:italic; }
.ax-ok   { color:#059669; font-weight:700; }

.ax-dl { display:flex; align-items:center; gap:.8rem; flex-wrap:wrap; }
.ax-fname { font-family:monospace; font-size:.78rem; color:#64748b; word-break:break-all; }
.ax-note { font-size:.78rem; color:#92400e; background:#fffbeb; border:1px solid #fcd34d;
           border-left:3px solid #f59e0b; border-radius:.35rem; padding:.6rem .8rem; margin-top:.9rem; }
</style>

<div class="hri-page ax-page">
  <div class="hri-page-hd">
    <h1 class="hri-page-title">&#128202; Auditor Export</h1>
  </div>

  <!-- ── Period ─────────────────────────────────────────────── -->
  <div class="ax-card">
    <div class="ax-hd">Period</div>
    <div class="ax-body">
      <div class="ax-presets">
        <button type="button" class="ax-preset on" id="pFY"   onclick="setRange('<?= $fyStart ?>','<?= $fyEnd ?>',this)">This financial year</button>
        <button type="button" class="ax-preset"    onclick="setRange('<?= $pfyStart ?>','<?= $pfyEnd ?>',this)">Last financial year</button>
        <button type="button" class="ax-preset"    onclick="setQuarter(this)">This quarter</button>
        <button type="button" class="ax-preset"    onclick="setRange('2000-01-01','<?= date('Y-m-d') ?>',this)">All time</button>
      </div>
      <div class="ax-range">
        <div>
          <label class="hri-label" style="font-size:.8rem;">From</label>
          <input type="date" id="axFrom" class="hri-input" value="<?= $fyStart ?>" onchange="clearPreset();refresh()">
        </div>
        <div>
          <label class="hri-label" style="font-size:.8rem;">To</label>
          <input type="date" id="axTo" class="hri-input" value="<?= $fyEnd ?>" onchange="clearPreset();refresh()">
        </div>
      </div>
      <div style="font-size:.76rem;color:#94a3b8;margin-top:.5rem;">
        Matched on the date posted to Sage, falling back to the date raised where a transaction was never posted.
      </div>
    </div>
  </div>

  <!-- ── Scope ──────────────────────────────────────────────── -->
  <div class="ax-card">
    <div class="ax-hd">Scope</div>
    <div class="ax-body">
      <label class="ax-switch">
        <input type="checkbox" id="axPosted" onchange="refresh()">
        <span>
          <span class="ax-sname">Only transactions posted to Sage</span><br>
          <span class="ax-sdesc">The true &ldquo;payments made&rdquo; list &mdash; completed, with a Sage reference. Leave off to include everything still in flight.</span>
        </span>
      </label>
      <label class="ax-switch">
        <input type="checkbox" id="axNoJrnl" checked onchange="refresh()">
        <span>
          <span class="ax-sname">Include transactions with no journal entries</span><br>
          <span class="ax-sdesc">Petty cash disbursed before journals were captured, and anything in flight. Shown as one row with the journal columns blank.</span>
        </span>
      </label>
    </div>
  </div>

  <!-- ── Columns ────────────────────────────────────────────── -->
  <div class="ax-card">
    <div class="ax-hd">Columns</div>
    <div class="ax-body">
      <div class="ax-groups">
        <?php foreach ($groups as $gid => $g): ?>
        <label class="ax-grp <?= $g['locked'] ? 'locked on' : 'on' ?>" id="lbl_<?= $gid ?>">
          <input type="checkbox" class="axg" data-g="<?= $gid ?>" checked
                 <?= $g['locked'] ? 'disabled' : '' ?> onchange="toggleGrp(this)">
          <span>
            <span class="ax-gname"><?= htmlspecialchars($g['name']) ?><?= $g['locked'] ? '<span class="ax-gtag">always</span>' : '' ?></span>
            <span class="ax-gdesc"><?= htmlspecialchars($g['desc']) ?></span>
            <span class="ax-gcols"><?= htmlspecialchars(implode(', ', $g['cols'])) ?></span>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Preview + download ─────────────────────────────────── -->
  <div class="ax-card">
    <div class="ax-hd">Preview</div>
    <div class="ax-body">
      <div class="ax-stats">
        <div class="ax-stat"><div class="ax-slabel">Transactions</div><div class="ax-sval" id="sTxn">&mdash;</div></div>
        <div class="ax-stat"><div class="ax-slabel">Rows</div><div class="ax-sval" id="sRows">&mdash;</div></div>
        <div class="ax-stat"><div class="ax-slabel">Columns</div><div class="ax-sval" id="sCols">&mdash;</div></div>
        <div class="ax-stat"><div class="ax-slabel">Total debit</div><div class="ax-sval" id="sDr">&mdash;</div></div>
        <div class="ax-stat"><div class="ax-slabel">Total credit</div><div class="ax-sval" id="sCr">&mdash;</div></div>
      </div>

      <div class="ax-flags" id="axFlags"></div>

      <div class="ax-grid-wrap">
        <div class="ax-grid-hd">
          <span id="axGridCount">Loading&hellip;</span>
          <span>Exactly the rows the CSV will contain</span>
        </div>
        <div class="ax-grid-scroll"><table class="ax-grid" id="axGrid"></table></div>
      </div>

      <div class="ax-dl" style="margin-top:1rem;">
        <button onclick="download()" class="hri-btn hri-btn-navy" id="axDl" style="font-size:.9rem;">
          &#11015; Download CSV
        </button>
        <span class="ax-fname" id="axFile"></span>
      </div>

      <div class="ax-note">
        <strong>&#9888; Confidential.</strong> This file carries staff and vendor names and bank account
        numbers. Releasing it to an audit firm is a third-party disclosure under the NDPA 2023 &mdash;
        lawful for a statutory audit, but every export is written to the audit log with your name, the
        period and the row count.
      </div>
    </div>
  </div>
</div>

<script>
function selGroups() {
    var out = [];
    document.querySelectorAll('.axg').forEach(function(c) {
        if (c.checked && !c.disabled) out.push(c.dataset.g);
    });
    return out;
}

function toggleGrp(cb) {
    var lbl = document.getElementById('lbl_' + cb.dataset.g);
    if (lbl) lbl.classList.toggle('on', cb.checked);
    refresh();
}

function clearPreset() {
    document.querySelectorAll('.ax-preset').forEach(function(b) { b.classList.remove('on'); });
}

function setRange(f, t, btn) {
    document.getElementById('axFrom').value = f;
    document.getElementById('axTo').value   = t;
    clearPreset();
    if (btn) btn.classList.add('on');
    refresh();
}

function setQuarter(btn) {
    var n = new Date(), q = Math.floor(n.getMonth() / 3);
    var s = new Date(n.getFullYear(), q * 3, 1);
    var e = new Date(n.getFullYear(), q * 3 + 3, 0);
    var f = function(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    setRange(f(s), f(e), btn);
}

function qs() {
    return 'from=' + encodeURIComponent(document.getElementById('axFrom').value)
         + '&to='   + encodeURIComponent(document.getElementById('axTo').value)
         + '&groups=' + encodeURIComponent(selGroups().join(','))
         + (document.getElementById('axPosted').checked ? '&posted=1' : '')
         + (document.getElementById('axNoJrnl').checked ? '&nojrnl=1' : '');
}

function money(n) {
    return '₦' + Number(n).toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
}

var _t = null;
function refresh() {
    clearTimeout(_t);
    _t = setTimeout(doRefresh, 220);
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Columns rendered as free text rather than monospace, and as right-aligned numbers
var TEXTY = ['description','requested_by','payee_name','jrnl_account','jrnl_narration',
             'approval_chain','posted_by','attachment_names','last_rejection_reason'];
var NUMY  = ['txn_amount','debit','credit','txn_total_debit','txn_total_credit',
             'advance_amount','variance'];

function cellHtml(col, val) {
    if (col === 'line_type' && val && val !== '—') {
        var c = val === 'Bank/Cash' ? 'bank'
              : (val === 'Receivable' || val === 'Liability') ? 'liab' : 'exp';
        return '<span class="ax-pill ' + c + '">' + esc(val) + '</span>';
    }
    if (col === 'txn_balanced') {
        if (val === 'Yes') return '<span class="ax-ok">Yes</span>';
        if (val && val !== '') return '<span class="ax-miss">' + esc(val) + '</span>';
    }
    if (col === 'originating_bank' && val === '(not recorded)') return '<span class="ax-miss">' + esc(val) + '</span>';
    if (col === 'sage_ref' && !val) return '<span class="ax-miss">— none —</span>';
    if (col === 'attachment_count' && val === '0') return '<span class="ax-miss">0</span>';
    return esc(val);
}

function renderGrid(d) {
    var h = '<thead><tr>';
    d.header.forEach(function(c) { h += '<th>' + esc(c) + '</th>'; });
    h += '</tr></thead><tbody>';

    if (!d.sample.length) {
        h += '<tr><td colspan="' + d.header.length + '" style="padding:1.2rem;text-align:center;color:#94a3b8;font-family:inherit;">'
           + 'Nothing to export for this period.</td></tr>';
    }
    d.sample.forEach(function(r) {
        h += '<tr' + (r.first ? ' class="first"' : '') + '>';
        r.cells.forEach(function(v, i) {
            var col = d.header[i];
            var cls = col === 'txn_ref' ? 'ref' : (NUMY.indexOf(col) >= 0 ? 'n' : (TEXTY.indexOf(col) >= 0 ? 't' : ''));
            h += '<td class="' + cls + '">' + cellHtml(col, v) + '</td>';
        });
        h += '</tr>';
    });
    h += '</tbody>';
    document.getElementById('axGrid').innerHTML = h;

    document.getElementById('axGridCount').textContent = d.truncated
        ? 'Showing first ' + d.shown.toLocaleString() + ' of ' + d.rows.toLocaleString() + ' rows'
        : 'Showing all ' + d.rows.toLocaleString() + ' row' + (d.rows === 1 ? '' : 's');
}

function doRefresh() {
    var f = document.getElementById('axFrom').value, t = document.getElementById('axTo').value;
    document.getElementById('axFile').textContent = 'HRI-IRS-Audit-' + f + '-to-' + t + '.csv';

    fetch('api/audit/export.php?count=1&limit=60&' + qs(), {credentials:'same-origin'})
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok) return;
        document.getElementById('sTxn').textContent  = d.transactions.toLocaleString();
        document.getElementById('sRows').textContent = d.rows.toLocaleString();
        document.getElementById('sCols').textContent = d.columns;
        document.getElementById('sDr').textContent   = money(d.total_debit);
        document.getElementById('sCr').textContent   = money(d.total_credit);

        var cr = document.getElementById('sCr');
        cr.className = 'ax-sval ' + (d.balanced ? 'ok' : 'bad');

        var h = '';
        if (d.transactions === 0) {
            h = '<div class="ax-flag warn">No transactions in this period.</div>';
        } else {
            h += d.balanced
                ? '<div class="ax-flag ok">&#10003; Journals balance across the period.</div>'
                : '<div class="ax-flag warn">&#9888; Debits and credits do not agree across the period. Check the unbalanced transactions before releasing this.</div>';
            if (d.unbalanced > 0)  h += '<div class="ax-flag warn">&#9888; ' + d.unbalanced + ' transaction(s) have journals that do not balance.</div>';
            if (d.no_journal > 0)  h += '<div class="ax-flag warn">&#9888; ' + d.no_journal + ' transaction(s) have no journal entries. Untick the scope option above to exclude them.</div>';
            if (d.no_sage_ref > 0) h += '<div class="ax-flag warn">&#9888; ' + d.no_sage_ref + ' transaction(s) have no Sage reference &mdash; the auditor cannot trace these.</div>';
        }
        document.getElementById('axFlags').innerHTML = h;

        renderGrid(d);
    })
    .catch(function() {
        document.getElementById('axGridCount').textContent = 'Could not load preview.';
    });
}

function download() {
    window.location.href = 'api/audit/export.php?' + qs();
}

refresh();
</script>
<?php Layout::end(); ?>
