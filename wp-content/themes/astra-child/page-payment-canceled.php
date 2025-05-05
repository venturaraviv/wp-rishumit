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

<?php get_footer(); ?>

