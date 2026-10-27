{{-- The client's Staff styles (Staff.dc.html) on top of the shared .ch set
     from children/partials/mock-style: the person rows down the left, the
     tabs, the type chips on the Time off form, the entry rows with their
     calendar leaf and availability bar. Values from docs/work.md. --}}
<style>
    .ch .srow { font: inherit; display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 9px 12px; border: 1px solid transparent; border-radius: 12px; background: transparent; cursor: pointer; color: #1c2130; min-height: 48px; text-decoration: none; }
    .ch .srow:hover { background: #f2f4f9; color: #1c2130; }
    .ch .srow.on { background: #f1f6fd; border-color: #b9d6f5; }
    .dark .ch .srow { color: #e2e8f0; } .dark .ch .srow:hover { background: rgb(255 255 255 / .06); color: #e2e8f0; } .dark .ch .srow.on { background: rgb(27 94 145 / .25); border-color: rgb(27 94 145 / .6); }
    .ch .ptab { font: inherit; font-size: 14px; font-weight: 600; padding: 12px 4px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #5f6676; cursor: pointer; min-height: 44px; text-decoration: none; display: inline-flex; align-items: center; }
    .ch .ptab.on { color: #1b5e91; border-bottom-color: #1b5e91; }
    .dark .ch .ptab { color: #94a3b8; } .dark .ch .ptab.on { color: #93c5fd; border-bottom-color: #93c5fd; }
    .ch .must { background: #1c2130; color: #fff; } .ch .pref { background: #dcebfa; color: #1b5e91; }
    .dark .ch .must { background: #fff; color: #1c2130; }
    .ch .tchip { font: inherit; font-size: 13px; font-weight: 600; padding: 8px 12px; border-radius: 10px; border: 1px solid #cfd3de; background: #fff; color: #1c2130; cursor: pointer; min-height: 38px; text-align: left; display: flex; flex-direction: column; gap: 1px; }
    .ch .tchip small { font-size: 11.5px; font-weight: 500; color: #5f6676; }
    .ch .tchip.on { border-color: #1b5e91; background: #f1f6fd; box-shadow: 0 0 0 2px #b9d6f5; }
    .dark .ch .tchip { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); } .dark .ch .tchip.on { background: rgb(27 94 145 / .3); }
    .ch .offrow { display: flex; align-items: flex-start; gap: 14px; padding: 12px 14px; border: 1px solid #e7e9f0; border-radius: 12px; }
    .dark .ch .offrow { border-color: rgb(255 255 255 / .1); }
    .ch .leaf { width: 52px; flex: none; border-radius: 10px; overflow: hidden; border: 1px solid #e7e9f0; text-align: center; }
    .ch .leaf b { display: block; background: #1b5e91; color: #fff; font-size: 10.5px; letter-spacing: .06em; padding: 2px 0; }
    .ch .leaf span { display: block; font-size: 19px; font-weight: 700; padding: 3px 0 4px; }
    .ch .hatch { background: repeating-linear-gradient(135deg, #eef0f5 0 6px, #dfe2ea 6px 12px); }
    .ch .stat { background: #f7f9fd; border-radius: 12px; padding: 12px 14px; } .ch .stat .n { font-size: 24px; font-weight: 700; } .ch .stat .l { font-size: 12px; color: #5f6676; }
    .dark .ch .stat { background: rgb(255 255 255 / .05); }
    .ch .seg.sq { border-radius: 0; border: 1px solid #cfd3de; background: #fff; color: #1c2130; } .ch .seg.sq.on { background: #1c2130; border-color: #1c2130; color: #fff; }
    .ch .seg.sq:first-child { border-radius: 10px 0 0 10px; } .ch .seg.sq:last-child { border-radius: 0 10px 10px 0; }
    .ch .dashed { border: 1px dashed #cfd3de; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 12px; background: #fbfcfe; }
    .dark .ch .dashed { background: rgb(255 255 255 / .03); border-color: rgb(255 255 255 / .2); }
    .ch .grid-fit { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
    .ch .grid-fit.tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .ch .pay { background: #fbfcfe; } .dark .ch .pay { background: rgb(255 255 255 / .03); }
</style>
