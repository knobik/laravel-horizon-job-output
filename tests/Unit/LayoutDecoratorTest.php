<?php

namespace Knobik\HorizonJobOutput\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\LayoutDecorator;
use Knobik\HorizonJobOutput\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LayoutDecoratorTest extends TestCase
{
    protected function layout(string $body = '<router-view></router-view>'): string
    {
        return '<html><head></head><body><div id="horizon">'.$body.'</div></body></html>';
    }

    protected function decorator(): LayoutDecorator
    {
        return $this->app->make(LayoutDecorator::class);
    }

    /**
     * Pull the panel settings back out of the page. They are embedded with
     * Js::from, which emits JSON.parse('...') with the quotes hex-escaped.
     */
    protected function settings(string $html): array
    {
        $this->assertMatchesRegularExpression("/window\.HorizonJobOutput = JSON\.parse\('.*'\);/", $html);

        preg_match("/window\.HorizonJobOutput = JSON\.parse\('(.*)'\);/U", $html, $matches);

        return json_decode(json_decode('"'.$matches[1].'"'), true);
    }

    #[Test]
    public function it_mounts_the_panel_directly_after_horizons_router_view(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('<router-view></router-view>', $html);
        $this->assertMatchesRegularExpression(
            '/<router-view><\/router-view>\s*<div id="'.LayoutDecorator::ROOT_ID.'"><\/div>/',
            $html
        );
    }

    #[Test]
    public function it_injects_the_assets_before_the_closing_body_tag(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('window.HorizonJobOutput =', $html);
        $this->assertLessThan(
            strpos($html, '</body>'),
            strpos($html, 'window.HorizonJobOutput ='),
            'The script must sit outside #horizon so Vue never compiles it as a template.'
        );
    }

    #[Test]
    public function it_passes_the_configured_settings_to_the_panel(): void
    {
        config(['horizon-job-output.poll_interval_ms' => 750]);
        config(['horizon-job-output.columns' => 120]);

        $settings = $this->settings($this->decorator()->decorate($this->layout()));

        $this->assertSame(750, $settings['pollInterval']);
        $this->assertSame(120, $settings['columns']);
        $this->assertSame(LayoutDecorator::ROOT_ID, $settings['rootId']);
    }

    /**
     * Settings are embedded inside a script tag, so they go through the same
     * escaping helper Horizon uses for its own — not a raw json_encode.
     */
    #[Test]
    public function it_escapes_the_settings_for_a_script_context(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('window.HorizonJobOutput = JSON.parse(', $html);
        $this->assertStringNotContainsString('window.HorizonJobOutput = {"', $html);
    }

    /**
     * Horizon offers no extension point, so the panel is injected by patching
     * anchors in its markup. If a Horizon release changes that markup the
     * dashboard has to keep working — just without the panel.
     */
    #[Test]
    public function it_leaves_the_dashboard_intact_when_the_router_view_anchor_is_gone(): void
    {
        Log::shouldReceive('warning')->once();

        $html = $this->decorator()->decorate(
            '<html><head></head><body><div id="horizon">no anchor here</div></body></html>'
        );

        $this->assertStringNotContainsString(LayoutDecorator::ROOT_ID.'"></div>', $html);
        $this->assertStringContainsString('no anchor here', $html);
    }

    #[Test]
    public function it_skips_the_assets_when_the_body_anchor_is_gone(): void
    {
        Log::shouldReceive('warning')->once();

        $html = $this->decorator()->decorate('<div id="horizon"><router-view></router-view></div>');

        $this->assertStringContainsString(LayoutDecorator::ROOT_ID, $html);
        $this->assertStringNotContainsString('window.HorizonJobOutput =', $html);
    }

    #[Test]
    public function it_inlines_the_terminal_and_rewrites_its_module_export(): void
    {
        config(['horizon-job-output.renderer' => 'terminal']);

        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('globalThis.HorizonJobOutputTerminal =', $html);

        // An export statement inside an inline module is inert, so if one
        // survived the terminal would never be reachable.
        $this->assertStringNotContainsString('export{', $html);
        $this->assertStringContainsString('.xterm', $html);
    }

    #[Test]
    public function it_leaves_the_terminal_out_when_the_html_renderer_is_configured(): void
    {
        config(['horizon-job-output.renderer' => 'html']);

        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringNotContainsString('globalThis.HorizonJobOutputTerminal =', $html);

        // The panel itself still ships; only the terminal is omitted.
        $this->assertStringContainsString('window.HorizonJobOutput =', $html);
    }

    #[Test]
    public function the_html_renderer_is_dramatically_lighter_than_the_terminal_one(): void
    {
        config(['horizon-job-output.renderer' => 'terminal']);
        $withTerminal = strlen($this->decorator()->decorate($this->layout()));

        config(['horizon-job-output.renderer' => 'html']);
        $withoutTerminal = strlen($this->decorator()->decorate($this->layout()));

        $this->assertGreaterThan(300_000, $withTerminal - $withoutTerminal);
    }
}
