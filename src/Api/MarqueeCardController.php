<?php

namespace Ernestdefoe\Curio\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/curio/marquee-card?kind=game&id=1942: what the discussion page sends
 * as curioMarquee, for a related title a reader switches Marquee's card to (the
 * page itself only carries the linked title's). Asked once per title, on the switch.
 */
class MarqueeCardController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('ernestdefoe-curio.view');

        $query = $request->getQueryParams();
        $kind = (string) Arr::get($query, 'kind');
        $id = (string) Arr::get($query, 'id');

        if (! isset(MarqueeCard::SOURCES[$kind]) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return new JsonResponse(['errors' => [['status' => '422', 'code' => 'validation_error']]], 422);
        }

        return new JsonResponse(['data' => MarqueeCard::for((object) ['kind' => $kind, 'external_id' => $id], $actor)]);
    }
}
