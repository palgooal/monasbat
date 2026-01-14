<?php
// includes/class-gallery.php
if (!defined('ABSPATH')) exit;

class Mon_Events_Gallery
{
    /** @var Mon_Events_MVP */
    private $plugin;

    const META_KEY = '_mon_gallery_ids';

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post_event', [$this, 'save_gallery_meta'], 20, 2);

        // Assets for admin edit screen
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_metabox(): void
    {
        add_meta_box(
            'mon_event_gallery',
            'ألبوم الصور',
            [$this, 'render_metabox'],
            'event',
            'normal',
            'default'
        );
    }

    public function render_metabox($post): void
    {
        $ids = get_post_meta($post->ID, self::META_KEY, true);
        if (!is_array($ids)) $ids = [];

        wp_nonce_field('mon_event_gallery_save', 'mon_event_gallery_nonce');

?>
        <div class="mon-gallery-admin" data-role="monGalleryAdmin">

            <p style="margin:0 0 10px;color:#6b7280;font-size:12px">
                اختر صور متعددة من مكتبة الوسائط، ثم يمكنك ترتيب الصور بالسحب والإفلات.
            </p>

            <input type="hidden" name="mon_gallery_ids" value="<?php echo esc_attr(implode(',', array_map('intval', $ids))); ?>" data-role="monGalleryIds">

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px">
                <button type="button" class="button button-primary" data-role="monGalleryAdd">إضافة / اختيار صور</button>
                <button type="button" class="button" data-role="monGalleryClear">مسح الكل</button>
            </div>

            <div class="mon-gallery-grid" data-role="monGalleryGrid" style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px">
                <?php foreach ($ids as $id):
                    $id = (int)$id;
                    $thumb = wp_get_attachment_image_url($id, 'thumbnail');
                    if (!$thumb) continue;
                ?>
                    <div class="mon-gallery-item" data-id="<?php echo (int)$id; ?>" style="position:relative;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
                        <img src="<?php echo esc_url($thumb); ?>" alt="" style="width:100%;height:90px;object-fit:cover;display:block">
                        <button type="button" class="mon-gallery-remove" data-role="monGalleryRemove"
                            style="position:absolute;top:6px;left:6px;background:#111827;color:#fff;border:0;border-radius:10px;padding:4px 8px;cursor:pointer;font-size:12px">
                            حذف
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="margin:10px 0 0;color:#6b7280;font-size:12px">
                * سيتم حفظ الألبوم في <code><?php echo esc_html(self::META_KEY); ?></code>
            </p>
        </div>
<?php
    }

    public function enqueue_admin_assets($hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;

        // فقط في event edit/new
        if ($screen->post_type !== 'event') return;
        if (!in_array($screen->base, ['post', 'post-new'], true)) return;

        // ✅ مهم جداً: تحميل media frame
        wp_enqueue_media();

        // ✅ للسحب والإفلات (إذا بتستخدم sortable)
        wp_enqueue_script('jquery-ui-sortable');

        // ✅ تحميل سكربت الأدمن
        wp_enqueue_script(
            'mon-events-admin',
            plugins_url('assets/mon-events-admin.js', dirname(__DIR__) . '/mon-events.php'),
            ['jquery', 'jquery-ui-sortable'],
            '0.2.0',
            true
        );
    }


    public function save_gallery_meta($post_id, $post): void
    {
        $post_id = (int)$post_id;

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (
            !isset($_POST['mon_event_gallery_nonce']) ||
            !wp_verify_nonce($_POST['mon_event_gallery_nonce'], 'mon_event_gallery_save')
        ) {
            return;
        }

        $raw = (string)($_POST['mon_gallery_ids'] ?? '');
        $raw = trim($raw);

        if ($raw === '') {
            update_post_meta($post_id, self::META_KEY, []);
            return;
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $ids = [];

        foreach ($parts as $p) {
            $id = (int)$p;
            if ($id > 0) $ids[] = $id;
        }

        // إزالة تكرار + ترتيب ثابت
        $ids = array_values(array_unique($ids));

        update_post_meta($post_id, self::META_KEY, $ids);
    }
}
