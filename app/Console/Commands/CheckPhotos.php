<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Models\Message;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Are the photos on this server, or only their names?
 *
 * A record keeps a file name; the picture itself is a file under storage/,
 * which git never carries. So a database copied to a server without its
 * storage folder lists photos that aren't there — the record shows initials
 * and the picture link 404s. This lists, for each kind of photo, how many
 * records name one and how many of those files are really on this server.
 * It only reads.
 */
class CheckPhotos extends Command
{
    protected $signature = 'photos:check {--list=15 : How many missing files to name per kind (0 for all)}';

    protected $description = 'Count photos on record and check each file is really on this server (read only)';

    public function handle(): int
    {
        $limit = (int) $this->option('list');
        $rows = [];
        $missing = [];

        // Children: storage/app/private/children
        $children = Child::query()->whereNotNull('photo_path')->where('photo_path', '!=', '')
            ->orderBy('id')->get(['id', 'first_name', 'last_name', 'photo_path']);
        $gone = $children->reject(fn (Child $c) => Storage::disk('local')->exists($c->photo_path));
        $rows[] = ['Children', Child::count(), $children->count(), $children->count() - $gone->count(), $gone->count(), 'storage/app/private/children'];
        $missing['Children'] = $gone->map(fn (Child $c) => "#{$c->id} {$c->first_name} {$c->last_name} -> {$c->photo_path}");

        // Staff profile photos: storage/app/public
        $users = User::query()->whereNotNull('avatar_path')->where('avatar_path', '!=', '')
            ->orderBy('id')->get(['id', 'name', 'avatar_path']);
        $gone = $users->reject(fn (User $u) => Storage::disk('public')->exists($u->avatar_path));
        $rows[] = ['Staff photos', User::count(), $users->count(), $users->count() - $gone->count(), $gone->count(), 'storage/app/public'];
        $missing['Staff photos'] = $gone->map(fn (User $u) => "#{$u->id} {$u->name} -> {$u->avatar_path}");

        // Photos and videos sent in messages: storage/app/private/messages/media
        $files = collect();
        Message::query()->whereNotNull('media')->orderBy('id')->select(['id', 'media'])
            ->chunkById(500, function ($messages) use ($files) {
                foreach ($messages as $m) {
                    foreach ((array) $m->media as $item) {
                        if (filled($item['path'] ?? null)) {
                            $files->push(['message' => $m->id, 'path' => $item['path']]);
                        }
                    }
                }
            });
        $gone = $files->reject(fn ($f) => Storage::disk('local')->exists($f['path']));
        $rows[] = ['Message photos', Message::count().' msgs', $files->count(), $files->count() - $gone->count(), $gone->count(), 'storage/app/private/messages/media'];
        $missing['Message photos'] = $gone->map(fn ($f) => "message #{$f['message']} -> {$f['path']}");

        $this->table(['Kind', 'Records', 'With a photo', 'File on server', 'File MISSING', 'Folder'], $rows);

        $total = collect($missing)->sum(fn ($list) => $list->count());
        if ($total === 0) {
            $this->info('Every photo on record is on this server.');

            return self::SUCCESS;
        }

        foreach ($missing as $kind => $list) {
            if ($list->isEmpty()) {
                continue;
            }
            $this->newLine();
            $this->warn("{$kind}: {$list->count()} file(s) missing");
            foreach ($limit > 0 ? $list->take($limit) : $list as $line) {
                $this->line('  '.$line);
            }
            if ($limit > 0 && $list->count() > $limit) {
                $this->line('  … and '.($list->count() - $limit).' more (--list=0 shows all)');
            }
        }

        $this->newLine();
        $this->line('The record names a file that is not in that folder. Upload the files there with the same names (SFTP),');
        $this->line('or add the photo again on the page. git pull never brings photos: storage/ is not in git.');

        return self::FAILURE;
    }
}
