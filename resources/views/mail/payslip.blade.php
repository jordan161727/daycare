{{-- Plain wording on purpose: a payslip email should look like a letter from
     the centre, not a marketing template, or it gets reported as phishing. --}}
<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #26323d; line-height: 1.55;">
    @foreach(preg_split('/\R{2,}/', trim($body)) as $paragraph)
        <p style="margin: 0 0 14px;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach

    <p style="margin: 24px 0 0; font-size: 12px; color: #6b7a88;">
        {{ config('app.name') }}
    </p>
</div>
