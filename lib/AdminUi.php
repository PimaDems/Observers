<?php
declare(strict_types=1);

final class AdminUi
{
    public static function header(string $title, array $admin): void
    {
        View::header($title . ' - Admin', 'admin');
        echo '<header><h1>Observers Admin</h1><nav>';
        foreach (['index' => 'Dashboard', 'sites' => 'Sites', 'shifts' => 'Shifts', 'signups' => 'Signups', 'export' => 'Export', 'blast' => 'Blast', 'import' => 'Import sites', 'coordinators' => 'Coordinators'] as $p => $label) {
            echo '<a href="' . View::h(Util::url("admin/$p.php")) . '">' . View::h($label) . '</a>';
        }
        echo '<form method="post" action="' . View::h(Util::url('admin/logout.php')) . '" class="inline">' . Security::csrfField()
            . '<button>Logout (' . View::h($admin['username']) . ')</button></form></nav></header><main><h2>' . View::h($title) . '</h2>';
    }

    public static function footer(): void
    {
        echo '</main>';
        View::footer();
    }

    public static function flash(?string $ok, array $errors = []): void
    {
        if ($ok) {
            echo '<p class="alert ok">' . View::h($ok) . '</p>';
        }
        View::errors($errors);
    }

    /** Common filter inputs read from $_GET. */
    public static function filters(): array
    {
        $day = (string) ($_GET['day'] ?? '');
        $aff = (string) ($_GET['group'] ?? '');
        return [
            'day' => Util::validDate($day) ? $day : '',
            'affiliation' => in_array($aff, ['partisan', 'nonpartisan'], true) ? $aff : '',
            'site_id' => (int) ($_GET['site_id'] ?? 0),
        ];
    }

    public static function siteOptions(int $selected = 0, string $blank = 'All sites'): string
    {
        $o = '<option value="0">' . View::h($blank) . '</option>';
        foreach (Db::all('SELECT id, name, type FROM sites ORDER BY name, type') as $s) {
            $o .= '<option value="' . (int) $s['id'] . '"' . ($s['id'] == $selected ? ' selected' : '') . '>'
                . View::h($s['name'] . ' (' . (Catalog::TYPES[$s['type']] ?? $s['type']) . ')') . '</option>';
        }
        return $o;
    }

    public static function filterForm(array $f, string $action = '', array $hidden = []): void
    {
        echo '<form method="get" action="' . View::h($action) . '" class="inline">';
        foreach ($hidden as $k => $v) {
            echo '<input type="hidden" name="' . View::h($k) . '" value="' . View::h((string) $v) . '">';
        }
        echo '<label>Group <select name="group"><option value="">Both</option>'
            . '<option value="partisan"' . ($f['affiliation'] === 'partisan' ? ' selected' : '') . '>Partisan (Democratic Party)</option>'
            . '<option value="nonpartisan"' . ($f['affiliation'] === 'nonpartisan' ? ' selected' : '') . '>Non-Partisan (Indivisible)</option></select></label>'
            . '<label>Site <select name="site_id">' . self::siteOptions((int) $f['site_id']) . '</select></label>'
            . '<label>Date <input type="date" name="day" value="' . View::h($f['day']) . '" style="display:inline-block;width:auto"></label> <button>Filter</button></form>';
    }
}
