/**
 * SiteGlow — Archive Page JS Lazy Loader
 *
 * Lazy-loads per-post JavaScript files on archive / blog-list pages using the
 * IntersectionObserver API.
 *
 * ── Why lazy-load JS on archives? ──────────────────────────────────────────
 * Archive pages display many post cards simultaneously.  Loading all of their
 * JS files upfront would block the main thread and delay Time to Interactive,
 * especially when most cards are below the fold and never seen by the user.
 * CSS, however, must be in `<head>` before first paint (handled in PHP via
 * `wp_enqueue_style`) — only JS benefits from deferral here.
 *
 * ── How it works ───────────────────────────────────────────────────────────
 * PHP passes `window.siteglowArchiveAssets` — an object mapping WordPress post IDs
 * (as string keys) to versioned JS file URLs:
 *   { "42": "https://example.com/.../pages/js/my-post.js?v=1234567890" }
 *
 * We observe all elements whose `id` attribute matches `post-{ID}` (the
 * convention WordPress uses in `post_class()` on article wrappers).  When a
 * matching element enters the viewport (plus a 200px pre-load margin), we
 * inject a `<script async>` tag for its URL and stop observing that element.
 *
 * ── Fallback ───────────────────────────────────────────────────────────────
 * Browsers that do not support IntersectionObserver (IE 11, older Safari)
 * fall back to injecting all scripts immediately on page load.
 *
 * @file    archive-lazy.js
 * @package SiteGlow
 * @since   1.0.0
 */
(function () {
    if ( ! window.siteglowArchiveAssets ) return;

    var pending = Object.assign( {}, siteglowArchiveAssets );
    if ( Object.keys( pending ).length === 0 ) return;

    /**
     * Appends an async `<script>` tag to `document.body`.
     *
     * @param {string} url  Absolute URL to the JS file (may include ?v= version).
     */
    function injectScript( url ) {
        var s    = document.createElement( 'script' );
        s.src    = url;
        s.async  = true;
        document.body.appendChild( s );
    }

    /* Immediate fallback for browsers without IntersectionObserver */
    if ( ! ( 'IntersectionObserver' in window ) ) {
        Object.keys( pending ).forEach( function ( id ) { injectScript( pending[ id ] ); } );
        return;
    }

    var observer = new IntersectionObserver( function ( entries ) {
        entries.forEach( function ( entry ) {
            if ( ! entry.isIntersecting ) return;

            var el    = entry.target;
            var match = el.id && el.id.match( /^post-(\d+)$/ );
            observer.unobserve( el );

            if ( ! match ) return;

            var postId = match[ 1 ];
            if ( pending[ postId ] ) {
                injectScript( pending[ postId ] );
                delete pending[ postId ];
            }
        } );
    }, {
        /* Start loading 200 px before the element enters the viewport */
        rootMargin: '0px 0px 200px 0px'
    } );

    /* WordPress outputs id="post-{ID}" on article wrappers via post_class() */
    document.querySelectorAll( '[id^="post-"]' ).forEach( function ( el ) {
        if ( /^post-\d+$/.test( el.id ) ) observer.observe( el );
    } );
} )();
