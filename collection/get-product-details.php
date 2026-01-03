<?php
// get-product-details.php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'bkmaster';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

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
            $size_array = array_map(function($size) {
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

$conn->close();
?>