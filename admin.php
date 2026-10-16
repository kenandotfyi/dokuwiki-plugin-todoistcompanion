<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\config\core\Configuration;

/** Settings and connection check for TodoistCompanion. */
class admin_plugin_todoistcompanion extends AdminPlugin
{
    private $notice = '';
    private $errors = [];

    public function forAdminOnly() { return true; }
    public function getMenuSort() { return 940; }
    public function getMenuIcon() { return __DIR__ . '/admin.svg'; }
    public function getMenuText($language) { return $this->getLang('menu'); }

    public function handle()
    {
        global $INPUT, $ID;
        if (!$INPUT->post->has('todoistcompanion_save') && !$INPUT->post->has('todoistcompanion_test')) return;
        if (!auth_isadmin() || !checkSecurityToken()) {
            $this->errors[] = $this->getLang('token_expired');
            return;
        }

        if ($INPUT->post->has('todoistcompanion_test')) {
            $token = $this->deploymentToken();
            if ($token === '') $token = trim($INPUT->post->str('api_token'));
            if ($token === '') $token = trim((string)$this->getConf('api_token'));
            if (!$this->validToken($token)) {
                $this->errors[] = $this->getLang('invalid_token');
                return;
            }
            [$ok, $message] = $this->testConnection($token);
            if ($ok) $this->notice = $this->getLang('connection_ok');
            else $this->errors[] = $message;
            return;
        }

        $token = trim($INPUT->post->str('api_token'));
        $clearToken = $INPUT->post->bool('clear_api_token');
        $deploymentToken = $this->deploymentToken();
        if ($deploymentToken !== '') {
            // The runtime secret is authoritative; this page only edits the project name.
            $token = '';
            $clearToken = false;
        }
        $projectName = trim($INPUT->post->str('project_name'));
        if ($token !== '' && !$this->validToken($token)) $this->errors[] = $this->getLang('invalid_token');
        if ($clearToken && $token !== '') $this->errors[] = $this->getLang('choose_token_action');
        if ($projectName === '' || strlen($projectName) > 100 || preg_match('/[\r\n]/', $projectName)) {
            $this->errors[] = $this->getLang('invalid_project_name');
        }
        if ($this->errors) return;
        if (!class_exists(Configuration::class)) {
            $this->errors[] = $this->getLang('config_unavailable');
            return;
        }

        try {
            $configuration = new Configuration();
            if ($configuration->isLocked()) throw new RuntimeException($this->getLang('config_locked'));
            $settings = $configuration->getSettings();
            $updates = ['project_name' => $projectName];
            if ($token !== '') $updates['api_token'] = $token;
            elseif ($clearToken) $updates['api_token'] = '';

            $changed = false;
            foreach ($updates as $name => $value) {
                $setting = $settings['plugin____todoistcompanion____' . $name] ?? null;
                if (!$setting) throw new RuntimeException($this->getLang('config_unavailable'));
                if ($setting->isProtected()) throw new RuntimeException($this->getLang('config_locked'));
                $changed = $setting->update($value) || $changed;
                if ($setting->hasError()) throw new RuntimeException($this->getLang('invalid_settings'));
            }
            if ($changed) $configuration->save();
            msg($this->getLang($changed ? 'saved' : 'unchanged'), 1);
            send_redirect(wl($ID, ['do' => 'admin', 'page' => 'todoistcompanion'], true, '&'));
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function html()
    {
        global $ID;
        $tokenConfigured = trim((string)$this->getConf('api_token')) !== '';
        $secretConfigured = $this->secretFileToken() !== '';
        $environmentConfigured = $this->environmentToken() !== '';
        $deploymentConfigured = $secretConfigured || $environmentConfigured;
        $anyTokenConfigured = $deploymentConfigured || $tokenConfigured;
        $projectName = (string)$this->getConf('project_name');
        $tokenSetting = null;
        $projectSetting = null;
        if (class_exists(Configuration::class)) {
            $settings = (new Configuration())->getSettings();
            $tokenSetting = $settings['plugin____todoistcompanion____api_token'] ?? null;
            $projectSetting = $settings['plugin____todoistcompanion____project_name'] ?? null;
        }
        $configLocked = !class_exists(Configuration::class) || (new Configuration())->isLocked();
        $tokenDisabled = $configLocked || !$tokenSetting || $tokenSetting->isProtected() || $deploymentConfigured;
        $projectDisabled = $configLocked || !$projectSetting || $projectSetting->isProtected();
        $testDisabled = !$anyTokenConfigured && $tokenDisabled;

        echo '<div class="plugin_todoistcompanion_admin">';
        echo '<h1>' . hsc($this->getLang('heading')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';
        if (!$tokenSetting || !$projectSetting) echo '<p class="error">' . hsc($this->getLang('config_unavailable')) . '</p>';
        elseif ($projectDisabled && !$deploymentConfigured) echo '<p class="info">' . hsc($this->getLang('protected')) . '</p>';
        foreach ($this->errors as $error) echo '<p class="error" role="alert">' . hsc($error) . '</p>';
        if ($this->notice !== '') echo '<p class="success" role="status">' . hsc($this->notice) . '</p>';

        echo '<h2>' . hsc($this->getLang('connection')) . '</h2>';
        $tokenMessage = $secretConfigured ? 'token_from_secret' : ($environmentConfigured ? 'token_from_environment' : ($tokenConfigured ? 'token_present' : 'token_missing'));
        echo '<p>' . hsc($this->getLang($tokenMessage)) . '</p>';
        echo '<form action="' . hsc(script()) . '" method="post">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '">';
        echo '<input type="hidden" name="do" value="admin"><input type="hidden" name="page" value="todoistcompanion">';
        formSecurityToken();
        echo '<table class="inline"><tbody>';
        echo '<tr><th>' . hsc($this->getLang('api_token')) . '</th><td>';
        if ($deploymentConfigured) {
            $tokenHelp = $secretConfigured ? 'secret_token_help' : 'environment_token_help';
            echo '<p>' . hsc($this->getLang($tokenHelp)) . '</p>';
        } else {
            echo '<label for="todoistcompanion-api-token">' . hsc($this->getLang('api_token')) . '</label><br>';
            echo '<input id="todoistcompanion-api-token" type="password" name="api_token" value="" maxlength="4096" autocomplete="new-password" spellcheck="false"' . ($tokenDisabled ? ' disabled' : '') . '>';
            echo '<p>' . hsc($this->getLang($tokenConfigured ? 'token_replace_help' : 'token_help')) . '</p>';
            if ($tokenConfigured) echo '<label><input type="checkbox" name="clear_api_token" value="1"' . ($tokenDisabled ? ' disabled' : '') . '> ' . hsc($this->getLang('clear_token')) . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th><label for="todoistcompanion-project-name">' . hsc($this->getLang('project_name')) . '</label></th><td>';
        echo '<input id="todoistcompanion-project-name" type="text" name="project_name" value="' . hsc($projectName) . '" maxlength="100" required' . ($projectDisabled ? ' disabled' : '') . '>';
        echo '<p>' . hsc($this->getLang('project_help')) . '</p></td></tr>';
        echo '</tbody></table><p>';
        echo '<button class="button" type="submit" name="todoistcompanion_save" value="1"' . ($projectDisabled ? ' disabled' : '') . '>' . hsc($this->getLang('save')) . '</button> ';
        echo '<button class="button" type="submit" name="todoistcompanion_test" value="1"' . ($testDisabled ? ' disabled' : '') . '>' . hsc($this->getLang('test_connection')) . '</button>';
        echo '</p></form>';

        echo '<h2>' . hsc($this->getLang('future_features')) . '</h2>';
        echo '<p>' . hsc($this->getLang('future_features_help')) . '</p>';

        echo '<h2>' . hsc($this->getLang('all_tasks')) . '</h2>';
        echo '<p>' . hsc($this->getLang('all_tasks_help')) . '</p>';
        $action = plugin_load('action', 'todoistcompanion');
        $tasks = $action && method_exists($action, 'getAllWikiTasks') ? $action->getAllWikiTasks(true) : [];
        if (!$tasks) {
            echo '<p>' . hsc($this->getLang('no_tasks')) . '</p>';
        } else {
            echo '<table class="inline"><thead><tr><th>' . hsc($this->getLang('task')) . '</th><th>' . hsc($this->getLang('page')) . '</th><th>' . hsc($this->getLang('status')) . '</th></tr></thead><tbody>';
            foreach ($tasks as $task) {
                $status = !empty($task['completed']) ? 'completed' : (!empty($task['todoist_id']) ? 'synced' : 'pending');
                echo '<tr><td>' . hsc($task['title']) . '</td><td><a href="' . hsc(wl($task['page'])) . '">' . hsc($task['page']) . '</a></td><td>' . hsc($this->getLang('task_status_' . $status)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function validToken($token)
    {
        return $token !== '' && strlen($token) <= 4096 && !preg_match('/[\r\n]/', $token);
    }

    private function secretFileToken()
    {
        $path = '/run/secrets/todoist_api_token';
        if (!is_readable($path)) return '';
        $token = @file_get_contents($path);
        return is_string($token) ? trim($token) : '';
    }

    private function environmentToken()
    {
        $token = getenv('TODOIST_API_TOKEN');
        return is_string($token) ? trim($token) : '';
    }

    private function deploymentToken()
    {
        $token = $this->secretFileToken();
        return $token !== '' ? $token : $this->environmentToken();
    }

    private function testConnection($token)
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://api.todoist.com/api/v1/projects', false, $context);
        if ($response === false) return [false, $this->getLang('connection_failed')];
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        }
        if ($status === 401 || $status === 403) return [false, $this->getLang('token_rejected')];
        if ($status < 200 || $status >= 300) return [false, sprintf($this->getLang('connection_http_error'), $status)];
        return [true, ''];
    }
}
