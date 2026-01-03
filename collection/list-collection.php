<?php
// list-collection.php
// Shows collections from DB in the table, supports delete via AJAX and inline edit-in-modal (loads add-collection.php form).
// Also includes "Show Products" button to display products of particular collection in modal.

// ----- EDIT THESE DB CREDENTIALS IF NEEDED -----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';
// ----------------------------------------------

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("DB connect error: " . $conn->connect_error);
}

$success = "";
$error = "";

// Helper: safe output
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Function to resolve product image URL
function resolve_product_image_url($stored)
{
    if (!$stored) return '/assets/images/product/p-1.png';
    $stored = trim($stored);

    if (preg_match('#^https?://#i', $stored)) {
        return $stored;
    }

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $scriptDirWeb = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    // Common image paths
    $candidates = [
        $docRoot . $stored,
        $docRoot . '/uploads/' . basename($stored),
        __DIR__ . $stored,
        __DIR__ . '/../uploads/' . basename($stored)
    ];

    foreach ($candidates as $fs) {
        if (file_exists($fs)) {
            $web = str_replace($docRoot, '', $fs);
            return $web !== '' ? $web : $stored;
        }
    }

    return $stored;
}

/**
 * Delete collection by ID (with products in product_collections)
 */
function delete_collection_by_id($conn, $id, &$out_error = null)
{
    $out_error = null;
    $id = (int)$id;
    if ($id <= 0) {
        $out_error = "Invalid collection id.";
        return false;
    }

    // Check if collection exists
    $stmt = $conn->prepare("SELECT id FROM collections WHERE id = ?");
    if (!$stmt) {
        $out_error = "Database error (prepare).";
        return false;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $out_error = "Collection not found.";
        return false;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Delete from product_collections first (foreign key constraint)
        $del_products = $conn->prepare("DELETE FROM product_collections WHERE collection_id = ?");
        if (!$del_products) {
            throw new Exception("Database error (prepare delete products).");
        }
        $del_products->bind_param('i', $id);
        if (!$del_products->execute()) {
            throw new Exception("Failed to delete collection products.");
        }
        $del_products->close();

        // Delete collection
        $del = $conn->prepare("DELETE FROM collections WHERE id = ?");
        if (!$del) {
            throw new Exception("Database error (prepare delete).");
        }
        $del->bind_param('i', $id);
        if (!$del->execute()) {
            throw new Exception("Failed to delete collection.");
        }
        $del->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        $out_error = $e->getMessage();
        return false;
    }
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete') {
        // Expect id in POST
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $out_err = null;
        $ok = delete_collection_by_id($conn, $id, $out_err);
        header('Content-Type: application/json; charset=utf-8');
        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Collection deleted successfully.', 'id' => $id]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => $out_err ?: 'Unknown error']);
            exit;
        }
    }

    // Get products for collection modal
    if ($_POST['action'] === 'get_collection_products') {
        $collection_id = isset($_POST['collection_id']) ? (int)$_POST['collection_id'] : 0;

        if ($collection_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid collection ID']);
            exit;
        }

        // Get collection info
        $stmt = $conn->prepare("SELECT id, title, description FROM collections WHERE id = ?");
        $stmt->bind_param('i', $collection_id);
        $stmt->execute();
        $collection_result = $stmt->get_result();
        $collection = $collection_result->fetch_assoc();
        $stmt->close();

        if (!$collection) {
            echo json_encode(['success' => false, 'error' => 'Collection not found']);
            exit;
        }

        // Get products for this collection
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
            WHERE p.id IN (
                SELECT product_id 
                FROM product_collections 
                WHERE collection_id = ?
            )
            GROUP BY p.id
            ORDER BY p.id DESC
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $collection_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $products = [];
            while ($row = $result->fetch_assoc()) {
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

                $products[] = $row;
            }
            $stmt->close();

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'collection' => $collection,
                'products' => $products,
                'count' => count($products)
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit;
    }
}

// Handle fallback GET delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $out_err = null;
    $ok = delete_collection_by_id($conn, $id, $out_err);
    if ($ok) {
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?deleted=1");
        exit;
    } else {
        $q = urlencode($out_err ?: 'Unknown error');
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . "?delete_error={$q}");
        exit;
    }
}

// JSON endpoint to fetch single collection (used to refresh UI after edit)
if (isset($_GET['action']) && $_GET['action'] === 'get_collection_json' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT id, title, description FROM collections WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        // Get product count
        $count_stmt = $conn->prepare("SELECT COUNT(*) as product_count FROM product_collections WHERE collection_id = ?");
        $count_stmt->bind_param('i', $id);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $count_stmt->close();

        if ($row) {
            $row['product_count'] = $count_row['product_count'];
        }

        header('Content-Type: application/json; charset=utf-8');
        if ($row) {
            echo json_encode(['success' => true, 'collection' => $row]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Collection not found.']);
        }
        exit;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'DB error']);
        exit;
    }
}

// AJAX endpoint for pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate' && isset($_GET['page'])) {
    $page = (int)$_GET['page'];
    $perPage = 10; // Number of items per page
    $offset = ($page - 1) * $perPage;

    // Get total count
    $countResult = $conn->query("SELECT COUNT(*) as total FROM collections");
    $totalRows = $countResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $perPage);

    // Get paginated data with product counts
    $sql = "
          SELECT c.id, c.title, c.description, COUNT(pc.product_id) as product_count 
          FROM collections c 
          LEFT JOIN product_collections pc ON c.id = pc.collection_id 
          GROUP BY c.id 
          ORDER BY c.id DESC 
          LIMIT $offset, $perPage
     ";

    $result = $conn->query($sql);

    $rows = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
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

/* =========================
   Normal page: fetch collections
   ========================= */

// Pagination variables
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$perPage = 10; // Items per page

// Get total count for pagination
$countResult = $conn->query("SELECT COUNT(*) as total FROM collections");
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

// Calculate offset
$offset = ($currentPage - 1) * $perPage;

// Fetch paginated collections with product counts
$sql = "
     SELECT c.id, c.title, c.description, COUNT(pc.product_id) as product_count 
     FROM collections c 
     LEFT JOIN product_collections pc ON c.id = pc.collection_id 
     GROUP BY c.id 
     ORDER BY c.id DESC 
     LIMIT $offset, $perPage
";
$result = $conn->query($sql);

// Build flash messages from GET params
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $flashSuccess = "Collection deleted successfully.";
}
if (isset($_GET['delete_error'])) {
    $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Collections List</title>

    <!-- App favicon -->
    <link rel="shortcut icon" href="/assets/images/favicon.ico">

    <!-- Vendor css (Require in all Page) -->
    <link href="/assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

    <!-- Icons css (Require in all Page) -->
    <link href="/assets/css/icons.min.css" rel="stylesheet" type="text/css" />

    <!-- App css (Require in all Page) -->
    <link href="/assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <!-- Toastify CSS (for toasts) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <!-- Theme Config js (Require in all Page) -->
    <script src="/assets/js/config.js"></script>

    <style>
        /* avatar sizing and empty-avatar style */
        .avatar-md {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 6px;
        }

        .empty-avatar {
            width: 56px;
            height: 56px;
            border-radius: 6px;
            background: #f5f6f8;
            display: inline-block;
        }

        .collection-name {
            margin-bottom: 4px;
        }

        /* only non-invasive small transition for smooth swap - does not change original styling */
        .avatar-xl,
        .card .card-body h4 {
            transition: opacity 0.4s ease;
        }

        /* optional small tweak so toast doesn't overlap with any fixed header */
        .toastify {
            z-index: 20000;
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
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            margin-right: 3px;
            margin-bottom: 3px;
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

        /* Product card in modal */
        .product-modal-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            height: 100%;
        }

        .product-modal-thumb {
            height: 150px;
            object-fit: cover;
            width: 100%;
        }

        /* Modal scrollable content */
        .modal-body-scrollable {
            max-height: 70vh;
            overflow-y: auto;
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

        /* Badge for product count */
        .product-count-badge {
            background: #0d6efd;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-left: 5px;
        }

        .title-hover:hover {
            color: #28a745 !important;
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
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center gap-1">
                                <h4 class="card-title flex-grow-1">All Collections List</h4>

                                <a href="add-collection.php" class="btn btn-sm btn-primary">
                                    Add Collection
                                </a>

                                <div class="dropdown">
                                    <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light" data-bs-toggle="dropdown" aria-expanded="false">
                                        This Month
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a href="#!" class="dropdown-item">Download</a>
                                        <a href="#!" class="dropdown-item">Export</a>
                                        <a href="#!" class="dropdown-item">Import</a>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="table-responsive" id="collectionsTableContainer">
                                    <table class="table align-middle mb-0 table-hover table-centered">
                                        <thead class="bg-light-subtle">
                                            <tr>
                                                <th style="width: 20px;">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="customCheck1">
                                                        <label class="form-check-label" for="customCheck1"></label>
                                                    </div>
                                                </th>
                                                <th>Collection Name</th>
                                                <th>Description</th>
                                                <th>Products</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="collectionsTableBody">
                                            <?php if ($result && $result->num_rows > 0): ?>
                                                <?php while ($row = $result->fetch_assoc()): ?>
                                                    <?php
                                                    $displayId = $row['id'];
                                                    $title = $row['title'] ?: '-';
                                                    $description = $row['description'] ? substr($row['description'], 0, 50) . (strlen($row['description']) > 50 ? '...' : '') : '-';
                                                    $product_count = $row['product_count'] ?? 0;
                                                    ?>
                                                    <tr id="row-<?php echo e($row['id']); ?>">
                                                        <td>
                                                            <div class="form-check">
                                                                <input type="checkbox" class="form-check-input" id="check_<?php echo e($row['id']); ?>">
                                                                <label class="form-check-label" for="check_<?php echo e($row['id']); ?>"></label>
                                                            </div>
                                                        </td>

                                                        <td>
                                                            <p id="name-<?php echo e($row['id']); ?>" class="text-dark fw-medium fs-15 mb-1 collection-name"><?php echo e($title); ?></p>
                                                        </td>
                                                        <td>
                                                            <p id="desc-<?php echo e($row['id']); ?>" class="text-muted mb-0"><?php echo e($description); ?></p>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo e($product_count); ?> products</span>
                                                        </td>

                                                        <td>
                                                            <div class="d-flex gap-2">
                                                                <!-- Show Products Button -->
                                                                <button class="btn btn-light btn-sm btn-show-products"
                                                                    data-id="<?php echo e($row['id']); ?>"
                                                                    data-title="<?php echo e($title); ?>"
                                                                    title="Show Products">
                                                                    <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                                                </button>

                                                                <!-- Edit Collection Button -->
                                                                <a href="add-collection.php?id=<?php echo e($row['id']); ?>" class="btn btn-soft-primary btn-sm" title="Edit">
                                                                    <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                </a>

                                                                <!-- Delete Collection Button -->
                                                                <a href="?action=delete&id=<?php echo e($row['id']); ?>"
                                                                    class="btn btn-soft-danger btn-sm btn-delete"
                                                                    data-id="<?php echo e($row['id']); ?>"
                                                                    title="Delete">
                                                                    <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No collections found.</td>
                                                </tr>
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
                                    Loading collections...
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

                                            // Show page numbers
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
                                            <!-- Single page fallback -->
                                            <li class="page-item active"><a class="page-link" href="javascript:void(0);">1</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>

                        </div>
                    </div>
                </div>

            </div> <!-- container -->
        </div> <!-- page-content -->

        <!-- Show Products Modal -->
        <div class="modal fade" id="showProductsModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="showProductsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="showProductsModalLabel">Collection Products</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body modal-body-scrollable" id="showProductsModalBody">
                        <!-- Products will be loaded here via AJAX -->
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3">Loading products...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal (content loaded via AJAX from add-collection.php and inserted) -->
        <div class="modal fade" id="editCollectionModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editCollectionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editCollectionModalLabel">Edit Collection</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="editModalBody">
                        <!-- AJAX-loaded form will be inserted here -->
                        <div class="text-center py-4" id="edit-loading-placeholder">Loading...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteConfirmModalLabel">Delete Collection</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this collection? This action cannot be undone.</p>
                        <p class="text-danger"><strong>Warning:</strong> This will also remove all product associations with this collection.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <!-- Confirm button triggers AJAX deletion -->
                        <button type="button" id="confirmDeleteBtn" class="btn btn-danger">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
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

    </div> <!-- wrapper -->

    <!-- Vendor Javascript (Require in all Page) -->
    <script src="/assets/js/vendor.js"></script>

    <!-- App Javascript (Require in all Page) -->
    <script src="/assets/js/app.js"></script>

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
                            "linear-gradient(to right, #dc3545, #b02a37)" : "linear-gradient(to right, #4CAF7C, #4CAF7C)",
                        color: "#fff"
                    }
                }).showToast();
            }

            // Show server-side messages (from fallback redirect params)
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
            var paginationLoading = document.getElementById('paginationLoading');
            var collectionsTableBody = document.getElementById('collectionsTableBody');
            var pagination = document.getElementById('pagination');

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
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success && data.rows) {
                            // Update table body
                            collectionsTableBody.innerHTML = '';

                            if (data.rows.length > 0) {
                                data.rows.forEach(function(row) {
                                    var title = row.title || '-';
                                    var description = row.description ? (row.description.length > 50 ? row.description.substring(0, 50) + '...' : row.description) : '-';
                                    var product_count = row.product_count || 0;
                                    var displayId = row.id;

                                    var rowHtml = `
                                        <tr id="row-${row.id}">
                                             <td>
                                                  <div class="form-check">
                                                       <input type="checkbox" class="form-check-input" id="check_${row.id}">
                                                       <label class="form-check-label" for="check_${row.id}"></label>
                                                  </div>
                                             </td>
                                             <td>
                                                  <p id="name-${row.id}" class="text-dark fw-medium fs-15 mb-1 collection-name">${title}</p>
                                             </td>
                                             <td>
                                                  <p id="desc-${row.id}" class="text-muted mb-0">${description}</p>
                                             </td>
                                             <td>
                                                  <span class="badge bg-primary">${product_count} products</span>
                                             </td>
                                             <td>${displayId}</td>
                                             <td>
                                                  <div class="d-flex gap-2">
                                                       <button class="btn btn-info btn-sm btn-show-products" 
                                                               data-id="${row.id}"
                                                               data-title="${title}"
                                                               title="Show Products">
                                                            <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                                       </button>
                                                       <a href="add-collection.php?id=${row.id}" class="btn btn-soft-primary btn-sm btn-edit" data-id="${row.id}" title="Edit">
                                                            <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
                                                       </a>
                                                       <a href="?action=delete&id=${row.id}" class="btn btn-soft-danger btn-sm btn-delete" data-id="${row.id}" title="Delete">
                                                            <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                       </a>
                                                  </div>
                                             </td>
                                        </tr>
                                        `;
                                    collectionsTableBody.innerHTML += rowHtml;
                                });
                            } else {
                                collectionsTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No collections found.</td></tr>';
                            }

                            // Update pagination
                            updatePagination(data.currentPage, data.totalPages);
                        }
                    })
                    .catch(function(error) {
                        console.error('Error loading page:', error);
                        showToast('Error loading page. Please try again.', 'error');
                    })
                    .finally(function() {
                        // Hide loading indicator
                        if (paginationLoading) paginationLoading.classList.remove('active');
                    });
            }

            function updatePagination(currentPage, totalPages) {
                if (!pagination || totalPages <= 1) return;

                var html = '';

                // Previous button
                html += `<li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                         <a class="page-link" href="?page=${currentPage - 1}" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''}>
                              Previous
                         </a>
                    </li>`;

                // Calculate page range
                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(totalPages, startPage + 4);

                if (endPage - startPage < 4 && startPage > 1) {
                    startPage = Math.max(1, endPage - 4);
                }

                // Page numbers
                for (var i = startPage; i <= endPage; i++) {
                    var activeClass = i == currentPage ? 'active' : '';
                    html += `<li class="page-item ${activeClass}">
                              <a class="page-link" href="?page=${i}" data-page="${i}">
                                   ${i}
                              </a>
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

            // Handle pagination clicks
            if (pagination) {
                pagination.addEventListener('click', function(e) {
                    var link = e.target.closest('.page-link');
                    if (!link) return;

                    e.preventDefault();

                    var page = link.getAttribute('data-page');
                    if (!page) {
                        page = parseInt(new URL(link.href).searchParams.get('page')) || 1;
                    }

                    loadPage(parseInt(page));
                });
            }

            // Handle browser back/forward buttons
            window.addEventListener('popstate', function() {
                var urlParams = new URLSearchParams(window.location.search);
                var page = parseInt(urlParams.get('page')) || 1;
                if (page !== currentPage) {
                    loadPage(page);
                }
            });

            /* -------------------
               Show Products Modal
               ------------------- */
            var showProductsModalEl = document.getElementById('showProductsModal');
            var bsShowProductsModal = null;
            var showProductsModalBody = document.getElementById('showProductsModalBody');

            // Use event delegation for show products buttons
            document.addEventListener('click', function(e) {
                var showBtn = e.target.closest('.btn-show-products');
                if (!showBtn) return;

                var collectionId = showBtn.getAttribute('data-id');
                var collectionTitle = showBtn.getAttribute('data-title');

                if (!collectionId) return;

                // Update modal title
                document.getElementById('showProductsModalLabel').textContent = 'Products in: ' + collectionTitle;

                // Show loading indicator
                showProductsModalBody.innerHTML = `
                         <div class="text-center py-5">
                              <div class="spinner-border text-primary" role="status">
                                   <span class="visually-hidden">Loading...</span>
                              </div>
                              <p class="mt-3">Loading products...</p>
                         </div>
                    `;

                // Show modal
                bsShowProductsModal = bootstrap.Modal.getOrCreateInstance(showProductsModalEl);
                bsShowProductsModal.show();

                // Fetch products for this collection
                var formData = new FormData();
                formData.append('action', 'get_collection_products');
                formData.append('collection_id', collectionId);

                fetch(window.location.pathname, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success && data.products) {
                            // Generate color mapping
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

                            const getColorCode = (colorName) => {
                                const lowerColor = colorName.toLowerCase().trim();
                                return colorMap[lowerColor] || '#6c757d';
                            };

                            var productsHtml = '';

                            if (data.products.length > 0) {
                                // Collection info
                                productsHtml += `
                                        <div class="alert alert-info mb-4">
                                             <h6 class="mb-1">${data.collection.title || 'Collection'}</h6>
                                             <p class="mb-0">${data.collection.description || 'No description'}</p>
                                             <p class="mb-0 mt-2"><strong>Total Products:</strong> ${data.count}</p>
                                        </div>
                                   `;

                                productsHtml += '<div class="row">';

                                data.products.forEach(function(product) {
                                    // Get price display
                                    const priceDisplay = product.min_price === product.max_price ?
                                        `$${product.min_price || '0'}` :
                                        `$${product.min_price || '0'} - $${product.max_price || '0'}`;

                                    // Generate color swatches
                                    let colorsHtml = '';
                                    if (product.colors && product.colors.length > 0) {
                                        product.colors.slice(0, 3).forEach(color => {
                                            const colorCode = getColorCode(color);
                                            colorsHtml += `<span class="color-swatch" style="background-color: ${colorCode};"></span>`;
                                        });

                                        if (product.colors.length > 3) {
                                            colorsHtml += `<span class="color-swatch-more">+${product.colors.length - 3}</span>`;
                                        }
                                    }

                                    // Generate sizes
                                    let sizesHtml = '';
                                    if (product.sizes && product.sizes.length > 0) {
                                        product.sizes.slice(0, 5).forEach(size => {
                                            sizesHtml += `<span class="size-tag">${size}</span>`;
                                        });

                                        if (product.sizes.length > 5) {
                                            sizesHtml += `<span class="size-tag">+${product.sizes.length - 5}</span>`;
                                        }
                                    }

                                    productsHtml += `
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <div class="product-modal-card">
                                                <img src="${product.image_url || '/assets/images/product/p-1.png'}" 
                                                        class="product-modal-thumb" alt="${product.title}">
                                                <div class="p-3">
                                                        <a href="../product/details-product.php?id=${product.id}" class="product-title-link fw-medium fs-15 d-block mb-1 text-dark title-hover" target="_blank">
                                                            ${product.title}
                                                        </a>
                                                        <p class="text-muted mb-1">${priceDisplay}</p>
                                                        
                                                        <!-- Colors and Sizes -->
                                                        <div class="product-info">
                                                            ${colorsHtml ? `<div class="mb-1">${colorsHtml}</div>` : ''}
                                                            ${sizesHtml ? `<div>${sizesHtml}</div>` : ''}
                                                        </div>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                });

                                productsHtml += '</div>';
                            } else {
                                productsHtml = `
                                        <div class="text-center py-5">
                                             <i class='bx bx-package fs-48 text-muted'></i>
                                             <h5 class="mt-3 text-muted">No products in this collection</h5>
                                             <p class="text-muted">Add products to this collection from the edit page</p>
                                        </div>
                                   `;
                            }

                            showProductsModalBody.innerHTML = productsHtml;
                        } else {
                            showProductsModalBody.innerHTML = `
                                   <div class="text-center py-5">
                                        <div class="alert alert-danger">
                                             <p class="mb-0">${data.error || 'Error loading products'}</p>
                                        </div>
                                   </div>
                              `;
                        }
                    })
                    .catch(function(error) {
                        console.error('Error loading products:', error);
                        showProductsModalBody.innerHTML = `
                              <div class="text-center py-5">
                                   <div class="alert alert-danger">
                                        <p class="mb-0">Error loading products. Please try again.</p>
                                   </div>
                              </div>
                         `;
                    });
            });

            /* -------------------
               Delete modal + AJAX
               ------------------- */
            var deleteModalEl = document.getElementById('deleteConfirmModal');
            var bsDeleteModal = null;

            // Use event delegation for delete buttons
            document.addEventListener('click', function(e) {
                var delBtn = e.target.closest('.btn-delete');
                if (!delBtn) return;
                e.preventDefault();
                var deleteId = delBtn.getAttribute('data-id');
                if (!deleteId) return;

                deleteModalEl.dataset.deleteId = deleteId;
                bsDeleteModal = bootstrap.Modal.getOrCreateInstance(deleteModalEl);
                bsDeleteModal.show();
            });

            var confirmBtn = document.getElementById('confirmDeleteBtn');
            var origConfirmHtml = confirmBtn ? confirmBtn.innerHTML : 'Delete';
            confirmBtn && confirmBtn.addEventListener('click', function() {
                var id = deleteModalEl.dataset.deleteId;
                if (!id) {
                    if (bsDeleteModal) bsDeleteModal.hide();
                    return;
                }
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Deleting...';

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
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origConfirmHtml;
                    if (bsDeleteModal) bsDeleteModal.hide();
                    if (json && json.success) {
                        var row = document.getElementById('row-' + id);
                        if (row) {
                            row.style.transition = 'opacity 0.3s';
                            row.style.opacity = '0';
                            setTimeout(function() {
                                row.remove();
                            }, 300);
                        }
                        showToast(json.message || 'Collection deleted successfully.', 'success');

                        // Reload current page to update pagination if needed
                        loadPage(currentPage);
                    } else {
                        showToast((json && json.error) ? json.error : 'Delete failed', 'error');
                    }
                }).catch(function(err) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origConfirmHtml;
                    if (bsDeleteModal) bsDeleteModal.hide();
                    showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                });
            });

            /* -----------------------------
               Edit in modal (AJAX load + submit)
               ----------------------------- */

            var editModalEl = document.getElementById('editCollectionModal');
            var bsEditModal = bootstrap.Modal.getOrCreateInstance(editModalEl);
            var editModalBody = document.getElementById('editModalBody');

            // Use event delegation for edit buttons
            document.addEventListener('click', function(e) {
                var editBtn = e.target.closest('.btn-edit');
                if (!editBtn) return;
                e.preventDefault();

                var href = editBtn.getAttribute('href') || editBtn.dataset.href;
                if (!href) return;
                editModalBody.innerHTML = '<div class="text-center py-4">Loading...</div>';
                bsEditModal.show();

                fetch(href, {
                    credentials: 'same-origin'
                }).then(function(resp) {
                    return resp.text();
                }).then(function(html) {
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var form = doc.querySelector('#collectionForm');
                    if (!form) {
                        editModalBody.innerHTML = '<div class="p-3">Could not load edit form. Try opening in a new tab.<br><a class="btn btn-sm btn-primary mt-2" href="' + href + '" target="_blank">Open</a></div>';
                        return;
                    }
                    // Insert form HTML into modal
                    editModalBody.innerHTML = form.outerHTML;

                    // Wire up the form submit
                    var modalForm = editModalBody.querySelector('#collectionForm');
                    if (!modalForm) return;

                    // Handle save button
                    var modalSaveBtn = modalForm.querySelector('#saveBtn');
                    if (modalSaveBtn) {
                        modalSaveBtn.addEventListener('click', function(ev) {
                            ev.preventDefault();
                            var title = modalForm.querySelector('#collection-title');
                            if (!title || title.value.trim() === '') {
                                alert('Collection Title is required.');
                                title.focus();
                                return;
                            }

                            // Submit the form
                            var fd = new FormData(modalForm);
                            fetch(modalForm.getAttribute('action') || 'add-collection.php', {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: fd,
                                credentials: 'same-origin'
                            }).then(function(r) {
                                return r.json();
                            }).then(function(json) {
                                if (json && json.success) {
                                    bsEditModal.hide();
                                    showToast(json.success, 'success');

                                    // Refresh the row data
                                    var catId = json.id || fd.get('id');
                                    if (catId) {
                                        fetch(window.location.pathname + '?action=get_collection_json&id=' + encodeURIComponent(catId), {
                                                credentials: 'same-origin'
                                            })
                                            .then(function(r) {
                                                return r.json();
                                            })
                                            .then(function(j) {
                                                if (j && j.success && j.collection) {
                                                    var col = j.collection;
                                                    var nameEl = document.getElementById('name-' + col.id);
                                                    if (nameEl) nameEl.textContent = col.title || '-';
                                                    var descEl = document.getElementById('desc-' + col.id);
                                                    if (descEl) {
                                                        var desc = col.description ? (col.description.length > 50 ? col.description.substring(0, 50) + '...' : col.description) : '-';
                                                        descEl.textContent = desc;
                                                    }
                                                }
                                            }).catch(function() {});
                                    }
                                } else {
                                    showToast((json && json.error) ? json.error : 'Save failed', 'error');
                                }
                            }).catch(function(err) {
                                showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                            });
                        });
                    }
                }).catch(function(err) {
                    editModalBody.innerHTML = '<div class="p-3 text-danger">Failed to load form: ' + (err && err.message ? err.message : 'network error') + '</div>';
                });
            });

            // When modal hidden, clear body
            editModalEl.addEventListener('hidden.bs.modal', function() {
                editModalBody.innerHTML = '<div class="text-center py-4">Loading...</div>';
            });

        })();
    </script>

</body>

</html>
<?php
if (isset($result) && $result) $result->free();
$conn->close();
?>