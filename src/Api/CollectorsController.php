<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Collection;
use Ernestdefoe\Curio\Type;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/curio/collectors?offset=N: the Collections page's "Top collectors",
 * ten members at a time, ranked by items owned, then by collections.
 *
 * Counted only over what the reader may open: collections they can see (of
 * switched-on types, by members they can see), and wishlist items only where
 * that wishlist is shown to them. One grouped query over the collections'
 * stored counts, then one for the ten members; never a query per member.
 */
class CollectorsController implements RequestHandlerInterface
{
    public const PER_PAGE = 10;

    /** "More" goes this deep. */
    private const MAX_OFFSET = 1000;

    public function __construct(private SlugManager $slugs)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('ernestdefoe-curio.view');

        $offset = min(self::MAX_OFFSET, max(0, (int) Arr::get($request->getQueryParams(), 'offset', 0)));

        $query = Collection::query()->whereVisibleTo($actor)
            ->whereIn('curio_collections.type_id', Type::query()->where('enabled', true)->select('curio_types.id'))
            ->whereIn('curio_collections.user_id', User::query()->whereVisibleTo($actor)->select('users.id'))
            ->toBase()
            ->select('user_id')
            ->selectRaw('count(*) as collections, sum(owned_count) as owned');

        // A wishlist counts where it is shown to the reader, as its items are (ScopeItemVisibility).
        if ($actor->hasPermission('ernestdefoe-curio.moderate')) {
            $query->selectRaw('sum(wish_count) as wishlist');
        } else {
            $query->selectRaw('sum(case when wishlist_public = ? or user_id = ? then wish_count else 0 end) as wishlist', [true, $actor->isGuest() ? 0 : $actor->id]);
        }

        $rows = $query->groupBy('user_id')
            ->orderByDesc('owned')->orderByDesc('collections')->orderBy('user_id')
            ->offset($offset)->limit(self::PER_PAGE + 1)
            ->get();

        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);
        $users = $rows->isEmpty() ? collect() : User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $slugger = $this->slugs->forResource(User::class);

        return new JsonResponse([
            'data' => $rows->filter(fn ($row) => $users->has($row->user_id))->map(fn ($row) => [
                'userId' => (string) $row->user_id,
                'collections' => (int) $row->collections,
                'owned' => (int) $row->owned,
                'wishlist' => (int) $row->wishlist,
            ])->values()->all(),
            // Just what an avatar and a profile link need, for the client's store.
            'users' => $users->map(fn (User $user) => [
                'type' => 'users',
                'id' => (string) $user->id,
                'attributes' => [
                    'username' => $user->username,
                    'displayName' => $user->display_name,
                    'avatarUrl' => $user->avatar_url,
                    'slug' => $slugger->toSlug($user),
                ],
            ])->values()->all(),
            'more' => $more,
        ]);
    }
}
