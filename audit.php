<?php
/** audit.php - admin only: security log. CSV export is protected against spreadsheet formulas. */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_full();
if ($me['role'] !== 'admin') {
    deny($me, 'audit page');
}

function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

function event_tone(string $event): string
{
    if (preg_match('/FAIL|LOCK|DENIED|TIMEOUT/', $event)) {
        return 'red';
    }
    if (preg_match('/DISABLED|ROLE|STEP_UP|ENROLL|CHANGED|DELETED|ENABLED|RESET|RENAMED/', $event)) {
        return 'yellow';
    }
    return 'green';
}

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['username'], 'audit_export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time', 'User', 'Event', 'Detail']);
    foreach (all('SELECT ts, username, event, detail FROM audit_log ORDER BY id DESC LIMIT 5000') as $r) {
        fputcsv($out, array_map(fn($v) => csv_safe((string) $v), [$r['ts'], $r['username'], strtoupper($r['event']), $r['detail']]));
    }
    fclose($out);
    exit;
}

$rows = all('SELECT ts, username, event, detail FROM audit_log ORDER BY id DESC LIMIT 200');

app_open('Audit log', 'audit', $me);
?>
<div class="page-header">
  <div><div class="eyebrow">Admin</div><h1>Audit log</h1><p>A security record of important activity in your workspace.</p></div>
  <a class="button secondary" href="<?= e(url('audit.php?export=csv')) ?>">Export CSV</a>
</div>
<section class="panel table-panel">
  <div class="table-title"><h2>Recent activity</h2><span>Latest 200 events</span></div>
  <div class="table-scroll">
    <table class="audit-table">
      <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $ev = strtoupper($r['event']); ?>
          <tr>
            <td><?= e($r['ts']) ?></td>
            <td><strong><?= e($r['username']) ?></strong></td>
            <td><span class="event-tag <?= event_tone($ev) ?>"><?= e($ev) ?></span></td>
            <td><?= e($r['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="audit-cards">
    <?php foreach ($rows as $r): $ev = strtoupper($r['event']); ?>
      <article>
        <div><span class="event-tag <?= event_tone($ev) ?>"><?= e($ev) ?></span><time><?= e($r['ts']) ?></time></div>
        <p><?= e($r['detail']) ?></p>
        <small>By <strong><?= e($r['username']) ?></strong></small>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php app_close();
