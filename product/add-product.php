<?php
// add-product.php (handles both add and edit)
// Single-file create/update product with variants, images, and upload handling

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
$edit_mode = false;
$product_id = 0;
$product_data = [];
$existing_images_by_variant = [];

// Utility: safe output for HTML
function e($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Check if we're in edit mode (product ID passed via GET)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    $edit_mode = true;
    
    // Fetch existing product data
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_product = $result->fetch_assoc();
    $stmt->close();
    
    if (!$existing_product) {
        $error = "Product not found!";
        $edit_mode = false;
    } else {
        // Store product data
        $product_data = $existing_product;
        
        // Fetch product variants
        $variants = [];
        $variant_stmt = $conn->prepare("SELECT * FROM product_variants WHERE product_id = ?");
        $variant_stmt->bind_param('i', $product_id);
        $variant_stmt->execute();
        $variant_result = $variant_stmt->get_result();
        while ($row = $variant_result->fetch_assoc()) {
            $variants[] = $row;
        }
        $variant_stmt->close();
        
        // Fetch product images for each variant
        foreach ($variants as $index => $variant) {
            $images = [];
            $image_stmt = $conn->prepare("SELECT * FROM product_images WHERE variant_id = ? ORDER BY position");
            $image_stmt->bind_param('i', $variant['id']);
            $image_stmt->execute();
            $image_result = $image_stmt->get_result();
            while ($img_row = $image_result->fetch_assoc()) {
                $images[] = $img_row;
            }
            $variants[$index]['images'] = $images;
            
            // Store images by variant for JavaScript
            $existing_images_by_variant[$index] = $images;
            
            $image_stmt->close();
        }
        
        // Store variants in product data
        $product_data['variants'] = $variants;
        
        // Parse metadata if exists
        if (!empty($variants[0]['metadata'])) {
            $product_data['metadata'] = json_decode($variants[0]['metadata'], true);
        } else {
            $product_data['metadata'] = [];
        }
    }
}

// Handle image uploads (for AJAX requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['product_images'])) {
    
    // Check if it's an AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        header('Content-Type: application/json');
        
        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads/products/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $file = $_FILES['product_images'];
        
        // Validate file
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
            exit;
        }
        
        // Check file size (5MB limit)
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['error' => 'File is too large. Maximum size is 5MB.']);
            exit;
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $targetPath = $uploadDir . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Return the relative path for web access
            $webPath = 'uploads/products/' . $filename;
            
            echo json_encode([
                'success' => true,
                'path' => $webPath,
                'filename' => $filename
            ]);
        } else {
            echo json_encode(['error' => 'Failed to upload file.']);
        }
        exit;
    }
}

// Fetch categories for dropdown
$categories = [];
$cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name");
if ($cat_result) {
  while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
  }
}

/* handle POST - create OR update product */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Check if this is a product submission (not an AJAX image upload)
  if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    
    // Basic product data
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $brand = isset($_POST['brand']) ? trim($_POST['brand']) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_custom_made = isset($_POST['is_custom_made']) ? 1 : 0;
    
    // Check if this is an update
    $is_update = isset($_POST['product_id']) && (int)$_POST['product_id'] > 0;
    $product_id = $is_update ? (int)$_POST['product_id'] : 0;
    
    // Get product metadata
    $product_metadata = [];
    if (isset($_POST['metadata_key']) && is_array($_POST['metadata_key'])) {
        foreach ($_POST['metadata_key'] as $index => $key) {
            if (!empty($key) && isset($_POST['metadata_value'][$index])) {
                $product_metadata[trim($key)] = trim($_POST['metadata_value'][$index]);
            }
        }
    }
    $metadata_json = !empty($product_metadata) ? json_encode($product_metadata, JSON_UNESCAPED_UNICODE) : null;
    
    // Get common prices
    $mrp = isset($_POST['mrp']) && $_POST['mrp'] !== '' ? (float)$_POST['mrp'] : null;
    $sale_price = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
    
    if ($title === '' || $category_id <= 0) {
      $error = "Product Title and Category are required.";
    } else {
      // Start transaction
      $conn->begin_transaction();
      
      try {
        if ($is_update) {
            // UPDATE existing product
            $stmt = $conn->prepare("UPDATE products SET category_id = ?, title = ?, description = ?, brand = ?, is_active = ?, is_custom_made = ?, updated_at = NOW() WHERE id = ?");
            if (!$stmt) {
              throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param('isssiii', $category_id, $title, $description, $brand, $is_active, $is_custom_made, $product_id);
            
            if (!$stmt->execute()) {
              throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $stmt->close();
            
            // Delete existing variants and images
            $delete_images = $conn->prepare("DELETE FROM product_images WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = ?)");
            $delete_images->bind_param('i', $product_id);
            $delete_images->execute();
            $delete_images->close();
            
            $delete_variants = $conn->prepare("DELETE FROM product_variants WHERE product_id = ?");
            $delete_variants->bind_param('i', $product_id);
            $delete_variants->execute();
            $delete_variants->close();
            
        } else {
            // INSERT new product
            $stmt = $conn->prepare("INSERT INTO products (category_id, title, description, brand, is_active, is_custom_made) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
              throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param('isssii', $category_id, $title, $description, $brand, $is_active, $is_custom_made);
            
            if (!$stmt->execute()) {
              throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $product_id = $stmt->insert_id;
            $stmt->close();
        }
        
        // Handle variants (one per color, with sizes array)
        if (isset($_POST['variants']) && is_array($_POST['variants'])) {
            $variant_stmt = $conn->prepare("INSERT INTO product_variants (product_id, color, sizes, stock_per_size, mrp, sale_price, stock, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$variant_stmt) {
                throw new Exception("Variant prepare failed: " . $conn->error);
            }
            
            foreach ($_POST['variants'] as $variant) {
                $color = isset($variant['color']) ? trim($variant['color']) : '';
                $color_name = isset($variant['color_name']) ? trim($variant['color_name']) : $color;
                
                // Get sizes and their stocks for this variant
                $sizes = isset($variant['sizes']) && is_array($variant['sizes']) ? $variant['sizes'] : [];
                $stocks = isset($variant['stocks']) && is_array($variant['stocks']) ? $variant['stocks'] : [];
                
                // Prepare sizes array as JSON
                $sizes_json = !empty($sizes) ? json_encode($sizes, JSON_UNESCAPED_UNICODE) : null;
                
                // Create stock_per_size as JSON object: {"S": 10, "M": 5, "L": 0}
                $stock_per_size = [];
                $total_stock = 0;
                foreach ($sizes as $index => $size) {
                    if (!empty($size)) {
                        $stock = isset($stocks[$index]) && $stocks[$index] !== '' ? (int)$stocks[$index] : 0;
                        $stock_per_size[$size] = $stock;
                        $total_stock += $stock;
                    }
                }
                $stock_per_size_json = !empty($stock_per_size) ? json_encode($stock_per_size, JSON_UNESCAPED_UNICODE) : null;
                
                $variant_stmt->bind_param('isssddds', 
                    $product_id, 
                    $color, 
                    $sizes_json, 
                    $stock_per_size_json,
                    $mrp, 
                    $sale_price, 
                    $total_stock, 
                    $metadata_json
                );
                
                if (!$variant_stmt->execute()) {
                    throw new Exception("Variant execute failed: " . $variant_stmt->error);
                }
                
                $variant_id = $variant_stmt->insert_id;
                
                // Handle variant images
                if (isset($variant['images']) && is_array($variant['images'])) {
                    $image_stmt = $conn->prepare("INSERT INTO product_images (product_id, variant_id, url, alt_text, position) VALUES (?, ?, ?, ?, ?)");
                    if (!$image_stmt) {
                        throw new Exception("Image prepare failed: " . $conn->error);
                    }
                    
                    $position_counter = 0;
                    foreach ($variant['images'] as $image_url) {
                        if (!empty($image_url)) {
                            $alt_text = $title . ' - ' . $color_name . ' image ' . ($position_counter + 1);
                            $image_stmt->bind_param('iissi', $product_id, $variant_id, $image_url, $alt_text, $position_counter);
                            $image_stmt->execute();
                            $position_counter++;
                        }
                    }
                    $image_stmt->close();
                }
            }
            $variant_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        if ($is_update) {
            $success = "Product updated successfully (ID: {$product_id}).";
            // Reload product data
            $edit_mode = true;
            // Re-fetch product data after update
            $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->bind_param('i', $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing_product = $result->fetch_assoc();
            $stmt->close();
            
            // Fetch variants again
            $variants = [];
            $variant_stmt = $conn->prepare("SELECT * FROM product_variants WHERE product_id = ?");
            $variant_stmt->bind_param('i', $product_id);
            $variant_stmt->execute();
            $variant_result = $variant_stmt->get_result();
            while ($row = $variant_result->fetch_assoc()) {
                $variants[] = $row;
            }
            $variant_stmt->close();
            
            foreach ($variants as $index => $variant) {
                $images = [];
                $image_stmt = $conn->prepare("SELECT * FROM product_images WHERE variant_id = ? ORDER BY position");
                $image_stmt->bind_param('i', $variant['id']);
                $image_stmt->execute();
                $image_result = $image_stmt->get_result();
                while ($img_row = $image_result->fetch_assoc()) {
                    $images[] = $img_row;
                }
                $variants[$index]['images'] = $images;
                $image_stmt->close();
            }
            
            $product_data = $existing_product;
            $product_data['variants'] = $variants;
            
            if (!empty($variants[0]['metadata'])) {
                $product_data['metadata'] = json_decode($variants[0]['metadata'], true);
            } else {
                $product_data['metadata'] = [];
            }
        } else {
            $success = "Product created successfully (ID: {$product_id}).";
            $insert_id = $product_id;
            // Reset form data for new entry
            $_POST = [];
        }
        
      } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error = $e->getMessage();
      }
    }
  }
}

// Get MRP and Sale Price from first variant for form population
$mrp_value = '';
$sale_price_value = '';

if ($edit_mode && isset($product_data['variants']) && count($product_data['variants']) > 0) {
    $first_variant = $product_data['variants'][0];
    $mrp_value = $first_variant['mrp'];
    $sale_price_value = $first_variant['sale_price'];
} elseif (isset($_POST['mrp'])) {
    $mrp_value = $_POST['mrp'];
} elseif (isset($_POST['sale_price'])) {
    $sale_price_value = $_POST['sale_price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit Product' : 'Add Product'; ?></title>

     <!-- App favicon -->
     <link rel="shortcut icon" href="/../assets/images/favicon.ico">

     <!-- Vendor css (Require in all Page) -->
     <link href="/../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

     <!-- Icons css (Require in all Page) -->
     <link href="/../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

     <!-- App css (Require in all Page) -->
     <link href="/../assets/css/app.min.css" rel="stylesheet" type="text/css" />

     <!-- Toastify CSS -->
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

     <!-- Theme Config js (Require in all Page) -->
     <script src="/../assets/js/config.js"></script>

     <style>
        .toastify {
            z-index: 20000;
        }
        .variant-row {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        .variant-images-container {
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        .variant-image-dropzone {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        .variant-image-dropzone:hover {
            border-color: #0d6efd;
            background: #f0f8ff;
        }
        .variant-image-dropzone.dragover {
            border-color: #198754;
            background: #f0fff4;
        }
        .variant-image-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .variant-image-preview {
            width: 100px;
            height: 120px;
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #dee2e6;
            background: white;
        }
        .variant-image-preview img {
            width: 100%;
            height: 80px;
            object-fit: cover;
        }
        .variant-image-info {
            padding: 5px;
            font-size: 11px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            text-align: center;
            word-break: break-all;
        }
        .remove-variant-image {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 10px;
            border: none;
            z-index: 10;
        }
        .image-counter {
            font-size: 11px;
            color: #6c757d;
            margin-top: 5px;
        }
        .color-picker-container {
            position: relative;
        }
        .selected-colors {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            min-height: 40px;
        }
        .color-chip {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            border: 2px solid #ddd;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.5);
            transition: all 0.3s;
        }
        .color-chip:hover {
            transform: scale(1.1);
            z-index: 10;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .color-chip:hover::after {
            content: '×';
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
        }
        .variant-info {
            background: #e9ecef;
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .variant-info-text {
            font-weight: 500;
        }
        .metadata-row {
            margin-bottom: 10px;
        }
        #mainImagePreview {
            max-width: 100%;
            object-fit: contain;
            transition: opacity 0.3s ease;
        }
        .preview-image-container {
            position: relative;
            display: inline-block;
            overflow: hidden;
            border-radius: 8px;
        }
        .preview-image-container .image-count {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        .color-picker-popup {
            position: fixed;
            z-index: 1050;
            background: white;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 350px;
            display: none;
        }
        .color-picker-popup.show {
            display: block;
        }
        .color-input-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        .color-swatch-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-top: 15px;
        }
        .color-swatch {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            cursor: pointer;
            border: 2px solid #eee;
            position: relative;
            transition: all 0.2s;
        }
        .color-swatch:hover {
            transform: scale(1.05);
            border-color: #0d6efd;
        }
        .color-swatch.selected {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
        }
        .color-swatch-label {
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 10px;
            white-space: nowrap;
            color: #666;
            font-weight: 500;
        }
        .variant-size-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .size-checkbox-wrapper {
            position: relative;
        }
        .size-checkbox-wrapper input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        .size-checkbox-wrapper label {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 50px;
            text-align: center;
            border: 2px solid #dee2e6;
            font-weight: 500;
        }
        .size-checkbox-wrapper input[type="checkbox"]:checked + label {
            background: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        .color-swatch-inner {
            width: 100%;
            height: 100%;
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: bold;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.5);
        }
        .color-name-badge {
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            position: absolute;
            top: 2px;
            left: 2px;
            right: 2px;
            text-align: center;
        }
        .size-stock-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        .size-label {
            min-width: 60px;
            font-weight: 500;
            text-align: center;
        }
        .stock-input {
            width: 100px;
        }
        .size-stock-row {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .selected-sizes-container {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        .no-sizes-selected {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-style: italic;
        }
        .color-image-mapping {
            display: none;
        }
        .hover-image-preview {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 8px;
        }
        .hover-image-preview.active {
            opacity: 1;
        }
        .existing-images-note {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 10px;
        }
     </style>
</head>
<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <!-- ========== Topbar Start ========== -->
          <?php include __DIR__.'/../includes/header.php' ?>

          <!-- ========== App Menu Start ========== -->
          <?php include __DIR__.'/../includes/sidebar.php' ?>
          <!-- ========== App Menu End ========== -->

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
          <div class="page-content">

               <!-- Start Container Fluid -->
               <div class="container-xxl">

                    <!-- Display Messages -->
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

                    <form method="post" enctype="multipart/form-data" id="productForm">
                    <?php if ($edit_mode): ?>
                    <input type="hidden" name="product_id" value="<?php echo e($product_id); ?>">
                    <?php endif; ?>
                    <div class="row">
                         <div class="col-xl-3 col-lg-4">
                              <div class="card">
                                   <div class="card-body">
                                        <div id="imagePreview" class="text-center">
                                            <div class="preview-image-container">
                                                <!-- Default preview image -->
                                                <img src="../assets/images/placeholder.png" alt="" class="img-fluid rounded bg-light" id="mainImagePreview" style="max-height: 200px;">
                                                
                                                <!-- Hover preview images will be dynamically added here -->
                                                
                                                <div class="image-count" id="imageCount" style="display: none;">0 images</div>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                             <h4 id="liveTitle"><?php echo $edit_mode ? e($product_data['title']) : 'Product Title'; ?></h4>
                                             <h5 class="text-dark fw-medium mt-3">Price :</h5>
                                             <h4 class="fw-semibold text-dark mt-2 d-flex align-items-center gap-2">
                                                  <span class="text-muted text-decoration-line-through" id="liveMrp">₹0</span> 
                                                  <span id="livePrice">₹0</span>
                                                  <small class="text-muted" id="liveDiscount"> (0% Off)</small>
                                             </h4>
                                             <div class="mt-3">
                                                  <h5 class="text-dark fw-medium">Available Colors :</h5>
                                                  <div class="d-flex flex-wrap gap-2" id="liveColors">
                                                       <?php if ($edit_mode && isset($product_data['variants']) && count($product_data['variants']) > 0): ?>
                                                       <?php foreach ($product_data['variants'] as $variant): ?>
                                                       <span class="badge me-1" style="background-color: <?php echo e($variant['color']); ?>; color: white;">
                                                           <?php echo e(isset($variant['color_name']) ? $variant['color_name'] : $variant['color']); ?>
                                                       </span>
                                                       <?php endforeach; ?>
                                                       <?php else: ?>
                                                       <span class="text-muted">No colors selected</span>
                                                       <?php endif; ?>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                                   <div class="card-footer bg-light-subtle">
                                        <div class="row g-2">
                                             <div class="col-lg-6">
                                                  <button type="submit" class="btn btn-primary w-100">
                                                      <?php echo $edit_mode ? 'Update Product' : 'Create Product'; ?>
                                                  </button>
                                             </div>
                                             <div class="col-lg-6">
                                                  <a href="list-products.php" class="btn btn-outline-secondary w-100">Cancel</a>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                         </div>

                         <div class="col-xl-9 col-lg-8 ">
                              <div class="card">
                                   <div class="card-header">
                                        <h4 class="card-title"><?php echo $edit_mode ? 'Edit Product Information' : 'Product Information'; ?></h4>
                                   </div>
                                   <div class="card-body">
                                        <div class="row">
                                             <div class="col-lg-6">
                                                  <div class="mb-3">
                                                       <label for="title" class="form-label">Product Name *</label>
                                                       <input type="text" id="title" name="title" class="form-control" placeholder="Items Name" required 
                                                              value="<?php echo isset($_POST['title']) ? e($_POST['title']) : ($edit_mode ? e($product_data['title']) : ''); ?>" 
                                                              oninput="document.getElementById('liveTitle').textContent = this.value || 'Product Title'">
                                                  </div>
                                             </div>
                                             <div class="col-lg-6">
                                                  <label for="category_id" class="form-label">Product Categories *</label>
                                                  <select class="form-control" id="category_id" name="category_id" required>
                                                       <option value="">Choose a category</option>
                                                       <?php foreach ($categories as $cat): ?>
                                                       <?php 
                                                       $selected = false;
                                                       if ($edit_mode && isset($product_data['category_id']) && $product_data['category_id'] == $cat['id']) {
                                                           $selected = true;
                                                       } elseif (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) {
                                                           $selected = true;
                                                       }
                                                       ?>
                                                       <option value="<?php echo e($cat['id']); ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                           <?php echo e($cat['name']); ?>
                                                       </option>
                                                       <?php endforeach; ?>
                                                  </select>
                                             </div>
                                        </div>
                                        <div class="row">
                                             <div class="col-lg-6">
                                                  <div class="mb-3">
                                                       <label for="brand" class="form-label">Brand</label>
                                                       <input type="text" id="brand" name="brand" class="form-control" placeholder="Brand Name"
                                                              value="<?php echo isset($_POST['brand']) ? e($_POST['brand']) : ($edit_mode ? e($product_data['brand']) : ''); ?>">
                                                  </div>
                                             </div>
                                             <div class="col-lg-6">
                                                  <div class="row">
                                                       <div class="col-6">
                                                            <div class="form-check mt-4">
                                                                 <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" 
                                                                 <?php 
                                                                 $checked = false;
                                                                 if ($edit_mode && isset($product_data['is_active']) && $product_data['is_active'] == 1) {
                                                                     $checked = true;
                                                                 } elseif (!isset($_POST['is_active']) && !$edit_mode) {
                                                                     $checked = true; // Default checked for new products
                                                                 } elseif (isset($_POST['is_active'])) {
                                                                     $checked = true;
                                                                 }
                                                                 echo $checked ? 'checked' : '';
                                                                 ?>>
                                                                 <label class="form-check-label" for="is_active">Active</label>
                                                            </div>
                                                       </div>
                                                       <div class="col-6">
                                                            <div class="form-check mt-4">
                                                                 <input type="checkbox" class="form-check-input" id="is_custom_made" name="is_custom_made" value="1"
                                                                 <?php 
                                                                 $checked = false;
                                                                 if ($edit_mode && isset($product_data['is_custom_made']) && $product_data['is_custom_made'] == 1) {
                                                                     $checked = true;
                                                                 } elseif (isset($_POST['is_custom_made'])) {
                                                                     $checked = true;
                                                                 }
                                                                 echo $checked ? 'checked' : '';
                                                                 ?>>
                                                                 <label class="form-check-label" for="is_custom_made">Custom Made</label>
                                                            </div>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                        
                                        <!-- Common Prices Section -->
                                        <div class="row mb-4">
                                             <div class="col-lg-6">
                                                  <div class="mb-3">
                                                       <label for="mrp" class="form-label">MRP *</label>
                                                       <input type="number" step="0.01" id="mrp" name="mrp" class="form-control" placeholder="MRP" required 
                                                              value="<?php echo e($mrp_value); ?>" oninput="updateLivePreview()">
                                                  </div>
                                             </div>
                                             <div class="col-lg-6">
                                                  <div class="mb-3">
                                                       <label for="sale_price" class="form-label">Sale Price *</label>
                                                       <input type="number" step="0.01" id="sale_price" name="sale_price" class="form-control" placeholder="Sale Price" required
                                                              value="<?php echo e($sale_price_value); ?>" oninput="updateLivePreview()">
                                                  </div>
                                             </div>
                                        </div>
                                        
                                        <div class="row mb-4">
                                             <div class="col-lg-12">
                                                  <div class="mt-3">
                                                       <h5 class="text-dark fw-medium">Colors :</h5>
                                                       <div class="color-picker-container">
                                                           <button type="button" class="btn btn-outline-primary mb-2" id="pickColorsBtn">
                                                               <i class="bx bx-palette me-1"></i> Pick Colors
                                                           </button>
                                                           
                                                           <div class="selected-colors" id="selectedColors">
                                                               <!-- Selected colors will appear here -->
                                                           </div>
                                                           
                                                           <!-- Color Picker Popup -->
                                                           <div class="color-picker-popup" id="colorPickerPopup">
                                                               <div class="d-flex justify-content-between align-items-center mb-3">
                                                                   <h6 class="mb-0">Select Colors</h6>
                                                                   <button type="button" class="btn-close" onclick="closeColorPicker()"></button>
                                                               </div>
                                                               
                                                               <div class="color-input-group">
                                                                   <div class="d-flex gap-2">
                                                                       <input type="color" id="colorInput" class="form-control form-control-color" value="#000000" title="Choose your color">
                                                                       <input type="text" id="colorHex" class="form-control" placeholder="#000000" value="#000000">
                                                                   </div>
                                                                   <div class="w-100">
                                                                       <input type="text" id="colorName" class="form-control mt-2" placeholder="Color Name (e.g., Red, Blue)" value="">
                                                                   </div>
                                                                   <button type="button" class="btn btn-primary" onclick="addColor()">Add Color</button>
                                                               </div>
                                                               
                                                               <div class="mb-3">
                                                                   <label class="form-label fw-medium">Predefined Colors:</label>
                                                                   <div class="color-swatch-grid" id="predefinedColors">
                                                                       <?php
                                                                       $predefinedColors = [
                                                                           ['#000000', 'Black'],
                                                                           ['#ffffff', 'White'],
                                                                           ['#dc3545', 'Red'],
                                                                           ['#0d6efd', 'Blue'],
                                                                           ['#198754', 'Green'],
                                                                           ['#ffc107', 'Yellow'],
                                                                           ['#6c757d', 'Gray'],
                                                                           ['#6610f2', 'Purple'],
                                                                           ['#fd7e14', 'Orange'],
                                                                           ['#20c997', 'Teal'],
                                                                           ['#0dcaf0', 'Cyan'],
                                                                           ['#6f42c1', 'Indigo'],
                                                                           ['#d63384', 'Pink'],
                                                                           ['#adb5bd', 'Light Gray'],
                                                                           ['#ff6b6b', 'Coral'],
                                                                           ['#4ecdc4', 'Turquoise'],
                                                                           ['#45b7d1', 'Sky Blue'],
                                                                           ['#96ceb4', 'Mint'],
                                                                           ['#feca57', 'Gold'],
                                                                           ['#ff9ff3', 'Light Pink'],
                                                                           ['#8B4513', 'Brown'],
                                                                           ['#800080', 'Purple'],
                                                                           ['#FF69B4', 'Hot Pink'],
                                                                           ['#00CED1', 'Dark Turquoise'],
                                                                           ['#228B22', 'Forest Green']
                                                                       ];
                                                                       foreach ($predefinedColors as $colorInfo):
                                                                           $contrastColor = getContrastColor($colorInfo[0]);
                                                                       ?>
                                                                       <div class="color-swatch" 
                                                                            data-color="<?php echo e($colorInfo[0]); ?>" 
                                                                            data-name="<?php echo e($colorInfo[1]); ?>"
                                                                            onclick="selectPredefinedColor(this, '<?php echo e($colorInfo[0]); ?>', '<?php echo e($colorInfo[1]); ?>')">
                                                                            <div class="color-swatch-inner" style="background-color: <?php echo e($colorInfo[0]); ?>; color: <?php echo e($contrastColor); ?>;">
                                                                                <div class="color-name-badge"><?php echo e($colorInfo[1]); ?></div>
                                                                            </div>
                                                                       </div>
                                                                       <?php endforeach; ?>
                                                                   </div>
                                                               </div>
                                                               
                                                               <div class="d-flex justify-content-between mt-3 pt-3 border-top">
                                                                   <button type="button" class="btn btn-success" onclick="addSelectedColors()">Add Selected Colors</button>
                                                                   <button type="button" class="btn btn-secondary" onclick="closeColorPicker()">Close</button>
                                                               </div>
                                                           </div>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                        
                                        <div class="row">
                                             <div class="col-lg-12">
                                                  <div class="mb-3">
                                                       <label for="description" class="form-label">Description</label>
                                                       <textarea class="form-control bg-light-subtle" id="description" name="description" rows="7" placeholder="Short description about the product"><?php echo isset($_POST['description']) ? e($_POST['description']) : ($edit_mode ? e($product_data['description']) : ''); ?></textarea>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              
                              <!-- Product Metadata Section (Only once per product, same for all variants) -->
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center">
                                        <h4 class="card-title mb-0">Product Metadata (Additional Information - Same for all variants)</h4>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addProductMetadataField()">Add Field</button>
                                   </div>
                                   <div class="card-body">
                                        <div id="productMetadataContainer">
                                            <?php if ($edit_mode && isset($product_data['metadata']) && count($product_data['metadata']) > 0): ?>
                                                <?php foreach ($product_data['metadata'] as $key => $value): ?>
                                                <div class="metadata-row row" id="productMetadataField<?php echo $key; ?>">
                                                    <div class="col-md-5">
                                                        <input type="text" class="form-control" name="metadata_key[]" placeholder="Field Name (e.g., Material, Weight)" value="<?php echo e($key); ?>">
                                                    </div>
                                                    <div class="col-md-5">
                                                        <input type="text" class="form-control" name="metadata_value[]" placeholder="Field Value (e.g., Cotton, 500g)" value="<?php echo e($value); ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeProductMetadataField('<?php echo $key; ?>')">Remove</button>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="alert alert-info">
                                                    Add additional information about the product that will be same for all variants (e.g., Material, Weight, Care Instructions)
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                   </div>
                              </div>
                              
                              <!-- Variants Section - Each variant has multiple sizes with individual stock -->
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center">
                                        <h4 class="card-title mb-0">Product Variants (Sizes, Stock & Images)</h4>
                                        <small class="text-muted">Each color variant can have multiple sizes with different stock levels</small>
                                   </div>
                                   <div class="card-body">
                                        <div id="variantContainer">
                                             <!-- Variants will be auto-generated here -->
                                             <div class="alert alert-info" id="noVariantsMessage">
                                                 <?php echo $edit_mode && isset($product_data['variants']) && count($product_data['variants']) > 0 ? 'Product variants loaded' : 'Select colors above to generate variants'; ?>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              
                              <div class="p-3 bg-light mb-3 rounded">
                                   <div class="row justify-content-end g-2">
                                        <div class="col-lg-2">
                                             <button type="submit" class="btn btn-primary w-100">
                                                 <?php echo $edit_mode ? 'Update Product' : 'Create Product'; ?>
                                             </button>
                                        </div>
                                        <div class="col-lg-2">
                                             <a href="list-products.php" class="btn btn-outline-secondary w-100">Cancel</a>
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

     <!-- Toastify JS -->
     <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

     <script>
        let variantCount = 0;
        let metadataCount = 0;
        let selectedColors = []; // Array of objects: {color: '#000000', name: 'Black'}
        let currentMainImageIndex = 0;
        let uploadedImagesByVariant = {}; // Object to store images per variant: { variantId: [{path, previewId}] }
        let tempSelectedColors = []; // Temporary storage for colors selected in picker
        let selectedSizesByVariant = {}; // Track selected sizes for each variant: { variantId: [{size: 'M', stock: 10}] }
        let colorImageMap = {}; // Maps color hex to array of image URLs: { '#000000': ['image1.jpg', 'image2.jpg'] }
        
        // PHP helper function for contrast color
        <?php 
        function getContrastColor($hexcolor) {
            $hexcolor = str_replace("#", "", $hexcolor);
            if (strlen($hexcolor) === 3) {
                $hexcolor = $hexcolor[0].$hexcolor[0].$hexcolor[1].$hexcolor[1].$hexcolor[2].$hexcolor[2];
            }
            $r = hexdec(substr($hexcolor, 0, 2));
            $g = hexdec(substr($hexcolor, 2, 2));
            $b = hexdec(substr($hexcolor, 4, 2));
            $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
            return ($yiq >= 128) ? '#000000' : '#ffffff';
        }
        ?>
        
        // Pre-populate data if in edit mode
        <?php if ($edit_mode && isset($product_data['variants'])): ?>
        selectedColors = [
            <?php foreach ($product_data['variants'] as $variant): ?>
            {
                color: '<?php echo e($variant['color']); ?>',
                name: '<?php echo e(isset($variant['color_name']) ? $variant['color_name'] : $variant['color']); ?>'
            },
            <?php endforeach; ?>
        ];
        
        // Store existing images by variant index
        <?php foreach ($existing_images_by_variant as $variantIndex => $images): ?>
        uploadedImagesByVariant[<?php echo $variantIndex; ?>] = [];
        <?php foreach ($images as $imgIndex => $image): ?>
        uploadedImagesByVariant[<?php echo $variantIndex; ?>].push({
            id: 'existing_<?php echo $variantIndex; ?>_<?php echo $imgIndex; ?>',
            path: '<?php echo e($image['url']); ?>',
            filename: '<?php echo e(basename($image['url'])); ?>'
        });
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>
        
        // Color picker functions
        function openColorPicker() {
            const popup = document.getElementById('colorPickerPopup');
            popup.style.display = 'block';
            popup.classList.add('show');
            
            // Center the popup
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const popupWidth = popup.offsetWidth;
            const popupHeight = popup.offsetHeight;
            
            popup.style.left = (viewportWidth - popupWidth) / 2 + 'px';
            popup.style.top = (viewportHeight - popupHeight) / 2 + 'px';
            
            // Close picker when clicking outside
            setTimeout(() => {
                document.addEventListener('click', closeColorPickerOnClickOutside);
            }, 10);
            
            // Reset temp selection
            tempSelectedColors = [];
            updateColorPickerSelection();
            
            // Set focus to color name input
            document.getElementById('colorName').focus();
        }
        
        function closeColorPickerOnClickOutside(event) {
            const popup = document.getElementById('colorPickerPopup');
            const button = document.getElementById('pickColorsBtn');
            
            if (!popup.contains(event.target) && event.target !== button) {
                closeColorPicker();
            }
        }
        
        function closeColorPicker() {
            const popup = document.getElementById('colorPickerPopup');
            popup.classList.remove('show');
            setTimeout(() => {
                popup.style.display = 'none';
            }, 300);
            document.removeEventListener('click', closeColorPickerOnClickOutside);
        }
        
        function selectPredefinedColor(element, color, name) {
            // Toggle selection
            if (element.classList.contains('selected')) {
                element.classList.remove('selected');
                // Remove from temp selection
                const index = tempSelectedColors.findIndex(c => c.color === color);
                if (index > -1) {
                    tempSelectedColors.splice(index, 1);
                }
            } else {
                element.classList.add('selected');
                // Add to temp selection
                tempSelectedColors.push({
                    color: color,
                    name: name
                });
            }
        }
        
        function addSelectedColors() {
            if (tempSelectedColors.length === 0) {
                Toastify({
                    text: "Please select at least one color",
                    duration: 3000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #ffc107, #e0a800)",
                        color: "#000"
                    }
                }).showToast();
                return;
            }
            
            let addedCount = 0;
            tempSelectedColors.forEach(colorObj => {
                // Check if color already exists (by hex value)
                const exists = selectedColors.some(c => c.color === colorObj.color);
                if (!exists) {
                    selectedColors.push({
                        color: colorObj.color,
                        name: colorObj.name
                    });
                    addedCount++;
                }
            });
            
            if (addedCount > 0) {
                updateSelectedColorsDisplay();
                updateLivePreview();
                generateVariants();
                
                Toastify({
                    text: addedCount + " color(s) added",
                    duration: 3000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #0d6efd, #0b5ed7)",
                        color: "#fff"
                    }
                }).showToast();
            }
            
            // Clear temp selection
            tempSelectedColors = [];
            updateColorPickerSelection();
            closeColorPicker();
        }
        
        function updateColorPickerSelection() {
            // Clear all selections
            document.querySelectorAll('.color-swatch').forEach(swatch => {
                swatch.classList.remove('selected');
            });
            
            // Mark already selected colors
            selectedColors.forEach(colorObj => {
                const swatch = document.querySelector(`.color-swatch[data-color="${colorObj.color}"]`);
                if (swatch) {
                    swatch.classList.add('selected');
                }
            });
        }
        
        function addColor() {
            const colorInput = document.getElementById('colorInput');
            const colorHex = document.getElementById('colorHex');
            const colorNameInput = document.getElementById('colorName');
            
            let color = colorHex.value.trim();
            let colorName = colorNameInput.value.trim();
            
            // Validate hex color
            if (!color.startsWith('#')) {
                color = '#' + color;
            }
            
            // Validate hex format
            const hexRegex = /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/;
            if (!hexRegex.test(color)) {
                alert('Please enter a valid hex color code (e.g., #FF0000 or #F00)');
                return;
            }
            
            // If it's a 3-digit hex, convert to 6-digit
            if (color.length === 4) {
                color = '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
            }
            
            color = color.toUpperCase();
            
            // Generate color name if not provided
            if (!colorName) {
                colorName = getColorNameFromHex(color);
            }
            
            // Check if color already exists (by hex value)
            const exists = selectedColors.some(c => c.color === color);
            if (!exists) {
                selectedColors.push({
                    color: color,
                    name: colorName
                });
                updateSelectedColorsDisplay();
                updateLivePreview();
                generateVariants();
                
                // Reset inputs
                colorInput.value = '#000000';
                colorHex.value = '#000000';
                colorNameInput.value = '';
                
                Toastify({
                    text: "Color added: " + colorName,
                    duration: 3000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #0d6efd, #0b5ed7)",
                        color: "#fff"
                    }
                }).showToast();
                
                closeColorPicker();
            } else {
                Toastify({
                    text: "Color already selected",
                    duration: 3000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #ffc107, #e0a800)",
                        color: "#000"
                    }
                }).showToast();
            }
        }
        
        function getColorNameFromHex(hexColor) {
            // Simple mapping of common colors
            const colorMap = {
                '#000000': 'Black',
                '#FFFFFF': 'White',
                '#FF0000': 'Red',
                '#00FF00': 'Green',
                '#0000FF': 'Blue',
                '#FFFF00': 'Yellow',
                '#FF00FF': 'Magenta',
                '#00FFFF': 'Cyan',
                '#FFA500': 'Orange',
                '#800080': 'Purple',
                '#A52A2A': 'Brown',
                '#808080': 'Gray',
                '#FFC0CB': 'Pink',
                '#FFD700': 'Gold',
                '#C0C0C0': 'Silver',
                '#DC3545': 'Red',
                '#0D6EFD': 'Blue',
                '#198754': 'Green',
                '#FFC107': 'Yellow',
                '#6C757D': 'Gray',
                '#6610F2': 'Purple',
                '#FD7E14': 'Orange',
                '#20C997': 'Teal',
                '#0DCAF0': 'Cyan',
                '#6F42C1': 'Indigo',
                '#D63384': 'Pink'
            };
            
            return colorMap[hexColor.toUpperCase()] || 'Custom Color';
        }
        
        function getContrastColor(hexcolor) {
            hexcolor = hexcolor.replace("#", "");
            if (hexcolor.length === 3) {
                hexcolor = hexcolor.split('').map(char => char + char).join('');
            }
            const r = parseInt(hexcolor.substr(0,2),16);
            const g = parseInt(hexcolor.substr(2,2),16);
            const b = parseInt(hexcolor.substr(4,2),16);
            const yiq = ((r*299)+(g*587)+(b*114))/1000;
            return (yiq >= 128) ? '#000000' : '#ffffff';
        }
        
        function updateSelectedColorsDisplay() {
            const container = document.getElementById('selectedColors');
            container.innerHTML = '';
            
            if (selectedColors.length === 0) {
                container.innerHTML = '<div class="text-muted">No colors selected</div>';
                return;
            }
            
            selectedColors.forEach((colorObj, index) => {
                const colorChip = document.createElement('div');
                colorChip.className = 'color-chip';
                colorChip.style.backgroundColor = colorObj.color;
                colorChip.title = colorObj.name + ' (' + colorObj.color + ') - Click to remove';
                colorChip.textContent = colorObj.name.substring(0, 3); // Show first 3 chars of name
                colorChip.style.color = getContrastColor(colorObj.color);
                colorChip.dataset.color = colorObj.color;
                colorChip.dataset.index = index;
                
                // Add hover event for image preview
                colorChip.addEventListener('mouseenter', function() {
                    showColorImagePreview(colorObj.color);
                });
                
                // Add mouseleave event to return to default
                colorChip.addEventListener('mouseleave', function() {
                    showDefaultImagePreview();
                });
                
                // Click to remove
                colorChip.onclick = () => removeColor(index);
                
                container.appendChild(colorChip);
            });
        }
        
        function showColorImagePreview(colorHex) {
            const previewContainer = document.getElementById('imagePreview');
            const previewImages = previewContainer.querySelectorAll('.hover-image-preview');
            const mainImage = document.getElementById('mainImagePreview');
            
            // Hide all hover images
            previewImages.forEach(img => {
                img.classList.remove('active');
            });
            
            // Show the image for this color
            const colorImage = document.getElementById(`hoverImage_${colorHex}`);
            if (colorImage) {
                // Hide main image
                mainImage.style.opacity = '0';
                
                // Show color image
                setTimeout(() => {
                    colorImage.classList.add('active');
                }, 10);
            }
        }
        
        function showDefaultImagePreview() {
            const previewContainer = document.getElementById('imagePreview');
            const previewImages = previewContainer.querySelectorAll('.hover-image-preview');
            const mainImage = document.getElementById('mainImagePreview');
            
            // Hide all hover images
            previewImages.forEach(img => {
                img.classList.remove('active');
            });
            
            // Show main image
            mainImage.style.opacity = '1';
        }
        
        function removeColor(index) {
            const removedColor = selectedColors[index];
            selectedColors.splice(index, 1);
            updateSelectedColorsDisplay();
            updateLivePreview();
            generateVariants();
            
            // Remove hover image for this color
            const hoverImage = document.getElementById(`hoverImage_${removedColor.color}`);
            if (hoverImage) {
                hoverImage.remove();
            }
            
            // Update main preview
            updateMainImagePreview();
            
            Toastify({
                text: "Color removed: " + removedColor.name,
                duration: 3000,
                close: true,
                gravity: "top",
                position: "right",
                style: {
                    background: "linear-gradient(to right, #dc3545, #b02a37)",
                    color: "#fff"
                }
            }).showToast();
        }
        
        // Generate variants based on selected colors
        function generateVariants() {
            const container = document.getElementById('variantContainer');
            const noVariantsMessage = document.getElementById('noVariantsMessage');
            
            // Clear existing variants
            container.innerHTML = '';
            
            if (selectedColors.length === 0) {
                container.innerHTML = '<div class="alert alert-info" id="noVariantsMessage">Select colors above to generate variants</div>';
                updateLivePreview();
                return;
            }
            
            // Generate one variant for each color
            variantCount = 0;
            selectedColors.forEach((colorObj, index) => {
                addVariant(colorObj, index);
            });
            
            // Update live preview after generating variants
            setTimeout(updateLivePreview, 100);
        }
        
        function addVariant(colorObj, variantIndex) {
            const container = document.getElementById('variantContainer');
            const variantId = variantIndex;
            
            // Initialize images array for this variant
            if (!uploadedImagesByVariant[variantId]) {
                uploadedImagesByVariant[variantId] = [];
            }
            
            // Initialize sizes array for this variant
            if (!selectedSizesByVariant[variantId]) {
                selectedSizesByVariant[variantId] = [];
            }
            
            // Remove no variants message if it exists
            const noVariantsMessage = document.getElementById('noVariantsMessage');
            if (noVariantsMessage) {
                noVariantsMessage.remove();
            }
            
            // Available sizes
            const availableSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
            
            // Check if we have existing data for this variant
            let existingSizes = [];
            let existingStocks = [];
            <?php if ($edit_mode && isset($product_data['variants'])): ?>
            <?php foreach ($product_data['variants'] as $index => $variant): ?>
            if (variantIndex === <?php echo $index; ?> && '<?php echo e($variant['color']); ?>' === colorObj.color) {
                <?php 
                // Parse sizes from JSON
                if (!empty($variant['sizes'])) {
                    $sizes = json_decode($variant['sizes'], true);
                    if ($sizes && is_array($sizes)) {
                        foreach ($sizes as $size) {
                            echo "existingSizes.push('" . e($size) . "');";
                        }
                    }
                }
                
                // Parse stock_per_size from JSON
                if (!empty($variant['stock_per_size'])) {
                    $stockData = json_decode($variant['stock_per_size'], true);
                    if ($stockData && is_array($stockData)) {
                        foreach ($stockData as $size => $stock) {
                            echo "existingStocks.push({size: '" . e($size) . "', stock: " . intval($stock) . "});";
                        }
                    }
                }
                ?>
            }
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Create variant info text
            const variantInfo = `Color: <span style="color:${colorObj.color}"><strong>${colorObj.name}</strong></span>`;
            const variantLabel = `${colorObj.name}`;
            
            // Generate size checkboxes HTML
            const sizeCheckboxesHTML = availableSizes.map(size => `
                <div class="size-checkbox-wrapper">
                    <input type="checkbox" class="btn-check" id="variant${variantId}_size_${size.toLowerCase()}" 
                           ${existingSizes.includes(size) ? 'checked' : ''}
                           onchange="handleSizeSelection(${variantId}, '${size}', this.checked)">
                    <label class="btn btn-light avatar-sm rounded d-flex justify-content-center align-items-center" 
                           for="variant${variantId}_size_${size.toLowerCase()}">${size}</label>
                </div>
            `).join('');
            
            const variantHtml = `
            <div class="variant-row" id="variant${variantId}">
                <div class="variant-info">
                    <div class="variant-info-text">${variantInfo}</div>
                    <small class="text-muted">Variant: ${variantLabel}</small>
                </div>
                
                <!-- Size selection for this variant -->
                <div class="mt-3">
                    <h6 class="text-dark fw-medium">Select Sizes for ${colorObj.name}:</h6>
                    <div class="variant-size-picker" role="group" aria-label="Basic checkbox toggle button group">
                        ${sizeCheckboxesHTML}
                    </div>
                </div>
                
                <!-- Selected sizes with stock inputs -->
                <div class="selected-sizes-container" id="selectedSizesContainer${variantId}">
                    <h6 class="fw-medium mb-3">Set Stock for Selected Sizes:</h6>
                    <div id="sizeStockContainer${variantId}" class="${existingSizes.length === 0 ? 'no-sizes-selected' : ''}">
                        ${existingSizes.length === 0 ? 'No sizes selected yet. Select sizes above to set stock levels.' : ''}
                    </div>
                </div>
                
                <!-- Hidden inputs for color -->
                <input type="hidden" name="variants[${variantId}][color]" value="${colorObj.color}">
                <input type="hidden" name="variants[${variantId}][color_name]" value="${colorObj.name}">
                
                <!-- Variant-specific image upload section -->
                <div class="variant-images-container">
                    <h6 class="mb-3">Variant Images (Max 5 images)</h6>
                    <?php if ($edit_mode): ?>
                    <div class="existing-images-note">
                        <small class="text-muted">Existing images are shown below. You can add new images or remove existing ones.</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="variant-image-dropzone" id="dropzone${variantId}" onclick="document.getElementById('fileInput${variantId}').click()">
                        <i class="bx bx-cloud-upload fs-24 text-primary mb-2"></i>
                        <h6 class="mb-1">Drop images here or click to browse</h6>
                        <span class="text-muted" style="font-size: 12px;">
                            Upload images specific to this variant (${variantLabel})
                        </span>
                    </div>
                    
                    <!-- Hidden file input for this variant -->
                    <input type="file" id="fileInput${variantId}" multiple accept="image/*" style="display: none;" onchange="handleVariantFiles(this.files, ${variantId})">
                    
                    <!-- Image counter -->
                    <div class="image-counter" id="imageCounter${variantId}">
                        ${uploadedImagesByVariant[variantId] ? uploadedImagesByVariant[variantId].length : 0} / 5 images uploaded
                    </div>
                    
                    <!-- Image previews container for this variant -->
                    <div class="variant-image-previews" id="variantPreviews${variantId}"></div>
                </div>
            </div>`;
            
            container.insertAdjacentHTML('beforeend', variantHtml);
            
            // Setup drag and drop for this variant
            setupVariantDragAndDrop(variantId);
            
            // Load existing images for this variant
            if (uploadedImagesByVariant[variantId] && uploadedImagesByVariant[variantId].length > 0) {
                loadExistingImages(variantId);
            }
            
            // Initialize existing sizes and stocks
            if (existingSizes.length > 0) {
                existingSizes.forEach((size, index) => {
                    // Find existing stock for this size
                    const existingStock = existingStocks.find(s => s.size === size);
                    const stockValue = existingStock ? existingStock.stock : 0;
                    
                    // Add size to selected sizes
                    selectedSizesByVariant[variantId].push({
                        size: size,
                        stock: stockValue
                    });
                    
                    // Create stock input HTML
                    const stockInputId = `variant${variantId}_stock_${size}`;
                    const sizeStockHTML = `
                    <div class="size-stock-row" id="sizeStockRow${variantId}_${size}">
                        <div class="size-label">${size}</div>
                        <input type="number" class="form-control stock-input" 
                               id="${stockInputId}"
                               name="variants[${variantId}][stocks][]"
                               placeholder="Stock"
                               value="${stockValue}"
                               min="0"
                               onchange="updateSizeStock(${variantId}, '${size}', this.value)">
                        <input type="hidden" name="variants[${variantId}][sizes][]" value="${size}">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeSizeSelection(${variantId}, '${size}')">
                            <i class="bx bx-x"></i>
                        </button>
                    </div>`;
                    
                    const container = document.getElementById(`sizeStockContainer${variantId}`);
                    if (container.classList.contains('no-sizes-selected')) {
                        container.innerHTML = sizeStockHTML;
                        container.classList.remove('no-sizes-selected');
                    } else {
                        container.insertAdjacentHTML('beforeend', sizeStockHTML);
                    }
                });
            }
        }
        
        function loadExistingImages(variantId) {
            const previewsContainer = document.getElementById(`variantPreviews${variantId}`);
            if (!previewsContainer) return;
            
            const images = uploadedImagesByVariant[variantId] || [];
            
            images.forEach((imageObj, index) => {
                const previewId = imageObj.id || 'existing_' + variantId + '_' + index;
                const preview = document.createElement('div');
                preview.className = 'variant-image-preview';
                preview.id = previewId;
                preview.innerHTML = `
                    <button type="button" class="remove-variant-image" onclick="removeVariantImage('${previewId}', ${variantId}, '${imageObj.path}')">&times;</button>
                    <img src="${imageObj.path}" alt="${imageObj.filename}" style="width: 100%; height: 80px; object-fit: cover;">
                    <div class="variant-image-info">
                        <small>${imageObj.filename.substring(0, 12)}${imageObj.filename.length > 12 ? '...' : ''}</small>
                    </div>
                `;
                previewsContainer.appendChild(preview);
                
                // Add hidden input for existing image
                addVariantImageInput(variantId, imageObj.path);
            });
            
            // Update image counter
            updateVariantImageCounter(variantId);
            
            // Update main preview
            updateMainImagePreview();
        }
        
        function handleSizeSelection(variantId, size, isChecked) {
            const container = document.getElementById(`sizeStockContainer${variantId}`);
            
            if (isChecked) {
                // Check if size already exists
                const exists = selectedSizesByVariant[variantId].some(s => s.size === size);
                if (!exists) {
                    // Add size with default stock of 0
                    selectedSizesByVariant[variantId].push({
                        size: size,
                        stock: 0
                    });
                    
                    // Create stock input for this size
                    const stockInputId = `variant${variantId}_stock_${size}`;
                    const sizeStockHTML = `
                    <div class="size-stock-row" id="sizeStockRow${variantId}_${size}">
                        <div class="size-label">${size}</div>
                        <input type="number" class="form-control stock-input" 
                               id="${stockInputId}"
                               name="variants[${variantId}][stocks][]"
                               placeholder="Stock"
                               value="0"
                               min="0"
                               onchange="updateSizeStock(${variantId}, '${size}', this.value)">
                        <input type="hidden" name="variants[${variantId}][sizes][]" value="${size}">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeSizeSelection(${variantId}, '${size}')">
                            <i class="bx bx-x"></i>
                        </button>
                    </div>`;
                    
                    // If container shows "no sizes selected" message, replace it
                    if (container.classList.contains('no-sizes-selected')) {
                        container.innerHTML = sizeStockHTML;
                        container.classList.remove('no-sizes-selected');
                    } else {
                        // Otherwise append
                        container.insertAdjacentHTML('beforeend', sizeStockHTML);
                    }
                    
                    Toastify({
                        text: `Size ${size} added`,
                        duration: 2000,
                        close: true,
                        gravity: "top",
                        position: "right",
                        style: {
                            background: "linear-gradient(to right, #20c997, #198754)",
                            color: "#fff"
                        }
                    }).showToast();
                }
            } else {
                // Remove size
                removeSizeSelection(variantId, size);
            }
        }
        
        function updateSizeStock(variantId, size, stock) {
            // Update the stock in the selectedSizesByVariant array
            const sizeIndex = selectedSizesByVariant[variantId].findIndex(s => s.size === size);
            if (sizeIndex > -1) {
                selectedSizesByVariant[variantId][sizeIndex].stock = parseInt(stock) || 0;
            }
        }
        
        function removeSizeSelection(variantId, size) {
            // Remove from selectedSizesByVariant array
            const sizeIndex = selectedSizesByVariant[variantId].findIndex(s => s.size === size);
            if (sizeIndex > -1) {
                selectedSizesByVariant[variantId].splice(sizeIndex, 1);
            }
            
            // Remove the HTML element
            const rowElement = document.getElementById(`sizeStockRow${variantId}_${size}`);
            if (rowElement) {
                rowElement.remove();
            }
            
            // Uncheck the size checkbox
            const checkbox = document.getElementById(`variant${variantId}_size_${size.toLowerCase()}`);
            if (checkbox) {
                checkbox.checked = false;
            }
            
            // Check if any sizes left
            const container = document.getElementById(`sizeStockContainer${variantId}`);
            if (selectedSizesByVariant[variantId].length === 0) {
                container.innerHTML = '<div class="no-sizes-selected">No sizes selected yet. Select sizes above to set stock levels.</div>';
                container.classList.add('no-sizes-selected');
            }
            
            Toastify({
                text: `Size ${size} removed`,
                duration: 2000,
                close: true,
                gravity: "top",
                position: "right",
                style: {
                    background: "linear-gradient(to right, #dc3545, #b02a37)",
                    color: "#fff"
                }
            }).showToast();
        }
        
        function setupVariantDragAndDrop(variantId) {
            const dropzone = document.getElementById(`dropzone${variantId}`);
            
            if (!dropzone) return;
            
            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragover');
            });
            
            dropzone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
            });
            
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleVariantFiles(files, variantId);
                }
            });
        }
        
        function handleVariantFiles(files, variantId) {
            // Check current image count
            const currentImages = uploadedImagesByVariant[variantId] || [];
            const remainingSlots = 5 - currentImages.length;
            
            if (remainingSlots <= 0) {
                Toastify({
                    text: "Maximum 5 images allowed per variant",
                    duration: 5000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #dc3545, #b02a37)",
                        color: "#fff"
                    }
                }).showToast();
                return;
            }
            
            // Process only as many files as we have slots
            const filesToProcess = Math.min(files.length, remainingSlots);
            
            for (let i = 0; i < filesToProcess; i++) {
                const file = files[i];
                
                // Validate file type
                if (!file.type.match('image.*')) {
                    Toastify({
                        text: "File " + file.name + " is not an image.",
                        duration: 5000,
                        close: true,
                        gravity: "top",
                        position: "right",
                        style: {
                            background: "linear-gradient(to right, #dc3545, #b02a37)",
                            color: "#fff"
                        }
                    }).showToast();
                    continue;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    Toastify({
                        text: "File " + file.name + " is too large. Maximum size is 5MB.",
                        duration: 5000,
                        close: true,
                        gravity: "top",
                        position: "right",
                        style: {
                            background: "linear-gradient(to right, #dc3545, #b02a37)",
                            color: "#fff"
                        }
                    }).showToast();
                    continue;
                }
                
                // Upload file
                uploadVariantFile(file, variantId);
            }
            
            // Reset file input
            document.getElementById(`fileInput${variantId}`).value = '';
            
            if (files.length > remainingSlots) {
                Toastify({
                    text: `Only ${remainingSlots} images were uploaded (max 5 per variant)`,
                    duration: 5000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #ffc107, #e0a800)",
                        color: "#000"
                    }
                }).showToast();
            }
        }
        
        function uploadVariantFile(file, variantId) {
            const formData = new FormData();
            formData.append('product_images', file);
            
            // Show uploading indicator
            const previewsContainer = document.getElementById(`variantPreviews${variantId}`);
            const previewId = 'variant-preview-' + variantId + '-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
            const preview = document.createElement('div');
            preview.className = 'variant-image-preview';
            preview.id = previewId;
            preview.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; background: #f8f9fa;">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span class="ms-2">Uploading...</span>
                </div>
            `;
            previewsContainer.appendChild(preview);
            
            // Send AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Create image object
                        const imageObj = {
                            id: previewId,
                            path: response.path,
                            filename: response.filename
                        };
                        
                        // Store uploaded image for this variant
                        if (!uploadedImagesByVariant[variantId]) {
                            uploadedImagesByVariant[variantId] = [];
                        }
                        uploadedImagesByVariant[variantId].push(imageObj);
                        
                        // Update preview with actual image
                        preview.innerHTML = `
                            <button type="button" class="remove-variant-image" onclick="removeVariantImage('${previewId}', ${variantId}, '${response.path}')">&times;</button>
                            <img src="${response.path}" alt="${file.name}" style="width: 100%; height: 80px; object-fit: cover;">
                            <div class="variant-image-info">
                                <small>${file.name.substring(0, 12)}${file.name.length > 12 ? '...' : ''}</small>
                            </div>
                        `;
                        
                        // Update image counter
                        updateVariantImageCounter(variantId);
                        
                        // Add hidden input for form submission
                        addVariantImageInput(variantId, response.path);
                        
                        // Update main preview with color-specific images
                        updateMainImagePreview(variantId, response.path);
                        
                        Toastify({
                            text: "Image uploaded for variant",
                            duration: 3000,
                            close: true,
                            gravity: "top",
                            position: "right",
                            style: {
                                background: "linear-gradient(to right, #28a745, #218838)",
                                color: "#fff"
                            }
                        }).showToast();
                    } else {
                        preview.remove();
                        Toastify({
                            text: "Upload failed: " + (response.error || 'Unknown error'),
                            duration: 5000,
                            close: true,
                            gravity: "top",
                            position: "right",
                            style: {
                                background: "linear-gradient(to right, #dc3545, #b02a37)",
                                color: "#fff"
                            }
                        }).showToast();
                    }
                } catch (e) {
                    preview.remove();
                    Toastify({
                        text: "Upload failed: Invalid server response",
                        duration: 5000,
                        close: true,
                        gravity: "top",
                        position: "right",
                        style: {
                            background: "linear-gradient(to right, #dc3545, #b02a37)",
                            color: "#fff"
                        }
                    }).showToast();
                }
            };
            
            xhr.onerror = function() {
                preview.remove();
                Toastify({
                    text: "Upload failed: Network error",
                    duration: 5000,
                    close: true,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #dc3545, #b02a37)",
                        color: "#fff"
                    }
                }).showToast();
            };
            
            xhr.send(formData);
        }
        
        function updateVariantImageCounter(variantId) {
            const counter = document.getElementById(`imageCounter${variantId}`);
            const images = uploadedImagesByVariant[variantId] || [];
            
            if (counter) {
                counter.textContent = `${images.length} / 5 images uploaded`;
                
                // Change color when reaching limit
                if (images.length >= 5) {
                    counter.style.color = '#dc3545';
                    counter.style.fontWeight = 'bold';
                } else if (images.length >= 3) {
                    counter.style.color = '#ffc107';
                } else {
                    counter.style.color = '#6c757d';
                }
            }
        }
        
        function addVariantImageInput(variantId, imagePath) {
            // Create hidden input for this variant's image
            const inputId = `variant${variantId}_image_${Date.now()}`;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.id = inputId;
            input.name = `variants[${variantId}][images][]`;
            input.value = imagePath;
            
            // Add to the variant container
            const variantContainer = document.getElementById(`variant${variantId}`);
            if (variantContainer) {
                variantContainer.appendChild(input);
            }
        }
        
        function removeVariantImage(previewId, variantId, imagePath) {
            // Remove preview
            const preview = document.getElementById(previewId);
            if (preview) preview.remove();
            
            // Remove from uploaded images array
            if (uploadedImagesByVariant[variantId]) {
                const imageIndex = uploadedImagesByVariant[variantId].findIndex(img => 
                    img.id === previewId && img.path === imagePath
                );
                if (imageIndex > -1) {
                    uploadedImagesByVariant[variantId].splice(imageIndex, 1);
                }
            }
            
            // Update image counter
            updateVariantImageCounter(variantId);
            
            // Remove hidden input
            const inputs = document.querySelectorAll(`input[name="variants[${variantId}][images][]"][value="${imagePath}"]`);
            inputs.forEach(input => input.remove());
            
            // Update main preview
            updateMainImagePreview();
            
            Toastify({
                text: "Image removed from variant",
                duration: 3000,
                close: true,
                gravity: "top",
                position: "right",
                style: {
                    background: "linear-gradient(to right, #6c757d, #545b62)",
                    color: "#fff"
                }
            }).showToast();
        }
        
        function updateMainImagePreview(variantId = null, newImagePath = null) {
            const mainPreview = document.getElementById('mainImagePreview');
            const imageCount = document.getElementById('imageCount');
            const previewContainer = document.getElementById('imagePreview').querySelector('.preview-image-container');
            
            // Get all images from all variants
            let allImages = [];
            for (const vid in uploadedImagesByVariant) {
                if (uploadedImagesByVariant[vid]) {
                    allImages = allImages.concat(uploadedImagesByVariant[vid]);
                }
            }
            
            if (allImages.length > 0) {
                // Show first image as main preview
                mainPreview.src = allImages[0].path;
                imageCount.textContent = allImages.length + ' image' + (allImages.length !== 1 ? 's' : '') + ' total';
                imageCount.style.display = 'block';
                
                // Add hover images for each color
                selectedColors.forEach(colorObj => {
                    // Check if hover image already exists for this color
                    let hoverImage = document.getElementById(`hoverImage_${colorObj.color}`);
                    
                    if (!hoverImage) {
                        // Find first image for this color
                        const colorImage = allImages.find(img => {
                            // This is a simplified check - in real app, you'd track which variant the image belongs to
                            return true; // For now, show all images
                        });
                        
                        if (colorImage) {
                            // Create new hover image
                            hoverImage = document.createElement('img');
                            hoverImage.id = `hoverImage_${colorObj.color}`;
                            hoverImage.className = 'hover-image-preview img-fluid rounded bg-light';
                            hoverImage.style.cssText = 'max-height: 200px; position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain;';
                            hoverImage.alt = `Preview for ${colorObj.color}`;
                            hoverImage.src = colorImage.path;
                            
                            // Insert after main preview
                            mainPreview.parentNode.insertBefore(hoverImage, mainPreview.nextSibling);
                        }
                    }
                });
                
                // Add click event to cycle through images
                mainPreview.onclick = function() {
                    if (allImages.length > 1) {
                        currentMainImageIndex = (currentMainImageIndex + 1) % allImages.length;
                        mainPreview.src = allImages[currentMainImageIndex].path;
                        
                        // Show which image we're viewing
                        Toastify({
                            text: "Image " + (currentMainImageIndex + 1) + " of " + allImages.length,
                            duration: 1000,
                            close: true,
                            gravity: "top",
                            position: "center",
                            style: {
                                background: "linear-gradient(to right, #0d6efd, #0b5ed7)",
                                color: "#fff"
                            }
                        }).showToast();
                    }
                };
                
                // Add hover effect
                mainPreview.style.cursor = allImages.length > 1 ? 'pointer' : 'default';
                mainPreview.title = allImages.length > 1 ? 'Click to view next image' : '';
            } else {
                mainPreview.src = '../assets/images/placeholder.png';
                mainPreview.onclick = null;
                mainPreview.style.cursor = 'default';
                imageCount.style.display = 'none';
                
                // Remove all hover images
                const hoverImages = previewContainer.querySelectorAll('.hover-image-preview');
                hoverImages.forEach(img => img.remove());
            }
        }
        
        // Product metadata functions
        function addProductMetadataField(key = '', value = '') {
            const container = document.getElementById('productMetadataContainer');
            
            // Remove info alert if it exists
            const infoAlert = container.querySelector('.alert-info');
            if (infoAlert) {
                infoAlert.remove();
            }
            
            const fieldId = 'metadata_' + Date.now();
            const fieldHtml = `
            <div class="metadata-row row" id="productMetadataField${fieldId}">
                <div class="col-md-5">
                    <input type="text" class="form-control" name="metadata_key[]" placeholder="Field Name (e.g., Material, Weight)" value="${key}">
                </div>
                <div class="col-md-5">
                    <input type="text" class="form-control" name="metadata_value[]" placeholder="Field Value (e.g., Cotton, 500g)" value="${value}">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeProductMetadataField('${fieldId}')">Remove</button>
                </div>
            </div>`;
            
            container.insertAdjacentHTML('beforeend', fieldHtml);
        }
        
        function removeProductMetadataField(fieldId) {
            const element = document.getElementById(`productMetadataField${fieldId}`);
            if (element) {
                element.remove();
            }
            
            // Show info message if no fields left
            const container = document.getElementById('productMetadataContainer');
            if (container.children.length === 0) {
                container.innerHTML = '<div class="alert alert-info">Add additional information about the product that will be same for all variants (e.g., Material, Weight, Care Instructions)</div>';
            }
        }
        
        function updateLivePreview() {
            // Update title from input field
            const titleInput = document.getElementById('title');
            const liveTitle = document.getElementById('liveTitle');
            liveTitle.textContent = titleInput.value || 'Product Title';
            
            // Update colors display
            const liveColors = document.getElementById('liveColors');
            liveColors.innerHTML = '';
            if (selectedColors.length > 0) {
                selectedColors.forEach(colorObj => {
                    const contrastColor = getContrastColor(colorObj.color);
                    const badge = document.createElement('span');
                    badge.className = 'badge me-1';
                    badge.style.backgroundColor = colorObj.color;
                    badge.style.color = contrastColor;
                    badge.textContent = colorObj.name;
                    badge.title = `Hover to see ${colorObj.name} variant`;
                    badge.style.cursor = 'pointer';
                    badge.style.transition = 'all 0.3s';
                    
                    // Add hover events
                    badge.addEventListener('mouseenter', function() {
                        this.style.transform = 'scale(1.1)';
                        this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
                        showColorImagePreview(colorObj.color);
                    });
                    
                    badge.addEventListener('mouseleave', function() {
                        this.style.transform = 'scale(1)';
                        this.style.boxShadow = 'none';
                        showDefaultImagePreview();
                    });
                    
                    liveColors.appendChild(badge);
                });
            } else {
                liveColors.innerHTML = '<span class="text-muted">No colors selected</span>';
            }
            
            // Update prices from common price inputs
            const mrpInput = document.getElementById('mrp');
            const salePriceInput = document.getElementById('sale_price');
            
            const mrp = parseFloat(mrpInput.value) || 0;
            const salePrice = parseFloat(salePriceInput.value) || 0;
            
            const livePrice = document.getElementById('livePrice');
            const liveMrp = document.getElementById('liveMrp');
            const liveDiscount = document.getElementById('liveDiscount');
            
            if (salePrice > 0) {
                livePrice.textContent = `$${salePrice.toFixed(2)}`;
                
                if (mrp > 0) {
                    liveMrp.textContent = `$${mrp.toFixed(2)}`;
                    
                    if (mrp > salePrice) {
                        const discount = Math.round((1 - salePrice / mrp) * 100);
                        liveDiscount.textContent = ` (${discount}% Off)`;
                    } else {
                        liveDiscount.textContent = ' (0% Off)';
                    }
                } else {
                    liveMrp.textContent = '₹0';
                    liveDiscount.textContent = ' (0% Off)';
                }
            } else {
                livePrice.textContent = '₹0';
                liveMrp.textContent = '₹0';
                liveDiscount.textContent = ' (0% Off)';
            }
        }
        
        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing...');
            
            // Initialize color picker button
            const pickColorsBtn = document.getElementById('pickColorsBtn');
            if (pickColorsBtn) {
                pickColorsBtn.addEventListener('click', openColorPicker);
            }
            
            // Sync color input with hex input
            const colorInput = document.getElementById('colorInput');
            const colorHex = document.getElementById('colorHex');
            
            if (colorInput && colorHex) {
                colorInput.addEventListener('input', function() {
                    colorHex.value = this.value;
                });
                
                colorHex.addEventListener('input', function() {
                    const value = this.value;
                    if (value.startsWith('#') && (value.length === 4 || value.length === 7)) {
                        colorInput.value = value;
                    }
                });
                
                // Add Enter key support for adding color
                const colorNameInput = document.getElementById('colorName');
                if (colorNameInput) {
                    colorNameInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addColor();
                        }
                    });
                }
            }
            
            // Add event listener to title input
            const titleInput = document.getElementById('title');
            if (titleInput) {
                titleInput.addEventListener('input', updateLivePreview);
            }
            
            // Add event listeners to price inputs
            const priceInputs = ['mrp', 'sale_price'];
            priceInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateLivePreview);
                }
            });
            
            // Initialize selected colors display
            updateSelectedColorsDisplay();
            
            // Generate variants if we have colors
            if (selectedColors.length > 0) {
                generateVariants();
            }
            
            // Update live preview
            updateLivePreview();
            
            <?php if ($success): ?>
            Toastify({
                text: "<?php echo e($success); ?>",
                duration: 4000,
                close: true,
                gravity: "top",
                position: "right",
                style: {
                    background: "linear-gradient(to right, #28a745, #218838)",
                    color: "#fff"
                }
            }).showToast();
            <?php endif; ?>
            
            <?php if ($error): ?>
            Toastify({
                text: "<?php echo e($error); ?>",
                duration: 6000,
                close: true,
                gravity: "top",
                position: "right",
                style: {
                    background: "linear-gradient(to right, #dc3545, #b02a37)",
                    color: "#fff"
                }
            }).showToast();
            <?php endif; ?>
            
            console.log('Product form initialized in <?php echo $edit_mode ? "edit" : "add"; ?> mode');
        });
    </script>

</body>
</html>