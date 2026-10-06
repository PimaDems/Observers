<?php
declare(strict_types=1);

/** Shared controller for the /partisan/ and /nonpartisan/ entry points. Never outputs volunteer PII. */
final class PublicApp
{
    private const TITLES = [
        'partisan' => ['Democratic Party Volunteers', 'Electioneering volunteers (75 ft from the entrance), roaming volunteers, and inside-observer interest.'],
        'nonpartisan' => ['Non-Partisan Observers (Indivisible)', 'Outside observers at polling places and early vote centers, ballot drop boxes, and roaming observers.'],
    ];

    private string $group;
    private string $self;

    public static function run(string $group): void
    {
        (new self($group))->dispatch();
    }

    private function __construct(string $group)
    {
        $this->group = $group;
        $this->self = Util::url($group . '/');
    }

    private function link(array $q = []): string
    {
        return $this->self . ($q ? '?' . http_build_query($q) : '');
    }

    private function dispatch(): void
    {
        Security::startSession();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleSignup();
            return;
        }
        $view = (string) ($_GET['view'] ?? '');
        if (isset($_GET['api'])) {
            $this->api((string) $_GET['api']);
            return;
        }
        match ($view) {
            'map' => $this->mapPage(),
            'site' => $this->sitePage(),
            'cov_site' => $this->coverageBySite(),
            'cov_time' => $this->coverageByTime(),
            default => $this->datePage(),
        };
    }

    // ---- shared chrome -------------------------------------------------------------------

    private function head(string $title, string $active, string $extraHead = ''): void
    {
        [$name, $blurb] = self::TITLES[$this->group];
        View::header($title . ' - ' . $name, '', $extraHead);
        echo '<header><h1>' . View::h($name) . '</h1><p class="muted">' . View::h($blurb) . '</p><nav>';
        $tabs = ['date' => 'Browse by date', 'map' => 'Browse by map', 'cov_site' => 'Coverage by site', 'cov_time' => 'Coverage by time'];
        foreach ($tabs as $v => $label) {
            echo '<a class="' . ($v === $active ? 'active' : '') . '" href="' . View::h($this->link($v === 'date' ? [] : ['view' => $v])) . '">' . View::h($label) . '</a>';
        }
        $other = $this->group === 'partisan' ? 'nonpartisan' : 'partisan';
        echo '<a class="other" href="' . View::h(Util::url($other . '/')) . '">' . ($other === 'partisan' ? 'Democratic Party volunteers' : 'Non-partisan observers') . ' &rarr;</a>';
        echo '</nav></header><main>';
    }

    private function foot(string $scripts = ''): void
    {
        echo '</main><footer><p class="muted">Volunteer details are private and visible only to election administrators and the coordinator of the location you sign up for.</p></footer>';
        View::footer($scripts);
    }

    private function shifts(array $f = []): array
    {
        return Shifts::query($f + ['affiliation' => $this->group, 'future_only' => true]);
    }

    private function shiftLabel(array $s): string
    {
        $out = View::h(Util::fmtTime($s['starts_at']) . ' – ' . Util::fmtTime($s['ends_at'])) . ' <span class="muted">' . View::h($s['role_name']) . '</span> ' . View::badge($s['level']);
        if ($s['is_external']) {
            $out .= ' <span class="tag">register interest</span>';
        } elseif ($s['remaining'] !== null) {
            $out .= ' <span class="tag">' . ($s['full'] ? 'full' : (int) $s['remaining'] . ' spot(s) left') . '</span>';
        }
        return $out;
    }

    // ---- 1. browse by date ---------------------------------------------------------------

    private function datePage(): void
    {
        $dates = array_column(Db::all(
            'SELECT DISTINCT SUBSTR(sh.starts_at, 1, 10) AS d FROM shifts sh JOIN roles r ON r.id = sh.role_id JOIN sites si ON si.id = sh.site_id
             WHERE r.affiliation = ? AND sh.is_active = 1 AND si.is_active = 1 AND sh.starts_at > ? ORDER BY d',
            [$this->group, Util::now()]
        ), 'd');
        $day = (string) ($_GET['date'] ?? '');
        if (!Util::validDate($day) || !in_array($day, $dates, true)) {
            $day = $dates[0] ?? '';
        }
        $this->head('Browse by date', 'date');
        if (!$dates) {
            echo '<p>No upcoming shifts have been published yet. Please check back soon.</p>';
            $this->foot();
            return;
        }
        echo '<form method="get" class="inline"><label>Date <select name="date" data-autosubmit>';
        foreach ($dates as $d) {
            echo '<option value="' . View::h($d) . '"' . ($d === $day ? ' selected' : '') . '>' . View::h(Util::fmtDate($d)) . '</option>';
        }
        echo '</select></label> <button>Show</button></form>';

        $bySite = [];
        foreach ($this->shifts(['day' => $day]) as $s) {
            $bySite[$s['site_id']]['site'] = $s;
            $bySite[$s['site_id']]['shifts'][] = $s;
        }
        echo '<h2>' . View::h(Util::fmtDate($day)) . ' &mdash; ' . count($bySite) . ' location(s)</h2>';
        foreach ($bySite as $sid => $g) {
            $s = $g['site'];
            $open = array_filter($g['shifts'], static fn($x) => !$x['full']);
            echo '<section class="card"><h3><a href="' . View::h($this->link(['view' => 'site', 'site' => $sid, 'day' => $day])) . '">' . View::h($s['site_name']) . '</a></h3>'
                . '<p class="muted">' . View::h(Catalog::TYPES[$s['site_type']] ?? '') . ($s['address'] ? ' &middot; ' . View::h($s['address']) : '') . '</p><ul class="shift-list">';
            foreach ($g['shifts'] as $x) {
                echo '<li>' . $this->shiftLabel($x) . '</li>';
            }
            echo '</ul><a class="button" href="' . View::h($this->link(['view' => 'site', 'site' => $sid, 'day' => $day])) . '">'
                . ($open ? 'Sign up' : 'View') . '</a></section>';
        }
        $this->foot('<script src="' . View::h(Util::url('assets/app.js')) . '"></script>');
    }

    // ---- 2. browse by map ----------------------------------------------------------------

    private function mapPage(): void
    {
        $css = '<link rel="stylesheet" href="' . View::h(Util::url('assets/vendor/leaflet/leaflet.css')) . '">';
        $this->head('Browse by map', 'map', $css);
        $missing = (int) Db::val(
            "SELECT COUNT(DISTINCT si.id) FROM sites si JOIN shifts sh ON sh.site_id = si.id JOIN roles r ON r.id = sh.role_id
             WHERE r.affiliation = ? AND si.is_active = 1 AND (si.lat IS NULL OR si.lng IS NULL) AND si.type <> 'roaming'",
            [$this->group]
        );
        echo '<p>Click a pin to see the dates and shifts for that location.</p>';
        echo '<div id="map" data-api="' . View::h($this->link(['api' => 'sites'])) . '" data-site-api="' . View::h($this->link(['api' => 'site'])) . '" data-site-url="' . View::h($this->link(['view' => 'site'])) . '"></div>';
        echo '<div id="map-detail" class="card" hidden></div>';
        if ($missing) {
            echo '<p class="muted">' . $missing . ' location(s) do not have map coordinates yet; find them under <a href="' . View::h($this->link()) . '">Browse by date</a>.</p>';
        }
        echo '<h3>Roaming</h3><ul>';
        foreach (Db::all("SELECT DISTINCT si.id, si.name FROM sites si JOIN shifts sh ON sh.site_id = si.id JOIN roles r ON r.id = sh.role_id
                          WHERE si.type = 'roaming' AND r.affiliation = ? AND si.is_active = 1", [$this->group]) as $r) {
            echo '<li><a href="' . View::h($this->link(['view' => 'site', 'site' => $r['id']])) . '">' . View::h($r['name']) . '</a></li>';
        }
        echo '</ul>';
        $this->foot('<script src="' . View::h(Util::url('assets/vendor/leaflet/leaflet.js')) . '"></script><script src="' . View::h(Util::url('assets/map.js')) . '"></script>');
    }

    private function api(string $what): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        if ($what === 'sites') {
            $rows = Db::all(
                "SELECT si.id, si.name, si.address, si.type, si.lat, si.lng, COUNT(sh.id) AS shifts
                 FROM sites si JOIN shifts sh ON sh.site_id = si.id JOIN roles r ON r.id = sh.role_id
                 WHERE r.affiliation = ? AND si.is_active = 1 AND sh.is_active = 1 AND sh.starts_at > ? AND si.lat IS NOT NULL AND si.lng IS NOT NULL
                 GROUP BY si.id, si.name, si.address, si.type, si.lat, si.lng",
                [$this->group, Util::now()]
            );
            $levels = [];
            foreach ($this->shifts() as $s) {
                $cur = $levels[$s['site_id']] ?? 'ok';
                $rank = ['none' => 0, 'low' => 1, 'ok' => 2];
                $levels[$s['site_id']] = $rank[$s['level']] < $rank[$cur] ? $s['level'] : $cur;
            }
            echo json_encode(array_map(fn($r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'address' => $r['address'], 'type' => Catalog::TYPES[$r['type']] ?? '',
                'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'shifts' => (int) $r['shifts'], 'level' => $levels[$r['id']] ?? 'none',
            ], $rows), JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($what === 'site') {
            $out = [];
            foreach ($this->shifts(['site_id' => (int) ($_GET['site'] ?? 0)]) as $s) {
                $out[substr($s['starts_at'], 0, 10)][] = [
                    'time' => Util::fmtTime($s['starts_at']) . ' – ' . Util::fmtTime($s['ends_at']), 'role' => $s['role_name'],
                    'level' => $s['level'], 'label' => Shifts::levelLabel($s['level']), 'full' => $s['full'],
                ];
            }
            $days = [];
            foreach ($out as $d => $list) {
                $days[] = ['date' => $d, 'label' => Util::fmtDate($d), 'shifts' => $list];
            }
            echo json_encode($days, JSON_UNESCAPED_UNICODE);
            return;
        }
        http_response_code(404);
        echo '[]';
    }

    // ---- site page + sign-up -------------------------------------------------------------

    private function sitePage(array $errors = [], array $old = []): void
    {
        $siteId = (int) ($_GET['site'] ?? $_POST['site'] ?? 0);
        $site = Db::one('SELECT * FROM sites WHERE id = ? AND is_active = 1', [$siteId]);
        $shifts = $site ? $this->shifts(['site_id' => $siteId]) : [];
        if (!$site || !$shifts) {
            http_response_code(404);
            $this->head('Location not found', 'date');
            echo '<p>No upcoming shifts for that location.</p><p><a href="' . View::h($this->link()) . '">Back</a></p>';
            $this->foot();
            return;
        }
        $day = (string) ($_GET['day'] ?? '');
        $byDay = [];
        foreach ($shifts as $s) {
            $byDay[substr($s['starts_at'], 0, 10)][] = $s;
        }
        $this->head($site['name'], 'date');
        echo '<h2>' . View::h($site['name']) . '</h2><p class="muted">' . View::h(Catalog::TYPES[$site['type']] ?? '') . ($site['address'] ? ' &middot; ' . View::h($site['address']) : '') . '</p>';
        if ($site['hours_text']) {
            echo '<p class="muted">Site hours: ' . View::h($site['hours_text']) . '</p>';
        }
        View::errors($errors);
        $checked = array_map('intval', (array) ($old['shifts'] ?? []));
        echo '<form method="post" action="' . View::h($this->self) . '" class="signup">' . Security::csrfField()
            . '<input type="hidden" name="site" value="' . $siteId . '">'
            . '<p class="hp" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
        echo '<h3>1. Choose one or more shifts</h3>';
        if (Util::validDate($day) && isset($byDay[$day])) {
            echo '<p><a href="' . View::h($this->link(['view' => 'site', 'site' => $siteId])) . '">Show all dates</a></p>';
            $byDay = [$day => $byDay[$day]];
        }
        foreach ($byDay as $d => $list) {
            echo '<fieldset><legend>' . View::h(Util::fmtDate($d)) . '</legend>';
            foreach ($list as $s) {
                $dis = $s['full'] ? ' disabled' : '';
                echo '<label class="shift' . ($s['full'] ? ' full' : '') . '"><input type="checkbox" name="shifts[]" value="' . (int) $s['id'] . '"'
                    . ($dis) . (in_array((int) $s['id'], $checked, true) ? ' checked' : '') . '> ' . $this->shiftLabel($s) . '</label>';
            }
            echo '</fieldset>';
        }
        echo '<h3>2. Your details</h3>'
            . '<label>Name <input name="name" required maxlength="150" autocomplete="name" value="' . View::h($old['name'] ?? '') . '"></label>'
            . '<label>Email <input type="email" name="email" required maxlength="190" autocomplete="email" value="' . View::h($old['email'] ?? '') . '"></label>'
            . '<label>Mobile phone (US) <input type="tel" name="phone" required maxlength="40" autocomplete="tel" value="' . View::h($old['phone'] ?? '') . '"></label>'
            . '<p class="muted">We will email you a link to verify your address. Your details are shared only with election administrators'
            . ' and, for locations run by an outside coordinator, that coordinator.</p>'
            . '<button class="primary">3. Sign up &amp; verify my email</button></form>';
        $this->foot();
    }

    private function handleSignup(): void
    {
        if (!Security::csrfOk()) {
            http_response_code(400);
            View::message('Session expired', '<p>Your form session expired. Please go back, reload the page and try again.</p>');
            return;
        }
        $_GET['site'] = $_POST['site'] ?? 0;
        $old = [
            'name' => (string) ($_POST['name'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''), 'shifts' => (array) ($_POST['shifts'] ?? []),
        ];
        if (!empty($_POST['website'])) { // honeypot
            View::message('Check your email', '<p>Thanks!</p>');
            return;
        }
        $emailKey = strtolower(trim($old['email']));
        if (!RateLimiter::check('signup_ip', Util::ip()) || !RateLimiter::check('signup_email', $emailKey)) {
            http_response_code(429);
            $this->sitePage(['Too many attempts. Please wait a while and try again.'], $old);
            return;
        }
        try {
            $ids = array_filter(array_map('intval', $old['shifts']), static fn($i) => $i > 0);
            Booking::book($old, $ids, $this->group);
        } catch (BookingException $e) {
            $this->sitePage([$e->getMessage()], $old);
            return;
        } catch (Throwable $e) {
            error_log('Signup failed: ' . $e->getMessage());
            http_response_code(500);
            $this->sitePage(['Something went wrong. Please try again.'], $old);
            return;
        }
        View::message(
            'Check your email',
            '<p>We sent a verification link to <strong>' . View::h($old['email']) . '</strong>. Your shifts are held for '
            . (int) Config::get('verification.token_ttl_hours', 24) . ' hours; they are confirmed once you click the link.</p>'
            . '<p class="muted">Phone verification: ' . (Verification::smsProvider()->canSend() ? 'you may receive a text code.' : 'text verification is not available yet; your number is saved as unverified (pending provider).') . '</p>'
        );
    }

    // ---- 3. coverage ---------------------------------------------------------------------

    private function siteChoices(): array
    {
        return Db::all(
            'SELECT DISTINCT si.id, si.name, si.type FROM sites si JOIN shifts sh ON sh.site_id = si.id JOIN roles r ON r.id = sh.role_id
             WHERE r.affiliation = ? AND si.is_active = 1 AND sh.is_active = 1 AND sh.starts_at > ? ORDER BY si.name',
            [$this->group, Util::now()]
        );
    }

    private function legend(): void
    {
        echo '<p class="legend">' . View::badge('none') . ' ' . View::badge('low') . ' ' . View::badge('ok') . ' <span class="muted">Includes confirmed volunteers and coverage reported by outside coordinators.</span></p>';
    }

    private function coverageBySite(): void
    {
        $this->head('Coverage by site', 'cov_site');
        $choices = $this->siteChoices();
        $siteId = (int) ($_GET['site'] ?? ($choices[0]['id'] ?? 0));
        echo '<form method="get" class="inline"><input type="hidden" name="view" value="cov_site"><label>Site <select name="site" data-autosubmit>';
        foreach ($choices as $c) {
            echo '<option value="' . (int) $c['id'] . '"' . ($c['id'] == $siteId ? ' selected' : '') . '>' . View::h($c['name'] . ' (' . (Catalog::TYPES[$c['type']] ?? '') . ')') . '</option>';
        }
        echo '</select></label> <button>Show</button></form>';
        $this->legend();
        $grid = [];
        $dates = [];
        foreach ($this->shifts(['site_id' => $siteId]) as $s) {
            $d = substr($s['starts_at'], 0, 10);
            $slot = date('H:i', strtotime($s['starts_at'])) . '|' . date('H:i', strtotime($s['ends_at']));
            $dates[$d] = true;
            $grid[$s['role_name']][$slot][$d] = $s;
        }
        ksort($dates);
        foreach ($grid as $role => $slots) {
            ksort($slots);
            echo '<h3>' . View::h($role) . '</h3><div class="scroll"><table class="grid"><thead><tr><th>Time</th>';
            foreach (array_keys($dates) as $d) {
                echo '<th>' . View::h(date('D M j', strtotime($d))) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($slots as $slot => $cells) {
                [$a, $b] = explode('|', $slot);
                echo '<tr><th>' . View::h(date('g:i A', strtotime($a)) . ' – ' . date('g:i A', strtotime($b))) . '</th>';
                foreach (array_keys($dates) as $d) {
                    $c = $cells[$d] ?? null;
                    if (!$c) {
                        echo '<td></td>';
                        continue;
                    }
                    echo '<td class="cov-' . View::h($c['level']) . '"><a href="' . View::h($this->link(['view' => 'site', 'site' => $siteId, 'day' => $d])) . '" title="' . View::h(Shifts::levelLabel($c['level'])) . '">'
                        . (int) $c['covered'] . '/' . (int) $c['target_coverage'] . '</a></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        if (!$grid) {
            echo '<p>No upcoming shifts.</p>';
        }
        $this->foot('<script src="' . View::h(Util::url('assets/app.js')) . '"></script>');
    }

    private function coverageByTime(): void
    {
        $this->head('Coverage by time', 'cov_time');
        $dates = array_column(Db::all(
            'SELECT DISTINCT SUBSTR(sh.starts_at, 1, 10) AS d FROM shifts sh JOIN roles r ON r.id = sh.role_id
             WHERE r.affiliation = ? AND sh.is_active = 1 AND sh.starts_at > ? ORDER BY d',
            [$this->group, Util::now()]
        ), 'd');
        $day = (string) ($_GET['date'] ?? '');
        if (!in_array($day, $dates, true)) {
            $day = $dates[0] ?? '';
        }
        $gaps = !empty($_GET['gaps']);
        echo '<form method="get" class="inline"><input type="hidden" name="view" value="cov_time"><label>Date <select name="date" data-autosubmit>';
        foreach ($dates as $d) {
            echo '<option value="' . View::h($d) . '"' . ($d === $day ? ' selected' : '') . '>' . View::h(Util::fmtDate($d)) . '</option>';
        }
        echo '</select></label> <label><input type="checkbox" name="gaps" value="1"' . ($gaps ? ' checked' : '') . ' data-autosubmit> Only show sites needing volunteers</label> <button>Show</button></form>';
        $this->legend();
        $slots = [];
        if ($day !== '') {
            foreach ($this->shifts(['day' => $day]) as $s) {
                if ($gaps && $s['level'] === 'ok') {
                    continue;
                }
                $slots[date('H:i', strtotime($s['starts_at'])) . '|' . date('H:i', strtotime($s['ends_at']))][] = $s;
            }
        }
        ksort($slots);
        foreach ($slots as $slot => $list) {
            [$a, $b] = explode('|', $slot);
            echo '<section class="card"><h3>' . View::h(date('g:i A', strtotime($a)) . ' – ' . date('g:i A', strtotime($b))) . '</h3><div class="chips">';
            foreach ($list as $s) {
                echo '<a class="chip cov-' . View::h($s['level']) . '" href="' . View::h($this->link(['view' => 'site', 'site' => $s['site_id'], 'day' => $day])) . '" title="'
                    . View::h(Shifts::levelLabel($s['level']) . ' - ' . $s['role_name']) . '">' . View::h($s['site_name']) . ' <small>' . (int) $s['covered'] . '/' . (int) $s['target_coverage'] . '</small></a>';
            }
            echo '</div></section>';
        }
        if (!$slots) {
            echo '<p>Nothing to show.</p>';
        }
        $this->foot('<script src="' . View::h(Util::url('assets/app.js')) . '"></script>');
    }
}
