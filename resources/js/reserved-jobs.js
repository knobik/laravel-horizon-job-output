/**
 * Reserved jobs page for the Horizon dashboard.
 *
 * Horizon's router is compiled into its bundle and cannot be extended from a
 * package, so this renders itself. Navigating to the page loads the dashboard
 * layout as normal, Horizon's router matches nothing and leaves its router view
 * empty, and this fills the #hjo-page mount the server injected beside it.
 */
(function () {
    const support = window.HorizonJobOutputSupport;
    const PAGE_ID = support.settings.pageId;
    const PAGE_PATH = support.settings.pagePath;

    let timer = null;
    let active = false;

    function onPage() {
        return support.dashboardPath() === PAGE_PATH;
    }

    function page() {
        return document.getElementById(PAGE_ID);
    }

    /* -------------------------------------------------------------- formatting */

    /**
     * Horizon shows job names fully qualified, but the class name is what anyone
     * is scanning the column for.
     */
    function baseName(name) {
        if (!name) {
            return 'Unknown Job';
        }

        const parts = name.split('\\');

        return parts[parts.length - 1] || name;
    }

    function duration(seconds) {
        if (seconds === null || seconds === undefined) {
            return '-';
        }

        const value = Math.max(0, seconds);

        if (value < 60) {
            return value.toFixed(1) + 's';
        }

        if (value < 3600) {
            return Math.floor(value / 60) + 'm ' + Math.round(value % 60) + 's';
        }

        return Math.floor(value / 3600) + 'h ' + Math.round((value % 3600) / 60) + 'm';
    }

    /* --------------------------------------------------------------- rendering */

    function shell(meta, body) {
        return `
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">Reserved Jobs</h5>
                    ${meta}
                </div>
                ${body}
            </div>
        `;
    }

    function notice(text) {
        return `
            <div class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <span>${text}</span>
            </div>
        `;
    }

    function badges(job) {
        let html = '';

        if (job.expired) {
            html +=
                '<small class="ms-1 badge bg-danger badge-sm" title="The worker holding this job is gone. Horizon will release it back onto the queue.">Reservation expired</small>';
        }

        if (job.has_output) {
            html += '<small class="ms-1 badge bg-secondary badge-sm">Output</small>';
        }

        if (job.attempts > 1) {
            html += `<small class="ms-1 badge bg-secondary badge-sm">Attempt ${job.attempts}</small>`;
        }

        return html;
    }

    function row(job) {
        const escape = support.escapeHtml;
        const tags = (job.tags || []).slice(0, 3).join(', ');

        // Horizon's job detail route takes any job id regardless of the type
        // segment, so the pending listing is a safe parent for the link.
        const href = support.basePath() + '/jobs/pending/' + encodeURIComponent(job.id);

        return `
            <tr>
                <td>
                    <a href="${escape(href)}" title="${escape(job.name || '')}">${escape(baseName(job.name))}</a>
                    ${badges(job)}
                    <br>
                    <small class="text-muted">
                        Queue: ${escape(job.queue || '-')}${tags ? ' | Tags: ' + escape(tags) : ''}
                    </small>
                </td>
                <td class="table-fit text-muted">${escape(job.connection || '-')}</td>
                <td class="table-fit text-muted text-end">${duration(job.running_for)}</td>
                <td class="table-fit text-end ${job.expired ? 'text-danger' : 'text-muted'}">
                    ${job.expired ? 'expired' : duration(job.expires_in)}
                </td>
            </tr>
        `;
    }

    function table(jobs) {
        return `
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th class="table-fit">Connection</th>
                        <th class="table-fit text-end">Running For</th>
                        <th class="table-fit text-end">Reservation Expires</th>
                    </tr>
                </thead>
                <tbody>${jobs.map(row).join('')}</tbody>
            </table>
        `;
    }

    function meta(jobs) {
        const expired = jobs.filter((job) => job.expired).length;
        const text = expired ? `${jobs.length} reserved, ${expired} expired` : `${jobs.length} reserved`;

        return `<small class="${expired ? 'text-danger' : 'text-muted'}">${text}</small>`;
    }

    function render(data) {
        const element = page();

        if (!element) {
            return;
        }

        const jobs = (data && data.jobs) || [];

        element.innerHTML = jobs.length
            ? shell(meta(jobs), table(jobs))
            : shell('', notice('No jobs are being worked on right now.'));
    }

    /* ----------------------------------------------------------------- polling */

    function tick() {
        // Nothing on this page is worth fetching for a tab nobody is looking at,
        // and the endpoint fans out across every queue on every call.
        if (!active || document.hidden) {
            return;
        }

        support.getJson('/api/reserved-jobs').then((data) => {
            // The page may have been navigated away from mid-request.
            if (active && data) {
                render(data);
            }
        });
    }

    function stop() {
        active = false;

        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start() {
        stop();
        active = true;

        const element = page();

        if (element && !element.innerHTML) {
            element.innerHTML = shell('', notice('Loading...'));
        }

        tick();

        // Unlike the output panel there is no terminal state to stop on: the set
        // of reserved jobs is only ever a snapshot.
        timer = setInterval(tick, support.pollInterval);
    }

    /**
     * Horizon marks the active sidebar item through vue-router, which knows
     * nothing about this page, so the link is highlighted here instead.
     */
    function markNavActive() {
        document.querySelectorAll('[data-hjo-nav]').forEach((link) => {
            link.classList.toggle('active', onPage());
        });
    }

    function sync() {
        markNavActive();

        if (!onPage()) {
            stop();

            const element = page();

            if (element) {
                element.innerHTML = '';
            }

            return;
        }

        if (!active) {
            support.whenElementExists(PAGE_ID, start);
        }
    }

    // A tab brought back to the foreground should refresh rather than show
    // whatever was on screen when it was hidden.
    document.addEventListener('visibilitychange', tick);

    support.onNavigation(sync);
})();
