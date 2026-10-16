<?php

if (!defined('DOKU_INC')) die();

/** Sync explicit page tasks to Todoist and handle completion from the page. */
class action_plugin_todoistcompanion extends DokuWiki_Action_Plugin
{
    private $apiError = '';

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handlePageWrite');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'disableTodosListCache');
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'addToolbarButton');
        $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'addScriptDependency');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'injectSecurityToken');
    }

    /** DokuWiki exposes the CSRF token to PHP, so pass it into JSINFO for AJAX. */
    public function injectSecurityToken(Doku_Event $event, $param)
    {
        if (empty($event->data['script']) || !is_array($event->data['script'])) return;
        $payload = json_encode(
            ['token' => getSecurityToken()],
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) return;
        foreach ($event->data['script'] as &$scriptTag) {
            if (isset($scriptTag['_data']) && strpos($scriptTag['_data'], 'var JSINFO') !== false) {
                $scriptTag['_data'] .= 'JSINFO.todoistcompanion=' . $payload . ';';
                break;
            }
        }
        unset($scriptTag);
    }

    public function addScriptDependency(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache)) return;
        if (!isset($cache->depends) || !is_array($cache->depends)) $cache->depends = [];
        if (!isset($cache->depends['files']) || !is_array($cache->depends['files'])) $cache->depends['files'] = [];
        $cache->depends['files'][] = __DIR__ . '/script.js';
    }

    public function addToolbarButton(Doku_Event $event, $param)
    {
        $event->data[] = [
            'type' => 'todoistcompanion',
            'title' => $this->getLang('toolbar_title'),
            'icon' => '../../plugins/todoistcompanion/images/check-square-duotone.svg',
        ];
    }

    public function handlePageWrite(Doku_Event $event, $param)
    {
        if (!isset($event->data[0][1]) || !is_string($event->data[0][1])) return;

        $namespace = $event->data[1] ?? false;
        $page = $event->data[2] ?? '';
        if (!is_string($page) || $page === '') return;
        $pageId = ($namespace && $namespace !== false) ? $namespace . ':' . $page : $page;
        $this->syncPageTasks($pageId, $event->data[0][1]);
    }

    /** Keep the aggregate TODO list fresh when any page containing it is opened. */
    public function disableTodosListCache(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache) || ($cache->mode ?? '') !== 'xhtml' || empty($cache->page)) return;
        if (!p_get_metadata($cache->page, 'todoistcompanion_todos')) return;
        $event->preventDefault();
        $event->stopPropagation();
        $event->result = false;
    }

    public function handleAjax(Doku_Event $event, $param)
    {
        if (!in_array($event->data, ['plugin_todoistcompanion_complete', 'plugin_todoistcompanion_status'], true)) return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        global $INPUT;
        $taskKey = $INPUT->post->str('task');
        $pageId = cleanID($INPUT->post->str('page'));
        if (!$this->validTaskKey($taskKey) || $pageId === '') {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid page task.']);
            return;
        }

        if ($event->data === 'plugin_todoistcompanion_status') {
            if (auth_quickaclcheck($pageId) < AUTH_READ) {
                http_response_code(403);
                echo json_encode(['error' => 'You cannot view this page task.']);
                return;
            }
            $record = $this->getTaskRecord($taskKey);
            if (!is_array($record) || ($record['page'] ?? '') !== $pageId) {
                echo json_encode(['ok' => true, 'synced' => false, 'completed' => false, 'canComplete' => false]);
                return;
            }
            echo json_encode([
                'ok' => true,
                'synced' => !empty($record['todoist_id']),
                'completed' => !empty($record['completed']),
                'canComplete' => !empty($record['todoist_id']) && empty($record['completed']) && auth_quickaclcheck($pageId) >= AUTH_EDIT,
            ]);
            return;
        }

        if (!checkSecurityToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Security token check failed.']);
            return;
        }
        if (auth_quickaclcheck($pageId) < AUTH_EDIT) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot complete this page task.']);
            return;
        }

        $tasks = $this->loadTasks();
        if (!isset($tasks[$taskKey]) || ($tasks[$taskKey]['page'] ?? '') !== $pageId) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found for this page.']);
            return;
        }
        if (!empty($tasks[$taskKey]['completed'])) {
            echo json_encode(['ok' => true, 'completed' => true]);
            return;
        }

        $taskId = (string)($tasks[$taskKey]['todoist_id'] ?? '');
        if ($taskId === '' || !$this->closeTodoistTask($taskId)) {
            http_response_code(502);
            echo json_encode(['error' => $this->apiError ?: 'Todoist could not complete the task.']);
            return;
        }

        $tasks[$taskKey]['completed'] = true;
        $tasks[$taskKey]['completed_at'] = time();
        $this->saveTasks($tasks);
        echo json_encode(['ok' => true, 'completed' => true]);
    }

    private function syncPageTasks($pageId, $source)
    {
        preg_match_all('/\{\{todoistcompanion>(.*?)\}\}/s', $source, $matches, PREG_SET_ORDER);
        if (!$matches) return;

        $tasks = $this->loadTasks();
        foreach ($matches as $match) {
            $parsed = $this->parseTaskSyntax($match[1]);
            if (!$parsed) continue;
            [$key, $title] = $parsed;
            if (isset($tasks[$key]) && ($tasks[$key]['page'] ?? '') === $pageId) {
                $tasks[$key]['title'] = $title;
                $tasks[$key]['updated_at'] = time();
                if (empty($tasks[$key]['todoist_id']) && $this->apiToken() !== '') {
                    $result = $this->createTodoistTask($key, $pageId, $title);
                    if ($result) {
                        $tasks[$key]['todoist_id'] = $result['id'];
                        $tasks[$key]['status'] = 'synced';
                    }
                }
                continue;
            }
            // Avoid attaching an ID collision to an unrelated page/task.
            if (isset($tasks[$key])) continue;

            $result = $this->apiToken() !== '' ? $this->createTodoistTask($key, $pageId, $title) : null;
            $tasks[$key] = [
                'page' => $pageId,
                'title' => $title,
                'todoist_id' => $result['id'] ?? '',
                'completed' => false,
                'status' => $result ? 'synced' : 'pending',
                'updated_at' => time(),
            ];
        }
        $this->saveTasks($tasks);
    }

    private function parseTaskSyntax($body)
    {
        if (!preg_match('/^(.*?)\|id=((?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-z0-9]{8}))$/is', trim($body), $parts)) return null;
        $title = trim($parts[1]);
        $key = strtolower($parts[2]);
        if ($title === '' || !$this->validTaskKey($key)) return null;
        return [$key, $title];
    }

    private function validTaskKey($key)
    {
        return is_string($key) && (
            preg_match('/^[a-z0-9]{8}$/i', $key) === 1 ||
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $key) === 1
        );
    }

    private function createTodoistTask($key, $pageId, $title)
    {
        $projectId = $this->getOrCreateProject();
        if (!$projectId) return null;
        global $conf;
        $url = wl($pageId, [], true, '&');
        $result = $this->request('POST', '/tasks', [
            'content' => $title,
            'description' => 'Wiki page: ' . $url . "\n\nDokuWiki task key: " . $key,
            'project_id' => $projectId,
        ]);
        if (!is_array($result) || empty($result['id'])) return null;
        return ['id' => (string)$result['id']];
    }

    private function getOrCreateProject()
    {
        $state = $this->loadProject();
        if (!empty($state['id']) && ($state['name'] ?? '') === $this->projectName()) return (string)$state['id'];

        $projects = $this->request('GET', '/projects');
        if (!is_array($projects)) return null;
        foreach (($projects['results'] ?? $projects) as $project) {
            if (is_array($project) && strcasecmp((string)($project['name'] ?? ''), $this->projectName()) === 0) {
                $this->saveProject(['id' => (string)$project['id'], 'name' => $this->projectName()]);
                return (string)$project['id'];
            }
        }

        $created = $this->request('POST', '/projects', ['name' => $this->projectName()]);
        if (!is_array($created) || empty($created['id'])) return null;
        $this->saveProject(['id' => (string)$created['id'], 'name' => $this->projectName()]);
        return (string)$created['id'];
    }

    private function closeTodoistTask($taskId)
    {
        return $this->request('POST', '/tasks/' . rawurlencode($taskId) . '/close') !== null;
    }

    private function request($method, $path, $payload = null)
    {
        $token = $this->apiToken();
        if ($token === '') return null;
        $url = 'https://api.todoist.com/api/v1' . $path;
        $headers = "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n";
        $options = ['http' => [
            'method' => $method,
            'header' => $headers,
            'timeout' => 8,
            'ignore_errors' => true,
        ]];
        if ($payload !== null) {
            $options['http']['header'] .= "Content-Type: application/json\r\n";
            $options['http']['content'] = json_encode($payload);
        }
        $response = @file_get_contents($url, false, stream_context_create($options));
        if ($response === false) {
            $this->apiError = 'Could not reach Todoist. Check outbound HTTPS access from the DokuWiki server.';
            return null;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        }
        if ($status < 200 || $status >= 300) {
            $this->apiError = 'Todoist returned HTTP ' . $status . '.';
            return null;
        }
        if ($response === '') return ['ok' => true];
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function projectName()
    {
        $name = trim((string)$this->getConf('project_name'));
        return $name === '' ? 'Wiki' : $name;
    }

    private function apiToken()
    {
        $secretFile = '/run/secrets/todoist_api_token';
        if (is_readable($secretFile)) {
            $secretToken = @file_get_contents($secretFile);
            if (is_string($secretToken) && trim($secretToken) !== '') return trim($secretToken);
        }

        $environmentToken = getenv('TODOIST_API_TOKEN');
        if (is_string($environmentToken) && trim($environmentToken) !== '') return trim($environmentToken);
        return trim((string)$this->getConf('api_token'));
    }

    private function storageDirectory()
    {
        global $conf;
        return rtrim($conf['metadir'], '/\\') . '/todoistcompanion';
    }

    private function loadTasks()
    {
        $file = $this->storageDirectory() . '/tasks.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** Return the local task status used by the syntax renderer. */
    public function getTaskRecord($key)
    {
        if (!$this->validTaskKey($key)) return null;
        $tasks = $this->loadTasks();
        return $tasks[$key] ?? null;
    }

    /** Scan current page sources and attach known Todoist completion state. */
    public function getAllWikiTasks($includeRestricted = false)
    {
        global $conf;
        $pages = [];
        search($pages, $conf['datadir'], 'search_allpages', ['skipacl' => true]);
        $pageIds = array_values(array_unique(array_filter(array_column($pages, 'id'), 'is_string')));
        sort($pageIds, SORT_NATURAL | SORT_FLAG_CASE);
        $stored = $this->loadTasks();
        $result = [];

        foreach ($pageIds as $pageId) {
            if (!$includeRestricted && auth_quickaclcheck($pageId) < AUTH_READ) continue;
            $source = rawWiki($pageId);
            if (!is_string($source) || !preg_match_all('/\{\{todoistcompanion>(.*?)\}\}/s', $source, $matches, PREG_SET_ORDER)) continue;
            foreach ($matches as $match) {
                $parsed = $this->parseTaskSyntax($match[1]);
                if (!$parsed) continue;
                [$key, $title] = $parsed;
                $record = $stored[$key] ?? [];
                if (!empty($record['page']) && $record['page'] !== $pageId) $record = [];
                $result[] = [
                    'key' => $key,
                    'title' => $title,
                    'page' => $pageId,
                    'todoist_id' => (string)($record['todoist_id'] ?? ''),
                    'completed' => !empty($record['completed']),
                    'status' => (string)($record['status'] ?? 'pending'),
                ];
            }
        }
        return $result;
    }

    private function saveTasks($tasks)
    {
        return $this->writeJson('tasks.json', $tasks);
    }

    private function loadProject()
    {
        $file = $this->storageDirectory() . '/project.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveProject($project)
    {
        return $this->writeJson('project.json', $project);
    }

    private function writeJson($name, $data)
    {
        $dir = $this->storageDirectory();
        io_mkdir_p($dir);
        $tmp = $dir . '/' . $name . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
        return @rename($tmp, $dir . '/' . $name);
    }
}
