// assets/mon-events-admin.js
(function ($) {
    function syncIds($root) {
        const ids = [];
        $root.find('[data-role="monGalleryGrid"] .mon-gallery-item').each(function () {
            const id = parseInt($(this).attr('data-id') || '0', 10);
            if (id) ids.push(id);
        });
        $root.find('[data-role="monGalleryIds"]').val(ids.join(','));
    }

    function bind($root) {
        const $grid = $root.find('[data-role="monGalleryGrid"]');

        // sortable
        if ($.fn.sortable) {
            $grid.sortable({
                items: '.mon-gallery-item',
                stop: function () {
                    syncIds($root);
                }
            });
        }

        // add/select
        $root.on('click', '[data-role="monGalleryAdd"]', function (e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                alert('مكتبة الوسائط غير محمّلة (wp.media). تأكد من wp_enqueue_media().');
                return;
            }

            const frame = wp.media({
                title: 'اختر صور الألبوم',
                button: { text: 'إضافة' },
                multiple: true,
                library: { type: 'image' }
            });

            frame.on('select', function () {
                const selection = frame.state().get('selection').toJSON();

                selection.forEach(function (att) {
                    const id = att.id;
                    const thumb =
                        (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url)
                            ? att.sizes.thumbnail.url
                            : (att.url || '');

                    if (!id || !thumb) return;

                    // منع التكرار
                    if ($grid.find('.mon-gallery-item[data-id="' + id + '"]').length) return;

                    const item = `
            <div class="mon-gallery-item" data-id="${id}" style="position:relative;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
              <img src="${thumb}" alt="" style="width:100%;height:90px;object-fit:cover;display:block">
              <button type="button" class="mon-gallery-remove" data-role="monGalleryRemove"
                style="position:absolute;top:6px;left:6px;background:#111827;color:#fff;border:0;border-radius:10px;padding:4px 8px;cursor:pointer;font-size:12px">
                حذف
              </button>
            </div>
          `;
                    $grid.append(item);
                });

                syncIds($root);
            });

            frame.open();
        });

        // remove one
        $root.on('click', '[data-role="monGalleryRemove"]', function (e) {
            e.preventDefault();
            $(this).closest('.mon-gallery-item').remove();
            syncIds($root);
        });

        // clear all
        $root.on('click', '[data-role="monGalleryClear"]', function (e) {
            e.preventDefault();
            if (!confirm('مسح كل الصور من الألبوم؟')) return;
            $grid.empty();
            syncIds($root);
        });
    }

    $(document).ready(function () {
        $('[data-role="monGalleryAdmin"]').each(function () {
            bind($(this));
        });
    });
})(jQuery);
