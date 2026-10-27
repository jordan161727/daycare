{{-- The client's Staff attendance styles (Main.dc.html) on top of the shared
     .ch set from children/partials/mock-style: the Live / History switch,
     the stat cards, the day cells on the week grid and their five colours,
     and the inputs on the Correct a day panel. Values from docs/work.md. --}}
<style>
    .ch .live-wrap { display: flex; flex-direction: column; gap: 20px; }
    .ch .hist-wrap { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .ch .vt { font: inherit; font-size: 14px; font-weight: 600; padding: 9px 18px; border-radius: 999px; border: 0; background: transparent; color: #5f6676; cursor: pointer; min-height: 40px; }
    .ch .vt.on { background: #1c2130; color: #fff; }
    .dark .ch .vt { color: #cbd5e1; } .dark .ch .vt.on { background: #fff; color: #1c2130; }
    .ch .vtwrap { display: flex; gap: 4px; background: #fff; border: 1px solid #e7e9f0; border-radius: 999px; padding: 4px; }
    .dark .ch .vtwrap { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }

    .ch .stat-card { padding: 16px 18px; display: flex; flex-direction: column; gap: 6px; }
    .ch .stat-card .l { font-size: 13px; color: #5f6676; } .dark .ch .stat-card .l { color: #94a3b8; }
    .ch .stat-card .n { font-size: 28px; font-weight: 700; line-height: 1.1; }
    .ch .stat-card .n small { font-size: 14px; font-weight: 500; color: #5f6676; }

    .ch .pill.filter { cursor: pointer; border: 0; font: inherit; font-size: 12px; font-weight: 600; }
    .ch .pill.filter.on { box-shadow: 0 0 0 2px #1c2130; } .dark .ch .pill.filter.on { box-shadow: 0 0 0 2px #fff; }

    .ch .tbl.att th, .ch .tbl.att td { padding: 10px 14px; }
    .ch .tbl.att td.day { padding: 6px 4px; }
    .ch .tbl.att td.day.today { background: #f7f9fd; } .dark .ch .tbl.att td.day.today { background: rgb(255 255 255 / .03); }

    /* One day of one person. A link where there is something to open, a
       plain span where there is nothing to correct. */
    .ch .cell { font: inherit; width: 100%; min-width: 96px; text-align: left; display: flex; flex-direction: column; gap: 2px; padding: 8px 8px; border-radius: 10px; border: 1px solid #e7e9f0; background: #fff; cursor: pointer; color: #1c2130; min-height: 52px; box-sizing: border-box; text-decoration: none; }
    .ch a.cell:hover { border-color: #5b8fd6; color: #1c2130; }
    .ch .cell.sel { box-shadow: 0 0 0 3px #b9d6f5; border-color: #1b5e91; }
    .ch .cell .t { font-size: 12.5px; font-weight: 600; white-space: nowrap; }
    .ch .cell .s { font-size: 12px; color: #5f6676; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ch .cell.c-bad { background: #fff7f8; border-color: #f9c2cb; } .ch .cell.c-bad .s { color: #be123c; }
    .ch .cell.c-warn { background: #fffbeb; border-color: #f3d27a; } .ch .cell.c-warn .s { color: #b45309; }
    .ch .cell.c-edit { background: #f1f6fd; border-color: #b9d6f5; } .ch .cell.c-edit .s { color: #1b5e91; }
    .ch .cell.c-now { background: #e3f5ec; border-color: #bfe6cf; } .ch .cell.c-now .s { color: #15803d; }
    .ch .cell.c-wait { background: #fff; border-style: dashed; border-color: #cfd3de; color: #5f6676; }
    .ch .cell.c-off { background: #f6f7fa; color: #8a90a0; cursor: default; } .ch .cell.c-off .s { color: #8a90a0; }
    .ch span.cell { cursor: default; }
    .dark .ch .cell { background: rgb(30 41 59); border-color: rgb(255 255 255 / .12); color: #e2e8f0; }
    .dark .ch .cell .s { color: #94a3b8; }
    .dark .ch .cell.c-bad { background: rgb(190 18 60 / .18); border-color: rgb(190 18 60 / .5); } .dark .ch .cell.c-bad .s { color: #fda4af; }
    .dark .ch .cell.c-warn { background: rgb(180 83 9 / .18); border-color: rgb(180 83 9 / .5); } .dark .ch .cell.c-warn .s { color: #fcd34d; }
    .dark .ch .cell.c-edit { background: rgb(27 94 145 / .25); border-color: rgb(27 94 145 / .6); } .dark .ch .cell.c-edit .s { color: #93c5fd; }
    .dark .ch .cell.c-now { background: rgb(21 128 61 / .2); border-color: rgb(21 128 61 / .5); } .dark .ch .cell.c-now .s { color: #86efac; }
    .dark .ch .cell.c-off { background: rgb(255 255 255 / .04); color: #64748b; } .dark .ch .cell.c-off .s { color: #64748b; }

    .ch .recorded { background: #f7f9fd; border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
    .dark .ch .recorded { background: rgb(255 255 255 / .05); }
    .ch .inp.att { border-radius: 10px; min-height: 40px; padding: 9px 12px; }
    .ch .flash { padding: 10px 12px; border-radius: 10px; font-size: 13px; }
    .ch .flash.ok { background: #e3f5ec; color: #15803d; } .ch .flash.bad { background: #ffe4e6; color: #be123c; }
    .ch .logline { font-size: 13px; line-height: 1.45; }
    .ch .logline b { font-weight: 600; }
</style>
