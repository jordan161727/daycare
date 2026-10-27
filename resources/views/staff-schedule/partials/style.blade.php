{{-- The client's Schedule styles (Schedule.dc.html) on top of the shared .ch
     set from children/partials/mock-style: the segmented switches, the room
     blocks with their lanes and bars, the coverage strip, the tray of people
     not working, and the pending-change card. Values from docs/work.md. --}}
<style>
    .ch .sch-wrap { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; }
    .ch .sch-main { flex: 999 1 720px; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
    .ch .sch-side { flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
    .ch .vseg { font: inherit; font-size: 13px; font-weight: 600; padding: 8px 14px; border: 1px solid #cfd3de; background: #fff; color: #5f6676; cursor: pointer; min-height: 38px; text-decoration: none; display: inline-flex; align-items: center; }
    .ch .vseg.on { background: #1b5e91; border-color: #1b5e91; color: #fff; }
    .ch .vseg:first-child { border-radius: 10px 0 0 10px; } .ch .vseg:last-child { border-radius: 0 10px 10px 0; margin-left: -1px; }
    .dark .ch .vseg { background: rgb(30 41 59); color: #cbd5e1; border-color: rgb(255 255 255 / .15); }
    .ch .chip.day.on { background: #dcebfa; border-color: #1b5e91; color: #1b5e91; }
    .ch .gapdot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #f43f5e; margin-left: 7px; vertical-align: middle; }
    .ch .dot { width: 12px; height: 12px; border-radius: 4px; border: 1px solid; display: inline-block; box-sizing: border-box; }

    .ch .rblock { border: 1px solid #e7e9f0; border-radius: 14px; overflow: hidden; background: #fff; }
    .ch .rblock.drop { box-shadow: 0 0 0 3px #b9d6f5; }
    .dark .ch .rblock { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
    .ch .rhead { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 14px; background: #f7f9fd; border-bottom: 1px solid #e7e9f0; }
    .dark .ch .rhead { background: rgb(255 255 255 / .04); border-color: rgb(255 255 255 / .1); }
    .ch .lane { display: flex; align-items: center; gap: 10px; padding: 3px 14px; }
    .ch .lname { width: 112px; flex: none; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ch .track { position: relative; flex: 1; height: 26px; border-radius: 6px; background: repeating-linear-gradient(90deg, transparent 0, transparent calc(100%/11 - 1px), #eef0f5 calc(100%/11 - 1px), #eef0f5 calc(100%/11)); }
    .dark .ch .track { background: repeating-linear-gradient(90deg, transparent 0, transparent calc(100%/11 - 1px), rgb(255 255 255 / .08) calc(100%/11 - 1px), rgb(255 255 255 / .08) calc(100%/11)); }
    .ch .bar { font: inherit; position: absolute; top: 2px; bottom: 2px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 18px; padding: 0 9px; white-space: nowrap; overflow: hidden; border: 1px solid; box-sizing: border-box; cursor: grab; text-align: left; }
    .ch .bar.still { cursor: default; }
    .ch .bar.float { border-style: dotted; border-width: 2px; }
    .ch .bar.sel { box-shadow: 0 0 0 3px #1b5e91; }
    .ch .bar.conf { box-shadow: 0 0 0 2px #be123c; background-image: repeating-linear-gradient(135deg, rgba(190,18,60,.12) 0 6px, transparent 6px 12px) !important; }
    .ch .offb { position: absolute; top: 2px; bottom: 2px; border-radius: 6px; background: repeating-linear-gradient(135deg, #eef0f5 0 6px, #dde0e8 6px 12px); border: 1px solid #cfd3de; font-size: 10.5px; font-weight: 600; color: #5f6676; line-height: 18px; padding: 0 7px; white-space: nowrap; overflow: hidden; box-sizing: border-box; pointer-events: none; }
    .ch .covtrack { position: relative; flex: 1; height: 18px; border-radius: 5px; overflow: hidden; background: #f2f4f9; }
    .ch .cs { position: absolute; top: 0; bottom: 0; font-size: 10px; font-weight: 700; line-height: 18px; text-align: center; overflow: hidden; }
    .ch .cs-ok { background: #d9efe1; color: #15803d; } .ch .cs-bad { background: #be123c; color: #fff; } .ch .cs-over { background: #dcebfa; color: #1b5e91; } .ch .cs-none { background: #f2f4f9; color: #b5bac6; }
    .ch .axis { position: relative; flex: 1; height: 14px; font-size: 11px; color: #8a90a0; }
    .ch .axis span { position: absolute; transform: translateX(-50%); }

    .ch .tray { font: inherit; display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 12px; border: 1px dashed #cfd3de; background: #fff; cursor: grab; font-size: 13px; color: #1c2130; min-height: 40px; width: 100%; text-align: left; }
    .ch .tray.no { cursor: not-allowed; background: #f6f7fa; color: #8a90a0; }
    .ch .tray.on { border-style: solid; border-color: #1b5e91; box-shadow: 0 0 0 2px #b9d6f5; }
    .dark .ch .tray { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .2); }
    .ch .tchip { font: inherit; font-size: 12.5px; font-weight: 600; padding: 6px 10px; border-radius: 9px; border: 1px solid #cfd3de; background: #fff; color: #1c2130; cursor: pointer; min-height: 32px; }
    .ch .tchip.on { border-color: #1b5e91; background: #f1f6fd; color: #1b5e91; }
    .ch .sinp { font: inherit; font-size: 13px; padding: 6px 8px; border: 1px solid #cfd3de; border-radius: 8px; background: #fff; min-height: 32px; color: #1c2130; }
    .dark .ch .sinp, .dark .ch .tchip { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); }

    .ch .pendcard { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; border-color: #5b8fd6; box-shadow: 0 0 0 3px #dcebfa; }
    .ch .confcard { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; border-color: #f3a7b3; box-shadow: 0 0 0 3px #ffe4e6; }
    .ch .covercard { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; border-color: #b9d6f5; }
    .ch .conf-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border-radius: 10px; background: #fff7f8; }
    .dark .ch .conf-row { background: rgb(190 18 60 / .12); }

    .ch .tbl.sched th { padding: 10px 12px; } .ch .tbl.sched td { padding: 8px 12px; white-space: normal; }
    .ch .tbl.sched td.daycell { min-width: 170px; }
    .ch .lines { display: flex; flex-direction: column; gap: 2px; }
    .ch .tbl.sched .track { height: 24px; }
    .ch .seg.sq { border-radius: 0; border: 1px solid #cfd3de; background: #fff; color: #1c2130; } .ch .seg.sq.on { background: #1c2130; border-color: #1c2130; color: #fff; }
</style>
