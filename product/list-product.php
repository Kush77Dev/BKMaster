<?php
// list-products.php
// Product listing page with database integration and modal-based deletion

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

// Utility: safe output for HTML
function e($s)
{
     return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Delete product and all its related data
 */
function delete_product_by_id($conn, $id, &$out_error = null)
{
     $out_error = null;
     $id = (int)$id;

     if ($id <= 0) {
          $out_error = "Invalid product id.";
          return false;
     }

     // Start transaction
     $conn->begin_transaction();

     try {
          // First, get all variant IDs for this product
          $variant_ids = [];
          $stmt = $conn->prepare("SELECT id FROM product_variants WHERE product_id = ?");
          if (!$stmt) {
               throw new Exception("Database error (prepare variants).");
          }
          $stmt->bind_param('i', $id);
          $stmt->execute();
          $result = $stmt->get_result();
          while ($row = $result->fetch_assoc()) {
               $variant_ids[] = $row['id'];
          }
          $stmt->close();

          // Delete product images for each variant
          if (!empty($variant_ids)) {
               $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
               $stmt = $conn->prepare("DELETE FROM product_images WHERE variant_id IN ($placeholders)");
               if (!$stmt) {
                    throw new Exception("Database error (prepare delete images).");
               }
               $types = str_repeat('i', count($variant_ids));
               $stmt->bind_param($types, ...$variant_ids);
               if (!$stmt->execute()) {
                    throw new Exception("Failed to delete product images.");
               }
               $stmt->close();
          }

          // Delete product variants
          $stmt = $conn->prepare("DELETE FROM product_variants WHERE product_id = ?");
          if (!$stmt) {
               throw new Exception("Database error (prepare delete variants).");
          }
          $stmt->bind_param('i', $id);
          if (!$stmt->execute()) {
               throw new Exception("Failed to delete product variants.");
          }
          $stmt->close();

          // Finally, delete the product
          $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
          if (!$stmt) {
               throw new Exception("Database error (prepare delete product).");
          }
          $stmt->bind_param('i', $id);
          if (!$stmt->execute()) {
               throw new Exception("Failed to delete product.");
          }
          $stmt->close();

          // Commit transaction
          $conn->commit();
          return true;
     } catch (Exception $e) {
          // Rollback transaction on error
          $conn->rollback();
          $out_error = $e->getMessage();
          return false;
     }
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
     // Expect id in POST
     $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
     $out_err = null;
     $ok = delete_product_by_id($conn, $id, $out_err);
     header('Content-Type: application/json; charset=utf-8');
     if ($ok) {
          echo json_encode(['success' => true, 'message' => 'Product deleted successfully.', 'id' => $id]);
          exit;
     } else {
          echo json_encode(['success' => false, 'error' => $out_err ?: 'Unknown error']);
          exit;
     }
}

// Handle fallback GET delete (graceful fallback)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
     $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
     $out_err = null;
     $ok = delete_product_by_id($conn, $id, $out_err);
     if ($ok) {
          // PRG: redirect with flag
          header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?deleted=1");
          exit;
     } else {
          $q = urlencode($out_err ?: 'Unknown error');
          header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?delete_error={$q}");
          exit;
     }
}

// AJAX endpoint for pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate' && isset($_GET['page'])) {
     $page = (int)$_GET['page'];
     $perPage = 10; // Number of items per page
     $offset = ($page - 1) * $perPage;
     
     // Get total count
     $countResult = $conn->query("SELECT COUNT(*) as total FROM products");
     $totalRows = $countResult->fetch_assoc()['total'];
     $totalPages = ceil($totalRows / $perPage);
     
     // Get paginated data
     $sql = "
         SELECT 
             p.id,
             p.title,
             p.description,
             p.brand,
             p.is_active,
             p.is_custom_made,
             p.created_at,
             c.name as category_name,
             GROUP_CONCAT(DISTINCT pv.color ORDER BY pv.color) as colors,
             SUM(pv.stock) as total_stock,
             MIN(pv.sale_price) as min_price,
             MAX(pv.sale_price) as max_price
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         LEFT JOIN product_variants pv ON p.id = pv.product_id
         GROUP BY p.id
         ORDER BY p.created_at DESC
         LIMIT $offset, $perPage
     ";
     $result = $conn->query($sql);
     
     $rows = [];
     if ($result) {
          while ($row = $result->fetch_assoc()) {
               // Get first image for each product
               $image_query = "
                 SELECT pi.url 
                 FROM product_images pi
                 INNER JOIN product_variants pv ON pi.variant_id = pv.id
                 WHERE pv.product_id = ?
                 ORDER BY pi.position ASC
                 LIMIT 1
             ";

               $stmt = $conn->prepare($image_query);
               $stmt->bind_param('i', $row['id']);
               $stmt->execute();
               $image_result = $stmt->get_result();
               $image_row = $image_result->fetch_assoc();
               $row['image_url'] = $image_row ? $image_row['url'] : '../assets/images/placeholder.png';
               $stmt->close();

               $row['sold_count'] = 0; // Placeholder

               // Process colors into array
               $colors = [];
               if (!empty($row['colors'])) {
                    $color_array = explode(',', $row['colors']);
                    foreach ($color_array as $color) {
                         if (!empty($color) && !in_array($color, $colors)) {
                              $colors[] = $color;
                         }
                    }
               }
               $row['color_array'] = $colors;

               // Get sizes
               $sizes = [];
               $size_query = "SELECT sizes FROM product_variants WHERE product_id = ? AND sizes IS NOT NULL";
               $size_stmt = $conn->prepare($size_query);
               $size_stmt->bind_param('i', $row['id']);
               $size_stmt->execute();
               $size_result = $size_stmt->get_result();

               while ($size_row = $size_result->fetch_assoc()) {
                    if (!empty($size_row['sizes'])) {
                         $variant_sizes = json_decode($size_row['sizes'], true);
                         if ($variant_sizes && is_array($variant_sizes)) {
                              foreach ($variant_sizes as $size) {
                                   if (!empty($size) && !in_array($size, $sizes)) {
                                        $sizes[] = $size;
                                   }
                              }
                         }
                    }
               }
               $size_stmt->close();

               // Sort sizes
               usort($sizes, function ($a, $b) {
                    $order = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, '3XL' => 7];
                    $a_order = $order[$a] ?? 99;
                    $b_order = $order[$b] ?? 99;
                    return $a_order - $b_order;
               });

               $row['sizes'] = !empty($sizes) ? implode(', ', $sizes) : '';
               $rows[] = $row;
          }
     }
     
     header('Content-Type: application/json; charset=utf-8');
     echo json_encode([
          'success' => true,
          'rows' => $rows,
          'currentPage' => $page,
          'totalPages' => $totalPages,
          'totalRows' => $totalRows
     ]);
     exit;
}

// Pagination variables for initial load
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$perPage = 10; // Items per page

// Get total products count for pagination
$total_query = "SELECT COUNT(*) as total FROM products";
$total_result = $conn->query($total_query);
$total_row = $total_result->fetch_assoc();
$total_products = $total_row['total'];
$totalPages = ceil($total_products / $perPage);

// Calculate offset
$offset = ($currentPage - 1) * $perPage;

// Fetch products with their data including colors (paginated)
$products = [];
$query = "
    SELECT 
        p.id,
        p.title,
        p.description,
        p.brand,
        p.is_active,
        p.is_custom_made,
        p.created_at,
        c.name as category_name,
        GROUP_CONCAT(DISTINCT pv.color ORDER BY pv.color) as colors,
        SUM(pv.stock) as total_stock,
        MIN(pv.sale_price) as min_price,
        MAX(pv.sale_price) as max_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_variants pv ON p.id = pv.product_id
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT $offset, $perPage
";

$result = $conn->query($query);

if ($result) {
     while ($row = $result->fetch_assoc()) {
          // Get first image for each product
          $image_query = "
            SELECT pi.url 
            FROM product_images pi
            INNER JOIN product_variants pv ON pi.variant_id = pv.id
            WHERE pv.product_id = ?
            ORDER BY pi.position ASC
            LIMIT 1
        ";

          $stmt = $conn->prepare($image_query);
          $stmt->bind_param('i', $row['id']);
          $stmt->execute();
          $image_result = $stmt->get_result();
          $image_row = $image_result->fetch_assoc();
          $row['image_url'] = $image_row ? $image_row['url'] : '../assets/images/placeholder.png';
          $stmt->close();

          // Get sold count (this would need tracking in your database)
          // For now, we'll use a placeholder
          $row['sold_count'] = 0; // You should implement actual sales tracking

          // Process colors into array
          $colors = [];
          if (!empty($row['colors'])) {
               $color_array = explode(',', $row['colors']);
               foreach ($color_array as $color) {
                    if (!empty($color) && !in_array($color, $colors)) {
                         $colors[] = $color;
                    }
               }
          }
          $row['color_array'] = $colors;
          $row['color_count'] = count($colors);

          // Get sizes for this product (from JSON array in product_variants)
          $sizes = [];
          $size_query = "SELECT sizes FROM product_variants WHERE product_id = ? AND sizes IS NOT NULL";
          $size_stmt = $conn->prepare($size_query);
          $size_stmt->bind_param('i', $row['id']);
          $size_stmt->execute();
          $size_result = $size_stmt->get_result();

          while ($size_row = $size_result->fetch_assoc()) {
               if (!empty($size_row['sizes'])) {
                    $variant_sizes = json_decode($size_row['sizes'], true);
                    if ($variant_sizes && is_array($variant_sizes)) {
                         foreach ($variant_sizes as $size) {
                              if (!empty($size) && !in_array($size, $sizes)) {
                                   $sizes[] = $size;
                              }
                         }
                    }
               }
          }
          $size_stmt->close();

          // Sort sizes in logical order
          usort($sizes, function ($a, $b) {
               $order = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, '3XL' => 7];
               $a_order = $order[$a] ?? 99;
               $b_order = $order[$b] ?? 99;
               return $a_order - $b_order;
          });

          $row['sizes_array'] = $sizes;
          $row['sizes'] = !empty($sizes) ? implode(', ', $sizes) : '';

          $products[] = $row;
     }
}

// Build flash messages from GET params
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
     $flashSuccess = "Product deleted successfully.";
}
if (isset($_GET['delete_error'])) {
     $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>Product List</title>

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
          .product-image {
               width: 60px;
               height: 60px;
               object-fit: cover;
               border-radius: 8px;
          }

          .status-badge {
               padding: 4px 12px;
               border-radius: 20px;
               font-size: 12px;
               font-weight: 500;
          }

          .status-active {
               background-color: #d1fae5;
               color: #065f46;
          }

          .status-inactive {
               background-color: #fee2e2;
               color: #991b1b;
          }

          .custom-badge {
               background-color: #f0f9ff;
               color: #0369a1;
               border: 1px solid #bae6fd;
          }

          .select-all-checkbox {
               margin-right: 10px;
          }

          /* .pagination .page-item.active .page-link {
               background-color: #0d6efd;
               border-color: #0d6efd;
          } */

          .stock-info {
               font-size: 13px;
          }

          .price-range {
               font-weight: 500;
               color: #0d6efd;
          }

          .toastify {
               z-index: 20000;
          }

          .product-title-link {
               color: #212529;
               text-decoration: none;
               transition: color 0.2s;
          }

          .product-title-link:hover {
               color: #0d6efd;
          }

          /* Pagination loading indicator */
          .pagination-loading {
               display: none;
               text-align: center;
               padding: 10px;
          }

          .pagination-loading.active {
               display: block;
          }

          .color-badge {
               display: inline-flex;
               align-items: center;
               padding: 4px 10px;
               border-radius: 20px;
               font-size: 11px;
               font-weight: 500;
               margin: 2px;
               border: 1px solid #dee2e6;
               background-color: white;
               color: #495057;
          }

          .color-dot {
               width: 20px;
               height: 20px;
               border-radius: 50%;
               margin-right: 5px;
               display: inline-block;
               border: 1px solid rgba(0, 0, 0, 0.1);
          }

          .more-colors {
               display: inline-flex;
               align-items: center;
               justify-content: center;
               width: 28px;
               height: 28px;
               border-radius: 50%;
               background-color: #f8f9fa;
               color: #6c757d;
               font-size: 11px;
               font-weight: 600;
               margin-left: 4px;
               border: 1px solid #dee2e6;
          }

          .colors-container {
               display: flex;
               flex-wrap: wrap;
               align-items: center;
               gap: 4px;
               max-width: 200px;
          }
     </style>
</head>

<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <!-- ========== Topbar Start ========== -->
          <?php include __DIR__ . '/../includes/header.php' ?>

          <!-- ========== App Menu Start ========== -->
          <?php include __DIR__ . '/../includes/sidebar.php' ?>
          <!-- ========== App Menu End ========== -->

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
          <div class="page-content">

               <!-- Start Container Fluid -->
               <div class="container-fluid">

                    <!-- Success/Error Messages -->
                    <?php if ($flashSuccess): ?>
                         <div class="alert alert-success alert-dismissible fade show" role="alert">
                              <?php echo e($flashSuccess); ?>
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                         </div>
                    <?php endif; ?>

                    <?php if ($flashError): ?>
                         <div class="alert alert-danger alert-dismissible fade show" role="alert">
                              <?php echo e($flashError); ?>
                              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                         </div>
                    <?php endif; ?>

                    <div class="row">
                         <div class="col-xl-12">
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                        <h4 class="card-title flex-grow-1">All Product List (<?php echo $total_products; ?> products)</h4>

                                        <a href="add-product.php" class="btn btn-sm btn-primary">
                                             <i class="bx bx-plus me-1"></i> Add Product
                                        </a>

                                        <div class="dropdown">
                                             <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light" data-bs-toggle="dropdown" aria-expanded="false">
                                                  This Month
                                             </a>
                                             <div class="dropdown-menu dropdown-menu-end">
                                                  <!-- item-->
                                                  <a href="#!" class="dropdown-item">Download CSV</a>
                                                  <!-- item-->
                                                  <a href="#!" class="dropdown-item">Export Excel</a>
                                                  <!-- item-->
                                                  <a href="#!" class="dropdown-item">Print</a>
                                             </div>
                                        </div>
                                   </div>
                                   <div>
                                        <div class="table-responsive">
                                             <table class="table align-middle mb-0 table-hover table-centered">
                                                  <thead class="bg-light-subtle">
                                                       <tr>
                                                            <th style="width: 20px;">
                                                                 <div class="form-check ms-1 select-all-checkbox">
                                                                      <input type="checkbox" class="form-check-input" id="selectAll">
                                                                      <label class="form-check-label" for="selectAll"></label>
                                                                 </div>
                                                            </th>
                                                            <th>Product Name & Size</th>
                                                            <th>Price</th>
                                                            <th>Stock</th>
                                                            <th>Category</th>
                                                            <th>Status</th>
                                                            <th>Colors</th>
                                                            <th>Action</th>
                                                       </tr>
                                                  </thead>
                                                  <tbody id="productsTableBody">
                                                       <?php if (empty($products)): ?>
                                                            <tr>
                                                                 <td colspan="8" class="text-center py-5">
                                                                      <div class="text-muted">
                                                                           <i class="bx bx-package fs-48 mb-3"></i>
                                                                           <h5>No products found</h5>
                                                                           <p>Get started by adding your first product</p>
                                                                           <a href="add-product.php" class="btn btn-primary">Add Product</a>
                                                                      </div>
                                                                 </td>
                                                            </tr>
                                                       <?php else: ?>
                                                            <?php foreach ($products as $product): ?>
                                                                 <tr id="row-<?php echo e($product['id']); ?>">
                                                                      <td>
                                                                           <div class="form-check ms-1">
                                                                                <input type="checkbox" class="form-check-input product-checkbox" id="product_<?php echo $product['id']; ?>" data-id="<?php echo $product['id']; ?>">
                                                                                <label class="form-check-label" for="product_<?php echo $product['id']; ?>">&nbsp;</label>
                                                                           </div>
                                                                      </td>
                                                                      <td>
                                                                           <div class="d-flex align-items-center gap-2">
                                                                                <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                                                     <img src="<?php echo e($product['image_url']); ?>" alt="<?php echo e($product['title']); ?>" class="avatar-md product-image">
                                                                                </div>
                                                                                <div>
                                                                                     <a href="details-product.php?id=<?php echo $product['id']; ?>" class="product-title-link fw-medium fs-15"><?php echo e($product['title']); ?></a>
                                                                                     <p class="text-muted mb-0 mt-1 fs-13">
                                                                                          <span>Brand: </span><?php echo e($product['brand'] ?: 'No brand'); ?>
                                                                                     </p>
                                                                                     <?php if ($product['sizes']): ?>
                                                                                          <p class="text-muted mb-0 mt-1 fs-13">
                                                                                               <span>Size: </span><?php echo e($product['sizes']); ?>
                                                                                          </p>
                                                                                     <?php endif; ?>
                                                                                     <?php if ($product['is_custom_made']): ?>
                                                                                          <span class="badge custom-badge mt-1">Custom Made</span>
                                                                                     <?php endif; ?>
                                                                                </div>
                                                                           </div>
                                                                      </td>
                                                                       <td class="price-range">
                                                                            <?php if ($product['min_price'] && $product['max_price']): ?>
                                                                                 <?php if ($product['min_price'] == $product['max_price']): ?>
                                                                                      ₹<?php echo number_format($product['min_price'], 2); ?>
                                                                                 <?php else: ?>
                                                                                      ₹<?php echo number_format($product['min_price'], 2); ?> - ₹<?php echo number_format($product['max_price'], 2); ?>
                                                                                 <?php endif; ?>
                                                                            <?php else: ?>
                                                                                 <span class="text-muted">No price set</span>
                                                                            <?php endif; ?>
                                                                       </td>
                                                                      <td>
                                                                           <div class="stock-info">
                                                                                <?php if ($product['total_stock'] > 0): ?>
                                                                                     <p class="mb-1 text-muted">
                                                                                          <span class="text-dark fw-medium"><?php echo number_format($product['total_stock']); ?> Item<?php echo $product['total_stock'] > 1 ? 's' : ''; ?></span> Left
                                                                                     </p>
                                                                                <?php else: ?>
                                                                                     <p class="mb-1 text-danger fw-medium">Out of Stock</p>
                                                                                <?php endif; ?>
                                                                                <p class="mb-0 text-muted"><?php echo $product['sold_count']; ?> Sold</p>
                                                                           </div>
                                                                      </td>
                                                                      <td><?php echo e($product['category_name'] ?: 'Uncategorized'); ?></td>
                                                                      <td>
                                                                           <?php if ($product['is_active']): ?>
                                                                                <span class="status-badge status-active">Active</span>
                                                                           <?php else: ?>
                                                                                <span class="status-badge status-inactive">Inactive</span>
                                                                           <?php endif; ?>
                                                                      </td>
                                                                      <td>
                                                                           <div class="colors-container">
                                                                                <?php if (!empty($product['color_array'])): ?>
                                                                                     <?php
                                                                                     // Show first 3 colors, then show "+X more"
                                                                                     $display_colors = array_slice($product['color_array'], 0, 3);
                                                                                     $remaining_count = count($product['color_array']) - 3;
                                                                                     ?>
                                                                                     <?php foreach ($display_colors as $color): ?>
                                                                                          <?php
                                                                                          // Check if it's a hex color or color name
                                                                                          $is_hex = preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color);
                                                                                          $bg_color = $is_hex ? $color : '#f8f9fa';
                                                                                          $color_name = $is_hex ? '' : substr($color, 0, 12) . (strlen($color) > 12 ? '...' : '');
                                                                                          ?>
                                                                                          <span class="" title="<?php echo e($color); ?>">
                                                                                               <?php if ($is_hex): ?>
                                                                                                    <span class="color-dot" style="background-color: <?php echo e($bg_color); ?>"></span>
                                                                                               <?php endif; ?>
                                                                                               <?php echo e($color_name); ?>
                                                                                          </span>
                                                                                     <?php endforeach; ?>

                                                                                     <?php if ($remaining_count > 0): ?>
                                                                                          <span class="more-colors" title="<?php echo $remaining_count; ?> more colors">+<?php echo $remaining_count; ?></span>
                                                                                     <?php endif; ?>
                                                                                <?php else: ?>
                                                                                     <span class="text-muted">No colors</span>
                                                                                <?php endif; ?>
                                                                           </div>
                                                                      </td>
                                                                      <td>
                                                                           <div class="d-flex gap-2">
                                                                                <a href="details-product.php?id=<?php echo $product['id']; ?>" class="btn btn-light btn-sm" title="View Details">
                                                                                     <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                                                                </a>
                                                                                <a href="add-product.php?id=<?php echo $product['id']; ?>" class="btn btn-soft-primary btn-sm" title="Edit">
                                                                                     <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                                </a>
                                                                                <a href="#" class="btn btn-soft-danger btn-sm btn-delete"
                                                                                     data-id="<?php echo e($product['id']); ?>"
                                                                                     data-name="<?php echo e($product['title']); ?>"
                                                                                     title="Delete">
                                                                                     <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                                </a>
                                                                           </div>
                                                                      </td>
                                                                 </tr>
                                                            <?php endforeach; ?>
                                                       <?php endif; ?>
                                                  </tbody>
                                             </table>
                                        </div>
                                        <!-- end table-responsive -->
                                   </div>
                                   <div class="card-footer border-top">
                                        <div class="pagination-loading" id="paginationLoading">
                                             <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                                                  <span class="visually-hidden">Loading...</span>
                                             </div>
                                             Loading products...
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                             <div class="text-muted" id="showingInfo">
                                                  Showing <?php echo count($products); ?> of <?php echo $total_products; ?> products
                                             </div>
                                             <nav aria-label="Page navigation example">
                                                  <ul class="pagination justify-content-end mb-0" id="pagination">
                                                       <?php if ($totalPages > 1): ?>
                                                            <!-- Previous Button -->
                                                            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                                                 <a class="page-link" href="?page=<?php echo $currentPage - 1; ?>" data-page="<?php echo $currentPage - 1; ?>" <?php echo $currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                                      Previous
                                                                 </a>
                                                            </li>
                                                            
                                                            <?php 
                                                            // Calculate page range to show
                                                            $startPage = max(1, $currentPage - 2);
                                                            $endPage = min($totalPages, $startPage + 4);
                                                            
                                                            // Adjust start page if we're near the end
                                                            if ($endPage - $startPage < 4 && $startPage > 1) {
                                                                 $startPage = max(1, $endPage - 4);
                                                            }
                                                            
                                                            for ($i = $startPage; $i <= $endPage; $i++): 
                                                                 $activeClass = $i == $currentPage ? 'active' : '';
                                                            ?>
                                                                 <li class="page-item <?php echo $activeClass; ?>">
                                                                      <a class="page-link" href="?page=<?php echo $i; ?>" data-page="<?php echo $i; ?>">
                                                                           <?php echo $i; ?>
                                                                      </a>
                                                                 </li>
                                                            <?php endfor; ?>
                                                            
                                                            <!-- Next Button -->
                                                            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                                                 <a class="page-link" href="?page=<?php echo $currentPage + 1; ?>" data-page="<?php echo $currentPage + 1; ?>" <?php echo $currentPage >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                                      Next
                                                                 </a>
                                                            </li>
                                                       <?php else: ?>
                                                            <li class="page-item active"><a class="page-link" href="javascript:void(0);">1</a></li>
                                                       <?php endif; ?>
                                                  </ul>
                                             </nav>
                                        </div>
                                   </div>
                              </div>
                         </div>
                    </div>

               </div>
               <!-- End Container Fluid -->

               <!-- ========== Footer Start ========== -->
               <footer class="footer">
                    <div class="container-fluid">
                         <div class="row">
                              <div class="col-12 text-center">
                                   <script>
                                        document.write(new Date().getFullYear())
                                   </script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a
                                        href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
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

     <!-- Delete Confirmation Modal -->
     <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
          <div class="modal-dialog">
               <div class="modal-content">
                    <div class="modal-header">
                         <h5 class="modal-title" id="deleteConfirmModalLabel">Delete Product</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                         <p>Are you sure you want to delete the product "<span id="deleteProductName"></span>"?</p>
                         <p class="text-danger mb-0"><strong>Warning:</strong> This action will delete the product and all its variants, images, and related data. This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <!-- Confirm button triggers AJAX deletion -->
                         <button type="button" id="confirmDeleteBtn" class="btn btn-danger">
                              Delete Product
                         </button>
                    </div>
               </div>
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
               // Helper to show toast notifications
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
                                   "linear-gradient(to right, #dc3545, #b02a37)" : "linear-gradient(to right, #4CAF7C, #4CAF7C)",
                              color: "#fff"
                         }
                    }).showToast();
               }

               // Show server-side messages
               var serverSuccess = <?php echo json_encode($flashSuccess ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
               var serverError = <?php echo json_encode($flashError ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
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

               /* -------------------
                  Dynamic Pagination with AJAX
                  ------------------- */
               var currentPage = <?php echo $currentPage; ?>;
               var totalPages = <?php echo $totalPages; ?>;
               const paginationLoading = document.getElementById('paginationLoading');
               const productsTableBody = document.getElementById('productsTableBody');
               const pagination = document.getElementById('pagination');
               const showingInfo = document.getElementById('showingInfo');

               function loadPage(page) {
                    if (page < 1 || page > totalPages || page === currentPage) return;
                    
                    // Show loading indicator
                    if (paginationLoading) paginationLoading.classList.add('active');
                    
                    // Update current page
                    currentPage = page;
                    
                    // Update URL without page reload
                    var url = new URL(window.location);
                    url.searchParams.set('page', page);
                    window.history.pushState({}, '', url);
                    
                    // Fetch data via AJAX
                    fetch('?action=paginate&page=' + page, {
                         credentials: 'same-origin',
                         headers: {
                              'X-Requested-With': 'XMLHttpRequest'
                         }
                    })
                    .then(r => r.json())
                    .then(data => {
                         if (data.success && data.rows) {
                              renderProductRows(data.rows);
                              updatePagination(data.currentPage, data.totalPages);
                              
                              if (showingInfo) {
                                   showingInfo.textContent = `Showing ${data.rows.length} of ${data.totalRows} products`;
                              }
                         }
                    })
                    .catch(error => {
                         console.error('Error loading page:', error);
                         showToast('Error loading page. Please try again.', 'error');
                    })
                    .finally(() => {
                         if (paginationLoading) paginationLoading.classList.remove('active');
                         // Scroll to top of table
                         const tableCard = document.querySelector('.card');
                         if (tableCard) tableCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
               }

               function renderProductRows(rows) {
                    if (!productsTableBody) return;
                    productsTableBody.innerHTML = '';
                    
                    if (rows.length === 0) {
                         productsTableBody.innerHTML = `
                              <tr>
                                   <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">
                                             <i class="bx bx-package fs-48 mb-3"></i>
                                             <h5>No products found</h5>
                                        </div>
                                   </td>
                              </tr>`;
                         return;
                    }

                    rows.forEach(product => {
                         const colorsHtml = renderColors(product.color_array);
                         const priceHtml = renderPrice(product.min_price, product.max_price);
                         const stockHtml = renderStock(product.total_stock, product.sold_count);
                         const statusHtml = product.is_active ? 
                              '<span class="status-badge status-active">Active</span>' : 
                              '<span class="status-badge status-inactive">Inactive</span>';
                         
                         const rowHtml = `
                              <tr id="row-${product.id}">
                                   <td>
                                        <div class="form-check ms-1">
                                             <input type="checkbox" class="form-check-input product-checkbox" id="product_${product.id}" data-id="${product.id}">
                                             <label class="form-check-label" for="product_${product.id}">&nbsp;</label>
                                        </div>
                                   </td>
                                   <td>
                                        <div class="d-flex align-items-center gap-2">
                                             <div class="rounded bg-light avatar-md d-flex align-items-center justify-content-center">
                                                  <img src="${product.image_url}" alt="${product.title}" class="avatar-md product-image">
                                             </div>
                                             <div>
                                                  <a href="details-product.php?id=${product.id}" class="product-title-link fw-medium fs-15">${product.title}</a>
                                                  <p class="text-muted mb-0 mt-1 fs-13">
                                                       <span>Brand: </span>${product.brand || 'No brand'}
                                                  </p>
                                                  ${product.sizes ? `
                                                  <p class="text-muted mb-0 mt-1 fs-13">
                                                       <span>Size: </span>${product.sizes}
                                                  </p>` : ''}
                                                  ${product.is_custom_made == 1 ? '<span class="badge custom-badge mt-1">Custom Made</span>' : ''}
                                             </div>
                                        </div>
                                   </td>
                                   <td class="price-range">${priceHtml}</td>
                                   <td>${stockHtml}</td>
                                   <td>${product.category_name || 'Uncategorized'}</td>
                                   <td>${statusHtml}</td>
                                   <td>${colorsHtml}</td>
                                   <td>
                                        <div class="d-flex gap-2">
                                             <a href="details-product.php?id=${product.id}" class="btn btn-light btn-sm" title="View Details">
                                                  <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                             </a>
                                             <a href="add-product.php?id=${product.id}" class="btn btn-soft-primary btn-sm" title="Edit">
                                                  <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                             </a>
                                             <a href="#" class="btn btn-soft-danger btn-sm btn-delete"
                                                  data-id="${product.id}"
                                                  data-name="${product.title}"
                                                  title="Delete">
                                                  <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                             </a>
                                        </div>
                                   </td>
                              </tr>`;
                         productsTableBody.innerHTML += rowHtml;
                    });
               }

               function renderPrice(min, max) {
                    if (min === null || max === null) return '<span class="text-muted">No price set</span>';
                    const fMin = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2 }).format(min);
                    const fMax = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2 }).format(max);
                    return min == max ? `₹${fMin}` : `₹${fMin} - ₹${fMax}`;
               }

               function renderStock(total, sold) {
                    const fTotal = new Intl.NumberFormat('en-IN').format(total || 0);
                    const stockText = (total || 0) > 0 ? 
                         `<p class="mb-1 text-muted"><span class="text-dark fw-medium">${fTotal} Item${total > 1 ? 's' : ''}</span> Left</p>` :
                         '<p class="mb-1 text-danger fw-medium">Out of Stock</p>';
                    return `<div class="stock-info">${stockText}<p class="mb-0 text-muted">${sold || 0} Sold</p></div>`;
               }

               function renderColors(colorArray) {
                    if (!colorArray || colorArray.length === 0) return '<span class="text-muted">No colors</span>';
                    
                    const displayColors = colorArray.slice(0, 3);
                    const remainingCount = colorArray.length - 3;
                    
                    let html = '<div class="colors-container">';
                    displayColors.forEach(color => {
                         const isHex = /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(color);
                         const bgColor = isHex ? color : '#f8f9fa';
                         const colorName = isHex ? '' : (color.length > 12 ? color.substring(0, 12) + '...' : color);
                         
                         html += `
                              <span class="" title="${color}">
                                   ${isHex ? `<span class="color-dot" style="background-color: ${bgColor}"></span>` : ''}
                                   ${colorName}
                              </span>`;
                    });
                    
                    if (remainingCount > 0) {
                         html += `<span class="more-colors" title="${remainingCount} more colors">+${remainingCount}</span>`;
                    }
                    html += '</div>';
                    return html;
               }

               function updatePagination(currentPage, totalPages) {
                    if (!pagination || totalPages <= 1) {
                         if (pagination) pagination.innerHTML = '<li class="page-item active"><a class="page-link" href="javascript:void(0);">1</a></li>';
                         return;
                    }
                    
                    var html = '';
                    
                    // Previous button
                    html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                         <a class="page-link" href="?page=${currentPage - 1}" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''}>
                              Previous
                         </a>
                    </li>`;
                    
                    var startPage = Math.max(1, currentPage - 2);
                    var endPage = Math.min(totalPages, startPage + 4);
                    if (endPage - startPage < 4 && startPage > 1) {
                         startPage = Math.max(1, endPage - 4);
                    }
                    
                    for (var i = startPage; i <= endPage; i++) {
                         html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                              <a class="page-link" href="?page=${i}" data-page="${i}">${i}</a>
                         </li>`;
                    }
                    
                    // Next button
                    html += `<li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                         <a class="page-link" href="?page=${currentPage + 1}" data-page="${currentPage + 1}" ${currentPage >= totalPages ? 'tabindex="-1" aria-disabled="true"' : ''}>
                              Next
                         </a>
                    </li>`;
                    
                    pagination.innerHTML = html;
               }

               // Handle pagination click
               if (pagination) {
                    pagination.addEventListener('click', function(e) {
                         var link = e.target.closest('.page-link');
                         if (!link) return;
                         
                         e.preventDefault();
                         var page = parseInt(link.getAttribute('data-page'));
                         if (!isNaN(page)) {
                              loadPage(page);
                         }
                    });
               }

               /* -------------------
                  Select All Checkbox
                  ------------------- */
               document.getElementById('selectAll').addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.product-checkbox');
                    checkboxes.forEach(checkbox => {
                         checkbox.checked = this.checked;
                    });
               });

               // Individual checkbox functionality
               document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                         const allCheckboxes = document.querySelectorAll('.product-checkbox');
                         const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                         document.getElementById('selectAll').checked = allChecked;
                    });
               });

               /* -------------------
                  Delete modal + AJAX
                  ------------------- */
               var deleteModalEl = document.getElementById('deleteConfirmModal');
               var bsDeleteModal = bootstrap.Modal.getOrCreateInstance(deleteModalEl);

               // Use event delegation for delete buttons
               document.addEventListener('click', function(e) {
                    var delBtn = e.target.closest('.btn-delete');
                    if (!delBtn) return;
                    e.preventDefault();

                    var deleteId = delBtn.getAttribute('data-id');
                    var productName = delBtn.getAttribute('data-name');

                    if (!deleteId || !productName) return;

                    // Store delete info in modal
                    deleteModalEl.dataset.deleteId = deleteId;
                    deleteModalEl.dataset.productName = productName;

                    // Update modal content
                    document.getElementById('deleteProductName').textContent = productName;

                    // Show modal
                    bsDeleteModal.show();
               });

               // Confirm delete button handler
               var confirmBtn = document.getElementById('confirmDeleteBtn');
               var origConfirmHtml = confirmBtn.innerHTML;

               confirmBtn.addEventListener('click', function() {
                    var id = deleteModalEl.dataset.deleteId;
                    var productName = deleteModalEl.dataset.productName;

                    if (!id) {
                         if (bsDeleteModal) bsDeleteModal.hide();
                         return;
                    }

                    // Show loading state
                    confirmBtn.disabled = true;
                    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Deleting...';

                    // Send AJAX delete request
                    var fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);

                    fetch(window.location.pathname, {
                         method: 'POST',
                         headers: {
                              'X-Requested-With': 'XMLHttpRequest'
                         },
                         body: fd,
                         credentials: 'same-origin'
                    }).then(r => r.json()).then(function(json) {
                         // Reset button state
                         confirmBtn.disabled = false;
                         confirmBtn.innerHTML = origConfirmHtml;

                         // Hide modal
                         if (bsDeleteModal) bsDeleteModal.hide();

                         if (json && json.success) {
                              // Remove row from table with animation
                              var row = document.getElementById('row-' + id);
                              if (row) {
                                   row.style.transition = 'opacity 0.3s';
                                   row.style.opacity = '0';
                                   setTimeout(function() {
                                        row.remove();
                                   }, 300);
                              }

                              // Show success toast
                              showToast(json.message || 'Product deleted successfully.', 'success');

                              // Update product count
                              var totalProductsEl = document.querySelector('.card-title');
                              if (totalProductsEl) {
                                   var match = totalProductsEl.textContent.match(/\((\d+) products\)/);
                                   if (match) {
                                        var currentCount = parseInt(match[1]);
                                        var newCount = currentCount - 1;
                                        totalProductsEl.textContent = totalProductsEl.textContent.replace(
                                             /\(\d+ products\)/,
                                             '(' + newCount + ' products)'
                                        );
                                   }
                              }

                              // Update "Showing X of Y products" message
                              var showingEl = document.querySelector('.card-footer .text-muted');
                              if (showingEl) {
                                   var match = showingEl.textContent.match(/Showing (\d+) of (\d+) products/);
                                   if (match) {
                                        var currentShowing = parseInt(match[1]);
                                        var currentTotal = parseInt(match[2]);
                                        var newShowing = currentShowing - 1;
                                        var newTotal = currentTotal - 1;

                                        showingEl.textContent = 'Showing ' + newShowing + ' of ' + newTotal + ' products';
                                   }
                              }

                         } else {
                              // Show error toast
                              showToast((json && json.error) ? json.error : 'Delete failed', 'error');
                         }
                    }).catch(function(err) {
                         // Reset button state
                         confirmBtn.disabled = false;
                         confirmBtn.innerHTML = origConfirmHtml;

                         // Hide modal
                         if (bsDeleteModal) bsDeleteModal.hide();

                         // Show error toast
                         showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                    });
               });

               /* -------------------
                  Bulk Actions
                  ------------------- */
               function performBulkAction(action) {
                    const selectedIds = [];
                    const selectedNames = [];

                    document.querySelectorAll('.product-checkbox:checked').forEach(checkbox => {
                         selectedIds.push(checkbox.dataset.id);

                         // Find product name from row
                         var row = checkbox.closest('tr');
                         if (row) {
                              var nameLink = row.querySelector('a.product-title-link');
                              if (nameLink) {
                                   selectedNames.push(nameLink.textContent);
                              }
                         }
                    });

                    if (selectedIds.length === 0) {
                         showToast('Please select at least one product to perform this action.', 'error');
                         return;
                    }

                    if (action === 'delete') {
                         // For bulk delete, we'll use a simpler approach - delete one by one
                         if (confirm(`Are you sure you want to delete ${selectedIds.length} product(s)? This action cannot be undone!`)) {
                              // You would implement bulk delete logic here
                              // For now, just show a message
                              showToast(`Bulk delete for ${selectedIds.length} products would be implemented here.`, 'success');
                         }
                    }
               }

               /* -------------------
                  Export Functionality
                  ------------------- */
               function exportData(format) {
                    showToast(`Preparing ${format} file for download...`, 'success');

                    // Simulate export process
                    setTimeout(() => {
                         showToast(`Your data has been exported as ${format} file.`, 'success');
                    }, 1500);
               }

               /* -------------------
                  Table Row Hover Effect
                  ------------------- */
               document.querySelectorAll('tbody tr').forEach(row => {
                    row.addEventListener('mouseenter', function() {
                         this.style.backgroundColor = '#f8f9fa';
                    });
                    row.addEventListener('mouseleave', function() {
                         this.style.backgroundColor = '';
                    });
               });

               /* -------------------
                  Bulk Action Buttons (if added in UI)
                  ------------------- */
               // Example: If you add bulk action buttons, wire them up here
               // document.querySelector('.btn-bulk-delete')?.addEventListener('click', () => performBulkAction('delete'));

          })();
     </script>

</body>

</html>