<?php

namespace Knobik\HorizonJobOutput;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * Injects the output panel into Horizon's rendered dashboard layout.
 *
 * Horizon inlines a compiled Vue bundle and exposes no extension point, so the
 * panel is added by patching two anchors in the rendered HTML. Both patches are
 * optional: if Horizon changes its markup the dashboard still renders, just
 * without the panel.
 */
class LayoutDecorator
{
    /**
     * The element the panel is mounted into.
     */
    public const ROOT_ID = 'hjo-root';

    protected const ROUTER_VIEW_ANCHOR = '<router-view></router-view>';

    protected const BODY_ANCHOR = '</body>';

    /**
     * How much of the xterm build to search for its trailing export statement.
     */
    protected const TAIL_BYTES = 512;

    protected const EXPORT_PATTERN = '/export\s*\{\s*(\w+)\s+as\s+Terminal\s*\}\s*;?/';

    public function __construct(protected Config $config) {}

    public function decorate(string $html): string
    {
        return $this->injectAssets($this->injectRoot($html));
    }

    /**
     * Add the mount point immediately after Horizon's router view.
     *
     * This lands inside #horizon, which Vue uses as its in-DOM template, so the
     * element becomes a static node in the compiled template. Vue renders it
     * once and never patches it, which is far safer than inserting into
     * Vue-owned DOM after mount.
     */
    protected function injectRoot(string $html): string
    {
        if (! str_contains($html, self::ROUTER_VIEW_ANCHOR)) {
            $this->warn('router-view');

            return $html;
        }

        return Str::replaceFirst(
            self::ROUTER_VIEW_ANCHOR,
            self::ROUTER_VIEW_ANCHOR.PHP_EOL.'<div id="'.self::ROOT_ID.'"></div>',
            $html
        );
    }

    /**
     * Add the stylesheet and script just before the closing body tag, which
     * places them outside #horizon so Vue never tries to compile them.
     */
    protected function injectAssets(string $html): string
    {
        if (! str_contains($html, self::BODY_ANCHOR)) {
            $this->warn('body');

            return $html;
        }

        return Str::replaceFirst(self::BODY_ANCHOR, $this->assets().PHP_EOL.self::BODY_ANCHOR, $html);
    }

    /**
     * Build the inline style and script tags.
     */
    protected function assets(): string
    {
        $terminal = $this->config->get('horizon-job-output.renderer', 'terminal') === 'terminal'
            ? $this->terminal()
            : ['css' => '', 'js' => ''];

        // Js::from applies the escaping needed to embed data inside a script
        // tag, which is the same helper Horizon uses for its own settings.
        $settings = Js::from([
            'rootId' => self::ROOT_ID,
            'pollInterval' => (int) $this->config->get('horizon-job-output.poll_interval_ms', 2000),
            'ansi' => (bool) $this->config->get('horizon-job-output.ansi', true),
            'columns' => (int) $this->config->get('horizon-job-output.columns', 80),
        ])->toHtml();

        $css = @file_get_contents(__DIR__.'/../resources/css/job-output.css') ?: '';
        $js = @file_get_contents(__DIR__.'/../resources/js/job-output.js') ?: '';

        return <<<HTML
        <style>{$terminal['css']}</style>
        <style>{$css}</style>
        <script type="module">
        window.HorizonJobOutput = {$settings};
        {$terminal['js']}
        {$js}
        </script>
        HTML;
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

    protected function warn(string $anchor): void
    {
        Log::warning(
            "[horizon-job-output] Could not find the '{$anchor}' anchor in Horizon's layout. ".
            'The job output panel will not be shown. This usually means Horizon changed its markup.'
        );
    }
}
