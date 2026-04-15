/**
 * SportsInfraX – Application JavaScript
 */

'use strict';

/* ── Auto-dismiss alerts ─────────────────────────────── */
document.querySelectorAll('.alert.alert-dismissible').forEach(el => {
  setTimeout(() => {
    const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
    if (bsAlert) bsAlert.close();
  }, 5000);
});

/* ── Image preview for file inputs ──────────────────── */
document.querySelectorAll('[data-preview]').forEach(input => {
  input.addEventListener('change', function () {
    const target = document.querySelector(this.dataset.preview);
    if (!target || !this.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => { target.src = e.target.result; };
    reader.readAsDataURL(this.files[0]);
  });
});

/* ── Auto-calculate membership end date ─────────────── */
(function () {
  const startDate  = document.getElementById('start_date');
  const duration   = document.getElementById('duration_months');
  const endDate    = document.getElementById('end_date');

  function calcEnd() {
    if (!startDate || !duration || !endDate) return;
    const sd = new Date(startDate.value);
    const d  = parseInt(duration.value, 10);
    if (isNaN(sd.getTime()) || isNaN(d) || d < 1) return;
    const ed = new Date(sd);
    ed.setMonth(ed.getMonth() + d);
    ed.setDate(ed.getDate() - 1);
    endDate.value = ed.toISOString().slice(0, 10);
  }

  if (startDate) startDate.addEventListener('change', calcEnd);
  if (duration)  duration.addEventListener('change',  calcEnd);
  if (startDate && duration) calcEnd();
})();

/* ── Auto-calculate net amount ───────────────────────── */
(function () {
  const amount   = document.getElementById('amount');
  const discount = document.getElementById('discount');
  const net      = document.getElementById('net_amount');

  function calcNet() {
    if (!amount || !net) return;
    const a = parseFloat(amount.value)   || 0;
    const d = parseFloat(discount?.value) || 0;
    net.value = Math.max(0, a - d).toFixed(2);
  }

  if (amount)   amount.addEventListener('input',   calcNet);
  if (discount) discount.addEventListener('input', calcNet);
  if (amount)   calcNet();
})();

/* ── Confirm-before-delete ───────────────────────────── */
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', function (e) {
    if (!confirm(this.dataset.confirm || 'Are you sure?')) {
      e.preventDefault();
    }
  });
});

/* ── Search table filter (client-side) ───────────────── */
const tableSearch = document.getElementById('tableSearch');
if (tableSearch) {
  tableSearch.addEventListener('input', function () {
    const query = this.value.toLowerCase();
    document.querySelectorAll('[data-table-row]').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
  });
}

/* ── Tooltip init ────────────────────────────────────── */
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
  bootstrap.Tooltip.getOrCreateInstance(el);
});
