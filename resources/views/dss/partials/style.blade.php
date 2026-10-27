{{-- The client's DSS attendance styles (Ray.dc.html) on top of the shared
     .ch set: the sticky toolbar, the grid with its sticky first column and
     header, the cell colours, the week-total columns and the export card.
     Values from docs/work.md. --}}
<style>
    .ch .btn.s { padding: 5px 9px; min-height: 32px; font-size: 12.5px; border-radius: 9px; }
    .ch .btn.dark { background: #1c2130; border-color: #1c2130; color: #fff; }
    .ch .btn.red { color: #be123c; }
    .ch .grp { display: flex; align-items: center; gap: 4px; padding: 3px 6px; border-radius: 10px; flex: none; }
    .ch .gl { font-size: 10.5px; font-weight: 700; }
    .ch .inp.t { width: 58px; min-height: 30px; padding: 4px 5px; font-size: 12.5px; }
    .ch .sep { width: 1px; height: 22px; background: #e7e9f0; flex: none; }
    .ch .chip.cl { padding: 5px 11px; min-height: 32px; }
    .ch .vio { background: #ede9fe; color: #5b21b6; }
    .ch .bar { position: sticky; top: 0; z-index: 5; }
    .ch .gridwrap { overflow: auto; max-height: min(72vh, 820px); outline: none; }
    .ch .grid { display: table; /* Tailwind's .grid utility would turn the table into a CSS grid and split the header from the body (client, 28 Oct 2026) */ border-collapse: separate; border-spacing: 0; font-size: 12px; }
    .ch .grid th { position: sticky; top: 0; z-index: 2; background: #fff; font-size: 10.5px; font-weight: 700; color: #5f6676; padding: 6px 2px; border-bottom: 1px solid #e7e9f0; text-align: center; white-space: nowrap; cursor: pointer; letter-spacing: 0; text-transform: none; }
    .ch .grid th.today { background: #d8ecfb; color: #1b5e91; }
    .ch .grid th.closed { color: #b5bac6; cursor: default; }
    .ch .grid td { border-bottom: 1px solid #f0f1f5; border-right: 1px solid #f5f6f9; padding: 0; text-align: center; vertical-align: middle; white-space: nowrap; }
    .ch .grid .stk { position: sticky; left: 0; z-index: 1; background: #fff; text-align: left; padding: 0 10px; border-right: 1px solid #e7e9f0; }
    .ch .grid th.stk { z-index: 3; }
    .dark .ch .grid th, .dark .ch .grid .stk { background: rgb(15 23 42); }
    .ch .cell { display: flex; flex-direction: column; justify-content: center; align-items: center; width: 52px; min-height: 39px; height: 100%; padding: 3px 0; cursor: cell; font-size: 11px; line-height: 1.3; font-variant-numeric: tabular-nums; user-select: none; box-sizing: border-box; }
    .ch .c-in { background: #effbf4; color: #147a52; }
    .ch .c-bill { background: #fff8e6; color: #94600e; }
    .ch .c-plan { color: #a3a9b7; }
    .ch .c-open { background: #fff; color: #1b5e91; box-shadow: inset 0 0 0 1px #3aa7e0; }
    .ch .c-abs { background: #ffe9ec; color: #be123c; font-weight: 700; }
    .ch .c-gone { background: #f7f8fa; color: #c3c8d3; cursor: not-allowed; }
    .ch .c-none { color: #c3c8d3; }
    .ch .c-closed { background: repeating-linear-gradient(135deg, #f5f6f9 0 5px, #eceef3 5px 10px); color: #b5bac6; cursor: default; }
    .ch .cell .pm { border-top: 1px dashed rgba(0,0,0,.12); padding-top: 1px; margin-top: 1px; }
    .ch .cell.sel { box-shadow: inset 0 0 0 2px #1b5e91; background-color: #dcebfa !important; }
    .ch .cell.anc { box-shadow: inset 0 0 0 2px #1b5e91, inset 0 0 0 4px #fff; }
    .ch .cell.fut { opacity: .8; }
    .ch .grid th.wk { background: #f4f1fd; color: #5b21b6; cursor: default; border-left: 2px solid #e4dcfb; border-right: 2px solid #e4dcfb; }
    .ch .grid td.wk { background: #faf8ff; border-left: 2px solid #e4dcfb; border-right: 2px solid #e4dcfb; }
    .ch .wkc { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 62px; min-height: 39px; font-size: 11px; line-height: 1.25; font-variant-numeric: tabular-nums; }
    .ch .wkc b { font-size: 13px; } .ch .wkc span { color: #8a90a0; font-size: 10.5px; }
    .ch .wkc.over { background: #ffe4e6; color: #be123c; } .ch .wkc.over span { color: #be123c; }
    .ch .wkc.risk { background: #fff7e6; color: #92400e; } .ch .wkc.risk span { color: #92400e; }
    .ch .wkc.okc { color: #15803d; }
    .ch .wkc.proj { color: #8a90a0; }
    .ch pre.csv { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; line-height: 1.5; background: #0f172a; color: #e2e8f0; padding: 12px 14px; border-radius: 10px; overflow: auto; max-height: 260px; white-space: pre; }
    .ch .kbd { display: inline-block; font-family: ui-monospace, Menlo, monospace; font-size: 11px; padding: 1px 6px; border: 1px solid #cfd3de; border-bottom-width: 2px; border-radius: 5px; background: #fff; color: #1c2130; }
    .ch .exportcard { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; border-color: #b9d6f5; box-shadow: 0 0 0 3px #dcebfa; }
</style>
