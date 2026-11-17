{{-- The alert chip's look, once per page (QA, 16 Nov 2026).

     One look on every screen that shows a child's alerts — the children
     list, the record, check-in, Director Attendance, the door kiosk, the
     kitchen list. Each kind has its own colour and icon: an allergy is
     orange, medical is blue, medication is violet, a court order is rose,
     and a note is neutral grey, so "Peanuts" never reads like "Leaves early
     on Tuesdays". Severe is a solid chip in the kind's colour with the word
     "Severe" on it. Plain CSS rather than Tailwind utilities, so the pages
     drawn in Alpine and the ones drawn in Blade agree without a build, and
     the class names are our own (no collision with a utility). --}}
@once
<style>
    .na-alert { display: inline-flex; align-items: center; gap: 5px; max-width: 100%; border-radius: 999px; border: 1px solid transparent; padding: 2px 9px 2px 7px; font-size: 12px; font-weight: 600; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
    .na-alert-block { display: flex; white-space: normal; border-radius: 12px; padding: 7px 11px; font-size: 13px; text-align: left; align-items: flex-start; }
    .na-alert-icon { flex: none; }
    .na-alert-block .na-alert-icon { margin-top: 2px; }
    .na-alert-label { font-weight: 700; }
    .na-alert-sev { margin-left: 2px; font-size: 10.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }

    .na-alert--allergy { background: #fff1e6; color: #9a3412; border-color: #fdba74; }
    .na-alert--medical { background: #e0f2fe; color: #075985; border-color: #7dd3fc; }
    .na-alert--medication { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
    .na-alert--court { background: #ffe4e6; color: #9f1239; border-color: #fda4af; }
    .na-alert--note { background: #f1f5f9; color: #475569; border-color: #e2e8f0; font-weight: 500; }

    .na-alert--severe { color: #fff; font-weight: 700; box-shadow: 0 0 0 2px rgba(255, 255, 255, .9), 0 0 0 3.5px currentColor; }
    .na-alert--allergy.na-alert--severe { background: #c2410c; border-color: #9a3412; box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #c2410c; }
    .na-alert--medical.na-alert--severe { background: #0369a1; border-color: #075985; box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #0369a1; }
    .na-alert--medication.na-alert--severe { background: #6d28d9; border-color: #5b21b6; box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #6d28d9; }
    .na-alert--court.na-alert--severe { background: #be123c; border-color: #9f1239; box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #be123c; }
    .na-alert--note.na-alert--severe { background: #475569; border-color: #334155; box-shadow: none; }

    .dark .na-alert--allergy { background: rgba(249, 115, 22, .16); color: #fed7aa; border-color: rgba(251, 146, 60, .5); }
    .dark .na-alert--medical { background: rgba(14, 165, 233, .16); color: #bae6fd; border-color: rgba(56, 189, 248, .5); }
    .dark .na-alert--medication { background: rgba(139, 92, 246, .18); color: #ddd6fe; border-color: rgba(167, 139, 250, .5); }
    .dark .na-alert--court { background: rgba(244, 63, 94, .16); color: #fecdd3; border-color: rgba(251, 113, 133, .5); }
    .dark .na-alert--note { background: rgba(255, 255, 255, .07); color: #cbd5e1; border-color: rgba(255, 255, 255, .12); }
    .dark .na-alert--severe { color: #fff; }
    .dark .na-alert--allergy.na-alert--severe { background: #ea580c; border-color: #fb923c; box-shadow: 0 0 0 2px #0f172a, 0 0 0 3.5px #fb923c; }
    .dark .na-alert--medical.na-alert--severe { background: #0284c7; border-color: #38bdf8; box-shadow: 0 0 0 2px #0f172a, 0 0 0 3.5px #38bdf8; }
    .dark .na-alert--medication.na-alert--severe { background: #7c3aed; border-color: #a78bfa; box-shadow: 0 0 0 2px #0f172a, 0 0 0 3.5px #a78bfa; }
    .dark .na-alert--court.na-alert--severe { background: #e11d48; border-color: #fb7185; box-shadow: 0 0 0 2px #0f172a, 0 0 0 3.5px #fb7185; }
    .dark .na-alert--note.na-alert--severe { background: #64748b; border-color: #94a3b8; box-shadow: none; }
</style>
@endonce
