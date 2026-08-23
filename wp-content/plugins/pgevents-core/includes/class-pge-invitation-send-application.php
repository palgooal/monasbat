<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send Application — Model D2، D2-W3 (Scoped Invitation Send
 * Authorization / Application Contract — تفويض + تنسيق فقط، بلا أي كتابة)
 * ============================================================================
 * الطبقة الوحيدة التي تُجيب: "هل يجوز للفاعل الحالي طلب إرسال دعوة لهذا
 * الضيف الحالي بهذه النيّة (عادي/إعادة إرسال) الآن؟" — تُركِّب فوق ثلاث طبقات
 * موجودة أصلاً بلا تعديل على منطقها:
 *   1) PGE_Event_Access_Authorization::resolve_context() + الطاقة الجديدة
 *      can_send_guest_invitation() (D2-W3) — من يملك سلطة الإرسال لهذا
 *      الضيف تحديداً، بناءً على النطاق الحالي (Current Scope) لا منشئ
 *      الضيف التاريخي (DEC-009).
 *   2) PGE_Invitation_Repository::get_invitation() — هل الضيف موجود حالياً
 *      أصلاً (قراءة فقط، Phase 9 القائمة).
 *   3) PGE_Event_Access_Repository::get_guest_assignment() — المجموعة
 *      الحالية المُخصَّصة للضيف (قراءة فقط، H1C القائم).
 *   4) PGE_Invitation_Send_State::resolve() (D2-W2) — حالة الإرسال الحالية
 *      وقابلية الإرسال العادي/إعادة الإرسال.
 *
 * لا كتابة هنا إطلاقاً: لا PGE_Invitation_Send_Ledger::claim()/
 * finalize_success()/finalize_failure()، لا GET_LOCK، لا إنشاء طابور/دفعة،
 * لا Cartat/UltraMsg. تتوقف هذه المرحلة قبل أي طفرة (Mutation) — D2-W4
 * المستقبلية وحدها تستدعي claim() الفعلية، بعد أن يُثبت هذا العقد أن الفاعل
 * مخوَّل والنيّة صالحة لحالة الإرسال الحالية.
 *
 * ============================================================================
 * ترتيب الخطوات (يطابق حرفياً القسم 5 من موجز D2-W3)
 * ============================================================================
 * 1) التحقق الشكلي من المدخلات (event_id/actor_user_id/guest_phone/intent).
 * 2) حلّ سياق الفاعل عبر PGE_Event_Access_Authorization::resolve_context().
 * 3) انهيار EC1: أي WP_Error من resolve_context() (مناسبة غير موجودة، أو أي
 *    خطأ آخر يمنع حتى تحديد السياق) ينهار لنفس not_authorized الخارجي — لا
 *    تمييز بين "مناسبة غير موجودة" و"فاعل غير مخوَّل لمناسبة حقيقية"،
 *    بنفس فلسفة DEC-006 المُطبَّقة فعلياً في resolve_actor_context() الخاصة
 *    بـPGE_Additional_Inviter.
 * 4) تأكيد وجود الضيف الحالي عبر PGE_Invitation_Repository::get_invitation()
 *    — نفس القراءة التي يعتمد عليها D2-W1 لتحديد lifecycle_started_at.
 * 5) حلّ التخصيص الحالي للضيف عبر
 *    PGE_Event_Access_Repository::get_guest_assignment() — null يعني ضيفاً
 *    غير مُخصَّص حالياً (لا خطأ، حالة صالحة).
 * 6) تفويض Owner/Admin أو الداعي الإضافي عبر can_send_guest_invitation()
 *    الجديدة (D2-W3) — إن رُفض، يُعاد not_authorized فوراً بلا أي قراءة
 *    لحالة الإرسال (القسم 8 أدناه) لتفادي تسريب أي معلومة عن حالة الإرسال
 *    لفاعل غير مخوَّل أصلاً.
 * 7) قراءة حالة إرسال D2-W2 عبر PGE_Invitation_Send_State::resolve() — فقط
 *    بعد ثبوت التفويض في الخطوة 6.
 * 8) مطابقة النيّة المطلوبة (normal/resend) مقابل تلك الحالة —
 *    evaluate_intent_against_state() أدناه، دالة نقية بحتة بلا أي قراءة/
 *    كتابة إضافية.
 * 9) إعادة نتيجة داخلية موحَّدة (انظر توثيق الثوابت RESULT_* أدناه) تستهلكها
 *    D2-W4 المستقبلية.
 *
 * ============================================================================
 * قسم 9 من الموجز — عقد الخصوصية/الخطأ الداخلي، والتمييز عن طبقة النقل
 * المستقبلية (Transport/AJAX) — **مهم جداً**
 * ============================================================================
 * الخطوة 4 (تأكيد وجود الضيف) تحدث **قبل** قرار التفويض في الخطوة 6 — هذا
 * يعني أن النتيجة الداخلية قد تُميِّز "لا ضيف بهذا الهاتف إطلاقاً"
 * (RESULT_NOT_FOUND) عن "ضيف حقيقي لكن الفاعل غير مخوَّل له"
 * (RESULT_NOT_AUTHORIZED) — بخلاف EC1 الخاص بوجود *المناسبة* في الخطوة 3،
 * الذي يبقى منهاراً بالكامل دوماً كما هو موثَّق أعلاه. هذا التمييز الداخلي
 * بين "ضيف غير موجود" و"غير مخوَّل" **مقصود ومسموح به صراحة** في هذه
 * المرحلة (D2-W3) لأغراض الاختبار/التركيب الداخلي فقط — راجع الاختبار V في
 * tests/test-d2-w3-invitation-send-authorization.php. **لكن أي طبقة نقل
 * مستقبلية (AJAX/D2-W4 الفعلية) يجب أن تُطابق سلوك EC1 بالكامل عند التعرّض
 * الخارجي: NOT_FOUND وNOT_AUTHORIZED يجب أن يظهرا بنفس الشكل الخارجي
 * لفاعل غير مخوَّل، تماماً كما لا يُكشَف وجود مناسبة لفاعل غير مخوَّل لها —
 * وإلا يصبح استعلام "هل هذا الضيف موجود؟" أداة استكشاف (Oracle) لداعٍ
 * إضافي خارج نطاقه.** هذا الملف يوفِّر فقط العقد الداخلي الخام؛ طيّ هذا
 * التمييز خارجياً مسؤولية D2-W4/طبقة النقل المستقبلية، لا هذه الطبقة.
 *
 * ============================================================================
 * هوية الفاعل (القسم 10 من الموجز)
 * ============================================================================
 * تقبل authorize_send_for_actor() أدناه $actor_user_id داخلياً (للاختبار/
 * التركيب فقط، بنفس اصطلاح كل طبقات D2-W1/D2-W2 السابقة). طبقة النقل
 * الموثَّقة مستقبلياً (AJAX) **يجب** أن تُمرِّر هذه القيمة حصراً من
 * get_current_user_id() للجلسة الحالية — لا ثقة أبداً بقيمة actor_user_id
 * قادمة من العميل. لا AJAX يُضاف في هذه المرحلة إطلاقاً.
 *
 * ============================================================================
 * القسم 8 من الموجز — تفصيل مطابقة النيّة، ومطابقتها الدقيقة لسلوك
 * PGE_Invitation_Send_Ledger::claim() الفعلي (بلا استدعائه هنا إطلاقاً)
 * ============================================================================
 * الجدول أدناه (evaluate_intent_against_state()) يُشتَقّ حصراً من حقول
 * PGE_Invitation_Send_State::resolve() (normal_send_allowed/resend_required/
 * in_progress/state) — لا إعادة حساب لانتهاء Lease هنا (ذلك يبقى حصراً
 * مسؤولية claim() الذرية في D2-W1، تماماً كما توثّق D2-W2 نفسها صراحة أنها
 * لا تحسب انتهاء Lease أيضاً). النتائج التالية مطابقة تماماً لمفردات
 * claim() الفعلية حيث تتطابق الدلالة، لتفادي أي تعارض مصطلحات مستقبلي بين
 * هذه الطبقة وD2-W4 الفعلية:
 *   - state=provider_accepted + intent=normal  → RESULT_ALREADY_SENT (نفس
 *     مصطلح claim() الحرفي 'already_sent' لنفس الحالة).
 *   - state=provider_accepted + intent=resend  → RESULT_AUTHORIZED
 *     (resend_required=true من D2-W2).
 *   - state=send_requested (أي نيّة)            → RESULT_ALREADY_IN_PROGRESS
 *     (نفس مصطلح claim() الحرفي 'already_in_progress'؛ القسم 8 من الموجز
 *     ينص صراحة: كلا النيّتين محجوبتان هنا).
 *   - state=ambiguous_transport_error + normal  → RESULT_RESEND_REQUIRED
 *     (محجوب صراحة لعادي — راجع توثيق D2-W2 القرار الأهم فيها).
 *   - state=ambiguous_transport_error + resend  → RESULT_AUTHORIZED.
 *   - state=not_sent/failed + intent=normal     → RESULT_AUTHORIZED.
 *   - state=not_sent/failed + intent=resend     → RESULT_INVALID_STATE
 *     (قرار حَكَمي موثَّق أدناه — لا اختبار في هذه المرحلة يُثبِّته، ولا
 *     PGE_Invitation_Send_State نفسها تُصرِّح به صراحة عبر resend_required).
 *
 * **قرار حَكَمي موثَّق صراحة (لا إخفاء)**: PGE_Invitation_Send_Ledger::claim()
 * الفعلية، عند القراءة المباشرة لمنطقها (بلا استدعائها هنا)، لا تُميِّز
 * فعلياً بين intent=normal وintent=resend عندما تكون أحدث محاولة failed أو
 * لا توجد محاولات إطلاقاً — كلاهما "يتابع مباشرة" لإنشاء محاولة جديدة بلا
 * أي فرع خاص بالنيّة. لو طُبِّقت هذه الحرفية هنا، لكان intent=resend على
 * not_sent/failed مُخوَّلاً أيضاً. اخترت بدلاً من ذلك الاعتماد **حصراً** على
 * إشارة resend_required الصريحة من D2-W2 (القسم 8 من الموجز: "allowed only
 * when resend_required = true OR another existing approved state explicitly
 * permits resend") — بما أن لا not_sent ولا failed "يُصرِّحان صراحة" بإعادة
 * الإرسال في عقد D2-W2 الموثَّق، اخترت الجانب الأكثر تحفظاً: طلب "إعادة
 * إرسال" صريحة لضيف لم يُرسَل له شيء قط، أو انتهت آخر محاولة إليه بفشل واضح
 * (Retry عادي عبر intent=normal يغطي هذه الحالة أصلاً)، يُعامَل كتعارض
 * حالة/نيّة (RESULT_INVALID_STATE)، لا تفويضاً ضمنياً. هذا لا يتعارض وظيفياً
 * مع claim() الفعلية — D2-W3 لا يستدعيها إطلاقاً هنا، فلا يوجد سلوك مزدوج
 * متضارب يظهر للمستخدم في هذه المرحلة؛ إن ثبت لاحقاً أن هذا التحفظ زائد عن
 * الحاجة، يُعاد النظر فيه بموجب مدخل منتجي صريح لا تخمين هنا.
 *
 * ============================================================================
 * D2-W4 — "Authorized Claim / Send Request Mutation Contract" (إضافة، بلا
 * تعديل على أي شيء أعلاه من D2-W3)
 * ============================================================================
 * request_send_for_actor() أدناه هي حدود الطفرة (Mutation Boundary) الوحيدة
 * في هذا الملف — الطريقة الوحيدة هنا التي تكتب أي شيء، وكتابتها الوحيدة
 * المسموحة هي تفويض مباشر لـPGE_Invitation_Send_Ledger::claim() (D2-W1
 * الذرية القائمة فعلاً، غير مُعدَّلة هنا إطلاقاً). لا finalize_success()، لا
 * finalize_failure()، لا طابور/عامل، لا Cartat/UltraMsg، لا AJAX/UI، لا
 * تكرار جماعي (Bulk) — إرسال ضيف واحد فقط، تماماً كما يُحتِّم DEC-009 ("سجل
 * دائم واحد... الأداة الأساسية... إرسال ضيف واحد مُصرَّح به، وليست 'إرسال
 * الكل'").
 *
 * ========================================================================
 * الترتيب الحتمي (يطابق القسم 3 من موجز D2-W4 حرفياً)
 * ========================================================================
 * 1) التحقق الشكلي من المدخلات — بلا تكرار منطق: يُفوَّض بالكامل لنفس
 *    الفحص الموجود أصلاً داخل authorize_send_for_actor() (الخطوة 1 هناك) —
 *    استدعاء واحد فقط أدناه يُغطّي هذا تماماً، لا نسخة موازية هنا (القسم 5
 *    من الموجز: "Do not duplicate authorization logic").
 * 2-3) حلّ تفويض/حالة **طازجة تماماً** عبر D2-W3 — استدعاء واحد فعلي حقيقي
 *    لـself::authorize_send_for_actor() في كل مرة تُستدعى فيها
 *    request_send_for_actor()، لا تخزين مؤقّت (Cache)، لا قبول نتيجة
 *    تفويض جاهزة كمعامل من المستدعي إطلاقاً — القاعدة الأهم في هذه المرحلة
 *    حرفياً: **لا يُعامَل أي نتيجة authorized سابقة كرمز صلاحية (Capability
 *    Token) قابل لإعادة الاستخدام**. توقيع الدالة نفسه لا يقبل نتيجة
 *    تفويض جاهزة كمعامل أصلاً — لا طريقة لتفويت هذه الخطوة حتى لو أراد
 *    مستدعٍ مستقبلي ذلك.
 *
 *    القسمان 2 و3 من الموجز (خطوتان منفصلتان اسمياً: "resolve fresh
 *    authorization" ثم "immediately before mutation, re-resolve... if
 *    needed") **يتّحدان هنا في استدعاء واحد فقط، بقرار معماري موثَّق
 *    صراحة، لا سهواً**: بين نهاية استدعاء authorize_send_for_actor() وبداية
 *    استدعاء claim() أدناه لا يوجد أي I/O إضافي، لا استعلام قاعدة بيانات
 *    آخر، لا زمن انتظار (Latency) — سطرا PHP متزامنان تماماً بلا أي فاصل
 *    فعلي. استدعاء ثانٍ منفصل لنفس resolve_context()/can_send_guest_
 *    invitation()/PGE_Invitation_Send_State::resolve() هنا لن يقرأ شيئاً
 *    مختلفاً عملياً عن الاستدعاء الأول (نفس اللحظة الزمنية تقريباً، بلا أي
 *    عملية خارجية تتداخل بينهما داخل نفس الطلب المتزامن) — فلا فائدة حقيقية
 *    من التكرار، فقط استعلامان مضاعفان بلا حماية إضافية فعلية. لذلك "if
 *    needed" في نص الموجز محسومة هنا بـ"غير محتاجة" — الاستدعاء الوحيد
 *    الموجود فعلياً **هو نفسه** الفحص "مباشرة قبل الطفرة" المطلوب، لأنه
 *    يقع فعلياً مباشرة قبل استدعاء claim() بلا أي فاصل. هذا يبقى متّسقاً
 *    تماماً مع القسم 4 من الموجز ("fresh auth immediately before claim" —
 *    وليس "استدعاءين منفصلين لتفويض متطابق").
 * 4) استدعاء PGE_Invitation_Send_Ledger::claim() الفعلية — فقط إن كانت
 *    نتيجة الخطوة 2-3 أعلاه authorized بالضبط؛ أي نتيجة أخرى (not_authorized/
 *    invalid_state/already_sent/already_in_progress/resend_required/
 *    not_found) تُعاد كما هي فوراً بلا أي استدعاء لـclaim() إطلاقاً — لا
 *    داعي لمحاولة مطالبة (Claim) لطلب مرفوض أصلاً من طبقة التفويض.
 * 5) تطبيع نتيجة claim() الخام إلى نتيجة تطبيقية واحدة مستقرة — راجع
 *    map_claim_result() أدناه لكل قرار تفصيلي.
 * 6) إعادة هوية المحاولة المُطالَب بها الدائمة (الحد الأدنى فقط — القسم 7
 *    من الموجز) عند النجاح فقط.
 *
 * ========================================================================
 * القسم 4 من الموجز — تحليل صريح لسباقات وقت الطلب (Request-Time TOCTOU)
 * ========================================================================
 * T1) الداعي يرى الضيف → T2) الضيف يُنقَل خارج مجموعة الداعي → T3) الداعي
 *     يضغط إرسال → **رفض**. مضمون بالفعل: الخطوة 2-3 أعلاه تقرأ
 *     PGE_Event_Access_Repository::get_guest_assignment() طازجة تماماً وقت
 *     الطلب نفسه، لا وقت ما رآه الداعي على الشاشة سابقاً — أي نقل حدث قبل
 *     هذه القراءة يُكتشَف فوراً ويُرفَض عبر can_send_guest_invitation().
 * T1) الداعي يرى الضيف → T2) الداعي يُلغى → T3) الداعي يضغط إرسال →
 *     **رفض**. نفس المنطق: resolve_context() طازج يقرأ حالة العضوية
 *     الحالية فعلياً، لا حالة مخبَّأة.
 * T1) التفويض ينجح → T2) فاعل آخر يُطالِب (Claim) بنفس الضيف → T3) هذا
 *     الطلب يصل إلى claim() → **نتيجة حصر (Dedup) السجل هي الفائزة؛ لا صف
 *     مكرَّر يُنشَأ**. مضمون بالكامل عبر GET_LOCK الذرية داخل
 *     PGE_Invitation_Send_Ledger::claim() نفسها (D2-W1، غير مُعدَّلة هنا) —
 *     أياً كان الفاعل الذي يصل للقفل أولاً يُنشئ الصف؛ الفاعل الآخر يُعاد
 *     قراءة السجل الفعلي *داخل* القفل نفسه فيُعاد already_sent/
 *     already_in_progress بحسب حالة السجل الفعلية عندها، لا صف ثانٍ.
 *     الاختبار O أدناه يُثبِت هذا صراحة (فاعلان، سجل واحد فقط).
 *
 * **لا محاولة لجعل التفويض ومطالبة السجل معاملة عابرة للجداول (Cross-Table
 * Transaction) واحدة** — Event Access (عضوية/تخصيص مجموعة) وpge_message_log
 * مساران تخزين/قراءة منفصلان تماماً في هذا المشروع، ولا حدود معاملة مشتركة
 * آمنة ورخيصة موجودة فعلياً بينهما تُبرِّر هذا التعقيد. **سباق متبقٍّ حقيقي
 * وموثَّق صراحة، لا مخفي**: نافذة زمنية (ولو دقيقة جداً، بلا أي I/O إضافي
 * بينهما في هذا التصميم تحديداً) تفصل قراءة التفويض/التخصيص عن الالتزام
 * الفعلي في claim() — تغيّر تخصيص/عضوية قد يقع نظرياً بين اللحظتين (تعديل
 * من طلب HTTP متزامن آخر تماماً، لا من داخل هذا الطلب نفسه). **هذا متوقَّع
 * ومقبول بموجب الدفاع المتعدد الطبقات (Defense in Depth) المعتمد أصلاً في
 * DEC-009**: "إعادة التحقق من التفويض عند وقت تنفيذ العامل (Worker execution
 * time)، لا عند وقت إدخال الطلب في الطابور فقط" — طلب دائم (Durable Request)
 * قد يُنشَأ هنا في D2-W4 ثم يصبح لاحقاً غير مخوَّل قبل التنفيذ الفعلي؛ ذلك
 * العامل المستقبلي (غير المُنفَّذ هنا إطلاقاً) هو من يجب أن يرفض النقل
 * ويُنهي/يُلغي تلك المحاولة عندها — **ليست مسؤولية D2-W4 ولا فشلاً فيه**،
 * هذا سلوك معماري متوقَّع وموثَّق مسبقاً، لا يُخفى هنا.
 *
 * ========================================================================
 * القسم 9 من الموجز — النيّة (Normal/Resend) لا تُحوَّل صامتة إطلاقاً
 * ========================================================================
 * request_send_for_actor() تُمرِّر $intent كما وصلت حرفياً إلى كل من
 * authorize_send_for_actor() وPGE_Invitation_Send_Ledger::claim() — لا
 * تحويل، لا قيمة افتراضية بديلة مخفية. القرارات الناتجة (مطابقة تماماً
 * لمصفوفة القسم 9 في الموجز، مُشتقّة كاملة من D2-W3 + خريطة claim() أدناه):
 *   normal + not_sent/failed      → مؤهَّل للمطالبة → claim() فعلياً.
 *   normal + provider_accepted    → محجوب قبل/عند المطالبة كـalready_sent
 *                                    (D2-W3 يمنع استدعاء claim() أصلاً).
 *   normal + ambiguous            → محجوب كـresend_required (D2-W3 يمنع
 *                                    استدعاء claim() أصلاً).
 *   resend + provider_accepted    → مطالبة (Claim) بمحاولة جديدة فعلياً.
 *   resend + ambiguous            → مطالبة (Claim) بمحاولة جديدة واعية
 *                                    فعلياً.
 *   resend + not_sent/failed      → invalid_state (D2-W3 يمنع استدعاء
 *                                    claim() أصلاً — قرار حَكَمي موثَّق هناك).
 *   pending (أي نيّة)              → already_in_progress (D2-W3 يمنع
 *                                    استدعاء claim() أصلاً).
 *
 * ========================================================================
 * النطاق (D2-W3 وD2-W4 معاً في هذا الملف)
 * ========================================================================
 * لا إرسال فعلي، لا Queue/Worker، لا AJAX/UI، لا Cartat/UltraMsg، لا إرسال
 * جماعي. لا Caller إنتاجي يستخدم هذه الطبقة بعد. يختبرها
 * tests/test-d2-w3-invitation-send-authorization.php (D2-W3) و
 * tests/test-d2-w4-invitation-send-request.php (D2-W4).
 */
final class PGE_Invitation_Send_Application
{
    /** الفاعل مخوَّل، والنيّة المطلوبة صالحة لحالة الإرسال الحالية — يمكن المتابعة لـD2-W4 (claim() الفعلية). */
    public const RESULT_AUTHORIZED = 'authorized';

    /** غير مخوَّل — يشمل: مناسبة غير موجودة (EC1)، فاعل بلا سياق موثوق، أو can_send_guest_invitation() رفضت. */
    public const RESULT_NOT_AUTHORIZED = 'not_authorized';

    /** مدخلات غير صالحة شكلياً، أو تعارض حالة/نيّة داخلي (راجع توثيق evaluate_intent_against_state() أعلاه)، أو فشل قراءة دفاعي غير متوقَّع. */
    public const RESULT_INVALID_STATE = 'invalid_state';

    /** state=provider_accepted + intent=normal — الإرسال العادي لا يُعيد الإرسال ضمناً لضيف أُرسل له مسبقاً بنجاح. */
    public const RESULT_ALREADY_SENT = 'already_sent';

    /** state=send_requested (أي نيّة) — محاولة أخرى قيد التنفيذ فعلاً وفق D2-W2. */
    public const RESULT_ALREADY_IN_PROGRESS = 'already_in_progress';

    /** الفاعل مخوَّل، لكن الإرسال العادي محجوب لحالة الإرسال الحالية ويلزم قرار Resend صريح (intent=resend) بدلاً منه. */
    public const RESULT_RESEND_REQUIRED = 'resend_required';

    /** لا ضيف بهذا الهاتف في هذه المناسبة حالياً — راجع تحذير EC1/Oracle الموثَّق أعلاه في رأس الملف قبل أي تعرّض خارجي مستقبلي. */
    public const RESULT_NOT_FOUND = 'not_found';

    /** D2-W4: مطالبة (Claim) جديدة نجحت فعلياً عبر PGE_Invitation_Send_Ledger::claim() — طلب إرسال دائم قائم الآن، بلا أي إرسال فعلي بعد. نفس المصطلح الحرفي 'claimed' من claim() نفسها. */
    public const RESULT_CLAIMED = 'claimed';

    /** D2-W4: فشل تقني في طبقة المطالبة نفسها (قفل لم يُؤخَذ، فشل إنشاء صف، إلخ) — لا يُبلَّغ كنجاح إطلاقاً، ولا يُسرِّب تفاصيل قاعدة البيانات الخام. */
    public const RESULT_ERROR = 'error';

    /**
     * العقد الوحيد لهذه الطبقة — قراءة/تحقق طلب بالكامل، بلا أي أثر جانبي:
     * بلا claim()، بلا finalize_*()، بلا GET_LOCK، بلا إنشاء صف/طابور/دفعة.
     *
     * @param mixed  $event_id
     * @param mixed  $guest_phone
     * @param mixed  $actor_user_id راجع تحذير هوية الفاعل في رأس الملف —
     *                              داخلي/اختباري فقط، لا AJAX يستهلكه بعد.
     * @param string $intent        PGE_Invitation_Send_Ledger::INTENT_NORMAL
     *                              (افتراضي) أو ::INTENT_RESEND.
     * @return array{result:string,reason:?string,state?:string,actor_user_id?:int,is_admin?:bool,is_owner?:bool,guest_group_id?:?int}
     */
    public static function authorize_send_for_actor(
        $event_id,
        $guest_phone,
        $actor_user_id,
        $intent = PGE_Invitation_Send_Ledger::INTENT_NORMAL
    ): array {
        // 1) التحقق الشكلي من المدخلات — بلا أي قراءة قاعدة بيانات بعد.
        $event_id = is_scalar($event_id) ? (int) $event_id : 0;
        $actor_user_id = is_scalar($actor_user_id) ? (int) $actor_user_id : 0;
        $intent = is_scalar($intent) ? (string) $intent : '';
        $normalized_phone = self::normalize_phone($guest_phone);

        if ($event_id <= 0 || $actor_user_id <= 0) {
            return self::invalid_state('invalid_identity');
        }
        if ($normalized_phone === '') {
            return self::invalid_state('invalid_phone');
        }
        if (!in_array($intent, PGE_Invitation_Send_Ledger::VALID_INTENTS, true)) {
            return self::invalid_state('invalid_intent');
        }

        if (!class_exists('PGE_Event_Access_Authorization')
            || !class_exists('PGE_Invitation_Repository')
            || !class_exists('PGE_Event_Access_Repository')
            || !class_exists('PGE_Invitation_Send_State')) {
            return self::invalid_state('dependency_unavailable');
        }

        // 2-3) حلّ سياق الفاعل + انهيار EC1 (راجع توثيق رأس الملف). أي
        // WP_Error هنا — مناسبة غير موجودة أو أي سبب آخر يمنع حتى بناء
        // السياق — ينهار لنفس not_authorized الخارجي، بلا أي تمييز.
        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) {
            return self::not_authorized();
        }

        // 4) تأكيد وجود الضيف الحالي — راجع تحذير EC1/Oracle الداخلي أعلاه:
        // هذا التمييز (not_found مقابل not_authorized) مقصود هنا، ويجب أن
        // يُطوى خارجياً لاحقاً في طبقة النقل.
        $invitation = PGE_Invitation_Repository::get_invitation($event_id, $normalized_phone);
        if (!is_array($invitation)) {
            return self::not_found();
        }

        // 5) حلّ التخصيص الحالي للضيف — null يعني "غير مُخصَّص حالياً"، حالة
        // صالحة وليست خطأ (القسم 7 من الموجز).
        $assignment = PGE_Event_Access_Repository::get_guest_assignment($event_id, $normalized_phone);
        if ($assignment instanceof WP_Error) {
            return self::invalid_state('assignment_unavailable');
        }
        $guest_group_id = (is_array($assignment) && isset($assignment['group_id']) && is_int($assignment['group_id']) && $assignment['group_id'] > 0)
            ? $assignment['group_id']
            : null;

        // 6) التفويض — Owner/Admin أو الداعي الإضافي المؤهَّل فقط. رفض هنا
        // يُعيد not_authorized فوراً بلا أي قراءة لحالة الإرسال أدناه — لا
        // تسريب لأي معلومة عن حالة الإرسال لفاعل غير مخوَّل أصلاً.
        $decision = PGE_Event_Access_Authorization::can_send_guest_invitation($context, $guest_group_id);
        if (empty($decision['allowed'])) {
            return self::not_authorized();
        }

        // 7) قراءة حالة إرسال D2-W2 — فقط بعد ثبوت التفويض في الخطوة 6.
        $send_state = PGE_Invitation_Send_State::resolve($event_id, $normalized_phone);
        if (empty($send_state['ok'])) {
            // دفاعي بحت: الضيف مُؤكَّد الوجود قبل لحظات (الخطوة 4) — السبب
            // الواقعي الوحيد هنا هو تسابق (Race) حذف بين الخطوة 4 وهذه
            // الخطوة. الانهيار الآمن هنا بدلاً من الثقة بفحص وجود قديم.
            return self::invalid_state('send_state_unavailable');
        }

        // 8) مطابقة النيّة المطلوبة مقابل حالة الإرسال — راجع توثيق مفصَّل
        // في رأس الملف.
        return self::evaluate_intent_against_state($send_state, $intent, $context, $guest_group_id);
    }

    /**
     * D2-W4 — حدود الطفرة (Mutation Boundary) الوحيدة في هذا الملف. راجع
     * توثيق رأس الملف (قسم D2-W4) للترتيب الحتمي الكامل ولتحليل TOCTOU.
     *
     * الكتابة الوحيدة المسموحة: تفويض مباشر لـPGE_Invitation_Send_Ledger::
     * claim() — لا شيء آخر يُكتَب هنا إطلاقاً. لا finalize_*()، لا قفل
     * إضافي خاص بهذه الطبقة (القفل الذري بالكامل من مسؤولية claim() نفسها).
     *
     * القاعدة الأهم (القسم 3 من الموجز): **لا نتيجة تفويض سابقة تُعامَل
     * كرمز صلاحية قابل لإعادة الاستخدام** — كل استدعاء لهذه الدالة يُجري
     * تفويضاً طازجاً تماماً عبر authorize_send_for_actor() فور استدعائه؛
     * لا معامل في التوقيع يقبل نتيجة تفويض جاهزة من المستدعي إطلاقاً.
     *
     * @param mixed  $event_id
     * @param mixed  $guest_phone
     * @param mixed  $actor_user_id راجع تحذير هوية الفاعل في توثيق D2-W3
     *                              أعلاه — داخلي/اختباري فقط، لا AJAX بعد.
     * @param string $intent        PGE_Invitation_Send_Ledger::INTENT_NORMAL
     *                              (افتراضي) أو ::INTENT_RESEND — يُمرَّر
     *                              كما وصل حرفياً، بلا أي تحويل صامت.
     * @return array{result:string,reason:?string,event_id?:int,actor_user_id?:int,intent?:string,log_id?:int,batch_id?:?string,state?:string}
     */
    public static function request_send_for_actor(
        $event_id,
        $guest_phone,
        $actor_user_id,
        $intent = PGE_Invitation_Send_Ledger::INTENT_NORMAL
    ): array {
        // 1) التحقق الشكلي + 2-3) تفويض/حالة طازجة تماماً — استدعاء واحد
        // فعلي حقيقي، بلا أي تخزين مؤقّت، بلا أي نتيجة جاهزة من المستدعي.
        // راجع توثيق رأس الملف لماذا يُغطّي هذا الاستدعاء الوحيد أيضاً
        // متطلب "immediately before mutation" (لا فاصل I/O بينه وبين
        // claim() أدناه).
        $auth = self::authorize_send_for_actor($event_id, $guest_phone, $actor_user_id, $intent);

        $normalized_event_id = is_scalar($event_id) ? (int) $event_id : 0;
        $normalized_actor_user_id = is_scalar($actor_user_id) ? (int) $actor_user_id : 0;
        $normalized_intent = is_scalar($intent) ? (string) $intent : '';

        if (($auth['result'] ?? null) !== self::RESULT_AUTHORIZED) {
            // غير مخوَّل، أو النيّة/الحالة لا تسمح بالمتابعة أصلاً (already_
            // sent/already_in_progress/resend_required/invalid_state/
            // not_found) — نفس رمز D2-W3 حرفياً، بلا أي استدعاء لـclaim().
            return self::request_result(
                (string) ($auth['result'] ?? self::RESULT_NOT_AUTHORIZED),
                $normalized_event_id,
                $normalized_actor_user_id,
                $normalized_intent,
                isset($auth['reason']) ? $auth['reason'] : null
            );
        }

        // 4) استدعاء claim() الفعلية — فقط الآن، بعد تفويض طازج ناجح تماماً.
        // نفس $guest_phone الخام يُمرَّر كما وصل — claim() تُطبِّعه بنفسها
        // (pge_norm_phone()، نفس القناة التي طبَّعت بها authorize_send_for_
        // actor() الاسم أعلاه بالفعل — عملية Idempotent، لا تعارض).
        if (!class_exists('PGE_Invitation_Send_Ledger')) {
            return self::request_result(self::RESULT_ERROR, $normalized_event_id, $normalized_actor_user_id, $normalized_intent, 'dependency_unavailable');
        }

        $claim = PGE_Invitation_Send_Ledger::claim($event_id, $guest_phone, $actor_user_id, $intent);

        // 5-6) تطبيع نتيجة claim() الخام إلى نتيجة تطبيقية واحدة مستقرة.
        return self::map_claim_result($claim, $normalized_event_id, $normalized_actor_user_id, $normalized_intent);
    }

    /**
     * تطبيع نتيجة PGE_Invitation_Send_Ledger::claim() الخام (D2-W1) إلى
     * عقد نتيجة D2-W4 المستقر — دالة نقية بحتة، بلا أي قراءة/كتابة إضافية.
     * لا تُسرِّب أي تفاصيل قاعدة بيانات خام (رسائل $wpdb->last_error، إلخ) —
     * فقط رموز النتيجة الثابتة أدناه + سبب نصي داخلي قصير عند الحاجة.
     *
     * القسم 6 من الموجز: "Do not leak guest existence externally... design
     * the result shape so a future AJAX layer can collapse not_found and
     * not_authorized without ambiguity" — كلا الرمزين هنا (المُعاد من
     * authorize_send_for_actor() في حال not_authorized/not_found، انظر
     * request_send_for_actor() أعلاه) يبقيان نصّين مسطَّحين متمايزين داخلياً
     * فقط (['result' => 'not_found'] أو ['result' => 'not_authorized'])
     * بلا أي حقل إضافي يُميِّز بينهما دلالياً بأكثر من قيمة 'result' نفسها
     * — طيّهما لنفس القيمة الخارجية الواحدة لاحقاً (طبقة AJAX/D2-W5+) هو
     * مجرد شرط `if (in_array($result, ['not_found','not_authorized'], true))
     * { $result = 'not_authorized'; }` بسيط، بلا أي إعادة هيكلة.
     */
    private static function map_claim_result(array $claim, int $event_id, int $actor_user_id, string $intent): array
    {
        $code = is_scalar($claim['result'] ?? null) ? (string) $claim['result'] : '';

        if ($code === 'claimed') {
            $log_id = isset($claim['log_id']) ? (int) $claim['log_id'] : 0;
            if ($log_id <= 0) {
                // دفاعي بحت: claim() لا تُعيد 'claimed' فعلياً بلا log_id
                // صالح في أي مسار حقيقي موجود — لكن هذه الطبقة لا تُبلِّغ
                // نجاحاً أبداً بلا هوية محاولة دائمة حقيقية قابلة للاستخدام
                // (القسم U/T من الموجز: "DB claim failure does not report
                // success").
                return self::request_result(self::RESULT_ERROR, $event_id, $actor_user_id, $intent, 'claimed_without_log_id');
            }
            return [
                'result' => self::RESULT_CLAIMED,
                'reason' => null,
                'event_id' => $event_id,
                'actor_user_id' => $actor_user_id,
                'intent' => $intent,
                'log_id' => $log_id,
                'batch_id' => isset($claim['batch_id']) && is_scalar($claim['batch_id']) ? (string) $claim['batch_id'] : null,
                'state' => 'send_requested',
            ];
        }

        // القسم 4 من الموجز، السيناريو الثالث: فاعل آخر طالَب (Claim) بنفس
        // الضيف بين تفويضنا الطازج وlحظة claim() هنا — نتيجة الحصر (Dedup)
        // من D2-W1 نفسها هي الفائزة، لا صف مكرَّر يُنشَأ من جانبنا.
        if ($code === 'already_sent') {
            return self::request_result(self::RESULT_ALREADY_SENT, $event_id, $actor_user_id, $intent, isset($claim['reason']) ? $claim['reason'] : null);
        }
        if ($code === 'already_in_progress') {
            return self::request_result(self::RESULT_ALREADY_IN_PROGRESS, $event_id, $actor_user_id, $intent, isset($claim['reason']) ? $claim['reason'] : null);
        }
        if ($code === 'invalid_state') {
            return self::request_result(self::RESULT_INVALID_STATE, $event_id, $actor_user_id, $intent, isset($claim['reason']) ? $claim['reason'] : null);
        }

        // 'error' الصريحة، أو أي رمز غير متوقَّع دفاعياً — لا تُبلَّغ أبداً
        // كنجاح. لا نُمرِّر reason الخام من claim() هنا حرفياً لو كان قد
        // يحمل تفاصيل قاعدة بيانات داخلية (lock_not_acquired/log_create_
        // failed/stale_claim_close_failed تبقى آمنة الإفصاح داخلياً — أسماء
        // ثابتة معروفة من D2-W1 نفسها، لا رسالة $wpdb خام مطلقاً).
        return self::request_result(self::RESULT_ERROR, $event_id, $actor_user_id, $intent, isset($claim['reason']) ? $claim['reason'] : 'claim_failed');
    }

    private static function request_result(string $code, int $event_id, int $actor_user_id, string $intent, $reason = null): array
    {
        return [
            'result' => $code,
            'reason' => $reason,
            'event_id' => $event_id,
            'actor_user_id' => $actor_user_id,
            'intent' => $intent,
        ];
    }

    /**
     * دالة نقية بحتة — بلا أي قراءة/كتابة قاعدة بيانات إضافية. راجع توثيق
     * القسم 8 المفصَّل في رأس الملف لمصدر كل قرار أدناه.
     */
    private static function evaluate_intent_against_state(
        array $send_state,
        string $intent,
        PGE_Event_Access_Authorization_Context $context,
        $guest_group_id
    ): array {
        $state = is_scalar($send_state['state'] ?? null) ? (string) $send_state['state'] : '';
        $normal_allowed = !empty($send_state['normal_send_allowed']);
        $resend_required = !empty($send_state['resend_required']);
        $in_progress = !empty($send_state['in_progress']);

        if ($in_progress) {
            // القسم 8: send_requested يحجب كلتا النيّتين صراحة — هذه الطبقة
            // تتّبع علم in_progress من D2-W2 كما هو (دالة نقية لـstate فقط،
            // بلا أي حساب انتهاء Lease هنا أو هناك) بدلاً من إعادة اشتقاق
            // توقيت الـLease، الذي يبقى حصراً شأن claim() الذرية في D2-W1.
            return self::result(self::RESULT_ALREADY_IN_PROGRESS, $context, $guest_group_id, $state, 'send_in_progress');
        }

        if ($intent === PGE_Invitation_Send_Ledger::INTENT_NORMAL) {
            if ($normal_allowed) {
                return self::result(self::RESULT_AUTHORIZED, $context, $guest_group_id, $state);
            }
            if ($state === 'provider_accepted') {
                // نفس مصطلح claim() الحرفي 'already_sent' لنفس الحالة تماماً.
                return self::result(self::RESULT_ALREADY_SENT, $context, $guest_group_id, $state);
            }
            if ($resend_required) {
                // مثال: ambiguous_transport_error — عادي محجوب صراحة، إعادة
                // إرسال صريحة هي الإجراء الشرعي الوحيد التالي.
                return self::result(self::RESULT_RESEND_REQUIRED, $context, $guest_group_id, $state);
            }
            return self::result(self::RESULT_INVALID_STATE, $context, $guest_group_id, $state, 'normal_send_blocked');
        }

        // intent === PGE_Invitation_Send_Ledger::INTENT_RESEND (التحقق من
        // صلاحية القيمة نفسها تم بالفعل في authorize_send_for_actor()).
        if ($resend_required) {
            return self::result(self::RESULT_AUTHORIZED, $context, $guest_group_id, $state);
        }

        // قرار حَكَمي موثَّق بالتفصيل في رأس الملف — راجعه قبل تعديل هذا
        // الفرع: not_sent/failed + intent=resend صراحة يُعامَلان كتعارض
        // حالة/نيّة هنا، لا تفويضاً ضمنياً، رغم أن claim() الفعلية (غير
        // المُستدعاة هنا إطلاقاً) لا تُميِّز فعلياً بين النيّتين لهاتين
        // الحالتين تحديداً.
        return self::result(self::RESULT_INVALID_STATE, $context, $guest_group_id, $state, 'resend_not_applicable');
    }

    private static function result(
        string $code,
        PGE_Event_Access_Authorization_Context $context,
        $guest_group_id,
        string $state,
        $reason = null
    ): array {
        return [
            'result' => $code,
            'reason' => $reason,
            'state' => $state,
            'actor_user_id' => $context->actor_user_id(),
            'is_admin' => $context->is_admin(),
            'is_owner' => $context->is_owner(),
            'guest_group_id' => $guest_group_id,
        ];
    }

    private static function not_authorized(): array
    {
        return ['result' => self::RESULT_NOT_AUTHORIZED, 'reason' => null];
    }

    private static function not_found(): array
    {
        return ['result' => self::RESULT_NOT_FOUND, 'reason' => null];
    }

    private static function invalid_state($reason): array
    {
        return ['result' => self::RESULT_INVALID_STATE, 'reason' => $reason];
    }

    /**
     * pge_norm_phone()/pge_event_guests_norm_phone() دالتان متطابقتان فعلياً
     * (الثانية مجرد اسم مستعار يستدعي الأولى مباشرة — includes/helpers.php)
     * — التطبيع هنا مرة واحدة آمن تماماً حتى مع إعادة التطبيع الداخلية التي
     * تُجريها PGE_Invitation_Repository::get_invitation() وPGE_Event_Access_
     * Repository::get_guest_assignment() كلتاهما (عملية Idempotent على نص
     * أرقام فقط أصلاً).
     */
    private static function normalize_phone($value): string
    {
        return function_exists('pge_norm_phone')
            ? pge_norm_phone($value)
            : preg_replace('/\D+/', '', trim((string) $value));
    }
}
