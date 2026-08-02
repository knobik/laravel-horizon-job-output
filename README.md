# Job Output for Laravel Horizon

Give a queued job the same output API an Artisan command has, and watch it live on
the Horizon job details page.

![The output panel on a Horizon job details page, showing a job's log lines and a progress bar](art/screenshot.png)

```php
use Knobik\HorizonJobOutput\Concerns\WritesJobOutput;

class RebuildSearchIndex implements ShouldQueue
{
    use Queueable;
    use WritesJobOutput;

    public function handle(): void
    {
        $this->info('Rebuilding search index');
        $this->comment('scanning 20 shards');

        $this->withProgressBar($shards, fn ($shard) => $shard->rebuild());

        $this->info('Index rebuilt');
    }
}
```

`info()`, `line()`, `comment()`, `error()`, `table()`, `newLine()` and
`withProgressBar()` all work exactly as they do in a command — the trait uses
Laravel's own `InteractsWithIO`.

The interactive prompts (`ask()`, `confirm()`, `choice()`, …) throw instead. A
queue worker has no input stream, so they would otherwise block until the job
timed out.

## Installation

```bash
composer require knobik/laravel-horizon-job-output
```

That is the whole setup. The service provider is auto-discovered, and the panel
adds itself to Horizon's dashboard.

## How it works

Output is stored as a field on **Horizon's own job hash** in Redis, rather than
under a key of its own. That means it shares one key and one TTL with the job, so
it is trimmed by Horizon's existing `horizon.trim.*` settings with no cleanup
code, no scheduled command, and no way for the two to fall out of sync.

Three integration points, none of which require changes to Horizon:

- `RedisJobRepository::$keys` is a public whitelist read with `HMGET`. The
  provider appends `output` to it, so the field flows through the existing
  `/api/jobs/{id}` endpoints with no controller or route overrides.
- A global bus pipe attaches the output instance while the job runs. Unserialized
  jobs never run their constructor, so this cannot be done at dispatch time.
- The `horizon::layout` view is overridden to inject the panel. Rather than
  shipping a copy that drifts, the override renders Horizon's real layout and
  patches two anchors in the result.

## Configuration

```bash
php artisan vendor:publish --tag=horizon-job-output-config
```

| Option | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Turn capture and the dashboard panel off entirely |
| `max_bytes` | `65536` | Truncate a runaway job's output |
| `flush_interval_ms` | `500` | How often a running job writes to Redis |
| `poll_interval_ms` | `2000` | How often the dashboard polls while a job runs |
| `ansi` | `true` | Store style tags as colour, rather than plain text |
| `renderer` | `terminal` | `terminal` or `html` — see below |
| `columns` | `80` | Terminal width; match what the job wrote at |

Setting `enabled` to `false` stops output being recorded and removes the panel,
but jobs using the trait keep working — their `$this->info()` calls simply go
nowhere. Disabling the package never changes whether your jobs run.

### Renderers

`terminal` inlines a vendored [xterm.js](https://xtermjs.org) build and renders
output through a real terminal emulator, so a progress bar redraws in place
exactly as it would in a shell. It adds roughly 345KB to each dashboard page.

`html` renders the output as styled HTML with no extra payload. Sequences that
rewrite the current line are collapsed, so a progress bar shows only its final
state. This is also the automatic fallback if the vendored build is unavailable.

## Running jobs outside Horizon

Writing output is never a reason for a job to fail. Whatever path a job takes,
the write helpers work:

| How the job runs | Output |
| --- | --- |
| Queued, processed by Horizon | captured and shown on the dashboard |
| Queued, processed by `queue:work` | captured — Horizon records the job when it is pushed, not when it runs |
| `dispatchSync()` / `dispatch_sync()` | discarded; there is no Horizon record to attach it to |
| `(new Job)->handle()` directly | discarded |
| Package disabled | discarded |

To assert on output in a test, attach one and read it back:

```php
$job = new RebuildSearchIndex();
$job->setOutput(new OutputStyle(new ArrayInput([]), $buffer = new BufferedOutput()));

$job->handle();

$this->assertStringContainsString('Index rebuilt', $buffer->fetch());
```

The interactive prompts are the one deliberate exception: `ask()`, `confirm()`
and friends throw rather than returning something meaningless, because a worker
has no input stream and they would otherwise block until the job timed out.

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- Laravel Horizon 5

## License

MIT — see [LICENSE.md](LICENSE.md).
