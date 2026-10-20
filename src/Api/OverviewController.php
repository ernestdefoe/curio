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
 * page's tiles. Counted from public collections only, once every ten minutes,
 * and shared by everyone (so a members-only or private collection never shows
 * through, whoever asks). Each type also names its latest public collection,
 * for the tags-page layout's footer line.
 */
class OverviewController implements RequestHandlerInterface
{
    private const SECONDS = 600;

    public const CACHE_KEY = 'curio.overview.3';

    public function __construct(private Cache $cache, private Images $images, private SettingsRepositoryInterface $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('ernestdefoe-curio.view');

        // The layout is read fresh; only the counting is cached.
        $layout = $this->settings->get(Settings::BROWSE_LAYOUT) === 'covers' ? 'covers' : 'tags';

        return new JsonResponse(['layout' => $layout] + $this->cache->remember(self::CACHE_KEY, self::SECONDS, function () {
            // Public collections of switched-on types: what a guest can see.
            $public = Collection::query()->where('visibility', 'public')
                ->whereIn('type_id', Type::query()->where('enabled', true)->select('id'));

            $types = [];
            $rows = (clone $public)->toBase()
                ->selectRaw('type_id, sum(owned_count) as total, count(*) as collections')->groupBy('type_id')->get();

            foreach ($rows as $row) {
                $types[(string) $row->type_id] = ['items' => (int) $row->total, 'collections' => (int) $row->collections, 'covers' => []];
            }

            foreach (array_keys($types) as $typeId) {
                $latest = (clone $public)->where('type_id', $typeId)->where('owned_count', '>', 0)
                    ->orderByDesc('updated_at')->limit(3)->get(['id', 'name', 'slug', 'preview', 'updated_at']);

                $types[$typeId]['covers'] = $latest->map(fn (Collection $c) => $this->images->url($c->preview[0] ?? null, true))->filter()->values()->all();
                $first = $latest->first();
                $types[$typeId]['latest'] = $first ? ['id' => (string) $first->id, 'name' => $first->name, 'slug' => $first->slug, 'updatedAt' => $first->updated_at?->toIso8601String()] : null;
            }

            return [
                'types' => (object) $types,
                'items' => array_sum(array_column($types, 'items')),
                'members' => (int) (clone $public)->distinct()->count('user_id'),
            ];
        }));
    }
}
