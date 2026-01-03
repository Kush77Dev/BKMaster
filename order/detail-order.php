<?php
// detail-order.php
// Shows detailed information about a specific order including items.

// ----- EDIT THESE DB CREDENTIALS IF NEEDED -----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';
// ----------------------------------------------

// Handle status update if POST request - DO THIS FIRST!
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    // Suppress warnings for clean JSON output
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');

    // Create connection for this request
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }

    $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
    $sendEmail = isset($_POST['send_email']) ? ($_POST['send_email'] == '1') : true;
    $emailMessage = isset($_POST['email_message']) ? trim($_POST['email_message']) : '';

    // Validate
    if ($orderId <= 0 || empty($newStatus)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        $conn->close();
        exit;
    }

    // Validate status
    $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
    if (!in_array($newStatus, $allowedStatuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        $conn->close();
        exit;
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Get current order details
        $stmt = $conn->prepare("
            SELECT o.*, u.email, u.first_name, u.last_name, o.order_number 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE o.id = ?
        ");

        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }

        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("Order not found");
        }

        $orderData = $result->fetch_assoc();
        $oldStatus = $orderData['status'];
        $orderNumber = $orderData['order_number'] ?: 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
        $customerEmail = $orderData['email'];
        $customerName = trim(($orderData['first_name'] ?? '') . ' ' . ($orderData['last_name'] ?? ''));
        $stmt->close();

        // Update order status in existing orders table
        $updateStmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
        if (!$updateStmt) {
            throw new Exception("Update failed: " . $conn->error);
        }

        $updateStmt->bind_param('si', $newStatus, $orderId);
        if (!$updateStmt->execute()) {
            throw new Exception("Update failed: " . $updateStmt->error);
        }
        $updateStmt->close();

        // Send email notification if requested
        $emailSent = false;
        if ($sendEmail && !empty($customerEmail) && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $emailSent = sendStatusUpdateEmail(
                $customerEmail,
                $customerName,
                $orderNumber,
                $oldStatus,
                $newStatus,
                $emailMessage
            );
        }

        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully',
            'orderId' => $orderId,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'emailSent' => $emailSent
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    $conn->close();
    exit; // Stop further execution
}

// Rest of the normal page rendering code...
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die("DB connect error: " . $conn->connect_error);
}

$error = "";
$order = null;
$orderItems = [];
$user = null;

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
        'paid' => 'bg-success-subtle text-success',
        'unpaid' => 'bg-light text-dark',
        'partial' => 'bg-warning-subtle text-warning',
        'refunded' => 'bg-info-subtle text-info',
        'failed' => 'bg-danger-subtle text-danger'
    ];

    return $classes[strtolower($status)] ?? 'bg-light text-dark';
}

// Helper: format currency
function formatCurrency($amount)
{
    if ($amount === null || $amount === '') return '₹0.00';
    return '₹' . number_format(floatval($amount), 2);
}

// Helper: format date
function formatDate($dateString, $format = 'M d, Y')
{
    if (empty($dateString)) return '';
    return date($format, strtotime($dateString));
}

// Helper: format datetime
function formatDateTime($dateString)
{
    if (empty($dateString)) return '';
    return date('M d, Y \a\t h:i A', strtotime($dateString));
}

// Helper: parse sizes JSON
function parseSizes($sizesJson)
{
    if (empty($sizesJson)) return '';
    $sizes = json_decode($sizesJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($sizes)) {
        return implode(', ', $sizes);
    }
    return $sizesJson;
}

// Helper: get first product image
function getFirstProductImage($conn, $productId, $variantId = null)
{
    $imageUrl = '';

    // Try to get variant-specific image first
    if ($variantId) {
        $stmt = $conn->prepare("
            SELECT url FROM product_images 
            WHERE product_id = ? AND (variant_id = ? OR variant_id IS NULL) 
            ORDER BY variant_id DESC, position ASC 
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $productId, $variantId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $imageUrl = $row['url'];
            }
            $stmt->close();
        }
    }

    // If no variant image, get any product image
    if (empty($imageUrl)) {
        $stmt = $conn->prepare("
            SELECT url FROM product_images 
            WHERE product_id = ? 
            ORDER BY position ASC 
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $imageUrl = $row['url'];
            }
            $stmt->close();
        }
    }

    return $imageUrl;
}

// Helper: check if addresses are the same
function addressesAreSame($shipping, $billing)
{
    if (empty($shipping['line1']) || empty($billing['line1'])) return false;

    return
        $shipping['line1'] === $billing['line1'] &&
        $shipping['line2'] === $billing['line2'] &&
        $shipping['city'] === $billing['city'] &&
        $shipping['state'] === $billing['state'] &&
        $shipping['pincode'] === $billing['pincode'] &&
        $shipping['country'] === $billing['country'];
}

function getTimelineSteps($currentStatus)
    {
        $steps = [
            ['status' => 'pending', 'label' => 'Ordered', 'icon' => '📋'],
            ['status' => 'processing', 'label' => 'Processing', 'icon' => '⚙️'],
            ['status' => 'shipped', 'label' => 'Shipped', 'icon' => '🚚'],
            ['status' => 'delivered', 'label' => 'Delivered', 'icon' => '📦']
        ];

        $html = '';
        $statusOrder = ['pending', 'processing', 'shipped', 'delivered'];
        $currentIndex = array_search($currentStatus, $statusOrder);

        if ($currentIndex === false) return '';

        foreach ($steps as $index => $step) {
            $statusClass = '';
            if ($index < $currentIndex) {
                $statusClass = 'step-completed';
            } elseif ($index == $currentIndex) {
                $statusClass = 'step-active';
            }

            $html .= "
            <div class='timeline-step'>
                <div class='step-icon $statusClass'>" . $step['icon'] . "</div>
                <div class='step-label'>" . $step['label'] . "</div>
            </div>
            ";
        }

        return $html;
    }

// Function to send status update email (SIMPLIFIED VERSION THAT WORKS)
function sendStatusUpdateEmail($toEmail, $customerName, $orderNumber, $oldStatus, $newStatus, $customMessage = '')
{
    // Email configuration
    $fromEmail = 'unknowndevloper77@gmail.com';
    $fromName = 'BKMaster';

    // Enhanced status information with detailed descriptions
    $statusDetails = [
        'pending' => [
            'label' => 'Order Received',
            'description' => 'Your order has been successfully placed and is awaiting confirmation.',
            'icon' => '📋',
            'progress' => 25
        ],
        'processing' => [
            'label' => 'Order Processing',
            'description' => 'We\'re preparing your items and getting them ready for shipment.',
            'icon' => '⚙️',
            'progress' => 50
        ],
        'shipped' => [
            'label' => 'Order Shipped',
            'description' => 'Your order is on its way! Tracking information will be available soon.',
            'icon' => '🚚',
            'progress' => 75
        ],
        'delivered' => [
            'label' => 'Order Delivered',
            'description' => 'Your order has been successfully delivered. Thank you for shopping with us!',
            'icon' => '📦',
            'progress' => 100
        ],
        'cancelled' => [
            'label' => 'Order Cancelled',
            'description' => 'This order has been cancelled as requested.',
            'icon' => '❌',
            'progress' => 0
        ]
    ];

    $currentStatus = $statusDetails[$newStatus] ?? [
        'label' => ucfirst($newStatus),
        'description' => 'Your order status has been updated.',
        'icon' => '📝',
        'progress' => 50
    ];

    // Email subject
    $subject = "Order Update #$orderNumber: " . $currentStatus['label'];

    // Create professional email body (HTML)
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Order Status Update</title>
        <style>
            /* Professional Color Palette */
            :root {
                --primary: #1a1a1a;
                --primary-light: #333333;
                --secondary: #d4af37;
                --success: #10b981;
                --info: #3b82f6;
                --warning: #f59e0b;
                --light: #f8f9fa;
                --gray: #6b7280;
                --gray-light: #e5e7eb;
                --white: #ffffff;
            }
            
            /* Base Reset */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
                background-color: #f9fafb;
                color: #1f2937;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
            
            /* Container */
            .email-container {
                max-width: 640px;
                margin: 0 auto;
                background-color: var(--white);
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            }
            
            /* Header */
            .email-header {
                background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
                padding: 40px 30px;
                text-align: center;
                border-bottom: 3px solid var(--secondary);
            }
            
            .logo {
                color: var(--white);
                font-size: 28px;
                font-weight: 700;
                letter-spacing: 1.5px;
                text-decoration: none;
                display: inline-block;
                margin-bottom: 20px;
            }
            
            .header-tagline {
                color: rgba(255, 255, 255, 0.9);
                font-size: 14px;
                font-weight: 400;
                letter-spacing: 0.5px;
            }
            
            /* Content */
            .email-content {
                padding: 50px 40px;
            }
            
            .greeting {
                font-size: 18px;
                color: var(--gray);
                margin-bottom: 25px;
                font-weight: 500;
            }
            
            .main-heading {
                font-size: 32px;
                font-weight: 700;
                color: var(--primary);
                margin-bottom: 15px;
                line-height: 1.2;
            }
            
            .sub-heading {
                font-size: 16px;
                color: var(--gray);
                margin-bottom: 40px;
                font-weight: 400;
            }
            
            /* Status Card */
            .status-card {
                background: linear-gradient(to right, #fefefe, #f8f9fa);
                border: 1px solid var(--gray-light);
                border-radius: 12px;
                padding: 40px;
                margin: 40px 0;
                text-align: center;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            }
            
            .status-icon {
                font-size: 48px;
                margin-bottom: 20px;
                display: block;
            }
            
            .status-label {
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 1.2px;
                color: var(--gray);
                margin-bottom: 10px;
                font-weight: 600;
            }
            
            .status-title {
                font-size: 28px;
                font-weight: 700;
                color: var(--primary);
                margin-bottom: 15px;
                line-height: 1.3;
            }
            
            .status-description {
                font-size: 16px;
                color: var(--gray);
                line-height: 1.5;
                max-width: 500px;
                margin: 0 auto;
            }
            
            /* Progress Tracker */
            .progress-tracker {
                margin: 50px 0;
            }
            
            .progress-label {
                display: flex;
                justify-content: space-between;
                margin-bottom: 15px;
                font-size: 14px;
                color: var(--gray);
                font-weight: 500;
            }
            
            .progress-bar {
                height: 8px;
                background-color: var(--gray-light);
                border-radius: 4px;
                overflow: hidden;
                margin-bottom: 30px;
            }
            
            .progress-fill {
                height: 100%;
                background: linear-gradient(to right, var(--secondary), #e6c158);
                width: " . $currentStatus['progress'] . "%;
                transition: width 0.3s ease;
            }
            
            /* Timeline */
            .timeline {
                display: flex;
                justify-content: space-between;
                position: relative;
                margin: 40px 0;
            }
            
            .timeline::before {
                content: '';
                position: absolute;
                top: 15px;
                left: 0;
                right: 0;
                height: 2px;
                background-color: var(--gray-light);
                z-index: 1;
            }
            
            .timeline-step {
                position: relative;
                z-index: 2;
                text-align: center;
                flex: 1;
            }
            
            .step-icon {
                width: 32px;
                height: 32px;
                background-color: var(--white);
                border: 2px solid var(--gray-light);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 10px;
                font-size: 14px;
                color: var(--gray);
            }
            
            .step-active {
                background-color: var(--secondary);
                border-color: var(--secondary);
                color: var(--white);
            }
            
            .step-completed {
                background-color: var(--success);
                border-color: var(--success);
                color: var(--white);
            }
            
            .step-label {
                font-size: 12px;
                color: var(--gray);
                font-weight: 500;
            }
            
            /* Order Details */
            .details-card {
                background-color: var(--light);
                border-radius: 10px;
                padding: 30px;
                margin: 40px 0;
            }
            
            .details-title {
                font-size: 18px;
                font-weight: 600;
                color: var(--primary);
                margin-bottom: 25px;
                padding-bottom: 15px;
                border-bottom: 1px solid var(--gray-light);
            }
            
            .detail-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
            }
            
            .detail-item {
                margin-bottom: 15px;
            }
            
            .detail-label {
                font-size: 13px;
                color: var(--gray);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 5px;
                font-weight: 500;
            }
            
            .detail-value {
                font-size: 16px;
                color: var(--primary);
                font-weight: 600;
            }
            
            /* Custom Message */
            .message-card {
                background-color: #f0f9ff;
                border: 1px solid #bae6fd;
                border-radius: 10px;
                padding: 25px;
                margin: 40px 0;
            }
            
            .message-title {
                font-size: 16px;
                font-weight: 600;
                color: #0369a1;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .message-content {
                font-size: 15px;
                color: #0c4a6e;
                line-height: 1.6;
            }
            
            /* Action Buttons */
            .action-buttons {
                display: flex;
                gap: 15px;
                margin: 50px 0;
                flex-wrap: wrap;
            }
            
            .btn {
                padding: 16px 32px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                font-size: 15px;
                transition: all 0.3s ease;
                display: inline-block;
                text-align: center;
                flex: 1;
                min-width: 200px;
            }
            
            .btn-primary {
                background-color: var(--primary);
                color: var(--white);
            }
            
            .btn-primary:hover {
                background-color: var(--primary-light);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            }
            
            .btn-secondary {
                background-color: var(--white);
                color: var(--primary);
                border: 2px solid var(--gray-light);
            }
            
            .btn-secondary:hover {
                border-color: var(--primary);
                transform: translateY(-2px);
            }
            
            /* Footer */
            .email-footer {
                background-color: #f8f9fa;
                padding: 40px;
                text-align: center;
                border-top: 1px solid var(--gray-light);
            }
            
            .social-links {
                display: flex;
                justify-content: center;
                gap: 20px;
                margin: 25px 0;
            }
            
            .social-icon {
                color: var(--gray);
                text-decoration: none;
                font-size: 14px;
                transition: color 0.3s ease;
            }
            
            .social-icon:hover {
                color: var(--primary);
            }
            
            .footer-links {
                font-size: 13px;
                color: var(--gray);
                margin: 20px 0;
            }
            
            .footer-links a {
                color: var(--gray);
                text-decoration: none;
                margin: 0 10px;
            }
            
            .footer-links a:hover {
                color: var(--primary);
                text-decoration: underline;
            }
            
            .copyright {
                font-size: 12px;
                color: #9ca3af;
                margin-top: 25px;
                line-height: 1.6;
            }
            
            /* Responsive */
            @media (max-width: 640px) {
                .email-content {
                    padding: 30px 20px;
                }
                
                .status-card {
                    padding: 30px 20px;
                }
                
                .action-buttons {
                    flex-direction: column;
                }
                
                .btn {
                    width: 100%;
                }
                
                .timeline {
                    flex-direction: column;
                    gap: 30px;
                }
                
                .timeline::before {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <!-- Header -->
            <div class='email-header'>
                <a href='#' class='logo'>BKMASTER</a>
                <div class='header-tagline'>Premium Quality & Exceptional Service</div>
            </div>
            
            <!-- Main Content -->
            <div class='email-content'>
                <!-- Greeting -->
                <div class='greeting'>
                    Dear " . htmlspecialchars($customerName ?: 'Valued Customer') . ",
                </div>
                
                <!-- Main Heading -->
                <h1 class='main-heading'>Order Update Available</h1>
                <p class='sub-heading'>
                    We're keeping you informed about your recent purchase with us.
                </p>
                
                <!-- Status Card -->
                <div class='status-card'>
                    <span class='status-icon'>" . $currentStatus['icon'] . "</span>
                    <div class='status-label'>Current Status</div>
                    <h2 class='status-title'>" . $currentStatus['label'] . "</h2>
                    <p class='status-description'>" . $currentStatus['description'] . "</p>
                </div>
                
                <!-- Progress Tracker -->
                <div class='progress-tracker'>
                    <div class='progress-label'>
                        <span>Order Progress</span>
                        <span>" . $currentStatus['progress'] . "% Complete</span>
                    </div>
                    <div class='progress-bar'>
                        <div class='progress-fill'></div>
                    </div>
                </div>
                
                <!-- Order Timeline -->
                <div class='timeline'>
                    " . getTimelineSteps($newStatus) . "
                </div>
                
                <!-- Order Details -->
                <div class='details-card'>
                    <h3 class='details-title'>Order Information</h3>
                    <div class='detail-grid'>
                        <div class='detail-item'>
                            <div class='detail-label'>Order Number</div>
                            <div class='detail-value'>#$orderNumber</div>
                        </div>
                        <div class='detail-item'>
                            <div class='detail-label'>Order Date</div>
                            <div class='detail-value'>" . date('F d, Y') . "</div>
                        </div>
                        <div class='detail-item'>
                            <div class='detail-label'>Status Updated</div>
                            <div class='detail-value'>" . date('F d, Y g:i A') . "</div>
                        </div>
                        <div class='detail-item'>
                            <div class='detail-label'>Reference ID</div>
                            <div class='detail-value'>BK-" . strtoupper(substr(md5($orderNumber), 0, 8)) . "</div>
                        </div>
                    </div>
                </div>
                
                <!-- Custom Message -->
                " . (!empty($customMessage) ? "
                <div class='message-card'>
                    <div class='message-title'>
                        <span>📬</span>
                        <span>Personal Message from Our Team</span>
                    </div>
                    <div class='message-content'>
                        " . nl2br(htmlspecialchars($customMessage)) . "
                    </div>
                </div>
                " : "") . "
                
                <!-- Action Buttons -->
                <div class='action-buttons'>
                    <a href='#' class='btn btn-primary'>View Order Details</a>
                    <a href='#' class='btn btn-secondary'>Track Your Order</a>
                </div>
                
                <!-- Support Information -->
                <p style='text-align: center; color: var(--gray); font-size: 15px; margin-top: 40px;'>
                    Need assistance with your order?<br>
                    Our support team is available 24/7 at 
                    <a href='mailto:support@bkmaster.com' style='color: var(--primary); text-decoration: none; font-weight: 600;'>support@bkmaster.com</a>
                </p>
            </div>
            
            <!-- Footer -->
            <div class='email-footer'>
                <!-- Social Links -->
                <div class='social-links'>
                    <a href='#' class='social-icon'>Website</a>
                    <a href='#' class='social-icon'>Instagram</a>
                    <a href='#' class='social-icon'>Facebook</a>
                    <a href='#' class='social-icon'>Twitter</a>
                </div>
                
                <!-- Footer Links -->
                <div class='footer-links'>
                    <a href='#'>Contact Us</a>
                    <a href='#'>Shipping Policy</a>
                    <a href='#'>Return Policy</a>
                    <a href='#'>Privacy Policy</a>
                    <a href='#'>Terms of Service</a>
                </div>
                
                <!-- Copyright -->
                <div class='copyright'>
                    © " . date('Y') . " BKMaster. All rights reserved.<br>
                    This is an automated message, please do not reply directly to this email.<br>
                    <small>BKMaster Inc., 123 Business Street, City, State 12345</small>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    // Helper function to generate timeline steps
    

    // Plain text version
    $plainMessage = "ORDER STATUS UPDATE\n";
    $plainMessage .= "===================\n\n";
    $plainMessage .= "Dear " . ($customerName ?: 'Customer') . ",\n\n";
    $plainMessage .= "We're writing to inform you about an update to your order #$orderNumber.\n\n";
    $plainMessage .= "CURRENT STATUS: " . $currentStatus['label'] . "\n";
    $plainMessage .= "Description: " . $currentStatus['description'] . "\n\n";
    $plainMessage .= "Order Progress: " . $currentStatus['progress'] . "% complete\n\n";
    $plainMessage .= "ORDER DETAILS:\n";
    $plainMessage .= "--------------\n";
    $plainMessage .= "Order Number: #$orderNumber\n";
    $plainMessage .= "Order Date: " . date('F d, Y') . "\n";
    $plainMessage .= "Status Updated: " . date('F d, Y g:i A') . "\n";
    $plainMessage .= "Reference ID: BK-" . strtoupper(substr(md5($orderNumber), 0, 8)) . "\n\n";

    if (!empty($customMessage)) {
        $plainMessage .= "ADDITIONAL NOTES:\n";
        $plainMessage .= "-----------------\n";
        $plainMessage .= $customMessage . "\n\n";
    }

    $plainMessage .= "NEXT STEPS:\n";
    $plainMessage .= "-----------\n";
    $plainMessage .= "You can view your order details by logging into your account on our website.\n\n";
    $plainMessage .= "NEED HELP?\n";
    $plainMessage .= "----------\n";
    $plainMessage .= "If you have any questions, our support team is ready to assist you.\n";
    $plainMessage .= "Email: support@bkmaster.com\n\n";
    $plainMessage .= "Thank you for choosing BKMaster!\n\n";
    $plainMessage .= "Best regards,\n";
    $plainMessage .= "The BKMaster Team\n\n";
    $plainMessage .= "---\n";
    $plainMessage .= "This is an automated message. Please do not reply to this email.\n";
    $plainMessage .= "© " . date('Y') . " BKMaster. All rights reserved.\n";

    // Headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: support@bkmaster.com',
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 1',
        'Importance: High'
    ];

    // Send email
    try {
        if (mail($toEmail, $subject, $htmlMessage, implode("\r\n", $headers))) {
            error_log("Professional status email sent to $toEmail");
            return true;
        } else {
            error_log("mail() function failed for $toEmail");
            
            // Try alternative method using PHPMailer if available
            return trySendWithPHPMailer($toEmail, $customerName, $subject, $htmlMessage, $plainMessage, $fromEmail, $fromName);
        }
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

// Helper function to try PHPMailer if available
function trySendWithPHPMailer($toEmail, $customerName, $subject, $htmlMessage, $plainMessage, $fromEmail, $fromName)
{
    // Check if PHPMailer exists in different possible locations
    $possiblePaths = [
        __DIR__ . '/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../../PHPMailer/src/PHPMailer.php',
        __DIR__ . '/../../../PHPMailer/src/PHPMailer.php'
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            try {
                require_once dirname($path) . '/Exception.php';
                require_once $path;
                require_once dirname($path) . '/SMTP.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                // Gmail SMTP Configuration
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'unknowndevloper77@gmail.com';
                $mail->Password = 'sfch siop qwzw bqeg'; // Your Gmail App Password
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                // For testing, disable SSL verification
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $customerName);
                $mail->isHTML(true);

                $mail->Subject = $subject;
                $mail->Body = $htmlMessage;
                $mail->AltBody = $plainMessage;

                if ($mail->send()) {
                    error_log("Email sent successfully via PHPMailer to $toEmail");
                    return true;
                } else {
                    error_log("PHPMailer failed: " . $mail->ErrorInfo);
                    return false;
                }
            } catch (Exception $e) {
                error_log("PHPMailer exception: " . $e->getMessage());
                return false;
            }
        }
    }

    // PHPMailer not found
    error_log("PHPMailer not found in any of the expected locations");
    return false;
}

// Get order ID from query parameter
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    $error = "Invalid order ID.";
} else {
    // Fetch order details with user and address information
    $stmt = $conn->prepare("
        SELECT o.*, 
               u.id as user_id, u.first_name, u.last_name, u.email, u.phone,
               sa.line1 as shipping_line1, sa.line2 as shipping_line2, 
               sa.city as shipping_city, sa.state as shipping_state, 
               sa.country as shipping_country, sa.pincode as shipping_pincode,
               ba.line1 as billing_line1, ba.line2 as billing_line2,
               ba.city as billing_city, ba.state as billing_state,
               ba.country as billing_country, ba.pincode as billing_pincode
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN addresses sa ON o.shipping_address_id = sa.id
        LEFT JOIN addresses ba ON o.billing_address_id = ba.id
        WHERE o.id = ?
    ");

    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();

            // Check if addresses are the same
            $shippingAddress = [
                'line1' => $order['shipping_line1'] ?? '',
                'line2' => $order['shipping_line2'] ?? '',
                'city' => $order['shipping_city'] ?? '',
                'state' => $order['shipping_state'] ?? '',
                'pincode' => $order['shipping_pincode'] ?? '',
                'country' => $order['shipping_country'] ?? ''
            ];

            $billingAddress = [
                'line1' => $order['billing_line1'] ?? '',
                'line2' => $order['billing_line2'] ?? '',
                'city' => $order['billing_city'] ?? '',
                'state' => $order['billing_state'] ?? '',
                'pincode' => $order['billing_pincode'] ?? '',
                'country' => $order['billing_country'] ?? ''
            ];

            $order['same_address'] = addressesAreSame($shippingAddress, $billingAddress);

            // Format order number
            $order['display_order_number'] = $order['order_number'] ?: 'ORD-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);

            // Get order items with product and variant details
            $itemsQuery = $conn->query("
                SELECT 
                    oi.*,
                    p.id as product_id,
                    p.title as product_name,
                    pv.color,
                    pv.sizes,
                    pv.sale_price as variant_price
                FROM order_items oi
                LEFT JOIN product_variants pv ON oi.variant_id = pv.id
                LEFT JOIN products p ON pv.product_id = p.id
                WHERE oi.order_id = $orderId
                ORDER BY oi.id
            ");

            if ($itemsQuery && $itemsQuery->num_rows > 0) {
                while ($item = $itemsQuery->fetch_assoc()) {
                    // Get product image for each item
                    if (!empty($item['product_id'])) {
                        $item['product_image'] = getFirstProductImage($conn, $item['product_id'], $item['variant_id']);
                    }
                    $orderItems[] = $item;
                }
            }

            // Calculate subtotal from items
            $order['items_subtotal'] = 0;
            foreach ($orderItems as $item) {
                $lineTotal = $item['line_total'] ?? ($item['unit_price'] * $item['quantity']);
                $order['items_subtotal'] += floatval($lineTotal);
            }

            // If total_amount is null, calculate it
            if ($order['total_amount'] === null || $order['total_amount'] === '') {
                $shippingAmount = floatval($order['shipping_amount'] ?? 0);
                $discountAmount = floatval($order['discount_amount'] ?? 0);
                $order['total_amount'] = $order['items_subtotal'] + $shippingAmount - $discountAmount;
            }

            // Calculate tax (if not in database, estimate as 0 for now)
            $order['tax_amount'] = 0;
        } else {
            $error = "Order not found.";
        }
        $stmt->close();
    } else {
        $error = "Database error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $order ? 'Order Details - #' . e($order['display_order_number']) : 'Order Not Found'; ?> | Larkon - Responsive Admin Dashboard Template</title>

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
        .order-status-progress {
            height: 10px;
        }

        .avatar-sm {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .initials-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            text-transform: uppercase;
        }

        .address-block {
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .map-placeholder {
            width: 100%;
            height: 200px;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }

        .item-sizes {
            font-size: 12px;
            color: #6c757d;
        }

        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .no-image {
            width: 60px;
            height: 60px;
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }

        /* Original Timeline Styles from your template */
        .timeline-vertical {
            position: relative;
        }

        .timeline-vertical::before {
            content: '';
            position: absolute;
            left: 19px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #e9ecef;
            border-left: 2px dashed #dee2e6;
        }

        .timeline-item-vertical {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 50px;
        }

        .timeline-item-vertical .timeline-icon {
            position: absolute;
            left: 0;
            top: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #fff;
            border: 2px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }

        .timeline-item-vertical.completed .timeline-icon {
            background-color: #4CAF7C;
            border-color: #4CAF7C;
            color: white;
        }

        .timeline-item-vertical.current .timeline-icon {
            background-color: #ffc107;
            border-color: #ffc107;
            color: white;
            animation: pulse 1.5s infinite;
        }

        .timeline-item-vertical.pending .timeline-icon {
            background-color: #fff;
            border-color: #dee2e6;
            color: #6c757d;
        }

        .timeline-content {
            padding-top: 5px;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        /* Status update modal styles */
        .status-option {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .status-option:hover {
            background-color: #f8f9fa;
            border-color: #4CAF7C;
        }

        .status-option.selected {
            background-color: #e8f5e9;
            border-color: #4CAF7C;
            font-weight: bold;
        }

        .status-badge-modal {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 10px;
        }

        .status-pending {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-processing {
            background: #fff3cd;
            color: #856404;
        }

        .status-shipped {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-delivered {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
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

            <!-- Start Container -->
            <div class="container-xxl">

                <?php if ($error): ?>
                    <div class="alert alert-danger mt-3">
                        <?php echo e($error); ?>
                        <a href="list-order.php" class="btn btn-sm btn-outline-danger ms-3">Back to Orders</a>
                    </div>
                <?php elseif ($order): ?>

                    <!-- Breadcrumb -->
                    <nav aria-label="breadcrumb" class="mb-4">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="list-orders.php">Orders</a></li>
                            <li class="breadcrumb-item"><a href="list-orders.php">Order Details</a></li>
                            <li class="breadcrumb-item active" aria-current="page">#<?php echo e($order['display_order_number']); ?></li>
                        </ol>
                    </nav>

                    <div class="row">
                        <div class="col-xl-9 col-lg-8">
                            <div class="row">
                                <div class="col-lg-12">
                                    <!-- Order Header Card -->
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                                <div>
                                                    <h4 class="fw-medium text-dark d-flex align-items-center gap-2">
                                                        #<?php echo e($order['display_order_number']); ?>
                                                        <span class="badge <?php echo getPaymentStatusBadgeClass($order['payment_status']); ?> px-2 py-1 fs-13">
                                                            <?php echo ucfirst($order['payment_status']); ?>
                                                        </span>
                                                        <span class="badge <?php echo getStatusBadgeClass($order['status']); ?> px-2 py-1 fs-13">
                                                            <?php echo ucfirst($order['status']); ?>
                                                        </span>
                                                    </h4>
                                                    <p class="mb-0">
                                                        Order / Order Details / #<?php echo e($order['display_order_number']); ?> -
                                                        <?php echo formatDateTime($order['created_at']); ?>
                                                    </p>
                                                </div>
                                                <div>
                                                    <a href="list-orders.php" class="btn btn-outline-secondary">Back to Orders</a>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#statusUpdateModal">
                                                        Update Status
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Order Progress -->
                                            <div class="mt-4">
                                                <h4 class="fw-medium text-dark">Progress</h4>
                                            </div>
                                            <div class="row row-cols-xxl-5 row-cols-md-2 row-cols-1 mt-3">
                                                <?php
                                                $statuses = ['pending', 'processing', 'shipped', 'delivered'];
                                                $statusIcons = [
                                                    'pending' => 'solar:clipboard-text-broken',
                                                    'processing' => 'solar:settings-broken',
                                                    'shipped' => 'solar:box-broken',
                                                    'delivered' => 'solar:check-circle-broken'
                                                ];
                                                $currentStatus = strtolower($order['status']);

                                                foreach ($statuses as $index => $status):
                                                    $isCompleted = array_search($currentStatus, $statuses) >= $index;
                                                    $isCurrent = $currentStatus === $status;
                                                    $width = $isCompleted ? '100%' : '0%';
                                                    $color = $isCompleted ? 'bg-success' : ($isCurrent ? 'bg-warning' : 'bg-primary');
                                                ?>
                                                    <div class="col">
                                                        <div class="progress order-status-progress" style="height: 10px;">
                                                            <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $color; ?>"
                                                                role="progressbar" style="width: <?php echo $width; ?>"
                                                                aria-valuenow="<?php echo $isCompleted ? 100 : 0; ?>"
                                                                aria-valuemin="0" aria-valuemax="100">
                                                            </div>
                                                        </div>
                                                        <p class="mb-0 mt-2 d-flex align-items-center gap-2">
                                                            <iconify-icon icon="<?php echo $statusIcons[$status]; ?>" class="fs-16"></iconify-icon>
                                                            <?php echo ucfirst($status); ?>
                                                            <?php if ($isCurrent): ?>
                                                        <div class="spinner-border spinner-border-sm text-warning" role="status">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    </p>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between bg-light-subtle gap-2">
                                            <p class="border rounded mb-0 px-2 py-1 bg-body">
                                                <iconify-icon icon="solar:calendar-broken" class="align-middle fs-16"></iconify-icon>
                                                Created: <?php echo formatDate($order['created_at']); ?> |
                                                Updated: <?php echo formatDate($order['updated_at']); ?>
                                            </p>
                                            <div>
                                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusUpdateModal">
                                                    <iconify-icon icon="solar:pen-2-broken" class="me-1"></iconify-icon>
                                                    Change Status
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Order Items Card -->
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h4 class="card-title">Order Items (<?php echo count($orderItems); ?>)</h4>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($orderItems)): ?>
                                                <div class="table-responsive">
                                                    <table class="table align-middle mb-0 table-hover table-centered">
                                                        <thead class="bg-light-subtle border-bottom">
                                                            <tr>
                                                                <th>Product</th>
                                                                <th>Variant Details</th>
                                                                <th>Status</th>
                                                                <th>Qty</th>
                                                                <th>Unit Price</th>
                                                                <th>Line Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($orderItems as $item): ?>
                                                                <?php
                                                                $productTitle = $item['product_title'] ?: $item['product_name'] ?: 'Unknown Product';
                                                                $color = $item['color'] ?? '';
                                                                $sizes = parseSizes($item['sizes'] ?? '');
                                                                $unitPrice = $item['unit_price'] ?? 0;
                                                                $quantity = $item['quantity'] ?? 0;
                                                                $lineTotal = $item['line_total'] ?? ($unitPrice * $quantity);
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <div class="d-flex align-items-center gap-2">
                                                                            <?php if (!empty($item['product_image'])): ?>
                                                                                <img src="<?php echo e($item['product_image']); ?>" alt="<?php echo e($productTitle); ?>" class="product-image">
                                                                            <?php else: ?>
                                                                                <div class="no-image">
                                                                                    <iconify-icon icon="solar:box-broken" class="fs-20"></iconify-icon>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                            <div>
                                                                                <a href="#!" class="text-dark fw-medium fs-15">
                                                                                    <?php echo e($productTitle); ?>
                                                                                </a>
                                                                                <p class="text-muted mb-0 mt-1 fs-13">
                                                                                    Variant ID: <?php echo e($item['variant_id'] ?? 'N/A'); ?>
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    </td>
                                                                    <td>
                                                                        <?php if ($color): ?>
                                                                            <p class="mb-1">
                                                                                <span class="text-muted">Color:</span>
                                                                                <span class="fw-medium"><?php echo e($color); ?></span>
                                                                            </p>
                                                                        <?php endif; ?>
                                                                        <?php if ($sizes): ?>
                                                                            <p class="mb-0 item-sizes">
                                                                                <span class="text-muted">Sizes:</span>
                                                                                <?php echo e($sizes); ?>
                                                                            </p>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td>
                                                                        <span class="badge bg-success-subtle text-success px-2 py-1 fs-13">Ready</span>
                                                                    </td>
                                                                    <td><?php echo e($quantity); ?></td>
                                                                    <td><?php echo formatCurrency($unitPrice); ?></td>
                                                                    <td class="fw-medium"><?php echo formatCurrency($lineTotal); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                        <tfoot>
                                                            <tr class="border-top">
                                                                <td colspan="5" class="text-end fw-bold">Subtotal:</td>
                                                                <td class="fw-bold"><?php echo formatCurrency($order['items_subtotal']); ?></td>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center text-muted py-4">
                                                    <iconify-icon icon="solar:box-broken" class="fs-48 text-muted"></iconify-icon>
                                                    <p class="mt-2">No items found for this order.</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Order Timeline (Original Style) -->
                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h4 class="card-title">Order Timeline</h4>
                                        </div>
                                        <div class="card-body">
                                            <div class="timeline-vertical">
                                                <!-- Order Created -->
                                                <div class="timeline-item-vertical completed">
                                                    <div class="timeline-icon">
                                                        <iconify-icon icon="solar:document-add-broken"></iconify-icon>
                                                    </div>
                                                    <div class="timeline-content">
                                                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                                            <div>
                                                                <h5 class="mb-1 text-dark fw-medium fs-15">Order Created</h5>
                                                                <p class="mb-2">Order #<?php echo e($order['display_order_number']); ?> was created</p>
                                                            </div>
                                                            <p class="mb-0 text-muted small"><?php echo formatDateTime($order['created_at']); ?></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Order Status -->
                                                <div class="timeline-item-vertical <?php echo $order['status'] === 'delivered' ? 'completed' : ($order['status'] === 'processing' || $order['status'] === 'shipped' ? 'current' : 'pending'); ?>">
                                                    <div class="timeline-icon">
                                                        <?php if ($order['status'] === 'delivered'): ?>
                                                            <iconify-icon icon="solar:check-circle-broken"></iconify-icon>
                                                        <?php elseif ($order['status'] === 'processing' || $order['status'] === 'shipped'): ?>
                                                            <iconify-icon icon="solar:settings-broken"></iconify-icon>
                                                        <?php else: ?>
                                                            <iconify-icon icon="solar:clock-circle-broken"></iconify-icon>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="timeline-content">
                                                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                                            <div>
                                                                <h5 class="mb-1 text-dark fw-medium fs-15">Order <?php echo ucfirst($order['status']); ?></h5>
                                                                <p class="mb-2">Order is currently <?php echo $order['status']; ?></p>
                                                                <?php if ($order['status'] === 'processing'): ?>
                                                                    <div class="d-flex align-items-center gap-2">
                                                                        <span class="badge bg-warning-subtle text-warning px-2 py-1 fs-13">Processing</span>
                                                                        <div class="spinner-border spinner-border-sm text-warning" role="status">
                                                                            <span class="visually-hidden">Loading...</span>
                                                                        </div>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <p class="mb-0 text-muted small">Last updated: <?php echo formatDateTime($order['updated_at']); ?></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Next Steps -->
                                                <?php if ($order['status'] !== 'shipped' && $order['status'] !== 'delivered'): ?>
                                                    <div class="timeline-item-vertical pending">
                                                        <div class="timeline-icon">
                                                            <iconify-icon icon="solar:box-broken"></iconify-icon>
                                                        </div>
                                                        <div class="timeline-content">
                                                            <h5 class="mb-1 text-dark fw-medium fs-15">Shipping</h5>
                                                            <p class="mb-2">Awaiting shipping confirmation</p>
                                                            <p class="text-muted mb-0 small">Not started</p>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($order['status'] !== 'delivered'): ?>
                                                    <div class="timeline-item-vertical pending">
                                                        <div class="timeline-icon">
                                                            <iconify-icon icon="solar:truck-delivery-broken"></iconify-icon>
                                                        </div>
                                                        <div class="timeline-content">
                                                            <h5 class="mb-1 text-dark fw-medium fs-15">Delivery</h5>
                                                            <p class="mb-2">Awaiting delivery confirmation</p>
                                                            <p class="text-muted mb-0 small">Not started</p>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Order Meta Information -->
                                    <div class="card bg-light-subtle mt-3">
                                        <div class="card-body">
                                            <div class="row g-3 g-lg-0">
                                                <div class="col-lg-3 border-end">
                                                    <div class="d-flex align-items-center gap-3 justify-content-between px-3">
                                                        <div>
                                                            <p class="text-dark fw-medium fs-16 mb-1">Order ID</p>
                                                            <p class="mb-0">#<?php echo e($order['display_order_number']); ?></p>
                                                        </div>
                                                        <div class="avatar bg-light d-flex align-items-center justify-content-center rounded">
                                                            <iconify-icon icon="solar:document-broken" class="fs-35 text-primary"></iconify-icon>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-lg-3 border-end">
                                                    <div class="d-flex align-items-center gap-3 justify-content-between px-3">
                                                        <div>
                                                            <p class="text-dark fw-medium fs-16 mb-1">Date</p>
                                                            <p class="mb-0"><?php echo formatDate($order['created_at']); ?></p>
                                                        </div>
                                                        <div class="avatar bg-light d-flex align-items-center justify-content-center rounded">
                                                            <iconify-icon icon="solar:calendar-date-bold-duotone" class="fs-35 text-primary"></iconify-icon>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-lg-3 border-end">
                                                    <div class="d-flex align-items-center gap-3 justify-content-between px-3">
                                                        <div>
                                                            <p class="text-dark fw-medium fs-16 mb-1">Customer</p>
                                                            <p class="mb-0">
                                                                <?php
                                                                $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                                                                echo e($customerName ?: 'Unknown Customer');
                                                                ?>
                                                            </p>
                                                        </div>
                                                        <div class="avatar bg-light d-flex align-items-center justify-content-center rounded">
                                                            <iconify-icon icon="solar:user-circle-bold-duotone" class="fs-35 text-primary"></iconify-icon>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-lg-3">
                                                    <div class="d-flex align-items-center gap-3 justify-content-between px-3">
                                                        <div>
                                                            <p class="text-dark fw-medium fs-16 mb-1">Total Amount</p>
                                                            <p class="mb-0 fw-bold"><?php echo formatCurrency($order['total_amount']); ?></p>
                                                        </div>
                                                        <div class="avatar bg-light d-flex align-items-center justify-content-center rounded">
                                                            <iconify-icon icon="mdi:currency-rupee" class="fs-35 text-primary"></iconify-icon>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar -->
                        <div class="col-xl-3 col-lg-4">
                            <!-- Order Summary -->
                            <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title">Order Summary</h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table mb-0">
                                            <tbody>
                                                <tr>
                                                    <td class="px-0">
                                                        <p class="d-flex mb-0 align-items-center gap-1">
                                                            <iconify-icon icon="solar:clipboard-text-broken"></iconify-icon>
                                                            Sub Total:
                                                        </p>
                                                    </td>
                                                    <td class="text-end text-dark fw-medium px-0">
                                                        <?php echo formatCurrency($order['items_subtotal']); ?>
                                                    </td>
                                                </tr>
                                                <?php if (($order['discount_amount'] ?? 0) > 0): ?>
                                                    <tr>
                                                        <td class="px-0">
                                                            <p class="d-flex mb-0 align-items-center gap-1">
                                                                <iconify-icon icon="solar:ticket-broken" class="align-middle"></iconify-icon>
                                                                Discount:
                                                            </p>
                                                        </td>
                                                        <td class="text-end text-danger fw-medium px-0">
                                                            -<?php echo formatCurrency($order['discount_amount']); ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php if (($order['shipping_amount'] ?? 0) > 0): ?>
                                                    <tr>
                                                        <td class="px-0">
                                                            <p class="d-flex mb-0 align-items-center gap-1">
                                                                <iconify-icon icon="solar:kick-scooter-broken" class="align-middle"></iconify-icon>
                                                                Shipping:
                                                            </p>
                                                        </td>
                                                        <td class="text-end text-dark fw-medium px-0">
                                                            <?php echo formatCurrency($order['shipping_amount']); ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php if (($order['tax_amount'] ?? 0) > 0): ?>
                                                    <tr>
                                                        <td class="px-0">
                                                            <p class="d-flex mb-0 align-items-center gap-1">
                                                                <iconify-icon icon="solar:calculator-minimalistic-broken" class="align-middle"></iconify-icon>
                                                                Tax:
                                                            </p>
                                                        </td>
                                                        <td class="text-end text-dark fw-medium px-0">
                                                            <?php echo formatCurrency($order['tax_amount']); ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="card-footer d-flex align-items-center justify-content-between bg-light-subtle">
                                    <div>
                                        <p class="fw-medium text-dark mb-0">Total Amount</p>
                                    </div>
                                    <div>
                                        <p class="fw-medium text-dark mb-0"><?php echo formatCurrency($order['total_amount']); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Information -->
                            <div class="card mt-3">
                                <div class="card-header">
                                    <h4 class="card-title">Payment Information</h4>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex align-items-center gap-3 mb-3">
                                        <div class="rounded-3 bg-light avatar d-flex align-items-center justify-content-center">
                                            <iconify-icon icon="mdi:currency-rupee" class="fs-24 text-primary"></iconify-icon>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-dark fw-medium">
                                                <?php echo ucfirst($order['payment_status']); ?>
                                                <span class="badge <?php echo getPaymentStatusBadgeClass($order['payment_status']); ?> ms-2 px-2 py-1 fs-12">
                                                    <?php echo $order['payment_status']; ?>
                                                </span>
                                            </p>
                                            <?php if ($order['payment_status'] === 'paid'): ?>
                                                <p class="mb-0 text-muted fs-13">Paid on <?php echo formatDate($order['updated_at']); ?></p>
                                            <?php elseif ($order['payment_status'] === 'unpaid'): ?>
                                                <p class="mb-0 text-muted fs-13">Awaiting payment</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($order['payment_status'] === 'paid'): ?>
                                        <p class="text-dark mb-1 fw-medium">
                                            Payment Method: <span class="text-muted fw-normal fs-13"> Credit Card</span>
                                        </p>
                                        <p class="text-dark mb-0 fw-medium">
                                            Transaction ID: <span class="text-muted fw-normal fs-13"> #<?php echo strtoupper(substr(md5($order['id']), 0, 10)); ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Customer Details -->
                            <div class="card mt-3">
                                <div class="card-header">
                                    <h4 class="card-title">Customer Details</h4>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex align-items-center gap-2 mb-3">
                                        <?php
                                        $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                                        $initials = '';
                                        if (!empty($order['first_name'])) $initials .= strtoupper(substr($order['first_name'], 0, 1));
                                        if (!empty($order['last_name'])) $initials .= strtoupper(substr($order['last_name'], 0, 1));
                                        if (!$initials) $initials = 'U';

                                        // Generate color from name
                                        $nameHash = crc32($customerName);
                                        $colors = ['#4A6FA5', '#166088', '#2D3047', '#419D78', '#E0A458'];
                                        $colorIndex = abs($nameHash) % count($colors);
                                        $avatarColor = $colors[$colorIndex];
                                        ?>
                                        <div class="initials-avatar flex-shrink-0" style="background-color: <?php echo $avatarColor; ?>;">
                                            <?php echo $initials; ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <p class="mb-1 fw-medium"><?php echo e($customerName ?: 'Unknown Customer'); ?></p>
                                            <?php if (!empty($order['email'])): ?>
                                                <a href="mailto:<?php echo e($order['email']); ?>" class="link-primary fw-medium text-break"><?php echo e($order['email']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($order['phone'])): ?>
                                        <div class="d-flex justify-content-between mt-3">
                                            <h5 class="fs-14">Contact Number</h5>
                                        </div>
                                        <p class="mb-1">
                                            <iconify-icon icon="solar:phone-calling-rounded-broken" class="me-2"></iconify-icon>
                                            <?php echo e($order['phone']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <!-- Shipping Address -->
                                    <?php if (!empty($order['shipping_line1'])): ?>
                                        <div class="d-flex justify-content-between mt-3">
                                            <h5 class="fs-14">Shipping Address</h5>
                                            <a href="#!" class="btn btn-sm btn-link p-0">
                                                <iconify-icon icon="solar:pen-2-broken" class="fs-16"></iconify-icon>
                                            </a>
                                        </div>
                                        <div class="address-block">
                                            <p class="mb-1 fw-medium"><?php echo e($customerName ?: 'Unknown Customer'); ?></p>
                                            <p class="mb-1"><?php echo e($order['shipping_line1']); ?></p>
                                            <?php if (!empty($order['shipping_line2'])): ?><p class="mb-1"><?php echo e($order['shipping_line2']); ?></p><?php endif; ?>
                                            <p class="mb-1">
                                                <?php
                                                $shippingParts = [];
                                                if (!empty($order['shipping_city'])) $shippingParts[] = $order['shipping_city'];
                                                if (!empty($order['shipping_state'])) $shippingParts[] = $order['shipping_state'];
                                                if (!empty($order['shipping_pincode'])) $shippingParts[] = $order['shipping_pincode'];
                                                echo e(implode(', ', $shippingParts));
                                                ?>
                                            </p>
                                            <?php if (!empty($order['shipping_country'])): ?><p class="mb-0"><?php echo e($order['shipping_country']); ?></p><?php endif; ?>
                                            <?php if (!empty($order['phone'])): ?><p class="mb-0 mt-2"><iconify-icon icon="solar:phone-calling-rounded-broken" class="me-1"></iconify-icon><?php echo e($order['phone']); ?></p><?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Billing Address -->
                                    <div class="d-flex justify-content-between mt-3">
                                        <h5 class="fs-14">Billing Address</h5>
                                        <?php if (!$order['same_address'] && !empty($order['billing_line1'])): ?>
                                            <a href="#!" class="btn btn-sm btn-link p-0">
                                                <iconify-icon icon="solar:pen-2-broken" class="fs-16"></iconify-icon>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($order['same_address']): ?>
                                        <p class="mb-1 text-muted">Same as shipping address</p>
                                    <?php elseif (!empty($order['billing_line1'])): ?>
                                        <div class="address-block">
                                            <p class="mb-1"><?php echo e($order['billing_line1']); ?></p>
                                            <?php if (!empty($order['billing_line2'])): ?><p class="mb-1"><?php echo e($order['billing_line2']); ?></p><?php endif; ?>
                                            <p class="mb-1">
                                                <?php
                                                $billingParts = [];
                                                if (!empty($order['billing_city'])) $billingParts[] = $order['billing_city'];
                                                if (!empty($order['billing_state'])) $billingParts[] = $order['billing_state'];
                                                if (!empty($order['billing_pincode'])) $billingParts[] = $order['billing_pincode'];
                                                echo e(implode(', ', $billingParts));
                                                ?>
                                            </p>
                                            <?php if (!empty($order['billing_country'])): ?><p class="mb-0"><?php echo e($order['billing_country']); ?></p><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="mb-1 text-muted">No billing address provided</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Location Map (Placeholder) -->
                            <div class="card mt-3">
                                <div class="card-body">
                                    <div class="map-placeholder">
                                        <div class="text-center">
                                            <iconify-icon icon="solar:map-point-broken" class="fs-48 text-muted"></iconify-icon>
                                            <p class="mt-2">Shipping Location</p>
                                            <?php if (!empty($order['shipping_city'])): ?>
                                                <p class="small mb-0"><?php echo e($order['shipping_city'] . ', ' . ($order['shipping_state'] ?? '')); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
            <!-- End Container -->

        </div>
        <!-- ==================================================== -->
        <!-- End Page Content -->
        <!-- ==================================================== -->

        <!-- Status Update Modal -->
        <div class="modal fade" id="statusUpdateModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="statusUpdateModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="statusUpdateModalLabel">Update Order Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-4">
                                    <h6>Order Information</h6>
                                    <p class="mb-1"><strong>Order #:</strong> <?php echo e($order['display_order_number'] ?? ''); ?></p>
                                    <p class="mb-1"><strong>Current Status:</strong>
                                        <span class="badge <?php echo getStatusBadgeClass($order['status'] ?? ''); ?>">
                                            <?php echo ucfirst($order['status'] ?? ''); ?>
                                        </span>
                                    </p>
                                    <p class="mb-1"><strong>Customer:</strong>
                                        <?php echo e(trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: 'Unknown'); ?>
                                    </p>
                                </div>

                                <h6 class="mb-3">Select New Status</h6>
                                <div id="statusOptions">
                                    <div class="status-option" data-status="pending">
                                        <span class="status-badge-modal status-pending">Pending</span>
                                        Order is awaiting confirmation
                                    </div>
                                    <div class="status-option" data-status="processing">
                                        <span class="status-badge-modal status-processing">Processing</span>
                                        Order is being prepared
                                    </div>
                                    <div class="status-option" data-status="shipped">
                                        <span class="status-badge-modal status-shipped">Shipped</span>
                                        Order has been dispatched
                                    </div>
                                    <div class="status-option" data-status="delivered">
                                        <span class="status-badge-modal status-delivered">Delivered</span>
                                        Order has been delivered
                                    </div>
                                    <div class="status-option" data-status="cancelled">
                                        <span class="status-badge-modal status-cancelled">Cancelled</span>
                                        Order has been cancelled
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <form id="statusUpdateForm">
                                    <input type="hidden" id="selectedStatus" name="status" value="">
                                    <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
                                    <input type="hidden" name="action" value="update_status">

                                    <div class="mb-3">
                                        <label class="form-check-label d-block mb-2">
                                            <input type="checkbox" class="form-check-input" name="send_email" id="sendEmail" checked>
                                            Send email notification to customer
                                        </label>
                                        <small class="text-muted d-block">Customer email: <?php echo e($order['email'] ?? 'Not available'); ?></small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="emailMessage" class="form-label">Additional Notes (Optional)</label>
                                        <textarea class="form-control" name="email_message" id="emailMessage" rows="4"
                                            placeholder="Add any additional notes for the customer..."></textarea>
                                        <small class="text-muted">These notes will be included in the email notification.</small>
                                    </div>

                                    <div class="alert alert-info">
                                        <iconify-icon icon="solar:info-circle-broken" class="me-2"></iconify-icon>
                                        <small>Updating the status will also update the order timeline and send an email notification if enabled.</small>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirmStatusUpdate" disabled>
                            Update Status
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div class="modal fade" id="staticBackdrop" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="staticBackdropLabel">Confirm Status Update</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="confirmationMessage" class="fw-medium">Are you sure you want to update this order?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="finalConfirmBtn">Yes, Update</button>
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
                        </script> &copy; Larkon. Crafted by
                        <iconify-icon icon="iconamoon:heart-duotone" class="fs-18 align-middle text-danger"></iconify-icon>
                        <a href="https://1.envato.market/techzaa" class="fw-bold footer-text" target="_blank">Techzaa</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- ========== Footer End ========== -->

    </div>
    <!-- END Wrapper -->

    <!-- Vendor Javascript (Require in all Page) -->
    <script src="../assets/js/vendor.js"></script>

    <!-- App Javascript (Require in all Page) -->
    <script src="../assets/js/app.js"></script>

    <!-- Toastify JS -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <script>
        // Status update functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Status options selection
            const statusOptions = document.querySelectorAll('.status-option');
            const selectedStatusInput = document.getElementById('selectedStatus');
            const confirmBtn = document.getElementById('confirmStatusUpdate');

            // Handle status option clicks
            statusOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remove selected class from all options
                    statusOptions.forEach(opt => opt.classList.remove('selected'));

                    // Add selected class to clicked option
                    this.classList.add('selected');

                    // Update hidden input value
                    const status = this.getAttribute('data-status');
                    selectedStatusInput.value = status;

                    // Enable confirm button
                    confirmBtn.disabled = false;

                    // Update button text
                    confirmBtn.innerHTML = `Update to <strong>${status.charAt(0).toUpperCase() + status.slice(1)}</strong>`;
                });
            });

            let pendingUpdateData = null;
            const confirmModalElement = document.getElementById('staticBackdrop');
            const confirmModal = new bootstrap.Modal(confirmModalElement);
            const finalConfirmBtn = document.getElementById('finalConfirmBtn');

            // Handle confirm button click (First Step)
            confirmBtn.addEventListener('click', function() {
                const status = selectedStatusInput.value;
                if (!status) {
                    showToast('Please select a status first', 'error');
                    return;
                }

                const orderId = <?php echo $orderId; ?>;
                const sendEmail = document.getElementById('sendEmail').checked;
                const emailMessage = document.getElementById('emailMessage').value;

                // Store data for final execution
                pendingUpdateData = {
                    action: 'update_status',
                    order_id: orderId,
                    status: status,
                    send_email: sendEmail ? '1' : '0',
                    email_message: emailMessage
                };

                // Update modal message
                const confirmMsg = `Are you sure you want to update order #${orderId} status to "${status.toUpperCase()}"?`;
                document.getElementById('confirmationMessage').textContent = confirmMsg;

                // Hide status modal and show confirmation modal
                const statusModal = bootstrap.Modal.getInstance(document.getElementById('statusUpdateModal'));
                statusModal.hide();
                confirmModal.show();
            });

            // Handle Final Confirmation (Second Step)
            finalConfirmBtn.addEventListener('click', function() {
                if (!pendingUpdateData) return;

                // Disable button and show loading
                const originalText = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

                // Prepare form data
                const formData = new FormData();
                for (const key in pendingUpdateData) {
                    formData.append(key, pendingUpdateData[key]);
                }

                // Send request
                fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Reset button
                        this.disabled = false;
                        this.innerHTML = originalText;

                        if (data.success) {
                            // Show success message
                            let successMsg = data.message;
                            if (data.emailSent) {
                                successMsg += ' Email notification sent to customer.';
                            } else if (pendingUpdateData.send_email === '1') {
                                successMsg += ' Email notification could not be sent.';
                            }
                            showToast(successMsg, 'success');

                            // Close modal
                            confirmModal.hide();

                            // Reload page after 1.5 seconds to show updated status
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast('Error: ' + data.error, 'error');
                        }
                    })
                    .catch(error => {
                        // Reset button on error
                        this.disabled = false;
                        this.innerHTML = originalText;
                        showToast('Request failed: ' + error.message, 'error');
                    });
            });

            // Pre-select current status if order exists
            <?php if ($order): ?>
                const currentStatus = '<?php echo $order['status']; ?>';
                const currentOption = document.querySelector(`.status-option[data-status="${currentStatus}"]`);
                if (currentOption) {
                    currentOption.classList.add('selected');
                    selectedStatusInput.value = currentStatus;
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = `Update to <strong>${currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)}</strong>`;
                }
            <?php endif; ?>
        });

        // Function to send payment reminder
        function sendPaymentReminder() {
            if (confirm('Send payment reminder to customer?')) {
                showToast('Payment reminder functionality would be implemented here', 'info');
            }
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            Toastify({
                text: message,
                duration: 5000,
                close: true,
                gravity: "top",
                position: "right",
                backgroundColor: type === 'success' ? '#4CAF7C' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#0d6efd',
            }).showToast();
        }
    </script>

</body>

</html>

<?php
$conn->close();
?>