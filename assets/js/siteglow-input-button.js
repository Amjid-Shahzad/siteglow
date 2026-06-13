/**
 * SiteGlow — Block Editor Floating Button
 *
 * Adds a draggable floating "Live Edit" button to the WordPress block editor
 * (Gutenberg) that lets administrators edit the current page's CSS and JS
 * live, with instant preview inside the block editor's iframe canvas.
 *
 * ── Architecture ───────────────────────────────────────────────────────────
 * The script is a single IIFE (Immediately Invoked Function Expression) that
 * renders a React component (`LiveEditor`) via `wp.element.render()`.  All
 * helper functions are defined in the shared IIFE scope so they can be called
 * from both the React component lifecycle and the `wp.data.subscribe` post-save
 * hook without cross-scope reference errors.
 *
 * ── CSS/JS persistence ─────────────────────────────────────────────────────
 * Edits are stored in `localStorage` under `siteglow_iframe_css_{postId}` and
 * `siteglow_iframe_js_{postId}` as the user types (real-time), then exported to
 * the server (via the `siteglow_export_live_editor_files` AJAX action) when:
 *   1. The admin clicks "Save CSS" or "Save JS".
 *   2. WordPress finishes saving the post (detected via `wp.data.subscribe`).
 * On each block-editor iframe reload, `watchIframeAndRestoreCSS()` re-injects
 * the stored CSS so the preview always reflects the latest edits.
 *
 * ── Security ───────────────────────────────────────────────────────────────
 * `window.siteglowData` (nonce + ajaxUrl) is only injected by PHP for users with
 * `manage_options` capability.  The guard at the top of the IIFE ensures the
 * script does nothing for non-admin visitors even if it were somehow loaded.
 *
 * ── Dependencies ───────────────────────────────────────────────────────────
 * wp-blocks, wp-element, wp-components, wp-editor (declared in PHP enqueue),
 * wp-code-editor (CodeMirror bundle for syntax highlighting).
 *
 * @file    siteglow-input-button.js
 * @package SiteGlow
 * @since   1.0.0
 */
(function () {
  // Requires admin context — siteglowData is only injected for manage_options users.
  if (!window.wp || !wp.element || !wp.codeEditor || !window.siteglowData) return;

  const { useEffect, createElement } = wp.element;
  const { render } = wp.element;

  // ── Storage keys ──────────────────────────────────────────────────────────
  function getStorageKeys() {
    const postId = wp.data.select("core/editor")?.getCurrentPostId?.();
    if (!postId) return { css: null, js: null };
    return {
      css: `siteglow_iframe_css_${postId}`,
      js:  `siteglow_iframe_js_${postId}`,
    };
  }

  // ── Iframe helpers ────────────────────────────────────────────────────────
  const getIframeEl  = () => document.querySelector('iframe[name="editor-canvas"]');
  const getIframeDoc = () => getIframeEl()?.contentDocument || null;
  const getHead      = () => getIframeDoc()?.head || null;
  const getBody      = () => getIframeDoc()?.body || null;

  function ensureCSSTag() {
    const head = getHead();
    if (!head) return null;
    let style = head.querySelector("#siteglow-editor-css");
    if (!style) {
      style = head.ownerDocument.createElement("style");
      style.id = "siteglow-editor-css";
      head.appendChild(style);
    }
    return style;
  }

  function applyCSS(css) {
    const tag = ensureCSSTag();
    if (tag) tag.textContent = css || "";
  }

  function runJS(js) {
    const body = getBody();
    if (!body || !js) return;
    let script = body.querySelector("#siteglow-editor-js");
    if (script) script.remove();
    script = body.ownerDocument.createElement("script");
    script.id = "siteglow-editor-js";
    script.textContent = `try{(new Function(${JSON.stringify(js)}))();}catch(e){console.error("Live JS Error:",e);}`;
    body.appendChild(script);
  }

  // ── Restore CSS every time the editor iframe (re)loads ───────────────────
  // The block editor iframe reloads whenever WordPress re-renders the preview.
  // We must re-inject the <style> tag on each load event, not just once.
  function watchIframeAndRestoreCSS() {
    function restore() {
      const keys = getStorageKeys();
      if (!keys.css) return;
      const css = localStorage.getItem(keys.css) || "";
      if (css) applyCSS(css);
    }

    function attachLoad(iframe) {
      // Re-apply CSS every time the iframe reloads (new document = styles reset)
      iframe.addEventListener("load", function () {
        // Small delay so the new document's <head> is fully ready
        setTimeout(restore, 50);
      });
      // Apply immediately if the iframe is already showing content
      restore();
    }

    const existing = getIframeEl();
    if (existing) {
      attachLoad(existing);
      return;
    }

    // Poll until the iframe element appears in the DOM (it is lazy-inserted)
    const poll = setInterval(function () {
      const iframe = getIframeEl();
      if (iframe) {
        clearInterval(poll);
        attachLoad(iframe);
      }
    }, 200);
  }

  // ── AJAX export ───────────────────────────────────────────────────────────
  // Defined here (shared scope) so both save buttons and the post-save
  // subscriber can call it without cross-IIFE scope issues.
  function exportLiveCode() {
    const postId = wp.data.select("core/editor")?.getCurrentPostId?.();
    if (!postId) return;

    const keys = getStorageKeys();
    const css  = localStorage.getItem(keys.css) || "";
    const js   = localStorage.getItem(keys.js)  || "";

    fetch(siteglowData.ajaxUrl, {
      method:  "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action:  "siteglow_export_live_editor_files",
        nonce:   siteglowData.nonce,
        post_id: postId,
        css,
        js,
      }),
    });
  }

  // ── Toast notification (replaces alert so no focus/click side-effects) ────
  function showToast(msg) {
    const t = document.createElement("div");
    t.textContent = msg;
    Object.assign(t.style, {
      position:     "fixed",
      bottom:       "28px",
      right:        "28px",
      background:   "#1d2327",
      color:        "#fff",
      padding:      "10px 18px",
      borderRadius: "4px",
      fontSize:     "0.9rem",
      zIndex:       999999,
      opacity:      "1",
      transition:   "opacity .4s ease",
      pointerEvents:"none",
    });
    document.body.appendChild(t);
    setTimeout(function () {
      t.style.opacity = "0";
      setTimeout(function () { t.remove(); }, 420);
    }, 2000);
  }

  // ── Main floating editor component ────────────────────────────────────────
  const LiveEditor = () => {
    useEffect(() => {
      if (document.getElementById("siteglow-input-btn")) return;

      // Start watching the iframe so CSS survives every preview reload
      watchIframeAndRestoreCSS();

      // Restore JS preview once the post ID is available
      const jsRestoreInterval = setInterval(function () {
        const keys = getStorageKeys();
        if (!keys.js) return;
        clearInterval(jsRestoreInterval);
        const savedJS = localStorage.getItem(keys.js) || "";
        if (savedJS) runJS(savedJS);
      }, 200);

      // ── Floating button ──────────────────────────────────────────────────
      const btn = document.createElement("button");
      btn.id = "siteglow-input-btn";
      btn.textContent = "Live Edit";
      Object.assign(btn.style, {
        position:     "fixed",
        top:          "70%",
        left:         "70%",
        width:        "70px",
        height:       "70px",
        borderRadius: "50%",
        background:   "#005007",
        color:        "#fff",
        border:       "none",
        cursor:       "grab",
        zIndex:       9999,
        fontSize:     "0.8rem",
        lineHeight:   "1.2",
      });
      document.body.appendChild(btn);

      const actions = [
        { label: "CSS Edit", mode: "css",        color: "#aa0077" },
        { label: "JS Edit",  mode: "javascript", color: "#b8a000" },
      ];

      const subButtons = [];
      const editors    = [];
      const inputs     = [];

      let isOpen   = false;
      let dragging = false, moved = false, ox = 0, oy = 0;

      const WIDTH  = 360;
      const HEIGHT = 260;

      actions.forEach(function (a) {
        const b = document.createElement("button");
        b.textContent = a.label;
        Object.assign(b.style, {
          position:     "fixed",
          width:        "60px",
          height:       "60px",
          borderRadius: "50%",
          background:   a.color,
          color:        "#fff",
          border:       "none",
          transform:    "scale(0)",
          transition:   "transform .2s",
          zIndex:       9998,
          fontSize:     "0.8rem",
        });
        document.body.appendChild(b);
        subButtons.push(b);

        const ta = document.createElement("textarea");
        ta.style.display = "none";
        document.body.appendChild(ta);
        inputs.push(ta);
        editors.push(null);
      });

      // ── Drag ─────────────────────────────────────────────────────────────
      btn.onmousedown = function (e) {
        dragging = true; moved = false;
        const r = btn.getBoundingClientRect();
        ox = e.clientX - r.left;
        oy = e.clientY - r.top;
      };
      document.onmousemove = function (e) {
        if (!dragging) return;
        moved = true;
        const x = e.clientX - ox;
        const y = e.clientY - oy;
        btn.style.left = x + "px";
        btn.style.top  = y + "px";
        if (isOpen) positionSubs(x, y);
        moveEditors(x, y);
      };
      document.onmouseup = function () { dragging = false; };

      function positionSubs(x, y) {
        subButtons.forEach(function (b, i) {
          const a = (i * 55 - 20) * (Math.PI / 180);
          b.style.left = x + Math.cos(a) * 77 + "px";
          b.style.top  = y + Math.sin(a) * 75 + "px";
        });
      }

      function moveEditors(x, y) {
        editors.forEach(function (ed) {
          if (ed) {
            ed.wrap.style.left = x - WIDTH + 30 + "px";
            ed.wrap.style.top  = y - HEIGHT - 40 + "px";
          }
        });
      }

      function closeEditors() {
        editors.forEach(function (ed) { if (ed) ed.wrap.style.display = "none"; });
      }

      // ── Main button toggle ────────────────────────────────────────────────
      btn.onclick = function () {
        if (moved) return;
        isOpen = !isOpen;
        subButtons.forEach(function (b) {
          b.style.transform = isOpen ? "scale(1)" : "scale(0)";
        });
        if (isOpen) positionSubs(btn.offsetLeft, btn.offsetTop);
        else closeEditors();
      };

      // ── Sub-button: open editor panel ─────────────────────────────────────
      subButtons.forEach(function (b, i) {
        b.onclick = function (e) {
          e.stopPropagation();

          const STORAGE = getStorageKeys();
          if (!STORAGE.css || !STORAGE.js) return;

          if (!editors[i]) {
            const cm = wp.codeEditor.initialize(inputs[i], {
              codemirror: { mode: actions[i].mode, lineNumbers: true },
            }).codemirror;

            cm.setValue(
              actions[i].mode === "css"
                ? localStorage.getItem(STORAGE.css) || ""
                : localStorage.getItem(STORAGE.js)  || ""
            );

            // Editor panel wrapper
            const wrap = document.createElement("div");
            Object.assign(wrap.style, {
              position:     "fixed",
              width:        WIDTH + "px",
              height:       HEIGHT + 64 + "px",
              background:   "#1e1e1e",
              borderRadius: "8px",
              zIndex:       9997,
              display:      "none",
              overflow:     "hidden",
            });

            // ── CRITICAL FIX: stop ALL clicks inside the panel from bubbling ──
            // Without this, any button click inside the panel would reach the
            // document click handler and trigger closeEditors().
            wrap.addEventListener("click",     function (e) { e.stopPropagation(); });
            wrap.addEventListener("mousedown", function (e) { e.stopPropagation(); });

            const title = document.createElement("div");
            title.textContent = actions[i].label + " Editor";
            Object.assign(title.style, {
              height:     "32px",
              lineHeight: "32px",
              padding:    "0 10px",
              background: "#111",
              color:      "#fff",
              fontSize:   "12px",
              userSelect: "none",
            });

            cm.getWrapperElement().style.height = HEIGHT + "px";
            wrap.append(title, cm.getWrapperElement());

            // Action bar
            const bar = document.createElement("div");
            Object.assign(bar.style, {
              display:      "flex",
              gap:          "10px",
              alignItems:   "center",
              padding:      "6px 12px",
              background:   "#151515",
              borderTop:    "1px solid #333",
            });

            if (actions[i].mode === "javascript") {
              const runBtn = document.createElement("button");
              runBtn.textContent = "Run JS";
              runBtn.onclick = function () { runJS(cm.getValue()); };

              const saveBtn = document.createElement("button");
              saveBtn.textContent = "Save JS";
              saveBtn.onclick = function () {
                localStorage.setItem(STORAGE.js, cm.getValue());
                exportLiveCode();
                showToast("JS saved!");
              };

              bar.append(runBtn, saveBtn);

            } else {
              const saveBtn = document.createElement("button");
              saveBtn.textContent = "Save CSS";
              saveBtn.onclick = function () {
                const value = cm.getValue();
                localStorage.setItem(STORAGE.css, value);
                applyCSS(value);
                exportLiveCode();
                showToast("CSS saved!");
              };

              bar.append(saveBtn);
            }

            wrap.appendChild(bar);
            document.body.appendChild(wrap);
            editors[i] = { cm, wrap };

            cm.on("change", function (c) {
              const v = c.getValue();
              if (actions[i].mode === "css") {
                localStorage.setItem(STORAGE.css, v);
                applyCSS(v);
              } else {
                localStorage.setItem(STORAGE.js, v);
              }
            });
          }

          closeEditors();
          editors[i].wrap.style.display = "block";
          moveEditors(btn.offsetLeft, btn.offsetTop);
          editors[i].cm.refresh();
          editors[i].cm.focus();
        };
      });

      // ── Outside-click: close panel only when clicking truly outside ───────
      document.addEventListener("click", function (e) {
        // Ignore clicks inside any editor wrap panel
        const insidePanel = editors.some(function (ed) {
          return ed && ed.wrap.contains(e.target);
        });
        if (insidePanel) return;
        if (e.target === btn || subButtons.includes(e.target)) return;

        isOpen = false;
        subButtons.forEach(function (b) { b.style.transform = "scale(0)"; });
        closeEditors();
      });

    }, []);

    return null;
  };

  // Fix CodeMirror autocomplete z-index inside the block editor
  const fix = document.createElement("style");
  fix.textContent = `.CodeMirror-hints{z-index:100000!important;position:fixed}`;
  document.head.appendChild(fix);

  wp.domReady(function () {
    const mount = document.createElement("div");
    document.body.appendChild(mount);
    render(createElement(LiveEditor), mount);
  });

  // ── Auto-export on WordPress post save ────────────────────────────────────
  // Runs inside the same IIFE so exportLiveCode() is in scope.
  if (wp?.data) {
    let wasSaving = false;
    wp.data.subscribe(function () {
      const isSaving    = wp.data.select("core/editor").isSavingPost();
      const isAutosaving = wp.data.select("core/editor").isAutosavingPost();
      if (wasSaving && !isSaving && !isAutosaving) {
        exportLiveCode();
      }
      wasSaving = isSaving;
    });
  }

})();
