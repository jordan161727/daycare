<?php

/*
 * The three signed-out screens: the parents' door (/kiosk), the staff time
 * clock (/clock) and the room tablets (/room).
 *
 * The lengths used to be written into each screen and each controller as a
 * bare 4 or 6 — the dots, the auto-submit, the validation rule, the "Key
 * your 4-digit PIN" line — so changing one meant finding all of them, and
 * missing one meant a keypad that submitted at four digits against a server
 * that wanted five (QA K1, 16 Nov 2026). Every one of those now reads here.
 *
 * Changing a length does not change anybody's existing PIN. A PIN is stored
 * only as a hash, so a centre moving staff from four digits to five has to
 * set everybody a new one — which is what the staff profile's "Set PIN" is.
 */

return [

    /*
     * Every PIN, staff and parent alike: the parents' door, the time clock,
     * the room tablets, the director's PIN at a tablet's gear, and the
     * length the staff profile sets (QA K1, 16 Nov 2026).
     *
     * Six by default. Four digits with about twenty staff could be found by
     * trying numbers at a tablet in minutes; six cannot. And one PIN is one
     * person across staff and parents (QA K2) — see App\Rules\UniqueKioskPin
     * — which only means something while both are the same length.
     */
    'pin_length' => (int) env('KIOSK_PIN_LENGTH', 6),

    /*
     * The room code behind the room tablet's gear, set in Settings → Rooms &
     * hours. Shorter than a PIN on purpose, so a code keyed at the door can
     * never be somebody's PIN; if the two lengths are ever set equal, the
     * Rooms & hours page refuses a code that is also a PIN. Each room's code
     * is unique, by validation and by the room_tablet_codes table's index.
     */
    'room_code_length' => (int) env('KIOSK_ROOM_CODE_LENGTH', 4),

    /*
     * A half-typed PIN or code is wiped after this long with no key pressed
     * (QA K3, 16 Nov 2026), so the next person at the screen does not start
     * from somebody else's first three digits. Every press restarts it.
     */
    'idle_clear_seconds' => (int) env('KIOSK_IDLE_CLEAR_SECONDS', 15),

    /*
     * Re-pairing a room tablet (QA K4, 16 Nov 2026): how long an
     * administrator's PIN at the gear holds the room-code pad open, and how
     * many tries the gear allows in its window before it says wait.
     */
    'setup_token_seconds' => (int) env('KIOSK_SETUP_TOKEN_SECONDS', 120),

    'setup_max_attempts' => 5,

    'setup_decay_minutes' => 10,

];
