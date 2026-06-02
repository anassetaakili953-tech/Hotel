/**
 * script.js — Hotel Booking Form (Fetch API + AJAX)
 * ────────────────────────────────────────────────
 * يرسل بيانات الفورمة لـ save_reservation.php بدون إعادة تحميل الصفحة
 */

// ══ 1. تحديد تاريخ اليوم كحد أدنى لتاريخ الوصول ══
(function setMinDates() {
  const today = new Date().toISOString().split("T")[0];
  const checkIn  = document.getElementById("check_in");
  const checkOut = document.getElementById("check_out");

  checkIn.min  = today;
  checkOut.min = today;

  // عند تغيير تاريخ الوصول، نحدد الحد الأدنى للمغادرة
  checkIn.addEventListener("change", () => {
    checkOut.min = checkIn.value;
    if (checkOut.value && checkOut.value <= checkIn.value) {
      checkOut.value = "";
    }
  });
})();


// ══ 2. دالة عرض الإشعارات ══
function showNotif(type, message) {
  const notif    = document.getElementById("notif");
  const notifMsg = document.getElementById("notif-msg");
  const notifIcon = document.getElementById("notif-icon");

  notif.className  = `notif ${type}`;
  notifIcon.textContent = type === "success" ? "✔️" : "❌";
  notifMsg.textContent  = message;
  notif.style.display   = "flex";

  // أخفي الإشعار بعد 6 ثوانٍ تلقائياً
  clearTimeout(notif._timer);
  notif._timer = setTimeout(() => {
    notif.style.display = "none";
  }, 6000);
}


// ══ 3. التحقق من صحة البيانات قبل الإرسال ══
function validateForm(data) {
  const { name, room_type, guests, email, check_in, check_out } = data;

  if (!name.trim() || name.trim().length < 3) {
    return "الرجاء إدخال الاسم الكامل (3 أحرف على الأقل).";
  }
  if (!room_type) {
    return "الرجاء اختيار نوع الغرفة.";
  }
  if (!guests || guests < 1 || guests > 10) {
    return "عدد النزلاء يجب أن يكون بين 1 و 10.";
  }
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return "الرجاء إدخال بريد إلكتروني صحيح.";
  }
  if (!check_in) {
    return "الرجاء تحديد تاريخ الوصول.";
  }
  if (!check_out) {
    return "الرجاء تحديد تاريخ المغادرة.";
  }
  if (check_out <= check_in) {
    return "تاريخ المغادرة يجب أن يكون بعد تاريخ الوصول.";
  }
  return null; // ✅ لا يوجد خطأ
}


// ══ 4. الدالة الرئيسية للحجز ══
async function submitBooking() {
  const btn     = document.getElementById("submit-btn");
  const btnText = document.getElementById("btn-text");
  const spinner = document.getElementById("spinner");

  // جمع بيانات الفورمة
  const formData = {
    name:       document.getElementById("name").value.trim(),
    room_type:  document.getElementById("room_type").value,
    guests:     parseInt(document.getElementById("guests").value, 10),
    email:      document.getElementById("email").value.trim(),
    check_in:   document.getElementById("check_in").value,
    check_out:  document.getElementById("check_out").value,
  };

  // التحقق من صحة البيانات
  const validationError = validateForm(formData);
  if (validationError) {
    showNotif("error", validationError);
    return;
  }

  // ═══ بداية الإرسال ═══
  btn.disabled       = true;
  btnText.style.display = "none";
  spinner.style.display = "block";

  try {
    const response = await fetch("save_reservation.php", {
      method:  "POST",
      headers: { "Content-Type": "application/json" },
      body:    JSON.stringify(formData),
    });

    // التحقق من أن الخادم رد بشكل صحيح
    if (!response.ok) {
      throw new Error(`HTTP error — status: ${response.status}`);
    }

    const result = await response.json();

    if (result.status === "success") {
      showNotif(
        "success",
        `✅ تم الحجز بنجاح! رقم الحجز: ${result.booking_id || "—"}. ` +
        `سيصلك بريد تأكيد على ${formData.email}`
      );
      resetForm();
    } else {
      showNotif("error", result.message || "حدث خطأ أثناء تسجيل الحجز.");
    }

  } catch (err) {
    console.error("Booking error:", err);
    showNotif(
      "error",
      "تعذر الاتصال بالخادم. تأكد من أن ملفات PHP تعمل بشكل صحيح."
    );
  } finally {
    // إعادة الزر لحالته الأصلية
    btn.disabled         = false;
    btnText.style.display = "inline";
    spinner.style.display = "none";
  }
}


// ══ 5. إعادة تهيئة الفورمة بعد الحجز الناجح ══
function resetForm() {
  const fields = ["name", "room_type", "guests", "email", "check_in", "check_out"];
  fields.forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
}
