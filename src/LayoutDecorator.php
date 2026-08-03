<?php

namespace Knobik\HorizonJobOutput;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;

/**
 * Injects this package's additions into Horizon's rendered dashboard layout.
 *
 * Horizon inlines a compiled Vue bundle and exposes no extension point, so the
 * output panel, the reserved jobs page and its sidebar link are all added by
 * patching anchors in the rendered HTML. Every patch is optional: if Horizon
 * changes its markup the dashboard still renders, just without that piece.
 */
class LayoutDecorator
{
    /**
     * The element the panel is mounted into.
     */
    public const ROOT_ID = 'hjo-root';

    /**
     * The element the reserved jobs page is mounted into.
     */
    public const PAGE_ID = 'hjo-page';

    /**
     * The dashboard path the reserved jobs page answers to.
     */
    public const PAGE_PATH = '/reserved';

    protected const ROUTER_VIEW_ANCHOR = '<router-view></router-view>';

    protected const BODY_ANCHOR = '</body>';

    protected const NAV_ANCHOR = '<ul class="nav flex-column">';

    protected const NAV_CLOSE = '</ul>';

    /**
     * How much of the xterm build to search for its trailing export statement.
     */
    protected const TAIL_BYTES = 512;

    protected const EXPORT_PATTERN = '/export\s*\{\s*(\w+)\s+as\s+Terminal\s*\}\s*;?/';

    public function __construct(protected Config $config) {}

    public function decorate(string $html): string
    {
        $html = $this->injectMounts($html);

        if ($this->reservedPageEnabled()) {
            $html = $this->injectNavItem($html);
        }

        return $this->injectAssets($html);
    }

    protected function reservedPageEnabled(): bool
    {
        return (bool) $this->config->get('horizon-job-output.reserved_page', true);
    }

    /**
     * Splice content into the layout at an anchor, or warn and leave it alone.
     *
     * Every patch this class makes is optional — Horizon can change its markup at
     * any release — so each one reports what the dashboard will be missing rather
     * than failing. $from is where to start looking, which is how one anchor gets
     * located relative to an earlier one.
     */
    protected function patch(
        string $html,
        string $anchor,
        string $insert,
        string $missing,
        bool $before = false,
        int $from = 0,
    ): string {
        $position = strpos($html, $anchor, $from);

        if ($position === false) {
            $this->warn($anchor, $missing);

            return $html;
        }

        return substr_replace($html, $insert, $before ? $position : $position + strlen($anchor), 0);
    }

    /**
     * Add the mount points immediately after Horizon's router view.
     *
     * These land inside #horizon, which Vue uses as its in-DOM template, so they
     * become static nodes in the compiled template. Vue renders them once and
     * never patches them, which is far safer than inserting into Vue-owned DOM
     * after mount. The reserved jobs page relies on the same placement: Horizon's
     * router has no route for it, so on that URL it renders an empty router view
     * and leaves the mount as the only content in the column.
     */
    protected function injectMounts(string $html): string
    {
        $mounts = PHP_EOL.'<div id="'.self::ROOT_ID.'"></div>';

        if ($this->reservedPageEnabled()) {
            $mounts .= PHP_EOL.'<div id="'.self::PAGE_ID.'"></div>';
        }

        return $this->patch($html, self::ROUTER_VIEW_ANCHOR, $mounts, 'the output panel will not be shown');
    }

    /**
     * Add a sidebar link for the reserved jobs page.
     *
     * This has to be a plain anchor rather than a router-link. The nav is inside
     * #horizon, so Vue compiles whatever is put there, and a router-link
     * pointing at a route the compiled bundle does not know about would resolve
     * to nothing. A real href navigates properly and Vue leaves it alone.
     */
    protected function injectNavItem(string $html): string
    {
        $missing = 'the Reserved Jobs link will be missing';

        $start = strpos($html, self::NAV_ANCHOR);

        if ($start === false) {
            $this->warn(self::NAV_ANCHOR, $missing);

            return $html;
        }

        // The item goes last in the list, so the anchor to splice at is the
        // closing tag of the nav the opening tag just located.
        return $this->patch($html, self::NAV_CLOSE, $this->navItem().PHP_EOL, $missing, before: true, from: $start);
    }

    /**
     * Build the sidebar link, mirroring the markup of Horizon's own items.
     */
    protected function navItem(): string
    {
        $href = e($this->pageUrl());

        return <<<HTML
        <li class="nav-item">
            <a href="{$href}" class="nav-link d-flex align-items-center" data-hjo-nav>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <path d="M11.983 1.907a.75.75 0 00-1.292-.657l-8.5 9.5A.75.75 0 002.75 12h6.572l-1.305 6.093a.75.75 0 001.292.657l8.5-9.5A.75.75 0 0017.25 8h-6.572l1.305-6.093z" />
                </svg>
                <span>Reserved Jobs</span>
            </a>
        </li>
        HTML;
    }

    /**
     * Work out the dashboard-relative URL of the page.
     *
     * Mirrors how Horizon's bundle builds its own base path, so the link keeps
     * working behind a reverse proxy or on a customised dashboard path.
     */
    protected function pageUrl(): string
    {
        $proxy = rtrim((string) $this->config->get('horizon.proxy_path', ''), '/');
        $path = trim((string) $this->config->get('horizon.path', 'horizon'), '/');

        $base = $path === '' ? $proxy : $proxy.'/'.$path;

        return $base.self::PAGE_PATH;
    }

    /**
     * Add the stylesheet and script just before the closing body tag, which
     * places them outside #horizon so Vue never tries to compile them.
     */
    protected function injectAssets(string $html): string
    {
        return $this->patch(
            $html,
            self::BODY_ANCHOR,
            $this->assets().PHP_EOL,
            'nothing this package adds will load',
            before: true
        );
    }

    /**
     * Build the inline style and script tags.
     */
    protected function assets(): string
    {
        $terminal = $this->config->get('horizon-job-output.renderer', 'terminal') === 'terminal'
            ? $this->terminal()
            : ['css' => '', 'js' => ''];

        $settings = [
            'rootId' => self::ROOT_ID,
            'pageId' => self::PAGE_ID,
            'pagePath' => self::PAGE_PATH,
            'pollInterval' => (int) $this->config->get('horizon-job-output.poll_interval_ms', 2000),
            'ansi' => (bool) $this->config->get('horizon-job-output.ansi', true),
            'columns' => (int) $this->config->get('horizon-job-output.columns', 80),
        ];

        // The shared groundwork has to come first: both feature scripts read it,
        // and it is what wraps the history methods navigation is observed through.
        $scripts = $this->read('js/support.js').$this->read('js/job-output.js');

        if ($this->reservedPageEnabled()) {
            $scripts .= $this->read('js/reserved-jobs.js');
        }

        $css = $this->read('css/job-output.css');

        // Js::from applies the escaping needed to embed data inside a script
        // tag, which is the same helper Horizon uses for its own settings.
        $settings = Js::from($settings)->toHtml();

        return <<<HTML
        <style>{$terminal['css']}</style>
        <style>{$css}</style>
        <script type="module">
        window.HorizonJobOutput = {$settings};
        {$terminal['js']}
        {$scripts}
        </script>
        HTML;
    }

    /**
     * Read one of the package's inlined assets, or nothing at all if it has gone
     * missing — a dashboard without the panel beats a dashboard that 500s.
     */
    protected function read(string $path): string
    {
        return @file_get_contents(__DIR__.'/../resources/'.$path) ?: '';
    }

    /**
     * Load the vendored xterm build for inlining.
     *
     * The ESM build ends in an export statement, which is inert inside an inline
     * module, so the export is rewritten into a global assignment the panel can
     * pick up. If that shape ever changes the terminal is simply left out and
     * the panel falls back to rendering the output as HTML.
     */
    protected function terminal(): array
    {
        $js = @file_get_contents(__DIR__.'/../resources/vendor/xterm/xterm.mjs');
        $css = @file_get_contents(__DIR__.'/../resources/vendor/xterm/xterm.css');

        if ($js === false || $css === false) {
            return ['css' => '', 'js' => ''];
        }

        // The export is the last statement in the bundle, so only the tail is
        // searched. Running the pattern over the whole 345KB build would repeat
        // that scan on every dashboard request for no added certainty.
        $tail = substr($js, -self::TAIL_BYTES);

        if (! preg_match(self::EXPORT_PATTERN, $tail, $matches, PREG_OFFSET_CAPTURE)) {
            Log::warning(
                '[horizon-job-output] Could not rewrite the xterm export, so the terminal renderer '.
                'was skipped. The output panel will render as plain HTML instead.'
            );

            return ['css' => '', 'js' => ''];
        }

        [$statement, $offsetInTail] = $matches[0];

        $js = substr_replace(
            $js,
            'globalThis.HorizonJobOutputTerminal = '.$matches[1][0].';',
            strlen($js) - strlen($tail) + $offsetInTail,
            strlen($statement)
        );

        return ['css' => $css, 'js' => $js];
    }

    /**
     * Report an anchor Horizon no longer has, and what that costs.
     */
    protected function warn(string $anchor, string $missing): void
    {
        Log::warning(
            "[horizon-job-output] Could not find the '{$anchor}' anchor in Horizon's layout, so {$missing}. ".
            'This usually means Horizon changed its markup.'
        );
    }
}
