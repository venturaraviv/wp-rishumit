<?php
/* Template Name: Payment Canceled */
get_header();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
?>

<div class="payment-cancelled-container" style="padding: 40px; max-width: 800px; margin: auto;">
    <h1>התשלום לא הושלם</h1>

    <div class="notification-box">
        <p>הטופס שלך נשלח בהצלחה והנתונים נשמרו במערכת, אבל התשלום לא הושלם.</p>
        <p>מספר ההזמנה שלך: <strong><?php echo esc_html($order_id); ?></strong></p>
    </div>

    <div class="options-container">
        <h2>כיצד ניתן להשלים את התהליך:</h2>

        <div class="option-card">
            <h3>השלם את התשלום עכשיו</h3>
            <p>לחץ על הכפתור למטה כדי להשלים את התשלום שלך.</p>
            <?php
            $payment_url = get_option('rishumit_payment_url_' . $order_id);
?>

            <?php if ($payment_url): ?>
                <a href="<?php echo esc_url($payment_url); ?>" class="payment-button">השלם תשלום</a>
            <?php else: ?>
                <p>לא הצלחנו לשחזר את קישור התשלום. אנא צור קשר עם התמיכה.</p>
            <?php endif; ?>

        </div>

        <div class="option-card">
            <h3>לסיוע נוסף</h3>
            <p>אם נתקלת בבעיה בתהליך התשלום, מומחי השירות שלנו ישמחו לעזור.</p>
            <a href="https://wa.me/972548021224" class="whatsapp-button">
                <i class="fab fa-whatsapp"></i> צור קשר בוואטסאפ
            </a>
        </div>
    </div>

    <div class="info-note">
        <p>שים לב: הבקשה שלך לא תטופל עד להשלמת התשלום.</p>
    </div>
</div>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "תשלום נכשל - רישומית אונליין בע\"מ",
  "url": "https://rishumit.online/payment-cancelled",
  "description": "עמוד תשלום נכשל של רישומית אונליין בע\"מ אליו מגיעים משתמשים במקרה שבו העסקה לא הושלמה בהצלחה.",
  "about": "עמוד זה מסביר למשתמש כי פעולת התשלום לא הושלמה ומציע לחזור ולנסות שוב או ליצור קשר עם שירות הלקוחות לקבלת סיוע.",
  "publisher": {
    "@type": "Organization",
    "name": "רישומית אונליין בע\"מ",
    "url": "https://rishumit.online",
    "logo": "https://rishumit.online/wp-content/uploads/2024/12/1.png",
    "contactPoint": {
      "@type": "ContactPoint",
      "telephone": "+972-54-8021224",
      "contactType": "שירות לקוחות",
      "email": "service@rishumit.online",
      "availableLanguage": ["Hebrew", "English", "Russian"]
    },
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "תוצרת הארץ 3, מגדלי ב.ס.ר",
      "addressLocality": "פתח תקווה",
      "addressRegion": "מרכז",
      "addressCountry": "IL"
    }
  }
}
</script>

<?php get_footer(); ?>

