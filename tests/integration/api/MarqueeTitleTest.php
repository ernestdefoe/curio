<?php

namespace Ernestdefoe\Curio\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Api\MarqueeTitle;
use Ernestdefoe\Curio\Item;
use Ernestdefoe\Marquee\Link;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Show it off finds the discussion Marquee gave a title: its own card first,
 * else one listing it among related titles, only where the reader can see it.
 * Runs against whichever Marquee is installed: 1.21+ keeps links in its own
 * tables, older releases on `discussions`.
 */
class MarqueeTitleTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-marquee', 'ernestdefoe-curio');

        $now = Carbon::now()->toDateTimeString();
        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'marquee_media' => [
                ['id' => 5, 'kind' => 'game', 'external_id' => '7346', 'title' => 'Zelda', 'fetched_at' => $now, 'data' => '{}'],
                ['id' => 6, 'kind' => 'game', 'external_id' => '1', 'title' => 'Other', 'fetched_at' => $now, 'data' => '{}'],
            ],
            'curio_works' => [
                ['id' => 50, 'source' => 'marquee-game', 'source_id' => '7346', 'title' => 'Zelda', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 0],
            ],
            Discussion::class => [
                ['id' => 11, 'title' => 'The series', 'slug' => 'series', 'user_id' => 1, 'created_at' => $now, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 12, 'title' => 'Zelda', 'slug' => 'zelda', 'user_id' => 1, 'created_at' => $now, 'comment_count' => 1, 'is_private' => 0],
            ],
        ]);
    }

    /** @param list<int> $related */
    private function link(int $discussion, int $media, array $related = []): void
    {
        $db = $this->database();

        if (class_exists(Link::class)) {
            $db->table('marquee_discussions')->insert(['discussion_id' => $discussion, 'media_id' => $media]);
            foreach ($related as $position => $id) {
                $db->table('marquee_related')->insert(['discussion_id' => $discussion, 'media_id' => $id, 'position' => $position]);
            }
        } else {
            $db->table('discussions')->where('id', $discussion)->update(['marquee_media_id' => $media, 'marquee_related' => $related ? json_encode($related) : null]);
        }
    }

    private function find(int $as = 2): ?array
    {
        return MarqueeTitle::for((new Item())->forceFill(['work_id' => 50]), User::query()->findOrFail($as))['discussion'];
    }

    #[Test]
    public function the_titles_own_card_comes_before_a_card_listing_it_as_related(): void
    {
        $this->link(11, 6, [5]);
        $this->assertSame(['id' => '11', 'slug' => 'series'], $this->find());

        $this->link(12, 5);
        $this->assertSame(['id' => '12', 'slug' => 'zelda'], $this->find());
    }

    #[Test]
    public function a_discussion_the_reader_cannot_see_is_never_offered(): void
    {
        $this->link(11, 6, [5]);
        $this->link(12, 5);
        $this->database()->table('discussions')->where('id', 12)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);

        $this->assertSame(['id' => '11', 'slug' => 'series'], $this->find());

        $this->database()->table('discussions')->where('id', 11)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);
        $this->assertNull($this->find());
    }

    #[Test]
    public function a_title_nothing_links_to_has_no_discussion(): void
    {
        $this->link(11, 6);
        $this->assertNull($this->find());
    }
}
