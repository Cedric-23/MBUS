<?php
session_start();
if (strtolower((string)($_SESSION['user_type'] ?? '')) !== 'conductor') { header('Location: ../login.php'); exit(); }
$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Conductor'));
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>MBUS Conductor</title>
<style>body{font-family:Inter,system-ui,sans-serif;background:#f5f7fa;margin:0;display:grid;place-items:center;min-height:100vh;color:#101828}
.c{background:#fff;border-radius:16px;padding:32px;max-width:420px;margin:16px;box-shadow:0 4px 20px rgba(16,24,40,.08)}
h1{margin:0 0 8px;font-size:1.3rem;color:#0a2540}p{color:#667085;line-height:1.5}a{display:inline-block;margin-top:12px;padding:10px 18px;border-radius:10px;background:#086FCF;color:#fff;text-decoration:none;font-weight:600}</style></head>
<body><div class="c"><h1>Welcome, <?php echo $name; ?></h1>
<p>Your conductor account is approved. Boarding and ticket tools will appear here once an operator assigns you to a trip.</p>
<a href="../logout.php">Log out</a></div></body></html>
