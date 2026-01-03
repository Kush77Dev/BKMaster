<?php
// add-category.php
// Single-file create + edit category (Dropzone friendly)
// Schema:
// id INT PRIMARY KEY AUTO_INCREMENT,
// name VARCHAR(150) NOT NULL,
// image_url VARCHAR(1000),
// position INT DEFAULT 0

// ----- EDIT THESE DB CREDENTIALS ----- 
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster'; // <-- change if needed
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

// Utility: attempt to delete a stored image path (multiple candidate locations). Non-fatal.
function try_delete_image_file($stored)
{
  if (!$stored) return;
  $stored = trim($stored);
  $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  $scriptDir = __DIR__;
  $candidates = [];

  // if stored starts with slash, try absolute mappings
  if ($stored !== '' && $stored[0] === '/') {
    $candidates[] = $docRoot . $stored;
    $candidates[] = $scriptDir . $stored;
    $candidates[] = $docRoot . '/category' . $stored;
    $candidates[] = $scriptDir . '/category' . $stored;
  }

  // try basename variants (common)
  $base = basename($stored);
  if ($base) {
    $candidates[] = $scriptDir . '/uploads/' . $base;
    $candidates[] = $scriptDir . '/category/uploads/' . $base;
    $candidates[] = $docRoot . '/uploads/' . $base;
    $candidates[] = $docRoot . '/category/uploads/' . $base;
  }

  // stored as relative path
  $candidates[] = $scriptDir . '/' . ltrim($stored, '/');
  $candidates[] = $docRoot . '/' . ltrim($stored, '/');

  $candidates = array_unique($candidates);
  foreach ($candidates as $fs) {
    if (!$fs) continue;
    $fs = str_replace(['//', '\\\\'], '/', $fs);
    if (file_exists($fs) && is_file($fs)) {
      @unlink($fs);
      // don't break; try to remove duplicates also
    }
  }
}

// ---- EDIT MODE: if id provided on GET, load values to prefill ----
$edit_mode = false;
$edit_id = 0;
$prefill_name = '';
$prefill_position = '';
$prefill_image_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($id > 0) {
    $stmt = $conn->prepare("SELECT id, name, image_url, position FROM categories WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $row = $res->fetch_assoc()) {
        $edit_mode = true;
        $edit_id = (int)$row['id'];
        $prefill_name = $row['name'];
        $prefill_position = $row['position'];
        $prefill_image_url = $row['image_url'];
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

  $name = isset($_POST['name']) ? trim($_POST['name']) : '';
  $position = isset($_POST['position']) && $_POST['position'] !== '' ? (int)$_POST['position'] : 0;

  if ($name === '') {
    $error = "Category Title (name) is required.";
  } else {
    // If update: fetch existing image_url to keep/remove when needed
    $existing_image_url = null;
    if ($is_update) {
      $stmt = $conn->prepare("SELECT image_url FROM categories WHERE id = ?");
      if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($existing_image_url);
        $stmt->fetch();
        $stmt->close();
      }
    }

    // handle image upload (optional) — Dropzone will post file as 'category_image'
    $new_image_url = null;
    if (isset($_FILES['category_image']) && $_FILES['category_image']['error'] !== UPLOAD_ERR_NO_FILE) {
      if ($_FILES['category_image']['error'] !== UPLOAD_ERR_OK) {
        $error = "Image upload error code: " . $_FILES['category_image']['error'];
      } else {
        $tmp = $_FILES['category_image']['tmp_name'];
        $fType = function_exists('mime_content_type') ? mime_content_type($tmp) : $_FILES['category_image']['type'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($fType, $allowed)) {
          $error = "Only JPG, PNG, GIF images are allowed.";
        } else {
          $uploadDir = __DIR__ . '/uploads/categories/';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $error = "Failed to create upload directory.";
          } else {
            $ext = pathinfo($_FILES['category_image']['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $target = $uploadDir . $filename;
            if (move_uploaded_file($tmp, $target)) {
              // stored web path; adjust if your web setup differs
              $new_image_url = '/uploads/categories/' . $filename;
            } else {
              $error = "Failed to move uploaded file.";
            }
          }
        }
      }
    }

    if ($error === "") {
      if ($is_update) {
        // Update flow: if new image uploaded, set image_url to new_image_url and delete old file
        $final_image_url = $existing_image_url;
        if ($new_image_url !== null) {
          $final_image_url = $new_image_url;
        }

        $stmt = $conn->prepare("UPDATE categories SET name = ?, image_url = ?, position = ? WHERE id = ?");
        if (!$stmt) {
          $error = "Prepare failed: " . $conn->error;
        } else {
          // use NULL if empty
          $img_param = $final_image_url === null ? null : $final_image_url;
          // bind_param cannot bind null directly for strings; set type and variable
          $stmt->bind_param('ssii', $name, $img_param, $position, $id);
          // Note: when $img_param is NULL PHP will send empty string; to explicitly set SQL NULL we must build query differently.
          // To keep code simple and safe, we allow empty string as stored value when null. If you prefer actual SQL NULL, we can adjust.
          if (!$stmt->execute()) {
            $error = "Execute failed: " . $stmt->error;
          } else {
            $success = "Category updated successfully (ID: {$id}).";
            $insert_id = $id;
            // if new image uploaded, try delete old image (non-fatal)
            if ($new_image_url !== null && $existing_image_url && $existing_image_url !== $new_image_url) {
              try_delete_image_file($existing_image_url);
            }
          }
          $stmt->close();
        }
      } else {
        // Insert flow
        $columns = ['name', 'image_url', 'position'];
        $placeholders = [];
        $bind_vals = [];
        $bind_types = '';

        $add = function ($val, $typeChar) use (&$placeholders, &$bind_vals, &$bind_types) {
          if ($val === null) {
            $placeholders[] = 'NULL';
          } else {
            $placeholders[] = '?';
            $bind_vals[] = $val;
            $bind_types .= $typeChar;
          }
        };

        $add($name, 's');
        $add($new_image_url, 's');
        $add($position, 'i');

        $sql = "INSERT INTO categories (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
          $error = "Prepare failed: " . $conn->error;
        } else {
          if (count($bind_vals) > 0) {
            $refs = [];
            $refs[] = &$bind_types;
            for ($i = 0; $i < count($bind_vals); $i++) {
              $refs[] = &$bind_vals[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
          }
          if (!$stmt->execute()) {
            $error = "Execute failed: " . $stmt->error;
          } else {
            $insert_id = $stmt->insert_id;
            $success = "Category created successfully (ID: " . $insert_id . ").";
          }
          $stmt->close();
        }
      }
    }
  }

  // If request is XHR (Dropzone sends XHR), return JSON immediately
  if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
  ) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'success' => $success ?: null,
      'error'   => $error ?: null,
      'id'      => $insert_id ?: null,
      'is_update' => $is_update ? 1 : 0
    ]);
    exit;
  }
  // Non-XHR: fall through so the page reload will show toast using $success/$error
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo $edit_mode ? 'Edit Category' : 'Add Category'; ?></title>

  <!-- App favicon -->
  <link rel="shortcut icon" href="/../assets/images/favicon.ico">

  <!-- Vendor css (Require in all Page) -->
  <link href="/../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

  <!-- Icons css (Require in all Page) -->
  <link href="/../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

  <!-- App css (Require in all Page) -->
  <link href="/../assets/css/app.min.css" rel="stylesheet" type="text/css" />

  <!-- Toastify CSS (for toasts) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

  <!-- Theme Config js (Require in all Page) -->
  <script src="/../assets/js/config.js"></script>

  <style>
    /* optional small tweak so toast doesn't overlap with any fixed header */
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
        <div class="row">
          <div class="col-xl-3 col-lg-4">
            <div class="card">
              <div class="card-body text-center">
                <div class="bg-light rounded p-3">
                  <img
                    id="liveImage"
                    src="<?php echo $prefill_image_url ? e($prefill_image_url) : '/../assets/images/placeholder.png'; ?>"
                    class="img-fluid rounded"
                    style="max-height:180px; object-fit:cover;"
                    alt="">
                </div>

                <h4 class="mt-3 mb-1" id="liveTitle">
                  <?php echo $edit_mode ? e($prefill_name) : 'Category Title'; ?>
                </h4>

                <p class="text-muted mb-0">
                  Position:
                  <span id="livePosition">
                    <?php echo $edit_mode ? e($prefill_position) : '0'; ?>
                  </span>
                </p>
              </div>

              <div class="card-footer border-top">
                <div class="row g-2">
                  <div class="col-12">
                    <a href="list-category.php" class="btn btn-outline-secondary w-100">Back to Categories</a>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <div class="col-xl-9 col-lg-8 ">
            <!-- NOTE: Toasts will show instead of server-side alert boxes -->

            <!-- MAIN FORM -->
            <!-- If editing, include hidden id -->
            <form id="categoryForm" method="post" enctype="multipart/form-data" action="">
              <input type="hidden" name="id" value="<?php echo $edit_mode ? e($edit_id) : ''; ?>">

              <div class="card">
                <div class="card-header">
                  <h4 class="card-title"><?php echo $edit_mode ? 'Edit Thumbnail Photo' : 'Add Thumbnail Photo'; ?></h4>
                </div>
                <div class="card-body">
                  <div class="dropzone dz-clickable" id="myAwesomeDropzone" data-plugin="dropzone" data-previews-container="#file-previews" data-upload-preview-template="#uploadPreviewTemplate">
                    <div class="fallback">
                      <input name="category_image" type="file" accept="image/*" />
                    </div>

                    <?php if ($edit_mode && $prefill_image_url): ?>
                      <div class="mb-3">
                        <label class="form-label">Existing Image</label><br>
                        <img src="<?php echo e($prefill_image_url); ?>" alt="Existing" class="existing-thumb" onerror="this.style.display='none'">
                        <small class="text-muted d-block mb-2">Uploading a new image will replace the existing one.</small>
                      </div>
                    <?php endif; ?>

                    <div class="dz-message needsclick">
                      <i class="bx bx-cloud-upload fs-48 text-primary"></i>
                      <h3 class="mt-4">Drop your image here, or <span class="text-primary">click to browse</span></h3>
                      <span class="text-muted fs-13">
                        1600 x 1200 (4:3) recommended. PNG, JPG and GIF files are allowed
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <h4 class="card-title">General Information</h4>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="category-title" class="form-label">Category Title</label>
                        <input type="text" id="category-title" name="name" class="form-control" placeholder="Enter Title" value="<?php echo e($edit_mode ? $prefill_name : ''); ?>">
                      </div>
                    </div>

                    <div class="col-lg-6">
                      <div class="mb-3">
                        <label for="position" class="form-label">Position</label>
                        <input type="number" id="position" name="position" class="form-control" placeholder="0" value="<?php echo e($edit_mode ? $prefill_position : ''); ?>">
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="p-3 bg-light mb-3 rounded">
                <div class="row justify-content-end g-2">
                  <div class="col-lg-2">
                    <button id="saveBtn" type="button" class="btn btn-outline-secondary w-100"><?php echo $edit_mode ? 'Update' : 'Save Change'; ?></button>
                  </div>
                  <div class="col-lg-2">
                    <a href="categories-list.php" class="btn btn-primary w-100">Cancel</a>
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
              </script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
            </div>
          </div>
        </div>
      </footer>

    </div> <!-- page-content -->
  </div> <!-- wrapper -->

  <!-- Vendor Javascript (Require in all Page) -->
  <script src="../assets/js/vendor.js"></script>

  <!-- App Javascript (Require in all Page) -->
  <script src="../assets/js/app.js"></script>

  <!-- Toastify JS -->
  <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

  <script>
    (function() {
      // small helper to show a toast
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

      // If the server rendered a non-XHR POST (normal submit) and set PHP $success / $error,
      // we still show toast on page load:
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

      var SaveBtn = document.getElementById('saveBtn');
      var form = document.getElementById('categoryForm');

      if (typeof Dropzone !== 'undefined') {
        Dropzone.autoDiscover = false;

        var myDropzone = new Dropzone("#myAwesomeDropzone", {
          url: window.location.href,
          autoProcessQueue: false,
          uploadMultiple: false,
          maxFiles: 1,
          paramName: "category_image",
          addRemoveLinks: true,
          acceptedFiles: "image/*"
        });

        myDropzone.on("sending", function(file, xhr, formData) {
          // Append all non-file form fields to FormData
          var elements = form.querySelectorAll("input[name], select[name], textarea[name]");
          elements.forEach(function(el) {
            if (!el.name) return;
            if (el.type === 'file') return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            formData.append(el.name, el.value);
          });
        });

        myDropzone.on("success", function(file, resp) {
          // resp should be parsed JSON from server
          var data = resp;
          if (typeof resp === 'string') {
            try {
              data = JSON.parse(resp);
            } catch (e) {
              data = {
                success: resp
              };
            }
          }
          if (data && data.success) {
            showToast(data.success, 'success');
            myDropzone.removeAllFiles(true);
            form.reset();
            // If in edit mode, we might want to stay on same page; for now we reset.
          } else if (data && data.error) {
            showToast(data.error, 'error');
          } else {
            showToast('Upload complete', 'success');
          }
        });

        myDropzone.on("error", function(file, resp) {
          // resp might be a string or object
          var msg = 'Upload failed';
          if (!resp) {
            if (file.xhr && file.xhr.response) resp = file.xhr.response;
          }
          if (typeof resp === 'string') {
            try {
              var parsed = JSON.parse(resp);
              if (parsed && parsed.error) msg = parsed.error;
              else if (parsed && parsed.success) msg = parsed.success;
              else msg = resp;
            } catch (e) {
              msg = resp;
            }
          } else if (typeof resp === 'object' && resp !== null) {
            if (resp.error) msg = resp.error;
            else if (resp.message) msg = resp.message;
          }
          showToast(msg, 'error');
        });

        SaveBtn.addEventListener('click', function(e) {
          e.preventDefault();
          var name = document.querySelector('input[name="name"]');
          if (!name || name.value.trim() === '') {
            alert('Category Title is required.');
            name && name.focus();
            return;
          }
          if (myDropzone.getQueuedFiles().length > 0) {
            myDropzone.processQueue();
          } else {
            // No file queued: do a normal form submit (non-XHR) which will render page and show toast from PHP
            form.submit();
          }
        });

      } else {
        // Dropzone not available; fallback to normal submit
        SaveBtn.addEventListener('click', function(e) {
          e.preventDefault();
          var name = document.querySelector('input[name="name"]');
          if (!name || name.value.trim() === '') {
            alert('Category Title is required.');
            name && name.focus();
            return;
          }
          form.submit();
        });
      }

      const titleInput = document.querySelector('input[name="name"]');
      const positionInput = document.querySelector('input[name="position"]');

      const liveTitle = document.getElementById('liveTitle');
      const livePosition = document.getElementById('livePosition');
      const liveImage = document.getElementById('liveImage');

      // Live title update
      if (titleInput) {
        titleInput.addEventListener('input', function() {
          liveTitle.textContent = this.value.trim() || 'Category Title';
        });
      }

      // Live position update
      if (positionInput) {
        positionInput.addEventListener('input', function() {
          livePosition.textContent = this.value || '0';
        });
      }

      // Live image preview (Dropzone OR fallback file input)
      function previewImage(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
          liveImage.src = e.target.result;
        };
        reader.readAsDataURL(file);
      }

      // Dropzone preview hook
      if (typeof Dropzone !== 'undefined' && Dropzone.instances.length) {
        Dropzone.instances[0].on("addedfile", function(file) {
          previewImage(file);
        });
      }

      // Fallback input preview
      const fileInput = document.querySelector('input[name="category_image"]');
      if (fileInput) {
        fileInput.addEventListener('change', function() {
          if (this.files && this.files[0]) {
            previewImage(this.files[0]);
          }
        });
      }

    })();
  </script>

</body>

</html>