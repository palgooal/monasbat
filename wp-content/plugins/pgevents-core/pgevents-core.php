<?php

/**
 * Plugin Name: PgEvents Core
 * Description: المحرك البرمجي لنظام المناسبات - شركة بال قول.
 * Version: 1.0.0
 * Author: Pal Goal Team
 */

if (!defined('ABSPATH')) exit;

define('PGE_URL', plugin_dir_url(__FILE__));
define('PGE_PATH', plugin_dir_path(__FILE__));

// 1. استدعاء المكونات الأساسية (Logic)
require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/cpts.php';
require_once PGE_PATH . 'includes/metaboxes.php';
require_once PGE_PATH . 'includes/user-profiles.php';

// Event Quota — Commit 8: لوحة تشخيص مطورين مؤقتة (قراءة فقط، للمدير حصراً)
// في شاشة تعديل المستخدم بلوحة التحكم. تستهلك pge_resolve_event_quota_status()
// (Commit 5) حصراً — لا منطق أعمال جديد، لا كتابة، لا حساب حصة مستقل.
require_once PGE_PATH . 'includes/event-quota-diagnostics.php';
require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/rsvp-migration.php';

// Schema كتالوج الباقات والخدمات (الخطوة الأولى فقط — لا CRUD ولا واجهة إدارة بعد)
require_once PGE_PATH . 'includes/class-mon-catalog-schema.php';
require_once PGE_PATH . 'includes/class-pge-catalog.php';

// سجل استهلاك رصيد الدعوات الذري (Invitation Credits Engine — المرحلة
// الثانية: تأسيس بنية فقط). لا يُستدعى من أي مسار إرسال/RSVP/مدعوين بعد.
require_once PGE_PATH . 'includes/class-pge-invitation-credit-ledger.php';

// سجل استحقاقات Replacement Credits (Invitation Credits Engine — المرحلة
// 4A: تأسيس بنية الاستحقاقات فقط). لا يُستدعى من أي مسار RSVP/Queue/Cartat بعد.
require_once PGE_PATH . 'includes/class-pge-replacement-entitlements.php';

// ربط منح Replacement Entitlement من مسارات RSVP الحية (المرحلة 4B) — دالة
// مركزية واحدة تُستدعى من pge_save_rsvp_response() وMon_Cartat_Handler::
// record_rsvp() فقط. لا إرسال Replacement فعلي، لا لمس لـQueue/Cron/Ledger.
require_once PGE_PATH . 'includes/replacement-entitlement-grant.php';

// Feature Registry — طبقة الميزات الجديدة (Phase 1: تأسيس فقط، وفق
// docs/FEATURES-PHASE-1-SPEC.md، مبني على docs/PACKAGE-FEATURE-MATRIX.md
// §6/§8). تعريف الميزات فقط — لا جدول قاعدة بيانات، لا Resolver، لا
// استهلاك من أي صفحة مستخدم بعد.
require_once PGE_PATH . 'includes/class-pge-feature-registry.php';

// Tier Features Repository — تخزين خام لقيم ميزات كل Tier (Phase 2 —
// Commit 2، وفق docs/FEATURES-PHASE-2-SPEC.md §9، مبني على جدول
// mon_tier_features المُضاف في class-mon-catalog-schema.php عند 1.8.0).
// CRUD خام فقط — لا Resolver، لا Snapshot، لا استهلاك من أي مسار آخر بعد.
require_once PGE_PATH . 'includes/class-pge-tier-features.php';

// Feature Resolver — الدوال الثلاث العامة لتفسير ميزات المستخدم (Phase 3 —
// Commit 1، وفق docs/FEATURES-PHASE-3-SPEC.md، مبني على DEC-001/DEC-002/DEC-003
// في docs/DECISION-LOG.md). قراءة فقط عبر PGE_Feature_Registry/PGE_Tier_Features/
// PGE_Catalog/PGE_Packages — لا كتابة Snapshot، لا استهلاك من أي صفحة مستخدم بعد.
require_once PGE_PATH . 'includes/feature-resolver.php';

// Supervisor Quota Resolver — Entry Check-in Supervisors، Phase 1
// ("Supervisor Entitlement Foundation" RFC). دالة معلوماتية بحتة
// (pge_resolve_supervisor_quota_status()) تقرأ Snapshot الميزات العام
// (_mon_package_features) وجدول mon_event_supervisors فقط — لا UI، لا AJAX،
// لا صلاحيات، لا دعوات في هذا الـCommit.
require_once PGE_PATH . 'includes/supervisor-quota-resolver.php';

// Supervisor Assignment Service — Entry Check-in Supervisors، Phase 2
// ("Supervisor Invitation Lifecycle" RFC). الوسيط الوحيد المسموح به للكتابة
// على mon_event_supervisors (إنشاء/قبول/إلغاء)، ودالة الـLookup الداخلية
// pge_has_active_supervisor_assignment() — بحث بيانات فقط، ليست دالة تفويض
// أو مصادقة (راجع التوثيق المفصَّل داخل class-pge-supervisor-assignment-
// service.php: قسم "Blocking Issue #1 — Authentication vs Lookup"). لا UI،
// لا AJAX، لا صفحات في هذا الـCommit — استهلاك مستقبلي فقط (Phase 3+).
require_once PGE_PATH . 'includes/class-pge-supervisor-assignment-service.php';

// Supervisor Session — Entry Check-in Supervisors، Phase 3 ("Supervisor
// Authentication" RFC). جلسة مشرف مستقلة تماماً عن تسجيل دخول WordPress
// (لا wp_users، لا حساب مطلوب) — الوسيط الوحيد المسموح به للكتابة على
// mon_supervisor_sessions (إنشاء/تحقق/تسجيل خروج). يوفّر أيضاً pge_is_active_
// supervisor_for_event($event_id) — دالة التفويض الحقيقية (تعتمد حصراً على
// جلسة مصادق عليها عبر كوكي، لا على رقم هاتف أو أي معامل طلب). لا معرفة هنا
// بدورة حياة الدعوة إطلاقاً (راجع تصحيح "Blocking Issue: Decoupling" داخل
// الملف نفسه) — لا UI، لا مسار HTTP يكتب الكوكي فعلياً بعد (Requirement 9).
require_once PGE_PATH . 'includes/class-pge-supervisor-session.php';

// Supervisor Authenticator — تصحيح معماري (Blocking Issue: "Invitation
// Acceptance and Supervisor Authentication are two different
// responsibilities. They must remain decoupled."). المنسِّق الوحيد الذي يربط
// بين قبول الدعوة (الملف أعلاه) وإنشاء الجلسة (الملف الذي قبله) — يوفّر
// PGE_Supervisor_Authenticator::authenticate() وpge_supervisor_authenticate()
// (غلاف رفيع للتوافق مع الاستدعاءات القائمة). صفر $wpdb هنا؛ كل تفاعل عبر
// الواجهات العامة للخدمتين فقط. لا UI، لا مسار HTTP — استهلاك مستقبلي.
require_once PGE_PATH . 'includes/class-pge-supervisor-authenticator.php';

// Supervisor Portal Middleware — Entry Check-in Supervisors، Phase 3.5
// ("Supervisor Portal Foundation" RFC). الحارس الوحيد لبوابة /supervisor/ —
// يستهلك PGE_Supervisor_Session::validate_session() وpge_is_active_supervisor_
// for_event() فقط (لا SQL خاص به)، ويُترجم كل سبب رفض إلى 401/403/404 صريحة.
require_once PGE_PATH . 'includes/class-pge-supervisor-portal-middleware.php';

// Supervisor Portal Bootstrap — Entry Check-in Supervisors، Phase 3.5. تحميل
// بيانات عرض قراءة-فقط (الإسناد/المناسبة/المضيف) بعد نجاح التفويض أعلاه فقط.
// لا مدعوين، لا دعوات، لا حضور — راجع توثيق الملف نفسه.
require_once PGE_PATH . 'includes/class-pge-supervisor-portal-bootstrap.php';

// Guest Check-in Engine — Entry Check-in Supervisors، Phase 4 ("Guest
// Check-in Engine" RFC). بنية بيانات (جدول تدقيق Append-Only + أعمدة إضافية
// على pge_event_rsvps)، خدمة تحقّق QR، خدمة حلّ الضيف (QR أو بحث يدوي، بنفس
// الكائن الموحَّد)، ومسجِّل الحضور الذري (GET_LOCK، منع تكرار). كل عملية
// تمرّ حصراً عبر PGE_Supervisor_Portal_Middleware::authorize() (Phase 3.5) —
// راجع includes/checkin-ajax.php. لا لوحات إحصاء، لا تقارير (خارج النطاق).
// Phase 9A Final Fix ("Enforce Cancellation in the Real Check-in Path"):
// PGE_Guest_Resolution_Service::resolve_by_rsvp_id()/resolve_by_phone() تحمل
// الآن حارس أهلية إداري صغير (يقرأ حالة الدعوة عبر PGE_Invitation_Repository
// إن كانت مُحمَّلة، وإلا يُعامِل الغياب كـ"نشطة" — توافق قديم) يرفض أي دعوة
// مُلغاة **قبل** بناء Guest Object وقبل وصول أي شيء لـPGE_Checkin_Recorder —
// راجع توثيق الدالتين في الملف نفسه. لا تعديل على Recorder/المخطط/الإحصاء.
require_once PGE_PATH . 'includes/class-pge-checkin-schema.php';
require_once PGE_PATH . 'includes/class-pge-checkin-qr-service.php';
require_once PGE_PATH . 'includes/class-pge-guest-resolution-service.php';
require_once PGE_PATH . 'includes/class-pge-checkin-recorder.php';
require_once PGE_PATH . 'includes/checkin-ajax.php';

// طبقة عرض/إعادة تشكيل رقيقة إضافية لبحث الحضور اليدوي — Entry Check-in
// Supervisors، Phase 7 ("Supervisor Check-in User Interface" RFC). تستهلك
// نفس PGE_Guest_Resolution_Service أعلاه (قراءة فقط، بلا تعديل) وتُسقِط
// rsvp_id/الهاتف الخام من الاستجابة (Security: "Never expose Raw RSVP IDs").
// لا كتابة هنا إطلاقاً — الكتابة الفعلية تبقى حصراً عبر
// pge_supervisor_checkin_confirm أعلاه (checkin-ajax.php)، غير مُعدَّلة.
require_once PGE_PATH . 'includes/checkin-ui-ajax.php';

// Attendance Statistics Engine — Entry Check-in Supervisors، Phase 5
// ("Attendance Statistics Engine" RFC). حساب فقط — لا HTML، لا رسوم بيانية،
// لا تصدير. PGE_Attendance_Statistics_Service: المصدر الوحيد لكل رقم حضور
// (يُشتَق حصراً من pge_event_rsvps + pge_checkin_audit_log). PGE_Attendance_
// Dashboard_Provider: حدود الطلب المُخوَّل الوحيدة (مضيف/أدمن أو جلسة مشرف
// لنفس المناسبة تحديداً) — يُركِّب Event/Supervisor/Attendance Summary وRecent
// Check-ins معاً. لا AJAX/قالب يستهلك أياً منهما بعد (مُعَدّان للاستهلاك
// المستقبلي فقط، خارج نطاق هذه المرحلة).
require_once PGE_PATH . 'includes/class-pge-attendance-statistics-service.php';
require_once PGE_PATH . 'includes/class-pge-attendance-dashboard-provider.php';

// Supervisor Attendance Dashboard UI — Entry Check-in Supervisors، Phase 6
// ("Supervisor Attendance Dashboard UI" RFC). عرض فقط — استهلاك حصري لـ
// PGE_Attendance_Dashboard_Provider أعلاه عبر includes/dashboard-ajax.php
// (رقيق، بلا حساب/SQL) وtemplates/supervisor-dashboard.php (بلا استعلام
// قاعدة بيانات من القالب). لا تعديل على أي طبقة حساب/تفويض هنا.
require_once PGE_PATH . 'includes/dashboard-ajax.php';

// Host Supervisor Management — Entry Check-in Supervisors، Phase 8 ("Host
// Supervisor Management" RFC). يدير المضيف دورة حياة إسناد المشرفين
// (إنشاء/تعديل/إعادة إرسال/إلغاء/قائمة مُرقَّمة+بحث) فقط — لا تعديل هنا على
// المصادقة/الجلسة/محرك تسجيل الحضور/لوحة الإحصاء. التفويض حصراً عبر
// pge_event_guests_user_can_manage() (نفس تفويض إدارة المدعوين في
// event-guests.php)، لا عبر PGE_Supervisor_Portal_Middleware (ذاك لجلسات
// المشرفين أنفسهم، لا للمضيف). جدول تدقيق مستقل تماماً عن pge_checkin_audit_log.
require_once PGE_PATH . 'includes/class-pge-supervisor-management-schema.php';
require_once PGE_PATH . 'includes/class-pge-supervisor-management-audit.php';

// Manual Package Activation — "التفعيل اليدوي للباقات من لوحة الإدارة" RFC.
// أداة إدارية (Admin-only) بديلة عن Webhook سلة لحالات الدعم الفني/التعويض/
// VIP/الاختبار/تعافي فشل Webhook/نقل الاشتراك. لا تكتب user_meta مباشرة ولا
// تحتوي أي منطق تفعيل جديد — تستدعي حصراً Mon_Events_Users::
// activate_catalog_tier()/activate_user_package() (نفس Service الذي ينتهي
// إليه Webhook سلة). جدول تدقيق مستقل تماماً عن كل جداول التدقيق الأخرى،
// بلا أي بيانات حساسة. Mon_Events_Users مُعرَّفة أصلاً بحلول هذه النقطة
// (class-mon-events-users.php يُحمَّل مبكراً جداً في هذا الملف).
require_once PGE_PATH . 'includes/class-pge-manual-package-activation-schema.php';
require_once PGE_PATH . 'includes/class-pge-manual-package-activation-audit.php';
require_once PGE_PATH . 'includes/manual-package-activation-ajax.php';
require_once PGE_PATH . 'includes/manual-package-activation-admin.php';

// Cartat Transport (Option B — Supervisor Invitation Delivery via Cartat).
// محمَّلة هنا صراحة (بدلاً من موقعها التاريخي عند مفتاح pge_wa_provider
// أسفل هذا الملف) لأنها مطلوبة الآن من PGE_Supervisor_Invitation_Delivery
// أدناه، التي تُستهلَك من supervisor-management-ajax.php — أبكر بكثير في
// ترتيب التحميل من مفتاح pge_wa_provider. هذا الملف صفر اعتماديات (يقرأ
// wp_options فقط عند الإنشاء)، فتحميله المبكر آمن تماماً؛ Mon_Cartat_Handler
// (المُحمَّل لاحقاً عند مفتاح pge_wa_provider) يعتمد عليها بدوره الآن —
// require_once يمنع أي تحميل مزدوج بين الموقعين.
require_once PGE_PATH . 'includes/class-pge-cartat-transport.php';

// Supervisor Invitation Delivery (Supervisor Invitation Delivery via Cartat،
// تنفيذ). النقطة المركزية الوحيدة لطلب تسليم دعوة مشرف فعلي عبر Cartat —
// تعتمد على PGE_Supervisor_Assignment_Service (أعلاه)، PGE_Cartat_Transport
// (السطر أعلاه مباشرة)، وPGE_Supervisor_Management_Audit (السطر الذي قبله).
// لا اعتماد عكسي: PGE_Supervisor_Assignment_Service/PGE_Cartat_Transport لا
// تعرفان بوجود هذا الملف إطلاقاً.
require_once PGE_PATH . 'includes/class-pge-supervisor-invitation-delivery.php';

// Supervisor Manual Invitation Link (Supervisor Manual Invitation Link: Secure
// One-Time Generation، تنفيذ). خدمة مستقلة تماماً عن السطر أعلاه: لا تعتمد على
// PGE_Cartat_Transport ولا تعرف بوجوده إطلاقاً — تعتمد فقط على PGE_Supervisor_
// Assignment_Service (أعلاه بكثير) وPGE_Supervisor_Management_Audit (أعلاه).
// تُحمَّل هنا (قبل supervisor-management-ajax.php مباشرة) لأن معالِج AJAX
// الجديد فيه يستهلكها.
require_once PGE_PATH . 'includes/class-pge-supervisor-manual-link-service.php';

// Supervisor Login Architecture (Post-Activation Login) — تنفيذ. ثلاث خدمات
// مستقلة تماماً عن كل ما سبق (لا تعرف بوجود PGE_Supervisor_Manual_Link_Service/
// PGE_Supervisor_Invitation_Delivery/PGE_Supervisor_Authenticator إطلاقاً،
// ولا هذه تعرف بوجودها): توليد/التزام توكن الدخول (Login_Service)، التسليم
// الاختياري عبر Cartat (Login_Delivery — تعتمد على Login_Service وعلى
// PGE_Cartat_Transport المُحمَّلة أعلاه)، ومنسِّق المصادقة (Login_Authenticator
// — يربط استهلاك توكن الدخول بـPGE_Supervisor_Session القائمة أصلاً، يُستهلَك
// من includes/routing.php عند طلب /supervisor/login/{token}/).
require_once PGE_PATH . 'includes/class-pge-supervisor-login-service.php';
require_once PGE_PATH . 'includes/class-pge-supervisor-login-delivery.php';
require_once PGE_PATH . 'includes/class-pge-supervisor-login-authenticator.php';

require_once PGE_PATH . 'includes/supervisor-management-ajax.php';

// نقطة نهاية AJAX عامة (nopriv) لطلب رابط دخول ذاتياً برقم الجوال —
// includes/supervisor-login-ajax.php (تُستهلَك من templates/supervisor-login.php).
// تعتمد على Login_Delivery أعلاه مباشرة، لا على أي ملف AJAX آخر.
require_once PGE_PATH . 'includes/supervisor-login-ajax.php';

// Host Invitation Management — Entry Check-in Supervisors، Phase 9A ("Host
// Invitation Management" RFC، بعد "Phase 9A Final Fix — Restore Phase 9A
// Scope Boundary"). النطاق الفعلي المُفعَّل في الإنتاج الآن: List / View /
// Create / Edit / Cancel / Search / Filters / Pagination / Audit
// (created/edited/cancelled فقط). "Invitation" يقابل الضيف الحالي
// (event-guests.php)، لا كياناً جديداً؛ لا تعديل هنا على المصادقة/الجلسة/
// محرك تسجيل الحضور/الإحصاء/لوحة المشرف/إدارة المشرفين (Phase 8)/الحصة.
// جدول تدقيق مستقل تماماً عن كل جداول التدقيق السابقة.
//
// Phase 9B (مُعتمَدة): Resend + QR Regeneration — PGE_INVITATION_MGMT_
// RESEND_QR_ENABLED = true، مُسجَّلتان فعلياً (راجع invitation-management-ajax.php).
// Phase 9C (مُعتمَدة الآن): Invitation Export (CSV/XLSX) — PGE_INVITATION_
// MGMT_EXPORT_ENABLED = true، مُسجَّلتان فعلياً. طبقتا التصدير الجديدتان
// (class-pge-invitation-export.php/class-pge-xlsx-writer.php) قراءة فقط
// بحتة — لا تعديل على Repository/Service/Audit (بنية الجدول والدوال كما
// هي)، فقط قيمة جديدة مسموحة ضمن بوابة ACTIVE_ACTIONS الحالية
// (export_completed) — راجع class-pge-invitation-management-audit.php.
// طبقات Repository/Audit/Service الأربع تُحمَّل الآن صراحة وبترتيب حتمي من
// هنا مباشرة (لا تحميل ضمني عبر side-effect requires داخل invitation-
// management-ajax.php كما كان سابقاً) — Loading Audit، Phase 9A Final Fix.
require_once PGE_PATH . 'includes/class-pge-invitation-management-schema.php';
require_once PGE_PATH . 'includes/class-pge-invitation-management-audit.php';
require_once PGE_PATH . 'includes/class-pge-invitation-repository.php';
require_once PGE_PATH . 'includes/class-pge-invitation-service.php';
require_once PGE_PATH . 'includes/class-pge-xlsx-writer.php';
require_once PGE_PATH . 'includes/class-pge-invitation-export.php';
// RC1 Fix Pack 3A ("Invitation Bulk Add Migration") — طبقة Parser/Validator
// وحيدة للإضافة الجماعية، تُحمَّل صراحة قبل invitation-management-ajax.php
// (نفس اصطلاح التحميل الصريح الحتمي أعلاه، Phase 9A Final Fix).
require_once PGE_PATH . 'includes/class-pge-invitation-bulk-add.php';
require_once PGE_PATH . 'includes/invitation-management-ajax.php';

// Event Operations — Entry Check-in Supervisors، Phase 10 ("Event
// Operations" RFC، مُعتمَدة). طبقة تجميع/عرض رقيقة فقط (Orchestration) فوق
// خدمات مُعتمَدة غير مُعدَّلة في حسابها: PGE_Attendance_Dashboard_Provider
// (Phase 5/6 — الإضافة الوحيدة هناك معامل $recent_checkins_limit اختياري
// توافقي خلفياً بالكامل)، PGE_Invitation_Service (Phase 9، بلا تعديل)،
// PGE_Supervisor_Assignment_Service (Phase 8، بلا تعديل). لا جدول جديد، لا
// تدقيق جديد (لا Audit::record() هنا إطلاقاً — عرض اللوحة لا يُسجِّل شيئاً).
require_once PGE_PATH . 'includes/event-operations-ajax.php';

// صفحة إدارة الباقات (خطوة النموذج فقط — عرض HTML بلا معالجة $_POST وبلا
// استدعاء لـ PGE_Catalog::create_plan() بعد؛ لا حفظ ولا رسائل نجاح/فشل)
add_action('admin_menu', function () {
    add_menu_page(
        'إدارة الباقات',
        'الباقات',
        'manage_options',
        'pge-catalog-plans',
        'pge_render_catalog_plans_page',
        'dashicons-products',
        58
    );

    add_submenu_page(
        'pge-catalog-plans',
        'مستويات الباقات',
        'مستويات الباقات',
        'manage_options',
        'pge-catalog-tiers',
        'pge_render_catalog_tiers_page'
    );
});

/**
 * ============================================================================
 * Phase 6 — Feature: نسخ ميزات مستوى إلى كل مستويات نفس الباقة (Copy Tier
 * Features — Admin Productivity فقط)
 * ============================================================================
 * مصدر وحيد لمنطق "حفظ ميزات مستوى واحد": استخراج حرفي (Extract Method) من
 * منطق الحفظ الذي كان مضمَّناً بالكامل داخل معالج POST الخاص بـ
 * update_tier_features (Phase 5 — Commit 2/2.1) — بلا أي تغيير في السلوك أو
 * الشروط أو رسائل النجاح/الفشل، فقط نقل نفس الأسطر إلى دالة تُستدعى من مكانين:
 *  1) معالج update_tier_features (الحفظ اليدوي العادي لميزات مستوى واحد).
 *  2) معالج copy_tier_features الجديد (يحفظ المستوى المصدر أولاً بنفس هذه
 *     الدالة تحديداً، قبل تنفيذ النسخ لبقية المستويات) — بلا أي نسخ/لصق لحلقة
 *     الحفظ في المكان الثاني، تماماً وفق الاشتراط الصريح لهذه الميزة.
 *
 * لا تغيير هنا على: Feature Registry، Tier Features Repository
 * (PGE_Tier_Features)، Resolver، Snapshot، Schema — كتابة عبر
 * PGE_Tier_Features::set_tier_feature_value() حصراً، كما في التنفيذ الأصلي.
 *
 * @param int        $tier_id               معرّف المستوى المراد حفظ ميزاته.
 * @param array|null $posted_tier_features  مصفوفة tier_features بعد
 *                                          wp_unslash() من المستدعي (أو null
 *                                          إن كانت غائبة تماماً عن الطلب أو
 *                                          لم تكن array أصلاً).
 * @return string 'incomplete'|'success'|'partial_failure'
 */
function pge_catalog_persist_tier_features($tier_id, $posted_tier_features)
{
    $registry = PGE_Feature_Registry::all();

    // ================================================================
    // Request-Level Completeness Check (مطابق حرفياً لـCommit 2.1)
    // ================================================================
    if (!is_array($posted_tier_features)) {
        return 'incomplete';
    }

    foreach ($registry as $required_key => $required_def) {
        if (
            ($required_def['type'] === 'integer' || $required_def['type'] === 'percentage')
            && !array_key_exists($required_key, $posted_tier_features)
        ) {
            return 'incomplete';
        }
    }

    // ================================================================
    // Field-Level Save Loop (أفضل-جهد، بلا Rollback) — مطابق حرفياً
    // ================================================================
    $any_failure = false;

    foreach ($registry as $feature_key => $feature_def) {
        $feature_type = $feature_def['type'];
        $key_present = array_key_exists($feature_key, $posted_tier_features);
        $posted_value = $key_present ? $posted_tier_features[$feature_key] : null;

        if ($feature_type === 'boolean') {
            if (!$key_present) {
                $raw_value = '0';
            } elseif (is_scalar($posted_value) && (string) $posted_value === '1') {
                $raw_value = '1';
            } else {
                $any_failure = true;
                continue;
            }
        } elseif ($feature_type === 'integer' || $feature_type === 'percentage') {
            if (!is_scalar($posted_value)) {
                $any_failure = true;
                continue;
            }

            $trimmed_value = trim((string) $posted_value);

            if (!preg_match('/^-?[0-9]+$/', $trimmed_value)) {
                $any_failure = true;
                continue;
            }

            $raw_value = $trimmed_value;
        } else {
            $any_failure = true;
            continue;
        }

        $write_result = PGE_Tier_Features::set_tier_feature_value($tier_id, $feature_key, $raw_value);

        if ($write_result !== true) {
            $any_failure = true;
        }
    }

    return $any_failure ? 'partial_failure' : 'success';
}

function pge_render_catalog_tiers_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'pgevents'));
    }

    $notice_type = '';
    $notice_message = '';

    $validate_salla_fields = static function ($product_id, $sku, $url) {
        $product_id = trim(sanitize_text_field((string) $product_id));
        if (!is_string($sku)) {
            return [
                'valid'      => false,
                'message'    => 'رمز SKU في سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => '',
                'url'        => trim((string) $url),
            ];
        }
        $sku = trim(sanitize_text_field($sku));
        if (strlen($product_id) > 64 || ($product_id !== '' && preg_match('/\s/u', $product_id))) {
            return [
                'valid'      => false,
                'message'    => 'معرّف منتج سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => $sku,
                'url'        => trim((string) $url),
            ];
        }

        if (strlen($sku) > 100 || ($sku !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $sku))) {
            return [
                'valid'      => false,
                'message'    => 'رمز SKU في سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => $sku,
                'url'        => trim((string) $url),
            ];
        }

        $url = trim((string) $url);
        if ($url !== '') {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة غير صالح.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }

            if (strtolower((string) $parts['scheme']) !== 'https') {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة يجب أن يستخدم HTTPS.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }

            $sanitized_url = esc_url_raw($url, ['https']);
            if ($sanitized_url === '' || strlen($sanitized_url) > 255) {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة غير صالح.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }
            $url = $sanitized_url;
        }

        return [
            'valid'      => true,
            'message'    => '',
            'product_id' => $product_id,
            'sku'        => $sku,
            'url'        => $url,
        ];
    };

    $tier_form_values = [
        'name'                      => '',
        'tier_key'                  => '',
        'price'                     => '0.00',
        'currency'                  => 'SAR',
        'salla_product_id'          => '',
        'salla_sku'                 => '',
        'salla_url'                 => '',
        'status'                    => 'active',
        'sort_order'                => '0',
        'invitation_credit_limit'   => '0',
        'replacement_credit_limit'  => '0',
        'guest_limit'               => '',
        'event_quota_mode'          => 'limited',
        'event_quota_limit'         => '1',
    ];

    $tier_edit_form_values = [
        'name'                      => '',
        'tier_key'                  => '',
        'price'                     => '',
        'currency'                  => 'SAR',
        'salla_product_id'          => '',
        'salla_sku'                 => '',
        'salla_url'                 => '',
        'status'                    => 'active',
        'sort_order'                => '0',
        'invitation_credit_limit'   => '0',
        'replacement_credit_limit'  => '0',
        'guest_limit'               => '',
        'event_quota_mode'          => 'limited',
        'event_quota_limit'         => '1',
    ];

    // Event Quota (Commit 2): تحقّق تناسق على مستوى النموذج قبل استدعاء
    // PGE_Catalog::create_tier()/update_tier() — بنفس فلسفة $validate_salla_fields
    // أعلاه بالضبط (دالة واحدة، تُعيد ['valid' => bool, 'message' => string,
    // 'mode' => string, 'limit' => string] لاستخدامها في كلا مساري
    // create_tier وupdate_tier). لماذا هنا وليس داخل normalize_event_quota_*()
    // في class-pge-catalog.php: تلك الدوال لا تُعيد أبداً false/null عمداً
    // (تطبيع آمن دائماً لأي مستدعٍ برمجي آخر مستقبلاً)، بينما "رفض تناسق
    // Limited+فارغ/صفر/سالب/نص غير رقمي" هو تحقّق نموذج إداري خاص بهذه
    // الشاشة تحديداً، تماماً كما $validate_salla_fields تحقّق نموذج خاص لا
    // جزء من normalize_salla_*() نفسها.
    //
    // Unlimited: أي مدخلة رقمية (فارغة أو عشوائية) مقبولة دوماً ولا تُستخدَم
    // فعلياً — الخادم يُرسِل قيمة ثابتة '1' لـevent_quota_limit بغض النظر عمّا
    // وصل فعلياً، تحقيقاً لمتطلب "Server still receives a consistent value".
    $validate_event_quota_fields = function ($mode_raw, $limit_raw) {
        $mode = is_string($mode_raw) ? strtolower(trim($mode_raw)) : '';
        if ($mode !== 'unlimited') {
            $mode = 'limited';
        }

        if ($mode === 'unlimited') {
            return [
                'valid'   => true,
                'message' => '',
                'mode'    => 'unlimited',
                'limit'   => '1',
            ];
        }

        $limit_trimmed = trim((string) $limit_raw);
        if (!preg_match('/^[1-9][0-9]*$/', $limit_trimmed)) {
            return [
                'valid'   => false,
                'message' => 'حصة المناسبات (محدود) يجب أن تكون رقماً صحيحاً موجباً (1 أو أكثر).',
                'mode'    => 'limited',
                'limit'   => $limit_trimmed,
            ];
        }

        return [
            'valid'   => true,
            'message' => '',
            'mode'    => 'limited',
            'limit'   => $limit_trimmed,
        ];
    };

    $tier_create_post_handled = false;
    $tier_post_handled = false;

    $editing_tier_id = 0;
    $editing_tier = null;

    $plans = PGE_Catalog::get_plans();
    if (!is_array($plans)) {
        $plans = [];
    }

    $selected_plan_id = 0;
    $selected_plan = null;
    $tiers = [];

    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'create_tier'
        && isset($_POST['submit_create_tier'])
    ) {
        check_admin_referer('pge_create_catalog_tier', 'pge_catalog_tier_nonce');

        $tier_create_post_handled = true;
        $tier_post_handled = true;

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            $tier_form_values = [
                'name'                     => wp_unslash($_POST['name'] ?? ''),
                'tier_key'                 => wp_unslash($_POST['tier_key'] ?? ''),
                'price'                    => wp_unslash($_POST['price'] ?? ''),
                'currency'                 => wp_unslash($_POST['currency'] ?? ''),
                'salla_product_id'         => wp_unslash($_POST['salla_product_id'] ?? ''),
                'salla_sku'                => wp_unslash($_POST['salla_sku'] ?? ''),
                'salla_url'                => wp_unslash($_POST['salla_url'] ?? ''),
                'status'                   => wp_unslash($_POST['status'] ?? ''),
                'sort_order'               => wp_unslash($_POST['sort_order'] ?? ''),
                'invitation_credit_limit'  => wp_unslash($_POST['invitation_credit_limit'] ?? ''),
                'replacement_credit_limit' => wp_unslash($_POST['replacement_credit_limit'] ?? ''),
                'guest_limit'              => wp_unslash($_POST['guest_limit'] ?? ''),
                'event_quota_mode'         => wp_unslash($_POST['event_quota_mode'] ?? ''),
                'event_quota_limit'        => wp_unslash($_POST['event_quota_limit'] ?? ''),
            ];

            $salla_validation = $validate_salla_fields(
                $tier_form_values['salla_product_id'],
                $tier_form_values['salla_sku'],
                $tier_form_values['salla_url']
            );
            $tier_form_values['salla_product_id'] = $salla_validation['product_id'];
            $tier_form_values['salla_sku'] = $salla_validation['sku'];
            $tier_form_values['salla_url'] = $salla_validation['url'];

            $event_quota_validation = $validate_event_quota_fields(
                $tier_form_values['event_quota_mode'],
                $tier_form_values['event_quota_limit']
            );
            $tier_form_values['event_quota_mode'] = $event_quota_validation['mode'];
            $tier_form_values['event_quota_limit'] = $event_quota_validation['limit'];

            if (!$salla_validation['valid']) {
                $notice_type = 'error';
                $notice_message = $salla_validation['message'];
            } elseif (!$event_quota_validation['valid']) {
                $notice_type = 'error';
                $notice_message = $event_quota_validation['message'];
            } else {
                $salla_owner = $tier_form_values['salla_sku'] !== ''
                    ? PGE_Catalog::get_tier_by_salla_sku($tier_form_values['salla_sku'])
                    : null;

                if (is_array($salla_owner)) {
                    $notice_type = 'error';
                    $notice_message = 'رمز SKU في سلة مستخدم مسبقًا في مستوى آخر.';
                } else {
                    $created_tier = PGE_Catalog::create_tier([
                        'plan_id'                  => $posted_plan_id,
                        'tier_key'                 => $tier_form_values['tier_key'],
                        'name'                     => $tier_form_values['name'],
                        'price'                    => $tier_form_values['price'],
                        'currency'                 => $tier_form_values['currency'],
                        'salla_product_id'         => $tier_form_values['salla_product_id'],
                        'salla_sku'                => $tier_form_values['salla_sku'],
                        'salla_url'                => $tier_form_values['salla_url'],
                        'status'                   => $tier_form_values['status'],
                        'sort_order'               => $tier_form_values['sort_order'],
                        'invitation_credit_limit'  => $tier_form_values['invitation_credit_limit'],
                        'replacement_credit_limit' => $tier_form_values['replacement_credit_limit'],
                        'guest_limit'              => $tier_form_values['guest_limit'],
                        'event_quota_mode'         => $tier_form_values['event_quota_mode'],
                        'event_quota_limit'        => $tier_form_values['event_quota_limit'],
                    ]);

                    if (is_array($created_tier)) {
                        $notice_type = 'success';
                        $notice_message = 'تمت إضافة المستوى بنجاح. تم حفظ ربط سلة بنجاح.';

                        $tier_form_values = [
                            'name'                     => '',
                            'tier_key'                 => '',
                            'price'                    => '0.00',
                            'currency'                 => 'SAR',
                            'salla_product_id'         => '',
                            'salla_sku'                => '',
                            'salla_url'                => '',
                            'status'                   => 'active',
                            'sort_order'               => '0',
                            'invitation_credit_limit'  => '0',
                            'replacement_credit_limit' => '0',
                            'guest_limit'              => '',
                            'event_quota_mode'         => 'limited',
                            'event_quota_limit'        => '1',
                        ];
                    } else {
                        $notice_type = 'error';
                        $notice_message = 'تعذر حفظ المستوى.';
                    }
                }
            }

            $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
            if (!is_array($tiers)) {
                $tiers = [];
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'update_tier'
        && isset($_POST['submit_update_tier'])
    ) {
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_update_catalog_tier_' . $posted_tier_id, 'pge_catalog_tier_update_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب تعديله.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $tier_edit_form_values = [
                    'name'                     => wp_unslash($_POST['name'] ?? ''),
                    'tier_key'                 => wp_unslash($_POST['tier_key'] ?? ''),
                    'price'                    => wp_unslash($_POST['price'] ?? ''),
                    'currency'                 => wp_unslash($_POST['currency'] ?? ''),
                    'salla_product_id'         => wp_unslash($_POST['salla_product_id'] ?? ''),
                    'salla_sku'                => wp_unslash($_POST['salla_sku'] ?? ''),
                    'salla_url'                => wp_unslash($_POST['salla_url'] ?? ''),
                    'status'                   => wp_unslash($_POST['status'] ?? ''),
                    'sort_order'               => wp_unslash($_POST['sort_order'] ?? ''),
                    'invitation_credit_limit'  => wp_unslash($_POST['invitation_credit_limit'] ?? ''),
                    'replacement_credit_limit' => wp_unslash($_POST['replacement_credit_limit'] ?? ''),
                    'guest_limit'              => wp_unslash($_POST['guest_limit'] ?? ''),
                    'event_quota_mode'         => wp_unslash($_POST['event_quota_mode'] ?? ''),
                    'event_quota_limit'        => wp_unslash($_POST['event_quota_limit'] ?? ''),
                ];

                $salla_validation = $validate_salla_fields(
                    $tier_edit_form_values['salla_product_id'],
                    $tier_edit_form_values['salla_sku'],
                    $tier_edit_form_values['salla_url']
                );
                $tier_edit_form_values['salla_product_id'] = $salla_validation['product_id'];
                $tier_edit_form_values['salla_sku'] = $salla_validation['sku'];
                $tier_edit_form_values['salla_url'] = $salla_validation['url'];

                $event_quota_validation = $validate_event_quota_fields(
                    $tier_edit_form_values['event_quota_mode'],
                    $tier_edit_form_values['event_quota_limit']
                );
                $tier_edit_form_values['event_quota_mode'] = $event_quota_validation['mode'];
                $tier_edit_form_values['event_quota_limit'] = $event_quota_validation['limit'];

                if (!$salla_validation['valid']) {
                    $notice_type = 'error';
                    $notice_message = $salla_validation['message'];

                    $editing_tier_id = $posted_tier_id;
                    $editing_tier = $posted_tier;
                } elseif (!$event_quota_validation['valid']) {
                    $notice_type = 'error';
                    $notice_message = $event_quota_validation['message'];

                    $editing_tier_id = $posted_tier_id;
                    $editing_tier = $posted_tier;
                } else {
                    $salla_owner = $tier_edit_form_values['salla_sku'] !== ''
                        ? PGE_Catalog::get_tier_by_salla_sku($tier_edit_form_values['salla_sku'])
                        : null;

                    if (is_array($salla_owner) && absint($salla_owner['id']) !== $posted_tier_id) {
                        $notice_type = 'error';
                        $notice_message = 'رمز SKU في سلة مستخدم مسبقًا في مستوى آخر.';

                        $editing_tier_id = $posted_tier_id;
                        $editing_tier = $posted_tier;
                    } else {
                        $updated_tier = PGE_Catalog::update_tier(
                            $posted_tier_id,
                            [
                                'plan_id'                  => $posted_plan_id,
                                'tier_key'                 => $tier_edit_form_values['tier_key'],
                                'name'                     => $tier_edit_form_values['name'],
                                'price'                    => $tier_edit_form_values['price'],
                                'currency'                 => $tier_edit_form_values['currency'],
                                'salla_product_id'         => $tier_edit_form_values['salla_product_id'],
                                'salla_sku'                => $tier_edit_form_values['salla_sku'],
                                'salla_url'                => $tier_edit_form_values['salla_url'],
                                'status'                   => $tier_edit_form_values['status'],
                                'sort_order'               => $tier_edit_form_values['sort_order'],
                                'invitation_credit_limit'  => $tier_edit_form_values['invitation_credit_limit'],
                                'replacement_credit_limit' => $tier_edit_form_values['replacement_credit_limit'],
                                'guest_limit'              => $tier_edit_form_values['guest_limit'],
                                'event_quota_mode'         => $tier_edit_form_values['event_quota_mode'],
                                'event_quota_limit'        => $tier_edit_form_values['event_quota_limit'],
                            ]
                        );

                        if (is_array($updated_tier)) {
                            $notice_type = 'success';
                            $notice_message = 'تم تحديث المستوى بنجاح. تم حفظ ربط سلة بنجاح.';

                            $editing_tier_id = absint($updated_tier['id']);
                            $editing_tier = $updated_tier;

                            $tier_edit_form_values = [
                                'name'                     => $updated_tier['name'],
                                'tier_key'                 => $updated_tier['tier_key'],
                                'price'                    => $updated_tier['price'],
                                'currency'                 => $updated_tier['currency'],
                                'salla_product_id'         => $updated_tier['salla_product_id'] ?? '',
                                'salla_sku'                => $updated_tier['salla_sku'] ?? '',
                                'salla_url'                => $updated_tier['salla_url'] ?? '',
                                'status'                   => $updated_tier['status'],
                                'sort_order'               => $updated_tier['sort_order'],
                                'invitation_credit_limit'  => $updated_tier['invitation_credit_limit'] ?? '0',
                                'replacement_credit_limit' => $updated_tier['replacement_credit_limit'] ?? '0',
                                'guest_limit'              => ($updated_tier['guest_limit'] ?? null) === null ? '' : (string) $updated_tier['guest_limit'],
                                'event_quota_mode'         => $updated_tier['event_quota_mode'] ?? 'limited',
                                'event_quota_limit'        => $updated_tier['event_quota_limit'] ?? '1',
                            ];
                        } else {
                            $notice_type = 'error';
                            $notice_message = 'تعذر حفظ المستوى.';

                            $editing_tier_id = $posted_tier_id;
                            $editing_tier = $posted_tier;
                        }
                    }
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'delete_tier'
        && isset($_POST['submit_delete_tier'])
    ) {
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_delete_catalog_tier_' . $posted_tier_id, 'pge_catalog_tier_delete_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب حذفه.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $deleted = PGE_Catalog::delete_tier($posted_tier_id);

                if ($deleted === true) {
                    $notice_type = 'success';
                    $notice_message = 'تم حذف المستوى بنجاح.';

                    $editing_tier_id = 0;
                    $editing_tier = null;

                    $tier_edit_form_values = [
                        'name'                     => '',
                        'tier_key'                 => '',
                        'price'                    => '',
                        'currency'                 => 'SAR',
                        'salla_product_id'         => '',
                        'salla_sku'                => '',
                        'salla_url'                => '',
                        'status'                   => 'active',
                        'sort_order'               => '0',
                        'invitation_credit_limit'  => '0',
                        'replacement_credit_limit' => '0',
                        'guest_limit'              => '',
                        'event_quota_mode'         => 'limited',
                        'event_quota_limit'        => '1',
                    ];
                } else {
                    $notice_type = 'error';
                    $notice_message = 'تعذر حذف المستوى.';

                    $editing_tier_id = 0;
                    $editing_tier = null;
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'update_tier_features'
        && isset($_POST['submit_update_tier_features'])
    ) {
        // Phase 5 — Commit 2 (كتابة ميزات المستوى)، وفق
        // docs/FEATURES-PHASE-5-SPEC.md §8/§9/§10/§15. كتابة عبر
        // PGE_Tier_Features::set_tier_feature_value() حصراً — لا لمس لـ
        // PGE_Catalog/Resolver/Snapshot/Registry.
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_update_catalog_tier_features_' . $posted_tier_id, 'pge_catalog_tier_features_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب تعديل ميزاته.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $editing_tier_id = $posted_tier_id;
                $editing_tier = $posted_tier;

                $tier_edit_form_values = [
                    'name'                     => $posted_tier['name'],
                    'tier_key'                 => $posted_tier['tier_key'],
                    'price'                    => $posted_tier['price'],
                    'currency'                 => $posted_tier['currency'],
                    'salla_product_id'         => $posted_tier['salla_product_id'] ?? '',
                    'salla_sku'                => $posted_tier['salla_sku'] ?? '',
                    'salla_url'                => $posted_tier['salla_url'] ?? '',
                    'status'                   => $posted_tier['status'],
                    'sort_order'               => $posted_tier['sort_order'],
                    'invitation_credit_limit'  => $posted_tier['invitation_credit_limit'] ?? '0',
                    'replacement_credit_limit' => $posted_tier['replacement_credit_limit'] ?? '0',
                    'guest_limit'              => ($posted_tier['guest_limit'] ?? null) === null ? '' : (string) $posted_tier['guest_limit'],
                ];

                // Phase 6 — Extract Method: منطق الحفظ (قراءة $_POST['tier_features']
                // + فحص الاكتمال + حلقة الحفظ) انتقل بالكامل إلى
                // pge_catalog_persist_tier_features() (مصدر وحيد، يُستدعى أيضاً
                // من معالج copy_tier_features أدناه) — بلا أي تغيير في السلوك
                // أو الشروط أو رسائل النجاح/الفشل عن التنفيذ الأصلي.
                $pge_save_posted_features = null;
                if (isset($_POST['tier_features']) && is_array($_POST['tier_features'])) {
                    $pge_save_posted_features = wp_unslash($_POST['tier_features']);
                }

                $pge_save_result = pge_catalog_persist_tier_features($posted_tier_id, $pge_save_posted_features);

                if ($pge_save_result === 'incomplete') {
                    $notice_type = 'error';
                    $notice_message = 'تعذر حفظ ميزات المستوى لأن بيانات النموذج غير مكتملة. لم يتم حفظ أي تغيير.';
                } elseif ($pge_save_result === 'partial_failure') {
                    $notice_type = 'error';
                    $notice_message = 'تعذر حفظ بعض ميزات المستوى.';
                } else {
                    $notice_type = 'success';
                    $notice_message = 'تم حفظ ميزات المستوى بنجاح.';
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'copy_tier_features'
        && isset($_POST['submit_copy_tier_features'])
    ) {
        // ================================================================
        // Phase 6 — Feature: نسخ ميزات المستوى إلى كل مستويات نفس الباقة
        // ================================================================
        // Admin Productivity فقط — لا تغيير في Feature Registry/Tier
        // Features Repository/Resolver/Snapshot/Schema. الحفظ الأول للمستوى
        // المصدر يمر عبر pge_catalog_persist_tier_features() (نفس الدالة
        // المستخدمة في update_tier_features أعلاه، بلا أي تكرار للحلقة).
        // بعد نجاح الحفظ فقط: القراءة مجدَّداً من قاعدة البيانات عبر
        // PGE_Tier_Features::get_all_tier_features() — لا اعتماد على $_POST
        // بعد هذه النقطة إطلاقاً. المستويات الشقيقة تُشتَق حصراً من الخادم
        // عبر PGE_Catalog::get_plan_tiers($plan_id) — لا ثقة بأي tier_id
        // مُرسَل من العميل.
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_copy_catalog_tier_features_' . $posted_tier_id, 'pge_catalog_tier_copy_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب نسخ ميزاته.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $editing_tier_id = $posted_tier_id;
                $editing_tier = $posted_tier;

                $tier_edit_form_values = [
                    'name'                     => $posted_tier['name'],
                    'tier_key'                 => $posted_tier['tier_key'],
                    'price'                    => $posted_tier['price'],
                    'currency'                 => $posted_tier['currency'],
                    'salla_product_id'         => $posted_tier['salla_product_id'] ?? '',
                    'salla_sku'                => $posted_tier['salla_sku'] ?? '',
                    'salla_url'                => $posted_tier['salla_url'] ?? '',
                    'status'                   => $posted_tier['status'],
                    'sort_order'               => $posted_tier['sort_order'],
                    'invitation_credit_limit'  => $posted_tier['invitation_credit_limit'] ?? '0',
                    'replacement_credit_limit' => $posted_tier['replacement_credit_limit'] ?? '0',
                    'guest_limit'              => ($posted_tier['guest_limit'] ?? null) === null ? '' : (string) $posted_tier['guest_limit'],
                ];

                // 1) حفظ المستوى المصدر أولاً — نفس دالة الحفظ المستخدمة في
                // update_tier_features حرفياً، بلا أي تكرار للمنطق.
                $pge_copy_posted_features = null;
                if (isset($_POST['tier_features']) && is_array($_POST['tier_features'])) {
                    $pge_copy_posted_features = wp_unslash($_POST['tier_features']);
                }

                $pge_copy_save_result = pge_catalog_persist_tier_features($posted_tier_id, $pge_copy_posted_features);

                if ($pge_copy_save_result === 'incomplete') {
                    $notice_type = 'error';
                    $notice_message = 'تعذر نسخ الميزات لأن بيانات النموذج غير مكتملة. لم يتم حفظ أو نسخ أي تغيير.';
                } elseif ($pge_copy_save_result === 'partial_failure') {
                    $notice_type = 'error';
                    $notice_message = 'تعذر حفظ بعض ميزات المستوى المصدر، فلم يتم تنفيذ النسخ لباقي المستويات.';
                } else {
                    // 2) إعادة القراءة من قاعدة البيانات — القاعدة هي مصدر
                    // الحقيقة من هنا فصاعداً، لا اعتماد على $_POST إطلاقاً.
                    $pge_copy_source_raw = PGE_Tier_Features::get_all_tier_features($posted_tier_id);

                    if ($pge_copy_source_raw === false) {
                        $notice_type = 'error';
                        $notice_message = 'تم حفظ المستوى المصدر، لكن تعذّرت قراءة ميزاته من قاعدة البيانات لتنفيذ النسخ.';
                    } else {
                        $pge_copy_source_map = [];
                        foreach ($pge_copy_source_raw as $pge_copy_row) {
                            $pge_copy_source_map[(string) $pge_copy_row['feature_key']] = (string) $pge_copy_row['feature_value'];
                        }

                        // 3) المستويات الشقيقة تُشتَق من الخادم حصراً — لا ثقة
                        // بأي tier_id من العميل. المستوى الحالي (المصدر) يُستبعَد.
                        $pge_copy_all_plan_tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                        if (!is_array($pge_copy_all_plan_tiers)) {
                            $pge_copy_all_plan_tiers = [];
                        }

                        $pge_copy_sibling_tiers = array_filter(
                            $pge_copy_all_plan_tiers,
                            function ($pge_copy_candidate_tier) use ($posted_tier_id) {
                                return absint($pge_copy_candidate_tier['id']) !== $posted_tier_id;
                            }
                        );

                        if (empty($pge_copy_sibling_tiers)) {
                            $notice_type = 'success';
                            $notice_message = 'تم حفظ المستوى، ولا توجد مستويات أخرى في هذه الباقة لنسخ الميزات إليها.';
                        } else {
                            // 4) نسخ كل مفتاح Registry موجود فعلياً في قراءة
                            // قاعدة البيانات (خطوة 2) إلى كل مستوى شقيق —
                            // أفضل-جهد، بلا Rollback، بنفس فلسفة update_tier_features.
                            $pge_copy_registry = PGE_Feature_Registry::all();
                            $pge_copy_any_failure = false;
                            $pge_copy_success_count = 0;

                            foreach ($pge_copy_sibling_tiers as $pge_copy_sibling_tier) {
                                $pge_copy_sibling_id = absint($pge_copy_sibling_tier['id']);
                                $pge_copy_sibling_ok = true;

                                foreach ($pge_copy_registry as $pge_copy_feature_key => $pge_copy_feature_def) {
                                    if (!array_key_exists($pge_copy_feature_key, $pge_copy_source_map)) {
                                        // لا قيمة مخزَّنة فعلياً لهذا المفتاح في
                                        // المستوى المصدر (غير متوقع بعد حفظ ناجح
                                        // عبر pge_catalog_persist_tier_features()،
                                        // لكن معاملته دفاعياً هنا: لا اختراع قيمة
                                        // Default جديدة — فقط تخطّي هذا المفتاح
                                        // لهذا المستوى الشقيق).
                                        continue;
                                    }

                                    $pge_copy_write_result = PGE_Tier_Features::set_tier_feature_value(
                                        $pge_copy_sibling_id,
                                        $pge_copy_feature_key,
                                        $pge_copy_source_map[$pge_copy_feature_key]
                                    );

                                    if ($pge_copy_write_result !== true) {
                                        $pge_copy_sibling_ok = false;
                                    }
                                }

                                if ($pge_copy_sibling_ok) {
                                    $pge_copy_success_count++;
                                } else {
                                    $pge_copy_any_failure = true;
                                }
                            }

                            $pge_copy_sibling_total = count($pge_copy_sibling_tiers);

                            if (!$pge_copy_any_failure) {
                                $notice_type = 'success';
                                $notice_message = sprintf(
                                    'تم نسخ الميزات بنجاح إلى %d من مستويات هذه الباقة الأخرى.',
                                    $pge_copy_sibling_total
                                );
                            } else {
                                $notice_type = 'error';
                                $notice_message = sprintf(
                                    'تم نسخ الميزات إلى %d من أصل %d من مستويات هذه الباقة الأخرى؛ فشل النسخ الكامل لمستوى واحد أو أكثر.',
                                    $pge_copy_success_count,
                                    $pge_copy_sibling_total
                                );
                            }
                        }
                    }
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    }

    if (!$tier_post_handled) {
        $selected_plan_id = absint(wp_unslash($_GET['plan_id'] ?? 0));

        if ($selected_plan_id > 0) {
            $selected_plan = PGE_Catalog::get_plan($selected_plan_id);

            if ($selected_plan === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على الباقة المطلوبة.';
            }
        }

        if (is_array($selected_plan)) {
            $tiers = PGE_Catalog::get_plan_tiers($selected_plan_id);
            if (!is_array($tiers)) {
                $tiers = [];
            }
        }

        $editing_tier_id = absint(wp_unslash($_GET['edit_tier'] ?? 0));

        if (is_array($selected_plan) && $editing_tier_id > 0) {
            $editing_tier = PGE_Catalog::get_tier($editing_tier_id);

            if ($editing_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب تعديله.';
                $editing_tier = null;
                $editing_tier_id = 0;
            } elseif (absint($editing_tier['plan_id']) !== $selected_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';
                $editing_tier = null;
                $editing_tier_id = 0;
            } else {
                $tier_edit_form_values = [
                    'name'                     => $editing_tier['name'],
                    'tier_key'                 => $editing_tier['tier_key'],
                    'price'                    => $editing_tier['price'],
                    'currency'                 => $editing_tier['currency'],
                    'salla_product_id'         => $editing_tier['salla_product_id'] ?? '',
                    'salla_sku'                => $editing_tier['salla_sku'] ?? '',
                    'salla_url'                => $editing_tier['salla_url'] ?? '',
                    'status'                   => $editing_tier['status'],
                    'sort_order'               => $editing_tier['sort_order'],
                    'invitation_credit_limit'  => $editing_tier['invitation_credit_limit'] ?? '0',
                    'replacement_credit_limit' => $editing_tier['replacement_credit_limit'] ?? '0',
                    'guest_limit'              => ($editing_tier['guest_limit'] ?? null) === null ? '' : (string) $editing_tier['guest_limit'],
                    'event_quota_mode'         => $editing_tier['event_quota_mode'] ?? 'limited',
                    'event_quota_limit'        => $editing_tier['event_quota_limit'] ?? '1',
                ];
            }
        }
    }

    $plan_type_labels = [
        'personal' => 'شخصية',
        'business' => 'أعمال',
    ];
    $status_labels = [
        'active' => 'نشطة',
        'inactive' => 'غير نشطة',
    ];
    $tier_status_labels = [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ];
?>
    <div class="wrap">
        <h1><?php esc_html_e('مستويات الباقات', 'pgevents'); ?></h1>

        <?php if ($notice_message !== ''): ?>
            <?php if ($notice_type === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php elseif ($notice_type === 'error'): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($plans)): ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e('لا توجد باقات متاحة لإدارة مستوياتها.', 'pgevents'); ?></p>
            </div>
        <?php else: ?>
            <form method="get">
                <input type="hidden" name="page" value="pge-catalog-tiers">

                <label for="pge_tiers_plan_id"><?php esc_html_e('اختر الباقة', 'pgevents'); ?></label>
                <select id="pge_tiers_plan_id" name="plan_id">
                    <option value=""><?php esc_html_e('— اختر باقة —', 'pgevents'); ?></option>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?php echo esc_attr(absint($plan['id'])); ?>" <?php echo selected($selected_plan_id, absint($plan['id']), false); ?>>
                            <?php echo esc_html($plan['name']); ?> — <?php echo esc_html($plan['plan_key']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php submit_button('عرض الباقة', 'secondary', 'submit', false); ?>
            </form>

            <?php if (is_array($selected_plan)): ?>
                <h2><?php esc_html_e('الباقة المختارة', 'pgevents'); ?></h2>
                <p>
                    <?php esc_html_e('اسم الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($selected_plan['name']); ?>
                </p>
                <p>
                    <?php esc_html_e('مفتاح الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($selected_plan['plan_key']); ?>
                </p>
                <p>
                    <?php esc_html_e('نوع الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($plan_type_labels[$selected_plan['plan_type']] ?? $selected_plan['plan_type']); ?>
                </p>
                <p>
                    <?php esc_html_e('الحالة:', 'pgevents'); ?>
                    <?php echo esc_html($status_labels[$selected_plan['status']] ?? $selected_plan['status']); ?>
                </p>

                <h2><?php esc_html_e('إضافة مستوى جديد', 'pgevents'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('pge_create_catalog_tier', 'pge_catalog_tier_nonce'); ?>
                    <input type="hidden" name="pge_catalog_action" value="create_tier">
                    <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="pge-tier-create-name"><?php esc_html_e('اسم المستوى', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge-tier-create-name" name="name" class="regular-text" value="<?php echo esc_attr($tier_form_values['name']); ?>" maxlength="190" required>
                                <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: 100 مدعو.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_key"><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_key" name="tier_key" class="regular-text" value="<?php echo esc_attr($tier_form_values['tier_key']); ?>" maxlength="64" required>
                                <p class="description"><?php esc_html_e('أحرف إنجليزية صغيرة وأرقام وشرطة أو شرطة سفلية فقط.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_price"><?php esc_html_e('السعر', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_price" name="price" min="0" max="99999999.99" step="0.01" value="<?php echo esc_attr($tier_form_values['price']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_currency"><?php esc_html_e('العملة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <select id="pge_tier_currency" name="currency" required>
                                    <option value="SAR" <?php echo selected($tier_form_values['currency'], 'SAR', false); ?>>SAR</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_product_id"><?php esc_html_e('معرّف منتج سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_salla_product_id" name="salla_product_id" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_product_id']); ?>" maxlength="64">
                                <p class="description"><?php esc_html_e('معرّف المنتج المقابل لهذا المستوى داخل متجر سلة.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_sku"><?php esc_html_e('رمز SKU في سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_salla_sku" name="salla_sku" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_sku']); ?>" maxlength="100" pattern="[A-Za-z0-9_-]+">
                                <p class="description"><?php esc_html_e('رمز الخيار المقابل لهذا المستوى داخل منتج سلة، مثل HALWA-CLASSIC-100.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_url"><?php esc_html_e('رابط منتج سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="pge_tier_salla_url" name="salla_url" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_url']); ?>" maxlength="255" placeholder="https://">
                                <p class="description"><?php esc_html_e('رابط صفحة المنتج التي سينتقل إليها العميل لإكمال الشراء.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_status"><?php esc_html_e('الحالة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <select id="pge_tier_status" name="status" required>
                                    <option value="active" <?php echo selected($tier_form_values['status'], 'active', false); ?>><?php esc_html_e('نشط', 'pgevents'); ?></option>
                                    <option value="inactive" <?php echo selected($tier_form_values['status'], 'inactive', false); ?>><?php esc_html_e('غير نشط', 'pgevents'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_form_values['sort_order']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_invitation_credit_limit"><?php esc_html_e('رصيد الدعوات الأساسي', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_invitation_credit_limit" name="invitation_credit_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_form_values['invitation_credit_limit']); ?>">
                                <p class="description"><?php esc_html_e('عدد المدعوين الذين يمكن إرسال دعوة واتساب لهم ضمن الاشتراك.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_replacement_credit_limit"><?php esc_html_e('رصيد الدعوات البديلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_replacement_credit_limit" name="replacement_credit_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_form_values['replacement_credit_limit']); ?>">
                                <p class="description"><?php esc_html_e('عدد الدعوات الإضافية المسموح بها بدل المدعوين المعتذرين.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_guest_limit"><?php esc_html_e('الحد الأقصى للمدعوين', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_guest_limit" name="guest_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_form_values['guest_limit']); ?>">
                                <p class="description"><?php esc_html_e('اتركه فارغاً أو 0 = بلا حد.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e('حصة المناسبات', 'pgevents'); ?>
                            </th>
                            <td>
                                <fieldset class="pge-event-quota-fieldset" data-target="pge_tier_event_quota_limit">
                                    <legend class="screen-reader-text"><?php esc_html_e('حصة المناسبات', 'pgevents'); ?></legend>
                                    <label>
                                        <input type="radio" name="event_quota_mode" value="limited" class="pge-event-quota-mode-radio" <?php checked($tier_form_values['event_quota_mode'] !== 'unlimited'); ?>>
                                        <?php esc_html_e('محدود', 'pgevents'); ?>
                                    </label>
                                    <input type="number" id="pge_tier_event_quota_limit" name="event_quota_limit" class="small-text" min="1" step="1" value="<?php echo esc_attr($tier_form_values['event_quota_limit']); ?>">
                                    <br>
                                    <label>
                                        <input type="radio" name="event_quota_mode" value="unlimited" class="pge-event-quota-mode-radio" <?php checked($tier_form_values['event_quota_mode'] === 'unlimited'); ?>>
                                        <?php esc_html_e('غير محدود', 'pgevents'); ?>
                                    </label>
                                </fieldset>
                                <p class="description"><?php esc_html_e('عدد المناسبات المسموح بإنشائها لكل مشترك في هذا المستوى.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('إضافة المستوى', 'primary', 'submit_create_tier'); ?>
                </form>

                <?php
                // Event Quota (Commit 2): تبديل عرض واجهة "حصة المناسبات" فقط —
                // بلا أي إرسال AJAX، بلا أي منطق أعمال، مجرد تفعيل/تعطيل حقل
                // الرقم بصرياً حسب الاختيار الحالي بين "محدود"/"غير محدود". يعمل
                // خارج شرط is_array($editing_tier) عمداً (محدد class عام
                // .pge-event-quota-fieldset) لأن نموذج الإنشاء أعلاه يحتاج نفس
                // السلوك حتى لو لم يكن هناك أي مستوى قيد التعديل حالياً. أول
                // سكربت في هذه الصفحة الإدارية (لم يكن هناك أي جافاسكربت هنا من قبل).
                ?>
                <script>
                (function () {
                    function syncEventQuotaFieldset(fieldset) {
                        var targetId = fieldset.getAttribute('data-target');
                        var target = targetId ? document.getElementById(targetId) : null;
                        if (!target) { return; }
                        var unlimitedRadio = fieldset.querySelector('input[type="radio"][value="unlimited"]');
                        target.disabled = !!(unlimitedRadio && unlimitedRadio.checked);
                    }
                    var fieldsets = document.querySelectorAll('.pge-event-quota-fieldset');
                    for (var i = 0; i < fieldsets.length; i++) {
                        (function (fieldset) {
                            syncEventQuotaFieldset(fieldset);
                            var radios = fieldset.querySelectorAll('input[type="radio"]');
                            for (var j = 0; j < radios.length; j++) {
                                radios[j].addEventListener('change', function () {
                                    syncEventQuotaFieldset(fieldset);
                                });
                            }
                        })(fieldsets[i]);
                    }
                })();
                </script>

                <?php if (is_array($editing_tier)): ?>
                    <h2><?php esc_html_e('تعديل المستوى', 'pgevents'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('pge_update_catalog_tier_' . absint($editing_tier['id']), 'pge_catalog_tier_update_nonce'); ?>
                        <input type="hidden" name="pge_catalog_action" value="update_tier">
                        <input type="hidden" name="tier_id" value="<?php echo esc_attr(absint($editing_tier['id'])); ?>">
                        <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="pge-tier-edit-name"><?php esc_html_e('اسم المستوى', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge-tier-edit-name" name="name" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['name']); ?>" maxlength="190" required>
                                    <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: 100 مدعو.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_key"><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_key" name="tier_key" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['tier_key']); ?>" maxlength="64" required>
                                    <p class="description"><?php esc_html_e('أحرف إنجليزية صغيرة وأرقام وشرطة أو شرطة سفلية فقط.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_price"><?php esc_html_e('السعر', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_price" name="price" min="0" max="99999999.99" step="0.01" value="<?php echo esc_attr($tier_edit_form_values['price']); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_currency"><?php esc_html_e('العملة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <select id="pge_edit_tier_currency" name="currency" required>
                                        <option value="SAR" <?php echo selected($tier_edit_form_values['currency'], 'SAR', false); ?>>SAR</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_product_id"><?php esc_html_e('معرّف منتج سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_salla_product_id" name="salla_product_id" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_product_id']); ?>" maxlength="64">
                                    <p class="description"><?php esc_html_e('معرّف المنتج المقابل لهذا المستوى داخل متجر سلة.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_sku"><?php esc_html_e('رمز SKU في سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_salla_sku" name="salla_sku" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_sku']); ?>" maxlength="100" pattern="[A-Za-z0-9_-]+">
                                    <p class="description"><?php esc_html_e('رمز الخيار المقابل لهذا المستوى داخل منتج سلة، مثل HALWA-CLASSIC-100.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_url"><?php esc_html_e('رابط منتج سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="url" id="pge_edit_tier_salla_url" name="salla_url" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_url']); ?>" maxlength="255" placeholder="https://">
                                    <p class="description"><?php esc_html_e('رابط صفحة المنتج التي سينتقل إليها العميل لإكمال الشراء.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_status"><?php esc_html_e('الحالة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <select id="pge_edit_tier_status" name="status" required>
                                        <option value="active" <?php echo selected($tier_edit_form_values['status'], 'active', false); ?>><?php esc_html_e('نشط', 'pgevents'); ?></option>
                                        <option value="inactive" <?php echo selected($tier_edit_form_values['status'], 'inactive', false); ?>><?php esc_html_e('غير نشط', 'pgevents'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_edit_form_values['sort_order']); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_invitation_credit_limit"><?php esc_html_e('رصيد الدعوات الأساسي', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_invitation_credit_limit" name="invitation_credit_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_edit_form_values['invitation_credit_limit']); ?>">
                                    <p class="description"><?php esc_html_e('عدد المدعوين الذين يمكن إرسال دعوة واتساب لهم ضمن الاشتراك.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_replacement_credit_limit"><?php esc_html_e('رصيد الدعوات البديلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_replacement_credit_limit" name="replacement_credit_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_edit_form_values['replacement_credit_limit']); ?>">
                                    <p class="description"><?php esc_html_e('عدد الدعوات الإضافية المسموح بها بدل المدعوين المعتذرين.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_guest_limit"><?php esc_html_e('الحد الأقصى للمدعوين', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_guest_limit" name="guest_limit" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_edit_form_values['guest_limit']); ?>">
                                    <p class="description"><?php esc_html_e('اتركه فارغاً أو 0 = بلا حد.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <?php esc_html_e('حصة المناسبات', 'pgevents'); ?>
                                </th>
                                <td>
                                    <fieldset class="pge-event-quota-fieldset" data-target="pge_edit_tier_event_quota_limit">
                                        <legend class="screen-reader-text"><?php esc_html_e('حصة المناسبات', 'pgevents'); ?></legend>
                                        <label>
                                            <input type="radio" name="event_quota_mode" value="limited" class="pge-event-quota-mode-radio" <?php checked($tier_edit_form_values['event_quota_mode'] !== 'unlimited'); ?>>
                                            <?php esc_html_e('محدود', 'pgevents'); ?>
                                        </label>
                                        <input type="number" id="pge_edit_tier_event_quota_limit" name="event_quota_limit" class="small-text" min="1" step="1" value="<?php echo esc_attr($tier_edit_form_values['event_quota_limit']); ?>">
                                        <br>
                                        <label>
                                            <input type="radio" name="event_quota_mode" value="unlimited" class="pge-event-quota-mode-radio" <?php checked($tier_edit_form_values['event_quota_mode'] === 'unlimited'); ?>>
                                            <?php esc_html_e('غير محدود', 'pgevents'); ?>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e('عدد المناسبات المسموح بإنشائها لكل مشترك في هذا المستوى.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('حفظ التعديلات', 'primary', 'submit_update_tier', false); ?>
                        <?php
                        $tier_cancel_url = add_query_arg(
                            [
                                'page'    => 'pge-catalog-tiers',
                                'plan_id' => $selected_plan_id,
                            ],
                            admin_url('admin.php')
                        );
                        ?>
                        <a href="<?php echo esc_url($tier_cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
                    </form>

                    <?php
                    // Phase 5 — Commit 1 (قراءة/عرض فقط، بلا أي معالجة POST هنا).
                    // قراءة واحدة فقط لـget_all_tier_features() — لا N+1 (راجع
                    // docs/FEATURES-PHASE-5-SPEC.md §7).
                    $pge_tier_features_registry = PGE_Feature_Registry::all();
                    $pge_tier_features_raw = PGE_Tier_Features::get_all_tier_features($editing_tier_id);
                    $pge_tier_features_fetch_failed = ($pge_tier_features_raw === false);
                    $pge_tier_feature_overrides = [];
                    if (is_array($pge_tier_features_raw)) {
                        foreach ($pge_tier_features_raw as $pge_tier_feature_row) {
                            $pge_tier_feature_overrides[(string) $pge_tier_feature_row['feature_key']] = (string) $pge_tier_feature_row['feature_value'];
                        }
                    }
                    ?>

                    <h2><?php esc_html_e('إدارة ميزات المستوى', 'pgevents'); ?></h2>

                    <?php if ($pge_tier_features_fetch_failed): ?>
                        <div class="notice notice-error inline">
                            <p><?php esc_html_e('تعذّرت قراءة ميزات هذا المستوى من قاعدة البيانات — حاول تحديث الصفحة. لا تُعرَض قيم الميزات الآن.', 'pgevents'); ?></p>
                        </div>
                    <?php else: ?>
                        <p class="description"><?php esc_html_e('عند عدم وجود قيمة مخصَّصة لهذا المستوى لميزة ما، تُعرَض القيمة الافتراضية من سجل الميزات (Feature Registry) — هذه قيمة معروضة فقط لأغراض الواجهة، وليست صفاً مخزَّناً فعلياً في قاعدة البيانات إلا إن حُفِظ صراحة.', 'pgevents'); ?></p>

                        <form method="post">
                            <?php wp_nonce_field('pge_update_catalog_tier_features_' . $editing_tier_id, 'pge_catalog_tier_features_nonce'); ?>
                            <input type="hidden" name="pge_catalog_action" value="update_tier_features">
                            <input type="hidden" name="tier_id" value="<?php echo esc_attr($editing_tier_id); ?>">
                            <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                            <table class="form-table" role="presentation">
                                <?php foreach ($pge_tier_features_registry as $pge_feature_key => $pge_feature_def): ?>
                                    <?php
                                    $pge_feature_has_override = array_key_exists($pge_feature_key, $pge_tier_feature_overrides);
                                    $pge_feature_raw_value = $pge_feature_has_override
                                        ? $pge_tier_feature_overrides[$pge_feature_key]
                                        : $pge_feature_def['default'];
                                    // نفس دالة تفسير Resolver (Phase 3) — القيمة المعروضة هنا
                                    // مطابقة لما سيراه أي مستخدم فعلي عبر Default (راجع §7 من
                                    // docs/FEATURES-PHASE-5-SPEC.md).
                                    $pge_feature_display_value = pge_feature_resolver_interpret_by_type($pge_feature_def['type'], $pge_feature_raw_value);
                                    $pge_feature_field_id = 'pge_tier_feature_' . preg_replace('/[^a-z0-9_]/', '_', $pge_feature_key);
                                    ?>
                                    <tr>
                                        <th scope="row">
                                            <label for="<?php echo esc_attr($pge_feature_field_id); ?>"><?php echo esc_html($pge_feature_def['admin_label']); ?></label>
                                        </th>
                                        <td>
                                            <?php if ($pge_feature_def['type'] === 'boolean'): ?>
                                                <input type="checkbox" id="<?php echo esc_attr($pge_feature_field_id); ?>" name="tier_features[<?php echo esc_attr($pge_feature_key); ?>]" value="1" <?php checked((bool) $pge_feature_display_value); ?>>
                                            <?php else: ?>
                                                <input type="number" id="<?php echo esc_attr($pge_feature_field_id); ?>" name="tier_features[<?php echo esc_attr($pge_feature_key); ?>]" class="small-text" step="1" value="<?php echo esc_attr((int) $pge_feature_display_value); ?>">
                                            <?php endif; ?>

                                            <p class="description">
                                                <?php echo esc_html($pge_feature_def['description']); ?>
                                                <?php if (!$pge_feature_has_override): ?>
                                                    <br><em><?php esc_html_e('عند عدم وجود Override تُستخدَم القيمة الافتراضية من Feature Registry.', 'pgevents'); ?></em>
                                                <?php endif; ?>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>

                            <?php submit_button('حفظ ميزات المستوى', 'primary', 'submit_update_tier_features', false); ?>
                        </form>

                        <?php
                        // Phase 6 — Feature: نسخ ميزات المستوى إلى كل مستويات
                        // نفس الباقة (Admin Productivity فقط). قسم منفصل بصرياً
                        // تماماً عن نموذج الحفظ أعلاه، ولا يظهر إطلاقاً إن كانت
                        // هذه الباقة تحوي مستوى واحداً فقط ($tiers هنا هي كل
                        // مستويات هذه الباقة، محسوبة أصلاً في كل مسارات POST/GET
                        // التي تصل لهذه النقطة).
                        ?>
                        <?php if (is_array($tiers) && count($tiers) > 1): ?>
                            <hr>
                            <h2><?php esc_html_e('نسخ المميزات', 'pgevents'); ?></h2>
                            <p class="description"><?php esc_html_e('انسخ جميع المميزات المحفوظة لهذا المستوى إلى جميع مستويات هذه الباقة.', 'pgevents'); ?></p>

                            <form method="post" onsubmit="return confirm('<?php echo esc_js('سيتم استبدال ميزات كل المستويات الأخرى في هذه الباقة بميزات هذا المستوى الحالية المحفوظة. لا يمكن التراجع عن هذا الإجراء. هل تريد المتابعة؟'); ?>');">
                                <?php wp_nonce_field('pge_copy_catalog_tier_features_' . $editing_tier_id, 'pge_catalog_tier_copy_nonce'); ?>
                                <input type="hidden" name="pge_catalog_action" value="copy_tier_features">
                                <input type="hidden" name="tier_id" value="<?php echo esc_attr($editing_tier_id); ?>">
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                                <?php
                                // حقول مخفية تُطابق تماماً القيم المعروضة حالياً
                                // في نموذج "حفظ ميزات المستوى" أعلاه (نفس متغيرات
                                // $pge_tier_features_registry/$pge_tier_feature_overrides
                                // المحسوبة مرة واحدة أعلاه) — بما أن هذا نموذج
                                // (form) منفصل فعلياً في DOM، لا يمكن الاعتماد على
                                // حقول النموذج الآخر عند الإرسال؛ هذا يضمن أن
                                // "المستوى المصدر" يُحفَظ أولاً (الخطوة 5 في خطة
                                // التنفيذ) بنفس القيم المعروضة فعلياً على الشاشة.
                                ?>
                                <?php foreach ($pge_tier_features_registry as $pge_copy_field_key => $pge_copy_field_def): ?>
                                    <?php
                                    $pge_copy_field_has_override = array_key_exists($pge_copy_field_key, $pge_tier_feature_overrides);
                                    $pge_copy_field_raw_value = $pge_copy_field_has_override
                                        ? $pge_tier_feature_overrides[$pge_copy_field_key]
                                        : $pge_copy_field_def['default'];
                                    $pge_copy_field_display_value = pge_feature_resolver_interpret_by_type($pge_copy_field_def['type'], $pge_copy_field_raw_value);
                                    ?>
                                    <?php if ($pge_copy_field_def['type'] === 'boolean'): ?>
                                        <?php if ($pge_copy_field_display_value): ?>
                                            <input type="hidden" name="tier_features[<?php echo esc_attr($pge_copy_field_key); ?>]" value="1">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="hidden" name="tier_features[<?php echo esc_attr($pge_copy_field_key); ?>]" value="<?php echo esc_attr((int) $pge_copy_field_display_value); ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <?php submit_button('نسخ المميزات إلى جميع المستويات', 'secondary', 'submit_copy_tier_features', false); ?>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <h2><?php esc_html_e('مستويات الباقة', 'pgevents'); ?></h2>
                <?php if (empty($tiers)): ?>
                    <div class="notice notice-info inline">
                        <p><?php esc_html_e('لا توجد مستويات مضافة لهذه الباقة حتى الآن.', 'pgevents'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                                <th><?php esc_html_e('اسم المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('السعر', 'pgevents'); ?></th>
                                <th><?php esc_html_e('العملة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ربط سلة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحد الأقصى للمدعوين', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tiers as $tier): ?>
                                <tr>
                                    <td><?php echo absint($tier['id']); ?></td>
                                    <td><?php echo esc_html($tier['name']); ?></td>
                                    <td><code><?php echo esc_html($tier['tier_key']); ?></code></td>
                                    <td><?php echo esc_html($tier['price']); ?></td>
                                    <td><?php echo esc_html($tier['currency']); ?></td>
                                    <td>
                                        <?php
                                        $tier_salla_product_id = trim((string) ($tier['salla_product_id'] ?? ''));
                                        $tier_salla_sku = trim((string) ($tier['salla_sku'] ?? ''));
                                        $tier_salla_url_raw = trim((string) ($tier['salla_url'] ?? ''));
                                        $tier_salla_url = $tier_salla_url_raw !== ''
                                            ? esc_url($tier_salla_url_raw, ['https'])
                                            : '';
                                        $tier_salla_url_parts = $tier_salla_url !== '' ? wp_parse_url($tier_salla_url) : false;
                                        $has_salla_product_id = $tier_salla_product_id !== '';
                                        $has_salla_sku = $tier_salla_sku !== '';
                                        $has_valid_salla_url = is_array($tier_salla_url_parts)
                                            && strtolower((string) ($tier_salla_url_parts['scheme'] ?? '')) === 'https'
                                            && !empty($tier_salla_url_parts['host']);

                                        if ($has_salla_product_id && $has_salla_sku && $has_valid_salla_url) {
                                            $salla_link_status = 'مرتبط بالكامل';
                                        } elseif ($has_salla_product_id && $has_salla_sku) {
                                            $salla_link_status = 'معرّف وSKU';
                                        } elseif ($has_salla_product_id) {
                                            $salla_link_status = 'معرّف فقط';
                                        } elseif ($has_salla_sku) {
                                            $salla_link_status = 'SKU فقط';
                                        } elseif ($has_valid_salla_url) {
                                            $salla_link_status = 'رابط فقط';
                                        } else {
                                            $salla_link_status = 'غير مرتبط';
                                        }
                                        ?>
                                        <strong<?php echo $has_salla_sku ? ' title="' . esc_attr($tier_salla_sku) . '"' : ''; ?>><?php echo esc_html($salla_link_status); ?></strong>
                                        <?php if ($has_valid_salla_url): ?>
                                            <br><a href="<?php echo esc_url($tier_salla_url, ['https']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('فتح المنتج', 'pgevents'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($tier_status_labels[$tier['status']] ?? $tier['status']); ?></td>
                                    <td><?php echo absint($tier['sort_order']); ?></td>
                                    <td>
                                        <?php
                                        $tier_guest_limit_raw = $tier['guest_limit'] ?? null;
                                        echo (empty($tier_guest_limit_raw))
                                            ? esc_html__('بلا حد', 'pgevents')
                                            : esc_html((string) absint($tier_guest_limit_raw));
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $tier_edit_url = add_query_arg(
                                            [
                                                'page'      => 'pge-catalog-tiers',
                                                'plan_id'   => $selected_plan_id,
                                                'edit_tier' => absint($tier['id']),
                                            ],
                                            admin_url('admin.php')
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($tier_edit_url); ?>"><?php esc_html_e('تعديل', 'pgevents'); ?></a>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="pge_catalog_action" value="delete_tier">
                                            <input type="hidden" name="tier_id" value="<?php echo esc_attr(absint($tier['id'])); ?>">
                                            <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">
                                            <?php wp_nonce_field('pge_delete_catalog_tier_' . absint($tier['id']), 'pge_catalog_tier_delete_nonce'); ?>
                                            <?php submit_button('حذف', 'delete small', 'submit_delete_tier', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                                <th><?php esc_html_e('اسم المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('السعر', 'pgevents'); ?></th>
                                <th><?php esc_html_e('العملة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ربط سلة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحد الأقصى للمدعوين', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php
}

function pge_render_catalog_plans_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('غير مصرح لك بالوصول لهذه الصفحة.', 'pgevents'));
    }

    $notice_type = null;
    $notice_message = null;

    $form_plan_key = '';
    $form_name = '';
    $form_plan_type = 'personal';
    $form_status = 'active';
    $form_sort_order = '0';
    $form_features = '';

    $edit_plan = null;
    $delete_plan = null;
    $delete_post_handled = false;
    $edit_features_raw_override = null;

    $pge_parse_features_textarea = function ($raw_text) {
        $raw_text = (string) $raw_text;
        $lines = preg_split('/\r\n|\r|\n/', $raw_text);
        return is_array($lines) ? $lines : [];
    };

    $pge_decode_features_for_display = function ($stored_value) {
        if (!is_string($stored_value) || trim($stored_value) === '') {
            return '';
        }
        $decoded = json_decode($stored_value, true);
        if (!is_array($decoded)) {
            return '';
        }
        $lines = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $lines[] = $item;
            }
        }
        return implode("\n", $lines);
    };

    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'create_plan'
        && isset($_POST['submit_create_plan'])
    ) {
        check_admin_referer('pge_create_catalog_plan', 'pge_catalog_plan_nonce');

        $form_plan_key = isset($_POST['plan_key']) ? wp_unslash($_POST['plan_key']) : '';
        $form_name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $form_plan_type = isset($_POST['plan_type']) ? wp_unslash($_POST['plan_type']) : '';
        $form_status = isset($_POST['status']) ? wp_unslash($_POST['status']) : '';
        $form_sort_order = isset($_POST['sort_order']) ? wp_unslash($_POST['sort_order']) : '';
        $form_features = isset($_POST['features']) ? wp_unslash($_POST['features']) : '';

        $created_plan = PGE_Catalog::create_plan([
            'plan_key'   => $form_plan_key,
            'name'       => $form_name,
            'plan_type'  => $form_plan_type,
            'status'     => $form_status,
            'sort_order' => $form_sort_order,
            'features'   => $pge_parse_features_textarea($form_features),
        ]);

        if (is_array($created_plan)) {
            $notice_type = 'success';
            $notice_message = 'تمت إضافة الباقة بنجاح.';

            $form_plan_key = '';
            $form_name = '';
            $form_plan_type = 'personal';
            $form_status = 'active';
            $form_sort_order = '0';
            $form_features = '';
        } else {
            $notice_type = 'error';
            $notice_message = 'تعذر إضافة الباقة. تحقق من الحقول أو من عدم تكرار مفتاح الباقة.';
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'update_plan'
        && isset($_POST['submit_update_plan'])
    ) {
        check_admin_referer('pge_update_catalog_plan', 'pge_catalog_update_nonce');

        $update_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $update_plan_key = isset($_POST['plan_key']) ? wp_unslash($_POST['plan_key']) : '';
        $update_name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $update_plan_type = isset($_POST['plan_type']) ? wp_unslash($_POST['plan_type']) : '';
        $update_status = isset($_POST['status']) ? wp_unslash($_POST['status']) : '';
        $update_sort_order = isset($_POST['sort_order']) ? wp_unslash($_POST['sort_order']) : '';
        $update_features = isset($_POST['features']) ? wp_unslash($_POST['features']) : '';

        $existing_plan = ($update_plan_id > 0) ? PGE_Catalog::get_plan($update_plan_id) : null;

        if ($existing_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        } else {
            $updated_plan = PGE_Catalog::update_plan(
                $update_plan_id,
                [
                    'plan_key'   => $update_plan_key,
                    'name'       => $update_name,
                    'plan_type'  => $update_plan_type,
                    'status'     => $update_status,
                    'sort_order' => $update_sort_order,
                    'features'   => $pge_parse_features_textarea($update_features),
                ]
            );

            if (is_array($updated_plan)) {
                $notice_type = 'success';
                $notice_message = 'تم حفظ تعديلات الباقة بنجاح.';
                $edit_plan = $updated_plan;
            } else {
                $notice_type = 'error';
                $notice_message = 'تعذر حفظ التعديلات. تحقق من الحقول أو من عدم تكرار مفتاح الباقة.';
                $edit_plan = [
                    'id'         => $update_plan_id,
                    'plan_key'   => $update_plan_key,
                    'name'       => $update_name,
                    'plan_type'  => $update_plan_type,
                    'status'     => $update_status,
                    'sort_order' => $update_sort_order,
                ];
                $edit_features_raw_override = $update_features;
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'delete_plan'
        && isset($_POST['submit_delete_plan'])
    ) {
        check_admin_referer('pge_delete_catalog_plan', 'pge_catalog_delete_nonce');

        $delete_post_handled = true;

        $delete_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $existing_delete_plan = ($delete_plan_id > 0) ? PGE_Catalog::get_plan($delete_plan_id) : null;

        if ($existing_delete_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        } else {
            $deleted = PGE_Catalog::delete_plan($delete_plan_id);

            if ($deleted === true) {
                $notice_type = 'success';
                $notice_message = 'تم حذف الباقة بنجاح.';
                $delete_plan = null;
            } else {
                $notice_type = 'error';
                $notice_message = 'تعذر حذف الباقة. قد تكون مرتبطة بمستويات أو لم تعد موجودة.';
                $delete_plan = $existing_delete_plan;
            }
        }
    }

    if (
        $edit_plan === null
        && isset($_GET['action'])
        && isset($_GET['plan_id'])
        && wp_unslash($_GET['action']) === 'edit'
    ) {
        $edit_plan_id = absint(wp_unslash($_GET['plan_id']));

        if ($edit_plan_id > 0) {
            $edit_plan = PGE_Catalog::get_plan($edit_plan_id);
        }

        if ($edit_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        }
    }

    if (
        !$delete_post_handled
        && $delete_plan === null
        && isset($_GET['action'])
        && isset($_GET['plan_id'])
        && wp_unslash($_GET['action']) === 'delete'
    ) {
        $delete_plan_id = absint(wp_unslash($_GET['plan_id']));

        if ($delete_plan_id > 0) {
            $delete_plan = PGE_Catalog::get_plan($delete_plan_id);
        }

        if ($delete_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        }
    }

    $plans = PGE_Catalog::get_plans();

    $edit_features_text = '';
    if (is_array($edit_plan)) {
        $edit_features_text = ($edit_features_raw_override !== null)
            ? $edit_features_raw_override
            : $pge_decode_features_for_display($edit_plan['features'] ?? null);
    }

    $plan_type_labels = [
        'personal' => 'شخصية',
        'business' => 'أعمال',
    ];
    $status_labels = [
        'active' => 'نشطة',
        'inactive' => 'غير نشطة',
    ];
?>
    <div class="wrap">
        <h1><?php esc_html_e('إدارة الباقات', 'pgevents'); ?></h1>

        <?php if ($notice_type === 'success'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($notice_message); ?></p>
            </div>
        <?php elseif ($notice_type === 'error'): ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($notice_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_array($edit_plan)): ?>
            <h2><?php esc_html_e('تعديل الباقة', 'pgevents'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('pge_update_catalog_plan', 'pge_catalog_update_nonce'); ?>
                <input type="hidden" name="pge_catalog_action" value="update_plan">
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($edit_plan['id']); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_key"><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="pge_edit_plan_key" name="plan_key" class="regular-text" value="<?php echo esc_attr($edit_plan['plan_key']); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_name"><?php esc_html_e('اسم الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="pge_edit_plan_name" name="name" class="regular-text" value="<?php echo esc_attr($edit_plan['name']); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_type"><?php esc_html_e('نوع الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <select id="pge_edit_plan_type" name="plan_type">
                                <option value="personal" <?php selected($edit_plan['plan_type'], 'personal'); ?>><?php esc_html_e('شخصية', 'pgevents'); ?></option>
                                <option value="business" <?php selected($edit_plan['plan_type'], 'business'); ?>><?php esc_html_e('أعمال', 'pgevents'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_status"><?php esc_html_e('حالة الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <select id="pge_edit_plan_status" name="status">
                                <option value="active" <?php selected($edit_plan['status'], 'active'); ?>><?php esc_html_e('نشطة', 'pgevents'); ?></option>
                                <option value="inactive" <?php selected($edit_plan['status'], 'inactive'); ?>><?php esc_html_e('غير نشطة', 'pgevents'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="pge_edit_plan_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($edit_plan['sort_order']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge-plan-edit-features"><?php esc_html_e('مزايا الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <textarea id="pge-plan-edit-features" name="features" rows="10" class="large-text"><?php echo esc_textarea($edit_features_text); ?></textarea>
                            <p class="description"><?php esc_html_e('كل ميزة في سطر مستقل.', 'pgevents'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('حفظ التعديلات', 'primary', 'submit_update_plan'); ?>
                <?php
                $cancel_url = add_query_arg(['page' => 'pge-catalog-plans'], admin_url('admin.php'));
                ?>
                <a href="<?php echo esc_url($cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
            </form>
        <?php endif; ?>

        <?php if (is_array($delete_plan)): ?>
            <h2><?php esc_html_e('تأكيد حذف الباقة', 'pgevents'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: plan name */
                    esc_html__('أنت على وشك حذف الباقة: %s', 'pgevents'),
                    esc_html($delete_plan['name'])
                );
                ?>
            </p>
            <p>
                <?php esc_html_e('مفتاح الباقة:', 'pgevents'); ?>
                <?php echo esc_html($delete_plan['plan_key']); ?>
            </p>
            <p>
                <strong><?php esc_html_e('لا يمكن حذف الباقة إذا كانت مرتبطة بأي مستوى.', 'pgevents'); ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field('pge_delete_catalog_plan', 'pge_catalog_delete_nonce'); ?>
                <input type="hidden" name="pge_catalog_action" value="delete_plan">
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($delete_plan['id']); ?>">

                <?php submit_button('تأكيد الحذف', 'delete', 'submit_delete_plan'); ?>
                <?php
                $delete_cancel_url = add_query_arg(['page' => 'pge-catalog-plans'], admin_url('admin.php'));
                ?>
                <a href="<?php echo esc_url($delete_cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
            </form>
        <?php endif; ?>

        <h2><?php esc_html_e('إضافة باقة جديدة', 'pgevents'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('pge_create_catalog_plan', 'pge_catalog_plan_nonce'); ?>
            <input type="hidden" name="pge_catalog_action" value="create_plan">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="pge_plan_key"><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pge_plan_key" name="plan_key" class="regular-text" value="<?php echo esc_attr($form_plan_key); ?>" required>
                        <p class="description"><?php esc_html_e('مفتاح تقني فريد للباقة، مثل: classic أو business-pro.', 'pgevents'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_name"><?php esc_html_e('اسم الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pge_plan_name" name="name" class="regular-text" value="<?php echo esc_attr($form_name); ?>" required>
                        <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: حلوة كلاسيك.', 'pgevents'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_type"><?php esc_html_e('نوع الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <select id="pge_plan_type" name="plan_type">
                            <option value="personal" <?php selected($form_plan_type, 'personal'); ?>><?php esc_html_e('شخصية', 'pgevents'); ?></option>
                            <option value="business" <?php selected($form_plan_type, 'business'); ?>><?php esc_html_e('أعمال', 'pgevents'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_status"><?php esc_html_e('حالة الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <select id="pge_plan_status" name="status">
                            <option value="active" <?php selected($form_status, 'active'); ?>><?php esc_html_e('نشطة', 'pgevents'); ?></option>
                            <option value="inactive" <?php selected($form_status, 'inactive'); ?>><?php esc_html_e('غير نشطة', 'pgevents'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="pge_plan_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($form_sort_order); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge-plan-create-features"><?php esc_html_e('مزايا الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <textarea id="pge-plan-create-features" name="features" rows="10" class="large-text"><?php echo esc_textarea($form_features); ?></textarea>
                        <p class="description"><?php esc_html_e('كل ميزة في سطر مستقل.', 'pgevents'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button('إضافة الباقة', 'primary', 'submit_create_plan'); ?>
        </form>

        <h2><?php esc_html_e('الباقات الحالية', 'pgevents'); ?></h2>
        <?php if (empty($plans)): ?>
            <p><?php esc_html_e('لا توجد باقات مضافة حتى الآن.', 'pgevents'); ?></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                        <th><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('اسم الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('المزايا', 'pgevents'); ?></th>
                        <th><?php esc_html_e('النوع', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        $row_features_count = 0;
                        if (is_string($plan['features']) && trim($plan['features']) !== '') {
                            $row_features_decoded = json_decode($plan['features'], true);
                            if (is_array($row_features_decoded)) {
                                $row_features_count = count($row_features_decoded);
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo absint($plan['id']); ?></td>
                            <td><?php echo esc_html($plan['plan_key']); ?></td>
                            <td><?php echo esc_html($plan['name']); ?></td>
                            <td>
                                <?php if ($row_features_count > 0): ?>
                                    <?php
                                    printf(
                                        /* translators: %d: features count */
                                        esc_html__('%d ميزات', 'pgevents'),
                                        (int) $row_features_count
                                    );
                                    ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($plan_type_labels[$plan['plan_type']] ?? $plan['plan_type']); ?></td>
                            <td><?php echo esc_html($status_labels[$plan['status']] ?? $plan['status']); ?></td>
                            <td><?php echo absint($plan['sort_order']); ?></td>
                            <td>
                                <?php
                                $edit_url = add_query_arg(
                                    [
                                        'page' => 'pge-catalog-plans',
                                        'action' => 'edit',
                                        'plan_id' => absint($plan['id']),
                                    ],
                                    admin_url('admin.php')
                                );
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('تعديل', 'pgevents'); ?></a>
                                |
                                <?php
                                $delete_url = add_query_arg(
                                    [
                                        'page' => 'pge-catalog-plans',
                                        'action' => 'delete',
                                        'plan_id' => absint($plan['id']),
                                    ],
                                    admin_url('admin.php')
                                );
                                ?>
                                <a href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('حذف', 'pgevents'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                        <th><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('اسم الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('المزايا', 'pgevents'); ?></th>
                        <th><?php esc_html_e('النوع', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
<?php
}

require_once PGE_PATH . 'includes/event-factory.php';
require_once PGE_PATH . 'includes/admin-mods.php';
require_once PGE_PATH . 'includes/class-pge-packages.php';
include_once PGE_PATH . 'includes/ajax.php';
require_once PGE_PATH . 'includes/event-guests.php';


// أضف هذا السطر هنا (مهم جداً لحل خطأ 500)
require_once PGE_PATH . 'includes/class-mon-events-users.php';

// 2. المحرك الرئيسي للربط مع سلة (Webhook Handler)
require_once PGE_PATH . 'includes/class-salla-handler.php';

// 3. تكامل واتساب — يُحمَّل المزوّد النشط فقط (Cartat أو UltraMsg). ملاحظة:
// class-pge-cartat-transport.php (طبقة النقل المشتركة، Option B) تُحمَّل
// الآن مبكراً بالأعلى (قرب Phase 8 Supervisor Management) لأن PGE_Supervisor_
// Invitation_Delivery تحتاجها هناك — require_once هنا (داخل class-cartat-
// handler.php نفسها) لا يُعيد تحميلها، فقط يضمن توفرها إن لم يسبق تحميلها.
$_pge_wa_provider = get_option('pge_wa_provider', 'cartat');
if ($_pge_wa_provider === 'ultramsg') {
    require_once PGE_PATH . 'includes/class-ultramsg-handler.php';
} else {
    require_once PGE_PATH . 'includes/class-cartat-handler.php';
}

// 2. استدعاء نظام التوجيه (Routing) - بديل الصفحات التقليدية
require_once PGE_PATH . 'includes/routing.php';

// 3. تحديث الروابط عند التفعيل لضمان عمل الـ Endpoints
register_activation_hook(__FILE__, function () {
    // 1. تسجيل نوع المنشورات
    pge_register_event_post_type();
    add_rewrite_rule('^e/([0-9]+)/?$', 'index.php?pge_short_event=$matches[1]', 'top');
    add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top');
    add_rewrite_rule('^create-event/?$', 'index.php?pge_action=create_event', 'top');
    add_rewrite_rule('^edit-event/([0-9]+)/?$', 'index.php?pge_action=edit_event&event_id=$matches[1]', 'top');
    add_rewrite_rule('^event-manage/([0-9]+)/?$', 'index.php?pge_action=event_manage&event_id=$matches[1]', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pge_action=login', 'top');
    add_rewrite_rule('^register/?$', 'index.php?pge_action=register', 'top');
    add_rewrite_rule('^forgot-password/?$', 'index.php?pge_action=forgot_password', 'top');
    flush_rewrite_rules();
    update_option('pge_rewrite_version', '1.0.5');
});

// auto-flush عند تغيير الإصدار (بدون deactivate/activate)
add_action('init', function () {
    if (get_option('pge_rewrite_version') !== '1.0.5') {
        flush_rewrite_rules();
        update_option('pge_rewrite_version', '1.0.5');
    }
}, 99);

// 4. تحديث الروابط عند التعطيل (تنظيف)
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
