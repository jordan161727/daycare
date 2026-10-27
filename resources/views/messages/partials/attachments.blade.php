{{-- The photos and videos waiting to go with a message — the reply's or the
     new message's, by $which — with the note on how each reaches a phone. --}}
<div x-show="att.{{ $which }}.length" x-cloak style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;" data-attachments="{{ $which }}">
    <template x-for="(a, i) in att.{{ $which }}" :key="a.path">
        <div class="thumb" :class="a.kind" style="width: 92px; height: 70px;">
            <img x-show="a.kind === 'photo'" :src="a.url" alt="">
            <span x-show="a.kind === 'video'" class="play" style="font-size: 14px; width: 28px; height: 28px;">▶</span>
            <span class="cap" x-text="a.size"></span>
            <button type="button" class="rm" @click="remove('{{ $which }}', i)" aria-label="Remove">×</button>
        </div>
    </template>
    <span style="font-size: 12px; max-width: 360px; line-height: 1.4;" class="muted" x-text="attNote('{{ $which }}')"></span>
</div>
