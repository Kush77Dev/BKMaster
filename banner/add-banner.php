<?php
// add-banner.php
// Single-file create + edit banner with Dropzone image upload

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

// Set upload directory
$UPLOAD_DIR = __DIR__ . '/../uploads/banners/';
if (!file_exists($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0777, true);
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

// ---- EDIT MODE: if id provided on GET, load values to prefill ----
$edit_mode = false;
$edit_id = 0;
$prefill_title = '';
$prefill_subtitle = '';
$prefill_image_url = '';
$prefill_link_url = '';
$prefill_banner_type = 'homepage';
$prefill_position = 0;
$prefill_is_active = '1';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if ($id > 0) {
    $stmt = $conn->prepare("SELECT id, title, subtitle, image_url, link_url, banner_type, position, is_active FROM banners WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $row = $res->fetch_assoc()) {
        $edit_mode = true;
        $edit_id = (int)$row['id'];
        $prefill_title = $row['title'];
        $prefill_subtitle = $row['subtitle'];
        $prefill_image_url = $row['image_url'];
        $prefill_link_url = $row['link_url'];
        $prefill_banner_type = $row['banner_type'];
        $prefill_position = $row['position'];
        $prefill_is_active = $row['is_active'];
      }
      $stmt->close();
    }
  }
}

/* handle POST - both create and update */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an image upload (Dropzone)
    if (isset($_FILES['file']) && isset($_POST['dz_type']) && $_POST['dz_type'] === 'banner_image') {
        $file = $_FILES['file'];
        $filename = uniqid() . '_' . time() . '_' . preg_replace("/[^a-zA-Z0-9\.]/", "_", $file['name']);
        $targetPath = $UPLOAD_DIR . $filename;
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Only JPG, PNG, GIF and WebP images are allowed']);
            exit;
        }
        
        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File size must be less than 5MB']);
            exit;
        }
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $webPath = '/uploads/banners/' . $filename;
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'webPath' => $webPath
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to upload file']);
        }
        exit;
    }
    
    // Handle regular form submission
    $is_update = isset($_POST['id']) && (int)$_POST['id'] > 0;
    $id = $is_update ? (int)$_POST['id'] : 0;

    // Get form values
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $subtitle = isset($_POST['subtitle']) ? trim($_POST['subtitle']) : '';
    $image_url = isset($_POST['image_url']) ? trim($_POST['image_url']) : '';
    $link_url = isset($_POST['link_url']) ? trim($_POST['link_url']) : '';
    $banner_type = isset($_POST['banner_type']) ? $_POST['banner_type'] : 'homepage';
    $position = isset($_POST['position']) ? intval($_POST['position']) : 0;
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

    // Validate inputs
    if ($image_url === '') {
        $error = "Please upload a banner image.";
    } elseif ($title === '') {
        $error = "Title is required.";
    } elseif (!in_array($banner_type, ['homepage', 'category', 'product'])) {
        $error = "Invalid banner type.";
    } elseif ($position < 0) {
        $error = "Position cannot be negative.";
    } else {
        try {
            if ($is_update) {
                // Update existing banner
                $sql = "UPDATE banners SET 
                        title = ?, 
                        subtitle = ?, 
                        image_url = ?, 
                        link_url = ?, 
                        banner_type = ?, 
                        position = ?, 
                        is_active = ? 
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('sssssiii', 
                        $title, 
                        $subtitle, 
                        $image_url, 
                        $link_url, 
                        $banner_type, 
                        $position, 
                        $is_active, 
                        $id
                    );
                }
                
                if ($stmt && $stmt->execute()) {
                    $success = "Banner updated successfully (ID: {$id}).";
                    $insert_id = $id;
                } else {
                    $error = "Failed to update banner: " . ($stmt ? $stmt->error : $conn->error);
                }
                if ($stmt) $stmt->close();
            } else {
                // Insert new banner
                $sql = "INSERT INTO banners (
                        title, subtitle, image_url, link_url, 
                        banner_type, position, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('sssssii', 
                        $title, 
                        $subtitle, 
                        $image_url, 
                        $link_url, 
                        $banner_type, 
                        $position, 
                        $is_active
                    );
                }
                
                if ($stmt && $stmt->execute()) {
                    $insert_id = $stmt->insert_id;
                    $success = "Banner created successfully (ID: " . $insert_id . ").";
                } else {
                    $error = "Failed to create banner: " . ($stmt ? $stmt->error : $conn->error);
                }
                if ($stmt) $stmt->close();
            }
            
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit Banner' : 'Add Banner'; ?></title>

     <!-- App favicon -->
     <link rel="shortcut icon" href="../assets/images/favicon.ico">

     <!-- Vendor css (Require in all Page) -->
     <link href="../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

     <!-- Icons css (Require in all Page) -->
     <link href="../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

     <!-- App css (Require in all Page) -->
     <link href="../assets/css/app.min.css" rel="stylesheet" type="text/css" />

     <!-- Dropzone CSS -->
     <link href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.css" rel="stylesheet">

     <!-- Toastify CSS (for toasts) -->
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

     <!-- Theme Config js (Require in all Page) -->
     <script src="../assets/js/config.js"></script>
     
     <style>
        .toastify {
            z-index: 20000;
        }
        .banner-preview {
            max-width: 100%;
            max-height: 300px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .edit-mode-badge {
            font-size: 0.7rem;
            vertical-align: middle;
        }
        .dropzone {
            border: 2px dashed #dee2e6 !important;
            border-radius: 8px;
            background: #f8f9fa;
            min-height: 200px;
        }
        .dropzone .dz-message {
            color: #6c757d;
            font-weight: 400;
            margin: 2em 0;
        }
        .dropzone .dz-message i {
            font-size: 48px;
            color: #405189;
        }
        .dropzone .dz-preview .dz-image {
            border-radius: 8px;
        }
        .hidden-input {
            display: none;
        }
        .uploaded-image {
            max-width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
            border: 2px solid #dee2e6;
        }
        .image-preview-container {
            position: relative;
            display: inline-block;
        }
        .remove-image-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            z-index: 10;
            border: 2px solid white;
        }
     </style>
</head>
<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <!-- ========== Topbar Start ========== -->
          <?php include __DIR__.'/../includes/header.php' ?>
          <!-- ========== App Menu End ========== -->

          <!-- ========== App Menu Start ========== -->
          <?php include __DIR__.'/../includes/sidebar.php' ?>
          <!-- ========== App Menu End ========== -->

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
          <div class="page-content">

               <!-- Start Container Fluid -->
               <div class="container-xxl">
                
                    <!-- Show Edit Mode Indicator -->
                    <?php if ($edit_mode): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <strong><iconify-icon icon="solar:pen-2-broken" class="align-middle me-1"></iconify-icon> Edit Mode:</strong> 
                        You are editing banner: <strong><?php echo e($prefill_title); ?></strong> (ID: <?php echo e($edit_id); ?>)
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Show success/error messages -->
                    <?php if ($success): ?>
                    <div class="alert <?php echo strpos($success, 'updated') !== false ? 'alert-info' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
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

                    <form id="bannerForm" method="post" action="">
                        <input type="hidden" name="id" value="<?php echo $edit_mode ? e($edit_id) : ''; ?>">
                        <input type="hidden" id="image_url" name="image_url" value="<?php echo e($edit_mode ? $prefill_image_url : ''); ?>">

                        <div class="row">
                            <div class="col-xl-3 col-lg-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <?php echo $edit_mode ? 'Banner Preview' : 'New Banner Preview'; ?>
                                            <?php if ($edit_mode): ?>
                                            <small class="text-muted d-block mt-1">ID: <?php echo e($edit_id); ?></small>
                                            <?php endif; ?>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="bg-light text-center rounded bg-light p-3">
                                            <div id="imagePreviewContainer">
                                                <?php if ($edit_mode && $prefill_image_url): ?>
                                                <div class="image-preview-container">
                                                    <img id="bannerPreview" src="<?php echo e($prefill_image_url); ?>" alt="Banner Preview" class="uploaded-image">
                                                    <div class="remove-image-btn" onclick="removeImage()">
                                                        <iconify-icon icon="solar:trash-bin-minimalistic-broken"></iconify-icon>
                                                    </div>
                                                </div>
                                                <?php else: ?>
                                                <!-- <img id="bannerPreview" src="../assets/images/product/p-1.png" alt="Banner Preview" class="banner-preview"> -->
                                                <p class="text-muted mt-2" id="noImageText">No Banner uploaded</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <h4 id="previewTitle"><?php echo e($edit_mode ? $prefill_title : 'Banner Title'); ?></h4>
                                            <p id="previewSubtitle" class="text-muted"><?php echo e($edit_mode ? $prefill_subtitle : 'Banner subtitle will appear here'); ?></p>
                                            <div class="row">
                                                <div class="col-lg-6 col-6">
                                                    <p class="mb-1 mt-2">Type :</p>
                                                    <h5 class="mb-0" id="previewType"><?php echo e($edit_mode ? ucfirst($prefill_banner_type) : 'Homepage'); ?></h5>
                                                </div>
                                                <div class="col-lg-6 col-6">
                                                    <p class="mb-1 mt-2">Status :</p>
                                                    <h5 class="mb-0" id="previewStatus"><?php echo $edit_mode ? ($prefill_is_active ? 'Active' : 'Inactive') : 'Active'; ?></h5>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer border-top">
                                        <div class="row g-2">
                                            <div class="col-lg-12">
                                                <a href="banners-list.php" class="btn btn-outline-secondary w-100">
                                                    <iconify-icon icon="solar:arrow-left-broken" class="align-middle me-1"></iconify-icon>
                                                    Back to Banners
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-9 col-lg-8 ">
                                <div class="card">
                                    <div class="card-header">
                                        <h4 class="card-title">
                                            <?php echo $edit_mode ? 'Edit Banner' : 'Add New Banner'; ?>
                                            <?php if ($edit_mode): ?>
                                            <span class="badge bg-info edit-mode-badge">Editing</span>
                                            <?php endif; ?>
                                        </h4>
                                    </div>
                                    <div class="card-body">
                                        <!-- Image Upload with Dropzone -->
                                        <div class="mb-3">
                                            <label class="form-label">Banner Image *</label>
                                            <div class="dropzone" id="bannerDropzone">
                                                <div class="fallback">
                                                    <input name="file" type="file" />
                                                </div>
                                                <div class="dz-message needsclick">
                                                    <i class="bx bx-cloud-upload fs-48 text-primary"></i>
                                                    <h3 class="mt-4">Drop your banner image here, or <span class="text-primary">click to browse</span></h3>
                                                    <span class="text-muted fs-13">
                                                        Recommended size: 1200 x 400 pixels. JPG, PNG, GIF and WebP files are allowed (max 5MB)
                                                    </span>
                                                </div>
                                            </div>
                                            <small class="text-muted">Upload a banner image. This field is required.</small>
                                        </div>
                                        
                                        <!-- Title -->
                                        <div class="row">
                                            <div class="col-lg-6">
                                                <div class="mb-3">
                                                    <label for="title" class="form-label">Title *</label>
                                                    <input type="text" id="title" name="title" class="form-control" 
                                                           placeholder="Enter banner title" 
                                                           value="<?php echo e($edit_mode ? $prefill_title : ''); ?>">
                                                </div>
                                            </div>
                                            
                                            <!-- Subtitle -->
                                            <div class="col-lg-6">
                                                <div class="mb-3">
                                                    <label for="subtitle" class="form-label">Subtitle</label>
                                                    <input type="text" id="subtitle" name="subtitle" class="form-control" 
                                                           placeholder="Enter banner subtitle" 
                                                           value="<?php echo e($edit_mode ? $prefill_subtitle : ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Link URL -->
                                        <div class="mb-3">
                                            <label for="link_url" class="form-label">Link URL</label>
                                            <input type="text" id="link_url" name="link_url" class="form-control" 
                                                   placeholder="https://example.com/redirect-link" 
                                                   value="<?php echo e($edit_mode ? $prefill_link_url : ''); ?>">
                                            <small class="text-muted">URL where the banner should redirect when clicked</small>
                                        </div>
                                        
                                        <!-- Banner Type -->
                                        <div class="row">
                                            <div class="col-lg-6">
                                                <div class="mb-3">
                                                    <label for="banner_type" class="form-label">Banner Type</label>
                                                    <select id="banner_type" name="banner_type" class="form-control">
                                                        <option value="homepage" <?php echo ($prefill_banner_type == 'homepage') ? 'selected' : ''; ?>>Homepage</option>
                                                        <option value="category" <?php echo ($prefill_banner_type == 'category') ? 'selected' : ''; ?>>Category</option>
                                                        <option value="product" <?php echo ($prefill_banner_type == 'product') ? 'selected' : ''; ?>>Product</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <!-- Position -->
                                            <div class="col-lg-6">
                                                <div class="mb-3">
                                                    <label for="position" class="form-label">Position</label>
                                                    <input type="number" id="position" name="position" class="form-control" 
                                                           min="0" placeholder="0" 
                                                           value="<?php echo e($edit_mode ? $prefill_position : '0'); ?>">
                                                    <small class="text-muted">Lower numbers appear first</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Status -->
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
                                </div>
                                
                                <div class="p-3 bg-light mb-3 rounded">
                                    <div class="row justify-content-end g-2">
                                        <div class="col-lg-2">
                                            <button type="submit" class="btn <?php echo $edit_mode ? 'btn-success' : 'btn-primary'; ?> w-100">
                                                <iconify-icon icon="solar:<?php echo $edit_mode ? 'check-circle-broken' : 'plus-circle-broken'; ?>" class="align-middle me-1"></iconify-icon>
                                                <?php echo $edit_mode ? 'Update Banner' : 'Create Banner'; ?>
                                            </button>
                                        </div>
                                        <div class="col-lg-2">
                                            <a href="banners-list.php" class="btn btn-outline-secondary w-100">
                                                <iconify-icon icon="solar:arrow-left-broken" class="align-middle me-1"></iconify-icon>
                                                Cancel
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

               </div>
               <!-- End Container Fluid -->

               <!-- ========== Footer Start ========== -->
               <footer class="footer">
                    <div class="container-fluid">
                         <div class="row">
                              <div class="col-12 text-center">
                                   <script>document.write(new Date().getFullYear())</script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
                              </div>
                         </div>
                    </div>
               </footer>
               <!-- ========== Footer End ========== -->

          </div>
          <!-- ==================================================== -->
          <!-- End Page Content -->
          <!-- ==================================================== -->
     </div>
     <!-- END Wrapper -->

     <!-- Vendor Javascript (Require in all Page) -->
     <script src="../assets/js/vendor.js"></script>

     <!-- App Javascript (Require in all Page) -->
     <script src="../assets/js/app.js"></script>

     <!-- Dropzone JS -->
     <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.js"></script>

     <!-- Toastify JS -->
     <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

     <script>
        (function() {
            // Helper to show toast
            function showToast(msg, type) {
                var background;
                switch(type) {
                    case 'error':
                        background = "linear-gradient(to right, #dc3545, #b02a37)";
                        break;
                    case 'info':
                        background = "linear-gradient(to right, #17a2b8, #138496)";
                        break;
                    case 'success':
                    default:
                        background = "linear-gradient(to right, #28a745, #218838)";
                }
                
                Toastify({
                    text: msg,
                    duration: 4000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    stopOnFocus: true,
                    style: {
                        background: background,
                        color: "#fff"
                    }
                }).showToast();
            }

            // Show server messages on page load
            var serverSuccess = <?php echo json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            var serverError = <?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            if (serverSuccess && serverSuccess.length) {
                setTimeout(function() {
                    if (serverSuccess.includes('updated')) {
                        showToast(serverSuccess, 'info');
                    } else {
                        showToast(serverSuccess, 'success');
                    }
                }, 50);
            }
            if (serverError && serverError.length) {
                setTimeout(function() {
                    showToast(serverError, 'error');
                }, 50);
            }

            // Get DOM elements
            const form = document.getElementById('bannerForm');
            const imageUrlInput = document.getElementById('image_url');
            const titleInput = document.getElementById('title');
            const subtitleInput = document.getElementById('subtitle');
            const linkUrlInput = document.getElementById('link_url');
            const bannerTypeSelect = document.getElementById('banner_type');
            const positionInput = document.getElementById('position');
            const activeRadio = document.getElementById('active');
            const inactiveRadio = document.getElementById('inactive');
            const imagePreviewContainer = document.getElementById('imagePreviewContainer');
            const bannerPreview = document.getElementById('bannerPreview');
            const noImageText = document.getElementById('noImageText');

            // Preview elements
            const previewTitle = document.getElementById('previewTitle');
            const previewSubtitle = document.getElementById('previewSubtitle');
            const previewType = document.getElementById('previewType');
            const previewStatus = document.getElementById('previewStatus');

            // Initialize Dropzone
            Dropzone.autoDiscover = false;
            let myDropzone = null;
            
            if (document.getElementById('bannerDropzone')) {
                myDropzone = new Dropzone('#bannerDropzone', {
                    url: window.location.href,
                    paramName: "file",
                    maxFiles: 1,
                    maxFilesize: 5, // MB
                    acceptedFiles: 'image/jpeg,image/jpg,image/png,image/gif,image/webp',
                    addRemoveLinks: true,
                    dictRemoveFile: 'Remove',
                    dictCancelUpload: 'Cancel',
                    dictDefaultMessage: '',
                    dictFileTooBig: 'File is too big ({{filesize}}MB). Max filesize: {{maxFilesize}}MB.',
                    dictInvalidFileType: 'Invalid file type. Only images are allowed.',
                    init: function() {
                        // Hide fallback input
                        this.hiddenFileInput.classList.add('hidden-input');
                        
                        // If editing and image exists, create a mock file
                        <?php if ($edit_mode && $prefill_image_url): ?>
                        var mockFile = {
                            name: "<?php echo basename($prefill_image_url); ?>",
                            size: 12345,
                            accepted: true
                        };
                        this.emit("addedfile", mockFile);
                        this.emit("thumbnail", mockFile, "<?php echo e($prefill_image_url); ?>");
                        this.emit("complete", mockFile);
                        this.files.push(mockFile);
                        <?php endif; ?>
                        
                        this.on("success", function(file, response) {
                            if (response.success) {
                                // Set the image URL in hidden input
                                imageUrlInput.value = response.webPath;
                                
                                // Update preview
                                updateImagePreview(response.webPath);
                                
                                // Show success message
                                showToast('Image uploaded successfully!', 'success');
                            } else {
                                showToast(response.error || 'Upload failed', 'error');
                                this.removeFile(file);
                            }
                        });
                        
                        this.on("error", function(file, message) {
                            showToast(message || 'Upload failed', 'error');
                            this.removeFile(file);
                        });
                        
                        this.on("removedfile", function(file) {
                            // Clear the image URL
                            imageUrlInput.value = '';
                            
                            // Reset preview
                            resetImagePreview();
                        });
                        
                        // Add custom param for PHP to identify Dropzone upload
                        this.on("sending", function(file, xhr, formData) {
                            formData.append("dz_type", "banner_image");
                        });
                    }
                });
            }

            // Function to update image preview
            function updateImagePreview(imageUrl) {
                imagePreviewContainer.innerHTML = `
                    <div class="image-preview-container">
                        <img id="bannerPreview" src="${imageUrl}" alt="Banner Preview" class="uploaded-image">
                        <div class="remove-image-btn" onclick="removeImage()">
                            <iconify-icon icon="solar:trash-bin-minimalistic-broken"></iconify-icon>
                        </div>
                    </div>
                `;
                
                // Update the preview image in the form
                const previewImg = document.getElementById('bannerPreview');
                if (previewImg) {
                    previewImg.src = imageUrl;
                }
            }

            // Function to reset image preview
            function resetImagePreview() {
                imagePreviewContainer.innerHTML = `
                    <img id="bannerPreview" src="../assets/images/product/p-1.png" alt="Banner Preview" class="banner-preview">
                    <p class="text-muted mt-2" id="noImageText">No image uploaded</p>
                `;
            }

            // Function to remove image (global for button click)
            window.removeImage = function() {
                if (myDropzone && myDropzone.files.length > 0) {
                    myDropzone.removeFile(myDropzone.files[0]);
                } else {
                    imageUrlInput.value = '';
                    resetImagePreview();
                }
            };

            // Update live preview for text fields
            function updatePreview() {
                // Update title
                previewTitle.textContent = titleInput.value.trim() || 'Banner Title';
                
                // Update subtitle
                previewSubtitle.textContent = subtitleInput.value.trim() || 'Banner subtitle will appear here';
                
                // Update banner type
                const typeText = bannerTypeSelect.options[bannerTypeSelect.selectedIndex].text;
                previewType.textContent = typeText;
                
                // Update status
                previewStatus.textContent = activeRadio.checked ? 'Active' : 'Inactive';
            }

            // Event listeners for live preview
            titleInput.addEventListener('input', updatePreview);
            subtitleInput.addEventListener('input', updatePreview);
            bannerTypeSelect.addEventListener('change', updatePreview);
            activeRadio.addEventListener('change', updatePreview);
            inactiveRadio.addEventListener('change', updatePreview);

            // Initialize preview
            updatePreview();

            // Form validation before submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Basic validation
                const imageUrl = imageUrlInput.value.trim();
                const title = titleInput.value.trim();
                const bannerType = bannerTypeSelect.value;
                
                if (!imageUrl) {
                    showToast('Please upload a banner image', 'error');
                    return false;
                }
                
                if (!title) {
                    showToast('Title is required', 'error');
                    titleInput.focus();
                    return false;
                }
                
                // Validate banner type
                const validTypes = ['homepage', 'category', 'product'];
                if (!validTypes.includes(bannerType)) {
                    showToast('Please select a valid banner type', 'error');
                    bannerTypeSelect.focus();
                    return false;
                }
                
                // Validate position
                const position = parseInt(positionInput.value);
                if (position < 0) {
                    showToast('Position cannot be negative', 'error');
                    positionInput.focus();
                    return false;
                }
                
                // Validate URL format for link_url if provided
                const linkUrl = linkUrlInput.value.trim();
                if (linkUrl) {
                    try {
                        new URL(linkUrl);
                    } catch (_) {
                        showToast('Please enter a valid URL for the link', 'error');
                        linkUrlInput.focus();
                        return false;
                    }
                }
                
                // If all validations pass, submit the form
                form.submit();
            });

        })();
    </script>

</body>
</html>
<?php
// Close database connection
if ($conn) $conn->close();
?>