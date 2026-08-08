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
    const CAN_RELEASE = !!support.settings.canRelease;

    /**
     * How long a release result stays in the card header.
     */
    const FLASH_MS = 6000;

    let timer = null;
    let active = false;

    // Set while the confirmation dialog is up. The dialog reports on a specific
    // row, so the table it was opened from is frozen underneath it rather than
    // being allowed to re-render and move on.
    let dialog = null;

    let flash = null;

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

    /**
     * The release button, carrying everything the endpoint and the confirmation
     * dialog need. The row is rebuilt from scratch on every poll, so nothing can
     * be kept in a closure — the data rides on the element instead.
     */
    function releaseButton(job) {
        const escape = support.escapeHtml;

        return `
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-hjo-release
                    data-id="${escape(job.id)}"
                    data-connection="${escape(job.connection || '')}"
                    data-queue="${escape(job.queue || '')}"
                    data-name="${escape(baseName(job.name))}"
                    data-attempts="${escape(job.attempts || 0)}"
                    data-expired="${job.expired ? '1' : ''}"
                    title="Put this job back onto its queue">Release</button>
        `;
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
                ${CAN_RELEASE ? `<td class="table-fit text-end">${releaseButton(job)}</td>` : ''}
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
                        ${CAN_RELEASE ? '<th class="table-fit"></th>' : ''}
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

    /**
     * The outcome of the last release, shown beside the count until it ages out.
     * A release usually empties the row it came from, so the header is the only
     * place on the card guaranteed to still be there to say what happened.
     */
    function flashMessage() {
        if (!flash) {
            return '';
        }

        if (Date.now() - flash.at > FLASH_MS) {
            flash = null;

            return '';
        }

        const classes = flash.danger ? 'text-danger' : 'text-muted';

        return `<small class="me-3 ${classes}">${support.escapeHtml(flash.text)}</small>`;
    }

    function announce(text, danger) {
        flash = { text: text, danger: !!danger, at: Date.now() };
    }

    function render(data) {
        const element = page();

        if (!element) {
            return;
        }

        const jobs = (data && data.jobs) || [];

        // Read once, whichever arm renders: flashMessage() is what ages the
        // message out, so calling it from only one of them would leave the
        // expiry depending on how many jobs happened to come back.
        const status = flashMessage();

        element.innerHTML = jobs.length
            ? shell(status + meta(jobs), table(jobs))
            : shell(status, notice('No jobs are being worked on right now.'));
    }

    /* --------------------------------------------------------------- releasing */

    function closeDialog() {
        if (!dialog) {
            return;
        }

        document.removeEventListener('keydown', onDialogKeydown);
        dialog.remove();
        dialog = null;
    }

    function onDialogKeydown(event) {
        if (event.key === 'Escape') {
            closeDialog();
        }
    }

    /**
     * What releasing this particular reservation would mean.
     *
     * The two cases are genuinely different actions. An expired reservation is
     * work nobody is doing, and releasing it only skips the wait. A live one may
     * well be in a worker's hands right now, and Redis has no way to tell that
     * worker to stop — so the job is queued a second time and both copies run.
     * That is worth saying in as many words before it happens.
     */
    function consequence(job) {
        return job.expired
            ? `<p class="mb-0 text-muted">
                   The worker holding this job is gone, so nothing is running it. Releasing puts it back
                   onto the queue now instead of waiting for a worker to return to that queue and migrate
                   it — which never happens at all if none ever does.
               </p>`
            : `<p class="mb-0 text-danger">
                   This reservation has not expired, so a worker may still be running the job. Releasing
                   it does not stop that worker: it puts a <strong>second copy</strong> onto the queue,
                   and both will run to completion.
               </p>`;
    }

    /**
     * The one thing about releasing that surprises people.
     *
     * The payload goes back exactly as it was reserved, attempt count included,
     * and the worker that picks it up increments that count as it always does.
     * A job on its last attempt therefore comes back only to be failed. This is
     * not something the release does differently — it is what happens to any
     * reservation Laravel recovers — but the click is what triggers it, so the
     * dialog says so rather than letting a "failed" appear out of nowhere.
     */
    function attemptsNote(job) {
        const attempt = job.attempts > 1 ? ` This is already attempt ${job.attempts}.` : '';

        return `
            <p class="mb-0 mt-3">
                <small class="text-muted">
                    The job goes back with its attempt count carried over, so the worker that picks it up
                    counts one more. If that exhausts the job's tries it will be marked failed rather than
                    run again.${attempt}
                </small>
            </p>
        `;
    }

    /**
     * Ask before releasing.
     *
     * The dialog is appended to the body rather than into the page mount, which
     * is wiped and rewritten on every poll. Its markup is Horizon's own Bootstrap
     * classes so that it follows the dashboard's light and dark schemes; only the
     * backdrop needs styling of this package's own.
     */
    function confirmRelease(job) {
        closeDialog();

        const escape = support.escapeHtml;

        dialog = document.createElement('div');
        dialog.className = 'hjo-modal';
        dialog.innerHTML = `
            <div class="hjo-modal-dialog card" role="alertdialog" aria-modal="true" aria-label="Release reservation">
                <div class="card-header">
                    <h5 class="mb-0">Release this reservation?</h5>
                </div>
                <div class="card-body">
                    <p>
                        <strong>${escape(job.name)}</strong>
                        <br>
                        <small class="text-muted">${escape(job.connection)} / ${escape(job.queue)}</small>
                    </p>
                    ${consequence(job)}
                    ${attemptsNote(job)}
                </div>
                <div class="card-body border-top text-end">
                    <button type="button" class="btn btn-sm btn-secondary" data-hjo-cancel>Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger ms-2" data-hjo-confirm>Release</button>
                </div>
            </div>
        `;

        // Clicking the backdrop is a cancel; clicking the dialog itself is not.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        const cancel = dialog.querySelector('[data-hjo-cancel]');
        const confirm = dialog.querySelector('[data-hjo-confirm]');

        cancel.addEventListener('click', closeDialog);
        confirm.addEventListener('click', () => release(job, confirm));

        document.addEventListener('keydown', onDialogKeydown);
        document.body.appendChild(dialog);

        // Focus goes to Cancel, not Release. The dialog opens on a click, and a
        // stray Enter straight afterwards should not be what queues a second
        // copy of a running job — releasing has to be asked for deliberately.
        cancel.focus();
    }

    function release(job, button) {
        button.disabled = true;
        button.textContent = 'Releasing...';

        support
            .postJson('/api/reserved-jobs/release', {
                connection: job.connection,
                queue: job.queue,
                id: job.id,
            })
            .then((response) => {
                closeDialog();

                if (!response.ok) {
                    announce(
                        response.status === 404
                            ? 'Releasing reservations is turned off.'
                            : 'Could not release that reservation.',
                        true
                    );
                } else if (response.data && response.data.released) {
                    announce(`Released ${job.name} back onto ${job.queue}.`);
                } else {
                    // The page can be a poll interval out of date, so a job that
                    // finished, or that someone else released, between the render
                    // and the click is an ordinary outcome rather than a failure.
                    announce('That job was no longer reserved, so nothing was released.');
                }

                tick();
            });
    }

    /* ----------------------------------------------------------------- polling */

    function tick() {
        // Nothing on this page is worth fetching for a tab nobody is looking at,
        // and the endpoint fans out across every queue on every call.
        //
        // The dialog is named a job on a specific row, so the listing behind it
        // is held still until that question has been answered: re-rendering
        // would slide the rows around under an open dialog and, worse, leave it
        // describing a job that is no longer on screen.
        if (!active || document.hidden || dialog) {
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

        // A dialog asks about one reserved job on one page; neither outlives the
        // page it was opened from.
        closeDialog();
        flash = null;

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

    // Delegated, and bound once: every poll replaces the table wholesale, so a
    // handler attached to a button would be thrown away seconds after it was
    // added. The page mount would do as the delegate, but the document is bound
    // before it exists and is never rewritten.
    if (CAN_RELEASE) {
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest && event.target.closest('[data-hjo-release]');

            if (!trigger || !onPage()) {
                return;
            }

            event.preventDefault();

            confirmRelease({
                id: trigger.getAttribute('data-id'),
                connection: trigger.getAttribute('data-connection'),
                queue: trigger.getAttribute('data-queue'),
                name: trigger.getAttribute('data-name'),
                attempts: parseInt(trigger.getAttribute('data-attempts'), 10) || 0,
                expired: !!trigger.getAttribute('data-expired'),
            });
        });
    }

    support.onNavigation(sync);
})();
