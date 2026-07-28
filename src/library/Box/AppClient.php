<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Response;

class Box_AppClient extends Box_App
{
    protected function init(): void
    {
        // Paths without letters can resolve to custom pages, but cannot identify modules.
        if (preg_match('/[a-zA-Z]/', $this->mod) === 1) {
            $m = $this->di['mod']($this->mod);
            $m->registerClientRoutes($this);
        }

        if ($this->mod == 'api') {
            define('API_MODE', true);

            // Prevent errors from being displayed in API mode as it can cause invalid JSON to be returned.
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        } else {
            $extensionService = $this->di['mod_service']('extension');
            if ($extensionService->isExtensionActive('mod', 'redirect')) {
                $m = $this->di['mod']('redirect');
                $m->registerClientRoutes($this);
            }

            if ($extensionService->isExtensionActive('mod', 'partnership')) {
                $m = $this->di['mod']('partnership');
                $m->registerClientRoutes($this);
            }

            // init index module manually
            $this->get('', 'get_index');
            $this->get('/', 'get_index');

            // init custom methods for undefined pages
            $this->get('/:page', 'get_custom_page', ['page' => '[a-z0-9-/.//]+']);
            $this->post('/:page', 'get_custom_page', ['page' => '[a-z0-9-/.//]+']);
        }
    }

    public function get_index(): string
    {
        return $this->render('mod_index_dashboard');
    }

    public function get_custom_page($page): Response
    {
        $ext = $this->ext;
        if (str_contains((string) $page, '.')) {
            $ext = substr((string) $page, strpos((string) $page, '.') + 1);
            $page = substr((string) $page, 0, strpos((string) $page, '.'));
        }

        if ($page === 'login' && $this->di['auth']->isClientLoggedIn()) {
            return $this->redirect('/');
        }

        $page = str_replace('/', '_', $page);
        $tpl = 'mod_page_' . $page;

        try {
            $content = $this->render($tpl, ['post' => $this->getRequest()->request->all()], $ext);

            if ("{$tpl}.{$ext}" === 'mod_page_sitemap.xml') {
                return $this->responseFactory()->html($content, 200, ['Content-Type' => 'application/xml']);
            }

            return $this->responseFactory()->html($content);
        } catch (FOSSBilling\InformationException $e) {
            // @phpstan-ignore if.alwaysFalse (DEBUG is a runtime constant that may be true during debugging)
            if (DEBUG) {
                error_log($e->getMessage());
            }
        } catch (Twig\Error\LoaderError|Twig\Error\RuntimeError|Twig\Error\SyntaxError $e) {
            // A real template bug, not a missing page. Surface as a 500 so the
            // next regression of this shape (issue #3818) cannot hide behind a
            // generic 404.
            $this->di['logger']->setChannel('routing')->error(sprintf(
                'Template rendering failed for "%s" (page "%s"): %s',
                $tpl,
                (string) $page,
                $e->getMessage(),
            ), ['exception' => $e]);

            $internal = new FOSSBilling\InformationException('The requested page could not be rendered.', [], 500);

            return $this->errorResponse($internal);
        }
        $e = new FOSSBilling\InformationException('Page :url not found', [':url' => $this->url], 404);

        $this->di['logger']->setChannel('routing')->info($e->getMessage());

        return $this->errorResponse($e, 404);
    }

    /**
     * Catch any exception from module controllers and route it through the
     * error template rather than letting it bubble to PHP's raw handler.
     * The base run() only catches Auth/Email exceptions; InformationException
     * thrown by module routes (Order, Support, etc.) would otherwise escape.
     */
    #[Override]
    public function run(): Response
    {
        try {
            return parent::run();
        } catch (FOSSBilling\InformationException $e) {
            return $this->errorResponse($e, $e->getCode() ?: null);
        } catch (\Throwable $e) {
            $this->di['logger']->setChannel('routing')->error($e->getMessage(), ['exception' => $e]);
            $internal = new FOSSBilling\InformationException('An unexpected error occurred.', [], 500);

            return $this->errorResponse($internal, 500);
        }
    }

    /**
     * Override errorResponse so that a failure inside error.html.twig itself
     * never escapes to the raw PHP handler — falls back to a minimal inline page.
     */
    #[Override]
    public function errorResponse(\Exception $e, ?int $statusCode = null, array $headers = []): Response
    {
        try {
            $html = $this->render('error', ['exception' => $e]);
        } catch (\Throwable $renderEx) {
            $this->di['logger']->setChannel('routing')->error(
                'error.html.twig itself failed to render: ' . $renderEx->getMessage(),
                ['exception' => $renderEx]
            );
            $code = $statusCode ?? ($e->getCode() ?: 500);
            $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Error {$code}</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:system-ui,-apple-system,sans-serif;background:#0d0f1a;color:#f1f5f9;
         display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem;}
    .wrap{text-align:center;max-width:420px}
    .code{font-size:5rem;font-weight:800;line-height:1;
          background:linear-gradient(135deg,#5271ff,#224dda);
          -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
    .msg{margin:1rem 0 .5rem;color:rgba(255,255,255,.55);font-size:.9375rem;line-height:1.6}
    a{display:inline-flex;align-items:center;gap:.4rem;margin-top:1.5rem;padding:.6rem 1.25rem;
      border-radius:.75rem;background:linear-gradient(135deg,#5271ff,#224dda);
      color:#fff;font-weight:600;font-size:.875rem;text-decoration:none}
  </style>
</head>
<body>
  <div class="wrap">
    <p class="code">{$code}</p>
    <p class="msg">{$msg}</p>
    <a href="/">&#8592; Go Home</a>
  </div>
</body>
</html>
HTML;
        }

        return $this->responseFactory()->error($html, $e, $statusCode, $headers);
    }

    /**
     * @param string $fileName
     */
    #[Override]
    public function render($fileName, $variableArray = [], $ext = 'html.twig'): string
    {
        try {
            $template = $this->getTwig()->load(Path::changeExtension($fileName, $ext));
        } catch (Twig\Error\LoaderError $e) {
            $this->di['logger']->setChannel('routing')->info($e->getMessage());

            throw new FOSSBilling\InformationException('Page not found', null, 404);
        }

        return $template->render($variableArray);
    }

    /**
     * Get Twig environment for client area.
     */
    protected function getTwig(): Twig\Environment
    {
        $twigFactory = $this->di['twig_factory'];

        return $twigFactory->createClientEnvironment($this->debugBar);
    }
}
