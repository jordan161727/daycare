<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A screen staff punch at.
 *
 * Named for where it is rather than what it is, because that is what somebody
 * reads on a punch weeks later: "Front desk", not "iPad 3".
 */
class StaffDevice extends Model
{
    /** The front door: payroll punches. */
    public const KIND_CLOCK = 'clock';

    /** A classroom tablet: room check-ins, for the ratios. */
    public const KIND_ROOM = 'room';

    public const KINDS = [
        self::KIND_CLOCK => 'Front door clock',
        self::KIND_ROOM => 'Room tablet',
    ];

    protected $fillable = ['name', 'location', 'is_active', 'kind', 'classroom'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        // Readable by the app, unreadable in a copied database. See the
        // migration that added it for why this is not hashed.
        'token' => 'encrypted',
    ];

    /** Never serialised by accident; the pairing screen asks for it by name. */
    protected $hidden = ['token_hash', 'token'];

    public function punches(): HasMany
    {
        return $this->hasMany(TimePunch::class);
    }

    /**
     * Issue a pairing token.
     *
     * Kept as well as hashed: the hash is what a request is checked against,
     * and the encrypted copy is what lets an administrator see the link again
     * without re-pairing — which would stop whichever tablet still held the
     * old one.
     */
    public function issueToken(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'token_hash' => Hash::make($token),
            'token' => $token,
            'token_last4' => substr($token, -4),
        ])->save();

        return $token;
    }

    public function isRoomTablet(): bool
    {
        return $this->kind === self::KIND_ROOM;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS[self::KIND_CLOCK];
    }

    /** The page this kind of device lives on. */
    public function kioskRoute(): string
    {
        return $this->isRoomTablet() ? 'room.kiosk' : 'clock.kiosk';
    }

    /** The address this device is paired by, for showing and for a QR. */
    public function pairingUrl(): ?string
    {
        return blank($this->token)
            ? null
            : route($this->kioskRoute(), ['token' => $this->token]);
    }

    /** Tell a room tablet which room it is standing in. */
    public function setRoom(?string $room): void
    {
        $this->forceFill(['classroom' => $room])->save();
    }

    /** Room tablets currently set to a room, for Settings → Rooms & hours. */
    public static function tabletsIn(string $room)
    {
        return static::where('is_active', true)
            ->where('kind', self::KIND_ROOM)
            ->where('classroom', $room)
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * The one cookie both kinds of screen pair by.
     *
     * One name on purpose: a tablet paired at /clock and then opened at /room
     * is the same paired tablet, and the kind on the row says which page it
     * should be on. See StaffClockController::PAIRED_DAYS for why a cookie.
     */
    public const COOKIE = 'staff_clock_device';

    public const SESSION_KEY = 'staff_clock.device';

    /** Whether this is the device the token belongs to. */
    public function tokenMatches(string $token): bool
    {
        return filled($this->token_hash) && Hash::check($token, $this->token_hash);
    }

    /**
     * The device a token names, or null.
     *
     * Every active device is checked because the token carries no hint of which
     * row it belongs to — a centre has a handful of kiosks, not a thousand, and
     * an index keyed on the token would be the lookup table the hash exists to
     * avoid.
     */
    public static function forToken(?string $token): ?self
    {
        if (blank($token)) {
            return null;
        }

        return static::where('is_active', true)->get()
            ->first(fn (self $device) => $device->tokenMatches($token));
    }

    /** Note that it is still on the wall and still talking to us. */
    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->save();
    }
}
