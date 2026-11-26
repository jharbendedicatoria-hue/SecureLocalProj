<?php
/**
 * orders.php - order desk. One order can hold many items (tap items, set quantities, see the running total).
 * Staff see orders they created or were assigned. Admin sees all.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/config/menu.php';
$me = require_full();

const STATUSES = ['New', 'Making', 'Ready', 'Complete'];
const PAYMENTS = ['Cash', 'GCash'];
const RANGES = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All time'];
const MAX_LINES = 30;

$range = (string) ($_GET['range'] ?? 'all');
if (!array_key_exists($range, RANGES)) {
    $range = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));
$range_q = 'range=' . $range . ($q !== '' ? '&q=' . rawurlencode($q) : '');

function load_order(int $id): ?array
{
    return one('SELECT * FROM orders WHERE id = ?', [$id]);
}

/** Items of one order. Older single-item orders are shown from their own columns. */
function load_items(array $o): array
{
    $rows = all('SELECT item, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
    if ($rows) {
        return $rows;
    }
    if ($o['unit_price'] !== null) {
        return [['item' => $o['item'], 'quantity' => (int) $o['quantity'], 'unit_price' => (float) $o['unit_price']]];
    }
    return [];
}

/** "₱198", or a dash when there is no price. */
function order_total(array $o): ?float
{
    if ((float) $o['total'] > 0) {
        return (float) $o['total'];
    }
    return $o['unit_price'] !== null ? (float) $o['unit_price'] * (int) $o['quantity'] : null;
}

function money(float $n): string
{
    return '₱' . number_format($n, 0);
}

/** "Sisig ×3, Classic Lemon ×1" for the history list. */
function cart_summary(array $items): string
{
    return mb_substr(implode(', ', array_map(fn($i) => $i['item'] . ' ×' . $i['qty'], $items)), 0, 255);
}

/** Checks the cart from the form. Returns ['items','total','qty'] or ['error']. */
function read_cart($raw): array
{
    if (!is_array($raw) || count($raw) === 0) {
        return ['error' => 'Tap at least one item to add it to the order.'];
    }
    if (count($raw) > MAX_LINES) {
        return ['error' => 'An order can have up to ' . MAX_LINES . ' lines.'];
    }
    $lines = [];
    foreach ($raw as $row) {
        $name = is_array($row) ? (string) ($row['name'] ?? '') : '';
        $qty = is_array($row) ? (int) ($row['qty'] ?? 0) : 0;
        $m = menu_item($name);
        if (!$m) {
            return ['error' => 'Choose items from the menu.'];
        }
        if ($m['price'] === null) {
            return ['error' => $name . ' has no price yet. Ask the admin to set it first.'];
        }
        if ($qty < 1 || $qty > 99) {
            return ['error' => 'Quantity must be between 1 and 99.'];
        }
        $lines[$name] = ['item' => $name, 'qty' => ($lines[$name]['qty'] ?? 0) + $qty, 'price' => (int) $m['price']];
    }
    $items = [];
    $total = 0;
    $count = 0;
    foreach ($lines as $line) {
        if ($line['qty'] > 99) {
            return ['error' => 'Quantity must be between 1 and 99.'];
        }
        $items[] = $line;
        $total += $line['qty'] * $line['price'];
        $count += $line['qty'];
    }
    return ['items' => $items, 'total' => $total, 'qty' => $count];
}

/** Checks customer, phone, payment, and assignee. Returns clean values or ['error']. */
function read_head(array $src): array
{
    $customer = trim((string) ($src['customer'] ?? ''));
    $phone = trim((string) ($src['phone'] ?? ''));
    $payment = (string) ($src['payment'] ?? '');
    $assign = (int) ($src['assign'] ?? 0);
    if ($customer === '' || mb_strlen($customer) > 80) {
        return ['error' => 'Enter the customer name (up to 80 characters).'];
    }
    if ($phone !== '' && !preg_match('/^[0-9()+\- ]{1,20}$/', $phone)) {
        return ['error' => 'Phone can only use numbers, spaces, and ( ) + -.'];
    }
    if (!in_array($payment, PAYMENTS, true)) {
        return ['error' => 'Choose a payment method.'];
    }
    if ($assign > 0 && !one('SELECT id FROM users WHERE id = ? AND disabled = 0', [$assign])) {
        return ['error' => 'That person is not available for assignment.'];
    }
    return ['customer' => $customer, 'phone' => $phone, 'payment' => $payment, 'assigned' => $assign > 0 ? $assign : null];
}

function save_items(int $order_id, array $items): void
{
    foreach ($items as $i) {
        run('INSERT INTO order_items (order_id, item, quantity, unit_price) VALUES (?,?,?,?)', [$order_id, $i['item'], $i['qty'], $i['price']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $reopen = null;

    if ($action === 'create' || $action === 'edit') {
        $target = null;
        if ($action === 'edit') {
            $target = load_order((int) ($_POST['id'] ?? 0));
            if (!$target) {
                flash('That order no longer exists.', 'error');
                redirect('orders.php?' . $range_q);
            }
            if (!can_edit_order($me, $target)) {
                deny($me, 'order ' . $target['id'] . ' edit');
            }
        }
        $head = read_head($_POST);
        $cart = read_cart($_POST['items'] ?? null);
        if (isset($head['error'])) {
            flash($head['error'], 'error');
        } elseif (isset($cart['error'])) {
            flash($cart['error'], 'error');
        } else {
            db()->beginTransaction();
            try {
                if ($target) {
                    run('UPDATE orders SET customer = ?, phone = ?, item = ?, quantity = ?, total = ?, payment_method = ?, assigned_to = ? WHERE id = ?',
                        [$head['customer'], $head['phone'], cart_summary($cart['items']), $cart['qty'], $cart['total'], $head['payment'], $head['assigned'], $target['id']]);
                    run('DELETE FROM order_items WHERE order_id = ?', [$target['id']]);
                    save_items((int) $target['id'], $cart['items']);
                    $oid = (int) $target['id'];
                    audit($me['username'], 'order_updated', 'order ' . $oid . ' total ' . $cart['total']);
                    flash('Order #' . $oid . ' updated. Total ' . money((float) $cart['total']) . '.');
                } else {
                    run('INSERT INTO orders (customer, phone, item, quantity, total, payment_method, status, created_by, assigned_to, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$head['customer'], $head['phone'], cart_summary($cart['items']), $cart['qty'], $cart['total'], $head['payment'], 'New', $me['id'], $head['assigned'], date('Y-m-d H:i:s')]);
                    $oid = (int) db()->lastInsertId();
                    save_items($oid, $cart['items']);
                    audit($me['username'], 'order_created', 'order ' . $oid . ': ' . cart_summary($cart['items']));
                    flash('Order #' . $oid . ' added. Total ' . money((float) $cart['total']) . '.');
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                flash('Could not save the order. Please try again.', 'error');
            }
        }
    } elseif ($action === 'status') {
        $o = load_order((int) ($_POST['id'] ?? 0));
        $status = (string) ($_POST['status'] ?? '');
        if (!$o) {
            flash('That order no longer exists.', 'error');
        } elseif (!can_set_status($me, $o)) {
            deny($me, 'order ' . $o['id'] . ' status');
        } elseif (!in_array($status, STATUSES, true)) {
            flash('Choose a valid status.', 'error');
        } else {
            run('UPDATE orders SET status = ? WHERE id = ?', [$status, $o['id']]);
            audit($me['username'], 'order_updated', 'order ' . $o['id'] . ' -> ' . $status);
        }
    } elseif ($action === 'delete') {
        $o = load_order((int) ($_POST['id'] ?? 0));
        if (!$o) {
            flash('That order no longer exists.', 'error');
        } elseif (!can_edit_order($me, $o)) {
            deny($me, 'order ' . $o['id'] . ' delete');
        } elseif (!step_up_ok($me, (string) ($_POST['mfa'] ?? ''), 'order ' . $o['id'] . ' delete')) {
            flash('Enter a valid fresh MFA code to delete this order.', 'error');
            $reopen = $o['id'];
        } else {
            run('DELETE FROM orders WHERE id = ?', [$o['id']]);
            audit($me['username'], 'order_deleted', 'order ' . $o['id']);
            flash('Order deleted.');
        }
    }
    redirect('orders.php?' . $range_q . ($reopen ? '&delete=' . $reopen : ''));
}

$since = match ($range) {
    'today' => date('Y-m-d 00:00:00'),
    'week' => date('Y-m-d H:i:s', time() - 7 * 86400),
    'month' => date('Y-m-d H:i:s', time() - 30 * 86400),
    default => '1970-01-01 00:00:00',
};

$orders = all(
    'SELECT o.*, c.display_name AS created_name, a.display_name AS assigned_name
     FROM orders o
     JOIN users c ON c.id = o.created_by
     LEFT JOIN users a ON a.id = o.assigned_to
     WHERE o.created_at >= ? AND (? = 1 OR o.created_by = ? OR o.assigned_to = ?)
       AND (? = \'\' OR o.customer LIKE ? OR o.phone LIKE ? OR o.id = ?)
     ORDER BY o.id DESC',
    [$since, $me['role'] === 'admin' ? 1 : 0, $me['id'], $me['id'], $q, '%' . $q . '%', '%' . $q . '%', (int) ltrim($q, '#')]
);

$edit = null;
$cart_initial = [];
if (isset($_GET['edit'])) {
    $o = load_order((int) $_GET['edit']);
    if ($o && can_edit_order($me, $o)) {
        $edit = $o;
        foreach (load_items($o) as $i) {
            $cart_initial[] = ['name' => $i['item'], 'qty' => (int) $i['quantity'], 'price' => (int) round((float) $i['unit_price'])];
        }
    }
}
$delete = null;
if (isset($_GET['delete'])) {
    $o = load_order((int) $_GET['delete']);
    if ($o && can_edit_order($me, $o)) {
        $delete = $o;
    }
}
$people = all('SELECT id, display_name FROM users WHERE disabled = 0 ORDER BY id');
$active_cat = 'drink';
if ($edit && !empty($cart_initial)) {
    $first = menu_item($cart_initial[0]['name']);
    $active_cat = $first['category'] ?? 'drink';
}

function items_text(array $o): string
{
    if (str_contains($o['item'], '×') || (int) $o['quantity'] === 0) {
        return $o['item'];
    }
    return (int) $o['quantity'] . ' × ' . $o['item'];
}

function format_time(string $ts): string
{
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return 'Today, ' . date('g:i A', $t);
    }
    return date('M j', $t) . (date('Y', $t) === date('Y') ? '' : ', ' . date('Y', $t));
}

$v = $edit ?? ['customer' => '', 'phone' => '', 'payment_method' => 'Cash', 'assigned_to' => null];

app_open('Orders', 'orders', $me);
echo flash_banner();
?>
<div class="page-header">
  <div>
    <div class="eyebrow">Order desk</div>
    <h1>Orders</h1>
    <p><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?> shown · Updated just now</p>
  </div>
  <a class="button primary mobile-add" href="#order-form"><?= icon('plus') ?> New order</a>
</div>

<section class="panel order-form-panel" id="order-form">
  <div class="panel-heading">
    <div>
      <h2><?= $edit ? 'Edit order #' . (int) $edit['id'] : 'New order' ?></h2>
      <p>Tap items to add them. Use + and − to set the quantity.</p>
    </div>
    <?php if ($edit): ?>
      <a class="close-panel" href="<?= e(url('orders.php?' . $range_q)) ?>" aria-label="Cancel edit"><?= icon('x') ?></a>
    <?php endif; ?>
  </div>

  <form method="post" action="<?= e(url('orders.php?' . $range_q)) ?>" class="order-form" data-cart-form>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'create' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

    <div class="order-details">
      <label class="field"><span class="label">Customer name <em>Required</em></span>
        <input name="customer" value="<?= e($v['customer']) ?>" placeholder="e.g. Jamie Smith" required maxlength="80"></label>
      <label class="field"><span class="label">Phone <small>Optional</small></span>
        <input name="phone" value="<?= e($v['phone']) ?>" placeholder="(000) 000-0000" maxlength="20"></label>
      <label class="field"><span class="label">Payment method <em>Required</em></span>
        <select name="payment">
          <?php foreach (PAYMENTS as $p): ?>
            <option<?= $p === $v['payment_method'] ? ' selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span class="label">Assign to <small>Optional</small></span>
        <select name="assign">
          <option value="0">Unassigned</option>
          <?php foreach ($people as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === (int) $v['assigned_to'] ? ' selected' : '' ?>><?= e($p['display_name']) ?></option>
          <?php endforeach; ?>
        </select></label>
    </div>

    <fieldset class="product-picker">
      <legend>Add items <span>Tap to add</span></legend>
      <div class="menu-tabs" role="group" aria-label="Menu categories">
        <?php foreach (MENU_CATEGORIES as $key => $label): ?>
          <button type="button" class="menu-tab<?= $key === $active_cat ? ' active' : '' ?>" data-cat="<?= e($key) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="product-options">
        <?php foreach (menu_all() as $m): $priced = $m['price'] !== null; ?>
          <button type="button" class="product-option<?= $priced ? '' : ' unpriced' ?>" data-add data-name="<?= e($m['name']) ?>"
                  data-price="<?= $priced ? (int) $m['price'] : 0 ?>" data-cat="<?= e($m['category']) ?>"<?= $m['category'] === $active_cat ? '' : ' hidden' ?><?= $priced ? '' : ' disabled' ?>>
            <span class="product-photo <?= e($m['category']) ?>"><?= menu_photo($m) ?></span>
            <span class="product-info"><strong><?= e($m['name']) ?></strong><small><?= $priced ? '₱' . number_format($m['price']) : 'Price needed' ?></small></span>
            <span class="product-check"><?= icon('plus', 13) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="cart" id="cart" data-initial="<?= e(json_encode($cart_initial)) ?>">
      <div class="cart-head"><h3>Order items</h3><small class="muted">Quantity is set with + and −</small></div>
      <div class="cart-lines"></div>
      <p class="cart-empty muted">No items yet. Tap an item above to add it.</p>
      <div class="cart-total"><span>Total</span><strong data-total>₱0</strong></div>
    </div>
    <div class="cart-inputs"></div>

    <div class="order-submit-row">
      <button class="button primary add-order" type="submit"><?= $edit ? 'Save changes' : icon('plus') . ' Place order' ?></button>
    </div>
  </form>
</section>

<section class="panel table-panel">
  <div class="table-title history-title">
    <div><h2>Order history</h2><span><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?> · Newest first</span></div>
    <form method="get" action="<?= e(url('orders.php')) ?>" class="search-form">
      <input type="hidden" name="range" value="<?= e($range) ?>">
      <input name="q" value="<?= e($q) ?>" placeholder="Search name, phone, or #order" aria-label="Search orders">
      <button class="button small secondary" type="submit">Search</button>
    </form>
    <form method="get" action="<?= e(url('orders.php')) ?>" class="time-filter-form">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <label class="time-filter"><span>Filter time</span>
        <select name="range" data-autosubmit aria-label="Filter orders by time">
          <?php foreach (RANGES as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $key === $range ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></label>
    </form>
  </div>

  <?php if (!$orders): ?>
    <div class="empty">
      <div class="empty-icon"><?= icon('check') ?></div>
      <h3>No orders found</h3>
      <p>Try another time range or search, or add a new order.</p>
      <a class="button secondary" href="<?= e(url('orders.php?range=all')) ?>">View all orders</a>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="orders-table">
        <thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Time</th><th>Created by</th><th>Assigned to</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): $mine = can_edit_order($me, $o); $total = order_total($o); ?>
            <tr>
              <td><strong>#<?= (int) $o['id'] ?></strong></td>
              <td><?= e($o['customer']) ?></td>
              <td><?= $o['phone'] !== '' ? e($o['phone']) : '<span class="muted">Not provided</span>' ?></td>
              <td class="item-cell"><?= e(items_text($o)) ?></td>
              <td class="money"><strong><?= $total === null ? '—' : e(money($total)) ?></strong></td>
              <td><span class="payment-tag <?= strtolower($o['payment_method']) ?>"><?= e($o['payment_method']) ?></span></td>
              <td>
                <?php if (can_set_status($me, $o)): ?>
                  <form method="post" action="<?= e(url('orders.php?' . $range_q)) ?>" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                    <select name="status" class="status-select <?= strtolower($o['status']) ?>" data-autosubmit aria-label="Order status">
                      <?php foreach (STATUSES as $st): ?><option<?= $st === $o['status'] ? ' selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?>
                    </select>
                  </form>
                <?php else: ?><?= e($o['status']) ?><?php endif; ?>
              </td>
              <td class="order-time"><?= e(format_time($o['created_at'])) ?></td>
              <td><?= e($o['created_name']) ?></td>
              <td><?= $o['assigned_name'] ? e($o['assigned_name']) : '<span class="muted">Unassigned</span>' ?></td>
              <td>
                <div class="row-actions">
                  <a title="View receipt" href="<?= e(url('receipt.php?id=' . (int) $o['id'])) ?>"><?= icon('receipt') ?></a>
                  <?php if ($mine): ?>
                    <a title="Edit order" href="<?= e(url('orders.php?' . $range_q . '&edit=' . (int) $o['id'])) ?>#order-form"><?= icon('edit') ?></a>
                    <a class="danger-icon" title="Delete order" href="<?= e(url('orders.php?' . $range_q . '&delete=' . (int) $o['id'])) ?>"><?= icon('trash') ?></a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="order-cards">
      <?php foreach ($orders as $o): $mine = can_edit_order($me, $o); $total = order_total($o); ?>
        <article class="order-card">
          <div class="order-card-top">
            <div><span class="order-number">Order #<?= (int) $o['id'] ?></span><h3><?= e($o['customer']) ?></h3></div>
            <span class="status-text"><?= e($o['status']) ?></span>
          </div>
          <dl>
            <div><dt>Phone</dt><dd><?= $o['phone'] !== '' ? e($o['phone']) : 'Not provided' ?></dd></div>
            <div><dt>Items</dt><dd><?= e(items_text($o)) ?></dd></div>
            <div><dt>Total</dt><dd><strong><?= $total === null ? '—' : e(money($total)) ?></strong></dd></div>
            <div><dt>Payment</dt><dd><span class="payment-tag <?= strtolower($o['payment_method']) ?>"><?= e($o['payment_method']) ?></span></dd></div>
            <div><dt>Time</dt><dd><?= e(format_time($o['created_at'])) ?></dd></div>
            <div><dt>Assigned to</dt><dd><?= $o['assigned_name'] ? e($o['assigned_name']) : 'Unassigned' ?></dd></div>
          </dl>
          <div class="card-actions">
            <a href="<?= e(url('receipt.php?id=' . (int) $o['id'])) ?>"><?= icon('receipt') ?> Receipt</a>
            <?php if ($mine): ?>
              <a href="<?= e(url('orders.php?' . $range_q . '&edit=' . (int) $o['id'])) ?>#order-form"><?= icon('edit') ?> Edit</a>
              <a class="danger-text" href="<?= e(url('orders.php?' . $range_q . '&delete=' . (int) $o['id'])) ?>"><?= icon('trash') ?> Delete</a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($delete): ?>
  <div class="modal-backdrop">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="delete-title">
      <a class="modal-close" href="<?= e(url('orders.php?' . $range_q)) ?>" aria-label="Close"><?= icon('x') ?></a>
      <div class="danger-mark"><?= icon('trash', 22) ?></div>
      <h2 id="delete-title">Delete order #<?= (int) $delete['id'] ?>?</h2>
      <p>This will permanently remove the order for <strong><?= e($delete['customer']) ?></strong>. This action cannot be undone.</p>
      <form method="post" action="<?= e(url('orders.php?' . $range_q)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $delete['id'] ?>">
        <label class="field"><span class="label">Fresh 6-digit MFA code <small>Required for sensitive actions</small></span>
          <input class="code-input" name="mfa" inputmode="numeric" maxlength="9" placeholder="000000" autocomplete="one-time-code" required></label>
        <div class="modal-actions">
          <a class="button secondary" href="<?= e(url('orders.php?' . $range_q)) ?>">Cancel</a>
          <button class="button danger" type="submit">Delete order</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php app_close();
