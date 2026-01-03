<?php
require_once 'db.php';

header('Content-Type: text/html');

// 1. Get or Create a User
// Try to get a user, if not create one.
$user_id = 1;
$res = $conn->query("SELECT id FROM users LIMIT 1");
if ($res && $res->num_rows > 0) {
    $user_id = $res->fetch_assoc()['id'];
} else {
    // Insert dummy user
    $conn->query("INSERT INTO users (first_name, last_name, email, password, role) VALUES ('Test', 'User', 'test@example.com', 'password', 'customer')");
    $user_id = $conn->insert_id;
}

// 2. Get or Create Address
$address_id = 1;
$resAddr = $conn->query("SELECT id FROM addresses LIMIT 1");
if ($resAddr && $resAddr->num_rows > 0) {
    $address_id = $resAddr->fetch_assoc()['id'];
} else {
    // Insert dummy address
    $conn->query("INSERT INTO addresses (user_id, address_line1, city, state, zip_code, country) VALUES ($user_id, '123 Test St', 'Test City', 'Gujarat', '380001', 'India')");
    $address_id = $conn->insert_id;
}

// 3. Create Order
$amount = rand(500, 5000);
$status_options = ['pending', 'processing', 'shipped', 'delivered'];
$status = $status_options[array_rand($status_options)];
$payment_status = 'paid';
$order_number = 'ORD-' . strtoupper(substr(md5(time()), 0, 6));

$stmt = $conn->prepare("INSERT INTO orders (order_number, user_id, total_amount, status, payment_status, shipping_address_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("sisssi", $order_number, $user_id, $amount, $status, $payment_status, $address_id);

if ($stmt->execute()) {
    $new_id = $stmt->insert_id;
    echo "<h1>Test Order Created!</h1>";
    echo "<p>Order ID: $new_id</p>";
    echo "<p>Order Number: $order_number</p>";
    echo "<p>Amount: ₹$amount</p>";
    echo "<p>Status: $status</p>";
    echo "<hr>";
    echo "<p><strong>Keep this tab open or refresh it to create more orders. Check your dashboard tab to see the alert.</strong></p>";
    echo "<a href='test-create-order.php'>Create Another Order</a> | <a href='Index.php'>Go to Dashboard</a>";
} else {
    echo "Error creating order: " . $conn->error;
}
