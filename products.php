<?php
/**
 * products.php - menu items: add, edit, delete, and photos.
 * Owner and staff can manage products. Deleting a product needs a fresh MFA code.
 * Past orders keep their own copy of the item name and price, so history is not changed.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_full();

const PHOTO_MAX = 2 * 1024 * 1024;
const PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/** Saves an uploaded picture. Returns ['name' => file name or null] or ['error' => message]. */
function save_photo(string $field, ?string $old): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['name' => $old];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'The photo did not upload. Try a smaller image.'];
    }
    if ($f['size'] > PHOTO_MAX) {
        return ['error' => 'The photo is too big. Use an image under 2 MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !isset(PHOTO_TYPES[$info[2]])) {
        return ['error' => 'Use a JPG, PNG, or WEBP picture.'];
    }
    $dir = __DIR__ . '/assets/img/products';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'p' . bin2hex(random_bytes(8)) . '.' . PHOTO_TYPES[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return ['error' => 'Could not save the photo. Check the folder permissions.'];
    }
    if ($old && is_file($dir . '/' . $old)) {
        @unlink($dir . '/' . $old);
    }
    return ['name' => $name];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? one('SELECT * FROM products WHERE id = ?', [$id]) : null;
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = (string) ($_POST['category'] ?? '');
        $price_raw = trim((string) ($_POST['price'] ?? ''));

        if ($id > 0 && !$existing) {
            flash('That product no longer exists.', 'error');
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            flash('Product name must be 2 to 60 characters.', 'error');
        } elseif (!array_key_exists($category, MENU_CATEGORIES)) {
            flash('Choose a category.', 'error');
        } elseif ($price_raw !== '' && (!ctype_digit($price_raw) || (int) $price_raw < 1 || (int) $price_raw > 99999)) {
            flash('Price must be a whole number of pesos (1 to 99,999), or left blank if not set yet.', 'error');
        } elseif (one('SELECT id FROM products WHERE name = ? AND id <> ?', [$name, $existing['id'] ?? 0])) {
            flash('That product is already on the menu.', 'error');
        } else {
            $photo = save_photo('photo', $existing['image'] ?? null);
            if (isset($photo['error'])) {
                flash($photo['error'], 'error');
            } else {
                $price = $price_raw === '' ? null : (int) $price_raw;
                if ($existing) {
                    run('UPDATE products SET category = ?, name = ?, price = ?, image = ?, updated_at = ? WHERE id = ?',
                        [$category, $name, $price, $photo['name'], date('Y-m-d H:i:s'), $existing['id']]);
                    audit($me['username'], 'product_updated', $name);
                    flash($name . ' updated.');
                } else {
                    run('INSERT INTO products (category, name, price, image, created_at) VALUES (?,?,?,?,?)',
                        [$category, $name, $price, $photo['name'], date('Y-m-d H:i:s')]);
                    audit($me['username'], 'product_created', $name);
                    flash($name . ' added to the menu.');
                }
            }
        }
        redirect('products.php');
    }

    if ($action === 'delete') {
        $p = one('SELECT * FROM products WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if (!$p) {
            flash('That product no longer exists.', 'error');
        } elseif (!step_up_ok($me, (string) ($_POST['mfa'] ?? ''), 'product delete ' . $p['name'])) {
            flash('Enter a valid fresh MFA code to delete this product.', 'error');
        } else {
            run('DELETE FROM products WHERE id = ?', [$p['id']]);
            if ($p['image'] && is_file(__DIR__ . '/assets/img/products/' . $p['image'])) {
                @unlink(__DIR__ . '/assets/img/products/' . $p['image']);
            }
            audit($me['username'], 'product_deleted', $p['name']);
            flash($p['name'] . ' removed from the menu.');
        }
        redirect('products.php');
    }
}

$filter = (string) ($_GET['cat'] ?? 'all');
if ($filter !== 'all' && !array_key_exists($filter, MENU_CATEGORIES)) {
    $filter = 'all';
}
$editing = null;
if (isset($_GET['edit'])) {
    $editing = one('SELECT * FROM products WHERE id = ?', [(int) $_GET['edit']]);
}
$products = array_values(array_filter(menu_all(), fn($p) => $filter === 'all' || $p['category'] === $filter));
$v = $editing ?? ['name' => '', 'category' => 'drink', 'price' => null, 'image' => null, 'id' => 0];

app_open('Products', 'products', $me);
echo flash_banner();
?>
<div class="page-header">
  <div><div class="eyebrow">Menu</div><h1>Products</h1><p>Add, edit, or remove what the stall sells, and add a picture for each item.</p></div>
</div>

<section class="panel settings-card product-form-panel" id="product-form">
  <h2><?= $editing ? 'Edit ' . e($editing['name']) : 'Add a product' ?></h2>
  <form method="post" action="<?= e(url('products.php')) ?>" enctype="multipart/form-data" class="product-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="product-form-grid">
      <label class="field"><span class="label">Product name</span>
        <input name="name" value="<?= e($v['name']) ?>" required minlength="2" maxlength="60" placeholder="e.g. Classic Lemon"></label>
      <label class="field"><span class="label">Category</span>
        <select name="category">
          <?php foreach (MENU_CATEGORIES as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $key === $v['category'] ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span class="label">Price <small>Leave blank if not set yet</small></span>
        <span class="price-wrap"><span>₱</span><input name="price" inputmode="numeric" value="<?= $v['price'] !== null ? (int) round((float) $v['price']) : '' ?>" placeholder="65" maxlength="5"></span></label>
      <label class="field"><span class="label">Picture <small>JPG, PNG, or WEBP, up to 2 MB</small></span>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
    </div>
    <div class="product-form-foot">
      <div class="photo-preview">
        <?php if (!empty($v['image']) && is_file(__DIR__ . '/assets/img/products/' . $v['image'])): ?>
          <img src="<?= e(url('assets/img/products/' . $v['image'])) ?>" alt="">
        <?php else: ?>
          <span class="photo-fallback"><?= e(mb_substr($v['name'] !== '' ? $v['name'] : 'P', 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="settings-actions">
        <?php if ($editing): ?><a class="button secondary" href="<?= e(url('products.php')) ?>">Cancel</a><?php endif; ?>
        <button class="button primary" type="submit"><?= $editing ? 'Save changes' : icon('plus') . ' Add product' ?></button>
      </div>
    </div>
  </form>
</section>

<section class="panel table-panel">
  <div class="table-title history-title">
    <div><h2>Menu items</h2><span><?= count($products) ?> products</span></div>
    <nav class="segmented" aria-label="Filter by category">
      <a href="<?= e(url('products.php')) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
      <?php foreach (MENU_CATEGORIES as $key => $label): ?>
        <a href="<?= e(url('products.php?cat=' . $key)) ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="table-scroll">
    <table class="products-table">
      <thead><tr><th>Photo</th><th>Name</th><th>Category</th><th>Price</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><div class="product-thumb"><?= menu_photo($p) ?></div></td>
            <td><strong><?= e($p['name']) ?></strong></td>
            <td><span class="cat-tag <?= e($p['category']) ?>"><?= e(MENU_CATEGORIES[$p['category']]) ?></span></td>
            <td><?= $p['price'] === null ? '<span class="muted">Price needed</span>' : e(money_text((float) $p['price'])) ?></td>
            <td>
              <div class="product-actions">
                <a class="button small secondary" href="<?= e(url('products.php?edit=' . (int) $p['id'] . '#product-form')) ?>"><?= icon('edit', 15) ?> Edit</a>
                <form method="post" action="<?= e(url('products.php')) ?>" class="action-form" data-confirm="Remove <?= e($p['name']) ?> from the menu?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <input name="mfa" inputmode="numeric" maxlength="9" placeholder="MFA code" required autocomplete="one-time-code">
                  <button class="button small danger" type="submit"><?= icon('trash', 15) ?> Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?>
          <tr><td colspan="5"><div class="empty"><h3>No products here yet</h3><p>Add the first one using the form above.</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php app_close();
