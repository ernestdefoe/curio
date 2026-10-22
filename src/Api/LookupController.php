<?php

namespace Ernestdefoe\Curio\Api;

use Ernestdefoe\Curio\Lookup\Barcode;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Lookup\CardLanguages;
use Ernestdefoe\Curio\Lookup\LookupException;
use Ernestdefoe\Curio\Lookup\Paged;
use Ernestdefoe\Curio\Lookup\Sources;
use Ernestdefoe\Curio\Lookup\WorkResolver;
use Ernestdefoe\Curio\Type;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/curio/lookup
 *   {type, q, page?, lang?} → title search ({results, more}), or a barcode/ISBN when q is one;
 *                       lang = the card language picked ("ja"), for card sources
 *   {source, id}      → {workId}: the stored work for a result (fetched once, shared)
 *
 * POST so it carries the CSRF token; each call can cost the forum outbound
 * requests, so members get a few dozen a minute.
 */
class LookupController implements RequestHandlerInterface
{
    use Throttle;

    /** "More results" goes this deep (a dozen at a time). */
    private const MAX_PAGE = 30;

    public function __construct(
        private Sources $sources,
        private BarcodeLookup $barcodes,
        private WorkResolver $works,
        private Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('ernestdefoe-curio.create');

        if ($limited = $this->throttled($this->cache, $actor)) {
            return $limited;
        }

        $body = (array) $request->getParsedBody();

        try {
            return isset($body['source'])
                ? $this->resolve($body)
                : $this->search($body, $actor->id);
        } catch (LookupException $e) {
            return new JsonResponse(['errors' => [['status' => $e->outage ? '503' : '404', 'code' => $e->outage ? 'source_unavailable' : 'not_found']]], $e->outage ? 503 : 404);
        }
    }

    private function search(array $body, int $actorId): ResponseInterface
    {
        $type = Type::query()->find((int) Arr::get($body, 'type'));
        $q = trim(mb_substr((string) Arr::get($body, 'q', ''), 0, 120));

        if (! $type || $q === '') {
            return new JsonResponse(['results' => []]);
        }

        if ($ean = Barcode::normalise($q)) {
            $found = $this->barcodes->lookup($type, $ean, $actorId);
            $found['results'] = array_map(fn ($r) => Arr::only($r, ['source', 'id', 'workId', 'title', 'creator', 'year', 'cover']), $found['results']);
            // An object even when empty, so the client can merge it into an item's details.
            $found['fields'] = (object) $found['fields'];

            return new JsonResponse(['barcode' => $found] + ['results' => $found['results']]);
        }

        $source = $this->sources->forType($type);
        $lang = Arr::get($body, 'lang');
        if ($source instanceof CardLanguages && is_string($lang)) {
            $source = $source->inLanguage(substr($lang, 0, 8));
        }
        $page = max(1, min(self::MAX_PAGE, (int) Arr::get($body, 'page', 1)));
        [$results, $more] = $source instanceof Paged ? $source->page($q, $page) : [$source && $page === 1 ? $source->search($q) : [], false];

        // A result names its source when one search asks several (trading cards).
        return new JsonResponse(['results' => array_map(fn ($r) => $r + ['source' => $source?->key()], $results), 'more' => $more && $page < self::MAX_PAGE]);
    }

    private function resolve(array $body): ResponseInterface
    {
        $source = (string) Arr::get($body, 'source');
        $id = trim((string) Arr::get($body, 'id'));

        if (! preg_match('/^[a-z-]{2,30}$/', $source) || $id === '' || strlen($id) > 100) {
            throw new LookupException('Bad look-up');
        }

        return new JsonResponse(['workId' => (string) $this->works->resolve($source, $id)->id]);
    }
}
