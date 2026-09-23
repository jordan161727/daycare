@extends('layouts.public')

@section('title', 'Help paying for day care')

@push('styles')
    .visit { display: grid; grid-template-columns: 1.15fr 1fr; gap: 36px; background: #edf7fb; border: 1px solid #d1e8f1; border-radius: 20px; padding: 32px; margin: 30px 0 42px; }
    .office { background: #fff; border-radius: 12px; padding: 24px; }
    .resources { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; border-top: 1px solid #dce5ec; padding-top: 32px; }
    iframe { width: 100%; aspect-ratio: 16/9; border: 0; border-radius: 12px; background: #12294b; }
    .prepare { margin-bottom: 40px; }
    .checklist { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 28px; padding-left: 22px; }
    .checklist li { margin: 0; }
    .questions { margin-top: 36px; }
    details { border-bottom: 1px solid #dce5ec; padding: 18px 0; }
    summary { cursor: pointer; font-size: 19px; font-weight: 700; }
    details > div { padding-top: 16px; }
    summary:focus-visible { outline: 3px solid #e7a322; outline-offset: 5px; }
    table { width: 100%; max-width: 580px; border-collapse: collapse; margin: 16px 0; font-variant-numeric: tabular-nums; }
    caption { text-align: left; font-size: 14px; padding-bottom: 10px; }
    th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid #dce5ec; }
    thead { background: #edf7fb; }
    .back { display: inline-block; font-size: 14px; font-weight: 700; text-decoration: none; margin-bottom: 18px; }
    .step-overview { display: flex; flex-wrap: wrap; gap: 10px; margin: 26px 0 34px; }
    .step-overview a { padding: 8px 14px; background: #edf7fb; border-radius: 8px; font-size: 14px; font-weight: 700; text-decoration: none; }
    .steps { list-style: none; padding: 0; margin: 0 0 44px; }
    .step { display: grid; grid-template-columns: 48px minmax(0, 1fr); gap: 22px; margin: 0; padding: 0 0 32px; position: relative; }
    .step:not(:last-child)::before { content: ''; position: absolute; width: 2px; background: #d1e8f1; top: 48px; bottom: 0; left: 23px; }
    .step-number { display: grid; place-items: center; width: 48px; height: 48px; background: #12294b; color: white; border-radius: 50%; font-size: 22px; font-weight: 800; }
    .step-content { min-width: 0; padding: 8px 0 0; }
    .step .prepare, .step .visit { margin: 0; }
    @media (max-width: 720px) {
        .checklist { grid-template-columns: 1fr; }
        .visit, .resources { grid-template-columns: 1fr; gap: 24px; }
        .visit { padding: 22px; }
        .office { padding: 16px; }
        .step { grid-template-columns: 36px minmax(0, 1fr); gap: 12px; }
        .step-number { width: 36px; height: 36px; font-size: 18px; }
        .step:not(:last-child)::before { left: 17px; top: 36px; }
        .step .visit { padding: 18px; }
    }
@endpush

{{--
    How to apply for help paying for day care, for a parent who has never done
    it before.

    Written in the order the visit happens rather than the order the program is
    organised: what to put in your bag, where to go, what you hand over, what
    comes back. The numbered rail is the whole point of the page — a parent who
    reads nothing else should still come away knowing it is four steps and the
    first one is paperwork.

    Plain words over the county's: "papers" not "documentation", "the county
    mails you a letter" not "you will receive a determination". The programme is
    named once, in full, and then dropped.

    Figures and requirements come from the NYS OCFS CCAP handout and are dated
    on the page. Erie County decides eligibility, not us — every claim here is
    hedged back to them on purpose, and the closing note says so outright.
--}}
@section('content')
    <a class="back" href="{{ route('assistance.index') }}">&larr; Help paying for day care</a>
    <div class="eyebrow">For families applying for the first time</div>
    <h1>Get help paying for<br>your child's day care.</h1>
    <p class="intro">New York State's Child Care Assistance Program (CCAP) pays part or all of the cost of day care for families who qualify. You apply through Erie County, and the county decides whether you qualify. Below are the four steps to apply for the first time. We recommend applying in person at the Erie County Day Care Unit.</p>

    <nav class="step-overview" aria-label="Application steps">
        <a href="#prepare-heading">1. Gather your paperwork</a>
        <a href="#visit-heading">2. Go to the county office</a>
        <a href="#submit-heading">3. Turn in your application</a>
        <a href="#decision-heading">4. Get the county's answer</a>
    </nav>

    <ol class="steps" aria-label="How to apply for help paying for day care">
        <li class="step">
            <span class="step-number" aria-hidden="true">1</span>
            <div class="step-content">
                <section class="prepare" aria-labelledby="prepare-heading">
                    <h2 id="prepare-heading">Gather your paperwork</h2>
                    <p>Bring these papers with you to the county office so you can fill out the application in one visit.</p>
                    <ul class="checklist">
                        <li><strong>Photo ID and birth records</strong><br>Identification for every person living in your home, including your children.</li>
                        <li><strong>Proof of income</strong><br>Recent pay stubs or other papers showing where you work and how much you earn.</li>
                        <li><strong>Proof of address</strong><br>A lease, mortgage statement, or utility bill showing where you live.</li>
                        <li><strong>Your work or school schedule</strong><br>The days and hours you need someone to care for your child.</li>
                    </ul>
                    <p class="small">Ask the county which citizenship or immigration papers they need for each child you are applying for, and whether they need any other forms.</p>
                    <a href="https://www3.erie.gov/socialservices/day-care">See Erie County's full list of required papers</a>
                </section>
            </div>
        </li>
        <li class="step">
            <span class="step-number" aria-hidden="true">2</span>
            <div class="step-content">
                <section class="visit" aria-labelledby="visit-heading">
                    <div>
                        <span class="badge">We recommend going in person</span>
                        <h2 id="visit-heading">Go to the Erie County office</h2>
                        <p>If this is your first time applying, going in person is the easiest way. A county worker can answer your questions and walk you through the form while you are there.</p>
                    </div>
                    <div class="office">
                        <h3>Erie County Day Care Unit</h3>
                        <address>Edward A. Rath County Office Building<br>95 Franklin Street<br>4th Floor, Room 449<br>Buffalo, NY 14202</address>
                        <p><strong>Open:</strong> 8:30 a.m.&ndash;4:00 p.m.</p>
                        <a class="button" href="https://www.google.com/maps/dir/?api=1&amp;destination=95+Franklin+Street+Buffalo+NY+14202">Get directions to the county office</a>
                        <p class="small" style="margin:14px 0 0">This is the county office that handles applications &mdash; not our day care. {{ config('app.name') }} is at 2645 Harlem Road, Cheektowaga.</p>
                    </div>
                </section>
            </div>
        </li>
        <li class="step">
            <span class="step-number" aria-hidden="true">3</span>
            <div class="step-content">
                <section aria-labelledby="submit-heading">
                    <h2 id="submit-heading">Fill out and turn in your application</h2>
                    <p>At the county office, ask for the child care assistance application and for help filling it out. Hand in the finished form along with the papers you brought.</p>
                    <p>Keep a copy of everything for yourself, and send in anything else the county asks for. Missing papers are the most common reason an application is delayed.</p>
                </section>
            </div>
        </li>
        <li class="step">
            <span class="step-number" aria-hidden="true">4</span>
            <div class="step-content">
                <section aria-labelledby="decision-heading">
                    <h2 id="decision-heading">Get the county's answer and set up care</h2>
                    <p>Erie County will review your application and mail you a letter saying whether you qualify. Follow the instructions in that letter.</p>
                    <h3>If you are approved</h3>
                    <p>Pick a day care that accepts child care assistance and sign your child up. Before you count on the county paying, check with them which dates are covered and what else you need to do.</p>
                    <p>Many families pay a small share of the cost themselves each week. This is called a <strong>family share copay</strong>. Some families pay nothing &mdash; ask the county whether a copay applies to you.</p>
                    <p>{{ config('app.name') }} accepts child care assistance. To ask about openings and enrolling, call <a href="tel:+17168962330">(716) 896-2330</a>.</p>
                </section>
            </div>
        </li>
    </ol>

    <div class="resources">
        <section aria-labelledby="video-heading">
            <h2 id="video-heading">Optional: watch a short video</h2>
            <p>New York State made this video explaining how help paying for child care works.</p>
            <iframe src="https://www.youtube-nocookie.com/embed/5Zg8UU855po" title="Video explaining New York State's Child Care Assistance Program" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
            <a class="small" href="https://www.youtube.com/watch?v=5Zg8UU855po">Watch the video on YouTube</a>
        </section>
        <section aria-labelledby="alternatives-heading">
            <h2 id="alternatives-heading">Can't get to the county office?</h2>
            <p>You can also start online or mail in a paper form.</p>
            <ul>
                <li><a href="https://hs.ocfs.ny.gov/CCAPeligibility/">Answer a few questions to see if you might qualify</a><br><span class="small">This is just a quick check. It is not an application and not an approval.</span></li>
                <li><a href="https://hs.ocfs.ny.gov/CCAPsignup/invite">Apply online</a><br><span class="small">Ask New York State to email you an invitation to their application website.</span></li>
                <li><a href="https://ocfs.ny.gov/forms/ocfs/OCFS-6025/OCFS-6025.pdf">Print the paper application (PDF)</a></li>
                <li><a href="https://www3.erie.gov/socialservices/day-care">See what papers to include and where to mail them</a></li>
            </ul>
        </section>
    </div>

    <section class="questions" aria-labelledby="questions-heading">
        <h2 id="questions-heading">Common questions</h2>
        <details>
            <summary>Who can qualify for help paying for day care?</summary>
            <div>
                <p>It depends on how much your household earns, your child's age and situation, and why you need child care. New York State lists working, going to school or job training, looking for a job, and receiving public assistance as reasons that can qualify you.</p>
                <p>Help is usually for children under 13. Children with special needs or under court supervision may qualify at older ages. Ask the county which rules apply to your family.</p>
                <a href="https://hs.ocfs.ny.gov/CCAPeligibility/">Answer a few questions to see if you might qualify</a>
            </div>
        </details>
        <details>
            <summary>How much can my family earn and still qualify?</summary>
            <div>
                <p>New York State uses the yearly income limits below. Income is only one part of qualifying &mdash; being under the limit does not guarantee you will be approved or that funding is available.</p>
                <table>
                    <caption>In effect {{ $limits['effective'] }} &middot; From the New York State CCAP handout</caption>
                    <thead><tr><th scope="col">People in your household</th><th scope="col">Yearly income limit</th></tr></thead>
                    <tbody>
                        @foreach ($limits['rows'] as $size => $limit)
                            <tr><th scope="row">{{ $size }}</th><td>${{ number_format($limit, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="small">These limits change every year on June 1. If your household has more than {{ count($limits['rows']) }} people, or you are applying outside these dates, call Erie County for the current limits.</p>
            </div>
        </details>
    </section>

    <p class="small note">{{ config('app.name') }} put this page together to help families get started. Erie County, not {{ config('app.name') }}, reviews applications and decides who qualifies. Contact the county with questions about your application, available funding, and any waiting list. The paperwork list and income limits above come from the New York State Office of Children and Family Services CCAP handout.</p>
@endsection
