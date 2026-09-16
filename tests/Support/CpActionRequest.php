<?php

namespace Tests\Support;

use Craft;
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use verbb\hyper\controllers\FieldsController;
use verbb\hyper\Hyper;

/** Exercise public actions with Craft's authentication, CSRF and permission checks intact. */
final class CpActionRequest
{
    public static function run(string $action, ?User $identity, array $body, bool $validCsrf = true, array $query = [], string $controllerClass = FieldsController::class): Response
    {
        $app = Craft::$app;
        $original = ['request' => $app->request, 'response' => $app->response, 'user' => $app->user, 'elementSources' => $app->elementSources];
        $site = $app->sites->getCurrentSite();
        $sourceCache = new \ReflectionProperty(\craft\base\Element::class, 'sources');
        $previousSources = $sourceCache->getValue();
        $view = $app->view;
        $mode = $view->getTemplateMode();
        try {
            $request = new class(['isCpRequest' => true, 'isConsoleRequest' => false, 'enableCookieValidation' => false]) extends Request {
                public function getMethod(): string { return 'POST'; }
            };
            $request->setScriptUrl('/index.php');
            $request->setHostInfo('https://hyper-tests.example.test');
            $request->setScriptFile(CRAFT_WEB_ROOT . '/index.php');
            $request->setUrl('/index.php?p=admin/actions/hyper/fields/' . $action);
            $request->setQueryParams($query);
            $request->getHeaders()->set('Accept', 'application/json');
            $app->set('request', $request);
            $app->set('response', new Response());
            $user = new class extends \craft\console\User {
                public string $idParam = '__id';
                // CP assets render session chrome in the console-backed request harness.
                public function getRemainingSessionTime(): int { return -1; }
                public function getImpersonator(): ?User { return null; }
            };
            $user->setIdentity($identity);
            $app->set('user', $user);
            // Picker sources depend on the current identity and newly created fixture sections.
            $sourceCache->setValue(null, []);
            $app->set('elementSources', new \craft\services\ElementSources());
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $request->setBodyParams($body + [$request->csrfParam => $validCsrf ? $request->getCsrfToken() : 'invalid']);
            // runAction invokes beforeAction; calling action methods directly would bypass CSRF/accessCp.
            $controller = match ($controllerClass) {
                FieldsController::class => new FieldsController('fields', Hyper::$plugin),
                \verbb\hyper\controllers\PluginController::class => new $controllerClass('plugin', Hyper::$plugin),
                default => new $controllerClass('element-indexes', $app),
            };
            return $controller->runAction($action);
        } finally {
            $sourceCache->setValue(null, $previousSources);
            foreach ($original as $id => $component) {
                $app->set($id, $component);
            }
            $app->sites->setCurrentSite($site);
            $view->setTemplateMode($mode);
        }
    }
}
