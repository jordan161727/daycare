{{-- The illustrated half of the sign-in page: sky, sun, clouds, birds,
     balloons and a rainbow, with whatever the page puts in the slot laid over
     it as glass.

     A composition, not a scene that plays: these coordinates are where
     everything sits, and the stylesheet gives a few of them a slow drift or
     sway around that resting place — weather beyond a window rather than an
     animation with a beginning. Every one of them has a still arrangement for
     anybody who has asked their system for less motion, which is why the
     placements live here and only the movement lives in the CSS.

     The art is one aria-hidden block that no pointer can reach, so everything a
     screen reader or a tab key finds here is the slot's content and nothing
     else.

     Sizes are in px rather than percentages: this is a picture with a
     composition, and a sun that grows with the window would crowd the balloons
     on a wide monitor. Only the placements are proportional. --}}
<div {{ $attributes->class('sky-panel') }}>
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <span class="sky-sun"><span class="sky-sun-rays"></span></span>
        <span class="sky-moon"><span class="sky-moon-crater sky-moon-crater--one"></span><span class="sky-moon-crater sky-moon-crater--two"></span><span class="sky-moon-crater sky-moon-crater--three"></span></span>
        <span class="sky-stars sky-stars--far"></span>
        <span class="sky-stars sky-stars--near"></span>

        {{-- Furthest away, so first: the rainbow is the one thing here the
             balloons stand in front of, and paint order is the only depth this
             picture has.

             Drawn from the foot up and cropped by the panel, the way one
             standing behind the building would be. The bands are strokes on
             concentric arcs, each radius one stroke-width inside the last, so
             they meet with no seam and no overlap.

             Sized off the panel's height, not its width, because that is what
             its position is measured against: the foot is 2% below the bottom
             edge, so an arc of a fixed width sinks further down the frame the
             taller the window gets. In vh the whole composition holds — the
             arc crests around two-thirds up on a laptop and on a 27in monitor
             alike. The vw cap is for the narrow, tall window, where 72vh would
             put the feet outside the panel. --}}
        <svg class="sky-rainbow" style="left:44%;bottom:-2%;width:min(52vw,72vh)" viewBox="0 0 400 220" fill="none" stroke-width="26" stroke-linecap="butt">
            <path d="M14 220A186 186 0 0 1 386 220" stroke="#ee9d8f"/>
            <path d="M40 220A160 160 0 0 1 360 220" stroke="#f3ba8d"/>
            <path d="M66 220A134 134 0 0 1 334 220" stroke="#f7de9b"/>
            <path d="M92 220A108 108 0 0 1 308 220" stroke="#abdba6"/>
            <path d="M118 220A82 82 0 0 1 282 220" stroke="#a6c9e9"/>
            <path d="M144 220A56 56 0 0 1 256 220" stroke="#c3b7e8"/>
        </svg>

        {{-- Both hug the left edge and run off it. A cloud parked whole in the
             middle of a sky that never moves is a sticker; one leaving the frame
             reads as weather carrying on past it. It also keeps the open sky
             between the sun and the rainbow clear, which is where the eye goes.

             Two, not a row of them down the edge — evenly spaced crops read as a
             pattern rather than as clouds. The lower one sits behind the hero
             card and shows through its glass as a soft white blur.

             How much of each is left is (180 x --s) + --x: the scale grows from
             the element's own left edge, so pushing a small one further out
             crops it to a lump rather than to a smaller cloud. --}}
        <span class="sky-cloud" style="--x:-78px;--y:27%"></span>
        <span class="sky-cloud" style="--x:-72px;--y:56%;--s:.85"></span>

        {{-- Day and night are two pictures, not one picture with the lights
             turned down. Birds and balloons belong to the first: at midnight
             they read as clip art somebody forgot to take off, so the dark
             theme drops them and puts a shooting star and two sky lanterns in
             the same three places instead. Same composition, same weights —
             what fills each corner is simply the thing that would be there at
             that hour. --}}
        <span class="sky-shoot" style="left:78%;top:9%"></span>

        <span class="sky-birds" style="left:69%;top:2%">
            <svg width="128" height="48" viewBox="0 0 128 48" fill="none" stroke="#2f4356" stroke-width="3" stroke-linecap="round" opacity=".5">
                <path d="M6 26q7-9 14 0 6-9 13 0"/>
                <path d="M48 9q6-8 12 0 5-8 11 0"/>
                <path d="M84 34q6-8 12 0 5-8 11 0"/>
            </svg>
        </span>

        {{-- Leaning opposite ways, and neither of them upright: two balloons at
             exactly the same angle would read as one shape stamped twice. --}}
        <span class="sky-balloon" style="left:70%;top:36%;--tilt:-3deg">
            <svg width="86" height="200" viewBox="0 0 86 200" fill="none">
                <ellipse cx="43" cy="50" rx="36" ry="44" fill="#f6d271"/>
                <ellipse cx="31" cy="36" rx="9" ry="13" fill="#fff" opacity=".35"/>
                <path d="M38 93h10l-3 8h-4z" fill="#e2b74f"/>
                <path d="M43 101c8 26-8 44 0 97" stroke="#fff" stroke-width="2.5" opacity=".75"/>
            </svg>
        </span>

        <span class="sky-balloon" style="left:84%;top:74%;--tilt:2.5deg">
            <svg width="74" height="180" viewBox="0 0 74 180" fill="none">
                <ellipse cx="37" cy="44" rx="31" ry="38" fill="#a9dcb2"/>
                <ellipse cx="27" cy="32" rx="8" ry="11" fill="#fff" opacity=".35"/>
                <path d="M33 81h8l-2 7h-4z" fill="#8ec69a"/>
                <path d="M37 88c7 22-7 38 0 84" stroke="#fff" stroke-width="2.5" opacity=".75"/>
            </svg>
        </span>

        {{-- The balloons after dark. Paper, lit from inside, rising — the same
             two silhouettes in the same two places, and the only warm colour on
             a page that is otherwise all blue. The flame is its own element so
             it can flicker without the lantern flickering with it. --}}
        <span class="sky-lantern" style="left:70%;top:36%;--tilt:-3deg">
            <svg width="60" height="86" viewBox="0 0 60 86" fill="none">
                <path d="M30 3c14 0 23 10 23 23 0 12-7 21-10 29-2 5-3 8-3 12H20c0-4-1-7-3-12C14 47 7 38 7 26 7 13 16 3 30 3Z" fill="#ffca7d" opacity=".9"/>
                <path d="M30 3c14 0 23 10 23 23 0 12-7 21-10 29H17C14 47 7 38 7 26 7 13 16 3 30 3Z" fill="#ffe3ae" opacity=".55"/>
                <rect x="19" y="65" width="22" height="4" rx="2" fill="#c9873c"/>
                <ellipse class="sky-lantern-flame" cx="30" cy="58" rx="4" ry="6" fill="#fff6cf"/>
            </svg>
        </span>

        <span class="sky-lantern" style="left:84%;top:74%;--tilt:2.5deg;--d:7.5s">
            <svg width="50" height="72" viewBox="0 0 60 86" fill="none">
                <path d="M30 3c14 0 23 10 23 23 0 12-7 21-10 29-2 5-3 8-3 12H20c0-4-1-7-3-12C14 47 7 38 7 26 7 13 16 3 30 3Z" fill="#ffbf6b" opacity=".85"/>
                <path d="M30 3c14 0 23 10 23 23 0 12-7 21-10 29H17C14 47 7 38 7 26 7 13 16 3 30 3Z" fill="#ffe3ae" opacity=".5"/>
                <rect x="19" y="65" width="22" height="4" rx="2" fill="#c9873c"/>
                <ellipse class="sky-lantern-flame" cx="30" cy="58" rx="4" ry="6" fill="#fff6cf"/>
            </svg>
        </span>
    </div>

    {{-- Two rows: everything the page puts first, centred in the height that is
         left, and a last block sitting on the floor.

         A grid rather than a flex column, because the two ways of doing this in
         flex both fail. justify-between spreads the blocks to the edges, which
         read as spread only while there were three of them — with two it pins
         the words to the ceiling. And justify-center with an mt-auto footer is
         worse than it looks: an auto margin swallows every pixel of free space
         before justify-content gets any, so the centring silently stops
         working and the words go back to the top.

         grid-rows-[1fr_auto] says the thing outright. The first row takes the
         height the footer does not want, and place-items-center puts the words
         in the middle of it. --}}
    <div class="relative grid h-full grid-rows-[1fr_auto] place-items-center gap-8 p-10 text-center xl:p-14">
        {{ $slot }}
    </div>
</div>
