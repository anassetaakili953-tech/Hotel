<?php
/**
 * save_reservation.php
 * ─────────────────────────────────────────────
 * يستقبل بيانات الحجز JSON، يحفظها في MySQL عبر PDO،
 * يرسل إيميل تأكيد، ويرجع رد JSON.
 *
 * ✅ محمي ضد SQL Injection (Prepared Statements)
 * ✅ محمي ضد XSS (htmlspecialchars)
 * ✅ التحقق من البيانات قبل الإدخال
 */

// ══ إعدادات الاستجابة ══
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// ══ 1. إعدادات قاعدة البيانات (عمّرها بمعلوماتك) ══
$db_host = "localhost";        // مثال: "localhost"
$db_name = "u625437859_BD";        // مثال: "hotel_db"
$db_user = "u625437859_anas_db";        // مثال: "root"
$db_pass = "w5rByj4@";        // كلمة مرور قاعدة البيانات

// ══ 2. إعدادات الإيميل ══
$hotel_email = "info@yourhotel.com";   // بريد الفندق (المُرسِل)
$hotel_name  = "فندق بريق";

// ══ 3. قبول طلبات POST فقط ══
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

// ══ 4. قراءة وتحليل JSON القادم من Fetch ══
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "بيانات JSON غير صحيحة"]);
    exit;
}

// ══ 5. تنظيف وتحقق من البيانات ══
$name      = isset($data["name"])      ? trim(htmlspecialchars($data["name"], ENT_QUOTES, "UTF-8")) : "";
$room_type = isset($data["room_type"]) ? trim(htmlspecialchars($data["room_type"], ENT_QUOTES, "UTF-8")) : "";
$guests    = isset($data["guests"])    ? (int)$data["guests"] : 0;
$email     = isset($data["email"])     ? trim(filter_var($data["email"], FILTER_SANITIZE_EMAIL)) : "";
$check_in  = isset($data["check_in"])  ? trim($data["check_in"]) : "";
$check_out = isset($data["check_out"]) ? trim($data["check_out"]) : "";

// أنواع الغرف المسموح بها (whitelist)
$allowed_rooms = ["standard", "deluxe", "suite", "villa"];

// التحقق من البيانات
$errors = [];

if (strlen($name) < 3) {
    $errors[] = "الاسم يجب أن يحتوي على 3 أحرف على الأقل";
}
if (!in_array($room_type, $allowed_rooms, true)) {
    $errors[] = "نوع الغرفة غير صحيح";
}
if ($guests < 1 || $guests > 10) {
    $errors[] = "عدد النزلاء يجب أن يكون بين 1 و 10";
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "البريد الإلكتروني غير صحيح";
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in)) {
    $errors[] = "تاريخ الوصول غير صحيح";
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out)) {
    $errors[] = "تاريخ المغادرة غير صحيح";
}
if (!empty($check_in) && !empty($check_out) && $check_out <= $check_in) {
    $errors[] = "تاريخ المغادرة يجب أن يكون بعد تاريخ الوصول";
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => implode(" | ", $errors)]);
    exit;
}

// ══ 6. الاتصال بقاعدة البيانات (PDO) ══
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,  // ⬅ مهم للأمان
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    // لا تعرض رسالة الخطأ الحقيقية للمستخدم
    error_log("DB Connection Error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "خطأ في الاتصال بقاعدة البيانات"]);
    exit;
}

// ══ 7. إنشاء الجدول إذا لم يكن موجوداً ══
/*
   يمكنك تشغيل هذا SQL مرة واحدة في phpMyAdmin بدلاً من تركه هنا:

   CREATE TABLE IF NOT EXISTS hotel_reservations (
     id         INT AUTO_INCREMENT PRIMARY KEY,
     name       VARCHAR(150) NOT NULL,
     room_type  ENUM('standard','deluxe','suite','villa') NOT NULL,
     guests     TINYINT UNSIGNED NOT NULL,
     email      VARCHAR(200) NOT NULL,
     check_in   DATE NOT NULL,
     check_out  DATE NOT NULL,
     status     ENUM('pending','confirmed','cancelled') DEFAULT 'pending',
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

// ══ 8. حفظ الحجز بـ Prepared Statement (محمي ضد SQL Injection) ══
try {
    $sql = "INSERT INTO hotel_reservations
                (name, room_type, guests, email, check_in, check_out, status)
            VALUES
                (:name, :room_type, :guests, :email, :check_in, :check_out, 'pending')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":name"      => $name,
        ":room_type" => $room_type,
        ":guests"    => $guests,
        ":email"     => $email,
        ":check_in"  => $check_in,
        ":check_out" => $check_out,
    ]);

    $booking_id = $pdo->lastInsertId();

} catch (PDOException $e) {
    http_response_code(500);
    error_log("DB Insert Error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "فشل تسجيل الحجز في قاعدة البيانات"]);
    exit;
}

// ══ 9. إرسال إيميل التأكيد (mail()) ══
$room_labels = [
    "standard" => "غرفة ستاندرد",
    "deluxe"   => "غرفة ديلوكس",
    "suite"    => "الجناح الملكي",
    "villa"    => "فيلا مع مسبح",
];
$room_label = $room_labels[$room_type] ?? $room_type;

$subject = "=?UTF-8?B?" . base64_encode("تأكيد حجزك في {$hotel_name} — رقم #{$booking_id}") . "?=";

$message = "
مرحباً {$name}،

شكراً لاختيارك {$hotel_name}! تم تأكيد حجزك بنجاح.

─────────────────────────────
  رقم الحجز   : #{$booking_id}
  نوع الغرفة  : {$room_label}
  عدد النزلاء : {$guests}
  تاريخ الوصول: {$check_in}
  تاريخ المغادرة: {$check_out}
─────────────────────────────

سيتواصل معك فريقنا قريباً لتأكيد التفاصيل.

مع أطيب التحيات،
فريق {$hotel_name}
{$hotel_email}
";

$headers  = "From: {$hotel_name} <{$hotel_email}>\r\n";
$headers .= "Reply-To: {$hotel_email}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: base64\r\n";

// إرسال الإيميل (قد يحتاج إعداد SMTP على الخادم)
$mail_sent = mail($email, $subject, base64_encode($message), $headers);

// ══ 10. الرد بـ JSON ══
echo json_encode([
    "status"     => "success",
    "message"    => "تم تسجيل حجزك بنجاح",
    "booking_id" => (int)$booking_id,
    "mail_sent"  => $mail_sent,
    "data"       => [
        "name"      => $name,
        "room_type" => $room_label,
        "guests"    => $guests,
        "check_in"  => $check_in,
        "check_out" => $check_out,
    ],
], JSON_UNESCAPED_UNICODE);
