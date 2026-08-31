<?php
defined('ABSPATH') || exit;

/**
 * Class PGE_Package_Activation_Email
 *
 * E2E-02 FIX PASS 5A — Post-Purchase Activation Email + Durable Per-Order
 * Replay Deduplication.
 *
 * نطاق ضيق ومقصود: بريد معاملاتي (transactional) مع منع دائم لإعادة تشغيل
 * الطلب نفسه عند نجاح تفعيل باقة Catalog فعلياً —
 * يُستدعى حصراً من Mon_Salla_Handler::process_catalog_match()، بعد أن يكون
 * Mon_Events_Users::activate_catalog_tier() قد أعاد نتيجة ناجحة (ليست
 * WP_Error)، وفي مسار activate فقط (لا يُستدعى إطلاقاً من مسار
 * deactivate/cancellation). هذا الكلاس لا يُشارك في، ولا يُغيّر، أي من:
 * التحقق من توقيع Salla، مطابقة الحالة/الحدث، مطابقة العميل، مطابقة
 * SKU/المنتج، التحقق من السعر/العملة، أو منطق activate_catalog_tier()
 * التجاري نفسه (الرصيد/الحدود) — كل ذلك يبقى كما هو تماماً، هذا الكلاس
 * يُستهلَك بعد اكتمالها بنجاح فقط.
 *
 * حدود صارمة ومتعمَّدة (بنفس روح PGE_Registration_Email):
 * - لا علاقة إطلاقاً بـCartat/UltraMsg (واتساب) ولا بـpge_message_log — بريد
 *   حساب/باقة عبر wp_mail() فقط.
 * - لا Hook عريض — استدعاء صريح واحد فقط من نقطة النجاح المُثبَتة في
 *   process_catalog_match().
 * - لا كلمة مرور (Salla أو WordPress)، لا رابط إعادة تعيين كلمة مرور، لا
 *   Nonce، لا Auth Token، لا أي جزء من الحمولة الخام لطلب الـWebhook (Raw
 *   Salla Payload) يظهر في البريد أو في السجلّات — فقط بيانات Hilwah
 *   الموثوقة بعد التفعيل (اسم العرض، اسم الباقة/المستوى، الحدود، رابط لوحة
 *   التحكم).
 * - فشل الإرسال (wp_mail() ترجع false) لا يُفشِل تفعيل الباقة ولا استجابة
 *   الـWebhook — التفعيل نجح بالفعل قبل استدعاء هذه الدالة؛ فقط يُسجَّل
 *   الفشل بأمان عبر error_log() (user_id/order_id/سبب مختصر فقط، بلا نص
 *   الرسالة أو البريد الإلكتروني).
 *
 * منع دائم لإعادة تشغيل كل Salla order (Durable Per-Order Replay Deduplication):
 * - لكل طلب علامة usermeta مستقلة مشتقة من SHA-256 لمعرّف الطلب المطبّع؛
 *   لا يُكشَف معرّف الطلب الخام في المفتاح ولا تستبدل علامة طلب علامة آخر.
 * - قبل الإرسال: تُقرَأ علامة الطلب المحدد (داخل قفل — راجع أدناه)؛ إن
 *   وُجدت يُتخطّى الإرسال بأمان (لا خطأ، لا محاولة).
 * - بعد نجاح wp_mail() فقط: تُكتَب علامة الطلب المحدد وتُقرأ لتأكيد دوامها.
 * - عند فشل wp_mail(): لا تُكتَب العلامة إطلاقاً — إعادة إرسال لاحقة لنفس
 *   الـWebhook (أو حتى طلب مختلف لاحقاً) قد تُعيد المحاولة بأمان.
 * - طلب Salla جديد فعلياً (order_id مختلف) لنفس المستخدم/الباقة/المستوى
 *   ليس تكراراً — يُرسَل بريد تفعيل جديد وتبقى علامات الطلبات السابقة.
 * - wp_mail() وusermeta غير مرتبطين بمعاملة واحدة. لذلك إذا نجح الإرسال
 *   وتعذر تأكيد العلامة، تُسجّل الحالة صراحة وتُعاد false؛ قد تكون الرسالة
 *   وصلت فعلاً وقد تُرسل إعادة المحاولة نسخة أخرى. لا ادعاء exactly-once.
 *
 * حماية التزامن (Concurrent Webhook Requests):
 * - القراءة-ثم-الكتابة العادية لـusermeta وحدها غير ذرّية (Race Condition
 *   حقيقي بين طلبَي Webhook متزامنَين لنفس الطلب). لهذا تُغلَّف نافذة
 *   "قراءة العلامة → إرسال → كتابة العلامة" بالكامل بقفل MySQL مُسمّى
 *   (GET_LOCK/RELEASE_LOCK عبر $wpdb) — بنفس الاصطلاح الحرفي المُستخدَم فعلاً
 *   في عشرات المواضع الأخرى بهذا المشروع (مثال: PGE_Checkin_Recorder::
 *   build_lock_name()، PGE_Invitation_Credit_Ledger::build_credit_lock_name()،
 *   event-factory.php). هذا يمنع محاولتَي الإرسال المتزامنتين للطلب نفسه
 *   طالما بقيت افتراضات هذا الاصطلاح نفسها
 *   صحيحة في بيئة التشغيل (خادم MySQL واحد لا يُوزَّع الاتصال بين عدة
 *   خوادم بلا تنسيق)، وهو نفس الافتراض الذي يعتمد عليه فعلاً كل قفل آخر في
 *   هذا المشروع اليوم — وهذا يوفّر منع إعادة تشغيل دائم لكل طلب تحت افتراض
 *   MySQL الواحد للمشروع، لا ضماناً معاملاتياً لتسليم البريد الخارجي.
 * - عدم الحصول على القفل خلال 5 ثوانٍ (نفس المهلة المُستخدَمة في معظم
 *   الأقفال المشابهة بالمشروع) يُعامَل بأمان: لا إرسال، فشل يُسجَّل، بلا أي
 *   تأثير على نجاح تفعيل الباقة.
 */
final class PGE_Package_Activation_Email
{
    const MARKER_META_KEY_PREFIX = '_mon_pkg_activation_email_sent_';
    const MARKER_VALUE = '1';

    /**
     * نقطة الدخول الوحيدة. لا ترمي استثناءً أبداً، ولا تُغيّر أي حالة تفعيل
     * — تُعيد bool للتشخيص الاختياري فقط (المُستدعي الحالي في
     * class-salla-handler.php لا يتحقق من القيمة المُعادة عمداً).
     *
     * @param mixed $user_id  معرّف مستخدم Hilwah المُفعَّل فعلياً (موثوق).
     * @param mixed $plan_id  معرّف الباقة (mon_plans.id) الموثوق من التفعيل.
     * @param mixed $tier_id  معرّف المستوى (mon_plan_tiers.id) الموثوق من التفعيل.
     * @param mixed $order_id معرّف طلب Salla — هوية الإشعار (Notification Identity).
     * @return bool true عند نجاح الإرسال أو عند التخطّي الآمن (تكرار مطابق
     *   للطلب نفسه)؛ false عند أي فشل (بيانات غير صالحة، قفل غير مُحصَّل،
     *   فشل wp_mail()، أو تعذر تأكيد دوام العلامة بعد نجاحه).
     */
    public static function send($user_id, $plan_id, $tier_id, $order_id)
    {
        $user_id = (int) $user_id;
        $plan_id = (int) $plan_id;
        $tier_id = (int) $tier_id;
        $order_id = is_scalar($order_id) ? trim(sanitize_text_field((string) $order_id)) : '';

        if ($user_id <= 0) {
            self::log_failure($user_id, $order_id, 'invalid_user_id');
            return false;
        }

        if ($plan_id <= 0 || $tier_id <= 0) {
            self::log_failure($user_id, $order_id, 'invalid_plan_or_tier_id');
            return false;
        }

        // هوية الإشعار (Notification Identity) هي order_id — بلا معرّف طلب
        // صالح لا يمكن تطبيق منع إعادة التشغيل بأمان، فلا يُرسَل إطلاقاً
        // (فشل آمن، لا تخمين لعلامة بديلة).
        if ($order_id === '') {
            self::log_failure($user_id, $order_id, 'missing_order_id');
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email) || !is_email($user->user_email)) {
            self::log_failure($user_id, $order_id, 'missing_or_invalid_user_email');
            return false;
        }

        if (!class_exists('PGE_Catalog')) {
            self::log_failure($user_id, $order_id, 'catalog_unavailable');
            return false;
        }

        $plan = PGE_Catalog::get_plan($plan_id);
        if (!is_array($plan)) {
            self::log_failure($user_id, $order_id, 'plan_not_found');
            return false;
        }

        $tier = PGE_Catalog::get_tier($tier_id);
        if (!is_array($tier)) {
            self::log_failure($user_id, $order_id, 'tier_not_found');
            return false;
        }

        global $wpdb;
        $lock_name = self::build_lock_name($user_id, $order_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));

        if ((int) $got_lock !== 1) {
            self::log_failure($user_id, $order_id, 'lock_not_acquired');
            return false;
        }

        try {
            $marker_meta_key = self::build_marker_meta_key($order_id);

            // إعادة قراءة العلامة **داخل** القفل — لا قبل الحصول عليه (هذا
            // هو ما يمنع التزامن فعلياً، لا مجرد تقليله).
            $existing_marker = (string) get_user_meta($user_id, $marker_meta_key, true);

            if ($existing_marker === self::MARKER_VALUE) {
                // تكرار مطابق تماماً لنفس الطلب (إعادة إرسال Webhook) — تخطٍّ
                // آمن ونظيف، ليس فشلاً.
                return true;
            }

            $display_name = ($user->display_name !== '' && $user->display_name !== null)
                ? $user->display_name
                : $user->user_login;

            $plan_name = sanitize_text_field((string) ($plan['name'] ?? ''));
            $tier_name = sanitize_text_field((string) ($tier['name'] ?? ''));
            $dashboard_url = home_url('/dashboard/');

            $subject = self::build_subject();
            $body = self::build_body($display_name, $plan_name, $tier_name, $tier, $dashboard_url);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            $sent = (bool) wp_mail($user->user_email, $subject, $body, $headers);

            if ($sent) {
                if (!self::persist_marker($user_id, $marker_meta_key)) {
                    self::log_failure($user_id, $order_id, 'marker_persistence_failed_email_may_duplicate');
                    return false;
                }
            } else {
                self::log_failure($user_id, $order_id, 'wp_mail_returned_false');
            }

            return $sent;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * اسم القفل يُشتَق من (user_id, order_id) معاً — نفس نطاق العلامة نفسها
     * تماماً، بنفس اصطلاح md5() المُستخدَم في كل أقفال المشروع الأخرى
     * لضمان طول ثابت وآمن.
     */
    private static function build_lock_name($user_id, $order_id)
    {
        return 'pge_pkg_activation_email_' . md5($user_id . '|' . $order_id);
    }

    /**
     * مفتاح مستقل وحتمي لكل طلب داخل نطاق usermeta الخاص بالمستخدم. قيمة
     * الطلب الخام لا تظهر في المفتاح، وSHA-256 يبقي الطول ضمن حد meta_key.
     */
    private static function build_marker_meta_key($order_id)
    {
        return self::MARKER_META_KEY_PREFIX . hash('sha256', (string) $order_id);
    }

    /**
     * كتابة العلامة بعد نجاح wp_mail() فقط. بنفس روح
     * Mon_Events_Users::update_user_meta_safely() (تحقّق من نجاح الكتابة
     * فعلياً، لا افتراض دائماً) دون الاعتماد على تلك الدالة الخاصة
     * (private) في كلاس آخر — نسخة محلية ضيقة مخصَّصة لعلامة نصية بسيطة.
     */
    private static function persist_marker($user_id, $marker_meta_key)
    {
        $updated = update_user_meta($user_id, $marker_meta_key, self::MARKER_VALUE);
        if ($updated !== false) {
            return true;
        }

        // update_user_meta() تُعيد false أيضاً عندما تكون القيمة الجديدة
        // مطابقة تماماً للقيمة المخزَّنة أصلاً (لا هذا خطأ) — تحقّق فعلي بدل
        // افتراض الفشل.
        return (string) get_user_meta($user_id, $marker_meta_key, true) === self::MARKER_VALUE;
    }

    private static function build_subject()
    {
        return 'تم تفعيل باقتك في حلوة 🎉';
    }

    private static function build_body($display_name, $plan_name, $tier_name, $tier, $dashboard_url)
    {
        $display_name_html = esc_html($display_name);
        $plan_name_html = esc_html($plan_name);
        $tier_name_html = esc_html($tier_name);
        $dashboard_url_attr = esc_url($dashboard_url);

        $guest_limit_line = self::build_guest_limit_line($tier);
        $events_line = self::build_events_line($tier);
        $wa_line = self::build_wa_line($tier);

        ob_start();
        ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
</head>
<body style="margin:0;padding:0;background-color:#f7f3ee;font-family:Tahoma,Arial,sans-serif;" dir="rtl">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f7f3ee;padding:32px 0;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
<tr>
<td style="padding:32px 28px 8px 28px;text-align:right;">
<h2 style="margin:0 0 12px 0;font-size:20px;color:#2d1914;">مرحباً <?php echo $display_name_html; ?>،</h2>
<p style="margin:0 0 16px 0;font-size:15px;line-height:1.7;color:#4a3a34;">
تم تفعيل باقتك بنجاح وأصبحت جاهزة للاستخدام.
</p>
<p style="margin:0 0 4px 0;font-size:14px;line-height:1.8;color:#4a3a34;">
الباقة: <strong><?php echo $plan_name_html; ?></strong>
</p>
<p style="margin:0 0 16px 0;font-size:14px;line-height:1.8;color:#4a3a34;">
المستوى: <strong><?php echo $tier_name_html; ?></strong>
</p>
<?php if ($guest_limit_line !== '' || $events_line !== '' || $wa_line !== ''): ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;background-color:#f7f3ee;border-radius:12px;">
<tr><td style="padding:14px 16px;">
<?php if ($guest_limit_line !== ''): ?>
<p style="margin:0 0 6px 0;font-size:13px;color:#4a3a34;"><?php echo esc_html($guest_limit_line); ?></p>
<?php endif; ?>
<?php if ($events_line !== ''): ?>
<p style="margin:0 0 6px 0;font-size:13px;color:#4a3a34;"><?php echo esc_html($events_line); ?></p>
<?php endif; ?>
<?php if ($wa_line !== ''): ?>
<p style="margin:0;font-size:13px;color:#4a3a34;"><?php echo esc_html($wa_line); ?></p>
<?php endif; ?>
</td></tr>
</table>
<?php endif; ?>
<p style="margin:0 0 24px 0;text-align:center;">
<a href="<?php echo $dashboard_url_attr; ?>" style="display:inline-block;background-color:#8a4a2f;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:14px 32px;border-radius:12px;">
الذهاب إلى لوحة التحكم
</a>
</p>
<p style="margin:0 0 20px 0;font-size:14px;line-height:1.7;color:#4a3a34;">
يمكنك الآن الدخول إلى لوحة التحكم وإنشاء مناسبتك وإدارة المدعوين والدعوات.
</p>
</td>
</tr>
<tr>
<td style="padding:16px 28px 28px 28px;border-top:1px solid #f0e8e0;text-align:right;">
<p style="margin:12px 0 0 0;font-size:12px;color:#b0a49c;">
<?php echo esc_html(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)); ?>
</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * عدد المدعوين — تُعرَض فقط عندما تحمل قيمة عددية موثوقة من الـTier
     * نفسه (guest_limit قد تكون null، أي "غير محدود" أو "غير معرَّف" حسب
     * تصميم الجدول — لا تُعرَض حينها بدل عرض رقم مُخترَع).
     */
    private static function build_guest_limit_line($tier)
    {
        $guest_limit = $tier['guest_limit'] ?? null;
        if ($guest_limit === null || $guest_limit === '') {
            return '';
        }
        if (!is_numeric($guest_limit)) {
            return '';
        }
        return sprintf('عدد المدعوين: %s', number_format_i18n((float) $guest_limit, 0));
    }

    /**
     * عدد المناسبات — من event_quota_mode/event_quota_limit (نفس الحقلين
     * اللذين يكتبهما فعلياً activate_catalog_tier() كـSnapshot عند التفعيل).
     * unlimited → عبارة عربية مناسبة بلا رقم؛ limited → الرقم الفعلي.
     */
    private static function build_events_line($tier)
    {
        $mode = is_string($tier['event_quota_mode'] ?? null) ? strtolower(trim((string) $tier['event_quota_mode'])) : '';
        if ($mode === 'unlimited') {
            return 'عدد المناسبات: غير محدود';
        }

        $limit = $tier['event_quota_limit'] ?? null;
        if ($limit === null || $limit === '' || !is_numeric($limit) || (int) $limit < 1) {
            return '';
        }

        return sprintf('عدد المناسبات: %s', number_format_i18n((float) $limit, 0));
    }

    /**
     * رصيد رسائل واتساب — تُعرَض فقط عند وجود قيمة عددية موثوقة
     * (wa_messages_limit عمود NULLable في mon_plan_tiers؛ NULL يعني "غير
     * محدَّد لهذا المستوى" فلا تُعرَض السطر إطلاقاً، لا صفراً مُضلِّلاً).
     */
    private static function build_wa_line($tier)
    {
        $wa_limit = $tier['wa_messages_limit'] ?? null;
        if ($wa_limit === null || $wa_limit === '' || !is_numeric($wa_limit)) {
            return '';
        }
        return sprintf('رصيد الرسائل: %s', number_format_i18n((float) $wa_limit, 0));
    }

    /**
     * تسجيل فشل آمن فقط — user_id/order_id/سبب مختصر (كود، لا نص حر). لا
     * نص الرسالة، لا البريد الإلكتروني، لا أي بيانات Salla خام إطلاقاً.
     */
    private static function log_failure($user_id, $order_id, $reason)
    {
        if (function_exists('error_log')) {
            error_log(sprintf(
                '[PGE Package Activation Email] send failed user_id=%d order_id=%s reason=%s',
                (int) $user_id,
                (string) $order_id,
                (string) $reason
            ));
        }
    }
}
