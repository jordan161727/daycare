@extends('layouts.public')

@section('title', 'Help paying for day care')

@push('styles')
    .choices { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; margin: 34px 0 44px; padding: 0; list-style: none; }
    .choice { position: relative; display: flex; flex-direction: column; background: #edf7fb; border: 1px solid #d1e8f1; border-radius: 20px; padding: 30px; margin: 0; }
    .choice h2 { margin-bottom: 10px; }
    .choice p { color: #46576a; }
    .choice ul { margin: 0 0 22px; color: #46576a; font-size: 15px; }
    .choice li { margin: 6px 0; }
    .choice .button { align-self: flex-start; margin-top: auto; }
    .choice .button::after { content: ''; position: absolute; inset: 0; border-radius: 20px; }
    .choice:hover { background: #e4f2f8; }
    .choice:has(.button:focus-visible) { outline: 3px solid #e7a322; outline-offset: 5px; }
    .help { display: grid; grid-template-columns: 1.15fr 1fr; gap: 36px; border-top: 1px solid #dce5ec; padding-top: 32px; }
    .office { background: #edf7fb; border: 1px solid #d1e8f1; border-radius: 12px; padding: 24px; }
    @media (max-width: 720px) {
        .choices, .help { grid-template-columns: 1fr; gap: 20px; }
        .choice { padding: 22px; }
        .office { padding: 18px; }
    }
@endpush

{{--
    The front door for families who need help paying for day care.

    Two cards and almost nothing else. A parent arriving here knows one thing
    about themselves — whether they have applied before — and that is the only
    question this page asks. Everything that tempted us onto this page (the
    income table, the document list, the county's hours) lives one click deeper,
    because a parent renewing does not need the first-timer's checklist and a
    first-timer reading both gets neither.

    The heading says "help paying for day care" rather than "child care
    assistance": the programme's own name is the thing parents do not recognise,
    so it appears once, in the paragraph, after the plain words have landed.

    The whole card is clickable — the button's ::after covers it — while the
    link text stays the real target, so keyboard and screen-reader users get one
    ordinary link rather than a div that swallows clicks.
--}}
@section('content')
    <div class="eyebrow">For {{ config('app.name') }} families</div>
    <h1>Help paying for<br>your child's day care.</h1>
    <p class="intro">New York State can pay part or all of your day care costs if your family qualifies. The program is called Child Care Assistance (CCAP), and Erie County decides who gets it. Choose the option below that matches where you are.</p>

    <ul class="choices">
        <li class="choice">
            <span class="badge">Start here if you're new</span>
            <h2>I've never applied before</h2>
            <p>See what papers to bring and the four steps to apply through Erie County.</p>
            <ul>
                <li>What documents you need</li>
                <li>Where to go and when they're open</li>
                <li>Income limits by household size</li>
            </ul>
            <a class="button" href="{{ route('assistance.apply') }}">See how to apply</a>
        </li>
        <li class="choice">
            <span class="badge">Don't let your help stop</span>
            <h2>I already get help and need to keep it</h2>
            <p>Erie County handles renewals. They mail you a packet before your approval ends &mdash; return it on time so your payments don't stop.</p>
            <ul>
                <li>Call the Day Care Unit if no packet arrives</li>
                <li>Tell them if your job, hours or household changed</li>
            </ul>
            <a class="button" href="https://www3.erie.gov/socialservices/day-care">Erie County renewals</a>
        </li>
    </ul>

    <div class="help">
        <section aria-labelledby="unsure-heading">
            <h2 id="unsure-heading">Not sure if you qualify?</h2>
            <p>New York State has a short questionnaire that gives you a rough idea in a few minutes. It is not an application and not an approval &mdash; only Erie County can decide.</p>
            <ul>
                <li><a href="https://hs.ocfs.ny.gov/CCAPeligibility/">Answer a few questions to see if you might qualify</a></li>
                <li><a href="https://www3.erie.gov/socialservices/day-care">Erie County day care assistance page</a></li>
            </ul>
            <p class="small">{{ config('app.name') }} accepts child care assistance. To ask about openings and enrolling, call <a href="tel:+17168962330">(716) 896-2330</a>.</p>
        </section>
        <section class="office" aria-labelledby="office-heading">
            <h3 id="office-heading">Erie County Day Care Unit</h3>
            <address>Edward A. Rath County Office Building<br>95 Franklin Street<br>4th Floor, Room 449<br>Buffalo, NY 14202</address>
            <p><strong>Open:</strong> 8:30 a.m.&ndash;4:00 p.m.</p>
            <a class="button" href="https://www.google.com/maps/dir/?api=1&amp;destination=95+Franklin+Street+Buffalo+NY+14202">Get directions</a>
            <p class="small" style="margin:14px 0 0">This is the county office that handles applications &mdash; not our day care. {{ config('app.name') }} is at 2645 Harlem Road, Cheektowaga.</p>
        </section>
    </div>

    <p class="small note">{{ config('app.name') }} put this page together to help families get started. Erie County, not {{ config('app.name') }}, reviews applications and decides who qualifies. Contact the county with questions about your application, available funding, and any waiting list.</p>
@endsection
