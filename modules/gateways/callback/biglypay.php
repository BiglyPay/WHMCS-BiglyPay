<?php
include('../../../includes/functions.php');
include('../../../includes/gatewayfunctions.php');
include('../../../includes/invoicefunctions.php');

if (file_exists('../../../dbconnect.php'))
    include '../../../dbconnect.php';
else if (file_exists('../../../init.php'))
    include '../../../init.php';
else
    die('[ERROR] In modules/gateways/callback/biglypay.php: include error: Cannot find dbconnect.php or init.php');



// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed");
}

// Decode incoming JSON
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['invoice_id'], $input['related_invoice_id'], $input['status'], $input['received_amount'], $input['ipn_key'], $input['tx_hash'])) {
    http_response_code(400);
    die("Invalid request parameters");
}

// Fetch gateway settings
$gatewayParams = getGatewayVariables("biglypay");
if (!$gatewayParams['type']) {
    http_response_code(400);
    die("Module not active");
}

// Validate IPN key
$expectedIpnKey = $gatewayParams['ipnKey'];
if ($input['ipn_key'] !== $expectedIpnKey) {
    http_response_code(403);
    die("Invalid IPN Key");
}

// Extract data
$invoiceId = (int) $input['related_invoice_id'];
$status = $input['status'];
$receivedAmount = (float) $input['received_amount'];
$txHash = $input['tx_hash']; // ✅ Include transaction hash

$invoiceId = checkCbInvoiceID($invoiceId, "biglypay");

checkCbTransID($txHash);


// Fetch invoice

if (!$invoiceId) {
    http_response_code(404);
    die("Invoice not found");
}

// Handle fully paid and partial payments
if ($status === "Confirmed") {
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $invoiceTotal = (float) $invoice['total'];
    $tolerance = 0.05;
    $diff = abs($invoiceTotal - $receivedAmount);

    // If within tolerance, round up to invoice total so WHMCS marks it paid cleanly
    $paymentAmount = ($diff <= $tolerance) ? $invoiceTotal : $receivedAmount;

    addInvoicePayment($invoiceId, $txHash, $paymentAmount, 0, "biglypay");
    logTransaction("biglypay", $input, "Confirmed: received $receivedAmount, applied $paymentAmount");

    http_response_code(200);
    echo "Payment processed successfully";
} else {
    logTransaction("biglypay", $input, "Payment Failed or Unconfirmed");
    http_response_code(400);
    echo "Invalid payment status";
}
?>
