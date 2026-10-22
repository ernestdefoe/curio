<?php

namespace Ernestdefoe\Curio\Console;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\CollectionStats;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Job\NotifyWishlists;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Sources;
use Ernestdefoe\Curio\Lookup\WorkResolver;
use Ernestdefoe\Curio\Work;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Queue\Queue;
use Symfony\Component\Console\Input\InputOption;

/**
 * Finds covers and texts for imported items, a few at a time (scheduled every
 * five minutes), so a 400-record import never holds up a request or floods a
 * look-up service. A barcode or ISBN is used when the file had one; otherwise
 * the title and creator are searched, and only a confident match is taken:
 * the same title, and the same creator when both are known.
 */
class MatchWorks extends AbstractCommand
{
    /** Works refreshed before this date are cards waiting for their artist (see migration 2026_10_22_000005). */
    public const CARD_MARK = '2001-01-01 00:00:00';

    private const CARDS_PER_RUN = 20;

    public function __construct(private Queue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('curio:match-works')
            ->setDescription('Find covers and descriptions for imported items.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many items to try', '25');
    }

    protected function fire(): int
    {
        $sources = resolve(Sources::class);
        $barcodes = resolve(BarcodeLookup::class);
        $works = resolve(WorkResolver::class);
        $matched = $tried = 0;
        $touched = [];

        $items = Item::query()->where('needs_match', true)->with('collection.type')->orderBy('id')
            ->limit(max(1, (int) $this->input->getOption('limit')))->get();

        foreach ($items as $item) {
            $tried++;
            $type = $item->collection?->type;
            $item->needs_match = false;

            try {
                $hit = null;

                if ($type && $item->barcode) {
                    $hit = $barcodes->lookup($type, $item->barcode, $item->user_id)['results'][0] ?? null;
                }

                $source = $type ? $sources->forType($type) : null;
                if (! $hit && $source) {
                    // A card's number finds its printing among hundreds of the same name.
                    $number = in_array($source->key(), ['tcgdex', 'scryfall', 'ygoprodeck', 'cards'], true) && is_string($item->fields['number'] ?? null) ? $item->fields['number'] : '';
                    $query = trim($item->title.' '.($number !== '' ? $number : $item->subtitle));
                    foreach (array_slice($source->search($query), 0, 5) as $result) {
                        // A card is printed many times: only the printing with the item's own number is taken.
                        if (isset($result['number']) && ! self::sameNumber($result['number'], $item->fields['number'] ?? null)) {
                            continue;
                        }
                        if (self::same($result['title'], $item->title) && (! $item->subtitle || ! $result['creator'] || self::same($result['creator'], $item->subtitle, true))) {
                            // Mixed card results name their own source.
                            $hit = $result + ['source' => $source->key()];
                            break;
                        }
                    }
                }

                if ($hit) {
                    $work = isset($hit['workId']) ? Work::query()->find($hit['workId']) : $works->resolve((string) $hit['source'], (string) $hit['id']);
                    if ($work) {
                        $item->work_id = $work->id;
                        $item->year ??= $work->year;
                        $matched++;
                    }
                }
            } catch (LookupException $e) {
                // A service is down: try this one again on a later run.
                $item->needs_match = $e->outage;
            }

            $item->save();
            $touched[$item->collection_id] = $item->collection;

            if ($item->work_id && $item->status === 'trade') {
                $this->queue->push(new NotifyWishlists($item->id));
            }
        }

        foreach (array_filter($touched) as $collection) {
            /** @var Collection $collection */
            resolve(CollectionStats::class)->refresh($collection);
        }

        // Cards stored before 1.9 (marked by its migration): their artist and finishes, a few a run.
        $cards = 0;
        foreach (Work::query()->whereIn('source', WorkResolver::CARD_SOURCES)->where('refreshed_at', '<', self::CARD_MARK)->limit(self::CARDS_PER_RUN)->get() as $work) {
            try {
                $works->refreshCard($work);
                $cards++;
            } catch (LookupException $e) {
                break;
            }
        }

        $this->info("Matched $matched of $tried.".($cards ? " Refreshed $cards cards." : ''));

        return 0;
    }

    /** The same name, give or take case, punctuation and a leading "The"; a creator may be one of several. */
    private static function same(string $a, string $b, bool $partial = false): bool
    {
        $n = fn (string $s) => trim(preg_replace('/^the\s+/', '', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)) ?? '') ?? '');
        [$a, $b] = [$n($a), $n($b)];

        return $a !== '' && ($a === $b || ($partial && (str_contains($a, $b) || str_contains($b, $a))));
    }

    /** "136/189", "136" and "0136" are one card number; no number on the item matches nothing. */
    private static function sameNumber(string $found, mixed $own): bool
    {
        $n = fn (string $s) => ltrim(strtolower(trim(explode('/', $s)[0])), '0');

        return is_scalar($own) && trim((string) $own) !== '' && $n($found) === $n((string) $own);
    }
}
