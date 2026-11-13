<?php

namespace Ernestdefoe\Curio\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The collector badge: a member's collections the reader may see, counted for every author at once. */
class CollectorTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** Authors 3–22: 3 public, 4 members-only, 5 private, 6 only in a switched-off type, the rest public. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-curio');

        $now = Carbon::now()->toDateTimeString();
        $users = $groups = $posts = [];
        foreach (range(3, 22) as $id) {
            $users[] = ['id' => $id, 'username' => "member$id", 'email' => "member$id@machine.local", 'is_email_confirmed' => 1];
            $groups[] = ['user_id' => $id, 'group_id' => 3];
            $posts[] = ['id' => $id, 'discussion_id' => 1, 'user_id' => $id, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>', 'created_at' => $now, 'number' => $id - 2];
        }

        $this->prepareDatabase([
            User::class => $users,
            'group_user' => $groups,
            Discussion::class => [
                ['id' => 1, 'title' => 'Shelves', 'user_id' => 3, 'created_at' => $now, 'first_post_id' => 3, 'comment_count' => 20, 'last_post_number' => 20],
                ['id' => 2, 'title' => 'Alone', 'user_id' => 3, 'created_at' => $now, 'first_post_id' => 23, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [...$posts, ['id' => 23, 'discussion_id' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>', 'created_at' => $now, 'number' => 1]],
        ]);
    }

    private function seedCollections(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $types = $this->database()->table('curio_types');
        $games = (int) (clone $types)->where('slug', 'games')->value('id');
        $off = (int) (clone $types)->where('slug', 'books')->value('id');
        $this->database()->table('curio_types')->where('id', $off)->update(['enabled' => false]);

        $row = fn (int $user, string $visibility, int $type) => ['user_id' => $user, 'type_id' => $type, 'name' => 'Shelf', 'slug' => 'shelf', 'visibility' => $visibility, 'created_at' => $now, 'updated_at' => $now];
        $rows = [$row(3, 'public', $games), $row(3, 'public', $games), $row(3, 'private', $games), $row(4, 'members', $games), $row(5, 'private', $games), $row(6, 'public', $off)];
        foreach (range(7, 22) as $id) {
            $rows[] = $row($id, 'public', $games);
        }
        $this->database()->table('curio_collections')->insert($rows);
    }

    private function place(string $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-curio.collector_place', $value);
    }

    /** @return array<int, ?int> user id => curioCollections (missing key = attribute absent) */
    private function counts(int $discussion = 1, ?int $as = null, ?int &$queries = null): array
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();

        // A discussion's posts as its page loads them, each with its author.
        $response = $this->send($this->request('GET', '/api/posts', $as ? ['authenticatedAs' => $as] : [])
            ->withQueryParams(['filter' => ['discussion' => $discussion], 'include' => 'user', 'page' => ['limit' => 20]]));
        $queries = count($db->getQueryLog());
        $db->disableQueryLog();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $counts = [];
        foreach (json_decode((string) $response->getBody(), true)['included'] as $resource) {
            if ($resource['type'] === 'users' && array_key_exists('curioCollections', $resource['attributes'])) {
                $counts[(int) $resource['id']] = $resource['attributes']['curioCollections'];
            }
        }

        return $counts;
    }

    #[Test]
    public function follows_each_collections_who_can_see_this()
    {
        $this->app();
        $this->seedCollections();
        $this->place('both');

        $guest = $this->counts();
        $this->assertSame(2, $guest[3], 'public collections count, the private one does not');
        $this->assertNull($guest[4], 'members-only is not shown to guests');
        $this->assertNull($guest[5], 'private is never shown');
        $this->assertNull($guest[6], 'a switched-off type is not shown');
        $this->assertSame(1, $guest[7]);

        $member = $this->counts(as: 7);
        $this->assertSame(1, $member[4], 'members-only is shown to members');
        $this->assertNull($member[5]);

        $this->assertNull($this->counts(as: 5)[5], 'not even to its owner');
        $this->assertNull($this->counts(as: 1)[5], 'nor to an admin');
    }

    #[Test]
    public function absent_when_switched_off_or_collections_are_hidden()
    {
        $this->app();
        $this->seedCollections();

        $this->assertSame([], $this->counts(), 'off by default');

        $this->place('card');
        $this->assertNotSame([], $this->counts());

        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-curio.view')->delete();
        $this->assertSame([], $this->counts(as: 7), 'readers who cannot see collections get nothing');
    }

    #[Test]
    public function one_query_for_every_author_on_the_page()
    {
        $this->app();
        $this->seedCollections();

        // What the badge adds: the same pages with it on, less with it off (after a first
        // request has warmed what every request loads once).
        $this->counts(1, 7);
        $cost = function (int $discussion) {
            $this->place('both');
            $this->assertNotSame([], $this->counts($discussion, 7, $on));
            $this->place('none');
            $this->counts($discussion, 7, $off);

            return $on - $off;
        };

        $this->assertSame(1, $cost(2), 'one query for 1 author');
        $this->assertSame(1, $cost(1), 'and one for 20');
    }
}
