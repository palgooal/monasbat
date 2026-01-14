(function () {
    function q(sel, root = document) { return root.querySelector(sel); }
    function qa(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

    function buildLightbox() {
        const el = document.createElement("div");
        el.className = "mon-lightbox";
        el.innerHTML = `
      <div class="mon-lightbox__backdrop" data-act="close"></div>
      <div class="mon-lightbox__panel" role="dialog" aria-modal="true">
        <button class="mon-lightbox__close" type="button" data-act="close" aria-label="إغلاق">×</button>
        <button class="mon-lightbox__nav mon-lightbox__prev" type="button" data-act="prev" aria-label="السابق">‹</button>
        <button class="mon-lightbox__nav mon-lightbox__next" type="button" data-act="next" aria-label="التالي">›</button>
        <img class="mon-lightbox__img" alt="">
        <div class="mon-lightbox__meta">
          <span class="mon-lightbox__count" data-role="count"></span>
        </div>
      </div>
    `;
        document.body.appendChild(el);
        return el;
    }

    document.addEventListener("click", (e) => {
        const trigger = e.target.closest("[data-mon-lightbox='item']");
        if (!trigger) return;

        const gallery = trigger.closest("[data-mon-lightbox='gallery']");
        const items = qa("[data-mon-lightbox='item']", gallery);

        const sources = items.map((it) => ({
            full: it.getAttribute("data-full") || "",
            alt: it.getAttribute("data-alt") || "",
        }));

        let idx = items.indexOf(trigger);
        if (idx < 0) idx = 0;

        let lb = q(".mon-lightbox");
        if (!lb) lb = buildLightbox();

        const img = q(".mon-lightbox__img", lb);
        const count = q("[data-role='count']", lb);

        function render() {
            const s = sources[idx];
            img.src = s.full;
            img.alt = s.alt || "";
            count.textContent = `${idx + 1} / ${sources.length}`;
            lb.classList.add("is-open");
            document.documentElement.classList.add("mon-lock");
        }

        function close() {
            lb.classList.remove("is-open");
            document.documentElement.classList.remove("mon-lock");
            img.src = "";
        }

        function next() { idx = (idx + 1) % sources.length; render(); }
        function prev() { idx = (idx - 1 + sources.length) % sources.length; render(); }

        lb.__monClose = close;
        lb.__monNext = next;
        lb.__monPrev = prev;

        lb.addEventListener("click", (ev) => {
            const act = ev.target?.getAttribute?.("data-act");
            if (!act) return;
            if (act === "close") close();
            if (act === "next") next();
            if (act === "prev") prev();
        }, { once: false });

        document.addEventListener("keydown", function onKey(ev) {
            if (!lb.classList.contains("is-open")) return;
            if (ev.key === "Escape") close();
            if (ev.key === "ArrowRight") next();
            if (ev.key === "ArrowLeft") prev();
        });

        render();
    });
})();
