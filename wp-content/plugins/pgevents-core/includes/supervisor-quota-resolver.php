<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Supervisor Quota Resolver — Entry Check-in Supervisors، Phase 1
 * ============================================================================
 * "Supervisor Entitlement Foundation" RFC، Requirement 2: "Create a dedicated
 * resolver similar to Event Quota... must return: allowed, used, remaining,
 * source, mode, without performing UI logic." بلا أي منطق واجهة، بلا AJAX،
 * بلا صلاحيات — دالة التفويض/المصادقة الفعلية (اسمها المُخطَّط مستقبلاً:
 * pge_is_active_supervisor_for_event($event_id)، بلا معامل هاتف، مبنية على
 * هوية موثوقة لا مُدخَل من المستدعي) مؤجَّلة صراحةً لمرحلة Phase 3/4، غير
 * موجودة هنا ولا في أي ملف حتى تاريخه. دالة الـLookup البحتة الحالية
 * (pge_has_active_supervisor_assignment()، Phase 2،
 * class-pge-supervisor-assignment-service.php) ليست بديلاً عنها ولا يجوز
 * استخدامها كحارس وصول.
 *
 * Requirement 1: "Do NOT invent a different entitlement mechanism" — يُعاد
 * استخدام نفس معمارية Registry → Tier Features → Resolver → Snapshot
 * الموجودة أصلاً حرفياً، لا مسار جديد مواز.
 *
 * اكتشاف معماري حاسم لهذا الـCommit (مهم لفهم لماذا لا تعديل على
 * Mon_Events_Users::activate_catalog_tier() هنا إطلاقاً رغم Requirement 4):
 * `_mon_package_features` (Snapshot الميزات العام) يُكتَب فعلياً منذ Phase 4
 * (Commit 2، class-mon-events-users.php::build_tier_features_snapshot())
 * عند **كل** تفعيل Catalog، ويشمل **كل** مفتاح من الـ19 المُعرَّفة في
 * PGE_Feature_Registry::all() بلا أي استثناء بحسب lifecycle (مؤكَّد عبر
 * docs/DECISION-LOG.md، الأسطر 195-196: "Registry نفسه لا يستهلك lifecycle
 * كفلتر تشغيلي في أي كود قائم"). هذا يعني أن admin_supervisor_limit كان
 * يُكتَب فعلياً داخل Snapshot كل مستخدم Catalog منذ اعتماد Phase 4 — قبل هذا
 * الـCommit بمراحل كاملة — بصرف النظر عن كون lifecycle له كانت 'planned'.
 *
 * إذن Requirement 4 ("When a Catalog activation occurs, write the supervisor
 * entitlement into Snapshot... No runtime Tier reads") محقَّق فعلاً ببنية
 * تحتية قائمة مسبقاً؛ إضافة مفتاح User Meta مُخصَّص جديد (كـ_mon_supervisor_limit
 * مستقل عن _mon_package_features) كانت ستُنشئ مصدرَي حقيقة مكرَّرين لنفس
 * القيمة بلا أي داعٍ معماري — يخالف صراحة "Do NOT invent a different
 * entitlement mechanism" في Requirement 1. القرار هنا: هذا الـResolver يقرأ
 * القيمة حصراً من Snapshot العام الموجود أصلاً (_mon_package_features
 * ['admin_supervisor_limit'])، بلا أي كتابة جديدة، بلا أي تعديل على
 * activate_catalog_tier().
 *
 * "No runtime Tier reads" هنا أكثر صرامة حتى من الـResolver العام
 * (pge_get_user_feature_value() في feature-resolver.php): تلك الدالة تحتوي
 * على Fallback دفاعي حيّ لقراءة mon_tier_features مباشرة إن غاب المفتاح عن
 * Snapshot (§8 الترتيب الإلزامي) — وهو تحديداً "قراءة Tier وقت التشغيل" التي
 * يمنعها RFC صراحة لهذه الميزة تحديداً. لذا هذا الملف **لا يستدعي**
 * pge_get_user_feature_value()/pge_feature_resolver_resolve_raw_value() —
 * يقرأ Snapshot مباشرة عبر get_user_meta() فقط، ويُعامِل غياب المفتاح فيه
 * كخطأ تكامل بيانات صريح (WP_Error)، لا كإشارة للانتقال لأي مصدر حيّ آخر.
 *
 * — Legacy (_mon_package_source !== 'catalog'، بما فيها الفراغ): لا Snapshot
 *   ميزات لهذا المستخدم إطلاقاً تحت أي ظرف (سياسة Phase 4 القائمة: Legacy لا
 *   يُستدعى activate_catalog_tier() له أبداً). لا مصدر بيانات حقيقي لعدد
 *   مشرفين هنا (تسعير Legacy التاريخي `admin_perms_price` سعر فقط، لا رقم
 *   استحقاق فعلي — docs/PACKAGE-FEATURE-MATRIX.md). القرار: allowed = 0
 *   دائماً لمستخدم Legacy — لا اختراع رقم، بنفس روح "لا تخمين" العامة لهذا
 *   المشروع.
 *
 * — Catalog (_mon_package_source === 'catalog'): مفتاح admin_supervisor_limit
 *   غائب عن Snapshot يعني تفعيلاً سابقاً لهذا Phase 1 (Snapshot بُني قبل
 *   ترقية lifecycle، أو أي حالة تكامل بيانات أخرى) — خطأ تكامل صريح
 *   (WP_Error)، بلا أي رجوع ضمني لـ0 أو لأي قيمة افتراضية بديلة، بنفس فلسفة
 *   pge_resolve_event_quota_status() لحالة _mon_credit_cycle_id المفقودة.
 *
 * "used" (القرار التجاري النهائي: لكل مناسبة — "PER EVENT"، لا لكل مستخدم):
 * عدّ صفوف mon_event_supervisors لهذه المناسبة تحديداً (event_id) بحالة غير
 * revoked/expired فقط — لا Invitation Flow فعلي بعد (Requirement 8)، فالجدول
 * فارغ عملياً لكل المناسبات في هذه المرحلة؛ الدالة جاهزة للاستهلاك الفعلي في
 * مرحلة لاحقة بلا أي تعديل بنيوي إضافي.
 *
 * لا استدعاء لهذا الملف من أي مسار آخر بعد (لا AJAX، لا UI، لا Cron) — صفر
 * استهلاك، بنفس نمط كل "Commit تأسيس" سابق في هذا المشروع.
 */

if (!function_exists('pge_count_active_event_supervisors')) {
    /**
     * عدّ إسنادات المشرفين "الفعّالة" لمناسبة واحدة — أي صف حالته ليست
     * revoked أو expired. لا فرز، لا حدّ أقصى للنتائج (COUNT بحت).
     *
     * @param int $event_id
     * @return int
     */
    function pge_count_active_event_supervisors($event_id)
    {
        global $wpdb;

        $event_id = (int) $event_id;
        $table = $wpdb->prefix . 'mon_event_supervisors';

        $inactive_statuses = ['revoked', 'expired'];
        $placeholders = implode(',', array_fill(0, count($inactive_statuses), '%s'));

        $query_args = array_merge([$event_id], $inactive_statuses);

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status NOT IN ($placeholders)",
                $query_args
            )
        );

        return (int) $count;
    }
}

if (!function_exists('pge_resolve_supervisor_quota_status')) {
    /**
     * Public API — Entry Check-in Supervisors، Phase 1، Requirement 2.
     *
     * دالة معلوماتية بحتة (Read-Only): لا كتابة، لا إنشاء صف، لا تغيير حالة،
     * لا منطق واجهة. لا إنفاذ (Enforcement) هنا إطلاقاً — الإنفاذ الفعلي عند
     * إنشاء إسناد مشرف جديد مرحلة لاحقة تماماً، خارج نطاق Phase 1 عمداً
     * (Requirement 5/8 يمنعان صراحة أي منطق دعوة/UI في هذا الـCommit).
     *
     * @param int $event_id
     * @return array{
     *   mode: 'legacy'|'limited',
     *   source: 'legacy_unsupported'|'catalog_snapshot',
     *   allowed: int,
     *   used: int,
     *   remaining: int
     * }|WP_Error
     *   نجاح: المصفوفة أعلاه.
     *   فشل: WP_Error('invalid_event_id'|'event_not_found'|'event_owner_missing'|
     *         'supervisor_snapshot_missing', ...).
     */
    function pge_resolve_supervisor_quota_status($event_id)
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return new WP_Error('invalid_event_id', 'معرّف مناسبة غير صالح لحلّ حصة مشرفي الدخول.');
        }

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'pge_event') {
            return new WP_Error('event_not_found', 'المناسبة المطلوبة غير موجودة.');
        }

        $owner_id = (int) $event->post_author;
        if ($owner_id <= 0) {
            return new WP_Error('event_owner_missing', 'لا يوجد مالك صالح لهذه المناسبة.');
        }

        $package_source = (string) get_user_meta($owner_id, '_mon_package_source', true);

        if ($package_source !== 'catalog') {
            $used = pge_count_active_event_supervisors($event_id);

            return [
                'mode'      => 'legacy',
                'source'    => 'legacy_unsupported',
                'allowed'   => 0,
                'used'      => $used,
                'remaining' => 0,
            ];
        }

        $feature_snapshot = get_user_meta($owner_id, '_mon_package_features', true);

        if (!is_array($feature_snapshot) || !array_key_exists('admin_supervisor_limit', $feature_snapshot)) {
            return new WP_Error(
                'supervisor_snapshot_missing',
                'حساب Catalog هذا في حالة غير متسقة: لا يوجد مفتاح admin_supervisor_limit ضمن Snapshot ميزات الباقة.'
            );
        }

        $allowed_raw = $feature_snapshot['admin_supervisor_limit'];
        $allowed = (is_int($allowed_raw) || (is_string($allowed_raw) && preg_match('/^[0-9]+$/', trim($allowed_raw))))
            ? (int) $allowed_raw
            : 0;
        if ($allowed < 0) {
            $allowed = 0;
        }

        $used = pge_count_active_event_supervisors($event_id);

        return [
            'mode'      => 'limited',
            'source'    => 'catalog_snapshot',
            'allowed'   => $allowed,
            'used'      => $used,
            'remaining' => max(0, $allowed - $used),
        ];
    }
}
