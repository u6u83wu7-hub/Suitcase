<?php

if (!function_exists('apIsProduction')) {
    function apIsProduction() {
        return getenv('APP_ENV') === 'production';
    }
}

if (!function_exists('apConfigureErrorHandling')) {
    function apConfigureErrorHandling() {
        ini_set('display_errors', apIsProduction() ? '0' : '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
    }
}

if (!function_exists('apEnsureSession')) {
    function apEnsureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('apCsrfToken')) {
    function apCsrfToken() {
        apEnsureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('apCsrfField')) {
    function apCsrfField() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(apCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('apValidateCsrf')) {
    function apValidateCsrf($token = null) {
        apEnsureSession();
        $postedToken = $token;
        if ($postedToken === null) {
            $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        }
        return isset($_SESSION['csrf_token'])
            && is_string($postedToken)
            && hash_equals($_SESSION['csrf_token'], $postedToken);
    }
}

if (!function_exists('apRequireCsrf')) {
    function apRequireCsrf($redirectUrl = '', $message = '表單驗證失敗，請重新操作。') {
        if (apValidateCsrf()) {
            return;
        }

        if ($redirectUrl !== '' && !headers_sent()) {
            header('Location: ' . $redirectUrl);
            exit;
        }

        http_response_code(403);
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        exit;
    }
}

if (!function_exists('apCsrfFormScript')) {
    function apCsrfFormScript() {
        $token = json_encode(apCsrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return <<<HTML
<script>
(function () {
    const csrfToken = {$token};

    function attachToken(form) {
        if (!form || String(form.method || '').toLowerCase() !== 'post') {
            return;
        }
        let input = form.querySelector('input[name="csrf_token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            form.appendChild(input);
        }
        input.value = csrfToken;
    }

    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(attachToken);
    document.addEventListener('submit', function (event) {
        attachToken(event.target);
    }, true);
})();
</script>
HTML;
    }
}
