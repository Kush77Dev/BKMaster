<?php
// list-orders.php
// Shows orders from DB with user details, addresses, and items.

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

// Helper: get status badge class
function getStatusBadgeClass($status)
{
    $classes = [
        'pending' => 'border border-secondary text-secondary',
        'processing' => 'border border-warning text-warning',
        'shipped' => 'border border-info text-info',
        'delivered' => 'border border-success text-success',
        'cancelled' => 'border border-danger text-danger',
        'draft' => 'border border-secondary text-secondary',
        'packaging' => 'border border-warning text-warning'
    ];
    
    return $classes[strtolower($status)] ?? 'border border-secondary text-secondary';
}

// Helper: get payment status badge class
function getPaymentStatusBadgeClass($status)
{
    $classes = [
        'paid' => 'bg-success text-light',
        'unpaid' => 'bg-light text-dark',
        'partial' => 'bg-warning text-dark',
        'refunded' => 'bg-info text-light',
        'failed' => 'bg-danger text-light'
    ];
    
    return $classes[strtolower($status)] ?? 'bg-light text-dark';
}

// Helper: format currency
function formatCurrency($amount)
{
    if ($amount === null) return '₹0.00';
    return '₹' . number_format($amount, 2);
}

// Helper: get initials from name
function getInitials($firstName, $lastName)
{
    $initials = '';
    if (!empty($firstName)) {
        $initials .= strtoupper(substr($firstName, 0, 1));
    }
    if (!empty($lastName)) {
        $initials .= strtoupper(substr($lastName, 0, 1));
    }
    if (empty($initials) && !empty($firstName)) {
        $initials = strtoupper(substr($firstName, 0, 1));
    }
    if (empty($initials) && !empty($lastName)) {
        $initials = strtoupper(substr($lastName, 0, 1));
    }
    if (empty($initials)) {
        $initials = 'U'; // Default for unknown
    }
    return $initials;
}

// Helper: get color based on name
function getColorFromName($name)
{
    $colors = [
        '#4A6FA5', '#166088', '#2D3047', '#419D78',
        '#E0A458', '#C04ABC', '#DB5461', '#686963',
        '#3D5A80', '#98C1D9', '#EE6C4D', '#293241',
        '#6B2737', '#E43F6F', '#1F2041', '#FFC857'
    ];
    
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $hash = ord($name[$i]) + (($hash << 5) - $hash);
    }
    
    $index = abs($hash) % count($colors);
    return $colors[$index];
}

/* =========================
   Deletion logic
   ========================= */

function delete_order_by_id($conn, $id, &$out_error = null)
{
     $out_error = null;
     $id = (int)$id;
     if ($id <= 0) {
          $out_error = "Invalid order id.";
          return false;
     }

     // Check if order exists
     $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ?");
     if (!$stmt) {
          $out_error = "Database error (prepare).";
          return false;
     }
     $stmt->bind_param('i', $id);
     $stmt->execute();
     $stmt->store_result();
     $found = $stmt->num_rows > 0;
     $stmt->close();

     if (!$found) {
          $out_error = "Order not found.";
          return false;
     }

     // Start transaction
     $conn->begin_transaction();
     
     try {
          // Delete order items first
          $delItems = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
          if (!$delItems) {
               throw new Exception("Database error (prepare delete items).");
          }
          $delItems->bind_param('i', $id);
          if (!$delItems->execute()) {
               throw new Exception("Failed to delete order items.");
          }
          $delItems->close();
          
          // Delete order
          $del = $conn->prepare("DELETE FROM orders WHERE id = ?");
          if (!$del) {
               throw new Exception("Database error (prepare delete order).");
          }
          $del->bind_param('i', $id);
          if (!$del->execute()) {
               throw new Exception("Failed to delete order.");
          }
          $del->close();
          
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
     $ok = delete_order_by_id($conn, $id, $out_err);
     header('Content-Type: application/json; charset=utf-8');
     if ($ok) {
          echo json_encode(['success' => true, 'message' => 'Order deleted successfully.', 'id' => $id]);
          exit;
     } else {
          echo json_encode(['success' => false, 'error' => $out_err ?: 'Unknown error']);
          exit;
     }
}

// Handle fallback GET delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
     $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
     $out_err = null;
     $ok = delete_order_by_id($conn, $id, $out_err);
     if ($ok) {
          header("Location: list-orders.php?deleted=1");
          exit;
     } else {
          $q = urlencode($out_err ?: 'Unknown error');
          header("Location: list-orders.php?delete_error={$q}");
          exit;
     }
}

// AJAX endpoint for pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate' && isset($_GET['page'])) {
     $page = (int)$_GET['page'];
     $perPage = 10; // Number of items per page
     $offset = ($page - 1) * $perPage;
     
     // Get total count
     $countResult = $conn->query("SELECT COUNT(*) as total FROM orders");
     $totalRows = $countResult->fetch_assoc()['total'];
     $totalPages = ceil($totalRows / $perPage);
     
     // Get paginated orders with user details
     $sql = "SELECT o.*, 
                    u.first_name, u.last_name, u.email,
                    sa.line1 as shipping_line1, sa.city as shipping_city, sa.state as shipping_state,
                    ba.line1 as billing_line1, ba.city as billing_city, ba.state as billing_state
             FROM orders o
             LEFT JOIN users u ON o.user_id = u.id
             LEFT JOIN addresses sa ON o.shipping_address_id = sa.id
             LEFT JOIN addresses ba ON o.billing_address_id = ba.id
             ORDER BY o.created_at DESC LIMIT $offset, $perPage";
     
     $result = $conn->query($sql);
     
     $rows = [];
     if ($result && $result->num_rows > 0) {
          while ($row = $result->fetch_assoc()) {
               // Get order items count
               $orderId = $row['id'];
               $itemCountResult = $conn->query("SELECT COUNT(*) as item_count FROM order_items WHERE order_id = $orderId");
               $itemCount = $itemCountResult->fetch_assoc()['item_count'];
               
               $row['item_count'] = $itemCount;
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

// Get order statistics for cards
$stats = [
    'payment_refund' => 0,
    'order_cancel' => 0,
    'order_shipped' => 0,
    'order_delivering' => 0,
    'pending_review' => 0,
    'pending_payment' => 0,
    'delivered' => 0,
    'in_progress' => 0
];

// Query for statistics
$statQueries = [
    'payment_refund' => "SELECT COUNT(*) as count FROM orders WHERE payment_status = 'refunded'",
    'order_cancel' => "SELECT COUNT(*) as count FROM orders WHERE status = 'cancelled'",
    'order_shipped' => "SELECT COUNT(*) as count FROM orders WHERE status = 'shipped'",
    'order_delivering' => "SELECT COUNT(*) as count FROM orders WHERE status = 'processing'",
    'pending_review' => "SELECT COUNT(*) as count FROM orders WHERE status = 'pending'",
    'pending_payment' => "SELECT COUNT(*) as count FROM orders WHERE payment_status = 'unpaid'",
    'delivered' => "SELECT COUNT(*) as count FROM orders WHERE status = 'delivered'",
    'in_progress' => "SELECT COUNT(*) as count FROM orders WHERE status IN ('processing', 'packaging')"
];

foreach ($statQueries as $key => $query) {
    $result = $conn->query($query);
    if ($result) {
        $stats[$key] = $result->fetch_assoc()['count'];
    }
}

/* =========================
   Normal page: fetch orders
   ========================= */

// Pagination variables
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$perPage = 10; // Items per page

// Get total count for pagination
$countResult = $conn->query("SELECT COUNT(*) as total FROM orders");
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

// Calculate offset
$offset = ($currentPage - 1) * $perPage;

// Fetch paginated orders with user details
$sql = "SELECT o.*, 
               u.first_name, u.last_name, u.email,
               sa.line1 as shipping_line1, sa.city as shipping_city, sa.state as shipping_state,
               ba.line1 as billing_line1, ba.city as billing_city, ba.state as billing_state
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN addresses sa ON o.shipping_address_id = sa.id
        LEFT JOIN addresses ba ON o.billing_address_id = ba.id
        ORDER BY o.created_at DESC LIMIT $offset, $perPage";

$result = $conn->query($sql);

// Build flash messages
$flashSuccess = "";
$flashError = "";
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
     $flashSuccess = "Order deleted successfully.";
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
    <title>Orders List | Larkon - Responsive Admin Dashboard Template</title>

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
        /* Initials Avatar Styles */
        .initials-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            margin-right: 8px;
        }
        
        .customer-cell {
            display: flex;
            align-items: center;
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

                         <div class="row">
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Payment Refund</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['payment_refund']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:chat-round-money-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Order Cancel</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['order_cancel']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:cart-cross-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>

                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Order Shipped</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['order_shipped']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:box-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>

                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Order Delivering</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['order_delivering']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:tram-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>

                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Pending Review</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['pending_review']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:clipboard-remove-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Pending Payment</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['pending_payment']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:clock-circle-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">Delivered</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['delivered']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:clipboard-check-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                   <div class="card">
                                        <div class="card-body">
                                             <div class="d-flex align-items-center justify-content-between">
                                                  <div>
                                                       <h4 class="card-title mb-2">In Progress</h4>
                                                       <p class="text-muted fw-medium fs-22 mb-0"><?php echo $stats['in_progress']; ?></p>
                                                  </div>
                                                  <div>
                                                       <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                            <iconify-icon icon="solar:inbox-line-broken" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                         </div>

                         <div class="row">
                              <div class="col-xl-12">
                                   <div class="card">
                                        <div class="d-flex card-header justify-content-between align-items-center">
                                             <div>
                                                  <h4 class="card-title">All Order List</h4>
                                             </div>
                                             <div class="dropdown">
                                                  <a href="#" class="dropdown-toggle btn btn-sm btn-outline-light rounded" data-bs-toggle="dropdown" aria-expanded="false">
                                                       This Month
                                                  </a>
                                                  <div class="dropdown-menu dropdown-menu-end">
                                                       <!-- item-->
                                                       <a href="#!" class="dropdown-item">Download</a>
                                                       <!-- item-->
                                                       <a href="#!" class="dropdown-item">Export</a>
                                                       <!-- item-->
                                                       <a href="#!" class="dropdown-item">Import</a>
                                                  </div>
                                             </div>
                                        </div>
                                        <div class="card-body p-0">
                                             <div class="table-responsive" id="ordersTableContainer">
                                                  <table class="table align-middle mb-0 table-hover table-centered">
                                                       <thead class="bg-light-subtle">
                                                            <tr>
                                                                 <th>Order ID</th>
                                                                 <th>Created at</th>
                                                                 <th>Customer</th>
                                                                 <th>Total</th>
                                                                 <th>Payment Status</th>
                                                                 <th>Items</th>
                                                                 <th>Order Status</th>
                                                                 <th>Action</th>
                                                            </tr>
                                                       </thead>
                                                       <tbody id="ordersTableBody">
                                                            <?php if ($result && $result->num_rows > 0): ?>
                                                                 <?php while ($order = $result->fetch_assoc()): ?>
                                                                      <?php
                                                                      // Get order items count
                                                                      $orderId = $order['id'];
                                                                      $itemCountResult = $conn->query("SELECT COUNT(*) as item_count FROM order_items WHERE order_id = $orderId");
                                                                      $itemCount = $itemCountResult ? $itemCountResult->fetch_assoc()['item_count'] : 0;
                                                                      
                                                                      // Format customer name
                                                                      $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                                                                      if (empty($customerName)) {
                                                                           $customerName = 'Unknown Customer';
                                                                      }
                                                                      
                                                                      // Get initials and color
                                                                      $initials = getInitials($order['first_name'] ?? '', $order['last_name'] ?? '');
                                                                      $color = getColorFromName($customerName);
                                                                      
                                                                      // Format dates
                                                                      $createdDate = date('M d, Y', strtotime($order['created_at']));
                                                                      
                                                                      // Format order number (use order_number if exists, otherwise generate)
                                                                      $orderNumber = $order['order_number'] ?: 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
                                                                      
                                                                      // Get status badges
                                                                      $statusBadgeClass = getStatusBadgeClass($order['status']);
                                                                      $paymentStatusBadgeClass = getPaymentStatusBadgeClass($order['payment_status']);
                                                                      
                                                                      // Format total amount
                                                                      $totalAmount = formatCurrency($order['total_amount']);
                                                                      ?>
                                                                      <tr id="row-<?php echo e($orderId); ?>">
                                                                           <td>
                                                                                <a href="detail-order.php?id=<?php echo e($orderId); ?>" class="link-primary fw-medium">#<?php echo e($orderNumber); ?></a>
                                                                           </td>
                                                                           <td><?php echo e($createdDate); ?></td>
                                                                           <td>
                                                                                <div class="customer-cell">
                                                                                     <div class="initials-avatar-sm" style="background-color: <?php echo e($color); ?>;">
                                                                                          <?php echo e($initials); ?>
                                                                                     </div>
                                                                                     <a href="../customers/detail-customer.php?id=<?php echo e($order['user_id']); ?>" class="link-primary fw-medium"><?php echo e($customerName); ?></a>
                                                                                </div>
                                                                           </td>
                                                                           <td><?php echo e($totalAmount); ?></td>
                                                                           <td>
                                                                                <span class="badge <?php echo e($paymentStatusBadgeClass); ?> px-2 py-1 fs-13">
                                                                                     <?php echo ucfirst($order['payment_status']); ?>
                                                                                </span>
                                                                           </td>
                                                                           <td><?php echo e($itemCount); ?></td>
                                                                           <td>
                                                                                <span class="badge <?php echo e($statusBadgeClass); ?> px-2 py-1 fs-13">
                                                                                     <?php echo ucfirst($order['status']); ?>
                                                                                </span>
                                                                           </td>
                                                                           <td>
                                                                                <div class="d-flex gap-2">
                                                                                     <a href="detail-order.php?id=<?php echo e($orderId); ?>" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                                     <a href="edit-order.php?id=<?php echo e($orderId); ?>" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                                                     <a href="?action=delete&id=<?php echo e($orderId); ?>"
                                                                                          class="btn btn-soft-danger btn-sm btn-delete"
                                                                                          data-id="<?php echo e($orderId); ?>"
                                                                                          data-order-number="#<?php echo e($orderNumber); ?>">
                                                                                          <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                                                     </a>
                                                                                </div>
                                                                           </td>
                                                                      </tr>
                                                                 <?php endwhile; ?>
                                                            <?php else: ?>
                                                                 <tr>
                                                                      <td colspan="10" class="text-center text-muted py-4">No orders found.</td>
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
                                                  Loading orders...
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

                    </div>
                    <!-- End Container Fluid -->

                    <!-- Delete Confirmation Modal -->
                    <div class="modal fade" id="deleteConfirmModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                         <div class="modal-dialog">
                              <div class="modal-content">
                                   <div class="modal-header">
                                        <h5 class="modal-title" id="deleteConfirmModalLabel">Delete Order</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                   </div>
                                   <div class="modal-body">
                                        <p>Are you sure you want to delete order <strong id="deleteOrderNumber"></strong>?</p>
                                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone. All order data including items will be permanently deleted.</p>
                                   </div>
                                   <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="button" id="confirmDeleteBtn" class="btn btn-danger">
                                             Delete Order
                                        </button>
                                   </div>
                              </div>
                         </div>
                    </div>

                    <!-- ========== Footer Start ========== -->
                    <footer class="footer">
                        <div class="container-fluid">
                            <div class="row">
                                <div class="col-12 text-center">
                                    <script>document.write(new Date().getFullYear())</script> &copy; Larkon. Crafted by <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon> <a
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
                                   "linear-gradient(to right, #dc3545, #b02a37)" :
                                   "linear-gradient(to right, #4CAF7C, #4CAF7C)",
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
               var paginationLoading = document.getElementById('paginationLoading');
               var ordersTableBody = document.getElementById('ordersTableBody');
               var pagination = document.getElementById('pagination');

               // Helper: get status badge class
               function getStatusBadgeClass(status) {
                    const classes = {
                         'pending': 'border border-secondary text-secondary',
                         'processing': 'border border-warning text-warning',
                         'shipped': 'border border-info text-info',
                         'delivered': 'border border-success text-success',
                         'cancelled': 'border border-danger text-danger',
                         'draft': 'border border-secondary text-secondary',
                         'packaging': 'border border-warning text-warning'
                    };
                    return classes[status.toLowerCase()] || 'border border-secondary text-secondary';
               }

               // Helper: get payment status badge class
               function getPaymentStatusBadgeClass(status) {
                    const classes = {
                         'paid': 'bg-success text-light',
                         'unpaid': 'bg-light text-dark',
                         'partial': 'bg-warning text-dark',
                         'refunded': 'bg-info text-light',
                         'failed': 'bg-danger text-light'
                    };
                    return classes[status.toLowerCase()] || 'bg-light text-dark';
               }

               // Helper: get initials from name
               function getInitials(firstName, lastName) {
                    let initials = '';
                    if (firstName && firstName.length > 0) {
                         initials += firstName.charAt(0).toUpperCase();
                    }
                    if (lastName && lastName.length > 0) {
                         initials += lastName.charAt(0).toUpperCase();
                    }
                    if (!initials && firstName && firstName.length > 0) {
                         initials = firstName.charAt(0).toUpperCase();
                    }
                    if (!initials && lastName && lastName.length > 0) {
                         initials = lastName.charAt(0).toUpperCase();
                    }
                    if (!initials) {
                         initials = 'U';
                    }
                    return initials;
               }

               // Helper: get color based on name
               function getColorFromName(name) {
                    const colors = [
                         '#4A6FA5', '#166088', '#2D3047', '#419D78',
                         '#E0A458', '#C04ABC', '#DB5461', '#686963',
                         '#3D5A80', '#98C1D9', '#EE6C4D', '#293241',
                         '#6B2737', '#E43F6F', '#1F2041', '#FFC857'
                    ];
                    
                    let hash = 0;
                    for (let i = 0; i < name.length; i++) {
                         hash = name.charCodeAt(i) + ((hash << 5) - hash);
                    }
                    
                    const index = Math.abs(hash) % colors.length;
                    return colors[index];
               }

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
                              ordersTableBody.innerHTML = '';
                              
                              if (data.rows.length > 0) {
                                   data.rows.forEach(function(order) {
                                        const customerName = (order.first_name || '') + ' ' + (order.last_name || '');
                                        const trimmedName = customerName.trim();
                                        const displayName = trimmedName || 'Unknown Customer';
                                        
                                        const initials = getInitials(order.first_name || '', order.last_name || '');
                                        const color = getColorFromName(displayName);
                                        
                                        const createdDate = new Date(order.created_at).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                                        const orderNumber = order.order_number || 'ORD-' + String(order.id).padStart(6, '0');
                                        const totalAmount = order.total_amount ? '₹' + parseFloat(order.total_amount).toFixed(2) : '$0.00';
                                        const itemCount = order.item_count || 0;
                                        
                                        const statusBadgeClass = getStatusBadgeClass(order.status || 'pending');
                                        const paymentStatusBadgeClass = getPaymentStatusBadgeClass(order.payment_status || 'unpaid');
                                        
                                        const rowHtml = `
                                        <tr id="row-${order.id}">
                                             <td>
                                                  <a href="detail-order.php?id=${order.id}" class="link-primary fw-medium">#${orderNumber}</a>
                                             </td>
                                             <td>${createdDate}</td>
                                             <td>
                                                  <div class="customer-cell">
                                                       <div class="initials-avatar-sm" style="background-color: ${color}">
                                                            ${initials}
                                                       </div>
                                                       <a href="customers/detail-customer.php?id=${order.user_id}" class="link-primary fw-medium">${displayName}</a>
                                                  </div>
                                             </td>
                                             <td>Normal</td>
                                             <td>${totalAmount}</td>
                                             <td>
                                                  <span class="badge ${paymentStatusBadgeClass} px-2 py-1 fs-13">
                                                       ${(order.payment_status || 'unpaid').charAt(0).toUpperCase() + (order.payment_status || 'unpaid').slice(1)}
                                                  </span>
                                             </td>
                                             <td>${itemCount}</td>
                                             <td>-</td>
                                             <td>
                                                  <span class="badge ${statusBadgeClass} px-2 py-1 fs-13">
                                                       ${(order.status || 'pending').charAt(0).toUpperCase() + (order.status || 'pending').slice(1)}
                                                  </span>
                                             </td>
                                             <td>
                                                  <div class="d-flex gap-2">
                                                       <a href="detail-order.php?id=${order.id}" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                       <a href="edit-order.php?id=${order.id}" class="btn btn-soft-primary btn-sm"><iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon></a>
                                                       <a href="?action=delete&id=${order.id}"
                                                            class="btn btn-soft-danger btn-sm btn-delete"
                                                            data-id="${order.id}"
                                                            data-order-number="#${orderNumber}">
                                                            <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon>
                                                       </a>
                                                  </div>
                                             </td>
                                        </tr>
                                        `;
                                        ordersTableBody.innerHTML += rowHtml;
                                   });
                              } else {
                                   ordersTableBody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">No orders found.</td></tr>';
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
                  Delete modal + AJAX
                  ------------------- */
               var deleteModalEl = document.getElementById('deleteConfirmModal');
               var deleteOrderNumberEl = document.getElementById('deleteOrderNumber');
               var bsDeleteModal = null;
               
               // Use event delegation for delete buttons
               document.addEventListener('click', function(e) {
                    var delBtn = e.target.closest('.btn-delete');
                    if (!delBtn) return;
                    e.preventDefault();
                    var deleteId = delBtn.getAttribute('data-id');
                    var orderNumber = delBtn.getAttribute('data-order-number');
                    if (!deleteId) return;
                    
                    // Update modal content
                    deleteOrderNumberEl.textContent = orderNumber;
                    deleteModalEl.dataset.deleteId = deleteId;
                    bsDeleteModal = bootstrap.Modal.getOrCreateInstance(deleteModalEl);
                    bsDeleteModal.show();
               });

               var confirmBtn = document.getElementById('confirmDeleteBtn');
               var origConfirmHtml = confirmBtn ? confirmBtn.innerHTML : 'Delete Order';
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
                              showToast(json.message || 'Order deleted successfully.', 'success');
                              
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

          })();
     </script>

</body>
</html>
<?php
if (isset($result) && $result) $result->free();
$conn->close();
?>