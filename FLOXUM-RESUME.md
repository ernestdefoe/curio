# Curio: Floxum wave 2, paused (resume notes)

## State: INCOMPLETE. The pre-floxum agent was stopped mid-edit. Verify everything before using it.
- DONE and tested (SQLite, against both Marquee versions): the Marquee reader change in src/Api/MarqueeTitle.php (discussion() and discussionFromColumns()). New test tests/integration/api/MarqueeTitleTest.php. composer.json: marquee added to require-dev and optional-dependencies (that was a Floxum finding).
- IN PROGRESS when stopped: the pre-floxum fixes. That includes constructor injection in the console commands, ItemResource and CollectionResource, a TypeScript migration (js/src/admin/*.tsx, js/src/common/extend.ts, tsconfig) and new src/Allowance.php and src/Api/ForumFields.php. None of it is verified. Run check-typings, the build, PHPStan and the full test suite first, then finish or revert each piece.
- Floxum's open findings (v1.27.0, 94): CollectionResource Update has no can('update') (medium); reposition() interpolates SQL; marquee was missing from optional-dependencies (now added); PhotoValidator uses resolve().

## Why this branch exists

Floxum wave 2 (Ernest 2026-11-19: "I want 100 on everything"; Marquee v1.20.2 was reviewed at 87). Paused on 2026-11-19 by Ernest ("Yes, pause rewrites"), with weekly usage at about 43%; resume after the reset on 2026-11-26. This branch is LOCAL ONLY: nothing is pushed or tagged. Some vendor/ dirs hold an rsync copy of the unreleased Marquee for testing; run `composer install` to get Packagist back.

## The coordinated move (5 repos)

Marquee moves its five columns off `discussions` (marquee_media_id, marquee_season, marquee_episode, marquee_related, marquee_backdrops) into `marquee_discussions` (discussion_id PK, media_id, season, episode, backdrops) plus `marquee_related` (discussion_id, media_id, position). The model is `Ernestdefoe\Marquee\Link`, reached as the Discussion relation `marqueeLink`.

Readers: Curio (MarqueeTitle), Shelf (DiscussionLinks), Club (PickResource), Spoiler Shield (MarkWriter plus its 2026_11_10_200004 backfill). Each reads through `Link` when `class_exists(Link::class)`, and otherwise the old columns.

Release order:
1. Readers first: Curio v1.27.1, Shelf v1.1.3, Club v1.0.3, Spoiler Shield 1.1.1. Commit subjects `fix:` only, so the drafter bumps the patch.
2. Then Marquee v1.21.0. It needs a `feat:` subject for the minor bump, and its composer.json conflicts with older readers: club <1.0.3, curio <1.27.1, shelf <1.1.3, spoiler-shield <1.1.1. If a reader ships at a different version (Shelf may take 1.1.3 for its security fix alone), raise the conflict to match.
3. Deploy readers first, then Marquee, then `php flarum migrate`. Do it on dev, then the showcase, then any live forum that runs them.
4. Before ANY tag: one combined review package to the Command Center. It needs the commit ranges, each repo's fresh pre-floxum, and tests on SQLite, MySQL and PostgreSQL for all 5 repos. The readers are also tested on SQLite against Marquee 1.20.2.

Spoiler Shield privacy gate: while a discussion saves, Marquee keeps the changed Link as the loaded `marqueeLink` relation (dirty media_id/season/episode; media_id null means unlinked) and writes it in afterSave. MarkWriter::marqueeChange() reads that. Marquee test: `while_a_discussion_saves_its_changed_link_is_the_loaded_relation`.

Test databases used: the Docker containers marquee_mysql_test (127.0.0.1:33911) and marquee_pg_test (127.0.0.1:55911), root/root, prefix dev_, one database per repo (curio_test etc.). They may be gone after the pause; the shared notes file marquee-companion-table-reader-brief.md has the details.
