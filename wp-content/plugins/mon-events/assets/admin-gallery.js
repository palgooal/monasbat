jQuery(function ($) {
    const $ids = $("#mon_gallery_ids");
    const $preview = $("#mon_gallery_preview");

    function getIds() {
        const v = ($ids.val() || "").trim();
        if (!v) return [];
        return v.split(",").map((x) => parseInt(x, 10)).filter(Boolean);
    }

    function setIds(arr) {
        $ids.val(arr.join(","));
    }

    function renderPreview(attachments) {
        $preview.empty();
        attachments.forEach((att) => {
            const src = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : att.url;
            const html = `
        <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
          <img src="${src}" style="width:100%;height:110px;object-fit:cover;display:block" />
        </div>`;
            $preview.append(html);
        });
    }

    $("#mon_gallery_add").on("click", function () {
        const frame = wp.media({
            title: "اختر صور الألبوم",
            button: { text: "إضافة" },
            multiple: true,
            library: { type: "image" },
        });

        frame.on("select", function () {
            const selection = frame.state().get("selection").toJSON();
            const newIds = selection.map((a) => a.id);

            const merged = Array.from(new Set(getIds().concat(newIds)));
            setIds(merged);

            renderPreview(selection);
        });

        frame.open();
    });

    $("#mon_gallery_clear").on("click", function () {
        setIds([]);
        $preview.empty();
    });
});
