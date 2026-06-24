<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($pageTitle ?? APP_NAME) ?> | <?= h(APP_NAME) ?></title>
  <meta name="description" content="<?= h(APP_TAGLINE) ?>" />
  <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/app/assets/img/favicon.svg" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <!-- App CSS -->
  <link href="<?= BASE_URL ?>/app/assets/css/app.css" rel="stylesheet" />
  <?php if (!empty($useCropper)): ?>
  <!-- Cropper.js -->
  <link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css" rel="stylesheet" />
  <?php endif; ?>
</head>
<body>
<?php require_once APP_ROOT . '/includes/navbar.php'; ?>
<main class="app-main">
  <div class="container-fluid py-4">
    <!-- Breadcrumb / Page Header -->
    <?php if (!empty($pageTitle)): ?>
    <div class="page-header mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <?php if (!empty($breadcrumbs)): ?>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-1">
            <?php foreach ($breadcrumbs as $label => $url): ?>
              <?php if ($url): ?>
                <li class="breadcrumb-item"><a href="<?= h($url) ?>"><?= h($label) ?></a></li>
              <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page"><?= h($label) ?></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ol>
        </nav>
        <?php endif; ?>
        <h4 class="page-title mb-0"><?= h($pageTitle) ?></h4>
      </div>
      <?php if (!empty($pageAction)): ?>
      <div class="page-actions"><?= $pageAction ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Flash Messages -->
    <?= renderFlash() ?>
