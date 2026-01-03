<?php
// product-view.php
// Admin side product details page

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

// Check if product ID is provided
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($product_id <= 0) {
    die("Invalid product ID");
}

// Fetch product details
$product = [];
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
        c.id as category_id
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param('i', $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Product not found");
}

$product = $result->fetch_assoc();
$stmt->close();

// Fetch product variants with images
$variants = [];
$variant_query = "
    SELECT 
        pv.id as variant_id,
        pv.color,
        pv.sizes,  -- Changed from pv.size to pv.sizes (JSON array)
        pv.mrp,
        pv.sale_price,
        pv.stock,
        pv.metadata,
        pv.stock_per_size,  -- Added to get stock per size
        GROUP_CONCAT(DISTINCT pi.url ORDER BY pi.position) as images
    FROM product_variants pv
    LEFT JOIN product_images pi ON pv.id = pi.variant_id
    WHERE pv.product_id = ?
    GROUP BY pv.id
    ORDER BY pv.color
";

$stmt = $conn->prepare($variant_query);
$stmt->bind_param('i', $product_id);
$stmt->execute();
$variant_result = $stmt->get_result();

// Group variants by color
$variants_by_color = [];
$all_images = [];
$unique_colors = [];

while ($variant = $variant_result->fetch_assoc()) {
    $variant['images'] = $variant['images'] ? explode(',', $variant['images']) : [];

    // Decode sizes from JSON array
    $variant['sizes_array'] = !empty($variant['sizes']) ? json_decode($variant['sizes'], true) : [];
    $variant['stock_per_size_obj'] = !empty($variant['stock_per_size']) ? json_decode($variant['stock_per_size'], true) : [];

    // Convert sizes array to string for display (like "S, M, L")
    $variant['sizes_display'] = !empty($variant['sizes_array']) ? implode(', ', $variant['sizes_array']) : '';

    $variants[] = $variant;

    // Group by color - use color value directly
    $color_value = $variant['color'] ?: '#000000';

    if (!isset($variants_by_color[$color_value])) {
        $variants_by_color[$color_value] = [
            'color_value' => $color_value,
            'variants' => [],
            'images' => [],
            'all_sizes' => []  // Track all sizes for this color
        ];
        $unique_colors[] = $color_value;
    }

    $variants_by_color[$color_value]['variants'][] = $variant;

    // Collect all sizes for this color
    if (!empty($variant['sizes_array'])) {
        foreach ($variant['sizes_array'] as $size) {
            if (!in_array($size, $variants_by_color[$color_value]['all_sizes'])) {
                $variants_by_color[$color_value]['all_sizes'][] = $size;
            }
        }
    }

    // Add images for this color
    if (!empty($variant['images'])) {
        foreach ($variant['images'] as $image) {
            if (!in_array($image, $variants_by_color[$color_value]['images'])) {
                $variants_by_color[$color_value]['images'][] = $image;
            }
            if (!in_array($image, $all_images)) {
                $all_images[] = $image;
            }
        }
    }
}

$stmt->close();

// Get default color (first one)
$default_color = !empty($unique_colors) ? $unique_colors[0] : null;
$selected_color = isset($_GET['color']) ? $_GET['color'] : $default_color;

// Get variants for selected color
$selected_color_variants = $selected_color ? ($variants_by_color[$selected_color]['variants'] ?? []) : [];

// Get images for selected color
$selected_color_images = $selected_color ? ($variants_by_color[$selected_color]['images'] ?? []) : $all_images;

// Get product metadata from first variant (should be same for all)
$product_metadata = [];
if (!empty($variants) && isset($variants[0]['metadata']) && $variants[0]['metadata']) {
    $product_metadata = json_decode($variants[0]['metadata'], true);
}

// Calculate min and max prices
$min_price = null;
$max_price = null;
$total_stock = 0;
$available_sizes = [];

foreach ($variants as $variant) {
    if ($variant['sale_price'] !== null) {
        if ($min_price === null || $variant['sale_price'] < $min_price) {
            $min_price = $variant['sale_price'];
        }
        if ($max_price === null || $variant['sale_price'] > $max_price) {
            $max_price = $variant['sale_price'];
        }
    }
    $total_stock += $variant['stock'];

    // Get all sizes from the JSON array
    if (!empty($variant['sizes_array'])) {
        foreach ($variant['sizes_array'] as $size) {
            if (!in_array($size, $available_sizes)) {
                $available_sizes[] = $size;
            }
        }
    }
}

// Sort sizes
usort($available_sizes, function ($a, $b) {
    $size_order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
    $a_index = array_search($a, $size_order);
    $b_index = array_search($b, $size_order);
    $a_index = $a_index !== false ? $a_index : 999;
    $b_index = $b_index !== false ? $b_index : 999;
    return $a_index - $b_index;
});

// Get all sizes across all colors
$all_sizes = $available_sizes;

// Also sort sizes for each color
foreach ($variants_by_color as $color => &$color_data) {
    if (!empty($color_data['all_sizes'])) {
        usort($color_data['all_sizes'], function ($a, $b) {
            $size_order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
            $a_index = array_search($a, $size_order);
            $b_index = array_search($b, $size_order);
            $a_index = $a_index !== false ? $a_index : 999;
            $b_index = $b_index !== false ? $b_index : 999;
            return $a_index - $b_index;
        });
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Details | Admin Panel</title>

    <!-- App favicon -->
    <link rel="shortcut icon" href="../assets/images/favicon.ico">

    <!-- Vendor css (Require in all Page) -->
    <link href="../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

    <!-- Icons css (Require in all Page) -->
    <link href="../assets/css/icons.min.css" rel="stylesheet" type="text/css" />

    <!-- App css (Require in all Page) -->
    <link href="../assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <!-- Theme Config js (Require in all Page) -->
    <script src="../assets/js/config.js"></script>

    <style>
        /* Swiper Carousel */
        .swiper-container {
            width: 100%;
            height: 400px;
            border-radius: 8px;
            overflow: hidden;
            background: #f8f9fa;
        }

        .swiper-slide {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-image {
            width: 100%;
            height: 400px;
            object-fit: contain;
        }

        .dynamic-pagination {
            bottom: 15px !important;
        }

        .dynamic-pagination .swiper-pagination-bullet {
            background-color: #000;
            opacity: 0.5;
        }

        .dynamic-pagination .swiper-pagination-bullet-active {
            background-color: #4CAF7C;
            opacity: 1;
        }

        /* Color Selection */
        .color-selection {
            margin-top: 20px;
        }

        .color-chip {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-block;
            margin-right: 8px;
            margin-bottom: 8px;
            border: 2px solid #dee2e6;
            transition: all 0.2s;
        }

        .color-chip:hover {
            transform: scale(1.1);
        }

        .color-chip.active {
            border-color: #4CAF7C;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.2);
        }

        /* Size Selection */
        .size-selection {
            margin-top: 20px;
        }

        .size-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 15px;
            margin: 4px;
            border-radius: 6px;
            border: 2px solid #dee2e6;
            background: white;
            font-weight: 500;
            color: #495057;
            position: relative;
        }

        .size-option.available {
            border-color: #4CAF7C;
            background: #f8fff9;
        }

        .size-option.unavailable {
            opacity: 0.5;
            background: #f8f9fa;
        }

        .stock-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #4CAF7C;
            color: white;
            font-size: 10px;
            padding: 1px 5px;
            border-radius: 10px;
            font-weight: bold;
            min-width: 20px;
            text-align: center;
        }

        .stock-count.out-of-stock {
            background: #dc3545;
        }

        .variant-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .price-badge {
            font-size: 1.3rem;
            font-weight: 600;
            color: #4CAF7C;
        }

        .stock-badge {
            font-size: 0.9rem;
            padding: 0.25rem 0.75rem;
            display: inline-block;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background-color: #d1fae5;
            color: #4CAF7C;
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

        .metadata-row {
            border-bottom: 1px solid #e9ecef;
            padding: 10px 0;
        }

        .metadata-row:last-child {
            border-bottom: none;
        }

        .action-buttons {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
        }

        .action-buttons .btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin-left: 5px;
        }

        .product-info-card {
            border-left: 4px solid #4CAF7C;
        }

        .color-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .color-info-preview {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 2px solid #4CAF7C;
        }

        .no-images {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 400px;
            background: #f8f9fa;
            border-radius: 8px;
            color: #6c757d;
            flex-direction: column;
        }

        .no-images i {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .total-stock-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: white;
        }

        .summary-card .card-body {
            padding: 20px 15px;
        }

        .summary-card h6 {
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .summary-card h3 {
            color: #212529;
            font-weight: 700;
            font-size: 1.8rem;
            margin: 0;
        }

        .thumbnail-container {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .thumbnail-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .thumbnail-image:hover {
            border-color: #dee2e6;
        }

        .thumbnail-image.active {
            border-color: #4CAF7C;
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

                <!-- Breadcrumb -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">Product Details</h4>
                            <div class="d-flex gap-2">
                                <a href="edit-product.php?id=<?php echo $product_id; ?>" class="btn btn-primary">
                                    <i class="bx bx-edit me-1"></i> Edit Product
                                </a>
                                <a href="list-products.php" class="btn btn-light">
                                    <i class="bx bx-arrow-back me-1"></i> Back to List
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-2">Total Variants</h6>
                                <h3 class="fw-bold"><?php echo count($variants); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-2">Available Colors</h6>
                                <h3 class="fw-bold"><?php echo count($unique_colors); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-2">Total Images</h6>
                                <h3 class="fw-bold"><?php echo count($all_images); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card summary-card">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-2">Available Sizes</h6>
                                <h3 class="fw-bold"><?php echo count($available_sizes); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Details -->
                <div class="row">
                    <!-- Left Column: Images -->
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-body position-relative p-0">
                                <!-- Action Buttons on Image -->
                                <div class="action-buttons">
                                    <a href="edit-product.php?id=<?php echo $product_id; ?>" class="btn btn-primary btn-sm">
                                        <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                    </a>
                                    <button type="button" class="btn btn-danger btn-sm mt-2" onclick="confirmDelete()">
                                        <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                    </button>
                                </div>

                                <!-- Swiper Carousel -->
                                <?php if (!empty($selected_color_images)): ?>
                                    <div class="swiper rounded" id="productSwiper" data-swiper="dynamic">
                                        <div class="swiper-wrapper">
                                            <?php foreach ($selected_color_images as $index => $image): ?>
                                                <div class="swiper-slide">
                                                    <img src="<?php echo e($image); ?>"
                                                        alt="<?php echo e($product['title']); ?>"
                                                        class="product-image">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="swiper-pagination dynamic-pagination"></div>
                                    </div>

                                    <!-- Thumbnails -->
                                    <div class="thumbnail-container p-3">
                                        <?php foreach ($selected_color_images as $index => $image): ?>
                                            <img src="<?php echo e($image); ?>"
                                                alt="Thumbnail <?php echo $index + 1; ?>"
                                                class="thumbnail-image <?php echo $index === 0 ? 'active' : ''; ?>"
                                                data-index="<?php echo $index; ?>"
                                                onclick="goToSlide(<?php echo $index; ?>)">
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-images">
                                        <i class="bx bx-image-alt"></i>
                                        <p>No images available</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Product Info -->
                    <div class="col-lg-7">
                        <div class="card product-info-card">
                            <div class="card-body">
                                <!-- Product Title and Status -->
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h2 class="fw-bold text-dark mb-1"><?php echo e($product['title']); ?></h2>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <?php if ($product['is_active']): ?>
                                                <span class="status-badge status-active">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">Inactive</span>
                                            <?php endif; ?>

                                            <?php if ($product['is_custom_made']): ?>
                                                <span class="badge custom-badge">Custom Made</span>
                                            <?php endif; ?>

                                            <span class="text-muted fs-14">ID: <?php echo $product_id; ?></span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="price-badge text-primary">
                                            <?php if ($min_price !== null && $max_price !== null): ?>
                                                <?php if ($min_price == $max_price): ?>
                                                    ₹<?php echo number_format($min_price, 2); ?>
                                                <?php else: ?>
                                                    ₹<?php echo number_format($min_price, 2); ?> - ₹<?php echo number_format($max_price, 2); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">No price set</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Size Selection -->
                                <?php if (!empty($all_sizes)): ?>
                                    <div class="size-selection p-3 pt-0">
                                        <h6 class="text-dark fw-medium mb-3">Available Sizes:</h6>
                                        <div>
                                            <?php
                                            // Calculate stock per size for selected color
                                            $size_stock_map = [];
                                            if ($selected_color && !empty($selected_color_variants)) {
                                                foreach ($selected_color_variants as $variant) {
                                                    if (!empty($variant['stock_per_size_obj'])) {
                                                        foreach ($variant['stock_per_size_obj'] as $size => $stock) {
                                                            if (!isset($size_stock_map[$size])) {
                                                                $size_stock_map[$size] = 0;
                                                            }
                                                            $size_stock_map[$size] += (int)$stock;
                                                        }
                                                    }
                                                }
                                            }

                                            foreach ($all_sizes as $size):
                                                $is_available = false;
                                                $size_stock = 0;

                                                // Check if this size exists in any variant for selected color
                                                if ($selected_color && !empty($selected_color_variants)) {
                                                    foreach ($selected_color_variants as $variant) {
                                                        if (in_array($size, $variant['sizes_array'] ?? [])) {
                                                            $is_available = true;
                                                            if (isset($size_stock_map[$size])) {
                                                                $size_stock = $size_stock_map[$size];
                                                            }
                                                            break;
                                                        }
                                                    }
                                                }
                                            ?>
                                                <div class="size-option <?php echo $is_available ? 'available' : 'unavailable'; ?>">
                                                    <?php echo e($size); ?>
                                                    <?php if ($is_available && $size_stock > 0): ?>
                                                        <span class="stock-count <?php echo ($size_stock > 0) ? '' : 'out-of-stock'; ?>">
                                                            <?php echo $size_stock; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Basic Info -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-medium">Brand:</label>
                                            <p class="mb-0"><?php echo e($product['brand'] ?: 'Not specified'); ?></p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-medium">Category:</label>
                                            <p class="mb-0"><?php echo e($product['category_name'] ?: 'Uncategorized'); ?></p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-medium">Total Stock:</label>
                                            <div class="total-stock-container">
                                                <span class="badge bg-<?php echo ($total_stock > 0) ? 'success' : 'danger'; ?> stock-badge">
                                                    <?php echo number_format($total_stock); ?> items
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-medium">Available Colors:</label>
                                            <?php if (!empty($unique_colors)): ?>
                                                <div class="color-selection">
                                                    <div>
                                                        <?php foreach ($unique_colors as $color_value): ?>
                                                            <div class="color-chip <?php echo ($color_value === $selected_color) ? 'active' : ''; ?>"
                                                                style="background-color: <?php echo preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color_value) ? e($color_value) : '#f8f9fa'; ?>;"
                                                                onclick="selectColor('<?php echo e($color_value); ?>')"
                                                                title="Click to view <?php echo e($color_value); ?>">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-medium">Created:</label>
                                            <p class="mb-0"><?php echo date('F j, Y', strtotime($product['created_at'])); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Description -->
                                <div class="mb-4">
                                    <label class="form-label fw-medium">Description:</label>
                                    <div class="bg-light-subtle p-3 rounded">
                                        <?php echo nl2br(e($product['description'] ?: 'No description provided.')); ?>
                                    </div>
                                </div>

                                <!-- Variants Table for Selected Color -->
                                <!-- Variants Table for Selected Color - One row per size -->
                                <?php if (!empty($selected_color_variants)): ?>
                                    <div class="mt-4">
                                        <h5 class="mb-3">Stock Details for Selected Color:</h5>
                                        <div class="table-responsive">
                                            <table class="table table-bordered variant-table">
                                                <thead>
                                                    <tr>
                                                        <th>Size</th>
                                                        <th>MRP</th>
                                                        <th>Sale Price</th>
                                                        <th>Stock</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // First, collect all size-stock combinations for this color
                                                    $size_details = [];

                                                    foreach ($selected_color_variants as $variant):
                                                        if (!empty($variant['stock_per_size_obj'])):
                                                            foreach ($variant['stock_per_size_obj'] as $size => $stock):
                                                                // If same size appears in multiple variants, combine stock
                                                                if (!isset($size_details[$size])) {
                                                                    $size_details[$size] = [
                                                                        'size' => $size,
                                                                        'mrp' => $variant['mrp'],
                                                                        'sale_price' => $variant['sale_price'],
                                                                        'stock' => (int)$stock,
                                                                        'variant_id' => $variant['variant_id']
                                                                    ];
                                                                } else {
                                                                    // Add stock if same size from another variant
                                                                    $size_details[$size]['stock'] += (int)$stock;
                                                                }
                                                            endforeach;
                                                        endif;
                                                    endforeach;

                                                    // Sort sizes in order
                                                    uasort($size_details, function ($a, $b) {
                                                        $size_order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
                                                        $a_index = array_search($a['size'], $size_order);
                                                        $b_index = array_search($b['size'], $size_order);
                                                        $a_index = $a_index !== false ? $a_index : 999;
                                                        $b_index = $b_index !== false ? $b_index : 999;
                                                        return $a_index - $b_index;
                                                    });

                                                    // Display each size as separate row
                                                    foreach ($size_details as $detail):
                                                    ?>
                                                        <tr>
                                                            <td><strong><?php echo e($detail['size']); ?></strong></td>
                                                            <td>
                                                                <?php if ($detail['mrp']): ?>
                                                                    ₹<?php echo number_format($detail['mrp'], 2); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($detail['sale_price']): ?>
                                                                    <span class="text-primary fw-medium">
                                                                        ₹<?php echo number_format($detail['sale_price'], 2); ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo ($detail['stock'] > 0) ? 'success' : 'danger'; ?>">
                                                                    <?php echo number_format($detail['stock']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($detail['stock'] > 0): ?>
                                                                    <span class="badge bg-success">In Stock</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Out of Stock</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>

                                                    <?php if (empty($size_details)): ?>
                                                        <tr>
                                                            <td colspan="5" class="text-center text-muted">
                                                                No size-specific stock information available
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Product Metadata -->
                                <?php if (!empty($product_metadata)): ?>
                                    <div class="mt-4">
                                        <h5 class="mb-3">Product Specifications</h5>
                                        <div class="bg-light-subtle p-3 rounded">
                                            <?php foreach ($product_metadata as $key => $value): ?>
                                                <div class="metadata-row">
                                                    <div class="row">
                                                        <div class="col-md-4 fw-medium fw-bolder"><?php echo e($key); ?>:</div>
                                                        <div class="col-md-8 fw-light"><?php echo e($value); ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer bg-light-subtle">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <a href="edit-product.php?id=<?php echo $product_id; ?>" class="btn btn-primary w-100">
                                            <i class="bx bx-edit me-1"></i> Edit Product
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="button" class="btn btn-danger w-100" onclick="confirmDelete()">
                                            <i class="bx bx-trash me-1"></i> Delete Product
                                        </button>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="list-products.php" class="btn btn-light w-100">
                                            <i class="bx bx-arrow-back me-1"></i> Back to List
                                        </a>
                                    </div>
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
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the product "<strong><?php echo e($product['title']); ?></strong>"?</p>
                    <p class="text-danger mb-0"><strong>Warning:</strong> This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="list-products.php?action=delete&id=<?php echo $product_id; ?>" class="btn btn-danger">Delete Product</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor Javascript (Require in all Page) -->
    <script src="../assets/js/vendor.js"></script>

    <!-- App Javascript (Require in all Page) -->
    <script src="../assets/js/app.js"></script>

    <script>
        // Store color data for JavaScript
        const colorData = <?php echo json_encode([
                                'product_id' => $product_id,
                                'colors' => $unique_colors,
                                'variants_by_color' => $variants_by_color,
                                'selected_color' => $selected_color,
                                'all_images' => $all_images,
                                'all_sizes' => $all_sizes
                            ]); ?>;
        
        // Swiper instance variable
        let productSwiperInstance = null;

        // Initialize Swiper
        document.addEventListener('DOMContentLoaded', function() {
            initSwiper();
        });

        function initSwiper() {
            const swiperEl = document.getElementById('productSwiper');
            if (swiperEl) {
                // Destroy existing instance if any
                if (productSwiperInstance) {
                    productSwiperInstance.destroy(true, true);
                }

                // Swiper will be initialized by app.js because of data-swiper="dynamic"
                // but we need to capture the instance if we want to control it manually later
                setTimeout(() => {
                    productSwiperInstance = swiperEl.swiper;
                    if (productSwiperInstance) {
                        productSwiperInstance.on('slideChange', function() {
                            updateActiveThumbnail(productSwiperInstance.realIndex);
                        });
                    }
                }, 100);
            }
        }

        // Go to specific slide
        function goToSlide(index) {
            if (productSwiperInstance) {
                productSwiperInstance.slideToLoop(index);
                updateActiveThumbnail(index);
            }
        }

        // Update active thumbnail
        function updateActiveThumbnail(activeIndex) {
            document.querySelectorAll('.thumbnail-image').forEach((thumb, index) => {
                thumb.classList.toggle('active', index === activeIndex);
            });
        }

        // Select color without page reload
        function selectColor(colorValue) {
            // Update active color chip
            document.querySelectorAll('.color-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            event.target.classList.add('active');

            // Get data for selected color
            const colorVariants = colorData.variants_by_color[colorValue]?.variants || [];
            const colorImages = colorData.variants_by_color[colorValue]?.images || colorData.all_images;
            const colorSizes = colorData.variants_by_color[colorValue]?.all_sizes || [];

            // 1. Update carousel images
            updateSwiperImages(colorImages);

            // 2. Update sizes display with correct stock
            updateSizesDisplay(colorVariants, colorSizes);

            // 3. Update variants table with detailed stock per size
            updateVariantsTable(colorVariants);

            // Update URL without reloading page
            const url = new URL(window.location.href);
            url.searchParams.set('color', colorValue);
            window.history.pushState({}, '', url);
        }

        // Update Swiper images
        function updateSwiperImages(images) {
            const swiperWrapper = document.querySelector('.swiper-wrapper');
            const thumbnailContainer = document.querySelector('.thumbnail-container');

            if (!images || images.length === 0) {
                // Show no images message
                swiperWrapper.innerHTML = `
                    <div class="swiper-slide">
                        <div class="no-images">
                            <i class="bx bx-image-alt"></i>
                            <p>No images available</p>
                        </div>
                    </div>
                `;
                if (thumbnailContainer) thumbnailContainer.innerHTML = '';
                return;
            }

            // Clear existing slides and thumbnails
            swiperWrapper.innerHTML = '';
            if (thumbnailContainer) thumbnailContainer.innerHTML = '';

            // Add new slides and thumbnails
            images.forEach((image, index) => {
                // Add slide
                const slide = document.createElement('div');
                slide.className = 'swiper-slide';
                slide.innerHTML = `<img src="${image}" alt="Product image" class="product-image">`;
                swiperWrapper.appendChild(slide);

                // Add thumbnail
                if (thumbnailContainer) {
                    const thumb = document.createElement('img');
                    thumb.src = image;
                    thumb.alt = `Thumbnail ${index + 1}`;
                    thumb.className = `thumbnail-image ${index === 0 ? 'active' : ''}`;
                    thumb.setAttribute('data-index', index);
                    thumb.onclick = () => goToSlide(index);
                    thumbnailContainer.appendChild(thumb);
                }
            });

            // Re-initialize Swiper
            if (typeof Swiper !== 'undefined') {
                if (productSwiperInstance) {
                    productSwiperInstance.destroy(true, true);
                }
                
                productSwiperInstance = new Swiper("#productSwiper", {
                    loop: true,
                    autoplay: {
                        delay: 2500,
                        disableOnInteraction: false,
                    },
                    pagination: {
                        clickable: true,
                        el: ".swiper-pagination",
                        dynamicBullets: true,
                    },
                    on: {
                        slideChange: function () {
                            updateActiveThumbnail(this.realIndex);
                        }
                    }
                });
            }
        }

        // Update sizes display with correct stock per size
        function updateSizesDisplay(variants, allSizesForColor) {
            const sizeContainer = document.querySelector('.size-selection div');
            if (!sizeContainer) return;

            // Create a map of size to total stock for this color
            const sizeStockMap = {};

            variants.forEach(variant => {
                if (variant.stock_per_size_obj) {
                    Object.entries(variant.stock_per_size_obj).forEach(([size, stock]) => {
                        if (!sizeStockMap[size]) {
                            sizeStockMap[size] = 0;
                        }
                        sizeStockMap[size] += parseInt(stock);
                    });
                }
            });

            // Clear and rebuild size options
            sizeContainer.innerHTML = '';

            // Sort sizes in order
            const sortedSizes = [...allSizesForColor].sort((a, b) => {
                const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
                const aIndex = sizeOrder.indexOf(a);
                const bIndex = sizeOrder.indexOf(b);
                return (aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex);
            });

            sortedSizes.forEach(size => {
                const stock = sizeStockMap[size] || 0;
                const isAvailable = stock > 0;

                const sizeOption = document.createElement('div');
                sizeOption.className = `size-option ${isAvailable ? 'available' : 'unavailable'}`;
                sizeOption.textContent = size;

                if (stock > 0) {
                    const stockBadge = document.createElement('span');
                    stockBadge.className = `stock-count ${stock > 0 ? '' : 'out-of-stock'}`;
                    stockBadge.textContent = stock;
                    sizeOption.appendChild(stockBadge);
                }

                sizeContainer.appendChild(sizeOption);
            });
        }

        // Update variants table with detailed stock per size
        // Update variants table with one row per size
        function updateVariantsTable(variants) {
            const tableBody = document.querySelector('.variant-table tbody');
            if (!tableBody) return;

            // Collect all size-stock combinations
            const sizeDetails = {};

            variants.forEach(variant => {
                if (variant.stock_per_size_obj) {
                    Object.entries(variant.stock_per_size_obj).forEach(([size, stock]) => {
                        const stockInt = parseInt(stock);
                        if (!sizeDetails[size]) {
                            sizeDetails[size] = {
                                size: size,
                                mrp: variant.mrp,
                                sale_price: variant.sale_price,
                                stock: stockInt,
                                variant_id: variant.variant_id
                            };
                        } else {
                            // Add stock if same size from another variant
                            sizeDetails[size].stock += stockInt;
                        }
                    });
                }
            });

            // Sort sizes in order
            const sortedSizes = Object.values(sizeDetails).sort((a, b) => {
                const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
                const aIndex = sizeOrder.indexOf(a.size);
                const bIndex = sizeOrder.indexOf(b.size);
                return (aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex);
            });

            // Clear table
            tableBody.innerHTML = '';

            // Add new rows for each size
            if (sortedSizes.length > 0) {
                sortedSizes.forEach(detail => {
                    const row = document.createElement('tr');

                    // Format prices
                    const mrp = detail.mrp ? `₹${parseFloat(detail.mrp).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : '<span class="text-muted">-</span>';
                    const salePrice = detail.sale_price ?
                        `<span class="text-primary fw-medium">₹${parseFloat(detail.sale_price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>` :
                        '<span class="text-muted">-</span>';

                    // Stock and status
                    const stockBadgeClass = detail.stock > 0 ? 'success' : 'danger';
                    const statusBadgeClass = detail.stock > 0 ? 'success' : 'danger';
                    const statusText = detail.stock > 0 ? 'In Stock' : 'Out of Stock';

                    row.innerHTML = `
                <td><strong>${detail.size}</strong></td>
                <td>${mrp}</td>
                <td>${salePrice}</td>
                <td>
                    <span class="badge bg-${stockBadgeClass}">
                        ${detail.stock.toLocaleString()}
                    </span>
                </td>
                <td>
                    <span class="badge bg-${statusBadgeClass}">${statusText}</span>
                </td>
            `;

                    tableBody.appendChild(row);
                });
            } else {
                // Show no data message
                tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-muted">
                    No size-specific stock information available
                </td>
            </tr>
        `;
            }

            // Update table header text if needed
            const tableHeader = document.querySelector('.variant-table + h5');
            if (tableHeader) {
                tableHeader.textContent = 'Stock Details for Selected Color:';
            }
        }

        // Confirm delete function
        function confirmDelete() {
            const modalElement = document.getElementById('deleteConfirmModal');
            if (modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        // Initialize page with selected color data
        window.addEventListener('load', function() {
            // Nothing needed here as PHP already renders the initial state
        });
    </script>

</body>

</html>