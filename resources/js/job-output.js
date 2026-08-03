/**
 * Live job output panel for the Horizon dashboard.
 *
 * Horizon's dashboard is a compiled Vue bundle with no extension point, so this
 * runs alongside it as plain JavaScript. It renders into #hjo-root, which the
 * server injected into Vue's in-DOM template as a static node, and polls the
 * existing job detail API for the `output` field the package adds to the
 * repository whitelist.
 */
(function () {
    const support = window.HorizonJobOutputSupport;
    const settings = support.settings;
    const escapeHtml = support.escapeHtml;
    const ROOT_ID = settings.rootId || 'hjo-root';
    const POLL_INTERVAL = support.pollInterval;
    const TERMINAL_STATUSES = ['completed', 'failed'];

    const COLUMNS = settings.columns || 80;
    const MIN_ROWS = 3;
    const MAX_ROWS = 32;

    let timer = null;
    let activeJobId = null;
    let lastRendered = null;
    let terminal = null;

    /* ---------------------------------------------------------------- routing */

    /**
     * Pull the job id out of the current URL. Both the job preview and failed job
     * preview routes are real paths.
     */
    function currentJobId() {
        const path = support.dashboardPath();

        const preview = path.match(/^\/jobs\/[^/]+\/([^/]+)\/?$/);
        if (preview) {
            return preview[1];
        }

        const failed = path.match(/^\/failed\/([^/]+)\/?$/);
        if (failed) {
            return failed[1];
        }

        return null;
    }

    const FOREGROUND = {
        30: 'black', 31: 'red', 32: 'green', 33: 'yellow',
        34: 'blue', 35: 'magenta', 36: 'cyan', 37: 'white',
        90: 'bright-black', 91: 'bright-red', 92: 'bright-green', 93: 'bright-yellow',
        94: 'bright-blue', 95: 'bright-magenta', 96: 'bright-cyan', 97: 'bright-white',
    };

    const BACKGROUND = {
        40: 'black', 41: 'red', 42: 'green', 43: 'yellow',
        44: 'blue', 45: 'magenta', 46: 'cyan', 47: 'white',
    };

    /**
     * Collapse the terminal control codes a progress bar emits, so that a bar
     * which redrew itself fifty times renders as a single final line.
     */
    function normalise(text) {
        return text
            // Progress bars also report themselves to the terminal title bar
            // with OSC sequences, which carry nothing visible.
            .replace(/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, '')
            // Symfony redraws a progress bar by moving the cursor back to
            // column one rather than by emitting a carriage return.
            .replace(/\x1b\[\d*G/g, '\r')
            // Any remaining non-colour sequence (cursor moves, line erases)
            // has no meaning once the output is static text. SGR codes end in
            // 'm' and are deliberately preserved for ansiToHtml.
            .replace(/\x1b\[[0-9;?]*[A-FHJKSTfhlsu]/g, '')
            .split('\n')
            .map((line) => {
                const parts = line.split('\r');
                return parts[parts.length - 1];
            })
            .join('\n');
    }

    /**
     * Convert SGR escape sequences into spans. Everything else is escaped, so
     * job output can never inject markup into the dashboard.
     */
    function ansiToHtml(text) {
        const pattern = /\x1b\[([0-9;]*)m/g;
        let html = '';
        let cursor = 0;
        let open = 0;
        let match;

        while ((match = pattern.exec(text)) !== null) {
            html += escapeHtml(text.slice(cursor, match.index));
            cursor = match.index + match[0].length;

            const codes = match[1] === '' ? [0] : match[1].split(';').map(Number);
            const classes = [];

            codes.forEach((code) => {
                if (code === 0) {
                    while (open > 0) {
                        html += '</span>';
                        open--;
                    }
                } else if (code === 1) {
                    classes.push('hjo-bold');
                } else if (code === 4) {
                    classes.push('hjo-underline');
                } else if (FOREGROUND[code]) {
                    classes.push('hjo-fg-' + FOREGROUND[code]);
                } else if (BACKGROUND[code]) {
                    classes.push('hjo-bg-' + BACKGROUND[code]);
                } else if (code === 39 || code === 49) {
                    while (open > 0) {
                        html += '</span>';
                        open--;
                    }
                }
            });

            if (classes.length) {
                html += '<span class="' + classes.join(' ') + '">';
                open++;
            }
        }

        html += escapeHtml(text.slice(cursor));

        while (open > 0) {
            html += '</span>';
            open--;
        }

        return html;
    }

    /* ---------------------------------------------------------------- rendering */

    function root() {
        return document.getElementById(ROOT_ID);
    }

    /**
     * The vendored xterm build, if it was inlined. When present the output is
     * fed to a real terminal emulator, so control sequences a normaliser can
     * only approximate — a progress bar redrawing itself in place, for one —
     * render exactly as they would in a shell.
     */
    function TerminalClass() {
        return globalThis.HorizonJobOutputTerminal || null;
    }

    /**
     * The terminal is always dark, in both page themes, so it reads as a
     * console. The background matches the code-bg colour Horizon uses for the
     * job payload card directly above it.
     */
    function terminalTheme() {
        return {
            background: '#292d3e',
            foreground: '#d7dae0',
            cursor: '#292d3e',
            cursorAccent: '#292d3e',
            selectionBackground: '#3f4661',

            black: '#3b4048',
            red: '#e06c75',
            green: '#98c379',
            yellow: '#e5c07b',
            blue: '#61afef',
            magenta: '#c678dd',
            cyan: '#56b6c2',
            white: '#d7dae0',

            brightBlack: '#6b727d',
            brightRed: '#f08d94',
            brightGreen: '#b3dd9a',
            brightYellow: '#f0d399',
            brightBlue: '#8bc6f5',
            brightMagenta: '#d79bea',
            brightCyan: '#7cccd6',
            brightWhite: '#ffffff',
        };
    }

    function shell() {
        return (
            '<div class="card overflow-hidden mt-4">' +
            '<div class="card-header d-flex align-items-center justify-content-between">' +
            '<h2 class="h6 m-0">Output</h2>' +
            '<span class="hjo-status"></span>' +
            '</div>' +
            '<div class="card-bg-secondary hjo-body"></div>' +
            '</div>'
        );
    }

    /**
     * Dispose of the terminal. Called whenever the element it was attached to
     * is about to go away.
     */
    function teardown() {
        if (terminal) {
            terminal.dispose();
            terminal = null;
        }
    }

    function hide() {
        teardown();
        lastRendered = null;

        const element = root();

        if (element) {
            element.innerHTML = '';
        }
    }

    function render(output, running) {
        const element = root();

        if (!element || lastRendered === output) {
            return;
        }

        // What the terminal has already been fed. Held only for the length of
        // this call: once the shell is rebuilt below, nothing has been written.
        let shown = lastRendered ?? '';

        lastRendered = output;

        if (!element.querySelector('.hjo-body')) {
            // Any terminal from a previous job is attached to markup that is
            // about to be replaced, so it has to go with it.
            teardown();
            element.innerHTML = shell();
            shown = '';
        }

        const body = element.querySelector('.hjo-body');
        const Terminal = TerminalClass();

        if (Terminal) {
            renderTerminal(Terminal, body, output, shown);
        } else {
            renderHtml(body, output);
        }

        element.querySelector('.hjo-status').textContent = running ? 'running…' : '';
    }

    function renderTerminal(Terminal, body, output, shown) {
        if (!terminal) {
            terminal = new Terminal({
                cols: COLUMNS,
                rows: MIN_ROWS,        // grown to fit the output as it arrives
                convertEol: true,      // jobs emit "\n", a terminal expects "\r\n"
                disableStdin: true,
                cursorBlink: false,
                cursorStyle: 'bar',
                cursorInactiveStyle: 'none',
                scrollback: 5000,
                fontSize: 13,
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                theme: terminalTheme(),
            });

            terminal.open(body);
            shown = '';
        }

        // Output only ever grows, so the terminal is fed the new bytes and keeps
        // its own state. Rewriting the whole buffer each poll would reset the
        // cursor and make an in-place progress bar stack up instead of redraw.
        //
        // write() parses asynchronously, so the terminal is only measured from
        // its callback — measuring straight after the call reads a buffer that
        // has not been filled in yet.
        if (output.startsWith(shown)) {
            terminal.write(output.slice(shown.length), fitRows);
        } else {
            terminal.reset();
            terminal.write(output, fitRows);
        }
    }

    /**
     * Grow the terminal to fit what it is showing, so a job that printed three
     * lines does not leave twenty blank ones below it. Past MAX_ROWS the
     * terminal keeps its height and scrolls instead.
     */
    function fitRows() {
        if (!terminal) {
            return;
        }

        const buffer = terminal.buffer.active;
        const used = buffer.baseY + buffer.cursorY + 1;
        const rows = Math.min(Math.max(used, MIN_ROWS), MAX_ROWS);

        if (rows !== terminal.rows) {
            terminal.resize(COLUMNS, rows);
            terminal.scrollToBottom();
        }
    }

    function renderHtml(body, output) {
        let pre = body.querySelector('.hjo-output');

        if (!pre) {
            body.innerHTML = '<pre class="hjo-output"></pre>';
            pre = body.querySelector('.hjo-output');
        }

        const pinned = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;

        pre.innerHTML = ansiToHtml(normalise(output));

        if (pinned) {
            pre.scrollTop = pre.scrollHeight;
        }
    }

    /* ------------------------------------------------------------------ polling */

    function tick() {
        const id = activeJobId;

        if (!id) {
            return;
        }

        support.getJson('/api/jobs/' + encodeURIComponent(id)).then((job) => {
            // The route may have changed while the request was in flight.
            if (!job || activeJobId !== id) {
                return;
            }

            // A job that never opted into the trait has no output field at all,
            // in which case the panel stays out of the way entirely.
            if (typeof job.output !== 'string' || job.output === '') {
                hide();
                return;
            }

            const finished = TERMINAL_STATUSES.indexOf(job.status) !== -1;

            render(job.output, !finished);

            if (finished) {
                stop();
            }
        });
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start(id) {
        stop();
        teardown();
        activeJobId = id;
        lastRendered = null;
        tick();
        timer = setInterval(tick, POLL_INTERVAL);
    }

    function sync() {
        const id = currentJobId();

        if (!id) {
            stop();
            activeJobId = null;
            hide();
            return;
        }

        if (id === activeJobId && timer) {
            return;
        }

        support.whenElementExists(ROOT_ID, () => start(id));
    }

    support.onNavigation(sync);
})();
