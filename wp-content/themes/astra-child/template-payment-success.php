<?php
/* Template Name: Payment Success */
get_header();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$payment_pending = isset($_GET['payment_pending']) && $_GET['payment_pending'] == '1';
?>

<div class="payment-success-container" style="padding: 40px; max-width: 800px; margin: auto;">
    <h1>תודה על הזמנתך!</h1>

    <div class="notification-box">
        <?php if ($payment_pending): ?>
            <p>הטופס שלך נשלח בהצלחה והנתונים נשמרו במערכת, אבל התשלום עדיין לא הושלם.</p>
        <?php else: ?>
            <p>הטופס שלך נשלח בהצלחה והתשלום התקבל. הבקשה שלך נמצאת בטיפול.</p>
        <?php endif; ?>
        <p>מספר ההזמנה שלך: <strong><?php echo esc_html($order_id); ?></strong></p>
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

<?php get_footer(); ?>