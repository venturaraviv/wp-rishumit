<?php
/* Template Name: Payment Success */
get_header();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$payment_pending = isset($_GET['payment_pending']) && $_GET['payment_pending'] == '1';

// Add these for SDK payments without breaking existing code
$confirmation = isset($_GET['confirmation']) ? sanitize_text_field($_GET['confirmation']) : '';
$method = isset($_GET['method']) ? sanitize_text_field($_GET['method']) : '';

// Get form name from stored payment data
$form_name = '';
if ($order_id > 0) {
    $payment_data = get_transient('payment_data_' . $order_id);
    if ($payment_data && isset($payment_data['form_name'])) {
        $form_name = $payment_data['form_name'];
        // Clean up now that we've used it
        delete_transient('payment_data_' . $order_id);
    }
}
?>

<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "תודה על פנייתך - רישומית אונליין בע\"מ",
  "url": "https://rishumit.online/thank-you",
  "description": "תודה על פנייתך או בקשתך לביטול. ניצור עמך קשר בהקדם.",
  "about": "עמוד זה נועד להודות למשתמשים שיצרו קשר או הגישו בקשת ביטול באתר.",
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

<div class="payment-success-container" style="padding: 40px; max-width: 800px; margin: auto;">
    <h1>תודה על הזמנתך!</h1>

    <div class="notification-box">
        <?php if ($payment_pending): ?>
            <p>הטופס שלך נשלח בהצלחה והנתונים נשמרו במערכת, אבל התשלום עדיין לא הושלם.</p>
        <?php else: ?>
            <p>הטופס שלך נשלח בהצלחה והתשלום התקבל. הבקשה שלך נמצאת בטיפול.</p>
        <?php endif; ?>
        <p>מספר ההזמנה שלך: <strong><?php echo esc_html($order_id); ?></strong></p>
        
        <?php if (!empty($confirmation)): ?>
            <p>מספר אישור התשלום: <strong><?php echo esc_html($confirmation); ?></strong></p>
        <?php endif; ?>
        
        <?php if (!empty($method)): ?>
            <p>אמצעי תשלום: <strong><?php echo esc_html($method); ?></strong></p>
        <?php endif; ?>
    </div>

    <?php if ($payment_pending): ?>
    <div class="options-container">
        <h2>השלמת התשלום:</h2>

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
    </div>
    <?php else: ?>
    <div class="success-message">
        <h2>מה קורה עכשיו?</h2>
        <div class="option-card">
            <h3>הבקשה בטיפול</h3>
            <p>הבקשה שלך התקבלה במערכת ונמצאת בטיפול. צוות המומחים שלנו יבדוק את הפרטים שהזנת.</p>
            <p>תוכל לעקוב אחר הסטטוס של הבקשה שלך דרך אזור אישי או באמצעות קבלת עדכונים בדוא"ל.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="additional-info">
        <div class="option-card">
            <h3>לסיוע נוסף</h3>
            <p>אם יש לך שאלות לגבי הבקשה שלך, מומחי השירות שלנו ישמחו לעזור.</p>
            <a href="https://wa.me/972548021224" class="whatsapp-button">
                <i class="fab fa-whatsapp"></i> צור קשר בוואטסאפ
            </a>
        </div>
    </div>

    <div class="info-note">
        <?php if ($payment_pending): ?>
            <p>שים לב: הבקשה שלך לא תטופל עד להשלמת התשלום.</p>
        <?php else: ?>
            <p>תוכל לקבל עדכונים על סטטוס הבקשה שלך באמצעות המייל או הטלפון שמסרת.</p>
        <?php endif; ?>
    </div>
</div>

<script> //gtags
  const formConversions = {
    'Green Form': 'AW-16765175858/l5ZWCK_iiIIaELKQobo-',
    'Death Certificate': 'AW-16765175858/NLP6CNDSxoEaELKQobo-',
    'IDF Certificates': 'AW-16765175858/XO1qCLuphoEaELKQobo-',
    'Registration Summary': 'AW-16765175858/nfpwCPiCiIIaELKQobo-',
    'Change Address': 'AW-16765175858/oT9pCLKp6IIaELKQobo-',
    'Birth Certificate': 'AW-16765175858/6A2ICKPs5IMaELKQobo-',
    'ID appendix': 'AW-16765175858/R3mYCNeEoLsaELKQobo-',
    'ESTA': 'AW-16765175858/IQfeCLiE1boaELKQobo-',
    'Income Tax Exemption': 'AW-16765175858/4P8YCOSQyYMaELKQobo-',
    'Tabu Service': 'AW-16765175858/b9wVCNzJrf4ZELKQobo-',
    'Tax coordination': 'AW-16765175858/L_DhCIjd8v4ZELKQobo-'
  };

  const formName = <?php echo json_encode($form_name); ?>;
  if (formName && formConversions[formName]) {
    gtag('event', 'conversion', {
      send_to: formConversions[formName]
    });
    console.log("✅ Sent conversion for form:", formName);
  } else {
    console.warn("⚠️ No conversion ID for form:", formName);
  }
</script>

<?php get_footer(); ?>