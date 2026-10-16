import { deleteProduct, listProducts, saveProduct } from "./data.js";
import { setEmptyRow, setFormBusy, showNotice } from "./feedback.js";

let products = [];

function textCell(row, value) {
  const cell = document.createElement("td");
  cell.textContent = value ?? "";
  row.append(cell);
}

async function loadProducts() {
  const body = document.getElementById("productRows");
  setEmptyRow(body, 6, "Loading products...");
  try {
    products = await listProducts();
    const lowStock = products.filter((product) => product.min_stock > 0 && product.stock <= product.min_stock).length;
    document.getElementById("lowStockCount").textContent = String(lowStock);
    if (!products.length) {
      setEmptyRow(body, 6, "No products have been added yet.");
      return;
    }
    const rows = products.map((product) => {
      const row = document.createElement("tr");
      const inputs = {};
      for (const [field, value, type] of [
        ["name", product.name, "text"],
        ["sku", product.sku, "text"],
        ["price", product.price, "number"],
        ["stock", product.stock, "number"],
        ["min_stock", product.min_stock, "number"],
      ]) {
        const cell = document.createElement("td");
        const input = document.createElement("input");
        input.className = "form-control form-control-sm";
        input.name = field;
        input.type = type;
        input.value = value ?? "";
        if (field === "price") input.step = "0.01";
        if (field === "stock" || field === "min_stock") input.step = "1";
        if (type === "number") input.min = "0";
        input.required = field !== "sku";
        inputs[field] = input;
        cell.append(input);
        row.append(cell);
      }
      const actions = document.createElement("td");
      actions.className = "d-flex gap-1";
      const save = document.createElement("button");
      save.type = "button";
      save.className = "btn btn-sm btn-outline-primary";
      save.textContent = "Save";
      save.addEventListener("click", async () => {
        if (Object.values(inputs).some((input) => !input.reportValidity())) return;
        save.disabled = true;
        try {
          await saveProduct({
            name: inputs.name.value.trim(),
            sku: inputs.sku.value.trim() || null,
            price: Number(inputs.price.value),
            stock: Number(inputs.stock.value),
            min_stock: Number(inputs.min_stock.value),
          }, product.id);
          showNotice("Product updated.");
          await loadProducts();
        } catch (error) {
          showNotice(error.message || "Unable to update product.", "danger");
        } finally {
          save.disabled = false;
        }
      });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn btn-sm btn-outline-danger";
      remove.textContent = "Delete";
      remove.addEventListener("click", async () => {
        if (!window.confirm(`Delete ${product.name}?`)) return;
        remove.disabled = true;
        try {
          await deleteProduct(product.id);
          showNotice("Product deleted.");
          await loadProducts();
        } catch (error) {
          showNotice(error.message || "Unable to delete product.", "danger");
        } finally {
          remove.disabled = false;
        }
      });
      actions.append(save, remove);
      row.append(actions);
      return row;
    });
    body.replaceChildren(...rows);
  } catch (error) {
    setEmptyRow(body, 6, "Unable to load products.");
    showNotice(error.message || "Unable to load products.", "danger");
  }
}

export async function initProducts() {
  const form = document.getElementById("productForm");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setFormBusy(form, true);
    try {
      const values = Object.fromEntries(new FormData(form));
      await saveProduct({
        name: values.name.trim(),
        sku: values.sku.trim() || null,
        price: Number(values.price),
        stock: Number(values.stock),
        min_stock: Number(values.min_stock),
      });
      form.reset();
      document.getElementById("productStock").value = "0";
      document.getElementById("productMinStock").value = "0";
      showNotice("Product added.");
      await loadProducts();
    } catch (error) {
      showNotice(error.message || "Unable to add product.", "danger");
    } finally {
      setFormBusy(form, false);
    }
  });
  await loadProducts();
}