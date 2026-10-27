{{-- The client's Children / child record styles (Children.dc.html,
     ChildProfile.dc.html), scoped to .ch the way the floor plan's are to
     .fp. The design system in docs/work.md is the source for every value. --}}
<style>
    .ch { color: #1c2130; font-size: 14px; }
    .dark .ch { color: #e2e8f0; }
    .ch a { color: #4c5a9e; text-decoration: none; } .ch a:hover { color: #1b5e91; }
    .dark .ch a { color: #a5b4fc; }
    .ch .ch-card { background: #fff; border: 1px solid #e7e9f0; border-radius: 16px; }
    .dark .ch .ch-card { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
    .ch .btn { font: inherit; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 8px 13px; border: 1px solid #cfd3de; background: #fff; color: #1c2130; cursor: pointer; min-height: 36px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .dark .ch .btn { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); }
    .ch .btn.pri { background: #1b5e91; border-color: #1b5e91; color: #fff; }
    .ch .btn.danger { background: #be123c; border-color: #be123c; color: #fff; }
    .ch .btn.sm { padding: 4px 10px; min-height: 30px; font-size: 12.5px; }
    .ch .btn:disabled { opacity: .5; cursor: not-allowed; }
    .ch .seg { font: inherit; font-size: 13px; font-weight: 600; padding: 7px 14px; border-radius: 999px; border: 0; background: transparent; color: #5f6676; cursor: pointer; min-height: 34px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .ch .seg.on { background: #1c2130; color: #fff; }
    .dark .ch .seg { color: #cbd5e1; } .dark .ch .seg.on { background: #fff; color: #1c2130; }
    .ch .chip { font: inherit; font-size: 13px; font-weight: 600; padding: 6px 12px; border-radius: 999px; border: 1px solid #e1e4ec; background: #fff; color: #1c2130; cursor: pointer; min-height: 34px; display: inline-flex; align-items: center; gap: 7px; text-decoration: none; white-space: nowrap; }
    .ch .chip.on { background: #1c2130; border-color: #1c2130; color: #fff; }
    .dark .ch .chip { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); } .dark .ch .chip.on { background: #fff; color: #1c2130; border-color: #fff; }
    .ch .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
    .ch .ok { background: #e3f5ec; color: #15803d; } .ch .info { background: #dcebfa; color: #1b5e91; } .ch .mut { background: #f2f4f9; color: #5f6676; }
    .ch .alert, .ch .allergy, .ch .warn { background: #fef3c7; color: #92400e; } .ch .court, .ch .bad { background: #ffe4e6; color: #be123c; } .ch .med, .ch .medical { background: #dcebfa; color: #1b5e91; } .ch .note { background: #f2f4f9; color: #5f6676; }
    .ch .miss { background: #fff1e6; color: #b45309; border: 1px dashed #f0b84a; }
    .ch .dss { background: #ede9fe; color: #5b21b6; }
    .ch .tbl { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .ch .tbl th { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #5f6676; text-align: left; padding: 10px 9px; border-bottom: 1px solid #e7e9f0; white-space: nowrap; }
    .ch .tbl td { padding: 9px 9px; border-bottom: 1px solid #f0f1f5; vertical-align: middle; white-space: nowrap; }
    .dark .ch .tbl th { color: #94a3b8; border-color: rgb(255 255 255 / .1); } .dark .ch .tbl td { border-color: rgb(255 255 255 / .06); }
    .ch .tbl.people th, .ch .tbl.people td { padding: 8px 6px; white-space: normal; }
    .ch .tbl.people td:nth-child(4) { white-space: nowrap; }
    .ch .tbl tbody tr.row { cursor: pointer; } .ch .tbl tbody tr.row:hover td { background: #f7f9fd; } .dark .ch .tbl tbody tr.row:hover td { background: rgb(255 255 255 / .04); }
    .ch .tbl th.sortable a { color: inherit; } .ch .tbl th.on a { color: #1b5e91; }
    .ch .av { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex: none; overflow: hidden; }
    .ch .inp { font: inherit; font-size: 14px; padding: 8px 11px; border: 1px solid #cfd3de; border-radius: 9px; background: #fff; color: #1c2130; min-height: 38px; box-sizing: border-box; width: 100%; }
    .dark .ch .inp { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); }
    .ch .inp.round { border-radius: 999px; }
    .ch .d { display: inline-flex; width: 20px; height: 20px; border-radius: 5px; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; margin-right: 2px; border: 0; font-family: inherit; }
    .ch .d.y { background: #dcebfa; color: #1b5e91; } .ch .d.n { background: #f2f4f9; color: #c3c8d3; }
    .ch .d.lg { width: 30px; height: 30px; border-radius: 8px; font-size: 12.5px; margin-right: 4px; } .ch .d.lg.y { background: #1b5e91; color: #fff; }
    .ch button.d { cursor: pointer; }
    .ch .gap { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 12px; background: #fff7e6; border: 1px solid #f6d79a; font-size: 13.5px; color: #7a4a0c; flex-wrap: wrap; }
    .ch .gapb { font: inherit; font-size: 13px; font-weight: 600; border: 1px solid #f0c27a; background: #fff; color: #7a4a0c; border-radius: 999px; padding: 5px 12px; cursor: pointer; text-decoration: none; }
    .ch .gapb.on { background: #f59e0b; border-color: #f59e0b; color: #fff; }
    .ch .lbl { font-size: 12px; font-weight: 600; color: #5f6676; } .dark .ch .lbl { color: #94a3b8; }
    .ch .muted { color: #5f6676; } .dark .ch .muted { color: #94a3b8; } .ch .faint { color: #8a90a0; }
    .ch .sec { background: #fff; border: 1px solid #e7e9f0; border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
    .dark .ch .sec { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
    .ch .sech { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; } .ch .sech h2 { margin: 0; font-size: 16px; font-weight: 700; }
    .ch .kv { display: grid; grid-template-columns: 150px 1fr; gap: 9px 14px; font-size: 14px; } .ch .kv > span:nth-child(odd) { color: #5f6676; font-size: 13px; }
    .ch .fld { display: flex; flex-direction: column; gap: 5px; }
    .ch .tick { display: inline-flex; width: 20px; height: 20px; border-radius: 6px; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
    .ch .tick.y { background: #e3f5ec; color: #15803d; } .ch .tick.n { background: #f2f4f9; color: #c3c8d3; } .ch .tick.x { background: #ffe4e6; color: #be123c; }
    .ch .mv { font: inherit; font-size: 11px; width: 20px; height: 20px; border: 1px solid #cfd3de; border-radius: 6px; background: #fff; cursor: pointer; padding: 0; color: #5f6676; } .ch .mv:hover { background: #f2f4f9; }
    .ch .mi { font: inherit; font-size: 13.5px; text-align: left; padding: 9px 12px; border: 0; background: transparent; border-radius: 8px; cursor: pointer; color: #1c2130; width: 100%; display: block; text-decoration: none; }
    .ch .mi:hover { background: #f2f4f9; } .dark .ch .mi { color: #e2e8f0; } .dark .ch .mi:hover { background: rgb(255 255 255 / .06); }
    .ch .menu { position: absolute; right: 0; top: calc(100% + 6px); z-index: 25; background: #fff; border: 1px solid #e7e9f0; border-radius: 12px; box-shadow: 0 10px 28px rgba(28,33,48,.16); padding: 6px; min-width: 220px; display: flex; flex-direction: column; }
    .dark .ch .menu { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
    .ch .anchor { font: inherit; font-size: 13.5px; font-weight: 600; padding: 10px 2px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #5f6676; cursor: pointer; }
    .ch .anchor.on { color: #1b5e91; border-bottom-color: #1b5e91; }
    .ch .fchip { font: inherit; font-size: 12.5px; font-weight: 600; padding: 5px 11px; border-radius: 999px; border: 1px solid #e1e4ec; background: #fff; cursor: pointer; color: #1c2130; }
    .ch .fchip.on { background: #1c2130; border-color: #1c2130; color: #fff; }
    .dark .ch .fchip { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); } .dark .ch .fchip.on { background: #fff; color: #1c2130; }
    .ch .cal { display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; }
    .ch .cd { border-radius: 8px; padding: 6px 4px; text-align: center; font-size: 12px; font-weight: 600; min-height: 34px; display: flex; flex-direction: column; justify-content: center; }
    .ch .c-in { background: #e3f5ec; color: #15803d; } .ch .c-absent { background: #ffe4e6; color: #be123c; } .ch .c-planned { background: #f7f9fd; color: #8a90a0; border: 1px dashed #cfd3de; } .ch .c-today { background: #dcebfa; color: #1b5e91; box-shadow: inset 0 0 0 2px #1b5e91; } .ch .c-closed { background: #f2f4f9; color: #b5bac6; } .ch .c-none { color: #c3c8d3; }
    .ch .panel { background: #f7f9fd; border: 1px solid #dcebfa; border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; }
    .dark .ch .panel { background: rgb(30 41 59 / .6); border-color: rgb(255 255 255 / .1); }
    .ch .sw-bar { height: 6px; border-radius: 999px; background: #eef0f5; overflow: hidden; display: inline-block; width: 90px; }
    .ch .thumb-note { font-size: 12px; color: #8a90a0; }
    .ch .tl { position: relative; padding-left: 18px; display: flex; flex-direction: column; }
    .ch .tl::before { content: ""; position: absolute; left: 5px; top: 8px; bottom: 8px; width: 2px; background: #e7e9f0; }
    .ch .tl .dot { position: absolute; left: -17px; top: 12px; width: 10px; height: 10px; border-radius: 50%; border: 2px solid #fff; background: #9aa6c4; }
    .ch .tl .dot.ok { background: #15803d; } .ch .tl .dot.bad { background: #be123c; } .ch .tl .dot.dss { background: #7c3aed; }
</style>
