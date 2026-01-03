<?php
include 'db.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "Starting data seed...<br>";

// 1. CLEAR EXISTING DATA (OPTIONAL - COMMENT OUT IF YOU WANT TO KEEP DATA)
// $conn->query("DELETE FROM order_items");
// $conn->query("DELETE FROM orders");
// $conn->query("DELETE FROM users");
// echo "Cleared existing data.<br>";

// 2. CREATE USERS
$users = [];
for ($i = 1; $i <= 7; $i++) {
    $email = "user$i@example.com";
    // Check if user exists
    $res = $conn->query("SELECT id FROM users WHERE email = '$email'");
    if ($res && $res->num_rows > 0) {
        $users[] = $res->fetch_object()->id;
    } else {
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password_hash, phone, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $fname = "User";
        $lname = "$i";
        $pass = password_hash("password", PASSWORD_DEFAULT);
        $phone = "1234567890";
        $stmt->bind_param("sssss", $fname, $lname, $email, $pass, $phone);
        $stmt->execute();
        $users[] = $stmt->insert_id;
        $stmt->close();
    }
}
echo "Users ready: " . implode(", ", $users) . "<br>";

// Helper to create order
function createOrder($conn, $userId, $date, $amount, $status = 'Completed') {
    $orderNum = 'ORD-' . strtoupper(uniqid());
    $stmt = $conn->prepare("INSERT INTO orders (user_id, order_number, status, payment_status, total_amount, created_at, updated_at) VALUES (?, ?, ?, 'paid', ?, ?, ?)");
    $stmt->bind_param("isssss", $userId, $orderNum, $status, $amount, $date, $date);
    $stmt->execute();
    $orderId = $stmt->insert_id;
    $stmt->close();
    
    // Add dummy item
    $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, variant_id, product_title, unit_price, quantity, line_total, created_at) VALUES (?, 1, 'Dummy Product', ?, 1, ?, ?)");
    $stmtItem->bind_param("idds", $orderId, $amount, $amount, $date);
    $stmtItem->execute();
    $stmtItem->close();
    
    return $orderId;
}

// 3. SEED "LAST WEEK" (Dec 14 - Dec 20, 2025)
// Let's create orders for User 1 and User 2 multiple times (Repeat Customers)
createOrder($conn, $users[0], '2025-12-17 10:00:00', 100.00);
createOrder($conn, $users[0], '2025-12-19 14:00:00', 150.00);
createOrder($conn, $users[1], '2025-12-20 09:00:00', 200.00);
createOrder($conn, $users[1], '2025-12-20 18:00:00', 50.00);
createOrder($conn, $users[2], '2025-12-18 12:00:00', 300.00); // Has historical order, so is Repeat
createOrder($conn, $users[5], '2025-12-18 15:00:00', 55.00); // NEW ONE-TIME BUYER (User 6)
echo "Inserted Last Week Orders (Dec 17-20).<br>";


// 4. SEED "THIS WEEK" (Dec 21 - Dec 27, 2025)
// User 1 is returning again (Total Orders > 1)
createOrder($conn, $users[0], '2025-12-22 10:00:00', 120.00);
// User 3 is new but buys twice this week (Repeat Customer)
createOrder($conn, $users[3], '2025-12-24 11:00:00', 80.00);
createOrder($conn, $users[3], '2025-12-26 15:00:00', 90.00);
// User 4 buys once (Has historical order, so is Repeat)
createOrder($conn, $users[4], '2025-12-25 09:00:00', 500.00);
createOrder($conn, $users[6], '2025-12-25 10:00:00', 45.00); // NEW ONE-TIME BUYER (User 7)
echo "Inserted This Week Orders (Dec 22-26).<br>";

// 5. SEED HISTORICAL DATA (For Performance Chart)
// Last Month (Nov 2025)
createOrder($conn, $users[0], '2025-11-10 10:00:00', 1200.00);
createOrder($conn, $users[1], '2025-11-20 10:00:00', 800.00);

// 3 Months Ago (Sep 2025)
createOrder($conn, $users[2], '2025-09-15 10:00:00', 2500.00);

// 5 Months Ago (Jul 2025)
createOrder($conn, $users[3], '2025-07-10 10:00:00', 1800.00);

// Last Year (May 2024)
createOrder($conn, $users[4], '2024-05-10 10:00:00', 5000.00);

echo "Inserted Historical Orders (Nov, Sep, Jul 2025 & May 2024).<br>";

echo "Done! Refresh the dashboard.";
?>
