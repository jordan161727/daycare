{{-- The tab icon, in one file because three layouts need it — the app shell,
     the login page and the forced password change — and a favicon that is right
     on two of them is the kind of thing nobody notices for a year.

     Real sizes rather than one 512px file for the browser to shrink: a tab is
     16 or 32 device pixels, and downscaling by sixteen turns two pale angels
     into a smudge. Each is resampled from the 512 and carries a little padding,
     so the mark fills the tab instead of floating in the middle of it.

     The 180 is Apple's, and it is the one with a white ground: iOS composites a
     transparent touch icon onto black, which would lose pale blue entirely.

     public/favicon.ico holds the same 16/32/48 as PNG entries, so the file a
     browser fetches by convention is this icon too and not whatever was there
     before. Regenerate the set from the 512 with tools/generate-icons.php. --}}
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/icon-angels-light-16.png') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icon-angels-light-32.png') }}">
<link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/icon-angels-light-48.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/icon-angels-light-180.png') }}">
