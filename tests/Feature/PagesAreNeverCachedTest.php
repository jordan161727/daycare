<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * No page is kept by the browser or by the host's cache.
 *
 * On the live server a corrected child — new photograph, new name — went on
 * showing as before on the roster and the attendance board, while a private
 * window showed the correction at once. The host runs LiteSpeed, whose cache
 * reads its own header; every response now carries it, and tells the browser
 * to keep nothing as well.
 */
class PagesAreNeverCachedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_tells_every_cache_to_keep_nothing(): void
    {
        $this->child();

        foreach (['children.index', 'attendance.index', 'dashboard'] as $page) {
            $response = $this->actingAs($this->admin())->get(route($page))->assertOk();

            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), "$page may be kept by the browser");
            $this->assertTrue($response->headers->hasCacheControlDirective('private'), "$page may be kept by a shared cache");
            $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'), "$page may be kept by LiteSpeed");
            $this->assertSame('0', $response->headers->get('X-Accel-Expires'), "$page may be kept by Nginx");
        }
    }

    public function test_the_login_page_is_covered_too(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-cache', $response->headers->get('X-LiteSpeed-Cache-Control'));
    }

    public function test_a_response_that_chose_its_own_lifetime_keeps_it(): void
    {
        Storage::fake('local');
        $child = $this->child();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('children.update', $child), [
            'first_name' => $child->first_name, 'last_name' => $child->last_name, 'status' => 'Active',
            'photo' => UploadedFile::fake()->image('ada.jpg'),
        ]);

        // The photograph lives at an address stamped with the file, so an
        // hour in the browser is safe and is left as the controller set it.
        $photo = $this->actingAs($admin)->get($child->fresh()->photoUrl())->assertOk();
        $this->assertSame('3600', $photo->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($photo->headers->hasCacheControlDirective('no-store'));
        // Still never in the host's cache: it is a photograph of a child.
        $this->assertSame('no-cache', $photo->headers->get('X-LiteSpeed-Cache-Control'));

        // The export already asked for no-store on its own terms.
        $export = $this->actingAs($admin)->get(route('children.export'))->assertOk();
        $this->assertTrue($export->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-cache', $export->headers->get('Pragma'));
    }

    private function child(): Child
    {
        return Child::create([
            'lan' => '1001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'Active',
            'classroom' => 'Infant',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
