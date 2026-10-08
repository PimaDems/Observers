<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
Security::startSession();
$t = (string) ($_REQUEST['t'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
    $r = Booking::cancel($t);
    if ($r && $r['was_active']) {
        View::message('Shift cancelled', '<p>Your shift at <strong>' . View::h($r['site_name']) . '</strong> on ' . View::h(Util::fmtRange($r['starts_at'], $r['ends_at'])) . ' was cancelled. Thank you for letting us know.</p>');
    } elseif ($r) {
        View::message('Already cancelled', '<p>This shift is no longer active.</p>');
    } else {
        http_response_code(404);
        View::message('Invalid link', '<p>This cancel link is not valid.</p>');
    }
    exit;
}

$info = preg_match('/^[a-f0-9]{32}$/', $t) ? Db::one(
    'SELECT sh.starts_at, sh.ends_at, si.name FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id JOIN sites si ON si.id = sh.site_id WHERE sg.cancel_token = ?',
    [$t]
) : null;
if (!$info) {
    http_response_code(404);
    View::message('Invalid link', '<p>This cancel link is not valid.</p>');
    exit;
}
View::header('Cancel shift');
echo '<h1>Cancel your shift?</h1><p>' . View::h($info['name']) . '<br>' . View::h(Util::fmtRange($info['starts_at'], $info['ends_at'])) . '</p>'
    . '<form method="post">' . Security::csrfField() . '<input type="hidden" name="t" value="' . View::h($t) . '"><button class="primary">Yes, cancel this shift</button></form>';
View::footer();
