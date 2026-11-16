<?php
declare(strict_types=1);

$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (isset($_GET['action'])) {
    try {
        $action = (string) $_GET['action'];
        $database = require __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

        if ($action === 'search') {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                header('Allow: GET');
                respond(405, ['error' => 'Use GET to search products.']);
            }

            $query = trim((string) ($_GET['q'] ?? ''));
            $statement = $database->prepare(
                'SELECT id, name, sku, price, quantity, image
                 FROM products
                 WHERE :empty_query = \'\' OR sku = :sku_query OR name LIKE :name_query
                 ORDER BY id DESC'
            );
            $statement->execute([
                'empty_query' => $query,
                'sku_query' => $query,
                'name_query' => '%' . $query . '%',
            ]);
            $matches = $statement->fetchAll();
            $total = (int) $database->query('SELECT COUNT(*) FROM products')->fetchColumn();

            respond(200, [
                'products' => $matches,
                'total' => $total,
            ]);
        }

        if ($action === 'delete') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Allow: POST');
                respond(405, ['error' => 'Use POST to delete a product.']);
            }

            $productId = filter_var($_POST['productId'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($productId === false) {
                respond(400, ['error' => 'Choose a valid product to delete.']);
            }

            $statement = $database->prepare('SELECT name, image FROM products WHERE id = :id');
            $statement->execute(['id' => $productId]);
            $product = $statement->fetch();
            if ($product === false) {
                respond(404, ['error' => 'The product was not found.']);
            }

            $statement = $database->prepare('DELETE FROM products WHERE id = :id');
            $statement->execute(['id' => $productId]);

            $warning = null;
            $storedImage = (string) $product['image'];
            $imageName = basename($storedImage);
            $imagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;
            if ($storedImage === 'uploads/' . $imageName && $imageName !== ''
                && is_file($imagePath) && !@unlink($imagePath)) {
                $warning = 'The product was deleted, but its image could not be removed.';
                error_log('Could not remove product image: ' . $imagePath);
            }

            respond(200, [
                'message' => $product['name'] . ' deleted.',
                'warning' => $warning,
            ]);
        }

        if ($action !== 'add' && $action !== 'update') {
            respond(404, ['error' => 'Unknown inventory action.']);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            respond(405, ['error' => 'Use POST to save a product.']);
        }

        $existingProduct = null;
        $productId = null;
        if ($action === 'update') {
            $productId = filter_var($_POST['productId'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($productId === false) {
                respond(400, ['error' => 'Choose a valid product to update.']);
            }

            $statement = $database->prepare('SELECT image FROM products WHERE id = :id');
            $statement->execute(['id' => $productId]);
            $existingProduct = $statement->fetch();
            if ($existingProduct === false) {
                respond(404, ['error' => 'The product was not found.']);
            }
        }

        $name = trim((string) ($_POST['productName'] ?? ''));
        $sku = trim((string) ($_POST['productSku'] ?? ''));
        $priceInput = $_POST['productPrice'] ?? null;
        $quantityInput = $_POST['productQuantity'] ?? null;

        if ($name === '' || strlen($name) > 100 || $sku === '' || strlen($sku) > 40) {
            respond(400, ['error' => 'Enter a product name (up to 100 characters) and SKU (up to 40 characters).']);
        }
        if (!is_string($priceInput) || !is_numeric($priceInput)
            || !is_finite((float) $priceInput) || (float) $priceInput < 0) {
            respond(400, ['error' => 'Enter a valid, non-negative price.']);
        }
        $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            respond(400, ['error' => 'Enter a non-negative whole-number quantity.']);
        }

        $image = $_FILES['productImage'] ?? null;
        $hasNewImage = false;
        $newImagePath = null;
        if (!is_array($image) || ($image['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($action === 'add') {
                respond(400, ['error' => 'Choose an image for this product.']);
            }
        } else {
            if (($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !isset($image['tmp_name'])
                || !is_string($image['tmp_name'])
                || !is_uploaded_file($image['tmp_name'])) {
                respond(400, ['error' => 'The image upload failed. Please try again.']);
            }
            if (($image['size'] ?? 0) > 5 * 1024 * 1024) {
                respond(400, ['error' => 'The image must be 5 MB or smaller.']);
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($image['tmp_name']);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($extensions[$mimeType]) || @getimagesize($image['tmp_name']) === false) {
                respond(400, ['error' => 'Upload a valid JPG, PNG, WEBP, or GIF image.']);
            }

            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException('Could not create the image upload directory.');
            }

            $imageName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
            $newImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;
            if (!move_uploaded_file($image['tmp_name'], $newImagePath)) {
                throw new RuntimeException('Could not save the uploaded product image.');
            }
            $hasNewImage = true;
        }

        $product = [
            'name' => $name,
            'sku' => $sku,
            'price' => (float) $priceInput,
            'quantity' => $quantity,
            'image' => $hasNewImage ? 'uploads/' . $imageName : ($existingProduct['image'] ?? ''),
        ];

        try {
            if ($action === 'add') {
                $statement = $database->prepare(
                    'INSERT INTO products (name, sku, price, quantity, image)
                     VALUES (:name, :sku, :price, :quantity, :image)'
                );
                $statement->execute($product);
                $product['id'] = (int) $database->lastInsertId();
            } else {
                $product['id'] = $productId;
                $statement = $database->prepare(
                    'UPDATE products
                     SET name = :name, sku = :sku, price = :price, quantity = :quantity, image = :image
                     WHERE id = :id'
                );
                $statement->execute($product);
            }
        } catch (Throwable $error) {
            if ($newImagePath !== null && is_file($newImagePath) && !@unlink($newImagePath)) {
                error_log('Could not remove uncommitted product image: ' . $newImagePath);
            }
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                respond(409, ['error' => 'A product with this SKU already exists.']);
            }
            throw $error;
        }

        $warning = null;
        if ($action === 'update' && $hasNewImage) {
            $storedImage = (string) $existingProduct['image'];
            $oldImageName = basename($storedImage);
            $oldImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $oldImageName;
            if ($storedImage === 'uploads/' . $oldImageName && $oldImageName !== ''
                && is_file($oldImagePath) && !@unlink($oldImagePath)) {
                $warning = 'Product updated, but its previous image could not be removed.';
                error_log('Could not remove previous product image: ' . $oldImagePath);
            }
        }

        respond($action === 'add' ? 201 : 200, [
            'product' => $product,
            'warning' => $warning,
        ]);
    } catch (Throwable $error) {
        error_log($error->getMessage());
        respond(500, ['error' => 'The inventory request failed. Check the PHP server logs.']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body[data-theme="light"] {
      background-color: #f4f7fb;
      color: #0f172a;
    }

    .page-header::after {
      position: absolute;
      top: -8rem;
      right: -4rem;
      width: 20rem;
      height: 20rem;
      border-radius: 9999px;
      background: rgba(16, 185, 129, 0.12);
      content: "";
      pointer-events: none;
    }

    body[data-theme="light"] .page-header {
      border-color: #dbeafe;
      background: linear-gradient(125deg, #0f172a, #172554 72%, #064e3b);
    }

    body[data-theme="light"] .page-header #themeToggle {
      border-color: rgba(255, 255, 255, 0.25);
      background-color: rgba(255, 255, 255, 0.12);
      color: #fff;
    }

    body[data-theme="light"] .page-header .text-white:not(button) {
      color: #fff;
    }

    body[data-theme="light"] .page-header .text-slate-300 {
      color: #cbd5e1;
    }

    body[data-theme="light"] .bg-slate-900 {
      background-color: #fff;
    }

    body[data-theme="light"] .bg-slate-800 {
      background-color: #e2e8f0;
    }

    body[data-theme="light"] .border-slate-800 {
      border-color: #e2e8f0;
    }

    body[data-theme="light"] .border-slate-700 {
      border-color: #cbd5e1;
    }

    body[data-theme="light"] .text-slate-100,
    body[data-theme="light"] .text-slate-200,
    body[data-theme="light"] .text-slate-300,
    body[data-theme="light"] .text-slate-400,
    body[data-theme="light"] .text-slate-500,
    body[data-theme="light"] .text-white:not(button) {
      color: #0f172a;
    }

    body[data-theme="light"] input {
      background-color: #fff;
      border-color: #cbd5e1;
      color: #0f172a;
    }

    body[data-theme="light"] input::placeholder {
      color: #64748b;
    }

    body[data-theme="light"] input[type="file"]::file-selector-button {
      background-color: #e2e8f0;
      color: #0f172a;
    }

    .table-heading {
      background-color: rgba(30, 41, 59, 0.55);
    }

    body[data-theme="light"] .table-heading {
      background-color: #f1f5f9;
    }

    body[data-theme="light"] [data-stock-status="low"] {
      border: 1px solid #eab308;
      background-color: #fef3c7;
      color: #854d0e;
    }

    #productList tr {
      transition: background-color 150ms ease;
    }

    #productList tr:hover {
      background-color: rgba(51, 65, 85, 0.2);
    }

    body[data-theme="light"] #productList tr:hover {
      background-color: #f8fafc;
    }
  </style>
</head>
<body data-theme="dark" class="min-h-screen bg-slate-950 text-slate-100 antialiased">
  <main class="w-full px-3 py-8 sm:px-6 lg:px-10 lg:py-12 2xl:px-16">
    <header class="page-header relative mb-8 flex flex-col gap-6 overflow-hidden rounded-3xl border border-slate-800 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950 p-6 shadow-2xl shadow-black/20 sm:flex-row sm:items-center sm:justify-between sm:p-8 lg:p-10">
      <div class="relative z-10">
        <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-300">
          <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
          Inventory workspace
        </p>
        <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Product inventory</h1>
        <p class="mt-2 max-w-xl text-sm text-slate-300 sm:text-base">Keep your catalog organized, monitor stock, and update product details in one place.</p>
      </div>
      <button id="themeToggle" type="button" aria-label="Switch to light mode"
        class="relative z-10 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-emerald-300">
        Switch to light mode
      </button>
    </header>

    <div class="grid gap-8 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,2fr)]">
      <section class="h-fit rounded-3xl border border-slate-800 bg-slate-900 p-5 shadow-xl shadow-black/20 sm:p-7 xl:p-8">
        <div class="mb-7 flex items-center gap-4">
          <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/15 text-sm font-bold text-emerald-300">01</span>
          <div>
            <h2 id="productFormTitle" class="text-xl font-semibold text-white">Add a product</h2>
            <p class="mt-1 text-sm text-slate-400">Create or update an inventory item.</p>
          </div>
        </div>
        <form id="productForm" class="mt-6 space-y-5" enctype="multipart/form-data">
          <input id="productId" name="productId" type="hidden">
          <div>
            <label for="productName" class="mb-2 block text-sm font-medium text-slate-200">Product name</label>
            <input id="productName" name="productName" type="text" required maxlength="100" placeholder="e.g. Wireless mouse"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
          </div>
          <div>
            <label for="productSku" class="mb-2 block text-sm font-medium text-slate-200">SKU</label>
            <input id="productSku" name="productSku" type="text" required maxlength="40" placeholder="e.g. MOU-001"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="productPrice" class="mb-2 block text-sm font-medium text-slate-200">Price</label>
              <input id="productPrice" name="productPrice" type="number" min="0" step="0.01" required placeholder="0.00"
                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
            </div>
            <div>
              <label for="productQuantity" class="mb-2 block text-sm font-medium text-slate-200">Quantity</label>
              <input id="productQuantity" name="productQuantity" type="number" min="0" step="1" required placeholder="0"
                class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
            </div>
          </div>
          <div>
            <label for="productImage" class="mb-2 block text-sm font-medium text-slate-200">Product image</label>
            <input id="productImage" name="productImage" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required
              class="block w-full rounded-xl border border-dashed border-slate-700 bg-slate-950 p-3 text-sm text-slate-400 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-700 file:px-4 file:py-2 file:font-medium file:text-white hover:file:bg-slate-600">
            <p id="imageHelp" class="mt-2 text-xs text-slate-500">JPG, PNG, WEBP, or GIF. Maximum size: 5 MB.</p>
          </div>
          <div class="space-y-3">
            <button id="submitButton" type="submit"
              class="w-full rounded-xl bg-emerald-500 px-4 py-3 font-semibold text-white shadow-lg shadow-emerald-950/20 transition hover:bg-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2 focus:ring-offset-slate-900">
              Add product
            </button>
            <button id="cancelEditButton" type="button" hidden
              class="w-full rounded-xl border border-slate-700 px-4 py-3 font-semibold text-slate-200 transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-300">
              Cancel edit
            </button>
          </div>
        </form>
        <p id="status" role="status" aria-live="polite" class="mt-4 min-h-6 text-sm text-slate-400"></p>
      </section>

      <section class="rounded-3xl border border-slate-800 bg-slate-900 p-5 shadow-xl shadow-black/20 sm:p-7 xl:p-8">
        <div class="flex flex-col gap-5 border-b border-slate-800 pb-5 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-400">Catalog</p>
            <h2 class="text-2xl font-semibold tracking-tight text-white">Products</h2>
            <p id="productCount" class="mt-1 text-sm text-slate-400">Loading products...</p>
          </div>
          <div class="w-full sm:max-w-xs">
            <label for="searchInput" class="mb-2 block text-sm font-medium text-slate-200">Search by SKU or product name</label>
            <input id="searchInput" type="search" placeholder="Enter SKU or product name"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
          </div>
        </div>

        <div class="mt-6 w-full">
          <table class="w-full table-fixed text-left text-sm">
            <thead class="table-heading border-b border-slate-700 text-xs uppercase tracking-wide text-slate-400">
              <tr>
                <th scope="col" class="w-[12%] px-1 py-3 font-medium sm:px-3">Image</th>
                <th scope="col" class="w-[28%] px-1 py-3 font-medium sm:px-3">Product</th>
                <th scope="col" class="w-[15%] px-1 py-3 text-center font-medium sm:px-3">SKU</th>
                <th scope="col" class="w-[17%] px-1 py-3 text-right font-medium sm:px-3">Price</th>
                <th scope="col" class="w-[13%] px-1 py-3 text-right text-[10px] font-medium leading-tight sm:px-3 sm:text-xs">Qty / stock</th>
                <th scope="col" class="w-[15%] break-words px-1 py-3 text-center text-[10px] font-medium leading-tight sm:px-3 sm:text-xs">Actions</th>
              </tr>
            </thead>
            <tbody id="productList" class="divide-y divide-slate-800"></tbody>
          </table>
        </div>
      </section>
    </div>
  </main>

  <script src="script.js"></script>
</body>
</html>