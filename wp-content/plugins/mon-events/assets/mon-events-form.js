(function ($) {
    function parseIds(raw) {
        raw = (raw || '').trim();
        if (!raw) return [];
        const parts = raw.split(/[,\s]+/).map(x => parseInt(x, 10)).filter(n => n > 0);
        return [...new Set(parts)];
    }

    function renderPreview(ids) {
        const box = document.getElementById('monGalleryPreview');
        if (!box) return;
        box.innerHTML = '';
        ids.forEach(id => {
            const el = document.createElement('div');
            el.className = 'mon-thumb';
            el.textContent = '#' + id;
            box.appendChild(el);
        });
    }

    $(document).ready(function () {
        const pickBtn = document.getElementById('monPickGallery');
        const clearBtn = document.getElementById('monClearGallery');
        const idsInput = document.getElementById('monGalleryIds');

        if (!idsInput) return;

        renderPreview(parseIds(idsInput.value));

        idsInput.addEventListener('input', function () {
            renderPreview(parseIds(idsInput.value));
        });

        if (pickBtn) {
            pickBtn.addEventListener('click', function () {
                if (typeof wp === 'undefined' || !wp.media) {
                    alert('Media uploader غير متاح.');
                    return;
                }

                const frame = wp.media({
                    title: 'اختيار صور الألبوم',
                    button: { text: 'اعتماد الصور' },
                    multiple: true
                });

                frame.on('select', function () {
                    const selection = frame.state().get('selection');
                    const picked = [];
                    selection.each(function (att) {
                        picked.push(att.get('id'));
                    });

                    const merged = [...new Set([...parseIds(idsInput.value), ...picked])];
                    idsInput.value = merged.join(',');
                    renderPreview(merged);
                });

                frame.open();
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                idsInput.value = '';
                renderPreview([]);
            });
        }
    });
})(jQuery);
