function addBtnActionTodoistcompanion($btn, props, edid) {
    $btn.on('click', function (event) {
        event.preventDefault();
        const dialog = getTaskDialog();
        dialog.dataset.editorId = edid;
        dialog.showModal();
        dialog.querySelector('[name="title"]').focus();
        return false;
    });
    return true;
}

function getTaskDialog() {
    let dialog = document.getElementById('todoistcompanion-add-dialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'todoistcompanion-add-dialog';
    dialog.className = 'todoistcompanion-add-dialog';
    dialog.setAttribute('aria-labelledby', 'todoistcompanion-add-title');
    dialog.innerHTML = '<form class="todoistcompanion-add-form">' +
        '<header class="todoistcompanion-add-header"><h2 id="todoistcompanion-add-title">Add a task</h2>' +
        '<button class="todoistcompanion-add-close" type="button" aria-label="Close dialog">×</button></header>' +
        '<label class="todoistcompanion-add-field">Task<input name="title" type="text" maxlength="500" autocomplete="off" required></label>' +
        '<fieldset class="todoistcompanion-due-fields"><legend>Due date and time <span>(optional)</span></legend>' +
        '<div class="todoistcompanion-due-inputs">' +
        '<label class="todoistcompanion-add-field">Date<input name="date" type="date"></label>' +
        '<label class="todoistcompanion-add-field">Time<input name="time" type="time" step="60"></label></div>' +
        '<p class="todoistcompanion-add-help">Choose both to set the task’s due date and time in Todoist.</p></fieldset>' +
        '<footer class="todoistcompanion-add-actions"><button class="todoistcompanion-add-cancel" type="button">Cancel</button>' +
        '<button class="todoistcompanion-add-submit" type="submit">Add task</button></footer></form>';
    document.body.appendChild(dialog);

    const form = dialog.querySelector('form');
    const date = form.elements.date;
    const time = form.elements.time;
    const today = localDateString(new Date());
    date.min = today;
    const updateTimeMinimum = function () {
        time.min = date.value === today ? localTimeString(new Date()) : '';
    };
    date.addEventListener('change', updateTimeMinimum);
    time.addEventListener('focus', updateTimeMinimum);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        date.setCustomValidity('');
        time.setCustomValidity('');
        if (!!date.value !== !!time.value) {
            const message = 'Choose both a due date and time, or leave both empty.';
            date.setCustomValidity(message);
            time.setCustomValidity(message);
            form.reportValidity();
            return;
        }
        if (date.value && new Date(date.value + 'T' + time.value) <= new Date()) {
            time.setCustomValidity('Choose a future date and time.');
            form.reportValidity();
            return;
        }

        const title = form.elements.title.value.trim().replace(/[{}|\r\n]+/g, ' ');
        if (!title) {
            form.elements.title.focus();
            return;
        }
        const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        const bytes = new Uint8Array(8);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) bytes[i] = Math.random() * 256;
        }
        const taskId = Array.from(bytes, byte => alphabet[byte % alphabet.length]).join('');
        let syntax = '{{todoistcompanion>' + title + '|id=' + taskId;
        if (date.value && time.value) {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
            syntax += '|due=' + date.value + 'T' + time.value + '|tz=' + timezone;
        }
        syntax += '}}';
        insertAtCarret(dialog.dataset.editorId, fixtxt(syntax));
        form.reset();
        date.min = localDateString(new Date());
        dialog.close();
    });

    dialog.querySelector('.todoistcompanion-add-close').addEventListener('click', function () {
        dialog.close();
    });
    dialog.querySelector('.todoistcompanion-add-cancel').addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () {
        form.reset();
        date.min = localDateString(new Date());
    });
    return dialog;
}

function localDateString(date) {
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function localTimeString(date) {
    return [String(date.getHours()).padStart(2, '0'), String(date.getMinutes()).padStart(2, '0')].join(':');
}

(function () {
    function ajaxUrl(action) {
        return DOKU_BASE + 'lib/exe/ajax.php?call=plugin_todoistcompanion_' + action;
    }

    function setTaskState(element, result) {
        const $task = jQuery(element);
        const $button = $task.find('.todoistcompanion-task-toggle');
        const $status = $task.find('.todoistcompanion-task-status');
        const completed = !!result.completed;
        const synced = !!result.synced;

        $task.toggleClass('is-completed', completed);
        $button.attr('aria-checked', completed ? 'true' : 'false')
            .attr('aria-busy', 'false')
            .attr('aria-label', completed ? 'Completed task' : 'Complete task')
            .prop('disabled', completed || !synced || !result.canComplete);
        const statusText = !synced
            ? 'Waiting for Todoist sync'
            : (result.duePending ? 'Task date could not sync' : '');
        $status.removeClass('is-error').text(statusText);
    }

    function loadTaskState(element) {
        if (element.getAttribute('data-state-requested') === 'true') return;
        element.setAttribute('data-state-requested', 'true');
        const $task = jQuery(element);
        const $status = $task.find('.todoistcompanion-task-status');

        jQuery.ajax({
            url: ajaxUrl('status'),
            method: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: {
                task: $task.attr('data-todoistcompanion-task'),
                page: $task.attr('data-page')
            }
        }).done(function (result) {
            if (result && result.ok) {
                setTaskState(element, result);
                return;
            }
            $task.find('.todoistcompanion-task-toggle').prop('disabled', true).attr('aria-busy', 'false');
            $status.addClass('is-error').text(result && result.error ? result.error : 'Could not load task status.');
        }).fail(function () {
            $task.find('.todoistcompanion-task-toggle').prop('disabled', true).attr('aria-busy', 'false');
            $status.addClass('is-error').text('Could not load task status. Reload to try again.');
        });
    }

    function scanTasks(root) {
        if (!root || root.nodeType !== 1 && root !== document) return;
        if (root.matches && root.matches('[data-todoistcompanion-task]')) loadTaskState(root);
        if (root.querySelectorAll) {
            root.querySelectorAll('[data-todoistcompanion-task]').forEach(loadTaskState);
        }
    }

    jQuery(function () {
        scanTasks(document);
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(scanTasks);
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });

        jQuery(document).on('click', '.todoistcompanion-task-toggle:not(:disabled)', function () {
            const $button = jQuery(this);
            const $task = $button.closest('[data-todoistcompanion-task]');
            const $status = $task.find('.todoistcompanion-task-status');
            $button.prop('disabled', true);
            $status.removeClass('is-error').text('Syncing…');
            jQuery.ajax({
                url: ajaxUrl('complete'),
                method: 'POST',
                dataType: 'json',
                timeout: 15000,
                data: {
                    task: $task.attr('data-todoistcompanion-task'),
                    page: $task.attr('data-page'),
                    sectok: (typeof JSINFO !== 'undefined' && JSINFO.todoistcompanion) ? JSINFO.todoistcompanion.token : ''
                }
            }).done(function (result) {
                if (result && result.ok) {
                    setTaskState($task[0], { ok: true, synced: true, completed: true, canComplete: false });
                    return;
                }
                $button.prop('disabled', false);
                $status.addClass('is-error').text(result && result.error ? result.error : 'Todoist sync failed.');
            }).fail(function (xhr, status) {
                $button.prop('disabled', false);
                const message = status === 'timeout'
                    ? 'Todoist did not respond in time. Try again.'
                    : (xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Could not sync with Todoist.');
                $status.addClass('is-error').text(message);
            });
        });
    });
}());
