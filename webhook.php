<?php
$config = require_once 'config.php';
// Function to connect to database using PDO
function connectToDatabase() {
    global $config;
    $host = $config['database']['host'];

    $username = $config['database']['username'];
    $password = $config['database']['password'];
    $database =$config['database']['database_name'];
    
    try {
        $conn = new PDO("mysql:host=$host;dbname=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // echo "connection_created";
        return $conn;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}
//ensuring table is created only once for first time
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SELECT 1 FROM $tableName LIMIT 1");
    } catch (Exception $e) {
        return false;
    }
    return $result !== false;
}

// Function to create necessary tables if they don't exist
function createTablesIfNotExist($pdo) {
    if (!tableExists($pdo, 'customers')) {
        $createCustomersTable = "
            CREATE TABLE customers (
                customer_id INT PRIMARY KEY,
                customer_name VARCHAR(255) NOT NULL,
                customer_email VARCHAR(255) NOT NULL,
                customer_mobile VARCHAR(20) NOT NULL,
                city_id INT NOT NULL
            )
        ";
        $pdo->exec($createCustomersTable);
    }

    if (!tableExists($pdo, 'categories')) {
        $createCategoriesTable = "
            CREATE TABLE categories (
                category_id INT PRIMARY KEY AUTO_INCREMENT,
                category_name VARCHAR(255) NOT NULL
            )
        ";
        $pdo->exec($createCategoriesTable);
    }

    if (!tableExists($pdo, 'abandoned_carts')) {
        $createAbandonedCartsTable = "
            CREATE TABLE abandoned_carts (
                cart_id VARCHAR(255) PRIMARY KEY,
                session_id VARCHAR(255) NOT NULL,
                url TEXT NOT NULL,
                store_name VARCHAR(255) NOT NULL,
                order_id INT NOT NULL,
                phase VARCHAR(50),
                customer_id INT NOT NULL,
                category_id INT NOT NULL,
                cart_total DECIMAL(10, 2),
                cart_total_string VARCHAR(50),
                status VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(category_id),
                FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
            )
        ";
        $pdo->exec($createAbandonedCartsTable);
    }
}

$pdo = connectToDatabase();
createTablesIfNotExist($pdo);





function storeAbandonedCart($pdo, $data) {
    try {
        // Check if customer already exists
        $customerStmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = :customer_id");
        $customerStmt->execute(['customer_id' => $data['abandoned_cart']['customer_id']]);
        
        if ($customerStmt->rowCount() == 0) {
            // Insert new customer
            $customerInsert = $pdo->prepare("
                INSERT INTO customers (customer_id, customer_name, customer_email, customer_mobile, city_id)
                VALUES (:customer_id, :customer_name, :customer_email, :customer_mobile, :city_id)
            ");
            $customerInsert->execute([
                'customer_id' => $data['abandoned_cart']['customer_id'],
                'customer_name' => $data['abandoned_cart']['customer_name'],
                'customer_email' => $data['abandoned_cart']['customer_email'],
                'customer_mobile' => $data['abandoned_cart']['customer_mobile'],
                'city_id' => $data['abandoned_cart']['city']['id']
            ]);
        }

        // Check if category already exists (assuming first product's first category)
        $categoryId = null;
        if (!empty($data['abandoned_cart']['products'][0]['categories'])) {
            $categoryId = $data['abandoned_cart']['products'][0]['categories'][0]['id'];
            $categoryStmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = :category_id");
            $categoryStmt->execute(['category_id' => $categoryId]);

            if ($categoryStmt->rowCount() == 0) {
                // Insert new category
                $categoryInsert = $pdo->prepare("
                    INSERT INTO categories (category_id, category_name)
                    VALUES (:category_id, :category_name)
                ");
                $categoryInsert->execute([
                    'category_id' => $categoryId,
                    'category_name' => $data['abandoned_cart']['products'][0]['categories'][0]['name']['en'] // Assuming English name
                ]);
            }
        }

        // Insert or update the abandoned cart
        $cartStmt = $pdo->prepare("
            INSERT INTO abandoned_carts (cart_id, session_id, url, store_name, order_id, phase, customer_id, category_id, cart_total, cart_total_string, status, created_at,updated_at)
            VALUES (:cart_id, :session_id, :url, :store_name, :order_id, :phase, :customer_id, :category_id, :cart_total, :cart_total_string, :status, :created_at,:updated_at)
            ON DUPLICATE KEY UPDATE
            session_id = VALUES(session_id),
            url = VALUES(url),
            store_name = VALUES(store_name),
            order_id = VALUES(order_id),
            phase = VALUES(phase),
            customer_id = VALUES(customer_id),
            category_id = VALUES(category_id),
            cart_total = VALUES(cart_total),
            cart_total_string = VALUES(cart_total_string),
            status = VALUES(status),
            created_at = VALUES(created_at),
            updated_at=VALUES(updated_at)
            
        ");
        
        $cartStmt->execute([
            'cart_id' => $data['abandoned_cart']['cart_id'],
            'session_id' => $data['abandoned_cart']['session_id'],
            'url' => $data['abandoned_cart']['url'],
            'store_name' => $data['abandoned_cart']['store_name'],
            'order_id' => $data['abandoned_cart']['order_id'],
            'phase' => $data['abandoned_cart']['phase'],
            'customer_id' => $data['abandoned_cart']['customer_id'],
            'category_id' => $categoryId,
            'cart_total' => $data['abandoned_cart']['cart_total'],
            'cart_total_string' => $data['abandoned_cart']['cart_total_string'],
            'status' => 'not_recovered', // Set a default status
            'created_at' => $data['abandoned_cart']['created_at'],
            'updated_at' => $data['abandoned_cart']['updated_at']
           
        ]);
        
    } catch (PDOException $e) {
        return ['error' => 'Failed to store abandoned cart: ' . $e->getMessage()];
    }
    
    return ['success' => true];
}



function addSubscriberToGroup($apiKey, $groupId, $email) {
    $url = "https://api.mailerlite.com/api/v2/groups/{$groupId}/subscribers";

    $data = json_encode([
        'email' => $email
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-MailerLite-ApiKey: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        throw new Exception("Failed to add subscriber to group. HTTP Status Code: " . $httpCode);
    }
}







// Function to send WhatsApp message using Ultra Message
function sendWhatsAppMessage($to, $message) {
    global $config;
    $ultra_message_token = $config['api_keys']['ultra_message_token'];
    $ultra_message_instance_id = $config['ids']['ultra_message_instance_id'];

    $data = [
        'token' => $ultra_message_token,
        'to' => $to,
        'body' => $message
    ];

    // Sending WhatsApp message via Ultra Message API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.ultramsg.com/$ultra_message_instance_id/messages/chat",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            "content-type: application/x-www-form-urlencoded"
        ],
    ]);

    $response = curl_exec($ch);
    echo $response;
    if ($response === false) {
        error_log('Ultra Message API error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 400) {
        error_log('Ultra Message API returned HTTP error: ' . $http_code);
        curl_close($ch);
        return false; 
    }
    echo $http_code;
    curl_close($ch);
    return true; 
}

// Handle post request from webhook
$request_body = file_get_contents('http://localhost/abandoned_carts/input.php');
$data = json_decode($request_body, true);

// Verifying if this is an abandoned_cart.created or abandoned_cart.completed event
if ($data['abandoned_cart']['phase']) {
    $phase = $data['abandoned_cart']['phase'];
    echo $phase;
    if ($phase === 'new') {
       
      
        // $cartDetails = fetchAbandonedCartDetailsFromAPI($cartId, $managerToken);
        echo $data['abandoned_cart']['customer_name'];
        $pdo=connectToDatabase();
        
        if ($pdo->errorCode() !== '00000') {
          
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $pdo['error']]);
            exit;
        }
        $storeResult=storeAbandonedCart($pdo,$data);
        
        if (isset($storeResult['error'])) {
            
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $storeResult['error']]);
            exit;
        }
        $customerEmail = $data['abandoned_cart']['customer_email'];
$customerMobile = $data['abandoned_cart']['customer_mobile'];
$customerName = $data['abandoned_cart']['customer_name'];
$productName = $data['abandoned_cart']['products'][0]['name']['ar']; 
$cartTotal = $data['abandoned_cart']['cart_total'];
$cartUrl = $data['abandoned_cart']['url'];

        $whatsappMessage = "مرحبًا $customerName\n\nہم نے دیکھا کہ آپ نے اپنی خریداری مکمل نہیں کی۔ اگر آپ کو کسی مسئلے کا سامنا ہے تو، ہم مدد کے لئے یہاں موجود ہیں۔\n\nچھوڑی گئی سلہ کی تفصیلات:\n\n• مصنوعات: $productName\n• کل: $cartTotal\n\nآپ اپنی خریداری کو $cartUrl پر جا کر آسانی سے دوبارہ شروع کر سکتے ہیں۔\n\nہم آپ کو ہماری دوسری دکان کا بھی دورہ کرنے کی دعوت دیتے ہیں جہاں بہترین خدمات موجود ہیں۔ ہمیں وزٹ کریں [https://linktr.ee/chargerquick.joygames].\n\nہم آپ کی خدمت کے منتظر ہیں!\n\nخیر مقدمات،\nچارج کوئیک سپورٹ ٹیم\n\nنوٹ: تکنیکی مدد کے لیے براہ کرم ہمیں واٹس ایپ کے ذریعے اس لنک پر رابطہ کریں: https://wa.me/message/T5HBP7QHD3MQB1";

        $groupId = $config['ids']['mailerlite_group_id'];
        $apiKey=$config['api_keys']['mailerlite'];
        //add user to group
        if (!addSubscriberToGroup($apiKey, $groupId, $customerEmail)) {
          
          
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add user to group in MailerLite.']);
            exit;
        }
        if (!sendWhatsAppMessage($customerMobile, $whatsappMessage)) {
           
      
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to send WhatsApp message using Ultra Message.']);
            exit;
        }

        //succes
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Abandoned cart created event processed successfully.']);

    } 
    elseif ($phase === 'completed') {
        
        $cartId = $data['abandoned_cart']['cart_id'];

        // Update status to 'recovered' for completed cart
        if (!markCartAsRecovered($cartId)) {
            
            
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update cart status to recovered.']);
            exit;
        }
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Abandoned cart completed event processed successfully.']);
    }
    else {
        // Invalid webhook event received
       
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid webhook event.']);
    }
}
else {
    // No event specified in webhook payload
   
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No event specified in webhook payload.']);
}


function markCartAsRecovered($cartId) {
    $pdo = connectToDatabase();
    echo $cartId;
    try {
        // Update status in abandoned_carts table to 'recovered'
        $stmt = $pdo->prepare("UPDATE abandoned_carts SET phase = 'completed' WHERE cart_id = :cartId");
        $stmt->bindParam(':cartId', $cartId);
        $stmt->execute();

    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        return false; 
    }

    return true; 
}
?>
