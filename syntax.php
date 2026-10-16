<?php

if (!defined('DOKU_INC')) die();

/** Render a Todoist-backed task embedded in a wiki page. */
class syntax_plugin_todoistcompanion extends DokuWiki_Syntax_Plugin
{
    public function getType() { return 'substition'; }
    public function getPType() { return 'normal'; }
    public function getSort() { return 170; }

    public function connectTo($mode)
    {
        if ($mode === 'base') {
            $this->Lexer->addSpecialPattern('\\{\\{todoistcompanion>[^}]*\\}\\}', $mode, 'plugin_todoistcompanion');
            $this->Lexer->addSpecialPattern('~~TODOS~~', $mode, 'plugin_todoistcompanion');
        }
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        if ($match === '~~TODOS~~') return ['type' => 'list'];
        preg_match('/^\\{\\{todoistcompanion>(.*?)\\}\\}$/s', $match, $matched);
        $body = $matched[1] ?? '';
        if (!preg_match('/^(.*?)\\|id=((?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-z0-9]{8}))$/is', trim($body), $parts)) {
            return ['type' => 'task', 'key' => '', 'title' => trim($body)];
        }
        return ['type' => 'task', 'key' => strtolower($parts[2]), 'title' => trim($parts[1])];
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format === 'metadata' && ($data['type'] ?? '') === 'list') {
            $renderer->meta['todoistcompanion_todos'] = true;
            unset($renderer->persistent['todoistcompanion_todos']);
            return true;
        }
        if ($format !== 'xhtml') return false;
        if (($data['type'] ?? '') === 'list') return $this->renderTaskList($renderer);
        global $ID;
        $key = (string)($data['key'] ?? '');
        $title = (string)($data['title'] ?? '');
        if ($key === '' || $title === '') {
            $renderer->doc .= '<span class="todoistcompanion-task-error">' . hsc($title ?: 'Invalid TodoistCompanion task syntax') . '</span>';
            return true;
        }

        $renderer->doc .= '<span class="todoistcompanion-task" data-todoistcompanion-task="' . hsc($key) . '" data-page="' . hsc($ID) . '">';
        $renderer->doc .= '<button type="button" class="todoistcompanion-task-toggle" aria-checked="false" aria-busy="true" role="checkbox" disabled aria-label="Loading task status"></button>';
        $renderer->doc .= '<span class="todoistcompanion-task-title">' . hsc($title) . '</span>';
        $renderer->doc .= '<span class="todoistcompanion-task-status" aria-live="polite">Loading task status…</span></span>';
        return true;
    }

    private function renderTaskList(Doku_Renderer $renderer)
    {
        $action = plugin_load('action', 'todoistcompanion');
        $tasks = $action && method_exists($action, 'getAllWikiTasks') ? $action->getAllWikiTasks() : [];
        $renderer->doc .= '<section class="todoistcompanion-todos"><h3>' . hsc($this->getLang('todo_list_heading')) . '</h3><ul>';
        if (!$tasks) {
            $renderer->doc .= '<li class="todoistcompanion-todos-empty">' . hsc($this->getLang('no_tasks')) . '</li>';
        } else {
            foreach ($tasks as $task) {
                $key = (string)$task['key'];
                $page = (string)$task['page'];
                $renderer->doc .= '<li class="todoistcompanion-todos-item"><span class="todoistcompanion-task" data-todoistcompanion-task="' . hsc($key) . '" data-page="' . hsc($page) . '">';
                $renderer->doc .= '<button type="button" class="todoistcompanion-task-toggle" aria-checked="false" aria-busy="true" role="checkbox" disabled aria-label="Loading task status"></button>';
                $renderer->doc .= '<span class="todoistcompanion-task-title">' . hsc($task['title']) . '</span>';
                $renderer->doc .= '<span class="todoistcompanion-task-status" aria-live="polite">Loading task status…</span></span> ';
                $renderer->doc .= '<a class="todoistcompanion-task-page" href="' . hsc(wl($page)) . '">' . hsc($page) . '</a></li>';
            }
        }
        $renderer->doc .= '</ul></section>';
        return true;
    }
}
