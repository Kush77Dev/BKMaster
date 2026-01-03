<?php
// add-collection.php
// Single-file create + edit collection with product selection modal

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

// Handle AJAX requests for getting products
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_products') {
    getProducts($conn);
    exit;
}

// Handle AJAX requests for getting product details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_product_details') {
    getProductDetails($conn);
    exit;
}

/* init */
$success = "";
$error = "";
$insert_id = null;
$selected_products = [];

// Utility: safe output for HTML
function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Function to get products for modal
function getProducts($conn)
{
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit = 12;
    $offset = ($page - 1) * $limit;

    // Build the main query to get products with their details
    $where = '';
    $params = [];
    $types = '';

    if ($search) {
        $where = "WHERE p.title LIKE ? OR p.description LIKE ?";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'ss';
    }

    $sql = "
        SELECT 
            p.id,
            p.title,
            p.description,
            p.category_id,
            p.brand,
            p.is_active,
            MIN(pv.sale_price) as min_price,
            MAX(pv.sale_price) as max_price,
            (
                SELECT pi.url 
                FROM product_images pi 
                WHERE pi.product_id = p.id 
                ORDER BY pi.position ASC 
                LIMIT 1
            ) as image_url,
            (
                SELECT GROUP_CONCAT(DISTINCT pv2.color ORDER BY pv2.color)
                FROM product_variants pv2 
                WHERE pv2.product_id = p.id AND pv2.color IS NOT NULL AND pv2.color != ''
            ) as colors,
            (
                SELECT GROUP_CONCAT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(sizes, '$[*]')))
                FROM product_variants pv3 
                WHERE pv3.product_id = p.id AND pv3.sizes IS NOT NULL
            ) as all_sizes
        FROM products p
        LEFT JOIN product_variants pv ON pv.product_id = p.id
        {$where}
        GROUP BY p.id
        ORDER BY p.id DESC
        LIMIT ? OFFSET ?
    ";

    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            echo json_encode(['error' => 'Query execution failed: ' . $stmt->error]);
            exit;
        }

        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure we have at least a minimum price
            if ($row['min_price'] === null) {
                $row['min_price'] = 0;
                $row['max_price'] = 0;
            }

            // Process colors
            $colors = [];
            if (!empty($row['colors'])) {
                $color_array = explode(',', $row['colors']);
                $colors = array_unique($color_array);
            }
            $row['colors'] = array_slice($colors, 0, 3);

            // Process sizes
            $sizes = [];
            if (!empty($row['all_sizes'])) {
                // Handle JSON array in sizes field
                $size_array = explode(',', $row['all_sizes']);
                // Clean up the sizes
                $size_array = array_map(function ($size) {
                    return trim($size, '[]"\' ');
                }, $size_array);
                $sizes = array_unique($size_array);
            }
            $row['sizes'] = array_slice($sizes, 0, 5);

            $products[] = $row;
        }
        $stmt->close();

        // Check if there are more products
        $countSql = "SELECT COUNT(DISTINCT p.id) as total FROM products p";
        if ($search) {
            $countSql .= " WHERE p.title LIKE ? OR p.description LIKE ?";
        }

        $countStmt = $conn->prepare($countSql);
        if ($search) {
            $countStmt->bind_param('ss', $search, $search);
        }

        if (!$countStmt->execute()) {
            echo json_encode(['error' => 'Count query failed: ' . $countStmt->error]);
            exit;
        }

        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $totalProducts = $totalRow['total'];
        $countStmt->close();

        $hasMore = ($page * $limit) < $totalProducts;

        header('Content-Type: application/json');
        echo json_encode([
            'products' => $products,
            'hasMore' => $hasMore,
            'total' => $totalProducts
        ]);
    } else {
        echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
    }
    exit;
}

// Function to get product details
function getProductDetails($conn)
{
    $product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($product_id <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit;
    }

    $sql = "
        SELECT 
            p.id,
            p.title,
            (
                SELECT pi.url 
                FROM product_images pi 
                WHERE pi.product_id = p.id 
                ORDER BY pi.position ASC 
                LIMIT 1
            ) as image_url,
            MIN(pv.sale_price) as min_price,
            MAX(pv.sale_price) as max_price,
            (
                SELECT GROUP_CONCAT(DISTINCT pv2.color ORDER BY pv2.color)
                FROM product_variants pv2 
                WHERE pv2.product_id = p.id AND pv2.color IS NOT NULL AND pv2.color != ''
            ) as colors,
            (
                SELECT GROUP_CONCAT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(sizes, '$[*]')))
                FROM product_variants pv3 
                WHERE pv3.product_id = p.id AND pv3.sizes IS NOT NULL
            ) as all_sizes
        FROM products p
        LEFT JOIN product_variants pv ON pv.product_id = p.id
        WHERE p.id = ?
        GROUP BY p.id
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $product_id);

        if (!$stmt->execute()) {
            echo json_encode(['error' => 'Query execution failed: ' . $stmt->error]);
            exit;
        }

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Process colors
            $colors = [];
            if (!empty($row['colors'])) {
                $color_array = explode(',', $row['colors']);
                $colors = array_unique($color_array);
            }
            $row['colors'] = array_slice($colors, 0, 3);

            // Process sizes
            $sizes = [];
            if (!empty($row['all_sizes'])) {
                $size_array = explode(',', $row['all_sizes']);
                $size_array = array_map(function ($size) {
                    return trim($size, '[]"\' ');
                }, $size_array);
                $sizes = array_unique($size_array);
            }
            $row['sizes'] = array_slice($sizes, 0, 5);

            header('Content-Type: application/json');
            echo json_encode($row);
        } else {
            echo json_encode(['error' => 'Product not found']);
        }

        $stmt->close();
    } else {
        echo json_encode(['error' => 'Query preparation failed: ' . $conn->error]);
    }
    exit;
}

// ---- EDIT MODE: if id provided on GET, load values to prefill ----
$edit_mode = false;
$edit_id = 0;
$prefill_title = '';
$prefill_description = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && !isset($_GET['ajax'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT id, title, description FROM collections WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $edit_mode = true;
                $edit_id = (int)$row['id'];
                $prefill_title = $row['title'];
                $prefill_description = $row['description'];

                // Load selected products for this collection
                $product_stmt = $conn->prepare("
          SELECT pc.product_id, pc.position 
          FROM product_collections pc 
          WHERE pc.collection_id = ? 
          ORDER BY pc.position ASC
        ");
                if ($product_stmt) {
                    $product_stmt->bind_param('i', $id);
                    $product_stmt->execute();
                    $product_res = $product_stmt->get_result();
                    while ($product_row = $product_res->fetch_assoc()) {
                        $selected_products[] = $product_row;
                    }
                    $product_stmt->close();
                }
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

    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $selected_product_ids = isset($_POST['selected_products']) ? explode(',', $_POST['selected_products']) : [];
    // Filter out empty values
    $selected_product_ids = array_filter($selected_product_ids, function ($id) {
        return !empty($id) && $id > 0;
    });

    if ($title === '') {
        $error = "Collection Title is required.";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            $collection_id = 0;

            if ($is_update) {
                // Update collection
                $stmt = $conn->prepare("UPDATE collections SET title = ?, description = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param('ssi', $title, $description, $id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();

                $success = "Collection updated successfully (ID: {$id}).";
                $collection_id = $id;
                $insert_id = $id;
            } else {
                // Insert collection
                $stmt = $conn->prepare("INSERT INTO collections (title, description) VALUES (?, ?)");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param('ss', $title, $description);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $collection_id = $stmt->insert_id;
                $insert_id = $collection_id;
                $stmt->close();

                $success = "Collection created successfully (ID: " . $collection_id . ").";
            }

            // Clear existing product associations (for both create and update, but update has already cleared)
            if ($is_update) {
                $delete_stmt = $conn->prepare("DELETE FROM product_collections WHERE collection_id = ?");
                if (!$delete_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $delete_stmt->bind_param('i', $collection_id);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Delete failed: " . $delete_stmt->error);
                }
                $delete_stmt->close();
            }

            // Insert selected products
            if (!empty($selected_product_ids)) {
                $position = 0;
                $insert_product_stmt = $conn->prepare("INSERT INTO product_collections (collection_id, product_id, position) VALUES (?, ?, ?)");
                // if (!$insert_product_stmt) {
                //   throw new Exception("Prepare failed: " . $insert_product_stmt->error);
                // }

                foreach ($selected_product_ids as $product_id) {
                    $product_id = (int)$product_id;
                    if ($product_id > 0) {
                        $insert_product_stmt->bind_param('iii', $collection_id, $product_id, $position);
                        if (!$insert_product_stmt->execute()) {
                            throw new Exception("Product insert failed: " . $insert_product_stmt->error);
                        }
                        $position++;
                    }
                }
                $insert_product_stmt->close();
            }

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }

    // If request is XHR, return JSON immediately
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
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $edit_mode ? 'Edit Collection' : 'Add Collection'; ?></title>

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
        .toastify {
            z-index: 20000;
        }

        .selected-product-card {
            border: 2px solid #28a745;
            position: relative;
        }

        .selected-product-card .selected-badge {
            position: absolute;
            top: 5px;
            left: 10px;
            background: #28a745;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .product-card {
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .product-card.selected {
            border: 2px solid #28a745;
        }

        .remove-product {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
        }

        .modal-xl {
            max-width: 90%;
        }

        .product-thumb {
            height: 150px;
            object-fit: cover;
            width: 100%;
        }

        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }

        /* Color Swatches */
        .color-swatch {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            margin-right: 4px;
            border: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .color-swatch-more {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 10px;
            text-align: center;
            line-height: 16px;
            border: 1px solid #dee2e6;
            vertical-align: middle;
        }

        /* Size Tags */
        .size-tag {
            display: inline-block;
            padding: 2px 6px;
            font-size: 11px;
            background: #28a745;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            margin-right: 5px;
            margin-bottom: 5px;
            color: #f8f9fa;
        }

        .product-details-row {
            font-size: 12px;
            margin-top: 5px;
        }

        .product-info {
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-top: 8px;
        }

        .modal-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #28a745;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            z-index: 5;
        }

        .title-hover:hover {
            color: #28a745 !important;
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
            <div class="container-xxl">

                <div class="row">
                    <div class="col-xl-3 col-lg-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="mt-3">
                                    <h4 id="liveTitle"><?php echo $edit_mode ? e($prefill_title) : 'Collection Title'; ?></h4>
                                    <p class="text-muted" id="liveDescription"><?php echo $edit_mode ? e($prefill_description) : 'Collection description...'; ?></p>
                                    <div class="row">
                                        <div class="col-12">
                                            <p class="mb-1 mt-2">Products in Collection:</p>
                                            <h5 class="mb-0" id="productCount"><?php echo count($selected_products); ?></h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer border-top">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <a href="list-collection.php" class="btn btn-outline-secondary w-100">Back to Collections</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-9 col-lg-8 ">
                        <!-- MAIN FORM -->
                        <form id="collectionForm" method="post" action="">
                            <input type="hidden" name="id" value="<?php echo $edit_mode ? e($edit_id) : ''; ?>">
                            <input type="hidden" name="selected_products" id="selectedProductsInput" value="<?php
                                                                                                            echo implode(',', array_column($selected_products, 'product_id'));
                                                                                                            ?>">

                            <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title">General Information</h4>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-lg-6">
                                            <div class="mb-3">
                                                <label for="collection-title" class="form-label">Collection Title *</label>
                                                <input type="text" id="collection-title" name="title" class="form-control" placeholder="Enter Collection Title"
                                                    value="<?php echo e($edit_mode ? $prefill_title : ''); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-12">
                                            <div class="mb-0">
                                                <label for="description" class="form-label">Description</label>
                                                <textarea class="form-control bg-light-subtle" id="description" name="description" rows="7"
                                                    placeholder="Type collection description"><?php echo e($edit_mode ? $prefill_description : ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h4 class="card-title mb-0">Products in Collection</h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                        <i class='bx bx-plus me-1'></i> Add Products
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="row" id="selectedProductsContainer">
                                        <?php
                                        // First, we need to fetch product details for selected products
                                        if ($edit_mode && !empty($selected_products)) {
                                            // Get product details for display
                                            $product_ids = array_column($selected_products, 'product_id');
                                            if (!empty($product_ids)) {
                                                $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
                                                $product_stmt = $conn->prepare("
                                                    SELECT 
                                                        p.id,
                                                        p.title,
                                                        (
                                                            SELECT pi.url 
                                                            FROM product_images pi 
                                                            WHERE pi.product_id = p.id 
                                                            ORDER BY pi.position ASC 
                                                            LIMIT 1
                                                        ) as image_url,
                                                        MIN(pv.sale_price) as min_price,
                                                        MAX(pv.sale_price) as max_price,
                                                        (
                                                            SELECT GROUP_CONCAT(DISTINCT pv2.color ORDER BY pv2.color)
                                                            FROM product_variants pv2 
                                                            WHERE pv2.product_id = p.id AND pv2.color IS NOT NULL AND pv2.color != ''
                                                        ) as colors,
                                                        (
                                                            SELECT GROUP_CONCAT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(sizes, '$[*]')))
                                                            FROM product_variants pv3 
                                                            WHERE pv3.product_id = p.id AND pv3.sizes IS NOT NULL
                                                        ) as all_sizes
                                                    FROM products p
                                                    LEFT JOIN product_variants pv ON pv.product_id = p.id
                                                    WHERE p.id IN ($placeholders)
                                                    GROUP BY p.id
                                                ");

                                                if ($product_stmt) {
                                                    $types = str_repeat('i', count($product_ids));
                                                    $product_stmt->bind_param($types, ...$product_ids);
                                                    $product_stmt->execute();
                                                    $product_details_result = $product_stmt->get_result();
                                                    $product_details = [];
                                                    while ($row = $product_details_result->fetch_assoc()) {
                                                        // Process colors
                                                        $colors = [];
                                                        if (!empty($row['colors'])) {
                                                            $color_array = explode(',', $row['colors']);
                                                            $colors = array_unique($color_array);
                                                        }
                                                        $row['colors'] = array_slice($colors, 0, 3);

                                                        // Process sizes
                                                        $sizes = [];
                                                        if (!empty($row['all_sizes'])) {
                                                            $size_array = explode(',', $row['all_sizes']);
                                                            $size_array = array_map(function ($size) {
                                                                return trim($size, '[]"\' ');
                                                            }, $size_array);
                                                            $sizes = array_unique($size_array);
                                                        }
                                                        $row['sizes'] = array_slice($sizes, 0, 5);

                                                        $product_details[$row['id']] = $row;
                                                    }
                                                    $product_stmt->close();
                                                }
                                            }
                                        }
                                        ?>

                                        <?php if (!empty($selected_products)): ?>
                                            <?php foreach ($selected_products as $index => $product): ?>
                                                <?php
                                                $product_id = $product['product_id'];
                                                $product_detail = isset($product_details[$product_id]) ? $product_details[$product_id] : null;
                                                $product_name = $product_detail ? $product_detail['title'] : 'Product #' . $product_id;
                                                $image_url = $product_detail ? $product_detail['image_url'] : '/../assets/images/product/p-1.png';
                                                $price_display = '';
                                                if ($product_detail) {
                                                    if ($product_detail['min_price'] === $product_detail['max_price'] || $product_detail['max_price'] === null) {
                                                        $price_display = '$' . $product_detail['min_price'];
                                                    } else {
                                                        $price_display = '$' . $product_detail['min_price'] . ' - $' . $product_detail['max_price'];
                                                    }
                                                }

                                                // Generate color swatches (no names)
                                                $colors_html = '';
                                                if ($product_detail && !empty($product_detail['colors'])) {
                                                    $color_map = [
                                                        'red' => '#dc3545',
                                                        'blue' => '#0d6efd',
                                                        'green' => '#198754',
                                                        'yellow' => '#ffc107',
                                                        'black' => '#212529',
                                                        'white' => '#ffffff',
                                                        'gray' => '#6c757d',
                                                        'pink' => '#e83e8c',
                                                        'purple' => '#6f42c1',
                                                        'orange' => '#fd7e14',
                                                        'brown' => '#795548',
                                                        'navy' => '#001f3f',
                                                        'teal' => '#20c997',
                                                        'cyan' => '#0dcaf0',
                                                        'silver' => '#c0c0c0',
                                                        'gold' => '#ffd700',
                                                        'maroon' => '#800000',
                                                        'olive' => '#808000',
                                                        'lime' => '#00ff00',
                                                        'aqua' => '#00ffff',
                                                        'fuchsia' => '#ff00ff'
                                                    ];

                                                    foreach ($product_detail['colors'] as $color) {
                                                        $lower_color = strtolower(trim($color));
                                                        $color_code = isset($color_map[$lower_color]) ? $color_map[$lower_color] : '#6c757d';
                                                        $colors_html .= '<span class="color-swatch" style="background-color: ' . $color_code . ';"></span>';
                                                    }

                                                    if (count($product_detail['colors']) > 3) {
                                                        $colors_html .= '<span class="color-swatch-more">+' . (count($product_detail['colors']) - 3) . '</span>';
                                                    }
                                                }

                                                // Generate sizes
                                                $sizes_html = '';
                                                if ($product_detail && !empty($product_detail['sizes'])) {
                                                    foreach ($product_detail['sizes'] as $size) {
                                                        $sizes_html .= '<span class="size-tag">' . htmlspecialchars($size) . '</span>';
                                                    }

                                                    if (count($product_detail['sizes']) > 5) {
                                                        $sizes_html .= '<span class="size-tag">+' . (count($product_detail['sizes']) - 5) . '</span>';
                                                    }
                                                }
                                                ?>
                                                <div class="col-md-4 col-lg-3 mb-3 product-item" data-product-id="<?php echo e($product_id); ?>">
                                                    <div class="card selected-product-card " >
                                                        <button type="button" class="remove-product" onclick="removeProduct(<?php echo e($product_id); ?>)">
                                                            <i class='bx bx-x'></i>
                                                        </button>
                                                        <div class="selected-badge"><?php echo $index + 1; ?></div>
                                                        <img src="<?php echo e($image_url); ?>"
                                                            class="product-thumb" alt="<?php echo e($product_name); ?>">
                                                        <div class="card-body bg-light-subtle rounded-bottom">
                                                            <h6 class="mb-1"><?php echo e($product_name); ?></h6>
                                                            <?php if ($price_display): ?>
                                                                <p class="text-muted mb-1"><?php echo e($price_display); ?></p>
                                                            <?php endif; ?>

                                                            <!-- Colors and Sizes -->
                                                            <div class="product-info">
                                                                <?php if ($colors_html): ?>
                                                                    <div class="mb-1"><?php echo $colors_html; ?></div>
                                                                <?php endif; ?>

                                                                <?php if ($sizes_html): ?>
                                                                    <div><?php echo $sizes_html; ?></div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="col-12 text-center py-5">
                                                <i class='bx bx-package fs-48 text-muted'></i>
                                                <h5 class="mt-3 text-muted">No products added yet</h5>
                                                <p class="text-muted">Click "Add Products" button to select products for this collection</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3 bg-light mb-3 rounded">
                                <div class="row justify-content-end g-2">
                                    <div class="col-lg-2">
                                        <button id="saveBtn" type="button" class="btn btn-outline-secondary w-100"><?php echo $edit_mode ? 'Update' : 'Save Change'; ?></button>
                                    </div>
                                    <div class="col-lg-2">
                                        <a href="list-collection.php" class="btn btn-primary w-100">Cancel</a>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <!-- END FORM -->

                        <!-- Product Selection Modal -->
                        <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="productModalLabel">Select Products</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="search-bar">
                                                    <span><i class="bx bx-search-alt"></i></span>
                                                    <input type="search" class="form-control" id="productSearch" placeholder="Search products...">
                                                </div>
                                            </div>
                                            <div class="col-md-6 text-end">
                                                <button type="button" class="btn btn-success" onclick="addSelectedProducts()">
                                                    <i class='bx bx-check me-1'></i> Add Selected Products
                                                </button>
                                            </div>
                                        </div>
                                        <div class="row" id="productGrid">
                                            <!-- Products will be loaded here via AJAX -->
                                        </div>
                                        <div class="text-center py-3" id="productLoadMore" style="display: none;">
                                            <button type="button" class="btn btn-outline-primary" onclick="loadMoreProducts()">
                                                <i class='bx bx-refresh me-1'></i> Load More
                                            </button>
                                        </div>
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
                            </script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
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
        (function() {
            // Toast helper
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

            // Show server messages
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

            // Live preview updates
            const titleInput = document.querySelector('#collection-title');
            const descriptionInput = document.querySelector('#description');
            const liveTitle = document.getElementById('liveTitle');
            const liveDescription = document.getElementById('liveDescription');

            if (titleInput) {
                titleInput.addEventListener('input', function() {
                    liveTitle.textContent = this.value.trim() || 'Collection Title';
                });
            }

            if (descriptionInput) {
                descriptionInput.addEventListener('input', function() {
                    liveDescription.textContent = this.value.trim() || 'Collection description...';
                });
            }

            // Save button handler
            document.getElementById('saveBtn').addEventListener('click', function(e) {
                e.preventDefault();
                const title = document.querySelector('#collection-title');
                if (!title || title.value.trim() === '') {
                    showToast('Collection Title is required.', 'error');
                    title.focus();
                    return;
                }
                document.getElementById('collectionForm').submit();
            });

            // Selected products management
            let selectedProductIds = <?php echo json_encode(array_column($selected_products, 'product_id')); ?>;
            let modalSelectedProductIds = [];

            // Function to update selected products input
            function updateSelectedProductsInput() {
                document.getElementById('selectedProductsInput').value = selectedProductIds.join(',');
                document.getElementById('productCount').textContent = selectedProductIds.length;
            }

            // Function to remove product from collection
            window.removeProduct = function(productId) {
                selectedProductIds = selectedProductIds.filter(id => id != productId);
                const productElement = document.querySelector(`.product-item[data-product-id="${productId}"]`);
                if (productElement) {
                    productElement.remove();
                }
                updateSelectedProductsInput();

                // If no products left, show empty state
                const container = document.getElementById('selectedProductsContainer');
                if (selectedProductIds.length === 0) {
                    container.innerHTML = `
                        <div class="col-12 text-center py-5">
                            <i class='bx bx-package fs-48 text-muted'></i>
                            <h5 class="mt-3 text-muted">No products added yet</h5>
                            <p class="text-muted">Click "Add Products" button to select products for this collection</p>
                        </div>
                    `;
                }

                showToast('Product removed from collection', 'success');
            }

            // Function to toggle product selection in modal
            window.toggleProductSelection = function(productId, element) {
                const card = element.closest('.product-card');
                const index = modalSelectedProductIds.indexOf(productId);

                if (index === -1) {
                    // Add to selection
                    modalSelectedProductIds.push(productId);
                    card.classList.add('selected');

                    // Add checkmark badge if not already there
                    if (!card.querySelector('.modal-badge')) {
                        const badge = document.createElement('div');
                        badge.className = 'modal-badge';
                        badge.innerHTML = '<i class="bx bx-check"></i>';
                        card.appendChild(badge);
                    }
                } else {
                    // Remove from selection
                    modalSelectedProductIds.splice(index, 1);
                    card.classList.remove('selected');

                    // Remove checkmark badge
                    const badge = card.querySelector('.modal-badge');
                    if (badge) {
                        badge.remove();
                    }
                }
            }

            // Function to add selected products from modal
            window.addSelectedProducts = function() {
                const selectedInModal = modalSelectedProductIds.filter(id => !selectedProductIds.includes(id));

                if (selectedInModal.length === 0) {
                    showToast('No new products selected', 'warning');
                    return;
                }

                // Get all product details from modal
                const productPromises = selectedInModal.map(productId => {
                    return fetch(`?ajax=get_product_details&id=${productId}`)
                        .then(response => response.json())
                        .then(data => ({
                            productId,
                            data
                        }))
                        .catch(() => ({
                            productId,
                            data: null
                        }));
                });

                // Wait for all product details to load
                Promise.all(productPromises).then(results => {
                    const container = document.getElementById('selectedProductsContainer');

                    // Remove empty state if present
                    if (container.querySelector('.text-center')) {
                        container.innerHTML = '';
                    }

                    results.forEach((result, index) => {
                        const productId = result.productId;
                        const productData = result.data;

                        if (productData && !productData.error) {
                            const productName = productData.title || 'Product #' + productId;
                            const imageUrl = productData.image_url || '/../assets/images/product/p-1.png';

                            // Price display
                            let priceDisplay = '';
                            if (productData.min_price !== undefined && productData.max_price !== undefined) {
                                if (productData.min_price === productData.max_price || productData.max_price === null) {
                                    priceDisplay = '$' + productData.min_price;
                                } else {
                                    priceDisplay = '$' + productData.min_price + ' - $' + productData.max_price;
                                }
                            }

                            // Generate color swatches (no names)
                            let colorsHtml = '';
                            if (productData.colors && productData.colors.length > 0) {
                                const colorMap = {
                                    'red': '#dc3545',
                                    'blue': '#0d6efd',
                                    'green': '#198754',
                                    'yellow': '#ffc107',
                                    'black': '#212529',
                                    'white': '#ffffff',
                                    'gray': '#6c757d',
                                    'pink': '#e83e8c',
                                    'purple': '#6f42c1',
                                    'orange': '#fd7e14',
                                    'brown': '#795548',
                                    'navy': '#001f3f',
                                    'teal': '#20c997',
                                    'cyan': '#0dcaf0',
                                    'silver': '#c0c0c0',
                                    'gold': '#ffd700',
                                    'maroon': '#800000',
                                    'olive': '#808000',
                                    'lime': '#00ff00',
                                    'aqua': '#00ffff',
                                    'fuchsia': '#ff00ff'
                                };

                                productData.colors.slice(0, 3).forEach(color => {
                                    const lowerColor = color.toLowerCase().trim();
                                    const colorCode = colorMap[lowerColor] || '#6c757d';
                                    colorsHtml += `<span class="color-swatch" style="background-color: ${colorCode};"></span>`;
                                });

                                if (productData.colors.length > 3) {
                                    colorsHtml += `<span class="color-swatch-more">+${productData.colors.length - 3}</span>`;
                                }
                            }

                            // Generate sizes
                            let sizesHtml = '';
                            if (productData.sizes && productData.sizes.length > 0) {
                                productData.sizes.slice(0, 5).forEach(size => {
                                    sizesHtml += `<span class="size-tag">${size}</span>`;
                                });

                                if (productData.sizes.length > 5) {
                                    sizesHtml += `<span class="size-tag">+${productData.sizes.length - 5}</span>`;
                                }
                            }

                            const globalIndex = selectedProductIds.length + index + 1;
                            container.innerHTML += `
                                <div class="col-md-4 col-lg-3 mb-3 product-item" data-product-id="${productId}">
                                    <div class="card selected-product-card">
                                        <button type="button" class="remove-product" onclick="removeProduct(${productId})">
                                            <i class='bx bx-x'></i>
                                        </button>
                                        <div class="selected-badge">${globalIndex}</div>
                                        <img src="${imageUrl}" class="product-thumb" alt="${productName}">
                                        <div class="card-body bg-light-subtle rounded-bottom">
                                            <a href="../product/details-product.php?id=${productId}" class="product-title-link fw-medium fs-15 d-block mb-1 text-dark title-hover" target="_blank">
                                                            ${productName}
                                                        </a>
                                            ${priceDisplay ? `<p class="text-muted mb-1">${priceDisplay}</p>` : ''}
                                            
                                            <!-- Colors and Sizes -->
                                            <div class="product-info">
                                                ${colorsHtml ? `<div class="mb-1">${colorsHtml}</div>` : ''}
                                                ${sizesHtml ? `<div>${sizesHtml}</div>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;

                            selectedProductIds.push(productId);
                        }
                    });

                    updateSelectedProductsInput();
                    showToast(`${selectedInModal.length} product(s) added to collection`, 'success');

                    // Clear modal selection and close modal
                    modalSelectedProductIds = [];
                    document.querySelectorAll('.product-card.selected').forEach(card => {
                        card.classList.remove('selected');
                        const badge = card.querySelector('.modal-badge');
                        if (badge) badge.remove();
                    });

                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
                    if (modal) modal.hide();
                });
            }

            // Load products for modal
            let currentPage = 1;
            let isLoading = false;
            let searchTerm = '';

            function loadProducts(page = 1, search = '') {
                if (isLoading) return;
                isLoading = true;

                // Show loading indicator
                const productGrid = document.getElementById('productGrid');
                if (page === 1) {
                    productGrid.innerHTML = '<div class="col-12 text-center py-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
                }

                fetch(`?ajax=get_products&page=${page}&search=${encodeURIComponent(search)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            showToast('Error loading products: ' + data.error, 'error');
                            productGrid.innerHTML = '<div class="col-12 text-center py-5"><p class="text-danger">Error loading products</p></div>';
                            isLoading = false;
                            return;
                        }

                        if (page === 1) {
                            productGrid.innerHTML = '';
                        }

                        if (data.products && data.products.length > 0) {
                            data.products.forEach(product => {
                                const isSelected = selectedProductIds.includes(product.id) ||
                                    modalSelectedProductIds.includes(product.id);

                                // Get the first image URL if available
                                const imageUrl = product.image_url || '/../assets/images/product/p-1.png';

                                // Get price display
                                const priceDisplay = product.min_price === product.max_price ?
                                    `$${product.min_price || '0'}` :
                                    `$${product.min_price || '0'} - $${product.max_price || '0'}`;

                                // Generate color swatches HTML (no names)
                                let colorsHtml = '';
                                if (product.colors && product.colors.length > 0) {
                                    // Function to generate color from color name
                                    const getColorCode = (colorName) => {
                                        const colorMap = {
                                            'red': '#dc3545',
                                            'blue': '#0d6efd',
                                            'green': '#198754',
                                            'yellow': '#ffc107',
                                            'black': '#212529',
                                            'white': '#ffffff',
                                            'gray': '#6c757d',
                                            'pink': '#e83e8c',
                                            'purple': '#6f42c1',
                                            'orange': '#fd7e14',
                                            'brown': '#795548',
                                            'navy': '#001f3f',
                                            'teal': '#20c997',
                                            'cyan': '#0dcaf0',
                                            'silver': '#c0c0c0',
                                            'gold': '#ffd700',
                                            'maroon': '#800000',
                                            'olive': '#808000',
                                            'lime': '#00ff00',
                                            'aqua': '#00ffff',
                                            'fuchsia': '#ff00ff'
                                        };

                                        const lowerColor = colorName.toLowerCase().trim();
                                        return colorMap[lowerColor] || '#6c757d';
                                    };

                                    product.colors.slice(0, 3).forEach(color => {
                                        const colorCode = getColorCode(color);
                                        colorsHtml += `<span class="color-swatch" style="background-color: ${colorCode};"></span>`;
                                    });

                                    if (product.colors.length > 3) {
                                        colorsHtml += `<span class="color-swatch-more">+${product.colors.length - 3}</span>`;
                                    }
                                }

                                // Generate sizes HTML
                                let sizesHtml = '';
                                if (product.sizes && product.sizes.length > 0) {
                                    product.sizes.slice(0, 5).forEach(size => {
                                        sizesHtml += `<span class="size-tag">${size}</span>`;
                                    });

                                    if (product.sizes.length > 5) {
                                        sizesHtml += `<span class="size-tag">+${product.sizes.length - 5}</span>`;
                                    }
                                }

                                productGrid.innerHTML += `
                                    <div class="col-md-4 col-lg-3 mb-3">
                                        <div class="card product-card ${isSelected ? 'selected' : ''}" 
                                             data-product-id="${product.id}"
                                             onclick="toggleProductSelection(${product.id}, this)">
                                            ${isSelected ? '<div class="modal-badge"><i class="bx bx-check"></i></div>' : ''}
                                            <img src="${imageUrl}" 
                                                 class="product-image product-thumb" alt="${product.title}">
                                            <div class="card-body bg-light-subtle rounded-bottom">
                                                <h6 class="product-name mb-1">${product.title}</h6>
                                                
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <p class="product-price text-dark mb-0">${priceDisplay}</p>
                                                    <small class="text-muted">#${product.id}</small>
                                                </div>
                                                
                                                <!-- Colors and Sizes -->
                                                <div class="product-details-row">
                                                    ${colorsHtml ? `<div class="mb-1">${colorsHtml}</div>` : ''}
                                                    ${sizesHtml ? `<div>${sizesHtml}</div>` : ''}
                                                </div>
                                                
                                                <!-- Sizes Info -->
                                                ${product.sizes && product.sizes.length > 0 ? 
                                                  `<small class="text-info d-block mt-1">${product.sizes.length} size${product.sizes.length > 1 ? 's' : ''} available</small>` : 
                                                  ''}
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });

                            if (data.hasMore) {
                                document.getElementById('productLoadMore').style.display = 'block';
                                currentPage = page;
                            } else {
                                document.getElementById('productLoadMore').style.display = 'none';
                            }
                        } else if (page === 1) {
                            productGrid.innerHTML = `
                                <div class="col-12 text-center py-5">
                                    <i class='bx bx-package fs-48 text-muted'></i>
                                    <h5 class="mt-3 text-muted">No products found</h5>
                                    <p class="text-muted">Try a different search term</p>
                                </div>
                            `;
                        }

                        isLoading = false;
                    })
                    .catch(error => {
                        console.error('Error loading products:', error);
                        productGrid.innerHTML = '<div class="col-12 text-center py-5"><p class="text-danger">Error loading products</p></div>';
                        isLoading = false;
                    });
            }

            window.loadMoreProducts = function() {
                loadProducts(currentPage + 1, searchTerm);
            }

            // Initialize modal when shown
            document.getElementById('productModal').addEventListener('show.bs.modal', function() {
                loadProducts(1, '');
            });

            // Clear modal selection when hidden
            document.getElementById('productModal').addEventListener('hidden.bs.modal', function() {
                modalSelectedProductIds = [];
            });

            // Search functionality
            let searchTimeout;
            document.getElementById('productSearch').addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTerm = e.target.value;
                searchTimeout = setTimeout(() => {
                    loadProducts(1, searchTerm);
                }, 500);
            });

        })();
    </script>

</body>

</html>