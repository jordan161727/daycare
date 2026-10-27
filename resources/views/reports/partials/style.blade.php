{{-- The client's Reports styles (Reports.dc.html) on top of the shared .ch
     set: the report cards, the filter chips, the three sheets and the
     district's paper layout, plus what print keeps. Values from docs/work.md. --}}
<style>
    .ch .rcard { font: inherit; text-align: left; display: flex; flex-direction: column; gap: 4px; padding: 11px 12px; border: 1px solid #e3e6ee; background: #fff; border-radius: 14px; cursor: pointer; flex: 1 1 130px; min-width: 0; box-sizing: border-box; color: #1c2130; text-decoration: none; }
    .ch .rcard:hover { border-color: #9fb0d6; color: #1c2130; }
    .ch .rcard.on { border-color: #1b5e91; box-shadow: 0 0 0 2px #cfe0f5; }
    .dark .ch .rcard { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .12); }
    .ch .chip.f { font-size: 12.5px; padding: 6px 12px; min-height: 32px; text-decoration: none; }
    .ch .chip.f.on { background: #1b5e91; border-color: #1b5e91; color: #fff; }
    .ch .lblu { font-size: 11px; font-weight: 600; color: #5f6676; text-transform: uppercase; letter-spacing: .06em; }
    .ch .fg { display: flex; flex-direction: column; gap: 6px; }
    .ch .ct { border-collapse: separate; border-spacing: 0; font-size: 12px; width: 100%; }
    .ch .ct th { font-size: 11px; font-weight: 600; color: #5f6676; background: #fff; border-bottom: 1px solid #e7e9f0; padding: 6px 4px; white-space: nowrap; text-transform: none; letter-spacing: 0; }
    .ch .ct td { border-bottom: 1px solid #f0f2f7; padding: 4px; white-space: nowrap; }
    .ch .stick { position: sticky; left: 0; background: #fff; z-index: 1; text-align: left; padding-left: 10px !important; border-right: 1px solid #e7e9f0; }
    .dark .ch .ct th, .dark .ch .stick { background: rgb(15 23 42); }
    .ch .dh { min-width: 46px; text-align: center; }
    .ch .dh span { display: block; font-size: 10px; color: #8a90a0; }
    .ch .dh b { font-size: 12px; color: #1c2130; }
    .ch .dh.closed b, .ch .dh.fut b { color: #b6bccb; }
    .ch .cc { text-align: center; font-variant-numeric: tabular-nums; font-size: 11px; line-height: 1.25; }
    .ch .cc span { display: block; }
    .ch .cc.ab { color: #be123c; font-weight: 700; font-size: 12.5px; background: #fff5f6; }
    .ch .cc.ck { color: #15803d; font-weight: 700; font-size: 13px; }
    .ch .closedc { background: repeating-linear-gradient(135deg, #f3f4f8 0 5px, #fff 5px 10px); }
    .ch .lg { display: inline-block; width: 12px; height: 12px; border: 1px solid #e1e4ec; border-radius: 3px; vertical-align: -2px; }
    .ch tr.grp td { background: #f7f9fd; padding-top: 8px; padding-bottom: 6px; }
    .ch .tt { text-align: center; white-space: nowrap; padding: 4px 10px !important; font-variant-numeric: tabular-nums; border-left: 1px solid #f0f2f7; }
    .ch .st .sc { min-width: 112px; text-align: left; padding: 6px 8px; vertical-align: top; border-left: 1px solid #f5f6fa; white-space: normal; }
    .ch .sc span { display: block; }
    .ch .t1 { font-size: 11.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ch .t2 { font-size: 11px; color: #5f6676; }
    .ch .t3 { font-size: 10.5px; color: #be123c; font-weight: 700; }
    .ch .sc.off { background: #f6f2ff; color: #5b21b6; font-size: 11.5px; font-weight: 600; }
    .ch .sw { position: relative; display: inline-block; width: 42px; height: 24px; }
    .ch .sw input { opacity: 0; width: 0; height: 0; }
    .ch .sw span { position: absolute; inset: 0; background: #cfd3de; border-radius: 999px; cursor: pointer; transition: .15s; }
    .ch .sw span:before { content: ""; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .15s; }
    .ch .sw input:checked + span { background: #15803d; }
    .ch .sw input:checked + span:before { transform: translateX(18px); }
    .ch .em-chip { display: inline-flex; align-items: center; gap: 6px; background: #eef3fb; color: #1b5e91; border-radius: 999px; padding: 4px 6px 4px 11px; font-size: 13px; font-weight: 600; }
    .ch .em-chip button { border: 0; background: #d6e3f5; color: #1b5e91; width: 20px; height: 20px; border-radius: 50%; cursor: pointer; font-size: 13px; line-height: 1; padding: 0; }
    .ch .em-in { font: inherit; font-size: 13.5px; border: 0; outline: 0; flex: 1; min-width: 200px; padding: 4px; background: transparent; color: inherit; }
    .ch .ckb { display: flex; align-items: center; gap: 8px; }
    .ch .ckb input { width: 16px; height: 16px; accent-color: #1b5e91; }
    .ch .att { background: #f2f4f9; border-radius: 8px; padding: 4px 9px; font-size: 12.5px; }
    .ch .kpi { border: 1px solid #e7e9f0; border-radius: 12px; padding: 10px 14px; display: flex; flex-direction: column; gap: 2px; }
    .ch .paper { background: #fff; border: 1px solid #d9dce4; box-shadow: 0 4px 18px rgba(28,33,48,.08); padding: 26px 30px 22px; font-family: Arial, Helvetica, sans-serif; color: #111; overflow-x: auto; border-radius: 16px; }
    .ch .fld { font-size: 14px; white-space: nowrap; }
    .ch .ul { display: inline-block; border-bottom: 1px solid #111; padding: 0 8px 1px; font-size: 19px; color: #1f2a7a; }
    .ch .pt { border-collapse: collapse; width: 100%; font-size: 12.5px; table-layout: fixed; min-width: 1000px; }
    .ch .pt th, .ch .pt td { border: 1px solid #444; }
    .ch .pt th { font-size: inherit; color: #111; text-transform: none; letter-spacing: 0; }
    .ch .pd { height: 22px; font-weight: 400; font-size: 13.5px; color: #1f2a7a; text-align: center; padding: 0; }
    .ch .pw { font-size: 11px; font-weight: 600; text-align: center; padding: 1px 0; }
    .ch .pc { height: 21px; text-align: center; padding: 0; font-size: 16px; color: #1f2a7a; }
    .ch .pc.live { cursor: pointer; }
    .ch .pc.live:hover, .ch .pc.live:focus-visible { background: #e8f1fb; outline: none; }
    @media print { .ch .pc.live { cursor: default; } }
    .ch .pn { padding: 0 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #1f2a7a; font-size: 13px; }
    .ch .no { display: inline-block; width: 20px; text-align: right; margin-right: 6px; color: #111; font-size: 12px; }
    .ch .pnote { font-size: 10.5px; text-align: left; padding: 0 4px; line-height: 1.1; white-space: normal; }
    .ch .pt .we { border-right-width: 2px; }
    @media print {
        aside, nav, header.noprint, .noprint, [data-kids-background] { display: none !important; }
        html, body { background: #fff !important; }
        main { padding: 0 !important; }
        .ch .sheet { box-shadow: none !important; border: 0 !important; padding: 0 !important; break-inside: avoid; page-break-inside: avoid; }
        @page { size: letter landscape; margin: 8mm; }
        .ch .paper { overflow: visible; }
        .ch .paper .pt { min-width: 0; font-size: 11px; }
        .ch .paper .pc { height: 21px; font-size: 14px; }
        .ch .paper .pn { font-size: 11.5px; }
        .ch .paper .pnote { font-size: 9px; line-height: 1.05; }
        .ch .paper .pd { height: 18px; font-size: 12px; }
        .ch .paper .ul { font-size: 16px; }
        .ch .paper .fld { font-size: 12.5px; }
    }
</style>
