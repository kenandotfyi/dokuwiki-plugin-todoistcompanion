function addBtnActionTodoistcompanion($btn, props, edid) {
    $btn.on('click', function (event) {
        event.preventDefault();
        const title = window.prompt('Task to add to Todoist:');
        if (!title || !title.trim()) return false;
        const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        const bytes = new Uint8Array(8);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) bytes[i] = Math.random() * 256;
        }
        const taskId = Array.from(bytes, byte => alphabet[byte % alphabet.length]).join('');
        const safeTitle = title.trim().replace(/[{}]/g, '');
        insertAtCarret(edid, fixtxt('{{todoistcompanion>' + safeTitle + '|id=' + taskId + '}}'));
        return false;
    });
    return true;
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
        $status.removeClass('is-error').text(synced ? '' : 'Waiting for Todoist sync');
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
