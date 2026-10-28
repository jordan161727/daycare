<?php

namespace Tests\Feature\QaProbe;

use App\Models\Child;
use App\Models\ChildDocument;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * QA probes for the Children list, the child record header, documents and
 * form behaviour (client test plan areas A, B, G-documents, K).
 *
 * Every test asserts the EXPECTED behaviour from the test plan, so a failing
 * test is a finding. Only TEST-… records are created. sqlite :memory: only.
 *
 * Set QA_DUMP=<dir> to write rendered pages there for screenshots.
 */
class ListUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $lan = 20000;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-29 09:00:00'));
        $this->admin = User::factory()->create(['name' => 'TEST-Director', 'role' => 'admin', 'must_change_password' => false]);
    }

    // ------------------------------------------------------------------ A

    public function test_A1_status_tab_counts_match_rows_and_footer(): void
    {
        $this->roll();

        $tabs = ['' => 5 + 2 + 2, 'Active' => 5, 'Pending' => 2, 'Inactive' => 2];
        foreach ($tabs as $status => $expected) {
            $html = $this->page(['status' => $status]);
            $rows = $this->rowCount($html);
            $label = $status === '' ? 'all' : $status;
            preg_match('/data-count="'.preg_quote($label, '/').'"[^>]*>[^<]*<span[^>]*>(\d+)</', $html, $m);
            preg_match('/Showing (\d+) of (\d+)/', $html, $f);
            $this->note("A1 tab=$label rows=$rows tabCount={$m[1]} footer=Showing {$f[1]} of {$f[2]}");
            $this->assertSame($expected, $rows, "rows on tab $label");
            $this->assertSame((string) $expected, $m[1], "tab count $label");
            $this->assertSame((string) $rows, $f[1], "footer X on $label");
        }
    }

    public function test_A1b_archived_is_not_in_all_and_footer_on_archived_tab(): void
    {
        $this->roll();
        Setting::put(\App\Http\Controllers\ChildController::SHOW_ARCHIVED_SETTING, '1');
        $html = $this->page(['status' => 'Archived']);
        preg_match('/Showing (\d+) of (\d+)/', $html, $f);
        $this->note("A1b archived tab rows={$this->rowCount($html)} footer=Showing {$f[1]} of {$f[2]}");
        $this->assertSame(1, $this->rowCount($html));
        $this->assertStringNotContainsString('TEST-Archie', $this->page([]));
    }

    public function test_A2_room_and_pay_chips_combine_with_status_tab(): void
    {
        $this->roll();
        $html = $this->page(['status' => 'Active', 'room' => 'Toddler', 'pay' => 'dss']);
        $lans = $this->rowLans($html);
        $this->note('A2 Active+Toddler+DSS rows='.implode(',', $lans));
        $this->assertCount(1, $lans);

        // Chip counts: does the Toddler chip count follow the pay chip that is on?
        preg_match('/data-room-chip="Toddler"[^>]*>.*?<span style="opacity: \.7; font-weight: 500;">(\d+)</s', $html, $m);
        preg_match('/data-pay-chip="dss"[^>]*>[^<]*<span[^>]*>(\d+)</', $html, $p);
        $this->note("A2 with pay=dss on: Toddler chip says {$m[1]}, DSS chip says {$p[1]}, list shows ".count($lans));

        // Status tab links keep room/pay
        preg_match('/href="([^"]+)"[^>]*data-count="Pending"/', $html, $t);
        $this->note('A2 Pending tab href while filtered: '.html_entity_decode($t[1]));
        $this->assertStringContainsString('room=Toddler', html_entity_decode($t[1]));
        $this->assertStringContainsString('pay=dss', html_entity_decode($t[1]));
    }

    public function test_A3_search_haystack_covers_name_nickname_lan_and_parent_names(): void
    {
        $c = $this->kid('TEST-Ava', 'TEST-Stone', ['nickname' => 'Bugsy']);
        $this->link($c, 'TEST-Mum Guardian', '555-0101', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1], 'Mother');
        $this->link($c, 'TEST-Dad Second', '555-0102', ['is_guardian' => true, 'is_emergency' => false], 'Father');
        $this->link($c, 'TEST-Gran Pickup', '555-0103', ['can_pickup' => true], 'Grandmother');

        $html = $this->page([]);
        preg_match('/data-child-row="'.$c->lan.'"\s+x-show="([^"]+)"/', $html, $m);
        $hay = html_entity_decode($m[1]);
        $this->note('A3 search x-show = '.$hay);
        $this->assertStringContainsString('test-ava test-stone', $hay, 'first last');
        $this->assertStringContainsString($c->lan, $hay, 'LAN');
        $this->assertStringContainsString('test-mum guardian', $hay, 'first guardian');
        $this->assertStringContainsString('test-dad second', $hay, 'second parent (also a guardian) is searchable');
        $this->assertStringContainsString('bugsy', $hay, 'nickname is searchable');
    }

    public function test_A3b_search_typed_as_shown_last_comma_first(): void
    {
        $c = $this->kid('TEST-Ava', 'TEST-Stone');
        $html = $this->page([]);
        preg_match('/data-child-row="'.$c->lan.'"\s+x-show="([^"]+)"/', $html, $m);
        $hay = html_entity_decode($m[1]);
        // The row reads "TEST-Stone, TEST-Ava": typing the name as shown should find it.
        $this->assertStringContainsString('test-stone, test-ava', $hay);
    }

    public function test_A4_empty_states(): void
    {
        $this->kid('TEST-Only', 'TEST-Toddler', ['classroom_override' => 'Toddler']);
        $html = $this->page(['room' => 'Infant']);
        $this->assertStringContainsString('Nobody matches these filters.', $html);
        $html = $this->page(['status' => 'Pending']);
        $this->assertStringContainsString('Nobody on the roll is pending right now.', $html);
        $html = $this->page(['status' => 'Inactive']);
        $this->assertStringContainsString('Nobody on the roll is withdrawn right now.', $html);

        // Search is client-side: is there any "no match" row for the search box?
        $html = $this->page([]);
        $this->note('A4 search empty-state markup present: '.(preg_match('/x-show="[^"]*search[^"]*"[^>]*>[^<]*(No|Nobody)[^<]*match/i', $html) ? 'yes' : 'no'));
        $this->assertMatchesRegularExpression('/(No children match|Nobody matches)[^<]*search|x-show="[^"]*search[^"]*"[^>]*>\s*(No|Nobody)/i', $html, 'an empty state for a search that finds nobody');
    }

    public function test_A5_sorting_both_ways(): void
    {
        $a = $this->kid('TEST-Cara', 'TEST-Adams', ['lan' => '2', 'birth_date' => '2025-06-01']);      // Infant
        $b = $this->kid('TEST-Abe', 'TEST-Zed', ['lan' => '10', 'birth_date' => '2022-01-15']);        // UPK-4? (4y9m)
        $c = $this->kid('TEST-Bea', 'TEST-Moss', ['lan' => '100', 'birth_date' => '2024-01-10']);      // Toddler? (2y9m)

        $rooms = fn () => Child::orderBy('lan')->pluck('classroom', 'lan')->all();
        $this->note('A5 rooms by LAN: '.json_encode($rooms()));

        $cases = [
            ['lan', 'asc', ['2', '10', '100']],
            ['lan', 'desc', ['100', '10', '2']],
            ['last_name', 'asc', ['2', '100', '10']],
            ['last_name', 'desc', ['10', '100', '2']],
            ['classroom', 'asc', ['2', '100', '10']],
            ['classroom', 'desc', ['10', '100', '2']],
            ['age', 'asc', ['2', '100', '10']],   // youngest first
            ['age', 'desc', ['10', '100', '2']],
        ];
        foreach ($cases as [$sort, $dir, $want]) {
            $got = $this->rowLans($this->page(['sort' => $sort, 'direction' => $dir]));
            $this->note("A5 sort=$sort $dir -> ".implode(',', $got));
            $this->assertSame($want, $got, "sort $sort $dir");
        }

        // The header arrow and the link to flip it.
        $html = $this->page(['sort' => 'age', 'direction' => 'asc']);
        $this->assertStringContainsString('Age ↑', $html);
        $this->assertStringContainsString('sort=age&amp;direction=desc', $html);
    }

    public function test_A6_each_records_to_finish_chip_lists_exactly_those_children(): void
    {
        $full = $this->complete($this->kid('TEST-Full', 'TEST-Done'));
        $noGuard = $this->complete($this->kid('TEST-NoGuard', 'TEST-G'), guardian: false);
        $noEmerg = $this->complete($this->kid('TEST-NoEmerg', 'TEST-E'), emergency: false);
        $imm = $this->complete($this->kid('TEST-Imm', 'TEST-I'), immunization: false);
        $photo = $this->complete($this->kid('TEST-Photo', 'TEST-P'), photo: false);
        $hours = $this->complete($this->kid('TEST-Hours', 'TEST-H'), hours: false);
        // A pending and a withdrawn child, each missing an emergency contact.
        $pend = $this->complete($this->kid('TEST-PendNoEmerg', 'TEST-PE', ['status' => 'Pending', 'enrolled_on' => '2026-11-09']), emergency: false);
        $gone = $this->complete($this->kid('TEST-GoneNoEmerg', 'TEST-WE', ['status' => 'Inactive', 'withdrawn_on' => '2026-09-30']), emergency: false);

        $html = $this->page([]);
        $chips = [];
        preg_match_all('/data-gap="(\w+)">(\d+) ([^<]+)</', $html, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $chips[$m[1]] = (int) $m[2];
        }
        $this->note('A6 chips: '.json_encode($chips));

        $want = ['guard' => [$noGuard], 'emerg' => [$noEmerg], 'imm' => [$imm], 'photo' => [$photo], 'hours' => [$hours]];
        foreach ($want as $gap => $kids) {
            $this->assertSame(1, $chips[$gap] ?? 0, "chip $gap count");
            // The chip's own link, as rendered on the default (All) tab.
            preg_match('/href="([^"]+)" class="gapb[^"]*" data-gap="'.$gap.'"/', $html, $h);
            $url = html_entity_decode($h[1]);
            $listed = $this->rowLans($this->actingAs($this->admin)->get($url)->getContent());
            $this->note("A6 chip $gap ({$chips[$gap]}) -> $url lists ".implode(',', $listed));
            if ([$kids[0]->lan] !== $listed) {
                $wrong[] = "chip $gap counts {$chips[$gap]} but lists ".count($listed);
            }
        }
        $wrong ??= [];

        // Record column matches.
        $this->assertMatchesRegularExpression('/data-child-row="'.$full->lan.'".*?>Complete</s', $html);
        foreach (['No guardian' => $noGuard, 'No emergency contact' => $noEmerg, 'Immunizations due' => $imm, 'Photo consent unsigned' => $photo, 'Hours not agreed' => $hours] as $label => $kid) {
            preg_match('/data-child-row="'.$kid->lan.'".*?<span class="pill miss" title="([^"]*)">([^<]*)</s', $html, $r);
            $this->note("A6 RECORD col for {$kid->first_name}: '{$r[2]}' title='{$r[1]}'");
            $this->assertSame($label, trim($r[2]));
        }
        $this->assertSame([], $wrong, 'each chip lists exactly the children it counts');
    }

    public function test_A7_row_opens_the_right_record(): void
    {
        $a = $this->kid('TEST-Row', 'TEST-One');
        $b = $this->kid('TEST-Row', 'TEST-Two');
        $html = $this->page([]);
        foreach ([$a, $b] as $kid) {
            preg_match('/data-child-row="'.$kid->lan.'".*?window\.location = \'([^\']+)\'/s', $html, $m);
            $url = stripslashes($m[1]);
            $this->note("A7 row {$kid->lan} -> $url");
            $this->assertSame(route('children.show', $kid), $url);
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee($kid->last_name);
        }
    }

    public function test_A8_back_to_children_keeps_filters(): void
    {
        $kid = $this->kid('TEST-Back', 'TEST-Link', ['classroom_override' => 'Toddler']);
        $from = route('children.index', ['status' => 'Active', 'room' => 'Toddler', 'pay' => 'private', 'sort' => 'age', 'direction' => 'desc']);
        $html = $this->actingAs($this->admin)->withHeader('referer', $from)->get(route('children.show', $kid))->getContent();
        preg_match('/<a href="([^"]+)" style="font-size: 13px; font-weight: 600;">← (\w+)</', $html, $m);
        $this->note('A8 back link: '.html_entity_decode($m[1]).' label='.$m[2]);
        $this->assertSame($from, html_entity_decode($m[1]));
        $this->assertSame('Children', $m[2]);

        // After saving a card the record reloads itself: where does Back go then?
        $this->actingAs($this->admin)->withHeader('referer', route('children.show', $kid))->put(route('children.enrollment-note', $kid), ['enrollment_note' => 'TEST note']);
        $html = $this->actingAs($this->admin)->withHeader('referer', route('children.show', $kid))->get(route('children.show', $kid))->getContent();
        preg_match('/<a href="([^"]+)" style="font-size: 13px; font-weight: 600;">← (\w+)</', $html, $m2);
        $this->note('A8 back link after an in-place save: '.html_entity_decode($m2[1]));
        $this->assertSame($from, html_entity_decode($m2[1]), 'filters survive an in-place save on the record');
    }

    public function test_A9_withdrawn_tab_columns(): void
    {
        $this->kid('TEST-Iris', 'TEST-Gone', ['status' => 'Inactive', 'classroom_override' => 'PreK', 'withdrawn_on' => '2026-09-30', 'withdrawal_reason' => 'Family choice', 'withdrawal_note' => 'TEST moving away', 'could_return' => 'Not sure']);
        $html = $this->page(['status' => 'Inactive']);
        $this->assertStringContainsString('<th>Last room</th><th>Last day</th><th>Reason</th><th>Note</th>', $html);
        $this->assertStringContainsString('PreK', $html);
        $this->assertStringContainsString('Sep 30, 2026', $html);
        $this->assertStringContainsString('Family choice', $html);
        $this->assertStringContainsString('TEST moving away', $html);
        $this->assertStringContainsString('Not sure', $html);
        // Re-enroll is a bare one-click form on the row.
        $this->note('A9 Re-enroll has a confirm step: '.(preg_match('/reenroll[^>]*onsubmit|confirm\(/', $html) ? 'yes' : 'no'));
    }

    public function test_A9b_last_room_does_not_drift_after_withdrawal(): void
    {
        // Born so the age rule moves them Toddler -> PreK on 2027-01-10.
        $kid = $this->kid('TEST-Drift', 'TEST-Room', ['birth_date' => '2024-01-10']);
        $this->assertSame('Toddler', $kid->fresh()->classroom);
        $this->actingAs($this->admin)->post(route('children.withdraw', $kid), ['last_day' => '2026-10-28', 'reason' => 'Family choice']);
        $this->assertSame('Inactive', $kid->fresh()->status);

        $this->travelTo(Carbon::parse('2027-02-01 09:00:00'));
        // Somebody tidies the withdrawn record (health card save).
        $this->actingAs($this->admin)->put(route('children.health', $kid), ['doctor' => 'TEST Dr']);
        $room = $kid->fresh()->classroom;
        $html = $this->page(['status' => 'Inactive']);
        $this->note("A9b Last room after a save months later: $room");
        $this->assertSame('Toddler', $room, 'Last room stays the room they left from');
    }

    public function test_A10_export_columns_filters_and_role(): void
    {
        $this->roll();
        $t = $this->kid('TEST-Toddy', 'TEST-Secret', ['classroom_override' => 'Toddler', 'custody_note' => 'TEST custody']);
        $p = Person::create(['name' => 'TEST-Dad SSN', 'cell' => '555-0199', 'ssn' => '123-45-6789', 'drivers_license' => 'TESTDL123']);
        ChildPerson::create(['child_id' => $t->id, 'person_id' => $p->id, 'relationship' => 'Father', 'is_guardian' => true]);

        // Admin, Active + Toddler.
        [$name, $sheets] = $this->export($this->admin, ['status' => 'Active', 'room' => 'Toddler']);
        $this->note('A10 file: '.$name.' sheets='.implode(',', array_keys($sheets)));
        $this->note('A10 Children headings: '.implode(' | ', $sheets['Children'][0]));
        $this->note('A10 People headings: '.implode(' | ', $sheets['People'][0]));
        $this->assertSame(count($sheets['Children'][0]), count(array_filter($sheets['Children'][1] ?? [], fn () => true)), 'every value column has a heading');
        $rows = array_slice($sheets['Children'], 1);
        $classes = array_unique(array_column($rows, 7));
        $statuses = array_unique(array_column($rows, 11));
        $this->note('A10 admin Active+Toddler rows='.count($rows).' rooms='.implode(',', $classes).' statuses='.implode(',', $statuses));
        $this->assertSame(['Toddler'], array_values($classes));
        $this->assertSame(['Active'], array_values($statuses));

        // Gap filter respected?
        [, $sheets] = $this->export($this->admin, ['gap' => 'photo']);
        $this->note('A10 gap=photo rows='.(count($sheets['Children']) - 1));

        // Withdrawn tab: file name and status wording.
        [$name] = $this->export($this->admin, ['status' => 'Inactive']);
        $this->note('A10 Withdrawn tab export file name: '.$name);

        // Archived never exported.
        [, $sheets] = $this->export($this->admin, []);
        $this->assertNotContains('TEST-Archie', array_column($sheets['Children'], 2));

        // Teacher: only their room, and what columns.
        $teacher = User::factory()->create(['name' => 'TEST-Teach', 'role' => 'teacher', 'classroom' => 'Infant', 'must_change_password' => false]);
        [, $sheets] = $this->export($teacher, []);
        $rooms = array_unique(array_column(array_slice($sheets['Children'], 1), 7));
        $this->note('A10 teacher export rooms='.implode(',', $rooms).' rows='.(count($sheets['Children']) - 1).' people rows='.(count($sheets['People']) - 1));
        $this->assertSame(['Infant'], array_values($rooms));

        // A teacher asking for another room by URL.
        [, $sheets] = $this->export($teacher, ['room' => 'Toddler']);
        $data = array_filter(array_slice($sheets['Children'], 1), fn ($r) => array_filter($r, fn ($v) => $v !== null && $v !== '') !== []);
        $this->note('A10 teacher(Infant) export ?room=Toddler non-empty data rows='.count($data).' '.json_encode(array_values($data)));
        $this->assertCount(0, $data, 'no Toddler children for an Infant teacher');

        // SSN in the admin People sheet
        [, $sheets] = $this->export($this->admin, ['room' => 'Toddler']);
        foreach (array_slice($sheets['People'], 1) as $r) {
            if ($r[3] === 'TEST-Dad SSN') {
                $this->note('A10 People row SSN col='.json_encode($r[20]).' DL='.json_encode($r[19]));
                $this->assertStringNotContainsString('123-45', (string) $r[20]);
            }
        }
    }

    public function test_A11_performance_with_100_children(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $kid = $this->kid('TEST-Perf'.$i, 'TEST-Kid'.$i, ['birth_date' => Carbon::parse('2021-01-01')->addDays($i * 15)->toDateString()]);
            $this->complete($kid, photo: $i % 3 !== 0, hours: $i % 5 !== 0);
            $kid->forceFill(['alerts' => [['type' => 'allergy', 'text' => 'TEST peanut']]])->save();
        }
        $this->actingAs($this->admin)->get(route('children.index'))->assertOk(); // warm

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $times = [];
        for ($r = 0; $r < 3; $r++) {
            $start = microtime(true);
            $resp = $this->actingAs($this->admin)->get(route('children.index'));
            $times[] = round((microtime(true) - $start) * 1000);
        }
        $per = intdiv($queries, 3);
        $size = strlen($resp->getContent());
        $this->note('A11 100 children /children: ms='.implode(',', $times)." queries/request=$per html=".round($size / 1024).'KB rows='.$this->rowCount($resp->getContent()));

        $start = microtime(true);
        $this->actingAs($this->admin)->get(route('children.export'))->assertOk();
        $this->note('A11 export ms='.round((microtime(true) - $start) * 1000));
        $this->assertLessThan(150, $per, 'queries per list request');
    }

    // ------------------------------------------------------------------ B

    public function test_B1_header_identity_age_and_enrolment(): void
    {
        $kid = $this->kid('TEST-Header', 'TEST-Kid', ['nickname' => 'Hedge', 'birth_date' => '2024-04-05', 'enrolled_on' => '2025-09-02']);
        $html = $this->record($kid);
        preg_match('/<section class="ch-card"[^>]*data-record-header>(.*?)data-record-anchors/s', $html, $m);
        $text = preg_replace('/\s+/', ' ', strip_tags($m[1]));
        $this->note('B1 header text: '.trim($text));
        $this->assertStringContainsString('TEST-Header TEST-Kid (Hedge)', $text);
        $this->assertStringContainsString('LAN '.$kid->lan, $text);
        $this->assertStringContainsString('Toddler', $text);
        $this->assertStringContainsString('2y 6m (04/05/24)', $text);
        $this->assertStringContainsString('Enrolled Sep 2, 2025', $text);

        // Birthday boundary: turns 3 on 2027-04-05.
        $this->travelTo(Carbon::parse('2027-04-04 09:00'));
        $this->assertStringContainsString('2y 11m', $this->record($kid));
        $this->travelTo(Carbon::parse('2027-04-05 09:00'));
        $this->assertStringContainsString('3y 0m', $this->record($kid));
        // Leap-day baby.
        $leap = $this->kid('TEST-Leap', 'TEST-Day', ['birth_date' => '2024-02-29']);
        $this->travelTo(Carbon::parse('2026-02-28 09:00'));
        $this->note('B1 leap-day baby on 2026-02-28 reads: '.(preg_match('/(\dy \d+m) \(02\/29\/24\)/', $this->record($leap), $x) ? $x[1] : '?'));
    }

    public function test_B2_allergy_and_missing_badges_match_health_and_documents(): void
    {
        $kid = $this->kid('TEST-Badge', 'TEST-Kid');
        $this->actingAs($this->admin)->post(route('children.alerts.store', $kid), ['type' => 'allergy', 'text' => 'TEST Peanuts', 'severity' => 'severe'])->assertRedirect();
        $html = $this->record($kid);
        $this->assertStringContainsString('! Allergy: TEST Peanuts · severe', $html, 'header badge');
        $this->assertMatchesRegularExpression('/data-alert="0".*?TEST Peanuts/s', $html, 'Health card');
        // The roll shows it too.
        $this->assertStringContainsString('! TEST Peanuts', $this->page([]));

        // Missing badge vs Documents card.
        $this->assertMatchesRegularExpression('/<span class="pill miss">Photo consent unsigned<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-document="photo_consent".*?pill miss">Missing</s', $html);

        // Medical statement expired: Health card vs Documents card.
        ChildDocument::create(['child_id' => $kid->id, 'kind' => 'medical_statement', 'name' => 'Medical statement (yearly)', 'signed_on' => '2025-09-01', 'expires_on' => '2026-09-01']);
        $html = $this->record($kid);
        preg_match('/<span>Medical statement<\/span><span>(.*?)<\/span><\/span>/s', $html, $h);
        preg_match('/data-document="medical_statement".*?<span class="pill (\w+)">([^<]+)</s', $html, $d);
        $this->note('B2 expired medical statement: Health card says "'.trim(strip_tags($h[1] ?? '?')).'"; Documents card says "'.$d[2].'" ('.$d[1].')');
        $this->assertStringNotContainsString('>On file<', $h[1] ?? '', 'Health card must not say On file for an expired statement');
    }

    public function test_B3_completeness_updates_when_an_item_is_fixed(): void
    {
        $kid = $this->kid('TEST-Pct', 'TEST-Kid', ['enrolled_on' => '2025-09-02']);
        $pct = fn () => (int) (preg_match('/Record (\d+)% complete/', $this->record($kid), $m) ? $m[1] : -1);
        $before = $pct();
        $this->actingAs($this->admin)->post(route('children.documents.store', $kid), ['kind' => 'photo_consent', 'file' => UploadedFile::fake()->create('consent.pdf', 50, 'application/pdf')]);
        $after = $pct();
        $this->actingAs($this->admin)->put(route('children.health', $kid), ['immunizations_due' => '2027-06-01']);
        $after2 = $pct();
        $this->note("B3 Record % : $before -> (photo consent) $after -> (immunization date) $after2");
        $this->assertGreaterThan($before, $after);
        $this->assertGreaterThan($after, $after2);
    }

    public function test_B4_status_menu_and_more_menu_offer_only_valid_actions(): void
    {
        $active = $this->kid('TEST-Act', 'TEST-Kid');
        $pending = $this->kid('TEST-Pend', 'TEST-Kid', ['status' => 'Pending', 'enrolled_on' => '2026-11-09']);
        $withdrawn = $this->kid('TEST-Wd', 'TEST-Kid', ['status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);
        $archived = $this->kid('TEST-Arc', 'TEST-Kid', ['status' => 'Inactive', 'withdrawn_on' => '2025-06-30']);
        $archived->archive($this->admin);

        $menus = [];
        foreach (['Active' => $active, 'Pending' => $pending, 'Withdrawn' => $withdrawn, 'Archived' => $archived] as $label => $kid) {
            $html = $this->record($kid);
            preg_match('/x-show="statusMenu".*?<\/div>/s', $html, $s);
            preg_match_all('/<b style="font-weight: 600;">([^<]+)<\/b>/', $s[0] ?? '', $sm);
            preg_match('/x-show="menu" x-cloak @click.outside="menu = false" class="menu">(.*?)<\/div>/s', $html, $o);
            preg_match_all('/class="mi"[^>]*>([^<]+)</', $o[1] ?? '', $om);
            $menus[$label] = ['status' => $sm[1], 'more' => array_map('trim', $om[1])];
        }
        $this->note('B4 menus: '.json_encode($menus));
        $this->assertSame(['Withdraw…'], $menus['Active']['status']);
        $this->assertSame(['Start enrollment', 'Cancel enrollment'], $menus['Pending']['status']);
        $this->assertSame(['Re-enroll', 'Archive…'], $menus['Withdrawn']['status']);
        $this->assertSame(['Restore'], $menus['Archived']['status']);

        // Server refuses the invalid transitions.
        $this->actingAs($this->admin)->post(route('children.withdraw', $pending), ['last_day' => '2026-10-29', 'reason' => 'Family choice'])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('children.start', $active), ['first_day' => '2026-10-29'])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('children.reenroll', $active))->assertStatus(422);

        // Teacher: no menu, and the routes are refused.
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
        $html = $this->actingAs($teacher)->get(route('children.show', $active))->getContent();
        $this->assertStringNotContainsString('x-show="statusMenu"', $html);
        $this->actingAs($teacher)->post(route('children.withdraw', $active), ['last_day' => '2026-10-29', 'reason' => 'Family choice'])->assertForbidden();
        preg_match('/x-show="menu" x-cloak @click.outside="menu = false" class="menu">(.*?)<\/div>/s', $html, $o);
        $this->note('B4 teacher ⋯ menu: '.trim(strip_tags($o[1] ?? '')));
    }

    public function test_B4b_archived_record_is_read_only_as_the_banner_says(): void
    {
        $kid = $this->kid('TEST-ReadOnly', 'TEST-Arch', ['status' => 'Inactive', 'withdrawn_on' => '2025-06-30']);
        $kid->archive($this->admin);
        $html = $this->record($kid);
        $this->assertStringContainsString('read-only until restored', $html);
        $editButtons = substr_count($html, '>Edit<') + substr_count($html, '+ Add alert') + substr_count($html, '+ Add person') + substr_count($html, '>Upload<');
        $this->note("B4b archived record still shows $editButtons edit/add/upload controls");
        $r1 = $this->actingAs($this->admin)->post(route('children.alerts.store', $kid), ['type' => 'allergy', 'text' => 'TEST added while archived']);
        $r2 = $this->actingAs($this->admin)->put(route('children.overview', $kid), ['nickname' => 'TEST-changed']);
        $this->note('B4b POST alert on archived -> '.$r1->status().', overview -> '.$r2->status().', nickname now '.$kid->fresh()->nickname);
        $this->assertNotSame('TEST-changed', $kid->fresh()->nickname, 'an archived record is read-only');
    }

    public function test_B5_message_parents_preselects_the_family_and_skips_restricted(): void
    {
        $kid = $this->kid('TEST-Msg', 'TEST-Kid');
        $this->link($kid, 'TEST-Mum Texts', '555-0111', ['is_guardian' => true, 'gets_texts' => true], 'Mother');
        $this->link($kid, 'TEST-Dad Barred', '555-0112', ['gets_texts' => true, 'restriction' => 'Court order TEST'], 'Father');
        $this->link($kid, 'TEST-Gran NoTick', '555-0113', ['can_pickup' => true], 'Grandmother');
        $html = $this->record($kid);
        $this->assertStringContainsString('href="'.route('messages.index', ['child' => $kid->id]).'"', $html);
        $page = $this->actingAs($this->admin)->get(route('messages.index', ['child' => $kid->id]))->assertOk()->getContent();
        preg_match('/TEST-Mum Texts \(Mother\)[^"]*/', $page, $g);
        $this->note('B5 message page startWith: '.($g[0] ?? 'not found'));
        $this->assertStringContainsString('TEST-Mum Texts (Mother)', $page);
        $this->assertStringNotContainsString('TEST-Dad Barred (Father)', $page);
        $this->assertStringContainsString('TEST-Dad Barred: restricted', $page);

        // Another room's teacher cannot open the family from the URL.
        $other = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant', 'must_change_password' => false]);
        $page = $this->actingAs($other)->get(route('messages.index', ['child' => $kid->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('TEST-Mum Texts', $page);
    }

    public function test_B6_sign_in_out_opens_the_room_and_sign_in_creates_an_attendance_row(): void
    {
        $kid = $this->kid('TEST-Sign', 'TEST-In', ['schedule_days' => [1, 2, 3, 4, 5], 'enrolled_on' => '2025-09-02']);
        $html = $this->record($kid);
        $this->assertStringContainsString('href="'.route('check-in.index').'?toddler"', $html);
        $page = $this->actingAs($this->admin)->get(route('check-in.index').'?toddler')->assertOk()->getContent();
        $this->assertStringContainsString('TEST-Sign', $page);

        $before = DB::table('attendances')->where('child_id', $kid->id)->count();
        $resp = $this->actingAs($this->admin)->postJson(route('check-in.day'), ['child_id' => $kid->id, 'attendance_date' => '2026-10-29', 'session' => 'FULL', 'signed_in_time' => '08:05']);
        $after = DB::table('attendances')->where('child_id', $kid->id)->whereNotNull('signed_in_at')->count();
        $this->note('B6 sign-in status='.$resp->status()." attendance rows $before -> $after");
        $this->assertSame(1, $after - $before);
        // and the record's Attendance card shows today as in.
        $this->assertMatchesRegularExpression('/class="cd c-today" title="Thu Oct 29[^"]*">29<span[^>]*>8:05/', $this->record($kid));
    }

    public function test_B7_every_tab_shows_the_right_section(): void
    {
        $kid = $this->kid('TEST-Tabs', 'TEST-Kid');
        $html = $this->record($kid);
        $map = ['overview' => 'overview', 'family' => 'family', 'health' => 'health', 'history' => 'history', 'notes' => 'notes', 'attendance' => 'attendance', 'docs' => 'documents'];
        foreach ($map as $tab => $section) {
            $this->assertStringContainsString("@click=\"sec = '$tab'\"", $html);
            $this->assertMatchesRegularExpression('/x-show="sec === \'all\' \|\| sec === \''.$tab.'\'" data-section="'.$section.'"/', $html, "tab $tab shows section $section");
        }
    }

    // ------------------------------------------------------------------ G

    public function test_G1_upload_pdf_and_jpg_then_preview_and_download(): void
    {
        $kid = $this->kid('TEST-Docs', 'TEST-Kid');
        $r = $this->actingAs($this->admin)->post(route('children.documents.store', $kid), ['kind' => 'enrollment_packet', 'signed_on' => '2026-10-01', 'file' => UploadedFile::fake()->create('packet.pdf', 300, 'application/pdf')]);
        $r->assertRedirect(route('children.show', $kid));
        $this->assertSame('Enrollment packet is on file.', session('success'));
        $r = $this->actingAs($this->admin)->post(route('children.documents.store', $kid), ['kind' => 'immunization_record', 'file' => UploadedFile::fake()->image('shots.jpg', 800, 600)]);
        $r->assertRedirect();

        $html = $this->record($kid);
        foreach (['enrollment_packet', 'immunization_record'] as $k) {
            preg_match('/data-document="'.$k.'".*?href="([^"]+)" target="_blank">.*?<span class="pill (\w+)">([^<]+)</s', $html, $m);
            $this->assertNotEmpty($m, "$k link");
            $resp = $this->actingAs($this->admin)->get($m[1]);
            $this->note("G1 $k pill='{$m[3]}' GET -> ".$resp->baseResponse->getStatusCode().' type='.$resp->headers->get('content-type').' disposition='.$resp->headers->get('content-disposition').' cache='.$resp->headers->get('cache-control'));
            $resp->assertOk();
        }
        // Upload shows who/when?
        $this->note('G1 Documents card shows uploader/date: '.(preg_match('/data-document="enrollment_packet".*?(TEST-Director|Oct 1)/s', $html) ? 'yes' : 'no'));
    }

    public function test_G2_size_and_type_limits_and_their_messages(): void
    {
        $kid = $this->kid('TEST-Limits', 'TEST-Kid');
        $cases = [
            'exact 10 MB pdf' => UploadedFile::fake()->create('ten.pdf', 10240, 'application/pdf'),
            '10 MB + 1 KB pdf' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            'docx' => UploadedFile::fake()->create('form.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'exe' => UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload'),
            'heic photo' => UploadedFile::fake()->create('IMG_0001.heic', 200, 'image/heic'),
        ];
        foreach ($cases as $label => $file) {
            $before = ChildDocument::count();
            $resp = $this->actingAs($this->admin)->from(route('children.show', $kid))->post(route('children.documents.store', $kid), ['kind' => 'allergy_care_plan', 'file' => $file]);
            $errors = session('errors')?->all() ?? [];
            $saved = ChildDocument::count() - $before;
            $this->note("G2 $label -> ".$resp->status().' saved='.$saved.' errors='.json_encode($errors));
            $this->app['session']->forget('errors');
        }
        // What the user sees after a refused upload.
        $this->actingAs($this->admin)->from(route('children.show', $kid))->post(route('children.documents.store', $kid), ['kind' => 'allergy_care_plan', 'file' => UploadedFile::fake()->create('virus.exe', 20)]);
        $html = $this->actingAs($this->admin)->get(route('children.show', $kid))->getContent();
        preg_match('/border: 1px solid #fbc4cc; background: #ffe4e6;[^>]*>([^<]+)</', $html, $e);
        $this->note('G2 error banner on the record: '.($e[1] ?? 'none').'; upload panel open again: '.(str_contains($html, 'docForm: true') ? 'yes' : 'no (docForm starts false)'));
        $this->assertSame(0, ChildDocument::where('child_id', $kid->id)->where('kind', 'allergy_care_plan')->whereNull('path')->count(), 'a refused upload leaves no "on file" row');
    }

    public function test_G3_expiry_turns_documents_expires_and_expired_on_the_right_day(): void
    {
        $kid = $this->kid('TEST-Expiry', 'TEST-Kid');
        ChildDocument::create(['child_id' => $kid->id, 'kind' => 'sunscreen_consent', 'name' => 'Sunscreen', 'signed_on' => '2026-01-01', 'expires_on' => '2026-12-28']);
        $pill = function () use ($kid) {
            preg_match('/data-document="sunscreen_consent".*?<span class="pill (\w+)">([^<]+)</s', $this->record($kid), $m);

            return $m[2].' ('.$m[1].')';
        };
        $seen = [];
        foreach (['2026-10-28', '2026-10-29', '2026-12-27', '2026-12-28', '2026-12-29'] as $day) {
            $this->travelTo(Carbon::parse($day.' 09:00'));
            $seen[$day] = $pill();
        }
        $this->note('G3 sunscreen expiring 2026-12-28: '.json_encode($seen));
        $this->assertSame('Renews Dec 28 (ok)', $seen['2026-10-28']);
        $this->assertSame('Expires Dec 28 (warn)', $seen['2026-10-29']); // 60 days out
        $this->assertSame('Expires Dec 28 (warn)', $seen['2026-12-28']); // last valid day
        $this->assertSame('Expired Dec 28 (bad)', $seen['2026-12-29']);
    }

    public function test_G3b_expired_photo_consent_raises_the_list_chip_and_header_badge(): void
    {
        $kid = $this->complete($this->kid('TEST-ExpPhoto', 'TEST-Kid'));
        ChildDocument::where('child_id', $kid->id)->where('kind', 'photo_consent')->update(['expires_on' => '2026-10-28', 'signed_on' => '2025-10-28']);
        $html = $this->page([]);
        $rec = $this->record($kid);
        preg_match('/data-document="photo_consent".*?<span class="pill (\w+)">([^<]+)</s', $rec, $d);
        $this->note('G3b expired photo consent: Documents card "'.$d[2].'"; list chip present: '.(str_contains($html, 'data-gap="photo"') ? 'yes' : 'no').'; RECORD col: '.(preg_match('/data-child-row="'.$kid->lan.'".*?>(Complete|Photo consent unsigned)</s', $html, $r) ? $r[1] : '?').'; header %: '.(preg_match('/Record (\d+)% complete/', $rec, $p) ? $p[1] : '?'));
        $this->assertStringContainsString('data-gap="photo"', $html, 'an expired consent is not a signed one');
    }

    public function test_G4_uploading_missing_photo_consent_clears_badge_card_and_chip(): void
    {
        $kid = $this->complete($this->kid('TEST-Consent', 'TEST-Kid'), photo: false);
        $this->assertStringContainsString('data-gap="photo"', $this->page([]));
        $rec = $this->record($kid);
        $this->assertStringContainsString('<span class="pill miss">Photo consent unsigned</span>', $rec);
        $this->assertStringContainsString('data-section="to-finish"', $rec);

        // The To-finish card's own "Upload signed form" (file only, no dates).
        $this->actingAs($this->admin)->post(route('children.documents.store', $kid), ['kind' => 'photo_consent', 'file' => UploadedFile::fake()->image('consent.jpg')])->assertRedirect();
        $rec = $this->record($kid);
        $this->assertStringNotContainsString('<span class="pill miss">Photo consent unsigned</span>', $rec);
        $this->assertStringNotContainsString('data-section="to-finish"', $rec);
        $this->assertStringNotContainsString('data-gap="photo"', $this->page([]));
        $this->note('G4 after To-finish upload: header '.(preg_match('/Record (\d+)% complete/', $rec, $p) ? $p[1].'%' : '?').', remaining badges: '.(preg_match_all('/<span class="pill miss">([^<]+)<\/span>/', $rec, $b) ? implode(',', $b[1]) : 'none'));
    }

    public function test_G5_documents_are_guarded_by_child_and_role(): void
    {
        $a = $this->kid('TEST-DocA', 'TEST-Kid', ['classroom_override' => 'Toddler']);
        $b = $this->kid('TEST-DocB', 'TEST-Kid', ['classroom_override' => 'Infant']);
        $this->actingAs($this->admin)->post(route('children.documents.store', $b), ['kind' => 'enrollment_packet', 'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]);
        $doc = ChildDocument::where('child_id', $b->id)->first();
        $this->actingAs($this->admin)->get(route('children.documents.show', [$a, $doc]))->assertNotFound();
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
        $this->actingAs($teacher)->get(route('children.documents.show', [$b, $doc]))->assertForbidden();
        $this->actingAs($teacher)->post(route('children.documents.store', $b), ['kind' => 'photo_consent'])->assertForbidden();
    }

    public function test_G6_no_file_still_marks_a_document_on_file(): void
    {
        $kid = $this->kid('TEST-NoFile', 'TEST-Kid');
        $r = $this->actingAs($this->admin)->post(route('children.documents.store', $kid), ['kind' => 'photo_consent']);
        $this->note('G6 Save with no file and no date -> '.$r->status().' message='.session('success').' rows='.ChildDocument::where('child_id', $kid->id)->count());
        $this->assertSame(0, ChildDocument::where('child_id', $kid->id)->count(), 'Save on an empty upload form should not clear the consent gap');
    }

    public function test_G7_expires_before_signed_is_refused(): void
    {
        $kid = $this->kid('TEST-Dates', 'TEST-Kid');
        $r = $this->actingAs($this->admin)->from(route('children.show', $kid))->post(route('children.documents.store', $kid), ['kind' => 'sunscreen_consent', 'signed_on' => '2026-10-01', 'expires_on' => '2025-01-01']);
        $this->note('G7 expires before signed -> '.$r->status().' errors='.json_encode(session('errors')?->all() ?? []).' saved='.ChildDocument::where('child_id', $kid->id)->count());
        $this->assertSame(0, ChildDocument::where('child_id', $kid->id)->count());
    }

    // ------------------------------------------------------------------ K

    public function test_K1_validation_messages_and_whether_typed_values_survive(): void
    {
        $kid = $this->kid('TEST-Valid', 'TEST-Kid', ['drop_off_time' => '08:00', 'pick_up_time' => '17:00']);
        $show = route('children.show', $kid);
        $cases = [
            'overview pick-up before drop-off' => ['put', route('children.overview', $kid), ['drop_off_time' => '15:00', 'pick_up_time' => '09:00', 'nickname' => 'TEST-typed']],
            'overview bad time' => ['put', route('children.overview', $kid), ['drop_off_time' => '25:99']],
            'overview drop-off 3am (outside opening)' => ['put', route('children.overview', $kid), ['drop_off_time' => '03:00', 'pick_up_time' => '04:00']],
            'alert with no text' => ['post', route('children.alerts.store', $kid), ['type' => 'allergy', 'text' => '']],
            'alert 121 chars' => ['post', route('children.alerts.store', $kid), ['type' => 'allergy', 'text' => str_repeat('x', 121)]],
            'health bad date' => ['put', route('children.health', $kid), ['immunizations_due' => 'not a date', 'doctor' => 'TEST-Dr typed']],
            'note empty' => ['post', route('children.notes.store', $kid), ['body' => '']],
            'withdraw no reason' => ['post', route('children.withdraw', $kid), ['last_day' => '2026-10-29']],
            'room bad' => ['post', route('children.room', $kid), ['room' => 'Attic']],
        ];
        foreach ($cases as $label => [$method, $url, $data]) {
            $resp = $this->actingAs($this->admin)->from($show)->{$method}($url, $data);
            $errs = session('errors')?->all() ?? [];
            $this->note("K1 $label -> ".$resp->status().' '.json_encode($errs));
            $this->app['session']->forget('errors');
        }
        $this->note('K1 drop/pick after "3am" case: '.$kid->fresh()->drop_off_time.'-'.$kid->fresh()->pick_up_time);

        // Typed values after a refused overview save.
        $this->actingAs($this->admin)->from($show)->put(route('children.overview', $kid), ['drop_off_time' => '15:00', 'pick_up_time' => '09:00', 'nickname' => 'TEST-typed']);
        $html = $this->actingAs($this->admin)->get($show)->getContent();
        $this->note('K1 after refused overview save: nickname input keeps typed value: '.(str_contains($html, 'value="TEST-typed"') ? 'yes' : 'no').'; form reopened: '.(preg_match('/overviewEdit: true/', $html) ? 'yes' : 'no'));
        $this->assertStringContainsString('value="TEST-typed"', $html, 'typed values survive a validation error');
    }

    public function test_K2_cancel_buttons_never_submit(): void
    {
        $kids = [
            $this->kid('TEST-CancelA', 'TEST-Kid'),
            $this->kid('TEST-CancelP', 'TEST-Kid', ['status' => 'Pending', 'enrolled_on' => '2026-11-09']),
            $this->kid('TEST-CancelW', 'TEST-Kid', ['status' => 'Inactive', 'withdrawn_on' => '2026-09-30']),
        ];
        $bad = [];
        foreach ($kids as $kid) {
            $html = $this->record($kid);
            preg_match_all('/<button([^>]*)>(Cancel|Keep pending|Close)<\/button>/', $html, $m, PREG_SET_ORDER);
            foreach ($m as $b) {
                if (! str_contains($b[1], 'type="button"')) {
                    $bad[] = $kid->first_name.': '.$b[0];
                }
            }
            $this->note('K2 '.$kid->first_name.' cancel-type buttons: '.count($m));
        }
        $this->assertSame([], $bad);
    }

    public function test_K4_icon_buttons_have_names(): void
    {
        $kid = $this->kid('TEST-A11y', 'TEST-Kid');
        $this->link($kid, 'TEST-One', '555-0001', ['is_emergency' => true, 'priority' => 1, 'is_guardian' => true], 'Mother');
        $this->link($kid, 'TEST-Two', '555-0002', ['is_emergency' => true, 'priority' => 2], 'Father');
        $html = $this->record($kid);
        preg_match_all('/<button[^>]*class="mv"[^>]*>[↑↓]<\/button>/u', $html, $mv);
        preg_match('/<button[^>]*>⋯<\/button>/u', $html, $more);
        preg_match('/<button[^>]*data-status-pill[^>]*>/u', $html, $status);
        $this->note('K4 arrow buttons: '.json_encode($mv[0]));
        $this->note('K4 more button: '.($more[0] ?? '?'));
        $this->note('K4 status pill button: '.($status[0] ?? '?'));
        foreach ($mv[0] as $b) {
            $this->assertStringContainsString('aria-label=', $b, 'arrow buttons need an accessible name naming the person');
        }
        $this->assertStringContainsString('aria-expanded', $more[0] ?? '', 'menu button exposes its state');
    }

    public function test_K6_very_long_names_render_and_dump_pages(): void
    {
        $long = $this->kid('TEST-Maximiliana-Alexandrina-Konstantinopoulou', 'TEST-Wolfeschlegelsteinhausenbergerdorff-Featherstonehaugh', ['nickname' => 'TEST-Maxi-The-Very-Long-Nickname', 'enrolled_on' => '2025-09-02']);
        $this->link($long, 'TEST-Bartholomew-Montgomery Featherstonehaugh-Wolfeschlegelstein', '(716) 555-0199', ['is_guardian' => true, 'is_emergency' => true, 'priority' => 1, 'gets_texts' => true], 'Father');
        $long->forceFill(['alerts' => [['type' => 'allergy', 'text' => 'TEST tree nuts, peanuts, sesame, shellfish, eggs — epi-pen in office', 'severity' => 'severe']]])->save();
        $this->roll();
        for ($i = 0; $i < 12; $i++) {
            $this->complete($this->kid('TEST-Child'.$i, 'TEST-Family'.$i, ['birth_date' => Carbon::parse('2021-03-01')->addDays($i * 70)->toDateString()]), photo: $i % 2 === 0);
        }
        $list = $this->page([]);
        $rec = $this->record($long);
        $this->assertStringContainsString('Wolfeschlegelsteinhausenbergerdorff', $list);
        if ($dir = getenv('QA_DUMP')) {
            file_put_contents($dir.'/list.html', $list);
            file_put_contents($dir.'/record.html', $rec);
            file_put_contents($dir.'/withdrawn.html', $this->page(['status' => 'Inactive']));
            $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
            file_put_contents($dir.'/list-teacher.html', $this->actingAs($teacher)->get(route('children.index'))->getContent());
        }
    }

    // ------------------------------------------------------------- helpers

    /** 5 active (2 Infant, 3 Toddler; 1 Toddler DSS), 2 pending, 2 withdrawn, 1 archived. */
    private function roll(): void
    {
        $this->kid('TEST-Ina', 'TEST-Infant1', ['classroom_override' => 'Infant']);
        $this->kid('TEST-Ivo', 'TEST-Infant2', ['classroom_override' => 'Infant', 'dss_case_no' => 'S1100101A']);
        $this->kid('TEST-Tia', 'TEST-Toddler1', ['classroom_override' => 'Toddler', 'dss_case_no' => 'S1100102A']);
        $this->kid('TEST-Tom', 'TEST-Toddler2', ['classroom_override' => 'Toddler']);
        $this->kid('TEST-Tess', 'TEST-Toddler3', ['classroom_override' => 'Toddler']);
        $this->kid('TEST-Pia', 'TEST-Pending1', ['status' => 'Pending', 'classroom_override' => 'Toddler', 'enrolled_on' => '2026-11-09']);
        $this->kid('TEST-Pat', 'TEST-Pending2', ['status' => 'Pending', 'classroom_override' => 'Infant']);
        $this->kid('TEST-Wes', 'TEST-Withdrawn1', ['status' => 'Inactive', 'classroom_override' => 'Toddler', 'withdrawn_on' => '2026-09-30', 'withdrawal_reason' => 'Family choice']);
        $this->kid('TEST-Wyn', 'TEST-Withdrawn2', ['status' => 'Inactive', 'classroom_override' => 'Infant', 'withdrawn_on' => '2026-08-31', 'withdrawal_reason' => 'Changed provider']);
        $this->kid('TEST-Archie', 'TEST-Archived', ['status' => 'Inactive', 'classroom_override' => 'Toddler', 'withdrawn_on' => '2025-06-30'])->archive($this->admin);
    }

    private function kid(string $first, string $last, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) $this->lan++,
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => '2024-04-05',
            'dob' => $attributes['birth_date'] ?? '2024-04-05',
        ]);
    }

    private function complete(Child $child, bool $guardian = true, bool $emergency = true, bool $photo = true, bool $immunization = true, bool $hours = true): Child
    {
        $child->forceFill(array_filter([
            'schedule_days' => [1, 2, 3, 4, 5],
            'drop_off_time' => $hours ? '08:00' : null,
            'pick_up_time' => $hours ? '17:00' : null,
            'health' => $immunization ? ['immunizations_due' => '2027-10-01'] : null,
        ], fn ($v) => $v !== null))->save();

        $this->link($child, 'TEST-Parent '.$child->last_name, '555-0100', ['is_guardian' => $guardian, 'is_emergency' => $emergency, 'priority' => 1], 'Mother');

        if ($photo) {
            ChildDocument::create(['child_id' => $child->id, 'kind' => 'photo_consent', 'name' => 'Photo consent', 'signed_on' => '2026-09-01']);
        }

        return $child;
    }

    private function link(Child $child, string $name, string $cell, array $flags, string $relationship = 'Mother'): void
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => $relationship] + $flags);
    }

    private function page(array $query): string
    {
        return $this->actingAs($this->admin)->get(route('children.index', array_filter($query, fn ($v) => $v !== '')))->assertOk()->getContent();
    }

    private function record(Child $child): string
    {
        return $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();
    }

    private function rowCount(string $html): int
    {
        return preg_match_all('/<tr class="row"/', $html);
    }

    private function rowLans(string $html): array
    {
        preg_match_all('/data-child-row="([^"]+)"/', $html, $m);

        return $m[1];
    }

    /** @return array{0: string, 1: array<string, array<int, array<int, mixed>>>} */
    private function export(User $user, array $query): array
    {
        $resp = $this->actingAs($user)->get(route('children.export', $query));
        $resp->assertOk();
        $file = $resp->baseResponse->getFile()->getPathname();
        preg_match('/filename="?([^";]+)/', (string) $resp->headers->get('content-disposition'), $n);
        $book = IOFactory::load($file);
        $sheets = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $sheets[$ws->getTitle()] = $ws->toArray(null, false, false, false);
        }

        return [$n[1] ?? '?', $sheets];
    }

    private function note(string $line): void
    {
        file_put_contents(sys_get_temp_dir().'/qa-list-ui-notes.txt', $line.PHP_EOL, FILE_APPEND);
        fwrite(STDERR, $line.PHP_EOL);
    }
}
