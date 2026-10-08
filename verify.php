<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
Security::startSession();

// Email links open a confirm page; the token is only consumed on POST so mail scanners cannot burn it.
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$t = (string) ($_REQUEST['t'] ?? '');
$p = (string) ($_REQUEST['p'] ?? '');

if ($post) {
    if (!Security::csrfOk()) {
        http_response_code(400);
        View::message('Session expired', '<p>Please go back, reload the page and try again.</p>');
        exit;
    }
    if (!RateLimiter::check('verify_ip', Util::ip())) {
        http_response_code(429);
        View::message('Too many attempts', '<p>Please wait a few minutes and try again.</p>');
        exit;
    }
}

if ($p !== '') {
    $msg = '';
    if ($post) {
        $r = Verification::checkPhoneCode($p, (string) ($_POST['code'] ?? ''));
        if ($r === 'ok') {
            View::message('Phone verified', '<p>Thank you! Your shifts are confirmed. Check your email for details and cancel links.</p>');
            exit;
        }
        $msg = ['wrong' => 'That code is not correct.', 'locked' => 'Too many wrong attempts. Please sign up again.',
                'expired' => 'That code has expired. Please sign up again.', 'invalid' => 'This link is not valid.'][$r] ?? 'Error.';
    }
    View::header('Verify your phone');
    echo '<h1>Verify your phone</h1>';
    View::errors($msg ? [$msg] : []);
    echo '<form method="post">' . Security::csrfField() . '<input type="hidden" name="p" value="' . View::h($p) . '">'
        . '<label>6-digit code from the text message <input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"></label>'
        . '<button class="primary">Verify</button></form>';
    View::footer();
    exit;
}

if (!$post) {
    View::header('Confirm your email');
    echo '<h1>Confirm your email</h1><form method="post">' . Security::csrfField() . '<input type="hidden" name="t" value="' . View::h($t) . '">'
        . '<p>Click the button to confirm your email address and your volunteer shifts.</p><button class="primary">Confirm my email</button></form>';
    View::footer();
    exit;
}

$r = Verification::verifyEmail($t);
switch ($r['status']) {
    case 'ok':
        if (!empty($r['phone_token'])) {
            header('Location: ' . Util::url('verify.php?p=' . $r['phone_token']));
            exit;
        }
        $pending = Verification::requirePhone()
            ? '<p>Your shifts will be confirmed once your phone number is verified. Text verification is not yet available; an administrator will follow up.</p>'
            : '<p>Your shifts are confirmed. We emailed you the details and a link to cancel if your plans change.</p>';
        View::message('Email verified', $pending);
        break;
    case 'already':
        View::message('Already verified', '<p>This link was already used. Your email is verified.</p>');
        break;
    case 'expired':
        View::message('Link expired', '<p>This link has expired and your held shifts were released. Please sign up again.</p>');
        break;
    default:
        View::message('Invalid link', '<p>This verification link is not valid.</p>');
}
