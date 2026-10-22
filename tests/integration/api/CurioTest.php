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
                ['id' => 1, 'source' => 'deezer', 'source_id' => '302127', 'title' => 'Discovery (orig)', 'creator' => 'Daft Punk', 'year' => 2001, 'wikidata_id' => 'Q206912', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 30],
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

    #[Test]
    public function every_switched_on_type_shows_unless_the_admin_hides_empty_ones(): void
    {
        $this->assertFalse($this->json($this->get('/api/curio/overview'))['hideEmpty']);

        $this->put('ernestdefoe-curio.hide_empty_types', '1');
        $this->assertTrue($this->json($this->get('/api/curio/overview'))['hideEmpty']);
    }

    #[Test]
    public function covers_that_do_not_fit_are_blurred_by_default(): void
    {
        $this->assertSame('blur', $this->json($this->get('/api'))['data']['attributes']['curioCoverFit']);
    }

    #[Test]
    public function covers_that_do_not_fit_can_be_cropped(): void
    {
        $this->setting('ernestdefoe-curio.cover_fit', 'crop');
        $this->assertSame('crop', $this->json($this->get('/api'))['data']['attributes']['curioCoverFit']);
    }

    #[Test]
    public function an_unknown_cover_fit_falls_back_to_blur(): void
    {
        $this->setting('ernestdefoe-curio.cover_fit', 'stretch');
        $this->assertSame('blur', $this->json($this->get('/api'))['data']['attributes']['curioCoverFit']);
    }

    #[Test]
    public function game_boxes_are_3_by_4_and_only_an_untouched_seeded_type_is_moved(): void
    {
        $this->app();
        $db = $this->database();
        $this->assertSame('box', $db->table('curio_types')->where('slug', 'games')->value('shape'));

        $migration = require __DIR__.'/../../../migrations/2026_10_20_000002_games_type_box_covers.php';
        $old = Carbon::parse('2026-01-01');

        // An admin saved it (as portrait): left alone.
        $db->table('curio_types')->where('slug', 'games')->update(['shape' => 'portrait', 'created_at' => $old, 'updated_at' => $old->copy()->addDay()]);
        $migration['up']($db->getSchemaBuilder());
        $this->assertSame('portrait', $db->table('curio_types')->where('slug', 'games')->value('shape'));

        // Never saved since it was seeded: moved to 3:4.
        $db->table('curio_types')->where('slug', 'games')->update(['updated_at' => $old]);
        $migration['up']($db->getSchemaBuilder());
        $this->assertSame('box', $db->table('curio_types')->where('slug', 'games')->value('shape'));
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
    public function the_overview_counts_what_the_reader_may_open(): void
    {
        $public = $this->newCollection(3);
        $members = $this->newCollection(3, ['name' => 'Members shelf', 'visibility' => 'members']);
        $private = $this->newCollection(3, ['name' => 'Private shelf', 'visibility' => 'private']);
        $this->item(3, $public, ['title' => 'Seen']);
        $this->item(3, $members, ['title' => 'Members see it']);
        $this->item(3, $private, ['title' => 'Unseen']);
        $this->item(3, $private, ['title' => 'Unseen too']);
        $theirs = $this->newCollection(4, ['name' => 'Their private shelf', 'visibility' => 'private']);
        $this->item(4, $theirs, ['title' => 'Only theirs']);

        $games = (string) $this->typeId('games');
        $count = function (?int $as) use ($games) {
            $body = $this->json($this->get('/api/curio/overview', [], $as));

            return [$body['items'], $body['types'][$games]['collections'], $body['members']];
        };

        // Guests: public only. Members: members-only too, and their own private ones (nobody else's).
        $this->assertSame([1, 1, 1], $count(null));
        $this->assertSame([4, 3, 1], $count(3));
        $this->assertSame([3, 3, 2], $count(4));
        // Moderators open everything.
        $this->assertSame([5, 4, 2], $count(1));
        // The counts follow a new item at once.
        $this->item(3, $members, ['title' => 'Another']);
        $this->assertSame([1, 1, 1], $count(null));
        $this->assertSame([4, 3, 2], $count(4));
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

    private function retype(int $as, int $collection, string $type): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/curio-collections/$collection", [
            'authenticatedAs' => $as,
            'json' => ['data' => [
                'type' => 'curio-collections',
                'id' => (string) $collection,
                'relationships' => ['type' => ['data' => ['type' => 'curio-types', 'id' => (string) $this->typeId($type)]]],
            ]],
        ]));
    }

    #[Test]
    public function the_owner_changes_a_collections_type_and_the_breakdown_follows(): void
    {
        $id = $this->newCollection(3);
        $this->item(3, $id, ['title' => 'Metroid Dread', 'fields' => ['platform' => 'nintendo-switch']]);

        // Nobody else can, not even a member who can see it.
        $this->assertSame(403, $this->retype(4, $id, 'lego')->getStatusCode());

        $response = $this->retype(3, $id, 'lego');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('lego', collect($this->json($response)['included'])->firstWhere('type', 'curio-types')['attributes']['slug']);
        // LEGO has no choice field: no breakdown, no facet, but the details are kept.
        $this->assertEquals([], $this->json($this->get("/api/curio-collections/$id", [], 3))['data']['attributes']['facets']);
        $item = $this->json($this->get('/api/curio-items', ['filter' => ['collection' => $id]], 3))['data'][0]['attributes'];
        $this->assertNull($item['facet']);
        $this->assertSame('nintendo-switch', $item['fields']['platform']);

        // Back to games, and the platform is the breakdown again.
        $this->assertSame(200, $this->retype(3, $id, 'games')->getStatusCode());
        $this->assertEquals(['nintendo-switch' => 1], $this->json($this->get("/api/curio-collections/$id", [], 3))['data']['attributes']['facets']);

        // A switched-off type takes no collection moved into it, except an admin's.
        $this->assertSame(200, $this->patchType($this->typeId('lego'), ['enabled' => false])->getStatusCode());
        $this->assertSame(422, $this->retype(3, $id, 'lego')->getStatusCode());
        $mine = $this->newCollection(1, ['name' => 'Admin shelf']);
        $this->assertSame(200, $this->retype(1, $mine, 'lego')->getStatusCode());
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

    /**
     * What the admin editor sends for a detail added there: blank keys on the
     * detail and its choices, labels in the language being edited only.
     */
    #[Test]
    public function choices_typed_in_the_editor_are_kept(): void
    {
        $new = [
            ['key' => '', 'kind' => 'select', 'labels' => ['fr' => 'Format'], 'options' => [
                ['key' => '', 'labels' => ['fr' => 'Physique']],
                ['key' => '', 'labels' => ['fr' => 'Numérique']],
            ]],
            ['key' => '', 'kind' => 'text', 'labels' => ['fr' => 'Édition']],
        ];
        $expected = [
            ['key' => 'format', 'kind' => 'select', 'labels' => ['fr' => 'Format'], 'options' => [
                ['key' => 'physique', 'labels' => ['fr' => 'Physique']],
                ['key' => 'numerique', 'labels' => ['fr' => 'Numérique']],
            ]],
            ['key' => 'edition', 'kind' => 'text', 'labels' => ['fr' => 'Édition']],
        ];

        // Added to an existing type, after the details it already has. Games
        // has an "edition" already: the new one is numbered, not dropped.
        $id = $this->typeId('games');
        $before = $this->json($this->get("/api/curio-types/$id"))['data']['attributes']['fields'];
        $response = $this->patchType($id, ['fields' => [...$before, ...$new]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $added = $expected;
        $added[1]['key'] = 'edition_2';
        $this->assertSame([...$before, ...$added], $this->json($this->get("/api/curio-types/$id"))['data']['attributes']['fields']);

        // On a brand new type.
        $response = $this->send($this->request('POST', '/api/curio-types', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'curio-types', 'attributes' => [
                'slug' => 'jeux', 'names' => ['fr' => 'Jeux'], 'icon' => 'fas fa-gamepad', 'shape' => 'box', 'source' => 'none', 'fields' => $new,
            ]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($expected, $this->json($response)['data']['attributes']['fields']);

        // A choice added later to a saved detail keeps the ones before it.
        $jeux = (int) $this->json($response)['data']['id'];
        $fields = $expected;
        $fields[0]['options'][] = ['key' => '', 'labels' => ['fr' => 'Collector']];
        $this->assertSame(200, $this->patchType($jeux, ['fields' => $fields])->getStatusCode());
        $this->assertSame(
            ['physique', 'numerique', 'collector'],
            array_column($this->json($this->get("/api/curio-types/$jeux"))['data']['attributes']['fields'][0]['options'], 'key')
        );
    }

    #[Test]
    public function any_font_awesome_class_list_is_an_icon(): void
    {
        $id = $this->typeId('games');

        foreach (['fa-brands fa-playstation', 'fab fa-xbox', 'fa-solid fa-gamepad fa-fw', 'fas fa-dice-d20', 'fa-regular fa-heart'] as $icon) {
            $response = $this->patchType($id, ['icon' => $icon]);
            $this->assertSame(200, $response->getStatusCode(), "$icon: ".$response->getBody());
            $this->assertSame($icon, $this->json($response)['data']['attributes']['icon']);
        }

        foreach (['gamepad', 'fa-brands', 'fas fa-x"><script>', 'fas  fa-x'] as $icon) {
            $this->assertSame(422, $this->patchType($id, ['icon' => $icon])->getStatusCode(), $icon);
        }
    }

    #[Test]
    public function a_switched_off_type_is_hidden_from_members_but_not_from_its_owners(): void
    {
        $lego = $this->typeId('lego');
        $shelf = $this->newCollection(3, ['name' => 'Bricks'], 'lego');
        $this->item(3, $shelf, ['title' => 'Millennium Falcon']);

        $this->assertSame(200, $this->patchType($lego, ['enabled' => false])->getStatusCode());

        // Members and guests no longer see the type, and cannot start a collection of it.
        $slugs = fn (?int $as) => array_column(array_column($this->json($this->get('/api/curio-types', [], $as))['data'], 'attributes'), 'slug');
        $this->assertNotContains('lego', $slugs(null));
        $this->assertNotContains('lego', $slugs(4));
        $this->assertContains('lego', $slugs(1));
        $this->assertSame(422, $this->collection(4, ['name' => 'Mine'], 'lego')->getStatusCode());
        // An admin can still try it out.
        $this->assertSame(201, $this->collection(1, ['name' => 'Test'], 'lego')->getStatusCode());

        // The existing collection stays for its owner, with its type, and is hidden from others.
        $own = $this->get("/api/curio-collections/$shelf", [], 3);
        $this->assertSame(200, $own->getStatusCode());
        $this->assertSame('lego', collect($this->json($own)['included'])->firstWhere('type', 'curio-types')['attributes']['slug']);
        $this->assertSame(1, count($this->json($this->get('/api/curio-items', ['filter' => ['collection' => $shelf]], 3))['data']));
        $this->assertSame(404, $this->get("/api/curio-collections/$shelf", [], 4)->getStatusCode());
        $this->assertSame(404, $this->get("/api/curio-collections/$shelf")->getStatusCode());
        $this->assertSame([], $this->json($this->get('/api/curio-items', ['filter' => ['collection' => $shelf]], 4))['data']);
        $this->assertArrayNotHasKey((string) $lego, $this->json($this->get('/api/curio/overview'))['types']);

        // Back on, back for everyone.
        $this->assertSame(200, $this->patchType($lego, ['enabled' => true])->getStatusCode());
        $this->assertSame(200, $this->get("/api/curio-collections/$shelf", [], 4)->getStatusCode());
        $this->assertSame(1, $this->json($this->get('/api/curio/overview'))['types'][(string) $lego]['items']);
    }

    #[Test]
    public function only_admins_switch_types_on_and_off(): void
    {
        $response = $this->send($this->request('PATCH', '/api/curio-types/'.$this->typeId('lego'), [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'curio-types', 'id' => (string) $this->typeId('lego'), 'attributes' => ['enabled' => false]]],
        ]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue((bool) $this->database()->table('curio_types')->where('slug', 'lego')->value('enabled'));
    }

    #[Test]
    public function items_track_progress_filter_by_it_and_carry_it_through_csv(): void
    {
        $types = array_column($this->json($this->get('/api/curio-types'))['data'], 'attributes', 'id');
        $this->assertTrue($types[$this->typeId('games')]['progress']);
        $this->assertFalse($types[$this->typeId('lego')]['progress']);

        $id = $this->newCollection(3);
        $playing = $this->json($this->item(3, $id, ['title' => 'Hades', 'progress' => 'in_progress']))['data']['id'];
        $this->item(3, $id, ['title' => 'Celeste', 'progress' => 'completed']);
        $this->item(3, $id, ['title' => 'Tunic']);
        $this->assertSame(422, $this->item(3, $id, ['title' => 'Nope', 'progress' => 'abandoned'])->getStatusCode());

        $titles = fn (array $filter) => array_column(array_column($this->json($this->get('/api/curio-items', ['filter' => $filter + ['collection' => $id]]))['data'], 'attributes'), 'title');
        $this->assertSame(['Hades'], $titles(['progress' => 'in_progress']));
        $this->assertSame(['Celeste'], $titles(['progress' => 'completed']));
        $this->assertSame('in_progress', $this->json($this->get("/api/curio-items/$playing"))['data']['attributes']['progress']);

        $csv = (string) $this->send($this->request('GET', "/api/curio-collections/$id/export", ['authenticatedAs' => 3]))->getBody();
        $this->assertStringContainsString(',progress', strtok($csv, "\n"));

        $to = $this->newCollection(3, ['name' => 'Copy']);
        $this->upload(3, $to, $csv."Outer Wilds,,,,,,,,,,,,En cours\n");
        $rows = $this->database()->table('curio_items')->where('collection_id', $to)->orderBy('position')->pluck('progress', 'title')->all();
        $this->assertSame(['Hades' => 'in_progress', 'Celeste' => 'completed', 'Tunic' => null, 'Outer Wilds' => 'in_progress'], $rows);
    }

    #[Test]
    public function a_reading_apps_shelves_become_progress(): void
    {
        $id = $this->newCollection(3, [], 'books');
        $csv = "Book Id,Title,Author,ISBN,ISBN13,My Rating,Year Published,Original Publication Year,Date Added,Exclusive Shelf\n"
            ."1,Dune,Frank Herbert,,,5,1965,1965,2024/01/02,read\n"
            ."2,Hyperion,Dan Simmons,,,0,1989,1989,2024/01/03,currently-reading\n"
            ."3,Foundation,Isaac Asimov,,,0,1951,1951,2024/01/04,to-read\n";
        $this->assertSame(200, $this->upload(3, $id, $csv)->getStatusCode());

        $rows = $this->database()->table('curio_items')->where('collection_id', $id)->orderBy('position')->get(['title', 'status', 'progress']);
        $this->assertSame([['Dune', 'owned', 'completed'], ['Hyperion', 'owned', 'in_progress'], ['Foundation', 'wishlist', null]], $rows->map(fn ($r) => [$r->title, $r->status, $r->progress])->all());
    }

    /** Wikidata and Wikipedia answered by a fake, and French installed. */
    private function fakeWikidata(): void
    {
        $container = $this->app()->getContainer();
        $container->make(\Flarum\Locale\LocaleManager::class)->addLocale('fr', 'Français');
        $container->instance(\Ernestdefoe\Curio\Translation\Wikidata::class, new class extends \Ernestdefoe\Curio\Translation\Wikidata {
            public function __construct()
            {
            }

            public function item(string $property, string $value): ?string
            {
                return null;
            }

            public function find(string $title, ?string $creator, ?int $year, string $class): ?string
            {
                return $title === 'Gran Turismo 7' && $class === 'Q7889' ? 'Q18345763' : null;
            }

            public function entity(string $item, array $languages): array
            {
                return $item === 'Q18345763'
                    ? ['labels' => ['mul' => 'Gran Turismo 7'], 'descriptions' => ['fr' => 'jeu vidéo de 2022'], 'wikis' => ['en' => 'Gran Turismo 7', 'fr' => 'Gran Turismo 7']]
                    : ['labels' => [], 'descriptions' => [], 'wikis' => []];
            }

            public function summary(string $wiki, string $title): ?array
            {
                // English Wikipedia is down this time; French answers.
                return $wiki === 'fr' ? ['extract' => 'Un jeu de course.', 'url' => 'https://fr.wikipedia.org/wiki/Gran_Turismo_7'] : null;
            }
        });
    }

    private function frenchWork(int $item, string $locale = 'fr'): array
    {
        $response = $this->send($this->request('GET', "/api/curio-items/$item")->withCookieParams(['locale' => $locale]));

        return collect($this->json($response)['included'])->firstWhere('type', 'curio-works')['attributes'];
    }

    #[Test]
    public function an_igdb_game_wikidata_missed_is_matched_when_opened(): void
    {
        $this->fakeWikidata();
        $now = Carbon::now();
        $this->database()->table('curio_works')->insert(['id' => 2, 'source' => 'igdb', 'source_id' => '101006', 'title' => 'Gran Turismo 7', 'year' => 2022, 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 0]);
        $this->database()->table('curio_work_texts')->insert(['work_id' => 2, 'locale' => 'en', 'description' => 'IGDB summary.', 'description_source' => 'source', 'fetched_at' => $now]);
        $item = (int) $this->json($this->item(3, $this->newCollection(3), ['title' => 'Gran Turismo 7'], 2))['data']['id'];

        $work = $this->frenchWork($item);
        $this->assertSame(['Un jeu de course.', 'wikipedia', 'fr', null], [$work['description'], $work['descriptionSource'], $work['descriptionLocale'], $work['fallback']]);
        $this->assertSame('Q18345763', $this->database()->table('curio_works')->where('id', 2)->value('wikidata_id'));
        // A failed English fetch kept IGDB's summary.
        $this->assertSame('IGDB summary.', $this->database()->table('curio_work_texts')->where(['work_id' => 2, 'locale' => 'en'])->value('description'));
    }

    #[Test]
    public function without_a_description_in_the_readers_language_the_english_one_waits_aside(): void
    {
        $this->fakeWikidata();
        $now = Carbon::now();
        $this->database()->table('curio_works')->insert(['id' => 2, 'source' => 'igdb', 'source_id' => '191761', 'title' => "Minishoot' Adventures", 'wikidata_id' => 'Q126695337', 'refreshed_at' => $now, 'created_at' => $now, 'languages' => 1]);
        $this->database()->table('curio_work_texts')->insert([
            ['work_id' => 2, 'locale' => 'en', 'title' => null, 'description' => 'A twin-stick shooter.', 'description_source' => 'wikipedia', 'source_url' => 'https://en.wikipedia.org/wiki/Minishoot', 'fetched_at' => $now],
            ['work_id' => 2, 'locale' => 'fr', 'title' => null, 'description' => 'jeu vidéo de 2024', 'description_source' => 'wikidata', 'source_url' => null, 'fetched_at' => $now],
        ]);
        $item = (int) $this->json($this->item(3, $this->newCollection(3), ['title' => 'Minishoot'], 2))['data']['id'];
        $english = ['description' => 'A twin-stick shooter.', 'source' => 'wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Minishoot', 'locale' => 'en'];

        // Only Wikidata's one-liner in French: not a description, the English article aside.
        $work = $this->frenchWork($item);
        $this->assertSame([null, null, $english], [$work['description'], $work['descriptionSource'], $work['fallback']]);

        // Nothing in French at all: no description, the English one aside, never in its place.
        $this->database()->table('curio_work_texts')->where(['work_id' => 2, 'locale' => 'fr'])->delete();
        $work = $this->frenchWork($item);
        $this->assertSame([null, $english], [$work['description'], $work['fallback']]);

        // An English reader gets it as the description.
        $work = $this->frenchWork($item, 'en');
        $this->assertSame(['A twin-stick shooter.', null], [$work['description'], $work['fallback']]);
    }

    private function patchItem(int $as, int $id, array $attributes): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/curio-items/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'curio-items', 'id' => (string) $id, 'attributes' => $attributes]],
        ]));
    }

    private function titles(ResponseInterface $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return array_column(array_column($this->json($response)['data'], 'attributes'), 'title');
    }

    #[Test]
    public function latest_changes_list_progress_and_ratings_newest_first_and_only_what_the_reader_may_see(): void
    {
        $public = $this->newCollection(3);
        $private = $this->newCollection(3, ['name' => 'Private', 'visibility' => 'private']);
        $music = $this->newCollection(3, ['name' => 'Records'], 'music');

        Carbon::setTestNow('2026-10-01 10:00:00');
        $old = (int) $this->json($this->item(3, $public, ['title' => 'Old game', 'progress' => 'completed', 'rating' => 9]))['data']['id'];
        Carbon::setTestNow('2026-10-02 10:00:00');
        $new = (int) $this->json($this->item(3, $public, ['title' => 'New game']))['data']['id'];
        $this->item(3, $private, ['title' => 'Secret game', 'progress' => 'in_progress', 'rating' => 4]);
        // Wanted, or of a type that does not track progress: never "marked as".
        $this->item(3, $public, ['title' => 'Wanted game', 'status' => 'wishlist', 'progress' => 'pending', 'rating' => 8]);
        $this->item(3, $music, ['title' => 'A record', 'progress' => 'completed']);
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->patchItem(3, $new, ['progress' => 'in_progress', 'rating' => 7]);
        Carbon::setTestNow();

        $progress = ['filter' => ['changed' => 'progress'], 'sort' => '-progressChangedAt', 'include' => 'collection.user,collection.type'];
        $ratings = ['filter' => ['changed' => 'rating'], 'sort' => '-ratingChangedAt'];
        // An import never counts as a change.
        $this->assertSame(200, $this->upload(3, $public, "title,rating,progress\nImported game,4,completed\n")->getStatusCode());

        $this->assertSame(['New game', 'Old game'], $this->titles($this->get('/api/curio-items', $progress)));
        $this->assertSame(['New game', 'Old game'], $this->titles($this->get('/api/curio-items', $ratings, 4)));
        // The owner sees their private one too.
        $this->assertSame(['New game', 'Secret game', 'Old game'], $this->titles($this->get('/api/curio-items', $ratings, 3)));

        $first = $this->json($this->get('/api/curio-items', $progress))['data'][0]['attributes'];
        $this->assertStringStartsWith('2026-10-03', $first['progressChangedAt']);

        // Clearing the rating takes the item off the list; a later edit of something else leaves the time alone.
        $this->patchItem(3, $old, ['rating' => null]);
        $this->patchItem(3, $new, ['title' => 'New game (GOTY)']);
        $this->assertSame(['New game (GOTY)'], $this->titles($this->get('/api/curio-items', $ratings)));
        $this->assertStringStartsWith('2026-10-03', $this->json($this->get('/api/curio-items', $progress))['data'][0]['attributes']['progressChangedAt']);
    }

    #[Test]
    public function recently_added_wishlists_show_only_where_their_owners_show_them(): void
    {
        $shown = $this->newCollection(3);
        $hidden = $this->newCollection(3, ['name' => 'Hidden wishes', 'wishlistPublic' => false]);
        $this->item(3, $shown, ['title' => 'Wanted openly', 'status' => 'wishlist']);
        $this->item(3, $hidden, ['title' => 'Wanted quietly', 'status' => 'wishlist']);
        $this->item(3, $shown, ['title' => 'Up for trade', 'status' => 'trade']);
        $this->item(3, $shown, ['title' => 'On the shelf']);

        $list = fn (string $status, ?int $as = null) => $this->titles($this->get('/api/curio-items', ['filter' => ['status' => $status], 'sort' => '-createdAt'], $as));

        $this->assertSame(['Wanted openly'], $list('wishlist'));
        $this->assertSame(['Wanted openly'], $list('wishlist', 4));
        $this->assertSame(['Wanted quietly', 'Wanted openly'], $list('wishlist', 3));
        $this->assertSame(['Up for trade'], $list('trade'));
        $this->assertSame(['On the shelf', 'Up for trade'], $list('owned'));
    }

    #[Test]
    public function import_and_export_each_need_their_permission(): void
    {
        $id = $this->newCollection(3);
        $export = fn () => $this->send($this->request('GET', "/api/curio-collections/$id/export", ['authenticatedAs' => 3]));

        // Members have both by default.
        $this->assertSame(200, $export()->getStatusCode());
        $this->assertSame(200, $this->upload(3, $id, "title\nOne\n")->getStatusCode());
        $attributes = $this->json($this->get("/api/curio-collections/$id", [], 3))['data']['attributes'];
        $this->assertSame([true, true], [$attributes['canImport'], $attributes['canExport']]);
        // Nobody else is told either way.
        $this->assertArrayNotHasKey('canImport', $this->json($this->get("/api/curio-collections/$id", [], 4))['data']['attributes']);

        $this->database()->table('group_permission')->whereIn('permission', ['ernestdefoe-curio.import', 'ernestdefoe-curio.export'])->delete();
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();

        $this->assertSame(403, $export()->getStatusCode());
        $this->assertSame(403, $this->upload(3, $id, "title\nTwo\n")->getStatusCode());
        $attributes = $this->json($this->get("/api/curio-collections/$id", [], 3))['data']['attributes'];
        $this->assertSame([false, false], [$attributes['canImport'], $attributes['canExport']]);
        $this->assertSame(1, $this->database()->table('curio_items')->where('collection_id', $id)->count());
    }

    #[Test]
    public function the_collections_page_offers_compact_tabs_their_colour_and_the_latest_changes_row(): void
    {
        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame(['tags', 'background', true], [$body['layout'], $body['typeColor'], $body['latest']]);

        $this->put('ernestdefoe-curio.browse_layout', 'compact');
        $this->put('ernestdefoe-curio.type_color', 'text');
        $this->put('ernestdefoe-curio.latest_changes', '0');
        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame(['compact', 'text', false], [$body['layout'], $body['typeColor'], $body['latest']]);

        $this->put('ernestdefoe-curio.browse_layout', 'nonsense');
        $this->put('ernestdefoe-curio.type_color', 'nonsense');
        $body = $this->json($this->get('/api/curio/overview'));
        $this->assertSame(['tags', 'background'], [$body['layout'], $body['typeColor']]);
    }

    #[Test]
    public function the_new_lists_cost_a_fixed_number_of_queries(): void
    {
        $id = $this->newCollection(3);
        for ($i = 0; $i < 12; $i++) {
            $this->item(3, $id, ['title' => "Game $i", 'status' => ['owned', 'wishlist', 'trade'][$i % 3], 'progress' => 'completed', 'rating' => 6], $i % 2 ? 1 : null);
        }

        // The repeated-query detector fails these if anything is fetched per row.
        foreach ([['status' => 'wishlist'], ['status' => 'trade'], ['changed' => 'progress'], ['changed' => 'rating']] as $filter) {
            $this->assertSame(200, $this->get('/api/curio-items', ['filter' => $filter, 'include' => 'collection.user,collection.type', 'sort' => '-createdAt', 'page' => ['limit' => 12]], 4)->getStatusCode());
        }
    }

    /** Card look-ups answered by a fake, by URL. */
    private function fakeCards(): object
    {
        $container = $this->app()->getContainer();
        $fake = new class($container->make(\Illuminate\Contracts\Cache\Repository::class), $container->make(\Flarum\Http\UrlGenerator::class)) extends \Ernestdefoe\Curio\Lookup\Http {
            public array $calls = [];

            public function json(string $service, string $url, array $query = [], array $headers = [], float $timeout = 6): array
            {
                $this->calls[] = $url.($query ? '?'.http_build_query($query) : '');

                return match (true) {
                    (bool) preg_match('#/[a-z]{2}/cards$#', $url) => [
                        ['id' => 'swsh3-136', 'localId' => '136', 'name' => 'Fouinar ex'],
                        ['id' => 'swsh3-135', 'localId' => '135', 'name' => 'Fouinar', 'image' => 'https://assets.tcgdex.net/fr/swsh/swsh3/135'],
                        ['id' => 'x', 'name' => 'no local id'],
                    ],
                    (bool) preg_match('#/[a-z]{2}/sets$#', $url) => [['id' => 'swsh3', 'name' => 'Ténèbres Embrasées', 'cardCount' => ['official' => 189]]],
                    str_ends_with($url, '/fr/cards/swsh3-135') => ['id' => 'swsh3-135', 'localId' => '135', 'name' => 'Fouinar', 'rarity' => 'Peu Commune', 'illustrator' => 'Kouki Saitou',
                        'variants' => ['normal' => true, 'reverse' => true, 'holo' => false, 'firstEdition' => false, 'wPromo' => false],
                        'image' => 'https://evil.example/x', 'set' => ['name' => 'Ténèbres Embrasées', 'cardCount' => ['official' => 189]]],
                    str_contains($url, 'scryfall.com/cards/search') => ['data' => [['id' => '0852f9f3-d7ef-4d31-93e3-93bc7c5a4ec9', 'name' => 'Lightning Bolt', 'set_name' => 'Foundations',
                        'collector_number' => '163', 'released_at' => '2024-11-15', 'image_uris' => ['small' => 'https://cards.scryfall.io/small/x.jpg']]]],
                    str_contains($url, 'ygoprodeck.com') => isset($query['id']) ? ['data' => [['id' => 46986414, 'name' => 'Magicien Sombre', 'card_sets' => [['set_name' => 'Legend of Blue Eyes', 'set_code' => 'LOB-005', 'set_rarity' => 'Ultra Rare']],
                        'card_images' => [['image_url' => 'https://images.ygoprodeck.com/images/cards/46986414.jpg']]]]]
                        : ['data' => [['id' => 46986414, 'name' => 'Magicien Sombre', 'humanReadableCardType' => 'Normal Monster', 'card_sets' => [
                            ['set_name' => 'Legend of Blue Eyes', 'set_code' => 'LOB-005', 'set_rarity' => 'Ultra Rare'], ['set_name' => 'Dark Legends', 'set_code' => 'DLG1-EN004', 'set_rarity' => 'Rare'],
                        ], 'card_images' => [['image_url' => 'https://images.ygoprodeck.com/images/cards/46986414.jpg']]]]],
                    default => [],
                };
            }
        };
        $container->instance(\Ernestdefoe\Curio\Lookup\Http::class, $fake);

        return $fake;
    }

    private function lookup(array $body, string $locale = 'fr'): array
    {
        $request = $this->request('POST', '/api/curio/lookup', ['authenticatedAs' => 3, 'json' => $body]);

        return $this->json($this->send($request->withHeader('Accept-Language', $locale)));
    }

    #[Test]
    public function trading_cards_are_looked_up_on_the_source_the_admin_picked(): void
    {
        $fake = $this->fakeCards();
        $cards = $this->typeId('cards');
        $this->assertSame(['set', 'number', 'variant', 'grade', 'rarity', 'language'], array_column(json_decode((string) $this->database()->table('curio_types')->where('id', $cards)->value('fields'), true), 'key'));

        // None until the admin picks one.
        $this->assertSame([], $this->lookup(['type' => $cards, 'q' => 'fouinar'])['results']);

        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'cards']);
        $results = $this->lookup(['type' => $cards, 'q' => 'Fouinar'])['results'];

        // Each result names its own source; Pokémon, Magic and Yu-Gi-Oh! take turns.
        $this->assertSame(['tcgdex', 'scryfall', 'ygoprodeck', 'tcgdex'], array_column($results, 'source'));
        $this->assertSame(['en/swsh3-135', 'Fouinar', null, 'Pokémon · Ténèbres Embrasées', '135/189'], [$results[0]['id'], $results[0]['title'], $results[0]['creator'], $results[0]['set'], $results[0]['number']]);
        $this->assertSame('https://assets.tcgdex.net/fr/swsh/swsh3/135/low.webp', $results[0]['cover']);
        // YGOPRODeck asks not to be hotlinked: no picture until it is added; its printings are choices.
        $this->assertNull($results[2]['cover']);
        $this->assertSame(['LOB-005', 'DLG1-EN004'], array_column($results[2]['variants'], 'label'));
        $this->assertSame(['set' => 'Legend of Blue Eyes', 'number' => 'LOB-005', 'rarity' => 'Ultra Rare', 'language' => null], $results[2]['variants'][0]['fields']);
        $this->assertSame('en', $results[2]['variants'][1]['fields']['language']);
        $this->assertSame('Legend of Blue Eyes · Ultra Rare', $results[2]['variants'][0]['title']);

        // Kept a day: the same search again asks nobody.
        $before = count($fake->calls);
        $this->lookup(['type' => $cards, 'q' => 'fouinar']);
        $this->assertSame($before, count($fake->calls));
    }

    #[Test]
    public function a_picked_card_brings_its_set_number_rarity_and_language(): void
    {
        $this->fakeCards();

        $workId = $this->lookup(['source' => 'tcgdex', 'id' => 'fr/swsh3-135'])['workId'];
        $work = $this->json($this->get("/api/curio-works/$workId", [], 3))['data']['attributes'];
        $this->assertSame(['set' => 'Ténèbres Embrasées', 'number' => '135/189', 'rarity' => 'Peu Commune', 'language' => 'fr', 'variants' => ['normal', 'reverse']], $work['facts']['card']);
        // The creator line is the card's artist, not its set.
        $this->assertSame('Kouki Saitou', $work['creator']);
        // A picture from anywhere but TCGdex's own host is never fetched.
        $this->assertNull($work['coverUrl']);

        $workId = $this->lookup(['source' => 'ygoprodeck', 'id' => 'fr/46986414'])['workId'];
        $work = $this->json($this->get("/api/curio-works/$workId", [], 3))['data']['attributes'];
        $this->assertSame(['set' => 'Legend of Blue Eyes', 'number' => 'LOB-005', 'rarity' => 'Ultra Rare'], $work['facts']['card']);

        // Ids are checked before anything is asked.
        foreach ([['tcgdex', 'fr/../../admin'], ['tcgdex', 'xx/swsh3-1'], ['scryfall', 'not-a-uuid'], ['ygoprodeck', 'fr/12a']] as [$source, $id]) {
            $response = $this->send($this->request('POST', '/api/curio/lookup', ['authenticatedAs' => 3, 'json' => ['source' => $source, 'id' => $id]]));
            $this->assertSame(404, $response->getStatusCode(), "$source $id");
        }
    }

    #[Test]
    public function game_results_name_the_platforms_they_came_out_on(): void
    {
        $this->assertSame([
            ['label' => 'PC', 'fields' => ['platform' => 'PC (Microsoft Windows)']],
            ['label' => 'Nintendo Switch', 'fields' => ['platform' => 'Nintendo Switch']],
            ['label' => 'Linux', 'fields' => ['platform' => 'Linux']],
        ], \Ernestdefoe\Curio\Lookup\Sources\Igdb::platforms(['platforms' => [
            ['id' => 3, 'name' => 'Linux'], ['id' => 130, 'name' => 'Nintendo Switch'], ['id' => 6, 'name' => 'PC (Microsoft Windows)', 'abbreviation' => 'PC'],
        ]]));

        // Through Marquee, the platforms are the first part of its line ("PC, PS5 +4 · Action").
        $marquee = new \Ernestdefoe\Curio\Lookup\Sources\Marquee('game', fn () => [
            ['id' => 1, 'title' => 'Hades', 'subtitle' => 'PC, PS5, Switch +4 · Action, RPG'],
            ['id' => 2, 'title' => 'Only genres', 'subtitle' => 'Action, RPG'],
            ['id' => 3, 'title' => 'Only platforms', 'subtitle' => 'PS4, Vita'],
        ], fn () => []);
        $results = $marquee->search('hades');

        $this->assertSame(['PC', 'PS5', 'Switch'], array_column($results[0]['variants'], 'label'));
        $this->assertSame('PlayStation 5', $results[0]['variants'][1]['fields']['platform']);
        $this->assertSame('Action, RPG', $results[0]['creator']);
        $this->assertSame([[], 'Action, RPG'], [$results[1]['variants'], $results[1]['creator']]);
        $this->assertSame(['PlayStation 4', 'PS Vita'], array_column(array_column($results[2]['variants'], 'fields'), 'platform'));
    }

    #[Test]
    public function an_imported_card_is_matched_only_to_the_printing_with_its_number(): void
    {
        $same = new \ReflectionMethod(\Ernestdefoe\Curio\Console\MatchWorks::class, 'sameNumber');
        $this->assertTrue($same->invoke(null, '136/189', '136'));
        $this->assertTrue($same->invoke(null, '036', '36/102'));
        $this->assertFalse($same->invoke(null, '136/189', '135'));
        $this->assertFalse($same->invoke(null, '136', null));
    }

    #[Test]
    public function a_card_search_reads_the_set_and_number_after_the_name(): void
    {
        $q = \Ernestdefoe\Curio\Lookup\CardQuery::parse('Goomy fst 195/264');
        $this->assertSame([['Goomy fst', null, '195'], ['Goomy', 'fst', '195'], ['', 'Goomy fst', '195'], ['Goomy fst 195/264', null, null], ['Goomy fst', '195/264', null], ['Goomy', 'fst 195/264', null]], $q->attempts());
        $this->assertSame('264', $q->total);
        $this->assertSame([['Goomy', null, null]], \Ernestdefoe\Curio\Lookup\CardQuery::parse('Goomy')->attempts());
        $this->assertSame('LOB-EN001', \Ernestdefoe\Curio\Lookup\CardQuery::parse('lob-en001')->code);
        // Japanese-style promos: the set code after the slash, or before the number with no name.
        $this->assertSame([['', 'SV-P', '201']], \Ernestdefoe\Curio\Lookup\CardQuery::parse('201/SV-P')->attempts());
        $this->assertSame([['シャリタツ', 'SV-P', '201']], \Ernestdefoe\Curio\Lookup\CardQuery::parse('シャリタツ 201/SV-P')->attempts());
        $this->assertSame(['', 'SV-P', '201'], \Ernestdefoe\Curio\Lookup\CardQuery::parse('SV-P 201')->attempts()[1]);
        $this->assertSame(['TG30', null], [\Ernestdefoe\Curio\Lookup\CardQuery::parse('Pikachu TG05/TG30')->total, \Ernestdefoe\Curio\Lookup\CardQuery::parse('Pikachu TG05/TG30')->set]);
        $this->assertSame([['201', null, null]], \Ernestdefoe\Curio\Lookup\CardQuery::parse('201')->attempts());
        $this->assertTrue(\Ernestdefoe\Curio\Lookup\CardQuery::sameNumber('066', '66'));
        $this->assertTrue(\Ernestdefoe\Curio\Lookup\CardQuery::sameNumber('TG05', 'tg5'));
        $this->assertFalse(\Ernestdefoe\Curio\Lookup\CardQuery::sameNumber('195', '19'));
    }

    /** Pokémon, Magic and Yu-Gi-Oh! answered by a fake with sets, codes and a name printed 30 times. */
    private function fakeCardCatalogue(): object
    {
        $container = $this->app()->getContainer();
        $fake = new class($container->make(\Illuminate\Contracts\Cache\Repository::class), $container->make(\Flarum\Http\UrlGenerator::class)) extends \Ernestdefoe\Curio\Lookup\Http {
            public array $calls = [];

            public function json(string $service, string $url, array $query = [], array $headers = [], float $timeout = 6): array
            {
                $this->calls[] = $url.($query ? '?'.http_build_query($query) : '');
                $card = fn (string $set, string $n, string $name) => ['id' => "$set-$n", 'localId' => $n, 'name' => $name, 'image' => "https://assets.tcgdex.net/en/x/$set/$n"];

                return match (true) {
                    str_ends_with($url, '/en/cards') => match ($query['name'] ?? '') {
                        'Goomy' => [$card('xy7', '58', 'Goomy'), $card('swsh8', '195', 'Goomy'), $card('sm6', '091', 'Goomy')],
                        'Pikachu' => array_map(fn ($i) => $card('base1', (string) $i, 'Pikachu'), range(1, 30)),
                        'Tatsugiri' => [$card('sv08', '142', 'Tatsugiri')],
                        default => [],
                    },
                    str_ends_with($url, '/en/sets') => [
                        ['id' => 'xy7', 'name' => 'Ancient Origins', 'cardCount' => ['official' => 98]], ['id' => 'swsh8', 'name' => 'Fusion Strike', 'cardCount' => ['official' => 264]],
                        ['id' => 'sm6', 'name' => 'Forbidden Light', 'cardCount' => ['official' => 131]], ['id' => 'base1', 'name' => 'Base Set', 'cardCount' => ['official' => 102]],
                    ],
                    str_ends_with($url, '/en/sets/swsh8') => ['id' => 'swsh8', 'abbreviation' => ['official' => 'FST']],
                    // Japanese: its own sets; a Pokémon's English name leads to its Pokédex number.
                    str_ends_with($url, '/ja/cards') => match ($query['name'] ?? $query['dexId'] ?? '') {
                        'シャリタツ', 'eq:978' => [['id' => 'SV1S-022', 'localId' => '022', 'name' => 'シャリタツ'], ['id' => 'SV-P-201', 'localId' => '201', 'name' => 'シャリタツ']],
                        default => [],
                    },
                    str_ends_with($url, '/ja/sets') => [['id' => 'SV-P', 'name' => 'スカーレット&バイオレット プロモカード', 'cardCount' => ['official' => 0]], ['id' => 'SV1S', 'name' => 'スカーレットex', 'cardCount' => ['official' => 78]]],
                    str_ends_with($url, '/ja/sets/SV-P') => ['id' => 'SV-P', 'cards' => [['id' => 'SV-P-201', 'localId' => '201', 'name' => 'シャリタツ'], ['id' => 'SV-P-202', 'localId' => '202', 'name' => 'ピカチュウ']]],
                    str_ends_with($url, '/en/cards/sv08-142') => ['id' => 'sv08-142', 'name' => 'Tatsugiri', 'dexId' => [978]],
                    str_contains($url, 'scryfall.com/cards/search') && str_contains($query['q'], 'lang:ja') => ['data' => [
                        ['id' => '623d1113-17c5-429d-8f91-978726aeb19d', 'lang' => 'ja', 'name' => 'Arc Lightning', 'printed_name' => '弧状の稲妻', 'set' => 'ktk', 'set_name' => 'Khans of Tarkir', 'collector_number' => '97', 'released_at' => '2014-09-26'],
                        ['id' => '89ea9a01-a4e9-43c5-bdd7-3a0e4dcec0f3', 'lang' => 'ja', 'name' => 'Lightning Bolt', 'printed_name' => '稲妻', 'set' => 'sta', 'set_name' => 'Strixhaven Mystical Archive', 'collector_number' => '42', 'released_at' => '2021-04-23'],
                    ]],
                    str_contains($url, 'scryfall.com/cards/search') => str_contains($query['q'], 'set:2x2') ? ['data' => [['id' => '0852f9f3-d7ef-4d31-93e3-93bc7c5a4ec9', 'name' => 'Lightning Bolt',
                        'set' => '2x2', 'set_name' => 'Double Masters 2022', 'collector_number' => '117', 'released_at' => '2022-07-08']]] : [],
                    str_contains($url, 'cardsetsinfo.php') => $query['setcode'] === 'LOB-EN001' ? ['id' => 89631139, 'name' => 'Blue-Eyes White Dragon'] : [],
                    str_contains($url, 'cardinfo.php') => ['data' => [['id' => 89631139, 'name' => 'Blue-Eyes White Dragon', 'card_sets' => [
                        ['set_name' => 'Legend of Blue Eyes White Dragon', 'set_code' => 'LOB-EN001', 'set_rarity' => 'Ultra Rare'], ['set_name' => 'Starter Deck: Kaiba', 'set_code' => 'SDK-001', 'set_rarity' => 'Ultra Rare'],
                    ]]]],
                    default => [],
                };
            }

            public function post(string $service, string $url, array $query = [], array $headers = [], ?string $body = null, float $timeout = 6): array
            {
                $this->calls[] = "POST $url";

                return ['data' => ['sets' => [['id' => 'xy7', 'tcgOnline' => 'AOR'], ['id' => 'swsh8', 'tcgOnline' => null]]]];
            }
        };
        $container->instance(\Ernestdefoe\Curio\Lookup\Http::class, $fake);

        return $fake;
    }

    #[Test]
    public function cards_are_found_by_set_and_number_and_a_page_at_a_time(): void
    {
        $fake = $this->fakeCardCatalogue();
        $cards = $this->typeId('cards');
        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'pokemon']);
        $ids = fn (string $q, int $page = 1) => array_column($this->lookup(['type' => $cards, 'q' => $q, 'page' => $page], 'en')['results'], 'id');

        $found = $this->lookup(['type' => $cards, 'q' => 'Goomy 195/264'], 'en');
        $this->assertSame(['en/swsh8-195'], array_column($found['results'], 'id'));
        $this->assertSame(['Fusion Strike', '195/264', false], [$found['results'][0]['set'], $found['results'][0]['number'], $found['more']]);
        $this->assertSame(['en/swsh8-195'], $ids('Goomy 195'));
        $this->assertSame(['en/sm6-091'], $ids('Goomy 91'));
        $this->assertSame([], $ids('Goomy 195/98'));
        // The set by the code on the card (from GraphQL, or the set's own page), its id or its name.
        $this->assertSame(['en/xy7-58'], $ids('Goomy aor'));
        $this->assertSame(['en/swsh8-195'], $ids('Goomy fst 195'));
        $this->assertSame(['en/swsh8-195'], $ids('Goomy Fusion Strike'));
        $this->assertSame(['en/sm6-091'], $ids('Goomy sm6'));
        $this->assertCount(3, $ids('Goomy'));

        // Thirty Pikachu, a dozen at a time, from one request kept a day.
        $before = count(array_filter($fake->calls, fn ($c) => str_contains($c, 'name=Pikachu')));
        $first = $this->lookup(['type' => $cards, 'q' => 'Pikachu'], 'en');
        $this->assertSame([12, true], [count($first['results']), $first['more']]);
        $last = $this->lookup(['type' => $cards, 'q' => 'Pikachu', 'page' => 3], 'en');
        $this->assertSame([6, false, 'en/base1-25'], [count($last['results']), $last['more'], $last['results'][0]['id']]);
        $this->assertSame($before + 1, count(array_filter($fake->calls, fn ($c) => str_contains($c, 'name=Pikachu'))));
        // The set codes: one GraphQL answer, and one set page for a set it leaves out.
        $this->assertSame(1, count(array_filter($fake->calls, fn ($c) => str_starts_with($c, 'POST'))));
        $this->assertSame(1, count(array_filter($fake->calls, fn ($c) => str_ends_with($c, '/en/sets/swsh8'))));

        // Magic: the set code and collector number become Scryfall's own.
        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'magic']);
        $found = $this->lookup(['type' => $cards, 'q' => 'Lightning Bolt 2x2 117'], 'en')['results'];
        $this->assertSame(['Double Masters 2022 (2X2)', '117'], [$found[0]['set'], $found[0]['number']]);
        $this->assertContains('https://api.scryfall.com/cards/search?q=Lightning+Bolt+set%3A2x2+cn%3A117&unique=prints&order=released', $fake->calls);

        // Yu-Gi-Oh!: a printing code finds that printing.
        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'yugioh']);
        $found = $this->lookup(['type' => $cards, 'q' => 'LOB-EN001'], 'en')['results'];
        $this->assertSame(['Blue-Eyes White Dragon', 'LOB-EN001', ['LOB-EN001']], [$found[0]['title'], $found[0]['number'], array_column($found[0]['variants'], 'label')]);
    }

    #[Test]
    public function cards_are_found_in_the_card_language_picked(): void
    {
        $fake = $this->fakeCardCatalogue();
        $cards = $this->typeId('cards');
        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'pokemon']);
        $find = fn (string $q, string $lang) => $this->lookup(['type' => $cards, 'q' => $q, 'lang' => $lang], 'en')['results'];

        // Its own name, the English one (through the Pokédex number), or just the set and number.
        foreach (['シャリタツ 201', 'Tatsugiri 201', 'Tatsugiri 201/SV-P', '201/SV-P', 'SV-P 201'] as $q) {
            $found = $find($q, 'ja');
            $this->assertSame([['ja/SV-P-201', 'シャリタツ', '201/SV-P']], array_map(fn ($r) => [$r['id'], $r['title'], $r['number']], $found), $q);
        }
        $this->assertSame(['ja/SV1S-022', 'ja/SV-P-201'], array_column($find('Tatsugiri', 'ja'), 'id'));
        // The species is looked up once, then kept.
        $this->assertSame(1, count(array_filter($fake->calls, fn ($c) => str_ends_with($c, '/en/cards/sv08-142'))));
        // A language TCGdex does not have is the reader's own, as before.
        $this->assertSame(['en/swsh8-195'], array_column($find('Goomy 195', 'xx'), 'id'));

        $workId = $this->lookup(['source' => 'tcgdex', 'id' => 'zh-tw/SV1S-022'])['workId'] ?? null;
        $this->assertNull($workId, 'a card TCGdex does not have is not stored');

        // Magic: Scryfall's own lang:, the printed name first when it is the one asked for.
        $this->database()->table('curio_types')->where('id', $cards)->update(['source' => 'magic']);
        $found = $find('稲妻', 'ja');
        $this->assertSame([['稲妻', 'Lightning Bolt'], ['弧状の稲妻', 'Arc Lightning']], array_map(fn ($r) => [$r['title'], $r['name']], $found));
        $this->assertContains('https://api.scryfall.com/cards/search?q=%E7%A8%B2%E5%A6%BB+lang%3Aja&unique=prints&order=released', $fake->calls);
        // Magic was never printed in Thai: nothing, and nothing asked.
        $before = count($fake->calls);
        $this->assertSame([], $find('Lightning Bolt', 'th'));
        $this->assertSame($before, count($fake->calls));
    }

    #[Test]
    public function a_card_stored_with_its_set_as_creator_gets_its_artist_back(): void
    {
        $this->fakeCards();
        $now = Carbon::now()->toDateTimeString();
        $this->database()->table('curio_works')->insert(['id' => 7, 'source' => 'tcgdex', 'source_id' => 'fr/swsh3-135', 'title' => 'Fouinar', 'creator' => null,
            'facts' => json_encode(['card' => ['set' => 'Ténèbres Embrasées']]), 'refreshed_at' => '2000-01-01 00:00:00', 'created_at' => $now, 'languages' => 0]);
        $id = $this->newCollection(3, [], 'cards');
        $item = (int) $this->json($this->item(3, $id, ['title' => 'Fouinar'], 7))['data']['id'];

        $this->app()->getContainer()->make(\Ernestdefoe\Curio\Lookup\WorkResolver::class)->refreshCard(\Ernestdefoe\Curio\Work::query()->findOrFail(7));

        $work = $this->database()->table('curio_works')->where('id', 7)->first();
        $this->assertSame('Kouki Saitou', $work->creator);
        $this->assertSame(['normal', 'reverse'], json_decode($work->facts, true)['card']['variants']);
        $this->assertGreaterThan(\Ernestdefoe\Curio\Console\MatchWorks::CARD_MARK, (string) $work->refreshed_at);
        $this->assertSame('Kouki Saitou', $this->database()->table('curio_items')->where('id', $item)->value('subtitle'));
    }

    #[Test]
    public function a_copy_into_a_collection_of_another_type_keeps_only_details_that_type_has(): void
    {
        $xbox = $this->newCollection(3, ['name' => 'Xbox']);
        $books = $this->newCollection(4, ['name' => 'Books'], 'books');

        // The details of a game (platform) mean nothing on a bookshelf, and nobody adds to a shelf that is not theirs.
        $response = $this->item(4, $books, ['title' => 'Halo', 'fields' => ['platform' => 'xbox-one', 'format' => 'hardback']]);
        $this->assertEquals(['format' => 'hardback'], $this->json($response)['data']['attributes']['fields']);
        $this->assertSame(403, $this->item(4, $xbox, ['title' => 'Halo'])->getStatusCode());
    }
}
