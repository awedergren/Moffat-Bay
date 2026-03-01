<?php
// contact_send.php - simple endpoint to accept contact form submissions and email them
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'errors' => ['Method not allowed']]);
    exit;
}

header('Content-Type: application/json');

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$reason = trim($_POST['reason'] ?? 'General');
$message = trim($_POST['message'] ?? '');

$errors = [];
if ($name === '') $errors[] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($message === '') $errors[] = 'Message is required.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

$to = 'info@moffatbaymarina.com';
$subject = sprintf('[Website Contact] %s - %s', $reason, $name);
$body = "Name: $name\nEmail: $email\nPhone: $phone\nReason: $reason\n\nMessage:\n$message\n";
$headers = "From: $name <$email>\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n";

$sent = false;
try {
    $sent = @mail($to, $subject, $body, $headers);
} catch (Exception $ex) {
    // swallow - handled below
}

if ($sent) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'errors' => ['Failed to send email. Please try again later.']]);
}

exit;
