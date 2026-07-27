<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Feature Resolver — Phase 3، Commit 1 (Resolver Core)
 * ============================================================================
 * وفق docs/FEATURES-PHASE-3-SPEC.md، مبني حرفياً على docs/PACKAGE-FEATURE-MATRIX.md
 * §7 (Resolver)، وdocs/DECISION-LOG.md: DEC-001، DEC-002، DEC-003.
 *
 * الترتيب الإلزامي (§7): Database (mon_tier_features، عبر PGE_Tier_Features)
 * → Registry (تعريف key → type/default, عبر PGE_Feature_Registry) → Resolver
 * (هذا الملف) → UI (خارج نطاق Phase 3).
 *
 * ترتيب مصادر القراءة لكل استدعاء (§8، DEC-003): Catalog Snapshot
 * (`_mon_package_features`) → Catalog Tier كـFallback دفاعي (فقط عند
 * `_mon_package_source === 'catalog'` و`_mon_package_status === 'active'`
 * معاً) → Legacy (`PGE_Packages` + `_mon_active_features`، فقط لمستخدم
 * `_mon_package_source !== 'catalog'`) → Default آمن من Registry، بعد تفسيره
 * دائماً وفق نوع الميزة (لا يُعاد أبداً كسلسلة/قيمة خام غير مُفسَّرة).
 *
 * تحديد Tier (DEC-003): المصدر الوحيد `_mon_catalog_tier_id` (User Meta،
 * تُكتب في class-mon-events-users.php:203). لا اشتقاق من Plan أو Package أو
 * Subscription. `PGE_Catalog::get_tier()` الذي يُعيد null موحَّداً لثلاث حالات
 * (tier_id غير صالح/الصف غير موجود/فشل استعلام) يُعامَل بالكامل كـ"Tier غير
 * متاح لهذا الاستدعاء" — تُتخطى خطوة Tier Fallback، يُتابَع الترتيب.
 *
 * فشل قاعدة البيانات (DEC-003): `false` من PGE_Tier_Features (وفق DEC-002)
 * يُعامَل مطابقاً تماماً لـ"لا قيمة موجودة في هذه الخطوة" — بلا استثناء، بلا
 * إشارة خطأ منفصلة، يُتابَع الترتيب فقط.
 *
 * Type Parsing (§9، DEC-003): boolean (واحدة فقط عبر pge_user_has_feature())،
 * integer، percentage (نفس آلية integer تماماً — (int) بلا Clamp). لا string،
 * لا array، لا enum.
 *
 * لا تعديل هنا على: Feature Registry (Phase 1)، Tier Features Repository
 * (Phase 2)، PGE_Catalog، Legacy (`PGE_Packages`/`_mon_active_features`)، أي
 * Schema/DB_VERSION. هذا الملف يقرأ فقط عبر الواجهات العامة الموجودة أصلاً؛
 * لا استعلام SQL مباشر، لا Query جديد.
 *
 * Snapshot (`_mon_package_features`): يُقرَأ فقط عبر `get_user_meta()` خام —
 * لا كتابة، لا إنشاء، لا تعديل. Phase 4 (خارج نطاق هذا الملف بالكامل) هي من
 * تكتب هذا المفتاح لاحقاً داخل `activate_catalog_tier()`؛ قبل ذلك، المفتاح
 * غالباً غير موجود لمعظم المستخدمين، فتُعيد `get_user_meta()` سلسلة فارغة
 * (غير Array)، ويُعامَل ذلك تلقائياً كـ"لا Snapshot" دون أي حالة خاصة.
 */

if (!function_exists('pge_feature_resolver_interpret_boolean')) {
    /**
     * تفسير Boolean — نفس القاعدة الحرفية المُستشهَد بها في §9 من
     * docs/FEATURES-PHASE-3-SPEC.md، منقولة من includes/event-factory.php
     * (دالة pge_plan_feature_enabled_for_events()، الأسطر 333-344): القيمة
     * الخام (أي مصدر: Snapshot/Tier/Legacy/Registry Default) تُقارَن كسلسلة
     * نصية بعد lowercase/trim بأشكال "صحيح" المعروفة فقط — لا تخمين، لا
     * PHP truthiness عامة.
     *
     * @param mixed $raw_value
     * @return bool
     */
    function pge_feature_resolver_interpret_boolean($raw_value)
    {
        $value = strtolower(trim((string) $raw_value));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }
}

if (!function_exists('pge_feature_resolver_interpret_integer')) {
    /**
     * تفسير Integer/Percentage — (int) صريح بلا Validation إضافي، وفق §9
     * (Integer محسوم بالكامل؛ Percentage يتبع نفس الآلية تماماً وفق DEC-003
     * — لا Clamp لأي نطاق، لا رفض لأي قيمة خارج 0-100).
     *
     * @param mixed $raw_value
     * @return int
     */
    function pge_feature_resolver_interpret_integer($raw_value)
    {
        return (int) $raw_value;
    }
}

if (!function_exists('pge_feature_resolver_interpret_by_type')) {
    /**
     * نقطة التفسير الموحَّدة الوحيدة — تسأل عن النوع أولاً (يُمرَّر من المستدعي
     * بعد قراءته من Registry) ثم تُطبِّق قاعدة ذلك النوع فقط، وفق القاعدة
     * الحاسمة في PACKAGE-FEATURE-MATRIX.md §7: "لا يفسّر القيمة الخام مباشرة
     * بأي منطق مضمَّن... يسأل Registry أولاً 'ما نوع $feature_key؟'... ثم
     * يُطبِّق قاعدة التفسير الموحَّدة لذلك النوع فقط."
     *
     * الأنواع الثلاثة المعتمدة فقط اليوم في Registry: boolean, integer,
     * percentage (لا string، لا array، لا enum — لا نوع رابع مُعرَّف).
     *
     * @param string $type
     * @param mixed  $raw_value
     * @return mixed
     */
    function pge_feature_resolver_interpret_by_type($type, $raw_value)
    {
        if ($type === 'boolean') {
            return pge_feature_resolver_interpret_boolean($raw_value);
        }

        if ($type === 'integer' || $type === 'percentage') {
            return pge_feature_resolver_interpret_integer($raw_value);
        }

        // لا نوع رابع معرَّف في Registry اليوم — لا تخمين لقاعدة تفسير غير
        // موثَّقة؛ إعادة القيمة كما هي بلا تفسير بدل اختراع سلوك جديد.
        return $raw_value;
    }
}

if (!function_exists('pge_feature_resolver_resolve_raw_value')) {
    /**
     * البحث عن القيمة الخام لميزة مستخدم واحدة، بالترتيب الإلزامي في §8:
     * Catalog Snapshot → Catalog Tier (Fallback دفاعي فقط) → Legacy (لمستخدم
     * غير Catalog فقط). لا تفسير نوع هنا إطلاقاً — القيمة المُعادة خام كما
     * وُجدت (قد تكون سلسلة نصية من mon_tier_features، أو قيمة مُفسَّرة أصلاً
     * من Snapshot وفق §9، أو 0/1 من Legacy) — التفسير يحدث لاحقاً في الدالة
     * المستدعية عبر pge_feature_resolver_interpret_by_type().
     *
     * لا Exception — أي تعذّر في أي خطوة (Tier غير متاح، فشل قاعدة بيانات،
     * مفتاح غير موجود) يُعامَل كـ"لم يُعثَر"، وينتقل البحث للخطوة التالية.
     *
     * @param int    $user_id
     * @param string $feature_key
     * @return array{found: bool, value: mixed}
     */
    function pge_feature_resolver_resolve_raw_value($user_id, $feature_key)
    {
        // 1) Catalog Snapshot (_mon_package_features) — قراءة خام فقط، بلا
        // كتابة/إنشاء/تعديل. القيم هنا مُفسَّرة مسبقاً وفق §9 (بنية Snapshot)،
        // لكن تمريرها عبر نفس مُفسِّر النوع لاحقاً آمن (Idempotent) ومتّسق مع
        // DEC-003 (التفسير يُطبَّق بلا استثناء لمصدر القيمة).
        $snapshot = get_user_meta($user_id, '_mon_package_features', true);
        if (is_array($snapshot) && array_key_exists($feature_key, $snapshot)) {
            return ['found' => true, 'value' => $snapshot[$feature_key]];
        }

        $package_source = (string) get_user_meta($user_id, '_mon_package_source', true);

        if ($package_source === 'catalog') {
            // 2) Catalog Tier — Fallback دفاعي فقط، وفق DEC-003: يُقرأ فقط
            // إذا كانت الحالة active أيضاً (بامتداد نمط
            // pge_get_catalog_user_plan_limits_for_events() في
            // event-factory.php:91-93، 197-199).
            $package_status = (string) get_user_meta($user_id, '_mon_package_status', true);

            if ($package_status === 'active') {
                $tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));

                if ($tier_id > 0 && class_exists('PGE_Catalog')) {
                    $tier = PGE_Catalog::get_tier($tier_id);

                    // null (tier_id غير صالح/الصف غير موجود/فشل استعلام —
                    // PGE_Catalog::get_tier() لا يميّز بينها، DEC-003) يُعامَل
                    // كـ"Tier غير متاح" فقط — لا مزيد من المحاولات هنا.
                    if (is_array($tier)) {
                        // فحص اتساق دفاعي، بنفس نمط event-factory.php:247-254:
                        // إن تعارض plan_id المخزَّن مع plan_id الفعلي لصف
                        // الـtier، تُهمَل بيانات ذلك الـtier بالكامل.
                        $catalog_plan_id = absint(get_user_meta($user_id, '_mon_catalog_plan_id', true));
                        $tier_plan_id = absint($tier['plan_id'] ?? 0);

                        if ($catalog_plan_id <= 0 || $tier_plan_id === $catalog_plan_id) {
                            if (class_exists('PGE_Tier_Features')) {
                                $raw = PGE_Tier_Features::get_tier_feature_value($tier_id, $feature_key);

                                // string = موجود (DEC-002). null (غير موجود)
                                // وfalse (فشل استعلام، DEC-003) يُعامَلان
                                // كلاهما كـ"لم يُعثَر" هنا بالضبط.
                                if (is_string($raw)) {
                                    return ['found' => true, 'value' => $raw];
                                }
                            }
                        }
                    }
                }
            }

            // مستخدم Catalog: لا fallback إلى Legacy تحت أي ظرف (الفصل
            // الصارم في PACKAGE-FEATURE-MATRIX.md §13) — الانتقال المباشر
            // لـ"غير موجود" يعني أن المستدعي سيستخدم Default من Registry.
            return ['found' => false, 'value' => null];
        }

        // 3) Legacy — فقط لمستخدم _mon_package_source !== 'catalog'. يُستخدَم
        // فقط ما هو موثَّق فعلياً: PGE_Packages::get_user_plan_limits() (التي
        // تقرأ _mon_active_features داخلياً) — لا قراءة مباشرة جديدة لأي
        // مفتاح Legacy، ولا Mapping مُخترَع بين أسماء مفاتيح Legacy القديمة
        // (14 مفتاحاً في PGE_Packages::get_feature_keys()) وأسماء Feature
        // Registry الجديدة (19 مفتاحاً) — التطابق الحرفي فقط، بلا تخمين، لأن
        // أي مطابقة أخرى غير موثَّقة ومعلَّقة صراحة في PACKAGE-FEATURE-MATRIX.md
        // §10 (بنود 5، 6).
        //
        // استثناء واحد مؤكَّد فقط (Phase 6 — Commit 1.1a): `google_maps`
        // (Registry) يقابله تاريخياً `google_map` (مفرد) في
        // PGE_Packages::get_feature_keys() — تطابق 1:1 موثَّق حرفياً في
        // PACKAGE-FEATURE-MATRIX.md §4 ("مفتاح google_map مباشر")، لا تخمين.
        // هذا Fallback توافقي محصور بهذا المفتاح وحده — ليس جدول Alias عاماً،
        // ولا يُستخدَم لأي مفتاح آخر. المفتاح القانوني له الأولوية دائماً؛
        // الـAlias يُفحَص فقط إن غاب المفتاح القانوني عن Legacy.
        if (class_exists('PGE_Packages')) {
            $legacy_limits = PGE_Packages::get_user_plan_limits($user_id);

            if (is_array($legacy_limits)) {
                if (array_key_exists($feature_key, $legacy_limits)) {
                    return ['found' => true, 'value' => $legacy_limits[$feature_key]];
                }

                if ($feature_key === 'google_maps' && array_key_exists('google_map', $legacy_limits)) {
                    return ['found' => true, 'value' => $legacy_limits['google_map']];
                }
            }
        }

        return ['found' => false, 'value' => null];
    }
}

if (!function_exists('pge_user_has_feature')) {
    /**
     * Public API — PACKAGE-FEATURE-MATRIX.md §7:
     * pge_user_has_feature($user_id, $feature_key): bool
     *
     * عقد الإرجاع (DEC-003): bool دائماً — لا null، لا استثناء. feature_key
     * غير معرَّف في Registry → false (مُشتقّ إلزاماً من نوع الإرجاع المُعلَن).
     * غير ذلك: يُطبَّق مُفسِّر Boolean على الناتج (قيمة خام إن وُجدت، أو حقل
     * default من Registry إن لم توجد) بصرف النظر عن نوع الميزة المُعلَن.
     */
    function pge_user_has_feature($user_id, $feature_key): bool
    {
        $feature_key = (string) $feature_key;
        $definition = PGE_Feature_Registry::get($feature_key);

        if ($definition === null) {
            return false;
        }

        $resolved = pge_feature_resolver_resolve_raw_value((int) $user_id, $feature_key);

        if ($resolved['found']) {
            return pge_feature_resolver_interpret_boolean($resolved['value']);
        }

        return pge_feature_resolver_interpret_boolean($definition['default']);
    }
}

if (!function_exists('pge_get_user_feature_value')) {
    /**
     * Public API — PACKAGE-FEATURE-MATRIX.md §7:
     * pge_get_user_feature_value($user_id, $feature_key, $default = null)
     *
     * عقد الإرجاع (DEC-003): mixed، بلا PHP Return Type Declaration (مطابق
     * حرفياً لغياب `: type` في التوقيع المُجمَّد بـ§7). لا يُعيد أبداً null/false
     * كإشارة خطأ، لا يرمي استثناء.
     *
     * - feature_key غير معرَّف في Registry: يُعاد $default كما مُرِّر تماماً،
     *   دون أي محاولة تفسير (لا نوع معروف لتفسيره).
     * - feature_key معرَّف: يُطبَّق مُفسِّر النوع المُعرَّف في Registry على
     *   الناتج (قيمة خام إن وُجدت عبر §8، أو حقل default من Registry إن لم
     *   توجد قيمة في أي مصدر — بما في ذلك أي فشل قاعدة بيانات، المُعامَل هنا
     *   كـ"لا قيمة"). $default لا يُستخدَم أبداً لميزة معرَّفة (DEC-003).
     */
    function pge_get_user_feature_value($user_id, $feature_key, $default = null)
    {
        $feature_key = (string) $feature_key;
        $definition = PGE_Feature_Registry::get($feature_key);

        if ($definition === null) {
            return $default;
        }

        $type = $definition['type'];
        $resolved = pge_feature_resolver_resolve_raw_value((int) $user_id, $feature_key);

        if ($resolved['found']) {
            return pge_feature_resolver_interpret_by_type($type, $resolved['value']);
        }

        return pge_feature_resolver_interpret_by_type($type, $definition['default']);
    }
}

if (!function_exists('pge_feature_resolver_build_bulk_context')) {
    /**
     * بناء سياق قراءة جماعي (مرة واحدة فقط) لكل مصادر §8، خاص حصراً
     * بـpge_get_user_package_features() — إصلاح N+1 (Phase 3، Commit 1.1):
     * الإصدار السابق كان يستدعي pge_get_user_feature_value() داخل حلقة على
     * كل الميزات، مما يعني استدعاء PGE_Tier_Features::get_tier_feature_value()
     * وPGE_Catalog::get_tier() مرة لكل مفتاح (حتى 19 استعلام لكل منهما لكل
     * تنفيذ). هذه الدالة تقرأ كل مصدر **مرة واحدة فقط**، ثم يُعاد استخدام
     * النتيجة لكل مفتاح — بلا أي تغيير في ترتيب المصادر أو شروطها (§8،
     * DEC-003)، فقط دمج القراءات المتكررة لنفس البيانات في استدعاء واحد.
     *
     * لا يُستخدَم هذا السياق من pge_user_has_feature()/pge_get_user_feature_value()
     * — تينك الدالتان تبقيان تماماً كما هما، تقرآن ميزة واحدة عبر
     * pge_feature_resolver_resolve_raw_value() دون أي تغيير.
     *
     * @param int $user_id
     * @return array{
     *   snapshot: array|null,
     *   is_catalog: bool,
     *   tier_features: array|null,
     *   legacy_limits: array|null
     * }
     */
    function pge_feature_resolver_build_bulk_context($user_id)
    {
        $context = [
            'snapshot' => null,
            'is_catalog' => false,
            'tier_features' => null,
            'legacy_limits' => null,
        ];

        // 1) Catalog Snapshot — قراءة خام واحدة، بلا كتابة/إنشاء/تعديل.
        $snapshot = get_user_meta($user_id, '_mon_package_features', true);
        $context['snapshot'] = is_array($snapshot) ? $snapshot : null;

        $package_source = (string) get_user_meta($user_id, '_mon_package_source', true);

        if ($package_source === 'catalog') {
            $context['is_catalog'] = true;

            // 2) Catalog Tier — Fallback دفاعي فقط، بنفس شروط DEC-003
            // (source === 'catalog' و status === 'active' معاً)، لكن
            // PGE_Catalog::get_tier() وPGE_Tier_Features::get_all_tier_features()
            // يُستدعَيان هنا **مرة واحدة فقط** بدل مرة لكل مفتاح.
            $package_status = (string) get_user_meta($user_id, '_mon_package_status', true);

            if ($package_status === 'active') {
                $tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));

                if ($tier_id > 0 && class_exists('PGE_Catalog')) {
                    $tier = PGE_Catalog::get_tier($tier_id);

                    // null (غير صالح/غير موجود/فشل استعلام، DEC-003) يُعامَل
                    // كـ"Tier غير متاح" — tier_features تبقى null.
                    if (is_array($tier)) {
                        $catalog_plan_id = absint(get_user_meta($user_id, '_mon_catalog_plan_id', true));
                        $tier_plan_id = absint($tier['plan_id'] ?? 0);

                        if ($catalog_plan_id <= 0 || $tier_plan_id === $catalog_plan_id) {
                            if (class_exists('PGE_Tier_Features')) {
                                $rows = PGE_Tier_Features::get_all_tier_features($tier_id);

                                // array = نجح (قد تكون [])؛ false = فشل استعلام
                                // (DEC-002/DEC-003) يُعامَل كـ"لا قيم Tier متاحة".
                                if (is_array($rows)) {
                                    $tier_map = [];
                                    foreach ($rows as $row) {
                                        if (is_array($row) && array_key_exists('feature_key', $row) && array_key_exists('feature_value', $row)) {
                                            $tier_map[(string) $row['feature_key']] = (string) $row['feature_value'];
                                        }
                                    }
                                    $context['tier_features'] = $tier_map;
                                }
                            }
                        }
                    }
                }
            }

            // مستخدم Catalog: لا Legacy تحت أي ظرف — context['legacy_limits']
            // تبقى null عمداً.
            return $context;
        }

        // 3) Legacy — فقط لمستخدم _mon_package_source !== 'catalog'، بنفس
        // مصدر pge_feature_resolver_resolve_raw_value() (PGE_Packages
        // فقط)، لكن مرة واحدة هنا بدل مرة لكل مفتاح.
        if (class_exists('PGE_Packages')) {
            $legacy_limits = PGE_Packages::get_user_plan_limits($user_id);
            $context['legacy_limits'] = is_array($legacy_limits) ? $legacy_limits : null;
        }

        return $context;
    }
}

if (!function_exists('pge_feature_resolver_resolve_from_bulk_context')) {
    /**
     * البحث عن القيمة الخام لميزة واحدة داخل سياق مُحمَّل مسبقاً (عبر
     * pge_feature_resolver_build_bulk_context())، بنفس ترتيب ومنطق
     * pge_feature_resolver_resolve_raw_value() تماماً (§8، DEC-003) — الفرق
     * الوحيد أن مصادر Tier/Legacy هنا محمَّلة سلفاً في $context بدل استعلام
     * جديد لكل استدعاء. لا تفسير نوع هنا — قيمة خام فقط.
     *
     * @param array  $context
     * @param string $feature_key
     * @return array{found: bool, value: mixed}
     */
    function pge_feature_resolver_resolve_from_bulk_context(array $context, $feature_key)
    {
        if ($context['snapshot'] !== null && array_key_exists($feature_key, $context['snapshot'])) {
            return ['found' => true, 'value' => $context['snapshot'][$feature_key]];
        }

        if ($context['is_catalog']) {
            if ($context['tier_features'] !== null && array_key_exists($feature_key, $context['tier_features'])) {
                return ['found' => true, 'value' => $context['tier_features'][$feature_key]];
            }

            // مستخدم Catalog: لا fallback إلى Legacy تحت أي ظرف.
            return ['found' => false, 'value' => null];
        }

        if ($context['legacy_limits'] !== null && array_key_exists($feature_key, $context['legacy_limits'])) {
            return ['found' => true, 'value' => $context['legacy_limits'][$feature_key]];
        }

        return ['found' => false, 'value' => null];
    }
}

if (!function_exists('pge_get_user_package_features')) {
    /**
     * Public API — PACKAGE-FEATURE-MATRIX.md §7:
     * pge_get_user_package_features($user_id): array
     *
     * عقد الإرجاع (DEC-003): array مسطّحة (Flat) — feature_key => قيمة_مُفسَّرة
     * لكل الميزات التسع عشرة المُعرَّفة في Registry، بلا استثناء بحسب
     * lifecycle. كل مفتاح يُفسَّر وفق نفس الترتيب والقواعد التي تستخدمها
     * pge_get_user_feature_value() (§8، §9) — بلا $default مستقل لكل مفتاح
     * (لا بارامتر كهذا في التوقيع المُعلَن). المصفوفة الناتجة دوماً بحجم 19
     * عنصراً بالضبط، لا تُعيد الدالة أبداً false أو مصفوفة جزئية (التزاماً
     * بالتوقيع المُعلَن `: array`).
     *
     * إصلاح N+1 (Commit 1.1): بدل استدعاء pge_get_user_feature_value() داخل
     * الحلقة (والذي كان يُعيد استعلام Tier/Catalog من جديد لكل مفتاح)، تُبنى
     * القراءة الجماعية مرة واحدة عبر pge_feature_resolver_build_bulk_context()
     * (تستدعي PGE_Catalog::get_tier() وPGE_Tier_Features::get_all_tier_features()
     * مرة واحدة فقط لكل تنفيذ)، ثم يُعاد استخدام نفس السياق لكل مفتاح عبر
     * pge_feature_resolver_resolve_from_bulk_context(). الترتيب والشروط
     * والعقد والقيمة الناتجة كلها بلا أي تغيير عن السلوك السابق.
     */
    function pge_get_user_package_features($user_id): array
    {
        $user_id = (int) $user_id;
        $context = pge_feature_resolver_build_bulk_context($user_id);
        $result = [];

        foreach (PGE_Feature_Registry::all() as $feature_key => $definition) {
            $type = $definition['type'];
            $resolved = pge_feature_resolver_resolve_from_bulk_context($context, $feature_key);

            if ($resolved['found']) {
                $result[$feature_key] = pge_feature_resolver_interpret_by_type($type, $resolved['value']);
            } else {
                $result[$feature_key] = pge_feature_resolver_interpret_by_type($type, $definition['default']);
            }
        }

        return $result;
    }
}
