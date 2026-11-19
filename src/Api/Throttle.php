<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Allowance;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;

/** Caps how many outbound look-ups one member can make the forum send. */
trait Throttle
{
    private function throttled(Cache $cache, User $actor, int $perMinute = 30): ?JsonResponse
    {
        if ($actor->isAdmin()) {
            return null;
        }

        if (! Allowance::spend($cache, 'curio.throttle.'.$actor->id.'.'.intdiv(time(), 60), $perMinute, 120)) {
            return new JsonResponse(['errors' => [['status' => '429', 'code' => 'too_many_requests']]], 429);
        }

        return null;
    }
}
