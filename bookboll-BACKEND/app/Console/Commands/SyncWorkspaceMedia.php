<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Copy public snapshot images once. Never touches private contracts or overwrites uploads. */
class SyncWorkspaceMedia extends Command
{
    protected $signature = 'workspace:sync-media';
    protected $description = 'Copy missing public snapshot images to the Railway upload volume';

    public function handle(): int
    {
        if (!config('preview.workspace') || DB::connection()->getDatabaseName() !== 'bookboll_preview') return self::SUCCESS;
        $fields = [
            'listings'=>['photo_url','photos','images_urls'], 'venues'=>['photo_url','photos'],
            'businesses'=>['brand_logo_url','hero_banners','brand_pages'],
            'class_programs'=>['photo_url','images'], 'communities'=>['cover_image','images','avatar_image'],
            'posts'=>['featured_image'], 'users'=>['image'], 'settings'=>['value'],
            'sports_categories'=>['image','icon'], 'sports_types'=>['image','icon'],
            'uploads'=>['path'], 'assets'=>['path'],
        ];
        $paths = [];
        $scan = function ($value) use (&$scan, &$paths) {
            if (is_array($value)) { foreach ($value as $item) $scan($item); return; }
            if (!is_string($value) || $value === '') return;
            $json = json_decode($value, true);
            if (is_array($json)) { $scan($json); return; }
            $path = parse_url(str_replace('\\/', '/', $value), PHP_URL_PATH);
            if (!is_string($path)) return;
            $path = preg_replace('#^/?storage/#', '', $path);
            if (str_contains($path, '..') || !preg_match('#^(?:listings|venues|uploads|users|posts|communities|sport-categories|sport-types|class-programs)/[a-zA-Z0-9_./-]+\.(?:jpe?g|png|webp|gif|ico)$#i', $path)) return;
            $paths[$path] = true;
        };
        foreach ($fields as $table=>$columns) {
            if (!Schema::hasTable($table)) continue;
            $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if (!$columns) continue;
            $q = DB::table($table)->select(array_merge(['id'], $columns));
            if ($table === 'uploads') $q->where('disk','public');
            $q->chunkById(500, function ($rows) use ($columns, $scan) {
                foreach ($rows as $row) foreach ($columns as $column) $scan($row->{$column});
            });
        }
        $disk = Storage::disk('public');
        $missing = array_values(array_filter(array_keys($paths), fn ($path) => !$disk->exists($path)));
        $copied = 0; $failed = 0;
        foreach (array_chunk($missing, 8) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(fn ($path) => $pool->as($path)->timeout(12)
                ->get('https://newbackend.bookboll.com/storage/'.$path), $batch));
            foreach ($responses as $path=>$response) {
                if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()
                    || strlen($response->body()) > 10 * 1024 * 1024 || !@getimagesizefromstring($response->body())) {
                    $failed++; continue;
                }
                if (!$disk->exists($path)) $disk->put($path, $response->body());
                $copied++;
            }
        }
        $this->info("Public media: {$copied} copied; {$failed} unavailable; existing files preserved.");
        return self::SUCCESS;
    }
}
