<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
View::header('Pima County Election Observers');
?>
<h1>Pima County Election Observers &amp; Volunteers</h1>
<p>Choose how you would like to help:</p>
<div class="cards">
    <a class="card big" href="<?= View::h(Util::url('nonpartisan/')) ?>"><h2>Non-Partisan Observer</h2><p>Indivisible outside observers at polling places, early vote centers and ballot drop boxes, plus roaming observers.</p></a>
    <a class="card big" href="<?= View::h(Util::url('partisan/')) ?>"><h2>Democratic Party Volunteer</h2><p>Electioneering volunteers, roaming volunteers, and inside-observer interest.</p></a>
</div>
<?php View::footer();
