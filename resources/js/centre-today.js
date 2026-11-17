/*
 * "Today" is the centre's today, not the computer's (QA retest, 18 Nov 2026).
 *
 * The header said Tuesday, Nov 17 — New York time — while the Holidays
 * calendar marked the 18th, because the director's laptop was in Manila and
 * every picker asked the browser. The layout writes the centre's timezone in
 * <meta name="centre-timezone"> and the server's own today beside it in
 * <meta name="centre-today">; everything that needs a today reads it here.
 *
 * A classic script, inlined in the page head by layouts/app.blade.php, so the
 * page's own inline scripts can use it before the bundle has loaded.
 *
 *   window.centreToday()      "2026-11-17"
 *   window.centreTodayDate()  that day at local midnight, as calendars want it
 */
(function (root) {
    function meta(name) {
        var tag = root.document && root.document.querySelector('meta[name="' + name + '"]');
        return tag ? tag.getAttribute('content') : null;
    }

    function localIso(at) {
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return at.getFullYear() + '-' + pad(at.getMonth() + 1) + '-' + pad(at.getDate());
    }

    // Worked out from the clock each time, so a page left open past midnight
    // moves on with the centre; the server's date only when the browser
    // cannot read the timezone.
    root.centreToday = function (now) {
        var at = now || new Date();
        var zone = meta('centre-timezone');

        if (zone) {
            try {
                var parts = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(at);
                var get = function (type) { return parts.filter(function (p) { return p.type === type; })[0].value; };
                return get('year') + '-' + get('month') + '-' + get('day');
            } catch (e) { /* fall through */ }
        }

        return meta('centre-today') || localIso(at);
    };

    root.centreTodayDate = function (now) {
        var bits = root.centreToday(now).split('-').map(Number);
        return new Date(bits[0], bits[1] - 1, bits[2]);
    };
})(window);
