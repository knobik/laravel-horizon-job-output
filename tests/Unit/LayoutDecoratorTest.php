<?php

namespace Knobik\HorizonJobOutput\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Knobik\HorizonJobOutput\LayoutDecorator;
use Knobik\HorizonJobOutput\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LayoutDecoratorTest extends TestCase
{
    /**
     * The parts of Horizon's layout the decorator patches: the sidebar nav and
     * the router view, wrapped in the #horizon element Vue templates from.
     */
    protected function layout(string $body = '<router-view></router-view>'): string
    {
        return '<html><head></head><body><div id="horizon">'.self::NAV.$body.'</div></body></html>';
    }

    protected const NAV = '<ul class="nav flex-column"><li class="nav-item"><a href="/horizon/failed">Failed Jobs</a></li></ul>';

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
            '<html><head></head><body><div id="horizon">'.self::NAV.'no anchor here</div></body></html>'
        );

        $this->assertStringNotContainsString(LayoutDecorator::ROOT_ID.'"></div>', $html);
        $this->assertStringContainsString('no anchor here', $html);
    }

    #[Test]
    public function it_skips_the_assets_when_the_body_anchor_is_gone(): void
    {
        Log::shouldReceive('warning')->once();

        $html = $this->decorator()->decorate('<div id="horizon">'.self::NAV.'<router-view></router-view></div>');

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

    /* ------------------------------------------------ reserved jobs page */

    #[Test]
    public function it_mounts_the_reserved_jobs_page_beside_the_output_panel(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        // Both land inside #horizon, where Vue treats them as static nodes of
        // its in-DOM template and leaves them alone.
        $this->assertMatchesRegularExpression(
            '/<div id="'.LayoutDecorator::ROOT_ID.'"><\/div>\s*<div id="'.LayoutDecorator::PAGE_ID.'"><\/div>/',
            $html
        );
    }

    #[Test]
    public function it_adds_a_sidebar_link_for_the_reserved_jobs_page(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('<span>Reserved Jobs</span>', $html);

        // Appended to the nav rather than replacing anything in it.
        $this->assertStringContainsString('Failed Jobs', $html);
        $this->assertLessThan(
            strpos($html, '<span>Reserved Jobs</span>'),
            strpos($html, 'Failed Jobs'),
        );
    }

    /**
     * The nav sits inside Vue's in-DOM template, so a router-link would be
     * compiled and resolve against a router that has never heard of this page.
     */
    #[Test]
    public function the_sidebar_link_is_a_plain_anchor_rather_than_a_router_link(): void
    {
        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringContainsString('<a href="/horizon/reserved"', $html);
        $this->assertStringNotContainsString('<router-link', $html);
    }

    #[Test]
    public function the_sidebar_link_follows_a_customised_dashboard_path(): void
    {
        config(['horizon.path' => 'ops/queues']);

        $this->assertStringContainsString(
            '<a href="/ops/queues/reserved"',
            $this->decorator()->decorate($this->layout())
        );
    }

    #[Test]
    public function it_leaves_the_navigation_alone_when_the_page_is_disabled(): void
    {
        config(['horizon-job-output.reserved_page' => false]);

        $html = $this->decorator()->decorate($this->layout());

        $this->assertStringNotContainsString('<span>Reserved Jobs</span>', $html);
        $this->assertStringNotContainsString('data-hjo-nav', $html);
        $this->assertStringNotContainsString('<div id="'.LayoutDecorator::PAGE_ID.'"></div>', $html);

        // The script that renders the page goes too, matched on a string only it
        // carries. The stylesheet is inlined either way — it is one file for both
        // features, and rules for a page that is not there cost nothing.
        $this->assertStringNotContainsString('No jobs are being worked on right now.', $html);

        // The output panel is a separate feature and carries on regardless.
        $this->assertStringContainsString('<div id="'.LayoutDecorator::ROOT_ID.'"></div>', $html);
    }

    /**
     * The endpoint enforces this too, but the page has no business offering a
     * button the server is only going to refuse.
     */
    #[Test]
    public function it_tells_the_page_whether_reservations_may_be_released(): void
    {
        $settings = $this->settings($this->decorator()->decorate($this->layout()));

        $this->assertTrue($settings['canRelease']);

        config(['horizon-job-output.release_reservations' => false]);

        $settings = $this->settings($this->decorator()->decorate($this->layout()));

        $this->assertFalse($settings['canRelease']);

        // Only the button goes; the listing it belongs to is untouched.
        $this->assertStringContainsString(
            '<div id="'.LayoutDecorator::PAGE_ID.'"></div>',
            $this->decorator()->decorate($this->layout())
        );
    }

    #[Test]
    public function it_keeps_the_dashboard_working_when_the_sidebar_markup_changes(): void
    {
        Log::shouldReceive('warning')->once();

        $html = $this->decorator()->decorate(
            '<html><head></head><body><div id="horizon"><router-view></router-view></div></body></html>'
        );

        $this->assertStringNotContainsString('<span>Reserved Jobs</span>', $html);

        // Everything else still renders, including the page mount, so the page
        // stays reachable by URL even with no link pointing at it.
        $this->assertStringContainsString(LayoutDecorator::ROOT_ID, $html);
        $this->assertStringContainsString('<div id="'.LayoutDecorator::PAGE_ID.'"></div>', $html);
    }
}
