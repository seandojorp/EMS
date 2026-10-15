<?php
declare(strict_types=1);

/**
 * Front controller — every request (pages and API) enters here.
 */

// PHP built-in server (php -S): let it serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\SetupService;

Response::securityHeaders();

try {
    Session::start();
    $path = Request::path();

    // First-run gate: until an administrator has been created, only the setup page is reachable.
    $setupPaths = ['/setup', '/api/setup'];
    if (!SetupService::isInitialized()) {
        if (!in_array($path, $setupPaths, true)) {
            if (Request::isApi()) {
                Response::fail('The system has not been initialized yet.', 503);
            }
            Response::redirect('/setup');
        }
    } elseif (in_array($path, $setupPaths, true)) {
        if (Request::isApi()) {
            App\Core\Audit::log('SETUP_REINIT_BLOCKED', 'ems_users', null, 'failure', []);
            Response::fail('The system has already been initialized.', 409);
        }
        Response::redirect('/login');
    }

    $router = require APP_PATH . '/routes.php';
    $router->dispatch(Request::method(), $path);
} catch (HttpException $e) {
    if (Request::isApi()) {
        Response::fail($e->getMessage(), $e->status, $e->errors);
    }
    http_response_code($e->status);
    View::render('errors/error', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => 'Error ' . $e->status], 'auth');
} catch (PDOException $e) {
    error_log('[DB] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $msg = 'The database is currently unavailable. Please contact the system administrator.';
    if (App\Core\Config::get('app.env') === 'development') {
        $msg .= ' (' . $e->getMessage() . ')';
    }
    if (Request::isApi()) {
        Response::fail($msg, 500);
    }
    http_response_code(500);
    View::render('errors/error', ['status' => 500, 'message' => $msg, 'title' => 'Service unavailable'], 'auth');
} catch (Throwable $e) {
    error_log('[APP] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    $msg = 'An unexpected error occurred. Please try again or contact the system administrator.';
    if (App\Core\Config::get('app.env') === 'development') {
        $msg .= ' (' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    if (Request::isApi()) {
        Response::fail($msg, 500);
    }
    http_response_code(500);
    View::render('errors/error', ['status' => 500, 'message' => $msg, 'title' => 'Unexpected error'], 'auth');
}
