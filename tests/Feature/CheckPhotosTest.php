<?php

namespace Tests\Feature;

use App\Models\Child;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** photos:check — a photo on record whose file isn't on the server is named. */
class CheckPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_file_present_passes(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('children/mia.jpg', 'x');
        Child::create(['lan' => '10001', 'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-01-01', 'photo_path' => 'children/mia.jpg']);

        $this->artisan('photos:check')
            ->expectsOutputToContain('Every photo on record is on this server.')
            ->assertExitCode(0);
    }

    public function test_a_missing_file_is_named_and_fails(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('children/mia.jpg', 'x');
        Child::create(['lan' => '10001', 'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-01-01', 'photo_path' => 'children/mia.jpg']);
        $leo = Child::create(['lan' => '10002', 'first_name' => 'Leo', 'last_name' => 'Cole', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-01-01', 'photo_path' => 'children/leo.jpg']);

        $this->artisan('photos:check')
            ->expectsOutputToContain('Children: 1 file(s) missing')
            ->expectsOutputToContain("#{$leo->id} Leo Cole -> children/leo.jpg")
            ->assertExitCode(1);
    }
}
