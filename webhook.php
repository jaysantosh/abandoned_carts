<?php

// Function to connect to database using PDO
function connectToDatabase() {
    $host = 'localhost';
    $username = 'your_username';
    $password = 'your_password';
    $database = 'your_database_name';

    try {
        $conn = new PDO("mysql:host=$host;dbname=$database", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// Function to store abandoned cart details in database
function storeAbandonedCartDetails($cartId, $customerId, $timestamp) {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("INSERT INTO abandoned_carts (cart_id, customer_id, abandoned_timestamp) 
                            VALUES (:cartId, :customerId, :timestamp)");
    $stmt->bindParam(':cartId', $cartId);
    $stmt->bindParam(':customerId', $customerId);
    $stmt->bindParam(':timestamp', $timestamp);

    try {
        $stmt->execute();
        // Log success or handle further processing
    } catch (PDOException $e) {
        // Log error or handle database failure
        error_log('Database error: ' . $e->getMessage());
        return false; // Return false on error
    }

    $conn = null; // connection is closed here 
    return true; // Return true on success
}

// Function to send email using MailerLite
function sendEmail($to, $subject, $body) {
    $mailerlite_api_key = 'your_mailerlite_api_key';
    $mailerlite_list_id = 'your_mailerlite_list_id';

    
    $data = [
        'email' => $to,
        'subject' => $subject,
        'body' => $body,
        'group_id' => $mailerlite_list_id
    ];

    // Send email via MailerLite API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.mailerlite.com/api/v2/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-MailerLite-ApiKey: ' . $mailerlite_api_key
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('MailerLite API error: ' . curl_error($ch));
        curl_close($ch);
        return false; // return false on error
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 400) {
        error_log('MailerLite API returned HTTP error: ' . $http_code);
        curl_close($ch);
        return false; // return false on HTTP error
    }

    curl_close($ch);
    return true; // return true on success
}

// function to send WhatsApp message using Ultra Message
function sendWhatsAppMessage($to, $message) {
    $ultra_message_api_key = 'your_ultra_message_api_key';
    $ultra_message_sender_id = 'your_ultra_message_sender_id';

    // Prepare data for Ultra message API request
    $data = [
        'phone' => $to,
        'message' => $message,
        'sender_id' => $ultra_message_sender_id
    ];

    // Send WhatsApp message via Ultra Message API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.ultramsg.com/rest/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $ultra_message_api_key
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('Ultra Message API error: ' . curl_error($ch));
        curl_close($ch);
        return false; // Return false on error
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 400) {
        error_log('Ultra Message API returned HTTP error: ' . $http_code);
        curl_close($ch);
        return false; // Return false on HTTP error
    }

    curl_close($ch);
    return true; // Return true on success
}

// Function to fetch abandoned cart details from api
function fetchAbandonedCartDetailsFromAPI($cartId, $managerToken) {
    $api_url = 'https:///managers/store/abandoned-carts/' . $cartId;
    $headers = [
        'Accept-Language: en-US',
        'Authorization: Bearer ' . $managerToken,
        'Content-Type: application/json'
    ];

    // Prepare API request using curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if ($response === false) {
        error_log('API request error: ' . curl_error($ch));
        curl_close($ch);
        return null; // Return null on error
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code >= 400) {
        error_log('API returned HTTP error: ' . $http_code);
        curl_close($ch);
        return null; // Return null on HTTP error
    }

    curl_close($ch);
    return json_decode($response, true); // Return API response as array
}

// handle post request from webhook
$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);

// Verify if this is an abandoned_cart.created event
if (isset($data['event']) && $data['event'] === 'abandoned_cart.created') {
    // Process the webhook data
    $cartId = $data['data']['cart_id'];
    $customerId = $data['data']['customer_id'];
    $timestamp = $data['data']['timestamp'];

    // Store abandoned cart details in database
    if (!storeAbandonedCartDetails($cartId, $customerId, $timestamp)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to store abandoned cart details.']);
        exit;
    }

    // Fetch abandoned cart details from API
    $managerToken = 'your_manager_token'; // Replace with your actual manager token
    $cartDetails = fetchAbandonedCartDetailsFromAPI($cartId, $managerToken);
    if (!$cartDetails) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch abandoned cart details from API.']);
        exit;
    }

    // extract customer email and mobile from response
    $customerEmail = $cartDetails['abandoned_cart']['customer_email'];
    $customerMobile = $cartDetails['abandoned_cart']['customer_mobile'];

    // Send personalized email using mailer Lite
    $emailSubject = 'Your Abandoned Cart at Zid Store';
    $emailBody = "Dear customer, we noticed that you left items in your cart. Click here to complete your purchase: {$cartDetails['abandoned_cart']['url']}";
    if (!sendEmail($customerEmail, $emailSubject, $emailBody)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to send email using MailerLite.']);
        exit;
    }

    // send personalized WhatsApp message using Ultra Message
    $whatsappMessage = "Hi {$cartDetails['abandoned_cart']['customer_name']}, we noticed you left items in your cart. Click here to complete your purchase: {$cartDetails['abandoned_cart']['url']}";
    if (!sendWhatsAppMessage($customerMobile, $whatsappMessage)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to send WhatsApp message using Ultra Message.']);
        exit;
    }

    // Send a success response
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook received and processed successfully.']);
} else {
    // invalid webhook event received
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook event.']);
}

?>