<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Settings;
use Ernestdefoe\Curio\Type;
use Ernestdefoe\Curio\Storage\Images;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/curio/overview: what the forum collects, per type, for the browse
 * page's tiles. Counted once every ten minutes per audience (guests see public
 * collections, members also members-only ones, moderators every one) and shared
 * by that audience, so nobody is counted a collection they could not open. A
 * member's own private collections are added on top, from one small query.
 * Each type also names its latest collection that audience can open, for the
 * tags-page layout's footer line.
 */
class OverviewController implements RequestHandlerInterface
{
    private const SECONDS = 600;

    private const AUDIENCES = ['guests' => ['public'], 'members' => ['public', 'members'], 'moderators' => null];

    public function __construct(private Cache $cache, private Images $images, private SettingsRepositoryInterface $settings)
    {
    }

    /** After a change to what is collected: every audience counts again. */
    public static function forget(Cache $cache): void
    {
        foreach (array_keys(self::AUDIENCES) as $audience) {
            $cache->forget("curio.overview.4.$audience");
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('ernestdefoe-curio.view');

        $audience = $actor->hasPermission('ernestdefoe-curio.moderate') ? 'moderators' : ($actor->isGuest() ? 'guests' : 'members');
        $overview = $this->cache->remember("curio.overview.4.$audience", self::SECONDS, fn () => $this->count(self::AUDIENCES[$audience]));

        if ($audience === 'members') {
            $overview = $this->withOwnPrivate($overview, $actor->id);
        }
        $overview['types'] = (object) $overview['types'];

        // The layout is read fresh; only the counting is cached.
        $layout = $this->settings->get(Settings::BROWSE_LAYOUT);

        return new JsonResponse([
            'layout' => in_array($layout, Settings::BROWSE_LAYOUTS, true) ? $layout : 'tags',
            'typeColor' => $this->settings->get(Settings::TYPE_COLOR) === 'text' ? 'text' : 'background',
            'hideEmpty' => (bool) $this->settings->get(Settings::HIDE_EMPTY),
            'latest' => (bool) $this->settings->get(Settings::LATEST_CHANGES),
        ] + $overview);
    }

    /** @param list<string>|null $visibilities null = every collection */
    private function count(?array $visibilities): array
    {
        // Collections of switched-on types that this audience can open.
        $open = Collection::query()->whereIn('type_id', Type::query()->where('enabled', true)->select('id'))
            ->when($visibilities, fn ($q) => $q->whereIn('visibility', $visibilities));

        $types = [];
        $rows = (clone $open)->toBase()
            ->selectRaw('type_id, sum(owned_count) as total, count(*) as collections')->groupBy('type_id')->get();

        foreach ($rows as $row) {
            $types[(string) $row->type_id] = ['items' => (int) $row->total, 'collections' => (int) $row->collections, 'covers' => []];
        }

        foreach (array_keys($types) as $typeId) {
            $latest = (clone $open)->where('type_id', $typeId)->where('owned_count', '>', 0)
                ->orderByDesc('updated_at')->limit(3)->get(['id', 'name', 'slug', 'preview', 'updated_at']);

            $types[$typeId]['covers'] = $latest->map(fn (Collection $c) => $this->images->url($c->preview[0] ?? null, true))->filter()->values()->all();
            $first = $latest->first();
            $types[$typeId]['latest'] = $first ? ['id' => (string) $first->id, 'name' => $first->name, 'slug' => $first->slug, 'updatedAt' => $first->updated_at?->toIso8601String()] : null;
        }

        return [
            'types' => $types,
            'items' => array_sum(array_column($types, 'items')),
            'members' => (int) (clone $open)->distinct()->count('user_id'),
        ];
    }

    /** A member's own private collections count for them (and only them). */
    private function withOwnPrivate(array $overview, int $userId): array
    {
        $own = Collection::query()->where('user_id', $userId)
            ->whereIn('type_id', Type::query()->where('enabled', true)->select('id'))
            ->toBase()->get(['type_id', 'visibility', 'owned_count']);
        $private = $own->where('visibility', 'private');

        foreach ($private as $c) {
            $type = &$overview['types'][(string) $c->type_id];
            $type ??= ['items' => 0, 'collections' => 0, 'covers' => [], 'latest' => null];
            $type['items'] += (int) $c->owned_count;
            $type['collections']++;
            unset($type);
        }

        $overview['items'] += (int) $private->sum('owned_count');
        // Counted among the members only when nothing else of theirs already was.
        $overview['members'] += $private->isNotEmpty() && $private->count() === $own->count() ? 1 : 0;

        return $overview;
    }
}
