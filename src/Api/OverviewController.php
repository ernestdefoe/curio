<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Storage\Images;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/curio/overview: what the forum collects, per type, for the browse
 * page's tiles. Counted from public collections only, once every ten minutes,
 * and shared by everyone (so a members-only or private collection never shows
 * through, whoever asks).
 */
class OverviewController implements RequestHandlerInterface
{
    private const SECONDS = 600;

    public function __construct(private Cache $cache, private Images $images, private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('ernestdefoe-curio.view');

        return new JsonResponse($this->cache->remember('curio.overview', self::SECONDS, function () {
            $public = Collection::query()->where('visibility', 'public');

            $types = [];
            $rows = $this->db->table('curio_collections')->where('visibility', 'public')
                ->selectRaw('type_id, sum(owned_count) as total, count(*) as collections')->groupBy('type_id')->get();

            foreach ($rows as $row) {
                $types[(string) $row->type_id] = ['items' => (int) $row->total, 'collections' => (int) $row->collections, 'covers' => []];
            }

            foreach (array_keys($types) as $typeId) {
                $types[$typeId]['covers'] = (clone $public)->where('type_id', $typeId)->where('owned_count', '>', 0)
                    ->orderByDesc('updated_at')->limit(3)->get(['preview'])
                    ->map(fn (Collection $c) => $this->images->url($c->preview[0] ?? null, true))->filter()->values()->all();
            }

            return [
                'types' => (object) $types,
                'items' => array_sum(array_column($types, 'items')),
                'members' => (int) (clone $public)->distinct()->count('user_id'),
            ];
        }));
    }
}
