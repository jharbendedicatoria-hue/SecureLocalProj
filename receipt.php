<?php
/** receipt.php - printable receipt with every item of the order. Only people who can see the order can open it. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/config/menu.php';
$me = require_full();

$o = one(
    'SELECT o.*, c.display_name AS created_name, a.display_name AS assigned_name
     FROM orders o
     JOIN users c ON c.id = o.created_by
     LEFT JOIN users a ON a.id = o.assigned_to
     WHERE o.id = ?',
    [(int) ($_GET['id'] ?? 0)]
);
if (!$o) {
    http_response_code(404);
    render_error(404);
    exit;
}
if (!can_see_order($me, $o)) {
    deny($me, 'receipt ' . $o['id']);
}
audit($me['username'], 'receipt_viewed', 'order ' . $o['id']);

$lines = all('SELECT item, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
if (!$lines && $o['unit_price'] !== null) {
    $lines = [['item' => $o['item'], 'quantity' => (int) $o['quantity'], 'unit_price' => (float) $o['unit_price']]];
}
$total = 0.0;
$qty_total = 0;
foreach ($lines as $l) {
    $total += (float) $l['unit_price'] * (int) $l['quantity'];
    $qty_total += (int) $l['quantity'];
}
if ((float) $o['total'] > 0) {
    $total = (float) $o['total'];
}
$when = date('M j, Y · g:i A', strtotime($o['created_at']));

app_open('Receipt #' . $o['id'], 'orders', $me);
?>
<div class="receipt-wrap">
  <div class="receipt-actions no-print">
    <a class="button secondary" href="<?= e(url('orders.php')) ?>">Back to orders</a>
    <button class="button primary" type="button" data-print><?= icon('copy', 16) ?> Print receipt</button>
  </div>

  <article class="receipt" aria-label="Receipt for order <?= (int) $o['id'] ?>">
    <header class="receipt-head">
      <div class="receipt-brand"><?= e(BRAND_NAME) ?></div>
      <small>Order receipt</small>
    </header>
    <dl class="receipt-meta">
      <div><dt>Order</dt><dd>#<?= (int) $o['id'] ?></dd></div>
      <div><dt>Date</dt><dd><?= e($when) ?></dd></div>
      <div><dt>Customer</dt><dd><?= e($o['customer']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= $o['phone'] !== '' ? e($o['phone']) : '—' ?></dd></div>
    </dl>
    <table class="receipt-items">
      <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $l): $amount = (float) $l['unit_price'] * (int) $l['quantity']; ?>
          <tr>
            <td><?= e($l['item']) ?></td>
            <td><?= (int) $l['quantity'] ?></td>
            <td>₱<?= number_format((float) $l['unit_price'], 0) ?></td>
            <td>₱<?= number_format($amount, 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="receipt-total"><span>Total (<?= $qty_total ?> <?= $qty_total === 1 ? 'item' : 'items' ?>)</span><strong>₱<?= number_format($total, 0) ?></strong></div>
    <dl class="receipt-meta">
      <div><dt>Payment</dt><dd><?= e($o['payment_method']) ?></dd></div>
      <div><dt>Status</dt><dd><?= e($o['status']) ?></dd></div>
      <div><dt>Taken by</dt><dd><?= e($o['created_name']) ?></dd></div>
      <div><dt>Assigned to</dt><dd><?= $o['assigned_name'] ? e($o['assigned_name']) : '—' ?></dd></div>
    </dl>
    <p class="receipt-foot">Thank you for ordering from <?= e(BRAND_NAME) ?>.</p>
  </article>
</div>
<?php app_close();
