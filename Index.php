<?php
include 'db.php';

// AJAX Responder for Order Alerts (Without separate API file)
if (isset($_GET['ajax_check_orders'])) {
    header('Content-Type: application/json');
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'DB Connection Error']);
        exit;
    }

    if ($last_id == 0) {
        $max_id = $conn->query("SELECT MAX(id) FROM orders")->fetch_row()[0] ?? 0;
        echo json_encode(['success' => true, 'new_orders' => [], 'latest_id' => $max_id]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT o.id, o.order_number, o.created_at, o.total_amount, o.status,
               u.first_name, u.last_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id > ?
        ORDER BY o.id ASC
    ");
    $stmt->bind_param("i", $last_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $new_orders = [];
    $latest_id = $last_id;
    while ($row = $result->fetch_assoc()) {
        $row['customer_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if (empty($row['customer_name'])) $row['customer_name'] = 'Guest';
        $row['formatted_amount'] = '₹' . number_format($row['total_amount'], 2);
        $new_orders[] = $row;
        $latest_id = $row['id'];
    }
    echo json_encode(['success' => true, 'new_orders' => $new_orders, 'latest_id' => $latest_id]);
    exit;
}

// Fetch stats
$totalOrders = 0;
$totalCustomers = 0;
$totalProducts = 0;
$totalRevenue = 0;

if ($conn) {
    $totalOrders = $conn->query("SELECT COUNT(*) FROM orders")->fetch_row()[0] ?? 0;
    $totalCustomers = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0;
    $totalProducts = $conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0] ?? 0;
    $totalRevenue = $conn->query("SELECT SUM(total_amount) FROM orders WHERE payment_status = 'paid'")->fetch_row()[0] ?? 0;

    // Helper for percentage change
    function getPercentageChange($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return (($current - $previous) / $previous) * 100;
    }

    // Orders Growth (Last Week)
    $ordersLastWeek = $conn->query("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)")->fetch_row()[0] ?? 0;
    $ordersPrevWeek = $conn->query("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 WEEK) AND created_at < DATE_SUB(NOW(), INTERVAL 1 WEEK)")->fetch_row()[0] ?? 0;
    $ordersChange = getPercentageChange($ordersLastWeek, $ordersPrevWeek);

    // Customers Growth (Last Month)
    $customersLastMonth = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $customersPrevMonth = $conn->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $customersChange = getPercentageChange($customersLastMonth, $customersPrevMonth);

    // Products Growth (Last Month)
    $productsLastMonth = $conn->query("SELECT COUNT(*) FROM products WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $productsPrevMonth = $conn->query("SELECT COUNT(*) FROM products WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $productsChange = getPercentageChange($productsLastMonth, $productsPrevMonth);

    // Revenue Growth (Last Month)
    $revenueLastMonth = $conn->query("SELECT SUM(total_amount) FROM orders WHERE payment_status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $revenuePrevMonth = $conn->query("SELECT SUM(total_amount) FROM orders WHERE payment_status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)")->fetch_row()[0] ?? 0;
    $revenueChange = getPercentageChange($revenueLastMonth, $revenuePrevMonth);

    // --- Chart Data Fetching ---

    // 1. Monthly Orders & Revenue (Current Year)
    $monthlyOrders = array_fill(1, 12, 0); // Jan to Dec initialized to 0
    $monthlyRevenue = array_fill(1, 12, 0);

    // Fetch Monthly Orders
    $ordersQuery = $conn->query("
        SELECT MONTH(created_at) as month, COUNT(*) as count 
        FROM orders 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        GROUP BY MONTH(created_at)
    ");
    while ($row = $ordersQuery->fetch_assoc()) {
        $monthlyOrders[$row['month']] = (int)$row['count'];
    }

    // Fetch Monthly Revenue
    $revenueQuery = $conn->query("
        SELECT MONTH(created_at) as month, SUM(total_amount) as total 
        FROM orders 
        WHERE YEAR(created_at) = YEAR(CURDATE()) AND payment_status = 'paid' 
        GROUP BY MONTH(created_at)
    ");
    while ($row = $revenueQuery->fetch_assoc()) {
        $monthlyRevenue[$row['month']] = (float)$row['total'];
    }

    // 2. Customer Retention Rate (Repeat Purchase Rate)
    // Overall Retention Rate
    $totalBuyers = $conn->query("SELECT COUNT(DISTINCT user_id) FROM orders")->fetch_row()[0] ?? 0;
    $repeatBuyers = $conn->query("SELECT COUNT(*) FROM (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*) > 1) as t")->fetch_row()[0] ?? 0;
    $retentionRate = ($totalBuyers > 0) ? ($repeatBuyers / $totalBuyers) * 100 : 0;

    // This Week Retention (Buyers this week who have > 1 order total)
    $buyersThisWeek = $conn->query("SELECT COUNT(DISTINCT user_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)")->fetch_row()[0] ?? 0;
    $returningBuyersThisWeek = $conn->query("SELECT COUNT(DISTINCT user_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) AND user_id IN (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*) > 1)")->fetch_row()[0] ?? 0;
    $retentionThisWeek = ($buyersThisWeek > 0) ? ($returningBuyersThisWeek / $buyersThisWeek) * 100 : 0;

    // Last Week Retention (Buyers last week who have > 1 order total)
    $buyersLastWeek = $conn->query("SELECT COUNT(DISTINCT user_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 WEEK) AND created_at < DATE_SUB(NOW(), INTERVAL 1 WEEK)")->fetch_row()[0] ?? 0;
    $returningBuyersLastWeek = $conn->query("SELECT COUNT(DISTINCT user_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 WEEK) AND created_at < DATE_SUB(NOW(), INTERVAL 1 WEEK) AND user_id IN (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*) > 1)")->fetch_row()[0] ?? 0;
    $retentionLastWeek = ($buyersLastWeek > 0) ? ($returningBuyersLastWeek / $buyersLastWeek) * 100 : 0;

    // --- Filter Data Fetching (1M, 6M, ALL) ---

    // 1M: Daily data (Last 30 Days)
    $dailyOrders = array_fill(1, 30, 0); // Simplified day index or just list
    $dailyRevenue = array_fill(1, 30, 0);
    // Note: For simplicity in JS, we'll pass arrays of {x: 'Day', y: val} or just values and fixed categories.
    // Let's use fixed last 30 days labels.

    $data1M_Orders = [];
    $data1M_Revenue = [];
    $labels1M = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $labels1M[] = date('jS M', strtotime($date));
        $data1M_Orders[] = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = '$date'")->fetch_row()[0] ?? 0;
        $data1M_Revenue[] = $conn->query("SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) = '$date' AND payment_status = 'paid'")->fetch_row()[0] ?? 0;
    }

    // 6M: Monthly data (Last 6 Months)
    $data6M_Orders = [];
    $data6M_Revenue = [];
    $labels6M = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $labels6M[] = date('M Y', strtotime($monthStart));
        
        $data6M_Orders[] = $conn->query("SELECT COUNT(*) FROM orders WHERE created_at >= '$monthStart' AND created_at <= '$monthEnd'")->fetch_row()[0] ?? 0;
        $data6M_Revenue[] = $conn->query("SELECT SUM(total_amount) FROM orders WHERE created_at >= '$monthStart' AND created_at <= '$monthEnd' AND payment_status = 'paid'")->fetch_row()[0] ?? 0;
    }

    // ALL: Yearly data (Last 5 Years)
    $dataALL_Orders = [];
    $dataALL_Revenue = [];
    $labelsALL = [];
    for ($i = 4; $i >= 0; $i--) {
        $year = date('Y', strtotime("-$i years"));
        $labelsALL[] = $year;
        
        $dataALL_Orders[] = $conn->query("SELECT COUNT(*) FROM orders WHERE YEAR(created_at) = '$year'")->fetch_row()[0] ?? 0;
        $dataALL_Revenue[] = $conn->query("SELECT SUM(total_amount) FROM orders WHERE YEAR(created_at) = '$year' AND payment_status = 'paid'")->fetch_row()[0] ?? 0;
    }

    // --- Helper Functions for Recent Orders Table ---
    if (!function_exists('e')) {
        function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    }
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
    if (!function_exists('formatCurrency')) {
        function formatCurrency($amount) {
            if ($amount === null) return '₹0.00';
            return '₹' . number_format($amount, 2);
        }
    }
    if (!function_exists('getInitials')) {
        function getInitials($firstName, $lastName) {
            $initials = '';
            if (!empty($firstName)) $initials .= strtoupper(substr($firstName, 0, 1));
            if (!empty($lastName)) $initials .= strtoupper(substr($lastName, 0, 1));
            if (empty($initials) && !empty($firstName)) $initials = strtoupper(substr($firstName, 0, 1));
            if (empty($initials)) $initials = 'U';
            return $initials;
        }
    }
    if (!function_exists('getColorFromName')) {
        function getColorFromName($name) {
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
    }

    // --- Recent Orders Fetching ---
    $recentOrdersQuery = "
        SELECT o.*, 
               u.first_name, u.last_name, u.email, u.phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ";
    $recentOrdersResult = $conn->query($recentOrdersQuery);
    $recentOrders = [];
    if($recentOrdersResult) {
        while($order = $recentOrdersResult->fetch_assoc()) {
             // Get item count
             $orderId = $order['id'];
             $itemCountResult = $conn->query("SELECT COUNT(*) as item_count FROM order_items WHERE order_id = $orderId");
             $order['item_count'] = $itemCountResult ? $itemCountResult->fetch_assoc()['item_count'] : 0;
             $recentOrders[] = $order;
        }
    }

    // --- State-wise Orders ---
    $stateMapping = [
        'Andaman and Nicobar Islands' => 'IN-AN',
        'Andhra Pradesh' => 'IN-AP',
        'Arunachal Pradesh' => 'IN-AR',
        'Assam' => 'IN-AS',
        'Bihar' => 'IN-BR',
        'Chandigarh' => 'IN-CH',
        'Chhattisgarh' => 'IN-CT',
        'Dadra and Nagar Haveli and Daman and Diu' => 'IN-DN', // or separately
        'Daman and Diu' => 'IN-DD',
        'Delhi' => 'IN-DL',
        'Goa' => 'IN-GA',
        'Gujarat' => 'IN-GJ',
        'Haryana' => 'IN-HR',
        'Himachal Pradesh' => 'IN-HP',
        'Jammu and Kashmir' => 'IN-JK',
        'Jharkhand' => 'IN-JH',
        'Karnataka' => 'IN-KA',
        'Kerala' => 'IN-KL',
        'Ladakh' => 'IN-LA',
        'Lakshadweep' => 'IN-LD',
        'Madhya Pradesh' => 'IN-MP',
        'Maharashtra' => 'IN-MH',
        'Manipur' => 'IN-MN',
        'Meghalaya' => 'IN-ML',
        'Mizoram' => 'IN-MZ',
        'Nagaland' => 'IN-NL',
        'Odisha' => 'IN-OR',
        'Puducherry' => 'IN-PY',
        'Punjab' => 'IN-PB',
        'Rajasthan' => 'IN-RJ',
        'Sikkim' => 'IN-SK',
        'Tamil Nadu' => 'IN-TN',
        'Telangana' => 'IN-AP', // Map to AP as TG is missing in in-mill SVG
        'Tripura' => 'IN-TR',
        'Uttar Pradesh' => 'IN-UP',
        'Uttarakhand' => 'IN-UT',
        'West Bengal' => 'IN-WB'
    ];

    $stateOrders = [];
    $stateQuery = $conn->query("
        SELECT a.state, COUNT(o.id) as count
        FROM orders o
        JOIN addresses a ON o.shipping_address_id = a.id
        GROUP BY a.state
    ");
    if ($stateQuery) {
        while ($row = $stateQuery->fetch_assoc()) {
            $stateName = $row['state'];
            $count = (int)$row['count'];
            if (isset($stateMapping[$stateName])) {
                $stateOrders[$stateMapping[$stateName]] = $count;
            }
        }
    }

    // --- Detailed State Orders for Modal (Client-Side) ---
    $detailedStateOrders = [];
    $detailedQuery = $conn->query("
        SELECT o.id, o.order_number, o.created_at, o.total_amount, o.status, o.payment_status,
               u.first_name, u.last_name, u.email,
               a.state
        FROM orders o
        JOIN addresses a ON o.shipping_address_id = a.id
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
    ");
    
    if ($detailedQuery) {
        while ($row = $detailedQuery->fetch_assoc()) {
            $stateName = $row['state'];
            $stateCode = isset($stateMapping[$stateName]) ? $stateMapping[$stateName] : null; // Map to code

            if ($stateCode) {
                 // Fetch item count (keeping it simple inside loop for now, optimization possible)
                 $orderId = $row['id'];
                 $itemCount = $conn->query("SELECT COUNT(*) FROM order_items WHERE order_id = $orderId")->fetch_row()[0] ?? 0;
                 
                 // Process Data
                 $customerName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                 if (empty($customerName)) $customerName = 'Guest/Unknown';
                 
                 // Initials
                 $initials = '';
                 if (!empty($row['first_name'])) $initials .= strtoupper(substr($row['first_name'], 0, 1));
                 if (!empty($row['last_name'])) $initials .= strtoupper(substr($row['last_name'], 0, 1));
                 if (empty($initials) && !empty($row['first_name'])) $initials = strtoupper(substr($row['first_name'], 0, 1));
                 if (empty($initials)) $initials = 'U';

                 // Color
                 $colors = ['#4A6FA5', '#166088', '#2D3047', '#419D78', '#E0A458', '#C04ABC', '#DB5461', '#686963', '#3D5A80', '#98C1D9', '#EE6C4D', '#293241', '#6B2737', '#E43F6F', '#1F2041', '#FFC857'];
                 $hash = 0;
                 for ($i = 0; $i < strlen($customerName); $i++) { $hash = ord($customerName[$i]) + (($hash << 5) - $hash); }
                 $avatarColor = $colors[abs($hash) % count($colors)];

                 $orderData = [
                     'id' => $row['id'],
                     'order_number' => $row['order_number'],
                     'formatted_date' => date('M d, Y', strtotime($row['created_at'])),
                     'customer_name' => $customerName,
                     'initials' => $initials,
                     'avatar_color' => $avatarColor,
                     'user_id' => $row['user_id'] ?? null, // careful with nulls
                     'formatted_amount' => '₹' . number_format($row['total_amount'], 2),
                     'payment_status' => $row['payment_status'],
                     'status' => $row['status'],
                     'item_count' => $itemCount
                 ];

                 if (!isset($detailedStateOrders[$stateCode])) {
                     $detailedStateOrders[$stateCode] = [];
                 }
                 $detailedStateOrders[$stateCode][] = $orderData;
            }
        }
    }

}
?>

<!DOCTYPE html>
<html lang="en">


<!-- Mirrored from techzaa.in/larkon/admin/index.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:18:38 GMT -->
<head>
     <!-- Title Meta -->
     <meta charset="utf-8" />
     <title>Dashboard | Larkon - Responsive Admin Dashboard Template</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <meta name="description" content="A fully responsive premium admin dashboard template" />
     <meta name="author" content="Techzaa" />
     <meta http-equiv="X-UA-Compatible" content="IE=edge" />

     <!-- App favicon -->
     <link rel="shortcut icon" href="/assets/images/favicon.ico">

     <!-- Vendor css (Require in all Page) -->
     <link href="/assets/css/vendor.min.css" rel="stylesheet" type="text/css" />

     <!-- Icons css (Require in all Page) -->
     <link href="/assets/css/icons.min.css" rel="stylesheet" type="text/css" />

     <!-- App css (Require in all Page) -->
     <link href="/assets/css/app.min.css" rel="stylesheet" type="text/css" />

     <style>
          /* Enhanced jsVectorMap tooltip styling */
          .jvm-tooltip {
               display: none;
               position: absolute;
               pointer-events: none;
               background: #ffffff !important;
               color: #1a1a1a !important;
               border-radius: 8px !important;
               padding: 0 !important; /* Managed by inner div */
               z-index: 999999;
               box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
               border: 1px solid #e5e7eb !important;
               min-width: 140px;
          }
          .jvm-tooltip.active {
               display: block !important;
          }
     </style>
     

     <!-- Theme Config js (Require in all Page) -->
     <script>
        window.dynamicChartData = {
            performance: {
                // Default 1Y (Current Year Monthly)
                orders: <?php echo json_encode(array_values($monthlyOrders)); ?>,
                revenue: <?php echo json_encode(array_values($monthlyRevenue)); ?>,
                
                // Filter Data
                filter1M: {
                    orders: <?php echo json_encode($data1M_Orders); ?>,
                    revenue: <?php echo json_encode($data1M_Revenue); ?>,
                    labels: <?php echo json_encode($labels1M); ?>
                },
                filter6M: {
                    orders: <?php echo json_encode($data6M_Orders); ?>,
                    revenue: <?php echo json_encode($data6M_Revenue); ?>,
                    labels: <?php echo json_encode($labels6M); ?>
                },
                filterALL: {
                    orders: <?php echo json_encode($dataALL_Orders); ?>,
                    revenue: <?php echo json_encode($dataALL_Revenue); ?>,
                    labels: <?php echo json_encode($labelsALL); ?>
                }
            },
            conversions: {
                rate: <?php echo round($retentionRate, 1); ?>,
                thisWeek: <?php echo round($retentionThisWeek, 1); ?>,
                lastWeek: <?php echo round($retentionLastWeek, 1); ?>
            },
            stateOrders: <?php echo json_encode($stateOrders); ?>,
            detailedStateOrders: <?php echo json_encode($detailedStateOrders); ?>
        };
     </script>
     <script src="/assets/js/config.js"></script>
</head>

<body>

     <!-- START Wrapper -->
     <div class="wrapper">

          <!-- ========== Header Start ========== -->
          <?php include __DIR__.'/includes/header.php'; ?>


          
          <!-- ========== Topbar End ========== -->

          <!-- ========== App Menu Start ========== -->
          <?php include __DIR__.'/includes/sidebar.php';?>
          <!-- ========== App Menu End ========== -->

          <!-- ==================================================== -->
          <!-- Start right Content here -->
          <!-- ==================================================== -->
          <div class="page-content">

               <!-- Start Container Fluid -->
               <div class="container-fluid">

                    <!-- Start here.... -->
                    <div class="row">
                         <div class="col-xxl-12">
                              <div class="row">

                                   <div class="col-md-3">
                                        <div class="card overflow-hidden">
                                             <div class="card-body">
                                                  <div class="row">
                                                       <div class="col-6">
                                                            <div class="avatar-md bg-soft-primary rounded">
                                                                 <iconify-icon icon="solar:cart-5-bold-duotone" class="avatar-title fs-32 text-primary"></iconify-icon>
                                                            </div>
                                                       </div> <!-- end col -->
                                                       <div class="col-6 text-end">
                                                            <p class="text-muted mb-0 text-truncate">Total Orders</p>
                                                            <h3 class="text-dark mt-1 mb-0"><?php echo number_format($totalOrders); ?></h3>
                                                       </div> <!-- end col -->
                                                  </div> <!-- end row-->
                                             </div> <!-- end card body -->
                                             <div class="card-footer py-2 bg-light bg-opacity-50">
                                                  <div class="d-flex align-items-center justify-content-between">
                                                       <div>
                                                            <?php if ($ordersChange >= 0): ?>
                                                                 <span class="text-success"> <i class="bx bxs-up-arrow fs-12"></i> <?php echo number_format($ordersChange, 1); ?>%</span>
                                                            <?php else: ?>
                                                                 <span class="text-danger"> <i class="bx bxs-down-arrow fs-12"></i> <?php echo number_format(abs($ordersChange), 1); ?>%</span>
                                                            <?php endif; ?>
                                                            <span class="text-muted ms-1 fs-12">Last Week</span>
                                                       </div>
                                                       <a href="#!" class="text-reset fw-semibold fs-12">View More</a>
                                                  </div>
                                             </div> <!-- end card body -->
                                        </div> <!-- end card -->
                                   </div> <!-- end col -->
                                   <div class="col-md-3">
                                        <div class="card overflow-hidden">
                                             <div class="card-body">
                                                  <div class="row">
                                                       <div class="col-6">
                                                            <div class="avatar-md bg-soft-primary rounded">
                                                                 <i class="bx bx-award avatar-title fs-24 text-primary"></i>
                                                            </div>
                                                       </div> <!-- end col -->
                                                       <div class="col-6 text-end">
                                                            <p class="text-muted mb-0 text-truncate">Total Customers</p>
                                                            <h3 class="text-dark mt-1 mb-0"><?php echo number_format($totalCustomers); ?></h3>
                                                       </div> <!-- end col -->
                                                  </div> <!-- end row-->
                                             </div> <!-- end card body -->
                                             <div class="card-footer py-2 bg-light bg-opacity-50">
                                                  <div class="d-flex align-items-center justify-content-between">
                                                       <div>
                                                            <?php if ($customersChange >= 0): ?>
                                                                 <span class="text-success"> <i class="bx bxs-up-arrow fs-12"></i> <?php echo number_format($customersChange, 1); ?>%</span>
                                                            <?php else: ?>
                                                                 <span class="text-danger"> <i class="bx bxs-down-arrow fs-12"></i> <?php echo number_format(abs($customersChange), 1); ?>%</span>
                                                            <?php endif; ?>
                                                            <span class="text-muted ms-1 fs-12">Last Month</span>
                                                       </div>
                                                       <a href="#!" class="text-reset fw-semibold fs-12">View More</a>
                                                  </div>
                                             </div> <!-- end card body -->
                                        </div> <!-- end card -->
                                   </div> <!-- end col -->
                                   <div class="col-md-3">
                                        <div class="card overflow-hidden">
                                             <div class="card-body">
                                                  <div class="row">
                                                       <div class="col-6">
                                                            <div class="avatar-md bg-soft-primary rounded">
                                                                 <i class="bx bxs-backpack avatar-title fs-24 text-primary"></i>
                                                            </div>
                                                       </div> <!-- end col -->
                                                       <div class="col-6 text-end">
                                                            <p class="text-muted mb-0 text-truncate">Total Products</p>
                                                            <h3 class="text-dark mt-1 mb-0"><?php echo number_format($totalProducts); ?></h3>
                                                       </div> <!-- end col -->
                                                  </div> <!-- end row-->
                                             </div> <!-- end card body -->
                                             <div class="card-footer py-2 bg-light bg-opacity-50">
                                                  <div class="d-flex align-items-center justify-content-between">
                                                       <div>
                                                            <?php if ($productsChange >= 0): ?>
                                                                 <span class="text-success"> <i class="bx bxs-up-arrow fs-12"></i> <?php echo number_format($productsChange, 1); ?>%</span>
                                                            <?php else: ?>
                                                                 <span class="text-danger"> <i class="bx bxs-down-arrow fs-12"></i> <?php echo number_format(abs($productsChange), 1); ?>%</span>
                                                            <?php endif; ?>
                                                            <span class="text-muted ms-1 fs-12">Last Month</span>
                                                       </div>
                                                       <a href="#!" class="text-reset fw-semibold fs-12">View More</a>
                                                  </div>
                                             </div> <!-- end card body -->
                                        </div> <!-- end card -->
                                   </div> <!-- end col -->
                                   <div class="col-md-3">
                                        <div class="card overflow-hidden">
                                             <div class="card-body">
                                                  <div class="row">
                                                       <div class="col-6">
                                                            <div class="avatar-md bg-soft-primary rounded">
                                                                 <i class="bx bx-dollar-circle avatar-title text-primary fs-24"></i>
                                                            </div>
                                                       </div> <!-- end col -->
                                                        <div class="col-6 text-end">
                                                             <p class="text-muted mb-0 text-truncate">Total Revenue</p>
                                                             <?php
                                                             $revText = "₹" . number_format($totalRevenue, 2);
                                                             $revLen = strlen($revText);
                                                             $fsClass = "fs-24"; // default
                                                             if ($revLen > 9) $fsClass = "fs-16"; 
                                                             elseif ($revLen > 7) $fsClass = "fs-18";
                                                             elseif ($revLen > 5) $fsClass = "fs-20";
                                                             ?>
                                                            <h3 class="text-dark mt-1 mb-0 <?php echo $fsClass; ?> "><?php echo $revText; ?></h3>
                                                        </div> <!-- end col -->
                                                  </div> <!-- end row-->
                                             </div> <!-- end card body -->
                                             <div class="card-footer py-2 bg-light bg-opacity-50">
                                                  <div class="d-flex align-items-center justify-content-between">
                                                       <div>
                                                            <?php if ($revenueChange >= 0): ?>
                                                                 <span class="text-success"> <i class="bx bxs-up-arrow fs-12"></i> <?php echo number_format($revenueChange, 1); ?>%</span>
                                                            <?php else: ?>
                                                                 <span class="text-danger"> <i class="bx bxs-down-arrow fs-12"></i> <?php echo number_format(abs($revenueChange), 1); ?>%</span>
                                                            <?php endif; ?>
                                                            <span class="text-muted ms-1 fs-12">Last Month</span>
                                                       </div>
                                                       <a href="#!" class="text-reset fw-semibold fs-12">View More</a>
                                                  </div>
                                             </div> <!-- end card body -->
                                        </div> <!-- end card -->
                                   </div> <!-- end col -->
                              </div> <!-- end row -->
                         </div> <!-- end col -->

                         
                    </div> <!-- end row -->

                    <div class="row">
                         <div class="col-xxl-12">
                              <div class="card">
                                   <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                             <h4 class="card-title">Performance</h4>
                                             <div>
                                                  <button type="button" class="btn btn-sm btn-outline-light chart-filter" data-filter="ALL">ALL</button>
                                                  <button type="button" class="btn btn-sm btn-outline-light chart-filter" data-filter="1M">1M</button>
                                                  <button type="button" class="btn btn-sm btn-outline-light chart-filter" data-filter="6M">6M</button>
                                                  <button type="button" class="btn btn-sm btn-outline-light active chart-filter" data-filter="1Y">1Y</button>
                                             </div>
                                        </div> <!-- end card-title-->

                                        <div dir="ltr">
                                             <div id="dash-performance-chart" class="apex-charts"></div>
                                        </div>
                                   </div> <!-- end card body -->
                              </div> <!-- end card -->
                         </div> <!-- end col -->
                    </div> <!-- end row -->

                    <div class="row">
                         <div class="col-lg-4">
                              <div class="card">
                                   <div class="card-body">
                                        <h5 class="card-title">Customer Retention</h5>
                                        <div id="conversions" class="apex-charts mb-2 mt-n2"></div>
                                        <div class="row text-center">
                                             <div class="col-6">
                                                  <p class="text-muted mb-2">This Week</p>
                                                  <h3 class="text-dark mb-3"><?php echo round($retentionThisWeek, 1); ?>%</h3>
                                             </div> <!-- end col -->
                                             <div class="col-6">
                                                  <p class="text-muted mb-2">Last Week</p>
                                                  <h3 class="text-dark mb-3"><?php echo round($retentionLastWeek, 1); ?>%</h3>
                                             </div> <!-- end col -->
                                        </div> <!-- end row -->
                                        <div class="text-center">
                                             <button type="button" class="btn btn-light shadow-none w-100">View Details</button>
                                        </div> <!-- end row -->
                                   </div>
                              </div>
                         </div> <!-- end col -->

                         <div class="col-lg-8">
                              <div class="card">
                                   <div class="card-header d-flex justify-content-between align-items-center">
                                        <h4 class="card-title">Orders by State</h4>
                                   </div>
                                   <div class="card-body">
                                        <div id="india-map-markers" style="height: 400px;"></div>
                                   </div> <!-- end card body -->
                              </div> <!-- end card -->
                         </div> <!-- end col -->
                    </div> <!-- end row -->

                    <div class="row">
                         <div class="col-lg">
                              <div class="card">
                                   <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between">
                                             <h4 class="card-title">
                                                  Recent Orders
                                             </h4>

                                             <a href="#!" class="btn btn-sm btn-soft-primary">
                                                  <i class="bx bx-plus me-1"></i>Create Order
                                             </a>
                                        </div>
                                   </div>
                                    <!-- end card body -->
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
                                              <!-- end thead-->
                                              <tbody>
                                                   <?php foreach ($recentOrders as $order): 
                                                        $orderId = $order['id'];
                                                        $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                                                        if (empty($customerName)) $customerName = 'Unknown Customer';
                                                        
                                                        $initials = getInitials($order['first_name'] ?? '', $order['last_name'] ?? '');
                                                        $color = getColorFromName($customerName);
                                                        $createdDate = date('M d, Y', strtotime($order['created_at']));
                                                        $orderNumber = $order['order_number'] ?: 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
                                                        
                                                        $statusBadgeClass = getStatusBadgeClass($order['status']);
                                                        $paymentStatusBadgeClass = getPaymentStatusBadgeClass($order['payment_status']);
                                                        $totalAmount = formatCurrency($order['total_amount']);
                                                   ?>
                                                   <tr>
                                                        <td>
                                                             <a href="order/detail-order.php?id=<?php echo e($orderId); ?>" class="link-primary fw-medium">#<?php echo e($orderNumber); ?></a>
                                                        </td>
                                                        <td><?php echo e($createdDate); ?></td>
                                                        <td>
                                                             <div class="d-flex align-items-center gap-2">
                                                                  <div class="avatar-sm flex-shrink-0">
                                                                       <span class="avatar-title rounded-circle fs-14 text-white" style="background-color: <?php echo e($color); ?>; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                                            <?php echo e($initials); ?>
                                                                       </span>
                                                                  </div>
                                                                  <a href="customers/detail-customer.php?id=<?php echo e($order['user_id']); ?>" class="link-primary fw-medium"><?php echo e($customerName); ?></a>
                                                             </div>
                                                        </td>
                                                        <td><?php echo e($totalAmount); ?></td>
                                                        <td>
                                                             <span class="badge <?php echo e($paymentStatusBadgeClass); ?> px-2 py-1 fs-13">
                                                                  <?php echo ucfirst($order['payment_status']); ?>
                                                             </span>
                                                        </td>
                                                        <td><?php echo e($order['item_count']); ?></td>
                                                        <td>
                                                             <span class="badge <?php echo e($statusBadgeClass); ?> px-2 py-1 fs-13">
                                                                  <?php echo ucfirst($order['status']); ?>
                                                             </span>
                                                        </td>
                                                        <td>
                                                             <a href="order/detail-order.php?id=<?php echo e($orderId); ?>" class="btn btn-light btn-sm"><iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon></a>
                                                        </td>
                                                   </tr>
                                                   <?php endforeach; ?>
                                                   <?php if (empty($recentOrders)): ?>
                                                   <tr>
                                                       <td colspan="8" class="text-center text-muted py-4">No recent orders found.</td>
                                                   </tr>
                                                   <?php endif; ?>
                                              </tbody>
                                              <!-- end tbody -->
                                         </table>
                                         <!-- end table -->
                                    </div>
                                    <!-- table responsive -->

                                    <div class="card-footer border-top">
                                         <div class="row g-3">
                                              <div class="col-sm">
                                                   <div class="text-muted">
                                                        Showing
                                                        <span class="fw-semibold"><?php echo count($recentOrders); ?></span>
                                                        of
                                                        <span class="fw-semibold"><?php echo number_format($totalOrders); ?></span>
                                                        orders
                                                   </div>
                                              </div> <!-- end col -->
                                              <div class="col-sm-auto">
                                                   <ul class="pagination pagination-rounded justify-content-center justify-content-sm-end mb-0">
                                                        <li class="page-item disabled">
                                                             <a href="#" class="page-link"><i class="bx bx-chevron-left"></i></a>
                                                        </li>
                                                        <li class="page-item active">
                                                             <a href="#" class="page-link">1</a>
                                                        </li>
                                                        <li class="page-item">
                                                             <a href="#" class="page-link">2</a>
                                                        </li>
                                                        <li class="page-item">
                                                             <a href="#" class="page-link">3</a>
                                                        </li>
                                                        <li class="page-item">
                                                             <a href="#" class="page-link"><i class="bx bx-chevron-right"></i></a>
                                                        </li>
                                                   </ul>
                                              </div> <!-- end col -->
                                         </div> <!-- end row -->
                                    </div> <!-- end card footer -->
                               </div> <!-- end card -->
                          </div> <!-- end col -->
                     </div> <!-- end row -->
               </div>
               <!-- End Container Fluid -->

               <!-- State Orders Modal -->
               <div class="modal fade" id="stateOrdersModal" tabindex="-1" aria-labelledby="stateOrdersModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="stateOrdersModalLabel">Orders from <span id="modalStateName"></span></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0">
                                <div class="table-responsive">
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
                                        <tbody id="stateOrdersTableBody">
                                            <!-- Rows loaded via AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                                <div id="stateOrdersLoading" class="text-center py-4" style="display: none;">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                <div id="stateOrdersEmpty" class="text-center py-4" style="display: none;">
                                    <p class="text-muted mb-0">No orders found for this state.</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
     <script src="/assets/js/vendor.js"></script>

     <!-- App Javascript (Require in all Page) -->
     <script src="/assets/js/app.js"></script>

     <!-- Vector Map Js -->
     <script src="/assets/vendor/jsvectormap/js/jsvectormap.min.js"></script>
     <script src="/assets/vendor/jsvectormap/maps/world-merc.js"></script>
      <script src="/assets/vendor/jsvectormap/maps/world.js"></script>
      <script src="/assets/vendor/jsvectormap/maps/india-jvector.js"></script>

     <!-- Dashboard Js -->
     <script src="/assets/js/pages/dashboard.js"></script>

</body>


<!-- Mirrored from techzaa.in/larkon/admin/index.html by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 29 Nov 2025 04:19:31 GMT -->
</html>