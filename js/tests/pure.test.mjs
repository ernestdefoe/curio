import test from 'node:test';
import assert from 'node:assert/strict';
import { pick, optionLabel, fieldValue } from '../src/common/labels.js';
import GridSort from '../src/common/gridSort.js';

test('labels follow the reader’s language, then its base, then English', () => {
  const labels = { en: 'Platform', fr: 'Plateforme', 'pt-BR': 'Plataforma' };
  assert.equal(pick(labels, 'fr'), 'Plateforme');
  assert.equal(pick(labels, 'pt-BR'), 'Plataforma');
  assert.equal(pick(labels, 'fr-CA'), 'Plateforme');
  assert.equal(pick(labels, 'de'), 'Platform');
  assert.equal(pick({ fr: 'Seulement' }, 'de'), 'Seulement');
  assert.equal(pick('plain', 'fr'), 'plain');
  assert.equal(pick(null, 'fr'), '');
});

test('choice values read as their option’s label', () => {
  const field = { kind: 'select', options: [{ key: 'collector', labels: { en: "Collector's Edition", fr: 'Édition collector' } }] };
  assert.equal(optionLabel(field, 'collector', 'fr'), 'Édition collector');
  assert.equal(optionLabel(field, 'gone', 'fr'), 'gone');
  assert.equal(fieldValue(field, 'collector', 'en'), "Collector's Edition");
  assert.equal(fieldValue({ kind: 'text' }, 'PAL', 'en'), 'PAL');
  assert.equal(fieldValue({ kind: 'text' }, '', 'en'), null);
});

test('arrow keys move a card one place and save once', () => {
  const items = ['a', 'b', 'c'];
  let saves = 0;
  const sort = new GridSort(
    () => items,
    () => saves++
  );
  const press = (index, key) => sort.attrs(index).onkeydown({ key, preventDefault() {}, target: { closest: () => null } });

  press(0, 'ArrowRight');
  assert.deepEqual(items, ['b', 'a', 'c']);
  press(0, 'ArrowLeft');
  assert.deepEqual(items, ['b', 'a', 'c']);
  press(2, 'ArrowUp');
  assert.deepEqual(items, ['b', 'c', 'a']);
  press(1, 'Enter');
  assert.equal(saves, 2);
});

import { onlyOption } from '../src/common/labels.js';
import { ratingAt, starsLabel } from '../src/common/rating.js';

test('a looked-up platform list picks an option only when exactly one matches', () => {
  const field = {
    kind: 'select',
    options: [
      { key: 'ps5', labels: { en: 'PlayStation 5' } },
      { key: 'pc', labels: { en: 'PC' } },
      { key: 'switch', labels: { en: 'Nintendo Switch' } },
    ],
  };
  assert.equal(onlyOption(field, ['PlayStation 5']), 'ps5');
  assert.equal(onlyOption(field, ['PC (Microsoft Windows)', 'Mac']), 'pc');
  assert.equal(onlyOption(field, ['PC (Microsoft Windows)', 'PlayStation 5']), null);
  assert.equal(onlyOption(field, ['Dreamcast']), null);
  assert.equal(onlyOption(field, undefined), null);
});

test('half-star ratings follow the pointer and read as stars', () => {
  assert.equal(ratingAt(1, 100), 1);
  assert.equal(ratingAt(50, 100), 5);
  assert.equal(ratingAt(100, 100), 10);
  assert.equal(ratingAt(150, 100), 10);
  assert.equal(ratingAt(5, 0), 0);
  assert.equal(starsLabel(9), '4.5');
  assert.equal(starsLabel(8), '4');
});
