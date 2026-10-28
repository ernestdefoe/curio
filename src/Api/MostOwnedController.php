<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Curio\Type;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/curio/most-owned?offset=N: the Collections page's "Most owned",
 * ten titles at a time, ranked by how many members have one (owned or to
 * trade), counted only over collections the reader may open, of switched-on
 * types. One grouped query; each row names one copy the reader can open, so
 * the page loads title and cover the way it loads any item.
 */
class MostOwnedController implements RequestHandlerInterface
{
    public const PER_PAGE = 10;

    private const MAX_OFFSET = 1000;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('ernestdefoe-curio.view');

        $offset = min(self::MAX_OFFSET, max(0, (int) Arr::get($request->getQueryParams(), 'offset', 0)));

        $query = Item::query();
        // 🚨 Raw SQL gets no table prefix: the columns go through the grammar.
        $wrap = fn (string $column) => $query->getQuery()->getGrammar()->wrap($column);

        $rows = $query->whereVisibleTo($actor)
            ->whereNotNull('curio_items.work_id')
            ->whereIn('curio_items.status', ['owned', 'trade'])
            ->whereIn('curio_items.collection_id', Collection::query()
                ->whereIn('curio_collections.type_id', Type::query()->where('enabled', true)->select('curio_types.id'))
                ->select('curio_collections.id'))
            ->toBase()
            ->select('curio_items.work_id')
            ->selectRaw('count(distinct '.$wrap('curio_items.user_id').') as owners, min('.$wrap('curio_items.id').') as item_id')
            ->groupBy('curio_items.work_id')
            ->orderByDesc('owners')->orderBy('item_id')
            ->offset($offset)->limit(self::PER_PAGE + 1)
            ->get();

        return new JsonResponse([
            'data' => $rows->take(self::PER_PAGE)->map(fn ($row) => [
                'workId' => (string) $row->work_id,
                'itemId' => (string) $row->item_id,
                'owners' => (int) $row->owners,
            ])->values()->all(),
            'more' => $rows->count() > self::PER_PAGE,
        ]);
    }
}
