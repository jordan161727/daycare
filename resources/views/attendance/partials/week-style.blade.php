{{-- The client's Director Attendance styles (DirectorAttendance.dc.html) on
     top of the shared .ch set: the day cells and their five states, the
     inline time box, the "fixed by hand" dot and the key. --}}
<style>
    .ch .inp.round { border-radius: 999px; min-height: 36px; padding: 8px 12px; font-size: 13px; }
    .ch .btn.sky { background: #e4eefa; border-color: #e4eefa; color: #1b5e91; }
    .ch .tbl.att th { padding: 10px 6px; }
    /* The LAN and Student headers are buttons: same look as the header, a hand and a lit arrow when in charge. */
    .ch .tbl.att th .sortb { font: inherit; color: inherit; text-transform: inherit; letter-spacing: inherit; background: none; border: 0; padding: 0; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
    .ch .tbl.att th .sortb span { color: #b5bac6; font-size: 11px; }
    .ch .tbl.att th .sortb.on span { color: #1b5e91; }
    .ch .tbl.att th .sortb:hover span { color: #1b5e91; }
    .ch .tbl.att th.day { text-align: center; }
    .ch .tbl.att th.today { background: #d8ecfb; color: #1b5e91; }
    .ch .tbl.att td { padding: 6px 6px; }
    .ch .tbl.att td.today { background: #eef7fe; }
    .dark .ch .tbl.att td.today { background: rgb(27 94 145 / .15); }
    .ch .cellw { position: relative; display: flex; align-items: stretch; width: 122px; margin: 0 auto; height: 30px; border-radius: 8px; box-sizing: border-box; }
    .ch .cellw .main { font: inherit; font-size: 11.5px; flex: 1; min-width: 0; border: 0; background: transparent; cursor: pointer; color: inherit; padding: 0 6px; display: flex; align-items: center; gap: 5px; justify-content: center; border-radius: 8px; white-space: nowrap; overflow: hidden; }
    .ch .cellw .main:disabled { cursor: default; }
    .ch .pen { font: inherit; font-size: 12px; width: 22px; flex: none; border: 0; border-left: 1px solid currentColor; background: transparent; cursor: pointer; color: inherit; opacity: .75; border-radius: 0 8px 8px 0; }
    .ch .pen:hover { opacity: 1; background: rgba(255,255,255,.6); }
    .ch .s-in { border: 1.5px dashed #6fd3a6; background: #effbf4; color: #147a52; }
    .ch .s-bill { border: 1.5px dashed #f0b84a; background: #fff8e6; color: #94600e; }
    .ch .s-exp { border: 1.5px dashed #3aa7e0; background: #fff; color: #5f6676; }
    .ch .s-abs { border: 1.5px solid #f3a7b3; background: #ffe9ec; color: #be123c; }
    .ch .s-none { border: 1.5px solid transparent; color: #b5bac6; }
    .ch .s-closed { border: 1.5px solid transparent; background: repeating-linear-gradient(135deg, #f5f6f9 0 5px, #eceef3 5px 10px); color: #8a90a0; }
    .ch .s-gone { border: 1.5px solid transparent; color: #c3c8d3; }
    .ch .dotm { width: 6px; height: 6px; border-radius: 50%; background: #c3c8d3; display: inline-block; }
    .ch .edt { position: absolute; top: -4px; right: -4px; width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; border: 2px solid #fff; }
    .ch .am { font-size: 10.5px; font-weight: 700; opacity: .8; }
    .ch .inl { display: flex; align-items: center; gap: 2px; width: 122px; height: 30px; margin: 0 auto; padding: 0 3px; border: 1.5px solid #1b5e91; border-radius: 8px; background: #fff; box-sizing: border-box; box-shadow: 0 0 0 3px #d8ecfb; }
    .ch .inl input { font: inherit; font-size: 11.5px; width: 44px; min-width: 0; border: 0; padding: 3px 2px; border-radius: 5px; background: #eef4fb; text-align: center; color: #1c2130; }
    .ch .inl input:focus { outline: 2px solid #3aa7e0; background: #fff; }
    .ch .inl .ok { font: inherit; font-size: 12px; font-weight: 700; border: 0; background: #1b5e91; color: #fff; border-radius: 5px; width: 20px; height: 22px; cursor: pointer; padding: 0; flex: none; }
    .ch .lg { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #5f6676; }
    .ch .sw { width: 22px; height: 14px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }
    .ch .av.sm { width: 30px; height: 30px; font-size: 11px; }
    .ch .linked { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 12px; background: #e4eefa; color: #1b5e91; font-size: 13px; }
    .ch .recent { position: absolute; right: 0; top: calc(100% + 6px); z-index: 25; background: #fff; border: 1px solid #e7e9f0; border-radius: 12px; box-shadow: 0 10px 28px rgba(28,33,48,.16); padding: 10px; min-width: 280px; display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
    .dark .ch .recent { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
</style>
