# TodoistCompanion

Add tasks to a DokuWiki page and sync them to a Todoist project named **Wiki**.
The page is where tasks are created and completed; Todoist stores each task's
optional due date and time.

## Setup

1. Enable **TodoistCompanion** in DokuWiki's Extension Manager.
2. Locally, open **Admin → TodoistCompanion** and enter a personal Todoist API token. In production with Docker Compose, configure `TODOIST_API_TOKEN` in the deployment environment and expose it to the DokuWiki service as a Compose secret named `todoist_api_token`.
3. Set the destination project name if you want a name other than `Wiki`; use **Test connection** to check the saved or newly entered token.
4. Ensure the DokuWiki PHP server can make outbound HTTPS requests to
   `api.todoist.com`.

The admin page stores a local-development token in DokuWiki's plugin configuration, never displays its value, and lets an administrator replace or remove it. In production, the plugin reads `/run/secrets/todoist_api_token` first, then checks `TODOIST_API_TOKEN`, then falls back to the saved DokuWiki token. The secret-file route works with PHP-FPM configurations that clear process environment variables. The admin page reports when a deployment secret or environment variable controls the token and provides a connection test. The plugin finds an existing project with the configured name or creates it
when it first syncs a task. Keep the token private: it is used only by the
server and is never sent to the browser.

## Add and complete tasks

In the page editor, use the TodoistCompanion toolbar button to open the task
dialog, enter the task text, and optionally choose a due date and time. It
inserts a page-local task marker with an 8-character ID, such as:

```text
{{todoistcompanion>add a reference to the capacitor datasheet|id=8f2k9m1d}}
```

When a due date is selected, the marker also stores the local date, time, and
time zone, for example `|due=2026-10-20T09:30|tz=Europe/Berlin`. After the
page is saved, TodoistCompanion creates or updates the Todoist task's due
date and time.

After the page is saved, new markers are sent to the configured Todoist
project. Saving the page again does not create another Todoist task. The task
includes a link back to its wiki page. Complete it using the checkbox next to
the task in the rendered page; the plugin closes it in Todoist as well. Task
status is fetched from DokuWiki's persistent metadata on each page view, so it
stays correct even when the rendered page is cached. Changes made directly in
Todoist are not imported.

Task-to-Todoist ID mappings and completion state are stored persistently under
`data/meta/todoistcompanion`, separate from DokuWiki's rebuildable cache.

Use `~~TODOS~~` on a page to show the current tasks found across readable wiki
pages. The TodoistCompanion admin page also lists all current task markers. Both
views read current page content rather than relying on a manual scan.
