<?php
// details-user.php
// Shows user details and addresses from DB.

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
          '#4A6FA5',
          '#166088',
          '#2D3047',
          '#419D78',
          '#E0A458',
          '#C04ABC',
          '#DB5461',
          '#686963',
          '#3D5A80',
          '#98C1D9',
          '#EE6C4D',
          '#293241',
          '#6B2737',
          '#E43F6F',
          '#1F2041',
          '#FFC857'
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
   - If POST && action=delete -> return JSON (AJAX)
   - Else if GET && action=delete -> perform delete and redirect (fallback)
   ========================= */

function delete_user_by_id($conn, $id, &$out_error = null)
{
     $out_error = null;
     $id = (int)$id;
     if ($id <= 0) {
          $out_error = "Invalid user id.";
          return false;
     }

     // Check if user exists
     $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
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
          $out_error = "User not found.";
          return false;
     }

     // delete row
     $del = $conn->prepare("DELETE FROM users WHERE id = ?");
     if (!$del) {
          $out_error = "Database error (prepare delete).";
          return false;
     }
     $del->bind_param('i', $id);
     if (!$del->execute()) {
          $out_error = "Failed to delete user (database).";
          $del->close();
          return false;
     }
     $del->close();

     return true;
}

// Handle AJAX delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
     // Expect id in POST
     $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
     $out_err = null;
     $ok = delete_user_by_id($conn, $id, $out_err);
     header('Content-Type: application/json; charset=utf-8');
     if ($ok) {
          echo json_encode(['success' => true, 'message' => 'User deleted successfully.', 'id' => $id]);
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
     $ok = delete_user_by_id($conn, $id, $out_err);
     if ($ok) {
          // PRG: redirect with flag
          header("Location: list-customer.php?deleted=1");
          exit;
     } else {
          $q = urlencode($out_err ?: 'Unknown error');
          header("Location: details-user.php?id=$id&delete_error={$q}");
          exit;
     }
}

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
     header("Location: list-customer.php");
     exit;
}

$userId = (int)$_GET['id'];

// Fetch user details
$user = null;
$stmt = $conn->prepare("SELECT id, email, first_name, last_name, phone, created_at FROM users WHERE id = ?");
if ($stmt) {
     $stmt->bind_param('i', $userId);
     $stmt->execute();
     $result = $stmt->get_result();
     $user = $result->fetch_assoc();
     $stmt->close();

     if (!$user) {
          header("Location: list-customer.php");
          exit;
     }
} else {
     die("Database error: " . $conn->error);
}

// Fetch user addresses
$addresses = [];
$stmt = $conn->prepare("SELECT id, label, line1, line2, city, state, pincode, country, phone, is_default_shipping, is_default_billing, created_at FROM addresses WHERE user_id = ? ORDER BY is_default_shipping DESC, is_default_billing DESC, created_at DESC");
if ($stmt) {
     $stmt->bind_param('i', $userId);
     $stmt->execute();
     $result = $stmt->get_result();
     while ($row = $result->fetch_assoc()) {
          $addresses[] = $row;
     }
     $stmt->close();
}

// Get default shipping address
$defaultShippingAddress = null;
foreach ($addresses as $address) {
     if ($address['is_default_shipping']) {
          $defaultShippingAddress = $address;
          break;
     }
}

// Get default billing address
$defaultBillingAddress = null;
foreach ($addresses as $address) {
     if ($address['is_default_billing']) {
          $defaultBillingAddress = $address;
          break;
     }
}

// Format user data
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (empty($fullName)) {
     $fullName = 'Unknown User';
}
$email = $user['email'] ?? '-';
$phone = $user['phone'] ?? '-';
$joinDate = date('d M, Y', strtotime($user['created_at'] ?? ''));
$initials = getInitials($user['first_name'] ?? '', $user['last_name'] ?? '');
$color = getColorFromName($fullName);
$accountId = 'AC-' . str_pad($userId, 6, '0', STR_PAD_LEFT);


// Fetch customer statistics
$totalOrders = 0;
$totalSpent = 0;
$totalAddresses = count($addresses);

if ($conn) {
     // Get total orders for this customer
     $ordersStmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
     if ($ordersStmt) {
          $ordersStmt->bind_param('i', $userId);
          $ordersStmt->execute();
          $ordersResult = $ordersStmt->get_result();
          $ordersRow = $ordersResult->fetch_assoc();
          $totalOrders = $ordersRow['count'] ?? 0;
          $ordersStmt->close();
     }
     
     // Get total spent (sum of all orders regardless of payment status)
     $spentStmt = $conn->prepare("SELECT SUM(total_amount) as total FROM orders WHERE user_id = ?");
     if ($spentStmt) {
          $spentStmt->bind_param('i', $userId);
          $spentStmt->execute();
          $spentResult = $spentStmt->get_result();
          $spentRow = $spentResult->fetch_assoc();
          $totalSpent = $spentRow['total'] ?? 0;
          $spentStmt->close();
     }
}

// Build comprehensive activity timeline
$activities = [];

// Add account creation activity
$activities[] = [
     'type' => 'account_created',
     'date' => $user['created_at'],
     'timestamp' => strtotime($user['created_at']),
     'description' => 'Account Created',
     'details' => 'User registered on the platform',
     'icon' => 'solar:user-plus-bold-duotone',
     'color' => 'success'
];

// Add address activities
foreach ($addresses as $address) {
     $addressLabel = $address['label'] ?? 'Address';
     $activities[] = [
          'type' => 'address_added',
          'date' => $address['created_at'],
          'timestamp' => strtotime($address['created_at']),
          'description' => 'Address Added',
          'details' => 'Added ' . $addressLabel . ' address',
          'icon' => 'solar:map-point-bold-duotone',
          'color' => 'info'
     ];
}

// Fetch and add order activities
if ($conn) {
     $ordersQuery = $conn->prepare("
          SELECT id, order_number, status, payment_status, total_amount, created_at 
          FROM orders 
          WHERE user_id = ? 
          ORDER BY created_at DESC
     ");
     if ($ordersQuery) {
          $ordersQuery->bind_param('i', $userId);
          $ordersQuery->execute();
          $ordersResult = $ordersQuery->get_result();
          while ($orderRow = $ordersResult->fetch_assoc()) {
               $orderNumber = $orderRow['order_number'] ?: 'ORD-' . str_pad($orderRow['id'], 6, '0', STR_PAD_LEFT);
               $activities[] = [
                    'type' => 'order_placed',
                    'date' => $orderRow['created_at'],
                    'timestamp' => strtotime($orderRow['created_at']),
                    'description' => 'Order Placed',
                    'details' => 'Order #' . $orderNumber . ' - ₹' . number_format($orderRow['total_amount'], 2),
                    'icon' => 'solar:box-bold-duotone',
                    'color' => 'primary',
                    'order_id' => $orderRow['id'],
                    'order_number' => $orderNumber,
                    'status' => $orderRow['status'],
                    'payment_status' => $orderRow['payment_status'],
                    'amount' => $orderRow['total_amount']
               ];
          }
          $ordersQuery->close();
     }
}

// Sort activities by timestamp (most recent first)
usort($activities, function($a, $b) {
     return $b['timestamp'] - $a['timestamp'];
});

// Pagination configuration for activities
$activityPage = isset($_GET['activity_page']) ? (int)$_GET['activity_page'] : 1;
if ($activityPage < 1) $activityPage = 1;

// Use user's preference or default to 5
$activitiesPerPage = 5; 
$totalActivities = count($activities);
$totalActivityPages = ceil($totalActivities / $activitiesPerPage);

// Limit current page to max pages
if ($activityPage > $totalActivityPages && $totalActivityPages > 0) {
     $activityPage = $totalActivityPages;
}

$activityOffset = ($activityPage - 1) * $activitiesPerPage;

// Slice activities for current page
$displayActivities = array_slice($activities, $activityOffset, $activitiesPerPage);

// Handle AJAX activity pagination
if (isset($_GET['action']) && $_GET['action'] === 'paginate_activity') {
     header('Content-Type: application/json; charset=utf-8');
     echo json_encode([
          'success' => true,
          'activities' => $displayActivities,
          'currentPage' => $activityPage,
          'totalPages' => $totalActivityPages,
          'totalActivities' => $totalActivities,
          'offset' => $activityOffset,
          'perPage' => $activitiesPerPage
     ]);
     exit;
}


// Helper function for order status badge
if (!function_exists('getStatusBadgeClass')) {
     function getStatusBadgeClass($status) {
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
}

// Helper function for payment status badge
if (!function_exists('getPaymentStatusBadgeClass')) {
     function getPaymentStatusBadgeClass($status) {
          $classes = [
               'paid' => 'bg-success text-light',
               'unpaid' => 'bg-light text-dark',
               'partial' => 'bg-warning text-dark',
               'refunded' => 'bg-info text-light',
               'failed' => 'bg-danger text-light'
          ];
          return $classes[strtolower($status)] ?? 'bg-light text-dark';
     }
}

// Build flash messages from GET params (set by fallback redirect)
$flashSuccess = "";
$flashError = "";
if (isset($_GET['delete_error'])) {
     $flashError = htmlspecialchars(urldecode($_GET['delete_error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<!-- Mirrored from techzaa.in/larkon/admin/customer-detail.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:03 GMT -->

<head>
     <!-- Title Meta -->
     <meta charset="utf-8" />
     <title>Customer Details | Larkon - Responsive Admin Dashboard Template</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <meta name="description" content="A fully responsive premium admin dashboard template" />
     <meta name="author" content="Techzaa" />
     <meta http-equiv="X-UA-Compatible" content="IE=edge" />

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
          /* Initials Avatar Styles */
          .initials-avatar {
               width: 100px;
               height: 100px;
               border-radius: 50%;
               display: flex;
               align-items: center;
               justify-content: center;
               color: white;
               font-weight: 700;
               font-size: 36px;
               text-transform: uppercase;
               border: 3px solid white;
          }

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

          .address-badge {
               font-size: 11px;
               padding: 2px 6px;
               margin-left: 5px;
          }

          .address-card {
               transition: all 0.3s ease;
               border: 1px solid #dee2e6;
          }

          .address-card:hover {
               border-color: #4A6FA5;
               box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
          }

          .address-card.default {
               border-color: #4A6FA5;
               background-color: rgba(74, 111, 165, 0.05);
          }

          .no-addresses {
               text-align: center;
               padding: 40px 20px;
               color: #6c757d;
          }

          .no-addresses-icon {
               font-size: 48px;
               color: #dee2e6;
               margin-bottom: 15px;
          }
          
          .delete-user-btn {
               width: 100%;
          }
     </style>
</head>

<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <?php include __DIR__ . '/../includes/header.php' ?>
          <?php include __DIR__ . '/../includes/sidebar.php' ?>

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
          <div>

               <div class="page-content">

                    <!-- Start Container Fluid -->
                    <div class="container-xxl">


                         <div class="row">
                              <div class="col-lg-4">
                                   <div class="card overflow-hidden">
                                        <div class="card-body">
                                             <div class="bg-primary profile-bg rounded-top p-5 position-relative mx-n3 mt-n3">
                                                  <div class="initials-avatar position-absolute top-100 start-0 translate-middle ms-5" style="background-color: <?php echo e($color); ?>;">
                                                       <?php echo e($initials); ?>
                                                  </div>
                                             </div>
                                             <div class="mt-4 pt-3">
                                                  <h4 class="mb-1"><?php echo e($fullName); ?><i class="bx bxs-badge-check text-success align-middle"></i></h4>
                                                  <div class="mt-2">
                                                       <a href="mailto:<?php echo e($email); ?>" class="link-primary fs-15"><?php echo e($email); ?></a>
                                                       <p class="fs-15 mb-1 mt-1"><span class="text-dark fw-semibold">Email : </span> <?php echo e($email); ?></p>
                                                       <p class="fs-15 mb-0 mt-1"><span class="text-dark fw-semibold">Phone : </span> <?php echo e($phone); ?></p>
                                                  </div>
                                             </div>
                                        </div>
                                        <div class="card-footer border-top gap-1 hstack">
                                             <button type="button" 
                                                  class="btn btn-soft-danger delete-user-btn"
                                                  data-bs-toggle="modal" 
                                                  data-bs-target="#deleteUserModal"
                                                  data-user-id="<?php echo e($userId); ?>"
                                                  data-user-name="<?php echo e($fullName); ?>">
                                                  <iconify-icon icon="solar:trash-bin-minimalistic-2-broken" class="align-middle fs-18"></iconify-icon> Delete User
                                             </button>
                                        </div>
                                   </div>

                                   <div class="card">
                                        <div class="card-header d-flex align-items-center justify-content-between">
                                             <div>
                                                  <h4 class="card-title">Customer Details</h4>
                                             </div>
                                             <div>
                                                  <span class="badge bg-success-subtle text-success px-2 py-1">Active User</span>
                                             </div>

                                        </div>
                                        <div class="card-body py-2">
                                             <div class="table-responsive">
                                                  <table class="table mb-0">
                                                       <tbody>
                                                            <!-- <tr>
                                                                 <td class="px-0">
                                                                      <p class="d-flex mb-0 align-items-center gap-1 fw-semibold text-dark">Account ID : </p>
                                                                 </td>
                                                                 <td class="text-dark fw-medium px-0">#<?php echo e($accountId); ?></td>
                                                            </tr> -->
                                                            <tr>
                                                                 <td class="px-0">
                                                                      <p class="d-flex mb-0 align-items-center gap-1 fw-semibold text-dark"> Invoice Email : </p>
                                                                 </td>
                                                                 <td class="text-dark fw-medium px-0"><?php echo e($email); ?></td>
                                                            </tr>
                                                            <tr>
                                                                 <td class="px-0">
                                                                      <p class="d-flex mb-0 align-items-center gap-1 fw-semibold text-dark"> Delivery Address : </p>
                                                                 </td>
                                                                 <td class="text-dark fw-medium px-0">
                                                                      <?php if ($defaultShippingAddress): ?>
                                                                           <?php
                                                                           $addressParts = [];
                                                                           if (!empty($defaultShippingAddress['line1'])) $addressParts[] = $defaultShippingAddress['line1'];
                                                                           if (!empty($defaultShippingAddress['line2'])) $addressParts[] = $defaultShippingAddress['line2'];
                                                                           if (!empty($defaultShippingAddress['city'])) $addressParts[] = $defaultShippingAddress['city'];
                                                                           if (!empty($defaultShippingAddress['pincode'])) $addressParts[] = $defaultShippingAddress['pincode'];
                                                                           echo e(implode(', ', $addressParts));
                                                                           ?>
                                                                      <?php else: ?>
                                                                           No default shipping address
                                                                      <?php endif; ?>
                                                                 </td>
                                                            </tr>
                                                            <tr>
                                                                 <td class="px-0">
                                                                      <p class="d-flex mb-0 align-items-center gap-1 fw-semibold text-dark"> State : </p>
                                                                 </td>
                                                                 <td class="text-dark fw-medium px-0"><?php echo e($defaultShippingAddress['state']) . ',' . $defaultShippingAddress['country']; ?></td>
                                                            </tr>
                                                            <tr>
                                                                 <td class="px-0">
                                                                      <p class="d-flex mb-0 align-items-center gap-1 fw-semibold text-dark"> Customer Since : </p>
                                                                 </td>
                                                                 <td class="text-dark fw-medium px-0"><?php echo e($joinDate); ?></td>
                                                            </tr>
                                                       </tbody>
                                                  </table>
                                             </div>
                                        </div>
                                   </div>


                              </div>

                              <div class="col-lg-8">
                                    <div class="row">
                                         <div class="col-lg-4">
                                              <div class="card">
                                                   <div class="card-body">
                                                        <div class="d-flex align-items-center justify-content-between">
                                                             <div>
                                                                  <h4 class="card-title mb-2 d-flex align-items-center gap-2">Total Orders</h4>
                                                                  <p class="text-muted fw-medium fs-22 mb-0"><?php echo number_format($totalOrders); ?></p>
                                                             </div>
                                                             <div>
                                                                  <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                                       <iconify-icon icon="solar:box-bold-duotone" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                                  </div>
                                                             </div>
                                                        </div>
                                                   </div>
                                              </div>
                                         </div>
                                         <div class="col-lg-4">
                                              <div class="card">
                                                   <div class="card-body">
                                                        <div class="d-flex align-items-center justify-content-between">
                                                             <div>
                                                                  <h4 class="card-title mb-2 d-flex align-items-center gap-2">Total Addresses</h4>
                                                                  <p class="text-muted fw-medium fs-22 mb-0"><?php echo number_format($totalAddresses); ?></p>
                                                             </div>
                                                             <div>
                                                                  <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                                       <iconify-icon icon="solar:map-point-bold-duotone" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                                  </div>
                                                             </div>
                                                        </div>
                                                   </div>
                                              </div>
                                         </div>
                                         <div class="col-lg-4">
                                              <div class="card">
                                                   <div class="card-body">
                                                        <div class="d-flex align-items-center justify-content-between">
                                                             <div>
                                                                  <h4 class="card-title mb-2 d-flex align-items-center gap-2">Total Spent</h4>
                                                                  <p class="text-muted fw-medium fs-22 mb-0">₹<?php echo number_format($totalSpent, 2); ?></p>
                                                             </div>
                                                             <div>
                                                                  <div class="avatar-md bg-primary bg-opacity-10 rounded">
                                                                       <iconify-icon icon="solar:wallet-money-bold-duotone" class="fs-32 text-primary avatar-title"></iconify-icon>
                                                                  </div>
                                                             </div>
                                                        </div>
                                                   </div>
                                              </div>
                                         </div>
                                   </div>

                                   <div class="card">
                                         <div class="card-header">
                                              <h4 class="card-title">Recent Activity</h4>
                                         </div>
                                         <div class="card-body">
                                              <div class="table-responsive">
                                                   <table class="table align-middle mb-0 table-hover table-centered">
                                                        <thead class="bg-light-subtle">
                                                             <tr>
                                                                  <th>Date</th>
                                                                  <th>Activity</th>
                                                                  <th>Details</th>
                                                                  <th>Action</th>
                                                             </tr>
                                                        </thead>
                                                         <tbody id="activityTableBody">
                                                              <?php if (count($displayActivities) > 0): ?>
                                                                   <?php foreach ($displayActivities as $activity): 
                                                                       $activityDate = date('d M, Y', $activity['timestamp']);
                                                                       $activityTime = date('h:i A', $activity['timestamp']);
                                                                  ?>
                                                                  <tr>
                                                                       <td>
                                                                            <div>
                                                                                 <div class="fw-medium"><?php echo e($activityDate); ?></div>
                                                                                 <small class="text-muted"><?php echo e($activityTime); ?></small>
                                                                            </div>
                                                                       </td>
                                                                       <td>
                                                                            <div class="d-flex align-items-center gap-2">
                                                                                 <div class="avatar-sm bg-<?php echo e($activity['color']); ?> bg-opacity-10 rounded d-flex align-items-center justify-content-center">
                                                                                      <iconify-icon icon="<?php echo e($activity['icon']); ?>" class="fs-20 text-<?php echo e($activity['color']); ?>"></iconify-icon>
                                                                                 </div>
                                                                                 <span class="fw-medium"><?php echo e($activity['description']); ?></span>
                                                                            </div>
                                                                       </td>
                                                                       <td>
                                                                            <?php if ($activity['type'] === 'order_placed'): ?>
                                                                                 <div>
                                                                                      <div><?php echo $activity['details']; ?></div>
                                                                                      <div class="mt-1">
                                                                                           <span class="badge <?php echo e(getStatusBadgeClass($activity['status'])); ?> px-2 py-1 fs-11 me-1">
                                                                                                <?php echo ucfirst($activity['status']); ?>
                                                                                           </span>
                                                                                           <span class="badge <?php echo e(getPaymentStatusBadgeClass($activity['payment_status'])); ?> px-2 py-1 fs-11">
                                                                                                <?php echo ucfirst($activity['payment_status']); ?>
                                                                                           </span>
                                                                                      </div>
                                                                                 </div>
                                                                            <?php else: ?>
                                                                                 <?php echo e($activity['details']); ?>
                                                                            <?php endif; ?>
                                                                       </td>
                                                                       <td>
                                                                            <?php if ($activity['type'] === 'order_placed'): ?>
                                                                                 <a href="../order/detail-order.php?id=<?php echo e($activity['order_id']); ?>" class="btn btn-light btn-sm">
                                                                                      <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                                                                                 </a>
                                                                            <?php else: ?>
                                                                                 <span class="text-muted">-</span>
                                                                            <?php endif; ?>
                                                                       </td>
                                                                  </tr>
                                                                  <?php endforeach; ?>
                                                             <?php else: ?>
                                                                  <tr>
                                                                       <td colspan="4" class="text-center text-muted py-4">
                                                                            <div class="py-3">
                                                                                 <iconify-icon icon="solar:history-bold-duotone" class="fs-48 text-muted mb-2"></iconify-icon>
                                                                                 <p class="mb-0">No activity found for this customer</p>
                                                                            </div>
                                                                       </td>
                                                                  </tr>
                                                             <?php endif; ?>
                                                        </tbody>
                                                   </table>
                                              </div>
                                         </div>
                                          <div class="card-footer border-top" id="activityFooter">
                                               <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                    <div class="text-muted">
                                                         <?php 
                                                         $start = $totalActivities > 0 ? $activityOffset + 1 : 0;
                                                         $end = min($activityOffset + $activitiesPerPage, $totalActivities);
                                                         echo "Showing $start to $end of $totalActivities activities"; 
                                                         ?>
                                                    </div>
                                                    <?php if ($totalActivityPages > 1): ?>
                                                    <nav aria-label="Activity pagination">
                                                         <ul class="pagination pagination-sm justify-content-end mb-0" id="activityPagination">
                                                              <!-- Previous Button -->
                                                              <li class="page-item <?php echo $activityPage <= 1 ? 'disabled' : ''; ?>">
                                                                   <a class="page-link" href="?id=<?php echo $userId; ?>&activity_page=<?php echo $activityPage - 1; ?>" data-page="<?php echo $activityPage - 1; ?>" <?php echo $activityPage <= 1 ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                                        Previous
                                                                   </a>
                                                              </li>
                                                              
                                                              <?php 
                                                              // Show simple page numbers
                                                              for ($i = 1; $i <= $totalActivityPages; $i++): 
                                                                   $activeClass = $i == $activityPage ? 'active' : '';
                                                              ?>
                                                                   <li class="page-item <?php echo $activeClass; ?>">
                                                                        <a class="page-link" href="?id=<?php echo $userId; ?>&activity_page=<?php echo $i; ?>" data-page="<?php echo $i; ?>">
                                                                             <?php echo $i; ?>
                                                                        </a>
                                                                   </li>
                                                              <?php endfor; ?>
                                                              
                                                              <!-- Next Button -->
                                                              <li class="page-item <?php echo $activityPage >= $totalActivityPages ? 'disabled' : ''; ?>">
                                                                   <a class="page-link" href="?id=<?php echo $userId; ?>&activity_page=<?php echo $activityPage + 1; ?>" data-page="<?php echo $activityPage + 1; ?>" <?php echo $activityPage >= $totalActivityPages ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                                                                        Next
                                                                   </a>
                                                              </li>
                                                         </ul>
                                                    </nav>
                                                    <?php endif; ?>
                                               </div>
                                         </div>
                                   </div>
                                   <div class="row">
                                        <div class="col-lg-6">
                                             <div class="card">
                                                  <div class="card-header border-bottom border-dashed">
                                                       <div class="d-flex align-items-center gap-2">
                                                            <div class="d-block">
                                                                 <h4 class="card-title mb-1">Addresses</h4>
                                                                 <p class="mb-0 text-muted">Total <?php echo count($addresses); ?> address(es)</p>
                                                            </div>

                                                       </div>
                                                  </div>
                                                  <div class="card-body">
                                                       <?php if (count($addresses) > 0): ?>
                                                            <?php foreach ($addresses as $address): ?>
                                                                 <?php
                                                                 $isDefault = $address['is_default_shipping'] || $address['is_default_billing'];
                                                                 $cardClass = $isDefault ? 'address-card default mb-3 p-3 rounded' : 'address-card mb-3 p-3 rounded';
                                                                 ?>
                                                                 <div class="<?php echo $cardClass; ?>">
                                                                      <div class="d-flex justify-content-between align-items-start">
                                                                           <div>
                                                                                <h6 class="mb-1">
                                                                                     <?php echo e($address['label'] ?? 'Address'); ?>
                                                                                     <?php if ($address['is_default_shipping']): ?>
                                                                                          <span class="badge bg-primary address-badge">Shipping</span>
                                                                                     <?php endif; ?>
                                                                                     <?php if ($address['is_default_billing']): ?>
                                                                                          <span class="badge bg-success address-badge">Billing</span>
                                                                                     <?php endif; ?>
                                                                                </h6>
                                                                           </div>
                                                                           <div class="dropdown">
                                                                                <a href="#" class="dropdown-toggle arrow-none card-drop p-0" data-bs-toggle="dropdown" aria-expanded="false">
                                                                                     <i class="ti ti-dots-vertical"></i>
                                                                                </a>
                                                                                <div class="dropdown-menu dropdown-menu-end">
                                                                                     <a href="edit-address.php?id=<?php echo e($address['id']); ?>&user_id=<?php echo e($userId); ?>" class="dropdown-item">Edit</a>
                                                                                     <a href="delete-address.php?id=<?php echo e($address['id']); ?>&user_id=<?php echo e($userId); ?>" class="dropdown-item text-danger" onclick="return confirm('Are you sure you want to delete this address?')">Delete</a>
                                                                                </div>
                                                                           </div>
                                                                      </div>
                                                                      <p class="mb-1"><?php echo e($address['line1']); ?></p>
                                                                      <?php if (!empty($address['line2'])): ?>
                                                                           <p class="mb-1"><?php echo e($address['line2']); ?></p>
                                                                      <?php endif; ?>
                                                                      <p class="mb-1">
                                                                           <?php
                                                                           $locationParts = [];
                                                                           if (!empty($address['city'])) $locationParts[] = $address['city'];
                                                                           if (!empty($address['state'])) $locationParts[] = $address['state'];
                                                                           if (!empty($address['pincode'])) $locationParts[] = $address['pincode'];
                                                                           echo e(implode(', ', $locationParts));
                                                                           ?>
                                                                      </p>
                                                                      <?php if (!empty($address['country'])): ?>
                                                                           <p class="mb-1"><?php echo e($address['country']); ?></p>
                                                                      <?php endif; ?>
                                                                      <?php if (!empty($address['phone'])): ?>
                                                                           <p class="mb-0"><small class="text-muted">Phone: <?php echo e($address['phone']); ?></small></p>
                                                                      <?php endif; ?>
                                                                 </div>
                                                            <?php endforeach; ?>
                                                       <?php else: ?>
                                                            <div class="no-addresses">
                                                                 <div class="no-addresses-icon">
                                                                      <iconify-icon icon="solar:map-point-wave-bold-duotone"></iconify-icon>
                                                                 </div>
                                                                 <h5 class="text-muted mb-2">No addresses found</h5>
                                                                 <p class="text-muted mb-3">This user hasn't added any addresses yet.</p>
                                                            </div>
                                                       <?php endif; ?>
                                                  </div>
                                             </div>
                                        </div>
                                        <div class="col-lg-6">
                                             <div class="card">
                                                  <div class="card-body">
                                                       <div class="d-flex gap-3">
                                                            <div class="avatar bg-light d-flex align-items-center justify-content-center rounded-circle">
                                                                 <i class="bx bx-user-plus fs-30"></i>
                                                            </div>
                                                            <div class="d-block">
                                                                 <h4 class="text-dark fw-medium mb-1">Account Created</h4>
                                                                 <p class="mb-0 text-muted"><?php echo e($joinDate); ?></p>
                                                            </div>
                                                            <div class="ms-auto">
                                                                 <h4 class="text-dark fw-medium mb-1">Active</h4>
                                                            </div>
                                                       </div>
                                                  </div>
                                             </div>
                                             <div class="card">
                                                  <div class="card-body">
                                                       <div class="d-flex align-items-center gap-2">
                                                            <div class="initials-avatar-sm" style="background-color: <?php echo e($color); ?>;">
                                                                 <?php echo e($initials); ?>
                                                            </div>
                                                            <div class="d-block">
                                                                 <h4 class="text-dark fw-medium mb-1"><?php echo e($fullName); ?></h4>
                                                                 <p class="mb-0 text-muted">Customer since <?php echo e($joinDate); ?></p>
                                                            </div>
                                                       </div>
                                                       <div class="mt-4">
                                                            <div class="d-flex align-items-center">
                                                                 <h5 class="text-dark mb-0">Account Summary <span class="text-muted fw-normal ms-1"><i class="bx bxs-circle fs-10"></i></span><span class="text-muted fw-normal ms-1">Status</span></h5>
                                                                 <div class="ms-auto">
                                                                      <a href="#!" class="link-reset fw-medium">Active <i class="bx bx-up-arrow-alt text-success"></i></a>
                                                                 </div>
                                                            </div>
                                                            <h3 class="fw-semibold mt-2 mb-0" style="font-size: 16px; "><?php echo e($email); ?></h3>
                                                       </div>
                                                  </div>
                                             </div>
                                        </div>
                                   </div>
                              </div>
                         </div>


                    </div>
                    <!-- End Container Fluid -->

                    <!-- Delete User Confirmation Modal -->
                    <div class="modal fade" id="deleteUserModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
                         <div class="modal-dialog">
                              <div class="modal-content">
                                   <div class="modal-header">
                                        <h5 class="modal-title" id="deleteUserModalLabel">Delete User</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                   </div>
                                   <div class="modal-body">
                                        <p>Are you sure you want to delete <strong id="deleteUserName"><?php echo e($fullName); ?></strong>?</p>
                                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone. All user data including addresses will be permanently deleted.</p>
                                   </div>
                                   <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="button" id="confirmDeleteUserBtn" class="btn btn-danger">
                                             Delete User
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

               // Show server-side messages (from fallback redirect params)
               var serverError = <?php echo json_encode($flashError ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
               if (serverError && serverError.length) {
                    setTimeout(function() {
                         showToast(serverError, 'error');
                    }, 50);
               }

               /* -------------------
                  Delete User Modal + AJAX
                  ------------------- */
               var deleteUserModalEl = document.getElementById('deleteUserModal');
               var deleteUserNameEl = document.getElementById('deleteUserName');
               var confirmDeleteUserBtn = document.getElementById('confirmDeleteUserBtn');
               var currentUserId = <?php echo $userId; ?>;
               var currentUserName = "<?php echo e($fullName); ?>";

               // When delete button is clicked, update modal with user info
               var deleteButtons = document.querySelectorAll('.delete-user-btn');
               deleteButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                         var userId = this.getAttribute('data-user-id');
                         var userName = this.getAttribute('data-user-name');
                         
                         // Update modal content
                         deleteUserNameEl.textContent = userName;
                         confirmDeleteUserBtn.setAttribute('data-user-id', userId);
                         confirmDeleteUserBtn.setAttribute('data-user-name', userName);
                    });
               });

               // Handle delete confirmation
               var origConfirmHtml = confirmDeleteUserBtn ? confirmDeleteUserBtn.innerHTML : 'Delete User';
               confirmDeleteUserBtn && confirmDeleteUserBtn.addEventListener('click', function() {
                    var id = this.getAttribute('data-user-id');
                    var userName = this.getAttribute('data-user-name');
                    
                    if (!id) {
                         // Use current user ID if modal wasn't updated
                         id = currentUserId;
                         userName = currentUserName;
                    }
                    
                    confirmDeleteUserBtn.disabled = true;
                    confirmDeleteUserBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Deleting...';

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
                         confirmDeleteUserBtn.disabled = false;
                         confirmDeleteUserBtn.innerHTML = origConfirmHtml;
                         
                         // Hide modal
                         var bsModal = bootstrap.Modal.getInstance(deleteUserModalEl);
                         if (bsModal) bsModal.hide();
                         
                         if (json && json.success) {
                              showToast(json.message || 'User deleted successfully.', 'success');
                              
                              // Redirect to user list after 1.5 seconds
                              setTimeout(function() {
                                   window.location.href = 'list-customer.php?deleted=1';
                              }, 1500);
                         } else {
                              showToast((json && json.error) ? json.error : 'Delete failed', 'error');
                         }
                    }).catch(function(err) {
                         confirmDeleteUserBtn.disabled = false;
                         confirmDeleteUserBtn.innerHTML = origConfirmHtml;
                         
                         // Hide modal
                         var bsModal = bootstrap.Modal.getInstance(deleteUserModalEl);
                         if (bsModal) bsModal.hide();
                         
                         showToast('Request failed: ' + (err && err.message ? err.message : 'network error'), 'error');
                    });
               });

               // Reset modal when closed
               deleteUserModalEl.addEventListener('hidden.bs.modal', function() {
                    confirmDeleteUserBtn.disabled = false;
                    confirmDeleteUserBtn.innerHTML = origConfirmHtml;
                    confirmDeleteUserBtn.removeAttribute('data-user-id');
                    confirmDeleteUserBtn.removeAttribute('data-user-name');
               });

          })();
     </script>

     <script>
          (function() {
               // AJAX logic for activity pagination
               const userId = <?php echo (int)$userId; ?>;
               const activityTableBody = document.getElementById('activityTableBody');
               const activityPagination = document.getElementById('activityPagination');
               const activityFooter = document.getElementById('activityFooter');
               let currentActivityPage = <?php echo (int)$activityPage; ?>;

               function loadActivityPage(page) {
                    if (page < 1) return;
                    
                    // Add fade-out or loading state
                    activityTableBody.style.opacity = '0.5';
                    
                    fetch(`?id=${userId}&action=paginate_activity&activity_page=${page}`)
                         .then(response => response.json())
                         .then(data => {
                              if (data.success) {
                                   renderActivities(data.activities);
                                   updatePagination(data.currentPage, data.totalPages);
                                   updateFooterText(data.offset, data.perPage, data.totalActivities);
                                   currentActivityPage = data.currentPage;
                                   
                                   // Update URL
                                   const url = new URL(window.location);
                                   url.searchParams.set('activity_page', page);
                                   window.history.pushState({}, '', url);
                              }
                         })
                         .catch(err => console.error('Error loading activities:', err))
                         .finally(() => {
                              activityTableBody.style.opacity = '1';
                         });
               }

               function renderActivities(activities) {
                    if (!activities || activities.length === 0) {
                         activityTableBody.innerHTML = '<tr><td colspan="4" class="text-center">No activity found</td></tr>';
                         return;
                    }

                    let html = '';
                    activities.forEach(activity => {
                         const date = new Date(activity.timestamp * 1000);
                         const dateStr = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                         const timeStr = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

                         let detailsHtml = '';
                         if (activity.type === 'order_placed') {
                              detailsHtml = `
                                   <div>
                                        <div>${activity.details}</div>
                                        <div class="mt-1">
                                             <span class="badge ${getStatusBadgeClass(activity.status)} px-2 py-1 fs-11 me-1">
                                                  ${capitalizeFirstLetter(activity.status)}
                                             </span>
                                             <span class="badge ${getPaymentStatusBadgeClass(activity.payment_status)} px-2 py-1 fs-11">
                                                  ${capitalizeFirstLetter(activity.payment_status)}
                                             </span>
                                        </div>
                                   </div>`;
                         } else {
                              detailsHtml = activity.details;
                         }

                         let actionHtml = '';
                         if (activity.type === 'order_placed') {
                              actionHtml = `<a href="../order/detail-order.php?id=${activity.order_id}" class="btn btn-light btn-sm">
                                   <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
                              </a>`;
                         } else {
                              actionHtml = '<span class="text-muted">-</span>';
                         }

                         html += `
                              <tr>
                                   <td>
                                        <div>
                                             <div class="fw-medium">${dateStr}</div>
                                             <small class="text-muted">${timeStr}</small>
                                        </div>
                                   </td>
                                   <td>
                                        <div class="d-flex align-items-center gap-2">
                                             <div class="avatar-sm bg-${activity.color} bg-opacity-10 rounded d-flex align-items-center justify-content-center">
                                                  <iconify-icon icon="${activity.icon}" class="fs-20 text-${activity.color}"></iconify-icon>
                                             </div>
                                             <span class="fw-medium">${activity.description}</span>
                                        </div>
                                   </td>
                                   <td>${detailsHtml}</td>
                                   <td>${actionHtml}</td>
                              </tr>`;
                    });
                    activityTableBody.innerHTML = html;
               }

               function updatePagination(currentPage, totalPages) {
                    if (!activityPagination) return;
                    if (totalPages <= 1) {
                         activityPagination.closest('nav').style.display = 'none';
                         return;
                    }
                    activityPagination.closest('nav').style.display = 'block';

                    let html = '';
                    // Previous
                    html += `
                         <li class="page-item ${currentPage <= 1 ? 'disabled' : ''}">
                              <a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a>
                         </li>`;

                    // Pages
                    for (let i = 1; i <= totalPages; i++) {
                         html += `
                              <li class="page-item ${i === currentPage ? 'active' : ''}">
                                   <a class="page-link" href="#" data-page="${i}">${i}</a>
                         </li>`;
                    }

                    // Next
                    html += `
                         <li class="page-item ${currentPage >= totalPages ? 'disabled' : ''}">
                              <a class="page-link" href="#" data-page="${currentPage + 1}">Next</a>
                         </li>`;

                    activityPagination.innerHTML = html;
               }

               function updateFooterText(offset, perPage, total) {
                    const textMuted = activityFooter.querySelector('.text-muted');
                    if (textMuted) {
                         const start = total > 0 ? offset + 1 : 0;
                         const end = Math.min(offset + perPage, total);
                         textMuted.textContent = `Showing ${start} to ${end} of ${total} activities`;
                    }
               }

               // Helpers for JS rendering
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

               function capitalizeFirstLetter(string) {
                    return string.charAt(0).toUpperCase() + string.slice(1);
               }

               // Listeners
               activityFooter.addEventListener('click', function(e) {
                    if (e.target.classList.contains('page-link')) {
                         e.preventDefault();
                         const page = parseInt(e.target.dataset.page);
                         if (!isNaN(page) && page > 0) {
                              loadActivityPage(page);
                         }
                    }
               });

               window.addEventListener('popstate', function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const page = parseInt(urlParams.get('activity_page')) || 1;
                    if (page !== currentActivityPage) {
                         loadActivityPage(page);
                    }
               });
          })();
     </script>
</body>


<!-- Mirrored from techzaa.in/larkon/admin/customer-detail.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:20:04 GMT -->

</html>
<?php
$conn->close();
?>