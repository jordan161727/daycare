<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') &middot; {{ config('app.name') }}</title>
    @includeIf('layouts.favicon')
    <style>
        {{--
            Plain CSS rather than the app's Tailwind build, and deliberately.

            Everything else in this app is a screen a staff member is logged in
            to. These two pages are the opposite: a parent finds them from the
            centre's website, on a phone, often once. They carry none of the app
            shell — no sidebar, no nav, nothing to sign in to — so pulling in the
            whole admin stylesheet would ship a few hundred kilobytes to render a
            page of text. What is here is what these pages use.
        --}}
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #12294b; font: 17px/1.65 system-ui, sans-serif; }
        header { border-top: 7px solid #12294b; border-bottom: 1px solid #e4eaf0; padding: 18px max(24px, calc((100% - 1080px)/2)); }
        .brand { display: inline-block; line-height: 0; }
        .brand img { height: 56px; width: auto; }
        main { max-width: 1128px; margin: auto; padding: 48px 24px; }
        .eyebrow { color: #087b88; font-size: 13px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { font-size: clamp(32px, 5vw, 48px); line-height: 1.15; letter-spacing: -.03em; margin: 12px 0 18px; }
        h2 { font-size: 26px; line-height: 1.3; margin: 0 0 14px; }
        h3 { font-size: 19px; margin: 0 0 8px; }
        p { margin: 0 0 18px; }
        .intro { max-width: 760px; color: #46576a; }
        .badge { display: inline-block; background: #d8eee8; color: #175c50; padding: 4px 12px; border-radius: 30px; font-size: 13px; font-weight: 750; margin-bottom: 16px; }
        address { font-style: normal; margin: 12px 0; }
        a { color: #175c99; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid #e7a322; outline-offset: 5px; }
        .button { display: inline-block; padding: 12px 20px; border-radius: 8px; background: #12294b; color: white; font-weight: 700; text-decoration: none; }
        .button:hover { background: #214a7d; }
        .small { font-size: 14px; color: #4c6072; }
        ul { padding-left: 22px; }
        li { margin: 12px 0; }
        .note { margin-top: 36px; border-top: 1px solid #dce5ec; padding-top: 22px; }
        html { scroll-behavior: smooth; }
        h2 { scroll-margin-top: 24px; }
        @media (max-width: 720px) { main { padding-top: 32px; } .brand img { height: 44px; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
        @stack('styles')
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ config('app.public_url', 'https://littleangelsdc.com/') }}">
            {{-- The same mark as the sidebar and the printed sheets, at its real
                 2.33:1 so the header does not reflow when it loads. --}}
            <img src="{{ asset('images/littleangel.jpeg') }}" alt="{{ config('app.name') }}" width="531" height="228">
        </a>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
