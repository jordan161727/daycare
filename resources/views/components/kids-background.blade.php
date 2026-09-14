@props(['strong' => false])
{{-- The animated background for the screens children and parents stand at.

     Two strengths. The app screens get the quiet one — it sits behind a dense
     sheet that people read all day, and colour competing with a sixty-row grid
     is noise. The kiosk gets `strong`: it is a screen a four-year-old stands in
     front of with nothing else on it, viewed from further away, and at the quiet
     strength it reads as a blank white page.

     Sign-in does not use this at all: it has its own scene, in the sky-panel
     component, because the illustration there is half the page rather than a
     wash behind a sheet.

     Everything here is decoration and nothing in it is a control: aria-hidden,
     pointer-events:none, and painted behind the cards at z-index -1, which is
     above the page's own background but below every card, so the glass frosts
     over it rather than hiding it.

     There is no pause switch. The one escape that matters is the system-level
     one — see the prefers-reduced-motion block in app.css, which stops all of
     it for anybody who has asked their device for less movement. --}}
<div {{ $attributes->class(["kids-bg", "kids-bg--strong" => $strong]) }} aria-hidden="true">
    <span class="kids-bg-blob" style="width:520px;height:520px;left:-140px;top:-120px;background:#fde8c8"></span>
    <span class="kids-bg-blob" style="width:460px;height:460px;right:-120px;top:-90px;background:#dcecff;animation-delay:-12s"></span>
    <span class="kids-bg-blob" style="width:480px;height:480px;left:30%;bottom:-200px;background:#e6f6e9;animation-delay:-25s"></span>

    <span class="kids-bg-cloud" style="top:5%;animation-duration:90s"></span>
    <span class="kids-bg-cloud" style="top:42%;animation-duration:120s;animation-delay:-40s;--s:.7"></span>
    <span class="kids-bg-cloud" style="top:70%;animation-duration:105s;animation-delay:-75s;--s:.85"></span>

    {{-- --rest is where each one sits when motion is turned off, so they spread
         across the page instead of stacking in one corner.

         The delays are negative on purpose: a positive delay means an empty sky
         for the first fifteen seconds, and somebody walking up to a kiosk sees
         exactly that first fifteen seconds. Negative starts them mid-flight. --}}
    <span class="kids-bg-float kids-bg-star" style="--x:8%;--d:22s;--delay:-3s;--rest:18%">★</span>
    <span class="kids-bg-float" style="--x:22%;--d:26s;--delay:-14s;--rest:62%">🎈</span>
    <span class="kids-bg-float" style="--x:38%;--d:30s;--delay:-9s;--rest:32%">☀️</span>
    <span class="kids-bg-float kids-bg-star" style="--x:55%;--d:24s;--delay:-19s;--rest:74%">★</span>
    <span class="kids-bg-float" style="--x:70%;--d:28s;--delay:-6s;--rest:26%">🦋</span>
    <span class="kids-bg-float" style="--x:84%;--d:25s;--delay:-21s;--rest:58%">🎈</span>
    <span class="kids-bg-float kids-bg-star" style="--x:93%;--d:21s;--delay:-11s;--rest:44%">★</span>

    @if($strong)
        {{-- A few more, and larger, for the screen with nothing else on it. --}}
        <span class="kids-bg-float" style="--x:15%;--d:27s;--delay:-17s;--rest:48%">🌈</span>
        <span class="kids-bg-float" style="--x:47%;--d:23s;--delay:-2s;--rest:80%">🧸</span>
        <span class="kids-bg-float" style="--x:62%;--d:31s;--delay:-24s;--rest:14%">🐣</span>
        <span class="kids-bg-float" style="--x:78%;--d:26s;--delay:-8s;--rest:68%">🍎</span>
    @endif
</div>
