<?php
// Email Notification Helper
// Configure SMTP settings in production for reliable email delivery

function send_email_notification($to_email, $subject, $message, $from_email = 'noreply@mbus.com', $from_name = 'MBUS System') {
    $headers = array(
        'From' => "$from_name <$from_email>",
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'Reply-To' => $from_email,
        'X-Mailer' => 'PHP/' . phpversion()
    );
    
    $html_message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>$subject</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1e90ff 0%, #4169e1 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            .btn { display: inline-block; padding: 12px 24px; background: #1e90ff; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .btn:hover { background: #4169e1; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>MBUS - Morong to SBMA Bus Reservation</h1>
            </div>
            <div class='content'>
                $message
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " MBUS System. All rights reserved.</p>
                <p>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Send email
    return mail($to_email, $subject, $html_message, implode("\r\n", $headers));
}

// Email templates for different actions
function get_reservation_created_email($ticket_code, $departure_time, $route) {
    return "
        <h2>Reservation Created Successfully</h2>
        <p>Your reservation has been created successfully.</p>
        <div style='background: white; padding: 20px; border-radius: 6px; margin: 20px 0;'>
            <p><strong>Ticket Code:</strong> $ticket_code</p>
            <p><strong>Route:</strong> $route</p>
            <p><strong>Departure Time:</strong> $departure_time</p>
            <p><strong>Reservation Status:</strong> Pending Payment</p>
        </div>
        <p>Please complete your payment within 10 minutes to secure your reservation.</p>
        <p>If payment is not completed, your reservation will be automatically cancelled.</p>
    ";
}

function get_payment_success_email($ticket_code, $departure_time, $route) {
    return "
        <h2>Payment Successful</h2>
        <p>Your payment has been processed successfully.</p>
        <div style='background: white; padding: 20px; border-radius: 6px; margin: 20px 0;'>
            <p><strong>Ticket Code:</strong> $ticket_code</p>
            <p><strong>Route:</strong> $route</p>
            <p><strong>Departure Time:</strong> $departure_time</p>
            <p><strong>Reservation Status:</strong> Paid</p>
        </div>
        <p>Please arrive at least 15 minutes before your departure time.</p>
    ";
}

function get_reservation_cancelled_email($ticket_code, $reason) {
    return "
        <h2>Reservation Cancelled</h2>
        <p>Your reservation has been cancelled.</p>
        <div style='background: white; padding: 20px; border-radius: 6px; margin: 20px 0;'>
            <p><strong>Ticket Code:</strong> $ticket_code</p>
            <p><strong>Reason:</strong> $reason</p>
        </div>
        <p>If you believe this is an error, please contact our support team.</p>
    ";
}

function get_verification_approved_email($verification_type) {
    return "
        <h2>Verification Approved</h2>
        <p>Congratulations! Your $verification_type verification has been approved.</p>
        <p>You can now avail of the 20% discount on your bookings.</p>
        <a href='http://yourdomain.com/Commuter/schedule.php' class='btn'>Book a Ride</a>
    ";
}

function get_verification_rejected_email($verification_type, $reason) {
    return "
        <h2>Verification Rejected</h2>
        <p>Your $verification_type verification has been rejected.</p>
        <div style='background: white; padding: 20px; border-radius: 6px; margin: 20px 0;'>
            <p><strong>Reason:</strong> $reason</p>
        </div>
        <p>Please review the reason and submit a new verification document if applicable.</p>
    ";
}

function get_operator_approved_email() {
    return "
        <h2>Operator Application Approved</h2>
        <p>Congratulations! Your driver/operator application has been approved.</p>
        <p>You can now log in to the system using your registered email.</p>
        <a href='http://yourdomain.com/login.php' class='btn'>Login Now</a>
    ";
}

function get_operator_rejected_email($reason) {
    return "
        <h2>Operator Application Rejected</h2>
        <p>Your driver/operator application has been rejected.</p>
        <div style='background: white; padding: 20px; border-radius: 6px; margin: 20px 0;'>
            <p><strong>Reason:</strong> $reason</p>
        </div>
        <p>If you believe this is an error, please contact our support team.</p>
    ";
}
?>
