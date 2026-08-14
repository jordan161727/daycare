<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded combined payroll PDF, split into a slip per employee.
 */
class PayrollBatch extends Model
{
    protected $fillable = [
        'period_label', 'original_filename', 'path', 'page_count', 'uploaded_by',
    ];

    public function slips(): HasMany
    {
        return $this->hasMany(PayrollSlip::class)->orderBy('position');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function disk()
    {
        return Storage::disk(config('daycare.payroll.disk'));
    }

    public function contents(): string
    {
        return $this->disk()->get($this->path);
    }

    public function absolutePath(): string
    {
        return $this->disk()->path($this->path);
    }

    /** How far through the run the director is. */
    public function sentCount(): int
    {
        return $this->slips->where('status', 'sent')->count();
    }

    /**
     * Drop the stored PDF along with the row.
     *
     * A payroll PDF is every employee's pay in one file, so leaving orphans on
     * disk after the record is gone is the one outcome worth writing code to
     * prevent.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $batch) {
            if ($batch->path && $batch->disk()->exists($batch->path)) {
                $batch->disk()->delete($batch->path);
            }
        });
    }
}
