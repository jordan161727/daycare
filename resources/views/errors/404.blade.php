{{--
    The page for an address that goes nowhere: a mistyped link, a bookmark to
    a child who has since left, a report row from last year. Drawn like the
    maintenance page and for the same reader — most likely a parent on a
    phone — with two ways out: home, or back to wherever they came from.
    Home is the root, which sends a signed-in person to their dashboard and
    anyone else to the login screen.
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
<title>Page not found | {{ $name }}</title>
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
    --apple-dark: #C23E32;
    --pool: #3A8DDE;
    --paper: #FFFFFF;
    --display: "Grandstander", "Comic Sans MS", "Trebuchet MS", cursive;
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
    justify-content: center;
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
    padding: 2rem 1.25rem 7.5rem;
    position: relative;
    z-index: 1;
  }
  .art { width: min(320px, 80vw); height: auto; margin-bottom: 1.5rem; overflow: visible; }
  .art .letter { font-family: var(--display); font-weight: 800; font-size: 50px; text-anchor: middle; }
  .code { font-family: var(--display); font-weight: 700; color: var(--pool); font-size: 1rem; margin-bottom: .35rem; }
  h1 {
    font-family: var(--display);
    font-weight: 800;
    font-size: clamp(2rem, 6vw, 3.25rem);
    line-height: 1.1;
    letter-spacing: -0.01em;
    max-width: 15ch;
  }
  .scribble { display: block; width: min(260px, 60vw); height: 14px; margin: 0.6rem auto 1.25rem; }
  .lead { max-width: 34rem; color: var(--ink-soft); font-size: 1.15rem; }
  .actions { margin-top: 1.75rem; display: flex; flex-wrap: wrap; gap: .9rem; justify-content: center; }
  .btn {
    font: inherit;
    font-weight: 700;
    font-size: 1.05rem;
    text-decoration: none;
    border-radius: 999px;
    padding: 0.8rem 1.8rem;
    min-height: 44px;
    cursor: pointer;
    border: 0;
    transition: transform .1s, box-shadow .1s;
  }
  .btn-primary { color: #fff; background: var(--apple); box-shadow: 0 5px 0 var(--apple-dark); }
  .btn-primary:active { transform: translateY(4px); box-shadow: 0 1px 0 var(--apple-dark); }
  .btn-secondary { color: var(--ink); background: var(--paper); box-shadow: 0 5px 0 #B9D3E2; }
  .btn-secondary:active { transform: translateY(4px); box-shadow: 0 1px 0 #B9D3E2; }
  .note {
    margin-top: 2rem;
    background: var(--paper);
    border: 3px dashed var(--sun);
    border-radius: 18px;
    padding: 1rem 1.25rem;
    max-width: 30rem;
    font-size: 1rem;
  }
  .note a { color: var(--pool); font-weight: 700; }
  .btn:focus-visible, .note a:focus-visible { outline: 3px solid var(--pool); outline-offset: 3px; }
  .hills { position: fixed; left: 0; right: 0; bottom: 0; width: 100%; height: 110px; z-index: 0; }
  @media (prefers-reduced-motion: reduce) {
    .btn { transition: none; }
  }
</style>
</head>
<body>
<header>
  <img src="{{ asset('images/littleangels-logo.png') }}" alt="{{ $name }}">
</header>

<main>
  <svg class="art" viewBox="0 0 320 200" role="img" aria-label="Toy blocks showing 4, an empty spot with a question mark, and 4">
    <g transform="rotate(-5 66 140)">
      <rect x="22" y="96" width="88" height="88" rx="12" fill="#C99A2E"/>
      <rect x="22" y="90" width="88" height="88" rx="12" fill="#FFC23D"/>
      <rect x="32" y="100" width="68" height="68" rx="8" fill="none" stroke="#fff" stroke-width="4" opacity=".7"/>
      <text class="letter" x="66" y="153" fill="#24324A">4</text>
    </g>
    <rect x="118" y="92" width="84" height="84" rx="12" fill="none" stroke="#4E5D78" stroke-width="4" stroke-dasharray="10 9" opacity=".55"/>
    <text class="letter" x="160" y="152" fill="#4E5D78" opacity=".55">?</text>
    <g transform="rotate(4 254 140)">
      <rect x="210" y="96" width="88" height="88" rx="12" fill="#2A6BAD"/>
      <rect x="210" y="90" width="88" height="88" rx="12" fill="#3A8DDE"/>
      <rect x="220" y="100" width="68" height="68" rx="8" fill="none" stroke="#fff" stroke-width="4" opacity=".7"/>
      <text class="letter" x="254" y="153" fill="#fff">4</text>
    </g>
  </svg>

  <p class="code">Error 404</p>
  <h1>We can't find that page</h1>
  <svg class="scribble" viewBox="0 0 260 14" aria-hidden="true">
    <path d="M4 9c30-8 52 6 84-1s54-6 84 0 52 2 84-4" fill="none" stroke="#FFC23D" stroke-width="6" stroke-linecap="round"/>
  </svg>
  <p class="lead">The page you're looking for may have moved or no longer exists. Let's get you back to the playroom.</p>

  <div class="actions">
    <a class="btn btn-primary" href="{{ url('/') }}">{{ auth()->check() ? 'Go to my dashboard' : 'Go to homepage' }}</a>
    <button class="btn btn-secondary" type="button" onclick="history.back()">Go back</button>
  </div>

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
  @endif
</main>

<svg class="hills" viewBox="0 0 1440 110" preserveAspectRatio="none" aria-hidden="true">
  <path d="M0 60c160-50 320-50 480 0s320 50 480 0 320-50 480 0v50H0z" fill="#8ED46B"/>
  <path d="M0 80c200-40 400-40 600 0s400 40 600 0 240-20 240-20v50H0z" fill="#6CC24A"/>
</svg>
</body>
</html>
