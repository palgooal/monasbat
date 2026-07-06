<?php
defined('ABSPATH') || exit;

if (!is_user_logged_in()) { auth_redirect(); }

$event_id   = (int) get_query_var('event_id');
$event_post = $event_id ? get_post($event_id) : null;

if (!$event_id || !$event_post || $event_post->post_type !== 'pge_event') {
    wp_safe_redirect(home_url('/dashboard/?tab=events')); exit;
}

$can_manage = function_exists('pge_event_guests_user_can_manage')
    ? pge_event_guests_user_can_manage($event_id)
    : (current_user_can('administrator') || ((int) $event_post->post_author === get_current_user_id()));

if (!$can_manage) { wp_safe_redirect(home_url('/dashboard/?tab=events')); exit; }

$guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
$stats = function_exists('pge_event_guests_get_stats')
    ? pge_event_guests_get_stats($event_id, $guests_map)
    : ['total' => count($guests_map), 'yes' => 0, 'no' => 0, 'pending' => count($guests_map), 'checked' => 0];

$invite_code_raw = (string) get_post_meta($event_id, '_pge_invite_code', true);
$invite_code     = function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($invite_code_raw) : strtoupper(trim($invite_code_raw));
$event_date      = (string) get_post_meta($event_id, '_pge_event_date', true);
$event_date_label= $event_date ? date_i18n('j F Y - g:i a', strtotime(str_replace('T', ' ', $event_date))) : '—';
$manage_nonce    = wp_create_nonce('pge_event_manage_nonce');
$dashboard_url   = home_url('/dashboard/?tab=events&event=' . $event_id);
$event_url       = get_permalink($event_id);
$event_image_url = (string) get_the_post_thumbnail_url($event_id, 'full');
$edit_url        = home_url('/edit-event/' . $event_id . '/');
$wa_templates    = function_exists('pge_wa_get_templates') ? pge_wa_get_templates($event_id) : [];
$wa_tpl_invite   = $wa_templates['invite']  ?? '';
$wa_tpl_yes      = $wa_templates['yes']     ?? '';
$wa_tpl_no       = $wa_templates['no']      ?? '';
$wa_tpl_invalid  = $wa_templates['invalid'] ?? '';
$wa_provider     = get_option('pge_wa_provider', 'cartat');
$wa_provider_label = $wa_provider === 'ultramsg' ? 'UltraMsg' : 'Cartat';

// عرض فقط — بيانات إضافية للهيرو والشريط الجانبي (قراءة فقط من post meta الموجودة أصلاً، بدون أي منطق جديد)
$event_address_manage  = (string) get_post_meta($event_id, '_pge_event_address', true);
$event_location_manage = (string) get_post_meta($event_id, '_pge_event_location', true);
$manage_event_ts       = $event_date ? strtotime(str_replace('T', ' ', $event_date)) : 0;
$manage_is_upcoming    = $manage_event_ts && $manage_event_ts >= current_time('timestamp');
$manage_status_label   = $manage_event_ts ? ($manage_is_upcoming ? 'قادمة' : 'منتهية') : 'بدون تاريخ';

// عرض فقط — ملخص الباقة/الحصة لنفس المستخدم الحالي (إعادة استخدام نفس الدوال والصيغة المستخدمة في لوحة التحكم وصفحة الإنشاء، بدون أي حساب جديد)
$manage_user_id      = get_current_user_id();
$manage_plan_limits  = function_exists('pge_get_user_plan_limits_for_events') ? pge_get_user_plan_limits_for_events($manage_user_id) : [];
$manage_plan_name    = (string) get_user_meta($manage_user_id, '_mon_package_name', true);
if ($manage_plan_name === '') {
    $manage_plan_name = (string) ($manage_plan_limits['name'] ?? 'بدون باقة');
}
$manage_events_limit_meta = get_user_meta($manage_user_id, '_mon_events_limit', true);
$manage_events_limit = $manage_events_limit_meta !== '' ? (int) $manage_events_limit_meta : (int) ($manage_plan_limits['events_count'] ?? 0);
$manage_events_used_q = new WP_Query([
    'post_type'      => 'pge_event',
    'post_status'    => ['publish', 'draft', 'pending'],
    'author'         => $manage_user_id,
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);
$manage_events_used  = (int) $manage_events_used_q->found_posts;
$manage_events_left  = max(0, $manage_events_limit - $manage_events_used);

get_header();
?>
<style>
:root { --safe-b: env(safe-area-inset-bottom, 0px); --bnav-h: 64px; --app-h: 56px; }

/* ── Scrollbar hide ───────────────────────────────── */
.noscroll::-webkit-scrollbar { display:none; }
.noscroll { -ms-overflow-style:none; scrollbar-width:none; }

/* ── Guest Card ───────────────────────────────────── */
.guest-card { background:#fff; border-radius:18px; border:1px solid var(--color-border); transition:box-shadow .15s; overflow:hidden; }
.guest-card:active { background:var(--color-background); }

/* ── Avatar ───────────────────────────────────────── */
.g-avatar { width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;flex-shrink:0; }
.av-yes    { background:linear-gradient(135deg,#10b981,#059669); }
.av-no     { background:linear-gradient(135deg, var(--color-destructive-text), #8f2d20); }
.av-pending{ background:linear-gradient(135deg, color-mix(in srgb, var(--color-foreground) 38%, white), color-mix(in srgb, var(--color-foreground) 55%, white)); }

/* ── Bottom Nav ───────────────────────────────────── */
.bnav { position:fixed;bottom:0;inset-x:0;height:calc(var(--bnav-h) + var(--safe-b));background:#fff;border-top:1px solid var(--color-border);z-index:50;display:grid;grid-template-columns:repeat(4,1fr);padding-bottom:var(--safe-b); }
.bnav-item { display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:11px;font-weight:700;color:color-mix(in srgb, var(--color-foreground) 45%, white);border:none;background:none;cursor:pointer;padding:8px 0;transition:color .15s; }
.bnav-item.active,.bnav-item:active { color:var(--color-primary); }
.bnav-icon { font-size:21px;line-height:1; }

/* ── Actions Panel (bottom sheet on mobile) ───────── */
@media (max-width:1023px) {
  #actionsPanel {
    position:fixed;bottom:calc(var(--bnav-h) + var(--safe-b));inset-x:0;height:86svh;
    background:#fff;border-radius:24px 24px 0 0;box-shadow:0 -8px 40px rgba(0,0,0,.18);
    z-index:45;overflow-y:auto;transform:translateY(110%);
    transition:transform .35s cubic-bezier(.32,.72,0,1);
  }
  #actionsPanel.open { transform:translateY(0); }
  #overlay { position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:44;opacity:0;pointer-events:none;transition:opacity .3s; }
  #overlay.show { opacity:1;pointer-events:auto; }
  .sheet-bar { width:40px;height:4px;border-radius:2px;background:var(--color-border);margin:12px auto 4px; }
  .pge-main { padding-top:var(--app-h);padding-bottom:calc(var(--bnav-h) + var(--safe-b) + 8px); }
}
@media (min-width:1024px) {
  .bnav,.sheet-bar,.panel-header { display:none!important; }
  .pge-main { display:grid;grid-template-columns:1fr 375px;gap:20px;align-items:start; }
  #actionsPanel { position:static;height:auto;transform:none!important;background:transparent;box-shadow:none;border-radius:0; }
}

/* ── Toast ────────────────────────────────────────── */
#toast { position:fixed;top:calc(var(--app-h) + 8px);left:50%;transform:translateX(-50%);z-index:99;min-width:180px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s,transform .25s;white-space:nowrap; }
#toast.show { opacity:1;transform:translateX(-50%) translateY(0); }
@media (min-width:1024px) { #toast { top:20px; } }

/* ── Stat card ────────────────────────────────────── */
.stat-chip { flex-shrink:0;display:flex;align-items:center;gap:6px;padding:8px 16px;border-radius:99px;font-size:13px;font-weight:700;border:1.5px solid; }
.stat-num { font-size:18px;font-weight:800; }

/* ── WA Send Panel ────────────────────────────────── */
#waSendPanel { position:fixed;inset-x:0;bottom:calc(var(--bnav-h) + var(--safe-b));z-index:46;background:#fff;border-radius:24px 24px 0 0;box-shadow:0 -6px 30px rgba(0,0,0,.15);transform:translateY(110%);transition:transform .35s cubic-bezier(.32,.72,0,1); }
#waSendPanel.open { transform:translateY(0); }

/* ── Hide site footer on this page ───────────────── */
body footer.border-t, body footer[class*="border"], footer[class] { display:none!important; }

/* ── Stats strip mobile top offset ──────────────── */
@media (max-width:1023px) {
  .stats-outer { padding-top:var(--app-h); }
}

/* ── Focus-visible: يُطبَّق الآن عالمياً من input.css (رمز واحد لكل الصفحات) ───────────── */
</style>

<div dir="rtl">

<!-- ══ Toast ══════════════════════════════════════════════════ -->
<div id="toast" role="status" aria-live="polite"></div>

<!-- ══ MOBILE APP HEADER ══════════════════════════════════════ -->
<header class="lg:hidden fixed top-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-b border-border" style="height:var(--app-h);">
  <div class="flex items-center gap-2 px-3 h-full">
    <a href="<?= esc_url($dashboard_url) ?>" aria-label="العودة للوحة التحكم" class="w-11 h-11 flex-shrink-0 flex items-center justify-center rounded-xl bg-secondary/60 text-foreground/70">
      <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <div class="flex-1 min-w-0">
      <div class="font-bold text-sm truncate text-foreground"><?= esc_html(get_the_title($event_id)) ?></div>
      <div class="text-[10px] text-foreground/65"><?= esc_html($event_date_label) ?></div>
    </div>
    <a href="<?= esc_url($event_url) ?>" target="_blank" rel="noopener" class="px-3 py-2 rounded-xl bg-primary text-white text-xs font-bold flex-shrink-0">فتح</a>
    <a href="<?= esc_url($edit_url) ?>" aria-label="تعديل المناسبة" class="w-11 h-11 flex-shrink-0 flex items-center justify-center rounded-xl bg-secondary/60 text-foreground/70">
      <svg aria-hidden="true" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    </a>
  </div>
</header>

<!-- ══ HERO (desktop) ═════════════════════════════════════════ -->
<div class="hidden lg:block max-w-7xl mx-auto px-6 pt-8">
  <section class="relative overflow-hidden rounded-[28px] border border-border bg-white p-8 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)]">
    <svg aria-hidden="true" class="pointer-events-none absolute -top-10 -start-10 h-56 w-56 text-gold opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
      <path d="M10 190C40 150 30 90 70 60C100 38 130 45 150 20" stroke-linecap="round"/>
      <circle cx="70" cy="60" r="5"/><circle cx="102" cy="46" r="4"/><circle cx="132" cy="34" r="3.5"/>
      <path d="M70 60c10-6 18-4 24 4M102 46c8-5 16-3 21 4"/>
    </svg>

    <div class="relative flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <span class="inline-flex items-center gap-1.5 rounded-full <?= $manage_is_upcoming ? 'bg-primary/10 text-primary-text ring-primary/20' : 'bg-secondary/70 text-foreground/70 ring-border' ?> ring-1 px-3 py-1 text-xs font-bold">
            <span aria-hidden="true">●</span> <?= esc_html($manage_status_label) ?>
          </span>
          <?php if ($invite_code): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-gold/10 text-gold-text ring-1 ring-gold/20 px-3 py-1 text-xs font-bold font-mono tracking-widest"><?= esc_html($invite_code) ?></span>
          <?php endif; ?>
        </div>
        <h1 class="mt-3 text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl"><?= esc_html(get_the_title($event_id)) ?></h1>
        <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-foreground/70">
          <span class="inline-flex items-center gap-1.5">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4"><rect x="3" y="5" width="18" height="16" rx="3"></rect><path d="M3 10h18M8 3v4M16 3v4"></path></svg>
            <?= esc_html($event_date_label) ?>
          </span>
          <?php if ($event_address_manage !== ''): ?>
            <span class="inline-flex items-center gap-1.5 min-w-0">
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4 flex-shrink-0"><path d="M4 21V8l8-5 8 5v13"/><path d="M9 21v-6h6v6"/></svg>
              <span class="truncate"><?= esc_html($event_address_manage) ?></span>
            </span>
          <?php elseif ($event_location_manage !== ''): ?>
            <a href="<?= esc_url($event_location_manage) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 underline underline-offset-4">
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              الموقع على الخريطة
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex flex-shrink-0 flex-wrap gap-2.5">
        <button type="button" onclick="focusAddGuest()" class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-primary px-5 text-sm font-bold text-white shadow-sm shadow-primary/30 transition-colors duration-200 hover:bg-primary-hover">
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4.5 w-4.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
          إضافة مدعو
        </button>
        <a href="<?= esc_url($edit_url) ?>" class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border-[1.5px] border-gold bg-white px-5 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
          تعديل المناسبة
        </a>
        <a href="<?= esc_url($event_url) ?>" target="_blank" rel="noopener" class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-border bg-white px-5 text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/50">
          عرض الدعوة
        </a>
      </div>
    </div>
  </section>
</div>

<!-- ══ STATS STRIP (mobile: horizontal chips) ═════════════════ -->
<div class="stats-outer max-w-7xl mx-auto lg:hidden">
  <div class="flex gap-2 overflow-x-auto noscroll px-4 py-3">
    <div class="stat-chip bg-secondary/60 border-border text-foreground/70"><span aria-hidden="true">👥</span><span>الكل</span><span class="stat-num"><?= (int)$stats['total'] ?></span></div>
    <div class="stat-chip bg-emerald-50 border-emerald-200 text-emerald-800"><span aria-hidden="true">✅</span><span>سيحضر</span><span class="stat-num"><?= (int)$stats['yes'] ?></span></div>
    <div class="stat-chip bg-destructive/10 border-destructive/20 text-destructive-text"><span aria-hidden="true">❌</span><span>اعتذر</span><span class="stat-num"><?= (int)$stats['no'] ?></span></div>
    <div class="stat-chip bg-gold/10 border-gold/20 text-gold-text"><span aria-hidden="true">⏳</span><span>لم يرد</span><span class="stat-num"><?= (int)$stats['pending'] ?></span></div>
    <div class="stat-chip bg-primary/10 border-primary/20 text-primary-text"><span aria-hidden="true">🏷️</span><span>حضر</span><span class="stat-num"><?= (int)$stats['checked'] ?></span></div>
  </div>
</div>

<!-- ══ STATS KPI CARDS (desktop) + QUICK ACTIONS ══════════════ -->
<div class="hidden lg:block max-w-7xl mx-auto px-6 mt-6 space-y-6">

  <!-- Quick Actions -->
  <section class="rounded-[28px] border border-border bg-white p-6 shadow-sm">
    <h2 class="text-sm font-extrabold text-foreground/70">إجراءات سريعة</h2>
    <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
      <button type="button" onclick="focusAddGuest()" class="flex h-16 flex-col items-center justify-center gap-1 rounded-2xl bg-primary text-sm font-bold text-white transition-colors hover:bg-primary-hover">
        <span aria-hidden="true" class="text-lg">➕</span> إضافة مدعو
      </button>
      <button type="button" onclick="focusBulkImport()" class="flex h-16 flex-col items-center justify-center gap-1 rounded-2xl border-[1.5px] border-gold bg-white text-sm font-bold text-gold-text transition-colors hover:bg-gold/[0.06]">
        <span aria-hidden="true" class="text-lg">📋</span> استيراد المدعوين
      </button>
      <button type="button" onclick="shareInviteLink()" class="flex h-16 flex-col items-center justify-center gap-1 rounded-2xl border border-border bg-white text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/50">
        <span aria-hidden="true" class="text-lg">📤</span> مشاركة الدعوة
      </button>
      <button type="button" onclick="document.getElementById('exportCsvBtn')?.click()" class="flex h-16 flex-col items-center justify-center gap-1 rounded-2xl border border-border bg-white text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/50">
        <span aria-hidden="true" class="text-lg">⬇️</span> تصدير القائمة
      </button>
    </div>
  </section>

  <!-- Statistics -->
  <section>
    <h2 class="px-1 text-sm font-extrabold text-foreground/70">الإحصائيات</h2>
    <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-5">
      <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm">
        <div class="flex items-center gap-2">
          <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-secondary/70 text-foreground/70">👥</span>
          <span class="text-xs font-bold text-foreground/70">إجمالي المدعوين</span>
        </div>
        <div class="mt-2 text-2xl font-extrabold text-foreground"><?= (int)$stats['total'] ?></div>
        <p class="mt-1 text-[11px] text-foreground/65">كل من أُضيف لقائمة هذه المناسبة</p>
      </div>
      <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm">
        <div class="flex items-center gap-2">
          <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">✅</span>
          <span class="text-xs font-bold text-foreground/70">أكد الحضور</span>
        </div>
        <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?= (int)$stats['yes'] ?></div>
        <p class="mt-1 text-[11px] text-foreground/65">ردّوا بـ «سأحضر»</p>
      </div>
      <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm">
        <div class="flex items-center gap-2">
          <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-destructive/10 text-destructive-text">❌</span>
          <span class="text-xs font-bold text-foreground/70">اعتذر</span>
        </div>
        <div class="mt-2 text-2xl font-extrabold text-destructive-text"><?= (int)$stats['no'] ?></div>
        <p class="mt-1 text-[11px] text-foreground/65">ردّوا بالاعتذار عن الحضور</p>
      </div>
      <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm">
        <div class="flex items-center gap-2">
          <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gold/10 text-gold-text">⏳</span>
          <span class="text-xs font-bold text-foreground/70">بانتظار الرد</span>
        </div>
        <div class="mt-2 text-2xl font-extrabold text-gold-text"><?= (int)$stats['pending'] ?></div>
        <p class="mt-1 text-[11px] text-foreground/65">لم يردّوا على الدعوة بعد</p>
      </div>
      <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm">
        <div class="flex items-center gap-2">
          <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">🏷️</span>
          <span class="text-xs font-bold text-foreground/70">تم تسجيل الحضور</span>
        </div>
        <div class="mt-2 text-2xl font-extrabold text-primary-text"><?= (int)$stats['checked'] ?></div>
        <p class="mt-1 text-[11px] text-foreground/65">حضروا فعلياً يوم المناسبة</p>
      </div>
    </div>
  </section>

  <!-- RSVP summary strip -->
  <section class="rounded-3xl border border-border bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-sm font-extrabold text-foreground/70">ملخص الردود (RSVP)</h2>
      <span class="text-xs text-foreground/65"><?= (int)$stats['total'] ?> مدعو إجمالاً</span>
    </div>
    <?php $rsvp_total = max(1, (int)$stats['total']); ?>
    <div class="mt-3 flex h-2.5 w-full overflow-hidden rounded-full bg-secondary/60">
      <div class="h-full bg-emerald-500" style="width: <?= round(((int)$stats['yes'] / $rsvp_total) * 100) ?>%"></div>
      <div class="h-full bg-destructive" style="width: <?= round(((int)$stats['no'] / $rsvp_total) * 100) ?>%"></div>
      <div class="h-full bg-gold" style="width: <?= round(((int)$stats['pending'] / $rsvp_total) * 100) ?>%"></div>
    </div>
    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-[11px] font-semibold text-foreground/60">
      <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2 w-2 rounded-full bg-emerald-500"></span> سيحضر</span>
      <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2 w-2 rounded-full bg-destructive"></span> اعتذر</span>
      <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2 w-2 rounded-full bg-gold"></span> لم يرد</span>
    </div>
  </section>
</div>

<!-- ══ MAIN CONTENT ════════════════════════════════════════════ -->
<div class="pge-main max-w-7xl mx-auto lg:px-6 lg:pb-10 lg:mt-6">

  <!-- ── GUEST SECTION ──────────────────────────────────────── -->
  <div class="min-w-0">

    <h2 class="hidden lg:block px-1 pb-3 text-sm font-extrabold text-foreground/70">إدارة المدعوين</h2>

    <!-- Search + Filter -->
    <div class="sticky z-30 bg-white/95 backdrop-blur-md px-4 lg:px-0 py-2.5 border-b border-border lg:border-0 lg:static lg:bg-transparent" style="top:var(--app-h);">
      <label for="guestSearch" class="sr-only">ابحث بالاسم أو الجوال أو الملاحظة</label>
      <div class="relative">
        <input id="guestSearch" type="search" placeholder="ابحث بالاسم أو الجوال أو الملاحظة..."
          class="h-12 w-full rounded-2xl border border-border bg-white ps-4 pe-11 text-sm text-foreground outline-none transition-shadow focus:border-primary" />
        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </span>
      </div>
      <div class="flex gap-1.5 mt-2.5 overflow-x-auto noscroll pb-0.5" role="group" aria-label="تصفية حسب حالة الرد">
        <button class="status-filter flex-shrink-0 flex h-11 items-center rounded-full bg-primary px-4 text-xs font-bold text-white" data-status="all">الكل</button>
        <button class="status-filter flex-shrink-0 flex h-11 items-center gap-1 rounded-full border border-border bg-white text-foreground/70 px-4 text-xs font-bold" data-status="yes"><span aria-hidden="true">✅</span> سيحضر</button>
        <button class="status-filter flex-shrink-0 flex h-11 items-center gap-1 rounded-full border border-border bg-white text-foreground/70 px-4 text-xs font-bold" data-status="no"><span aria-hidden="true">❌</span> اعتذر</button>
        <button class="status-filter flex-shrink-0 flex h-11 items-center gap-1 rounded-full border border-border bg-white text-foreground/70 px-4 text-xs font-bold" data-status="pending"><span aria-hidden="true">⏳</span> لم يرد</button>
      </div>
    </div>

    <!-- Bulk Action Bar -->
    <div class="flex flex-wrap gap-2 px-4 lg:px-0 py-2.5 items-center">
      <label class="flex items-center gap-2 px-3 h-11 rounded-xl bg-secondary/60 text-xs font-bold cursor-pointer text-foreground/70">
        <input id="selectAllGuests" type="checkbox" class="h-4 w-4 rounded border-border accent-primary" />الكل
      </label>
      <button id="bulkDeleteBtn" type="button" disabled class="h-11 rounded-xl bg-destructive/10 px-3 text-xs font-bold text-destructive-text ring-1 ring-destructive/20 disabled:opacity-40"><span aria-hidden="true">🗑</span> حذف</button>
      <button id="bulkWhatsappBtn" type="button" disabled class="h-11 rounded-xl bg-emerald-600 px-3 text-xs font-bold text-white disabled:opacity-40"><span aria-hidden="true">📲</span> WA</button>
      <button id="whatsappAllBtn" type="button" class="h-11 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-xs font-bold text-emerald-800"><span aria-hidden="true">📲</span> للكل</button>
      <button id="waTestSendBtn" type="button" class="h-11 rounded-xl border border-gold/30 bg-gold/10 px-3 text-xs font-bold text-gold-text"><span aria-hidden="true">🧪</span> تجريبي</button>
      <button id="sendWaInvitesBtn" type="button" class="h-11 rounded-xl bg-primary px-3 text-xs font-bold text-white"><span aria-hidden="true">📨</span> إرسال تلقائي</button>
      <button id="waReportBtn" type="button" class="h-11 rounded-xl border border-border bg-white px-3 text-xs font-bold text-foreground/70"><span aria-hidden="true">📊</span> تقرير</button>
      <button id="exportCsvBtn" type="button" class="h-11 rounded-xl border border-border bg-white px-3 text-xs font-bold text-foreground/70"><span aria-hidden="true">⬇</span> CSV</button>
    </div>

    <!-- Guest Cards -->
    <div id="guestsContainer" class="px-4 lg:px-0 space-y-2.5 pb-4">
      <?php if (empty($guests_map)): ?>
        <div class="rounded-[28px] border border-border bg-white px-6 py-14 text-center">
          <span aria-hidden="true" class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-3xl">👥</span>
          <div class="mt-4 font-extrabold text-foreground">لا يوجد مدعوون بعد</div>
          <p class="mx-auto mt-1.5 max-w-xs text-sm text-foreground/60">ابدأ بإضافة أول مدعو لمناسبتك، أو استورد قائمة أرقام دفعة واحدة.</p>
          <button type="button" onclick="focusAddGuest()" class="mt-5 inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-primary px-6 text-sm font-bold text-white transition-colors hover:bg-primary-hover">
            <span aria-hidden="true">➕</span> إضافة أول مدعو
          </button>
        </div>
      <?php else: ?>
        <?php foreach ($guests_map as $phone => $guest):
          $rd = function_exists('pge_event_guests_get_row_payload')
              ? pge_event_guests_get_row_payload($event_id, $guest)
              : ['phone'=>$phone,'name'=>'','note'=>'','status'=>'pending','status_label'=>'لم يرد','checked'=>'no'];
          $g_name   = (string)($rd['name']   ?? '');
          $g_note   = (string)($rd['note']   ?? '');
          $g_code   = (string)($rd['code']   ?? '');
          $g_status = (string)($rd['status'] ?? 'pending');
          $g_label  = (string)($rd['status_label'] ?? 'لم يرد');
          $g_check  = (string)($rd['checked'] ?? 'no');
          $initial  = mb_substr($g_name ?: $phone, 0, 1, 'UTF-8');
          $avc      = $g_status === 'yes' ? 'av-yes' : ($g_status === 'no' ? 'av-no' : 'av-pending');
          $sbg      = $g_status === 'yes' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                    : ($g_status === 'no'  ? 'bg-destructive/10 text-destructive-text ring-destructive/20'
                    :                        'bg-secondary/60 text-foreground/60 ring-border');
          $status_icon = $g_status === 'yes' ? '✅' : ($g_status === 'no' ? '❌' : '⏳');
        ?>
        <div class="guest-row"
          data-phone="<?= esc_attr($phone) ?>"
          data-name="<?= esc_attr($g_name) ?>"
          data-note="<?= esc_attr($g_note) ?>"
          data-code="<?= esc_attr($g_code) ?>"
          data-status="<?= esc_attr($g_status) ?>"
          data-checked="<?= esc_attr($g_check) ?>">
          <div class="guest-card overflow-hidden">
            <!-- صف علوي: checkbox + avatar + معلومات + حالة -->
            <div class="flex items-center gap-3 p-3 pb-2">
              <label class="flex-shrink-0">
                <span class="sr-only">تحديد <?= $g_name !== '' ? esc_html($g_name) : esc_html($phone) ?></span>
                <input type="checkbox" class="guest-checkbox h-5 w-5 rounded-md border-border accent-primary flex-shrink-0" data-phone="<?= esc_attr($phone) ?>" />
              </label>
              <div class="g-avatar <?= $avc ?> text-base" aria-hidden="true"><?= esc_html($initial) ?></div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-bold text-sm text-foreground leading-tight"><?= $g_name !== '' ? esc_html($g_name) : '<span class="text-foreground/65 font-normal text-xs">بدون اسم</span>' ?></span>
                  <span class="text-[10px] font-bold rounded-full px-2 py-0.5 ring-1 <?= $sbg ?>"><span aria-hidden="true"><?= $status_icon ?></span> <?= esc_html($g_label) ?><?= $g_check === 'yes' ? ' <span aria-hidden="true">🏷️</span><span class="sr-only">تم تسجيل الحضور</span>' : '' ?></span>
                </div>
                <div class="text-xs text-foreground/65 font-mono mt-0.5" dir="ltr"><?= esc_html($phone) ?></div>
              </div>
            </div>
            <!-- صف أسفل: رمز الدعوة + أزرار الإجراءات -->
            <div class="flex items-center justify-between px-3 pb-3 gap-2">
              <div class="flex items-center gap-1.5 min-w-0">
                <?php if ($g_code !== ''): ?>
                <span class="guest-code-display font-mono text-[11px] font-bold tracking-widest text-gold-text bg-gold/10 px-2 py-1 rounded-lg ring-1 ring-gold/20 whitespace-nowrap"><?= esc_html($g_code) ?></span>
                <button type="button" class="guest-copy-code-btn w-11 h-11 rounded-lg bg-secondary/60 flex items-center justify-center text-sm" data-code="<?= esc_attr($g_code) ?>" aria-label="نسخ رمز الدعوة">📋</button>
                <button type="button" class="guest-regen-code-btn w-11 h-11 rounded-lg bg-secondary/60 flex items-center justify-center text-sm" data-phone="<?= esc_attr($phone) ?>" aria-label="توليد رمز جديد">🔄</button>
                <?php else: ?>
                <button type="button" class="guest-regen-code-btn text-[11px] text-primary-text underline font-semibold" data-phone="<?= esc_attr($phone) ?>">+ توليد رمز</button>
                <?php endif; ?>
                <?php if ($g_note !== ''): ?>
                <span class="text-[10px] text-foreground/65 truncate">· <?= esc_html($g_note) ?></span>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <button type="button" class="guest-wa-btn h-11 px-3 rounded-xl bg-emerald-600 flex items-center gap-1 text-white text-xs font-bold" data-phone="<?= esc_attr($phone) ?>" data-name="<?= esc_attr($g_name) ?>"><span aria-hidden="true">📱</span><span class="hidden sm:inline">واتساب</span></button>
                <button type="button" class="guest-edit-btn w-11 h-11 rounded-xl border border-border bg-white flex items-center justify-center text-foreground/60" data-phone="<?= esc_attr($phone) ?>" aria-label="تعديل بيانات المدعو">✏️</button>
                <button type="button" class="guest-delete-btn w-11 h-11 rounded-xl bg-destructive/10 flex items-center justify-center text-destructive-text" data-phone="<?= esc_attr($phone) ?>" aria-label="حذف المدعو">🗑️</button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div><!-- /guest section -->

  <!-- ── ACTIONS PANEL (mobile sheet / desktop sidebar) ──────── -->
  <div id="actionsPanel">
    <div class="sheet-bar"></div>

    <!-- Panel Header (mobile only) -->
    <div class="panel-header flex items-center justify-between px-5 pt-1 pb-3 border-b border-border sticky top-0 bg-white z-10">
      <div class="font-bold text-sm" id="panelTitle">إضافة مدعو</div>
      <button id="closePanelBtn" aria-label="إغلاق" class="w-11 h-11 rounded-full bg-secondary/60 flex items-center justify-center text-foreground/70 text-lg font-bold">×</button>
    </div>

    <div class="p-4 space-y-4">

      <!-- ملخص المناسبة (سايدبار الديسكتوب) -->
      <div class="hidden lg:block rounded-[24px] border border-border bg-white p-5 lg:sticky lg:top-6">
        <h3 class="text-sm font-extrabold text-foreground/70">ملخص المناسبة</h3>
        <div class="mt-3 space-y-2 text-sm">
          <div class="flex items-center justify-between gap-2">
            <span class="text-foreground/60">التاريخ</span>
            <span class="font-semibold text-foreground text-left"><?= esc_html($event_date_label) ?></span>
          </div>
          <?php if ($invite_code): ?>
          <div class="flex items-center justify-between gap-2">
            <span class="text-foreground/60">رمز الدعوة</span>
            <span class="font-mono font-bold tracking-widest text-gold-text"><?= esc_html($invite_code) ?></span>
          </div>
          <?php endif; ?>
          <div class="flex items-center justify-between gap-2">
            <span class="text-foreground/60">الحالة</span>
            <span class="font-semibold text-foreground"><?= esc_html($manage_status_label) ?></span>
          </div>
        </div>

        <div class="mt-4 border-t border-gold/30"></div>

        <h3 class="mt-4 text-sm font-extrabold text-foreground/70">ملخص الباقة</h3>
        <div class="mt-3 flex items-center justify-between gap-2">
          <span class="text-xs font-semibold text-foreground/65">الباقة الحالية</span>
          <span class="truncate rounded-full bg-secondary/60 px-3 py-1 text-xs font-bold text-foreground ring-1 ring-border"><?= esc_html($manage_plan_name) ?></span>
        </div>
        <?php if ($manage_events_limit > 0): ?>
        <div class="mt-2 flex items-center justify-between gap-2">
          <span class="text-xs font-semibold text-foreground/65">المناسبات المتبقية</span>
          <span class="text-sm font-extrabold text-foreground"><?= (int)$manage_events_left ?> <span class="text-xs font-semibold text-foreground/65">/ <?= (int)$manage_events_limit ?></span></span>
        </div>
        <?php endif; ?>

        <div class="mt-4 border-t border-gold/30"></div>

        <h3 class="mt-4 text-sm font-extrabold text-foreground/70">روابط سريعة</h3>
        <div class="mt-3 flex flex-col gap-2">
          <a href="<?= esc_url($dashboard_url) ?>" class="flex h-11 items-center justify-center rounded-xl border border-border bg-white text-xs font-bold text-foreground/70 hover:bg-secondary/50">لوحة التحكم</a>
          <a href="<?= esc_url(home_url('/packages/')) ?>" class="flex h-11 items-center justify-center rounded-xl border-[1.5px] border-gold bg-white text-xs font-bold text-gold-text hover:bg-gold/[0.06]">إدارة الباقة</a>
        </div>
      </div>

      <!-- تعديل مدعو -->
      <div id="editGuestCard" class="hidden rounded-2xl border border-primary/20 bg-primary/5 p-4">
        <h3 class="font-bold text-sm text-primary-text mb-3"><span aria-hidden="true">✏️</span> تعديل المدعو</h3>
        <form id="editGuestForm" class="space-y-2.5">
          <input type="hidden" id="editOldPhone" name="old_phone" />
          <label for="editGuestName" class="sr-only">الاسم</label>
          <input id="editGuestName" name="name" type="text" placeholder="الاسم"
            class="h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none focus:border-primary" />
          <label for="editGuestPhone" class="sr-only">رقم الجوال</label>
          <input id="editGuestPhone" name="phone" type="tel" inputmode="tel" placeholder="رقم الجوال" required
            class="h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none focus:border-primary" />
          <label for="editGuestNote" class="sr-only">ملاحظة</label>
          <input id="editGuestNote" name="note" type="text" placeholder="ملاحظة"
            class="h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none focus:border-primary" />
          <div class="flex gap-2">
            <button type="submit" class="flex-1 h-12 rounded-2xl bg-primary text-sm font-bold text-white hover:bg-primary-hover">حفظ</button>
            <button id="cancelEditGuestBtn" type="button" class="h-12 px-4 rounded-2xl border border-border bg-white text-sm font-semibold text-foreground/70">إلغاء</button>
          </div>
        </form>
      </div>

      <!-- إضافة مدعو -->
      <div class="rounded-2xl border border-border bg-white p-4" id="addSection">
        <h3 class="text-base font-bold text-foreground mb-3"><span aria-hidden="true">➕</span> إضافة مدعو</h3>
        <form id="addGuestForm" class="space-y-2.5">
          <label for="addGuestName" class="sr-only">الاسم (اختياري)</label>
          <input id="addGuestName" name="name" type="text" placeholder="الاسم (اختياري)"
            class="h-12 w-full rounded-2xl border border-border px-4 text-sm outline-none focus:border-primary" />
          <label for="addGuestPhone" class="sr-only">رقم الجوال</label>
          <input id="addGuestPhone" name="phone" type="tel" inputmode="tel" placeholder="رقم الجوال" required
            class="h-12 w-full rounded-2xl border border-border px-4 text-sm outline-none focus:border-primary" />
          <label for="addGuestNote" class="sr-only">ملاحظة (اختياري)</label>
          <input id="addGuestNote" name="note" type="text" placeholder="ملاحظة (اختياري)"
            class="h-12 w-full rounded-2xl border border-border px-4 text-sm outline-none focus:border-primary" />
          <button type="submit" class="w-full h-12 rounded-2xl bg-primary text-sm font-bold text-white hover:bg-primary-hover">إضافة المدعو</button>
        </form>
      </div>

      <!-- إضافة جماعية -->
      <div class="rounded-2xl border border-border bg-white p-4" id="bulkImportSection">
        <h3 class="text-base font-bold text-foreground mb-1"><span aria-hidden="true">📋</span> إضافة جماعية</h3>
        <p class="text-xs text-foreground/60 mb-3">ضع كل رقم في سطر منفصل.</p>
        <form id="bulkGuestForm" class="space-y-2.5">
          <label for="bulkPhones" class="sr-only">أرقام الجوال</label>
          <textarea id="bulkPhones" name="phones_text" rows="5"
            class="w-full rounded-2xl border border-border px-4 py-3 text-sm outline-none focus:border-primary"
            placeholder="05XXXXXXXX&#10;9665XXXXXXXX"></textarea>
          <button type="submit" class="w-full h-12 rounded-2xl bg-emerald-600 text-sm font-bold text-white">إضافة الأرقام</button>
        </form>
      </div>

      <!-- رسائل واتساب التلقائية -->
      <details class="rounded-2xl border border-emerald-200 bg-white" id="templatesSection">
        <summary class="flex items-center justify-between px-4 py-3.5 cursor-pointer select-none list-none">
          <span class="font-bold text-sm text-foreground"><span aria-hidden="true">📝</span> رسائل واتساب التلقائية</span>
          <span class="text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 px-2 py-0.5">تخصيص</span>
        </summary>
        <div class="border-t border-border p-4 space-y-4">
          <p class="text-xs text-foreground/60">اتركها فارغة لاستخدام النص الافتراضي.</p>
          <?php
          $wa_tpl_fields = [
            'invite'  => ['label' => '📨 رسالة الدعوة', 'vars' => ['guest_name','event_name','event_date','event_date_line','guest_phone'], 'val' => $wa_tpl_invite],
            'yes'     => ['label' => '✅ رد الحضور',    'vars' => ['event_name','event_url','invite_code','guest_phone'], 'val' => $wa_tpl_yes],
            'no'      => ['label' => '❌ رد الاعتذار',  'vars' => ['event_name'], 'val' => $wa_tpl_no],
            'invalid' => ['label' => '❓ رد غير معروف', 'vars' => [], 'val' => $wa_tpl_invalid],
          ];
          foreach ($wa_tpl_fields as $tpl_key => $tpl_info): ?>
          <div>
            <label for="wa_tpl_<?= esc_attr($tpl_key) ?>" class="text-xs font-bold text-foreground/80 block mb-1"><?= esc_html($tpl_info['label']) ?></label>
            <?php if ($tpl_info['vars']): ?>
            <div class="flex flex-wrap gap-1 mb-1.5">
              <?php foreach ($tpl_info['vars'] as $v): ?>
              <button type="button" class="wa-var-insert rounded px-1.5 py-0.5 text-[9px] font-mono bg-secondary/60 text-foreground/60 hover:bg-emerald-100 hover:text-emerald-700 ring-1 ring-border"
                data-var="{{<?= $v ?>}}" data-target="wa_tpl_<?= esc_attr($tpl_key) ?>">{{<?= $v ?>}}</button>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <textarea id="wa_tpl_<?= esc_attr($tpl_key) ?>" name="tpl_<?= esc_attr($tpl_key) ?>" rows="3"
              placeholder="اتركه فارغاً للنص الافتراضي"
              class="w-full rounded-xl border border-border px-3 py-2 text-xs font-mono outline-none focus:border-emerald-400 resize-y"
            ><?= esc_textarea($tpl_info['val']) ?></textarea>
          </div>
          <?php endforeach; ?>
          <div id="waTplMsg" class="hidden text-xs font-semibold rounded-xl px-3 py-2"></div>
          <button id="saveWaTplBtn" class="w-full h-11 rounded-2xl bg-emerald-600 text-sm font-bold text-white"><span aria-hidden="true">💾</span> حفظ القوالب</button>
          <button id="resetWaTplBtn" class="w-full h-10 rounded-2xl border border-border text-xs font-semibold text-foreground/60 mt-1"><span aria-hidden="true">↩️</span> استعادة الافتراضي</button>
        </div>
      </details>

      <!-- قالب الإرسال اليدوي -->
      <details class="rounded-2xl border border-border bg-white">
        <summary class="flex items-center justify-between px-4 py-3.5 cursor-pointer select-none list-none">
          <span class="font-bold text-sm text-foreground"><span aria-hidden="true">✏️</span> قالب الإرسال اليدوي</span>
          <span class="text-[10px] font-bold rounded-full bg-secondary/60 text-foreground/60 ring-1 ring-border px-2 py-0.5">Template</span>
        </summary>
        <div class="border-t border-border p-4 space-y-3">
          <label for="whatsappTemplateInput" class="sr-only">قالب رسالة الدعوة</label>
          <textarea id="whatsappTemplateInput" rows="6"
            class="w-full rounded-2xl border border-border px-4 py-3 text-sm outline-none focus:border-primary"
            placeholder="نص رسالة الدعوة..."></textarea>
          <div class="flex flex-wrap gap-1.5">
            <?php foreach (['guest_name','event_title','event_url','image_url','invite_code','guest_phone'] as $var): ?>
            <span class="cursor-pointer rounded-full bg-secondary/60 px-2 py-1 ring-1 ring-border text-[10px] font-mono text-foreground/60 hover:bg-secondary"
              onclick="navigator.clipboard.writeText('{{<?= $var ?>}}')">{{<?= $var ?>}}</span>
            <?php endforeach; ?>
          </div>
          <div class="flex gap-2">
            <button id="resetWhatsappTemplateBtn" class="flex-1 h-10 rounded-2xl border border-border text-xs font-semibold text-foreground/70">استعادة الافتراضي</button>
            <button id="copyWhatsappPreviewBtn" class="flex-1 h-10 rounded-2xl border border-border text-xs font-semibold text-foreground/70">نسخ المعاينة</button>
          </div>
          <div class="rounded-xl bg-secondary/40 p-3 ring-1 ring-border">
            <div class="text-[10px] font-semibold text-foreground/65 mb-1">معاينة</div>
            <pre id="whatsappPreviewText" class="whitespace-pre-wrap break-words font-sans text-xs leading-6 text-foreground/70"></pre>
          </div>
        </div>
      </details>

    </div><!-- /panel inner -->
  </div><!-- /actionsPanel -->

</div><!-- /pge-main -->

<!-- ══ Panel Overlay ═══════════════════════════════════════════ -->
<div id="overlay"></div>

<!-- ══ WA SEND QUICK PANEL (mobile) ═══════════════════════════ -->
<div id="waSendPanel" class="lg:hidden">
  <div class="sheet-bar"></div>
  <div class="px-5 pb-6 pt-2 space-y-3">
    <h3 class="font-bold text-base text-center text-foreground"><span aria-hidden="true">📱</span> إرسال واتساب</h3>
    <div class="grid grid-cols-2 gap-3">
      <button onclick="triggerAndClose('sendWaInvitesBtn')" class="h-16 rounded-2xl bg-primary text-white font-bold text-sm flex flex-col items-center justify-center gap-1">
        <span aria-hidden="true" class="text-2xl">📨</span><span>إرسال تلقائي</span>
      </button>
      <button onclick="triggerAndClose('waTestSendBtn')" class="h-16 rounded-2xl border border-gold/30 bg-gold/10 text-gold-text font-bold text-sm flex flex-col items-center justify-center gap-1">
        <span aria-hidden="true" class="text-2xl">🧪</span><span>إرسال تجريبي</span>
      </button>
      <button onclick="triggerAndClose('whatsappAllBtn')" class="h-16 rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 font-bold text-sm flex flex-col items-center justify-center gap-1">
        <span aria-hidden="true" class="text-2xl">📲</span><span>واتساب للكل</span>
      </button>
      <button onclick="triggerAndClose('waReportBtn')" class="h-16 rounded-2xl border border-border bg-secondary/40 text-foreground/70 font-bold text-sm flex flex-col items-center justify-center gap-1">
        <span aria-hidden="true" class="text-2xl">📊</span><span>التقرير</span>
      </button>
    </div>
  </div>
</div>

<!-- ══ WA Test Modal ════════════════════════════════════════════ -->
<div id="waTestModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" dir="rtl">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md p-6 space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-lg font-extrabold text-foreground"><span aria-hidden="true">🧪</span> إرسال رسالة تجريبية</h3>
      <button id="waTestModalClose" aria-label="إغلاق" class="w-11 h-11 rounded-full bg-secondary/60 flex items-center justify-center text-foreground/70 text-xl font-bold">×</button>
    </div>
    <p class="text-sm text-foreground/65">اختبر الدعوة قبل الإرسال الجماعي. لن يُسجَّل RSVP.</p>
    <div class="space-y-3">
      <label for="waTestPhone" class="sr-only">رقم الجوال</label>
      <input id="waTestPhone" type="tel" inputmode="tel" placeholder="رقم الجوال"
        class="h-12 w-full rounded-2xl border border-border px-4 text-sm outline-none focus:border-primary" />
      <label for="waTestName" class="sr-only">اسم تجريبي</label>
      <input id="waTestName" type="text" placeholder="اسم تجريبي (اختياري)" value="ضيف تجريبي"
        class="h-12 w-full rounded-2xl border border-border px-4 text-sm outline-none focus:border-primary" />
    </div>
    <div id="waTestResult" class="hidden text-sm font-semibold rounded-2xl p-3"></div>
    <div class="flex gap-3">
      <button id="waTestSendConfirmBtn" class="flex-1 h-12 rounded-2xl bg-primary text-sm font-bold text-white transition-colors hover:bg-primary-hover">📨 إرسال التجربة</button>
      <button id="waTestModalClose2" class="h-12 px-4 rounded-2xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
    </div>
  </div>
</div>

<!-- ══ MOBILE BOTTOM NAV ════════════════════════════════════════ -->
<nav class="bnav lg:hidden" aria-label="التنقل السريع">
  <button class="bnav-item active" id="navGuests">
    <span class="bnav-icon" aria-hidden="true">👥</span><span>الضيوف</span>
  </button>
  <button class="bnav-item" id="navAdd">
    <span class="bnav-icon" aria-hidden="true">➕</span><span>إضافة</span>
  </button>
  <button class="bnav-item" id="navSend">
    <span class="bnav-icon" aria-hidden="true">📱</span><span>إرسال</span>
  </button>
  <button class="bnav-item" id="navMore">
    <span class="bnav-icon" aria-hidden="true">⚙️</span><span>أكثر</span>
  </button>
</nav>

</div><!-- /dir=rtl -->

<script>
window.PGE_EVENT_MANAGE = {
    ajax:       "<?= esc_js(admin_url('admin-ajax.php')) ?>",
    nonce:      "<?= esc_js($manage_nonce) ?>",
    eventId:    "<?= (int) $event_id ?>",
    eventUrl:   "<?= esc_js($event_url) ?>",
    eventTitle: "<?= esc_js(get_the_title($event_id)) ?>",
    eventImage: "<?= esc_js($event_image_url) ?>"
};
</script>
<script>
const cfg = window.PGE_EVENT_MANAGE || {};

// ── Toast ─────────────────────────────────────────────────────────
const toastEl = document.getElementById('toast');
let toastTimer;
function showMsg(type, text) {
    if (!toastEl) return;
    clearTimeout(toastTimer);
    const colors = { success:'#059669', info:'var(--color-primary)', error:'var(--color-destructive-text)' };
    toastEl.style.background = colors[type] || colors.error;
    toastEl.style.color = '#fff';
    toastEl.textContent = text;
    toastEl.classList.add('show');
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3500);
}

// ── Panel ─────────────────────────────────────────────────────────
const panel    = document.getElementById('actionsPanel');
const overlay  = document.getElementById('overlay');
const panelTitleEl = document.getElementById('panelTitle');
const isMobile = () => window.innerWidth < 1024;

function openPanel(section) {
    if (!isMobile()) return;
    panel.classList.add('open');
    overlay.classList.add('show');
    setNavActive(section === 'add' ? 'navAdd' : 'navMore');

    const targets = { add:'addSection', edit:'editGuestCard', templates:'templatesSection', bulk:'bulkImportSection' };
    const titles  = { add:'إضافة مدعو', edit:'تعديل المدعو', templates:'رسائل واتساب', bulk:'إضافة جماعية' };

    if (panelTitleEl) panelTitleEl.textContent = titles[section] || 'الإعدادات';

    setTimeout(() => {
        const el = document.getElementById(targets[section]);
        if (el) el.scrollIntoView({ block: 'nearest' });
        if (section === 'add') document.getElementById('addGuestPhone')?.focus();
    }, 380);
}

function closePanel() {
    panel.classList.remove('open');
    overlay.classList.remove('show');
    setNavActive('navGuests');
}

// ── Quick Actions glue (UI-only helpers, no business logic) ───────
function focusAddGuest() {
    if (isMobile()) { openPanel('add'); return; }
    document.getElementById('addSection')?.scrollIntoView({ behavior:'smooth', block:'start' });
    document.getElementById('addGuestPhone')?.focus();
}
function focusBulkImport() {
    if (isMobile()) { openPanel('bulk'); return; }
    document.getElementById('bulkImportSection')?.scrollIntoView({ behavior:'smooth', block:'start' });
    document.getElementById('bulkPhones')?.focus();
}
function shareInviteLink() {
    const url = (cfg.eventUrl || '').toString();
    if (!url) { showMsg('error','رابط الدعوة غير متوفر.'); return; }
    if (navigator.share) {
        navigator.share({ title: cfg.eventTitle || '', url }).catch(() => {});
        return;
    }
    navigator.clipboard?.writeText(url).then(() => showMsg('success','تم نسخ رابط الدعوة.'))
        .catch(() => showMsg('error','تعذر نسخ الرابط.'));
}

// WA Send Panel
const waSendPanel = document.getElementById('waSendPanel');
function openWaPanel()  { if (waSendPanel && isMobile()) waSendPanel.classList.add('open'); }
function closeWaPanel() { if (waSendPanel) waSendPanel.classList.remove('open'); }

function triggerAndClose(btnId) {
    closeWaPanel();
    document.getElementById(btnId)?.click();
}

// Overlay closes both panels
overlay?.addEventListener('click', () => { closePanel(); closeWaPanel(); });
document.getElementById('closePanelBtn')?.addEventListener('click', closePanel);

// Bottom Nav
function setNavActive(id) {
    document.querySelectorAll('.bnav-item').forEach(b => b.classList.remove('active'));
    document.getElementById(id)?.classList.add('active');
}
document.getElementById('navGuests')?.addEventListener('click', () => { closePanel(); closeWaPanel(); setNavActive('navGuests'); });
document.getElementById('navAdd')?.addEventListener('click', () => openPanel('add'));
document.getElementById('navSend')?.addEventListener('click', () => { setNavActive('navSend'); openWaPanel(); });
document.getElementById('navMore')?.addEventListener('click', () => openPanel('templates'));

// Auto-open panel when editCard becomes visible (mobile)
const editCard = document.getElementById('editGuestCard');
if (editCard) {
    new MutationObserver(() => {
        if (!editCard.classList.contains('hidden') && isMobile()) openPanel('edit');
    }).observe(editCard, { attributes: true, attributeFilter: ['class'] });
}

// ── Helpers ───────────────────────────────────────────────────────
const addForm   = document.getElementById('addGuestForm');
const bulkForm  = document.getElementById('bulkGuestForm');
const editForm  = document.getElementById('editGuestForm');
const cancelEditBtn   = document.getElementById('cancelEditGuestBtn');
const bulkDeleteBtn   = document.getElementById('bulkDeleteBtn');
const bulkWhatsappBtn = document.getElementById('bulkWhatsappBtn');
const whatsappAllBtn  = document.getElementById('whatsappAllBtn');
const exportCsvBtn    = document.getElementById('exportCsvBtn');
const selectAllGuests = document.getElementById('selectAllGuests');
const searchInput     = document.getElementById('guestSearch');
const statusFilterBtns= document.querySelectorAll('.status-filter');
const whatsappTemplateInput   = document.getElementById('whatsappTemplateInput');
const resetWhatsappTemplateBtn= document.getElementById('resetWhatsappTemplateBtn');
const copyWhatsappPreviewBtn  = document.getElementById('copyWhatsappPreviewBtn');
const whatsappPreviewText     = document.getElementById('whatsappPreviewText');
const whatsappTemplateStorageKey = cfg.eventId ? `pge_whatsapp_template_${cfg.eventId}` : 'pge_whatsapp_template';
const inviteCodeInput = null;
let activeStatus = 'all';

function normPhone(v) { return (v || '').toString().replace(/\D+/g, ''); }
function getRows()    { return Array.from(document.querySelectorAll('.guest-row')); }
function getSelectedPhones() {
    return Array.from(document.querySelectorAll('.guest-checkbox:checked'))
        .map(el => normPhone(el.dataset.phone || '')).filter(Boolean);
}
function refreshBulkDeleteState() {
    const has = getSelectedPhones().length > 0;
    if (bulkDeleteBtn)   bulkDeleteBtn.disabled   = !has;
    if (bulkWhatsappBtn) bulkWhatsappBtn.disabled  = !has;
}
function applyFilters() {
    const q = (searchInput?.value || '').toLowerCase().trim();
    getRows().forEach(row => {
        const text    = `${row.dataset.name||''} ${row.dataset.phone||''} ${row.dataset.note||''}`.toLowerCase();
        const statusOk= activeStatus === 'all' || (row.dataset.status || 'pending') === activeStatus;
        const queryOk = q === '' || text.includes(q);
        row.style.display = (statusOk && queryOk) ? '' : 'none';
    });
}
function normalizeInviteCode(v) {
    const c = (v||'').toString().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,8);
    return c.length > 4 ? `${c.slice(0,4)}-${c.slice(4)}` : c;
}
function normalizeWhatsappPhone(phone) {
    let d = normPhone(phone);
    if (!d) return '';
    if (d.startsWith('00')) d = d.slice(2);
    if (d.startsWith('0') && d.length === 10 && d[1] === '5') d = `966${d.slice(1)}`;
    else if (d.length === 9 && d.startsWith('5')) d = `966${d}`;
    return (d.length < 8 || d.length > 15) ? '' : d;
}
function getDefaultWhatsappTemplate() {
    return ['مرحباً *{{guest_name}}* 👋','','يسعدنا دعوتك لحضور:','✨ *{{event_title}}*','','🔗 رابط الدعوة:','{{event_url}}','','🔑 رمز الدعوة: *{{invite_code}}*','📱 رقمك المسجل: *{{guest_phone}}*','','━━━━━━━━━━━━━━━','للرد على الدعوة أرسل:','✅ *1* — سأحضر بإذن الله','❌ *2* — لن أتمكن من الحضور'].join('\n');
}
function getWhatsappTemplateValue() {
    return ((whatsappTemplateInput ? whatsappTemplateInput.value : '') || '').trim() || getDefaultWhatsappTemplate();
}
function renderWhatsappTemplate(template, ctx) {
    let out = (template || '').toString();
    Object.entries(ctx).forEach(([k,v]) => {
        out = out.replace(new RegExp(`\\{\\{\\s*${k}\\s*\\}\\}`, 'g'), v == null ? '' : String(v));
        out = out.replace(new RegExp(`\\{\\s*${k}\\s*\\}`, 'g'), v == null ? '' : String(v));
    });
    return out;
}
function buildInviteMessage(name, phone, guestCode='') {
    const code = normalizeInviteCode(guestCode);
    const link = (cfg.eventUrl||'').toString().trim();
    if (!code || !link) return '';
    return renderWhatsappTemplate(getWhatsappTemplateValue(), {
        guest_name: (name||'').trim() || 'ضيفنا الكريم',
        event_title: (cfg.eventTitle||'').trim() || 'مناسبتنا',
        event_url:  link,
        image_url:  (cfg.eventImage||link).toString().trim(),
        invite_code:code,
        guest_phone:normPhone(phone)||''
    }).trim();
}
function updateWhatsappPreview() {
    if (!whatsappPreviewText) return;
    const firstRow = getRows()[0];
    const nm  = firstRow ? ((firstRow.dataset.name||'').trim()||'ضيفنا الكريم') : 'ضيفنا الكريم';
    const ph  = firstRow ? normPhone(firstRow.dataset.phone||'') : '05XXXXXXXX';
    const cd  = firstRow ? (firstRow.dataset.code||'') : '';
    const out = buildInviteMessage(nm, ph, cd);
    whatsappPreviewText.textContent = out || 'احفظ رمز الدعوة أولاً لإظهار المعاينة.';
}
function getWhatsappUrl(phone, name, guestCode='', silent=false) {
    const code = normalizeInviteCode(guestCode);
    if (!code) { if (!silent) showMsg('error','هذا الضيف ليس له رمز دعوة — اضغط 🔄 لتوليد رمز.'); return ''; }
    const waPhone = normalizeWhatsappPhone(phone);
    if (!waPhone) { if (!silent) showMsg('error','رقم الجوال غير صالح لواتساب.'); return ''; }
    const text = buildInviteMessage(name, phone, guestCode);
    if (!text) { if (!silent) showMsg('error','تعذر تجهيز نص الدعوة.'); return ''; }
    return `https://wa.me/${waPhone}?text=${encodeURIComponent(text)}`;
}
function openWhatsappInvite(phone, name, guestCode='', silent=false) {
    const url = getWhatsappUrl(phone, name, guestCode, silent);
    if (!url) return false;
    const win = window.open(url, '_blank', 'noopener');
    if (!win && !silent) showMsg('error','المتصفح منع فتح واتساب. اسمح بفتح النوافذ.');
    return !!win;
}

async function postAction(action, payload={}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', cfg.nonce||'');
    fd.append('event_id', cfg.eventId||'');
    Object.entries(payload).forEach(([k,v]) => {
        if (Array.isArray(v)) v.forEach(i => fd.append(`${k}[]`, i));
        else fd.append(k, v == null ? '' : String(v));
    });
    const res = await fetch(cfg.ajax, { method:'POST', body:fd });
    return res.json();
}
function reloadSoon() { setTimeout(() => location.reload(), 350); }

// ── Filters ────────────────────────────────────────────────────────
statusFilterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        activeStatus = btn.dataset.status || 'all';
        statusFilterBtns.forEach(b => {
            b.classList.remove('bg-primary','text-white');
            b.classList.add('border','border-border','bg-white','text-foreground/70');
        });
        btn.classList.add('bg-primary','text-white');
        btn.classList.remove('border','border-border','bg-white','text-foreground/70');
        applyFilters();
    });
});
if (searchInput) searchInput.addEventListener('input', applyFilters);
document.addEventListener('change', e => { if (e.target?.classList.contains('guest-checkbox')) refreshBulkDeleteState(); });
if (selectAllGuests) {
    selectAllGuests.addEventListener('change', () => {
        const checked = !!selectAllGuests.checked;
        document.querySelectorAll('.guest-checkbox').forEach(el => { el.checked = checked; });
        refreshBulkDeleteState();
    });
}

// ── WA Test Modal ─────────────────────────────────────────────────
const waTestModal = document.getElementById('waTestModal');
const waTestResult= document.getElementById('waTestResult');
const waTestPhone = document.getElementById('waTestPhone');
const waTestName  = document.getElementById('waTestName');
function openTestModal()  { waTestResult?.classList.add('hidden'); waTestModal?.classList.remove('hidden'); waTestPhone?.focus(); }
function closeTestModal() { waTestModal?.classList.add('hidden'); }
document.getElementById('waTestSendBtn')?.addEventListener('click', openTestModal);
document.getElementById('waTestModalClose')?.addEventListener('click', closeTestModal);
document.getElementById('waTestModalClose2')?.addEventListener('click', closeTestModal);
waTestModal?.addEventListener('click', e => { if (e.target === waTestModal) closeTestModal(); });
function showTestResult(ok, text) {
    if (!waTestResult) return;
    waTestResult.classList.remove('hidden','bg-emerald-50','text-emerald-800','bg-destructive/10','text-destructive-text');
    waTestResult.classList.add(ok?'bg-emerald-50':'bg-destructive/10', ok?'text-emerald-800':'text-destructive-text');
    waTestResult.textContent = text;
}
document.getElementById('waTestSendConfirmBtn')?.addEventListener('click', async () => {
    const phone = (waTestPhone?.value||'').trim();
    const name  = (waTestName?.value||'').trim() || 'ضيف تجريبي';
    if (!phone) { showTestResult(false,'⚠️ أدخل رقم الجوال أولاً'); return; }
    const btn = document.getElementById('waTestSendConfirmBtn');
    btn.disabled = true; btn.textContent = '⏳ جاري الإرسال...';
    try {
        const fd = new FormData();
        fd.append('action','pge_wa_test_send'); fd.append('nonce',cfg.nonce);
        fd.append('event_id',cfg.eventId); fd.append('test_phone',phone); fd.append('test_name',name);
        const json = await (await fetch(cfg.ajax,{method:'POST',body:fd})).json();
        showTestResult(json.success, json.success ? (json.data?.message||'✅ تم الإرسال!') : '❌ '+(json.data?.message||JSON.stringify(json.data)));
    } catch(err) { showTestResult(false,'❌ خطأ: '+err.message); }
    finally { btn.disabled=false; btn.textContent='📨 إرسال التجربة'; }
});

// ── WA Templates ──────────────────────────────────────────────────
document.querySelectorAll('.wa-var-insert').forEach(btn => {
    btn.addEventListener('click', () => {
        const ta = document.getElementById(btn.dataset.target);
        if (!ta) return;
        const s = ta.selectionStart ?? ta.value.length;
        const e = ta.selectionEnd   ?? ta.value.length;
        ta.value = ta.value.slice(0,s) + btn.dataset.var + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + btn.dataset.var.length;
        ta.focus();
    });
});
const saveWaTplBtn = document.getElementById('saveWaTplBtn');
const waTplMsg     = document.getElementById('waTplMsg');
function showWaTplMsg(ok, text) {
    if (!waTplMsg) return;
    waTplMsg.classList.remove('hidden','bg-emerald-50','text-emerald-800','bg-destructive/10','text-destructive-text');
    waTplMsg.classList.add(ok?'bg-emerald-50':'bg-destructive/10', ok?'text-emerald-800':'text-destructive-text');
    waTplMsg.textContent = text;
    waTplMsg.classList.remove('hidden');
    setTimeout(() => waTplMsg.classList.add('hidden'), 3000);
}
if (saveWaTplBtn) {
    saveWaTplBtn.addEventListener('click', async () => {
        saveWaTplBtn.disabled = true; saveWaTplBtn.textContent = '⏳ جاري الحفظ...';
        const fd = new FormData();
        fd.append('action','pge_event_save_wa_templates'); fd.append('nonce',cfg.nonce); fd.append('event_id',cfg.eventId);
        ['invite','yes','no','invalid'].forEach(k => { const ta = document.getElementById('wa_tpl_'+k); fd.append('tpl_'+k, ta?ta.value:''); });
        try { const json = await (await fetch(cfg.ajax,{method:'POST',body:fd})).json(); showWaTplMsg(json.success, json.success?(json.data?.message||'✅ تم الحفظ'):'❌ تعذر الحفظ'); }
        catch { showWaTplMsg(false,'❌ خطأ في الاتصال'); }
        finally { saveWaTplBtn.disabled=false; saveWaTplBtn.textContent='💾 حفظ القوالب'; }
    });
}
document.getElementById('resetWaTplBtn')?.addEventListener('click', () => {
    if (!confirm('هل تريد مسح كل القوالب وإعادة النص الافتراضي؟')) return;
    ['invite','yes','no','invalid'].forEach(k => { const ta=document.getElementById('wa_tpl_'+k); if(ta) ta.value=''; });
    showWaTplMsg(true,'سيتم استخدام النص الافتراضي عند الحفظ التالي');
});

// ── WA Manual Template ────────────────────────────────────────────
if (whatsappTemplateInput) {
    let tmpl = '';
    try { tmpl = (window.localStorage && localStorage.getItem(whatsappTemplateStorageKey)) || ''; } catch(e) {}
    whatsappTemplateInput.value = (tmpl || getDefaultWhatsappTemplate()).toString();
    whatsappTemplateInput.addEventListener('input', () => {
        try { if(window.localStorage) localStorage.setItem(whatsappTemplateStorageKey, whatsappTemplateInput.value||''); } catch(e) {}
        updateWhatsappPreview();
    });
}
resetWhatsappTemplateBtn?.addEventListener('click', () => {
    const def = getDefaultWhatsappTemplate();
    if (whatsappTemplateInput) whatsappTemplateInput.value = def;
    try { if(window.localStorage) localStorage.setItem(whatsappTemplateStorageKey, def); } catch(e) {}
    updateWhatsappPreview();
    showMsg('success','تمت استعادة القالب الافتراضي.');
});
copyWhatsappPreviewBtn?.addEventListener('click', async () => {
    const text = (whatsappPreviewText?.textContent||'').trim();
    if (!text) { showMsg('error','لا توجد معاينة.'); return; }
    try { await navigator.clipboard.writeText(text); showMsg('success','تم نسخ المعاينة.'); }
    catch { showMsg('error','تعذر النسخ.'); }
});
updateWhatsappPreview();

// ── Forms ─────────────────────────────────────────────────────────
addForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const data = new FormData(addForm);
    const phone = normPhone(data.get('phone'));
    if (!phone) { showMsg('error','أدخل رقم جوال صحيح'); return; }
    try {
        const json = await postAction('pge_event_guest_add', { phone, name:data.get('name')||'', note:data.get('note')||'' });
        if (json?.success) { showMsg('success', json.data?.message||'تمت الإضافة'); addForm.reset(); closePanel(); reloadSoon(); }
        else showMsg('error', json?.data||'تعذر تنفيذ العملية');
    } catch { showMsg('error','تعذر الاتصال بالخادم'); }
});
bulkForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const phones = (new FormData(bulkForm).get('phones_text')||'').toString().trim();
    if (!phones) { showMsg('error','أدخل أرقام الجوال أولاً'); return; }
    try {
        const json = await postAction('pge_event_guest_bulk_add', { phones_text:phones });
        if (json?.success) { showMsg('success', json.data?.message||'تمت الإضافة الجماعية'); bulkForm.reset(); closePanel(); reloadSoon(); }
        else showMsg('error', json?.data||'تعذر تنفيذ العملية');
    } catch { showMsg('error','تعذر الاتصال بالخادم'); }
});
editForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const data = new FormData(editForm);
    const oldPhone = normPhone(data.get('old_phone'));
    const phone    = normPhone(data.get('phone'));
    if (!oldPhone || !phone) { showMsg('error','رقم الجوال غير صالح'); return; }
    try {
        const json = await postAction('pge_event_guest_update', { old_phone:oldPhone, phone, name:data.get('name')||'', note:data.get('note')||'' });
        if (json?.success) { showMsg('success', json.data?.message||'تم التحديث'); reloadSoon(); }
        else showMsg('error', json?.data||'تعذر تنفيذ العملية');
    } catch { showMsg('error','تعذر الاتصال بالخادم'); }
});
cancelEditBtn?.addEventListener('click', () => {
    editCard?.classList.add('hidden');
    if (isMobile()) closePanel();
});

// ── Guest Action Clicks ───────────────────────────────────────────
document.addEventListener('click', async e => {
    const waBtn = e.target.closest('.guest-wa-btn');
    if (waBtn) {
        const phone = normPhone(waBtn.dataset.phone||'');
        const name  = (waBtn.dataset.name||'').toString();
        if (!phone) { showMsg('error','رقم الجوال غير صالح.'); return; }
        const row = waBtn.closest('.guest-row');
        openWhatsappInvite(phone, name, row?.dataset.code||'');
        return;
    }
    const editBtn = e.target.closest('.guest-edit-btn');
    if (editBtn) {
        const row = editBtn.closest('.guest-row');
        if (!row || !editCard || !editForm) return;
        editForm.querySelector('#editOldPhone').value   = row.dataset.phone||'';
        editForm.querySelector('#editGuestPhone').value = row.dataset.phone||'';
        editForm.querySelector('#editGuestName').value  = row.dataset.name||'';
        editForm.querySelector('#editGuestNote').value  = row.dataset.note||'';
        editCard.classList.remove('hidden');
        if (!isMobile()) editCard.scrollIntoView({ behavior:'smooth', block:'nearest' });
        return;
    }
    const delBtn = e.target.closest('.guest-delete-btn');
    if (delBtn) {
        const phone = normPhone(delBtn.dataset.phone||'');
        if (!phone || !confirm('هل تريد حذف هذا المدعو؟')) return;
        try {
            const json = await postAction('pge_event_guest_delete', { phone });
            if (json?.success) { showMsg('success', json.data?.message||'تم الحذف'); reloadSoon(); }
            else showMsg('error', json?.data||'تعذر تنفيذ العملية');
        } catch { showMsg('error','تعذر الاتصال بالخادم'); }
    }
});

// ── Bulk WA ───────────────────────────────────────────────────────
bulkWhatsappBtn?.addEventListener('click', () => {
    const phones = getSelectedPhones();
    phones.forEach((phone, idx) => {
        const row = document.querySelector(`.guest-row[data-phone="${phone}"]`);
        setTimeout(() => openWhatsappInvite(phone, row?.dataset.name||'', row?.dataset.code||'', true), idx * 220);
    });
    showMsg('success', `جاري فتح واتساب للمحدد (${phones.length}).`);
});
whatsappAllBtn?.addEventListener('click', () => {
    const rows = getRows().filter(r => r.style.display !== 'none');
    if (!rows.length) { showMsg('error','لا يوجد مدعوون.'); return; }
    rows.forEach((row, idx) => {
        const phone = normPhone(row.dataset.phone||'');
        if (!phone) return;
        setTimeout(() => openWhatsappInvite(phone, row.dataset.name||'', row.dataset.code||'', true), idx * 220);
    });
    showMsg('success', `جاري فتح واتساب لكل المدعوين (${rows.length}).`);
});
bulkDeleteBtn?.addEventListener('click', async () => {
    const phones = getSelectedPhones();
    if (!phones.length || !confirm(`حذف ${phones.length} مدعو؟`)) return;
    try {
        const json = await postAction('pge_event_guest_bulk_delete', { phones });
        if (json?.success) { showMsg('success', json.data?.message||'تم الحذف'); reloadSoon(); }
        else showMsg('error', json?.data||'تعذر تنفيذ العملية');
    } catch { showMsg('error','تعذر الاتصال بالخادم'); }
});

// ── CSV Export ────────────────────────────────────────────────────
exportCsvBtn?.addEventListener('click', () => {
    const rows = getRows();
    if (!rows.length) { showMsg('error','لا توجد بيانات.'); return; }
    const data = [['الاسم','الجوال','الملاحظة','RSVP','Check-in']];
    rows.forEach(row => {
        const statusMap = { yes:'سيحضر', no:'اعتذر', pending:'لم يرد' };
        data.push([
            row.dataset.name||'',
            row.dataset.phone||'',
            row.dataset.note||'',
            statusMap[row.dataset.status]||row.dataset.status||'',
            row.dataset.checked==='yes' ? 'تم' : '',
        ]);
    });
    const esc = v => `"${String(v||'').replace(/"/g,'""')}"`;
    const csv = '﻿' + data.map(r => r.map(esc).join(',')).join('\n');
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'})),
        download: `guests-<?= (int)$event_id ?>.csv`
    });
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
});

// ── Code Actions ──────────────────────────────────────────────────
document.addEventListener('click', async e => {
    const copyBtn = e.target.closest('.guest-copy-code-btn');
    if (copyBtn) {
        const code = copyBtn.dataset.code||'';
        if (!code) return;
        try { await navigator.clipboard.writeText(code); const o=copyBtn.textContent; copyBtn.textContent='✅'; setTimeout(()=>{copyBtn.textContent=o;},1500); }
        catch { showMsg('error','تعذر نسخ الرمز'); }
        return;
    }
    const regenBtn = e.target.closest('.guest-regen-code-btn');
    if (regenBtn) {
        const phone = normPhone(regenBtn.dataset.phone||'');
        if (!phone || !confirm(`توليد رمز جديد للرقم ${phone}؟`)) return;
        regenBtn.textContent = '⏳'; regenBtn.disabled = true;
        try {
            const json = await postAction('pge_event_guest_regen_code', { phone });
            if (json?.success && json.data?.code) {
                const newCode = json.data.code;
                const row = document.querySelector(`.guest-row[data-phone="${phone}"]`);
                if (row) {
                    row.dataset.code = newCode;
                    const cd = row.querySelector('.guest-code-display'); if(cd) cd.textContent = newCode;
                    const cb = row.querySelector('.guest-copy-code-btn'); if(cb) cb.dataset.code = newCode;
                }
                showMsg('success', `✅ رمز جديد: ${newCode}`);
            } else showMsg('error', json?.data||'تعذر توليد رمز جديد');
        } catch { showMsg('error','تعذر الاتصال بالخادم'); }
        finally { regenBtn.textContent='🔄'; regenBtn.disabled=false; }
    }
});

// ── Background Queue ──────────────────────────────────────────────
const ajaxUrl  = '<?= esc_url(admin_url('admin-ajax.php')) ?>';
const waNonce  = '<?= esc_js(wp_create_nonce('pge_event_manage_nonce')) ?>';
const waEventId= <?= (int) $event_id ?>;

async function showWaReport() {
    const res  = await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'pge_wa_queue_status',nonce:waNonce,event_id:waEventId})});
    const json = await res.json();
    if (!json.success) return;
    const d = json.data;
    if (d.status === 'none') { showMsg('info','لا يوجد إرسال مجدول بعد.'); return; }
    const statusLabel = {queued:'⏳ في الانتظار',running:'🔄 جارٍ',done:'✅ اكتمل'};
    const rows = (d.report??[]).map(r=>`<tr class="border-b"><td class="py-1.5 px-3">${r.name}</td><td class="py-1.5 px-3 font-mono text-xs text-slate-500" dir="ltr">${r.phone}</td><td class="py-1.5 px-3">${r.status==='sent'?'✅ أُرسل':'❌ فشل'}</td><td class="py-1.5 px-3 text-slate-400 text-xs">${r.time}</td></tr>`).join('');
    document.getElementById('waReportModal')?.remove();
    const m = document.createElement('div');
    m.id='waReportModal';
    m.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;';
    m.innerHTML=`<div style="background:#fff;border-radius:20px;width:100%;max-width:600px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;" dir="rtl">
        <div style="padding:16px 20px;border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
          <h3 style="font-weight:800;font-size:16px;">📊 تقرير الإرسال</h3>
          <button onclick="document.getElementById('waReportModal').remove()" style="font-size:22px;cursor:pointer;line-height:1;">×</button>
        </div>
        <div style="padding:12px 20px;background:var(--color-background);border-bottom:1px solid var(--color-border);display:flex;gap:20px;font-size:13px;flex-wrap:wrap;">
          <span>${statusLabel[d.status]??d.status}</span>
          <span>📊 ${d.offset??0} / ${d.total??0}</span>
          <span>✅ ${d.sent??0}</span><span>❌ ${d.failed??0}</span>
          ${d.done_at?`<span style="color:#6b7280;">${d.done_at}</span>`:''}
        </div>
        <div style="overflow-y:auto;flex:1;">
          <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <thead style="background:var(--color-secondary);position:sticky;top:0;"><tr>
              <th style="padding:8px 12px;text-align:right;">الاسم</th>
              <th style="padding:8px 12px;text-align:right;">الجوال</th>
              <th style="padding:8px 12px;text-align:right;">الحالة</th>
              <th style="padding:8px 12px;text-align:right;">الوقت</th>
            </tr></thead>
            <tbody>${rows||'<tr><td colspan="4" style="text-align:center;padding:20px;color:#9ca3af;">لا توجد نتائج</td></tr>'}</tbody>
          </table>
        </div></div>`;
    document.body.appendChild(m);
    m.addEventListener('click', e => { if(e.target===m) m.remove(); });
}
document.getElementById('waReportBtn')?.addEventListener('click', showWaReport);

(async () => {
    try {
        const res  = await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'pge_wa_queue_status',nonce:waNonce,event_id:waEventId})});
        const json = await res.json();
        if (!json?.success) return;
        const d = json.data;
        if (d.status==='running'||d.status==='queued')
            showMsg('info',`⏳ إرسال جارٍ في الخلفية — ${d.offset??0}/${d.total??0}. اضغط "تقرير".`);
    } catch {}
})();

document.getElementById('sendWaInvitesBtn')?.addEventListener('click', async () => {
    const total = getRows().length;
    if (!total) { showMsg('error','لا يوجد مدعوون.'); return; }
    const mins = Math.ceil(total * 3.5 / 60);
    if (!confirm(`إرسال دعوة واتساب لـ ${total} مدعو في الخلفية.\n⏱ مدة تقريبية: ${mins} دقيقة.\n✅ يمكنك إغلاق الصفحة.`)) return;
    const btn = document.getElementById('sendWaInvitesBtn');
    btn.disabled=true; btn.textContent='⏳ جاري التقديم...';
    try {
        const res  = await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'pge_wa_queue_start',nonce:waNonce,event_id:waEventId})});
        const json = await res.json();
        if (json.success) showMsg('success',`🚀 ${json.data.message}`);
        else showMsg('error','❌ '+(json.data?.message??JSON.stringify(json.data)));
    } catch(err) { showMsg('error','تعذر الاتصال: '+err.message); }
    finally { btn.disabled=false; btn.textContent='📨 إرسال تلقائي'; }
});

refreshBulkDeleteState();
applyFilters();
</script>

<?php get_footer(); ?>
