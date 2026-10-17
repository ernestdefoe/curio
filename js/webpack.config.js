const config = require('flarum-webpack-config');

const NS = 'ernestdefoe-curio';

/*
 * Lazy chunks need ids no other extension can have: Flarum finds a chunk's URL
 * by its id alone and every extension's chunks share one loading global, so
 * webpack's default 3-digit ids collide across extensions. Copied from
 * onair-plus.
 */
class NamespacedChunkIds {
  apply(compiler) {
    compiler.hooks.compilation.tap('NamespacedChunkIds', (compilation) => {
      compilation.hooks.chunkIds.tap('NamespacedChunkIds', (chunks) => {
        for (const chunk of chunks) {
          if (chunk.id === null) {
            chunk.id = `${NS}:${chunk.name || chunk.debugId}`;
            chunk.ids = [chunk.id];
          }
        }
      });
    });
  }
}

module.exports = (...args) => {
  let base = config();
  if (typeof base === 'function') base = base(...args);

  base.optimization = { ...base.optimization, chunkIds: false };
  base.output = { ...base.output, chunkLoadingGlobal: 'webpackChunk_ernestdefoe_curio' };
  base.plugins = [...(base.plugins || []), new NamespacedChunkIds()];

  return base;
};
