<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Tier Features — Repository الوصول الخام لجدول mon_tier_features
 * ============================================================================
 * Phase 2 — Commit 2 (Tier Features Storage)، وفق docs/PACKAGE-FEATURE-MATRIX.md
 * §5 وdocs/FEATURES-PHASE-2-SPEC.md §7. هذه الطبقة (Repository) هي الوسيط
 * الوحيد المسموح للتعامل مع جدول {$wpdb->prefix}mon_tier_features (راجع
 * class-mon-catalog-schema.php لتعريف الجدول والقيد UNIQUE (tier_id, feature_key)،
 * أُضيف في 1.8.0).
 *
 * تخزين خام فقط — بلا أي تفسير نوع (boolean/integer/percentage، عمل الـResolver
 * في Phase 3)، بلا أي تحقق من وجود feature_key داخل Feature Registry (عمل
 * الإدارة في Phase 5)، بلا Snapshot، بلا User Meta، بلا Cartat/UltraMsg/Salla،
 * بلا Legacy. لا تُستدعى دوال هذا الملف حالياً من أي مسار آخر — صفر استهلاك.
 *
 * عقود قيم الإرجاع الأربعة معتمدة صراحة عبر DEC-002 في docs/DECISION-LOG.md.
 */

class PGE_Tier_Features
{
    /**
     * اسم جدول ميزات المستويات (mon_tier_features) مع بادئة $wpdb->prefix.
     */
    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_tier_features';
    }

    /**
     * قراءة القيمة الخام لميزة واحدة لـTier محدَّد. لا تفسير نوع — القيمة
     * تُعاد كما خُزِّنت حرفياً (نص).
     *
     * عقد الإرجاع (DEC-002):
     * - string إذا وُجد الصف المطابق لـ(tier_id, feature_key).
     * - null إذا لم يوجد الصف.
     * - false إذا فشل استعلام قاعدة البيانات.
     *
     * $wpdb->get_row() يُعيد null في كلتا حالتي "لا صف مطابق" و"فشل الاستعلام"
     * (لا تمييز بينهما عبر القيمة المُعادة وحدها في wpdb الحقيقية) — لذا عند
     * null نتحقق من $wpdb->last_error لتمييز الفشل الفعلي عن عدم الوجود
     * المشروع، بنفس أسلوب فحص last_error المُتَّبع فعلاً في
     * class-pge-invitation-credit-ledger.php لتمييز حالات خاصة مشابهة.
     */
    public static function get_tier_feature_value($tier_id, $feature_key)
    {
        global $wpdb;
        $table = self::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT feature_value FROM $table WHERE tier_id = %d AND feature_key = %s LIMIT 1",
                (int) $tier_id,
                (string) $feature_key
            ),
            ARRAY_A
        );

        if ($row === null) {
            $last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            if ($last_error !== '') {
                return false;
            }
            return null;
        }

        return (string) $row['feature_value'];
    }

    /**
     * قراءة كل صفوف الميزات الخاصة بـTier محدَّد فقط (عزل كامل بين Tiers
     * مختلفة عبر tier_id). لا تفسير نوع لأي قيمة داخل الصفوف.
     *
     * عقد الإرجاع (DEC-002):
     * - array إذا نجح الاستعلام (قد تكون [] إذا لم توجد أي صفوف).
     * - false إذا فشل استعلام قاعدة البيانات.
     *
     * $wpdb->get_results() الحقيقية تُعيد null فقط عند فشل الاستعلام نفسه،
     * وتُعيد array (فارغة أو لا) عند النجاح بصرف النظر عن عدد الصفوف — لذا
     * null === فشل هنا بلا لبس، بنفس أسلوب التحقق المُتَّبع فعلاً في
     * class-mon-catalog-schema.php (مثال: upgrade_to_1_5_0()، upgrade_to_1_7_0()).
     */
    public static function get_all_tier_features($tier_id)
    {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE tier_id = %d",
                (int) $tier_id
            ),
            ARRAY_A
        );

        if ($rows === null) {
            return false;
        }

        return $rows;
    }

    /**
     * Upsert خام وفق القيد UNIQUE (tier_id, feature_key) — بلا أي فحص نوع أو
     * Validation على $raw_value. محاولة INSERT مباشرة أولاً (بنفس أسلوب
     * PGE_Invitation_Credit_Ledger::create_reservation()/
     * PGE_Replacement_Entitlements::create_entitlement() — الاعتماد على القيد
     * الذري في قاعدة البيانات نفسها لا فحص "SELECT ثم قرار" منفصل)؛ عند فشل
     * الإدخال بسبب تعارض المفتاح الفريد (يُكتشَف عبر $wpdb->last_error، بنفس
     * أسلوب تمييز "duplicate" في الملفين المذكورين)، يُنفَّذ UPDATE على الصف
     * الموجود بدلاً من ذلك.
     *
     * عقد الإرجاع (DEC-002):
     * - true عند نجاح عملية الإدراج أو التحديث.
     * - false عند فشل الكتابة في قاعدة البيانات.
     */
    public static function set_tier_feature_value($tier_id, $feature_key, $raw_value)
    {
        global $wpdb;
        $table = self::table_name();

        $normalized_tier_id = (int) $tier_id;
        $normalized_feature_key = (string) $feature_key;
        $normalized_value = (string) $raw_value;
        $now = current_time('mysql', true);

        $inserted = $wpdb->insert(
            $table,
            [
                'tier_id'       => $normalized_tier_id,
                'feature_key'   => $normalized_feature_key,
                'feature_value' => $normalized_value,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted) {
            return true;
        }

        $last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        $looks_like_duplicate_key = $last_error !== '' && stripos($last_error, 'duplicate') !== false;

        if (!$looks_like_duplicate_key) {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'feature_value' => $normalized_value,
                'updated_at'    => $now,
            ],
            [
                'tier_id'     => $normalized_tier_id,
                'feature_key' => $normalized_feature_key,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $updated !== false;
    }

    /**
     * حذف صف ميزة واحدة لـTier محدَّد فقط — لا منطق آخر.
     *
     * عقد الإرجاع (DEC-002):
     * - true عند نجاح تنفيذ الحذف، سواء حُذف صف فعلياً أو لم يكن موجوداً
     *   أصلاً (Idempotent).
     * - false عند فشل الاستعلام.
     *
     * $wpdb->delete() الحقيقية تُعيد عدد الصفوف المحذوفة (قد يكون 0 إذا لم
     * يوجد صف مطابق) عند نجاح الاستعلام، أو false عند فشله — 0 !== false في
     * PHP (مقارنة صارمة)، فيُعامَل "لا يوجد صف" كنجاح Idempotent تلقائياً
     * بلا أي فرع إضافي.
     */
    public static function delete_tier_feature($tier_id, $feature_key)
    {
        global $wpdb;
        $table = self::table_name();

        $deleted = $wpdb->delete(
            $table,
            [
                'tier_id'     => (int) $tier_id,
                'feature_key' => (string) $feature_key,
            ],
            ['%d', '%s']
        );

        return $deleted !== false;
    }
}
