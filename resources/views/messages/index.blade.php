@extends('layouts.app')
@section('title', 'Messages')
@section('content')
{{--
    Messages — Phase 9 of docs/work.md, as Messages.dc.html draws it.

    The conversations down the left; on the right the open one, or a new
    message. Three kinds — Room, Office, Announcement — and who may read
    each is the whole difference between them (see MessageThread). Who gets
    a text is the child record's "Texts" tick, less the people the law or
    the record says to skip, and the page shows every one of those before
    anything is sent. See messagesPage() at the foot.
--}}
@include('children.partials.mock-style')
@include('messages.partials.style')
<x-kids-background />

@php
    $view = $startWith ? 'new' : ($current ? 'thread' : 'new');
@endphp

<div class="ch" x-data="messagesPage(@js([
        'threads' => $threads,
        'current' => $current,
        'view' => $view,
        'startWith' => $startWith,
        'isAdmin' => $isAdmin,
        'rooms' => $rooms,
        'lines' => $lines,
        'urls' => [
            'show' => url('/messages'), 'store' => route('messages.store'), 'recipients' => route('messages.recipients'),
            'search' => route('messages.search'), 'polish' => route('messages.polish'), 'upload' => route('messages.upload'),
        ],
    ]))" :data-view="view" data-view="{{ $view }}" style="display: flex; flex-direction: column; gap: 18px;">

    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;" class="muted">Families</span>
            <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Messages</h1>
            <span style="font-size: 14px;" class="muted">
                @if($isAdmin) Director · you see every conversation
                @else {{ auth()->user()->name }} · {{ implode(', ', $rooms) ?: 'no room' }} teacher · sees {{ implode(' and ', $rooms) ?: 'no' }} families only
                @endif
            </span>
        </div>
        <button type="button" class="btn pri" @click="openNew()">+ New message</button>
    </header>

    <div class="msg-wrap">
        {{-- ---- the list ---- --}}
        <section class="ch-card msg-list" data-thread-list>
            <input class="inp" placeholder="Search a parent or child" aria-label="Search messages" x-model.trim="search" style="border-radius: 12px; min-height: 44px;">
            <div style="display: flex; gap: 4px; flex-wrap: wrap; padding: 4px 0;" role="group" aria-label="Filter conversations">
                @php($tabs = $isAdmin ? ['all' => 'All', 'room' => 'Rooms', 'office' => 'Office', 'ann' => 'Announcements'] : ['all' => 'All', 'room' => (count($rooms) === 1 ? $rooms[0].' room' : 'Rooms'), 'ann' => 'Announcements'])
                @foreach($tabs as $key => $label)
                    <button type="button" class="tab" :class="tab === '{{ $key }}' ? 'on' : ''" @click="tab = '{{ $key }}'">{{ $label }}<span x-show="unread('{{ $key }}') > 0"> <span x-text="unread('{{ $key }}')"></span></span></button>
                @endforeach
            </div>
            <template x-for="t in shown()" :key="t.id">
                <button type="button" class="thr" :class="(t.unread ? 'unread ' : '') + (view === 'thread' && current && current.id === t.id ? 'on' : '')" @click="open(t.id)" :data-thread="t.id">
                    <span class="av msg" :style="avatar(t.kind)" x-text="t.ini"></span>
                    <span style="display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0;">
                        <span style="display: flex; justify-content: space-between; gap: 8px;"><span class="nm" x-text="t.name"></span><span style="font-size: 12px; white-space: nowrap;" class="faint" x-text="t.when"></span></span>
                        <span style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;"><span class="kind" :class="t.kind" x-text="t.kindLabel"></span></span>
                        <span class="lt" x-text="t.last"></span>
                    </span>
                </button>
            </template>
            <p x-show="shown().length === 0" style="padding: 14px; font-size: 13px;" class="muted">No conversations here yet. Start one with “New message”.</p>
        </section>

        {{-- ---- the open conversation ---- --}}
        <section x-show="view === 'thread' && current" x-cloak class="ch-card msg-main" data-thread>
            <template x-if="current">
                <div style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
                    <div style="padding: 16px 20px; border-bottom: 1px solid #e7e9f0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <span style="font-size: 17px; font-weight: 700;" x-text="current.name"></span>
                            <span style="font-size: 13px;" class="muted" x-text="current.sub"></span>
                        </div>
                        <span class="kind lg" :class="current.kind" x-text="current.kindLabel"></span>
                    </div>
                    <div class="see" :class="current.kind"><span aria-hidden="true">👁</span><span x-text="current.see"></span></div>
                    <div class="feed" x-ref="feed">
                        <template x-for="m in current.msgs" :key="m.id">
                            <div style="display: flex; flex-direction: column; gap: 4px;" :style="'align-items:' + (m.out ? 'flex-end' : 'flex-start')">
                                <div x-show="m.media.length" style="display: flex; gap: 6px; flex-wrap: wrap; max-width: 78%;" :style="'justify-content:' + (m.out ? 'flex-end' : 'flex-start')">
                                    <template x-for="(x, i) in m.media" :key="i">
                                        <a :href="x.url" target="_blank" rel="noopener" class="thumb" :class="x.kind" :style="x.kind === 'video' ? 'width:240px;height:136px' : 'width:170px;height:128px'">
                                            <img x-show="x.kind === 'photo' && x.url" :src="x.url" alt="">
                                            <span x-show="x.kind === 'video'" class="play">▶</span>
                                            <span class="cap" x-text="x.label"></span>
                                        </a>
                                    </template>
                                </div>
                                <div x-show="m.text" class="bubble" :class="m.out ? 'out' : ''" x-text="m.text"></div>
                                <span class="meta" x-text="m.meta"></span>
                            </div>
                        </template>
                    </div>

                    <div x-show="current.canReply" style="padding: 14px 16px; border-top: 1px solid #e7e9f0; display: flex; flex-direction: column; gap: 8px;" data-reply>
                        @include('messages.partials.attachments', ['which' => 'reply'])
                        <div x-show="pol.which === 'reply'" x-cloak class="pol">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap;"><span style="font-size: 13px; font-weight: 700; color: #6d28d9;">✨ Polished</span><span style="font-size: 12px; color: #6b5f8a;">Spelling, grammar and a friendly tone · your meaning stays the same</span></div>
                            <div style="font-size: 14px; line-height: 1.5;" x-text="pol.text"></div>
                            <div style="display: flex; gap: 8px;"><button type="button" class="btn sm pri" @click="usePolish()">Use this</button><button type="button" class="btn sm" @click="pol.which = null">Keep mine</button></div>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: flex-end;">
                            <textarea class="inp ta" rows="2" :placeholder="'Reply to ' + current.first + '…'" style="flex: 1;" aria-label="Reply" x-model="reply" @keydown.enter.meta="send()" @keydown.enter.ctrl="send()"></textarea>
                            <button type="button" class="btn pri" style="min-height: 44px;" :disabled="busy || (! reply.trim() && ! att.reply.length)" @click="send()" x-text="busy ? 'Sending…' : 'Send'"></button>
                        </div>
                        <p x-show="error" x-cloak style="margin: 0; font-size: 13px; color: #be123c;" x-text="error"></p>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <div style="display: flex; gap: 6px;"><button type="button" class="btn sm polb" :disabled="! reply.trim() || busy" @click="polish('reply')">✨ Polish</button><button type="button" class="btn sm" @click="pick('reply', 'photo')">📷 Photo</button><button type="button" class="btn sm" @click="pick('reply', 'video')">🎥 Video</button></div>
                            <span style="font-size: 12px;" class="muted" x-text="current.line"></span>
                        </div>
                    </div>
                    <div x-show="! current.canReply" style="padding: 14px 16px; border-top: 1px solid #e7e9f0; font-size: 13px;" class="muted" x-text="current.noReplyTxt"></div>
                </div>
            </template>
        </section>

        {{-- ---- a new message ---- --}}
        <section x-show="view === 'new'" x-cloak class="ch-card msg-new" data-new>
            <div style="display: flex; justify-content: space-between; align-items: center;"><span style="font-size: 18px; font-weight: 700;">New message</span><button type="button" class="btn" @click="closeNew()" x-show="current">Cancel</button></div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <span class="lblu">To</span>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;" role="group" aria-label="To">
                    <button type="button" class="chip" :class="nw.to === 'family' ? 'on' : ''" @click="setTo('family')" x-text="isAdmin ? 'A family' : 'A family in ' + roomsLabel"></button>
                    <button type="button" class="chip" :class="nw.to === 'room' ? 'on' : ''" @click="setTo('room')" x-text="isAdmin ? 'A whole room' : 'The whole ' + roomsLabel + ' room'"></button>
                    <button type="button" x-show="isAdmin" class="chip" :class="nw.to === 'everyone' ? 'on' : ''" @click="setTo('everyone')">Everyone</button>
                </div>

                {{-- A family: the one chosen, or the search to choose one. --}}
                <div x-show="nw.to === 'family'" style="display: flex; flex-direction: column; gap: 8px;">
                    <div x-show="fam" x-cloak class="famrow">
                        <div style="display: flex; flex-direction: column;">
                            <span style="font-weight: 600;"><span x-text="fam ? fam.child : ''"></span> · <span x-text="fam ? fam.room : ''"></span></span>
                            <span style="font-size: 12.5px;" class="muted">
                                <span x-show="fam && fam.phones">Texts go to <span x-text="fam ? fam.gets : ''"></span> · “Texts” ticked on the child’s record</span>
                                <span x-show="fam && ! fam.phones" style="color: #be123c;">Nobody on this child’s record can get texts — tick “Texts” on a contact first.</span>
                                <span x-show="fam && fam.skipped" class="faint"> · Skipped: <span x-text="fam ? fam.skipped : ''"></span></span>
                            </span>
                        </div>
                        <button type="button" class="btn" style="min-height: 32px; padding: 5px 10px;" @click="fam = null">Change</button>
                    </div>
                    <div x-show="! fam" style="display: flex; flex-direction: column; gap: 4px;">
                        <input class="inp" placeholder="Type a child’s or parent’s name" aria-label="Find a family" x-model.trim="famQuery" @input.debounce.250ms="searchFamilies()" @focus="searchFamilies()">
                        <div x-show="famResults.length" style="border: 1px solid #e3e6ee; border-radius: 12px; padding: 4px; display: flex; flex-direction: column; max-height: 240px; overflow: auto;">
                            <template x-for="c in famResults" :key="c.child_id">
                                <button type="button" class="pick" @click="fam = c; famResults = []; loadRecipients()">
                                    <span style="font-weight: 600;"><span x-text="c.child"></span> · <span x-text="c.room"></span></span>
                                    <span style="font-size: 12px;" class="muted" x-text="c.phones ? 'Texts: ' + c.gets : 'Nobody can get texts'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div x-show="nw.to === 'room' && isAdmin" style="display: flex; gap: 8px; flex-wrap: wrap;" role="group" aria-label="Room">
                    <template x-for="r in rooms" :key="r"><button type="button" class="chip sm" :class="nw.room === r ? 'on' : ''" @click="nw.room = r; excl = []; loadRecipients()" x-text="r"></button></template>
                </div>

                {{-- Who it reaches, for a room or everyone. --}}
                <div x-show="rc" x-cloak style="border: 1px solid #e3e6ee; border-radius: 12px; overflow: hidden;" data-recipients>
                    <button type="button" class="rchead" @click="rcOpen = ! rcOpen">
                        <span style="display: flex; flex-direction: column; gap: 2px; text-align: left;"><b style="font-size: 14px;" x-text="rcTitle()"></b><span style="font-size: 12.5px;" class="muted" x-text="rcSub()"></span></span>
                        <span style="font-size: 13px; font-weight: 600; color: #1b5e91;" x-text="rcOpen ? 'Hide list ▴' : 'See who ▾'"></span>
                    </button>
                    <div x-show="rcOpen" style="max-height: 300px; overflow: auto; border-top: 1px solid #e3e6ee;">
                        <table class="rt">
                            <thead><tr><th style="width: 30px;"></th><th x-text="nw.to === 'room' ? 'Child' : 'Room'"></th><th>Gets the text</th><th>Skipped</th></tr></thead>
                            <tbody>
                                <template x-for="r in (rc ? rc.rows : [])" :key="r.child_id || r.room">
                                    <tr :style="'opacity:' + (r.child_id && excl.includes(r.child_id) ? '.45' : '1')">
                                        <td><input x-show="r.child_id" type="checkbox" :checked="! excl.includes(r.child_id)" @change="toggle(r.child_id)" aria-label="Include"></td>
                                        <td style="font-weight: 600; white-space: nowrap;"><span x-text="r.child || r.room"></span><span x-show="r.no_photo && att.new.length" class="pill warn" style="font-size: 10.5px; padding: 1px 6px; margin-left: 6px;">No photo consent</span></td>
                                        <td x-text="r.child_id ? (excl.includes(r.child_id) ? '—' : r.gets) : r.families + ' families · ' + r.phones + ' phones'"></td>
                                        <td style="color: #b45309;" x-text="r.child_id ? r.skip : (r.skipped ? r.skipped + (r.skipped === 1 ? ' contact' : ' contacts') : '')"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div x-show="rcOpen" style="padding: 8px 12px; font-size: 12px; background: #fbfbfe; border-top: 1px solid #f0f1f5;" class="muted">Who gets texts is set per person on each child’s record (Family → “Texts” tick). <span x-text="nw.to === 'room' ? 'Untick a child to leave that family out of this one message.' : 'A parent with children in two rooms gets it once. Pick “A whole room” to leave out a single family.'"></span></div>
                </div>
            </div>

            <div x-show="isAdmin && nw.to === 'family'" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="lblu">Who can see this conversation</span>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <button type="button" class="opt" :class="nw.kind === 'room' ? 'on' : ''" @click="nw.kind = 'room'"><span class="kind room">Room</span><b>Teachers in the room + you</b><span>The child’s day, supplies, pick-up</span></button>
                    <button type="button" class="opt" :class="nw.kind === 'office' ? 'on' : ''" @click="nw.kind = 'office'"><span class="kind office">Office</span><b>Office only — teachers can’t see it</b><span>Tuition, DSS, enrollment, anything private</span></button>
                </div>
            </div>
            <div x-show="nw.to === 'everyone'" x-cloak style="display: flex; gap: 10px; align-items: center; padding: 10px 12px; background: #f2f4f9; border-radius: 10px; font-size: 13px;"><span class="kind ann">Announcement</span><span>One-way. If a parent replies, it lands in <b>Office</b>.</span></div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
                <span class="lblu">Message</span>
                <textarea class="inp ta" rows="5" style="resize: vertical; line-height: 1.5;" aria-label="Message" x-model="nw.text"></textarea>
                <div x-show="pol.which === 'new'" x-cloak class="pol">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap;"><span style="font-size: 13px; font-weight: 700; color: #6d28d9;">✨ Polished</span><span style="font-size: 12px; color: #6b5f8a;">Spelling, grammar and a friendly tone · your meaning stays the same</span></div>
                    <div style="font-size: 14px; line-height: 1.5;" x-text="pol.text"></div>
                    <div style="display: flex; gap: 8px;"><button type="button" class="btn sm pri" @click="usePolish()">Use this</button><button type="button" class="btn sm" @click="pol.which = null">Keep mine</button></div>
                </div>
                @include('messages.partials.attachments', ['which' => 'new'])
                <div style="display: flex; gap: 6px;"><button type="button" class="btn sm polb" :disabled="! nw.text.trim() || busy" @click="polish('new')">✨ Polish</button><button type="button" class="btn sm" @click="pick('new', 'photo')">📷 Photo</button><button type="button" class="btn sm" @click="pick('new', 'video')">🎥 Video</button></div>
                <div x-show="consentWarning()" x-cloak style="background: #fef3c7; color: #92400e; border-radius: 10px; padding: 9px 12px; font-size: 13px; line-height: 1.45;">⚠ <span x-text="consentWarning()"></span></div>
            </div>

            <p x-show="error" x-cloak style="margin: 0; font-size: 13px; color: #be123c;" x-text="error"></p>
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 6px; border-top: 1px solid #f0f1f5;">
                <span style="font-size: 13px;" class="muted" x-text="foot()"></span>
                <button type="button" class="btn pri" style="min-height: 44px; padding: 10px 22px;" :disabled="! canSend()" @click="sendNew()" x-text="busy ? 'Sending…' : sendLabel()"></button>
            </div>
        </section>
    </div>

    <input type="file" x-ref="file" accept="" style="display: none;" @change="upload($event)">

    <section class="ch-card" style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 22px; font-size: 13px; color: #465577; background: #fbfbfe;">
        <div style="display: flex; gap: 8px; align-items: baseline; flex: 1 1 260px;"><span class="kind room">Room</span><span>Teachers in that child’s room and you. Texts come from the <b>Classroom line</b> {{ $lines['classroom'] }}.</span></div>
        <div style="display: flex; gap: 8px; align-items: baseline; flex: 1 1 260px;"><span class="kind office">Office</span><span>You and office staff only. Texts come from the <b>Office line</b> {{ $lines['office'] }}.</span></div>
        <div style="display: flex; gap: 8px; align-items: baseline; flex: 1 1 260px;"><span class="kind ann">Announcement</span><span>You only, one-way. Opt-outs and court-restricted contacts are skipped automatically.</span></div>
    </section>
</div>

<script>
/*
 * The page. The list and the open conversation come from the server as
 * data; replying, sending, the recipients table, Polish and uploads all go
 * back as JSON, so nothing reloads mid-thought. Who may see what is the
 * server's decision (MessageThread::visibleTo); this only draws it.
 */
function messagesPage(opts) { return {
    threads: opts.threads,
    current: opts.current,
    view: opts.view,
    isAdmin: opts.isAdmin,
    rooms: opts.rooms,
    roomsLabel: opts.rooms.join(' / '),
    lines: opts.lines,
    urls: opts.urls,

    tab: 'all',
    search: '',
    reply: '',
    busy: false,
    error: '',
    pol: {which: null, text: ''},
    att: {reply: [], new: []},
    pickFor: null,
    pickKind: 'photo',

    nw: {to: 'family', room: opts.rooms[0] || null, kind: 'room', text: ''},
    fam: opts.startWith,
    famQuery: '',
    famResults: [],
    rc: null,
    rcOpen: false,
    excl: [],

    init() {
        this.$watch('current', () => this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; }));
        this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; });
    },

    /* ---- the list ---- */
    shown() {
        return this.threads.filter(t => (this.tab === 'all' || t.kind === this.tab) && (! this.search || t.search.includes(this.search.toLowerCase())));
    },
    unread(tab) {
        return this.threads.filter(t => t.unread && (tab === 'all' || t.kind === tab)).length;
    },
    avatar(kind) {
        return kind === 'ann' ? 'background:#1c2130;color:#fff' : kind === 'office' ? 'background:#ede4fb;color:#5b21b6' : 'background:#dcebfa;color:#1b5e91';
    },
    async open(id) {
        this.error = '';
        try {
            const response = await fetch(this.urls.show + '/' + id, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not open that conversation.');
            this.current = await response.json();
            this.view = 'thread';
            this.reply = '';
            this.att.reply = [];
            this.pol.which = null;
            const t = this.threads.find(x => x.id === id);
            if (t) t.unread = false;
            history.replaceState(null, '', '?thread=' + id);
        } catch (e) {
            this.error = e.message;
        }
    },

    /* ---- replying ---- */
    async send() {
        if (! this.current || this.busy) return;
        this.busy = true;
        this.error = '';
        try {
            const response = await window.postJson(this.urls.show + '/' + this.current.id + '/reply', {body: this.reply.trim() || null, media: this.att.reply});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not send.');
            this.current = data;
            this.reply = '';
            this.att.reply = [];
            this.pol.which = null;
            const t = this.threads.find(x => x.id === data.id);
            if (t) { t.last = data.msgs[data.msgs.length - 1].text || '📷 Picture message'; t.when = 'Now'; this.threads = [t, ...this.threads.filter(x => x.id !== t.id)]; }
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
        }
    },

    /* ---- a new message ---- */
    openNew() {
        this.view = 'new';
        this.error = '';
        this.pol.which = null;
        history.replaceState(null, '', location.pathname);
        if (this.nw.to !== 'family') this.loadRecipients();
    },
    closeNew() {
        this.view = 'thread';
    },
    setTo(to) {
        this.nw.to = to;
        this.excl = [];
        this.rc = null;
        this.pol.which = null;
        if (to === 'everyone') this.nw.kind = 'room';
        this.loadRecipients();
    },
    async loadRecipients() {
        if (this.nw.to === 'family') { this.rc = null; return; }
        const params = new URLSearchParams({to: this.nw.to});
        if (this.nw.to === 'room') params.set('room', this.nw.room || '');
        try {
            const response = await fetch(this.urls.recipients + '?' + params, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            this.rc = response.ok ? await response.json() : null;
        } catch (e) {
            this.rc = null;
        }
    },
    async searchFamilies() {
        try {
            const response = await fetch(this.urls.search + '?q=' + encodeURIComponent(this.famQuery), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            this.famResults = response.ok ? (await response.json()).children : [];
        } catch (e) {
            this.famResults = [];
        }
    },
    toggle(childId) {
        this.excl = this.excl.includes(childId) ? this.excl.filter(x => x !== childId) : [...this.excl, childId];
    },
    included() {
        return this.rc ? this.rc.rows.filter(r => ! r.child_id || ! this.excl.includes(r.child_id)) : [];
    },
    rcTitle() {
        const rows = this.included();
        if (! this.rc) return '';
        if (this.nw.to === 'room') {
            const fam = rows.filter(r => r.phones > 0).length;
            return fam + ' families · ' + rows.reduce((n, r) => n + r.phones, 0) + ' phones';
        }
        return rows.reduce((n, r) => n + r.families, 0) + ' families · ' + rows.reduce((n, r) => n + r.phones, 0) + ' phones';
    },
    rcSub() {
        if (! this.rc) return '';
        const rows = this.rc.rows;
        if (this.nw.to === 'room') {
            const out = this.excl.length;
            return this.nw.room + ' · ' + rows.length + ' children' + (out ? ' · ' + out + ' left out by you' : '') + ' · ' + this.included().reduce((n, r) => n + r.skipped, 0) + ' contacts skipped';
        }
        return 'Every active child · ' + rows.reduce((n, r) => n + r.skipped, 0) + ' contacts skipped (Texts off, STOP, restricted, no mobile)';
    },
    consentWarning() {
        if (! this.att.new.length) return '';
        if (this.nw.to === 'family') return this.fam && this.fam.no_photo ? this.fam.child + ' has no photo consent on file. Make sure they’re not in these photos or videos before you send.' : '';
        if (this.nw.to === 'room' && this.rc) {
            const kids = this.included().filter(r => r.no_photo).map(r => r.child);
            if (! kids.length) return '';
            return kids.join(' and ') + (kids.length === 1 ? ' has' : ' have') + ' no photo consent on file (marked in the list above). Make sure they’re not in these photos or videos before you send to the whole group.';
        }
        return 'Some children may have no photo consent on file. Make sure they’re not in these photos or videos before you send to everyone.';
    },
    foot() {
        if (this.nw.to === 'everyone') return 'From the Office line · STOP and restricted contacts skipped';
        if (this.nw.kind === 'office' && this.nw.to === 'family') return 'From the Office line · replies come back to Office';
        const room = this.nw.to === 'room' ? this.nw.room : (this.fam ? this.fam.room : this.roomsLabel);
        return 'From the Classroom line · replies go to ' + room + ' teachers and you';
    },
    sendLabel() {
        if (this.nw.to === 'family') return 'Send';
        return this.rc ? 'Send to ' + this.rcTitle().split(' · ')[0] : 'Send';
    },
    canSend() {
        if (this.busy) return false;
        if (! this.nw.text.trim() && ! this.att.new.length) return false;
        if (this.nw.to === 'family') return !! (this.fam && this.fam.phones);
        if (this.nw.to === 'room') return !! this.nw.room;
        return true;
    },
    async sendNew() {
        if (! this.canSend()) return;
        this.busy = true;
        this.error = '';
        try {
            const response = await window.postJson(this.urls.store, {
                to: this.nw.to, child_id: this.fam ? this.fam.child_id : null, room: this.nw.room, kind: this.nw.kind,
                body: this.nw.text.trim() || null, media: this.att.new, exclude: this.excl,
            });
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not send.');
            window.location.href = location.pathname + (data.thread_id ? '?thread=' + data.thread_id : '');
        } catch (e) {
            this.error = e.message;
            this.busy = false;
        }
    },

    /* ---- ✨ Polish ---- */
    async polish(which) {
        const text = which === 'reply' ? this.reply : this.nw.text;
        if (! text.trim() || this.busy) return;
        this.busy = true;
        try {
            const response = await window.postJson(this.urls.polish, {text});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(data.message || 'Could not polish that.');
            this.pol = {which, text: data.text};
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
        }
    },
    usePolish() {
        if (this.pol.which === 'reply') this.reply = this.pol.text; else this.nw.text = this.pol.text;
        this.pol.which = null;
    },

    /* ---- photos and videos ---- */
    pick(which, kind) {
        this.pickFor = which;
        this.pickKind = kind;
        this.$refs.file.accept = kind === 'video' ? 'video/*' : 'image/*';
        this.$refs.file.value = '';
        this.$refs.file.click();
    },
    async upload(event) {
        const file = event.target.files[0];
        if (! file || ! this.pickFor) return;
        const body = new FormData();
        body.append('file', file);
        this.busy = true;
        this.error = '';
        try {
            const response = await fetch(this.urls.upload, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': window.csrfToken()}});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not add that file.');
            this.att[this.pickFor].push(data);
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
        }
    },
    remove(which, i) {
        this.att[which].splice(i, 1);
    },
    attNote(which) {
        const items = this.att[which];
        if (! items.length) return '';
        return items.some(a => a.kind === 'video')
            ? 'Videos are too big for a text, so parents get a private link to watch it (expires in {{ (int) config('daycare.messages.video_link_days', 30) }} days). Photos arrive as picture messages.'
            : 'Photos arrive in the parent’s texts as picture messages.';
    },
}; }
</script>
@endsection
