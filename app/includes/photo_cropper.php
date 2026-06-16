<?php /* Passport Photo Cropper — include INSIDE the <form> tag */ ?>
<input type="hidden" name="cropped_photo_data" id="croppedPhotoData">

<!-- Cropper Modal -->
<div class="modal fade" id="cropperModal" tabindex="-1"
     aria-labelledby="cropperModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cropperModalLabel">
          <i class="bi bi-crop me-2 text-primary"></i>Crop Passport Photo
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-center bg-light rounded p-3"
             style="min-height:280px;max-height:460px;overflow:hidden;">
          <img id="cropperImage" src="" alt="Crop preview"
               style="max-width:100%;display:block;margin:0 auto;">
        </div>
        <p class="text-muted small text-center mt-2 mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Drag to reposition &middot; Scroll / pinch to zoom &middot; Passport ratio 35&times;45 mm
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="cropConfirmBtn">
          <i class="bi bi-check2-circle me-1"></i>Use This Crop
        </button>
      </div>
    </div>
  </div>
</div>
