<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Assignment Service — Entry Check-in Supervisors، Phase 2
 * ============================================================================
 * "Supervisor Invitation Lifecycle" RFC، Requirement 1: "Create the Supervisor
 * Assignment Service. This service becomes the only way to create, revoke or
 * validate supervisor assignments. No direct database writes elsewhere."
 *
 * هذا الملف هو الوسيط الوحيد المسموح به للكتابة **أو القراءة المباشرة** على
 * جدول {$wpdb->prefix}mon_event_supervisors (Phase 1 — class-mon-catalog-
 * schema.php). لا AJAX، لا صفحة إدارة، لا مسار واجهة يستدعي هذا الملف بعد
 * (Requirement 9: "No UI. No admin pages. No event pages. No AJAX. No
 * buttons.") — دوال هذا الملف مُعدَّة للاستهلاك المستقبلي فقط (Phase 3+).
 *
 * تحديث Phase 3 Blocking Issue (فصل معماري): أُضيفت get_assignment_state($id)
 * كواجهة قراءة عامة صريحة — "Assignment state" إحدى المسؤوليات الثلاث
 * المرخَّصة صراحةً لهذه الخدمة (Accept invitation, Revoke assignment,
 * Assignment state). أي كود خارجي يحتاج معرفة حالة إسناد (مثل
 * PGE_Supervisor_Authenticator) يستدعي هذه الدالة حصراً، لا يقرأ mon_event_
 * supervisors بـ$wpdb مباشرة من ملف آخر إطلاقاً — راجع تعليق الدالة نفسها.
 *
 * دورة الحياة المطبَّقة هنا بالكامل (Requirements 2، 4، 5):
 *   invited → (accept_invitation) → active → (revoke_supervisor_assignment) → revoked
 *   invited → (revoke_supervisor_assignment) → revoked
 * لا مسار في هذا الملف يُنتج الحالة 'pending' أو 'expired' — كلتاهما موضع
 * نائب محجوز لمرحلة لاحقة (راجع docs/ENTRY-SUPERVISORS-DESIGN.md §5): 'pending'
 * قد تمثّل لاحقاً "تم إرسال رسالة الدعوة فعلياً عبر واتساب/قناة أخرى، بانتظار
 * تفاعل الضيف" (تكامل مراسلة، خارج نطاق Phase 2)، و'expired' قد تمثّل انتهاء
 * صلاحية زمنية تلقائية (Cron مستقبلي، خارج نطاق Phase 2 أيضاً). كلا الحالتين
 * مُعرَّفتان في status VARCHAR(20) (لا ENUM، Phase 1) ومُعاملتان بشكل صحيح
 * أينما ظهرتا (accept/revoke ترفضان أي حالة ليست ضمن ما هو مسموح صراحة)، لكن
 * لا كود هنا يكتبهما إطلاقاً.
 *
 * ============================================================================
 * قرار الانتهاء (Expiration) — Blocking Issue #2، Option A مُختار صراحةً
 * ============================================================================
 * القرار: **Option A — 'expired' مؤجَّلة لمرحلة مستقبلية. لا انتهاء تلقائي
 * موجود اليوم بأي شكل.** لا Cron، لا فحص وقتي، لا مقارنة بـinvited_at/أي طابع
 * زمني آخر يُنتج 'expired' في أي مكان من هذا الملف أو أي ملف آخر في المشروع
 * حتى تاريخه. الحرفان 'e','x','p','i','r','e','d' لا يظهران كقيمة مكتوبة عبر
 * $wpdb->insert()/$wpdb->update() في هذا الملف إطلاقاً — يمكن التحقق من هذا
 * مباشرة بقراءة create_supervisor_assignment() (تكتب 'invited' فقط)،
 * accept_invitation() (تكتب 'active' فقط)، وrevoke_supervisor_assignment()
 * (تكتب 'revoked' فقط). الحالات الأربع القابلة للوصول فعلياً اليوم عبر هذه
 * الخدمة، وفقط هذه الأربع، هي: invited، pending (كمُدخَل مقبول نظرياً في
 * ACCEPTABLE_STATUSES، رغم أن create_supervisor_assignment() لا يُنتجها
 * بنفسه اليوم)، active، revoked. أي صف يحمل status = 'expired' في قاعدة
 * البيانات اليوم لا يمكن أن يكون قد نتج عن استدعاء أي دالة عامة في هذه
 * الخدمة — لو ظهر، فهو من مصدر خارجي (كتابة يدوية/مرحلة مستقبلية لم تُبنَ
 * بعد)، وaccept_invitation()/revoke_supervisor_assignment() تتعاملان معه
 * بأمان بمجرد ظهوره (ترفضانه صراحة عبر ACCEPTABLE_STATUSES/REVOCABLE_STATUSES
 * تماماً كما ترفضان 'revoked') — دفاع استباقي، لا اعتراف بوجود مسار ينتجه.
 * تنفيذ Option B (سياسة انتهاء صريحة: schema + validation + Cron + tests)
 * مؤجَّل بالكامل لمرحلة لاحقة غير محدَّدة بعد، بانتظار تفويض صريح جديد.
 *
 * الحماية الذرية ضد إسنادين "نشطين" معاً لنفس (event_id, supervisor_phone)
 * (Requirement 8) تعتمد على GET_LOCK فقط عند الإنشاء (create_supervisor_
 * assignment())، بنفس فلسفة PGE_Invitation_Credit_Ledger::claim_for_delivery()
 * وقفل pge_handle_event_creation() (Event Quota Commit 6) تماماً: اسم قفل
 * مُشتقّ عبر md5() من (event_id, الهاتف المُطبَّع)، GET_LOCK(name, 5) بمهلة
 * انتظار قصيرة، RELEASE_LOCK داخل finally لضمان التحرير حتى عند استثناء PHP
 * غير متوقَّع (لا wp_send_json/die() هنا — هذا ملف خدمة صرف، لا AJAX handler،
 * فـtry/finally آمن تماماً، بخلاف قيد event-factory.php الموثَّق هناك).
 *
 * الحصة (Requirement 7): تُفرَض حصراً عبر pge_resolve_supervisor_quota_status()
 * (Phase 1، includes/supervisor-quota-resolver.php) — لا قراءة مباشرة لـ
 * mon_tier_features، لا تجاوز لـSnapshot (_mon_package_features)، بلا استثناء.
 *
 * التوكن (Requirement 3): يُولَّد عشوائياً (32 بايت، bin2hex → 64 حرف hex،
 * بنفس أسلوب PGE_Invitation_Credit_Ledger::generate_attempt_token() الحالي
 * فعلاً في المشروع)، لكن يُخزَّن الـhash فقط (sha256 → 64 حرفاً hex أيضاً، عبر
 * hash_invitation_token()) — القيمة الخام لا تُخزَّن في أي عمود إطلاقاً،
 * وتُعاد مرة واحدة فقط من create_supervisor_assignment() للمستدعي المباشر.
 */

class PGE_Supervisor_Assignment_Service
{
    /**
     * الحالات "النشطة" وفق تعريف RFC الحرفي (Requirement 2/6): invited/
     * pending/active. أي حالة أخرى (revoked/expired) تُعامَل كـ"غير نشطة" —
     * لا تُحتسَب ضمن التحقق من التكرار عند الإنشاء، ولا ضمن الحصة (التي
     * تُقرأ فعلياً من pge_count_active_event_supervisors() في Phase 1، بنفس
     * التعريف الدلالي، معرَّف بشكل مستقل هناك لتفادي أي اعتماد جديد بين
     * ملفات Phase 1 المعتمدة وPhase 2 — نفس المعنى، لا نفس الثابت البرمجي).
     */
    const ACTIVE_STATUSES = ['invited', 'pending', 'active'];

    /**
     * الحالات التي يجوز قبولها (Acceptance) — invited/pending فقط. حالة
     * active أصلاً (قبول مكرر) تُعامَل بشكل مميَّز (already_active)، لا كخطأ
     * ولا كنجاح جديد. revoked/expired مرفوضتان دائماً.
     */
    const ACCEPTABLE_STATUSES = ['invited', 'pending'];

    /**
     * الحالات التي يجوز إلغاؤها (Revocation) — invited/pending/active، وفق
     * نص RFC الحرفي في Requirement 5 ("May only revoke: invited, pending,
     * active"). نفس قيمة ACTIVE_STATUSES بالضبط في هذه النسخة، لكن تُعرَّف
     * بثابت مستقل عمداً — تطابقهما اليوم لا يعني بالضرورة تطابقهما مستقبلاً
     * (قد تحتاج قاعدة الإلغاء تعديلاً مستقلاً عن قاعدة "ماذا يُعتبَر نشطاً
     * للحصة" في مرحلة لاحقة، دون أن يفرض ذلك تعديل الأخرى).
     */
    const REVOCABLE_STATUSES = ['invited', 'pending', 'active'];

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_event_supervisors';
    }

    /**
     * تطبيع معرّف موجب — نفس قاعدة normalize_positive_id() في
     * class-pge-invitation-credit-ledger.php حرفياً (DRY منطقي، لا اعتماد
     * برمجي جديد بين الملفين).
     */
    private static function normalize_positive_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * تطبيع رقم جوال المشرف — عبر pge_norm_phone() الحالية إن كانت محمَّلة
     * (الحالة الطبيعية داخل الإضافة الفعلية)، أو نفس منطقها كاحتياط، بنفس
     * أسلوب normalize_guest_phone() في class-pge-invitation-credit-ledger.php.
     */
    private static function normalize_phone($value)
    {
        if (function_exists('pge_norm_phone')) {
            return pge_norm_phone($value);
        }

        return preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * توليد توكن عشوائي آمن (Requirement 3) — 32 بايت عشوائية حقيقية
     * (random_bytes، آمنة تشفيرياً)، مُمثَّلة كسلسلة hex بطول 64 حرفاً. نفس
     * الآلية الحرفية المستخدَمة فعلاً في PGE_Invitation_Credit_Ledger::
     * generate_attempt_token() — إعادة استخدام نمط مُثبَت، لا اختراع آلية
     * توليد جديدة.
     */
    private static function generate_invitation_token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * هاش التوكن (Requirement 3: "Store only a hash. Never store the raw
     * token."). sha256 بلا ملح (Salt/HMAC) — القيمة المُدخَلة عالية الإنتروبيا
     * أصلاً (256 بت عشوائية حقيقية من random_bytes()، لا كلمة مرور بشرية
     * منخفضة الإنتروبيا)، فهاش مباشر كافٍ تماماً لمنع استرجاع التوكن الخام من
     * قاعدة البيانات حتى لو تسرَّبت — بنفس المبدأ المُتَّبع لتخزين أي "مفتاح
     * API" عالي العشوائية في الصناعة عموماً. النتيجة: سلسلة hex بطول 64
     * حرفاً بالضبط (تطابق طول عمود invitation_token_hash VARCHAR(64) تماماً).
     */
    private static function hash_invitation_token(string $raw_token): string
    {
        return hash('sha256', $raw_token);
    }

    /**
     * اسم قفل GET_LOCK مشتق وآمن من (event_id, الهاتف المُطبَّع) — نطاق القفل
     * هو "نية دعوة واحدة لمناسبة وهاتف محدَّدين"، لا المناسبة كاملة ولا كل
     * الجدول: هذا يسمح بمعالجة دعوات هواتف مختلفة لنفس المناسبة بالتوازي
     * التام، بينما يُسلسِل فقط أي محاولتين تتنافسان فعلياً على نفس المفتاح
     * (event_id, phone) — بنفس فلسفة build_credit_lock_name() في
     * PGE_Invitation_Credit_Ledger حرفياً.
     */
    private static function build_assignment_lock_name($event_id, $normalized_phone): string
    {
        return 'pge_supervisor_assignment_' . md5($event_id . '|' . $normalized_phone);
    }

    /**
     * قراءة صف واحد بمعرّفه (id) — قراءة خام بلا شرط حالة، تُستخدَم داخلياً
     * فقط قبل أي انتقال حالة (Accept/Revoke) لمعرفة الحالة الحالية أولاً.
     */
    private static function find_by_id($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $normalized_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * ============================================================================
     * قارئ حالة عام للإسناد — Blocking Issue (فصل معماري: Assignment Lifecycle
     * vs Authentication vs Session Management)
     * ============================================================================
     * "Keep three distinct responsibilities... 1. PGE_Supervisor_Assignment_
     * Service: Responsible ONLY for assignment lifecycle. Responsibilities:
     * Accept invitation, Revoke assignment, Assignment state."
     *
     * قبل هذا التصحيح، كانت دالة التنسيق (الآن PGE_Supervisor_Authenticator،
     * سابقاً pge_supervisor_authenticate() داخل class-pge-supervisor-session.php)
     * تقرأ مباشرة من جدول mon_event_supervisors عبر $wpdb لتتحقق من أن الإسناد
     * أصبح فعلاً active بعد accept_invitation() — هذا يخالف مبدأ "لا كتابة
     * *أو قراءة* مباشرة على جدول هذه الخدمة من أي ملف آخر"، ويجعل المنسِّق يعرف
     * تفاصيل مخطط قاعدة البيانات (اسم الجدول، أسماء الأعمدة) بدل الاعتماد حصراً
     * على واجهة الخدمة العامة.
     *
     * هذه الدالة تكشف find_by_id() (الموجودة أصلاً وتبقى خاصة) عبر واجهة عامة
     * صريحة — "Assignment state" مذكورة حرفياً كمسؤولية مشروعة لهذه الخدمة في
     * توصيف Blocking Issue. أي كود خارجي (المنسِّق، أو أي مستهلك مستقبلي) يريد
     * معرفة حالة إسناد ما يستدعي هذه الدالة فقط — لا يكتب SQL خاصاً به إطلاقاً.
     * قراءة فقط، بلا أي أثر جانبي — لا تُغيِّر شيئاً، بعكس accept_invitation()/
     * revoke_supervisor_assignment().
     *
     * @return array|null الصف الكامل (id, event_id, status, ...) أو null إذا
     *                      لم يوجد إسناد بهذا المعرّف.
     */
    public static function get_assignment_state($id): ?array
    {
        return self::find_by_id($id);
    }

    /**
     * ============================================================================
     * قارئ دفعي عام — Phase 5 ("Attendance Statistics Engine" RFC، Requirement 5:
     * Dashboard Provider يحتاج "Supervisor Summary")
     * ============================================================================
     * إضافة صغيرة، قراءة فقط، بلا أي أثر جانبي — تُبقي هذا الملف الوسيط الوحيد
     * الذي يقرأ/يكتب على جدول mon_event_supervisors مباشرة (نفس المبدأ الموثَّق
     * أعلى الملف)؛ لولاها لاضطرت PGE_Attendance_Statistics_Service الجديدة إلى
     * قراءة هذا الجدول بنفسها مباشرة (مخالفة صريحة لذلك المبدأ). لا علاقة لها
     * بدورة حياة الإسناد (invited/active/revoked) — فقط عرض قائمة، بلا تعديل.
     *
     * @return array<int,array{id:int,supervisor_phone:string,supervisor_name:string,status:string}>
     */
    public static function list_assignments_for_event($event_id): array
    {
        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, supervisor_phone, supervisor_name, status FROM $table WHERE event_id = %d ORDER BY id ASC",
                $normalized_event_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * ============================================================================
     * قارئ مُرقَّم مع بحث — Phase 8 ("Host Supervisor Management" RFC،
     * "Supervisor List page... support Search/Pagination")
     * ============================================================================
     * قراءة فقط، بلا أي أثر جانبي — إضافة صرفة على غرار list_assignments_for_
     * event() أعلاه (Phase 5)، لا تُعدِّل تلك الدالة ولا مستهلكيها الحاليين
     * (PGE_Attendance_Statistics_Service) بأي شكل. تبقى mon_event_supervisors
     * مقروءة/مكتوبة حصراً من هذا الملف.
     *
     * البحث: عن الاسم (LIKE جزئي) أو الهاتف (بعد تطبيعه، LIKE جزئي على الشكل
     * المُطبَّع المخزَّن أصلاً في supervisor_phone) — مطابقة أيّهما كافية.
     *
     * @return array{items: array<int,array>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function list_assignments_for_event_page($event_id, $search = '', $page = 1, $per_page = 20): array
    {
        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => (int) $per_page, 'total_pages' => 0];
        }

        $page = max(1, (int) $page);
        $per_page = ((int) $per_page > 0 && (int) $per_page <= 100) ? (int) $per_page : 20;
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $table = self::table_name();

        $search = is_scalar($search) ? trim((string) $search) : '';
        $where = 'event_id = %d';
        $args = [$normalized_event_id];

        if ($search !== '') {
            $like_name = '%' . $wpdb->esc_like($search) . '%';
            $normalized_search_phone = self::normalize_phone($search);

            if ($normalized_search_phone !== '') {
                $like_phone = '%' . $wpdb->esc_like($normalized_search_phone) . '%';
                $where .= ' AND (supervisor_name LIKE %s OR supervisor_phone LIKE %s)';
                $args[] = $like_name;
                $args[] = $like_phone;
            } else {
                $where .= ' AND supervisor_name LIKE %s';
                $args[] = $like_name;
            }
        }

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE $where", $args));

        $items_args = array_merge($args, [$per_page, $offset]);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, supervisor_phone, supervisor_name, status, invited_at, accepted_at, revoked_at, updated_at FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
                $items_args
            ),
            ARRAY_A
        );

        return [
            'items'       => is_array($rows) ? $rows : [],
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
        ];
    }

    /**
     * ============================================================================
     * تعديل بيانات مشرف — Phase 8 (Requirement "Edit Supervisor: Only Display
     * Name/Phone... must NOT reset authentication/invitation/assignment")
     * ============================================================================
     * لا تُعدِّل status/invitation_token_hash/invited_at/accepted_at/revoked_at
     * إطلاقاً — الاسم والهاتف فقط. تمنع تعارض الهاتف الجديد مع إسناد آخر نشط
     * لنفس المناسبة (نفس فلسفة فحص duplicate_active في create_supervisor_
     * assignment()، لكن مُستثنى منها الصف الحالي نفسه).
     *
     * @return array{result:string, ...}
     *   'updated'          — نجح التعديل. يتضمن 'id'.
     *   'duplicate_active' — الهاتف الجديد يخصّ إسناداً نشطاً آخر بالفعل.
     *   'error'            — id/هاتف غير صالح، أو الإسناد غير موجود. يتضمن 'reason'.
     */
    public static function edit_supervisor_details($id, $phone, $name = '')
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_id'];
        }

        $existing = self::find_by_id($normalized_id);
        if ($existing === null) {
            return ['result' => 'error', 'reason' => 'assignment_not_found'];
        }

        $normalized_phone = self::normalize_phone($phone);
        if ($normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $normalized_name = is_scalar($name) ? trim((string) $name) : '';
        $event_id = (int) $existing['event_id'];

        global $wpdb;
        $table = self::table_name();

        if ($normalized_phone !== (string) $existing['supervisor_phone']) {
            $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '%s'));
            $dup = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE event_id = %d AND supervisor_phone = %s AND id != %d AND status IN ($placeholders) LIMIT 1",
                    array_merge([$event_id, $normalized_phone, $normalized_id], self::ACTIVE_STATUSES)
                ),
                ARRAY_A
            );

            if ($dup !== null) {
                return ['result' => 'duplicate_active', 'id' => (int) $dup['id']];
            }
        }

        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'supervisor_phone' => $normalized_phone,
                'supervisor_name'  => $normalized_name,
                'updated_at'       => $now,
            ],
            ['id' => $normalized_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return ['result' => 'error', 'reason' => 'update_failed'];
        }

        return ['result' => 'updated', 'id' => $normalized_id];
    }

    /**
     * ============================================================================
     * توليد توكن تسليم جديد (Supervisor Invitation Delivery via Cartat —
     * تنفيذ) — غلاف عام حول generate_invitation_token()/hash_invitation_token()
     * الخاصتين أعلاه، بلا أي كتابة على قاعدة البيانات. مصمَّمة خصيصاً لتمكين
     * "ترتيب التسليم الآمن" المطلوب صراحةً في التكليف: يجب توليد توكن جديد
     * وبناء رابط القبول منه **قبل** أي التزام في invitation_token_hash، بحيث
     * لو رفض Cartat الرسالة أو فشل النقل، يبقى التوكن القديم سارياً (لا يُستبدَل
     * أبداً إلا بعد قبول المزوّد فعلياً — راجع commit_new_token_hash() أدناه
     * وPGE_Supervisor_Invitation_Delivery::deliver() المستهلك الوحيد لكلتيهما).
     *
     * لا هذه الدالة ولا commit_new_token_hash() تُعيدان تعريف آلية التوليد/
     * الهاش — كلتاهما تستدعيان نفس private static methods الموجودتين أعلاه
     * حرفياً (DRY حقيقي، لا نسخة موازية).
     *
     * @return array{raw:string, hash:string}
     */
    public static function generate_delivery_token(): array
    {
        $raw = self::generate_invitation_token();
        return ['raw' => $raw, 'hash' => self::hash_invitation_token($raw)];
    }

    /**
     * ============================================================================
     * تثبيت هاش توكن جديد بعد قبول التسليم فعلياً (Supervisor Invitation
     * Delivery via Cartat — تنفيذ)
     * ============================================================================
     * نصف الكتابة من resend_invitation() الأصلية سابقاً (توليد+كتابة معاً)،
     * مفصولة الآن عمداً عن التوليد: المستدعي (PGE_Supervisor_Invitation_
     * Delivery) يُولِّد التوكن عبر generate_delivery_token() *قبل* محاولة
     * الإرسال، ولا يستدعي هذه الدالة إلا **بعد** أن يقبل Cartat الرسالة
     * فعلياً — بذلك لا يُصبح التوكن القديم غير صالح إلا بعد أن يصبح رابط
     * التوكن الجديد قد أُرسِل فعلياً وقُبِل من المزوّد، لا قبل ذلك أبداً.
     *
     * شرط WHERE مركَّب (id + الحالة المتوقَّعة $expected_status معاً) — نفس
     * فلسفة accept_invitation()/revoke_supervisor_assignment() تماماً: يحمي
     * من تغيّر حالة الإسناد (مثلاً إلغاء متزامن) بين لحظة التحقق من الأهلية
     * في المستدعي ولحظة هذه الكتابة الفعلية. $updated === 0 يعني تعارض
     * تزامن حقيقي (الحالة تغيّرت)، وليس بالضرورة خطأً برمجياً.
     *
     * @return array{result:string, ...}
     *   'committed' — نجح تثبيت الهاش الجديد فعلياً. يتضمن 'id'.
     *   'error'     — id غير صالح/hash فارغ، أو تعارض تزامن (الحالة تغيّرت
     *                 قبل وصول هذه الكتابة). يتضمن 'reason'.
     */
    public static function commit_new_token_hash($id, $expected_status, $new_token_hash): array
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_id'];
        }

        $expected_status = is_scalar($expected_status) ? (string) $expected_status : '';
        $new_token_hash = is_scalar($new_token_hash) ? (string) $new_token_hash : '';
        if ($expected_status === '' || $new_token_hash === '') {
            return ['result' => 'error', 'reason' => 'invalid_arguments'];
        }

        global $wpdb;
        $table = self::table_name();
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'invitation_token_hash' => $new_token_hash,
                'invited_at'            => $now,
                'updated_at'            => $now,
            ],
            [
                'id'     => $normalized_id,
                'status' => $expected_status,
            ],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_status_change'];
        }

        return ['result' => 'committed', 'id' => $normalized_id];
    }

    /**
     * ============================================================================
     * إعادة إرسال الدعوة — Phase 8 (Requirement "Resend Invitation: must NOT
     * create another assignment, must NOT consume another slot, must NOT
     * duplicate invitations")
     * ============================================================================
     * لا استدعاء لـcreate_supervisor_assignment() هنا إطلاقاً (فلا فحص حصة
     * جديد، ولا صف جديد) — تحديث ذات الصف فقط: توكن جديد (يُبطِل القديم
     * ضمنياً)، invited_at يُحدَّث ليعكس آخر إرسال فعلي. مسموح فقط لحالات
     * invited/pending (نفس ACCEPTABLE_STATUSES) — إعادة إرسال دعوة مقبولة
     * (active) أو ملغاة (revoked) لا معنى له. شرط WHERE مركَّب (id + status)
     * لأمان تزامن (نفس فلسفة accept_invitation()/revoke_supervisor_assignment()).
     *
     * Supervisor Invitation Delivery via Cartat — تنفيذ: أُعيدت كتابة جسم
     * هذه الدالة لاستدعاء generate_delivery_token()/commit_new_token_hash()
     * أعلاه بدل تكرار نفس منطق التوليد+الكتابة محلياً (DRY) — **بلا أي تغيير
     * في القيم المُعادة أو شروطها** (نفس 'resent'/'error'/'not_resendable'/
     * 'concurrent_status_change' تماماً)، فتبقى متوافقة خلفياً مع كل مستدعٍ
     * قائم. ملاحظة معمارية: هذه الدالة (الالتزام الفوري) تبقى متاحة لأي
     * مستهلك مستقبلي يحتاج "تدوير فوري بلا تسليم فعلي"، لكن مسار الإنتاج
     * الفعلي لتسليم دعوات المشرفين (إنشاء/إعادة إرسال عبر الواجهة) لا يستدعي
     * هذه الدالة بعد الآن — يستدعي generate_delivery_token()/commit_new_
     * token_hash() منفصلتين عبر PGE_Supervisor_Invitation_Delivery::deliver()
     * لضمان عدم إبطال التوكن القديم قبل قبول Cartat فعلياً (راجع توثيق
     * الدالتين أعلاه).
     *
     * @return array{result:string, ...}
     *   'resent' — نجحت إعادة الإرسال. يتضمن 'id' و'invitation_token' (الخام،
     *              مرة واحدة فقط، لا يُخزَّن).
     *   'error'  — id غير صالح، الإسناد غير موجود، حالته ليست invited/pending،
     *              أو تعارض تزامن. يتضمن 'reason'.
     */
    public static function resend_invitation($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_id'];
        }

        $existing = self::find_by_id($normalized_id);
        if ($existing === null) {
            return ['result' => 'error', 'reason' => 'assignment_not_found'];
        }

        $current_status = (string) ($existing['status'] ?? '');
        if (!in_array($current_status, self::ACCEPTABLE_STATUSES, true)) {
            return ['result' => 'error', 'reason' => 'not_resendable', 'status' => $current_status];
        }

        $token = self::generate_delivery_token();
        $commit = self::commit_new_token_hash($normalized_id, $current_status, $token['hash']);

        if (($commit['result'] ?? '') !== 'committed') {
            return ['result' => 'error', 'reason' => 'concurrent_status_change'];
        }

        return ['result' => 'resent', 'id' => $normalized_id, 'invitation_token' => $token['raw']];
    }

    /**
     * إنشاء إسناد مشرف جديد (Requirement 2) — الدالة العامة الوحيدة المسموح
     * بها لإنشاء صف جديد في mon_event_supervisors. الترتيب حرفي وفق RFC:
     * تطبيع الهاتف ← GET_LOCK ← فحص الحصة (Phase 1 Resolver فقط) ← فحص
     * إسناد نشط موجود مسبقاً ← رفض التكرار ← إنشاء الإسناد ← تحرير القفل.
     *
     * @return array{result: string, ...} — قيم 'result' الممكنة:
     *   'created'         — نجح الإنشاء. يتضمن 'id' و'invitation_token' (الخام،
     *                        مرة واحدة فقط، لا يُخزَّن).
     *   'duplicate_active'— يوجد إسناد نشط (invited/pending/active) بالفعل
     *                        لنفس (event_id, phone). يتضمن 'id' للصف الموجود.
     *   'quota_exceeded'  — الحصة (Phase 1 Resolver) مستنفَدة. يتضمن
     *                        'allowed'/'used'.
     *   'error'           — فشل تحقق/قفل/استعلام. يتضمن 'reason'.
     */
    public static function create_supervisor_assignment($event_id, $invited_by_user_id, $phone, $name = '')
    {
        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }

        $normalized_inviter_id = self::normalize_positive_id($invited_by_user_id);
        if ($normalized_inviter_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_invited_by_user_id'];
        }

        $normalized_phone = self::normalize_phone($phone);
        if ($normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $normalized_name = is_scalar($name) ? trim((string) $name) : '';

        global $wpdb;
        $table = self::table_name();
        $lock_name = self::build_assignment_lock_name($normalized_event_id, $normalized_phone);

        // GET_LOCK(name, timeout_seconds) — بنفس نمط claim_for_delivery():
        // 5 ثوانٍ كافية لعملية سريعة (قراءتان + INSERT واحد)؛ 1 = نجح، 0 =
        // مشغول من جلسة أخرى حتى انتهاء المهلة، NULL = خطأ فعلي.
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            // ── Requirement 7: الحصة عبر Resolver من Phase 1 فقط ────────────
            if (!function_exists('pge_resolve_supervisor_quota_status')) {
                return ['result' => 'error', 'reason' => 'resolver_unavailable'];
            }

            $quota_status = pge_resolve_supervisor_quota_status($normalized_event_id);
            if (is_wp_error($quota_status)) {
                return [
                    'result' => 'error',
                    'reason' => 'quota_resolution_failed',
                    'quota_error_code' => $quota_status->get_error_code(),
                ];
            }

            $allowed = (int) ($quota_status['allowed'] ?? 0);
            $used = (int) ($quota_status['used'] ?? 0);
            if ($used >= $allowed) {
                return [
                    'result' => 'quota_exceeded',
                    'allowed' => $allowed,
                    'used' => $used,
                ];
            }

            // ── فحص إسناد نشط موجود مسبقاً لنفس (event_id, phone) ───────────
            $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '%s'));
            $existing_active = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE event_id = %d AND supervisor_phone = %s AND status IN ($placeholders) LIMIT 1",
                    array_merge([$normalized_event_id, $normalized_phone], self::ACTIVE_STATUSES)
                ),
                ARRAY_A
            );

            if ($existing_active !== null) {
                return [
                    'result' => 'duplicate_active',
                    'id' => (int) $existing_active['id'],
                    'status' => (string) $existing_active['status'],
                ];
            }

            // ── إنشاء الإسناد الجديد (Append-Only — INSERT فقط، لا UPDATE
            // على أي صف تاريخي إطلاقاً) ──────────────────────────────────────
            $raw_token = self::generate_invitation_token();
            $token_hash = self::hash_invitation_token($raw_token);
            $now = current_time('mysql', true);

            $inserted = $wpdb->insert(
                $table,
                [
                    'event_id' => $normalized_event_id,
                    'user_id' => null,
                    'supervisor_phone' => $normalized_phone,
                    'supervisor_name' => $normalized_name,
                    'status' => 'invited',
                    'invitation_token_hash' => $token_hash,
                    'invited_by_user_id' => $normalized_inviter_id,
                    'invited_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );

            if (!$inserted) {
                return ['result' => 'error', 'reason' => 'insert_failed'];
            }

            return [
                'result' => 'created',
                'id' => (int) $wpdb->insert_id,
                'invitation_token' => $raw_token,
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * قبول دعوة (Requirement 4) — الترتيب حرفي وفق RFC: التحقق من التوكن ←
     * التحقق من حالة الإسناد ← تفعيل الإسناد ← إبطال التوكن ← تسجيل
     * accepted_at. الانتقال ذاته ذري عبر شرط WHERE مركَّب (id +
     * invitation_token_hash معاً، لا id وحده) — بنفس فلسفة mark_consumed()
     * في PGE_Invitation_Credit_Ledger تماماً: حتى لو قرأ استدعاءان متزامنان
     * نفس الصف بنفس التوكن معاً، فـ$wpdb->update() الفعلي الذي يُنفَّذ ثانياً
     * (بعد أن يكون الأول قد صفَّر invitation_token_hash إلى NULL بالفعل) لن
     * يجد أي صف مطابق للشرط، ويُعيد 0 صفاً متأثراً — لا حاجة لقفل GET_LOCK
     * هنا (Requirement 8 يفرضه صراحة على الإنشاء فقط، لا على القبول).
     *
     * @return array{result: string, ...} — قيم 'result' الممكنة:
     *   'accepted'       — التفعيل نجح فعلاً. يتضمن 'id'.
     *   'already_active' — الإسناد كان active بالفعل قبل هذا الاستدعاء (قبول
     *                       مكرر، Idempotent معلوماتياً — لا كتابة إضافية).
     *   'error'          — توكن غير صالح/غير موجود، أو حالة غير قابلة للقبول
     *                       (revoked/expired)، أو تعارض تزامن. يتضمن 'reason'.
     */
    public static function accept_invitation($raw_token)
    {
        $raw_token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
        if ($raw_token === '') {
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $token_hash = self::hash_invitation_token($raw_token);

        global $wpdb;
        $table = self::table_name();

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE invitation_token_hash = %s LIMIT 1", $token_hash),
            ARRAY_A
        );

        if ($existing === null) {
            // لا تمييز بين "توكن غير موجود إطلاقاً" و"توكن أُبطِل مسبقاً" في
            // رسالة الخطأ — كلاهما نفس النتيجة العملية (لا قبول ممكن)، ولا
            // داعٍ لتسريب أي معلومة إضافية عبر رمز الخطأ.
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $current_status = (string) ($existing['status'] ?? '');
        $existing_id = (int) $existing['id'];

        if ($current_status === 'active') {
            return ['result' => 'already_active', 'id' => $existing_id];
        }

        if (!in_array($current_status, self::ACCEPTABLE_STATUSES, true)) {
            return ['result' => 'error', 'reason' => 'assignment_not_acceptable', 'status' => $current_status];
        }

        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'status' => 'active',
                'accepted_at' => $now,
                'invitation_token_hash' => null,
                'updated_at' => $now,
            ],
            [
                'id' => $existing_id,
                'invitation_token_hash' => $token_hash,
            ],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            // تعارض تزامن حقيقي: استدعاء آخر سبق هذا واستهلك نفس التوكن قبل
            // أن يصل تحديثنا، فلم يعد الشرط المركَّب (id + hash) يطابق أي صف.
            return ['result' => 'error', 'reason' => 'token_already_used_or_invalid'];
        }

        return ['result' => 'accepted', 'id' => $existing_id];
    }

    /**
     * إلغاء إسناد (Requirement 5) — يجوز فقط لحالات invited/pending/active.
     * لا حذف صف إطلاقاً (Never delete rows)، لا إعادة استخدام صف (Never
     * reuse rows) — تحديث status/revoked_at على نفس الصف فقط، فيبقى التاريخ
     * Append-Only بحق. الانتقال مشروط بقراءة الحالة الحالية أولاً، ثم تحديث
     * ذري بشرط WHERE id + status معاً (نفس فلسفة mark_consumed() تماماً) —
     * لا GET_LOCK هنا (Requirement 8 يخصّ الإنشاء فقط).
     *
     * @return array{result: string, ...} — قيم 'result' الممكنة:
     *   'revoked' — الإلغاء نجح فعلاً. يتضمن 'id'.
     *   'error'   — id غير صالح، الصف غير موجود، الحالة الحالية ليست ضمن
     *               invited/pending/active (مثل revoked/expired مسبقاً)، أو
     *               تعارض تزامن. يتضمن 'reason'.
     */
    public static function revoke_supervisor_assignment($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_id'];
        }

        $existing = self::find_by_id($normalized_id);
        if ($existing === null) {
            return ['result' => 'error', 'reason' => 'assignment_not_found'];
        }

        $current_status = (string) ($existing['status'] ?? '');
        if (!in_array($current_status, self::REVOCABLE_STATUSES, true)) {
            return ['result' => 'error', 'reason' => 'not_revocable', 'status' => $current_status];
        }

        global $wpdb;
        $table = self::table_name();
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'status' => 'revoked',
                'revoked_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $normalized_id,
                'status' => $current_status,
            ],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_status_change'];
        }

        return ['result' => 'revoked', 'id' => $normalized_id];
    }

    /**
     * ============================================================================
     * تدوير توكن دخول (Supervisor Login Architecture — Post-Activation Login RFC)
     * ============================================================================
     * نصف الكتابة فقط (الالتزام)؛ التوليد عبر generate_delivery_token() المشتركة
     * أعلاه — **لا مولّد توكن ثانٍ** (نفس bin2hex(random_bytes(32))/sha256()
     * المُستخدَمَين فعلياً لتوكن الدعوة/الجلسة). نفس بنية commit_new_token_hash()
     * حرفياً (شرط WHERE id + الحالة المتحقَّقة معاً، ذري)، لكن تكتب على عمود
     * login_token_hash المستقل تماماً — **لا تلمس invitation_token_hash ولا
     * status إطلاقاً**: توكن الدخول لا يمثّل انتقال حالة (بخلاف توكن الدعوة)،
     * فقط دليل هوية لحظي لإسناد active بالفعل. لا تُحدِّث invited_at أيضاً
     * (ذلك العمود خاص بدورة حياة الدعوة حصراً، لا صلة له بتوكن الدخول).
     *
     * @return array{result:string, id?:int, reason?:string}
     */
    public static function commit_new_login_token_hash($id, $expected_status, $new_login_token_hash): array
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_id'];
        }

        $expected_status = is_scalar($expected_status) ? (string) $expected_status : '';
        $new_login_token_hash = is_scalar($new_login_token_hash) ? (string) $new_login_token_hash : '';
        if ($expected_status === '' || $new_login_token_hash === '') {
            return ['result' => 'error', 'reason' => 'invalid_arguments'];
        }

        global $wpdb;
        $table = self::table_name();
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'login_token_hash' => $new_login_token_hash,
                'updated_at' => $now,
            ],
            [
                'id' => $normalized_id,
                'status' => $expected_status,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_status_change'];
        }

        return ['result' => 'committed', 'id' => $normalized_id];
    }

    /**
     * ============================================================================
     * استهلاك توكن دخول (Supervisor Login Architecture RFC) — مسار مصادقة
     * مستقل تماماً عن accept_invitation()، بلا أي استدعاء مشترك بينهما
     * ("Never reuse invitation service" حرفياً). يماثلها بنيوياً فقط (بحث
     * بالهاش ← تحقّق الحالة ← تحديث ذري بشرط WHERE id + الهاش نفسه معاً، نفس
     * فلسفة عدم الحاجة لـGET_LOCK هنا تماماً كـaccept_invitation()) لكن
     * الفروق الجوهرية:
     *  - الحالة المطلوبة 'active' فقط (عكس invited/pending تماماً).
     *  - **لا تُغيِّر status إطلاقاً** — يبقى 'active' كما هو قبل/بعد
     *    الاستهلاك؛ توكن الدخول لا يمثّل انتقال حالة على الإطلاق.
     *  - تُصفِّر login_token_hash فقط إلى NULL (استهلاك لمرة واحدة — يمنع
     *    إعادة استخدام نفس الرابط بعد أول تسجيل دخول ناجح به، بنفس الفلسفة
     *    الأمنية لتوكن الدعوة)، بلا أي لمس لـinvitation_token_hash/
     *    accepted_at/revoked_at.
     *
     * hash_invitation_token() المشتركة أعلاه (sha256 عام لا علاقة له
     * بالدعوة تحديداً رغم اسمها) تُعاد هنا لتفادي خوارزمية هاش ثانية —
     * لا يعني ذلك إعادة استخدام أي منطق دعوة فعلي.
     *
     * @return array{result:string, id?:int, event_id?:int, reason?:string, status?:string}
     *   'consumed' — الاستهلاك نجح فعلياً. يتضمن 'id'، 'event_id'.
     *   'error'    — توكن غير موجود/مستهلَك مسبقاً، الإسناد لم يعد active،
     *                أو تعارض تزامن. يتضمن 'reason'.
     */
    public static function consume_login_token($raw_token): array
    {
        $raw_token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
        if ($raw_token === '') {
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $token_hash = self::hash_invitation_token($raw_token);

        global $wpdb;
        $table = self::table_name();

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE login_token_hash = %s LIMIT 1", $token_hash),
            ARRAY_A
        );

        if ($existing === null) {
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $current_status = (string) ($existing['status'] ?? '');
        $existing_id = (int) $existing['id'];
        $existing_event_id = (int) ($existing['event_id'] ?? 0);

        if ($current_status !== 'active') {
            // id/event_id مُضمَّنان هنا عمداً (بخلاف فرع 'invalid_token' أعلاه
            // حيث لا صف مطابق أصلاً) — الإسناد معروف بيقين، فيجوز للمستدعي
            // (PGE_Supervisor_Login_Authenticator) كتابة تدقيق login_failed
            // صادق منسوباً إليه.
            return ['result' => 'error', 'reason' => 'assignment_not_active', 'status' => $current_status, 'id' => $existing_id, 'event_id' => $existing_event_id];
        }

        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'login_token_hash' => null,
                'updated_at' => $now,
            ],
            [
                'id' => $existing_id,
                'login_token_hash' => $token_hash,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            // تعارض تزامن حقيقي: استدعاء آخر (أو تدويرة جديدة) سبق هذا
            // واستهلك/استبدل نفس الهاش قبل وصول تحديثنا. id/event_id من
            // القراءة الأولى أعلاه (الإسناد نفسه معروف، فقط الهاش لم يعد
            // يطابق) — تكفي لتدقيق login_failed صادق.
            return ['result' => 'error', 'reason' => 'token_already_used_or_invalid', 'id' => $existing_id, 'event_id' => $existing_event_id];
        }

        return ['result' => 'consumed', 'id' => $existing_id, 'event_id' => $existing_event_id];
    }

    /**
     * قراءة كل الإسنادات النشطة (status = 'active') لهاتف مُطبَّع مُعطى، عبر
     * كل المناسبات — Supervisor Login Architecture RFC، تُستهلَك حصراً من
     * تدفّق "Request Login Link" الذاتي (/supervisor/login) حيث لا event_id
     * معروفاً مسبقاً (المشرف يُدخِل رقم جواله فقط، لا رابط مناسبة). قراءة
     * فقط، بلا أي كتابة.
     *
     * تحذير معماري صريح (نفس تحذير pge_has_active_supervisor_assignment()
     * أعلاه حرفياً): هذه دالة Lookup بحتة، **ليست** دالة تفويض/مصادقة — رقم
     * الهاتف مُدخَل حر من طرف مجهول الهوية بالكامل. المستدعي (AJAX الذاتي)
     * مسؤول عن التعامل الآمن مع النتيجة (رسالة استجابة موحَّدة بصرف النظر عن
     * وجود تطابق، لمنع تعداد الأرقام — Phone Enumeration).
     *
     * @return array<int, array> صفوف كاملة لكل تطابق (قد تكون أكثر من واحد
     *   إن كان نفس الهاتف مُسنَداً نشطاً في أكثر من مناسبة).
     */
    public static function find_active_assignments_by_phone($phone): array
    {
        $normalized_phone = function_exists('pge_norm_phone')
            ? pge_norm_phone((string) $phone)
            : preg_replace('/\D+/', '', (string) $phone);

        if ($normalized_phone === '') {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE supervisor_phone = %s AND status = 'active'", $normalized_phone),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('pge_has_active_supervisor_assignment')) {
    /**
     * ============================================================================
     * تنبيه معماري صريح — LOOKUP فقط، وليست دالة تفويض/مصادقة (Blocking Issue #1)
     * ============================================================================
     * كانت هذه الدالة تحمل اسم pge_is_active_supervisor_for_event() سابقاً، وقد
     * أُعيدت تسميتها عمداً إلى pge_has_active_supervisor_assignment() لتفادي أي
     * إيحاء بأنها دالة "تحقق من هوية" (is_*_for_event يوحي بفحص صلاحية المستدعي
     * الحالي). الاسم الجديد يصف بدقة كل ما تفعله الدالة فعلياً: "هل يوجد إسناد
     * نشط؟" — سؤال عن حالة بيانات، لا سؤال عن هوية.
     *
     * ── الفرق بين ثلاثة مفاهيم منفصلة تماماً (لتفادي الخلط مستقبلاً) ──────────
     *   1. Lookup (هذه الدالة):
     *      تطبيع رقم هاتف مُدخَل من المستدعي ← بحث في الجدول ← true/false.
     *      لا تعرف من هو المستخدم الحالي. لا تتحقق من أي جلسة. رقم الهاتف قد
     *      يكون معروفاً للجميع (يُشارَك عبر واتساب/طباعة) أو مُزوَّراً بالكامل
     *      من طرف مُهاجم يرسل رقماً لا يملكه — لذلك **لا يجوز أبداً** استخدامها
     *      لتحديد "هل يُسمح لمستدعي هذا الطلب بالعمل كمشرف؟".
     *   2. Authorization (تفويض — غير موجودة في أي مرحلة حتى الآن):
     *      "بما أن هوية X مؤكَّدة، هل X مسموح له بفعل Y؟" — تحتاج مُدخَلاً موثوقاً
     *      أصلاً (هوية مصادَق عليها)، لا رقم هاتف من body/query الطلب.
     *   3. Authentication (مصادقة — Phase 3/4، موثَّقة أدناه، غير مُنفَّذة هنا):
     *      "من هو المستخدم الحالي فعلياً، بثقة؟" — جلسة مشرف أو حساب WordPress
     *      مسجَّل الدخول، لا قيمة يُرسلها المستدعي بحرية ضمن الطلب.
     *
     * هذه الدالة (رقم 1 فقط) **ممنوع منعاً باتاً** استخدامها كحارس تفويض
     * (authorization guard) في أي مسار مستقبلي — أي كود يستدعيها بنيّة "امنع
     * الوصول إلا إذا رجعت true" يرتكب نفس الخطأ المعماري الذي يمنعه هذا التوثيق.
     *
     * ============================================================================
     * توثيق فقط — بنية التفويض المستقبلية (Phase 3/4، بلا أي تنفيذ هنا إطلاقاً)
     * ============================================================================
     * Phase 3/4 ستُدخِل هوية مشرف موثوقة (trusted supervisor identity) عبر إحدى
     * آليتين مرشَّحتين (لم تُحسَم بعد، تحديد الآلية النهائية خارج نطاق هذا الملف):
     *   - "Supervisor Session": جلسة/توكن جلسة يُصدَر بعد accept_invitation()
     *     الناجحة، يُخزَّن في cookie موقَّع (بنفس فلسفة pge_event_phone_{id}
     *     HMAC cookie المُستخدَمة فعلاً لضيوف RSVP في rsvp-handler.php)، أو
     *   - "Authenticated WP User": ربط الإسناد فعلياً بـuser_id حساب WordPress
     *     مسجَّل الدخول (is_user_logged_in() + get_current_user_id())، وعندها
     *     يمتلئ عمود user_id في الجدول (الذي يبقى NULL طوال Phase 2 بالكامل).
     *
     * وفق أي الآليتين وقع الاختيار، الدالة المستقبلية المكافئة للتفويض ستحمل
     * حصراً التوقيع:
     *     pge_is_active_supervisor_for_event($event_id)
     * أي **بلا معامل $phone على الإطلاق** — تشتق الهوية داخلياً من الجلسة/
     * المستخدم الحالي الموثوق، لا من مُدخَل خارجي يُرسله المستدعي. هذا يُعيد
     * استخدام اسم الدالة القديم عمداً لأنه الاسم الصحيح لدالة *تفويض حقيقية*؛
     * المشكلة لم تكن في الاسم نفسه بل في توقيعها الحالي (يقبل $phone من
     * الخارج) — لذلك أُعيدت تسمية دالة الـLookup الحالية بدلاً من إبقائها تحت
     * اسم سيُعاد استخدامه لاحقاً لغرض مختلف تماماً.
     * لا تنفيذ لأي من هذا هنا — توثيق حصراً، بانتظار Phase 3/4.
     *
     * ── الاستخدام الصحيح الوحيد للدالة الحالية اليوم ──────────────────────────
     * قراءة داخلية بحتة (مثلاً: عرض معلوماتية في تقرير مستقبلي "هل هذا الرقم
     * مُسجَّل كمشرف نشط؟")، وليست حارس وصول مطلقاً. لا مستدعي في هذه المرحلة
     * (Phase 2) يستدعيها أصلاً — لا UI، لا AJAX، لا مسار وصول (Requirement 9).
     *
     * تُعيد true فقط عند: وجود إسناد فعلي، حالته active بالضبط (لا
     * invited/pending/revoked/expired)، لنفس event_id المطلوب تحديداً.
     *
     * @param int    $event_id
     * @param string $phone رقم مُدخَل من المستدعي — غير موثوق، غير مُصادَق عليه.
     * @return bool نتيجة بحث فقط، وليست قرار تفويض.
     */
    function pge_has_active_supervisor_assignment($event_id, $phone): bool
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return false;
        }

        $normalized_phone = function_exists('pge_norm_phone') ? pge_norm_phone($phone) : preg_replace('/\D+/', '', trim((string) $phone));
        if ($normalized_phone === '') {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mon_event_supervisors';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE event_id = %d AND supervisor_phone = %s AND status = %s LIMIT 1",
                $event_id,
                $normalized_phone,
                'active'
            ),
            ARRAY_A
        );

        return $row !== null;
    }
}
