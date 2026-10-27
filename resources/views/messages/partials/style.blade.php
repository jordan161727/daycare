{{-- The client's Messages styles (Messages.dc.html) on top of the shared .ch
     set from children/partials/mock-style: the thread rows, the kind tags,
     the bubbles, the ✨ Polished card, the recipients table and the media
     thumbs. Values from docs/work.md. --}}
<style>
    .ch .msg-wrap { display: flex; flex-wrap: wrap; gap: 16px; align-items: stretch; }
    .ch .msg-list { flex: 1 1 320px; min-width: 0; padding: 12px; display: flex; flex-direction: column; gap: 6px; }
    .ch .msg-main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
    .ch .msg-new { flex: 999 1 520px; min-width: 0; padding: 22px; display: flex; flex-direction: column; gap: 18px; }
    .ch .tab { font: inherit; font-size: 13px; font-weight: 600; padding: 7px 12px; border-radius: 999px; border: 0; background: transparent; color: #5f6676; cursor: pointer; min-height: 34px; }
    .ch .tab.on { background: #1c2130; color: #fff; }
    .dark .ch .tab { color: #cbd5e1; } .dark .ch .tab.on { background: #fff; color: #1c2130; }
    .ch .thr { font: inherit; display: flex; gap: 10px; width: 100%; text-align: left; padding: 12px; border: 0; border-radius: 12px; background: transparent; cursor: pointer; color: #1c2130; min-height: 56px; }
    .ch .thr:hover { background: #f2f4f9; }
    .ch .thr.on { background: #f1f6fd; box-shadow: inset 0 0 0 1px #b9d6f5; }
    .dark .ch .thr { color: #e2e8f0; } .dark .ch .thr:hover { background: rgb(255 255 255 / .06); } .dark .ch .thr.on { background: rgb(27 94 145 / .25); }
    .ch .thr .nm { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ch .thr .lt { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ch .thr.unread .nm, .ch .thr.unread .lt { font-weight: 700; }
    .ch .av.msg { width: 36px; height: 36px; font-size: 13px; }
    .ch .kind { display: inline-flex; align-items: center; font-size: 11px; font-weight: 700; border-radius: 6px; padding: 2px 7px; white-space: nowrap; }
    .ch .kind.room { background: #e3f5ec; color: #166534; } .ch .kind.office { background: #ede4fb; color: #5b21b6; } .ch .kind.ann { background: #1c2130; color: #fff; }
    .ch .kind.lg { font-size: 12px; padding: 4px 10px; }
    .ch .see { padding: 9px 20px; font-size: 13px; display: flex; gap: 8px; align-items: center; }
    .ch .see.room { background: #eef8f1; color: #166534; } .ch .see.office { background: #f3ecfd; color: #5b21b6; } .ch .see.ann { background: #f2f4f9; color: #465577; }
    .ch .feed { flex: 1; padding: 20px; display: flex; flex-direction: column; gap: 12px; background: #fbfcfe; min-height: 400px; max-height: 60vh; overflow-y: auto; }
    .dark .ch .feed { background: rgb(255 255 255 / .03); }
    .ch .bubble { max-width: 78%; border-radius: 16px 16px 16px 4px; padding: 10px 14px; font-size: 14px; line-height: 1.45; background: #e9ecf3; color: #1c2130; white-space: pre-wrap; word-break: break-word; }
    .ch .bubble.out { background: #2f6fb0; color: #fff; border-radius: 16px 16px 4px 16px; }
    .ch .meta { font-size: 12px; color: #8a90a0; }
    .ch .chip.on { background: #1b5e91; border-color: #1b5e91; color: #fff; }
    .ch .chip.sm { font-size: 12px; padding: 6px 11px; min-height: 32px; }
    .ch .lblu { font-size: 12px; font-weight: 600; color: #5f6676; text-transform: uppercase; letter-spacing: .06em; }
    .ch .opt { font: inherit; text-align: left; cursor: pointer; color: #1c2130; background: #fff; border: 2px solid #e3e6ee; border-radius: 14px; padding: 12px 14px; display: flex; flex-direction: column; gap: 5px; font-size: 14px; }
    .ch .opt span:last-child { font-size: 12.5px; color: #5f6676; }
    .ch .opt .kind { align-self: flex-start; }
    .ch .opt.on { border-color: #1b5e91; background: #f5f9fe; }
    .dark .ch .opt { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); }
    .ch .polb { border-color: #c4b5fd; color: #6d28d9; background: #faf7ff; }
    .ch .pol { background: #faf7ff; border: 1px solid #ddd0fb; border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
    .ch .rchead { font: inherit; width: 100%; display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 11px 14px; border: 0; background: #f7f9fd; cursor: pointer; color: #1c2130; }
    .ch .rt { width: 100%; border-collapse: collapse; font-size: 13px; }
    .ch .rt th { position: sticky; top: 0; background: #fff; font-size: 11px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #5f6676; text-align: left; padding: 7px 10px; border-bottom: 1px solid #e7e9f0; }
    .ch .rt td { padding: 7px 10px; border-bottom: 1px solid #f3f4f8; vertical-align: top; }
    .ch .rt input { width: 16px; height: 16px; accent-color: #1b5e91; }
    .ch .thumb { position: relative; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; flex: none; background: linear-gradient(135deg, #fbd786, #f7797d); }
    .ch .thumb.video { background: linear-gradient(135deg, #84d0c2, #5a8bd6); }
    .ch .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .ch .thumb .play { position: absolute; width: 38px; height: 38px; border-radius: 50%; background: rgba(0,0,0,.45); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .ch .thumb .cap { position: absolute; left: 6px; bottom: 5px; font-size: 11px; font-weight: 600; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.5); }
    .ch .thumb .rm { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: rgba(0,0,0,.55); color: #fff; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; }
    .ch .inp.ta { border-radius: 12px; min-height: 44px; padding: 10px 12px; resize: none; }
    .ch .famrow { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; background: #f7f9fd; border: 1px solid #b9d6f5; border-radius: 10px; flex-wrap: wrap; }
    .ch .pick { font: inherit; width: 100%; text-align: left; padding: 8px 10px; border: 0; border-radius: 8px; background: transparent; cursor: pointer; color: #1c2130; display: flex; flex-direction: column; gap: 2px; }
    .ch .pick:hover { background: #f2f4f9; }
</style>
