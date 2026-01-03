<?php
// add-coupon.php
// Single-file create + edit coupon
// Schema:
// id INT(11) PRIMARY KEY AUTO_INCREMENT,
// code VARCHAR(100) NOT NULL UNIQUE,
// discount_type ENUM('percent','fixed') NOT NULL,
// discount_value DECIMAL(10,2) NOT NULL,
// min_order_value DECIMAL(12,2) DEFAULT 0.00,
// usage_limit INT(11) NULL,
// used_count INT(11) DEFAULT 0,
// starts_at TIMESTAMP NULL,
// expires_at TIMESTAMP NULL,
// is_active TINYINT(1) DEFAULT 1,
// created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

// ----- EDIT THESE DB CREDENTIALS ----- 
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';
// -------------------------------------

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  die("DB connect error: " . $conn->connect_error);
}

/* init */
$success = "";
$error = "";
$insert_id = null;

// Utility: safe output for HTML
function e($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Utility: format datetime-local value for HTML input
function formatDateTimeForInput($timestamp)
{
  if (!$timestamp || $timestamp == '0000-00-00 00:00:00' || $timestamp == 'NULL') {
    return '';
  }
  return date('Y-m-d\TH:i', strtotime($timestamp));
}

// ---- EDIT MODE: if id provided on GET, load values to prefill ----
$edit_mode = false;
$edit_id = 0;
$prefill_code = '';
$prefill_discount_type = 'percent';
$prefill_discount_value = '';
$prefill_min_order_value = '0.00';
$prefill_usage_limit = '';
$prefill_starts_at = '';
$prefill_expires_at = '';
$prefill_is_active = '1';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($id > 0) {
    $stmt = $conn->prepare("SELECT id, code, discount_type, discount_value, min_order_value, usage_limit, used_count, starts_at, expires_at, is_active FROM coupon_codes WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $row = $res->fetch_assoc()) {
        $edit_mode = true;
        $edit_id = (int)$row['id'];
        $prefill_code = $row['code'];
        $prefill_discount_type = $row['discount_type'];
        $prefill_discount_value = $row['discount_value'];
        $prefill_min_order_value = $row['min_order_value'];
        $prefill_usage_limit = $row['usage_limit'];
        $prefill_starts_at = formatDateTimeForInput($row['starts_at']);
        $prefill_expires_at = formatDateTimeForInput($row['expires_at']);
        $prefill_is_active = $row['is_active'];
      }
      $stmt->close();
    }
  }
}

/* handle POST - both create and update */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Determine if this is update or create
  $is_update = isset($_POST['id']) && (int)$_POST['id'] > 0;
  $id = $is_update ? (int)$_POST['id'] : 0;

  // Get form values
  $code = isset($_POST['code']) ? trim($_POST['code']) : '';
  $discount_type = isset($_POST['discount_type']) ? $_POST['discount_type'] : 'percent';
  $discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : 0;
  $min_order_value = isset($_POST['min_order_value']) && $_POST['min_order_value'] !== '' ? floatval($_POST['min_order_value']) : 0.00;
  $usage_limit = isset($_POST['usage_limit']) && $_POST['usage_limit'] !== '' ? intval($_POST['usage_limit']) : null;
  $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;
  
  // Handle datetime fields - if empty, set to NULL
  $starts_at = isset($_POST['starts_at']) && trim($_POST['starts_at']) !== '' ? $_POST['starts_at'] : null;
  $expires_at = isset($_POST['expires_at']) && trim($_POST['expires_at']) !== '' ? $_POST['expires_at'] : null;
  
  // Convert to proper datetime format if not null
  if ($starts_at !== null) {
    $starts_at = date('Y-m-d H:i:s', strtotime($starts_at));
  }
  if ($expires_at !== null) {
    $expires_at = date('Y-m-d H:i:s', strtotime($expires_at));
  }

  // Validate inputs
  if ($code === '') {
    $error = "Coupon Code is required.";
  } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    $error = "Coupon Code can only contain letters, numbers, hyphens and underscores.";
  } elseif ($discount_value <= 0) {
    $error = "Discount value must be greater than 0.";
  } elseif ($discount_type === 'percent' && ($discount_value < 0 || $discount_value > 100)) {
    $error = "Percentage discount must be between 0 and 100.";
  } elseif ($min_order_value < 0) {
    $error = "Minimum order value cannot be negative.";
  } elseif ($usage_limit !== null && $usage_limit < 0) {
    $error = "Usage limit cannot be negative.";
  } elseif ($starts_at && $expires_at && strtotime($starts_at) >= strtotime($expires_at)) {
    $error = "Expiry date must be after start date.";
  } else {
    try {
      // Check if coupon code already exists (for new or update with different code)
      if ($is_update) {
        $check_sql = "SELECT id FROM coupon_codes WHERE code = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('si', $code, $id);
      } else {
        $check_sql = "SELECT id FROM coupon_codes WHERE code = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $code);
      }
      
      $check_stmt->execute();
      $check_stmt->store_result();
      
      if ($check_stmt->num_rows > 0) {
        $error = "Coupon code '{$code}' already exists. Please use a different code.";
      } else {
        if ($is_update) {
          // Update existing coupon - handle NULL values differently
          if ($starts_at === null && $expires_at === null) {
            $sql = "UPDATE coupon_codes SET 
                    code = ?, 
                    discount_type = ?, 
                    discount_value = ?, 
                    min_order_value = ?, 
                    usage_limit = ?, 
                    is_active = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsii', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $id
              );
            }
          } elseif ($starts_at === null) {
            $sql = "UPDATE coupon_codes SET 
                    code = ?, 
                    discount_type = ?, 
                    discount_value = ?, 
                    min_order_value = ?, 
                    usage_limit = ?, 
                    is_active = ?, 
                    expires_at = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsisi', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $expires_at, 
                $id
              );
            }
          } elseif ($expires_at === null) {
            $sql = "UPDATE coupon_codes SET 
                    code = ?, 
                    discount_type = ?, 
                    discount_value = ?, 
                    min_order_value = ?, 
                    usage_limit = ?, 
                    is_active = ?, 
                    starts_at = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsisi', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $starts_at, 
                $id
              );
            }
          } else {
            $sql = "UPDATE coupon_codes SET 
                    code = ?, 
                    discount_type = ?, 
                    discount_value = ?, 
                    min_order_value = ?, 
                    usage_limit = ?, 
                    is_active = ?, 
                    starts_at = ?, 
                    expires_at = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsissi', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $starts_at, 
                $expires_at, 
                $id
              );
            }
          }
          
          if ($stmt && $stmt->execute()) {
            $success = "Coupon updated successfully (ID: {$id}).";
            $insert_id = $id;
          } else {
            $error = "Failed to update coupon: " . ($stmt ? $stmt->error : $conn->error);
          }
          if ($stmt) $stmt->close();
        } else {
          // Insert new coupon - handle NULL values differently
          if ($starts_at === null && $expires_at === null) {
            $sql = "INSERT INTO coupon_codes (
                    code, discount_type, discount_value, min_order_value, 
                    usage_limit, is_active
                  ) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsi', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active
              );
            }
          } elseif ($starts_at === null) {
            $sql = "INSERT INTO coupon_codes (
                    code, discount_type, discount_value, min_order_value, 
                    usage_limit, is_active, expires_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsis', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $expires_at
              );
            }
          } elseif ($expires_at === null) {
            $sql = "INSERT INTO coupon_codes (
                    code, discount_type, discount_value, min_order_value, 
                    usage_limit, is_active, starts_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsis', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $starts_at
              );
            }
          } else {
            $sql = "INSERT INTO coupon_codes (
                    code, discount_type, discount_value, min_order_value, 
                    usage_limit, is_active, starts_at, expires_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
              $stmt->bind_param('ssddsiss', 
                $code, 
                $discount_type, 
                $discount_value, 
                $min_order_value, 
                $usage_limit, 
                $is_active, 
                $starts_at, 
                $expires_at
              );
            }
          }
          
          if ($stmt && $stmt->execute()) {
            $insert_id = $stmt->insert_id;
            $success = "Coupon created successfully (ID: " . $insert_id . ").";
          } else {
            $error = "Failed to create coupon: " . ($stmt ? $stmt->error : $conn->error);
          }
          if ($stmt) $stmt->close();
        }
      }
      if ($check_stmt) $check_stmt->close();
      
    } catch (Exception $e) {
      $error = "Database error: " . $e->getMessage();
    }
  }

  // If request is XHR, return JSON
  if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'success' => $success ?: null,
      'error'   => $error ?: null,
      'id'      => $insert_id ?: null,
      'is_update' => $is_update ? 1 : 0
    ]);
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo $edit_mode ? 'Edit Coupon' : 'Add Coupon'; ?></title>

  <!-- App favicon -->
  <link rel="shortcut icon" href="../assets/images/favicon.ico">

  <!-- Vendor css (Require in all Page) -->
  <link href="../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

  <!-- Icons css (Require in all Page) -->
  <link href="../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

  <!-- App css (Require in all Page) -->
  <link href="../assets/css/app.min.css" rel="stylesheet" type="text/css" />

  <!-- Toastify CSS (for toasts) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

  <!-- Theme Config js (Require in all Page) -->
  <script src="../assets/js/config.js"></script>

  <style>
    .toastify {
      z-index: 20000;
    }
    .existing-thumb {
      max-width: 120px;
      max-height: 120px;
      object-fit: cover;
      border-radius: 6px;
      display: block;
      margin-bottom: 8px;
    }
  </style>
</head>

<body>

  <div class="wrapper">
    <?php include __DIR__ . '/../includes/header.php' ?>
    <?php include __DIR__ . '/../includes/sidebar.php' ?>

    <div class="page-content">
      <div class="container-xxl">
        <!-- Show success/error messages -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-xl-3 col-lg-4">
            <div class="card">
              <div class="card-body text-center">
                <div class="bg-light rounded p-3">
                  <h2 class="text-primary" id="liveCode">
                    <?php echo $edit_mode ? e($prefill_code) : 'COUPON123'; ?>
                  </h2>
                  <h4 class="mt-2 text-danger" id="liveDiscount">
                    <?php 
                    if ($edit_mode) {
                      echo e($prefill_discount_value) . ($prefill_discount_type == 'percent' ? '% OFF' : ' OFF');
                    } else {
                      echo '10% OFF';
                    }
                    ?>
                  </h4>
                </div>

                <h4 class="mt-3 mb-1" id="liveTitle">
                  <?php echo $edit_mode ? e($prefill_code) : 'Coupon Preview'; ?>
                </h4>

                <p class="text-muted mb-0">
                  Status: <span id="liveStatus"><?php echo $edit_mode ? ($prefill_is_active ? 'Active' : 'Inactive') : 'Active'; ?></span>
                </p>
                <p class="text-muted mb-0">
                  Usage: <span id="liveUsage"><?php echo $edit_mode ? e($prefill_usage_limit ?: 'Unlimited') : 'Unlimited'; ?></span>
                </p>
                <p class="text-muted mb-0">
                  Min. Order: <span id="liveMinOrder">₹<?php echo $edit_mode ? e($prefill_min_order_value) : '0.00'; ?></span>
                </p>
              </div>

              <div class="card-footer border-top">
                <div class="row g-2">
                  <div class="col-12">
                    <a href="coupons-list.php" class="btn btn-outline-secondary w-100">Back to Coupons</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-xl-9 col-lg-8">
            <!-- MAIN FORM -->
            <form id="couponForm" method="post" action="">
              <input type="hidden" name="id" value="<?php echo $edit_mode ? e($edit_id) : ''; ?>">

              <div class="card">
                <div class="card-header">
                  <h4 class="card-title">Coupon Information</h4>
                </div>
                <div class="card-body">
                  <div class="row">
                    <!-- Coupon Code -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="code" class="form-label">Coupon Code *</label>
                        <input type="text" id="code" name="code" class="form-control" 
                               placeholder="SUMMER2024" 
                               value="<?php echo e($edit_mode ? $prefill_code : ''); ?>"
                               pattern="[A-Za-z0-9_-]+"
                               title="Only letters, numbers, hyphens and underscores allowed">
                        <small class="text-muted">Unique code for the coupon</small>
                      </div>
                    </div>
                    
                    <!-- Discount Type -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label class="form-label">Discount Type *</label><br>
                        <div class="d-flex gap-4">
                          <div class="form-check">
                            <input class="form-check-input" type="radio" 
                                   name="discount_type" id="percent" value="percent" 
                                   <?php echo ($prefill_discount_type == 'percent') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="percent">
                              Percentage (%)
                            </label>
                          </div>
                          <div class="form-check">
                            <input class="form-check-input" type="radio" 
                                   name="discount_type" id="fixed" value="fixed"
                                   <?php echo ($prefill_discount_type == 'fixed') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="fixed">
                              Fixed Amount
                            </label>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Discount Value -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="discount_value" class="form-label">Discount Value *</label>
                        <div class="input-group">
                          <input type="number" id="discount_value" name="discount_value" 
                                 class="form-control" step="0.01" min="0.01" 
                                 placeholder="10"
                                 value="<?php echo e($edit_mode ? $prefill_discount_value : ''); ?>">
                          <span class="input-group-text" id="discountSuffix">%</span>
                        </div>
                        <small class="text-muted" id="discountHelp">Enter percentage value (1-100)</small>
                      </div>
                    </div>
                    
                    <!-- Minimum Order Value -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="min_order_value" class="form-label">Minimum Order Value</label>
                        <div class="input-group">
                          <span class="input-group-text">₹</span>
                          <input type="number" id="min_order_value" name="min_order_value" 
                                 class="form-control" step="0.01" min="0" 
                                 placeholder="0.00" 
                                 value="<?php echo e($edit_mode ? $prefill_min_order_value : '0.00'); ?>">
                        </div>
                        <small class="text-muted">Leave 0 for no minimum requirement</small>
                      </div>
                    </div>
                    
                    <!-- Usage Limit -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="usage_limit" class="form-label">Usage Limit</label>
                        <input type="number" id="usage_limit" name="usage_limit" 
                               class="form-control" min="0" 
                               placeholder="Leave blank for unlimited"
                               value="<?php echo e($edit_mode ? $prefill_usage_limit : ''); ?>">
                        <small class="text-muted">Maximum number of times coupon can be used</small>
                      </div>
                    </div>
                    
                    <!-- Status -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label class="form-label">Status</label><br>
                        <div class="d-flex gap-4">
                          <div class="form-check">
                            <input class="form-check-input" type="radio" 
                                   name="is_active" id="active" value="1" 
                                   <?php echo ($prefill_is_active == '1') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="active">
                              Active
                            </label>
                          </div>
                          <div class="form-check">
                            <input class="form-check-input" type="radio" 
                                   name="is_active" id="inactive" value="0"
                                   <?php echo ($prefill_is_active == '0') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="inactive">
                              Inactive
                            </label>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Start Date -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="starts_at" class="form-label">Start Date</label>
                        <input type="datetime-local" id="starts_at" name="starts_at" 
                               class="form-control"
                               value="<?php echo e($edit_mode ? $prefill_starts_at : ''); ?>">
                        <small class="text-muted">Leave blank to start immediately</small>
                      </div>
                    </div>
                    
                    <!-- Expiry Date -->
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="expires_at" class="form-label">Expiry Date</label>
                        <input type="datetime-local" id="expires_at" name="expires_at" 
                               class="form-control"
                               value="<?php echo e($edit_mode ? $prefill_expires_at : ''); ?>">
                        <small class="text-muted">Leave blank for no expiry</small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="p-3 bg-light mb-3 rounded">
                <div class="row justify-content-end g-2">
                  <div class="col-lg-2">
                    <button id="saveBtn" type="submit" class="btn btn-primary w-100">
                      <?php echo $edit_mode ? 'Update Coupon' : 'Create Coupon'; ?>
                    </button>
                  </div>
                  <div class="col-lg-2">
                    <a href="coupons-list.php" class="btn btn-outline-secondary w-100">Cancel</a>
                  </div>
                </div>
              </div>
            </form>
            <!-- END FORM -->
          </div>
        </div>
      </div>

      <footer class="footer">
        <div class="container-fluid">
          <div class="row">
            <div class="col-12 text-center">
              <script>
                document.write(new Date().getFullYear())
              </script> &copy; Larkon. Crafted by 
              <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> 
              <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>

  <!-- Vendor Javascript (Require in all Page) -->
  <script src="../assets/js/vendor.js"></script>

  <!-- App Javascript (Require in all Page) -->
  <script src="../assets/js/app.js"></script>

  <!-- Toastify JS -->
  <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

  <script>
    (function() {
      // Helper to show toast
      function showToast(msg, type) {
        Toastify({
          text: msg,
          duration: type === 'error' ? 6000 : 4000,
          close: true,
          gravity: "top",
          position: "right",
          stopOnFocus: true,
          style: {
            background: type === 'error' ?
              "linear-gradient(to right, #dc3545, #b02a37)" : "linear-gradient(to right, #28a745, #218838)",
            color: "#fff"
          }
        }).showToast();
      }

      // Show server messages on page load
      var serverSuccess = <?php echo json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      var serverError = <?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      if (serverSuccess && serverSuccess.length) {
        setTimeout(function() {
          showToast(serverSuccess, 'success');
        }, 50);
      }
      if (serverError && serverError.length) {
        setTimeout(function() {
          showToast(serverError, 'error');
        }, 50);
      }

      // Get DOM elements
      const form = document.getElementById('couponForm');
      const codeInput = document.getElementById('code');
      const discountTypePercent = document.getElementById('percent');
      const discountTypeFixed = document.getElementById('fixed');
      const discountValueInput = document.getElementById('discount_value');
      const discountSuffix = document.getElementById('discountSuffix');
      const discountHelp = document.getElementById('discountHelp');
      const minOrderInput = document.getElementById('min_order_value');
      const usageLimitInput = document.getElementById('usage_limit');
      const startsAtInput = document.getElementById('starts_at');
      const expiresAtInput = document.getElementById('expires_at');
      const activeRadio = document.getElementById('active');
      const inactiveRadio = document.getElementById('inactive');

      // Preview elements
      const liveCode = document.getElementById('liveCode');
      const liveDiscount = document.getElementById('liveDiscount');
      const liveStatus = document.getElementById('liveStatus');
      const liveUsage = document.getElementById('liveUsage');
      const liveMinOrder = document.getElementById('liveMinOrder');

      // Update discount suffix based on type
      function updateDiscountType() {
        const isPercent = discountTypePercent.checked;
        discountSuffix.textContent = isPercent ? '%' : '₹';
        discountHelp.textContent = isPercent ? 
          'Enter percentage value (1-100)' : 
          'Enter fixed discount amount';
        
        // Update preview
        updatePreview();
      }

      // Update live preview
      function updatePreview() {
        // Update code
        liveCode.textContent = codeInput.value.trim() || 'COUPON123';
        
        // Update discount
        const discountValue = discountValueInput.value || '0';
        const isPercent = discountTypePercent.checked;
        liveDiscount.textContent = discountValue + (isPercent ? '% OFF' : '₹ OFF');
        
        // Update status
        liveStatus.textContent = activeRadio.checked ? 'Active' : 'Inactive';
        
        // Update usage limit
        const usageLimit = usageLimitInput.value;
        liveUsage.textContent = usageLimit ? usageLimit + ' times' : 'Unlimited';
        
        // Update min order
        const minOrder = minOrderInput.value || '0';
        liveMinOrder.textContent = '₹' + parseFloat(minOrder).toFixed(2);
      }

      // Event listeners for live preview
      codeInput.addEventListener('input', updatePreview);
      discountValueInput.addEventListener('input', updatePreview);
      discountTypePercent.addEventListener('change', updateDiscountType);
      discountTypeFixed.addEventListener('change', updateDiscountType);
      minOrderInput.addEventListener('input', updatePreview);
      usageLimitInput.addEventListener('input', updatePreview);
      activeRadio.addEventListener('change', updatePreview);
      inactiveRadio.addEventListener('change', updatePreview);

      // Initialize
      updateDiscountType();
      updatePreview();

      // Form validation before submission
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Basic validation
        const code = codeInput.value.trim();
        const discountValue = parseFloat(discountValueInput.value);
        const isPercent = discountTypePercent.checked;
        
        if (!code) {
          showToast('Coupon Code is required', 'error');
          codeInput.focus();
          return false;
        }
        
        if (!/^[A-Za-z0-9_-]+$/.test(code)) {
          showToast('Coupon Code can only contain letters, numbers, hyphens and underscores', 'error');
          codeInput.focus();
          return false;
        }
        
        if (!discountValue || discountValue <= 0) {
          showToast('Discount value must be greater than 0', 'error');
          discountValueInput.focus();
          return false;
        }
        
        if (isPercent && (discountValue < 0 || discountValue > 100)) {
          showToast('Percentage discount must be between 0 and 100', 'error');
          discountValueInput.focus();
          return false;
        }
        
        // Validate date range if both dates are provided
        const startsAt = startsAtInput.value;
        const expiresAt = expiresAtInput.value;
        if (startsAt && expiresAt) {
          const startDate = new Date(startsAt);
          const endDate = new Date(expiresAt);
          if (startDate >= endDate) {
            showToast('Expiry date must be after start date', 'error');
            expiresAtInput.focus();
            return false;
          }
        }
        
        // If all validations pass, submit the form
        form.submit();
      });

      // Set minimum datetime for expiry based on start date
      startsAtInput.addEventListener('change', function() {
        if (this.value) {
          expiresAtInput.min = this.value;
        }
      });

      // Also set initial min value if starts_at has value on page load
      if (startsAtInput.value) {
        expiresAtInput.min = startsAtInput.value;
      }

    })();
  </script>

</body>
</html>