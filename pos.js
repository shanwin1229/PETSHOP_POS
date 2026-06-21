'use strict';

RBAC.initPage({ allowed: ['Admin', 'Cashier'] });

let products = [];
let customers = [];
let cart = [];
let category = 'All';
let searchQ = '';
let discountType = 'peso';
const $ = id => document.getElementById(id);

function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function mapProduct(p) { return { id:+p.id, name:p.name, sku:p.sku||'', category:p.category||'Others', price:+(p.selling_price ?? p.price ?? 0), stock:+(p.stock_qty ?? p.stock ?? 0) }; }
function fullName(c) { return c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim(); }
async function loadData() {
  const [prodData, custData] = await Promise.all([PawApi.get('products.php?limit=1000'), PawApi.get('customers.php?limit=1000')]);
  products = (prodData.products || []).map(mapProduct).filter(p => p.stock > 0);
  customers = custData.customers || [];
  renderCustomers();
  renderCategories();
  renderProducts();
  renderCart();
}
function renderCustomers() {
  const sel = $('customerSelect');
  if (!sel) return;
  sel.innerHTML = '<option value="">Walk-in customer</option>' + customers.map(c => `<option value="${c.id}">${fullName(c)}</option>`).join('');
}
function renderCategories() {
  const cats = ['All', ...new Set(products.map(p => p.category))];
  const el = $('catPills');
  if (!el) return;
  el.innerHTML = cats.map(c => `<button class="cat-pill ${c === category ? 'active' : ''}" data-cat="${c}">${c}</button>`).join('');
  el.querySelectorAll('.cat-pill').forEach(b => b.addEventListener('click', () => { category = b.dataset.cat; renderCategories(); renderProducts(); }));
}
function renderProducts() {
  const q = searchQ.toLowerCase();
  const list = products.filter(p =>
    (category === 'All' || p.category === category) &&
    (!q || p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q))
  );
  const grid = $('productGrid');
  if (!grid) return;

  if (!list.length) {
    grid.innerHTML = '<div class="no-products"><i class="ti ti-package-off"></i><p>No products found.</p></div>';
    return;
  }

  grid.innerHTML = list.map(p => `
    <button class="prod-card ${p.stock <= 0 ? 'out-of-stock' : ''}" data-id="${p.id}" type="button">
      <div class="prod-card-icon">🐾</div>
      <div class="prod-card-name">${p.name}</div>
      <div class="prod-card-sku">${p.sku || 'No SKU'}</div>
      <div class="prod-card-bottom">
        <span class="prod-card-price">${peso(p.price)}</span>
        <span class="prod-card-stock ${p.stock <= 5 ? 'low' : ''}">${p.stock} left</span>
      </div>
    </button>
  `).join('');

  grid.querySelectorAll('.prod-card').forEach(card => {
    card.addEventListener('click', () => addToCart(Number(card.dataset.id)));
  });
}
function addToCart(id) {
  const p = products.find(x => x.id === id);
  if (!p) return;
  const item = cart.find(x => x.product_id === id);
  if (item) {
    if (item.quantity < p.stock) item.quantity++;
  } else cart.push({ product_id:id, name:p.name, quantity:1, unit_price:p.price, stock:p.stock });
  renderCart();
}
function totals() {
  const subtotal = cart.reduce((s, i) => s + i.quantity * i.unit_price, 0);
  const raw = Number($('discountInput')?.value || 0);
  const discount = discountType === 'percent' ? subtotal * Math.min(raw, 100) / 100 : Math.min(raw, subtotal);
  const total = Math.max(0, subtotal - discount);
  const paid = Number($('paymentInput')?.value || 0);
  return { subtotal, discount, total, paid, change: Math.max(0, paid - total) };
}
function renderCart() {
  const list = $('cartItems');
  if (!list) return;

  if (!cart.length) {
    list.innerHTML = '<div class="cart-empty" id="cartEmpty"><i class="ti ti-shopping-cart-off"></i><p>No items yet.<br>Click a product to add.</p></div>';
  } else {
    list.innerHTML = cart.map(i => `
      <div class="cart-item">
        <div class="cart-item-info">
          <div class="cart-item-name">${i.name}</div>
          <div class="cart-item-price">${peso(i.unit_price)} each</div>
          <div class="qty-ctrl">
            <button class="qty-btn-sm" data-act="dec" data-id="${i.product_id}" type="button">−</button>
            <span class="qty-display">${i.quantity}</span>
            <button class="qty-btn-sm" data-act="inc" data-id="${i.product_id}" type="button">+</button>
          </div>
        </div>
        <div class="cart-item-subtotal">${peso(i.quantity * i.unit_price)}</div>
        <button class="remove-btn" data-id="${i.product_id}" type="button">×</button>
      </div>
    `).join('');
  }

  list.querySelectorAll('[data-act]').forEach(b => {
    b.addEventListener('click', () => changeQty(Number(b.dataset.id), b.dataset.act === 'inc' ? 1 : -1));
  });
  list.querySelectorAll('.remove-btn').forEach(b => {
    b.addEventListener('click', () => { cart = cart.filter(i => i.product_id !== Number(b.dataset.id)); renderCart(); });
  });

  const t = totals();
  $('cartCount') && ($('cartCount').textContent = ` · ${cart.reduce((s,i)=>s+i.quantity,0)} items`);
  $('subtotalAmt') && ($('subtotalAmt').textContent = peso(t.subtotal));
  $('discountAmt') && ($('discountAmt').textContent = '−' + peso(t.discount));
  $('discountRow') && ($('discountRow').style.display = t.discount ? '' : 'none');
  $('grandTotal') && ($('grandTotal').textContent = peso(t.total));
  $('changeAmt') && ($('changeAmt').textContent = peso(t.change));
  $('checkoutBtn') && ($('checkoutBtn').disabled = !cart.length || t.paid < t.total);
}
function changeQty(id, delta) {
  const item = cart.find(i => i.product_id === id);
  if (!item) return;
  item.quantity += delta;
  if (item.quantity <= 0) cart = cart.filter(i => i.product_id !== id);
  if (item.quantity > item.stock) item.quantity = item.stock;
  renderCart();
}
async function checkout() {
  const t = totals();
  if (!cart.length) return;
  if (t.paid < t.total) return alert('Insufficient payment.');
  const payload = {
    customer_id: $('customerSelect')?.value || null,
    items: cart.map(i => ({ product_id: i.product_id, quantity: i.quantity })),
    discount_type: discountType,
    discount_value: Number($('discountInput')?.value || 0),
    amount_paid: t.paid
  };
  const receipt = await PawApi.post('sales.php', payload);
  renderReceipt(receipt);
  $('receiptModal')?.classList.add('open');
  cart = [];
  $('discountInput').value = 0;
  $('paymentInput').value = '';
  await loadData();
}
function renderReceipt(r) {
  $('receiptTxnNo') && ($('receiptTxnNo').textContent = r.txn_no || r.txnNo || 'TXN');
  $('receiptDate') && ($('receiptDate').textContent = r.date || new Date().toLocaleString());
  $('receiptCustomer') && ($('receiptCustomer').textContent = r.customer || 'Walk-in');
  $('receiptItems') && ($('receiptItems').innerHTML = (r.items || []).map(i => `<div class="receipt-line"><span>${i.quantity || i.qty} × ${i.product_name || i.name}</span><span>${peso(i.line_total || i.subtotal)}</span></div>`).join(''));
  $('receiptSubtotal') && ($('receiptSubtotal').textContent = peso(r.subtotal));
  $('receiptDisc') && ($('receiptDisc').textContent = '−' + peso(r.discount_amount || r.discount));
  $('receiptDiscRow') && ($('receiptDiscRow').style.display = (r.discount_amount || r.discount) ? '' : 'none');
  $('receiptTotal') && ($('receiptTotal').textContent = peso(r.total_amount || r.total));
  $('receiptCash') && ($('receiptCash').textContent = peso(r.amount_paid || r.cash || r.paid));
  $('receiptChange') && ($('receiptChange').textContent = peso(r.change_amount || r.change));
}
function bind() {
  $('productSearch')?.addEventListener('input', e => { searchQ = e.target.value.trim(); renderProducts(); });
  $('discountInput')?.addEventListener('input', renderCart);
  $('paymentInput')?.addEventListener('input', renderCart);
  $('discPeso')?.addEventListener('click', () => { discountType = 'peso'; $('discPeso').classList.add('active'); $('discPercent').classList.remove('active'); renderCart(); });
  $('discPercent')?.addEventListener('click', () => { discountType = 'percent'; $('discPercent').classList.add('active'); $('discPeso').classList.remove('active'); renderCart(); });
  $('clearCartBtn')?.addEventListener('click', () => { cart = []; renderCart(); });
  $('checkoutBtn')?.addEventListener('click', () => checkout().catch(e => alert(e.message)));
  $('receiptCloseBtn')?.addEventListener('click', () => $('receiptModal')?.classList.remove('open'));
  $('receiptPrintBtn')?.addEventListener('click', () => window.print());
  $('sidebarToggle')?.addEventListener('click', () => $('sidebar')?.classList.toggle('open'));
  $('logoutBtn')?.addEventListener('click', () => { if (confirm('Log out of PAWPOS?')) RBAC.logout(); });
}
bind();
loadData().catch(e => alert(e.message));
