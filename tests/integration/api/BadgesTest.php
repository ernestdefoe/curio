<?php

namespace Ernestdefoe\Curio\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Badges\EvaluateBadges;
use Ernestdefoe\Curio\Event\CollectionCreated;
use Ernestdefoe\Curio\Event\ItemsChanged;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\Badges\Badge;
use FoF\Badges\Trigger\MetricManager;
use FoF\Badges\Trigger\TriggerEvaluator;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/** FoF Badges triggers: items in a member's collections and collections made. */
class BadgesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private function setUpForum(bool $badges): void
    {
        $badges ? $this->extension('fof-badges', 'ernestdefoe-curio') : $this->extension('ernestdefoe-curio');

        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'collector', 'email' => 'collector@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 3],
            ],
        ]);

        if (! $badges) {
            return;
        }

        // Added once the app is up, so the seeded types' ids can be looked up.
        $now = Carbon::now()->toDateTimeString();
        $badge = fn (int $id, string $metric, int $value, array $extra = []) => [
            'id' => $id, 'name' => "Badge $id", 'slug' => "badge-$id", 'created_at' => $now, 'updated_at' => $now,
            'trigger_config' => json_encode(['logic' => 'AND', 'conditions' => [['metric' => $metric, 'operator' => '>=', 'value' => $value]]] + $extra),
        ];

        $this->database()->table('fof_badges')->insert([
            $badge(1, 'curio_items', 2),
            $badge(2, 'curio_items', 2, ['curio_type_id' => $this->typeId('books')]),
            $badge(3, 'curio_collections', 2),
        ]);
    }

    private function typeId(string $slug): int
    {
        return (int) $this->database()->table('curio_types')->where('slug', $slug)->value('id');
    }

    private function collection(string $type): int
    {
        $response = $this->send($this->request('POST', '/api/curio-collections', [
            'authenticatedAs' => 3,
            'json' => ['data' => [
                'type' => 'curio-collections',
                'attributes' => ['name' => "My $type"],
                'relationships' => ['type' => ['data' => ['type' => 'curio-types', 'id' => (string) $this->typeId($type)]]],
            ]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function item(int $collection, array $attributes): ResponseInterface
    {
        $response = $this->send($this->request('POST', '/api/curio-items', [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'curio-items', 'attributes' => $attributes, 'relationships' => [
                'collection' => ['data' => ['type' => 'curio-collections', 'id' => (string) $collection]],
            ]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /** @return list<int> */
    private function earned(): array
    {
        return $this->database()->table('fof_badge_user')->where('user_id', 3)->orderBy('badge_id')->pluck('badge_id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function metrics_are_registered_while_fof_badges_is_enabled(): void
    {
        $this->setUpForum(true);
        $metrics = $this->app()->getContainer()->make(MetricManager::class);

        $this->assertTrue($metrics->isAvailable('curio_items'));
        $this->assertTrue($metrics->isAvailable('curio_collections'));
        $this->assertSame(['curio_items'], $metrics->getTypesForEvent(ItemsChanged::class));
        $this->assertSame(['curio_collections'], $metrics->getTypesForEvent(CollectionCreated::class));
    }

    #[Test]
    public function badges_are_awarded_at_the_threshold(): void
    {
        $this->setUpForum(true);

        $games = $this->collection('games');
        $this->assertSame([], $this->earned());

        $this->item($games, ['title' => 'Hades']);
        $this->item($games, ['title' => 'EarthBound', 'status' => 'wishlist']);
        $this->assertSame([], $this->earned(), 'one item and a wish: not yet two items');

        $this->item($games, ['title' => 'Celeste', 'status' => 'trade']);
        $this->assertSame([1], $this->earned(), 'two items: the any-type badge, not the books one');

        $books = $this->collection('books');
        $this->assertSame([1, 3], $this->earned(), 'two collections');

        $this->item($books, ['title' => 'Dune']);
        $this->assertSame([1, 3], $this->earned());
        $this->item($books, ['title' => 'Emma']);
        $this->assertSame([1, 2, 3], $this->earned(), 'two books');
    }

    #[Test]
    public function existing_collections_count_when_badges_are_recalculated(): void
    {
        $this->setUpForum(true);
        $books = $this->collection('books');
        $this->item($books, ['title' => 'Dune']);
        $this->item($books, ['title' => 'Emma']);
        $this->database()->table('fof_badge_user')->delete();

        $evaluator = $this->app()->getContainer()->make(TriggerEvaluator::class);
        $user = User::query()->find(3);

        $this->assertTrue($evaluator->evaluate($user, Badge::query()->find(2)));
        $this->assertFalse($evaluator->evaluate($user, Badge::query()->find(3)));
    }

    #[Test]
    public function nothing_of_fof_badges_is_loaded_without_it(): void
    {
        $this->setUpForum(false);

        $games = $this->collection('games');
        $this->item($games, ['title' => 'Hades']);
        $this->item($games, ['title' => 'Celeste']);

        $events = $this->app()->getContainer()->make(Dispatcher::class);
        $this->assertFalse($events->hasListeners(ItemsChanged::class));
        $this->assertFalse($events->hasListeners(CollectionCreated::class));
        $this->assertFalse($this->app()->getContainer()->bound(MetricManager::class));
        $this->assertFalse(class_exists(EvaluateBadges::class, false), 'the listener class was never loaded');
    }
}
