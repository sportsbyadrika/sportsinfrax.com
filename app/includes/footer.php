  </div><!-- /.container-fluid -->
</main><!-- /.app-main -->

<!-- Footer -->
<footer class="app-footer">
  <div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-semibold"><?= h(APP_NAME) ?></span>
        <span class="text-muted small">&ndash;</span>
        <a href="https://sportsbya.com" target="_blank" rel="noopener"
           class="text-white-50 small text-decoration-none footer-company-link">
          SportsByA Tech (OPC) Private Limited
        </a>
      </div>
      <span class="text-muted small">Digital OS for Sports Institutions</span>
      <span class="text-muted small">&copy; <?= date('Y') ?> All rights reserved.</span>
    </div>
  </div>
</footer>

<!-- Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="<?= BASE_URL ?>/app/assets/js/app.js"></script>
<?php if (!empty($useCropper)): ?>
<!-- Cropper.js -->
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script src="<?= BASE_URL ?>/app/assets/js/photo-cropper.js"></script>
<?php endif; ?>
</body>
</html>
