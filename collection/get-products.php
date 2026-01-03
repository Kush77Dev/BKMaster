<?php
// get-products.php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

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
        $row['colors'] = array_slice($colors, 0, 3); // Limit to 3 colors
        
        // Process sizes
        $sizes = [];
        if (!empty($row['all_sizes'])) {
            // Handle JSON array in sizes field
            $size_array = explode(',', $row['all_sizes']);
            // Clean up the sizes
            $size_array = array_map(function($size) {
                return trim($size, '[]"\' ');
            }, $size_array);
            $sizes = array_unique($size_array);
        }
        $row['sizes'] = array_slice($sizes, 0, 5); // Limit to 5 sizes
        
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

$conn->close();
?>