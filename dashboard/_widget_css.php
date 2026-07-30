<?php
/**
 * Styling for the shared dashboard widgets.
 *
 * The widgets previously borrowed .card / .chd / .cht / .chl / .ritem from
 * whichever dashboard included them. Those classes are defined separately in
 * each dashboard with slightly different values, and .ritem is not defined in
 * staff.php at all — so the same widget rendered differently from page to page
 * and had no row layout on one of them.
 *
 * These hriw-* classes are self-contained and match the existing visual
 * language (12px radius, soft shadow, navy titles, green links), so every
 * widget now renders identically wherever it appears.
 *
 * Printed once per request.
 */
if (defined('HRI_WIDGET_CSS')) return;
define('HRI_WIDGET_CSS', true);
?>
<style>
/* ── Shared dashboard widgets ─────────────────────────────────────────────── */
.hriw-card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden;margin-bottom:16px;}
.hriw-card.is-urgent{box-shadow:0 1px 3px rgba(0,0,0,.08),inset 4px 0 0 #dc2626;}
.hriw-card.is-warn{box-shadow:0 1px 3px rgba(0,0,0,.08),inset 4px 0 0 #f59e0b;}

.hriw-hd{padding:11px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.hriw-title{font-size:13px;font-weight:700;color:#002850;display:flex;align-items:center;gap:7px;min-width:0;}
.hriw-link{font-size:12.5px;color:#64A014;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0;}
.hriw-link:hover{text-decoration:underline;}
.hriw-pill{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 6px;border-radius:99px;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}
.hriw-btn{display:inline-block;background:#002850;color:#fff;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0;}
.hriw-btn:hover{background:#1a4470;}

/* rows — consistent height, alignment and separators */
.hriw-row{display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;}
.hriw-row:last-child{border-bottom:0;}
.hriw-row:hover{background:#f8fafc;}
.hriw-main{flex:1;min-width:0;}
.hriw-t1{font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hriw-t2{font-size:11px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hriw-meta{font-size:11px;color:#94a3b8;white-space:nowrap;flex-shrink:0;}
.hriw-tag{display:inline-block;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:1px 5px;border-radius:3px;margin-right:5px;vertical-align:1px;}
.hriw-chip{display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;white-space:nowrap;flex-shrink:0;}
.hriw-act{font-size:11.5px;font-weight:600;color:#002850;background:#eff6ff;padding:4px 10px;border-radius:5px;text-decoration:none;white-space:nowrap;flex-shrink:0;}
.hriw-act:hover{background:#dbeafe;}
.hriw-act.warn{background:#d97706;color:#fff;}
.hriw-act.warn:hover{background:#b45309;}

.hriw-empty{padding:22px 16px;text-align:center;color:#94a3b8;font-size:12.5px;}
.hriw-foot{padding:8px 16px;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:11px;color:#94a3b8;}

/* progress bars */
.hriw-bar{height:4px;background:#f1f5f9;border-radius:2px;margin-top:6px;overflow:hidden;}
.hriw-bar>span{display:block;height:100%;border-radius:2px;}

/* table inside a widget (approver queue) */
.hriw-tbl-wrap{overflow-x:auto;}
.hriw-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.hriw-tbl th{padding:7px 16px;text-align:left;color:#64748b;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.03em;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap;}
.hriw-tbl td{padding:8px 16px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.hriw-tbl tr:last-child td{border-bottom:0;}
.hriw-tbl tr:hover td{background:#f8fafc;}
.hriw-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}

/* 768px matches the shell's own breakpoint, where the sidebar collapses and
   .hri-main goes full width — widgets need to reflow at the same moment. */
/* ── Mobile ───────────────────────────────────────────────────────────────
   768px matches the shell, where the sidebar collapses and .hri-main goes
   full width. A row holding a title, meta and an action button will not fit
   one line on a phone, so it wraps onto two rather than being squeezed —
   nothing is hidden, it just gets the space it needs.                      */
@media(max-width:768px){
  .hriw-card{margin-bottom:14px;border-radius:10px;}
  .hriw-hd{padding:12px 14px;}
  .hriw-title{font-size:14px;}
  .hriw-link{font-size:13px;}

  /* wrap instead of crush */
  .hriw-row{padding:12px 14px;gap:8px 10px;flex-wrap:wrap;align-items:flex-start;}
  .hriw-main{flex:1 1 100%;min-width:0;}
  .hriw-t1{font-size:14px;white-space:normal;}      /* let long titles wrap */
  .hriw-t2{font-size:12.5px;white-space:normal;}
  .hriw-meta{font-size:12px;order:2;}               /* own line, still visible */
  .hriw-chip{font-size:11px;padding:3px 9px;}
  .hriw-act{font-size:13px;padding:8px 14px;margin-left:auto;order:3;}

  .hriw-empty{font-size:13.5px;padding:24px 14px;}
  .hriw-foot{padding:10px 14px;font-size:12px;}

  /* the approver queue is seven columns — scroll it rather than crush it */
  .hriw-tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .hriw-tbl{min-width:600px;font-size:13px;}
  .hriw-tbl th,.hriw-tbl td{padding:10px 12px;}
}
</style>
