# Vendored xterm.js

`xterm.mjs` and `xterm.css` are the unmodified distribution files from
[`@xterm/xterm`](https://github.com/xtermjs/xterm.js) **6.0.0**, MIT licensed —
see `LICENSE`.

They are vendored rather than pulled from npm so the package needs no build step
and no network access at runtime: Horizon inlines its own dashboard bundle into
the page, and this is inlined the same way.

Because the ESM build ends in `export{<minified> as Terminal}`, which cannot be
imported from an inline module, `LayoutDecorator::terminal()` rewrites that final
export into a global assignment. If a future xterm release changes the shape of
that export the rewrite is skipped, and the panel falls back to its own HTML
renderer.

## Updating

```bash
npm pack @xterm/xterm@<version>
tar xzf xterm-xterm-<version>.tgz
cp package/lib/xterm.mjs package/css/xterm.css package/LICENSE .
```

Then confirm the panel still renders a progress bar as a single animating line.
