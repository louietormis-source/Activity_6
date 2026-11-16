const apiUrl = window.location.pathname;
const productForm = document.getElementById("productForm");
const searchInput = document.getElementById("searchInput");
const productList = document.getElementById("productList");
const productCount = document.getElementById("productCount");
const statusMessage = document.getElementById("status");
const themeToggle = document.getElementById("themeToggle");
const productFormTitle = document.getElementById("productFormTitle");
const productIdInput = document.getElementById("productId");
const productImageInput = document.getElementById("productImage");
const imageHelp = document.getElementById("imageHelp");
const submitButton = document.getElementById("submitButton");
const cancelEditButton = document.getElementById("cancelEditButton");
let searchRequest = 0;
let editingProductId = null;

function setTheme(theme) {
  document.body.dataset.theme = theme;
  const nextTheme = theme === "dark" ? "light" : "dark";
  themeToggle.textContent = `Switch to ${nextTheme} mode`;
  themeToggle.setAttribute("aria-label", `Switch to ${nextTheme} mode`);
  localStorage.setItem("inventory-theme", theme);
}

setTheme(localStorage.getItem("inventory-theme") === "light" ? "light" : "dark");
themeToggle.addEventListener("click", () => {
  setTheme(document.body.dataset.theme === "dark" ? "light" : "dark");
});

function showStatus(message, isError = false) {
  statusMessage.textContent = message;
  statusMessage.classList.toggle("text-rose-400", isError);
  statusMessage.classList.toggle("text-slate-400", !isError);
}

function resetProductForm() {
  productForm.reset();
  editingProductId = null;
  productIdInput.value = "";
  productImageInput.required = true;
  productFormTitle.textContent = "Add a product";
  imageHelp.textContent = "JPG, PNG, WEBP, or GIF. Maximum size: 5 MB.";
  submitButton.textContent = "Add product";
  cancelEditButton.hidden = true;
}

function startEditing(product) {
  editingProductId = String(product.id);
  productIdInput.value = String(product.id);
  document.getElementById("productName").value = product.name;
  document.getElementById("productSku").value = product.sku;
  document.getElementById("productPrice").value = product.price;
  document.getElementById("productQuantity").value = product.quantity;
  productImageInput.value = "";
  productImageInput.required = false;
  productFormTitle.textContent = "Edit product";
  imageHelp.textContent = "Leave empty to keep the current image, or choose a replacement (maximum 5 MB).";
  submitButton.textContent = "Save changes";
  cancelEditButton.hidden = false;
  productForm.scrollIntoView({ behavior: "smooth", block: "center" });
}

function renderProducts(products, total, query) {
  if (query) {
    productCount.textContent = `${products.length} matching ${products.length === 1 ? "product" : "products"}`;
  } else {
    productCount.textContent = `${total} ${total === 1 ? "product" : "products"}`;
  }
  productList.replaceChildren();

  if (products.length === 0) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "px-3 py-8 text-center text-slate-400";
    cell.textContent = query ? "No products match your search." : "No products yet. Add one to get started.";
    row.append(cell);
    productList.append(row);
    return;
  }

  for (const product of products) {
    const row = document.createElement("tr");
    const imageCell = document.createElement("td");
    const nameCell = document.createElement("td");
    const skuCell = document.createElement("td");
    const priceCell = document.createElement("td");
    const quantityCell = document.createElement("td");
    const actionsCell = document.createElement("td");
    const image = document.createElement("img");
    const quantity = Number(product.quantity);
    const quantityValue = document.createElement("span");
    const stockBadge = document.createElement("span");
    const stockStatus = quantity === 0
      ? { label: "Out of stock", color: "bg-rose-500/10 text-rose-300", key: "out" }
      : quantity <= 5
        ? { label: "Low stock", color: "bg-amber-500/10 text-amber-300", key: "low" }
        : { label: "In stock", color: "bg-emerald-500/10 text-emerald-300", key: "in" };

    image.src = product.image;
    image.alt = product.name;
    image.width = 48;
    image.height = 48;
    imageCell.className = "px-1 py-4 sm:px-3";
    image.className = "h-9 w-9 rounded-xl border border-slate-700 object-cover sm:h-12 sm:w-12";
    imageCell.append(image);
    nameCell.className = "break-words px-1 py-4 font-semibold text-white sm:px-3";
    skuCell.className = "break-all px-1 py-4 text-center font-medium text-slate-300 sm:px-3";
    priceCell.className = "break-all px-1 py-4 text-right font-medium text-slate-200 sm:px-3";
    quantityCell.className = "px-1 py-4 text-right text-slate-300 sm:px-3";
    nameCell.textContent = product.name;
    skuCell.textContent = product.sku;
    priceCell.textContent = `₱${Number(product.price).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
    quantityValue.textContent = String(quantity);
    stockBadge.className = `mt-1 inline-flex max-w-full whitespace-normal break-words rounded-full px-2 py-1 text-[10px] font-semibold leading-tight ${stockStatus.color}`;
    stockBadge.textContent = stockStatus.label;
    stockBadge.dataset.stockStatus = stockStatus.key;
    quantityCell.append(quantityValue, document.createElement("br"), stockBadge);

    const actions = document.createElement("div");
    const editButton = document.createElement("button");
    const deleteButton = document.createElement("button");
    actions.className = "flex flex-col gap-1";
    actionsCell.className = "px-1 py-3 sm:px-2";
    editButton.type = "button";
    editButton.textContent = "Edit";
    editButton.setAttribute("aria-label", `Edit ${product.name}`);
    editButton.className = "rounded-lg bg-emerald-600 px-1 py-1.5 text-[10px] font-semibold text-white hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-300";
    editButton.addEventListener("click", () => startEditing(product));
    deleteButton.type = "button";
    deleteButton.textContent = "Delete";
    deleteButton.setAttribute("aria-label", `Delete ${product.name}`);
    deleteButton.className = "rounded-lg bg-rose-600 px-1 py-1.5 text-[10px] font-semibold text-white hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-300";
    deleteButton.addEventListener("click", async () => {
      if (!window.confirm(`Delete "${product.name}"? This cannot be undone.`)) {
        return;
      }

      deleteButton.disabled = true;
      try {
        const formData = new FormData();
        formData.append("productId", String(product.id));
        const response = await fetch(`${apiUrl}?action=delete`, {
          method: "POST",
          body: formData,
        });
        const data = await response.json();
        if (!response.ok) {
          throw new Error(data.error || `Could not delete product (${response.status}).`);
        }

        if (editingProductId === String(product.id)) {
          resetProductForm();
        }
        showStatus(data.warning || `${product.name} deleted.`, Boolean(data.warning));
        await loadProducts();
      } catch (error) {
        showStatus(error.message, true);
      } finally {
        deleteButton.disabled = false;
      }
    });
    actions.append(editButton, deleteButton);
    actionsCell.append(actions);

    row.append(imageCell, nameCell, skuCell, priceCell, quantityCell, actionsCell);
    productList.append(row);
  }
}

async function loadProducts() {
  const requestId = ++searchRequest;
  const query = searchInput.value.trim();

  try {
    const response = await fetch(`${apiUrl}?action=search&q=${encodeURIComponent(query)}`);
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `Search failed (${response.status}).`);
    }
    if (requestId === searchRequest) {
      renderProducts(data.products, data.total, query);
    }
  } catch (error) {
    if (requestId === searchRequest) {
      showStatus(`Could not load products: ${error.message}`, true);
    }
  }
}

cancelEditButton.addEventListener("click", () => {
  resetProductForm();
  showStatus("Edit cancelled.");
});

productForm.addEventListener("submit", async event => {
  event.preventDefault();
  const isEditing = editingProductId !== null;
  const action = isEditing ? "update" : "add";
  submitButton.disabled = true;
  submitButton.textContent = isEditing ? "Saving..." : "Adding...";

  try {
    const response = await fetch(`${apiUrl}?action=${action}`, {
      method: "POST",
      body: new FormData(productForm),
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `Could not save product (${response.status}).`);
    }

    resetProductForm();
    showStatus(data.warning || `${data.product.name} ${isEditing ? "updated" : "added"}.`, Boolean(data.warning));
    await loadProducts();
  } catch (error) {
    showStatus(error.message, true);
  } finally {
    submitButton.disabled = false;
    submitButton.textContent = editingProductId === null ? "Add product" : "Save changes";
  }
});

searchInput.addEventListener("input", loadProducts);
loadProducts();