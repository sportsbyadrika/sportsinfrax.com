/* Passport photo cropper — requires Cropper.js 1.6.x and Bootstrap 5 */
(function () {
  'use strict';

  const ASPECT_W = 35, ASPECT_H = 45;
  const OUT_W = 413, OUT_H = 531;

  const modalEl       = document.getElementById('cropperModal');
  const cropperImg    = document.getElementById('cropperImage');
  const confirmBtn    = document.getElementById('cropConfirmBtn');
  const hiddenInput   = document.getElementById('croppedPhotoData');
  const photoInput    = document.getElementById('photoInput');
  const photoPreview  = document.getElementById('photoPreview');
  const placeholder   = document.getElementById('photoPlaceholder');

  if (!modalEl || !photoInput || !cropperImg) return;

  const bsModal = new bootstrap.Modal(modalEl);
  let cropper   = null;

  photoInput.addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = function (e) {
      cropperImg.src = e.target.result;
      bsModal.show();
    };
    reader.readAsDataURL(file);
  });

  modalEl.addEventListener('shown.bs.modal', function () {
    if (cropper) { cropper.destroy(); cropper = null; }
    cropper = new Cropper(cropperImg, {
      aspectRatio: ASPECT_W / ASPECT_H,
      viewMode: 1,
      dragMode: 'move',
      autoCropArea: 0.9,
      responsive: true,
      checkOrientation: true,
    });
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    if (cropper) { cropper.destroy(); cropper = null; }
    // Reset file input so user can re-select the same file
    photoInput.value = '';
  });

  confirmBtn.addEventListener('click', function () {
    if (!cropper) return;
    const canvas  = cropper.getCroppedCanvas({ width: OUT_W, height: OUT_H, imageSmoothingQuality: 'high' });
    const dataUrl = canvas.toDataURL('image/jpeg', 0.92);

    if (hiddenInput) hiddenInput.value = dataUrl;

    if (photoPreview) {
      photoPreview.src = dataUrl;
      photoPreview.style.display = 'block';
    }
    if (placeholder) placeholder.style.display = 'none';

    bsModal.hide();
  });
})();
