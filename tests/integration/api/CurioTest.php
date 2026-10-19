<?php

namespace Ernestdefoe\Curio\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Curio\Lookup\BarcodeLookup;
use Ernestdefoe\Curio\Lookup\UpcItemDb;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class CurioTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-curio');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'collector', 'email' => 'collector@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'visitor', 'email' => 'visitor@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 3],
                ['user_id' => 4, 'group_id' => 3],
            ],
            'curio_works' => [
                ['id' => 1, 'source' => 'deezer', 'source_id' => '302127', 'title' => 'Discovery (orig)', 'creator' => 'Daft Punk', 'year' => 2001, 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 30],
            ],
            'curio_work_texts' => [
                ['work_id' => 1, 'locale' => 'en', 'title' => 'Discovery', 'description' => 'The second album.', 'description_source' => 'wikipedia', 'source_url' => 'https://en.wikipedia.org/wiki/Discovery_(album)', 'fetched_at' => $now],
            ],
        ]);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function get(string $path, array $query = [], ?int $as = null): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
    }

    /** A setting changed after the app has booted. */
    private function put(string $key, string $value): void
    {
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set($key, $value);
    }

    private function typeId(string $slug): int
    {
        return (int) $this->database()->table('curio_types')->where('slug', $slug)->value('id');
    }

    private function collection(int $as, array $attributes, string $type = 'games'): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/curio-collections', [
            'authenticatedAs' => $as,
            'json' => ['data' => [
                'type' => 'curio-collections',
                'attributes' => $attributes,
                'relationships' => ['type' => ['data' => ['type' => 'curio-types', 'id' => (string) $this->typeId($type)]]],
            ]],
        ]));
    }

    private function item(int $as, int $collection, array $attributes, ?int $work = null): ResponseInterface
    {
        $relationships = ['collection' => ['data' => ['type' => 'curio-collections', 'id' => (string) $collection]]];
        if ($work) {
            $relationships['work'] = ['data' => ['type' => 'curio-works', 'id' => (string) $work]];
        }

        return $this->send($this->request('POST', '/api/curio-items', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-items', 'attributes' => $attributes, 'relationships' => $relationships]],
        ]));
    }

    private function newCollection(int $as, array $attributes = [], string $type = 'games'): int
    {
        $response = $this->collection($as, $attributes + ['name' => 'Shelf of '.$as], $type);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->json($response)['data']['id'];
    }

    #[Test]
    public function presets_are_listed_to_guests(): void
    {
        $response = $this->get('/api/curio-types');
        $this->assertSame(200, $response->getStatusCode());

        $slugs = array_column(array_column($this->json($response)['data'], 'attributes'), 'slug');
        $this->assertSame(['games', 'music', 'books', 'films', 'boardgames', 'lego', 'cards', 'comics', 'other'], $slugs);

        $games = $this->json($response)['data'][0]['attributes'];
        $this->assertSame('Jeux vidéo', $games['names']['fr']);
        $this->assertSame('platform', $games['fields'][0]['key']);
        // Games search comes from Marquee, which is not installed here.
        $this->assertFalse($games['searchable']);
        $this->assertTrue($this->json($response)['data'][2]['attributes']['searchable']);
    }

    #[Test]
    public function guests_cannot_create_collections(): void
    {
        $response = $this->send($this->request('POST', '/api/curio-collections', [
            'json' => ['data' => ['type' => 'curio-collections', 'attributes' => ['name' => 'Nope']]],
        ]));

        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
    }

    #[Test]
    public function members_without_the_permission_cannot_create(): void
    {
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-curio.create')->delete();

        $this->assertSame(403, $this->collection(4, ['name' => 'Nope'])->getStatusCode());
        $response = $this->send($this->request('POST', '/api/curio/lookup', ['authenticatedAs' => 4, 'json' => ['type' => 1, 'q' => 'dune']]));
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function members_create_collections_with_a_slug(): void
    {
        $response = $this->collection(3, ['name' => 'The Nintendo shelf', 'description' => 'Switch first.']);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        $this->assertSame('the-nintendo-shelf', $data['attributes']['slug']);
        $this->assertSame('public', $data['attributes']['visibility']);
        $this->assertSame('3', $data['relationships']['user']['data']['id']);
    }

    #[Test]
    public function visibility_is_enforced(): void
    {
        $public = $this->newCollection(3, ['name' => 'Everyone']);
        $members = $this->newCollection(3, ['name' => 'Members', 'visibility' => 'members']);
        $private = $this->newCollection(3, ['name' => 'Mine', 'visibility' => 'private']);

        $ids = fn (ResponseInterface $r) => array_map('intval', array_column($this->json($r)['data'], 'id'));

        $this->assertSame([$public], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]])));
        $this->assertSame([$public, $members], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 4)));
        $this->assertSame([$public, $members, $private], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 3)));
        $this->assertSame([$public, $members, $private], $ids($this->get('/api/curio-collections', ['filter' => ['user' => 3]], 1)));

        $this->assertSame(404, $this->get("/api/curio-collections/$private", [], 4)->getStatusCode());
        $this->assertSame(404, $this->get("/api/curio-collections/$members")->getStatusCode());

        // Items follow their collection.
        $this->assertSame(201, $this->item(3, $private, ['title' => 'Secret'])->getStatusCode());
        $this->assertSame(0, count($this->json($this->get('/api/curio-items', ['filter' => ['user' => 3]], 4))['data']));
    }

    #[Test]
    public function only_the_owner_adds_items(): void
    {
        $theirs = $this->newCollection(3);

        $response = $this->item(4, $theirs, ['title' => 'Planted']);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());

        $response = $this->send($this->request('PATCH', '/api/curio-collections/'.$theirs, [
            'authenticatedAs' => 4,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $theirs, 'attributes' => ['name' => 'Renamed']]],
        ]));
        $this->assertSame('Shelf of 3', $this->database()->table('curio_collections')->where('id', $theirs)->value('name'), (string) $response->getBody());
    }

    #[Test]
    public function fields_are_cleaned_and_counted(): void
    {
        $id = $this->newCollection(3);

        $response = $this->item(3, $id, ['title' => 'Metroid Dread', 'year' => 2021, 'fields' => [
            'platform' => 'nintendo-switch', 'region' => 'not-a-region', 'cib' => '1', 'sneaky' => '<script>',
        ]]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $item = $this->json($response)['data']['attributes'];

        $this->assertEquals(['platform' => 'nintendo-switch', 'cib' => true], $item['fields']);
        $this->assertSame('nintendo-switch', $item['facet']);

        $this->item(3, $id, ['title' => 'EarthBound', 'status' => 'wishlist', 'fields' => ['platform' => 'snes']]);
        $this->item(3, $id, ['title' => 'Hades', 'status' => 'trade', 'fields' => ['platform' => 'nintendo-switch']]);

        $collection = $this->json($this->get("/api/curio-collections/$id"))['data']['attributes'];
        $this->assertSame(2, $collection['ownedCount']);
        $this->assertSame(1, $collection['wishCount']);
        $this->assertSame(1, $collection['tradeCount']);
        // Wanted items are not on the shelf, so they are not in the breakdown.
        $this->assertEquals(['nintendo-switch' => 2], $collection['facets']);
    }

    #[Test]
    public function a_hidden_wishlist_stays_hidden(): void
    {
        $id = $this->newCollection(3, ['wishlistPublic' => false]);
        $this->item(3, $id, ['title' => 'Owned one']);
        $this->item(3, $id, ['title' => 'Wanted one', 'status' => 'wishlist']);

        $titles = fn (?int $as) => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id]], $as))['data'], 'attributes'), 'title');

        $this->assertSame(['Owned one'], $titles(4));
        $this->assertSame(['Owned one'], $titles(null));
        $this->assertSame(['Owned one', 'Wanted one'], $titles(3));
        $this->assertSame(0, $this->json($this->get("/api/curio-collections/$id", [], 4))['data']['attributes']['wishCount']);
    }

    #[Test]
    public function the_price_paid_is_only_sent_to_the_owner(): void
    {
        $id = $this->newCollection(3);
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Boxed', 'pricePaid' => 49.5, 'priceCurrency' => 'EUR', 'barcode' => '045496590420']))['data']['id'];

        $mine = $this->json($this->get("/api/curio-items/$item", [], 3))['data']['attributes'];
        $this->assertEquals(49.5, $mine['pricePaid']);
        $this->assertSame('EUR', $mine['priceCurrency']);
        $this->assertSame('0045496590420', $mine['barcode']);

        foreach ([4, null] as $as) {
            $theirs = $this->json($this->get("/api/curio-items/$item", [], $as))['data']['attributes'];
            $this->assertArrayNotHasKey('pricePaid', $theirs);
            $this->assertArrayNotHasKey('priceCurrency', $theirs);
            $this->assertArrayNotHasKey('barcode', $theirs);
        }
    }

    #[Test]
    public function items_filter_by_status_facet_and_words(): void
    {
        $id = $this->newCollection(3);
        foreach ([
            ['title' => 'Celeste', 'fields' => ['platform' => 'nintendo-switch']],
            ['title' => 'Hades', 'status' => 'trade', 'fields' => ['platform' => 'pc']],
            ['title' => 'Chrono Trigger', 'status' => 'wishlist', 'fields' => ['platform' => 'snes']],
            ['title' => 'Hollow Knight', 'subtitle' => 'Team Cherry', 'fields' => ['platform' => 'nintendo-switch']],
        ] as $attributes) {
            $this->item(3, $id, $attributes);
        }

        $titles = fn (array $filter) => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id] + $filter]))['data'], 'attributes'), 'title');

        $this->assertSame(['Celeste', 'Hades', 'Hollow Knight'], $titles(['status' => 'owned']));
        $this->assertSame(['Chrono Trigger'], $titles(['status' => 'wishlist']));
        $this->assertSame(['Celeste', 'Hollow Knight'], $titles(['facet' => 'nintendo-switch']));
        $this->assertSame(['Hollow Knight'], $titles(['q' => 'cherry']));
        $this->assertSame(['Hollow Knight'], $titles(['q' => 'HOLLOW']));
        // Wildcards are words, not patterns.
        $this->assertSame([], $titles(['q' => '50% off']));
    }

    #[Test]
    public function the_owner_reorders_items(): void
    {
        $id = $this->newCollection(3);
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            $ids[] = (int) $this->json($this->item(3, $id, ['title' => $title]))['data']['id'];
        }

        $order = [$ids[2], $ids[0], $ids[1]];
        $patch = fn (int $as) => $this->send($this->request('PATCH', "/api/curio-collections/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $id, 'attributes' => ['order' => $order]]],
        ]));

        // Not someone else.
        $patch(4);
        $titles = fn () => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id], 'sort' => 'position']))['data'], 'attributes'), 'title');
        $this->assertSame(['A', 'B', 'C'], $titles());

        $this->assertSame(200, $patch(3)->getStatusCode());
        $this->assertSame(['C', 'A', 'B'], $titles());
    }

    #[Test]
    public function looked_up_titles_follow_the_readers_language(): void
    {
        $id = $this->newCollection(3, [], 'music');
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Discovery (orig)'], 1))['data']['id'];
        $typed = (int) $this->json($this->item(3, $id, ['title' => 'My own name'], 1))['data']['id'];

        $list = $this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id]]))['data'];
        $this->assertSame('Discovery', $list[0]['attributes']['displayTitle']);
        // A title the member typed is theirs.
        $this->assertSame('My own name', $list[1]['attributes']['displayTitle']);

        $shown = $this->json($this->get("/api/curio-items/$item"));
        $work = collect($shown['included'])->firstWhere('type', 'curio-works')['attributes'];
        $this->assertSame('The second album.', $work['description']);
        $this->assertSame('wikipedia', $work['descriptionSource']);
        $this->assertSame(30, $work['languages']);
        $this->assertNotNull($typed);
    }

    #[Test]
    public function a_known_barcode_is_answered_without_asking_anyone(): void
    {
        $this->app();
        resolve(BarcodeLookup::class)->remember('0724384960650', 1, 'Discovery', ['format' => '2-lp'], 'deezer');

        $response = $this->send($this->request('POST', '/api/curio/lookup', [
            'authenticatedAs' => 3,
            'json' => ['type' => $this->typeId('music'), 'q' => '724384960650'],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->assertTrue($body['barcode']['known']);
        $this->assertSame(1, $body['results'][0]['workId']);
        $this->assertSame(['format' => '2-lp'], $body['barcode']['fields']);
    }

    #[Test]
    public function an_item_with_a_barcode_teaches_the_forum(): void
    {
        $id = $this->newCollection(3);
        $this->item(3, $id, ['title' => 'Breath of the Wild', 'barcode' => '045496590420', 'fields' => ['platform' => 'nintendo-switch']]);

        $row = $this->database()->table('curio_barcodes')->where('code', '0045496590420')->first();
        $this->assertNotNull($row);
        $this->assertSame('Breath of the Wild', $row->title);
        $this->assertSame('member', $row->source);
    }

    #[Test]
    public function look_ups_only_take_well_formed_ids(): void
    {
        foreach ([['marquee-movie', '../account'], ['openlibrary', 'OL1W/../x'], ['deezer', '1?x=1'], ['musicbrainz', 'nope'], ['nowhere', '1']] as [$source, $id]) {
            $response = $this->send($this->request('POST', '/api/curio/lookup', [
                'authenticatedAs' => 3,
                'json' => ['source' => $source, 'id' => $id],
            ]));
            $this->assertSame(404, $response->getStatusCode(), "$source $id");
        }
    }

    #[Test]
    public function guests_cannot_look_things_up(): void
    {
        $response = $this->send($this->request('POST', '/api/curio/lookup', ['json' => ['type' => 1, 'q' => 'dune']]));
        $this->assertContains($response->getStatusCode(), [400, 401, 403]);
    }

    #[Test]
    public function photos_are_stored_as_webp_and_capped(): void
    {
        $id = $this->newCollection(3);
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Photographed']))['data']['id'];

        $upload = function (int $as) use ($item) {
            $path = tempnam(sys_get_temp_dir(), 'curio').'.png';
            $image = imagecreatetruecolor(40, 60);
            imagepng($image, $path);

            return $this->send($this->request('POST', "/api/curio-items/$item/photos", ['authenticatedAs' => $as])
                ->withUploadedFiles(['photo' => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'box.png', 'image/png')]));
        };

        $this->assertSame(403, $upload(4)->getStatusCode());

        $response = $upload(3);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $photos = $this->json($response)['data']['attributes']['photos'];
        $this->assertCount(1, $photos);
        $this->assertStringEndsWith('.webp', $photos[0]['url']);
        $this->assertStringEndsWith('-sm.webp', $photos[0]['small']);

        for ($i = 0; $i < 5; $i++) {
            $upload(3);
        }
        $this->assertSame(422, $upload(3)->getStatusCode());

        $remove = $this->send($this->request('DELETE', "/api/curio-items/$item/photos/0", ['authenticatedAs' => 3]));
        $this->assertCount(5, $this->json($remove)['data']['attributes']['photos']);
    }

    #[Test]
    public function photo_uploads_are_rate_limited(): void
    {
        $id = $this->newCollection(3);
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = (int) $this->json($this->item(3, $id, ['title' => "Box $i"]))['data']['id'];
        }

        $statuses = [];
        for ($n = 0; $n < 13; $n++) {
            $path = tempnam(sys_get_temp_dir(), 'curio').'.png';
            imagepng(imagecreatetruecolor(20, 20), $path);
            $statuses[] = $this->send($this->request('POST', '/api/curio-items/'.$items[intdiv($n, 5)].'/photos', ['authenticatedAs' => 3])
                ->withUploadedFiles(['photo' => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'box.png', 'image/png')]))->getStatusCode();
        }

        $this->assertSame(array_fill(0, 12, 200), array_slice($statuses, 0, 12));
        $this->assertSame(429, $statuses[12]);
    }

    #[Test]
    public function unknown_barcodes_cannot_spend_the_forums_allowance(): void
    {
        $container = $this->app()->getContainer();
        $fake = new class($container->make(\Ernestdefoe\Curio\Lookup\Http::class), $container->make(\Flarum\Settings\SettingsRepositoryInterface::class), $container->make(\Illuminate\Contracts\Cache\Repository::class)) extends UpcItemDb {
            public int $asked = 0;

            protected function ask(string $ean, string $key): ?string
            {
                $this->asked++;

                return null;
            }
        };
        $container->instance(UpcItemDb::class, $fake);

        $lookup = fn (string $code) => $this->send($this->request('POST', '/api/curio/lookup', [
            'authenticatedAs' => 4,
            'json' => ['type' => $this->typeId('lego'), 'q' => $code],
        ]));

        // The same unknown barcode is asked about once.
        $this->assertSame(200, $lookup('045496590420')->getStatusCode());
        $lookup('045496590420');
        $this->assertSame(1, $fake->asked);

        // One member spends at most 25 a day, however many barcodes they try.
        // 26 fresh, valid barcodes (under the 30-a-minute look-up limit).
        for ($i = 0, $made = 0; $made < 26; $i++) {
            for ($check = 0; $check < 10; $check++) {
                $code = sprintf('7%010d%d', 4242000 + $i, $check);
                if (\Ernestdefoe\Curio\Lookup\Barcode::normalise($code)) {
                    $lookup($code);
                    $made++;
                    break;
                }
            }
        }
        $this->assertSame(25, $fake->asked);
    }

    private function startDiscussion(int $as, ?int $itemId, string $title = 'Look what I found'): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => array_filter(['title' => $title, 'content' => 'It finally arrived.', 'curioItemId' => $itemId])]],
        ]));
    }

    #[Test]
    public function members_show_off_their_own_items(): void
    {
        $id = $this->newCollection(3);
        $mine = (int) $this->json($this->item(3, $id, ['title' => 'Metroid Dread', 'fields' => ['platform' => 'nintendo-switch']]))['data']['id'];
        $wanted = (int) $this->json($this->item(3, $id, ['title' => 'EarthBound', 'status' => 'wishlist']))['data']['id'];

        // Not someone else's, and not something you only want.
        $this->assertSame(422, $this->startDiscussion(4, $mine)->getStatusCode());
        $this->assertSame(422, $this->startDiscussion(3, $wanted)->getStatusCode());

        $response = $this->startDiscussion(3, $mine);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $discussion = (int) $this->json($response)['data']['id'];
        $this->assertSame($discussion, (int) $this->database()->table('curio_items')->where('id', $mine)->value('discussion_id'));

        // The discussion page carries the item, its collection and its owner…
        $page = $this->json($this->get("/api/discussions/$discussion"));
        $this->assertSame((string) $mine, $page['data']['relationships']['curioItem']['data']['id']);
        $types = array_count_values(array_column($page['included'], 'type'));
        $this->assertSame(1, $types['curio-items']);
        $this->assertSame(1, $types['curio-collections']);
        $this->assertSame(1, $types['curio-types']);

        // …and the item page links back to the discussion.
        $this->assertSame($discussion, $this->json($this->get("/api/curio-items/$mine"))['data']['attributes']['discussionId']);

        // Lists never carry it.
        $list = $this->json($this->get('/api/discussions'));
        $this->assertArrayNotHasKey('curioItem', $list['data'][0]['relationships'] ?? []);
    }

    #[Test]
    public function a_private_item_does_not_leak_through_its_discussion(): void
    {
        $id = $this->newCollection(3, ['visibility' => 'private']);
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Secret grail', 'pricePaid' => 900]))['data']['id'];
        $discussion = (int) $this->json($this->startDiscussion(3, $item))['data']['id'];

        foreach ([null, 4] as $as) {
            $page = $this->json($this->get("/api/discussions/$discussion", [], $as));
            $this->assertNull($page['data']['relationships']['curioItem']['data'] ?? null);
            $this->assertNotContains('curio-items', array_column($page['included'] ?? [], 'type'));
        }

        $own = $this->json($this->get("/api/discussions/$discussion", [], 3));
        $this->assertSame((string) $item, $own['data']['relationships']['curioItem']['data']['id']);
    }

    private function notifications(int $userId, string $type): int
    {
        return $this->database()->table('notifications')->where('user_id', $userId)->where('type', $type)->count();
    }

    #[Test]
    public function an_offer_to_trade_reaches_those_who_want_it_and_can_see_it(): void
    {
        $wants = $this->newCollection(4, [], 'music');
        $wanted = (int) $this->json($this->item(4, $wants, ['title' => 'Discovery', 'status' => 'wishlist'], 1))['data']['id'];

        // A private offer tells nobody.
        $hidden = $this->newCollection(3, ['name' => 'Hidden', 'visibility' => 'private'], 'music');
        $this->item(3, $hidden, ['title' => 'Discovery', 'status' => 'trade'], 1);
        $this->assertSame(0, $this->notifications(4, 'curioWishlistMatch'));

        $open = $this->newCollection(3, ['name' => 'Spares'], 'music');
        $offer = (int) $this->json($this->item(3, $open, ['title' => 'Discovery'], 1))['data']['id'];
        $this->assertSame(0, $this->notifications(4, 'curioWishlistMatch'));

        // Owned → open to trade: now they hear, once, however often it is re-offered.
        $trade = fn (string $status) => $this->send($this->request('PATCH', "/api/curio-items/$offer", [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'curio-items', 'id' => (string) $offer, 'attributes' => ['status' => $status]]],
        ]));
        $trade('trade');
        $trade('owned');
        $trade('trade');
        $this->assertSame(1, $this->notifications(4, 'curioWishlistMatch'));
        $this->assertSame(0, $this->notifications(3, 'curioWishlistMatch'));

        // The wanted item points at the offer.
        $offers = $this->json($this->get("/api/curio-items/$wanted", [], 4))['data']['attributes']['tradeOffers'];
        $this->assertSame(['count' => 1, 'itemId' => $offer, 'collectionId' => $open], $offers);
    }

    #[Test]
    public function a_list_of_curio_notifications_costs_a_fixed_number_of_queries(): void
    {
        $wants = $this->newCollection(4, [], 'music');
        $this->item(4, $wants, ['title' => 'Discovery', 'status' => 'wishlist'], 1);

        $spares = $this->newCollection(3, ['name' => 'Spares'], 'music');
        for ($i = 0; $i < 8; $i++) {
            $this->item(3, $spares, ['title' => 'Discovery (orig)', 'status' => 'trade'], 1);
        }
        $this->assertSame(8, $this->notifications(4, 'curioWishlistMatch'));

        // The repeated-query detector fails this if each notification's item loads its own title or cover.
        $response = $this->get('/api/notifications', [], 4);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Discovery', collect($this->json($response)['included'])->firstWhere('type', 'curio-items')['attributes']['displayTitle']);
    }

    #[Test]
    public function followers_hear_about_new_things_once_a_day(): void
    {
        $id = $this->newCollection(3);
        $follow = fn (int $as, bool $on) => $this->send($this->request('PATCH', "/api/curio-collections/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $id, 'attributes' => ['followed' => $on]]],
        ]));

        $this->assertTrue($this->json($follow(4, true))['data']['attributes']['followed']);
        $follow(3, true);
        $this->assertSame(0, $this->database()->table('curio_follows')->where('user_id', 3)->count());
        $this->assertSame(1, $this->json($this->get("/api/curio-collections/$id", [], 4))['data']['attributes']['followerCount']);

        $this->item(3, $id, ['title' => 'Wanted only', 'status' => 'wishlist']);
        $this->assertSame(0, $this->notifications(4, 'curioNewItems'));

        $this->item(3, $id, ['title' => 'Celeste']);
        $this->item(3, $id, ['title' => 'Hades']);
        $this->assertSame(1, $this->notifications(4, 'curioNewItems'));

        $this->assertFalse($this->json($follow(4, false))['data']['attributes']['followed']);
    }

    private function upload(int $as, int $collection, string $csv): ResponseInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'curio');
        file_put_contents($path, $csv);

        return $this->send($this->request('POST', "/api/curio-collections/$collection/import", ['authenticatedAs' => $as])
            ->withUploadedFiles(['file' => new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'export.csv', 'text/csv')]));
    }

    #[Test]
    public function a_discogs_export_imports(): void
    {
        $id = $this->newCollection(3, [], 'music');
        $csv = "Catalog#,Artist,Title,Label,Format,Rating,Released,release_id,CollectionFolder,Date Added,Collection Media Condition,Collection Sleeve Condition,Collection Notes\n"
            ."K 56344,Fleetwood Mac,Rumours,Warner Bros. Records,\"LP, Album\",5,1977,1234,Uncategorized,2023-04-12 18:22:01,Very Good Plus (VG+),Very Good (VG),First press?\n"
            ."0190295,Daft Punk (2),Discovery,Virgin,\"2xLP, Album, RE\",,2001,99,Uncategorized,2024-01-02 10:00:00,Mint (M),,\n"
            .",,,,,,,,,,,,\n";

        $response = $this->upload(3, $id, $csv);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['format' => 'discogs', 'imported' => 2, 'skipped' => 1], $this->json($response));

        $rows = $this->database()->table('curio_items')->where('collection_id', $id)->orderBy('position')->get();
        $this->assertSame('Fleetwood Mac', $rows[0]->subtitle);
        $this->assertSame([10, null], [(int) $rows[0]->rating, $rows[1]->rating]);
        $this->assertSame('very_good', $rows[0]->condition);
        $this->assertSame(1977, (int) $rows[0]->year);
        $this->assertSame('2023-04-12', substr((string) $rows[0]->acquired_on, 0, 10));
        $this->assertEquals(['format' => 'lp', 'label' => 'Warner Bros. Records', 'catalogue' => 'K 56344'], json_decode($rows[0]->fields, true));
        $this->assertSame('Daft Punk', $rows[1]->subtitle);
        $this->assertSame('2-lp', $rows[1]->facet);
        $this->assertTrue((bool) $rows[1]->needs_match);
        $this->assertSame(2, $this->json($this->get("/api/curio-collections/$id"))['data']['attributes']['ownedCount']);
    }

    #[Test]
    public function a_goodreads_export_imports(): void
    {
        $id = $this->newCollection(3, [], 'books');
        $csv = "Book Id,Title,Author,ISBN,ISBN13,My Rating,Year Published,Original Publication Year,Date Added,Exclusive Shelf\n"
            ."1,Dune,Frank Herbert,\"=\"\"0441172717\"\"\",\"=\"\"9780441172719\"\"\",5,1990,1965,2023/04/12,read\n"
            ."2,Hyperion,Dan Simmons,,,0,1990,1989,2024/05/01,to-read\n";

        $this->assertSame('goodreads', $this->json($this->upload(3, $id, $csv))['format']);

        $rows = $this->database()->table('curio_items')->where('collection_id', $id)->orderBy('position')->get();
        $this->assertSame(['Dune', 'owned', '9780441172719', 1965], [$rows[0]->title, $rows[0]->status, $rows[0]->barcode, (int) $rows[0]->year]);
        $this->assertSame(['Hyperion', 'wishlist', null], [$rows[1]->title, $rows[1]->status, $rows[1]->acquired_on]);
        // Goodreads' "My Rating": 5 stars → 10 half stars; 0 is unrated.
        $this->assertSame([10, null], [(int) $rows[0]->rating, $rows[1]->rating]);
    }

    #[Test]
    public function a_storygraph_export_brings_its_quarter_star_ratings_as_halves(): void
    {
        $id = $this->newCollection(3, [], 'books');
        $csv = "Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,Last Date Read,Dates Read,Read Count,Moods,Pace,Character- or Plot-Driven?,Strong Character Development?,Loveable Characters?,Diverse Characters?,Flawed Characters?,Star Rating,Review,Content Warnings,Content Warning Description,Tags,Owned?
"
            ."Piranesi,Susanna Clarke,,9781635575996,hardcover,read,2024/01/02,,,1,,,,,,,,3.75,,,,,Yes
"
            ."Circe,Madeline Miller,,9780316556347,paperback,to-read,2024/01/03,,,0,,,,,,,,,,,,,No
";

        $this->assertSame('storygraph', $this->json($this->upload(3, $id, $csv))['format']);
        $rows = $this->database()->table('curio_items')->where('collection_id', $id)->orderBy('position')->get();
        $this->assertSame([8, null], [(int) $rows[0]->rating, $rows[1]->rating]);
    }

    #[Test]
    public function owners_rate_items_in_half_stars_and_sort_by_rating(): void
    {
        $id = $this->newCollection(3);
        $low = $this->json($this->item(3, $id, ['title' => 'Okay game', 'rating' => 5]))['data']['id'];
        $this->item(3, $id, ['title' => 'Unrated game']);
        $high = $this->json($this->item(3, $id, ['title' => 'Great game', 'rating' => 9]))['data']['id'];

        $this->assertSame(422, $this->item(3, $id, ['title' => 'Too good', 'rating' => 11])->getStatusCode());
        $this->assertSame(422, $this->item(3, $id, ['title' => 'Too bad', 'rating' => 0])->getStatusCode());

        $list = $this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id], 'sort' => '-rating'], 4))['data'];
        $this->assertSame([$high, $low], array_slice(array_column($list, 'id'), 0, 2));
        $this->assertSame(9, $list[0]['attributes']['rating']);

        // Only the owner changes it.
        $patch = fn (int $as) => $this->send($this->request('PATCH', "/api/curio-items/$low", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-items', 'id' => $low, 'attributes' => ['rating' => 10]]],
        ]));
        $this->assertSame(403, $patch(4)->getStatusCode());
        $this->assertSame(200, $patch(3)->getStatusCode());

        // Exported as stars out of five, and read back in.
        $csv = (string) $this->send($this->request('GET', "/api/curio-collections/$id/export", ['authenticatedAs' => 3]))->getBody();
        $this->assertStringContainsString(',4.5', $csv);
        $to = $this->newCollection(3, ['name' => 'Copy']);
        $this->upload(3, $to, $csv);
        $this->assertEqualsCanonicalizing([10, 9, null], $this->database()->table('curio_items')->where('collection_id', $to)->pluck('rating')->map(fn ($r) => $r === null ? null : (int) $r)->all());
    }

    #[Test]
    public function the_recently_added_widget_is_off_until_an_admin_turns_it_on(): void
    {
        $this->assertFalse($this->json($this->get('/api'))['data']['attributes']['curioWidget']);
    }

    #[Test]
    public function an_admin_can_turn_the_widget_on(): void
    {
        $this->setting('ernestdefoe-curio.widget', '1');
        $this->assertTrue($this->json($this->get('/api'))['data']['attributes']['curioWidget']);
    }

    #[Test]
    public function the_browse_page_layout_is_the_tags_look_unless_the_admin_picks_covers(): void
    {
        $id = $this->newCollection(3, ['name' => 'Nintendo shelf']);
        $this->item(3, $id, ['title' => 'Seen']);

        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame('tags', $body['layout']);
        $this->assertSame(['id' => (string) $id, 'name' => 'Nintendo shelf'], array_intersect_key($body['types'][(string) $this->typeId('games')]['latest'], ['id' => 1, 'name' => 1]));

        $this->put('ernestdefoe-curio.browse_layout', 'covers');
        $this->assertSame('covers', $this->json($this->get('/api/curio/overview'))['layout']);
    }

    /** IGDB answered by a fake: two token-holding searches, then a game's details. */
    private function fakeIgdb(): object
    {
        $container = $this->app()->getContainer();
        $fake = new class($container->make(\Illuminate\Contracts\Cache\Repository::class), $container->make(\Flarum\Http\UrlGenerator::class)) extends \Ernestdefoe\Curio\Lookup\Http {
            public array $calls = [];

            public function post(string $service, string $url, array $query = [], array $headers = [], ?string $body = null, float $timeout = 6): array
            {
                $this->calls[] = $service.' '.$url;

                if ($service === 'twitch') {
                    return ['access_token' => 'tok', 'expires_in' => 5000000];
                }

                $game = ['id' => 154986, 'name' => 'Metroid Dread', 'slug' => 'metroid-dread', 'first_release_date' => 1633651200,
                    'summary' => 'Samus on ZDR.', 'cover' => ['image_id' => 'co2r5x'], 'total_rating_count' => 300,
                    'platforms' => [['name' => 'Nintendo Switch']], 'genres' => [['name' => 'Platform'], ['name' => 'Adventure']],
                    'involved_companies' => [
                        ['company' => ['name' => 'MercurySteam'], 'developer' => true, 'publisher' => false],
                        ['company' => ['name' => 'Nintendo'], 'developer' => false, 'publisher' => true],
                    ]];

                return str_contains((string) $body, 'search') ? [['id' => 1, 'name' => 'Metroid Dread Demo'], $game] : [$game];
            }
        };
        $container->instance(\Ernestdefoe\Curio\Lookup\Http::class, $fake);

        return $fake;
    }

    #[Test]
    public function games_are_looked_up_on_igdb_with_the_forums_twitch_app(): void
    {
        $this->put('ernestdefoe-curio.igdb_client_id', 'client');
        $this->put('ernestdefoe-curio.igdb_client_secret', 'secret');
        $fake = $this->fakeIgdb();

        $search = fn () => $this->json($this->send($this->request('POST', '/api/curio/lookup', [
            'authenticatedAs' => 3,
            'json' => ['type' => $this->typeId('games'), 'q' => 'metroid dread'],
        ])));

        $first = $search()['results'];
        $this->assertSame(['igdb', '154986', 'Metroid Dread', 'MercurySteam', 2021], [$first[0]['source'], $first[0]['id'], $first[0]['title'], $first[0]['creator'], $first[0]['year']]);
        $this->assertStringContainsString('images.igdb.com', $first[0]['cover']);

        // The token and the search are both kept: a second search sends nothing.
        $search();
        $this->assertSame(['twitch https://id.twitch.tv/oauth2/token', 'igdb https://api.igdb.com/v4/games'], $fake->calls);
    }

    #[Test]
    public function an_igdb_game_brings_its_developer_publisher_date_platforms_and_genres(): void
    {
        $details = \Ernestdefoe\Curio\Lookup\Sources\Igdb::normalise([
            'name' => 'Metroid Dread', 'slug' => 'metroid-dread', 'first_release_date' => 1633651200,
            'platforms' => [['name' => 'Nintendo Switch']], 'genres' => [['name' => 'Platform']],
            'involved_companies' => [
                ['company' => ['name' => 'MercurySteam'], 'developer' => true],
                ['company' => ['name' => 'Nintendo'], 'publisher' => true],
            ],
        ]);

        $this->assertSame(['P5794', 'metroid-dread'], $details['wikidata']);
        $this->assertSame([
            'developer' => ['MercurySteam'], 'publisher' => ['Nintendo'], 'platforms' => ['Nintendo Switch'], 'genres' => ['Platform'], 'released' => '2021-10-08',
        ], \Ernestdefoe\Curio\Lookup\WorkResolver::facts($details['facts']));
        $this->assertNull(\Ernestdefoe\Curio\Lookup\WorkResolver::facts(['developer' => 'not a list', 'released' => '8 Oct']));
    }

    #[Test]
    public function without_igdb_keys_games_search_as_before(): void
    {
        // No IGDB keys and no Marquee: games have no title search, only barcodes and by hand.
        $types = $this->json($this->get('/api/curio-types'))['data'];
        $games = array_values(array_filter($types, fn ($t) => $t['attributes']['slug'] === 'games'))[0];
        $this->assertFalse($games['attributes']['searchable']);

        $this->put('ernestdefoe-curio.igdb_client_id', 'client');
        $this->put('ernestdefoe-curio.igdb_client_secret', 'secret');
        $types = $this->json($this->get('/api/curio-types'))['data'];
        $games = array_values(array_filter($types, fn ($t) => $t['attributes']['slug'] === 'games'))[0];
        $this->assertTrue($games['attributes']['searchable']);
    }

    #[Test]
    public function an_export_reads_back_in_and_cannot_carry_formulas(): void
    {
        $from = $this->newCollection(3);
        $this->item(3, $from, ['title' => 'Metroid Dread', 'year' => 2021, 'condition' => 'mint', 'pricePaid' => 20, 'priceCurrency' => 'GBP', 'fields' => ['platform' => 'nintendo-switch', 'cib' => true]]);
        $this->item(3, $from, ['title' => '=HYPERLINK("http://evil")', 'status' => 'wishlist']);

        $response = $this->send($this->request('GET', "/api/curio-collections/$from/export", ['authenticatedAs' => 3]));
        $this->assertSame(200, $response->getStatusCode());
        $csv = (string) $response->getBody();
        $this->assertStringContainsString('Nintendo Switch', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        // Only the owner exports.
        $this->assertSame(403, $this->send($this->request('GET', "/api/curio-collections/$from/export", ['authenticatedAs' => 4]))->getStatusCode());

        $to = $this->newCollection(3, ['name' => 'Copy']);
        $this->assertSame(['format' => 'curio', 'imported' => 2, 'skipped' => 0], $this->json($this->upload(3, $to, $csv)));

        $copy = $this->database()->table('curio_items')->where('collection_id', $to)->orderBy('position')->first();
        $this->assertSame(['Metroid Dread', 2021, 'mint', 'GBP'], [$copy->title, (int) $copy->year, $copy->condition, $copy->price_currency]);
        $this->assertEquals(['platform' => 'nintendo-switch', 'cib' => true], json_decode($copy->fields, true));
    }

    #[Test]
    public function imports_are_owner_only_and_known_formats_only(): void
    {
        $id = $this->newCollection(3);
        $this->assertSame(403, $this->upload(4, $id, "title\nNope\n")->getStatusCode());
        $this->assertSame(422, $this->upload(3, $id, "foo,bar\n1,2\n")->getStatusCode());
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $this->newCollection(3);

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('games'), ['authenticatedAs' => 1]));
        $this->assertSame(422, $response->getStatusCode());

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('other'), ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $response = $this->send($this->request('DELETE', '/api/curio-types/'.$this->typeId('lego'), ['authenticatedAs' => 3]));
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function the_overview_counts_public_collections_only(): void
    {
        $public = $this->newCollection(3);
        $private = $this->newCollection(3, ['visibility' => 'private']);
        $this->item(3, $public, ['title' => 'Seen']);
        $this->item(3, $private, ['title' => 'Unseen']);
        $this->item(3, $private, ['title' => 'Unseen too']);

        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame(1, $body['items']);
        $this->assertSame(1, $body['types'][(string) $this->typeId('games')]['items']);
    }

    #[Test]
    public function lists_cost_a_fixed_number_of_queries(): void
    {
        $id = $this->newCollection(3, [], 'music');
        for ($i = 0; $i < 12; $i++) {
            $this->item(3, $id, ['title' => "Record $i", 'status' => $i % 3 ? 'owned' : 'wishlist'], $i % 2 ? 1 : null);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->newCollection(3, ['name' => "More $i"]);
        }

        // The repeated-query detector fails these if anything is fetched per row.
        $this->assertSame(200, $this->get('/api/curio-items', ['filter' => ['collection' => $id], 'include' => 'collection.user,collection.type'], 4)->getStatusCode());
        $this->assertSame(200, $this->get('/api/curio-items', ['filter' => ['status' => 'owned'], 'include' => 'collection.user,collection.type', 'sort' => '-createdAt'])->getStatusCode());
        $this->assertSame(200, $this->get('/api/curio-collections', ['filter' => ['user' => 3]], 4)->getStatusCode());
    }

    private function patchCollection(int $as, int $id, array $attributes): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/curio-collections/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-collections', 'id' => (string) $id, 'attributes' => $attributes]],
        ]));
    }

    private function featuredIds(): array
    {
        return array_column($this->json($this->get('/api/curio-collections', ['filter' => ['featured' => '1']]))['data'], 'id');
    }

    #[Test]
    public function an_unfeatured_collection_leaves_the_featured_list(): void
    {
        $kept = $this->newCollection(3, ['name' => 'Kept']);
        $dropped = $this->newCollection(3, ['name' => 'Dropped']);

        $this->assertSame(200, $this->patchCollection(1, $kept, ['featured' => true])->getStatusCode());
        $this->assertSame(200, $this->patchCollection(1, $dropped, ['featured' => true])->getStatusCode());
        $this->assertEqualsCanonicalizing([(string) $kept, (string) $dropped], $this->featuredIds());

        $response = $this->patchCollection(1, $dropped, ['featured' => false]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse($this->json($response)['data']['attributes']['featured']);
        $this->assertNull($this->database()->table('curio_collections')->where('id', $dropped)->value('featured_at'));
        $this->assertSame([(string) $kept], $this->featuredIds());

        // The owner cannot feature their own collection.
        $this->patchCollection(3, $dropped, ['featured' => true]);
        $this->assertSame([(string) $kept], $this->featuredIds());
    }

    #[Test]
    public function the_my_collections_shortcut_goes_where_the_admin_chose(): void
    {
        $this->setting('ernestdefoe-curio.shortcut', 'menu');
        $this->assertSame('menu', $this->json($this->get('/api'))['data']['attributes']['curioShortcut']);
    }

    #[Test]
    public function the_my_collections_shortcut_is_in_both_places_by_default(): void
    {
        $this->assertSame('both', $this->json($this->get('/api'))['data']['attributes']['curioShortcut']);
    }

    #[Test]
    public function an_unknown_shortcut_placement_falls_back_to_both(): void
    {
        $this->setting('ernestdefoe-curio.shortcut', 'everywhere');
        $this->assertSame('both', $this->json($this->get('/api'))['data']['attributes']['curioShortcut']);
    }

    private function patchType(int $id, array $attributes): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/curio-types/$id", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'curio-types', 'id' => (string) $id, 'attributes' => $attributes]],
        ]));
    }

    #[Test]
    public function a_type_colour_is_a_hex_code_or_blank(): void
    {
        $id = $this->typeId('lego');
        $this->assertNotNull($this->json($this->get("/api/curio-types/$id"))['data']['attributes']['color']);

        $response = $this->patchType($id, ['color' => null]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->json($response)['data']['attributes']['color']);

        $this->assertSame('#aabbcc', $this->json($this->patchType($id, ['color' => '#ABC']))['data']['attributes']['color']);
        $this->assertSame('#123456', $this->json($this->patchType($id, ['color' => '#123456']))['data']['attributes']['color']);

        $this->assertSame(422, $this->patchType($id, ['color' => 'red'])->getStatusCode());
        $this->assertSame(422, $this->patchType($id, ['color' => '#12345'])->getStatusCode());
        $this->assertSame('#123456', $this->json($this->get("/api/curio-types/$id"))['data']['attributes']['color']);
    }

    #[Test]
    public function a_copied_type_keeps_its_details_and_their_labels(): void
    {
        $games = $this->json($this->get('/api/curio-types/'.$this->typeId('games')))['data']['attributes'];
        $names = array_map(fn ($name) => "$name 2", $games['names']);

        $response = $this->send($this->request('POST', '/api/curio-types', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'curio-types', 'attributes' => [
                'slug' => 'video-games-2',
                'names' => $names,
                'icon' => $games['icon'],
                'color' => $games['color'],
                'shape' => $games['shape'],
                'source' => $games['source'],
                'fields' => $games['fields'],
            ]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $copy = $this->json($response)['data']['attributes'];
        $this->assertSame($names, $copy['names']);
        $this->assertSame($games['fields'], $copy['fields']);
        $this->assertSame([$games['icon'], $games['color'], $games['shape'], $games['source']], [$copy['icon'], $copy['color'], $copy['shape'], $copy['source']]);
    }
}
