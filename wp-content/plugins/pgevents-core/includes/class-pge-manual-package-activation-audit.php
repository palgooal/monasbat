<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Manual Package Activation Audit — "التفعيل اليدوي للباقات من لوحة
 * الإدارة" RFC
 * ============================================================================
 * الوسيط الوحيد للكتابة/القراءة على {$wpdb->prefix}pge_manual_package_
 * activation_audit. Append-Only بحت: لا UPDATE ولا DELETE على أي صف إطلاقاً —
 * record() تُنفِّذ INSERT فقط (نفس فلسفة PGE_Supervisor_Management_Audit/
 * PGE_Invitation_Management_Audit).
 *
 * لا بيانات حساسة تُخزَّن هنا مطلقاً: لا بريد، لا جوال، لا اسم كامل — فقط
 * معرّفات رقمية (target_user_id/actor_user_id) ونصوص وصفية قصيرة (مصدر
 * الباقة/معرّفها/السبب الذي كتبه الأدمن بنفسه).
 *
 * هذا الكلاس **لا يُنفِّذ أي تفعيل فعلي** — يُستدعى فقط بعد نجاح الاستدعاء
 * الحقيقي لـ Mon_Events_Users::activate_catalog_tier() أو
 * Mon_Events_Users::activate_user_package() من includes/manual-package-
 * activation-ajax.php، كسجل تدقيق مستقل تماماً عن منطق التفعيل نفسه.
 */
class PGE_Manual_Package_Activation_Audit
{
    /**
     * قيم package_source المسموحة — نفس القيمتين الفعليتين المستخدمتين في
     * _mon_package_source (Catalog) وفي الفرع القديم (Legacy)، لا قيم جديدة.
     */
    const SOURCES = ['catalog', 'legacy'];

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_manual_package_activation_audit';
    }

    /**
     * تسجيل حدث تدقيق واحد — INSERT فقط، بلا أي تعديل على أي صف قائم.
     *
     * @param int    $target_user_id      المستخدم الذي فُعِّلت له الباقة.
     * @param int    $actor_user_id       الأدمن الذي نفَّذ الإجراء.
     * @param string $package_source      'catalog'|'legacy'.
     * @param string $package_identifier  وصف مختصر للباقة (plan_key، أو "plan_id:tier_id").
     * @param bool   $had_active_package  هل كان لدى المستخدم باقة فعالة قبل هذا التفعيل؟
     * @param string $reason              سبب التفعيل اليدوي (إلزامي من الواجهة).
     * @return bool
     */
    public static function record($target_user_id, $actor_user_id, $package_source, $package_identifier, $had_active_package, $reason = ''): bool
    {
        $target_user_id = (int) $target_user_id;
        $actor_user_id = (int) $actor_user_id;
        $package_source = is_scalar($package_source) ? (string) $package_source : '';
        $package_identifier = is_scalar($package_identifier) ? (string) $package_identifier : '';
        $reason = is_scalar($reason) ? (string) $reason : '';

        if ($target_user_id <= 0 || $actor_user_id <= 0) {
            return false;
        }

        if (!in_array($package_source, self::SOURCES, true)) {
            return false;
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'target_user_id'      => $target_user_id,
                'actor_user_id'       => $actor_user_id,
                'package_source'      => $package_source,
                'package_identifier'  => $package_identifier,
                'had_active_package'  => $had_active_package ? 1 : 0,
                'reason'              => $reason,
                'created_at'          => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );

        return (bool) $inserted;
    }

    /**
     * سجل التدقيق الكامل لمستخدم واحد — قراءة فقط، مُرتَّبة زمنياً (الأحدث أولاً).
     *
     * @return array<int,array>
     */
    public static function list_for_user($target_user_id, $limit = 50): array
    {
        $target_user_id = (int) $target_user_id;
        $limit = max(1, min(500, (int) $limit));
        if ($target_user_id <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE target_user_id = %d ORDER BY id DESC LIMIT %d",
                $target_user_id,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
