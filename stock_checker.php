<?php

$config = [
    'api_base_url' => 'https://restyletest.iai-shop.com/api/admin/v5',
    'api_key' => 'YXBwbGljYXRpb24yOjAyTXJZcnp5YktDNkNlMDJHREpxL2hoR3VsUDFUZlNwM2N3NTZXbEJQOTF1RTcrY0trTFNqU3BKUG1vMUtGSTA=',
    'full_response_file' => __DIR__ . '/full_api_response_' . date('Y-m-d_H-i') . '.json',
    'stock_summary_file' => __DIR__ . '/stock_summary_' . date('Y-m-d_H-i') . '.json',
    'xml_file' => __DIR__ . '/feed.xml',
    'output_file' => __DIR__ . '/products_with_stock_' . date('Y-m-d_H-i') . '.json',
    'log_file' => __DIR__ . '/xml_parser_log.txt'
];

function log_message($message) {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($config['log_file'], $logMessage, FILE_APPEND | LOCK_EX);
    echo $logMessage;
}

function parse_xml_feed($xmlFile) {
    if (!file_exists($xmlFile)) {
        throw new Exception("XML file not found: {$xmlFile}");
    }
    
    log_message("Starting XML parsing of: {$xmlFile}");
    log_message("File size: " . number_format(filesize($xmlFile) / 1024 / 1024, 2) . " MB");
    
    // Use XMLReader for memory-efficient parsing of large files
    $reader = new XMLReader();
    $reader->open($xmlFile);
    
    $productsWithStock = [];
    $totalProducts = 0;
    $productsProcessed = 0;
    
    // Read through the XML
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'product') {
            $totalProducts++;
            
            // Get the product as a DOM element for easier parsing
            $productXml = $reader->readOuterXML();
            $productDom = new DOMDocument();
            $productDom->loadXML($productXml);
            
            $product = parse_product($productDom);
            
            if ($product && $product['total_stock'] > 0) {
                $productsWithStock[] = $product;
                $productsProcessed++;
                
                log_message("Product {$product['id']}: {$product['name']} - Total stock: {$product['total_stock']} (exactly 1 unit)");
            }
            
            // Progress update every 100 products
            if ($totalProducts % 100 === 0) {
                log_message("Processed {$totalProducts} products, found {$productsProcessed} with exactly 1 stock");
            }
        }
    }
    
    $reader->close();
    
    log_message("Parsing completed. Total products: {$totalProducts}, Products with exactly 1 stock: {$productsProcessed}");
    
    return [
        'summary' => [
            'generated_at' => date('Y-m-d H:i:s'),
            'total_products_in_feed' => $totalProducts,
            'products_with_exactly_1_stock' => $productsProcessed
        ],
        'products' => $productsWithStock
    ];
}

function parse_product($productDom) {
    $xpath = new DOMXPath($productDom);
    
    // Get basic product info
    $productElement = $xpath->query('//product')->item(0);
    if (!$productElement) {
        return null;
    }
    
    $productId = $productElement->getAttribute('id');
    $productCode = $productElement->getAttribute('code_on_card');
    $currency = $productElement->getAttribute('currency');
    $available = $productElement->getAttribute('iaiext:available');
    
    // Get product name from description
    $descriptionNode = $xpath->query('//description')->item(0);
    $productName = $descriptionNode ? trim(strip_tags($descriptionNode->textContent)) : 'Unknown Product';
    // Clean and limit name length
    $productName = clean_utf8_string($productName);
    $productName = substr($productName, 0, 100);
    
    // Get producer
    $producerNode = $xpath->query('//producer')->item(0);
    $producerName = $producerNode ? clean_utf8_string($producerNode->getAttribute('name')) : '';
    
    // Get category
    $categoryNode = $xpath->query('//category')->item(0);
    $categoryName = $categoryNode ? clean_utf8_string($categoryNode->getAttribute('name')) : '';
    
    // Get main price
    $priceNode = $xpath->query('//price[@gross]')->item(0);
    $grossPrice = $priceNode ? (float)$priceNode->getAttribute('gross') : 0;
    $netPrice = $priceNode ? (float)$priceNode->getAttribute('net') : 0;
    
    // Parse sizes and calculate total stock
    $sizes = [];
    $totalStock = 0;
    $totalValue = 0;
    
    $sizeNodes = $xpath->query('//sizes/size');
    foreach ($sizeNodes as $sizeNode) {
        $sizeId = $sizeNode->getAttribute('id');
        $sizeName = $sizeNode->getAttribute('name');
        $sizeCode = $sizeNode->getAttribute('code');
        $sizeAvailable = $sizeNode->getAttribute('available');
        
        // Get stock for this size
        $stockNode = $xpath->query('.//stock', $sizeNode)->item(0);
        if ($stockNode) {
            $quantity = (int)$stockNode->getAttribute('quantity');
            $availableStockQuantity = (int)$stockNode->getAttribute('available_stock_quantity');
            
            if ($quantity > 0) {
                $totalStock += $quantity;
                $totalValue += $quantity * $grossPrice;
                
                $sizes[] = [
                    'size_id' => $sizeId,
                    'size_name' => $sizeName,
                    'size_code' => $sizeCode,
                    'quantity' => $quantity,
                    'available_quantity' => $availableStockQuantity,
                    'available_status' => $sizeAvailable
                ];
            }
        }
    }
    
    // Only return products that have exactly 1 total stock
    if ($totalStock !== 1) {
        return null;
    }
    
    // Return only the required fields: id, name, total_stock
    return [
        'id' => $productId,
        'name' => $productName,
        'total_stock' => $totalStock
    ];
}

function clean_utf8_string($string) {
    // Convert to UTF-8 and remove problematic characters
    $string = iconv('UTF-8', 'UTF-8//IGNORE', $string);
    
    // Remove control characters
    $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
    
    // Additional cleanup for common issues
    $string = preg_replace('/[\x80-\xFF]{4,}/', '', $string); // Remove sequences of high bytes
    
    return trim($string);
}

function clean_array_utf8(&$array) {
    foreach ($array as $key => &$value) {
        if (is_array($value)) {
            clean_array_utf8($value);
        } elseif (is_string($value)) {
            $value = clean_utf8_string($value);
        }
    }
}

function save_results($results, $outputFile) {
    // Clean all strings recursively
    clean_array_utf8($results);
    
    // Force all string values to be valid UTF-8
    array_walk_recursive($results, function(&$item) {
        if (is_string($item)) {
            $item = filter_var($item, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
            $item = preg_replace('/[^\x20-\x7E\xC0-\xDF][\x80-\xBF]*/', '', $item);
        }
    });
    
    // Try simple JSON encoding first
    $jsonData = json_encode($results, JSON_PRETTY_PRINT);
    
    if ($jsonData === false) {
        log_message("Regular JSON encoding failed, trying ASCII-safe version...");
        
        // Convert all non-ASCII characters to safe representations
        array_walk_recursive($results, function(&$item) {
            if (is_string($item)) {
                $item = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $item);
            }
        });
        
        $jsonData = json_encode($results, JSON_PRETTY_PRINT);
        
        if ($jsonData === false) {
            throw new Exception("Failed to encode JSON even with ASCII conversion: " . json_last_error_msg());
        }
    }
    
    file_put_contents($outputFile, $jsonData);
    log_message("Results saved to: {$outputFile}");
    log_message("File size: " . number_format(strlen($jsonData) / 1024, 2) . " KB");
}

function display_summary($results) {
    $summary = $results['summary'];
    $products = $results['products'];
    
    echo "\n";
    echo "=== STOCK SUMMARY (Products with exactly 1 unit) ===\n";
    echo "Generated: {$summary['generated_at']}\n";
    echo "Total products in feed: {$summary['total_products_in_feed']}\n";
    echo "Products with exactly 1 stock: {$summary['products_with_exactly_1_stock']}\n";
    echo "\n";
    
    if (!empty($products)) {
        echo "=== PRODUCTS WITH EXACTLY 1 UNIT IN STOCK ===\n";
        
        foreach ($products as $product) {
            printf("ID: %-6s | Stock: %d | %s\n", 
                $product['id'],
                $product['total_stock'],
                substr($product['name'], 0, 50)
            );
        }
    }
    
    echo "\n";
}

// Main execution
try {
    log_message("=== Starting XML feed parsing ===");
    
    // Check if XML file exists
    if (!file_exists($config['xml_file'])) {
        throw new Exception("XML file not found: {$config['xml_file']}");
    }
    
    // Parse XML feed
    $results = parse_xml_feed($config['xml_file']);
    
    // Save results
    save_results($results, $config['output_file']);
    
    // Display summary
    display_summary($results);
    
    log_message("=== Processing completed successfully ===");
    
} catch (Exception $e) {
    log_message("ERROR: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Files created:\n";
echo "- Results: {$config['output_file']}\n";
echo "- Log: {$config['log_file']}\n";
?>