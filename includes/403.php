<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/helpers.php';
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"/>
<title>403 Forbidden</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.rtl.min.css"/>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
<div class="text-center p-5">
  <h1 class="display-1 text-danger">403</h1>
  <h4>Access Denied</h4>
  <p class="text-muted">You do not have permission to perform this action.</p>
  <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
</div>
</body></html>
