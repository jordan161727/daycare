{{--
    The maintenance page: what a parent or a teacher sees while the site is
    down for a deploy (php artisan down). Laravel renders this once when the
    site goes down and serves the result from disk, so nothing here may need
    the database at request time — which is why the name falls back to config
    and the contact line is left off entirely when nothing is set.

    Drawn for the people who land on it: a toddler's toy blocks spelling NAP,
    a moon, and a plain sentence. It is on purpose nothing like the admin
    screens, because the person reading it is most likely a parent on a phone
    at the door, not a director at a desk.
--}}
@php
    $name = rescue(fn () => \App\Models\Setting::get('company.name', config('daycare.company.name')), config('daycare.company.name'), false);
    $phone = config('daycare.company.phone');
    $email = config('daycare.company.email');
    $dial = $phone ? preg_replace('/[^0-9+]/', '', $phone) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta http-equiv="refresh" content="60">
<title>We'll be right back | {{ $name }}</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icon-angels-light-32.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Grandstander:wght@700;800&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --sky: #DDF1FB;
    --ink: #24324A;
    --ink-soft: #4E5D78;
    --sun: #FFC23D;
    --apple: #F2594B;
    --pool: #3A8DDE;
    --grass: #6CC24A;
    --paper: #FFFFFF;
    box-sizing: border-box;
    padding-top: env(safe-area-inset-top, 0px);
    padding-bottom: env(safe-area-inset-bottom, 0px);
  }
  *, *::before, *::after { box-sizing: inherit; margin: 0; }
  html, body { height: 100%; }
  body {
    background: var(--sky);
    color: var(--ink);
    font-family: "Nunito", "Segoe UI", system-ui, -apple-system, sans-serif;
    font-size: 1.0625rem;
    line-height: 1.6;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
  }
  header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .75rem;
    padding: 1.5rem 1.25rem 0;
  }
  header img { height: 60px; width: auto; }
  main {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem 1.25rem 7rem;
    position: relative;
    z-index: 1;
  }
  .blocks { width: min(320px, 80vw); height: auto; margin-bottom: 1.5rem; }
  .zzz text { font-family: "Grandstander", "Comic Sans MS", cursive; font-weight: 800; fill: var(--pool); }
  .z { animation: drift 3.6s ease-in-out infinite; opacity: 0; }
  .z2 { animation-delay: 1.2s; }
  .z3 { animation-delay: 2.4s; }
  @keyframes drift {
    0%   { opacity: 0; transform: translate(0, 8px); }
    30%  { opacity: 1; }
    100% { opacity: 0; transform: translate(14px, -22px); }
  }
  h1 {
    font-family: "Grandstander", "Comic Sans MS", "Trebuchet MS", cursive;
    font-weight: 800;
    font-size: clamp(2rem, 6vw, 3.25rem);
    line-height: 1.1;
    letter-spacing: -0.01em;
    max-width: 14ch;
  }
  .scribble { display: block; width: min(260px, 60vw); height: 14px; margin: 0.6rem auto 1.25rem; }
  .lead { max-width: 34rem; color: var(--ink-soft); font-size: 1.15rem; }
  .note {
    margin-top: 1.75rem;
    background: var(--paper);
    border: 3px dashed var(--sun);
    border-radius: 18px;
    padding: 1rem 1.25rem;
    max-width: 30rem;
    font-size: 1rem;
  }
  .note strong { color: var(--ink); }
  .note a { color: var(--pool); font-weight: 700; }
  .retry {
    margin-top: 1.75rem;
    font: inherit;
    font-weight: 700;
    font-size: 1.05rem;
    color: #fff;
    background: var(--apple);
    border: 0;
    border-radius: 999px;
    padding: 0.8rem 1.8rem;
    min-height: 44px;
    box-shadow: 0 5px 0 #C23E32;
    cursor: pointer;
    transition: transform .1s, box-shadow .1s;
  }
  .retry:active { transform: translateY(4px); box-shadow: 0 1px 0 #C23E32; }
  .retry:focus-visible, .note a:focus-visible { outline: 3px solid var(--pool); outline-offset: 3px; }
  .hills { position: fixed; left: 0; right: 0; bottom: 0; width: 100%; height: 110px; z-index: 0; }
  @media (prefers-reduced-motion: reduce) {
    .z { animation: none; opacity: 1; }
  }
</style>
</head>
<body>
<header>
  <img src="{{ asset('images/littleangels-logo.png') }}" alt="{{ $name }}">
</header>

<main>
  <svg class="blocks" viewBox="0 0 320 200" role="img" aria-label="Three toy blocks spelling NAP, with sleepy Z's floating above">
    <path d="M262 30a22 22 0 1 0 22 30 18 18 0 1 1-22-30z" fill="#FFC23D"/>
    <g class="zzz">
      <text class="z z1" x="214" y="70" font-size="20">z</text>
      <text class="z z2" x="230" y="52" font-size="26">z</text>
      <text class="z z3" x="250" y="34" font-size="32">Z</text>
    </g>
    <g transform="rotate(-6 70 140)">
      <rect x="22" y="96" width="88" height="88" rx="12" fill="#C99A2E"/>
      <rect x="22" y="90" width="88" height="88" rx="12" fill="#FFC23D"/>
      <rect x="32" y="100" width="68" height="68" rx="8" fill="none" stroke="#fff" stroke-width="4" opacity=".7"/>
      <text x="66" y="153" text-anchor="middle" font-family="Grandstander, Comic Sans MS, cursive" font-weight="800" font-size="50" fill="#24324A">N</text>
    </g>
    <g transform="rotate(3 160 140)">
      <rect x="116" y="96" width="88" height="88" rx="12" fill="#C23E32"/>
      <rect x="116" y="90" width="88" height="88" rx="12" fill="#F2594B"/>
      <rect x="126" y="100" width="68" height="68" rx="8" fill="none" stroke="#fff" stroke-width="4" opacity=".7"/>
      <text x="160" y="153" text-anchor="middle" font-family="Grandstander, Comic Sans MS, cursive" font-weight="800" font-size="50" fill="#fff">A</text>
    </g>
    <g transform="rotate(-2 254 140)">
      <rect x="210" y="96" width="88" height="88" rx="12" fill="#2A6BAD"/>
      <rect x="210" y="90" width="88" height="88" rx="12" fill="#3A8DDE"/>
      <rect x="220" y="100" width="68" height="68" rx="8" fill="none" stroke="#fff" stroke-width="4" opacity=".7"/>
      <text x="254" y="153" text-anchor="middle" font-family="Grandstander, Comic Sans MS, cursive" font-weight="800" font-size="50" fill="#fff">P</text>
    </g>
  </svg>

  <h1>Our site is taking a little nap</h1>
  <svg class="scribble" viewBox="0 0 260 14" aria-hidden="true">
    <path d="M4 9c30-8 52 6 84-1s54-6 84 0 52 2 84-4" fill="none" stroke="#FFC23D" stroke-width="6" stroke-linecap="round"/>
  </svg>

  <p class="lead">We're tidying up the playroom and making a few improvements. The site will be back shortly, so please check again in a few minutes.</p>

  @if($phone || $email)
    <div class="note">
      <strong>Parents:</strong> need to reach us about your child today?
      @if($phone && $email)
        Call <a href="tel:{{ $dial }}">{{ $phone }}</a> or email <a href="mailto:{{ $email }}">{{ $email }}</a>.
      @elseif($phone)
        Call <a href="tel:{{ $dial }}">{{ $phone }}</a>.
      @else
        Email <a href="mailto:{{ $email }}">{{ $email }}</a>.
      @endif
    </div>
  @else
    <div class="note">
      <strong>Teachers:</strong> the door kiosk and the time clock are paused too. Sign children in on paper for now and the register can be caught up once we are back.
    </div>
  @endif

  <button class="retry" type="button" onclick="location.reload()">Try again</button>
</main>

<svg class="hills" viewBox="0 0 1440 110" preserveAspectRatio="none" aria-hidden="true">
  <path d="M0 60c160-50 320-50 480 0s320 50 480 0 320-50 480 0v50H0z" fill="#8ED46B"/>
  <path d="M0 80c200-40 400-40 600 0s400 40 600 0 240-20 240-20v50H0z" fill="#6CC24A"/>
</svg>
</body>
</html>
