<?php

namespace Ernestdefoe\Curio\Console;

use Carbon\Carbon;
use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\CollectionStats;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\WorkResolver;
use Ernestdefoe\Curio\Translation\WorkTexts;
use Ernestdefoe\Curio\Type;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Brings Shelf's "In my collection" titles into Curio, once: each member gets a
 * collection per kind ("Video games (from Shelf)"), each title becomes an item
 * with its looked-up cover and texts. Titles already in that collection are
 * skipped, so running it twice adds nothing.
 */
class ImportShelf extends AbstractCommand
{
    /** Marquee's kind → the preset type's slug, and the source Curio looks it up with. */
    private const KINDS = [
        'movie' => ['films', 'marquee-movie'],
        'tv' => ['films', 'marquee-tv'],
        'game' => ['games', 'marquee-game'],
        'music' => ['music', 'deezer'],
        'book' => ['books', 'openlibrary'],
    ];

    protected function configure(): void
    {
        $this->setName('curio:import-shelf')
            ->setDescription('Copy the titles members marked "In my collection" in Shelf into Curio collections.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be imported, change nothing');
    }

    protected function fire(): int
    {
        $db = resolve(ConnectionInterface::class);

        if (! $db->getSchemaBuilder()->hasTable('shelf_entries') || ! $db->getSchemaBuilder()->hasTable('marquee_media')) {
            $this->error('Shelf is not installed here.');

            return 1;
        }

        $dry = (bool) $this->input->getOption('dry-run');
        $works = resolve(WorkResolver::class);
        $stats = resolve(CollectionStats::class);
        $locale = WorkTexts::base((string) resolve(SettingsRepositoryInterface::class)->get('default_locale', 'en'));
        $types = Type::query()->get()->keyBy('slug');
        $added = $skipped = 0;
        $touched = [];

        $db->table('shelf_entries')
            ->join('marquee_media', 'marquee_media.id', '=', 'shelf_entries.media_id')
            ->where('shelf_entries.owned', true)
            ->orderBy('shelf_entries.user_id')
            ->select(['shelf_entries.user_id', 'marquee_media.kind', 'marquee_media.external_id', 'marquee_media.title', 'marquee_media.year', 'shelf_entries.rating'])
            ->chunk(200, function ($rows) use ($dry, $works, $types, $locale, &$added, &$skipped, &$touched) {
                foreach ($rows as $row) {
                    [$slug, $source] = self::KINDS[$row->kind] ?? [null, null];
                    $type = $slug ? $types->get($slug) : null;

                    if (! $type) {
                        $skipped++;
                        continue;
                    }

                    if ($dry) {
                        $added++;
                        continue;
                    }

                    $collection = $this->collectionFor((int) $row->user_id, $type, $locale);

                    try {
                        $work = $works->resolve($source, (string) $row->external_id);
                    } catch (LookupException) {
                        $work = null;
                    }

                    $exists = Item::query()->where('collection_id', $collection->id)
                        ->where(fn ($q) => $work ? $q->where('work_id', $work->id) : $q->where('title', $row->title))
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $item = new Item();
                    $item->collection_id = $collection->id;
                    $item->user_id = (int) $row->user_id;
                    $item->work_id = $work?->id;
                    $item->title = mb_substr($work->title ?? (string) $row->title, 0, 255);
                    $item->subtitle = $work?->creator;
                    $item->year = $work->year ?? ($row->year ? (int) $row->year : null);
                    $item->status = 'owned';
                    $item->favourite = false;
                    // Shelf rates in the same half stars (1-10).
                    $item->rating = $row->rating >= 1 && $row->rating <= 10 ? (int) $row->rating : null;
                    $item->position = (int) Item::query()->where('collection_id', $collection->id)->max('position') + 1;
                    $item->save();

                    $touched[$collection->id] = $collection;
                    $added++;
                }
            });

        foreach ($touched as $collection) {
            $stats->refresh($collection);
        }

        $titles = $added === 1 ? '1 title' : "$added titles";
        $this->info($dry
            ? "Would import $titles; $skipped skipped."
            : "Imported $titles into ".count($touched).' collection(s); '.$skipped.' already there or of an unknown kind.');

        return 0;
    }

    private function collectionFor(int $userId, Type $type, string $locale): Collection
    {
        $name = mb_substr(($type->names[$locale] ?? $type->names['en'] ?? $type->slug).' (Shelf)', 0, 100);

        $collection = Collection::query()->where('user_id', $userId)->where('type_id', $type->id)->where('name', $name)->first();

        if ($collection) {
            return $collection;
        }

        $collection = new Collection();
        $collection->user_id = $userId;
        $collection->type_id = $type->id;
        $collection->name = $name;
        $collection->slug = Str::slug($name) ?: 'collection';
        $collection->visibility = 'public';
        $collection->wishlist_public = true;
        $collection->position = (int) Collection::query()->where('user_id', $userId)->max('position') + 1;
        $collection->created_at = Carbon::now();
        $collection->save();

        return $collection;
    }
}
